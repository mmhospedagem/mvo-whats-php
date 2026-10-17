<?php
declare(strict_types=1);
namespace MVO\History;

use MVO\Protocol\BinaryData;
use MVO\Signal\SignalStore;

/** Public durable sync ledger is separate from private encrypted-media descriptors. */
final class HistorySyncStore
{
    public function __construct(private readonly SignalStore $store){}
    /**
     * @param array<string,mixed> $job */
    public function queued(string $id,array $job):void
    {
        if($this->get($id)!==null)return;
        $n=$job['notification']??[];$row=['id'=>$id,'notificationId'=>$job['id']??null,'state'=>'queued','attempt'=>0,'createdAt'=>time(),'updatedAt'=>time(),'source'=>'authenticated_primary'];
        foreach(['syncType','chunkOrder','progress','oldestMsgInChunkTimestampSec','peerDataRequestSessionId']as$field)if(isset($n[$field])&&(is_int($n[$field])||is_string($n[$field])))$row[$field]=$n[$field];
        if(is_bool($n['messageAccessStatus']['completeAccessGranted']??null))$row['completeAccessGranted']=$n['messageAccessStatus']['completeAccessGranted'];
        $context=$n['fullHistorySyncOnDemandRequestMetadata']??null;
        if(is_array($context)&&is_string($context['businessProduct']??null)&&strlen($context['businessProduct'])<=128&&is_array($context['connection']??null)&&isset($context['connection']['providerId'],$context['connection']['product'])&&is_string($context['connection']['product'])&&strlen($context['connection']['product'])<=128&&(!isset($context['opaqueClientData'])||(is_string($context['opaqueClientData'])&&strlen($context['opaqueClientData'])<=16384))){
            $this->store->set('history-full-context',$id,BinaryData::encode($context));$row['fullHistoryContextObserved']=true;
        }
        $this->put($id,$row);
    }
    /**
     * @param array<string,mixed> $changes
     * @return array<string,mixed> */
    public function update(string $id,array $changes):array
    {
        $row=$this->get($id)??throw new \OutOfBoundsException('History sync not observed');
        $row=array_replace($row,$changes,['updatedAt'=>time()]);$this->put($id,$row);return $row;
    }
    /**
     * @return array<string,mixed>|null */
    public function get(string $id):?array
    {
        self::id($id);$bytes=$this->store->get('history-sync-ledger',$id);if($bytes===null)return null;$row=BinaryData::decode($bytes);if(!is_array($row))throw new \UnexpectedValueException('Invalid history sync ledger');return $row;
    }
    /**
     * @return list<array<string,mixed>> */
    public function list(int $limit=100,string $after=''):array
    {
        ProtocolValues::page($limit,$after);$rows=[];foreach($this->store->entries('history-sync-ledger',$limit,$after)as$bytes){$row=BinaryData::decode($bytes);if(is_array($row))$rows[]=$row;}return $rows;
    }
    /**
     * @param array<string,mixed> $row */
    public function put(string $id,array $row):void
    {
        self::id($id);$this->store->set('history-sync-ledger',$id,BinaryData::encode($row));
        // Bound retained metadata. Private pending downloads have their own lifecycle.
        $rows=$this->store->entries('history-sync-ledger',1001);if(count($rows)>1000){$oldest=null;$time=PHP_INT_MAX;foreach($rows as$key=>$bytes){$r=BinaryData::decode($bytes);if(is_array($r)&&($r['updatedAt']??0)<$time&&in_array($r['state']??null,['imported','failed','expired','declined'],true)){$time=$r['updatedAt']??0;$oldest=(string)$key;}}if($oldest!==null)$this->store->delete('history-sync-ledger',$oldest);}
    }
    public static function id(string $id):void{if(preg_match('/^[a-f0-9]{64}$/D',$id)!==1)throw new \InvalidArgumentException('Invalid history sync ID');}
    public static function error(\Throwable $error):string{return match(true){$error instanceof \LengthException=>'history_limit_exceeded',$error instanceof \UnexpectedValueException=>'history_integrity_or_format_error',$error instanceof \OverflowException=>'history_integer_overflow',default=>'history_transfer_failed'};}
    /**
     * @param array<string,mixed> $notification */
    public function received(array $notification):void
    {
        $requestId=$notification['fullHistorySyncOnDemandRequestMetadata']['requestId']??$notification['peerDataRequestSessionId']??null;
        if(!is_string($requestId))return;$id=hash('sha256',$requestId);$row=$this->get($id);
        if($row!==null&&($row['requestId']??null)===$requestId&&($row['kind']??null)==='request')$this->update($id,['state'=>'received','historyReceived'=>true,'receivedAt'=>time()]);
    }
}
