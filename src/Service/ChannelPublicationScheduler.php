<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\Message\ContentBuilder;
use MVO\Protocol\BinaryData;
use MVO\Signal\SignalStore;

/** Durable local publication queue. This does not activate WhatsApp's native scheduling. */
final class ChannelPublicationScheduler
{
    private const RECORDS='channel-publication-schedules';
    private const DUE='channel-publication-schedule-due';
    private const ARCHIVE='channel-publication-schedule-tombstones';
    /**
     * @var array<string,true> */
    private static array $inFlight=[];
    /**
     * @param \Closure(string,array<string,mixed>,array{id:string}):array<string,mixed> $publish
     *
     * @param \Closure():int|null $clock
     *
     * @param \Closure():void|null $authorizationGuard Throws if the owning application revoked access. */
    public function __construct(private readonly SignalStore $store,private readonly \Closure $publish,private readonly ?\Closure $clock=null,private readonly ?\Closure $authorizationGuard=null) {}

    /** Validate before HTTP enqueue, without querying WhatsApp or uploading media.
     *
     * @param array<string,mixed> $content */
    public static function validate(string $jid,array $content,int $sendAt,?int $now=null):void
    {
        NewsletterInput::jid($jid);self::time($sendAt,$now??time());
        $type=$content['type']??null;
        $allowed=match($type){
            'text'=>['type','text'],
            'poll'=>['type','name','options','selectableCount','allowAddOption','hideParticipantName','endTime'],
            default=>throw new \InvalidArgumentException('Local channel schedules support text or text-option polls'),
        };
        if(array_diff(array_keys($content),$allowed)!==[])throw new \InvalidArgumentException('Unknown scheduled publication field');
        if($type==='text')NewsletterInput::text($content['text']??null,'text',65536,false);
        else {
            NewsletterInput::text($content['name']??null,'poll name',65536,false);
            $options=$content['options']??null;
            if(!is_array($options)||!array_is_list($options)||count($options)<2||count($options)>12)throw new \InvalidArgumentException('Scheduled polls require 2..12 text options');
            foreach($options as$option)NewsletterInput::text($option,'poll option',1024,false);
            if(count(array_unique($options,SORT_STRING))!==count($options))throw new \InvalidArgumentException('Poll options must be unique');
            if(isset($content['endTime'])&&(!is_int($content['endTime'])||$content['endTime']<=$sendAt))throw new \InvalidArgumentException('Poll endTime must be after its scheduled publication');
        }
        ContentBuilder::build($content,$jid);
    }

    /**
     * @param array<string,mixed> $content
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function schedule(string $jid,array $content,int $sendAt,?string $id=null,array $options=[]):array
    {
        $now=$this->now();$id??=bin2hex(random_bytes(16));self::id($id);self::validate($jid,$content,$sendAt,$this->get($jid,$id)===null?$now:min($now,$sendAt-1));$policy=PublicationPolicy::normalize($options,$sendAt);$fingerprint=PublicationPolicy::fingerprint(['channelJid'=>$jid,'content'=>$content,'sendAt'=>$sendAt]+$policy);
        return $this->store->transaction(function()use($jid,$content,$sendAt,$id,$now,$policy,$fingerprint):array{
            if(($old=$this->get($jid,$id))!==null){
                if(isset($old['requestFingerprint'])?!hash_equals($old['requestFingerprint'],$fingerprint):($old['content']!==$content||$old['sendAt']!==$sendAt))throw new \InvalidArgumentException('Schedule ID already exists with different content or time');
                return $old;
            }
            if(count(array_filter($this->all($jid),static fn(array $row):bool=>!PublicationPolicy::terminal($row['state'])))>=1000)throw new \LengthException('Active channel schedule limit reached');
            $record=['id'=>$id,'channelJid'=>$jid,'engine'=>'mvo_local','state'=>'queued','sendAt'=>$sendAt,
                'revision'=>1,'requestFingerprint'=>$fingerprint,...$policy,'content'=>$content,'messageId'=>'3EB0'.strtoupper(bin2hex(random_bytes(9))),
                'createdAt'=>$now,'updatedAt'=>$now,'attempts'=>0,'result'=>null,'failure'=>null,'sentAt'=>null];
            $this->write($record);$this->store->set(self::DUE,self::dueKey($record),self::recordKey($jid,$id));return $record;
        });
    }

    /**
     * @return array<string,mixed>|null */
    public function get(string $jid,string $id):?array
    {
        NewsletterInput::jid($jid);self::id($id);$bytes=$this->store->get(self::RECORDS,self::recordKey($jid,$id))??$this->store->get(self::ARCHIVE,self::recordKey($jid,$id));
        if($bytes===null)return null;$row=BinaryData::decode($bytes);
        if(!is_array($row)||($row['id']??null)!==$id||($row['channelJid']??null)!==$jid)throw new \UnexpectedValueException('Invalid persisted channel schedule');
        return $row;
    }

    /** Ascending schedule ID; use the last ID as $after. All states are retained.
     *
     * @return list<array<string,mixed>> */
    public function list(string $jid,int $limit=50,string $after=''):array
    {
        NewsletterInput::jid($jid);if($limit<1||$limit>1000)throw new \InvalidArgumentException('Schedule limit must be 1..1000');if($after!=='')self::id($after);
        $rows=[];$prefix=$jid.':';$cursor=$after===''?$prefix:self::recordKey($jid,$after);
        foreach($this->store->entries(self::RECORDS,$limit,$cursor)as$key=>$bytes){
            if(!str_starts_with((string)$key,$prefix))break;
            $row=$this->get($jid,substr((string)$key,strlen($prefix)));if($row!==null)$rows[]=$row;
        }
        return $rows;
    }

    /** Only publications which have never begun can be canceled or moved.
     *
     * @return array<string,mixed> */
    public function cancel(string $jid,string $id):array
    {
        return $this->store->transaction(function()use($jid,$id):array{
            $row=$this->get($jid,$id)??throw new \OutOfBoundsException('Channel schedule not found');
            if($row['state']==='canceled')return $row;
            self::queued($row);$this->store->delete(self::DUE,self::dueKey($row));
            $row['state']='canceled';$row['updatedAt']=$this->now();$row['revision']=($row['revision']??1)+1;$this->write($row);return $row;
        });
    }

    /**
     * @return array<string,mixed> */
    public function reschedule(string $jid,string $id,int $sendAt,?int $expectedRevision=null):array
    {
        return $this->store->transaction(function()use($jid,$id,$sendAt,$expectedRevision):array{
            $row=$this->get($jid,$id)??throw new \OutOfBoundsException('Channel schedule not found');self::queued($row);self::revision($row,$expectedRevision);PublicationPolicy::normalize(array_intersect_key($row,array_flip(['misfirePolicy','maxLatenessSeconds','expiresAt'])),$sendAt);
            self::validate($jid,$row['content'],$sendAt,$this->now());$this->store->delete(self::DUE,self::dueKey($row));
            $row['sendAt']=$sendAt;$row['updatedAt']=$this->now();$row['revision']=($row['revision']??1)+1;$this->write($row);
            $this->store->set(self::DUE,self::dueKey($row),self::recordKey($jid,$id));return $row;
        });
    }

    /**
     * @param array<string,mixed> $patch
     * @return array<string,mixed> */
    public function edit(string $jid,string $id,array $patch,int $expectedRevision):array
    {
        if((array_key_exists('content',$patch)&&!is_array($patch['content']))||(isset($patch['sendAt'])&&!is_int($patch['sendAt']))||$patch===[]||array_diff(array_keys($patch),['content','sendAt','misfirePolicy','maxLatenessSeconds','expiresAt'])!==[])throw new \InvalidArgumentException('Invalid channel schedule patch');
        return $this->store->transaction(function()use($jid,$id,$patch,$expectedRevision):array{
            $row=$this->get($jid,$id)??throw new \OutOfBoundsException('Channel schedule not found');self::queued($row);self::revision($row,$expectedRevision);
            $content=$patch['content']??$row['content'];$when=$patch['sendAt']??$row['sendAt'];if(!is_array($content))throw new \InvalidArgumentException('Content must be an object');self::validate($jid,$content,$when,$this->now());
            $policy=PublicationPolicy::normalize(array_replace(array_intersect_key($row,array_flip(['misfirePolicy','maxLatenessSeconds','expiresAt'])),array_intersect_key($patch,array_flip(['misfirePolicy','maxLatenessSeconds','expiresAt']))),$when);
            $this->store->delete(self::DUE,self::dueKey($row));$row=array_replace($row,$policy,['content'=>$content,'sendAt'=>$when,'revision'=>($row['revision']??1)+1,'updatedAt'=>$this->now()]);$this->write($row);$this->store->set(self::DUE,self::dueKey($row),self::recordKey($jid,$id));return $row;
        });
    }
    /** Remove terminal payloads and retain immutable idempotency tombstones.
     * @return array<string,mixed> */
    public function archiveTerminal(string $jid,int $before,int $limit=100):array
    {
        NewsletterInput::jid($jid);if($before<1||$before>$this->now()||$limit<1||$limit>1000)throw new \InvalidArgumentException('Invalid archive cutoff or limit');
        return $this->store->transaction(function()use($jid,$before,$limit):array{$ids=[];foreach($this->all($jid)as$row){if(!PublicationPolicy::terminal($row['state'])||$row['updatedAt']>$before)continue;
            $fingerprint=$row['requestFingerprint']??PublicationPolicy::fingerprint(['channelJid'=>$jid,'content'=>$row['content'],'sendAt'=>$row['sendAt']]+PublicationPolicy::normalize(array_intersect_key($row,array_flip(['misfirePolicy','maxLatenessSeconds','expiresAt'])),$row['sendAt']));
            $tombstone=array_intersect_key($row,array_flip(['id','channelJid','engine','state','sendAt','messageId','revision','createdAt','updatedAt','sentAt','attempts','failure','misfirePolicy','maxLatenessSeconds','expiresAt']));$tombstone+=['requestFingerprint'=>$fingerprint,'archived'=>true,'archivedAt'=>$this->now(),'content'=>null,'result'=>null];$key=self::recordKey($jid,$row['id']);
            $this->store->set(self::ARCHIVE,$key,BinaryData::encode($tombstone));$this->store->delete(self::RECORDS,$key);$this->store->delete(self::DUE,self::dueKey($row));$ids[]=$row['id'];if(count($ids)>=$limit)break;
        }return ['channelJid'=>$jid,'archivedIds'=>$ids,'archivedCount'=>count($ids),'before'=>$before,'idempotencyRetained'=>true];});
    }
    /**
     * @return list<array<string,mixed>> */
    private function all(string $jid):array{$rows=[];$after='';do{$page=$this->list($jid,1000,$after);foreach($page as$row)$rows[]=$row;if($page!==[])$after=$page[array_key_last($page)]['id'];}while(count($page)===1000);return $rows;}
    /**
     * @param array<string,mixed> $row */
    private static function revision(array $row,?int $expected):void{if($expected!==null&&($expected<1||($row['revision']??1)!==$expected))throw new PublicationRevisionConflict('Schedule revision no longer matches');}

    /** Called by the connected client. A crash/uncertain ACK never triggers an automatic resend.
     *
     * @return list<array<string,mixed>> */
    public function drain(int $limit=10):array
    {
        if($limit<1||$limit>100)throw new \InvalidArgumentException('Schedule drain limit must be 1..100');$results=[];$now=$this->now();
        ($this->authorizationGuard)?->__invoke();
        foreach($this->store->entries(self::DUE,$limit)as$due=>$key){
            if((int)substr((string)$due,0,12)>$now)break;
            $bytes=$this->store->get(self::RECORDS,$key);
            if($bytes===null){$this->store->delete(self::DUE,(string)$due);continue;}
            $row=BinaryData::decode($bytes);
            if(!is_array($row)||!is_string($row['channelJid']??null)||!is_string($row['id']??null))throw new \UnexpectedValueException('Invalid schedule due reference');
            $flight=spl_object_id($this->store).':'.$key;
            if(isset(self::$inFlight[$flight]))continue;
            if($row['state']==='publishing'){
                $row['state']='unknown';$row['failure']=['code'=>'publication_interrupted','message'=>'Publication began before recovery; verify the preserved message ID before sending again.'];
                $row['updatedAt']=$now;$this->write($row);$this->store->delete(self::DUE,(string)$due);$results[]=$row;continue;
            }
            if($row['state']!=='queued'){$this->store->delete(self::DUE,(string)$due);continue;}
            self::$inFlight[$flight]=true;
            try{
                ($this->authorizationGuard)?->__invoke();
                $claimed=$this->store->transaction(function()use($row,$due):?array{
                    $fresh=$this->get($row['channelJid'],$row['id']);$claimNow=$this->now();
                    // Another HTTP process may have moved or canceled this item after
                    // the due-index snapshot. Only claim the exact item still due now.
                    if($fresh===null||$fresh['state']!=='queued'||self::dueKey($fresh)!==(string)$due||$fresh['sendAt']>$claimNow)return null;
                    $reason=PublicationPolicy::skipReason($fresh,$claimNow);if($reason!==null){$fresh['state']='skipped';$fresh['failure']=['code'=>$reason,'message'=>'Publication skipped by local expiration or lateness policy.','outcome'=>'not_executed'];$fresh['updatedAt']=$claimNow;$this->write($fresh);$this->store->delete(self::DUE,(string)$due);return $fresh;}$fresh['state']='publishing';$fresh['updatedAt']=$claimNow;$fresh['attempts']++;$this->write($fresh);return $fresh;
                });
                if($claimed===null)continue;$row=$claimed;if($row['state']==='skipped'){$results[]=$row;continue;}
                // The claim is durable, but the publisher has not run yet. A denied guard
                // at this point is proof that this publication can safely remain queued.
                try{($this->authorizationGuard)?->__invoke();}
                catch(\Throwable $error){$this->restoreUnexecuted($row);throw $error;}
                try{
                    $result=($this->publish)($row['channelJid'],$row['content'],['id'=>$row['messageId']]);
                    $row['result']=array_intersect_key($result,array_flip(['id','status','serverId','timestampSeconds','responseServerId']));
                    $confirmed=($result['status']??null)==='server_ack'&&($result['id']??null)===$row['messageId'];
                    $row['state']=$confirmed?'server_ack':'unknown';
                    if($confirmed)$row['sentAt']=$this->now();
                    else $row['failure']=['code'=>'publication_unconfirmed','message'=>'The publication has no matching server acknowledgement.'];
                }catch(ScheduledPublicationNotExecuted $error){
                    $this->restoreUnexecuted($row);throw $error;
                }catch(\Throwable $error){
                    $rejected=$error instanceof ServiceException&&$error->serverCode!==null;
                    $row['state']=$rejected?'failed':'unknown';
                    $row['failure']=['code'=>$rejected?'publication_rejected':'publication_unconfirmed',
                        'message'=>$rejected?'The server rejected this publication.':'Publication outcome is uncertain; verify the preserved message ID before sending again.'];
                    if($rejected)$row['failure']['serverCode']=$error->serverCode;
                }
                $row['updatedAt']=$this->now();$this->write($row);$this->store->delete(self::DUE,(string)$due);$results[]=$row;
            }finally{unset(self::$inFlight[$flight]);}
        }
        return $results;
    }

    /**
     * @return array<string,mixed> */
    public static function capabilities():array
    {return ['engine'=>'mvo_local','nativeCreationSupported'=>false,'contentTypes'=>['text','poll'],'requiresConnectedWorker'=>true,'editable'=>true,'retentionArchive'=>true,'revisionRequiredForEdit'=>true,'misfirePolicies'=>['send_late','skip_if_late'],'maxActiveSchedules'=>1000];}

    private function now():int {return $this->clock===null?time():($this->clock)();}
    /**
     * @param array<string,mixed> $row */
    private function restoreUnexecuted(array $row):void
    {
        $this->store->transaction(function()use($row):void{
            $fresh=$this->get($row['channelJid'],$row['id']);
            if($fresh===null||$fresh['state']!=='publishing'||$fresh['messageId']!==$row['messageId'])return;
            $fresh['state']='queued';$fresh['attempts']=max(0,$fresh['attempts']-1);$fresh['updatedAt']=$this->now();$this->write($fresh);
        });
    }
    private static function time(int $sendAt,int $now):void {if($sendAt<=$now||$sendAt>$now+2592000)throw new \InvalidArgumentException('sendAt must be Unix seconds in the future, within 30 days');}
    private static function id(string $id):void {if(!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$id))throw new \InvalidArgumentException('Invalid channel schedule ID');}
    /**
     * @param array<string,mixed> $row */
    private static function queued(array $row):void {if(($row['state']??null)!=='queued')throw new \LogicException('Only queued channel publications can be changed');}
    private static function recordKey(string $jid,string $id):string {return $jid.':'.$id;}
    /**
     * @param array<string,mixed> $row */
    private static function dueKey(array $row):string {return sprintf('%012d',$row['sendAt']).':'.self::recordKey($row['channelJid'],$row['id']);}
    /**
     * @param array<string,mixed> $row */
    private function write(array $row):void {$this->store->set(self::RECORDS,self::recordKey($row['channelJid'],$row['id']),BinaryData::encode($row));}
}
