<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\Media\MediaSource;
use MVO\Message\ContentBuilder;
use MVO\Protocol\{BinaryData,Jid};
use MVO\Signal\SignalStore;

/** Durable PHP scheduling. An uncertain publication always retains its ID and is never resent automatically. */
final class MessagePublicationScheduler
{
    private const RECORDS='message-publication-schedules';
    private const DUE='message-publication-schedule-due';
    private const ASSETS='message-publication-schedule-assets';
    private const ARCHIVE='message-publication-schedule-tombstones';
    private const MAX_MEDIA=33554432;
    private const MAX_QUEUED_MEDIA=134217728;
    /**
     * @var array<string,true> */
    private static array $inFlight=[];

    /**
     * @param \Closure(string,array<string,mixed>,array{id:string}):array<string,mixed> $publish
     *
     * @param \Closure():int|null $clock
     *
     * @param \Closure():void|null $authorizationGuard */
    public function __construct(private readonly SignalStore $store,private readonly \Closure $publish,private readonly ?\Closure $clock=null,private readonly ?\Closure $authorizationGuard=null) {}

    /**
     * @return array<string,mixed> */
    public static function capabilities():array
    {
        return ['engine'=>'mvo_local','nativeCreationSupported'=>false,'contentTypes'=>['text','poll','image','video','audio','ptt','document','sticker'],
            'recipientTypes'=>['individual','group'],'requiresConnectedWorker'=>true,'snapshotsMedia'=>true,
            'editable'=>true,'retentionArchive'=>true,'revisionRequiredForEdit'=>true,'misfirePolicies'=>['send_late','skip_if_late'],'maxActiveSchedules'=>1000,
            'maxMediaBytes'=>self::MAX_MEDIA,'maxQueuedMediaBytes'=>self::MAX_QUEUED_MEDIA,'maxSchedules'=>1000,'maxDelaySeconds'=>2592000];
    }

    /** Does not fetch URLs, upload media or contact WhatsApp.
     *
     * @param array<string,mixed> $content */
    public static function validate(string $to,array $content,int $sendAt,?int $now=null):void
    {
        self::recipient($to);self::time($sendAt,$now??time());$type=$content['type']??null;
        $common=['type'];
        $allowed=match($type){
            'text'=>[...$common,'text'],
            'poll'=>[...$common,'name','options','selectableCount','allowAddOption','hideParticipantName','endTime'],
            'image'=>[...$common,'media','caption','mimetype','width','height','fileName','accessibilityLabel'],
            'video'=>[...$common,'media','caption','mimetype','width','height','seconds','fileName','accessibilityLabel'],
            'audio','ptt'=>[...$common,'media','mimetype','seconds','fileName'],
            'document'=>[...$common,'media','caption','mimetype','fileName','title','pageCount'],
            'sticker'=>[...$common,'media','mimetype','fileName','width','height','isAnimated','accessibilityLabel'],
            default=>throw new \InvalidArgumentException('Unsupported scheduled message type'),
        };
        if(array_diff(array_keys($content),$allowed)!==[])throw new \InvalidArgumentException('Unknown scheduled message field');
        foreach(['text','name','caption','title','mimetype','fileName','accessibilityLabel']as$field)if(isset($content[$field])){
            if(!is_string($content[$field])||preg_match('//u',$content[$field])!==1||strlen($content[$field])>65536||str_contains($content[$field],"\0"))throw new \InvalidArgumentException('Invalid scheduled message '.$field);
        }
        foreach(['width','height','seconds','pageCount']as$field)if(isset($content[$field])&&(!is_int($content[$field])||$content[$field]<0||$content[$field]>0xffffffff))throw new \InvalidArgumentException('Invalid scheduled media '.$field);
        if(isset($content['isAnimated'])&&!is_bool($content['isAnimated']))throw new \InvalidArgumentException('isAnimated must be boolean');
        if(isset($content['mimetype'])&&(!is_string($content['mimetype'])||strlen($content['mimetype'])>128||preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~iD',$content['mimetype'])!==1))throw new \InvalidArgumentException('Invalid scheduled media MIME');
        if(isset($content['mimetype'])){
            $mime=strtolower($content['mimetype']);
            $major=match($type){'image'=>'image','video'=>'video','audio','ptt'=>'audio',default=>null};
            if(($major!==null&&!str_starts_with($mime,$major.'/'))||($type==='sticker'&&$mime!=='image/webp'))throw new \InvalidArgumentException('Scheduled media MIME does not match its content type');
        }
        if($type==='text'){
            if(!is_string($content['text']??null)||trim($content['text'])==='')throw new \InvalidArgumentException('Scheduled text is required');
            ContentBuilder::build($content,self::recipient($to));
        }elseif($type==='poll'){
            if(isset($content['endTime'])&&(!is_int($content['endTime'])||$content['endTime']<=$sendAt))throw new \InvalidArgumentException('Poll endTime must follow its scheduled publication');
            ContentBuilder::build($content,self::recipient($to));
        }elseif(!array_key_exists('media',$content))throw new \InvalidArgumentException('Scheduled media source is required');
    }

    /** Local media is copied into the session store now; temporary HTTP files may be removed afterwards.
     *
     * @param array<string,mixed> $content
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function schedule(string $to,array $content,int $sendAt,?string $id=null,array $options=[]):array
    {
        $to=self::recipient($to);$id??=bin2hex(random_bytes(16));self::id($id);self::validate($to,$content,$sendAt,$this->get($id)===null?$this->now():min($this->now(),$sendAt-1));
        $policy=PublicationPolicy::normalize($options,$sendAt);$bytes=null;$asset=null;
        if(array_key_exists('media',$content)){
            $bytes=MediaSource::read($content['media'],self::MAX_MEDIA);if($bytes==='')throw new \InvalidArgumentException('Scheduled media is empty');
            $name=$content['fileName']??MediaSource::fileName($content['media']);
            if($name!==null)$content['fileName']=$name;
            unset($content['media']);$asset=['size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'fileName'=>$name];
        }
        $fingerprint=PublicationPolicy::fingerprint(['chatJid'=>$to,'content'=>$content,'sendAt'=>$sendAt,'media'=>$asset]+$policy);
        return $this->store->transaction(function()use($to,$content,$sendAt,$id,$bytes,$asset,$policy,$fingerprint):array{
            $old=$this->get($id);
            if($old!==null){if(isset($old['requestFingerprint'])?!hash_equals($old['requestFingerprint'],$fingerprint):($old['chatJid']!==$to||$old['content']!==$content||$old['sendAt']!==$sendAt||$old['media']!==$asset))throw new MessageScheduleConflict('Schedule ID already has different content, recipient or time');return $old;}
            $rows=$this->all();$active=array_filter($rows,static fn(array $row):bool=>!PublicationPolicy::terminal($row['state']));if(count($active)>=1000)throw new \LengthException('Active message schedule limit reached');
            $held=0;foreach($rows as$row)if(in_array($row['state'],['queued','publishing','unknown'],true))$held+=(int)($row['media']['size']??0);
            if($held+($asset['size']??0)>self::MAX_QUEUED_MEDIA)throw new \LengthException('Queued scheduled media exceeds its session quota');
            $now=$this->now();$record=['id'=>$id,'chatJid'=>$to,'engine'=>'mvo_local','state'=>'queued','sendAt'=>$sendAt,'content'=>$content,'media'=>$asset,
                'revision'=>1,'requestFingerprint'=>$fingerprint,...$policy,
                'messageId'=>'3EB0'.strtoupper(bin2hex(random_bytes(9))),'createdAt'=>$now,'updatedAt'=>$now,'attempts'=>0,'sentAt'=>null,'result'=>null,'failure'=>null];
            if($bytes!==null)$this->store->set(self::ASSETS,$id,$bytes);
            $this->write($record);$this->store->set(self::DUE,self::dueKey($record),$id);return $record;
        });
    }

    /**
     * @return array<string,mixed>|null */
    public function get(string $id):?array
    {
        self::id($id);$bytes=$this->store->get(self::RECORDS,$id)??$this->store->get(self::ARCHIVE,$id);if($bytes===null)return null;$row=BinaryData::decode($bytes);
        if(!is_array($row)||($row['id']??null)!==$id||!is_string($row['chatJid']??null))throw new \UnexpectedValueException('Invalid persisted message schedule');return $row;
    }

    /** Cursor scans the session queue, including when filtering by chat.
     *
     * @return list<array<string,mixed>> */
    public function list(int $limit=50,string $after='',?string $chat=null):array
    {
        if($limit<1||$limit>1000)throw new \InvalidArgumentException('Schedule limit must be 1..1000');if($after!=='')self::id($after);if($chat!==null)$chat=self::recipient($chat);
        $rows=[];$cursor=$after;
        do{
            $page=$this->store->entries(self::RECORDS,min(1000,max(50,$limit)),$cursor);
            foreach($page as$id=>$_){$cursor=(string)$id;$row=$this->get((string)$id);if($row!==null&&($chat===null||$row['chatJid']===$chat))$rows[]=$row;if(count($rows)>=$limit)break;}
        }while(count($rows)<$limit&&count($page)>=min(1000,max(50,$limit)));
        return $rows;
    }

    /**
     * @return array<string,mixed> */
    public function cancel(string $id):array
    {
        return $this->store->transaction(function()use($id):array{
            $row=$this->get($id)??throw new \OutOfBoundsException('Message schedule not found');if($row['state']==='canceled')return $row;self::queued($row);
            $this->store->delete(self::DUE,self::dueKey($row));$this->store->delete(self::ASSETS,$id);$row['state']='canceled';$row['updatedAt']=$this->now();$row['revision']=($row['revision']??1)+1;$this->write($row);return $row;
        });
    }

    /**
     * @return array<string,mixed> */
    public function reschedule(string $id,int $sendAt,?int $expectedRevision=null):array
    {
        self::time($sendAt,$this->now());
        return $this->store->transaction(function()use($id,$sendAt,$expectedRevision):array{
            $row=$this->get($id)??throw new \OutOfBoundsException('Message schedule not found');self::queued($row);self::revision($row,$expectedRevision);
            PublicationPolicy::normalize(array_intersect_key($row,array_flip(['misfirePolicy','maxLatenessSeconds','expiresAt'])),$sendAt);
            if(isset($row['content']['endTime'])&&$row['content']['endTime']<=$sendAt)throw new \InvalidArgumentException('Poll endTime must follow its scheduled publication');
            $this->store->delete(self::DUE,self::dueKey($row));$row['sendAt']=$sendAt;$row['updatedAt']=$this->now();$row['revision']=($row['revision']??1)+1;$this->write($row);$this->store->set(self::DUE,self::dueKey($row),$id);return $row;
        });
    }

    /** Atomic replacement of queued content/time/policy. Media bytes are snapshotted before the CAS transaction.
     *
     * @param array<string,mixed> $patch
     * @return array<string,mixed> */
    public function edit(string $id,array $patch,int $expectedRevision):array
    {
        self::id($id);if((array_key_exists('content',$patch)&&!is_array($patch['content']))||(isset($patch['sendAt'])&&!is_int($patch['sendAt']))||$patch===[]||array_diff(array_keys($patch),['content','sendAt','misfirePolicy','maxLatenessSeconds','expiresAt'])!==[])throw new \InvalidArgumentException('Invalid schedule patch');
        $replacement=$patch['content']??null;$bytes=null;$asset=null;
        if($replacement!==null){
            if(!is_array($replacement))throw new \InvalidArgumentException('Replacement content must be an object');
            // Structural validation precedes any potentially expensive media read.
            $current=$this->get($id)??throw new \OutOfBoundsException('Message schedule not found');self::queued($current);self::revision($current,$expectedRevision);
            self::validate($current['chatJid'],$replacement,$patch['sendAt']??$current['sendAt'],$this->now());
            if(array_key_exists('media',$replacement)){$bytes=MediaSource::read($replacement['media'],self::MAX_MEDIA);if($bytes==='')throw new \InvalidArgumentException('Scheduled media is empty');$name=$replacement['fileName']??MediaSource::fileName($replacement['media']);if($name!==null)$replacement['fileName']=$name;unset($replacement['media']);$asset=['size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'fileName'=>$name];}
        }
        return $this->store->transaction(function()use($id,$patch,$expectedRevision,$replacement,$bytes,$asset):array{
            $row=$this->get($id)??throw new \OutOfBoundsException('Message schedule not found');self::queued($row);self::revision($row,$expectedRevision);
            $when=$patch['sendAt']??$row['sendAt'];self::time($when,$this->now());$policy=PublicationPolicy::normalize(array_replace(array_intersect_key($row,array_flip(['misfirePolicy','maxLatenessSeconds','expiresAt'])),array_intersect_key($patch,array_flip(['misfirePolicy','maxLatenessSeconds','expiresAt']))),$when);
            if($replacement!==null){$held=0;foreach($this->all()as$other)if($other['id']!==$id&&!PublicationPolicy::terminal($other['state']))$held+=(int)($other['media']['size']??0);if($held+($asset['size']??0)>self::MAX_QUEUED_MEDIA)throw new \LengthException('Queued media quota exceeded');$row['content']=$replacement;$row['media']=$asset;if($bytes===null)$this->store->delete(self::ASSETS,$id);else$this->store->set(self::ASSETS,$id,$bytes);}
            if(isset($row['content']['endTime'])&&$row['content']['endTime']<=$when)throw new \InvalidArgumentException('Poll endTime must follow publication');
            $this->store->delete(self::DUE,self::dueKey($row));$row=array_replace($row,$policy,['sendAt'=>$when,'revision'=>($row['revision']??1)+1,'updatedAt'=>$this->now()]);$this->write($row);$this->store->set(self::DUE,self::dueKey($row),$id);return $row;
        });
    }

    /** Terminal payloads are removed; compact identity tombstones remain forever to prevent duplicate IDs.
     * Unknown/publishing/queued records are never archived.
     * @return array<string,mixed> */
    public function archiveTerminal(int $before,int $limit=100):array
    {
        if($before<1||$before>$this->now()||$limit<1||$limit>1000)throw new \InvalidArgumentException('Invalid archive cutoff or limit');
        return $this->store->transaction(function()use($before,$limit):array{$ids=[];foreach($this->all()as$row){if(!PublicationPolicy::terminal($row['state'])||$row['updatedAt']>$before)continue;
            $fingerprint=$row['requestFingerprint']??PublicationPolicy::fingerprint(['chatJid'=>$row['chatJid'],'content'=>$row['content'],'sendAt'=>$row['sendAt'],'media'=>$row['media']]+PublicationPolicy::normalize(array_intersect_key($row,array_flip(['misfirePolicy','maxLatenessSeconds','expiresAt'])),$row['sendAt']));
            $tombstone=array_intersect_key($row,array_flip(['id','chatJid','engine','state','sendAt','messageId','revision','createdAt','updatedAt','sentAt','attempts','failure','misfirePolicy','maxLatenessSeconds','expiresAt']));$tombstone+=['requestFingerprint'=>$fingerprint,'archived'=>true,'archivedAt'=>$this->now(),'content'=>null,'media'=>null,'result'=>null];
            $this->store->set(self::ARCHIVE,$row['id'],BinaryData::encode($tombstone));$this->store->delete(self::RECORDS,$row['id']);$this->store->delete(self::DUE,self::dueKey($row));$this->store->delete(self::ASSETS,$row['id']);$ids[]=$row['id'];if(count($ids)>=$limit)break;
        }return ['archivedIds'=>$ids,'archivedCount'=>count($ids),'before'=>$before,'idempotencyRetained'=>true];});
    }
    /**
     * @return list<array<string,mixed>> */
    private function all():array{$rows=[];$after='';do{$page=$this->list(1000,$after);foreach($page as$row)$rows[]=$row;if($page!==[])$after=$page[array_key_last($page)]['id'];}while(count($page)===1000);return $rows;}
    /**
     * @param array<string,mixed> $row */
    private static function revision(array $row,?int $expected):void{if($expected!==null&&($expected<1||($row['revision']??1)!==$expected))throw new PublicationRevisionConflict('Schedule revision no longer matches');}

    /**
     * @return list<array<string,mixed>> */
    public function drain(int $limit=10):array
    {
        if($limit<1||$limit>100)throw new \InvalidArgumentException('Schedule drain limit must be 1..100');($this->authorizationGuard)?->__invoke();$results=[];
        foreach($this->store->entries(self::DUE,$limit)as$due=>$id){
            if((int)substr((string)$due,0,12)>$this->now())break;$row=$this->get($id);
            if($row===null){$this->store->delete(self::DUE,(string)$due);continue;}
            $flight=spl_object_id($this->store).':'.$id;if(isset(self::$inFlight[$flight]))continue;
            if($row['state']==='publishing'){$row['state']='unknown';$row['failure']=['code'=>'publication_interrupted','message'=>'Publication began before recovery; inspect the preserved message ID.'];$row['updatedAt']=$this->now();$this->write($row);$this->store->delete(self::DUE,(string)$due);$results[]=$row;continue;}
            if($row['state']!=='queued'){$this->store->delete(self::DUE,(string)$due);continue;}
            self::$inFlight[$flight]=true;
            try{
                ($this->authorizationGuard)?->__invoke();
                // A concurrent reschedule/cancel must win over the stale due-index snapshot.
                $row=$this->store->transaction(function()use($id,$due):?array{$fresh=$this->get($id);if($fresh===null||$fresh['state']!=='queued'||$fresh['sendAt']>$this->now()||self::dueKey($fresh)!==(string)$due)return null;$reason=PublicationPolicy::skipReason($fresh,$this->now());if($reason!==null){$fresh['state']='skipped';$fresh['failure']=['code'=>$reason,'message'=>'Publication was skipped by its local expiration or lateness policy.','outcome'=>'not_executed'];$fresh['updatedAt']=$this->now();$this->write($fresh);return $fresh;}$fresh['state']='publishing';$fresh['attempts']++;$fresh['updatedAt']=$this->now();$this->write($fresh);return $fresh;});
                if($row===null)continue;if($row['state']==='skipped'){$this->finish($row);$results[]=$row;continue;}
                // Verify only the claimed snapshot, so an asset failure cannot overwrite a concurrent cancellation.
                try{$content=$this->hydrate($row);}catch(\Throwable){$row['state']='failed';$row['failure']=['code'=>'scheduled_asset_unavailable','message'=>'The durable media snapshot is missing or invalid.'];$this->finish($row);$results[]=$row;continue;}
                try{($this->authorizationGuard)?->__invoke();}catch(\Throwable $error){$this->restore($row);throw $error;}
                try{
                    $result=($this->publish)($row['chatJid'],$content,['id'=>$row['messageId']]);
                    $row['result']=array_intersect_key($result,array_flip(['id','to','status','timestamp','serverCode']));
                    $confirmed=($result['id']??null)===$row['messageId']&&in_array($result['status']??null,['server_ack','delivered','read','played'],true);
                    $row['state']=$confirmed?'server_ack':(($result['status']??null)==='rejected'?'failed':'unknown');
                    if($confirmed)$row['sentAt']=$this->now();else$row['failure']=['code'=>$row['state']==='failed'?'publication_rejected':'publication_unconfirmed','message'=>$row['state']==='failed'?'The server rejected this publication.':'Inspect the preserved message ID before retrying.'];
                }catch(ScheduledPublicationNotExecuted $error){$this->restore($row);throw $error;}
                catch(ScheduledMessagePreparationFailed){$row['state']='failed';$row['failure']=['code'=>'publication_preparation_failed','message'=>'Preparation failed before this message was persisted or transmitted.','outcome'=>'not_executed'];}
                catch(\Throwable $error){$row['state']='unknown';$row['failure']=['code'=>'publication_unconfirmed','message'=>'Inspect the preserved message ID before retrying.'];}
                $this->finish($row);$results[]=$row;
            }finally{unset(self::$inFlight[$flight]);}
        }
        return $results;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed> */
    private function hydrate(array $row):array
    {
        $content=$row['content'];if(!is_array($content))throw new \UnexpectedValueException('Invalid scheduled content');
        if(is_array($row['media'])){$bytes=$this->store->get(self::ASSETS,$row['id']);if($bytes===null||strlen($bytes)!==$row['media']['size']||!hash_equals($row['media']['sha256'],hash('sha256',$bytes)))throw new \UnexpectedValueException('Scheduled media snapshot mismatch');$content['media']=['bytes'=>$bytes];}
        return $content;
    }
    /**
     * @param array<string,mixed> $row */
    private function finish(array &$row):void{$row['updatedAt']=$this->now();$this->store->transaction(function()use($row):void{$this->write($row);$this->store->delete(self::DUE,self::dueKey($row));if(PublicationPolicy::terminal($row['state']))$this->store->delete(self::ASSETS,$row['id']);});}
    /**
     * @param array<string,mixed> $row */
    private function restore(array $row):void{$this->store->transaction(function()use($row):void{$fresh=$this->get($row['id']);if($fresh===null||$fresh['state']!=='publishing'||$fresh['messageId']!==$row['messageId'])return;$fresh['state']='queued';$fresh['attempts']=max(0,$fresh['attempts']-1);$fresh['updatedAt']=$this->now();$this->write($fresh);});}
    private function now():int{return $this->clock===null?time():($this->clock)();}
    private static function recipient(string $jid):string{$j=Jid::recipient($jid)->canonical();if(!in_array($j->server,['s.whatsapp.net','lid','g.us'],true)||$j->device!==0)throw new \InvalidArgumentException('Schedules require a primary individual or group JID');return $j->bare();}
    private static function time(int $sendAt,int $now):void{if($sendAt<=$now||$sendAt>$now+2592000)throw new \InvalidArgumentException('sendAt must be future Unix seconds within 30 days');}
    private static function id(string $id):void{if(preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$id)!==1)throw new \InvalidArgumentException('Invalid message schedule ID');}
    /**
     * @param array<string,mixed> $row */
    private static function queued(array $row):void{if($row['state']!=='queued')throw new \LogicException('Only queued message schedules can change');}
    /**
     * @param array<string,mixed> $row */
    private static function dueKey(array $row):string{return sprintf('%012d',$row['sendAt']).':'.$row['id'];}
    /**
     * @param array<string,mixed> $row */
    private function write(array $row):void{$this->store->set(self::RECORDS,$row['id'],BinaryData::encode($row));}
}
