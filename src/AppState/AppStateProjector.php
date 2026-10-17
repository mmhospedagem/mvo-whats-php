<?php
declare(strict_types=1);
namespace MVO\AppState;

use MVO\History\HistoryStore;
use MVO\Protocol\{BinaryData,Jid};
use MVO\Signal\SignalStore;
use MVO\Service\IdentityService;

/** Materializes authenticated app-state mutations in the local history and account settings. */
final class AppStateProjector
{
    public function __construct(private readonly SignalStore $store,private readonly HistoryStore $history) {}

    /** Reconciles previously projected records that disappeared from a replacement snapshot.
     * @param list<array<string,mixed>> $mutations */
    public function replaceSnapshot(string $collection,array $mutations): void
    {
        $retained=[];foreach($mutations as $mutation)$retained[hash('sha256',$collection."\0".$mutation['index'])]=true;
        $after='';
        do {
            $page=$this->store->entries('appstate-values',1000,$after);
            foreach($page as $key=>$bytes) {
                $after=(string)$key;$old=BinaryData::decode($bytes);
                if(!is_array($old) || ($old['collection']??null)!==$collection || isset($retained[$key]) || ($old['operation']??'')==='remove')continue;
                // A snapshot is authoritative even when its surviving records have older action timestamps.
                $old['operation']='remove';$old['value']=null;$old['source']='snapshot';
                $this->apply($old);
            }
        }while(count($page)===1000);
    }

    /** Call only after AppStateService authenticates and commits a collection.
     * @param array<string,mixed> $mutation
     * @return array<string,mixed>|null */
    public function apply(array $mutation): ?array
    {
        $event=AppStateSchema::parse($mutation);
        if($event===null){
            // An authenticated new value can be observed before its index contract is known.
            // It must never become a guessed writable action specification.
            $value=$mutation['value']??null;
            if(is_string($mutation['index']??null)&&is_string($mutation['collection']??null)){
                $key=hash('sha256',$mutation['collection']."\0".$mutation['index']);
                if(($mutation['operation']??'')==='remove'&&$this->store->get('appstate-values',$key)!==null)$this->store->set('appstate-values',$key,BinaryData::encode($mutation));
                foreach(['ctwaMessageReceivedAction','communityNestingStateAction','businessFolderActivationAction','sharedDeviceAllowlistAction']as$field){
                    if(($mutation['operation']??'')==='remove'){$this->store->delete('appstate-observed:'.$field,$key);continue;}
                    if(!is_array($value)||!is_array($value[$field]??null))continue;
                    $this->store->set('appstate-values',$key,BinaryData::encode($mutation));
                    $this->store->set('appstate-observed:'.$field,$key,BinaryData::encode(['id'=>$key,'field'=>$field,'data'=>$value[$field],'index'=>$mutation['index'],'collection'=>$mutation['collection'],'timestamp'=>$mutation['timestamp']??$value['timestamp']??0,'source'=>$mutation['source']??'patch','writable'=>false]));
                }
            }
            return null;
        }
        $this->store->transaction(function()use($mutation,$event):void {
            $key=hash('sha256',$mutation['collection']."\0".$mutation['index']);
            $removed=($mutation['operation']??'')==='remove';
            // Collection version ordering is authoritative; wall clocks on account devices can disagree.
            $this->store->set('appstate-values',$key,BinaryData::encode($mutation));
            $schema=$event['schema']; $jid=$event['chatJid']??$event['remote']??null;
            if(is_string($jid))$jid=Jid::recipient($jid)->canonical()->bare();
            if($schema==='Archive' && is_string($jid))$this->history->upsertChat(['jid'=>$jid,'archived'=>$removed?false:($event['archived']??false)]);
            elseif($schema==='Pin' && is_string($jid))$this->history->upsertChat(['jid'=>$jid,'pinned'=>$removed?false:($event['pinned']??false),'pinnedTimestamp'=>$removed?0:($event['timestamp']??0)]);
            elseif($schema==='Mute' && is_string($jid))$this->history->upsertChat(['jid'=>$jid,'muted'=>$removed?false:($event['muted']??false),'muteEndTimestamp'=>$removed?0:($event['muteEndTimestamp']??0)]);
            elseif($schema==='LockChat' && is_string($jid))$this->history->upsertChat(['jid'=>$jid,'locked'=>$removed?false:($event['locked']??false)]);
            elseif($schema==='MarkChatAsRead' && is_string($jid))$this->history->upsertChat(['jid'=>$jid,'markedAsRead'=>$removed?false:($event['read']??false),'unreadCount'=>!$removed && ($event['read']??false)?0:1]);
            elseif($schema==='Star' && is_string($jid))$this->messageFlag($jid,$event,['starred'=>$removed?false:($event['starred']??false)]);
            elseif($schema==='DeleteMessageForMe' && is_string($jid) && !$removed)$this->deleteMessage($jid,$event);
            elseif($schema==='ClearChat' && is_string($jid) && !$removed)$this->clearRange($jid,$event);
            elseif($schema==='DeleteChat' && is_string($jid) && !$removed) {
                $this->clearRange($jid,$event);
                if($this->history->listMessages($jid,1)===[])$this->store->delete('history-chats',$jid);
                else $this->history->upsertChat(['jid'=>$jid,'deletedAt'=>$event['timestamp']??0]);
            }
            elseif(in_array($schema,['Contact','LidContact'],true))$this->contact($event,$removed);
            elseif($schema==='GroupHistoryToggle' && is_string($jid)){
                $mode=$event['groupHistoryToggleMode']??0;
                if(str_ends_with($jid,'@g.us')&&in_array($mode,[1,2,'ON','OFF'],true))$this->history->upsertChat(['jid'=>$jid,'groupHistorySharing'=>in_array($mode,[1,'ON'],true),'groupHistorySharingTimestamp'=>$event['timestamp']??0]);
            }
            elseif($schema==='ContactManagerMetadata' && is_string($event['metadataJid']??null)){
                $contact=Jid::parse($event['metadataJid'])->canonical();
                if($contact->server==='lid')$this->history->upsertContact(['jid'=>$contact->bare(),'managerHidden'=>$removed?false:($event['isHidden']??false)]);
            }
            elseif($schema==='PnForLidChat'){
                // The public client supports SET only; removal is not proof that
                // a linkage learned through another authenticated source expired.
                if(!$removed){
                    $lid=IdentityService::lid($event['lid']);$pn=$event['pnJid']??null;
                    if(!is_string($pn))throw new \UnexpectedValueException('Invalid PN/LID app-state phone');
                    (new IdentityService($this->store))->pnForLidChat($lid,$pn);
                    $this->history->upsertChat(['jid'=>$lid,'phoneNumber'=>IdentityService::phone($pn)]);
                }
            }
            elseif($schema==='ShareOwnPn'){
                if(!$removed){
                    $lid=IdentityService::lid($event['lid']);
                    (new IdentityService($this->store))->shareOwnPhone($lid,true);
                    $this->history->upsertChat(['jid'=>$lid,'shareOwnPhoneNumber'=>true]);
                }
            }
            elseif($schema==='LabelEdit') {
                $id=$event['id'];
                if($removed || ($event['deleted']??false)) { $this->store->delete('appstate-labels',$id);$this->removeLabelAssociations($id); }
                else $this->store->set('appstate-labels',$id,BinaryData::encode([...($this->read('appstate-labels',$id)??[]),...$event,'id'=>$id]));
            }
            elseif($schema==='LabelJid' && is_string($jid)) {
                $chat=$this->history->getChat($jid)??[];$labels=$chat['labelIds']??[];
                if(!is_array($labels))$labels=[];
                $labels=array_values(array_filter($labels,static fn($id)=>$id!==$event['labelId']));
                if(!$removed && ($event['labeled']??false))$labels[]=$event['labelId'];
                $this->history->upsertChat(['jid'=>$jid,'labelIds'=>$labels]);
            }
            elseif($schema==='LabelMessage' && is_string($jid)) {
                $message=$this->history->getMessage($jid,$event['messageId'])??['chat'=>$jid,'id'=>$event['messageId'],'timestamp'=>0,'appstatePlaceholder'=>true];
                $labels=is_array($message['labelIds']??null)?$message['labelIds']:[];
                $labels=array_values(array_filter($labels,static fn($id)=>$id!==$event['labelId']));
                if(!$removed && ($event['labeled']??false))$labels[]=$event['labelId'];
                $this->history->upsertMessage([...$message,'labelIds'=>$labels]);
            }
            elseif($schema==='BusinessBroadcastList') {
                $id=$event['id'];
                if($removed)$this->store->delete('appstate-broadcast-lists',$id);
                else $this->store->set('appstate-broadcast-lists',$id,BinaryData::encode($event));
            }
            elseif($schema==='UserStatusMute' && is_string($event['id']??null))$this->history->upsertContact(['jid'=>Jid::recipient($event['id'])->canonical()->bare(),'statusMuted'=>$removed?false:($event['muted']??false)]);
            else {
                // All remaining protocol settings remain queryable without discarding unknown action data.
                if($removed)$this->store->delete('appstate-settings',$schema);
                else $this->store->set('appstate-settings',$schema,BinaryData::encode($event));
            }
        });
        return $event;
    }

    /** @return array<string,mixed> */
    public function messageRange(string $chat,int $limit=256): array
    {
        $chat=Jid::recipient($chat)->canonical()->bare();$messages=[];$last=0;
        foreach($this->history->listMessages($chat,$limit) as $message) {
            $id=$message['id']??null;if(!is_string($id) || $id==='')continue;
            $fromMe=($message['fromMe']??$message['key']['fromMe']??false)===true;
            $key=['remoteJid'=>$chat,'id'=>$id,'fromMe'=>$fromMe];
            if(str_ends_with($chat,'@g.us') && !$fromMe) {
                $participant=$message['participant']??$message['key']['participant']??$message['from']??null;
                if(!is_string($participant) || $participant==='')continue;
                $key['participant']=(string)Jid::recipient($participant)->canonical();
            }
            $timestamp=$message['timestamp']??0;
            if(isset($message['timestampMs']) && is_int($message['timestampMs']))$timestamp=intdiv($message['timestampMs'],1000);
            if(!is_int($timestamp) || $timestamp<0)$timestamp=0;
            $messages[]=['key'=>$key,'timestamp'=>$timestamp];$last=max($last,$timestamp);
        }
        return ['lastMessageTimestamp'=>$last,'messages'=>$messages];
    }

    /** @return array<string,mixed>|null */
    public function getSetting(string $schema): ?array { return $this->read('appstate-settings',$schema); }
    /** Stable hashed cursors cover indexed actions without dropping siblings.
     * @return list<array<string,mixed>> */
    public function listActions(string $schema,int $limit=100,string $after=''):array
    {
        \MVO\Service\StateFeatureInputs::page($limit,$after);
        if(!isset(AppStateSchema::all()[$schema]))throw new \InvalidArgumentException('Unknown app-state schema');
        $out=[];$cursor=$after;
        do{
            $page=$this->store->entries('appstate-values',1000,$cursor);
            foreach($page as$key=>$bytes){
                $cursor=(string)$key;$mutation=BinaryData::decode($bytes);
                if(!is_array($mutation)||($mutation['operation']??'')==='remove')continue;
                $event=AppStateSchema::parse($mutation);
                if(($event['schema']??'')!==$schema)continue;
                unset($event['_raw']);$out[]=['cursor'=>$cursor,...$event];
                if(count($out)===$limit)return $out;
            }
        }while(count($page)===1000);
        return $out;
    }
    /** Read-only values whose writable index/semantics have not been established.
     * @return list<array<string,mixed>> */
    public function listObservedActions(string $field,int $limit=100,string $after=''):array
    {
        \MVO\Service\StateFeatureInputs::page($limit,$after);
        if(!in_array($field,['ctwaMessageReceivedAction','communityNestingStateAction','businessFolderActivationAction','sharedDeviceAllowlistAction'],true))throw new \InvalidArgumentException('Unknown observed action field');
        $out=[];foreach($this->store->entries('appstate-observed:'.$field,$limit,$after)as$bytes){$record=BinaryData::decode($bytes);if(is_array($record))$out[]=$record;}return $out;
    }
    /** Returns a parsed setting/action even when its schema has several indexed records.
     * @phpstan-impure
     * @param array<string,mixed> $args
     * @return array<string,mixed>|null */
    public function getAction(string $schema,array $args=[]): ?array
    {
        $input=AppStateSchema::mutation($schema,$args,null,0,true);
        $mutation=$this->read('appstate-values',hash('sha256',$input['collection']."\0".$input['index']));
        if($mutation===null || ($mutation['operation']??'')==='remove')return null;
        return AppStateSchema::parse($mutation);
    }
    /** @return array<string,mixed>|null */
    public function getLabel(string $id): ?array { return $this->read('appstate-labels',$id); }
    /** @return list<array<string,mixed>> */
    public function listLabels(int $limit=100,string $after=''): array
    {
        $result=[];foreach($this->store->entries('appstate-labels',$limit,$after) as $bytes){$record=BinaryData::decode($bytes);if(is_array($record))$result[]=$record;}
        return $result;
    }
    /** @return list<array<string,mixed>> */
    public function listBroadcastLists(int $limit=100,string $after=''): array
    {
        $result=[];foreach($this->store->entries('appstate-broadcast-lists',$limit,$after) as $bytes){$record=BinaryData::decode($bytes);if(is_array($record))$result[]=$record;}
        return $result;
    }
    /** Reads all authenticated action records, including stores created before dedicated projections.
     * Pagination is by the action's ID, never by the hashed storage key.
     * @return list<array<string,mixed>> */
    public function listQuickReplies(int $limit=100,string $after=''): array
    {
        if($limit<1 || $limit>1000)throw new \InvalidArgumentException('Quick reply limit must be between 1 and 1000');
        $cursor='';$result=[];
        do {
            $page=$this->store->entries('appstate-values',1000,$cursor);
            foreach($page as $key=>$bytes) {
                $cursor=(string)$key;$mutation=BinaryData::decode($bytes);
                if(!is_array($mutation) || ($mutation['operation']??'')==='remove')continue;
                $event=AppStateSchema::parse($mutation);
                if(($event['schema']??'')!=='QuickReply' || ($event['deleted']??false) || !is_string($event['id']??null) || strcmp($event['id'],$after)<=0)continue;
                $result[$event['id']]=$event;
                if(count($result)>$limit){ksort($result,SORT_STRING);array_pop($result);}
            }
        }while(count($page)===1000);
        ksort($result,SORT_STRING);
        return array_values($result);
    }
    /** @param array<string,mixed> $event
     * @param array<string,mixed> $changes */
    private function messageFlag(string $chat,array $event,array $changes): void
    {
        $participant=$event['participant']??null;
        $message=$this->history->getMessage($chat,$event['id'],$participant==='0'?null:$participant);
        if($message!==null && ($message['fromMe']??false)!==($event['fromMe']??false))return;
        if($message===null)$message=['chat'=>$chat,'id'=>$event['id'],'fromMe'=>$event['fromMe']??false,'timestamp'=>0,'appstatePlaceholder'=>true];
        if(is_string($participant) && $participant!=='0')$message['participant']=$participant;
        $this->history->upsertMessage([...$message,...$changes]);
    }
    /** @param array<string,mixed> $event */
    private function deleteMessage(string $chat,array $event): void
    {
        $participant=$event['participant']??null;$participant=$participant==='0'?null:$participant;
        $message=$this->history->getMessage($chat,$event['id'],$participant);
        if($message!==null && ($message['fromMe']??false)===($event['fromMe']??false))$this->history->deleteMessage($chat,$event['id'],$participant);
    }
    /** @param array<string,mixed> $event */
    private function clearRange(string $chat,array $event): void
    {
        $range=$event['messageRange']??[];$explicit=[];
        foreach($range['messages']??[] as $message)if(is_array($message['key']??null))$explicit[]=$message['key'];
        $last=$range['lastMessageTimestamp']??$range['lastSystemMessageTimestamp']??null;
        $after='';$namespace='history-messages:'.$chat;
        do {
            $messages=$this->store->entries($namespace,1000,$after);
            foreach($messages as $key=>$bytes) {
                $after=(string)$key;$message=BinaryData::decode($bytes);if(!is_array($message))throw new \UnexpectedValueException('Invalid history during app-state deletion');
                $timestamp=$message['timestamp']??0;
                if($event['schema']==='ClearChat' && ($message['starred']??false) && !($event['deleteStarred']??false))continue;
                $matched=is_int($last) && is_int($timestamp) && $timestamp<=$last;
                if(!$matched)foreach($explicit as $messageKey)if(($messageKey['id']??null)===$message['id'] && ($messageKey['fromMe']??false)===($message['fromMe']??false) && (!isset($messageKey['participant']) || Jid::recipient($messageKey['participant'])->canonical()->bare()===($message['participant']??''))){$matched=true;break;}
                if($matched)$this->store->delete($namespace,(string)$key);
            }
        }while(count($messages)===1000);
        $latest=$this->history->listMessages($chat,1)[0]??null;
        $this->history->upsertChat(['jid'=>$chat,'lastMessageTimestamp'=>$latest['timestamp']??0,'lastMessageId'=>$latest['id']??null]);
    }
    private function removeLabelAssociations(string $id): void
    {
        $after='';
        do {
            $page=$this->history->listChats(1000,$after);
            foreach($page as $chat) {
                $after=$chat['jid'];
                if(!is_array($chat['labelIds']??null) || !in_array($id,$chat['labelIds'],true))continue;
                $this->history->upsertChat(['jid'=>$chat['jid'],'labelIds'=>array_values(array_filter($chat['labelIds'],static fn($label)=>$label!==$id))]);
            }
        }while(count($page)===1000);
        foreach($this->history->iterateMessages() as $message) {
            if(!is_array($message['labelIds']??null) || !in_array($id,$message['labelIds'],true))continue;
            $this->history->upsertMessage([...$message,'labelIds'=>array_values(array_filter($message['labelIds'],static fn($label)=>$label!==$id))]);
        }
    }
    /** @param array<string,mixed> $event */
    private function contact(array $event,bool $removed): void
    {
        $jid=$event['lidJid']??$event['id']??null;if(!is_string($jid))return;
        $jid=Jid::recipient($jid)->canonical()->bare();
        if(($event['schema']??null)==='LidContact')$jid=IdentityService::lid($jid);
        if($removed){$contact=$this->history->getContact($jid);if($contact!==null)$this->history->upsertContact(['jid'=>$jid,'addressBookName'=>null,'displayName'=>null]);return;}
        $name=$event['fullName']??$event['firstName']??null;
        $record=['jid'=>$jid,...$event,'lastUpdatedMs'=>$event['timestamp']??0];
        if(is_string($name) && $name!==''){$record['displayName']=$name;$record['addressBookName']=$name;}
        $id=$event['id']??null;if(is_string($id) && str_ends_with($id,'@s.whatsapp.net')){$record['phoneNumber']=$id;if(str_ends_with($jid,'@lid'))(new IdentityService($this->store))->remember($id,$jid,true);}
        $this->history->upsertContact($record);
    }
    /** @return array<string,mixed>|null */
    private function read(string $namespace,string $key): ?array
    {
        $bytes=$this->store->get($namespace,$key);if($bytes===null)return null;
        $record=BinaryData::decode($bytes);if(!is_array($record))throw new \UnexpectedValueException('Invalid projected app-state value');return $record;
    }
}
