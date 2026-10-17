<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\AppState\AppStateProjector;
use MVO\History\HistoryStore;
use MVO\Message\ModernEvents;
use MVO\Protocol\{BinaryData,Jid};
use MVO\Signal\SignalStore;

/** Per-account projections of authenticated events. An ACK never means primary work completed. */
final class ModernStateService
{
    /** @var \Closure():array<string,mixed> */
    private \Closure $credentials;
    /** @var \Closure(string,array<string,mixed>,array<string,mixed>):array<string,mixed> */
    private \Closure $publishProtocol;
    /** @var \Closure(array<string,mixed>):array<string,mixed> */
    private \Closure $requestHistory;
    /** @var \Closure():int */
    private \Closure $clock;
    /** @param callable():array<string,mixed> $credentials
     * @param callable(string,array<string,mixed>,array<string,mixed>):array<string,mixed> $publishProtocol
     * @param callable(array<string,mixed>):array<string,mixed> $requestHistory
     * @param callable():int|null $clock */
    public function __construct(private readonly SignalStore $store,private readonly HistoryStore $history,private readonly AppStateProjector $projector,callable $credentials,callable $publishProtocol,callable $requestHistory,?callable $clock=null)
    {
        $this->credentials=\Closure::fromCallable($credentials);$this->publishProtocol=\Closure::fromCallable($publishProtocol);$this->requestHistory=\Closure::fromCallable($requestHistory);$this->clock=$clock===null?static fn():int=>(int)floor(microtime(true)*1000):\Closure::fromCallable($clock);
    }
    /** Called only by the internal, authenticated Signal event pipeline, never from request JSON.
     * @param array<string,mixed> $event
     * @return list<array{name:string,payload:array<string,mixed>}> */
    public function ingestAuthenticatedEvent(array $event):array
    {
        $sender=$event['authenticatedSender']??null;$chat=$event['chat']??null;$id=$event['id']??null;
        if(!is_string($sender)||!is_string($chat)||!is_string($id)||!is_array($event['proto']??null))return [];
        try{$sender=Jid::parse($sender)->canonical();$chat=StateFeatureInputs::chat($chat);}catch(\InvalidArgumentException){return [];}
        $own=$this->own($sender->bare());$fromMe=($event['fromMe']??false)===true;
        if($fromMe!==$own)return [];
        // Direct-chat state cannot be assigned by a third party in another chat.
        if(!$own&&!str_ends_with($chat,'@g.us')&&$sender->bare()!==$chat)return [];
        $normalized=ModernEvents::normalize($event['proto']);$events=[];
        if(is_array($normalized['ctwa']??null)&&!$own&&!str_ends_with($chat,'@g.us')){
            $key=hash('sha256',$chat."\0".$sender->bare()."\0".$id);
            if($this->store->get('ctwa-promotions',$key)===null){
                $record=['id'=>$key,'messageId'=>$id,'chat'=>$chat,'author'=>$sender->bare(),'timestamp'=>$event['timestamp']??0,'observedAt'=>($this->clock)(),'promotion'=>$normalized['ctwa'],'source'=>'authenticated_message'];
                $this->store->set('ctwa-promotions',$key,BinaryData::encode($record));$events[]=['name'=>'ctwa_promotion','payload'=>$record];
            }
        }
        $setting=$normalized['acp2']??((($normalized['acp2Context']['enabled']??false)===true)?$normalized['acp2Context']:null);
        if(is_array($setting)&&!str_ends_with($chat,'@g.us')&&$this->applyAcp2($chat,$setting,$sender->bare(),$id,$fromMe))$events[]=['name'=>'acp2','payload'=>$this->getAcp2($chat)??[]];
        if($own&&($event['authenticatedOwnDevice']??false)===true&&$sender->device===0&&is_array($normalized['contactRefresh']??null)){
            $response=$normalized['contactRefresh'];$ids=[];
            if(is_string($response['requestId']??null))$ids[]=$response['requestId'];
            foreach($response['results']as$result)foreach($result['coveredRequestIds']??[]as$covered)if(is_string($covered))$ids[]=$covered;
            foreach(array_slice(array_unique($ids),0,1000)as$requestId){
                if($response['results']===[])continue;
                try{$record=$this->getContactRefresh($requestId);}catch(\InvalidArgumentException){continue;}
                if($record===null||!in_array($record['status'],['pending','server_ack','outcome_unknown'],true))continue;
                if(($this->clock)()-$record['requestedAt']>86400000)continue;
                // Response aggregates are kept intact; ACK alone does not populate contact data.
                $record=[...$record,'status'=>'completed','completedAt'=>($this->clock)(),'responseMessageId'=>$id,'results'=>$response['results']];
                $this->writeRefresh($requestId,$record);$events[]=['name'=>'contact_refresh','payload'=>$record];
            }
        }
        return $events;
    }
    /** @return list<array<string,mixed>> */
    public function listCtwaPromotions(?string $chat=null,int $limit=100,string $after=''):array
    {
        StateFeatureInputs::page($limit,$after);$chat=$chat===null?null:StateFeatureInputs::chat($chat);$out=[];$cursor=$after;
        do{$page=$this->store->entries('ctwa-promotions',1000,$cursor);foreach($page as$key=>$bytes){$cursor=(string)$key;$record=BinaryData::decode($bytes);if(!is_array($record)||$chat!==null&&($record['chat']??null)!==$chat)continue;$out[]=$record;if(count($out)===$limit)return $out;}}while(count($page)===1000);return $out;
    }
    /** @return array<string,mixed>|null */
    public function getAcp2(string $chat):?array
    {
        $chat=StateFeatureInputs::chat($chat);$record=$this->read('chat-acp2',$chat);$history=$this->history->getChat($chat);
        $setting=$history['acp2Setting']??null;
        if(is_array($setting)&&is_bool($setting['enabled']??null)&&is_int($setting['settingTimestamp']??null)&&$setting['settingTimestamp']>($record['settingTimestamp']??0)&&$setting['settingTimestamp']<=($this->clock)()+604800000)$record=[...$setting,'chat'=>$chat,'source'=>'authenticated_history','historyWithheld'=>($history['acp2HistoryWithheld']??false)===true];
        if($record!==null)$record=[...$record,'enabledFromPrimaryOnly'=>true,'linkedRestricted'=>($record['enabled']??false)===true,'configurationSupported'=>false,'enforcementVerified'=>false];
        return $record;
    }
    /** @return array{observed:bool,refreshSupported:?bool,deviceJid:?string,timestamp:?int} */
    public function contactRefreshCapabilities():array
    {
        $latest=null;
        foreach(['DeviceCapabilitiesDevice','DeviceCapabilitiesV2']as$schema){$after='';do{$page=$this->projector->listActions($schema,1000,$after);foreach($page as$record){$after=$record['cursor'];try{$jid=Jid::parse($record['deviceJid'])->canonical();}catch(\InvalidArgumentException){continue;}if($jid->device!==0||!$this->own($jid->bare()))continue;if($latest===null||($record['timestamp']??0)>($latest['timestamp']??0))$latest=$record;}}while(count($page)===1000);}
        $supported=$latest['contactRefresh']['refreshSupported']??null;
        return ['observed'=>$latest!==null,'refreshSupported'=>is_bool($supported)?$supported:null,'deviceJid'=>$latest['deviceJid']??null,'timestamp'=>isset($latest['timestamp'])?(int)$latest['timestamp']:null];
    }
    /** @return array<string,mixed> */
    public function requestContactRefresh():array
    {
        if($this->contactRefreshCapabilities()['refreshSupported']!==true)throw new \LogicException('The primary phone has not advertised contact refresh support');
        $credentials=($this->credentials)();$primary=$credentials['meJid']??null;
        if(!is_string($primary))throw new \LogicException('Contact refresh requires a registered companion');
        $primary=Jid::parse($primary)->canonical();
        if($primary->device===0)throw new \LogicException('Contact refresh is requested by a companion from its primary phone');
        $this->pruneRefresh();$id='3EB0'.strtoupper(bin2hex(random_bytes(9)));$record=['requestId'=>$id,'status'=>'pending','requestedAt'=>($this->clock)(),'results'=>[]];$this->writeRefresh($id,$record);
        try{
            $ack=($this->publishProtocol)($primary->bare(),['type'=>'PEER_DATA_OPERATION_REQUEST_MESSAGE','peerDataOperationRequestMessage'=>['peerDataOperationRequestType'=>'CONTACT_REFRESH_REQUEST']],['id'=>$id]);
            $current=$this->getContactRefresh($id)??$record;
            if($current['status']!=='completed'){$current['status']=($ack['status']??null)==='server_ack'?'server_ack':'outcome_unknown';$this->writeRefresh($id,$current);}
            return [...$current,'message'=>$ack];
        }catch(\Throwable $error){$current=$this->getContactRefresh($id)??$record;if($current['status']!=='completed'){$current['status']='outcome_unknown';$this->writeRefresh($id,$current);}throw $error;}
    }
    /** @return array<string,mixed>|null */
    public function getContactRefresh(string $requestId):?array
    {
        StateFeatureInputs::refreshRequestId($requestId);$record=$this->read('contact-refresh',hash('sha256',$requestId));
        if($record!==null&&in_array($record['status']??null,['pending','server_ack','outcome_unknown'],true)&&($this->clock)()-$record['requestedAt']>86400000)$record['status']='expired';return $record;
    }
    /** @return array{provided:bool,conversations:list<array<string,mixed>>} */
    public function omittedConversations(int $limit=100,string $after=''):array{return $this->history->omittedConversations($limit,$after);}
    /** @return array<string,mixed> */
    public function recoverConversation(string $inventoryId,int $count=100):array
    {
        StateFeatureInputs::recovery($inventoryId,$count);$record=$this->history->getOmittedConversation($inventoryId)??throw new \OutOfBoundsException('Conversation is absent from the authenticated omitted inventory');
        $ack=($this->requestHistory)(['chatJid'=>$record['historyChatId'],'count'=>$count]);
        return ['inventoryId'=>$inventoryId,'historyChatId'=>$record['historyChatId'],'status'=>'requested','message'=>$ack,'historyReceived'=>false];
    }
    /** @param array<string,mixed> $setting */
    private function applyAcp2(string $chat,array $setting,string $author,string $id,bool $fromMe):bool
    {
        $timestamp=$setting['settingTimestamp']??null;
        if(!is_bool($setting['enabled']??null)||!is_int($timestamp)||$timestamp<=0||$timestamp>($this->clock)()+604800000)return false;
        $current=$this->getAcp2($chat);if($timestamp<=($current['settingTimestamp']??0))return false;
        $record=[...array_intersect_key($setting,array_flip(['enabled','trigger','settingTimestamp','initiatedByMe'])),'chat'=>$chat,'author'=>$author,'messageId'=>$id,'fromMe'=>$fromMe,'source'=>'authenticated_message'];
        $this->store->set('chat-acp2',$chat,BinaryData::encode($record));return true;
    }
    private function own(string $jid):bool
    {
        $credentials=($this->credentials)();foreach(['meJid','meLid']as$field)if(is_string($credentials[$field]??null)&&Jid::parse($credentials[$field])->canonical()->bare()===$jid)return true;return false;
    }
    /** @param array<string,mixed> $record */
    private function writeRefresh(string $id,array $record):void{$this->store->set('contact-refresh',hash('sha256',$id),BinaryData::encode($record));}
    private function pruneRefresh():void
    {
        $after='';$count=0;do{$page=$this->store->entries('contact-refresh',1000,$after);foreach($page as$key=>$bytes){$after=(string)$key;$record=BinaryData::decode($bytes);if(!is_array($record)||($this->clock)()-($record['requestedAt']??0)>172800000)$this->store->delete('contact-refresh',(string)$key);else ++$count;}}while(count($page)===1000);
        if($count>=1000)throw new \LengthException('Contact refresh record limit reached');
    }
    /** @return array<string,mixed>|null */
    private function read(string $namespace,string $key):?array{$bytes=$this->store->get($namespace,$key);if($bytes===null)return null;$record=BinaryData::decode($bytes);return is_array($record)?$record:null;}
}
