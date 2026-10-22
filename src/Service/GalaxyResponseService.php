<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\Message\ContentDecoder;
use MVO\Protocol\{BinaryData,Jid};
use MVO\Signal\SignalStore;

/** Correlated Galaxy CSV exports from this companion's authenticated primary device. */
final class GalaxyResponseService
{
    public const MAX_COMPRESSED_BYTES=8388608;
    public const MAX_CSV_BYTES=16777216;
    private const LIFETIME=86400;
    private \Closure $credentials;
    private \Closure $publisher;
    private \Closure $download;
    private \Closure $clock;

    /**
     * @param callable():array<string,mixed> $credentials
     * @param callable(string,array<string,mixed>,array<string,mixed>):array<string,mixed> $publishProtocol
     * @param callable(array<string,mixed>):string $download Authenticated DOCUMENT media download; returns gzip bytes.
     * @param callable():int|null $clock Unix time in milliseconds.
     */
    public function __construct(private readonly SignalStore $store,callable $credentials,callable $publishProtocol,callable $download,?callable $clock=null)
    {
        $this->credentials=\Closure::fromCallable($credentials);$this->publisher=\Closure::fromCallable($publishProtocol);
        $this->download=\Closure::fromCallable($download);$this->clock=$clock===null?static fn():int=>(int)floor(microtime(true)*1000):\Closure::fromCallable($clock);
    }

    /** An ACK only confirms submission; a matching primary response makes the CSV available.
     * @return array<string,mixed> */
    public function requestResponses(string $flowId,?string $requestId=null):array
    {
        self::flowId($flowId);$requestId??=self::uuid();self::requestId($requestId);
        $credentials=($this->credentials)();$own=self::ownJids($credentials);
        if(($credentials['role']??'companion')==='primary'||$own===[])throw new \LogicException('Galaxy response export requires a registered companion and its primary device');
        $existing=$this->record($requestId);
        if($existing!==null){if($existing['flowId']!==$flowId)throw new \LogicException('Galaxy request ID already belongs to another Flow');return $this->projection($existing);}
        $activeId=$this->store->get('galaxy-response-flows',hash('sha256',$flowId));
        if($activeId!==null){$active=$this->record($activeId);if($active!==null&&in_array($active['state'],['prepared','server_ack','unknown'],true))throw new \LogicException('A Galaxy export for this Flow still has an unresolved outcome');}
        $now=$this->now();$stanzaId='3EB0'.strtoupper(bin2hex(random_bytes(9)));
        $record=['requestId'=>$requestId,'flowId'=>$flowId,'stanzaId'=>$stanzaId,'state'=>'prepared','createdAt'=>$now,'updatedAt'=>$now,'expiresAt'=>$now+self::LIFETIME,'primaryJids'=>$own];
        $this->store->transaction(function()use($record,$flowId,$requestId,$stanzaId):void{
            $this->save($record);$this->store->set('galaxy-response-flows',hash('sha256',$flowId),$requestId);
            $this->store->set('galaxy-response-stanzas',hash('sha256',$stanzaId),$requestId);
        });
        try{
            $ack=($this->publisher)($own[0],['type'=>'PEER_DATA_OPERATION_REQUEST_MESSAGE','peerDataOperationRequestMessage'=>[
                'peerDataOperationRequestType'=>'GALAXY_FLOW_ACTION','galaxyFlowAction'=>['type'=>'DOWNLOAD_RESPONSES','flowId'=>$flowId,'galaxyFlowDownloadRequestId'=>$requestId],
            ]],['id'=>$stanzaId,'pushPriority'=>'high']);
            $current=$this->record($requestId)??$record;
            if($current['state']==='prepared'){$current['state']=($ack['id']??null)===$stanzaId&&in_array($ack['status']??null,['server_ack','delivered','read'],true)?'server_ack':'unknown';$current['updatedAt']=$this->now();$this->save($current);}
            return $this->projection($current);
        }catch(\Throwable $error){
            $current=$this->record($requestId)??$record;
            if($current['state']==='prepared'){$current['state']='unknown';$current['error']='galaxy_export_outcome_unknown';$current['updatedAt']=$this->now();$this->save($current);}
            throw $error;
        }
    }

    /** Public projection deliberately excludes media keys, paths and private download descriptors.
     * @return array<string,mixed>|null */
    public function requestStatus(string $requestId):?array
    {
        self::requestId($requestId);$record=$this->record($requestId);return $record===null?null:$this->projection($record);
    }

    /** Persists descriptors only. No download, query or user callback is executed here.
     * @param array<string,mixed> $event */
    public function ingestAuthenticatedEvent(array $event):void
    {
        if(($event['fromMe']??false)!==true||($event['authenticatedOwnDevice']??false)!==true||!is_string($event['authenticatedSender']??null))return;
        try{$sender=Jid::parse($event['authenticatedSender'])->canonical();}catch(\InvalidArgumentException){return;}
        if($sender->device!==0||!in_array($sender->bare(),self::ownJids(($this->credentials)()),true))return;
        try{$message=ContentDecoder::unwrap(is_array($event['proto']??null)?$event['proto']:[])['message'];}catch(\UnexpectedValueException|\LengthException){return;}
        $response=$message['protocolMessage']['peerDataOperationRequestResponseMessage']??null;
        if(!is_array($response)||!in_array($response['peerDataOperationRequestType']??null,[11,'GALAXY_FLOW_ACTION'],true))return;
        $stanzaRequestId=null;
        if(isset($response['stanzaId'])){
            if(!is_string($response['stanzaId']))return;
            $stanzaRequestId=$this->store->get('galaxy-response-stanzas',hash('sha256',$response['stanzaId']));if($stanzaRequestId===null)return;
        }
        $results=$response['peerDataOperationResult']??null;if(!is_array($results))return;
        foreach(array_slice($results,0,100)as$result){
            if(!is_array($result)||!is_array($result['flowResponsesCsvBundle']??null))continue;$bundle=$result['flowResponsesCsvBundle'];
            // Web accepts bundles without flowId/download UUID. Require one reserved
            // request identifier, and reject every contradictory identifier supplied.
            $bundleRequestId=$bundle['galaxyFlowDownloadRequestId']??null;
            if($bundleRequestId!==null&&!is_string($bundleRequestId))continue;
            if($bundleRequestId!==null&&$stanzaRequestId!==null&&$bundleRequestId!==$stanzaRequestId)continue;
            $requestId=$bundleRequestId??$stanzaRequestId;if($requestId===null)continue;
            $record=$this->record($requestId);
            if($record===null||!in_array($record['state'],['prepared','server_ack','unknown'],true)||!in_array($sender->bare(),$record['primaryJids'],true))continue;
            if(isset($response['stanzaId'])&&$record['stanzaId']!==$response['stanzaId'])continue;
            if(isset($bundle['flowId'])&&$bundle['flowId']!==$record['flowId'])continue;
            try{$descriptor=self::descriptor($bundle);}catch(\InvalidArgumentException){continue;}
            $record['bundle']=$descriptor;$record['state']='ready';$record['receivedAt']=$record['updatedAt']=$this->now();unset($record['error']);
            if(is_string($event['id']??null)&&strlen($event['id'])<=256)$record['responseMessageId']=$event['id'];
            foreach(['fileName','mimetype','fileLength']as$field)if(isset($descriptor[$field]))$record[$field]=$descriptor[$field];
            $this->save($record);return;
        }
    }

    /** Returns unchanged UTF-8 CSV bytes, or the caller's destination after an atomic local write. */
    public function downloadResponses(string $requestId,?string $destination=null):string
    {
        self::requestId($requestId);if($destination!==null)self::destination($destination);
        $record=$this->record($requestId);
        if($record===null||!is_array($record['bundle']??null)||!in_array($record['state'],['ready','downloaded','download_failed'],true))throw new \LogicException('Galaxy CSV is not ready; wait for the authenticated primary response');
        try{
            $compressed=($this->download)($record['bundle']);
            if(strlen($compressed)>self::MAX_COMPRESSED_BYTES)throw new \LengthException('Galaxy compressed CSV exceeds the SDK limit');
            $csv=self::inflate($compressed);self::csv($csv);
            if($destination!==null)self::write($destination,$csv);
            $record['state']='downloaded';$record['downloadedAt']=$record['updatedAt']=$this->now();$record['csvBytes']=strlen($csv);unset($record['error']);$this->save($record);
            return $destination??$csv;
        }catch(\Throwable $error){$record['state']='download_failed';$record['updatedAt']=$this->now();$record['error']='galaxy_csv_download_failed';$this->save($record);throw $error;}
    }

    /** @param array<string,mixed> $credentials
     * @return list<string> */
    private static function ownJids(array $credentials):array
    {
        $out=[];foreach(['meJid','meLid']as$field){if(!is_string($credentials[$field]??null))continue;
            try{$jid=Jid::parse($credentials[$field])->canonical();if(in_array($jid->server,['s.whatsapp.net','lid'],true))$out[]=$jid->bare();}catch(\InvalidArgumentException){}
        }return array_values(array_unique($out));
    }
    /** @param array<string,mixed> $bundle
     * @return array<string,mixed> */
    private static function descriptor(array $bundle):array
    {
        $out=['mediaType'=>'document'];
        foreach(['mediaKey','fileSha256','fileEncSha256']as$field){if(!is_string($bundle[$field]??null)||strlen($bundle[$field])!==32)throw new \InvalidArgumentException('Invalid Galaxy media integrity fields');$out[$field]=$bundle[$field];}
        if(!is_string($bundle['directPath']??null)||$bundle['directPath']===''||strlen($bundle['directPath'])>8192||preg_match('/[\x00-\x20\x7f]/',$bundle['directPath']))throw new \InvalidArgumentException('Invalid Galaxy media path');$out['directPath']=$bundle['directPath'];
        foreach(['fileName'=>1024,'mimetype'=>256]as$field=>$limit)if(isset($bundle[$field])){if(!is_string($bundle[$field])||strlen($bundle[$field])>$limit||preg_match('//u',$bundle[$field])!==1||str_contains($bundle[$field],"\0"))throw new \InvalidArgumentException('Invalid Galaxy file metadata');$out[$field]=$bundle[$field];}
        if(isset($bundle['fileLength'])){if((!is_int($bundle['fileLength'])&&!is_string($bundle['fileLength']))||preg_match('/^(?:0|[1-9][0-9]*)$/D',(string)$bundle['fileLength'])!==1||strlen((string)$bundle['fileLength'])>8||(int)$bundle['fileLength']>self::MAX_COMPRESSED_BYTES)throw new \InvalidArgumentException('Invalid Galaxy compressed file length');$out['fileLength']=(int)$bundle['fileLength'];}
        if(isset($bundle['mediaKeyTimestamp'])&&(is_int($bundle['mediaKeyTimestamp'])||is_string($bundle['mediaKeyTimestamp'])))$out['mediaKeyTimestamp']=$bundle['mediaKeyTimestamp'];
        return $out;
    }
    private static function inflate(string $bytes):string
    {
        if(!str_starts_with($bytes,"\x1f\x8b"))throw new \UnexpectedValueException('Galaxy export is not a gzip stream');
        $context=inflate_init(ZLIB_ENCODING_GZIP);if($context===false)throw new \RuntimeException('Cannot initialize Galaxy CSV decompression');$csv='';$length=strlen($bytes);
        for($offset=0;$offset<$length;$offset+=1024){$part=@inflate_add($context,substr($bytes,$offset,1024),$offset+1024>=$length?ZLIB_FINISH:ZLIB_SYNC_FLUSH);
            if($part===false)throw new \UnexpectedValueException('Invalid Galaxy gzip export');
            if(strlen($csv)+strlen($part)>self::MAX_CSV_BYTES)throw new \LengthException('Galaxy CSV exceeds the SDK expansion limit');$csv.=$part;
        }
        if(inflate_get_status($context)!==ZLIB_STREAM_END||inflate_get_read_len($context)!==$length)throw new \UnexpectedValueException('Truncated or trailing Galaxy gzip data');return $csv;
    }
    private static function csv(string $csv):void
    {
        if(preg_match('//u',$csv)!==1||str_contains($csv,"\0"))throw new \UnexpectedValueException('Galaxy CSV is not valid UTF-8 text');
        $state=0;$length=strlen($csv);$start=str_starts_with($csv,"\xef\xbb\xbf")?3:0;
        for($i=$start;$i<$length;$i++){$char=$csv[$i];
            if($state===2){if($char==='"')$state=3;continue;}
            if($state===3){if($char==='"'){$state=2;continue;}if($char!==','&&$char!=="\r"&&$char!=="\n")throw new \UnexpectedValueException('Invalid quoted Galaxy CSV field');$state=0;continue;}
            if($char===','||$char==="\r"||$char==="\n"){$state=0;continue;}
            if($char==='"'){if($state!==0)throw new \UnexpectedValueException('Invalid Galaxy CSV quote');$state=2;}else $state=1;
        }
        if($state===2)throw new \UnexpectedValueException('Unterminated Galaxy CSV field');
    }
    private static function destination(string $path):void
    {
        if($path===''||str_contains($path,"\0")||preg_match('~^[a-z][a-z0-9+.-]*://~i',$path)||!is_dir(dirname($path))||is_dir($path))throw new \InvalidArgumentException('Galaxy CSV destination must be a local file in an existing directory');
    }
    private static function write(string $destination,string $csv):void
    {
        $temporary=tempnam(dirname($destination),'.mvo-galaxy-');if($temporary===false)throw new \RuntimeException('Cannot create Galaxy CSV destination');
        try{if(file_put_contents($temporary,$csv,LOCK_EX)!==strlen($csv)||!rename($temporary,$destination))throw new \RuntimeException('Cannot write Galaxy CSV destination');}finally{if(is_file($temporary))unlink($temporary);}
    }
    /** @return array<string,mixed>|null */
    private function record(string $requestId):?array
    {
        $bytes=$this->store->get('galaxy-response-requests',hash('sha256',$requestId));if($bytes===null)return null;$record=BinaryData::decode($bytes);
        if(!is_array($record)||($record['requestId']??null)!==$requestId||!is_int($record['expiresAt']??null)||!is_string($record['state']??null))throw new \UnexpectedValueException('Invalid saved Galaxy export request');
        if($record['state']!=='expired'&&$record['expiresAt']<=$this->now()){$record['state']='expired';$record['updatedAt']=$this->now();unset($record['bundle']);$this->save($record);}return $record;
    }
    /** @param array<string,mixed> $record */
    private function save(array $record):void{$this->store->set('galaxy-response-requests',hash('sha256',$record['requestId']),BinaryData::encode($record));}
    /** @param array<string,mixed> $record
     * @return array<string,mixed> */
    private function projection(array $record):array
    {
        return array_intersect_key($record,array_flip(['requestId','flowId','stanzaId','state','createdAt','updatedAt','expiresAt','receivedAt','responseMessageId','fileName','mimetype','fileLength','downloadedAt','csvBytes','error']))+['csvReady'=>isset($record['bundle'])&&$record['state']!=='expired'];
    }
    private function now():int{return intdiv(($this->clock)(),1000);}
    private static function flowId(string $id):void{if(preg_match('/^[0-9]{1,64}$/D',$id)!==1)throw new \InvalidArgumentException('Galaxy flowId must be a remote numeric Flow ID');}
    private static function requestId(string $id):void{if(preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$id)!==1)throw new \InvalidArgumentException('Invalid Galaxy export request ID');}
    private static function uuid():string{$bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);}
}
