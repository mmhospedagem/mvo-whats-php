<?php
declare(strict_types=1);
namespace MVO\History;

use MVO\Message\{ContentDecoder,MessageCodec};
use MVO\Protocol\{BinaryData,Jid};
use MVO\Signal\SignalStore;

/** Local queryable history. All writes can participate in the Signal/inbox transaction. */
final class HistoryStore
{
    public function __construct(private readonly SignalStore $store) {}

    /** @param array<string,mixed> $chat */
    public function upsertChat(array $chat):void
    {
        $jid=self::jid($chat['jid'] ?? $chat['id'] ?? null);
        $this->merge('history-chats',$jid,[...$chat,'jid'=>$jid]);
    }

    /** @param array<string,mixed> $contact */
    public function upsertContact(array $contact):void
    {
        $jid=self::jid($contact['jid'] ?? null);
        $this->merge('history-contacts',$jid,[...$contact,'jid'=>$jid]);
    }

    /** @param array<string,mixed> $message */
    public function upsertMessage(array $message):void
    {
        $chat=self::jid($message['chat'] ?? $message['key']['remoteJid'] ?? null);
        $id=$message['id'] ?? $message['key']['id'] ?? null;
        if (!is_string($id) || $id==='') throw new \InvalidArgumentException('History message needs an ID');
        $participant=$message['participant'] ?? $message['key']['participant'] ?? '';
        if (!is_string($participant)) throw new \InvalidArgumentException('Invalid message participant');
        $participant=$participant==='' ? '' : self::jid($participant);
        $record=[...$message,'chat'=>$chat,'id'=>$id];
        if ($participant!=='') $record['participant']=$participant;
        $key=self::messageKey($id,$participant);
        $this->merge('history-messages:'.$chat,$key,$record);
        $old=$this->getChat($chat) ?? ['jid'=>$chat];
        $timestamp=$record['timestamp'] ?? 0;
        if (is_int($timestamp) && $timestamp>=($old['lastMessageTimestamp'] ?? 0)) {
            $old['lastMessageTimestamp']=$timestamp; $old['lastMessageId']=$id;
        }
        $this->upsertChat($old);
    }

    /** @param array<string,mixed> $event */
    public function recordEvent(array $event):void
    {
        if (!is_string($event['id'] ?? null) || !is_string($event['chat'] ?? null)) return;
        $this->upsertMessage($event);
        $this->messageObservations()->ingest($event);
        (new ObservedProtocolStore($this->store))->recordMessage($event);
        if ($event['chat']===StatusStore::CHAT) (new StatusStore($this->store,$this))->recordEvent($event);
        if (is_string($event['from'] ?? null) && is_string($event['pushName'] ?? null) && $event['pushName']!=='') {
            $this->upsertContact(['jid'=>$event['from'],'pushName'=>$event['pushName']]);
        }
    }

    /** @param array<string,mixed> $info */
    public function recordWebMessage(array $info,string $chat,?string $ownJid=null):void
    {
        $key=$info['key'] ?? []; $id=$key['id'] ?? null;
        if (!is_string($id) || $id==='') return;
        $proto=$info['message'] ?? [];
        $decoded=is_array($proto) ? MessageCodec::decode(\MVO\Protocol\SchemaCodec::encode('Message',$proto)) : [];
        $fromMe=($key['fromMe'] ?? false)===true;
        $group=str_ends_with($chat,'@g.us') || str_ends_with($chat,'@broadcast');
        $author=$key['participant'] ?? $info['participant'] ?? ($fromMe ? $ownJid : ($group ? null : $chat));
        $event=['id'=>$id,'chat'=>$chat,'fromMe'=>$fromMe,'timestamp'=>$info['messageTimestamp'] ?? 0,
            'key'=>[...$key,'remoteJid'=>$chat],'proto'=>$proto,'type'=>$decoded['kind'] ?? 'unknown',
            'text'=>$decoded['text'] ?? null,'data'=>$decoded['data'] ?? [],'webMessageInfo'=>$info,'history'=>true];
        if (is_string($author)) $event['from']=self::jid($author);
        if (!$fromMe&&is_string($ownJid)) $event['authenticatedRecipientJid']=self::jid($ownJid);
        if ($group && is_string($author)) $event['participant']=self::jid($author);
        $this->recordEvent($event);
        $secret=$info['messageSecret'] ?? (is_array($proto) ? (ContentDecoder::unwrap($proto)['context']['messageSecret'] ?? null) : null);
        if (is_string($secret) && strlen($secret)===32 && is_string($author)) {
            $this->store->set('message-secrets',hash('sha256',self::jid($chat)."\0".$id),BinaryData::encode(['author'=>self::jid($author),'secret'=>$secret]));
        }
    }

    /** @return array<string,mixed>|null */
    public function getChat(string $jid):?array { return $this->read('history-chats',self::jid($jid)); }
    /** @return array<string,mixed>|null */
    public function getContact(string $jid):?array { return $this->read('history-contacts',self::jid($jid)); }
    /** @return array<string,mixed>|null */
    public function getMessage(string $chat,string $id,?string $participant=null):?array
    {
        $record=$this->getStoredMessage($chat,$id,$participant);
        return $record===null?null:$this->messageObservations()->project($record);
    }
    /** Immutable received content; derived current content is exposed by getMessage.
     * @return array<string,mixed>|null */
    public function getStoredMessage(string $chat,string $id,?string $participant=null):?array
    {
        $chat=self::jid($chat);
        if ($participant!==null) return $this->read('history-messages:'.$chat,self::messageKey($id,self::jid($participant)));
        $direct=$this->read('history-messages:'.$chat,self::messageKey($id,''));
        if ($direct!==null) return $direct;
        foreach ($this->all('history-messages:'.$chat) as $record) if (($record['id'] ?? '')===$id) return $record;
        return null;
    }

    /** Ordered by JID. The returned last JID can be used as $after.
     * @return list<array<string,mixed>> */
    public function listChats(int $limit=100, string $after=''):array { return $this->page('history-chats',$limit,$after); }
    /** @return list<array<string,mixed>> */
    public function listContacts(int $limit=100,string $after=''):array { return $this->page('history-contacts',$limit,$after); }
    public function syncs():HistorySyncStore{return new HistorySyncStore($this->store);}
    public function observed():ObservedProtocolStore{return new ObservedProtocolStore($this->store);}
    public function messageObservations():MessageObservationStore{return new MessageObservationStore($this->store,$this);}
    /** Native sharing observations do not imply that a particular phone-number request was answered.
     * @return list<array<string,mixed>> */
    public function phoneNumberShares(string $chat,int $limit=100,string $after=''):array{return \MVO\Message\PhoneNumberShares::list($this,$chat,$limit,$after);}
    /** @param array<string,mixed> $event
     * @return array<string,mixed>|null */
    public function decodePhoneNumberShare(array $event):?array{return \MVO\Message\PhoneNumberShares::decode($event,new \MVO\Service\IdentityService($this->store));}
    /** Read only identities already confirmed for this individual conversation; never discover a number.
     * @return list<string> */
    public function phoneNumberShareAliases(string $chat):array
    {
        $chat=\MVO\Service\AccountToolsService::chat($chat,true);$identities=new \MVO\Service\IdentityService($this->store);$aliases=[$chat];
        $lid=str_ends_with($chat,'@lid')?$identities->currentLid($chat):$identities->resolveLid($chat);
        if($lid!==null){$aliases[]=$lid;$pn=$identities->resolvePhone($lid);if($pn!==null)$aliases[]=$pn;}
        return array_values(array_unique($aliases));
    }
    /** Authenticated and sanitized global preferences imported from the primary.
     * @return array<string,mixed>|null */
    public function observedGlobalSettings():?array{return \MVO\Service\AccountExtensionsService::readGlobalSettings($this->store);}
    /** A primary-authenticated inventory, not invented chats or downloaded history.
     * @param array<string,mixed> $conversation */
    public function recordOmittedConversation(array $conversation):void
    {
        $raw=$conversation['historyChatId']??null;$lid=$conversation['accountLid']??null;
        if(!is_string($raw)||$raw===''||strlen($raw)>256||str_contains($raw,"\0"))throw new \UnexpectedValueException('Omitted conversation requires a history chat ID');
        $chat=Jid::parse($raw)->canonical();
        if(!in_array($chat->server,['s.whatsapp.net','lid','g.us'],true)||$chat->device!==0)throw new \UnexpectedValueException('Unsupported omitted history chat JID');
        if($lid!==null){if(!is_string($lid))throw new \UnexpectedValueException('Invalid omitted account LID');$lid=Jid::parse($lid)->canonical();if($lid->server!=='lid'||$lid->device!==0)throw new \UnexpectedValueException('Invalid omitted account LID');$lid=$lid->bare();}
        $id=hash('sha256',$chat->bare()."\0".($lid??''));
        $previous=$this->read('history-omitted',$id);$received=($previous['status']??null)==='received'||$this->getChat($chat->bare())!==null;
        $this->merge('history-omitted',$id,['id'=>$id,'historyChatId'=>$chat->bare(),'accountLid'=>$lid,'status'=>$received?'received':'omitted','observedAt'=>time()]);
    }
    public function omittedInventoryProvided(bool $provided):void{$this->store->set('history-meta','omittedConversationsProvided',$provided?'1':'0');}
    /** @return array{provided:bool,conversations:list<array<string,mixed>>} */
    public function omittedConversations(int $limit=100,string $after=''):array
    {
        \MVO\Service\StateFeatureInputs::page($limit,$after);
        return ['provided'=>$this->store->get('history-meta','omittedConversationsProvided')==='1','conversations'=>$this->page('history-omitted',$limit,$after)];
    }
    /** @return array<string,mixed>|null */
    public function getOmittedConversation(string $id):?array
    {
        \MVO\Service\StateFeatureInputs::recovery($id,100);return $this->read('history-omitted',$id);
    }
    /** Mark reception only when a genuine conversation is imported; a request ACK is insufficient. */
    public function reconcileOmittedConversation(string $historyChatId):void
    {
        $jid=self::jid($historyChatId);
        foreach($this->all('history-omitted')as$record)if(($record['historyChatId']??null)===$jid)$this->merge('history-omitted',$record['id'],['status'=>'received','receivedAt'=>time()]);
    }
    /** Streams every local chat in stable JID order with bounded page memory.
     * @return \Generator<int,array<string,mixed>> */
    public function iterateChats():\Generator { yield from $this->all('history-chats'); }
    /** @return \Generator<int,array<string,mixed>> */
    public function iterateContacts():\Generator { yield from $this->all('history-contacts'); }
    /** Streams complete local message records, including arbitrarily many equal timestamps.
     * The cursor is the stored message key, never a timestamp. Across chats the
     * order is JID then storage key; within one chat it is storage-key order.
     * This is an inventory iterator, not the newest-first listMessages view.
     * @return \Generator<int,array<string,mixed>> */
    public function iterateMessages(?string $chat=null):\Generator
    {
        foreach($this->iterateStoredMessages($chat)as$record)yield $this->messageObservations()->project($record);
    }
    /** Trusted internal inventory of immutable source records; do not expose directly over HTTP.
     * @return \Generator<int,array<string,mixed>> */
    public function iterateStoredMessages(?string $chat=null):\Generator
    {
        if ($chat!==null) { yield from $this->all('history-messages:'.self::jid($chat));return; }
        foreach ($this->iterateChats() as $row) yield from $this->all('history-messages:'.self::jid($row['jid']));
    }
    /** Newest first; beforeTimestamp is exclusive.
     * @return list<array<string,mixed>> */
    public function listMessages(string $chat,int $limit=100,?int $beforeTimestamp=null):array
    {
        self::limit($limit); $records=[];
        foreach ($this->all('history-messages:'.self::jid($chat)) as $record) {
            if ($beforeTimestamp!==null && ($record['timestamp'] ?? 0)>=$beforeTimestamp) continue;
            $records[]=$record;
            if (count($records)>=$limit*2) {
                usort($records,self::newest(...)); $records=array_slice($records,0,$limit);
            }
        }
        usort($records,self::newest(...));
        return array_map($this->messageObservations()->project(...),array_slice($records,0,$limit));
    }

    /** @param array<string,mixed> $changes */
    public function updateMessage(string $chat,string $id,array $changes,?string $participant=null):void
    {
        $record=$this->getStoredMessage($chat,$id,$participant);
        if ($record!==null) $this->upsertMessage([...$record,...$changes]);
    }

    /** Local deletion only; synchronized mutations belong to AppState. */
    public function deleteMessage(string $chat,string $id,?string $participant=null):void
    {
        $record=$this->getMessage($chat,$id,$participant);
        if ($record!==null) $this->store->delete('history-messages:'.self::jid($chat),self::messageKey($id,$record['participant'] ?? ''));
    }

    public function deleteChat(string $chat):void
    {
        $chat=self::jid($chat);
        foreach ($this->all('history-messages:'.$chat) as $record) $this->store->delete('history-messages:'.$chat,self::messageKey($record['id'],$record['participant'] ?? ''));
        $this->store->delete('history-chats',$chat);
    }

    /** @param array<string,mixed> $record */
    private function merge(string $namespace,string $key,array $record):void
    {
        $this->store->set($namespace,$key,BinaryData::encode([...($this->read($namespace,$key) ?? []),...$record]));
    }
    /** @return array<string,mixed>|null */
    private function read(string $namespace,string $key):?array
    {
        $bytes=$this->store->get($namespace,$key); if ($bytes===null) return null;
        $record=BinaryData::decode($bytes);
        if (!is_array($record)) throw new \UnexpectedValueException('Invalid history record');
        return $record;
    }
    /** @return list<array<string,mixed>> */
    private function page(string $namespace,int $limit,string $after):array
    {
        self::limit($limit); $result=[];
        foreach ($this->store->entries($namespace,$limit,$after) as $bytes) {
            $record=BinaryData::decode($bytes);
            if (!is_array($record)) throw new \UnexpectedValueException('Invalid history record');
            $result[]=$record;
        }
        return $result;
    }
    /** @return \Generator<int,array<string,mixed>> */
    private function all(string $namespace):\Generator
    {
        $after='';
        do {
            $page=$this->store->entries($namespace,1000,$after);
            foreach ($page as $key=>$bytes) {
                $record=BinaryData::decode($bytes);
                if (!is_array($record)) throw new \UnexpectedValueException('Invalid history record');
                yield $record; $after=(string)$key;
            }
        } while (count($page)===1000);
    }
    private static function messageKey(string $id,string $participant):string { return hash('sha256',$id."\0".$participant); }
    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private static function newest(array $a,array $b):int { return (($b['timestamp'] ?? 0)<=>($a['timestamp'] ?? 0)) ?: strcmp($a['id'],$b['id']); }
    private static function jid(mixed $jid):string
    {
        if (!is_string($jid) || $jid==='') throw new \InvalidArgumentException('Missing history JID');
        return Jid::parse($jid)->canonical()->bare();
    }
    private static function limit(int $limit):void { if ($limit<1 || $limit>1000) throw new \InvalidArgumentException('Page limit must be 1..1000'); }
}
