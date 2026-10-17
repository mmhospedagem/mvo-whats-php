<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\History\{HistoryStore,HistorySyncStore,ProtocolValues};
use MVO\Protocol\{BinaryData,Jid};
use MVO\Signal\SignalStore;

/** Correlated primary-device requests and queries of authenticated observations. */
final class HistoryFeaturesService
{
    private \Closure $credentials;
    private ?\Closure $publisher;
    private \Closure $clock;
    /**
     *
     * @param callable():array<string,mixed> $credentials
     *
     *
     * @param callable(string,array<string,mixed>,array<string,mixed>):array<string,mixed>|null $publishProtocol
     *
     *
     * @param callable():int|null $clock */
    public function __construct(private readonly SignalStore $store,private readonly HistoryStore $history,callable $credentials,?callable $publishProtocol=null,?callable $clock=null)
    {$this->credentials=\Closure::fromCallable($credentials);$this->publisher=$publishProtocol===null?null:\Closure::fromCallable($publishProtocol);$this->clock=$clock===null?static fn():int=>(int)floor(microtime(true)*1000):\Closure::fromCallable($clock);}
    /**
     *
     * @return list<array<string,mixed>> */
    public function listSyncs(int $limit=100,string $after=''):array{return $this->history->syncs()->list($limit,$after);}
    /**
     *
     * @return array<string,mixed>|null */
    public function getSync(string $id):?array{return $this->history->syncs()->get($id);}
    /**
     *
     * @param array<string,mixed> $filters
     *
     * @return list<array<string,mixed>> */
    public function listCallLogs(array $filters=[]):array{return $this->history->observed()->list('calls',$filters);}
    /**
     *
     * @param array<string,mixed> $filters
     *
     * @return list<array<string,mixed>> */
    public function listStickers(array $filters=[]):array{return $this->history->observed()->list('stickers',$filters);}
    /**
     *
     * @param array<string,mixed> $filters
     *
     * @return list<array<string,mixed>> */
    public function listStatusInteractions(array $filters=[]):array{return $this->history->observed()->list('status-interactions',$filters);}
    /**
     *
     * @param array<string,mixed> $filters
     *
     * @return list<array<string,mixed>> */
    public function listBotReminders(array $filters=[]):array{return $this->history->observed()->list('bot-reminders',$filters);}
    /**
     *
     * @return array<string,mixed>|null */
    public function getBotReminder(string $id):?array{return $this->history->observed()->getReminder($id);}
    /**
     * @param array<string,mixed> $filters
     *
     * @return list<array<string,mixed>> */
    public function listPayments(array $filters=[]):array{return $this->history->observed()->list('payments',$filters);}
    /**
     * @return array<string,mixed>|null */
    public function getPayment(string $id):?array{return $this->history->observed()->getPayment($id);}
    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>> */
    public function listMessageObservations(string $kind,array $filters=[]):array{return $this->history->messageObservations()->list($kind,$filters);}
    /**
     * @return array<string,mixed> */
    public function messageReactions(string $chat,string $id,int $limit=100,string $after=''):array{return $this->history->messageObservations()->reactions($chat,$id,$limit,$after);}
    /**
     * @return array<string,mixed> */
    public function messageRevisions(string $chat,string $id,int $limit=100,string $after=''):array{return $this->history->messageObservations()->revisions($chat,$id,$limit,$after);}
    /**
     *
     * @return array<string,mixed> */
    public function capabilities():array
    {
        $c=($this->credentials)();$companion=($c['role']??'companion')!=='primary';
        return ['source'=>'authenticated_protocol_state','fullHistory'=>['requestRequiresObservedCoexContext'=>true,'companion'=>$companion,'recoverDeleted'=>false],
            'chunkRetry'=>['supported'=>$companion&&$this->publisher!==null,'requiresFailedObservedChunk'=>true,'maxRequestsPerChunk'=>2,'automatic'=>false],
            'callLogs'=>['receive'=>true,'query'=>true],'stickers'=>['receive'=>true,'query'=>true],'statusInteractions'=>['receive'=>true,'query'=>true],
            'botReminders'=>['receive'=>true,'query'=>true,'nativeCreation'=>false],
            'messageObservations'=>['reactions'=>true,'revisions'=>true,'pastParticipants'=>true,'memberLabels'=>true,'botMetadata'=>true,'comments'=>true,'liveLocations'=>true,'afterReadPolicies'=>true],
            'experimental'=>[
                'statusPoll'=>['schemaKnown'=>true,'authenticatedDecode'=>true,'observedQuery'=>true,'nativePublish'=>false,'reason'=>'A Status poll publication and vote-routing producer has not been established.'],
                'statusAddYours'=>['schemaKnown'=>true,'authenticatedDecode'=>true,'observedQuery'=>true,'nativePublish'=>false,'reason'=>'Observed responses and wrappers do not establish native prompt publication.'],
                'nativeScheduling'=>['schemaKnown'=>true,'authenticatedDecode'=>true,'observedQuery'=>true,'nativePublish'=>false,'localScheduler'=>true,'reason'=>'Native scheduling service/reveal lifecycle is not implemented; MVO local scheduling is distinct.'],
                'payments'=>['schemaKnown'=>true,'authenticatedDecode'=>true,'observedQuery'=>true,'nativePublish'=>false,'executePayment'=>false,'paymentVerified'=>false,'reason'=>'Only authenticated observations; protobuf reminder/split entries do not prove payment or a supported sender.'],
                'botReminder'=>['schemaKnown'=>true,'authenticatedDecode'=>true,'observedQuery'=>true,'nativePublish'=>false,'reason'=>'Only authenticated bot reminder observations are implemented.'],
                'instantImage'=>['schemaKnown'=>true,'authenticatedDecode'=>true,'observedQuery'=>false,'nativePublish'=>false,'reason'=>'The message field is observed; a public sending producer and client availability were not established.'],
                'comments'=>['schemaKnown'=>true,'authenticatedDecode'=>true,'observedQuery'=>true,'nativePublish'=>false,'reason'=>'Only observed comments; native sender eligibility and UI require validation.'],
                'liveLocation'=>['schemaKnown'=>true,'authenticatedDecode'=>true,'observedQuery'=>true,'nativePublish'=>false,'reason'=>'Observed coordinate updates; automatic GPS, duration and native lifecycle are not implemented.'],
                'afterRead'=>['schemaKnown'=>true,'authenticatedDecode'=>true,'observedQuery'=>true,'nativePublish'=>false,'reason'=>'Reported duration only; native activation and expiry units/lifecycle are not established.'],
            ]];
    }
    /** Requests regeneration of a failed, authenticated chunk, never arbitrary IDs.
     *
     *
     * @return array<string,mixed> */
    public function retryChunk(string $syncId,bool $regenerate=true):array
    {
        $ledger=$this->getSync($syncId)??throw new \OutOfBoundsException('History chunk not observed');
        if(($ledger['state']??null)!=='failed'||($ledger['kind']??'chunk')!=='chunk')throw new \LogicException('Only a failed observed history chunk can be retried');
        if(($ledger['recoveryRequests']??0)>=2)throw new \LogicException('History chunk retry limit reached');
        $bytes=$this->store->get('history-pending',$syncId);$job=$bytes===null?null:BinaryData::decode($bytes);$n=is_array($job)?($job['notification']??[]):[];
        if(!is_int($n['chunkOrder']??null)||!is_int($n['syncType']??null)||!is_string($job['id']??null))throw new \LogicException('History chunk lacks authenticated retry metadata');
        $request=['syncType'=>$n['syncType'],'chunkOrder'=>$n['chunkOrder'],'chunkNotificationId'=>$job['id'],'regenerateChunk'=>$regenerate];
        $this->peer();
        $requestId=strtoupper(bin2hex(random_bytes(12)));
        $this->store->transaction(function()use($syncId,$requestId):void{
            $current=$this->getSync($syncId)??throw new \OutOfBoundsException('History chunk not observed');
            if(($current['recoveryRequests']??0)>=2)throw new \LogicException('History chunk retry limit reached');
            if(is_string($current['activeRecoveryRequest']??null)){$old=$this->getSync($current['activeRecoveryRequest']);if($old!==null&&!in_array($old['state']??null,['primary_responded','received'],true))throw new \LogicException('Previous history retry has an unresolved outcome');if(($old['canRecover']??null)===false)throw new \LogicException('The primary cannot recover this chunk');}
            $this->history->syncs()->update($syncId,['recoveryRequests'=>($current['recoveryRequests']??0)+1,'activeRecoveryRequest'=>hash('sha256',$requestId)]);
        });
        return $this->request('HISTORY_SYNC_CHUNK_RETRY','historySyncChunkRetryRequest',$request,['sourceSyncId'=>$syncId],$requestId);
    }
    /** Reuses only full-history context actually received from the primary/Coex service.
     *
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed> */
    public function requestFullHistory(array $input):array
    {
        self::validateFull($input);$source=$this->getSync($input['syncId'])??throw new \OutOfBoundsException('Coexistence history context not observed');
        if(($source['createdAt']??0)+86400<$this->now())throw new \LogicException('Observed full history context has expired');
        $bytes=$this->store->get('history-full-context',$source['id']);$context=$bytes===null?null:BinaryData::decode($bytes);
        if(!is_array($context)||!is_string($context['businessProduct']??null)||!is_array($context['connection']??null)||!isset($context['connection']['providerId'],$context['connection']['product']))throw new \LogicException('Full history requires an authenticated business coexistence context');
        $this->peer();$context['requestId']=strtoupper(bin2hex(random_bytes(12)));
        $this->store->transaction(function()use($input,$context):void{$source=$this->getSync($input['syncId'])??throw new \OutOfBoundsException('History source not observed');if(is_string($source['activeFullRequest']??null)){$old=$this->getSync($source['activeFullRequest']);if($old!==null&&!in_array($old['state']??null,['primary_responded','received'],true))throw new \LogicException('Previous full history request has an unresolved outcome');}$this->history->syncs()->update($input['syncId'],['activeFullRequest'=>hash('sha256',$context['requestId'])]);});
        return $this->request('FULL_HISTORY_SYNC_ON_DEMAND','fullHistorySyncOnDemandRequest',['requestMetadata'=>$context,'fullHistorySyncOnDemandConfig'=>array_intersect_key($input,array_flip(['historyFromTimestamp','historyDurationDays']))],['sourceSyncId'=>$input['syncId']],$context['requestId']);
    }
    /**
     *
     * @param array<string,mixed> $input */
    public static function validateFull(array $input):void
    {
        if(array_diff(array_keys($input),['syncId','historyFromTimestamp','historyDurationDays'])!==[])throw new \InvalidArgumentException('Unknown full history request field');
        if(!is_string($input['syncId']??null))throw new \InvalidArgumentException('Full history requires syncId');HistorySyncStore::id($input['syncId']);
        if(!is_int($input['historyDurationDays']??null)||$input['historyDurationDays']<1||$input['historyDurationDays']>365)throw new \InvalidArgumentException('History duration must be 1..365 days');
        if(isset($input['historyFromTimestamp'])&&(!is_int($input['historyFromTimestamp'])||$input['historyFromTimestamp']<0||$input['historyFromTimestamp']>time()))throw new \InvalidArgumentException('Invalid history starting timestamp');
    }
    /** Own-device response only, and only for a locally reserved request.
     *
     *
     * @param array<string,mixed> $event */
    public function ingestResponse(array $event):void
    {
        if(($event['fromMe']??false)!==true||($event['authenticatedOwnDevice']??false)!==true||!is_string($event['authenticatedSender']??null))return;
        try{$sender=Jid::parse($event['authenticatedSender'])->canonical();}catch(\InvalidArgumentException){return;}
        $own=false;foreach(['meJid','meLid']as$field)if(is_string((($this->credentials)())[$field]??null)&&Jid::parse((($this->credentials)())[$field])->canonical()->bare()===$sender->bare())$own=true;
        if(!$own||$sender->device!==0)return;
        $m=\MVO\Message\ContentDecoder::unwrap($event['proto']??[])['message'];$response=$m['protocolMessage']['peerDataOperationRequestResponseMessage']??null;if(!is_array($response))return;
        $requestId=$response['stanzaId']??null;if(!is_string($requestId))return;$id=hash('sha256',$requestId);$record=$this->getSync($id);
        if($record===null||($record['requestId']??null)!==$requestId||($record['createdAt']??0)+86400<$this->now())return;
        $expected=($record['requestType']??null)==='HISTORY_SYNC_CHUNK_RETRY'?10:6;if(!in_array($response['peerDataOperationRequestType']??null,[$expected,$record['requestType']],true))return;
        $field=$expected===10?'historySyncChunkRetryResponse':'fullHistorySyncOnDemandRequestResponse';
        foreach(array_slice(is_array($response['peerDataOperationResult']??null)?$response['peerDataOperationResult']:[],0,100)as$result){$r=$result[$field]??null;if(!is_array($r))continue;
            if($expected===10&&isset($r['requestId'])&&$r['requestId']!==$requestId)continue;
            if($expected===6&&($r['requestMetadata']['requestId']??null)!==$requestId)continue;
            $code=$r['responseCode']??null;if(!is_int($code)&&!is_string($code))continue;
            $changes=['state'=>'primary_responded','responseCode'=>$code,'responseMessageId'=>$event['id']??null,'respondedAt'=>$this->now()];
            if(is_bool($r['canRecover']??null))$changes['canRecover']=$r['canRecover'];
            $this->history->syncs()->update($id,$changes);break;
        }
    }
    /**
     *
     * @param array<string,mixed> $request
     *
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed> */
    private function request(string $type,string $field,array $request,array $extra=[],?string $requestId=null):array
    {
        $to=$this->peer();$publisher=$this->publisher??throw new \LogicException('History request transport not configured');$requestId??=strtoupper(bin2hex(random_bytes(12)));$id=hash('sha256',$requestId);
        $record=['id'=>$id,'kind'=>'request','requestId'=>$requestId,'requestType'=>$type,'state'=>'prepared','createdAt'=>$this->now(),'updatedAt'=>$this->now(),'historyReceived'=>false,...$extra];$this->history->syncs()->put($id,$record);
        try{$ack=$publisher($to,['type'=>'PEER_DATA_OPERATION_REQUEST_MESSAGE','peerDataOperationRequestMessage'=>['peerDataOperationRequestType'=>$type,$field=>$request]],['id'=>$requestId,'pushPriority'=>'high_force']);
            $current=$this->getSync($id)??$record;if($current['state']==='prepared')$current=$this->history->syncs()->update($id,['state'=>in_array($ack['status']??null,['server_ack','delivered','read'],true)?'server_ack':'unknown']);return $current+['submission'=>array_intersect_key($ack,array_flip(['id','to','status','timestamp']))];
        }catch(\Throwable $e){$current=$this->getSync($id)??$record;if($current['state']==='prepared')$this->history->syncs()->update($id,['state'=>'unknown','error'=>'history_request_outcome_unknown']);throw $e;}
    }
    private function peer():string
    {
        $c=($this->credentials)();if(($c['role']??'companion')==='primary'||!is_string($c['meJid']??null))throw new \LogicException('History requests require a registered companion and an available primary');return Jid::parse($c['meJid'])->canonical()->bare();
    }
    private function now():int{return intdiv(($this->clock)(),1000);}
}
