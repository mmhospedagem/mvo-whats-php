<?php
declare(strict_types=1);
namespace MVO\Testing;

use MVO\AppState\{AppStateCrypto,AppStateSchema,AppStateService};
use MVO\Protocol\{BinaryData,BinaryNode,SchemaCodec};
use MVO\Service\ServiceTransport;
use MVO\Signal\SignalStore;

/** Cryptographically verified app-state authority for test accounts, using the production MAC/LT-hash implementation. */
final class FakeAppState
{
    private readonly SignalStore $store;
    private readonly AppStateService $service;
    /** @var array<string,mixed> */
    private array $key;
    /** @param array{keyId:string,keyData:string,timestamp?:int}|null $key */
    public function __construct(?SignalStore $store=null,?array $key=null,private readonly ?FakeMediaServer $media=null)
    {
        $this->store=$store ?? new SignalStore(':memory:','fake-appstate');$saved=$this->store->get('fake-appstate','key');$this->key=$saved===null ? ($key ?? ['keyId'=>random_bytes(6),'keyData'=>random_bytes(32),'timestamp'=>(int)floor(microtime(true)*1000)]) : BinaryData::decode($saved);$this->store->set('fake-appstate','key',BinaryData::encode($this->key));
        $this->service=new AppStateService($this->store,static fn(BinaryNode $n,float $t):BinaryNode=>throw new \LogicException('Fake app-state authority does not issue network requests'));$this->service->importKeys([$this->key]);
        foreach(AppStateService::COLLECTIONS as $collection)if(!$this->service->collectionState($collection)['initialized'])$this->service->applySnapshot($collection,['version'=>['version'=>0],'records'=>[],'keyId'=>['id'=>$this->key['keyId']],'mac'=>AppStateCrypto::snapshotMac($this->key['keyData'],str_repeat("\0",128),0,$collection)]);
    }
    /** @return list<array<string,mixed>> Peer protocol keyshare; only expose inside explicit test setup. */
    public function keyShare():array {return [['keyId'=>['keyId'=>$this->key['keyId']],'keyData'=>['keyData'=>$this->key['keyData'],'timestamp'=>$this->key['timestamp'] ?? 0,'fingerprint'=>['rawId'=>1,'currentIndex'=>1,'deviceIndexes'=>[0,1]]]]];}
    /** @return array<string,mixed> */
    public function key():array {return $this->key;}
    public function service():AppStateService {return $this->service;}
    /** @param array<string,mixed> $args
     * @param array<string,mixed>|null $data */
    public function appendMutation(string $schema,array $args,?array $data,bool $remove=false):void
    {
        $input=AppStateSchema::mutation($schema,$args,$data,(int)floor(microtime(true)*1000),$remove);$collection=$input['collection'];$built=$this->service->buildPatch($collection,[$input]);$patch=$built['patch'];$patch['version']=['version'=>$built['state']['version']];$this->accept($collection,$patch);
    }
    /** @return array<string,mixed> */
    public function snapshot(string $collection):array
    {
        $state=$this->service->collectionState($collection);$records=$this->records($collection);return ['version'=>['version'=>$state['version']],'records'=>array_values($records),'keyId'=>['id'=>$this->key['keyId']],'mac'=>AppStateCrypto::snapshotMac($this->key['keyData'],$state['hash'],$state['version'],$collection)];
    }
    public function handle(BinaryNode $iq):BinaryNode
    {
        $requests=ServiceTransport::children($iq->child('sync'),'collection');$seen=[];foreach($requests as $request){$name=$request->attrs['name'] ?? '';if(!in_array($name,AppStateService::COLLECTIONS,true)||isset($seen[$name]))return FakeProtocol::error($iq,400,'fake-unknown-or-duplicate-appstate-collection');$seen[$name]=true;$raw=$request->attrs['version'] ?? '0';if(!ctype_digit($raw)||strlen($raw)>15)return FakeProtocol::error($iq,400,'fake-invalid-appstate-version');}
        return $this->store->transaction(function()use($iq,$requests):BinaryNode{$responses=[];foreach($requests as $request){$name=$request->attrs['name'];$base=(int)($request->attrs['version'] ?? '0');$version=$this->service->collectionState($name)['version'];$submitted=$request->child('patch');$accepted=false;$conflict=$submitted!==null && $base!==$version;
                if($submitted!==null&&!$conflict){if(!is_string($submitted->content))throw new \UnexpectedValueException('Fake patch body missing');$patch=SchemaCodec::decode('SyncdPatch',$submitted->content);$patch['version']=['version'=>$version+1];$this->accept($name,$patch);$version++;$accepted=true;}
                $children=[];$attrs=['name'=>$name,'version'=>(string)$version,'has_more_patches'=>'false'];
                if($conflict){$attrs['type']='error';$children[]=new BinaryNode('error',['code'=>'409','text'=>'fake-appstate-version-conflict']);}
                if(!$accepted&&($request->attrs['return_snapshot'] ?? '')==='true'&&$version>0&&$this->media!==null){$descriptor=$this->media->publish(SchemaCodec::encode('SyncdSnapshot',$this->snapshot($name)),'md-app-state');$descriptor['fileSizeBytes']=$descriptor['fileLength'];unset($descriptor['url'],$descriptor['mediaType'],$descriptor['fileLength']);$children[]=new BinaryNode('snapshot',[],SchemaCodec::encode('ExternalBlobReference',$descriptor));}
                elseif(!$accepted){$patches=[];foreach($this->history($name) as $number=>$bytes)if((int)$number>$base)$patches[]=new BinaryNode('patch',[],$bytes);if($patches!==[])$children[]=new BinaryNode('patches',[],$patches);}
                $responses[]=new BinaryNode('collection',$attrs,$children);
            }return FakeProtocol::result($iq,[new BinaryNode('sync',[],$responses)]);});
    }
    /** @param array<string,mixed> $patch */
    private function accept(string $collection,array $patch):void
    {
        $this->store->transaction(function()use($collection,$patch):void{$this->service->applyPatch($collection,$patch);$records=$this->records($collection);foreach($patch['mutations'] ?? [] as $mutation){$record=$mutation['record'];$index=bin2hex($record['index']['blob']);if(($mutation['operation'] ?? 0)===1)unset($records[$index]);else $records[$index]=$record;}$history=$this->history($collection);if(count($history)>=1000)throw new \LengthException('Fake app-state patch history limit');$version=$this->service->collectionState($collection)['version'];$history[$version]=SchemaCodec::encode('SyncdPatch',$patch);$this->store->set('fake-appstate-records',$collection,BinaryData::encode($records));$this->store->set('fake-appstate-history',$collection,BinaryData::encode($history));});
    }
    /** @return array<string,array<string,mixed>> */
    private function records(string $collection):array {$bytes=$this->store->get('fake-appstate-records',$collection);return $bytes===null ? [] : BinaryData::decode($bytes);}
    /** @return array<int,string> */
    private function history(string $collection):array {$bytes=$this->store->get('fake-appstate-history',$collection);return $bytes===null ? [] : BinaryData::decode($bytes);}
    public function close():void {$this->store->close();}
}
