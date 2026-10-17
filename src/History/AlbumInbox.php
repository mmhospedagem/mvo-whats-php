<?php
declare(strict_types=1);
namespace MVO\History;

use MVO\Message\ContentDecoder;
use MVO\Protocol\{BinaryData,Jid};
use MVO\Signal\SignalStore;

/** Bounded projection of already authenticated album parents and associated media.
 * The returned event belongs in the caller's durable inbox record before commit.
 * Its marker prevents ordinary reassembly replay; callback delivery is at least once. */
final class AlbumInbox
{
    public const NAMESPACE='album-inbox-v1';
    public const TTL=172800;
    public const MAX_RECORDS=1000;
    private const INDEX='album-inbox-index-v1';
    /** @param \Closure():int|null $clock Unix seconds */
    public function __construct(private readonly SignalStore $store,private readonly ?\Closure $clock=null){}

    /** Only call after Signal/history authentication, using the authenticated sender fields.
     * Children may arrive before their parent. No uploaded bytes, thumbnails, keys or
     * complete message bodies are copied: items refer to the existing history rows.
     * @param array<string,mixed> $event
     * @return array<string,mixed>|null */
    public function ingest(array $event):?array
    {
        $now=$this->clock===null?time():($this->clock)();
        $identity=self::identity($event);
        if($identity===null)return null;
        $timestamp=$event['timestamp']??null;
        if(!is_int($timestamp)||$timestamp<0||$timestamp<=$now-self::TTL)return null;
        $proto=$event['proto']??null;if(!is_array($proto))return null;
        try{$unwrapped=ContentDecoder::unwrap($proto);}
        catch(\InvalidArgumentException|\UnexpectedValueException|\LengthException){return null;}
        $message=$unwrapped['message'];
        $parent=$message['albumMessage']??null;$association=$unwrapped['context']['messageAssociation']??null;
        $itemType=isset($message['imageMessage'])?'image':(isset($message['videoMessage'])?'video':null);
        if(is_array($parent)){
            if($itemType!==null)return null;
            $images=$parent['expectedImageCount']??0;$videos=$parent['expectedVideoCount']??0;
            if(!is_int($images)||!is_int($videos)||$images<0||$videos<0||$images+$videos<1||$images+$videos>100)return null;
            $parentId=$identity['id'];$item=null;
            $summary=self::reference($event,$identity)+['expectedImageCount'=>$images,'expectedVideoCount'=>$videos];
        }elseif($itemType!==null&&is_array($association)&&in_array($association['associationType']??null,[1,'MEDIA_ALBUM'],true)){
            if(isset($message['imageMessage'],$message['videoMessage']))return null;
            $index=$association['messageIndex']??null;$key=$association['parentMessageKey']??null;
            if(!is_int($index)||$index<0||$index>=100||!is_array($key)||!self::parentMatches($key,$identity,$event))return null;
            $parentId=$key['id'];if($parentId===$identity['id'])return null;
            $body=$message[$itemType.'Message'];if(!is_array($body)||($body['viewOnce']??false)===true)return null;
            if(array_intersect($unwrapped['wrappers'],['viewOnceMessage','viewOnceMessageV2','viewOnceMessageV2Extension'])!==[])return null;
            $item=self::reference($event,$identity)+['index'=>$index,'messageId'=>$identity['id'],'type'=>$itemType];
            foreach(['width','height','seconds']as$field)if(isset($body[$field])&&is_int($body[$field]))$item[$field]=$body[$field];
            // Local projection budgets, not protocol limits. Full values remain
            // in authenticated history, including captions omitted from this DTO.
            if(is_string($body['caption']??null)){
                $item['hasCaption']=$body['caption']!=='';
                if(strlen($body['caption'])<=8192)$item['caption']=$body['caption'];
            }
            if(is_string($body['mimetype']??null)&&strlen($body['mimetype'])<=255)$item['mimetype']=$body['mimetype'];
            $summary=null;
        }else return null;
        $bucketKey=hash('sha256',$identity['chat']."\0".$parentId."\0".$identity['author']."\0".($identity['fromMe']?'1':'0'));
        return $this->store->transaction(function()use($bucketKey,$identity,$parentId,$summary,$item,$now,$timestamp):?array{
            $index=$this->index($now);
            $bucket=$this->read(self::NAMESPACE,$bucketKey);
            if($bucket!==null&&($bucket['expiresAt']??0)<=$now){$this->store->delete(self::NAMESPACE,$bucketKey);unset($index[$bucketKey]);$bucket=null;}
            if($bucket===null){
                // Do not evict completion tombstones to admit a replay as a new album.
                if(count($index)>=self::MAX_RECORDS)return null;
                $bucket=['chat'=>$identity['chat'],'author'=>$identity['author'],'fromMe'=>$identity['fromMe'],'parentId'=>$parentId,
                    'createdAt'=>$now,'expiresAt'=>min($now+self::TTL,$timestamp+self::TTL),'parent'=>null,'items'=>[],'emitted'=>false];
            }
            if(($bucket['emitted']??false)===true)return null;
            if($summary!==null){
                if($bucket['parent']!==null)return null;
                $bucket['parent']=$summary;
                $total=$summary['expectedImageCount']+$summary['expectedVideoCount'];
                foreach($bucket['items']as$position=>$ignored)if($position>=$total)unset($bucket['items'][$position]);
            }elseif($item!==null){
                $position=$item['index'];
                if($bucket['parent']!==null&&$position>=$bucket['parent']['expectedImageCount']+$bucket['parent']['expectedVideoCount'])return null;
                if(isset($bucket['items'][$position]))return null;
                foreach($bucket['items']as$existing)if($existing['messageId']===$item['messageId'])return null;
                $bucket['items'][$position]=$item;
            }
            $bucket['expiresAt']=min($bucket['expiresAt'],$timestamp+self::TTL);
            $result=$this->complete($bucket);
            if($result!==null)$bucket['emitted']=true;
            $this->store->set(self::NAMESPACE,$bucketKey,BinaryData::encode($bucket));
            $index[$bucketKey]=['expiresAt'=>$bucket['expiresAt']];
            $this->store->set(self::INDEX,'index',BinaryData::encode($index));
            return $result;
        });
    }

    /** @param array<string,mixed> $bucket
     * @return array<string,mixed>|null */
    private function complete(array $bucket):?array
    {
        $parent=$bucket['parent'];if(!is_array($parent))return null;
        $images=$parent['expectedImageCount'];$videos=$parent['expectedVideoCount'];$total=$images+$videos;$items=$bucket['items'];
        if(count($items)!==$total)return null;
        ksort($items,SORT_NUMERIC);if(array_keys($items)!==range(0,$total-1))return null;
        $actualImages=0;$actualVideos=0;
        foreach($items as$item){if($item['type']==='image')++$actualImages;elseif($item['type']==='video')++$actualVideos;}
        if($actualImages!==$images||$actualVideos!==$videos)return null;
        return ['parentKey'=>$parent['key'],'parent'=>$parent,'items'=>array_values($items),'expectedImageCount'=>$images,
            'expectedVideoCount'=>$videos,'expectedCount'=>$total,'complete'=>true,'chat'=>$bucket['chat'],
            'author'=>$bucket['author'],'fromMe'=>$bucket['fromMe'],'source'=>'authenticated_history'];
    }

    /** Expire projections and completion markers together, in the inbox transaction.
     * @return array<string,array<string,mixed>> */
    private function index(int $now):array
    {
        $index=$this->read(self::INDEX,'index')??[];$changed=false;
        foreach($index as$key=>$entry){
            if(!is_string($key)||!is_array($entry)||!is_int($entry['expiresAt']??null)||$entry['expiresAt']<=$now){
                if(is_string($key))$this->store->delete(self::NAMESPACE,$key);unset($index[$key]);$changed=true;
            }
        }
        if($changed)$this->store->set(self::INDEX,'index',BinaryData::encode($index));
        return $index;
    }

    /** @param array<string,mixed> $event
     * @return array{chat:string,id:string,author:string,fromMe:bool,key:array<string,mixed>,participant:?string}|null */
    private static function identity(array $event):?array
    {
        $key=$event['key']??[];if(!is_array($key))return null;
        $id=$event['id']??$key['id']??null;$chat=self::jid($event['chat']??$key['remoteJid']??null);$fromMe=$event['fromMe']??$key['fromMe']??null;
        if(!self::id($id)||$chat===null||!is_bool($fromMe))return null;
        if(isset($key['id'])&&$key['id']!==$id||isset($key['fromMe'])&&$key['fromMe']!==$fromMe)return null;
        if(isset($key['remoteJid'])&&self::jid($key['remoteJid'])!==$chat)return null;
        $author=self::jid($event['authenticatedSender']??$event['from']??$event['participant']??$key['participant']??null);
        if($author===null||!in_array(Jid::parse($author)->server,['s.whatsapp.net','lid','bot'],true))return null;
        foreach(['from','participant']as$field)if(isset($event[$field])&&self::jid($event[$field])!==$author)return null;
        if(isset($key['participant'])&&self::jid($key['participant'])!==$author)return null;
        $participant=self::jid($event['participant']??$key['participant']??null);
        $cleanKey=['remoteJid'=>$chat,'fromMe'=>$fromMe,'id'=>$id];
        if($participant!==null||str_ends_with($chat,'@g.us')||str_ends_with($chat,'@broadcast'))$cleanKey['participant']=$author;
        return ['chat'=>$chat,'id'=>$id,'author'=>$author,'fromMe'=>$fromMe,'key'=>$cleanKey,'participant'=>$participant];
    }

    /** @param array<string,mixed> $key
     * @param array{chat:string,id:string,author:string,fromMe:bool,key:array<string,mixed>,participant:?string} $identity
     * @param array<string,mixed> $event */
    private static function parentMatches(array $key,array $identity,array $event):bool
    {
        if(!self::id($key['id']??null))return false;
        if(isset($key['fromMe'])&&!is_bool($key['fromMe']))return false;
        if(isset($key['remoteJid'])){
            $remote=self::jid($key['remoteJid']);$allowed=[$identity['chat']];
            $direct=in_array(Jid::parse($identity['chat'])->server,['s.whatsapp.net','lid'],true);
            if($direct){
                $peerAlias=self::jid($event['key']['remoteJidAlt']??null);if($peerAlias!==null)$allowed[]=$peerAlias;
                // Wire MessageKey.fromMe is relative to the original sender. A
                // received direct album can therefore name our authenticated
                // recipient as remoteJid, never an unrelated conversation.
                if(!$identity['fromMe']&&($key['fromMe']??false)===true){
                    foreach(['authenticatedRecipientJid','authenticatedRecipientAlt']as$field){$recipient=self::jid($event[$field]??null);if($recipient!==null)$allowed[]=$recipient;}
                    foreach(['recipientJid','recipientAlt']as$field){$recipient=self::jid($event['key'][$field]??null);if($recipient!==null)$allowed[]=$recipient;}
                }
            }
            if($remote===null||!in_array($remote,$allowed,true))return false;
        }
        return !isset($key['participant'])||self::jid($key['participant'])===$identity['author'];
    }

    /** @param array<string,mixed> $event
     * @param array{chat:string,id:string,author:string,fromMe:bool,key:array<string,mixed>,participant:?string} $identity
     * @return array<string,mixed> */
    private static function reference(array $event,array $identity):array
    {
        return ['id'=>$identity['id'],'key'=>$identity['key'],'author'=>$identity['author'],'timestamp'=>$event['timestamp'],
            'historyRef'=>['chat'=>$identity['chat'],'id'=>$identity['id'],'participant'=>$identity['participant'],'author'=>$identity['author'],'fromMe'=>$identity['fromMe']]];
    }
    private static function id(mixed $id):bool{return is_string($id)&&$id!==''&&strlen($id)<=512&&preg_match('//u',$id)===1&&!str_contains($id,"\0");}
    private static function jid(mixed $jid):?string
    {if(!is_string($jid))return null;try{return Jid::parse($jid)->canonical()->bare();}catch(\InvalidArgumentException){return null;}}
    /** @return array<string,mixed>|null */
    private function read(string $namespace,string $key):?array
    {
        $value=$this->store->get($namespace,$key);if($value===null)return null;
        try{$record=BinaryData::decode($value);}catch(\JsonException|\UnexpectedValueException|\LengthException){return null;}
        return is_array($record)?$record:null;
    }
}
