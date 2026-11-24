<?php
declare(strict_types=1);

namespace MVO\Facebook\Protocol;

use MVO\Facebook\FacebookException;
use MVO\Messenger\MessengerException;
use MVO\Messenger\Protocol\LightspeedDecoder;
use MVO\Messenger\Protocol\LightspeedSyncGuard;

/** Inert, bounded projection of the current native Page inbox bootstrap only.
 * No transport, JavaScript execution, persistent sync cursor or personal mailbox fallback.
 */
final class PageInboxBootstrapSnapshotReader
{
    private int $visited=0;
    /** @var list<array<string,mixed>> */ private array $preloaders=[];
    /** @var array<string,array<string,mixed>> */ private array $responses=[];

    /** The document must originate from PageInboxContextLoader under the existing account owner.
     * IDs in thread_key and comm_item_id remain distinct. The optional selection
     * is a LOCAL snapshot thread key, never a selected_item_id for a remote route.
     * @return array{threads:list<array<string,mixed>>,messages:list<array<string,mixed>>,selected_thread_id:?string,has_more:null,source:string,attachment_coverage:array{partial:bool,unsupported_procedures:list<string>}}
     */
    public function read(PageInboxDocument $document,?string $selectedItemId=null,int $limit=20,?string $beforeTimePrecise=null):array
    {
        if($limit<1||$limit>100)throw new \InvalidArgumentException('Use an inbox snapshot limit between 1 and 100');
        if($beforeTimePrecise!==null&&!preg_match('/^[0-9]{1,24}$/D',$beforeTimePrecise))throw new \InvalidArgumentException('Preserve the exact observed inbox timestamp cursor');
        $this->visited=0;$this->preloaders=[];$this->responses=[];
        $context=$document->context;$account=$context->privateRequestFields()['__user']??null;
        if(!is_string($account))self::unavailable('The native Page inbox did not identify its account owner');
        // Bind the supplied context to this exact document, not a previous cached route.
        $verified=(new PageInboxContextReader())->read($document->html(),$account,$context->pageId,$document->url);
        if($verified->actorId!==$context->actorId||$verified->profileActorId!==$context->profileActorId)self::unavailable('The native Page inbox context differs from its document');
        if($selectedItemId!==null&&($selectedItemId===''||strlen($selectedItemId)>4096||preg_match('/[\x00-\x1f\x7f]/',$selectedItemId)))throw new \InvalidArgumentException('Preserve a bounded native inbox selection ID');
        preg_match_all('~<script\b[^>]*>(.*?)</script\s*>~is',$document->html(),$scripts);
        foreach($scripts[1]as $script){
            $script=trim($script);if($script===''||!in_array($script[0],['{','['],true))continue;
            try{$value=json_decode($script,true,128,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException){continue;}
            $this->walk($value,0);
        }
        $requests=$this->requests($context->pageId);$batches=[];
        foreach($requests as $database=>$request){
            $response=$this->responses[$request['preloader']]??null;
            if(!is_array($response))self::unavailable('The Page inbox did not supply its correlated initial response');
            $dependencies=$response['dependencies']??null;$payload=$response['payload']??null;
            if(!is_array($dependencies)||!is_string($payload))self::unavailable('The native Page inbox response has an unknown Lightspeed envelope');
            $guard=new LightspeedSyncGuard(static fn(int $db):?array=>$db===$database?[
                'request'=>['epochId'=>$request['epoch'],'failureCount'=>0],
                'group'=>['cursor'=>null,'cursorKind'=>'undefined'],
            ]:null);
            try{$calls=(new LightspeedDecoder(procedureResultResolver:$guard))->decode($payload,$dependencies);}
            catch(MessengerException $error){throw new FacebookException('facebook_inbox_snapshot_unsupported','The native Page inbox snapshot uses an unsupported or unconfirmed Lightspeed batch');}
            self::transaction($calls,$database,$request['epoch']);$batches[$database]=$calls;
        }
        // All correlated batches must decode before any message or thread escapes.
        $contacts=(new PageInboxContactProjector())->project([...($batches[2]??[]),...$batches[205]]);
        $result=$this->project($batches[205],$context->pageId,$selectedItemId,$contacts);
        if($beforeTimePrecise!==null){
            $result['threads']=array_values(array_filter($result['threads'],static fn(array $row):bool=>self::compare($row['updated_time_precise'],$beforeTimePrecise)<0));
            $result['messages']=array_values(array_filter($result['messages'],static fn(array $row):bool=>self::compare($row['timestamp_precise'],$beforeTimePrecise)<0));
        }
        $result['threads']=array_slice($result['threads'],0,$limit);$result['messages']=array_slice($result['messages'],0,$limit);
        return $result;
    }

    private function walk(mixed $value,int $depth):void
    {
        if(++$this->visited>250000||$depth>100)throw new FacebookException('facebook_document_limit','The Page inbox snapshot exceeds the parser nesting limit');
        if(!is_array($value))return;
        foreach(is_array($value['require']??null)?$value['require']:[]as $row){
            if(!is_array($row))continue;
            if(is_string($row[0]??null)&&str_starts_with($row[0],'CometPlatformRootClient@')&&($row[1]??null)==='initialize'
                &&in_array('BusinessCometRootConfig',is_array($row[2]??null)?$row[2]:[],true)){
                $preloaders=$row[3][0]['expectedPreloaders']??null;
                if(is_array($preloaders))foreach($preloaders as $preloader)if(is_array($preloader)&&($preloader['queryName']??null)==='LSBizInboxGraphQLLightspeedRequestQuery')$this->preloaders[]=$preloader;
            }
            if(is_string($row[0]??null)&&($row[0]==='RelayPrefetchedStreamCache'||str_starts_with($row[0],'RelayPrefetchedStreamCache@'))&&($row[1]??null)==='next'){
                $key=$row[3][0]??null;$result=$row[3][1]['__bbox']['result']??null;
                if(!is_string($key)||!is_array($result))continue;
                $response=$result['data']['viewer']['unified_inbox_lightspeed_web_request']??null;
                if($response===null)continue;
                if(!is_array($response)||!empty($result['errors']))self::unavailable('The native Page inbox bootstrap response was rejected');
                if(isset($this->responses[$key])&&$this->responses[$key]!==$response)self::unavailable('The native Page inbox contains conflicting initial responses');
                $this->responses[$key]=$response;
            }
        }
        foreach($value as $child)if(is_array($child))$this->walk($child,$depth+1);
    }

    /** @return array<int,array{preloader:string,epoch:string}> */
    private function requests(string $pageId):array
    {
        $requests=[];$keys=[];
        foreach($this->preloaders as $preloader){
            $vars=$preloader['variables']??null;$key=$preloader['preloaderID']??null;
            if(!is_array($vars)||!is_string($key)||$key===''||strlen($key)>1024||isset($keys[$key])
                ||($vars['pageAssetId']??null)!==$pageId||($vars['igdAssetId']??null)!==null||($vars['waAssetId']??null)!==null
                ||!in_array($vars['requestType']??null,[1,'1'],true)||!is_string($vars['requestPayload']??null))self::unavailable('The native initial sync is not unambiguously bound to the selected Page');
            try{$payload=json_decode($vars['requestPayload'],true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException){self::unavailable('The native Page initial sync payload is not bounded JSON');}
            $db=$payload['database']??null;$epoch=self::decimal($payload['epoch_id']??null);
            if(!is_array($payload)||!is_int($db)||!in_array($db,[2,205],true)||$epoch===null||isset($requests[$db])
                ||!array_key_exists('last_applied_cursor',$payload)||$payload['last_applied_cursor']!==null)self::unavailable('The native Page inbox does not provide one initial mailbox request');
            $requests[$db]=['preloader'=>$key,'epoch'=>$epoch];$keys[$key]=true;
        }
        if(!isset($requests[205]))self::unavailable('The native Page inbox did not preload its Page mailbox database');
        return $requests;
    }

    /** @param list<array{name:string,args:list<mixed>}> $calls */
    private static function transaction(array $calls,int $database,string $epoch):void
    {
        $first=$calls[0]??null;$last=$calls[array_key_last($calls)]??null;
        if(($first['name']??null)!=='LSExecuteFirstBlockForSyncTransactionV4'||($last['name']??null)!=='LSExecuteFinallyBlockForSyncTransaction'
            ||self::decimal($first['args'][0]??null)!==(string)$database||self::decimal($first['args'][1]??null)!==$epoch
            ||self::decimal($last['args'][1]??null)!==(string)$database||self::decimal($last['args'][2]??null)!==$epoch)self::unavailable('The native initial response does not match its Page database and epoch');
        $begins=0;$ends=0;
        foreach($calls as $call){if($call['name']==='LSExecuteFirstBlockForSyncTransactionV4')++$begins;if($call['name']==='LSExecuteFinallyBlockForSyncTransaction')++$ends;}
        if($begins!==1||$ends!==1)self::unavailable('The native Page initial response contains ambiguous sync transactions');
    }

    /** @param list<array{name:string,args:list<mixed>}> $calls
     * @param array<string,array{id:string,name:?string,photo_url:?string}> $contacts
     * @return array{threads:list<array<string,mixed>>,messages:list<array<string,mixed>>,selected_thread_id:?string,has_more:null,source:string,attachment_coverage:array{partial:bool,unsupported_procedures:list<string>}}
     */
    private function project(array $calls,string $pageId,?string $selectedItemId,array $contacts):array
    {
        $threads=[];$threadTypes=[];$participants=[];$business=[];$messages=[];
        foreach($calls as $call){
            $args=$call['args'];
            switch($call['name']){
                case 'LSDeleteThenInsertThread':
                    // Offsets corroborated in LSDeleteThenInsertThread and EventProjector.
                    $id=self::id($args[7]??null);$updated=self::decimal($args[0]??null);
                    if($id===null||$updated===null||count($args)<10)self::unavailable('The native Page mailbox supplied an invalid thread row');
                    if(isset($args[66])&&self::decimal($args[66])!=='205')self::unavailable('The native thread belongs to another sync group');
                    $thread=['id'=>$id,'selection_id'=>null,'thread_key'=>['thread_fbid'=>$id,'other_user_id'=>null],
                        'name'=>is_string($args[3]??null)?$args[3]:null,'photo_url'=>PageInboxContactProjector::photoUrl($args[4]??null,$args[11]??null),
                        'folder'=>is_string($args[10]??null)?$args[10]:null,
                        'unread_count'=>self::countValue($args[89]??null),'messages_count'=>null,'updated_time_precise'=>$updated,
                        'cannot_reply_reason'=>is_string($args[30]??null)?$args[30]:null,
                        'composer_input_disabled'=>is_bool($args[44]??null)?$args[44]:null,
                        'comm_item_id'=>null,'selection_source'=>'snapshot_thread_key'];
                    $type=self::decimal($args[9]??null);
                    if(isset($threads[$id])&&($threads[$id]!==$thread||$threadTypes[$id]!==$type))self::unavailable('The native Page mailbox supplied conflicting thread rows');
                    $threads[$id]=$thread;$threadTypes[$id]=$type;break;
                case 'LSAddParticipantIdToGroupThread':
                    // Shipped procedure stores threadKey=slot0 and contactId=slot1.
                    $thread=self::id($args[0]??null);$participant=self::id($args[1]??null);
                    if($thread===null||$participant===null)self::unavailable('The native Page mailbox supplied invalid participants');
                    $participants[$thread][$participant]=true;break;
                case 'LSSetBusinessThreadInfo':
                    // Shipped table260: threadKey=slot0, commItemId=slot1.
                    $thread=self::id($args[0]??null);$comm=self::id($args[1]??null);
                    if($thread===null||$comm===null||isset($business[$thread])&&$business[$thread]!==$comm)self::unavailable('The native business thread identity is ambiguous');
                    $business[$thread]=$comm;break;
                case 'LSInsertMessage':case 'LSUpsertMessage':
                    // Same wire offsets as EventProjector; undefined text is an attachment, not an empty string.
                    $thread=self::id($args[3]??null);$sender=self::id($args[10]??null);$id=$args[8]??null;$timestamp=self::decimal($args[5]??null);
                    if($thread===null||$sender===null||!is_string($id)||$id===''||strlen($id)>512||$timestamp===null)self::unavailable('The native Page mailbox supplied an invalid message row');
                    $message=['id'=>$id,'message_id'=>$id,'offline_threading_id'=>is_string($args[9]??null)?$args[9]:null,
                        'thread_id'=>$thread,'sender_id'=>$sender,'text'=>is_string($args[0]??null)?$args[0]:null,'snippet'=>null,
                        'timestamp_precise'=>$timestamp,'unread'=>null];
                    if(isset($messages[$id])&&$messages[$id]!==$message)self::unavailable('The native Page mailbox supplied conflicting message rows');
                    $messages[$id]=$message;break;
            }
        }
        foreach($threads as $id=>&$thread){
            if(!isset($participants[$id][$pageId])||!isset($business[$id]))self::unavailable('A native inbox thread did not confirm the selected Page as a participant and business owner');
            $others=array_values(array_filter(array_keys($participants[$id]),static fn(string|int $id):bool=>(string)$id!==$pageId));
            $thread['thread_key']['other_user_id']=count($others)===1?(string)$others[0]:null;
            $thread['participants']=array_map(static function(string|int $participant)use($contacts,$pageId):array{
                $participant=(string)$participant;$contact=$contacts[$participant]??null;
                return ['id'=>$participant,'name'=>$contact['name']??null,'photo_url'=>$contact['photo_url']??null,'is_page'=>$participant===$pageId];
            },array_keys($participants[$id]));
            // LSMessagingThreadTypeUtil.isOneToOne: membership count alone is
            // insufficient for a group with just one remaining participant.
            if(count($others)===1&&in_array($threadTypes[$id],['1','7','10','13','15','201','207','228'],true)){
                $contact=$contacts[(string)$others[0]]??null;
                if($thread['name']===null||trim($thread['name'])==='')$thread['name']=$contact['name']??null;
                $thread['photo_url']??=$contact['photo_url']??null;
            }
            $thread['comm_item_id']=$business[$id];
            $thread['selection_id']=(string)$id;
        }
        unset($thread);
        foreach($messages as $message)if(!isset($threads[$message['thread_id']])||!isset($participants[$message['thread_id']][$message['sender_id']]))self::unavailable('A native message did not belong to a confirmed Page thread and participant');
        $attachmentProjector=new PageInboxAttachmentProjector();
        $attachments=$attachmentProjector->project($calls,array_values($messages),$pageId);
        foreach($messages as &$message){
            $contact=$contacts[$message['sender_id']]??null;
            $message['sender_name']=$contact['name']??null;$message['sender_photo_url']=$contact['photo_url']??null;
            $message['attachments']=$attachments[$message['message_id']]??[];
        }
        unset($message);
        // This selects already decoded rows locally. It must never be used as
        // selected_item_id in a native route, GraphQL request or send preflight.
        if($selectedItemId!==null){
            if(!isset($threads[$selectedItemId]))throw new FacebookException('facebook_inbox_selection_mismatch','The selected thread is not present in the current Page bootstrap snapshot');
            $messages=array_filter($messages,static fn(array $row):bool=>$row['thread_id']===$selectedItemId);
        }
        usort($threads,static fn(array $a,array $b):int=>self::compare($b['updated_time_precise'],$a['updated_time_precise']));
        usort($messages,static fn(array $a,array $b):int=>self::compare($b['timestamp_precise'],$a['timestamp_precise']));
        return ['threads'=>$threads,'messages'=>$messages,'selected_thread_id'=>$selectedItemId,'has_more'=>null,'source'=>'page_inbox_bootstrap_lightspeed',
            'attachment_coverage'=>$attachmentProjector->coverage()];
    }

    private static function id(mixed $value):?string { $v=self::decimal($value);return $v!==null&&$v!=='0'?$v:null; }
    private static function decimal(mixed $value):?string
    {
        if(is_int($value)&&$value>=0)$value=(string)$value;
        return is_string($value)&&preg_match('/^(?:0|[1-9][0-9]{0,18})$/D',$value)&&self::compare($value,'9223372036854775807')<=0?$value:null;
    }
    private static function countValue(mixed $value):?int { $v=self::decimal($value);return $v!==null&&self::compare($v,(string)PHP_INT_MAX)<=0?(int)$v:null; }
    private static function compare(string $a,string $b):int { return strlen($a)<=>strlen($b)?:strcmp($a,$b); }
    private static function unavailable(string $message):never { throw new FacebookException('facebook_inbox_snapshot_unconfirmed',$message); }
    /** @return array<string,int> */ public function __debugInfo():array { return ['preloaders'=>count($this->preloaders),'responses'=>count($this->responses)]; }
}
