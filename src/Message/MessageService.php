<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Protocol\{BinaryCodec,BinaryData,BinaryNode,Jid,SchemaCodec};
use MVO\Media\{MediaTransfer,MediaExpiredException};
use MVO\History\{AlbumInbox,HistoryService,InteractionStore,StatusStore};
use MVO\Signal\{SignalProtocol,SignalStore};
use MVO\Service\{IdentityService,PassiveTasksService,TrustedContactTokenService};

/** Durable native PHP direct/group messaging and encrypted media transfer. */
final class MessageService
{
    private const OUTGOING_PROJECTION_PENDING='message-outgoing-projection-pending-v1';
    private const OUTGOING_PROJECTION_DONE='message-outgoing-projection-done-v1';
    private const OUTGOING_PROJECTION_BATCH=16;
    private string $outgoingProjectionCursor='';
    private const RECIPIENT_RETRY_BLOCKED_CLEANUP_BATCH=16;
    private string $recipientRetryBlockedCursor='';
    private int $recipientRetryBlockedCleanupAt=0;
    /**
     * @var array<string,true> */
    private array $projectingOutgoing=[];
    private bool $preKeysPending = false;
    private int $operationDepth = 0;
    private bool $closeRequested = false;
    /**
     * @var (\Closure():void)|null */
    private ?\Closure $groupStatusGuard=null;
    private readonly MediaTransfer $media;
    private readonly GroupTransport $groups;
    private readonly HistoryService $history;
    private ?StatusStore $statuses=null;
    private ?InteractionStore $interactionStore=null;
    private ?AlbumInbox $albumInbox=null;
    private readonly ReceiptQueue $receipts;
    private readonly MediaRetryService $mediaRetry;
    private readonly TrustedContactTokenService $privacyTokens;
    private readonly PassiveTasksService $passiveTasks;
    /**
     * @var array<string,true> */
    private array $deliveringInbox=[];

    /**
     *
     * @param \Closure():array<string,mixed> $getCredentials
     *
     * @param \Closure(array<string,mixed>):void $saveCredentials
     *
     * @param \Closure(BinaryNode,float):BinaryNode $query Correlates IQ results and message ACKs.
     *
     * @param \Closure(BinaryNode):void $sendNode
     *
     * @param \Closure(string,array<string,mixed>):void $emit
     *
     * @param \Closure(float):void|null $pump Advances incoming notifications while awaiting media reupload.
     *
     * @param \Closure(string):string|null $usernameResolver Resolves usernames to authenticated account JIDs.
     */
    public function __construct(
        private readonly SignalProtocol $signal,
        private readonly SignalStore $store,
        private readonly \Closure $getCredentials,
        private readonly \Closure $saveCredentials,
        private readonly \Closure $query,
        private readonly \Closure $sendNode,
        private readonly \Closure $emit,
        ?MediaTransfer $media = null,
        private readonly ?\Closure $pump = null,
        private readonly ?\Closure $usernameResolver = null,
    ) {
        $this->media = $media ?? new MediaTransfer(fn(BinaryNode $node):BinaryNode => ($this->query)($node,30.0));
        $this->groups = new GroupTransport($signal,$store,$query,
            fn(array $users):array => $this->discoverDevices($users),
            function(array $devices):void { $this->ensureSessions($devices); },
            $getCredentials,fn(string $jid):string => $this->sessionJid($jid,($this->getCredentials)()));
        $this->receipts = new ReceiptQueue($store,function(BinaryNode $node) use($sendNode):void {
            $sendNode($node);
            $this->recordStatusReadReceipt($node);
        });
        $this->history = new HistoryService($store,$this->media,$getCredentials,$emit,
            function(array $key,string $type):void { $this->sendReceipt([$key],$type); });
        $this->mediaRetry=new MediaRetryService($store,$getCredentials,$sendNode,$emit);
        $this->privacyTokens=new TrustedContactTokenService($query,$store,$getCredentials);
        $this->passiveTasks=new PassiveTasksService($query,$store,$signal,$getCredentials,$saveCredentials,function():void{$this->initializeInternal();});
    }

    public function history():HistoryService { return $this->history; }
    public function statusStore():StatusStore { return $this->statuses??=new StatusStore($this->store,$this->history->store); }
    public function interactions():InteractionStore { return $this->interactionStore??=new InteractionStore($this->store,$this->history->store); }
    private function albums():AlbumInbox { return $this->albumInbox??=new AlbumInbox($this->store); }
    public function privacyTokens():TrustedContactTokenService {return $this->privacyTokens;}
    public function passiveTasks():PassiveTasksService {return $this->passiveTasks;}
    public function setGroupStatusGuard(?callable $guard):void{$this->groupStatusGuard=$guard===null?null:\Closure::fromCallable($guard);}
    /** Refresh every requested device from authenticated server bundles in one transaction.
     * Existing sessions and trusted identities survive a missing/invalid bundle or a network failure.
     * $jid accepts a PN/LID account or one explicit device; hosted variants use canonical Signal addresses.
     *
     * @return array{jid:string,devices:list<string>,synced:int} */
    public function syncSignalSession(string $jid):array
    {
        return $this->operation(function() use($jid):array {
            $address=Jid::recipient($jid)->canonical();
            if(!in_array($address->server,['s.whatsapp.net','lid'],true)||!preg_match('/^[0-9]{1,20}$/D',$address->user)||$address->device>255)throw new \InvalidArgumentException('Signal session target must be a PN/LID user with device 0..255');
            $credentials=($this->getCredentials)();
            if(($credentials['registered']??false)!==true||!is_string($credentials['meJid']??null))throw new \LogicException('Pair the account before synchronizing Signal sessions');
            $target=Jid::parse($this->sessionJid((string)$address,$credentials));
            if($address->device!==0||preg_match('/:[0-9]+@/D',$jid)===1)$devices=[(string)$target];
            else{
                $devices=$this->discoverDevices([$target->bare()])[$target->bare()]??[];
                if($devices===[])throw new \UnexpectedValueException('No devices returned for Signal session synchronization');
                $devices=array_map(static fn(string $device):string=>(string)Jid::parse($device)->canonical(),$devices);
            }
            $devices=array_values(array_unique($devices));sort($devices,SORT_STRING);
            $this->ensureSessions($devices,true);
            return ['jid'=>(string)$address,'devices'=>$devices,'synced'=>count($devices)];
        });
    }
    /** Authenticated call-signaling bridge; calls reuse the same device sessions as messages.
     *
     * @return list<string> */
    public function resolveCallDevices(string $peer):array {
        return $this->operation(function() use($peer):array {
            $jid=Jid::recipient($peer)->canonical();
            if (!in_array($jid->server,['s.whatsapp.net','lid'],true)) throw new \InvalidArgumentException('Call targets must be user accounts');
            if ($jid->device!==0) return [(string)$jid];
            $route=$this->resolveRecipient($jid,($this->getCredentials)());
            $list=$this->discoverDevices([$route['to']])[$route['to']] ?? [];
            // Calls always ring the routed primary first, even when usync lists only companions.
            return array_values(array_unique([$route['to'],...$list]));
        });
    }
    /** Ratchets and exact encrypted call-key envelopes commit together before signaling I/O.
     *
     * @param list<string> $devices
     *
     * @return array{nodes:list<BinaryNode>,deviceIdentity:?string} */
    public function encryptCallKeys(array $devices,string $paddedMessage):array {
        return $this->operation(function() use($devices,$paddedMessage):array {
            if ($devices===[] || count($devices)>1000 || strlen($paddedMessage)>65536) throw new \InvalidArgumentException('Invalid call-key fanout');
            MessageCodec::unpad($paddedMessage);
            $canonical=[];
            foreach ($devices as $device) {
                $jid=Jid::parse($device)->canonical();
                if (!in_array($jid->server,['s.whatsapp.net','lid'],true)) throw new \InvalidArgumentException('Invalid call-key device');
                $canonical[]=(string)$jid;
            }
            $canonical=array_values(array_unique($canonical)); sort($canonical,SORT_STRING);
            $key=hash('sha256',BinaryData::encode([$canonical,$paddedMessage]));
            $cached=$this->store->get('call-key-envelopes',$key);
            if ($cached!==null) return self::decodeCallEnvelope($cached);
            $this->ensureSessions($canonical); $credentials=($this->getCredentials)();
            return $this->store->transaction(function() use($canonical,$paddedMessage,$credentials,$key):array {
                $nodes=[]; $identity=false; $codec=new BinaryCodec(); $stored=[];
                foreach ($canonical as $device) {
                    $encrypted=$this->signal->encrypt($this->sessionJid($device,$credentials),$paddedMessage);
                    $identity=$identity || $encrypted['type']==='pkmsg';
                    $node=new BinaryNode('to',['jid'=>$device],[new BinaryNode('enc',['v'=>'2','type'=>$encrypted['type']],$encrypted['ciphertext'])]);
                    $nodes[]=$node; $stored[]=$codec->encode($node);
                }
                $deviceIdentity=$identity && self::needsDeviceIdentity($credentials) ? self::deviceIdentity($credentials) : null;
                $this->store->set('call-key-envelopes',$key,BinaryData::encode(['nodes'=>$stored,'deviceIdentity'=>$deviceIdentity]));
                return ['nodes'=>$nodes,'deviceIdentity'=>$deviceIdentity];
            });
        });
    }
    /**
     * @return array{nodes:list<BinaryNode>,deviceIdentity:?string} */
    private static function decodeCallEnvelope(string $bytes):array {
        $record=BinaryData::decode($bytes);
        if (!is_array($record) || !is_array($record['nodes'] ?? null) || !array_key_exists('deviceIdentity',$record) || ($record['deviceIdentity']!==null && !is_string($record['deviceIdentity']))) throw new \UnexpectedValueException('Invalid persisted call-key envelope');
        $nodes=[]; foreach ($record['nodes'] as $node) { if (!is_string($node)) throw new \UnexpectedValueException('Invalid persisted call-key node'); $nodes[]=(new BinaryCodec())->decode($node); }
        return ['nodes'=>$nodes,'deviceIdentity'=>$record['deviceIdentity']];
    }
    /** Authenticated padded WAProto call-key plaintext; callers validate/unpad its content. */
    public function decryptCallKey(string $sender,string $type,string $ciphertext):string {
        return $this->operation(fn():string=>$this->signal->decrypt($this->sessionJid($sender,($this->getCredentials)()),$type,$ciphertext));
    }

    /** Send read/played/delivery receipts. Keys accept message events, or WAProto MessageKey values.
     *
     * @param list<array<string,mixed>> $keys */
    public function sendReceipt(array $keys,string $type='read'):void
    {
        $this->operation(function() use($keys,$type):void {
            if (!in_array($type,['read','played','read-self','played-self','delivery','hist_sync','peer_msg'],true)) throw new \InvalidArgumentException('Unsupported receipt type');
            $groups=[];
            foreach ($keys as $item) {
                $key=is_array($item['key'] ?? null) ? [...$item,...$item['key']] : $item;
                $jid=$key['remoteJid'] ?? $key['chat'] ?? null; $id=$key['id'] ?? null;
                if (!is_string($jid) || !is_string($id) || $id==='') throw new \InvalidArgumentException('Receipt needs remoteJid and id');
                $chat=Jid::parse($jid)->canonical()->bare();
                $participant=$key['participant'] ?? null;
                if ($participant!==null) {
                    if (!is_string($participant)) throw new \InvalidArgumentException('Invalid receipt participant');
                    $participant=(string)Jid::parse($participant)->canonical();
                    if (isset($key['senderDevice']) && is_int($key['senderDevice'])) {
                        $p=Jid::parse($participant); $participant=(string)new Jid($p->user,$p->server,$key['senderDevice']);
                    }
                }
                $groupKey=$chat."\0".($participant ?? '');
                $groups[$groupKey] ??= ['jid'=>$chat,'participant'=>$participant,'ids'=>[]];
                $groups[$groupKey]['ids'][]=$id;
            }
            foreach ($groups as $group) {
                $ids=array_values(array_unique($group['ids']));
                $attrs=['id'=>$ids[0],'to'=>$group['jid']];
                if ($type!=='delivery') $attrs['type']=$type;
                if ($type==='read' || $type==='played') $attrs['t']=(string)time();
                if ($group['participant']!==null) $attrs['participant']=$group['participant'];
                $content=count($ids)>1 ? [new BinaryNode('list',[],array_map(static fn(string $id):BinaryNode=>new BinaryNode('item',['id'=>$id]),array_slice($ids,1)))] : null;
                $this->receipts->enqueue(new BinaryNode('receipt',$attrs,$content));
            }
        });
    }

    /** Record a local view only after the durable receipt actually reached the transport. */
    private function recordStatusReadReceipt(BinaryNode $node):void
    {
        if (($node->attrs['to']??null)!==StatusStore::CHAT || !in_array($node->attrs['type']??null,['read','read-self','played','played-self'],true)) return;
        $author=$node->attrs['participant']??null;if (!is_string($author)) return;
        $ids=[$node->attrs['id']];foreach (self::children($node->child('list'),'item') as $item) if (isset($item->attrs['id'])) $ids[]=$item->attrs['id'];
        foreach (array_unique($ids) as $id) {
            $status=$this->statusStore()->getStatus($id,$author);if ($status===null || $status['fromMe'] || $status['viewed']) continue;
            $timestamp=isset($node->attrs['t']) && ctype_digit($node->attrs['t']) ? (int)$node->attrs['t'] : time();
            $this->statusStore()->markViewed($id,$author,$timestamp);
            ($this->emit)('status',['action'=>'viewed','id'=>$id,'author'=>$status['author'],'timestamp'=>$timestamp,'receiptStatus'=>'sent','source'=>'local_receipt']);
        }
    }

    /** Peer protocol messages are encrypted directly to one device of the paired account.
     *
     * @param array<string,mixed> $protocol
     *
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed> */
    public function publishProtocolMessage(string $deviceJid,array $protocol,array $options=[]):array
    {
        return $this->operation(function() use($deviceJid,$protocol,$options):array {
            $credentials=($this->getCredentials)(); $device=Jid::parse($deviceJid)->canonical();
            if (!$this->isOwn((string)$device,$credentials)) throw new \InvalidArgumentException('Peer protocol target must belong to the paired account');
            if (!in_array($device->server,['lid','s.whatsapp.net'],true)) throw new \InvalidArgumentException('Invalid peer device');
            $id=$options['id'] ?? '3EB0'.strtoupper(bin2hex(random_bytes(9)));
            if (!is_string($id) || $id==='') throw new \InvalidArgumentException('Invalid protocol message ID');
            if ($this->outbox($id)!==null) throw new \LogicException('Message ID already exists; use retryMessage for uncertain sends');
            $priority=$options['pushPriority'] ?? 'high';
            if (!in_array($priority,['high','high_force'],true)) throw new \InvalidArgumentException('Invalid protocol push priority');
            $built=ContentBuilder::build(['type'=>'raw','message'=>['protocolMessage'=>$protocol]],$device->bare());
            $this->ensureSessions([(string)$device]);
            $metadata=['id'=>$id,'to'=>$device->bare(),'status'=>'pending','timestamp'=>time(),'type'=>'protocol'];
            $node=$this->store->transaction(function() use($built,$device,$credentials,$id,$metadata,$priority):BinaryNode {
                $encrypted=$this->signal->encrypt($this->sessionJid((string)$device,$credentials),MessageCodec::pad($built['bytes']));
                $children=[new BinaryNode('enc',['v'=>'2','type'=>$encrypted['type']],$encrypted['ciphertext']),new BinaryNode('meta',['appdata'=>'default'])];
                if ($encrypted['type']==='pkmsg' && self::needsDeviceIdentity($credentials)) $children[]=new BinaryNode('device-identity',[],self::deviceIdentity($credentials));
                $node=new BinaryNode('message',['id'=>$id,'to'=>(string)$device,'type'=>'text','category'=>'peer','push_priority'=>$priority],$children);
                $this->persistOutgoing($node,$metadata,$built,$credentials['meJid'],[(string)$device]);
                return $node;
            });
            return $this->submit($node,$metadata);
        });
    }

    /** Asynchronously requests an older history page from the primary phone.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed> */
    public function requestHistorySync(array $input):array
    {
        $chat=$input['chatJid'] ?? null;
        if (!is_string($chat)) throw new \InvalidArgumentException('History request needs chatJid');
        $request=['chatJid'=>Jid::recipient($chat)->canonical()->bare(),'supportInlineResponse'=>true];
        foreach (['oldestMsgId','oldestMsgFromMe','oldestMsgTimestampMs'] as $field) if (array_key_exists($field,$input)) $request[$field]=$input[$field];
        if (isset($request['oldestMsgId']) && (!is_string($request['oldestMsgId']) || $request['oldestMsgId']==='')) throw new \InvalidArgumentException('Invalid history anchor ID');
        if (isset($request['oldestMsgFromMe']) && !is_bool($request['oldestMsgFromMe'])) throw new \InvalidArgumentException('Invalid history anchor fromMe');
        if (isset($request['oldestMsgTimestampMs']) && (!is_int($request['oldestMsgTimestampMs']) || $request['oldestMsgTimestampMs']<0)) throw new \InvalidArgumentException('Invalid history anchor timestamp');
        if (isset($input['count'])) {
            if (!is_int($input['count']) || $input['count']<1 || $input['count']>10000) throw new \InvalidArgumentException('History count must be 1..10000');
            $request['onDemandMsgCount']=$input['count'];
        }
        $me=($this->getCredentials)()['meJid'] ?? null;
        if (!is_string($me)) throw new \LogicException('History requires a paired account');
        return $this->publishProtocolMessage(Jid::parse($me)->canonical()->bare(),[
            'type'=>'PEER_DATA_OPERATION_REQUEST_MESSAGE',
            'peerDataOperationRequestMessage'=>['peerDataOperationRequestType'=>'HISTORY_SYNC_ON_DEMAND','historySyncOnDemandRequest'=>$request],
        ],['pushPriority'=>'high_force']);
    }

    /** Close SQLite after any in-flight send has persisted its final/uncertain status. */
    public function close(): void
    {
        $this->closeRequested = true;
        if ($this->operationDepth === 0) $this->store->close();
    }

    /**
     *
     * @template T
     *
     * @param callable():T $operation
     *
     * @return T
     */
    private function operation(callable $operation): mixed
    {
        if ($this->closeRequested && $this->operationDepth === 0) throw new \LogicException('Message service is closed');
        ++$this->operationDepth;
        try { return $operation(); }
        finally { if (--$this->operationDepth === 0 && $this->closeRequested) $this->store->close(); }
    }

    /** Upload keys only after saving their private counterparts locally. */
    public function initialize(): void
    {
        $this->operation(fn() => $this->initializeInternal());
    }

    private function initializeInternal(): void
    {
        $credentials = ($this->getCredentials)();
        $keys = $this->store->pendingPreKeys();
        if (($credentials['serverHasPreKeys'] ?? false) === true && $keys === [] && !$this->preKeysPending) return;
        if ($keys === []) $keys = $this->store->generatePreKeys(812);
        $children = [
            new BinaryNode('identity', [], $credentials['identityKeyPair']['public']),
            new BinaryNode('registration', [], pack('N', $credentials['registrationId'])),
            new BinaryNode('type', [], "\x05"),
            new BinaryNode('list', [], array_map(static fn(array $key):BinaryNode => new BinaryNode('key', [], [
                new BinaryNode('id', [], self::keyId($key['id'])), new BinaryNode('value', [], $key['public'])
            ]), $keys)),
            self::signedKeyNode($credentials['signedPreKey']),
        ];
        $response = ($this->query)(new BinaryNode('iq', ['type'=>'set','to'=>'s.whatsapp.net','xmlns'=>'encrypt'], $children), 30);
        self::assertIq($response);
        $this->store->markPreKeysUploaded(array_column($keys, 'id'));
        $credentials = ($this->getCredentials)();
        $credentials['serverHasPreKeys'] = true;
        ($this->saveCredentials)($credentials);
        $this->preKeysPending = false;
    }

    /** Runs queries outside the incoming-node dispatcher to avoid reentrant initialization. */
    public function drainPending(): void
    {
        $this->operation(function ():void {
            $this->drainOutgoingProjections();
            foreach ($this->pendingInbox() as $key) $this->deliverInbox($key, true);
            if ($this->preKeysPending) $this->initializeInternal();
            $this->receipts->drainPending();
            $this->history->drainPending();
            $this->drainRecipientRetries();
            try{$this->privacyTokens->drainPending();}catch(\Throwable $e){($this->emit)('service_error',['operation'=>'privacy.issueToken','error'=>$e->getMessage()]);}
            try{$this->passiveTasks->tick();}catch(\Throwable $e){($this->emit)('service_error',['operation'=>'signal.digest','error'=>$e->getMessage()]);}
        });
    }

    /**
     * Returns only after the server acknowledges this exact ID. That ACK does
     * not mean the destination has received/read the message.
     *
     * @return array<string,mixed>
     */
    public function sendText(string $to, string $text): array
    {
        return $this->sendMessage($to,$text);
    }

    /**
     * @param string|array<string,mixed> $content
     *
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed> */
    public function sendBotPrompt(string $to,string|array $content,array $options=[]):array
    {
        return $this->operation(function() use($to,$content,$options):array {
            $to=$this->resolveInputRecipient($to);
            $content=$this->prepareContent($content);
            $base=ContentBuilder::build($content,Jid::recipient($to)->canonical()->bare());
            $prompt=BotTransport::prompt($to,$base['proto'],$options);
            $result=$this->sendMessageInternal($prompt['to'],['type'=>'raw','message'=>$prompt['message']]);
            if ($prompt['threadId']!==null) $result['aiThreadId']=$prompt['threadId'];
            return $result;
        });
    }

    /** Shares a compressed, encrypted GroupHistory only with selected current members.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed> */
    public function shareGroupHistory(string $group,array $input):array
    {
        return $this->operation(function() use($group,$input):array {
            $group=Jid::recipient($group)->canonical()->bare();
            if (!str_ends_with($group,'@g.us')) throw new \InvalidArgumentException('Group history requires a g.us chat');
            $requested=$input['toJids'] ?? null; $count=$input['count'] ?? 100;
            if (!is_array($requested) || !array_is_list($requested) || $requested===[]) throw new \InvalidArgumentException('Group history needs toJids');
            if (!is_int($count) || $count<1 || $count>1000) throw new \InvalidArgumentException('Group history count must be 1..1000');
            if (isset($input['sinceMs']) && (!is_int($input['sinceMs']) || $input['sinceMs']<0)) throw new \InvalidArgumentException('Invalid group-history sinceMs');
            $plan=$this->groups->plan($group); $credentials=($this->getCredentials)();
            $members=array_values(array_unique(array_map(static fn(string $jid):string=>Jid::parse($jid)->canonical()->bare(),[...$plan['devices'],$plan['sender']])));
            $receivers=[];
            foreach ($requested as $jid) {
                if (!is_string($jid)) throw new \InvalidArgumentException('Invalid group history recipient');
                $jid=Jid::recipient($jid)->canonical()->bare();
                if ($this->isOwn($jid,$credentials)) throw new \InvalidArgumentException('Cannot share group history with this account');
                $candidate=Jid::parse($this->sessionJid($jid,$credentials))->bare(); $match=null;
                foreach ($members as $member) if (Jid::parse($this->sessionJid($member,$credentials))->bare()===$candidate) { $match=$member; break; }
                if ($match===null) throw new \InvalidArgumentException('Group history recipient is not a current member');
                $receivers[]=$match;
            }
            $receivers=array_values(array_unique($receivers));
            $messages=$input['messages'] ?? null;
            if ($messages===null) {
                $messages=[];
                foreach ($this->history->store->listMessages($group,$count) as $record) {
                    if (isset($input['sinceMs']) && ($record['timestamp'] ?? 0)*1000<$input['sinceMs']) continue;
                    if (!is_array($record['proto'] ?? null)) continue;
                    $messages[]=['key'=>['id'=>$record['id'],'remoteJid'=>$group,'fromMe'=>$record['fromMe'] ?? false,
                        'participant'=>$record['participant'] ?? $record['from'] ?? $plan['sender']],
                        'message'=>$record['proto'],'messageTimestamp'=>$record['timestamp'] ?? 0];
                }
            }
            if (!is_array($messages) || !array_is_list($messages) || $messages===[] || count($messages)>1000) throw new \InvalidArgumentException('Group history needs 1..1000 messages');
            $pins=$input['outOfWindowPinnedMessages'] ?? [];
            if (!is_array($pins) || !array_is_list($pins) || count($pins)>100) throw new \InvalidArgumentException('Invalid group history pinned messages');
            foreach ([...$messages,...$pins] as $message) {
                if (!is_array($message) || !is_string($message['key']['id'] ?? null) || !is_array($message['message'] ?? null)) throw new \InvalidArgumentException('Invalid group history WebMessageInfo');
                if (isset($message['key']['remoteJid']) && $message['key']['remoteJid']!==$group) throw new \InvalidArgumentException('Cannot share another chat in group history');
            }
            $compressed=gzcompress(\MVO\Protocol\SchemaCodec::encode('GroupHistory',['messages'=>$messages,'outOfWindowPinnedMessages'=>$pins]));
            if ($compressed===false) throw new \RuntimeException('Cannot compress group history');
            $upload=$this->media->upload($compressed,'group-history',['mimetype'=>'application/protobuf']);
            $metadata=['historyReceivers'=>$receivers,'nonHistoryReceivers'=>array_values(array_diff($members,$receivers,[Jid::parse($plan['sender'])->bare()])),
                'messageCount'=>count($messages)];
            $times=[]; foreach ($messages as $message) if (is_int($message['messageTimestamp'] ?? null)) $times[]=$message['messageTimestamp'];
            if ($times!==[]) $metadata['oldestMessageTimestampInWindow']=min($times);
            foreach ($pins as $message) if (is_int($message['messageTimestamp'] ?? null)) $times[]=$message['messageTimestamp'];
            if ($times!==[]) $metadata['oldestMessageTimestampInBundle']=min($times);
            $bundle=[]; foreach (['mediaKey','fileSha256','fileEncSha256','directPath'] as $field) $bundle[$field]=$upload[$field];
            $result=$this->sendMessageInternal($group,['type'=>'raw','message'=>['messageHistoryBundle'=>[...$bundle,'mimetype'=>'application/protobuf',
                'mediaKeyTimestamp'=>time(),'messageHistoryMetadata'=>$metadata]]]);
            $notice=null;
            try { $notice=$this->sendMessageInternal($group,['type'=>'raw','message'=>['messageHistoryNotice'=>['messageHistoryMetadata'=>$metadata]]])['id']; }
            catch (\Throwable $error) { ($this->emit)('message_error',['id'=>$result['id'],'reason'=>'group_history_notice_failed','error'=>$error->getMessage()]); }
            return ['bundleMessageId'=>$result['id'],'noticeMessageId'=>$notice,'messagesCount'=>count($messages),
                'historyReceivers'=>$receivers,'nonHistoryReceivers'=>$metadata['nonHistoryReceivers']];
        });
    }

    /** Native sender-key fanout to a status/broadcast audience, with durable recipient distribution.
     *
     * @param string|array<string,mixed> $content
     *
     * @param list<string> $recipients
     *
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed> */
    public function sendBroadcast(string $listJid,string|array $content,array $recipients,array $options=[]):array
    {
        return $this->operation(function() use($listJid,$content,$recipients,$options):array {
            $list=Jid::parse($listJid)->canonical();
            if ($list->server!=='broadcast' || $list->device!==0 || $recipients===[]) throw new \InvalidArgumentException('Broadcast requires a list JID and explicit audience');
            $status=$list->user==='status'; $setting=$options['statusSetting'] ?? 'contacts';
            if ($status && !in_array($setting,['contacts','allowlist','denylist','close_friends'],true)) throw new \InvalidArgumentException('Invalid status distribution setting');
            $credentials=($this->getCredentials)(); $users=[];
            foreach ($recipients as $recipient) {
                if (!is_string($recipient)) throw new \InvalidArgumentException('Invalid broadcast recipient');
                $user=Jid::recipient($recipient)->canonical();
                if (!in_array($user->server,['lid','s.whatsapp.net'],true) || $user->device!==0) throw new \InvalidArgumentException('Broadcast audience must contain direct users');
                $users[]= $status ? $this->resolveRecipient($user,$credentials)['to'] : $user->bare();
            }
            $plan=$this->groups->planBroadcast($list->bare(),$users,$status);
            $id=$options['id'] ?? '3EB0'.strtoupper(bin2hex(random_bytes(9)));
            if (!is_string($id) || $id==='') throw new \InvalidArgumentException('Invalid broadcast ID');
            if ($this->outbox($id)!==null) throw new \LogicException('Message ID already exists; use retryMessage for uncertain sends');
            $content=$this->prepareContent($content);
            $built=ContentBuilder::build($content,$list->bare(),['outgoingId'=>$id,'meJid'=>$plan['sender'],'nowMs'=>(int)floor(microtime(true)*1000)]);
            $metadata=['id'=>$id,'to'=>$list->bare(),'status'=>'pending','timestamp'=>time(),'type'=>$built['attrs']['type'] ?? 'text'];
            $node=$this->store->transaction(function() use($list,$id,$built,$plan,$metadata,$status,$setting,$users):BinaryNode {
                $node=$this->groups->prepareBroadcast($list->bare(),$id,$built['bytes'],$built['attrs']['type'] ?? 'text',$built['encAttrs']['mediatype'] ?? null,$plan,$status ? $setting : null);
                $node=$this->applyMessageOptions($node,$built);
                $inner=ContentDecoder::unwrap($built['proto'])['message'];
                if ($status && !isset($inner['protocolMessage']) && !isset($inner['reactionMessage'])) $this->statusStore()->recordAudience($id,$plan['sender'],$users,$setting,$metadata['timestamp']);
                $this->persistOutgoing($node,$metadata,$built,$plan['sender'],$plan['devices']);
                return $node;
            });
            return $this->submit($node,$metadata);
        });
    }

    /**
     *
     * @param string|array<string,mixed> $content
     *
     * @return array<string,mixed> */
    public function sendMessage(string $to, string|array $content): array
    {
        if(is_array($content)&&($content['type']??null)==='event'&&array_key_exists('cover',$content)){
            return $this->operation(function()use($to,$content):array{
                if((($this->getCredentials)()['registered']??false)!==true)throw new \LogicException('Pair the account before sending messages');
                $sender=new EventCoverSender(function(string $destination,array $message,array $options):array{
                    $id=$options['id'];
                    try{$result=$this->sendMessageInternal($destination,['type'=>'raw','message'=>$message,'messageId'=>$id]);}
                    catch(\Throwable $error){$saved=$this->outbox($id);if($saved===null)throw $error;$result=$saved['metadata'];}
                    $result['key']=['id'=>$result['id'],'remoteJid'=>$result['to'],'fromMe'=>true];
                    if(str_ends_with($result['to'],'@g.us')){$record=$this->outbox($result['id']);if(is_string($record['author']??null))$result['key']['participant']=ContentBuilder::bare($record['author']);}
                    return $result;
                },fn(array $media):array=>$this->media->prepare($media));
                return $sender->send($to,$content);
            });
        }
        return $this->operation(fn():array => $this->sendMessageInternal($to,$content));
    }

    /**
     *
     * @param list<array<string,mixed>> $cards
     *
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed> */
    public function sendCarousel(string $to,array $cards,array $options=[]):array
    {
        foreach($options as$key=>$_)if(!in_array($key,['text','footer','quote','messageId'],true))throw new \InvalidArgumentException('Unknown carousel option '.$key);
        return $this->sendMessage($to,['type'=>'carousel','cards'=>$cards]+$options);
    }
    /** Spoiler is a presentation hint, not an access-control or secrecy mechanism.
     *
     * @param string|array<string,mixed> $content
     *
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed> */
    public function sendSpoiler(string $to,string|array $content,array $options=[]):array
    {
        foreach($options as$key=>$_)if(!in_array($key,['quote','messageId'],true))throw new \InvalidArgumentException('Unknown spoiler option '.$key);
        return $this->sendMessage($to,['type'=>'spoiler','content'=>$content]+$options);
    }
    /** Native .was package pass-through. No JSON conversion or container/UI compatibility assertion.
     *
     * @param string|array<string,mixed> $media
     *
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed> */
    public function sendLottieSticker(string $to,string|array $media,array $options=[]):array
    {
        foreach($options as$key=>$_)if(!in_array($key,['mimetype','width','height','pngThumbnail','accessibilityLabel','fileName','messageId','quote'],true))throw new \InvalidArgumentException('Unknown Lottie sticker option '.$key);
        return $this->sendMessage($to,['type'=>'lottie-sticker','media'=>$media]+$options);
    }
    /** The parent and selected text must be observed in this session's history.
     *
     * @param string|array<string,mixed> $content
     *
     * @return array<string,mixed> */
    public function sendPartialQuote(string $to,string $parentId,string $selectedText,string|array $content,?string $author=null):array
    {
        $content=is_string($content)?['type'=>'text','text'=>$content]:$content;
        if(isset($content['quote']))throw new \InvalidArgumentException('Partial quote cannot replace an existing quotation');
        $quote=['id'=>$parentId,'selectedText'=>$selectedText];if($author!==null)$quote['author']=$author;
        return $this->sendMessage($to,[...$content,'quote'=>$quote]);
    }

    /** One encrypted publication per explicit group. Media uploads once, before the first send.
     * A rejected/uncertain group stops the batch; acknowledged groups must not be resent.
     *
     * @param string|list<string> $groups
     *
     * @param string|array<string,mixed> $content
     *
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed> */
    public function sendGroupStatuses(string|array $groups,string|array $content,array $options=[]):array
    {
        return $this->publishGroupStatuses($groups,$content,$options);
    }

    /** A durable workflow reserves this ID before invoking the publisher. No automatic retry is performed.
     *
     * @param string|array<string,mixed> $content
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function sendGroupStatusWithId(string $group,string|array $content,string $messageId,array $options=[]):array
    {
        if(preg_match('/^3EB0[A-F0-9]{18}$/D',$messageId)!==1)throw new \InvalidArgumentException('A reserved group status ID is required');
        $group=GroupStatusContent::normalizeGroups([$group])[0];
        if($this->outbox($messageId)!==null)throw new \LogicException('This group status ID already has a persisted publication; inspect its outcome');
        $batch=$this->publishGroupStatuses([$group],$content,$options,[$group=>$messageId]);
        $row=$batch['results'][0]??throw new \UnexpectedValueException('Missing group status publication outcome');
        return ['to'=>$group,...$row];
    }
    /**
     * @param string|list<string> $groups
     * @param string|array<string,mixed> $content
     *
     * @param array<string,mixed> $options
     * @param array<string,string>|null $reservedIds
     * @return array<string,mixed> */
    private function publishGroupStatuses(string|array $groups,string|array $content,array $options=[],?array $reservedIds=null):array
    {
        $groups=GroupStatusContent::normalizeGroups($groups);$content=GroupStatusContent::normalizeContent($content);$options=GroupStatusContent::normalizeOptions($options);
        return $this->operation(function()use($groups,$content,$options,$reservedIds):array{
            if((($this->getCredentials)()['registered']??false)!==true)throw new \LogicException('Pair the account before publishing Group Status');
            ($this->groupStatusGuard)?->__invoke();
            if($reservedIds===null){$reservedIds=[];foreach($groups as$group)$reservedIds[$group]='3EB0'.strtoupper(bin2hex(random_bytes(9)));}
            // Validate every destination against authenticated current membership before uploading.
            foreach($groups as$group){
                ($this->groupStatusGuard)?->__invoke();
                try{$this->groups->plan($group,true);}
                catch(GroupPreparationException $error){
                    $id=$reservedIds[$group];$preflight=$error->diagnostic();
                    return ['type'=>'group-status','complete'=>false,'requestedCount'=>count($groups),'sentCount'=>0,
                        'results'=>[['groupJid'=>$group,'id'=>$id,'status'=>'not_executed','timestamp'=>time(),'type'=>'group-status','preflight'=>$preflight]],
                        'failure'=>['groupJid'=>$group,'id'=>$id,'code'=>'group_preparation_failed','outcome'=>'not_executed','preflight'=>$preflight],
                        'skippedGroups'=>array_values(array_filter($groups,static fn(string $other):bool=>$other!==$group))];
                }
            }
            ($this->groupStatusGuard)?->__invoke();
            $prepared=$this->prepareContent($content);
            $message=is_array($prepared)&&($prepared['type']??null)==='raw'?$prepared['message']:ContentBuilder::build($prepared,$groups[0])['proto'];
            $results=[];$failure=null;$sentCount=0;$skipped=[];
            foreach($groups as$index=>$group){
                $id=$reservedIds[$group];
                try{
                    ($this->groupStatusGuard)?->__invoke();
                    // Membership may change while a media upload or earlier group send is running.
                    $plan=$this->groups->plan($group,true);
                    $subject=$plan['subject']??'';
                    if($subject===''||strlen($subject)>512||preg_match('//u',$subject)!==1||preg_match('/[\x00-\x1f\x7f]/',$subject))$subject='Status do grupo';
                    $wrapped=GroupStatusContent::wrap($message,['listName'=>$subject,'listEmoji'=>'📢',...$options]);
                    $result=$this->sendMessageInternal($group,['type'=>'raw','message'=>$wrapped,'messageId'=>$id],$plan);
                }catch(\Throwable $error){
                    $saved=$this->outbox($id);
                    $result=$saved['metadata']??['id'=>$id,'to'=>$group,'status'=>'not_executed','timestamp'=>time(),'type'=>'group-status'];
                    if($saved===null)$result['reason']=$error instanceof \LogicException?'group_status_not_allowed':'group_metadata_not_confirmed';
                    if($saved===null&&$error instanceof GroupPreparationException)$result['preflight']=$error->diagnostic();
                }
                $status=$result['status']??'unknown';$ack=in_array($status,['server_ack','delivered','read','played'],true);
                $row=['groupJid'=>$group,'id'=>$id,'status'=>$status,'timestamp'=>$result['timestamp']??time(),'type'=>'group-status'];
                if(isset($result['serverCode']))$row['serverCode']=$result['serverCode'];
                if(isset($result['reason']))$row['reason']=$result['reason'];
                if(isset($result['preflight']))$row['preflight']=$result['preflight'];
                $results[]=$row;
                if($ack){$sentCount++;continue;}
                $failure=['groupJid'=>$group,'id'=>$id,'code'=>match($status){'rejected'=>'group_status_rejected','not_executed'=>'group_status_not_executed',default=>'group_status_outcome_unknown'},'outcome'=>match($status){'rejected'=>'rejected','not_executed'=>'not_executed',default=>'unknown'}];
                if(isset($result['serverCode']))$failure['serverCode']=$result['serverCode'];
                if(isset($result['preflight'])){$failure['code']='group_preparation_failed';$failure['preflight']=$result['preflight'];}
                $skipped=array_slice($groups,$index+1);break;
            }
            return ['type'=>'group-status','complete'=>$failure===null,'requestedCount'=>count($groups),'sentCount'=>$sentCount,'results'=>$results,'failure'=>$failure,'skippedGroups'=>$skipped];
        });
    }

    /** Revoke only the exact group publication in this session's durable outbox.
     *
     * @return array<string,mixed> */
    public function revokeGroupStatus(string $group,string $id):array
    {
        $group=GroupStatusContent::normalizeGroups($group)[0];
        return $this->operation(function()use($group,$id):array{
            $original=$this->outbox($id);$plain=is_string($original['plaintext']??null)?base64_decode($original['plaintext'],true):false;
            if(($original['metadata']['to']??null)!==$group||$plain===false||!in_array('groupStatusMessageV2',ContentDecoder::unwrap(MessageCodec::decode($plain)['proto'])['wrappers'],true))throw new \LogicException('Only a Group Status published by this session in this group can be revoked');
            if(!in_array($original['metadata']['status']??null,['server_ack','delivered','read','played'],true))throw new \LogicException('Group Status revocation requires an acknowledged original publication');
            $originalBody=ContentDecoder::unwrap(MessageCodec::decode($plain)['proto'])['message'];
            if(isset($originalBody['protocolMessage']))throw new \LogicException('A revocation is not a Group Status publication');
            $key=['remoteJid'=>$group,'id'=>$id,'fromMe'=>true];
            if(is_string($original['author']??null))$key['participant']=ContentBuilder::bare($original['author']);
            $protocol=['protocolMessage'=>['type'=>'REVOKE','key'=>$key]];$results=[];$failure=null;
            foreach(['group_status'=>GroupStatusContent::wrap($protocol),'linked_devices'=>$protocol]as$step=>$message){
                $sendId='3EB0'.strtoupper(bin2hex(random_bytes(9)));
                try{($this->groupStatusGuard)?->__invoke();$result=$this->sendMessageInternal($group,['type'=>'raw','message'=>$message,'messageId'=>$sendId],$this->groups->plan($group,true));}
                catch(\Throwable $error){$saved=$this->outbox($sendId);$result=$saved['metadata']??['id'=>$sendId,'to'=>$group,'status'=>'not_executed'];if($saved===null&&$error instanceof GroupPreparationException)$result['preflight']=$error->diagnostic();}
                $row=['step'=>$step,'id'=>$sendId,'status'=>$result['status']??'unknown'];if(isset($result['serverCode']))$row['serverCode']=$result['serverCode'];$results[]=$row;
                if(isset($result['preflight'])){$row['preflight']=$result['preflight'];$results[array_key_last($results)]=$row;}
                if(!in_array($row['status'],['server_ack','delivered','read','played'],true)){$failure=['step'=>$step,'id'=>$sendId,'outcome'=>match($row['status']){'rejected'=>'rejected','not_executed'=>'not_executed',default=>'unknown'},...(isset($result['preflight'])?['code'=>'group_preparation_failed','preflight'=>$result['preflight']]:[])];break;}
                if($step==='group_status')$this->statusStore()->markGroupStatusRevoked($group,$id);
            }
            return ['groupJid'=>$group,'id'=>$id,'complete'=>$failure===null,'results'=>$results,'failure'=>$failure];
        });
    }

    /** Upload all album items before publishing the parent; return each send result, including uncertain sends.
     *
     * @param list<array<string,mixed>> $items
     *
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed> */
    public function sendAlbum(string $to, array $items, array $options = []): array
    {
        return $this->operation(function () use ($to, $items, $options): array {
            $sender = new AlbumSender(function (string $destination, array $message, array $sendOptions): array {
                $id='3EB0'.strtoupper(bin2hex(random_bytes(9)));
                try { $result = $this->sendMessageInternal($destination, [...$sendOptions, 'type'=>'raw', 'message'=>$message, 'messageId'=>$id]); }
                catch (\Throwable $error) {
                    $saved=$this->outbox($id);
                    if ($saved===null) throw $error;
                    $result=$saved['metadata'];
                    $result['failure']=['messageId'=>$id, 'reason'=>'send_not_acknowledged', 'outcome'=>($result['status']??null)==='rejected' ? 'rejected' : 'unknown'];
                }
                $result['key'] = ['id'=>$result['id'], 'remoteJid'=>$result['to'], 'fromMe'=>true];
                if (str_ends_with($result['to'], '@g.us')) {
                    $record = $this->outbox($result['id']);
                    if (is_string($record['author'] ?? null)) $result['key']['participant'] = ContentBuilder::bare($record['author']);
                }
                return $result;
            }, fn(array $media): array => $this->media->prepare($media));
            return $sender->send($to, $items, $options);
        });
    }

    /**
     *
     * @param array<string,mixed> $message Verified plaintext bytes or destination path. */
    public function downloadMedia(array $message, ?string $destination = null): string
    {
        return $this->operation(function() use($message,$destination):string {
            $payload = $message['media'] ?? $this->media->resolvePayload($message['proto'] ?? $message) ?? $message;
            if (!is_array($payload)) throw new \InvalidArgumentException('Message has no downloadable media');
            try { return $this->media->download($payload,$destination); }
            catch (MediaExpiredException $error) {
                if ($this->pump===null || (!isset($message['id']) && !isset($message['key']['id']) && !isset($message['messageId']))) throw $error;
                $retry=$this->requestMediaReupload($message);
                if (($retry['result'] ?? '')!=='success') throw new \RuntimeException('Media reupload '.$retry['result'],0,$error);
                $payload['directPath']=$retry['directPath']; unset($payload['url']);
                $this->cacheMediaPath($message,$payload);
                return $this->media->download($payload,$destination);
            }
        });
    }

    /**
     * @param array<string,mixed> $message */
    public function downloadMediaStream(array $message,string $destination):string
    {
        return $this->operation(function() use($message,$destination):string {
            $payload=$message['media'] ?? MediaTransfer::resolvePayload($message['proto'] ?? $message) ?? $message;
            if (!is_array($payload)) throw new \InvalidArgumentException('Message has no downloadable media');
            try { return $this->media->downloadStream($payload,$destination); }
            catch (MediaExpiredException $error) {
                if ($this->pump===null || (!isset($message['id']) && !isset($message['key']['id']) && !isset($message['messageId']))) throw $error;
                $retry=$this->requestMediaReupload($message);
                if (($retry['result'] ?? '')!=='success') throw new \RuntimeException('Media reupload '.$retry['result'],0,$error);
                $payload['directPath']=$retry['directPath']; unset($payload['url']);
                $this->cacheMediaPath($message,$payload);
                return $this->media->downloadStream($payload,$destination);
            }
        });
    }

    /** Asynchronous alternative: call poll(), then getMediaReuploadResult().
     *
     * @param array<string,mixed> $source */
    public function beginMediaReupload(array $source,float $timeoutSeconds=30):string
    {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds<=0 || $timeoutSeconds>300) throw new \InvalidArgumentException('Invalid media reupload timeout');
        return $this->operation(fn():string=>$this->mediaRetry->begin($this->mediaRetryInput($source),$timeoutSeconds));
    }
    /**
     * @return array<string,mixed>|null */
    public function getMediaReuploadResult(string $id):?array { return $this->operation(fn():?array=>$this->mediaRetry->result($id)); }

    /**
     * @param array<string,mixed> $source
     *
     * @return array<string,mixed> */
    public function requestMediaReupload(array $source,float $timeoutSeconds=30):array
    {
        return $this->operation(function() use($source,$timeoutSeconds):array {
            if ($this->pump===null) throw new \LogicException('Synchronous media reupload requires a client event-loop pump; use beginMediaReupload');
            $id=$this->beginMediaReupload($source,$timeoutSeconds); $deadline=microtime(true)+$timeoutSeconds;
            try {
                while (($result=$this->mediaRetry->result($id))===null) {
                    if ($this->closeRequested) throw new \RuntimeException('Media reupload cancelled because the client stopped');
                    $remaining=$deadline-microtime(true);
                    if ($remaining<=0) throw new \RuntimeException('Media reupload timed out for '.$id);
                    ($this->pump)(min(0.25,$remaining));
                }
                if (isset($result['error'])) throw new \UnexpectedValueException($result['error']);
                return $result;
            } finally { $this->mediaRetry->expire($id); }
        });
    }

    /**
     * @param array<string,mixed> $source
     *
     * @return array<string,mixed> */
    private function mediaRetryInput(array $source):array
    {
        $key=$source['key'] ?? []; $payload=$source['media'] ?? MediaTransfer::resolvePayload($source['proto'] ?? $source) ?? [];
        return ['messageId'=>$source['messageId'] ?? $key['id'] ?? $source['id'] ?? null,
            'chatJid'=>$source['chatJid'] ?? $key['remoteJid'] ?? $source['chat'] ?? null,
            'mediaKey'=>$source['mediaKey'] ?? $payload['mediaKey'] ?? null,'fromMe'=>$source['fromMe'] ?? $key['fromMe'] ?? false,
            'participant'=>$source['participant'] ?? $key['participant'] ?? null];
    }

    /**
     * @param array<string,mixed> $source
     *
     * @param array<string,mixed> $payload */
    private function cacheMediaPath(array $source,array $payload):void
    {
        $input=$this->mediaRetryInput($source);
        if (is_string($input['chatJid']) && is_string($input['messageId'])) $this->history->store->updateMessage($input['chatJid'],$input['messageId'],['media'=>$payload],
            is_string($input['participant']) ? $input['participant'] : null);
    }

    /**
     * @param array<string,mixed> $content
     *
     * @return array<string,mixed> Uploaded WAProto envelope, reusable in edits/raw messages. */
    public function prepareMedia(array $content):array
    {
        return $this->operation(fn():array => $this->media->prepare($content));
    }

    private function resolveInputRecipient(string $to):string
    {
        $to=\MVO\Service\RecipientResolver::normalize($to);
        if (\MVO\Service\RecipientResolver::isUsername($to)) {
            if ($this->usernameResolver===null) throw new \LogicException('Username resolution is not configured; use WaClient::sendMessage');
            $to=($this->usernameResolver)($to);
            try { $jid=Jid::parse($to)->canonical(); } catch (\InvalidArgumentException) { throw new \MVO\Service\RecipientResolutionException('invalid-result'); }
            if (!in_array($jid->server,['s.whatsapp.net','lid'],true) || $jid->device!==0 || !ctype_digit($jid->user)) throw new \MVO\Service\RecipientResolutionException('invalid-result');
            return (string)$jid;
        }
        return $to;
    }

    /**
     *
     * @return array<string,mixed>
     *
     * @param string|array<string,mixed> $content
     *
     * @param array{group:string,sender:string,mode:'pn'|'lid',devices:list<string>,fingerprint:string,subject?:string}|null $preparedGroupPlan */
    private function sendMessageInternal(string $to, string|array $content,?array $preparedGroupPlan=null): array
    {
        $to=$this->resolveInputRecipient($to);
        $recipient = Jid::recipient($to)->canonical();
        if (!in_array($recipient->server, ['s.whatsapp.net','lid','g.us','bot'], true) || $recipient->device !== 0) {
            throw new \InvalidArgumentException('Sending supports direct user chats, groups and bots');
        }
        $to = $recipient->bare();
        $credentials = ($this->getCredentials)();
        if (($credentials['registered'] ?? false) !== true) throw new \LogicException('Pair the account before sending messages');
        $routing = in_array($recipient->server,['g.us','bot'],true) ? ['to'=>$to,'pn'=>null] : $this->resolveRecipient($recipient, $credentials);
        $to = $routing['to'];
        $recipient = Jid::parse($to);
        $groupPlan = $recipient->server === 'g.us' ? ($preparedGroupPlan??$this->groups->plan($to)) : null;
        $id = is_array($content) ? ($content['messageId'] ?? '3EB0'.strtoupper(bin2hex(random_bytes(9)))) : '3EB0'.strtoupper(bin2hex(random_bytes(9)));
        if (!is_string($id) || preg_match('/^[A-Za-z0-9_-]{8,128}$/D',$id)!==1) throw new \InvalidArgumentException('Invalid message ID');
        if ($this->outbox($id)!==null) throw new \LogicException('Message ID already exists; use retryMessage for an uncertain send');
        $scheduledCallAuthorized=false;
        if(is_array($content)&&($content['type']??null)==='scheduled-call-cancel'){$content=$this->authorizeScheduledCallCancellation($content,$to,$credentials);$scheduledCallAuthorized=true;}
        $content = $this->hydrateAddon($content,$to,$credentials);
        $content = $this->hydratePartialQuote($content,$to,$credentials);
        $content = $this->prepareContent($content);
        $author = $groupPlan['sender'] ?? ($recipient->server === 'lid' ? ($credentials['meLid'] ?? $credentials['meJid']) : $credentials['meJid']);
        $built = ContentBuilder::build($content,$to,['outgoingId'=>$id,'meJid'=>$author,'meLid'=>$credentials['meLid'] ?? null,'nowMs'=>(int)floor(microtime(true)*1000),'scheduledCallCancellationAuthorized'=>$scheduledCallAuthorized,'senderIsPrimary'=>($credentials['role']??null)==='primary']);
        $bot=BotTransport::invokedBot($built['proto']);
        if (($recipient->server==='bot' || $bot!==null) && !isset($built['secrets']['messageSecret'])) {
            $proto=$built['proto']; $proto['messageContextInfo']=[...($proto['messageContextInfo'] ?? []),'messageSecret'=>random_bytes(32)];
            $built=ContentBuilder::build(['type'=>'raw','message'=>$proto],$to,['outgoingId'=>$id,'meJid'=>$author]);
        }
        if ($bot!==null && $recipient->server==='g.us') $this->ensureSessions([$bot]);
        $message = $built['bytes'];
        $metadata = ['id'=>$id,'to'=>$to,'status'=>'pending','timestamp'=>time(),'type'=>$built['attrs']['type'] ?? 'text'];
        if ($recipient->server === 'g.us') {
            $plan = $groupPlan;
            $node = $this->store->transaction(function() use($to,$id,$message,$built,$metadata,$plan):BinaryNode {
                $receivers=ContentDecoder::unwrap($built['proto'])['message']['messageHistoryBundle']['messageHistoryMetadata']['historyReceivers'] ?? [];
                $node = $receivers===[] ? $this->groups->prepare($to,$id,$message,$built['attrs']['type'] ?? 'text',$built['encAttrs']['mediatype'] ?? null,$plan)
                    : $this->groups->prepareDirect($to,$id,$message,$built['attrs']['type'] ?? 'text',$built['encAttrs']['mediatype'] ?? null,$plan,$receivers);
                $node = $this->applyMessageOptions($node,$built);
                $node = $this->attachBotSidecar($node,$built['proto']);
                $eligible=$plan['devices'];
                if ($receivers!==[]) $eligible=array_values(array_filter($eligible,static fn(string $device):bool=>in_array(Jid::parse($device)->bare(),[...$receivers,Jid::parse($plan['sender'])->bare()],true)));
                $this->persistOutgoing($node,$metadata,$built,$plan['sender'],$eligible);
                return $node;
            });
            return $this->submit($node,$metadata);
        }
        $me = Jid::parse($credentials['meJid'])->canonical();
        if ($recipient->server === 'lid' && is_string($credentials['meLid'] ?? null)) {
            $lid = Jid::parse($credentials['meLid'])->canonical();
            // success.lid may omit the device segment.
            $me = new Jid($lid->user, $lid->server, $lid->device ?: $me->device);
        }
        $devices = $this->discoverDevices($recipient->server==='bot' ? [$me->bare()] : array_values(array_unique([$to, $me->bare()])));
        $fanout = $devices[$to] ?? [];
        if ($fanout === []) $fanout[] = $to;
        foreach ($devices[$me->bare()] ?? [] as $device) {
            if ((string) Jid::parse($device)->canonical() !== (string) $me) $fanout[] = $device;
        }
        $fanout = array_values(array_unique(array_filter($fanout,
            static fn(string $jid):bool => (string) Jid::parse($jid)->canonical() !== (string) $me)));
        if ($fanout === []) throw new \RuntimeException('No destination devices available');
        $this->ensureSessions($fanout);
        // Ratchet state and exact outgoing ciphertext commit together before I/O.
        $node = $this->store->transaction(function () use ($fanout, $message, $to, $id, $credentials, $metadata, $routing, $built, $me): BinaryNode {
            $participants = []; $needsIdentity = false;
            foreach ($fanout as $device) {
                $body = $this->isOwn($device, $credentials) ? MessageCodec::deviceSent($message, $to) : $message;
                $encrypted = $this->signal->encrypt($this->sessionJid($device, $credentials), MessageCodec::pad($body));
                $needsIdentity = $needsIdentity || $encrypted['type'] === 'pkmsg';
                $participants[] = new BinaryNode('to', ['jid'=>$device], [
                    new BinaryNode('enc', ['v'=>'2','type'=>$encrypted['type']], $encrypted['ciphertext'])
                ]);
            }
            $children = [new BinaryNode('participants', [], $participants)];
            if ($needsIdentity && self::needsDeviceIdentity($credentials)) $children[] = new BinaryNode('device-identity', [], self::deviceIdentity($credentials));
            $attrs = ['id'=>$id,'to'=>$to,'type'=>'text'];
            if ($routing['pn'] !== null && Jid::parse($to)->server === 'lid') $attrs['peer_recipient_pn'] = $routing['pn'];
            $node = $this->applyMessageOptions(new BinaryNode('message', $attrs, $children),$built);
            $this->persistOutgoing($node,$metadata,$built,(string)$me,$fanout);
            return $node;
        });
        return $this->submit($node, $metadata);
    }

    /**
     *
     * @param array<string,mixed> $built */
    private function applyMessageOptions(BinaryNode $node,array $built): BinaryNode
    {
        $transform = function(BinaryNode $child) use(&$transform,$built):BinaryNode {
            $content = is_array($child->content) ? array_map($transform,$child->content) : $child->content;
            return new BinaryNode($child->tag,$child->tag === 'enc' ? [...$child->attrs,...$built['encAttrs']] : $child->attrs,$content);
        };
        $children = array_map($transform,is_array($node->content) ? $node->content : []);
        if (!empty($built['metaAttrs'])) $children[] = new BinaryNode('meta',$built['metaAttrs']);
        foreach ($built['companionNodes'] as $companion) $children[] = $companion;
        $token=$this->privacyTokens->resolveTokenForMessage($node->attrs['to'] ?? '');if($token!==null && $node->child('tctoken')===null && $node->child('cstoken')===null)$children[]=$token;
        return new BinaryNode('message',[...$node->attrs,...$built['attrs']],$children);
    }

    /** Called only inside the outgoing ratchet/outbox transaction.
     *
     * @param array<string,mixed> $message */
    private function attachBotSidecar(BinaryNode $node,array $message):BinaryNode
    {
        $bot=BotTransport::invokedBot($message); if ($bot===null) return $node;
        $copy=BotTransport::sidecarCopy($message);
        $encrypted=$this->signal->encrypt($bot,MessageCodec::pad(MessageCodec::encode($copy)));
        $children=is_array($node->content) ? $node->content : [];
        $children[]=new BinaryNode('bot',[],[new BinaryNode('to',['jid'=>$bot],[new BinaryNode('enc',['v'=>'2','type'=>$encrypted['type']],$encrypted['ciphertext'])])]);
        $credentials=($this->getCredentials)();
        if ($encrypted['type']==='pkmsg' && $node->child('device-identity')===null && self::needsDeviceIdentity($credentials)) $children[]=new BinaryNode('device-identity',[],self::deviceIdentity($credentials));
        return new BinaryNode('message',$node->attrs,$children);
    }

    /**
     *
     * @param array<string,mixed> $metadata
     *
     * @param array<string,mixed> $built
     *
     * @param list<string> $eligibleDevices
     */
    private function persistOutgoing(BinaryNode $node,array $metadata,array $built,string $author,array $eligibleDevices=[]):void
    {
        if(in_array($node->attrs['addressing_mode']??null,['pn','lid'],true))$metadata['addressingMode']=$node->attrs['addressing_mode'];
        $record = ['node'=>base64_encode((new BinaryCodec())->encode($node)),'metadata'=>$metadata,
            'plaintext'=>base64_encode($built['bytes']),'author'=>$author,'eligibleDevices'=>$eligibleDevices];
        $this->store->set('message-outbox',$metadata['id'],json_encode($record,JSON_THROW_ON_ERROR));
        $decoded=MessageCodec::decode($built['bytes']);
        $statusFields=$metadata['to']===StatusStore::CHAT||in_array('groupStatusMessageV2',ContentDecoder::unwrap($built['proto'])['wrappers'],true) ? ['participant'=>ContentBuilder::bare($author)] : [];
        $this->history->store->upsertMessage([...$statusFields,'id'=>$metadata['id'],'chat'=>$metadata['to'],'fromMe'=>true,
            'from'=>ContentBuilder::bare($author),'timestamp'=>$metadata['timestamp'],'status'=>$metadata['status'],
            'key'=>[...$statusFields,'id'=>$metadata['id'],'remoteJid'=>$metadata['to'],'fromMe'=>true],
            'proto'=>$built['proto'],'text'=>$decoded['text'] ?? null,'type'=>$decoded['kind'],'data'=>$decoded['data'] ?? []]);
        if (isset($built['secrets']['messageSecret'])) {
            $expectedBot=BotTransport::invokedBot($built['proto']);
            if ($expectedBot===null && str_ends_with($metadata['to'],'@bot')) $expectedBot=BotTransport::fbid($metadata['to']);
            $this->rememberSecret($metadata['to'],$metadata['id'],$author,$built['secrets']['messageSecret'],$expectedBot);
        }
    }

    /**
     * @param string|array<string,mixed> $content
     *
     * @return string|array<string,mixed> */
    private function prepareContent(string|array $content,int $depth=0):string|array
    {
        if ($depth>8) throw new \LengthException('Message content nesting exceeded');
        if (!is_array($content)) return $content;
        if(isset($content['type'])&&$content['type']!=='raw'&&array_key_exists('messageContextInfo',$content)){
            $metadata=$content['messageContextInfo'];unset($content['messageContextInfo']);
            if($metadata!==null&&!is_array($metadata))throw new \InvalidArgumentException('messageContextInfo must be an array');
            // The outer spoiler replaces nested caller options; raw proto-owned metadata still wins.
            if($content['type']==='spoiler'&&is_array($content['content']??null))unset($content['content']['messageContextInfo']);
            // Preserve shared typed metadata across strict preparers and media's typed-to-raw conversion.
            // This recursion does not add a message wrapper and consumes the option before re-entering.
            $prepared=$this->prepareContent($content,$depth);
            if($metadata===null)return $prepared;
            if(!is_array($prepared))throw new \LogicException('Typed preparation did not return message content');
            if(($prepared['type']??null)==='raw'){
                $message=$prepared['message']??null;$generated=is_array($message)?($message['messageContextInfo']??[]):null;
                if(!is_array($message)||!is_array($generated))throw new \LogicException('Invalid prepared Message metadata');
                $message['messageContextInfo']=array_replace($metadata,$generated);
                $prepared['message']=$message;
            }else $prepared['messageContextInfo']=$metadata;
            return $prepared;
        }
        if(($content['type']??null)==='spoiler'){
            foreach($content as$key=>$_)if(!in_array($key,['type','content','quote','messageId','messageContextInfo'],true))throw new \InvalidArgumentException('Unknown spoiler field '.$key);
            $inner=$content['content']??null;if(is_string($inner))$inner=['type'=>'text','text'=>$inner];
            if(!is_array($inner)||($inner['type']??null)==='spoiler')throw new \InvalidArgumentException('Spoiler requires text or media');
            return $this->prepareContent([...$inner,...array_intersect_key($content,array_flip(['quote','messageId','messageContextInfo'])),'spoiler'=>true],$depth+1);
        }
        if(($content['type']??null)==='carousel')return CarouselContent::prepare($content,fn(array $media):array=>$this->media->prepare($media),$this->media->maxBytes());
        if (isset($content['statusMetadata'])) {
            $metadata=$content['statusMetadata'];
            if (!is_array($metadata)) throw new \InvalidArgumentException('Invalid status metadata');
            unset($content['statusMetadata']);
            $prepared=$this->prepareContent($content,$depth+1);
            $message=is_array($prepared) && ($prepared['type']??null)==='raw' ? $prepared['message'] : ContentBuilder::build($prepared,StatusStore::CHAT)['proto'];
            return ['type'=>'raw','message'=>ModernContent::decorateStatus($message,$metadata)];
        }
        if (in_array($content['type'] ?? '',['image','video','gif','ptv','audio','ptt','document','sticker','lottie-sticker','sticker-pack','audio-sticker'],true)) {
            if (isset($content['prepared'])) return $content;
            return [...$content,'type'=>'raw','message'=>$this->media->prepare($content)];
        }
        if (($content['type'] ?? '')==='edit') {
            foreach (['content','message'] as $field) if (isset($content[$field]) && (is_string($content[$field]) || is_array($content[$field]))) $content[$field]=$this->prepareContent($content[$field],$depth+1);
        }
        return $content;
    }

    /**
     *
     * @param string|array<string,mixed> $content
     *
     * @param array<string,mixed> $credentials
     *
     * @return string|array<string,mixed> */
    private function hydratePartialQuote(string|array $content,string $chat,array $credentials):string|array
    {
        if(!is_array($content)||!is_array($content['quote']??null)||!array_key_exists('selectedText',$content['quote']))return $content;
        $quote=$content['quote'];$key=$quote['key']??$quote;$id=$key['id']??null;
        if(!is_string($id)||$id===''||strlen($id)>128)throw new \InvalidArgumentException('Partial quote requires a parent message ID');
        $chats=array_values(array_unique(array_filter([$chat,$this->store->get('message-pn-to-lid',$chat),$this->store->get('message-lid-to-pn',$chat)])));
        if(isset($key['remoteJid'])&&!in_array(ContentBuilder::bare($key['remoteJid']),$chats,true))throw new \InvalidArgumentException('Partial quote belongs to another conversation');
        $requested=$quote['author']??$key['participant']??null;
        if($requested!==null){if(!is_string($requested))throw new \InvalidArgumentException('Invalid quote author');$requested=Jid::parse($requested)->canonical()->bare();}
        $records=[];
        foreach($chats as$candidate)foreach($this->history->store->iterateMessages($candidate)as$row){
            if(($row['id']??null)!==$id||($row['revoked']??false)===true||!is_array($row['proto']??null))continue;
            $fromMe=($row['fromMe']??$row['key']['fromMe']??false)===true;
            $author=$row['participant']??$row['key']['participant']??$row['from']??($fromMe?($credentials['meJid']??null):$candidate);
            if(!is_string($author))continue;$author=Jid::parse($author)->canonical()->bare();
            if($requested!==null&&!in_array($requested,$this->identityCandidates($author,$credentials),true))continue;
            $records[hash('sha256',$author."\0".SchemaCodec::encode('Message',$row['proto']))]=[$row,$author,$fromMe];
        }
        if(count($records)!==1)throw new \InvalidArgumentException($records===[]?'Quoted parent not observed in this conversation':'Quote ID is ambiguous; specify the original author');
        [$record,$author,$fromMe]=array_values($records)[0];$selected=QuoteSelection::validate($record['proto'],$quote['selectedText']);
        $content['quote']=['key'=>['id'=>$id,'remoteJid'=>$chat,'fromMe'=>$fromMe,'participant'=>$author],'message'=>$record['proto'],'selectedText'=>$selected];
        return $content;
    }

    private function rememberSecret(string $chat,string $id,string $author,string $secret,?string $expectedBot=null):void
    {
        if (strlen($secret) !== 32) throw new \InvalidArgumentException('Message secret must be 32 bytes');
        $key=hash('sha256',$chat."\0".$id);$record=['author'=>ContentBuilder::bare($author),'secret'=>$secret];
        if ($expectedBot!==null) $record['expectedBot']=BotTransport::fbid($expectedBot);
        elseif (($previous=$this->store->get('message-secrets',$key))!==null) {
            $previous=BinaryData::decode($previous);
            if (is_array($previous) && ($previous['author']??null)===$record['author'] && ($previous['secret']??null)===$secret && is_string($previous['expectedBot']??null)) $record['expectedBot']=$previous['expectedBot'];
        }
        $this->store->set('message-secrets',$key,BinaryData::encode($record));
    }

    /**
     *
     * @return array{author:string,secret:string,expectedBot?:string}|null */
    private function lookupSecret(string $chat,string $id):?array
    {
        $chats = [$chat];
        foreach (['message-pn-to-lid','message-lid-to-pn'] as $namespace) {
            if (($alias = $this->store->get($namespace,$chat)) !== null) $chats[] = $alias;
        }
        foreach ($chats as $candidate) {
            $bytes = $this->store->get('message-secrets',hash('sha256',$candidate."\0".$id));
            if ($bytes === null) continue;
            $value = BinaryData::decode($bytes);
            if (!is_array($value) || !is_string($value['author'] ?? null) || !is_string($value['secret'] ?? null) || strlen($value['secret']) !== 32) throw new \UnexpectedValueException('Invalid saved message secret');
            $result=['author'=>$value['author'],'secret'=>$value['secret']];
            if (is_string($value['expectedBot']??null)) $result['expectedBot']=BotTransport::fbid($value['expectedBot']);
            return $result;
        }
        return null;
    }

    /** Known account/contact aliases only; each alternative still needs AES-GCM authentication.
     *
     * @param array<string,mixed> $credentials
     *
     * @return list<string> */
    private function identityCandidates(string $jid,array $credentials):array
    {
        $jid = Jid::parse($jid)->canonical()->bare();
        $candidates = [$jid];
        if ($this->isOwn($jid,$credentials)) {
            foreach (['meJid','meLid'] as $field) if (is_string($credentials[$field] ?? null)) $candidates[]=Jid::parse($credentials[$field])->canonical()->bare();
        }
        foreach (['message-pn-to-lid','message-lid-to-pn'] as $namespace) if (($alias=$this->store->get($namespace,$jid))!==null) $candidates[]=$alias;
        return array_values(array_unique($candidates));
    }

    /**
     *
     * @param string|array<string,mixed> $content
     *
     * @param array<string,mixed> $credentials
     *
     * @return string|array<string,mixed> */
    private function hydrateAddon(string|array $content,string $chat,array $credentials):string|array
    {
        if (!is_array($content) || !in_array($content['type'] ?? '',['poll-vote','poll-edit','poll-add-option','event-response','event-edit'],true)) return $content;
        $field = str_starts_with($content['type'], 'poll-') ? 'poll' : 'event';
        if (!is_array($content[$field] ?? null)) throw new \InvalidArgumentException('Missing addon parent');
        $parent = $content[$field];
        if (isset($parent['key']) && is_array($parent['key'])) $parent = [...$parent,...$parent['key']];
        $saved = $this->lookupSecret($chat,is_string($parent['id'] ?? null) ? $parent['id'] : '');
        if ($saved !== null) {
            $parent['messageSecret'] = $saved['secret'];
            $parent['authorJid'] = $saved['author'];
        }
        $chats = array_unique(array_filter([$chat, $this->store->get('message-pn-to-lid', $chat), $this->store->get('message-lid-to-pn', $chat)]));
        foreach ($chats as $candidate) {
            $record = $this->history->store->getMessage($candidate, (string)($parent['id'] ?? ''));
            if (!is_array($record['proto'] ?? null)) continue;
            $parent['message'] = ContentDecoder::unwrap($record['proto'])['message'];
            unset($parent['pollCreation'], $parent['eventCreation'], $parent['proto']);
            $parent[$field.'Creation']=$this->interactions()->parentContent($candidate,(string)$parent['id'],$field,$record['participant']??null);
            break;
        }
        $parent['fromMe'] = isset($parent['authorJid']) && $this->isOwn($parent['authorJid'],$credentials);
        if (str_ends_with($chat,'@g.us') && isset($parent['authorJid'])) $parent['participant'] = $parent['authorJid'];
        unset($parent['key']);
        $content[$field] = $parent;
        return $content;
    }

    /**
     * @param array<string,mixed> $content
     *
     * @param array<string,mixed> $credentials
     *
     * @return array<string,mixed> */
    private function authorizeScheduledCallCancellation(array $content,string $chat,array $credentials):array
    {
        ScheduledCallContent::validate($content);$id=$content['target']['id'];
        if(isset($content['target']['remoteJid'])&&!in_array($content['target']['remoteJid'],array_filter([$chat,$this->store->get('message-pn-to-lid',$chat),$this->store->get('message-lid-to-pn',$chat)]),true))throw new \InvalidArgumentException('Scheduled call belongs to another conversation');
        $record=null;
        foreach(array_unique(array_filter([$chat,$this->store->get('message-pn-to-lid',$chat),$this->store->get('message-lid-to-pn',$chat)]))as$candidate){$record=$this->history->store->getMessage($candidate,$id);if($record!==null)break;}
        if($record===null||($record['fromMe']??false)!==true||!is_array($record['proto']??null)||!isset(ContentDecoder::unwrap($record['proto'])['message']['scheduledCallCreationMessage']))throw new \InvalidArgumentException('Cancellation requires a known scheduled call sent by this account');
        $author=$record['from']??$record['participant']??null;
        if(!is_string($author)||!$this->isOwn($author,$credentials))throw new \InvalidArgumentException('Only the original sender may cancel a scheduled call');
        $content['target']=['id'=>$id,'remoteJid'=>$chat,'fromMe'=>true];
        if(str_ends_with($chat,'@g.us'))$content['target']['participant']=ContentBuilder::bare($author);
        return $content;
    }

    /**
     * Explicit retry reuses the original ID and ciphertext, without advancing any ratchet.
     *
     * @return array<string,mixed>
     */
    public function retryMessage(string $id): array
    {
        return $this->operation(fn():array => $this->retryMessageInternal($id));
    }

    /**
     *
     * @return array<string,mixed> */
    private function retryMessageInternal(string $id): array
    {
        $record = $this->outbox($id);
        if ($record === null) throw new \OutOfBoundsException('Unknown outgoing message ID');
        if (in_array($record['metadata']['status'] ?? '',['server_ack','delivered','read','played'],true)) {
            // ACKed messages are never retransmitted. Repair a missed local projection instead.
            $this->updateOutbox($record['metadata']);
            $this->projectAcknowledgedOutgoing($record['metadata']);
            return $record['metadata'];
        }
        $bytes = base64_decode($record['node'], true);
        if ($bytes === false) throw new \UnexpectedValueException('Invalid persisted message');
        return $this->submit((new BinaryCodec())->decode($bytes), $record['metadata']);
    }

    /**
     *
     * @return array<string,mixed>|null */
    public function getMessageStatus(string $id): ?array
    {
        return $this->operation(fn():?array => $this->outbox($id)['metadata'] ?? null);
    }

    /**
     *
     * @param array<string,mixed> $metadata
     *
     * @return array<string,mixed>
     */
    private function submit(BinaryNode $node, array $metadata): array
    {
        try {
            $ack = ($this->query)($node, 30);
            if ($ack->tag==='ack' && ($ack->attrs['class'] ?? '')==='message' && ($ack->attrs['id'] ?? '')===$metadata['id'] &&
                (isset($ack->attrs['error']) || ($ack->attrs['type'] ?? '')==='error' || $ack->child('error')!==null)) {
                $code=$ack->child('error')?->attrs['code'] ?? $ack->attrs['error'] ?? '';
                throw new \MVO\Service\ServiceException('message',preg_match('/^[0-9]{1,5}$/D',$code)===1?$code:null,'WhatsApp rejected outgoing message');
            }
            if ($ack->tag !== 'ack' || ($ack->attrs['class'] ?? '') !== 'message' ||
                ($ack->attrs['id'] ?? '') !== $metadata['id'] || isset($ack->attrs['error']) ||
                ($ack->attrs['type'] ?? '') === 'error' || $ack->child('error') !== null) {
                throw new \RuntimeException('WhatsApp did not acknowledge outgoing message');
            }
            $metadata=[...$metadata,...($this->outbox($metadata['id'])['metadata'] ?? [])];
            if (!in_array($metadata['status'],['delivered','read','played'],true)) $metadata['status'] = 'server_ack';
            unset($metadata['serverCode']);
            $metadata['acknowledgedAt'] = time();
            if(in_array($ack->attrs['addressing_mode']??null,['pn','lid'],true)){
                $metadata['addressingMode']=$ack->attrs['addressing_mode'];
                $metadata['ack']=['addressingMode'=>$ack->attrs['addressing_mode']];
            }
            $this->updateOutbox($metadata);
            $this->groups->acknowledge($metadata['id']);
        } catch (\Throwable $error) {
            // A receipt may arrive while query() is waiting. Never erase observed delivery.
            $metadata=[...$metadata,...($this->outbox($metadata['id'])['metadata'] ?? [])];
            $refused=$error instanceof \MVO\Service\ServiceException && $error->operation==='message';
            if (!in_array($metadata['status'],['server_ack','delivered','read','played'],true)) $metadata['status']=$error instanceof \MVO\Service\GroupStatusNotExecuted&&$error->messageId===$metadata['id']?'not_executed':($refused?'rejected':'unknown');
            if ($refused) $metadata['serverCode']=$error->serverCode;
            $this->updateOutbox($metadata);
            ($this->emit)('message_error', ['id'=>$metadata['id'],'to'=>$metadata['to'],'status'=>$metadata['status'],'reason'=>'send_not_acknowledged']+
                ($refused?['serverCode'=>$error->serverCode]:[]));
            throw $error;
        }
        // Local state must be committed before application callbacks can throw or stop the worker.
        $this->projectAcknowledgedOutgoing($metadata);
        ($this->emit)('message_ack', $metadata);
        if ($metadata['to']===StatusStore::CHAT) {
            $record=$this->outbox($metadata['id']);
            $bytes=is_string($record['plaintext']??null)?base64_decode($record['plaintext'],true):false;
            if ($bytes!==false) {
                $decoded=MessageCodec::decode($bytes);$author=ContentBuilder::bare($record['author']);
                $event=$this->statusStore()->recordEvent([...$metadata,'chat'=>StatusStore::CHAT,'fromMe'=>true,'participant'=>$author,'proto'=>$decoded['proto']??[],
                    'type'=>$decoded['kind'],'text'=>$decoded['text']??null,'data'=>$decoded['data']??[],
                    'key'=>['id'=>$metadata['id'],'remoteJid'=>StatusStore::CHAT,'fromMe'=>true,'participant'=>$author]]);
                if ($event!==null) ($this->emit)('status',[...$event,'deliveryStatus'=>$metadata['status']]);
            }
        }
        if(!$this->closeRequested && ($node->attrs['category'] ?? '')!=='peer')$this->privacyTokens->queueSenderToken($metadata['to']);
        return $metadata;
    }

    /** Local projections only follow a confirmed send and retain the original encrypted audit data.
     *
     * @param array<string,mixed> $metadata */
    private function projectAcknowledgedOutgoing(array $metadata):void
    {
        if (!in_array($metadata['status']??null,['server_ack','delivered','read','played'],true)) return;
        if(isset($this->projectingOutgoing[$metadata['id']]))return;
        $this->projectingOutgoing[$metadata['id']]=true;
        try {
            $album=$this->store->transaction(function()use($metadata):?array {
                $id=$metadata['id'];$done=$this->store->get(self::OUTGOING_PROJECTION_DONE,$id);
                if($done!==null){
                    $marker=BinaryData::decode($done);
                    if(!is_array($marker))throw new \UnexpectedValueException('Invalid outgoing projection marker');
                    if(is_array($marker['albumEvent']??null))return $marker['albumEvent'];
                    $this->store->delete(self::OUTGOING_PROJECTION_PENDING,$id);
                    return null;
                }
                $saved=$this->outbox($id);
                if($saved===null){$this->store->delete(self::OUTGOING_PROJECTION_PENDING,$id);return null;}
                $current=$saved['metadata'];
                if(!in_array($current['status']??null,['server_ack','delivered','read','played'],true))return null;
                if(!is_string($saved['plaintext']??null)||!is_string($saved['author']??null))throw new \UnexpectedValueException('Outgoing projection requires persisted plaintext and author');
                $plain=base64_decode($saved['plaintext'],true);
                if($plain===false)throw new \UnexpectedValueException('Invalid outgoing projection plaintext');
                $decoded=MessageCodec::decode($plain);$author=ContentBuilder::bare($saved['author']);
                $event=[...$current,'chat'=>$current['to'],'from'=>$author,'fromMe'=>true,'authenticatedSender'=>$author,'authenticatedOwnDevice'=>true,
                    'type'=>$decoded['kind'],'text'=>$decoded['text']??null,'data'=>$decoded['data']??[],'proto'=>$decoded['proto'],
                    'key'=>['id'=>$id,'remoteJid'=>$current['to'],'fromMe'=>true]];
                if(str_ends_with($current['to'],'@g.us'))$event['key']['participant']=$event['participant']=$author;
                foreach(['poll','event','album','association','music','audioSticker','locationRequest','chatTheme','statusContent','scheduledCall','eventCover','pollMedia','statusInteraction','ctwa','acp2','contactRefresh','spoiler','quote','instantImage','carousel','channelInvite','botReminder','botSources','botProgress','botSuggestions','botMemory','botCapabilities','payment']as$field)if(isset($decoded[$field]))$event[$field]=$decoded[$field];
                $addon=AddonCrypto::identify($decoded['proto']);
                if($addon!==null){
                    $parent=$this->lookupSecret($current['to'],$addon['key']['id']??'');
                    if($parent===null)throw new \LogicException('Outgoing projection parent secret is unavailable');
                    $data=AddonCrypto::decrypt($parent['secret'],$addon['key']['id'],$parent['author'],$author,$addon['modificationType'],$addon['encPayload'],$addon['encIv']);
                    $event['data']=AddonCrypto::decodePlaintext($addon['kind'],$data);
                    $event['type']=$addon['kind'];$event['target']=$addon['key'];$event['encrypted']=false;
                    $event=array_replace($event,AdvancedEvents::normalize([],$addon['kind'],$event['data']));
                }
                $share=$this->history->store->decodePhoneNumberShare($event);if($share!==null)$event['phoneNumberShare']=$share;
                $this->history->store->recordEvent($event);
                $this->interactions()->ingest($event);
                $album=$this->albums()->ingest($event);
                // The completion marker and all local projections share one commit.
                $this->store->set(self::OUTGOING_PROJECTION_DONE,$id,BinaryData::encode(['completedAt'=>time(),'albumEvent'=>$album]));
                if($album===null)$this->store->delete(self::OUTGOING_PROJECTION_PENDING,$id);
                return $album;
            });
            if($album!==null){
                // Keep the callback payload durable until delivery succeeds; retries do not reaggregate.
                ($this->emit)('album',$album);
                $this->store->transaction(function()use($metadata):void {
                    $this->store->set(self::OUTGOING_PROJECTION_DONE,$metadata['id'],BinaryData::encode(['completedAt'=>time(),'albumEvent'=>null]));
                    $this->store->delete(self::OUTGOING_PROJECTION_PENDING,$metadata['id']);
                });
            }
        } catch (\Throwable) {
            ($this->emit)('service_error',['operation'=>'history.interaction-projection','messageId'=>$metadata['id'],'reason'=>'projection_unavailable']);
        } finally {
            unset($this->projectingOutgoing[$metadata['id']]);
        }
    }

    /** A bounded indexed queue, never a periodic scan of the complete outbox/history. */
    private function drainOutgoingProjections():void
    {
        $page=$this->store->entries(self::OUTGOING_PROJECTION_PENDING,self::OUTGOING_PROJECTION_BATCH,$this->outgoingProjectionCursor);
        if($page===[]&&$this->outgoingProjectionCursor!==''){
            $this->outgoingProjectionCursor='';
            $page=$this->store->entries(self::OUTGOING_PROJECTION_PENDING,self::OUTGOING_PROJECTION_BATCH);
        }
        foreach($page as$id=>$ignored){
            $this->outgoingProjectionCursor=(string)$id;
            $record=$this->outbox((string)$id);
            if($record===null||!in_array($record['metadata']['status']??null,['server_ack','delivered','read','played'],true)){
                $this->store->delete(self::OUTGOING_PROJECTION_PENDING,(string)$id);
                continue;
            }
            $this->projectAcknowledgedOutgoing($record['metadata']);
        }
    }

    public function handleNode(BinaryNode $node): bool
    {
        return $this->operation(fn():bool => $this->handleNodeInternal($node));
    }

    private function handleNodeInternal(BinaryNode $node): bool
    {
        if ($this->receipts->handleAck($node)) return true;
        if ($node->tag === 'notification') {
            $this->privacyTokens->handleNode($node);$this->passiveTasks->handleNode($node);
            if ($this->mediaRetry->handleNotification($node)) { $this->ack($node,'notification'); return true; }
            if (($node->attrs['type'] ?? '') === 'encrypt') {
                $count = $node->child('count')?->attrs['value'] ?? null;
                if ($count !== null && ctype_digit($count) && (int) $count < 10) $this->preKeysPending = true;
            }
            $this->ack($node, 'notification');
            return true;
        }
        if ($node->tag === 'receipt') {
            $this->ack($node, 'receipt');
            $type = $node->attrs['type'] ?? 'delivery';
            $ids = [$node->attrs['id'] ?? ''];
            foreach (self::children($node->child('list'), 'item') as $item) $ids[] = $item->attrs['id'] ?? '';
            foreach (array_unique(array_filter($ids)) as $id) {
                $this->recordSyncedStatusReadReceipt($id,$node);
                $this->persistReceiptStatus($id,$node,$type);
                ($this->emit)('message_receipt', ['id'=>$id,'from'=>$node->attrs['from'] ?? '', 'type'=>$type,
                    'participant'=>$node->attrs['participant'] ?? null,'timestamp'=>$node->attrs['t'] ?? null]);
            }
            if (in_array($type,['retry','enc_rekey_retry'],true)) $this->queueRecipientRetry($node);
            return true;
        }
        if ($node->tag !== 'message') return false;
        $this->incoming($node);
        return true;
    }

    /** Persist monotonically increasing recipient state, independently for every device. */
    private function persistReceiptStatus(string $id,BinaryNode $node,string $type):void
    {
        $status=match($type) {'delivery',''=> 'delivered','read','read-self'=>'read','played','played-self'=>'played',default=>null};
        if ($status===null) return;
        $record=$this->outbox($id); if ($record===null) return;
        if (($record['metadata']['to']??null)===StatusStore::CHAT && in_array($type,['read-self','played-self'],true)) return;
        if (($record['metadata']['to']??null)===StatusStore::CHAT && ($node->attrs['from']??null)!==StatusStore::CHAT) return;
        $requester=$node->attrs['participant'] ?? $node->attrs['from'] ?? '';
        if (!$this->authorizedRequester($record,$requester)) return;
        $credentials=($this->getCredentials)();
        if ($this->isOwn($requester,$credentials)) return;
        $rank=['pending'=>0,'unknown'=>0,'server_ack'=>1,'delivered'=>2,'read'=>3,'played'=>4];
        $metadata=$record['metadata'];
        $metadata['receipts'] ??= [];
        $current=$metadata['receipts'][$requester]['status'] ?? 'pending';
        if ($rank[$status]>($rank[$current] ?? 0)) $metadata['receipts'][$requester]=['status'=>$status,'timestamp'=>$node->attrs['t'] ?? (string)time()];
        if ($rank[$status]>($rank[$metadata['status']] ?? 0)) $metadata['status']=$status;
        $this->updateOutbox($metadata);
        if (($metadata['to']??null)===StatusStore::CHAT && in_array($type,['read','played'],true) && $rank[$status]>($rank[$current]??0)) {
            ($this->emit)('status',['action'=>'view','id'=>$id,'author'=>ContentBuilder::bare($record['author']),
                'viewer'=>Jid::parse($requester)->canonical()->bare(),'timestamp'=>$metadata['receipts'][$requester]['timestamp'],'source'=>'authenticated_receipt']);
        }
    }

    /** A paired device's synchronized private read marks our local view, never a remote viewer. */
    private function recordSyncedStatusReadReceipt(string $id,BinaryNode $node):void
    {
        if (!in_array($node->attrs['type']??null,['read-self','played-self'],true) || ($node->attrs['recipient']??null)!==StatusStore::CHAT) return;
        $credentials=($this->getCredentials)();$from=$node->attrs['from']??'';
        try { if (!$this->isOwn($from,$credentials)) return; } catch (\InvalidArgumentException) { return; }
        $author=$node->attrs['participant']??null;if (!is_string($author)) return;
        try { $status=$this->statusStore()->getStatus($id,$author); } catch (\InvalidArgumentException) { return; }
        if ($status===null || $status['fromMe'] || $status['viewed']) return;
        $timestamp=isset($node->attrs['t']) && ctype_digit($node->attrs['t']) ? (int)$node->attrs['t'] : time();
        $this->statusStore()->markViewed($id,$author,$timestamp);
        ($this->emit)('status',['action'=>'viewed','id'=>$id,'author'=>$status['author'],'timestamp'=>$timestamp,'receiptStatus'=>'synchronized','source'=>'paired_receipt']);
    }

    private function queueRecipientRetry(BinaryNode $node):void
    {
        if (($node->attrs['type'] ?? '')==='enc_rekey_retry') return; // VoIP rekey is a different protocol.
        $id=$node->child('retry')?->attrs['id'] ?? $node->attrs['id'] ?? '';
        $requester=$node->attrs['participant'] ?? $node->attrs['from'] ?? '';
        $count=$node->child('retry')?->attrs['count'] ?? '1';
        if ($id==='' || !ctype_digit($count) || (int)$count<1 || (int)$count>4) return;
        $record=$this->outbox($id);
        if ($record===null || !is_string($record['plaintext'] ?? null) || !$this->authorizedRequester($record,$requester)) return;
        if (($record['metadata']['timestamp'] ?? 0)<time()-604800) return;
        if (isset($record['metadata']['receipts'][$requester])) return;
        $key=hash('sha256',$id."\0".$requester);
        // An identity conflict needs an explicit trust decision. New retry
        // counts must not turn that refusal into an automatic trust/reset.
        if ($this->store->get('recipient-retry-blocked',$key)!==null) return;
        $last=(int)($this->store->get('recipient-retry-count',$key) ?? '0');
        if ((int)$count<=$last) return;
        $pendingBytes=$this->store->get('recipient-retry-pending',$key);
        if ($pendingBytes!==null) {
            $pending=BinaryData::decode($pendingBytes);
            // A write may have succeeded despite its missing ACK. Preserve
            // the committed ciphertext/ratchet rather than preparing again.
            if (isset($pending['prepared'])) return;
            if (($pending['count'] ?? 0)>=(int)$count) return;
        }
        $this->store->set('recipient-retry-pending',$key,BinaryData::encode(['request'=>(new BinaryCodec())->encode($node),
            'id'=>$id,'requester'=>$requester,'count'=>(int)$count,'nextAttempt'=>0,'attempt'=>0]));
    }

    /**
     * @param array<string,mixed> $record */
    private function authorizedRequester(array $record,string $requester):bool
    {
        try {
            $credentials=($this->getCredentials)();
            $target=$this->sessionJid($requester,$credentials);
            foreach ($record['eligibleDevices'] ?? [] as $allowed) if ($target===$this->sessionJid($allowed,$credentials)) return true;
        } catch (\InvalidArgumentException) {}
        return false;
    }

    /** Retry preparation is deferred so key fetches cannot recursively enter the node dispatcher. */
    private function drainRecipientRetries():void
    {
        $this->pruneBlockedRecipientRetries();
        foreach ($this->store->entries('recipient-retry-pending',100) as $key=>$bytes) {
            $job=BinaryData::decode($bytes);
            if (!is_array($job) || !is_string($job['request'] ?? null)) throw new \UnexpectedValueException('Invalid persisted retry request');
            if (($job['nextAttempt'] ?? 0)>time()) continue;
            try {
                $record=$this->outbox($job['id']);
                if ($record===null || !$this->authorizedRequester($record,$job['requester'])) { $this->store->delete('recipient-retry-pending',(string)$key); continue; }
                if (isset($record['metadata']['receipts'][$job['requester']])) { $this->store->delete('recipient-retry-pending',(string)$key); continue; }
                $request=(new BinaryCodec())->decode($job['request']);
                if (!isset($job['prepared'])) {
                    $credentials=($this->getCredentials)(); $session=$this->sessionJid($job['requester'],$credentials);
                    $registration=self::uint(self::nodeBytes($request->child('registration'),4));
                    $existing=$this->store->getSession($session);
                    $offline=isset($request->attrs['offline']);
                    if ($offline && ($existing===null || $existing['remoteRegistration']!==$registration)) throw new \UnexpectedValueException('Offline retry registration mismatch');
                    $bundle=$this->retryKeyBundle($request,$registration);
                    if ($bundle===null) $this->ensureSessions([$job['requester']],$existing===null || $existing['remoteRegistration']!==$registration || $job['count']>=3,$registration);
                    $job=$this->store->transaction(function() use($job,$record,$credentials,$session,$bundle,$key):array {
                        if ($bundle!==null) $this->signal->ensureSession($session,$bundle,true);
                        $plain=base64_decode($record['plaintext'],true);
                        $original=base64_decode($record['node'],true);
                        if ($plain===false || $original===false) throw new \UnexpectedValueException('Invalid retry plaintext');
                        $original=(new BinaryCodec())->decode($original);
                        $to=$record['metadata']['to']; $group=str_ends_with($to,'@g.us') || str_ends_with($to,'@broadcast');
                        $body=$this->isOwn($job['requester'],$credentials) ? MessageCodec::deviceSent($plain,$to) : $plain;
                        $encrypted=$this->signal->encrypt($session,MessageCodec::pad($body));
                        $attrs=[...$original->attrs,'to'=>$group ? $to : $job['requester'],'id'=>$job['id']];
                        unset($attrs['phash'],$attrs['peer_recipient_pn']);
                        if ($group) {
                            $attrs['participant']=$job['requester'];
                            if (str_ends_with($to,'@g.us')) $attrs['addressing_mode']=Jid::parse($job['requester'])->server==='lid' ? 'lid' : 'pn';
                            $this->groups->forgetDistribution($to,$record['author'],$job['requester']);
                        }
                        $encAttrs=['v'=>'2','type'=>$encrypted['type'],'count'=>(string)$job['count']];
                        foreach (self::children($original,'enc') as $enc) if (isset($enc->attrs['mediatype'])) $encAttrs['mediatype']=$enc->attrs['mediatype'];
                        foreach (self::children($original->child('participants'),'to') as $participant) {
                            $mediaType=$participant->child('enc')?->attrs['mediatype'] ?? null;
                            if ($mediaType!==null) { $encAttrs['mediatype']=$mediaType; break; }
                        }
                        $children=[new BinaryNode('enc',$encAttrs,$encrypted['ciphertext'])];
                        if ($encrypted['type']==='pkmsg' && self::needsDeviceIdentity($credentials)) $children[]=new BinaryNode('device-identity',[],self::deviceIdentity($credentials));
                        foreach (is_array($original->content) ? $original->content : [] as $child) if (!in_array($child->tag,['participants','enc','device-identity'],true)) $children[]=$child;
                        $prepared=new BinaryNode('message',$attrs,$children);
                        $updated=[...$job,'prepared'=>(new BinaryCodec())->encode($prepared)];
                        $this->store->set('recipient-retry-pending',(string)$key,BinaryData::encode($updated));
                        return $updated;
                    });
                }
                $node=(new BinaryCodec())->decode($job['prepared']);
                $ack=($this->query)($node,30);
                if ($ack->tag!=='ack' || ($ack->attrs['class'] ?? '')!=='message' || ($ack->attrs['id'] ?? '')!==$job['id'] || isset($ack->attrs['error'])) throw new \RuntimeException('Recipient retry was not acknowledged');
                $this->store->transaction(function() use($job,$key):void {
                    $this->store->set('recipient-retry-count',(string)$key,(string)$job['count']);
                    $this->store->delete('recipient-retry-pending',(string)$key);
                });
                ($this->emit)('message_retry',['id'=>$job['id'],'requester'=>$job['requester'],'count'=>$job['count'],'status'=>'server_ack']);
            } catch (\Throwable $error) {
                $job['attempt']=($job['attempt'] ?? 0)+1;
                // SignalStore currently exposes this refusal as an
                // UnexpectedValueException. Classify only its exact local
                // prefix, before any write, without publishing the address.
                $identityChanged=!isset($job['prepared']) && $error instanceof \UnexpectedValueException
                    && str_starts_with($error->getMessage(),'Signal identity changed for ');
                $terminal=$job['attempt']>=4 || $error instanceof \UnexpectedValueException;
                $blocked=$identityChanged || ($terminal && isset($job['prepared']));
                $code=$identityChanged ? 'signal_trusted_identity_changed' : 'recipient_retry_failed';
                if ($blocked) {
                    // Retain the authenticated request, and any uncertain
                    // ciphertext, outside the runnable queue. It survives a
                    // restart without starving other recipients or spinning.
                    $this->store->transaction(function() use($job,$key,$code):void {
                        $this->store->set('recipient-retry-blocked',(string)$key,BinaryData::encode([
                            ...$job,'blockedReason'=>$code,'blockedAt'=>time(),
                        ]));
                        $this->store->delete('recipient-retry-pending',(string)$key);
                    });
                } elseif ($terminal) {
                    $this->store->transaction(function() use($job,$key):void {
                        $last=(int)($this->store->get('recipient-retry-count',(string)$key) ?? '0');
                        $this->store->set('recipient-retry-count',(string)$key,(string)max($last,$job['count']));
                        $this->store->delete('recipient-retry-pending',(string)$key);
                    });
                } else {
                    $job['nextAttempt']=time()+min(300,2**$job['attempt']);
                    $this->store->set('recipient-retry-pending',(string)$key,BinaryData::encode($job));
                }
                ($this->emit)('message_error',[
                    'id'=>$job['id'],'reason'=>'recipient_retry_failed','code'=>$code,
                    'error'=>$identityChanged ? 'Recipient retry blocked: trusted Signal identity changed'
                        : (isset($job['prepared']) ? 'Recipient retry was not confirmed' : 'Recipient retry preparation failed'),
                    'retryable'=>!$blocked && !$terminal,'retryStatus'=>$blocked ? 'blocked' : ($terminal ? 'failed' : 'pending'),
                    'retryOutcome'=>isset($job['prepared']) ? 'unknown' : 'not_executed',
                    'action'=>$identityChanged ? 'verify_recipient_identity' : 'inspect_recipient_retry',
                ]);
            }
        }
    }

    /** Retention only: never resume a blocked request or change cryptographic state. */
    private function pruneBlockedRecipientRetries():void
    {
        $now=time();
        if ($this->recipientRetryBlockedCleanupAt>$now) return;
        $rows=$this->store->entries('recipient-retry-blocked',self::RECIPIENT_RETRY_BLOCKED_CLEANUP_BATCH,$this->recipientRetryBlockedCursor);
        $this->recipientRetryBlockedCleanupAt=$now+60;
        foreach ($rows as $key=>$bytes) {
            $this->recipientRetryBlockedCursor=(string)$key;
            $job=BinaryData::decode($bytes);
            if (!is_array($job) || !is_string($job['id'] ?? null)) continue;
            $this->store->transaction(function() use($job,$key,$now):void {
                $record=$this->outbox($job['id']);
                // Match admission's original message window, not blockedAt:
                // expiry cannot make an otherwise eligible request runnable.
                if ($record===null || ($record['metadata']['timestamp'] ?? 0)<$now-604800)
                    $this->store->delete('recipient-retry-blocked',(string)$key);
            });
        }
        if (count($rows)<self::RECIPIENT_RETRY_BLOCKED_CLEANUP_BATCH) $this->recipientRetryBlockedCursor='';
    }

    /**
     * @return array{registrationId:int,identityKey:string,signedPreKey:array{id:int,public:string,signature:string},preKey?:array{id:int,public:string}}|null */
    private function retryKeyBundle(BinaryNode $request,int $registration):?array
    {
        $keys=$request->child('keys'); if ($keys===null) return null;
        $signed=$keys->child('skey'); $pre=$keys->child('key');
        $bundle=['registrationId'=>$registration,'identityKey'=>self::nodeBytes($keys->child('identity'),32),
            'signedPreKey'=>['id'=>self::uint(self::nodeBytes($signed?->child('id'),3)),
                'public'=>self::nodeBytes($signed?->child('value'),32),'signature'=>self::nodeBytes($signed?->child('signature'),64)]];
        if ($pre!==null) $bundle['preKey']=['id'=>self::uint(self::nodeBytes($pre->child('id'),3)),'public'=>self::nodeBytes($pre->child('value'),32)];
        return $bundle;
    }

    private function incoming(BinaryNode $node): void
    {
        $id = $node->attrs['id'] ?? ''; $from = $node->attrs['from'] ?? '';
        if ($id === '' || $from === '') {
            ($this->emit)('message_error', ['id'=>$id,'from'=>$from,'reason'=>'missing_message_address']);
            return;
        }
        try { $address = Jid::parse($from)->canonical(); }
        catch (\InvalidArgumentException) { $this->ack($node, 'message'); return; }
        if (!in_array($address->server, ['s.whatsapp.net','lid','g.us','broadcast','bot'], true)) {
            $this->ack($node, 'message');
            ($this->emit)('message_unsupported', ['id'=>$id,'from'=>$from,'reason'=>'unsupported_chat_type']);
            return;
        }
        $multi=in_array($address->server,['g.us','broadcast'],true);
        $dedup = hash('sha256', $from."\0".($multi ? ($node->attrs['participant'] ?? '')."\0" : '').$id);
        if ($this->store->get('message-inbox', $dedup) !== null) {
            $this->deliverInbox($dedup, true);
            $this->receipt($node);
            return;
        }
        $credentials = ($this->getCredentials)();
        try { $senderAddress = Jid::parse($node->attrs['participant'] ?? $from)->canonical();
            if (!in_array($senderAddress->server,['s.whatsapp.net','lid','bot'],true)) throw new \InvalidArgumentException('Invalid sender');
        }
        catch (\InvalidArgumentException) {
            $this->ack($node, 'message');
            ($this->emit)('message_error',['id'=>$id,'from'=>$from,'reason'=>'invalid_message_sender']);
            return;
        }
        $sender = $this->sessionJid((string) $senderAddress, $credentials);
        $senderAlias = $this->incomingAlias($node, $senderAddress);
        // Old PN-addressed sessions may receive their first reply in LID form.
        // Authenticate against the established session before migrating its alias.
        $senders=[$sender];
        foreach([(string)$senderAddress,$senderAlias]as$candidate)if($candidate!==null&&$this->signal->hasSession($candidate))$senders[]=$candidate;
        $senders=array_values(array_unique($senders));
        $decoded = null; $failure = false; $unsupported = false;
        $msmsgNodes=array_values(array_filter(self::children($node,'enc'),static fn(BinaryNode $enc):bool=>($enc->attrs['type'] ?? '')==='msmsg'));
        $encryptedNodes = $msmsgNodes!==[] ? $msmsgNodes : ($multi ? [$node] : self::children($node,'enc'));
        if ($multi && $msmsgNodes===[]) {
            $groupEncs=self::children($node,'enc');
            $senderKeyEncs=array_values(array_filter($groupEncs,static fn(BinaryNode $enc):bool=>($enc->attrs['type']??'')==='skmsg'));
            $hasDirect=false;foreach($groupEncs as$groupEnc)if(in_array($groupEnc->attrs['type']??'',['msg','pkmsg'],true))$hasDirect=true;
            // Each attempt has its own outer transaction. An invalid optional
            // direct carrier must not veto an independently authenticated skmsg
            // using a previously established sender key. Never catch a nested
            // Signal failure and then commit its partially changed ratchet.
            if($hasDirect&&count($senderKeyEncs)===1)$encryptedNodes[]=new BinaryNode('message',$node->attrs,$senderKeyEncs);
        }
        foreach ($encryptedNodes as $enc) {
            $type = $enc->attrs['type'] ?? '';
            if (!$multi && (!in_array($type, ['msg','pkmsg','msmsg'], true) || !is_string($enc->content))) { $unsupported = true; continue; }
            foreach($senders as$sender){
            try {
                $decoded = $this->store->transaction(function () use ($sender, $type, $enc, $dedup, $senderAlias, $address, $senderAddress, $node, $credentials): array {
                    if ($type==='msmsg') {
                        if (!is_string($enc->content)) throw new \UnexpectedValueException('Missing msmsg bytes');
                        $secret=\MVO\Protocol\SchemaCodec::decode('MessageSecretMessage',$enc->content);
                        $raw=MessageCodec::encode(['secretEncryptedMessage'=>['encIv'=>$secret['encIv'] ?? '', 'encPayload'=>$secret['encPayload'] ?? '']]);
                        $consumed=false;
                    } elseif (in_array($address->server,['g.us','broadcast'],true)) {
                        $raw = $this->groups->processIncoming($enc,$sender);
                        $consumed = $raw === null;
                    } else {
                        $raw = MessageCodec::unpad($this->signal->decrypt($sender, $type, $enc->content));
                        $consumed = $this->groups->consumeDistribution((string)$senderAddress,$raw);
                    }
                    $value = $raw === null ? ['kind'=>'internal'] : MessageCodec::decode($raw);
                    $record = $consumed ? ['name'=>'internal','event'=>[]] : $this->incomingEvent($node, $address, $senderAddress, $credentials, $value);
                    if ($type==='msmsg') {
                        $record['event']['authenticatedOwnDevice']=false;
                        $record['event']['authentication']='message-secret';
                    }
                    if (!$consumed && $node->child('bot')!==null) {
                        $chunk=$this->decodeBotChunk($record['event'],$node,$credentials);
                        if ($chunk!==null) {
                            $record['botChunk']=$chunk;
                            // Persist the authenticated reply and stream correlation before callbacks.
                            // Saving only SecretEncryptedMessage loses the answer on history queries/restart.
                            $record['event']=[...$chunk,'botChunk'=>array_intersect_key($chunk,array_flip(['targetMessageId','editType','editTargetId','saltId','responseMode','revisionTimestampMs']))];
                            unset($record['event']['plaintext']);
                            $record['name']='message';
                        }
                        elseif ($type==='msmsg') throw new \UnexpectedValueException('Bot chunk parent secret unavailable');
                    } elseif ($type==='msmsg') throw new \UnexpectedValueException('Bot chunk missing bot metadata');
                    // Neither aliases nor cryptographic state may be learned before Signal/AEAD authenticates.
                    if ($senderAlias !== null) {
                        $canonical = (string) $senderAddress;
                        if ($type!=='msmsg' && $sender !== $canonical && ($state = $this->store->getSession($sender)) !== null && !$this->signal->hasSession($canonical)) {
                            $this->store->trustIdentity($canonical, $state['remoteIdentity']);
                            $this->store->putSession($canonical, $state);
                        }
                        $alias=Jid::parse($senderAlias);
                        $this->rememberMapping($senderAddress->server==='lid'?$alias->bare():$senderAddress->bare(),$senderAddress->server==='lid'?$senderAddress->bare():$alias->bare());
                    }
                    if(!$consumed){
                        $record['event']=$this->incomingIdentities($record['event'],$node,$address,$senderAddress,$credentials);
                        $share=$this->history->store->decodePhoneNumberShare($record['event']);if($share!==null)$record['event']['phoneNumberShare']=$share;
                        if(isset($record['botChunk']))$record['botChunk']['key']=$record['event']['key'];
                    }
                    if (!$consumed) {
                        $this->history->store->recordEvent($record['event']);
                        $this->interactions()->ingest($record['event']);
                        $album=$this->albums()->ingest($record['event']);
                        if ($album!==null) $record['albumEvent']=$album;
                        $statusEvent=$this->statusStore()->recordEvent($record['event']);
                        if ($statusEvent!==null) $record['statusEvent']=$statusEvent;
                        $this->history->handleMessage($record['event']);
                        if ($record['name']==='message_protocol') $this->history->handleProtocol($record['event']);
                    }
                    if(($record['event']['fromMe'] ?? false)===true && in_array($address->server,['s.whatsapp.net','lid'],true)
                        && ($node->attrs['category'] ?? '')!=='peer' && !isset($node->attrs['recipient']) && is_string($value['destination'] ?? null)) {
                        // Delivery replaces the inbox body with a timestamp; retain routing only for duplicate echoes.
                        $this->store->set('message-inbox-receipt-chat',$dedup,$value['destination']);
                    }
                    $this->store->set('message-inbox', $dedup, BinaryData::encode($record));
                    $pending = $this->pendingInbox();
                    if (!in_array($dedup, $pending, true)) $pending[] = $dedup;
                    $this->store->set('message-inbox-pending', 'index', json_encode($pending, JSON_THROW_ON_ERROR));
                    return $value;
                });
                break 2;
            } catch (\Throwable) { $failure = true; }
            }
        }
        if ($decoded === null) {
            if ($failure) {
                if ($msmsgNodes!==[]) {
                    $this->ack($node,'message');
                    ($this->emit)('message_error',['id'=>$id,'from'=>$from,'reason'=>'bot_chunk_authentication_failed']);
                    return;
                }
                $this->retryReceipt($node);
                ($this->emit)('message_error', ['id'=>$id,'from'=>$from,'reason'=>'decrypt_failed']);
            } else {
                $this->ack($node, 'message');
                ($this->emit)('message_unsupported', ['id'=>$id,'from'=>$from,
                    'reason'=>$unsupported ? 'unsupported_encryption' : 'unavailable_message']);
            }
            return;
        }
        // If the process stops after commit, drainPending replays this durable
        // event after restart. Application callbacks can deduplicate chat + ID.
        $this->deliverInbox($dedup, false);
        $this->receipt($node,is_string($decoded['destination'] ?? null) ? $decoded['destination'] : null);
    }

    /**
     *
     * @param array<string,mixed> $credentials
     *
     * @param array<string,mixed> $decoded
     *
     * @return array{name:string,event:array<string,mixed>}
     */
    private function incomingEvent(BinaryNode $node, Jid $address, Jid $sender, array $credentials, array $decoded): array
    {
        $from = $node->attrs['from']; $id = $node->attrs['id'];
        $fromMe = $this->isOwn((string)$sender, $credentials);
        $chat = in_array($address->server,['g.us','broadcast'],true) ? $from : ($fromMe ? ($node->attrs['recipient'] ?? $decoded['destination'] ?? $from) : $from);
        try { $chat = Jid::parse($chat)->canonical()->bare(); }
        catch (\InvalidArgumentException) { $chat = $address->bare(); }
        $event = ['id'=>$id,'from'=>$sender->bare(),'chat'=>$chat,'fromMe'=>$fromMe,
            'authenticatedSender'=>(string)$sender,'authenticatedOwnDevice'=>$fromMe,
            'timestamp'=>isset($node->attrs['t']) && ctype_digit($node->attrs['t']) ? (int) $node->attrs['t'] : time(),
            'pushName'=>$node->attrs['notify'] ?? null,'senderDevice'=>$sender->device,'offline'=>isset($node->attrs['offline']),
            'category'=>$node->attrs['category'] ?? null,
            'key'=>['id'=>$id,'remoteJid'=>$chat,'fromMe'=>$fromMe]];
        if (!$fromMe) {
            $event['authenticatedRecipientJid']=Jid::parse($credentials['meJid'])->canonical()->bare();
            if (is_string($credentials['meLid']??null)) $event['authenticatedRecipientAlt']=Jid::parse($credentials['meLid'])->canonical()->bare();
        }
        if (in_array($address->server,['g.us','broadcast'],true)) {
            $event['participant'] = $sender->bare();
            $event['key']['participant'] = $sender->bare();
        }
        $proto = $decoded['proto'] ?? [];
        $inner = ContentDecoder::unwrap($proto)['message'];
        $secret = $decoded['messageContextInfo']['messageSecret'] ?? $inner['messageContextInfo']['messageSecret'] ?? $proto['messageContextInfo']['messageSecret'] ?? null;
        if (is_string($secret) && strlen($secret) === 32) $this->rememberSecret($chat,$id,$sender->bare(),$secret);
        $event = [...$event,'type'=>$decoded['kind'],'text'=>$decoded['text'] ?? null,'data'=>$decoded['data'] ?? [],
            'proto'=>$proto,'context'=>$decoded['context'] ?? null,'fields'=>$decoded['fields'] ?? []];
        foreach (['poll','event','album','association','music','audioSticker','locationRequest','chatTheme','statusContent','scheduledCall','eventCover','pollMedia','statusInteraction','ctwa','acp2','contactRefresh','richResponse','spoiler','quote','instantImage','carousel','channelInvite','botReminder','botSources','botProgress','botSuggestions','botMemory','botCapabilities','payment'] as $field) {
            if (array_key_exists($field, $decoded)) $event[$field] = $decoded[$field];
        }
        if (isset($inner['protocolMessage'])) $event['protocolMessage']=$inner['protocolMessage'];
        if (($media = $this->media->resolvePayload($proto)) !== null) $event['media'] = $media;
        if (($addon = AddonCrypto::identify($proto)) !== null) {
            $parent = $this->lookupSecret($chat,$addon['key']['id'] ?? '');
            $event['encrypted'] = true;
            if ($parent !== null) {
                try {
                    $plaintext = AddonCrypto::decryptWithSenderFallback($parent['secret'],$addon['key']['id'],
                        $this->identityCandidates($parent['author'],$credentials),$this->identityCandidates($sender->bare(),$credentials),
                        $addon['modificationType'],$addon['encPayload'],$addon['encIv']);
                    $event['data'] = AddonCrypto::decodePlaintext($addon['kind'],$plaintext);
                    $event['encrypted'] = false;
                    $event['type'] = $addon['kind'];
                    $event = array_replace($event, AdvancedEvents::normalize([], $addon['kind'], $event['data']));
                    $event['target'] = $addon['key'];
                } catch (\Throwable) { $event['decryptError'] = 'addon_authentication_failed'; }
            } else $event['decryptError'] = 'parent_message_secret_unavailable';
        }
        return ['name'=>$decoded['kind'] === 'protocol' ? 'message_protocol' : 'message','event'=>$event];
    }

    /** Add alternatives to an already authenticated event, preserving its chat, sender and message key.
     *
     * @param array<string,mixed> $event
     *
     * @param array<string,mixed> $credentials
     *
     * @return array<string,mixed> */
    private function incomingIdentities(array $event,BinaryNode $node,Jid $address,Jid $sender,array $credentials):array
    {
        $multi=in_array($address->server,['g.us','broadcast'],true);
        $suffix=$sender->server==='lid'?'pn':'lid';
        $senderRaw=$node->attrs[$multi?'participant_'.$suffix:'sender_'.$suffix]??$node->attrs['sender_'.$suffix]??null;
        $senderAlt=$this->authenticatedAlternative($sender,$senderRaw,$credentials);
        if($multi){if($senderAlt!==null)$event['key']['participantAlt']=$senderAlt->bare();}
        elseif($event['chat']===$sender->bare()){
            if($senderAlt!==null)$event['key']['remoteJidAlt']=$senderAlt->bare();
        }
        $recipient=null;$rawRecipient=$node->attrs['recipient']??null;
        if($rawRecipient!==null){
            try{$candidate=Jid::parse($rawRecipient)->canonical();if(in_array($candidate->server,['s.whatsapp.net','lid'],true))$recipient=$candidate;}
            catch(\InvalidArgumentException){}
        }elseif(!$multi&&in_array($address->server,['s.whatsapp.net','lid'],true)&&!$event['fromMe']){
            $field=$address->server==='lid'&&is_string($credentials['meLid']??null)?'meLid':'meJid';
            if(is_string($credentials[$field]??null)){
                $own=Jid::parse($credentials[$field])->canonical();$device=Jid::parse($credentials['meJid'])->device;
                $recipient=new Jid($own->user,$own->server,$own->device?:$device);
            }
        }
        if($recipient!==null){
            $suffix=$recipient->server==='lid'?'pn':'lid';
            $rawAlt=$node->attrs['recipient_'.$suffix]??($event['fromMe']?($node->attrs['peer_recipient_'.$suffix]??null):null);
            $recipientAlt=$this->authenticatedAlternative($recipient,$rawAlt,$credentials);
            $event['key']['recipientJid']=(string)$recipient;
            if($recipientAlt!==null)$event['key']['recipientAlt']=(string)$recipientAlt;
            // An own-device echo is keyed by the remote peer, not by the account that sent it.
            if(!$multi&&$event['chat']===$recipient->bare()&&$recipientAlt!==null)$event['key']['remoteJidAlt']=$recipientAlt->bare();
        }
        $mode=$node->attrs['addressing_mode']??null;
        if(in_array($mode,['pn','lid'],true))$event['key']['addressingMode']=$mode;
        elseif(in_array($sender->server,['s.whatsapp.net','lid'],true))$event['key']['addressingMode']=$sender->server==='lid'?'lid':'pn';
        return $event;
    }

    /** Only call after authenticating the enclosing message. Device IDs are transferred, never guessed.
     *
     * @param array<string,mixed> $credentials */
    private function authenticatedAlternative(Jid $primary,?string $provided,array $credentials):?Jid
    {
        if(!in_array($primary->server,['s.whatsapp.net','lid'],true))return null;
        $opposite=$primary->server==='lid'?'s.whatsapp.net':'lid';$alternative=null;
        if($provided!==null){
            try{$candidate=Jid::parse($provided)->canonical();
                if($candidate->server===$opposite&&preg_match('/^[0-9]{1,20}$/D',$candidate->user)&&$candidate->device<=255
                    &&($candidate->device===0||$candidate->device===$primary->device))$alternative=$candidate;
            }catch(\InvalidArgumentException){}
            if($alternative!==null){
                $phone=$primary->server==='s.whatsapp.net'?$primary:$alternative;
                $lid=$primary->server==='lid'?$primary:$alternative;
                $this->rememberMapping($phone->bare(),$lid->bare());
            }
        }
        if($alternative===null&&$this->isOwn((string)$primary,$credentials)){
            $field=$opposite==='lid'?'meLid':'meJid';
            if(is_string($credentials[$field]??null))$alternative=Jid::parse($credentials[$field])->canonical();
        }
        if($alternative===null){
            $identities=new IdentityService($this->store);
            $mapped=$primary->server==='lid'?$identities->resolvePhone($primary->bare()):$identities->resolveLid($primary->bare());
            if($mapped!==null)$alternative=Jid::parse($mapped)->canonical();
        }
        return $alternative===null?null:new Jid($alternative->user,$alternative->server,$primary->device);
    }

    /**
     * @param array<string,mixed> $event
     *
     * @param array<string,mixed> $credentials
     *
     * @return array<string,mixed>|null */
    private function decodeBotChunk(array $event,BinaryNode $node,array $credentials):?array
    {
        $sec=ContentDecoder::unwrap($event['proto'] ?? [])['message']['secretEncryptedMessage'] ?? null;
        if (!is_array($sec)) return null;
        $parent=$sec['targetMessageKey']['id'] ?? $node->child('meta')?->attrs['target_id'] ?? null;
        if (!is_string($parent) || $parent==='') return null;
        $chat=$node->child('meta')?->attrs['target_chat_jid'] ?? $event['chat'];
        $secret=$this->lookupSecret($chat,$parent); if ($secret===null) return null;
        if (!$this->isOwn($secret['author'],$credentials)) return null;
        $expectedBot=$secret['expectedBot']??null;
        if ($expectedBot===null) {
            // Legacy direct prompts are bound by their bot chat. Mentions require the saved prompt.
            if (str_ends_with($chat,'@bot') || $chat==='13135550002@s.whatsapp.net') $expectedBot=BotTransport::fbid($chat);
            else {
                $prompt=$this->history->store->getMessage($chat,$parent);
                if (is_array($prompt) && ($prompt['fromMe']??null)===true && is_array($prompt['proto']??null)) $expectedBot=BotTransport::invokedBot($prompt['proto']);
            }
        }
        if ($expectedBot===null) return null;
        try { if (BotTransport::fbid($event['from'])!==$expectedBot) return null; }
        catch (\InvalidArgumentException) { return null; }
        $secret['expectedBot']=$expectedBot;$secret['targetChat']=$chat;
        $own=[];
        foreach (['meLid','meJid'] as $field) if (is_string($credentials[$field] ?? null)) $own[]=Jid::parse($credentials[$field])->canonical()->bare();
        $chunk=BotTransport::decryptChunk($secret,$event,$node,$own);
        if ($chunk===null) return null;
        return [...$event,...$chunk,'expectedBotJid'=>$expectedBot,'encrypted'=>false];
    }

    /**
     *
     * @return list<string> */
    private function pendingInbox(): array
    {
        $bytes = $this->store->get('message-inbox-pending', 'index');
        if ($bytes === null) return [];
        $keys = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($keys) || !array_is_list($keys)) throw new \UnexpectedValueException('Invalid inbox pending index');
        foreach ($keys as $key) if (!is_string($key)) throw new \UnexpectedValueException('Invalid inbox pending key');
        return $keys;
    }

    private function deliverInbox(string $key, bool $replayed): void
    {
        if (isset($this->deliveringInbox[$key])) return;
        $bytes = $this->store->get('message-inbox', $key);
        if ($bytes === null || ctype_digit($bytes)) return;
        $record = BinaryData::decode($bytes);
        if (!is_array($record) || !is_string($record['name'] ?? null) || !is_array($record['event'] ?? null)) throw new \UnexpectedValueException('Invalid persisted incoming event');
        $this->deliveringInbox[$key]=true;
        try {
            $event = [...$record['event'],'replayed'=>$replayed];
            // Derive JSON objects from the durable original on both first delivery and replay.
            // The binary event codec intentionally stores arrays and scalars only.
            if (is_array($event['proto'] ?? null)) {
                $flowResponse = ContentDecoder::flowResponse($event['proto']);
                if ($flowResponse !== null) $event['parsedFlowResponse'] = $flowResponse;
                $formResponse = ContentDecoder::formResponse($event['proto']);
                if ($formResponse !== null) $event['parsedFormResponse'] = $formResponse;
            }
            if ($record['name'] !== 'internal') ($this->emit)($record['name'], $event);
            if (is_array($record['statusEvent'] ?? null)) ($this->emit)('status',[...$record['statusEvent'],'replayed'=>$replayed]);
            if (is_array($record['albumEvent'] ?? null)) ($this->emit)('album',[...$record['albumEvent'],'replayed'=>$replayed]);
            if (is_array($record['botChunk'] ?? null)) ($this->emit)('bot_chunk',[...$record['botChunk'],'replayed'=>$replayed]);
            $this->store->transaction(function () use ($key):void {
            // Keep a dedup marker; discard plaintext after successful application delivery.
            $this->store->set('message-inbox', $key, (string) time());
            $remaining = array_values(array_filter($this->pendingInbox(), static fn(string $item):bool => $item !== $key));
            $this->store->set('message-inbox-pending', 'index', json_encode($remaining, JSON_THROW_ON_ERROR));
            });
        } finally { unset($this->deliveringInbox[$key]); }
    }

    /**
     *
     * @param list<string> $users
     *
     * @return array<string,list<string>>
     */
    private function discoverDevices(array $users): array
    {
        $request = new BinaryNode('iq', ['type'=>'get','to'=>'s.whatsapp.net','xmlns'=>'usync'], [
            new BinaryNode('usync', ['sid'=>bin2hex(random_bytes(12)),'index'=>'0','last'=>'true','mode'=>'query','context'=>'interactive'], [
                new BinaryNode('query', [], [new BinaryNode('devices', ['version'=>'2'])]),
                new BinaryNode('list', [], array_map(static fn(string $jid):BinaryNode => new BinaryNode('user', ['jid'=>$jid]), $users))
            ])
        ]);
        $response = ($this->query)($request, 30); self::assertIq($response);
        $list = $response->child('usync')?->child('list');
        if ($list === null) throw new \UnexpectedValueException('Device discovery response missing usync list');
        $result = [];
        foreach (self::children($list, 'user') as $user) {
            $raw = $user->attrs['jid'] ?? '';
            try { $jid = Jid::parse($raw)->canonical(); }
            catch (\InvalidArgumentException) { continue; }
            if (!in_array($jid->bare(), $users, true)) continue;
            $devicesNode = $user->child('devices');
            if ($devicesNode?->child('error') !== null || $user->child('error') !== null) throw new \RuntimeException('WhatsApp device discovery rejected a target');
            $devices = [];
            foreach (self::children($devicesNode?->child('device-list'), 'device') as $device) {
                $id = $device->attrs['id'] ?? '';
                if (!ctype_digit($id) || strlen($id) > 3 || (int) $id > 255) continue;
                $hosted = (int) $id === 99 || ($device->attrs['is_hosted'] ?? '') === 'true';
                $server = $hosted ? ($jid->server === 'lid' ? 'hosted.lid' : 'hosted') : $jid->server;
                $devices[] = (string) new Jid($jid->user, $server, (int) $id);
            }
            $result[$jid->bare()] = array_values(array_unique($devices));
        }
        return $result;
    }

    /**
     * WhatsApp's current direct routing uses LID when resolvable. The contact
     * payload includes '+' so Brazil/country normalization cannot resolve a
     * different local phone number. Cache mappings, never whole device lists.
     *
     * @param array<string,mixed> $credentials
     *
     * @return array{to:string,pn:?string}
     */
    private function resolveRecipient(Jid $recipient, array $credentials): array
    {
        $bare = $recipient->bare();$identities=new IdentityService($this->store);
        if ($recipient->server === 'lid') {$current=$identities->currentLid($bare);return ['to'=>$current,'pn'=>$identities->resolvePhone($current)];}
        $cached = $identities->resolveLid($bare);
        if ($cached !== null) return ['to'=>$cached,'pn'=>$identities->resolvePhone($cached) ?? $bare];
        if ($this->isOwn($bare, $credentials) && is_string($credentials['meLid'] ?? null)) {
            return ['to'=>Jid::parse($credentials['meLid'])->canonical()->bare(),'pn'=>$bare];
        }
        $response = ($this->query)(new BinaryNode('iq', ['type'=>'get','to'=>'s.whatsapp.net','xmlns'=>'usync'], [
            new BinaryNode('usync', ['sid'=>bin2hex(random_bytes(12)),'index'=>'0','last'=>'true','mode'=>'query','context'=>'interactive'], [
                new BinaryNode('query', [], [new BinaryNode('contact'),new BinaryNode('lid')]),
                new BinaryNode('list', [], [new BinaryNode('user', ['jid'=>$bare], [new BinaryNode('contact', [], '+'.$recipient->user)])])
            ])
        ]), 30);
        self::assertIq($response);
        foreach (self::children($response->child('usync')?->child('list'), 'user') as $user) {
            $contact = $user->child('contact');
            $echo = $contact !== null && is_string($contact->content) ? $contact->content : '';
            if (($user->attrs['jid'] ?? '') !== $bare && $echo !== '+'.$recipient->user) continue;
            if ($contact !== null && in_array($contact->attrs['type'] ?? '', ['out','invalid'], true)) throw new \RuntimeException('Destination is not a valid WhatsApp account');
            $rawLid = $user->child('lid')?->attrs['val'] ?? null;
            if ($rawLid === null || $user->child('lid')?->child('error') !== null) continue;
            try { $lid = Jid::parse($rawLid)->canonical(); }
            catch (\InvalidArgumentException) { continue; }
            if ($lid->server !== 'lid') continue;
            $canonicalPn = $bare;
            try {
                $canonical = Jid::parse($user->attrs['pn_jid'] ?? $user->attrs['jid'] ?? $bare)->canonical();
                if ($canonical->server === 's.whatsapp.net') $canonicalPn = $canonical->bare();
            } catch (\InvalidArgumentException) {}
            $identities->confirmPair($bare,$canonicalPn,$lid->bare());
            return ['to'=>$lid->bare(),'pn'=>$canonicalPn];
        }
        return ['to'=>$bare,'pn'=>$bare];
    }

    private function rememberMapping(string $pn, string $lid,bool $authoritative=false): void
    {
        (new IdentityService($this->store))->remember($pn,$lid,$authoritative);
    }

    private function incomingAlias(BinaryNode $node, Jid $address): ?string
    {
        if(!in_array($address->server,['s.whatsapp.net','lid'],true))return null;
        $suffix=$address->server==='lid'?'pn':'lid';$opposite=$address->server==='lid'?'s.whatsapp.net':'lid';
        $raw = $node->attrs['participant_'.$suffix] ?? $node->attrs['sender_'.$suffix] ?? null;
        if ($raw===null) return null;
        try { $alias = Jid::parse($raw)->canonical(); }
        catch (\InvalidArgumentException) { return null; }
        if ($alias->server !== $opposite||!preg_match('/^[0-9]{1,20}$/D',$alias->user)||$alias->device>255||($alias->device!==0&&$alias->device!==$address->device)) return null;
        return (string) new Jid($alias->user, $alias->server, $address->device);
    }

    /**
     *
     * @param list<string> $devices */
    private function ensureSessions(array $devices,bool $force=false,?int $expectedRegistration=null): void
    {
        $credentials = ($this->getCredentials)();
        $missing = array_values(array_filter($devices, fn(string $jid):bool => $force || !$this->signal->hasSession($this->sessionJid($jid, $credentials))));
        if ($missing === []) return;
        $response = ($this->query)(new BinaryNode('iq', ['type'=>'get','to'=>'s.whatsapp.net','xmlns'=>'encrypt'], [
            new BinaryNode('key', [], array_map(static fn(string $jid):BinaryNode => new BinaryNode('user', ['jid'=>$jid]), $missing))
        ]), 30);
        self::assertIq($response);
        $byJid = [];
        foreach (self::children($response->child('list'), 'user') as $user) {
            $jid = $user->attrs['jid'] ?? '';
            try { $canonical = (string) Jid::parse($jid)->canonical(); }
            catch (\InvalidArgumentException) { continue; }
            if(isset($byJid[$canonical]))throw new \UnexpectedValueException('Duplicate Signal key bundle');
            $byJid[$canonical] = $user;
        }
        $bundles=[];
        foreach ($missing as $jid) {
            $user = $byJid[(string) Jid::parse($jid)->canonical()] ?? null;
            if ($user === null || $user->child('error') !== null) throw new \RuntimeException('WhatsApp key bundle missing or rejected for '.$jid);
            $signed = $user->child('skey');
            $bundle = ['registrationId'=>self::uint(self::nodeBytes($user->child('registration'), 4)),
                'identityKey'=>self::nodeBytes($user->child('identity'), 32),
                'signedPreKey'=>['id'=>self::uint(self::nodeBytes($signed?->child('id'), 3)),
                    'public'=>self::nodeBytes($signed?->child('value'), 32),
                    'signature'=>self::nodeBytes($signed?->child('signature'), 64)]];
            if (($key = $user->child('key')) !== null) {
                $bundle['preKey'] = ['id'=>self::uint(self::nodeBytes($key->child('id'), 3)), 'public'=>self::nodeBytes($key->child('value'), 32)];
            }
            if ($expectedRegistration!==null && $bundle['registrationId']!==$expectedRegistration) throw new \UnexpectedValueException('Retry key bundle registration mismatch');
            $bundles[$this->sessionJid($jid,$credentials)]=$bundle;
        }
        $this->store->transaction(function() use($bundles,$force):void {
            foreach($bundles as$jid=>$bundle)$this->signal->ensureSession($jid,$bundle,$force);
        });
    }

    private function ack(BinaryNode $node, string $class): void
    {
        $attrs = ['class'=>$class,'to'=>$node->attrs['from'] ?? 's.whatsapp.net'];
        foreach (['id','type','participant','recipient'] as $name) if (isset($node->attrs[$name])) $attrs[$name] = $node->attrs[$name];
        ($this->sendNode)(new BinaryNode('ack', $attrs));
    }

    private function receipt(BinaryNode $node,?string $destination=null): void
    {
        $attrs = ['id'=>$node->attrs['id'],'to'=>$node->attrs['from']];
        if (isset($node->attrs['participant'])) $attrs['participant'] = $node->attrs['participant'];
        if (($node->attrs['category'] ?? '') === 'peer') $attrs['type'] = 'peer_msg';
        elseif($this->isOwn($node->attrs['participant'] ?? $node->attrs['from'],($this->getCredentials)())) {
            $attrs['type']='sender';
            if(in_array(Jid::parse($node->attrs['from'])->canonical()->server,['s.whatsapp.net','lid'],true)) {
                // Own-device echoes acknowledge the sender, with the original conversation as recipient.
                if(!isset($node->attrs['recipient']) && $destination===null)
                    $destination=$this->store->get('message-inbox-receipt-chat',hash('sha256',$node->attrs['from']."\0".$node->attrs['id']));
                try{$chat=Jid::parse($node->attrs['recipient'] ?? $destination ?? '')->canonical();}
                catch(\InvalidArgumentException){$this->ack($node,'message');return;}
                if(!in_array($chat->server,['s.whatsapp.net','lid','g.us','broadcast'],true)){$this->ack($node,'message');return;}
                $sender=$node->attrs['participant'] ?? $node->attrs['from'];
                if(in_array($chat->server,['g.us','broadcast'],true)){$attrs['to']=$chat->bare();$attrs['participant']=$sender;}
                else{$attrs['to']=$sender;$attrs['recipient']=$chat->bare();unset($attrs['participant']);}
            }
        }
        $this->receipts->enqueue(new BinaryNode('receipt', $attrs));
    }

    private function retryReceipt(BinaryNode $node): void
    {
        $key = hash('sha256', $node->attrs['from']."\0".$node->attrs['id']);
        $count = (int) ($this->store->get('message-retries', $key) ?? '0')+1;
        if ($count > 3) { $this->ack($node, 'message'); return; }
        $this->store->set('message-retries', $key, (string) $count);
        $retry = ['count'=>(string) $count,'id'=>$node->attrs['id'],'v'=>'1'];
        if (isset($node->attrs['t'])) $retry['t'] = $node->attrs['t'];
        $credentials = ($this->getCredentials)();
        $content = [new BinaryNode('retry', $retry),new BinaryNode('registration', [], pack('N', $credentials['registrationId']))];
        if ($count > 1) {
            $preKey = $this->store->generatePreKeys(1)[0];
            $keyChildren = [
                new BinaryNode('type', [], "\x05"),new BinaryNode('identity', [], $credentials['identityKeyPair']['public']),
                new BinaryNode('key', [], [new BinaryNode('id', [], self::keyId($preKey['id'])),new BinaryNode('value', [], $preKey['public'])]),
                self::signedKeyNode($credentials['signedPreKey'])
            ];
            if (self::needsDeviceIdentity($credentials)) $keyChildren[]=new BinaryNode('device-identity', [], self::deviceIdentity($credentials));
            $content[] = new BinaryNode('keys', [], $keyChildren);
        }
        $attrs = ['id'=>$node->attrs['id'],'to'=>$node->attrs['from'],'type'=>'retry'];
        if (isset($node->attrs['participant'])) $attrs['participant'] = $node->attrs['participant'];
        if (isset($node->attrs['recipient'])) $attrs['recipient'] = $node->attrs['recipient'];
        if (($node->attrs['category'] ?? '') === 'peer') $attrs['category'] = 'peer';
        $this->receipts->enqueue(new BinaryNode('receipt', $attrs, $content));
    }

    /**
     *
     * @param array<string,mixed> $credentials */
    private function isOwn(string $jid, array $credentials): bool
    {
        $bare = Jid::parse($jid)->canonical()->bare();
        foreach (['meJid','meLid'] as $name) {
            if (is_string($credentials[$name] ?? null) && Jid::parse($credentials[$name])->canonical()->bare() === $bare) return true;
        }
        return false;
    }

    /**
     * Own PN/LID aliases must share a ratchet; raw routing JIDs remain unchanged.
     *
     * @param array<string,mixed> $credentials
     */
    private function sessionJid(string $jid, array $credentials): string
    {
        $parsed = Jid::parse($jid)->canonical();
        if ($parsed->server === 's.whatsapp.net' && $this->isOwn($jid, $credentials) && is_string($credentials['meLid'] ?? null)) {
            $lid = Jid::parse($credentials['meLid'])->canonical();
            return (string) new Jid($lid->user, $lid->server, $parsed->device);
        }
        if ($parsed->server === 's.whatsapp.net' && ($mapped = (new IdentityService($this->store))->resolveLid($parsed->bare())) !== null) {
            $lid = Jid::parse($mapped)->canonical();
            return (string) new Jid($lid->user, $lid->server, $parsed->device);
        }
        return (string) $parsed;
    }

    /**
     *
     * @param array<string,mixed> $credentials */
    private static function deviceIdentity(array $credentials): string
    {
        $identity = $credentials['signedIdentity'] ?? null;
        if (!is_array($identity)) throw new \LogicException('Paired signed device identity is missing');
        $bytes = '';
        foreach ([1=>'details',2=>'accountSignatureKey',3=>'accountSignature',4=>'deviceSignature'] as $number=>$name) {
            if (!is_string($identity[$name] ?? null)) throw new \UnexpectedValueException('Incomplete paired device identity');
            $bytes .= MessageCodec::bytes($number, $identity[$name]);
        }
        return $bytes;
    }
    /** Only imported primary identities on device 0 may omit a companion certificate.
     *
     * @param array<string,mixed> $credentials */
    private static function needsDeviceIdentity(array $credentials):bool
    {
        return is_array($credentials['signedIdentity'] ?? null) || ($credentials['role'] ?? '')!=='primary' ||
            !is_string($credentials['meJid'] ?? null) || Jid::parse($credentials['meJid'])->device!==0;
    }

    /**
     *
     * @param array{id:int,public:string,signature:string,private?:string} $key */
    private static function signedKeyNode(array $key): BinaryNode
    {
        return new BinaryNode('skey', [], [new BinaryNode('id', [], self::keyId($key['id'])),
            new BinaryNode('value', [], $key['public']),new BinaryNode('signature', [], $key['signature'])]);
    }
    private static function keyId(int $id): string { return substr(pack('N', $id), 1); }
    private static function uint(string $bytes): int { $result = 0; foreach (str_split($bytes) as $byte) $result = ($result << 8) | ord($byte); return $result; }
    private static function nodeBytes(?BinaryNode $node, int $length): string
    {
        if ($node === null || !is_string($node->content) || strlen($node->content) !== $length) throw new \UnexpectedValueException('Invalid WhatsApp key bundle field');
        return $node->content;
    }
    private static function assertIq(BinaryNode $node): void
    {
        if ($node->tag !== 'iq' || ($node->attrs['type'] ?? '') !== 'result' || $node->child('error') !== null) throw new \RuntimeException('WhatsApp query rejected');
    }
    /**
     *
     * @return list<BinaryNode> */
    private static function children(?BinaryNode $node, string $tag): array
    {
        if ($node === null || !is_array($node->content)) return [];
        return array_values(array_filter($node->content, static fn(BinaryNode $child):bool => $child->tag === $tag));
    }
    /**
     *
     * @return array{node:string,metadata:array<string,mixed>,plaintext?:string,author?:string,eligibleDevices?:list<string>}|null */
    private function outbox(string $id): ?array
    {
        $bytes = $this->store->get('message-outbox', $id);
        if ($bytes === null) return null;
        $record = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($record) || !is_string($record['node'] ?? null) || !is_array($record['metadata'] ?? null)) throw new \UnexpectedValueException('Invalid outgoing message record');
        if (isset($record['plaintext']) && !is_string($record['plaintext'])) throw new \UnexpectedValueException('Invalid outgoing plaintext');
        if (isset($record['author']) && !is_string($record['author'])) throw new \UnexpectedValueException('Invalid outgoing author');
        if (isset($record['eligibleDevices'])) {
            if (!is_array($record['eligibleDevices']) || !array_is_list($record['eligibleDevices'])) throw new \UnexpectedValueException('Invalid outgoing recipients');
            foreach ($record['eligibleDevices'] as $device) if (!is_string($device)) throw new \UnexpectedValueException('Invalid outgoing device');
        }
        return $record;
    }
    /**
     *
     * @param array<string,mixed> $metadata */
    private function updateOutbox(array $metadata): void
    {
        $this->store->transaction(function()use($metadata):void {
            $record = $this->outbox($metadata['id']);
            if ($record === null) throw new \LogicException('Missing persisted outgoing message');
            $record['metadata'] = $metadata;
            $this->store->set('message-outbox', $metadata['id'], json_encode($record, JSON_THROW_ON_ERROR));
            $this->history->store->updateMessage($metadata['to'],$metadata['id'],['status'=>$metadata['status'],
                'acknowledgedAt'=>$metadata['acknowledgedAt'] ?? null],$metadata['to']===StatusStore::CHAT && is_string($record['author']??null) ? ContentBuilder::bare($record['author']) : null);
            if(in_array($metadata['status']??null,['server_ack','delivered','read','played'],true)&&$this->store->get(self::OUTGOING_PROJECTION_DONE,$metadata['id'])===null){
                $this->store->set(self::OUTGOING_PROJECTION_PENDING,$metadata['id'],'1');
            }
        });
    }
}
