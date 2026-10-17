<?php
declare(strict_types=1);
namespace MVO\History;

use MVO\Message\{ContentDecoder,MessageCodec,ModernEvents};
use MVO\Protocol\{BinaryData,Jid,SchemaCodec};
use MVO\Signal\SignalStore;

/** Durable authenticated observations. No synthetic native publication or GPS lifecycle. */
final class MessageObservationStore
{
    public function __construct(private readonly SignalStore $store,private readonly HistoryStore $history){}
    /**
     * @param array<string,mixed> $event */
    public function ingest(array $event):void
    {
        if(isset($event['decryptError'])||($event['encrypted']??false)===true)return;
        $actor=$this->actor($event);$chat=$this->jid($event['chat']??null);$id=ProtocolValues::text($event['id']??null,256);
        if($actor===null||$chat===null||$id===null||$id==='')return;
        $proto=ContentDecoder::unwrap(is_array($event['proto']??null)?$event['proto']:[])['message'];
        $timestamp=$event['timestamp']??null;if(!is_int($timestamp)||$timestamp<0||$timestamp>intdiv(PHP_INT_MAX,1000))return;
        $base=['messageId'=>$id,'chat'=>$chat,'author'=>$actor,'timestamp'=>$timestamp,'observedAt'=>time(),'source'=>'authenticated_message'];
        $reaction=$proto['reactionMessage']??(($event['type']??null)==='reaction'?($event['data']??null):null);
        if(is_array($reaction))$this->reaction($event,$reaction,$base);
        $protocol=$proto['protocolMessage']??(($event['type']??null)==='edit'?($event['data']['protocolMessage']??null):null);
        if(is_array($protocol)&&in_array($protocol['type']??null,[0,14,'REVOKE','MESSAGE_EDIT'],true))$this->revision($event,$protocol,$base);
        $comment=$proto['commentMessage']??(($event['type']??null)==='comment'?($event['data']??null):null);
        if(is_array($comment)){
            $key=ProtocolValues::key($comment['targetMessageKey']??$event['target']??null);$parent=$key===null?null:$this->parent($chat,$key,$event);
            if($key!==null&&$parent===null)$this->defer($event,$key,$base);
            if($parent!==null&&$key['id']!==$id&&is_array($comment['message']??null)){
                $body=MessageCodec::decode(SchemaCodec::encode('Message',$comment['message']));
                $this->write('comments',$this->eventId($base),$base+['id'=>$this->eventId($base),'targetMessageId'=>$key['id'],'text'=>ProtocolValues::text($body['text']??null,16384),'contentType'=>$body['contentType']??'unknown','nativeSendSupported'=>false]);
            }
        }
        $location=$proto['liveLocationMessage']??null;
        if(is_array($location)&&is_numeric($location['degreesLatitude']??null)&&is_numeric($location['degreesLongitude']??null)){
            $lat=(float)$location['degreesLatitude'];$lon=(float)$location['degreesLongitude'];
            if(is_finite($lat)&&is_finite($lon)&&abs($lat)<=90&&abs($lon)<=180){
                $row=$base+['id'=>$this->eventId($base),'latitude'=>$lat,'longitude'=>$lon,'nativeLifecycleSupported'=>false];
                foreach(['accuracyInMeters','speedInMps','degreesClockwiseFromMagneticNorth','sequenceNumber','timeOffset']as$f)if((is_int($location[$f]??null)||is_float($location[$f]??null))&&is_finite((float)$location[$f])&&$location[$f]>=0)$row[$f]=$location[$f];
                $this->write('live-locations',$row['id'],$row);
            }
        }
        $context=ContentDecoder::unwrap(is_array($event['proto']??null)?$event['proto']:[])['context'];
        foreach($proto as$body)if(is_array($body)&&is_array($body['contextInfo']??null))$context=array_replace($context,$body['contextInfo']);
        $duration=$context['afterReadDuration']??$protocol['afterReadDuration']??null;
        if(is_int($duration)&&$duration>=0)$this->write('after-read',$this->eventId($base),$base+['id'=>$this->eventId($base),'reportedDuration'=>$duration,'nativeEnableSupported'=>false,'expiryKnown'=>false]);
        if(str_ends_with($chat,'@g.us')&&is_array($protocol['memberLabel']??null)&&in_array($protocol['type']??null,[30,'GROUP_MEMBER_LABEL_CHANGE'],true))$this->memberLabel($chat,$actor,$protocol['memberLabel'],$timestamp,'authenticated_message');
        if(($event['fromMe']??false)!==true&&(str_ends_with($actor,'@bot')||$actor==='13135550002@s.whatsapp.net')){
            $modern=ModernEvents::normalize(is_array($event['proto']??null)?$event['proto']:[]);$fields=array_intersect_key($modern,array_flip(['botProgress','botSuggestions','botMemory','botCapabilities']));
            if($fields!==[])$this->write('bot-observations',$this->eventId($base),$base+['id'=>$this->eventId($base),...$fields,'memoryWriteSupported'=>false]);
        }
        $this->replay($chat,$id);
    }
    /**
     * @param array<string,mixed> $event
     * @param array<string,mixed> $r
     * @param array<string,mixed> $base */
    private function reaction(array $event,array $r,array $base):void
    {
        $key=ProtocolValues::key($r['key']??$event['target']??null);if($key===null||$key['id']===$base['messageId'])return;if($this->parent($base['chat'],$key,$event)===null){$this->defer($event,$key,$base);return;}
        $text=ProtocolValues::text($r['text']??null,128);$ms=$r['senderTimestampMs']??$base['timestamp']*1000;
        if($text===null||($text!==''&&preg_match('/^\X$/u',$text)!==1)||!is_int($ms)||$ms<0||$ms>$base['timestamp']*1000+86400000)return;
        $id=hash('sha256',$base['chat']."\0".$key['id']."\0".$this->identity($base['author']));$old=$this->read('reactions',$id);
        if($old!==null&&([$old['timestampMs'],$old['messageId']]>=[$ms,$base['messageId']]))return;
        $row=$base+['id'=>$id,'targetMessageId'=>$key['id'],'emoji'=>$text,'removed'=>$text==='','timestampMs'=>$ms];$this->write('reactions',$id,$row);$this->write($this->bucket('reactions',$base['chat'],$key['id']),$id,$row);
    }
    /**
     * @param array<string,mixed> $event
     * @param array<string,mixed> $p
     * @param array<string,mixed> $base */
    private function revision(array $event,array $p,array $base):void
    {
        $key=ProtocolValues::key($p['key']??$event['target']??null);$parent=$key===null?null:$this->parent($base['chat'],$key,$event);
        if($key===null||$key['id']===$base['messageId'])return;if($parent===null){$this->defer($event,$key,$base);return;}if(!$this->same($this->actor($parent),$base['author']))return;
        $ms=$p['timestampMs']??$base['timestamp']*1000;if(!is_int($ms)||$ms<$base['timestamp']*1000-86400000||$ms<($parent['timestamp']??0)*1000||$ms>$base['timestamp']*1000+86400000)return;
        $edit=in_array($p['type']??null,[14,'MESSAGE_EDIT'],true);if($edit&&!is_array($p['editedMessage']??null))return;
        $row=$base+['id'=>$this->eventId($base),'targetMessageId'=>$key['id'],'action'=>$edit?'edit':'revoke','timestampMs'=>$ms];
        if($edit){$decoded=MessageCodec::decode(SchemaCodec::encode('Message',$p['editedMessage']));if(in_array($decoded['kind']??null,['protocol','unsupported'],true))return;$row+=['text'=>ProtocolValues::text($decoded['text']??null,65536),'type'=>$decoded['kind'],'contentType'=>$decoded['contentType'],'proto'=>$p['editedMessage']];}
        if($this->read('revisions',$row['id'])!==null)return;
        $this->write('revisions',$row['id'],$row);$this->write($this->bucket('revisions',$row['chat'],$row['targetMessageId']),str_pad((string)$ms,20,'0',STR_PAD_LEFT).'_'.$row['id'],$row);
    }
    /**
     * @param array<string,mixed> $record */
    public function pastParticipants(array $record):void
    {
        $group=$this->jid($record['groupJid']??null);if($group===null||!str_ends_with($group,'@g.us'))return;
        foreach(array_slice(is_array($record['pastParticipants']??null)?$record['pastParticipants']:[],0,10000)as$p){if(!is_array($p))continue;$jid=$this->jid($p['userJid']??null);$ts=$p['leaveTs']??null;$reason=match($p['leaveReason']??null){0,'LEFT'=>'left',1,'REMOVED'=>'removed',default=>null};if($jid===null||!in_array(Jid::parse($jid)->server,['s.whatsapp.net','lid'],true)||!is_int($ts)||$ts<0||$reason===null)continue;$id=hash('sha256',$group."\0".$jid."\0".$ts."\0".$reason);$this->write('past-participants',$id,['id'=>$id,'chat'=>$group,'participant'=>$jid,'reason'=>$reason,'leftAt'=>$ts,'source'=>'authenticated_history','observedAt'=>time(),'currentMembershipKnown'=>false]);}
    }
    /**
     * @param array<string,mixed> $label */
    public function memberLabel(string $group,string $participant,array $label,int $timestamp=0,string $source='authenticated_history'):void
    {
        $group=$this->jid($group);$participant=$this->jid($participant);$text=ProtocolValues::text($label['label']??null,256);$ts=$label['labelTimestamp']??$timestamp;
        if($group===null||!str_ends_with($group,'@g.us')||$participant===null||!in_array(Jid::parse($participant)->server,['s.whatsapp.net','lid'],true)||$text===null||!is_int($ts)||$ts<0)return;
        $id=hash('sha256',$group."\0".$this->identity($participant));$old=$this->read('member-labels',$id);if($old!==null&&($old['labelTimestamp']??0)>=$ts)return;
        $this->write('member-labels',$id,['id'=>$id,'chat'=>$group,'participant'=>$participant,'label'=>$text,'labelTimestamp'=>$ts,'source'=>$source,'observedAt'=>time(),'nativeSetSupported'=>false]);
    }
    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>> */
    public function list(string $kind,array $filters=[]):array
    {
        self::kind($kind);$limit=$filters['limit']??100;$after=$filters['after']??'';ProtocolValues::page($limit,$after);$out=[];
        foreach($this->rows($kind)as$key=>$row){if(strcmp($key,$after)<=0)continue;foreach(['chat','author','participant','targetMessageId','messageId']as$f)if(isset($filters[$f])&&($row[$f]??null)!==$filters[$f])continue 2;$row=array_diff_key($row,['proto'=>true]);$out[]=$row;if(count($out)>=$limit)break;}return $out;
    }
    /**
     * @return array<string,mixed> */
    public function reactions(string $chat,string $messageId,int $limit=100,string $after=''):array
    {
        $chat=$this->jid($chat)??throw new \InvalidArgumentException('Invalid chat');if($this->history->getMessage($chat,$messageId)===null)throw new \OutOfBoundsException('Original message not observed');ProtocolValues::page($limit,$after);$items=[];$counts=[];$total=0;
        foreach($this->latestReactions($chat,$messageId)as$key=>$row)if(!$row['removed']){$emoji=$row['emoji'];$counts[$emoji]=($counts[$emoji]??0)+1;++$total;if(strcmp($key,$after)>0&&count($items)<$limit)$items[]=$row;}
        ksort($counts);$summary=[];foreach($counts as$emoji=>$count)$summary[]=['emoji'=>(string)$emoji,'count'=>$count];return ['chat'=>$chat,'messageId'=>$messageId,'totalReactions'=>$total,'counts'=>$summary,'items'=>$items,'nextCursor'=>$items===[]?null:$items[array_key_last($items)]['id'],'source'=>'observed_authenticated_messages'];
    }
    /** Returns only authenticated ordered edits/revocations and the original immutable content.
     *
     * @return array<string,mixed> */
    public function revisions(string $chat,string $messageId,int $limit=100,string $after=''):array
    {
        ProtocolValues::page($limit,$after);$parent=$this->history->getMessage($chat,$messageId)??throw new \OutOfBoundsException('Original message not observed');$rows=[];$next=null;
        foreach($this->rows($this->bucket('revisions',$parent['chat'],$messageId))as$key=>$row){if(strcmp($key,$after)<=0)continue;$rows[]=array_diff_key($row,['proto'=>true])+['cursor'=>$key];$next=$key;if(count($rows)>=$limit)break;}
        $original=$this->history->getStoredMessage($parent['chat'],$messageId);
        return ['chat'=>$parent['chat'],'messageId'=>$messageId,'originalText'=>$original['text']??null,'currentText'=>$parent['text']??null,'revoked'=>($parent['revoked']??false)===true,'totalRevisions'=>$parent['revisionCount']??0,'items'=>$rows,'nextCursor'=>$next,'source'=>'observed_authenticated_messages'];
    }
    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed> */
    public function project(array $record):array
    {
        $original=$record;$count=0;$terminal=false;
        foreach($this->rows($this->bucket('revisions',$record['chat'],$record['id']))as$row){
            if(!$this->same($row['author'],$this->actor($original)))continue;++$count;
            if($terminal)continue;
            if($row['action']==='revoke'){$terminal=true;$record['revoked']=true;$record['revokedAtMs']=$row['timestampMs'];$record['text']=null;$record['proto']=[];$record['data']=[];$record['type']='revoked';unset($record['media']);}
            else{$record['text']=$row['text'];$record['type']=$row['type'];$record['proto']=$row['proto'];$record['data']=ContentDecoder::unwrap($row['proto'])['message'];$record['editedAtMs']=$row['timestampMs'];unset($record['media']);}
        }
        if($count>0){
            unset($record['webMessageInfo']);
            foreach(['richResponse','parsedFlowResponse','parsedFormResponse','music','audioSticker','carousel','channelInvite','quote','instantImage','poll','event','album','association','statusContent','media']as$field)unset($record[$field]);
            if(!$terminal){$decoded=MessageCodec::decode(SchemaCodec::encode('Message',$record['proto']));$record['data']=$decoded['data'];$record['context']=$decoded['context'];foreach(['richResponse','parsedFlowResponse','parsedFormResponse','music','audioSticker','carousel','channelInvite','quote','instantImage','poll','event','album','association','statusContent','media']as$field)if(isset($decoded[$field]))$record[$field]=$decoded[$field];}
            $record['revisionCount']=$count;
        }
        return $record;
    }
    /** Preserve authenticated out-of-order additions until their original is imported.
     * @param array<string,mixed> $event
     * @param array<string,mixed> $key
     * @param array<string,mixed> $base */
    private function defer(array $event,array $key,array $base):void
    {
        if($key['id']===$base['messageId']||!$this->targetMatches($event,$base['chat'],$key)||$this->history->getStoredMessage($base['chat'],$key['id'])!==null)return;
        $id=$this->eventId($base);$old=$this->store->get('message-observations-pending',$id);
        if($old===null&&count($this->store->entries('message-observations-pending',1000))>=1000)return;
        if($old===null){$bytes=BinaryData::encode($event);if(strlen($bytes)>65536)return;$namespace='message-observation-pending:'.hash('sha256',$base['chat']."\0".$key['id']);$this->store->set($namespace,$id,$bytes);$this->store->set('message-observations-pending',$id,$namespace);}
    }
    private function replay(string $chat,string $id):void
    {
        $namespace='message-observation-pending:'.hash('sha256',$chat."\0".$id);
        foreach($this->store->entries($namespace,1000)as$key=>$bytes){$event=BinaryData::decode($bytes);$this->store->delete($namespace,(string)$key);$this->store->delete('message-observations-pending',(string)$key);if(is_array($event))$this->ingest($event);}
    }
    /**
     * @param array<string,mixed> $key
     * @param array<string,mixed> $event
     * @return array<string,mixed>|null */
    private function parent(string $chat,array $key,array $event=[]):?array
    {
        if(!$this->targetMatches($event,$chat,$key))return null;$parent=$this->history->getMessage($chat,$key['id']);if($parent===null)return null;if(isset($key['participant'])&&!$this->same($key['participant'],$this->actor($parent)))return null;return $parent;
    }
    /** A direct incoming key is expressed from the authenticated sender's perspective.
     * Only transport-confirmed local recipients may stand in for the peer chat.
     * @param array<string,mixed> $event
     * @param array<string,mixed> $key */
    private function targetMatches(array $event,string $chat,array $key):bool
    {
        if(!isset($key['remoteJid'])||$this->same($key['remoteJid'],$chat))return true;
        if(($event['fromMe']??null)!==false||!in_array(Jid::parse($chat)->server,['s.whatsapp.net','lid'],true))return false;
        foreach(['authenticatedRecipientJid','authenticatedRecipientAlt']as$field){$recipient=$this->jid($event[$field]??null);if($recipient!==null&&$this->same($recipient,$key['remoteJid']))return true;}
        foreach(['recipientJid','recipientAlt']as$field){$recipient=$this->jid($event['key'][$field]??null);if($recipient!==null&&$this->same($recipient,$key['remoteJid']))return true;}
        return false;
    }
    /**
     * @param array<string,mixed> $event */
    private function actor(array $event):?string{$jid=$this->jid($event['authenticatedSender']??$event['participant']??$event['from']??null);return $jid!==null&&in_array(Jid::parse($jid)->server,['s.whatsapp.net','lid','bot'],true)?$jid:null;}
    private function jid(mixed $v):?string{if(!is_string($v))return null;try{return Jid::parse($v)->canonical()->bare();}catch(\InvalidArgumentException){return null;}}
    private function identity(string $jid):string{return $this->store->get('message-pn-to-lid',$jid)??$jid;}
    private function same(?string $a,?string $b):bool{return $a!==null&&$b!==null&&$this->identity($a)===$this->identity($b);}
    /**
     * @param array<string,mixed> $base */
    private function eventId(array $base):string{return hash('sha256',$base['chat']."\0".$base['author']."\0".$base['messageId']);}
    private function bucket(string $kind,string $chat,string $id):string{return $kind.':'.hash('sha256',$chat."\0".$id);}
    /** @return array<string,array<string,mixed>> */
    private function latestReactions(string $chat,string $id):array
    {
        $latest=[];foreach($this->rows($this->bucket('reactions',$chat,$id))as$row){$actor=$this->identity($row['author']);if(!isset($latest[$actor])||[$row['timestampMs'],$row['messageId']]>[$latest[$actor]['timestampMs'],$latest[$actor]['messageId']])$latest[$actor]=$row;}
        $out=[];foreach($latest as$row)$out[$row['id']]=$row;ksort($out);return $out;
    }
    private static function kind(string $kind):void{if(!in_array($kind,['reactions','revisions','comments','live-locations','after-read','bot-observations','past-participants','member-labels'],true))throw new \InvalidArgumentException('Unknown message observation');}
    /**
     * @param array<string,mixed> $row */
    private function write(string $kind,string $id,array $row):void{$this->store->set('message-observations:'.$kind,$id,BinaryData::encode($row));}
    /**
     * @return array<string,mixed>|null */
    private function read(string $kind,string $id):?array{$v=$this->store->get('message-observations:'.$kind,$id);return $v===null?null:BinaryData::decode($v);}
    /**
     * @return \Generator<string,array<string,mixed>> */
    private function rows(string $kind):\Generator{$after='';do{$page=$this->store->entries('message-observations:'.$kind,1000,$after);foreach($page as$key=>$bytes){$after=(string)$key;$row=BinaryData::decode($bytes);if(is_array($row))yield $after=>$row;}}while(count($page)===1000);}
}
