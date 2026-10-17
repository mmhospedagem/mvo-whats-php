<?php
declare(strict_types=1);
namespace MVO\AppState;

use MVO\Protocol\BinaryData;
use MVO\Protocol\BinaryNode;
use MVO\Protocol\Jid;
use MVO\Protocol\SchemaCodec;
use MVO\Signal\SignalStore;

/** Companion app-state synchronization. No network operation is performed in incoming message handlers. */
final class AppStateService
{
    public const COLLECTIONS=['critical_unblock_low','critical_block','regular_low','regular','regular_high'];
    private \Closure $query;
    private ?\Closure $emit;
    private ?\Closure $download;
    private ?\Closure $ownDevice;
    private ?\Closure $messageRange;
    private bool $syncing=false;
    private bool $syncRequested=false;
    private ?AppStateProjector $projector=null;
    private bool $primaryMode=false;
    /**
     * @param callable(BinaryNode,float):BinaryNode $query
     * @param callable(string,array<string,mixed>):void|null $emit
     * @param callable(array<string,mixed>):string|null $downloadExternal
     * @param callable(string):bool|null $ownDevice
     * @param callable(string):array<string,mixed>|null $messageRange */
    public function __construct(private readonly SignalStore $store,callable $query,?callable $emit=null,?callable $downloadExternal=null,?callable $ownDevice=null,?callable $messageRange=null)
    {
        $this->query=\Closure::fromCallable($query);
        $this->emit=$emit===null?null:\Closure::fromCallable($emit);
        $this->download=$downloadExternal===null?null:\Closure::fromCallable($downloadExternal);
        $this->ownDevice=$ownDevice===null?null:\Closure::fromCallable($ownDevice);
        $this->messageRange=$messageRange===null?null:\Closure::fromCallable($messageRange);
    }
    /** Uses the same durable transaction for cryptographic collection state and local history projection. */
    public function setProjector(AppStateProjector $projector): void { $this->projector=$projector; }
    /** Only the registered mobile-primary coordinator should enable this mode. */
    public function setPrimaryMode(bool $primary): void { if($this->syncing)throw new \LogicException('Cannot change app-state role during sync');$this->primaryMode=$primary; }

    /**
     * @param array<string,mixed> $message
     * @param array<string,mixed> $context */
    public function handleProtocol(array $message,array $context=[]): bool
    {
        $protocol=$message['protocolMessage']??$message;
        if (!is_array($protocol)) return false;
        if (!isset($protocol['appStateSyncKeyShare']) && !isset($protocol['appStateSyncKeyRequest'])) return false;
        $sender=$context['participant']??$context['from']??$context['sender']??'';
        $trusted=($context['authenticatedOwnDevice']??false)===true || ($context['fromMe']??false)===true;
        if (!is_string($sender) || $sender==='' || !$trusted || ($this->ownDevice!==null && !($this->ownDevice)($sender))) throw new \UnexpectedValueException('App-state key protocol sender must be an authenticated own-account device');
        if (isset($protocol['appStateSyncKeyShare'])) {
            $share=$protocol['appStateSyncKeyShare'];
            if (!is_array($share)) throw new \UnexpectedValueException('Invalid app-state key share');
            $keys=[];
            foreach ($share['keys']??[] as $entry) {
                if (!is_array($entry) || !is_array($entry['keyData']??null)) throw new \UnexpectedValueException('Invalid app-state shared key');
                $keys[]=['keyId'=>$entry['keyId']['keyId']??null,'keyData'=>$entry['keyData']['keyData']??null,'timestamp'=>$entry['keyData']['timestamp']??0,'fingerprint'=>$entry['keyData']['fingerprint']??null];
            }
            $inserted=$this->importKeys($keys);
            if ($inserted>0) { $this->syncRequested=true; $this->event('appstate_keys',['count'=>$inserted]); }
        } else {
            $ids=[]; $keys=[];
            foreach ($protocol['appStateSyncKeyRequest']['keyIds']??[] as $entry) {
                $id=$entry['keyId']??null;
                if (!is_string($id) || $id==='') continue;
                $ids[bin2hex($id)]=$id;
            }
            foreach ($ids as $id) {
                $key=$this->getKey($id);
                if ($key!==null) $keys[]=['keyId'=>['keyId'=>$id],'keyData'=>array_filter(['keyData'=>$key['keyData'],'timestamp'=>$key['timestamp'],'fingerprint'=>$key['fingerprint']??null],static fn($value)=>$value!==null)];
            }
            $this->event('appstate_key_share_requested',['to'=>$sender,'message'=>['protocolMessage'=>['type'=>6,'appStateSyncKeyShare'=>['keys'=>$keys]]]]);
        }
        return true;
    }

    /** Explicit trusted import for restored/exported sync keys.
     * @param list<array<string,mixed>> $keys */
    public function importKeys(array $keys): int
    {
        return $this->store->transaction(function() use($keys):int {
            $ids=$this->record('key-index',[]); $added=0;
            foreach ($keys as $key) {
                $id=$key['keyId']??null; $data=$key['keyData']??null;
                if (!is_string($id) || $id==='' || strlen($id)>254 || !is_string($data) || strlen($data)!==32) throw new \InvalidArgumentException('Invalid app-state sync key');
                $hex=bin2hex($id); $existing=$this->getKey($id);
                if ($existing!==null) {
                    if (!hash_equals($existing['keyData'],$data)) throw new \UnexpectedValueException('App-state sync key changed for an existing ID');
                    continue;
                }
                if (count($ids)>=4096) throw new \LengthException('App-state sync key inventory is full');
                $key['timestamp']=self::integer($key['timestamp']??0,'key timestamp');
                $this->store->set('appstate-keys',$hex,BinaryData::encode($key)); $ids[]=$hex; ++$added;
            }
            $this->put('key-index',$ids);
            return $added;
        });
    }

    /**
     * @return array<string,mixed>|null */
    public function getKey(string $id): ?array
    {
        $value=$this->store->get('appstate-keys',bin2hex($id));
        if ($value===null) return null;
        $key=BinaryData::decode($value);
        if (!is_array($key) || !is_string($key['keyData']??null)) throw new \UnexpectedValueException('Invalid stored app-state key');
        return $key;
    }

    /**
     * @return array<string,mixed>|null */
    public function activeKey(): ?array
    {
        $active=null;
        foreach ($this->record('key-index',[]) as $hex) {
            $key=$this->getKey(hex2bin($hex)); if ($key===null) continue;
            $id=$key['keyId']; $epoch=strlen($id)>=6?unpack('N',substr($id,2,4))[1]:-1; $device=strlen($id)>=2?unpack('n',$id)[1]:65536;
            if ($active===null || $epoch>$active['epoch'] || ($epoch===$active['epoch'] && $device<$active['device'])) $active=$key+['epoch'=>$epoch,'device'=>$device];
        }
        return $active;
    }

    /**
     * @return array{initialized:bool,version:int,hash:string,indexValueMap:array<string,string>} */
    public function collectionState(string $collection): array
    {
        self::collection($collection);
        $state=$this->record('collection.'.$collection,['initialized'=>$this->primaryMode,'version'=>0,'hash'=>str_repeat("\0",128),'indexValueMap'=>[]]);
        if (!is_bool($state['initialized']??null) || !is_int($state['version']??null) || !is_string($state['hash']??null) || strlen($state['hash'])!==128 || !is_array($state['indexValueMap']??null)) throw new \UnexpectedValueException('Invalid stored app-state collection');
        return $state;
    }

    /**
     * @return list<array<string,mixed>> */
    public function pendingMutations(): array { return $this->record('pending',[]); }
    public function needsSync(): bool { return $this->syncRequested || $this->pendingMutations()!==[]; }
    public function requestSync(): void { $this->syncRequested=true; }

    /** Queues durably before network I/O; an error leaves the action available for the next sync.
     * @param array<string,mixed> $args
     * @param array<string,mixed>|null $data
     * @return array<string,mixed> */
    public function mutate(string $schema,array $args,?array $data,bool $remove=false,bool $flush=true): array
    {
        $mutation=AppStateSchema::mutation($schema,$args,$data,(int)floor(microtime(true)*1000),$remove);
        $this->enqueue([$mutation]);
        if ($flush) $this->flushMutations();
        return $mutation;
    }

    /**
     * @param list<array<string,mixed>> $mutations */
    public function enqueue(array $mutations): void
    {
        foreach ($mutations as $mutation) {
            self::collection($mutation['collection']??'');
            if (!in_array($mutation['operation']??null,['set','remove'],true) || !is_string($mutation['index']??null) || !is_int($mutation['version']??null) || $mutation['version']<0) throw new \InvalidArgumentException('Invalid queued app-state mutation');
            $index=json_decode($mutation['index'],true,16,JSON_THROW_ON_ERROR);
            if (!is_array($index) || !array_is_list($index)) throw new \InvalidArgumentException('Mutation index must be a JSON array');
            if ($mutation['operation']==='set') SchemaCodec::encode('SyncActionValue',$mutation['value']??[]);
        }
        $this->store->transaction(function() use($mutations):void {
            $pending=[];
            foreach ([...$this->pendingMutations(),...$mutations] as $mutation) $pending[$mutation['collection']."\0".$mutation['index']]=$mutation;
            if (count($pending)>10000) throw new \LengthException('App-state pending queue is full');
            $this->put('pending',array_values($pending));
        });
    }

    /**
     * @return array<string,mixed> */
    public function flushMutations(): array
    {
        $collections=array_values(array_unique(array_column($this->pendingMutations(),'collection')));
        if ($collections===[]) return ['collections'=>[]];
        $result=$this->sync($collections);
        if ($this->pendingMutations()!==[]) throw new \RuntimeException('App-state mutations remain pending; inspect collection sync results');
        return $result;
    }

    /** Fetches first to reconcile an upload whose acknowledgment was lost.
     * @param list<string> $collections
     * @return array{collections:list<array<string,mixed>>} */
    public function sync(array $collections=self::COLLECTIONS,float $timeout=30.0): array
    {
        if ($this->syncing) throw new \LogicException('App-state sync is already in progress');
        if ($timeout<=0 || !is_finite($timeout)) throw new \InvalidArgumentException('Invalid app-state timeout');
        $collections=array_values(array_unique($collections)); foreach($collections as $collection) self::collection($collection);
        if ($collections===[]) return ['collections'=>[]];
        $this->syncing=true; $results=[]; $remaining=$collections;
        try {
            for ($round=0;$remaining!==[] && $round<8;$round++) {
                $nodes=[]; $uploads=[];
                foreach ($remaining as $collection) {
                    $state=$this->collectionState($collection); $children=[];
                    $pending=array_values(array_filter($this->pendingMutations(),static fn($m)=>$m['collection']===$collection));
                    if ($round>0 && $state['initialized'] && $pending!==[] && $this->activeKey()===null) {
                        $this->event('appstate_missing_keys',['keyIds'=>[],'collections'=>[$collection]]);
                        $results[$collection]=['collection'=>$collection,'state'=>'blocked','error'=>'No app-state sync key available for upload'];
                        continue;
                    }
                    if ($round>0 && $state['initialized'] && $pending!==[]) {
                        $upload=$this->buildPatch($collection,$pending); $uploads[$collection]=$upload;
                        $children[]=new BinaryNode('patch',[],SchemaCodec::encode('SyncdPatch',$upload['patch']));
                    }
                    $nodes[]=new BinaryNode('collection',['name'=>$collection,'version'=>(string)$state['version'],'return_snapshot'=>$state['initialized']?'false':'true'],$children===[]?null:$children);
                }
                if ($nodes===[]) { $remaining=[]; break; }
                $requested=array_column(array_map(static fn(BinaryNode $node)=>$node->attrs,$nodes),'name');
                $response=($this->query)(new BinaryNode('iq',['to'=>'s.whatsapp.net','type'=>'set','xmlns'=>'w:sync:app:state'],[new BinaryNode('sync',$this->primaryMode?['data_namespace'=>'3']:[],$nodes)]),$timeout);
                if ($response->tag!=='iq' || ($response->attrs['type']??'')!=='result') throw new \RuntimeException('App-state sync IQ failed');
                $sync=$response->child('sync')??throw new \UnexpectedValueException('App-state response missing sync');
                $payloads=[]; foreach(is_array($sync->content)?$sync->content:[] as $node) if($node->tag==='collection') $payloads[$node->attrs['name']??'']=$node;
                $refetch=[];
                foreach ($requested as $collection) {
                    try {
                        $node=$payloads[$collection]??throw new \UnexpectedValueException('App-state response omitted collection');
                        $error=$node->child('error'); $conflict=($node->attrs['type']??'')==='error' && ($error?->attrs['code']??'')==='409';
                        if (($node->attrs['type']??'')==='error' && !$conflict) throw new \RuntimeException('App-state collection error '.($error?->attrs['code']??'unknown'));
                        $applied=[]; $snapshotMutations=null; $before=$this->collectionState($collection);
                        $state=$before;
                        if (($snapshot=$node->child('snapshot'))!==null && !$this->primaryMode) {
                            $reference=SchemaCodec::decode('ExternalBlobReference',self::bytes($snapshot));
                            $data=$this->download($reference);
                            $decoded=SchemaCodec::decode('SyncdSnapshot',$data);
                            [$state,$mutations]=$this->decodeSnapshot($collection,$decoded); $snapshotMutations=$mutations; $applied=[...$applied,...$mutations];
                        }
                        $patchNodes=$node->child('patches'); $patches=[];
                        foreach(is_array($patchNodes?->content)?$patchNodes->content:[] as $patchNode) if($patchNode->tag==='patch') $patches[]=SchemaCodec::decode('SyncdPatch',self::bytes($patchNode));
                        usort($patches,static fn($a,$b)=>self::integer($a['version']['version']??null,'patch version')<=>self::integer($b['version']['version']??null,'patch version'));
                        foreach($patches as $patch) { [$state,$mutations]=$this->decodePatch($collection,$patch,$state); $applied=[...$applied,...$mutations]; }
                        $version=isset($node->attrs['version'])?self::integer($node->attrs['version'],'collection version'):$state['version'];
                        $accepted=false;
                        if (isset($uploads[$collection]) && !$conflict) {
                            $upload=$uploads[$collection];
                            if ($patches===[] && $version===$upload['state']['version']) { $state=$upload['state']; $applied=[...$applied,...$upload['mutations']]; $accepted=true; }
                            elseif ($state['version']>=$upload['state']['version'] && $state['indexValueMap']===$upload['state']['indexValueMap']) $accepted=true;
                            else throw new \UnexpectedValueException('App-state upload was not acknowledged at the expected version');
                        }
                        if (!$state['initialized']) {
                            if ($version!==0 || $conflict) throw new \UnexpectedValueException('Collection bootstrap requires a snapshot or contiguous patches');
                            $state['initialized']=true;
                        }
                        if ($version!==$state['version']) throw new \UnexpectedValueException('App-state response version does not match authenticated collection state');
                        $this->store->transaction(function() use($collection,$state,$accepted,$uploads,$applied,$snapshotMutations):void {
                            $this->put('collection.'.$collection,$state);
                            if($this->projector!==null) {
                                if($snapshotMutations!==null)$this->projector->replaceSnapshot($collection,$snapshotMutations);
                                foreach($applied as $mutation)$this->projector->apply($mutation);
                            }
                            if ($accepted) $this->removePending($uploads[$collection]['inputs']);
                            $this->removeObservedPending($applied);
                        });
                        foreach($applied as $mutation) $this->event('appstate_mutation',$mutation);
                        $more=($node->attrs['has_more_patches']??'')==='true';
                        $pending=array_filter($this->pendingMutations(),static fn($m)=>$m['collection']===$collection);
                        if ($more || $conflict || $pending!==[]) $refetch[]=$collection;
                        $results[$collection]=['collection'=>$collection,'state'=>$conflict?'conflict':($more?'success_has_more':'success'),'version'=>$state['version'],'mutations'=>$applied];
                    } catch (MissingAppStateKey $error) {
                        $this->event('appstate_missing_keys',['keyIds'=>$error->keyIds,'collections'=>[$collection]]);
                        $results[$collection]=['collection'=>$collection,'state'=>'blocked','error'=>$error->getMessage()];
                    } catch (\Throwable $error) {
                        $results[$collection]=['collection'=>$collection,'state'=>'error_retry','error'=>$error->getMessage()];
                        $this->event('appstate_error',['collection'=>$collection,'error'=>$error->getMessage()]);
                    }
                }
                $remaining=$refetch;
            }
            foreach($remaining as $collection) $results[$collection]['state']='error_retry';
            $this->syncRequested=false;
            foreach ($results as $result) if (in_array($result['state'], ['error_retry','conflict','success_has_more'],true)) $this->syncRequested=true;
            return ['collections'=>array_values($results)];
        } finally { $this->syncing=false; }
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return list<array<string,mixed>> */
    public function applySnapshot(string $collection,array $snapshot): array
    {
        self::collection($collection); [$state,$mutations]=$this->decodeSnapshot($collection,$snapshot);
        $this->store->transaction(function()use($collection,$state,$mutations):void {
            $this->put('collection.'.$collection,$state);
            if($this->projector!==null){$this->projector->replaceSnapshot($collection,$mutations);foreach($mutations as $mutation)$this->projector->apply($mutation);}
        });
        foreach($mutations as $mutation) $this->event('appstate_mutation',$mutation);
        return $mutations;
    }
    /**
     * @param array<string,mixed> $patch
     * @return list<array<string,mixed>> */
    public function applyPatch(string $collection,array $patch): array
    {
        self::collection($collection); [$state,$mutations]=$this->decodePatch($collection,$patch,$this->collectionState($collection));
        $this->store->transaction(function()use($collection,$state,$mutations):void {
            $this->put('collection.'.$collection,$state);
            if($this->projector!==null)foreach($mutations as $mutation)$this->projector->apply($mutation);
        });
        foreach($mutations as $mutation) $this->event('appstate_mutation',$mutation);
        return $mutations;
    }

    /**
     * @param list<array<string,mixed>> $inputs
     * @return array<string,mixed> */
    public function buildPatch(string $collection,array $inputs): array
    {
        $state=$this->collectionState($collection);
        if (!$state['initialized']) throw new \LogicException('Synchronize collection before uploading mutations');
        $key=$this->activeKey()??throw new MissingAppStateKey('');
        $records=[]; $mutations=[]; $macs=[];
        foreach($inputs as $input) {
            if (($input['collection']??null)!==$collection) throw new \InvalidArgumentException('Mutation belongs to another collection');
            $operation=$input['operation']==='remove'?1:0;
            $encrypted=AppStateCrypto::encryptMutation($operation,$key['keyId'],$key['keyData'],$input['index'],$input['value']??null,$input['version']);
            $records[]=['operation'=>$operation,'record'=>['keyId'=>['id'=>$key['keyId']],'index'=>['blob'=>$encrypted['indexMac']],'value'=>['blob'=>$encrypted['valueBlob']]]];
            $mutation=$input+['source'=>'local','indexMac'=>$encrypted['indexMac'],'valueMac'=>$encrypted['valueMac'],'keyId'=>$key['keyId']];
            $mutations[]=$mutation; $macs[]=$encrypted['valueMac'];
            $state=self::advance($state,$operation,$encrypted['indexMac'],$encrypted['valueMac']);
        }
        if ($state['version']===PHP_INT_MAX) throw new \OverflowException('App-state version exhausted');
        ++$state['version'];
        $snapshot=AppStateCrypto::snapshotMac($key['keyData'],$state['hash'],$state['version'],$collection);
        $patch=['mutations'=>$records,'snapshotMac'=>$snapshot,'patchMac'=>AppStateCrypto::patchMac($key['keyData'],$snapshot,$macs,$state['version'],$collection),'keyId'=>['id'=>$key['keyId']]];
        return ['patch'=>$patch,'state'=>$state,'mutations'=>$mutations,'inputs'=>$inputs];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array{array<string,mixed>,list<array<string,mixed>>} */
    private function decodeSnapshot(string $collection,array $snapshot): array
    {
        $version=self::integer($snapshot['version']['version']??null,'snapshot version');
        $records=$snapshot['records']??[];
        if(!is_array($records)) throw new \UnexpectedValueException('Invalid app-state snapshot records');
        $this->requireAvailableKeys($records,$snapshot['keyId']['id']??null);
        $state=['initialized'=>true,'version'=>$version,'hash'=>str_repeat("\0",128),'indexValueMap'=>[]]; $mutations=[];
        foreach($records as $record) {
            $mutation=$this->decryptRecord($collection,0,$record,'snapshot');
            $state=self::advance($state,0,$mutation['indexMac'],$mutation['valueMac']); $mutations[]=$mutation;
        }
        $key=$this->requireKey($snapshot['keyId']['id']??null);
        self::mac(AppStateCrypto::snapshotMac($key,$state['hash'],$version,$collection),$snapshot['mac']??null,'snapshot');
        return [$state,$mutations];
    }
    /**
     * @param array<string,mixed> $patch
     * @param array<string,mixed> $state
     * @return array{array<string,mixed>,list<array<string,mixed>>} */
    private function decodePatch(string $collection,array $patch,array $state): array
    {
        $version=self::integer($patch['version']['version']??null,'patch version');
        if ($version!==$state['version']+1) throw new \UnexpectedValueException('Noncontiguous app-state patch version');
        if (($patch['exitCode']['code']??0)!==0) throw new \UnexpectedValueException('Terminal app-state patch exit code');
        if (isset($patch['externalMutations'])) {
            if (($patch['mutations']??[])!==[]) throw new \UnexpectedValueException('Patch has inline and external mutations');
            $decoded=SchemaCodec::decode('SyncdMutations',$this->download($patch['externalMutations']));
            $patch['mutations']=$decoded['mutations']??[];
        }
        $entries=$patch['mutations']??[];
        if(!is_array($entries)) throw new \UnexpectedValueException('Invalid app-state patch records');
        $this->requireAvailableKeys($entries,$patch['keyId']['id']??null,true);
        $macs=[]; $mutations=[];
        foreach($entries as $entry) {
            $operation=$entry['operation']??null;
            if (!is_int($operation) || !in_array($operation,[0,1],true) || !is_array($entry['record']??null)) throw new \UnexpectedValueException('Invalid app-state patch record');
            $mutation=$this->decryptRecord($collection,$operation,$entry['record'],'patch');
            $state=self::advance($state,$operation,$mutation['indexMac'],$mutation['valueMac']); $macs[]=$mutation['valueMac']; $mutations[]=$mutation;
        }
        $key=$this->requireKey($patch['keyId']['id']??null);
        self::mac(AppStateCrypto::patchMac($key,self::requiredBytes($patch['snapshotMac']??null),$macs,$version,$collection),$patch['patchMac']??null,'patch');
        self::mac(AppStateCrypto::snapshotMac($key,$state['hash'],$version,$collection),$patch['snapshotMac']??null,'patch snapshot');
        $state['version']=$version; $state['initialized']=true;
        return [$state,$mutations];
    }
    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed> */
    private function decryptRecord(string $collection,int $operation,array $record,string $source): array
    {
        $id=self::requiredBytes($record['keyId']['id']??null);
        $decoded=AppStateCrypto::decryptMutation($operation,$id,$this->requireKey($id),self::requiredBytes($record['index']['blob']??null),self::requiredBytes($record['value']['blob']??null));
        return $decoded+['collection'=>$collection,'operation'=>$operation===0?'set':'remove','source'=>$source,'keyId'=>$id,'timestamp'=>$decoded['value']['timestamp']??0];
    }
    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed> */
    private static function advance(array $state,int $operation,string $indexMac,string $valueMac): array
    {
        $hex=bin2hex($indexMac); $existing=$state['indexValueMap'][$hex]??null;
        if ($operation===1) {
            if ($existing!==null) { unset($state['indexValueMap'][$hex]); $state['hash']=AppStateCrypto::ltHash($state['hash'],[],[$existing]); }
        } else {
            $state['hash']=AppStateCrypto::ltHash($state['hash'],[$valueMac],$existing===null?[]:[$existing]);
            $state['indexValueMap'][$hex]=$valueMac;
            if (count($state['indexValueMap'])>100000) throw new \LengthException('App-state collection exceeds 100000 entries');
        }
        return $state;
    }

    public function setChatMute(string $jid,bool $muted,?int $endTimestampMs=null): void
    {
        if ($muted && $endTimestampMs===null) throw new \InvalidArgumentException('Muted chat requires an absolute end timestamp in milliseconds');
        if ($endTimestampMs!==null && $endTimestampMs<0) throw new \InvalidArgumentException('Invalid mute end timestamp');
        $this->mutate('Mute',['chatJid'=>$jid],array_filter(['muted'=>$muted,'muteEndTimestamp'=>$endTimestampMs],static fn($v)=>$v!==null));
    }
    public function setChatRead(string $jid,bool $read): void { $this->mutate('MarkChatAsRead',['chatJid'=>$jid],['read'=>$read,'messageRange'=>$this->range($jid)]); }
    public function setChatPin(string $jid,bool $pinned): void
    {
        $this->mutate('Pin',['chatJid'=>$jid],['pinned'=>$pinned],false,false);
        if ($pinned) $this->mutate('Archive',['chatJid'=>$jid],['archived'=>false,'messageRange'=>$this->range($jid)],false,false);
        $this->flushMutations();
    }
    public function setChatArchive(string $jid,bool $archived): void
    {
        $this->mutate('Archive',['chatJid'=>$jid],['archived'=>$archived,'messageRange'=>$this->range($jid)],false,false);
        if ($archived) $this->mutate('Pin',['chatJid'=>$jid],['pinned'=>false],false,false);
        $this->flushMutations();
    }
    public function setChatLock(string $jid,bool $locked): void
    {
        if ($locked) { $this->mutate('Archive',['chatJid'=>$jid],['archived'=>false,'messageRange'=>$this->range($jid)],false,false); $this->mutate('Pin',['chatJid'=>$jid],['pinned'=>false],false,false); }
        $this->mutate('LockChat',['chatJid'=>$jid],['locked'=>$locked]);
    }
    /**
     * @param array<string,mixed> $key */
    public function setMessageStar(array $key,bool $starred): void { $this->mutate('Star',self::messageIndex($key),['starred'=>$starred]); }
    /**
     * @param array<string,mixed> $key */
    public function deleteMessageForMe(array $key,bool $deleteMedia=false,?int $timestampMs=null): void
    {
        if ($timestampMs!==null && $timestampMs<0) throw new \InvalidArgumentException('Invalid message timestamp');
        $this->mutate('DeleteMessageForMe',self::messageIndex($key),array_filter(['deleteMedia'=>$deleteMedia,'messageTimestamp'=>$timestampMs===null?null:intdiv($timestampMs,1000)],static fn($v)=>$v!==null));
    }
    public function clearChat(string $jid,bool $deleteStarred=false,bool $deleteMedia=false): void { $this->mutate('ClearChat',['chatJid'=>$jid,'deleteStarred'=>$deleteStarred?'1':'0','deleteMedia'=>$deleteMedia?'1':'0'],['messageRange'=>$this->range($jid)]); }
    public function deleteChat(string $jid,bool $deleteMedia=false): void { $this->mutate('DeleteChat',['chatJid'=>$jid,'deleteMedia'=>$deleteMedia?'1':'0'],['messageRange'=>$this->range($jid)]); }
    /**
     * @param array<string,mixed> $contact */
    public function setContact(string $jid,array $contact): void { $this->mutate('Contact',['id'=>self::userJid($jid)],$contact); }
    /**
     * @param array<string,mixed> $label */
    public function editLabel(string $id,array $label): void { $this->mutate('LabelEdit',['id'=>$id],$label); }
    public function setChatLabel(string $jid,string $labelId,bool $associated): void { $this->mutate('LabelJid',['chatJid'=>$jid,'labelId'=>$labelId],['labeled'=>$associated]); }
    public function setMessageLabel(string $jid,string $messageId,string $labelId,bool $associated): void { $this->mutate('LabelMessage',['chatJid'=>$jid,'messageId'=>$messageId,'labelId'=>$labelId],['labeled'=>$associated]); }
    /** @param array<string,mixed> $reply */
    public function setQuickReply(string $id,array $reply): void { $this->mutate('QuickReply',['id'=>$id],$reply); }
    public function setPushName(string $name): void
    {
        $this->mutate('SettingPushName',[],['name'=>$name]);
    }
    /** @param array<string,mixed> $input */
    public function setStatusPrivacy(array $input): void
    {
        $mode=$input['mode']??null;
        if(is_string($mode))$mode=match(strtolower($mode)){'allowlist'=>'ALLOW_LIST','denylist','contacts_except'=>'DENY_LIST',default=>strtoupper($mode)};
        if((!is_int($mode) && !is_string($mode)) || (is_int($mode) && ($mode<0 || $mode>4)))throw new \InvalidArgumentException('Status privacy requires a valid mode');
        if(is_string($mode) && !in_array($mode,['ALLOW_LIST','DENY_LIST','CONTACTS','CLOSE_FRIENDS','CUSTOM_LIST'],true))throw new \InvalidArgumentException('Unknown status privacy mode');
        $users=$input['userJids']??[];
        if(!is_array($users) || !array_is_list($users)) throw new \InvalidArgumentException('Status userJids must be a list');
        $normalized=[];
        foreach($users as $user) {
            if(!is_string($user)) throw new \InvalidArgumentException('Invalid status audience JID');
            $normalized[]=self::userJid($user);
        }
        $this->mutate('StatusPrivacy',[],array_filter(['mode'=>$mode,'userJid'=>$normalized,'shareToFb'=>$input['shareToFB']??$input['shareToFb']??null,'shareToIg'=>$input['shareToIG']??$input['shareToIg']??null],static fn($value)=>$value!==null));
    }
    public function setUserStatusMute(string $jid,bool $muted): void { $this->mutate('UserStatusMute',['id'=>self::userJid($jid)],['muted'=>$muted]); }
    /** @param array<string,mixed> $input */
    public function setBroadcastList(array $input): void
    {
        if(!is_string($input['id']??null) || $input['id']==='' || !is_string($input['listName']??null) || !is_array($input['participants']??null)) throw new \InvalidArgumentException('Broadcast list requires id, listName and participants');
        $participants=[];
        foreach($input['participants'] as $participant) {
            if(!is_array($participant) || !is_string($participant['lidJid']??null)) throw new \InvalidArgumentException('Broadcast participant requires lidJid');
            $lid=self::userJid($participant['lidJid']);
            if(!str_ends_with($lid,'@lid'))throw new \InvalidArgumentException('Broadcast lidJid must use the LID server');
            $record=['lidJid'=>$lid];
            if(isset($participant['pnJid'])) {
                $pn=self::userJid($participant['pnJid']);
                if(!str_ends_with($pn,'@s.whatsapp.net'))throw new \InvalidArgumentException('Broadcast pnJid must use the phone-number server');
                $record['pnJid']=$pn;
            }
            $participants[]=$record;
        }
        $this->mutate('BusinessBroadcastList',['id'=>$input['id']],['participants'=>$participants,'listName'=>$input['listName'],'labelIds'=>$input['labelIds']??[]]);
    }
    public function removeBroadcastList(string $id): void { $this->mutate('BusinessBroadcastList',['id'=>$id],null,true); }

    /**
     * @return array<string,mixed> */
    private function range(string $jid): array { return $this->messageRange===null?['messages'=>[]]:($this->messageRange)(Jid::recipient($jid)->canonical()->bare()); }
    /**
     * @param array<string,mixed> $key
     * @return array<string,mixed> */
    private static function messageIndex(array $key): array
    {
        $jid=$key['chatJid']??$key['remoteJid']??null; $id=$key['id']??null;
        if (!is_string($jid) || !is_string($id) || trim($id)==='') throw new \InvalidArgumentException('Message mutation requires chatJid and id');
        $jid=Jid::recipient($jid)->canonical()->bare(); $fromMe=($key['fromMe']??false)===true; $participant='0';
        if (!$fromMe && in_array(Jid::parse($jid)->server,['g.us','broadcast'],true)) {
            $participant=$key['participantJid']??$key['participant']??null;
            if (!is_string($participant) || $participant==='') throw new \InvalidArgumentException('Incoming group message mutation requires participant');
            $participant=(string)Jid::recipient($participant)->canonical();
            if(!in_array(Jid::parse($participant)->server,['s.whatsapp.net','lid'],true))throw new \InvalidArgumentException('Message participant requires a user JID');
        }
        return ['remote'=>$jid,'id'=>trim($id),'fromMe'=>$fromMe,'participant'=>$participant];
    }
    /**
     * @param list<array<string,mixed>> $inputs */
    private function removePending(array $inputs): void
    {
        $accepted=[]; foreach($inputs as $input) $accepted[$input['collection']."\0".$input['index']]=BinaryData::encode($input);
        $remaining=[];
        foreach($this->pendingMutations() as $pending) if(($accepted[$pending['collection']."\0".$pending['index']]??null)!==BinaryData::encode($pending)) $remaining[]=$pending;
        $this->put('pending',$remaining);
    }
    /** Reconciles a server patch accepted before a previous connection lost its acknowledgment.
     * @param list<array<string,mixed>> $observed */
    private function removeObservedPending(array $observed): void
    {
        $accepted=[];
        foreach($this->pendingMutations() as $pending) foreach($observed as $mutation) {
            if ($pending['collection']!==$mutation['collection'] || $pending['index']!==$mutation['index'] || $pending['operation']!==$mutation['operation'] || $pending['version']!==$mutation['version']) continue;
            $pendingValue=$pending['value']??null; $observedValue=$mutation['value']??null;
            if (($pendingValue===null && $observedValue===null) || (is_array($pendingValue) && is_array($observedValue) && SchemaCodec::encode('SyncActionValue',$pendingValue)===SchemaCodec::encode('SyncActionValue',$observedValue))) { $accepted[]=$pending; break; }
        }
        if($accepted!==[]) $this->removePending($accepted);
    }
    private function requireKey(mixed $id): string { $id=self::requiredBytes($id); return ($this->getKey($id)??throw new MissingAppStateKey($id))['keyData']; }
    /**
     * Check the complete payload before attempting any mutation cryptography, so
     * a snapshot containing old key epochs requests them in one round trip.
     * @param array<mixed> $records
     */
    private function requireAvailableKeys(array $records,mixed $macKeyId,bool $patch=false): void
    {
        $seen=[]; $missing=[];
        foreach($records as $record) {
            if(!is_array($record)) throw new \UnexpectedValueException('Invalid app-state record');
            if($patch) {
                $operation=$record['operation']??null;
                if(!is_int($operation) || !in_array($operation,[0,1],true) || !is_array($record['record']??null)) throw new \UnexpectedValueException('Invalid app-state patch record');
                $record=$record['record'];
            }
            $id=self::requiredBytes($record['keyId']['id']??null); $hex=bin2hex($id);
            if(isset($seen[$hex])) continue;
            $seen[$hex]=true;
            if($this->getKey($id)===null) $missing[]=$id;
        }
        $id=self::requiredBytes($macKeyId);
        if(!isset($seen[bin2hex($id)]) && $this->getKey($id)===null) $missing[]=$id;
        if($missing!==[]) throw new MissingAppStateKey($missing[0],$missing);
    }
    private static function userJid(string $jid): string
    {
        $parsed=Jid::recipient($jid)->canonical();
        if(!in_array($parsed->server,['s.whatsapp.net','lid'],true)) throw new \InvalidArgumentException('App-state action requires a user JID');
        return $parsed->bare();
    }
    private static function collection(string $name): void { if(!in_array($name,self::COLLECTIONS,true)) throw new \InvalidArgumentException('Invalid app-state collection '.$name); }
    private static function requiredBytes(mixed $bytes): string { if(!is_string($bytes) || $bytes==='') throw new \UnexpectedValueException('Missing app-state binary field'); return $bytes; }
    private static function mac(string $expected,mixed $actual,string $kind): void { if(!is_string($actual) || !hash_equals($expected,$actual)) throw new \UnexpectedValueException('App-state '.$kind.' MAC mismatch'); }
    private static function bytes(BinaryNode $node): string { if(!is_string($node->content)) throw new \UnexpectedValueException('App-state node requires binary content'); return $node->content; }
    private static function integer(mixed $value,string $name): int
    {
        if(is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D',$value)) $value=filter_var($value,FILTER_VALIDATE_INT);
        if(!is_int($value) || $value<0) throw new \UnexpectedValueException('Invalid app-state '.$name);
        return $value;
    }
    /**
     * @param array<string,mixed> $reference */
    private function download(array $reference): string
    {
        if($this->download===null) throw new \LogicException('App-state external snapshot/patch requires a media downloader');
        if(isset($reference['fileSizeBytes'])) $reference['fileLength']=self::integer($reference['fileSizeBytes'],'external blob size');
        return ($this->download)(['mediaType'=>'md-app-state',...$reference]);
    }
    /**
     * @param array<mixed> $default
     * @return array<mixed> */
    private function record(string $name,array $default): array { $value=$this->store->get('appstate',$name); if($value===null)return $default; $decoded=BinaryData::decode($value); if(!is_array($decoded))throw new \UnexpectedValueException('Invalid app-state record'); return $decoded; }
    /**
     * @param array<mixed> $value */
    private function put(string $name,array $value): void { $this->store->set('appstate',$name,BinaryData::encode($value)); }
    /**
     * @param array<string,mixed> $value */
    private function event(string $name,array $value): void { if($this->emit!==null) ($this->emit)($name,$value); }
}

