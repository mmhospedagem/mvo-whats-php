<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\History\HistoryStore;
use MVO\History\ChannelStore;
use MVO\Protocol\{BinaryCodec,BinaryData,BinaryNode,Jid};
use MVO\Signal\SignalStore;

/** Durable channel events and their local history projection, with callback replay after restart. */
final class NewsletterInbox
{
    /** @var array<string,true> */
    private array $delivering=[];
    /** @param \Closure(string,array<string,mixed>):void $emit */
    public function __construct(private readonly SignalStore $store,private readonly HistoryStore $history,private readonly \Closure $emit) {}

    /** @param list<array{event:string,data:array<string,mixed>}> $entries */
    public function accept(BinaryNode $node,array $entries):bool
    {
        $groups=[];
        foreach ($entries as $entry) {
            $data=$entry['data']; $chat=$data['chat'] ?? $data['chatJid'] ?? '';
            if (!is_string($chat) || !str_ends_with($chat,'@newsletter')) continue;
            if (!in_array($entry['event'],['message','newsletter_message','newsletter_update','channel_update','service_error'],true)) continue;
            $raw=$data['rawNode'] ?? $node;
            if (!$raw instanceof BinaryNode) throw new \UnexpectedValueException('Channel event raw node missing');
            $server=$data['serverId'] ?? $data['parentMessageServerId'] ?? null;
            $id=$server===null ? ($data['id'] ?? $data['stanzaId'] ?? '') : 'server:'.$server;
            $body=(new BinaryCodec())->encode(new BinaryNode('message',[],$raw->content));
            if ($id===''&&in_array($entry['event'],['channel_update','service_error'],true))$id='metadata:'.hash('sha256',$body);
            if ($id==='') throw new \UnexpectedValueException('Channel event has no message identity');
            $version=in_array($entry['event'],['newsletter_update','channel_update'],true)?($data['timestampSeconds']??null):null;
            if(($data['isChannelQuestionResponse']??false)===true)$id=$data['id']??$data['stanzaId']??$id;
            $key=hash('sha256',BinaryData::encode([$chat,$id,($data['isChannelStatus']??false)?'status':(($data['isChannelQuestionResponse']??false)?'response':'post'),$raw->attrs['edit'] ?? '',$raw->attrs['type'] ?? '',$body,$version]));
            $packed=$data; unset($packed['rawNode']);
            $groups[$key] ??= ['rawNode'=>(new BinaryCodec())->encode($raw),'entries'=>[],'cursor'=>0];
            $groups[$key]['entries'][]=['event'=>$entry['event'],'data'=>$packed];
        }
        if ($groups===[]) return false;
        foreach ($groups as $key=>$job) {
            if ($this->store->get('newsletter-inbox-done',$key)!==null) continue;
            $this->store->transaction(function() use($key,$job):void {
                if ($this->store->get('newsletter-inbox-done',$key)!==null || $this->store->get('newsletter-inbox-pending',$key)!==null) return;
                foreach ($job['entries'] as $entry) {
                    if ($entry['event']==='message') $this->projectMessage($entry['data']);
                    elseif ($entry['event']==='newsletter_update') $this->projectUpdate($entry['data']);
                    elseif ($entry['event']==='channel_update') (new ChannelStore($this->store,$this->history))->recordUpdate($entry['data']['channelJid'],$entry['data']['action'],$entry['data']['data'],$entry['data']['timestampSeconds']??null);
                }
                $this->store->set('newsletter-inbox-pending',$key,BinaryData::encode($job));
            });
            $this->deliver($key,false);
        }
        return true;
    }

    /** Explicit history queries use the same durable projection and deduplication as pushed messages.
     * @return list<array{event:string,data:array<string,mixed>}> */
    public function ingestResponse(BinaryNode $node,string $jid):array
    {
        $entries=IncomingEvents::parseNewsletterResponse($node,$jid);$this->accept($node,$entries);return $entries;
    }

    /** Persist an own post/edit/revocation only after the correlated server ACK.
     * This local projection emits no synthetic incoming-message callback. */
    public function recordPublication(BinaryNode $post,BinaryNode $ack):void
    {
        if($post->tag!=='message'||$ack->tag!=='ack'||($ack->attrs['class']??null)!=='message'||($ack->attrs['id']??null)!==($post->attrs['id']??null)||isset($ack->attrs['error']))throw new \UnexpectedValueException('Channel publication was not acknowledged');
        $jid=ChannelStore::jid($post->attrs['to']??'');$attrs=['from'=>$jid,'id'=>$post->attrs['id'],'type'=>$post->attrs['type']??'text','is_sender'=>'true'];
        foreach(['server_id','t']as$field)if(isset($ack->attrs[$field])&&ctype_digit($ack->attrs[$field])&&strlen($ack->attrs[$field])<=18)$attrs[$field]=$ack->attrs[$field];
        if(isset($post->attrs['edit']))$attrs['edit']=$post->attrs['edit'];
        if(($post->child('meta')?->attrs['questiontype']??null)==='response'&&isset($post->attrs['server_id']))$attrs['server_id']=$post->attrs['server_id'];
        $children=$post->content;
        if(is_array($children)&&isset($ack->attrs['response_server_id'])){
            $meta=$post->child('meta');$replacement=new BinaryNode('meta',($meta->attrs??[])+['response_server_id'=>$ack->attrs['response_server_id']]);
            $children=array_values(array_filter($children,static fn(BinaryNode $child):bool=>$child->tag!=='meta'));$children[]=$replacement;
            if(isset($post->attrs['server_id']))$attrs['server_id']=$post->attrs['server_id'];
        }
        $entries=IncomingEvents::parse(new BinaryNode('message',$attrs,$children));
        $this->store->transaction(function()use($entries):void {
            foreach($entries as$entry){$data=[...$entry['data'],'serverAcknowledged'=>true,'outgoing'=>true];
                if($entry['event']==='message')$this->projectMessage($data);
                elseif($entry['event']==='newsletter_update')$this->projectUpdate($data);
                elseif($entry['event']==='service_error')throw new \UnexpectedValueException('Acknowledged channel publication could not be projected');
            }
        });
    }

    public function drainPending():void
    {
        foreach ($this->store->entries('newsletter-inbox-pending',1000) as $key=>$bytes) $this->deliver((string)$key,true);
    }

    private function deliver(string $key,bool $replayed):void
    {
        if (isset($this->delivering[$key])) return;
        $bytes=$this->store->get('newsletter-inbox-pending',$key); if ($bytes===null) return;
        $job=BinaryData::decode($bytes);
        if (!is_array($job) || !is_array($job['entries'] ?? null) || !is_string($job['rawNode'] ?? null) || !is_int($job['cursor'] ?? null)) throw new \UnexpectedValueException('Invalid persisted newsletter inbox');
        $raw=(new BinaryCodec())->decode($job['rawNode']); $this->delivering[$key]=true;
        try {
            while ($job['cursor']<count($job['entries'])) {
                $entry=$job['entries'][$job['cursor']];
                ($this->emit)($entry['event'],[...$entry['data'],'rawNode'=>$raw,'replayed'=>$replayed]);
                ++$job['cursor'];
                $this->store->set('newsletter-inbox-pending',$key,BinaryData::encode($job));
            }
            $this->store->transaction(function() use($key):void {
                $this->store->set('newsletter-inbox-done',$key,(string)time());
                $this->store->delete('newsletter-inbox-pending',$key);
            });
        } finally { unset($this->delivering[$key]); }
    }

    /** @param array<string,mixed> $data */
    private function projectMessage(array $data):void
    {
        $chat=Jid::parse($data['chat'])->canonical()->bare(); $server=$data['serverId'] ?? null;
        $status=($data['isChannelStatus']??false)===true;
        $response=($data['isChannelQuestionResponse']??false)===true;
        $old=$response?$this->history->getMessage($chat,'response:'.$data['id']):$this->find($chat,$data['id'],$server,$status); $record=$data; unset($record['rawNode']);
        if($status){$record['channelOriginalMessageId']=$record['id'];$record['id']='status:'.$record['id'];}
        elseif($response){$record['channelOriginalMessageId']=$record['id'];$record['id']='response:'.$record['id'];}
        $record['timestamp']=$record['timestampSeconds'] ?? 0;
        if ($old!==null) {
            if (isset($old['editedAt']) || ($old['revoked'] ?? false)) foreach (['text','proto','type','data','media'] as $field) if (array_key_exists($field,$old)) $record[$field]=$old[$field];
            if ($old['id']!==$record['id']) $this->history->deleteMessage($chat,$old['id']);
            $record=[...$old,...$record];
        }
        $this->history->upsertMessage($record);
        (new ChannelStore($this->store,$this->history))->indexMessage($record);
    }

    /** @param array<string,mixed> $data */
    private function projectUpdate(array $data):void
    {
        $chat=Jid::parse($data['chatJid'])->canonical()->bare(); $server=$data['parentMessageServerId'] ?? null;
        $status=($data['isChannelStatus']??false)===true;
        $record=$this->find($chat,$data['stanzaId'] ?? '',$server,$status) ?? ['chat'=>$chat,'id'=>($status?'status:':'').($server===null ? $data['stanzaId'] : 'server:'.$server),
            'serverId'=>$server,'timestamp'=>0,'fromMe'=>$data['isSender'] ?? false,'isNewsletter'=>true,'isChannelStatus'=>$status,'type'=>'unknown','newsletterPlaceholder'=>true];
        $update=$data['update'] ?? []; $kind=$update['kind'] ?? '';
        $timestamp=$data['timestampSeconds'] ?? null;
        if ($kind==='edit' && ($record['revoked'] ?? false)) return;
        $times=$record['newsletterUpdateTimes']??[];
        $previous=$times[$kind]??(($record['newsletterUpdate']['kind']??null)===$kind?($record['newsletterUpdatedAt']??null):null);
        if (is_int($timestamp) && is_int($previous) && $timestamp<$previous && !in_array($kind,['revoke','counters'],true)) return;
        if ($kind==='edit') {
            $decoded=$update['data'] ?? [];
            $record=[...$record,'proto'=>$update['message'] ?? [],'text'=>$decoded['text'] ?? null,'type'=>$decoded['kind'] ?? 'unknown',
                'data'=>$decoded['data'] ?? [],'media'=>$decoded['media'] ?? null,'editedAt'=>$data['timestampSeconds'] ?? time()];
        } elseif ($kind==='revoke') {
            $record=[...$record,'revoked'=>true,'text'=>null,'proto'=>[],'data'=>[],'media'=>null];
        } elseif ($kind==='reaction') {
            $reactions=($update['snapshot']??false)?[]:($record['reactions']??[]);
            foreach ($update['reactions'] ?? [] as $reaction) {
                $code=$reaction['code'];
                $reactions=array_values(array_filter($reactions,static fn(array $entry):bool=>($entry['code'] ?? '')!==$code));
                if (!($update['revoked'] ?? false)) $reactions[]=$reaction;
            }
            $record['reactions']=$reactions;
        } elseif ($kind==='poll_vote') $record['pollVotes']=$update['votes'] ?? [];
        elseif ($kind==='counters') foreach (['views','forwards','responses'] as $field) if (isset($update[$field])&&(!is_int($timestamp)||$timestamp>=($times['counter:'.$field]??0))) {$record[$field]=$update[$field];if(is_int($timestamp))$times['counter:'.$field]=$timestamp;}
        if(is_int($timestamp))$times[$kind]=max($times[$kind]??0,$timestamp);
        $record=[...$record,'newsletterUpdate'=>$update,'newsletterUpdateTimes'=>$times,'newsletterUpdatedAt'=>max($record['newsletterUpdatedAt']??0,$timestamp??0)];
        $this->history->upsertMessage($record);
        (new ChannelStore($this->store,$this->history))->indexMessage($record);
    }

    /** @return array<string,mixed>|null */
    private function find(string $chat,string $id,int|string|null $server,bool $isChannelStatus=false):?array
    {
        if ($id!=='' && ($record=$this->history->getMessage($chat,($isChannelStatus?'status:':'').$id))!==null && ($record['isChannelStatus']??false)===$isChannelStatus) return $record;
        if ($server===null) return null;
        if(!is_int($server)&&(!ctype_digit($server)||strlen($server)>18))return null;
        return (new ChannelStore($this->store,$this->history))->findByServerId($chat,(int)$server,$isChannelStatus);
    }
}
