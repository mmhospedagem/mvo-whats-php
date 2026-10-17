<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\Media\MediaSource;
use MVO\Message\GroupStatusContent;
use MVO\Protocol\{BinaryData,Jid};
use MVO\Signal\SignalStore;

/** Explicit bounded workflow. Each destination has a durable ID before the first network operation. */
final class DurablePublicationBatchService
{
    private const RECORDS='publication-batches';
    private const ASSETS='publication-batch-assets';
    private const RUNNABLE='publication-batch-runnable';
    private const META='publication-batch-index';
    /**
     * @var array<string,true> */ private static array $inFlight=[];
    /**
     * @param \Closure(string,string,array<string,mixed>,array{id:string}):array<string,mixed> $publish
     *
     * @param \Closure():int|null $clock
     * @param \Closure():void|null $authorizationGuard */
    public function __construct(private readonly SignalStore $store,private readonly \Closure $publish,private readonly ?\Closure $clock=null,private readonly ?\Closure $authorizationGuard=null) {}

    /**
     * @return array<string,mixed> */
    public static function capabilities():array{return ['engine'=>'mvo_local','modes'=>['message','group-status'],'maxItems'=>100,'maxActiveBatches'=>100,'maxMediaBytes'=>33554432,'maxQueuedMediaBytes'=>134217728,'automaticUnknownRetry'=>false,'explicitResumeOnly'=>true];}

    /** No destination discovery or implicit expansion.
     * @param list<mixed> $recipients
     * @param array<string,mixed> $content
     * @return array<string,mixed> */
    public function create(string $mode,array $recipients,array $content,?string $id=null):array
    {
        $id??=bin2hex(random_bytes(16));self::id($id);
        if(!in_array($mode,['message','group-status'],true)||!array_is_list($recipients)||count($recipients)<1||count($recipients)>100)throw new \InvalidArgumentException('Batch requires a mode and 1..100 explicit recipients');
        $destinations=[];foreach($recipients as$to){if(!is_string($to))throw new \InvalidArgumentException('Recipient must be a JID');$jid=Jid::recipient($to)->canonical();if($jid->device!==0||!in_array($jid->server,$mode==='group-status'?['g.us']:['s.whatsapp.net','lid','g.us'],true))throw new \InvalidArgumentException('Invalid batch destination');$destinations[]=$jid->bare();}
        if(count(array_unique($destinations,SORT_STRING))!==count($destinations))throw new \InvalidArgumentException('Duplicate batch destination');
        if($mode==='group-status')GroupStatusContent::normalizeContent($content);
        else MessagePublicationScheduler::validate($destinations[0],$content,$this->now()+1,$this->now());
        $asset=null;$bytes=null;
        if(array_key_exists('media',$content)){$bytes=MediaSource::read($content['media'],33554432);if($bytes==='')throw new \InvalidArgumentException('Empty batch media');$name=$content['fileName']??MediaSource::fileName($content['media']);if($name!==null&&$mode==='message')$content['fileName']=$name;unset($content['media']);$asset=['size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'fileName'=>$name];}
        $fingerprint=PublicationPolicy::fingerprint(['mode'=>$mode,'recipients'=>$destinations,'content'=>$content,'media'=>$asset]);
        return $this->store->transaction(function()use($mode,$destinations,$content,$asset,$bytes,$id,$fingerprint):array{
            if(($old=$this->get($id))!==null){if(!hash_equals($old['requestFingerprint'],$fingerprint))throw new MessageScheduleConflict('Batch ID already belongs to another request');return $old;}
            $active=0;$held=0;foreach($this->all()as$old){if(in_array($old['state'],['running','paused'],true))++$active;if($old['media']!==null&&($old['media']['released']??false)!==true)$held+=$old['media']['size'];}
            if($active>=100||$held+($asset['size']??0)>134217728)throw new \LengthException('Active batch or media quota exceeded');
            $now=$this->now();$items=[];foreach($destinations as$index=>$to)$items[]=['itemId'=>sprintf('%04d',$index+1),'to'=>$to,'messageId'=>'3EB0'.strtoupper(bin2hex(random_bytes(9))),'state'=>'pending','attempts'=>0,'updatedAt'=>$now,'result'=>null,'failure'=>null];
            $record=['id'=>$id,'engine'=>'mvo_local','mode'=>$mode,'state'=>'running','revision'=>1,'requestFingerprint'=>$fingerprint,'content'=>$content,'media'=>$asset,'items'=>$items,'createdAt'=>$now,'updatedAt'=>$now];
            if($bytes!==null)$this->store->set(self::ASSETS,$id,$bytes);$this->write($record);return $record;
        });
    }
    /**
     * @return array<string,mixed>|null */
    public function get(string $id):?array{self::id($id);$bytes=$this->store->get(self::RECORDS,$id);if($bytes===null)return null;$row=BinaryData::decode($bytes);if(!is_array($row)||($row['id']??null)!==$id)throw new \UnexpectedValueException('Invalid durable batch');return $row;}
    /**
     * @return list<array<string,mixed>> */
    public function list(int $limit=50,string $after=''):array{if($limit<1||$limit>1000)throw new \InvalidArgumentException('Invalid batch limit');if($after!=='')self::id($after);$rows=[];foreach($this->store->entries(self::RECORDS,$limit,$after)as$id=>$_){$row=$this->get((string)$id);if($row!==null)$rows[]=$row;}return $rows;}
    /**
     * @return array<string,mixed> */
    public function pause(string $id,?int $expectedRevision=null):array{return $this->control($id,'paused',$expectedRevision);}
    /** Only unattempted or definitively not_executed items are eligible. Unknown/rejected/ACK items are never reset.
     *
     * @return array<string,mixed> */
    public function resume(string $id,?int $expectedRevision=null):array{return $this->control($id,'running',$expectedRevision);}
    /** Cancels pending work only; an in-flight or unknown send remains observable.
     * @return array<string,mixed> */
    public function cancel(string $id,?int $expectedRevision=null):array{return $this->control($id,'canceled',$expectedRevision);}
    /**
     * @return array<string,mixed> */
    private function control(string $id,string $state,?int $expectedRevision):array
    {
        return $this->store->transaction(function()use($id,$state,$expectedRevision):array{$row=$this->get($id)??throw new \OutOfBoundsException('Batch not found');
            if($expectedRevision!==null&&($expectedRevision<1||$expectedRevision!==$row['revision']))throw new PublicationRevisionConflict('Batch revision no longer matches');
            if($row['state']===$state)return $row;if(in_array($row['state'],['canceled','completed'],true))throw new \LogicException('Terminal batch cannot resume');
            foreach($row['items']as&$item){if($state==='canceled'&&in_array($item['state'],['pending','not_executed'],true))$item['state']='canceled';elseif($state==='running'&&$item['state']==='not_executed'){$item['state']='pending';$item['failure']=null;}}unset($item);
            $row['state']=$state;$row['revision']++;$row['updatedAt']=$this->now();$this->releaseAssetIfFinished($row);$this->write($row);return $row;
        });
    }
    /** At most $limit items. Preparation/unknown/rejected outcomes pause the batch; there is no automatic retry.
     *
     * @return list<array<string,mixed>> */
    public function drain(int $limit=1):array
    {
        if($limit<1||$limit>100)throw new \InvalidArgumentException('Batch drain limit must be 1..100');($this->authorizationGuard)?->__invoke();$this->ensureIndex();$results=[];
        // Only runnable records are examined on the hot worker path; terminal history is never scanned here.
        foreach($this->store->entries(self::RUNNABLE,1000)as$id=>$_){
            $candidate=$this->get((string)$id);if($candidate===null){$this->store->delete(self::RUNNABLE,(string)$id);continue;}
            if(count($results)>=$limit)break;$id=$candidate['id'];$flight=spl_object_id($this->store).':'.$id;if(isset(self::$inFlight[$flight]))continue;
            self::$inFlight[$flight]=true;
            try{
                $claim=$this->store->transaction(function()use($id):?array{$row=$this->get($id);if($row===null)return null;
                    foreach($row['items']as&$item)if($item['state']==='publishing'&&($item['leaseUntil']??0)<=$this->now()){$item['state']='unknown';$item['failure']=['code'=>'publication_interrupted','outcome'=>'unknown'];unset($item['claimToken'],$item['leaseUntil']);$row['state']=$row['state']==='canceled'?'canceled':'paused';$row['updatedAt']=$this->now();$this->write($row);return ['record'=>$row,'index'=>null];}unset($item);
                    if($row['state']!=='running'||array_filter($row['items'],static fn(array $item):bool=>$item['state']==='publishing'))return null;
                    foreach($row['items']as$index=>&$item)if($item['state']==='pending'){$item['state']='publishing';$item['attempts']++;$item['updatedAt']=$this->now();$item['claimToken']=bin2hex(random_bytes(16));$item['leaseUntil']=$this->now()+300;$row['updatedAt']=$this->now();$this->write($row);return ['record'=>$row,'index'=>$index];}unset($item);
                    $row['state']='completed';$row['updatedAt']=$this->now();$this->releaseAssetIfFinished($row);$this->write($row);return null;
                });
                if($claim===null)continue;$row=$claim['record'];$index=$claim['index'];if($index===null){$results[]=$row;continue;}$item=$row['items'][$index];
                try{($this->authorizationGuard)?->__invoke();}catch(\Throwable $error){$this->finish($row,$index,'not_executed',null,['code'=>'authorization_denied','outcome'=>'not_executed']);throw $error;}
                try{
                    $content=$row['content'];if($row['media']!==null){$bytes=$this->store->get(self::ASSETS,$id);if($bytes===null||strlen($bytes)!==$row['media']['size']||!hash_equals($row['media']['sha256'],hash('sha256',$bytes)))throw new ScheduledMessagePreparationFailed(new \UnexpectedValueException('Snapshot unavailable'));$content['media']=['bytes'=>$bytes];}
                    $result=($this->publish)($row['mode'],$item['to'],$content,['id'=>$item['messageId']]);$status=$result['status']??'unknown';
                    $state=($result['id']??null)!==$item['messageId']?'unknown':(in_array($status,['server_ack','delivered','read','played'],true)?'server_ack':(in_array($status,['not_executed','rejected'],true)?$status:'unknown'));
                    $safe=array_intersect_key($result,array_flip(['id','to','status','timestamp','serverCode','reason','preflight']));$failure=$state==='server_ack'?null:['code'=>'publication_'.$state,'outcome'=>$state==='not_executed'?'not_executed':($state==='rejected'?'rejected':'unknown')];
                }catch(GroupStatusNotExecuted $error){$state=$error->messageId===$item['messageId']?'not_executed':'unknown';$safe=null;$failure=['code'=>'publication_'.$state,'outcome'=>$state];}
                catch(ScheduledPublicationNotExecuted|ScheduledMessagePreparationFailed){$state='not_executed';$safe=null;$failure=['code'=>'publication_not_executed','outcome'=>'not_executed'];}
                catch(\Throwable){$state='unknown';$safe=null;$failure=['code'=>'publication_unconfirmed','outcome'=>'unknown'];}
                $results[]=$this->finish($row,$index,$state,$safe,$failure);
            }finally{unset(self::$inFlight[$flight]);}
        }
        return $results;
    }
    /** JSON returns the safe durable record; CSV escapes spreadsheet formula-leading characters. */
    public function export(string $id,string $format='json'):string
    {
        $row=$this->get($id)??throw new \OutOfBoundsException('Batch not found');$safe=$this->publicRecord($row);
        if($format==='json')return json_encode($safe,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if($format!=='csv')throw new \InvalidArgumentException('Export format must be json or csv');
        $stream=fopen('php://temp','w+');if($stream===false)throw new \RuntimeException('Cannot create export');
        try{fputcsv($stream,['batch_id','item_id','recipient','message_id','state','attempts','failure_code'],',','"','');foreach($safe['items']as$item){$fields=[$row['id'],$item['itemId'],$item['to'],$item['messageId'],$item['state'],(string)$item['attempts'],$item['failure']['code']??''];foreach($fields as&$field)if(preg_match('/^[=+@\-\t\r]/',$field))$field="'".$field;unset($field);fputcsv($stream,$fields,',','"','');}rewind($stream);$out=stream_get_contents($stream);if($out===false)throw new \RuntimeException('Cannot read export');return $out;}finally{fclose($stream);}
    }
    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed> */
    public function publicRecord(array $row):array{unset($row['requestFingerprint']);foreach($row['items']as&$item)unset($item['claimToken'],$item['leaseUntil']);unset($item);return $row;}
    /**
     * @param array<string,mixed> $claimed
     * @param array<string,mixed>|null $result
     * @param array<string,mixed>|null $failure
     * @return array<string,mixed> */
    private function finish(array $claimed,int $index,string $state,?array $result,?array $failure):array
    {
        return $this->store->transaction(function()use($claimed,$index,$state,$result,$failure):array{$row=$this->get($claimed['id'])??throw new \UnexpectedValueException('Claimed batch disappeared');$item=&$row['items'][$index];if(($item['claimToken']??null)!==$claimed['items'][$index]['claimToken'])return $row;
            $item['state']=$state;$item['result']=$result;$item['failure']=$failure;$item['updatedAt']=$this->now();unset($item['claimToken'],$item['leaseUntil']);unset($item);
            if($row['state']!=='canceled'&&$state!=='server_ack')$row['state']='paused';
            if($row['state']==='running'&&!array_filter($row['items'],static fn(array $i):bool=>in_array($i['state'],['pending','publishing'],true)))$row['state']='completed';
            $row['updatedAt']=$this->now();$this->releaseAssetIfFinished($row);$this->write($row);return $row;
        });
    }
    /**
     * @param array<string,mixed> $row */
    private function releaseAssetIfFinished(array &$row):void{if($row['media']!==null&&!array_filter($row['items'],static fn(array $item):bool=>in_array($item['state'],['pending','publishing','not_executed'],true))){$this->store->delete(self::ASSETS,$row['id']);$row['media']['released']=true;}}
    /**
     * @return list<array<string,mixed>> */ private function all():array{$rows=[];$after='';do{$page=$this->list(1000,$after);foreach($page as$row)$rows[]=$row;if($page!==[])$after=$page[array_key_last($page)]['id'];}while(count($page)===1000);return $rows;}
    /**
     * @param array<string,mixed> $row */ private function write(array $row):void{$this->store->set(self::RECORDS,$row['id'],BinaryData::encode($row));$this->index($row);}
    /** Rebuild once for sessions written before the runnable index existed, inside the same write lock. */
    private function ensureIndex():void
    {
        if($this->store->get(self::META,'version')==='1')return;
        $this->store->transaction(function():void{
            if($this->store->get(self::META,'version')==='1')return;
            foreach($this->all()as$row)$this->index($row);
            $this->store->set(self::META,'version','1');
        });
    }
    /** @param array<string,mixed> $row */
    private function index(array $row):void
    {
        if($row['state']==='running'||array_filter($row['items'],static fn(array $item):bool=>$item['state']==='publishing'))$this->store->set(self::RUNNABLE,$row['id'],$row['id']);
        else $this->store->delete(self::RUNNABLE,$row['id']);
    }
    private static function id(string $id):void{if(preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$id)!==1)throw new \InvalidArgumentException('Invalid batch ID');}
    private function now():int{return $this->clock===null?time():($this->clock)();}
}
