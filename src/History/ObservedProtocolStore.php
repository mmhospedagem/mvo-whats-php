<?php
declare(strict_types=1);
namespace MVO\History;

use MVO\Message\{ContentDecoder,ModernEvents};
use MVO\Protocol\{BinaryData,Jid};
use MVO\Signal\SignalStore;

/** Observed records only. No publication, scheduling or remote recovery is inferred. */
final class ObservedProtocolStore
{
    public function __construct(private readonly SignalStore $store){}
    /** Called in the authenticated history/inbox transaction.
     * @param array<string,mixed> $event */
    public function recordMessage(array $event):void
    {
        if(!is_string($event['id']??null)||!is_string($event['chat']??null)||!is_array($event['proto']??null))return;
        $from=$event['authenticatedSender']??$event['from']??$event['participant']??null;
        if(!is_string($from))return;
        try{$author=Jid::parse($from)->canonical()->bare();$chat=Jid::parse($event['chat'])->canonical()->bare();}catch(\InvalidArgumentException){return;}
        $normalized=ModernEvents::normalize($event['proto']);
        if(is_array($normalized['payment']??null))$this->recordPayment($event,$normalized['payment'],$author,$chat);
        if(is_array($normalized['statusInteraction']??null)){
            $interaction=$normalized['statusInteraction'];$target=ProtocolValues::key($interaction['originalMessageKey']??null);
            if($target!==null){
                $id=hash('sha256',$chat."\0".$author."\0".$event['id']."\0".($interaction['type']??''));
                $row=['id'=>$id,'messageId'=>$event['id'],'chat'=>$chat,'respondent'=>$author,'fromMe'=>($event['fromMe']??false)===true,'timestamp'=>$event['timestamp']??0,'observedAt'=>time(),'originalMessageKey'=>$target,'source'=>'authenticated_message'];
                foreach(['type','action','text','stickerKey']as$field)if(is_string($interaction[$field]??null))$row[$field]=ProtocolValues::text($interaction[$field],$field==='text'?16384:256);
                $response=ProtocolValues::key($interaction['responseMessageKey']??null);if($response!==null)$row['responseMessageKey']=$response;
                $this->write('observed-status-interactions',$id,$row,2000);
            }
        }
        $reminder=$normalized['botReminder']??null;
        if(!is_array($reminder)||($event['fromMe']??false)===true||(!str_ends_with($author,'@bot')&&$author!=='13135550002@s.whatsapp.net'))return;
        $request=ProtocolValues::key($reminder['requestMessageKey']??null);if($request===null)return;
        $id=hash('sha256',$author."\0".($request['remoteJid']??$chat)."\0".$request['id']);
        $old=$this->read('observed-bot-reminders',$id);$timestamp=is_int($event['timestamp']??null)?$event['timestamp']:0;
        if($old!==null&&($timestamp<($old['timestamp']??0)||($old['messageId']??null)===$event['id']))return;
        $row=['id'=>$id,'botJid'=>$author,'chat'=>$chat,'requestMessageKey'=>$request,'messageId'=>$event['id'],'timestamp'=>$timestamp,'observedAt'=>time(),'source'=>'authenticated_bot','nativeCreationSupported'=>false];
        foreach(['action','name','nextTriggerTimestamp','frequency']as$field)if(isset($reminder[$field]))$row[$field]=$reminder[$field];
        $row['state']=match($reminder['action']??null){'delete'=>'deleted','notify'=>'notified',default=>'active'};
        $this->write('observed-bot-reminders',$id,array_replace($old??[],$row),1000);
    }
    /**
     * @param array<string,mixed> $record */
    public function callLog(array $record):void
    {
        $callId=ProtocolValues::text($record['callId']??null,256);$creator=ProtocolValues::text($record['callCreatorJid']??null,256);
        if($callId===null||$callId==='')return;
        $id=hash('sha256',($creator??'')."\0".$callId);$row=['id'=>$id,'callId'=>$callId,'source'=>'authenticated_history','observedAt'=>time()];
        foreach(['duration','startTime','scheduledCallId','callResult','isIncoming','isVideo','isCallLink','callType','isDndMode','silenceReason']as$field)if(is_int($record[$field]??null)||is_bool($record[$field]??null)||is_string($record[$field]??null))$row[$field]=$record[$field];
        foreach(['callCreatorJid','groupJid']as$field)if(is_string($record[$field]??null)){try{$row[$field]=Jid::parse($record[$field])->canonical()->bare();}catch(\InvalidArgumentException){}}
        $row['participants']=[];foreach(array_slice(is_array($record['participants']??null)?$record['participants']:[],0,2048)as$p)if(is_array($p)&&is_string($p['userJid']??null)){try{$item=['userJid'=>Jid::parse($p['userJid'])->canonical()->bare()];}catch(\InvalidArgumentException){continue;}if(is_int($p['callResult']??null))$item['callResult']=$p['callResult'];$name=ProtocolValues::text($p['guestInfo']['pushName']??null,256);if($name!==null)$item['pushName']=$name;$row['participants'][]=$item;}
        $this->write('observed-call-logs',$id,$row,5000);
    }
    /** Store private descriptors separately from query DTOs.
     * @param array<string,mixed> $record */
    public function sticker(array $record,string $collection):void
    {
        if(!in_array($collection,['recent','favorite'],true))throw new \InvalidArgumentException('Unknown sticker collection');
        $hash=$record['fileSha256']??null;if(!is_string($hash)||strlen($hash)!==32)return;$id=bin2hex($hash);
        $old=$this->read('observed-stickers',$id);$collections=array_values(array_unique([...($old['collections']??[]),$collection]));
        $row=['id'=>$id,'sha256'=>$id,'collections'=>$collections,'source'=>'authenticated_history','observedAt'=>time()];
        foreach(['mimetype','height','width','weight','lastStickerSentTs','isLottie','isAvatarSticker','imageHash']as$field)if(is_int($record[$field]??null)||is_bool($record[$field]??null)||is_float($record[$field]??null)||is_string($record[$field]??null))$row[$field]=is_string($record[$field])?ProtocolValues::text($record[$field],256):$record[$field];
        $this->write('observed-stickers',$id,$row,2000);
    }
    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>> */
    public function list(string $kind,array $filters=[]):array
    {
        $namespace=match($kind){'calls'=>'observed-call-logs','stickers'=>'observed-stickers','status-interactions'=>'observed-status-interactions','bot-reminders'=>'observed-bot-reminders','payments'=>'observed-payments',default=>throw new \InvalidArgumentException('Unknown observed collection')};
        $limit=$filters['limit']??100;$after=$filters['after']??'';ProtocolValues::page($limit,$after);$result=[];
        do{$page=$this->store->entries($namespace,1000,$after);foreach($page as$key=>$bytes){$after=(string)$key;$row=BinaryData::decode($bytes);if(!is_array($row))continue;
            foreach(['chat','respondent','botJid','state','type']as$field)if(isset($filters[$field])&&($row[$field]??null)!==$filters[$field])continue 2;
            if(isset($filters['messageId'])&&($row['originalMessageKey']['id']??null)!==$filters['messageId'])continue;
            if(isset($filters['author'])&&($row['originalMessageKey']['participant']??null)!==$filters['author'])continue;
            if(isset($filters['collection'])&&!in_array($filters['collection'],$row['collections']??[],true))continue;
            $result[]=$row;if(count($result)>=$limit)return $result;
        }}while(count($page)===1000);return $result;
    }
    /**
     * @return array<string,mixed>|null */
    public function getReminder(string $id):?array{HistorySyncStore::id($id);return $this->read('observed-bot-reminders',$id);}
    /** @return array<string,mixed>|null */
    public function getPayment(string $id):?array{HistorySyncStore::id($id);return $this->read('observed-payments',$id);}
    /** @param array<string,mixed> $event
     * @param array<string,mixed> $payment */
    private function recordPayment(array $event,array $payment,string $author,string $chat):void
    {
        $actor=Jid::parse($author);if(!in_array($actor->server,['s.whatsapp.net','lid'],true)||($payment['invalidReference']??false)===true)return;
        $type=$payment['type']??null;$timestamp=is_int($event['timestamp']??null)?$event['timestamp']:0;
        $base=['messageId'=>$event['id'],'chat'=>$chat,'author'=>$author,'timestamp'=>$timestamp,'observedAt'=>time(),'source'=>'authenticated_message','observedOnly'=>true,'paymentVerified'=>false];
        if($type==='split-payment-update'){
            if(!is_string($payment['splitId']??null)||!is_string($payment['participantJid']??null)||!$this->sameUser($author,$payment['participantJid']))return;
            $matches=[];foreach($this->paymentCandidates($chat,'split-payment','splitId',$payment['splitId'])as$row)foreach($row['participants']??[]as$p)if($this->sameUser($p['jid'],$payment['participantJid'])){$matches[]=$row;break;}
            if(count($matches)!==1)return;$row=$matches[0];
            if($timestamp<($row['lastUpdateTimestamp']??$row['timestamp']??0)||($row['lastUpdateMessageId']??null)===$event['id'])return;
            // This proto carries no paid flag or receipt. Never infer settlement from its existence.
            $row=array_replace($row,['lastUpdateMessageId'=>$event['id'],'lastUpdateAuthor'=>$author,'lastUpdateParticipant'=>$payment['participantJid'],'lastUpdateTimestamp'=>$timestamp,'updateCount'=>($row['updateCount']??0)+1,'observedAt'=>time()]);$this->write('observed-payments',$row['id'],$row,2000);return;
        }
        if($type==='split-payment'){
            if(!is_string($payment['splitId']??null)||(($payment['participantsIncomplete']??false)===true)||isset($payment['requesterJid'])&&!$this->sameUser($author,$payment['requesterJid']))return;
            $id=hash('sha256',$chat."\0split\0".$this->identity($author)."\0".$payment['splitId']);$old=$this->read('observed-payments',$id);
            if($old===null&&$this->hasAlias($author))foreach($this->paymentCandidates($chat,'split-payment','splitId',$payment['splitId'])as$candidate)if($this->sameUser($candidate['author'],$author)){$old=$candidate;break;}
            // A split creation is immutable; later snapshots do not silently change participants/amounts.
            if($old!==null)return;$this->write('observed-payments',$id,['id'=>$id,...$payment,...$base],2000);return;
        }
        if($type==='payment-reminder'){
            if(!is_string($payment['reminderId']??null))return;
            $payee=$payment['payeeJid']??$author;$payer=$payment['payerJid']??null;
            if(!$this->sameUser($author,$payee)&&(!is_string($payer)||!$this->sameUser($author,$payer)))return;
            $id=hash('sha256',$chat."\0reminder\0".$this->identity($payee)."\0".$payment['reminderId']."\0".($payment['instanceId']??''));$old=$this->read('observed-payments',$id);
            if($old===null&&$this->hasAlias($payee))foreach($this->paymentCandidates($chat,'payment-reminder','reminderId',$payment['reminderId'])as$candidate)if(($candidate['instanceId']??'')===($payment['instanceId']??'')&&$this->sameUser($candidate['payeeJid']??$candidate['author'],$payee)){$old=$candidate;$id=$candidate['id'];break;}
            if($old===null&&!$this->sameUser($author,$payee))return;
            if($old!==null){if($timestamp<($old['timestamp']??0)||($old['messageId']??null)===$event['id'])return;if(!$this->sameUser($author,$old['payeeJid']??$old['author'])&&(!isset($old['payerJid'])||!$this->sameUser($author,$old['payerJid'])))return;foreach(['payeeJid','payerJid']as$field)if(isset($old[$field],$payment[$field])&&!$this->sameUser($old[$field],$payment[$field]))return;}
            if(in_array($payment['reportedStatus']??null,['active','cancelled_by_creator'],true)&&!$this->sameUser($author,$payee))return;
            if(($payment['reportedStatus']??null)==='stopped_by_receiver'&&(!is_string($payer)||!$this->sameUser($author,$payer)))return;
            $this->write('observed-payments',$id,array_replace($old??[],['id'=>$id,...$payment,...$base]),2000);
        }
    }
    private function sameUser(string $a,string $b):bool
    {
        $aliases=fn(string $id):array=>array_values(array_filter([$id,$this->store->get('message-pn-to-lid',$id),$this->store->get('message-lid-to-pn',$id)],'is_string'));
        return array_intersect($aliases($a),$aliases($b))!==[];
    }
    private function identity(string $id):string{return $this->store->get('message-pn-to-lid',$id)??$id;}
    private function hasAlias(string $id):bool{return $this->store->get('message-pn-to-lid',$id)!==null||$this->store->get('message-lid-to-pn',$id)!==null;}
    /** @return list<array<string,mixed>> */
    private function paymentCandidates(string $chat,string $type,string $field,string $value):array
    {
        $result=[];$after='';do{$page=$this->list('payments',['chat'=>$chat,'type'=>$type,'limit'=>1000,'after'=>$after]);foreach($page as$row){$after=$row['id'];if(($row[$field]??null)===$value)$result[]=$row;}}while(count($page)===1000);return $result;
    }
    /**
     * @return array<string,mixed>|null */
    private function read(string $namespace,string $id):?array{$bytes=$this->store->get($namespace,$id);if($bytes===null)return null;$row=BinaryData::decode($bytes);return is_array($row)?$row:null;}
    /**
     * @param array<string,mixed> $row */
    private function write(string $namespace,string $id,array $row,int $maximum):void
    {
        $existing=$this->store->get($namespace,$id)!==null;$counter=$this->store->get('observed-record-counts',$namespace);$count=$counter===null?0:(int)$counter;
        $this->store->set($namespace,$id,BinaryData::encode($row));if(!$existing)++$count;$this->store->set('observed-record-counts',$namespace,(string)$count);
        if($count<=$maximum)return;
        $after='';$oldest=null;$time=PHP_INT_MAX;
        do{$page=$this->store->entries($namespace,1000,$after);foreach($page as$key=>$bytes){$after=(string)$key;$r=BinaryData::decode($bytes);if(is_array($r)&&($r['observedAt']??0)<$time){$time=$r['observedAt']??0;$oldest=(string)$key;}}}while(count($page)===1000);
        if($oldest!==null){$this->store->delete($namespace,$oldest);$this->store->set('observed-record-counts',$namespace,(string)($count-1));}
    }
}
