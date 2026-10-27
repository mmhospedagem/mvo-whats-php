<?php
declare(strict_types=1);
namespace MVO\History;

use MVO\Media\MediaTransfer;
use MVO\Protocol\{BinaryData,Jid,SchemaCodec};
use MVO\Signal\SignalStore;

/** Authenticated, bounded history-sync ingestion, deferred outside node dispatch. */
final class HistoryService
{
    public readonly HistoryStore $store;
    private bool $closed=false;
    private bool $draining=false;
    private bool $importing=false;
    /**
     * @param \Closure():array<string,mixed> $getCredentials
     * @param \Closure(string,array<string,mixed>):void $emit
     * @param \Closure(array<string,mixed>,string):void $receipt
     */
    public function __construct(
        private readonly SignalStore $signalStore,
        private readonly MediaTransfer $media,
        private readonly \Closure $getCredentials,
        private readonly \Closure $emit,
        private readonly \Closure $receipt,
        private readonly int $maxInflatedBytes=67108864,
        private readonly ?HistorySyncSink $sink=null,
        private readonly bool $persist=true,
    ) {
        if ($maxInflatedBytes<1 || $maxInflatedBytes>268435456) throw new \InvalidArgumentException('Invalid history expansion limit');
        if (!$persist && $sink===null) throw new \InvalidArgumentException('Disabling history persistence requires a history sink');
        $this->store=new HistoryStore($signalStore);
    }

    /** Cancel work owned by this connection, including a paused sink callback. */
    public function close():void { $this->closed=true; }

    private function active():void
    {
        if ($this->closed) throw new \MVO\Transport\SessionRestartRequired('History import belongs to a closed connection');
    }

    /** Only authenticated protocol messages from another device of this account are accepted.
     * @param array<string,mixed> $event */
    public function handleProtocol(array $event):void
    {
        $this->active();
        if (($event['fromMe'] ?? false)!==true) return;
        $message=\MVO\Message\ContentDecoder::unwrap($event['proto'] ?? [])['message'];
        (new \MVO\Service\HistoryFeaturesService($this->signalStore,$this->store,$this->getCredentials))->ingestResponse($event);
        $notification=$message['protocolMessage']['historySyncNotification'] ?? null;
        if (!is_array($notification)) return;
        $id=$event['id'] ?? null; $from=$event['from'] ?? null;
        if (!is_string($id) || !is_string($from)) return;
        $key=hash('sha256',$from."\0".$id);
        if($this->signalStore->get('history-pending',$key)!==null)return;
        (new HistorySyncStore($this->signalStore))->queued($key,['id'=>$id,'notification'=>$notification]);
        $this->signalStore->set('history-pending',$key,BinaryData::encode(['notification'=>$notification,'id'=>$id,'from'=>$from,'attempt'=>0,'nextAttempt'=>0]));
    }

    /** Queue a bundle only after authenticating its carrier, and only for its named receiver.
     * @param array<string,mixed> $event */
    public function handleMessage(array $event):void
    {
        $this->active();
        $group=$event['chat'] ?? ''; if (!is_string($group) || !str_ends_with($group,'@g.us')) return;
        $bundle=\MVO\Message\ContentDecoder::unwrap($event['proto'] ?? [])['message']['messageHistoryBundle'] ?? null;
        if (!is_array($bundle)) return;
        $receivers=$bundle['messageHistoryMetadata']['historyReceivers'] ?? []; $own=[]; $credentials=($this->getCredentials)();
        foreach (['meJid','meLid'] as $field) if (is_string($credentials[$field] ?? null)) $own[]=Jid::parse($credentials[$field])->canonical()->bare();
        $addressed=false;
        foreach ($receivers as $receiver) if (is_string($receiver) && in_array(Jid::parse($receiver)->canonical()->bare(),$own,true)) $addressed=true;
        if (!$addressed || (is_int($event['timestamp'] ?? null) && $event['timestamp']+1209600<time())) return;
        $key=hash('sha256',$group."\0".($event['participant'] ?? '')."\0".$event['id']);
        if ($this->signalStore->get('group-history-processed',$key)!==null) return;
        $this->signalStore->set('group-history-pending',$key,BinaryData::encode(['event'=>$event,'bundle'=>$bundle,'attempt'=>0,'nextAttempt'=>0]));
    }

    /** Downloading/querying is allowed here, never in handleProtocol(). */
    public function drainPending():void
    {
        $this->active();
        if ($this->draining || $this->importing) throw new \LogicException('History ingestion is not reentrant');
        $this->draining=true;
        try { $this->drainPendingInternal(); }
        finally { $this->draining=false; }
    }

    private function drainPendingInternal():void
    {
        foreach ($this->pendingHistory() as $key=>$bytes) {
            $this->active();
            $job=BinaryData::decode($bytes);
            if (!is_array($job) || !is_array($job['notification'] ?? null)) throw new \UnexpectedValueException('Invalid pending history notification');
            if (($job['nextAttempt'] ?? 0)>time()) continue;
            try {
                if ($this->signalStore->get('history-processed',(string)$key)!==null) {
                    ($this->receipt)(['id'=>$job['id'],'remoteJid'=>$job['from']],'hist_sync');
                    $this->active();
                    $this->signalStore->delete('history-pending',(string)$key);
                    $ledger=new HistorySyncStore($this->signalStore);if($ledger->get((string)$key)!==null)$ledger->update((string)$key,['state'=>'imported','error'=>null,'receiptPending'=>false]);continue;
                }
                $notification=$job['notification'];
                $syncType=$notification['syncType'] ?? null;
                $ledger=new HistorySyncStore($this->signalStore);
                $ledger->queued((string)$key,$job);
                $ledger->update((string)$key,['state'=>'processing','attempt'=>($job['attempt']??0)+1]);
                if ($this->sink!==null && !in_array($syncType,[7,8,'NO_HISTORY','MESSAGE_ACCESS_STATUS'],true)) {
                    $result=$this->sinkAttempt(function() use($notification) {
                        $inline=$notification['initialHistBootstrapInlinePayload'] ?? null;
                        if (is_string($inline) && $inline!=='') return $this->inflateStream($this->stringStream($inline));
                        foreach (['mediaKey','fileSha256','fileEncSha256'] as $field) {
                            if (!is_string($notification[$field] ?? null) || strlen($notification[$field])!==32) throw new \UnexpectedValueException('History notification missing '.$field);
                        }
                        $path=tempnam(sys_get_temp_dir(),'mvo-history-');
                        if ($path===false) throw new \RuntimeException('Cannot spool history download');
                        try {
                            $this->media->downloadStream([...$notification,'mediaType'=>'history'],$path);
                            $this->active();
                            $source=fopen($path,'rb');
                            if ($source===false) throw new \RuntimeException('Cannot open history download');
                            return $this->inflateStream($source);
                        } finally { if (is_file($path)) unlink($path); }
                    },[...$notification,'id'=>$job['id']]);
                } elseif(in_array($syncType,[7,8,'NO_HISTORY','MESSAGE_ACCESS_STATUS'],true))$result=['syncType'=>$syncType,'messagesCount'=>0,'conversationsCount'=>0,'pushnamesCount'=>0,'inlineContactsCount'=>0];
                else {
                    $inline=$notification['initialHistBootstrapInlinePayload'] ?? null;
                    if (is_string($inline) && $inline!=='') $compressed=$inline;
                    else {
                        foreach (['mediaKey','fileSha256','fileEncSha256'] as $field) {
                            if (!is_string($notification[$field] ?? null) || strlen($notification[$field])!==32) throw new \UnexpectedValueException('History notification missing '.$field);
                        }
                        $compressed=$this->media->download([...$notification,'mediaType'=>'history']);
                    }
                    $result=$this->ingestCompressed($compressed,$notification);
                }
                $this->active();
                $this->signalStore->transaction(function() use($key):void {
                    $this->signalStore->set('history-processed',(string)$key,(string)time());
                    $this->signalStore->delete('history-pending',(string)$key);
                });
                $safe=array_intersect_key($result,array_flip(['syncType','chunkOrder','progress','messagesCount','conversationsCount','pushnamesCount','inlineContactsCount','omittedConversationsCount','callLogsCount','stickersCount']));
                $ledger->update((string)$key,['state'=>'imported','result'=>$safe,'completedAt'=>time(),'error'=>null]);
                $ledger->received($notification);
                ($this->receipt)(['id'=>$job['id'],'remoteJid'=>$job['from']],'hist_sync');
                $this->active();
                ($this->emit)('history_sync_chunk',[...$result,'id'=>$job['id'],'syncId'=>(string)$key,'state'=>'imported']);
                $this->active();
            } catch (\Throwable $error) {
                if ($this->closed) throw $error;
                $job['attempt']=($job['attempt'] ?? 0)+1;
                $job['nextAttempt']=time()+min(3600,2**min(10,$job['attempt']));
                $this->signalStore->set('history-pending',(string)$key,BinaryData::encode($job));
                $imported=$this->signalStore->get('history-processed',(string)$key)!==null;
                $code=$imported?'history_receipt_pending':HistorySyncStore::error($error);$state=$imported?'imported':'failed';
                (new HistorySyncStore($this->signalStore))->update((string)$key,['state'=>$state,'receiptPending'=>$imported,'attempt'=>$job['attempt'],'nextAttempt'=>$job['nextAttempt'],'error'=>$code]);
                ($this->emit)('history_sync_error',['id'=>$job['id'] ?? '', 'syncId'=>(string)$key,'state'=>$state,'attempt'=>$job['attempt'],'error'=>$code]);
                $this->active();
            }
        }
        $this->drainGroupHistory();
    }

    /** Keep inline payloads from accumulating in a 100-job in-memory page.
     * @return \Generator<string,string> */
    private function pendingHistory():\Generator
    {
        $after=''; $count=0;
        while ($count<100 && ($page=$this->signalStore->entries('history-pending',$this->sink===null ? 100 : 1,$after))!==[]) {
            foreach ($page as $key=>$bytes) {
                $after=(string)$key; ++$count;
                yield (string)$key=>$bytes;
            }
        }
    }

    /** Trusted imports/tests can supply the same compressed HistorySync used by the primary.
     * @param array<string,mixed> $notification
     * @return array<string,mixed> */
    public function ingestCompressed(string $compressed,array $notification=[]):array
    {
        $this->active();
        if ($this->sink!==null) return $this->sinkAttempt(fn()=>$this->inflateStream($this->stringStream($compressed)),$notification);
        return $this->ingest($this->inflate($compressed),$notification);
    }

    /** All external callbacks run outside the session database transaction.
     * @param \Closure():resource $obtain Verified and expanded HistorySync at offset zero.
     * @param array<string,mixed> $notification
     * @return array<string,mixed> */
    private function sinkAttempt(\Closure $obtain,array $notification):array
    {
        $this->active();
        if ($this->importing) throw new \LogicException('History ingestion is not reentrant');
        $sink=$this->sink ?? throw new \LogicException('History sink is not configured');
        $this->importing=true; $source=null; $committed=false;
        $chunk=['id'=>$notification['id'] ?? '', 'syncType'=>$notification['syncType'] ?? null,
            'chunkOrder'=>$notification['chunkOrder'] ?? null,'progress'=>$notification['progress'] ?? null];
        try {
            $source=$obtain();
            $this->active();
            $chunk=[...$chunk,...HistorySyncReader::metadata($source)];
            if (!is_string($chunk['id']) || $chunk['id']==='') {
                rewind($source); $hash=hash_init('sha256'); hash_update_stream($hash,$source); $chunk['id']=hash_final($hash);
            }
            foreach (HistorySyncReader::items($source) as $item) {
                $this->active();
                $sink->item($item,$chunk);
                $this->active();
            }
            $result=$this->signalStore->transaction(function() use($source,$chunk):array {
                $this->active();
                $importer=new HistorySyncImporter($this->signalStore,$this->getCredentials,$chunk,$this->persist);
                foreach (HistorySyncReader::items($source) as $item) $importer->item($item);
                return $importer->result();
            });
            $this->active();
            $sink->commit([...$chunk,...$result]);
            $committed=true;
            $this->active();
            return $result;
        } catch (\Throwable $error) {
            // Cleanup must never mask the failure that controls receipt/retry decisions.
            if (!$committed) try { $sink->abort($chunk,$error); } catch (\Throwable) {}
            throw $error;
        } finally {
            if (is_resource($source)) fclose($source);
            $this->importing=false;
        }
    }

    /** @return resource */
    private function stringStream(string $bytes)
    {
        $stream=\MVO\Media\StreamMediaCrypto::temporary();
        try { \MVO\Media\StreamMediaCrypto::write($stream,$bytes); rewind($stream); return $stream; }
        catch (\Throwable $error) { fclose($stream); throw $error; }
    }

    /** Consume the compressed stream; verify the complete zlib/gzip stream before exposing items.
     * @param resource $source
     * @return resource */
    private function inflateStream($source)
    {
        $target=null;
        try {
            $prefix=fread($source,2);
            if ($prefix===false || fseek($source,0)!==0) throw new \RuntimeException('Cannot read compressed history');
            $ctx=inflate_init($prefix==="\x1f\x8b" ? ZLIB_ENCODING_GZIP : ZLIB_ENCODING_DEFLATE);
            if ($ctx===false) throw new \RuntimeException('Cannot initialize history inflater');
            $target=\MVO\Media\StreamMediaCrypto::temporary(); $read=0; $written=0;
            while (!feof($source)) {
                $this->active();
                // A small compressed input block also bounds highly compressible output bursts.
                $bytes=fread($source,1024);
                if ($bytes===false || ($bytes==='' && !feof($source))) throw new \RuntimeException('Cannot read compressed history');
                if ($bytes==='') break;
                $read+=strlen($bytes);
                $part=@inflate_add($ctx,$bytes,ZLIB_SYNC_FLUSH);
                if ($part===false) throw new \UnexpectedValueException('Invalid compressed history');
                $written+=strlen($part);
                if ($written>$this->maxInflatedBytes) throw new \LengthException('History exceeds inflated byte limit');
                \MVO\Media\StreamMediaCrypto::write($target,$part);
            }
            if ($read===0 || inflate_get_status($ctx)!==ZLIB_STREAM_END || inflate_get_read_len($ctx)!==$read) throw new \UnexpectedValueException('Truncated or trailing compressed history');
            rewind($target); return $target;
        } catch (\Throwable $error) { if (is_resource($target)) fclose($target); throw $error; }
        finally { fclose($source); }
    }

    private function inflate(string $compressed):string
    {
        $ctx=inflate_init(str_starts_with($compressed,"\x1f\x8b") ? ZLIB_ENCODING_GZIP : ZLIB_ENCODING_DEFLATE);
        if ($ctx===false) throw new \RuntimeException('Cannot initialize history inflater');
        $inflated=''; $length=strlen($compressed);
        for ($offset=0;$offset<$length;$offset+=16384) {
            $part=@inflate_add($ctx,substr($compressed,$offset,16384),$offset+16384>=$length ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if ($part===false) throw new \UnexpectedValueException('Malformed compressed history');
            if (strlen($inflated)>$this->maxInflatedBytes-strlen($part)) throw new \LengthException('History exceeds inflated byte limit');
            $inflated.=$part;
        }
        if ($length===0 || inflate_get_status($ctx)!==ZLIB_STREAM_END || inflate_get_read_len($ctx)!==$length) throw new \UnexpectedValueException('Truncated or trailing compressed history');
        return $inflated;
    }

    private function drainGroupHistory():void
    {
        foreach ($this->signalStore->entries('group-history-pending',100) as $key=>$bytes) {
            $this->active();
            $job=BinaryData::decode($bytes);
            if (!is_array($job) || !is_array($job['event'] ?? null) || !is_array($job['bundle'] ?? null)) throw new \UnexpectedValueException('Invalid group-history job');
            if (($job['nextAttempt'] ?? 0)>time()) continue;
            try {
                foreach (['mediaKey','fileSha256','fileEncSha256'] as $field) if (!is_string($job['bundle'][$field] ?? null) || strlen($job['bundle'][$field])!==32) throw new \UnexpectedValueException('Invalid group-history '.$field);
                $compressed=$this->media->download([...$job['bundle'],'mediaType'=>'group-history']);
                $this->active();
                $result=$this->ingestGroupCompressed($compressed,$job['event']['chat']);
                $this->signalStore->transaction(function() use($key):void {
                    $this->signalStore->set('group-history-processed',(string)$key,(string)time());
                    $this->signalStore->delete('group-history-pending',(string)$key);
                });
                ($this->emit)('group_history_bundle',[...$result,'groupJid'=>$job['event']['chat'],'senderJid'=>$job['event']['from'] ?? null,
                    'bundleMessageId'=>$job['event']['id'],'historyReceivers'=>$job['bundle']['messageHistoryMetadata']['historyReceivers'] ?? []]);
                $this->active();
            } catch (\Throwable $error) {
                if ($this->closed) throw $error;
                $job['attempt']=($job['attempt'] ?? 0)+1; $job['nextAttempt']=time()+min(3600,2**min(10,$job['attempt']));
                $this->signalStore->set('group-history-pending',(string)$key,BinaryData::encode($job));
                ($this->emit)('history_sync_error',['id'=>$job['event']['id'] ?? '', 'groupJid'=>$job['event']['chat'] ?? '', 'error'=>HistorySyncStore::error($error)]);
                $this->active();
            }
        }
    }

    /** Imports a verified GroupHistory payload, dropping foreign/expired/outdated messages.
     * @return array<string,mixed> */
    public function ingestGroupCompressed(string $compressed,string $group,?int $now=null):array
    {
        $this->active();
        $group=Jid::parse($group)->canonical()->bare();
        if (!str_ends_with($group,'@g.us')) throw new \InvalidArgumentException('Group history requires g.us');
        $bytes=$this->inflate($compressed); $now ??= time();
        return $this->signalStore->transaction(function() use($bytes,$group,$now):array {
            $result=['messagesCount'=>0,'outOfWindowPinsCount'=>0,'droppedCount'=>0,'oldestTimestampMs'=>null];
            foreach (self::fields($bytes) as [$number,$wire,$value]) {
                if (!in_array($number,[1,4,5],true) || $wire!==2 || !is_string($value)) continue;
                $info=SchemaCodec::decode('WebMessageInfo',$value); $pin=$number===4;
                $theme=$number===5;
                if ($pin) ++$result['outOfWindowPinsCount'];
                $timestamp=$info['messageTimestamp'] ?? 0; $duration=$info['ephemeralDuration'] ?? 0; $start=$info['ephemeralStartTimestamp'] ?? $timestamp;
                if (!is_string($info['key']['id'] ?? null) || !is_array($info['message'] ?? null) ||
                    (isset($info['key']['remoteJid']) && $info['key']['remoteJid']!==$group) ||
                    (is_int($duration) && $duration>0 && is_int($start) && $start>0 && $start+$duration<=$now) ||
                    (!$pin && !$theme && is_int($timestamp) && $timestamp>0 && $timestamp+2419200<$now)) {
                    ++$result['droppedCount']; continue;
                }
                $this->store->recordWebMessage($info,$group,($this->getCredentials)()['meJid'] ?? null); ++$result['messagesCount'];
                if($theme){
                    $protocol=\MVO\Message\ContentDecoder::unwrap($info['message']??[])['message']['protocolMessage']??[];$setting=$protocol['chatThemeSetting']??null;
                    $previous=$this->store->getChat($group)['groupTheme']['settingTimestampMs']??0;
                    if(is_array($setting)&&in_array($protocol['type']??null,[34,'CHAT_THEME_SETTING'],true)&&is_int($setting['settingTimestampMs']??null)&&$setting['settingTimestampMs']>=$previous){
                        $this->store->upsertChat(['jid'=>$group,'groupTheme'=>$setting,'groupThemeMessageId'=>$info['key']['id']??null]);
                        $result['themeMessageCount']=($result['themeMessageCount']??0)+1;
                    }
                }
                if (!$theme && is_int($timestamp) && $timestamp>0) $result['oldestTimestampMs']=$result['oldestTimestampMs']===null ? $timestamp*1000 : min($result['oldestTimestampMs'],$timestamp*1000);
            }
            return $result;
        });
    }

    /** Atomic ingestion: malformed chunks commit neither conversations nor messages.
     * @param array<string,mixed> $notification
     * @return array<string,mixed> */
    public function ingest(string $bytes,array $notification=[]):array
    {
        $this->active();
        if ($this->sink!==null) return $this->sinkAttempt(function() use($bytes) {
            if (strlen($bytes)>$this->maxInflatedBytes) throw new \LengthException('History exceeds inflated byte limit');
            return $this->stringStream($bytes);
        },$notification);
        if (strlen($bytes)>$this->maxInflatedBytes) throw new \LengthException('History exceeds inflated byte limit');
        return $this->signalStore->transaction(function() use($bytes,$notification):array {
            $result=['syncType'=>$notification['syncType'] ?? null,'chunkOrder'=>$notification['chunkOrder'] ?? null,
                'progress'=>$notification['progress'] ?? null,'messagesCount'=>0,'conversationsCount'=>0,'pushnamesCount'=>0,'inlineContactsCount'=>0];
            $pushnames=[]; $contacts=[]; $mappings=[];
            foreach (self::fields($bytes) as $field) {
                [$number,$wire,$value]=$field;
                if ($wire===0) {
                    if ($number===1) $result['syncType']=$value;
                    if ($number===5) $result['chunkOrder']=$value;
                    if ($number===6) $result['progress']=$value;
                    if ($number===24){$this->store->omittedInventoryProvided($value!==0);$result['omittedConversationsProvided']=$value!==0;}
                    continue;
                }
                if ($wire!==2 || !is_string($value)) continue;
                if ($number===2) {
                    $this->conversation($value,$result,$mappings);
                } elseif ($number===3) {
                    $info=SchemaCodec::decode('WebMessageInfo',$value);
                    $chat=$info['key']['remoteJid'] ?? 'status@broadcast';
                    $this->store->recordWebMessage($info,$chat,($this->getCredentials)()['meJid'] ?? null);
                    ++$result['messagesCount'];
                } elseif ($number===7) $pushnames[]=SchemaCodec::decode('Pushname',$value);
                elseif ($number===8) \MVO\Service\AccountExtensionsService::observeGlobalSettings($this->signalStore,SchemaCodec::decode('GlobalSettings',$value));
                elseif ($number===15) $mappings[]=SchemaCodec::decode('PhoneNumberToLIDMapping',$value);
                elseif ($number===20) $contacts[]=SchemaCodec::decode('InlineContact',$value);
                elseif ($number===19) $this->signalStore->set('history-meta','nctSalt',$value);
                elseif ($number===23){$this->store->recordOmittedConversation(SchemaCodec::decode('RecoverableHistoryConversation',$value));$result['omittedConversationsCount']=($result['omittedConversationsCount']??0)+1;}
                elseif($number===13){$this->store->observed()->callLog(SchemaCodec::decode('CallLogRecord',$value));$result['callLogsCount']=($result['callLogsCount']??0)+1;}
                elseif($number===12){$this->store->messageObservations()->pastParticipants(SchemaCodec::decode('PastParticipants',$value));$result['pastParticipantGroupsCount']=($result['pastParticipantGroupsCount']??0)+1;}
                elseif(in_array($number,[11,22],true)){$this->store->observed()->sticker(SchemaCodec::decode('StickerMetadata',$value),$number===11?'recent':'favorite');$result['stickersCount']=($result['stickersCount']??0)+1;}
            }
            foreach ($contacts as $contact) if (isset($contact['pnJid'],$contact['lidJid'])) $mappings[]=$contact;
            $pnToLid=[];
            foreach ($mappings as $mapping) {
                if (!is_string($mapping['pnJid'] ?? null) || !is_string($mapping['lidJid'] ?? null)) continue;
                $pn=Jid::parse($mapping['pnJid'])->canonical(); $lid=Jid::parse($mapping['lidJid'])->canonical();
                if ($pn->server!=='s.whatsapp.net' || $lid->server!=='lid') throw new \UnexpectedValueException('Invalid history PN/LID mapping');
                $this->signalStore->set('message-pn-to-lid',$pn->bare(),$lid->bare());
                $this->signalStore->set('message-lid-to-pn',$lid->bare(),$pn->bare());
                $pnToLid[$pn->bare()]=$lid->bare();
                $this->store->upsertContact(['jid'=>$lid->bare(),'phoneNumber'=>$pn->bare()]);
            }
            foreach ($pushnames as $pushname) {
                if (!is_string($pushname['id'] ?? null) || $pushname['id']==='') continue;
                $jid=Jid::parse($pushname['id'])->canonical()->bare();
                $this->store->upsertContact(['jid'=>$pnToLid[$jid] ?? $jid,'pushName'=>$pushname['pushname'] ?? '']);
                ++$result['pushnamesCount'];
            }
            foreach ($contacts as $contact) {
                $jid=$contact['lidJid'] ?? $contact['pnJid'] ?? null;
                if (!is_string($jid) || $jid==='') continue;
                $record=['jid'=>$jid,'displayName'=>$contact['fullName'] ?? $contact['firstName'] ?? ''];
                if (isset($contact['username'])) $record['username']=$contact['username'];
                if (isset($contact['pnJid'])) $record['phoneNumber']=$contact['pnJid'];
                $this->store->upsertContact($record); ++$result['inlineContactsCount'];
            }
            return $result;
        });
    }

    /**
     * @param array<string,mixed> $result
     * @param list<array<string,mixed>> $mappings
     */
    private function conversation(string $bytes,array &$result,array &$mappings):void
    {
        // Messages can precede the ID in protobuf. Decode the bounded header first.
        $header=''; $messages=[];
        foreach (self::fields($bytes) as [$number,$wire,$value,$raw]) {
            if ($number===2 && $wire===2 && is_string($value)) $messages[]=$value;
            else $header.=$raw;
        }
        $chat=SchemaCodec::decode('Conversation',$header); $jid=$chat['id'] ?? null;
        if (!is_string($jid) || $jid==='') throw new \UnexpectedValueException('History conversation missing ID');
        $jid=Jid::parse($jid)->canonical()->bare();
        $this->store->upsertChat([...$chat,'jid'=>$jid]); ++$result['conversationsCount'];
        foreach($chat['participant']??[]as$participant)if(is_array($participant)&&is_string($participant['userJid']??null)&&is_array($participant['memberLabel']??null))$this->store->messageObservations()->memberLabel($jid,$participant['userJid'],$participant['memberLabel']);
        $this->store->reconcileOmittedConversation($jid);
        $lid=$chat['lidJid'] ?? $chat['accountLid'] ?? null;
        if (isset($chat['pnJid']) && is_string($lid)) $mappings[]=['pnJid'=>$chat['pnJid'],'lidJid'=>$lid];
        if (isset($chat['displayName']) || isset($chat['username'])) {
            $this->store->upsertContact(['jid'=>$lid ?? $jid,'displayName'=>$chat['displayName'] ?? '', 'username'=>$chat['username'] ?? '']);
        }
        foreach ($messages as $messageBytes) {
            $history=SchemaCodec::decode('HistorySyncMsg',$messageBytes);
            if (!is_array($history['message'] ?? null)) continue;
            $this->store->recordWebMessage($history['message'],$jid,($this->getCredentials)()['meJid'] ?? null);
            ++$result['messagesCount'];
        }
    }

    /** Yield one protobuf field, avoiding a decoded tree for the whole history chunk.
     * @return \Generator<int,array{int,int,int|string,string}> */
    private static function fields(string $bytes):\Generator
    {
        $offset=0; $length=strlen($bytes); $count=0;
        while ($offset<$length) {
            if (++$count>1000000) throw new \LengthException('History field count exceeded');
            $start=$offset; $tag=self::varint($bytes,$offset); $number=$tag>>3; $wire=$tag&7;
            if ($number<1 || $number>536870911) throw new \UnexpectedValueException('Invalid history protobuf field');
            if ($wire===0) $value=self::varint($bytes,$offset);
            else {
                $size=match($wire) {1=>8,5=>4,2=>self::varint($bytes,$offset),default=>throw new \UnexpectedValueException('Invalid history wire type')};
                if ($size>16777215 || $size>$length-$offset) throw new \LengthException('History record exceeds limit or is truncated');
                $value=substr($bytes,$offset,$size); $offset+=$size;
            }
            yield [$number,$wire,$value,substr($bytes,$start,$offset-$start)];
        }
    }
    private static function varint(string $bytes,int &$offset):int
    {
        $result=0;
        for ($shift=0;$shift<=56;$shift+=7) {
            if (!isset($bytes[$offset])) throw new \UnexpectedValueException('Truncated history varint');
            $byte=ord($bytes[$offset++]); $result|=($byte&127)<<$shift;
            if (($byte&128)===0) return $result;
        }
        throw new \OverflowException('History integer exceeds supported range');
    }
}
