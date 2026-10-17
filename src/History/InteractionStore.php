<?php
declare(strict_types=1);
namespace MVO\History;

use MVO\Message\ContentDecoder;
use MVO\Protocol\{BinaryData,Jid};
use MVO\Signal\SignalStore;

/** Latest authenticated, decrypted votes and RSVPs observed by this session. */
final class InteractionStore
{
    public function __construct(private readonly SignalStore $store,private readonly HistoryStore $history) {}

    /** Call only after Signal/addon authentication, using the transport's authenticatedSender.
     * @param array<string,mixed> $event */
    public function ingest(array $event):void
    {
        $kind=$event['type']??null;
        if(!in_array($kind,['poll-vote','event-response','poll-add-option','poll-edit','event-edit'],true)||isset($event['decryptError'])||($event['encrypted']??false)===true)return;
        if(!is_array($event['data']??null)||!is_array($event['target']??null)||!is_string($event['authenticatedSender']??null))return;
        $key=$event['target'];$chat=$event['chat']??null;$id=$key['id']??null;
        if(!is_string($chat)||!is_string($id)||!is_string($event['id']??null))return;
        try{
            $sender=$this->canonicalUser(self::user($event['authenticatedSender']));$chat=self::chat($chat);
            if(!$this->targetMatches($event,$chat,$key))return;
            $participant=isset($key['participant'])?self::user($key['participant']):null;
            [$parent,$body]=$this->parent($chat,$id,$participant,str_starts_with($kind,'poll-')?'poll':'event');
        }catch(\InvalidArgumentException|\OutOfBoundsException|\LogicException){return;}
        $timestamp=$kind==='event-response'?($event['data']['timestampMs']??null):null;
        if(in_array($kind,['poll-edit','event-edit'],true))$timestamp=$event['data']['protocolMessage']['timestampMs']??null;
        if($timestamp===null&&is_array($event['proto']??null))$timestamp=ContentDecoder::unwrap($event['proto'])['message']['pollUpdateMessage']['senderTimestampMs']??null;
        if($timestamp===null&&is_int($event['timestamp']??null)&&$event['timestamp']>=0&&$event['timestamp']<=intdiv(PHP_INT_MAX,1000))$timestamp=$event['timestamp']*1000;
        if(is_string($timestamp)&&ctype_digit($timestamp)&&strlen($timestamp)<=16)$timestamp=(int)$timestamp;
        if(!is_int($timestamp)||$timestamp<0)return;
        $record=['jid'=>$sender,'timestampMs'=>$timestamp,'messageId'=>$event['id']];
        if(in_array($kind,['poll-edit','poll-add-option','event-edit'],true)){$this->modify($event,$parent,$body,$record);return;}
        if($kind==='poll-vote'){
            $selected=$event['data']['selectedOptions']??null;
            if(!is_array($selected)||!array_is_list($selected)||count($selected)>100)return;
            $hashes=[];foreach($selected as$hash){if(!is_string($hash)||strlen($hash)!==32)return;$hashes[]=bin2hex($hash);}
            $record['selectedOptionHashes']=array_values(array_unique($hashes));
        }else{
            $response=match($event['data']['response']??null){1,'GOING','going'=>'going',2,'NOT_GOING','not_going'=>'not_going',3,'MAYBE','maybe'=>'maybe',default=>null};
            $guests=$event['data']['extraGuestCount']??0;
            if($response===null||!is_int($guests)||$guests<0||$guests>100000)return;
            if($guests>0&&($body['extraGuestsAllowed']??false)!==true)return;
            $record+=['response'=>$response,'extraGuestCount'=>$guests];
        }
        $namespace=$this->namespace($parent,$kind);
        $this->store->transaction(function()use($namespace,$sender,$record):void{
            foreach($this->aliases($sender)as$alias){$old=$this->read($namespace,$alias);if($old!==null&&($old['timestampMs']??0)>=$record['timestampMs'])return;}
            $this->store->set($namespace,$sender,BinaryData::encode($record));
        });
    }

    /** Results are a local observation, not an authoritative remote tally.
     * @return array<string,mixed> */
    public function pollResults(string $chat,string $id,?string $participant=null):array
    {
        [$parent,$body]=$this->parent($chat,$id,$participant,'poll');$anonymous=($body['hideParticipantName']??false)===true;
        $options=[];
        foreach($body['options']??[]as$option){
            if(!is_array($option)||!is_string($option['optionName']??null))continue;
            try{$hash=bin2hex(\MVO\Message\PollOptionHash::vote($option));}catch(\InvalidArgumentException){continue;}
            $name=$option['optionName'];$options[$hash]=['name'=>$name,'hash'=>$hash,'votes'=>0]+($anonymous?[]:['voters'=>[]]);
        }
        $voters=[];$totalSelections=0;$unresolved=0;
        foreach($this->latest($parent,'poll-vote')as$row){
            $selected=$row['selectedOptionHashes']??[];if($selected===[])continue;
            $voters[]=['jid'=>$row['jid'],'selectedOptionHashes'=>$selected,'timestampMs'=>$row['timestampMs']];
            foreach($selected as$hash){$totalSelections++;if(!isset($options[$hash])){$unresolved++;continue;}$options[$hash]['votes']++;if(!$anonymous)$options[$hash]['voters'][]=$row['jid'];}
        }
        return ['chat'=>$parent['chat'],'id'=>$id,'name'=>$body['name']??null,'anonymous'=>$anonymous,'totalVoters'=>count($voters),'totalSelections'=>$totalSelections,'unresolvedSelections'=>$unresolved,'options'=>array_values($options)]+($anonymous?[]:['voters'=>$voters])+['source'=>'observed_authenticated_messages'];
    }

    /** @return array<string,mixed> */
    public function eventResponses(string $chat,string $id,?string $participant=null):array
    {
        [$parent,$body]=$this->parent($chat,$id,$participant,'event');$counts=['going'=>0,'not_going'=>0,'maybe'=>0];$responses=[];
        foreach($this->latest($parent,'event-response')as$row){$response=$row['response']??null;if(!is_string($response)||!isset($counts[$response]))continue;$counts[$response]++;$responses[]=['jid'=>$row['jid'],'response'=>$response,'extraGuestCount'=>$row['extraGuestCount'],'timestampMs'=>$row['timestampMs']];}
        return ['chat'=>$parent['chat'],'id'=>$id,'name'=>$body['name']??null,'counts'=>$counts,'totalResponses'=>count($responses),'responses'=>$responses,'source'=>'observed_authenticated_messages'];
    }

    /** Current authenticated parent fields for subsequent edits; the history protobuf remains intact.
     * @return array<string,mixed> */
    public function parentContent(string $chat,string $id,string $kind,?string $participant=null):array
    {
        if(!in_array($kind,['poll','event'],true))throw new \InvalidArgumentException('Parent content requires poll or event');
        return $this->parent($chat,$id,$participant,$kind)[1];
    }

    /** @return array{array<string,mixed>,array<string,mixed>} */
    private function parent(string $chat,string $id,?string $participant,string $kind):array
    {
        $chat=self::chat($chat);if($id===''||strlen($id)>256||preg_match('/[\x00-\x20]/',$id))throw new \InvalidArgumentException('Invalid interaction parent ID');
        $participant=$participant===null?null:self::user($participant);$rows=[];
        foreach($this->aliases($chat)as$address){
            if($participant!==null){foreach($this->aliases($participant)as$author){$row=$this->history->getMessage($address,$id,$author);if($row!==null)$rows[]=$row;}$row=$this->history->getMessage($address,$id);if($row!==null)$rows[]=$row;}
            else{$row=$this->history->getMessage($address,$id);if($row!==null)$rows[]=$row;}
        }
        foreach($rows as$row){
            if(($row['revoked']??false)===true)continue;
            $author=$row['participant']??$row['key']['participant']??$row['from']??null;
            if($participant!==null&&(!is_string($author)||!$this->sameAddress(self::user($author),$participant)))continue;
            $inner=ContentDecoder::unwrap(is_array($row['proto']??null)?$row['proto']:[])['message'];
            foreach($inner as$field=>$body)if(is_array($body)&&($kind==='poll'?str_starts_with($field,'pollCreationMessage'):$field==='eventMessage')){
                $parent=[...$row,'chat'=>self::chat($row['chat']),'interactionAuthor'=>is_string($author)?self::user($author):''];
                return [$parent,$this->project($parent,$body,$kind)];
            }
        }
        throw new \OutOfBoundsException('The original '.$kind.' was not observed in this session and conversation');
    }

    /** @param array<string,mixed> $parent
     * @return list<array<string,mixed>> */
    private function latest(array $parent,string $kind):array
    {
        $latest=[];
        foreach($this->records($parent,$kind)as$row){if(!is_string($row['jid']??null))continue;$sender=$this->canonicalUser(self::user($row['jid']));$row['jid']=$sender;if(!isset($latest[$sender])||($row['timestampMs']??0)>($latest[$sender]['timestampMs']??0))$latest[$sender]=$row;}
        ksort($latest);return array_values($latest);
    }
    /** @param array<string,mixed> $parent
     * @return list<array<string,mixed>> */
    private function records(array $parent,string $kind):array
    {
        $records=[];$authors=$parent['interactionAuthor']===''?['']:$this->aliases($parent['interactionAuthor']);
        foreach($this->aliases($parent['chat'])as$chat)foreach($authors as$author){
            $namespace=$this->namespace([...$parent,'chat'=>$chat,'interactionAuthor'=>$author],$kind);$after='';
            do{$page=$this->store->entries($namespace,1000,$after);foreach($page as$key=>$bytes){$after=(string)$key;$row=BinaryData::decode($bytes);if(is_array($row))$records[]=$row;}}while(count($page)===1000);
        }
        return $records;
    }

    /** Sender-perspective keys may name our authenticated local recipient in direct incoming messages.
     * @param array<string,mixed> $event
     * @param array<string,mixed> $key */
    private function targetMatches(array $event,string $chat,array $key):bool
    {
        if(!isset($key['remoteJid']))return true;
        if(!is_string($key['remoteJid']))return false;$target=self::chat($key['remoteJid']);
        if($this->sameAddress($chat,$target))return true;
        if(($event['fromMe']??null)!==false||str_ends_with($chat,'@g.us'))return false;
        foreach(['authenticatedRecipientJid','authenticatedRecipientAlt']as$field)if(is_string($event[$field]??null)&&$this->sameAddress(self::user($event[$field]),$target))return true;
        foreach(['recipientJid','recipientAlt']as$field)if(is_string($event['key'][$field]??null)&&$this->sameAddress(self::user($event['key'][$field]),$target))return true;
        return false;
    }

    /** Store mutable projections separately, retaining the original history protobuf for audit.
     * @param array<string,mixed> $event
     * @param array<string,mixed> $parent
     * @param array<string,mixed> $body
     * @param array<string,mixed> $record */
    private function modify(array $event,array $parent,array $body,array $record):void
    {
        $kind=$event['type'];$key='state';
        if($kind==='poll-add-option'){
            if(($body['allowAddOption']??false)!==true)return;
            $add=$event['data']['pollAddOptionMessage']??[];$target=$add['pollCreationMessageKey']??null;
            if(is_array($target)&&((isset($target['id'])&&$target['id']!==$parent['id'])||!$this->targetMatches($event,$parent['chat'],$target)))return;
            $option=$add['addOption']??null;
            if(!is_array($option)||!self::validText($option['optionName']??null,1024))return;
            $name=$option['optionName'];$hash=hash('sha256',$name);
            foreach($body['options']??[]as$old)if(($old['optionName']??null)===$name||(isset($option['optionHash'])&&($old['optionHash']??null)===$option['optionHash']))return;
            if(count($body['options']??[])>=100)return;
            $record['option']=['optionName'=>$name];if(isset($option['optionHash'])&&self::validText($option['optionHash'],1024))$record['option']['optionHash']=$option['optionHash'];$key=$hash;
        }else{
            if($parent['interactionAuthor']===''||!$this->sameAddress($parent['interactionAuthor'],$record['jid']))return;
            $protocol=$event['data']['protocolMessage']??null;
            if(!is_array($protocol)||!in_array($protocol['type']??null,[14,'MESSAGE_EDIT'],true)||!is_array($protocol['editedMessage']??null))return;
            if(isset($protocol['key']['id'])&&$protocol['key']['id']!==$parent['id'])return;
            if(isset($protocol['key'])&&(!is_array($protocol['key'])||!$this->targetMatches($event,$parent['chat'],$protocol['key'])))return;
            $edit=$protocol['editedMessage'];$change=null;
            if($kind==='poll-edit'){
                foreach($edit as$field=>$value)if(str_starts_with($field,'pollCreationMessage')&&is_array($value)&&self::validText($value['name']??null,1024)){$change=['name'=>$value['name']];break;}
            }elseif(isset($edit['eventMessage'])&&is_array($edit['eventMessage'])){
                $candidate=$edit['eventMessage'];
                $allowed=['name','description','startTime','endTime','joinLink','extraGuestsAllowed','isScheduleCall','isCanceled','hasReminder','reminderOffsetSec','location'];
                $change=array_intersect_key($candidate,array_flip($allowed));
                if(!self::validText($change['name']??null,1024))return;
                foreach(['startTime','endTime','reminderOffsetSec']as$field)if(isset($change[$field])&&(!is_int($change[$field])||$change[$field]<0))return;
                foreach(['extraGuestsAllowed','isScheduleCall','isCanceled','hasReminder']as$field)if(isset($change[$field])&&!is_bool($change[$field]))return;
                if(isset($change['endTime'],$change['startTime'])&&$change['endTime']<$change['startTime'])return;
            }
            if($change===null)return;$record['change']=$change;
        }
        $this->store->transaction(function()use($parent,$kind,$record,$key):void{
            foreach($this->records($parent,$kind)as$old){if($kind==='poll-add-option'&&($old['option']['optionName']??null)!==($record['option']['optionName']??null))continue;if(($old['timestampMs']??0)>=$record['timestampMs'])return;}
            $this->store->set($this->namespace($parent,$kind),$key,BinaryData::encode($record));
        });
    }

    /** @param array<string,mixed> $parent
     * @param array<string,mixed> $body
     * @return array<string,mixed> */
    private function project(array $parent,array $body,string $kind):array
    {
        $latest=null;foreach($this->records($parent,$kind.'-edit')as$row)if($latest===null||($row['timestampMs']??0)>($latest['timestampMs']??0))$latest=$row;
        if(is_array($latest['change']??null))$body=array_replace($body,$latest['change']);
        if($kind==='poll'){
            $added=$this->records($parent,'poll-add-option');usort($added,static fn(array $a,array $b):int=>($a['timestampMs']<=>$b['timestampMs'])?:strcmp($a['option']['optionName'],$b['option']['optionName']));
            $names=array_column($body['options']??[],'optionName');
            foreach($added as$row){$option=$row['option']??null;if(is_array($option)&&is_string($option['optionName']??null)&&!in_array($option['optionName'],$names,true)){$body['options'][]=$option;$names[]=$option['optionName'];}}
        }
        return $body;
    }
    private static function validText(mixed $value,int $limit):bool {return is_string($value)&&trim($value)!==''&&strlen($value)<=$limit&&preg_match('//u',$value)===1;}
    /** @param array<string,mixed> $parent */
    private function namespace(array $parent,string $kind):string {return 'interaction-'.$kind.':'.hash('sha256',$parent['chat']."\0".$parent['id']."\0".$parent['interactionAuthor']);}
    /** @return array<string,mixed>|null */
    private function read(string $namespace,string $key):?array {$bytes=$this->store->get($namespace,$key);if($bytes===null)return null;$value=BinaryData::decode($bytes);if(!is_array($value))throw new \UnexpectedValueException('Invalid interaction record');return $value;}
    /** @return list<string> */
    private function aliases(string $jid):array
    {
        $aliases=[$jid];foreach(['message-pn-to-lid','message-lid-to-pn']as$namespace)if(($alias=$this->store->get($namespace,$jid))!==null){try{$aliases[]=self::user($alias);}catch(\InvalidArgumentException){}}return array_values(array_unique($aliases));
    }
    private function canonicalUser(string $jid):string {foreach($this->aliases($jid)as$alias)if(str_ends_with($alias,'@lid'))return $alias;return $jid;}
    private function sameAddress(string $a,string $b):bool {return array_intersect($this->aliases($a),$this->aliases($b))!==[];}
    private static function user(mixed $input):string {if(!is_string($input))throw new \InvalidArgumentException('Invalid interaction author');$jid=Jid::recipient($input)->canonical();if(!in_array($jid->server,['s.whatsapp.net','lid'],true)||!ctype_digit($jid->user))throw new \InvalidArgumentException('Interaction author must be a PN/LID account');return $jid->bare();}
    private static function chat(string $input):string {$jid=Jid::recipient($input)->canonical();if(!in_array($jid->server,['s.whatsapp.net','lid','g.us'],true)||$jid->device!==0)throw new \InvalidArgumentException('Invalid interaction conversation');return $jid->bare();}
}
