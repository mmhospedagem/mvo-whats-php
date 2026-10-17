<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\AppState\{AppStateCrypto,AppStateSchema,AppStateService,MissingAppStateKey};
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Signal\SignalStore;

final class AppStateServiceTest extends TestCase
{
    private array $stores=[];
    protected function tearDown(): void { foreach($this->stores as $store)$store->close(); }
    private function store(): SignalStore { $store=new SignalStore(':memory:'); $this->stores[]=$store; return $store; }
    private function service(?callable $query=null,?callable $emit=null,?callable $download=null): AppStateService
    {
        return new AppStateService($this->store(),$query??static fn()=>throw new \LogicException('Unexpected query'),$emit,$download);
    }
    private function keyed(?callable $query=null,?callable $emit=null,?callable $download=null): AppStateService
    {
        $service=$this->service($query,$emit,$download);
        $service->importKeys([['keyId'=>hex2bin('000100000001'),'keyData'=>str_repeat('k',32),'timestamp'=>1]]);
        return $service;
    }
    private static function response(string $name,int $version,array $patches=[],array $attrs=[]): BinaryNode
    {
        $children=[];
        if($patches!==[])$children[]=new BinaryNode('patches',[],array_map(static fn($patch)=>new BinaryNode('patch',[],SchemaCodec::encode('SyncdPatch',$patch)),$patches));
        return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('sync',[],[new BinaryNode('collection',['name'=>$name,'version'=>(string)$version,...$attrs],$children)])]);
    }
    private static function snapshot(AppStateService $service,array $mutations=[],int $version=1): array
    {
        $key=$service->activeKey(); $records=[]; $hash=str_repeat("\0",128);
        foreach($mutations as $input) {
            $e=AppStateCrypto::encryptMutation(0,$key['keyId'],$key['keyData'],$input['index'],$input['value'],$input['version']);
            $records[]=['keyId'=>['id'=>$key['keyId']],'index'=>['blob'=>$e['indexMac']],'value'=>['blob'=>$e['valueBlob']]];
            $hash=AppStateCrypto::ltHash($hash,[$e['valueMac']]);
        }
        return ['version'=>['version'=>$version],'records'=>$records,'keyId'=>['id'=>$key['keyId']],'mac'=>AppStateCrypto::snapshotMac($key['keyData'],$hash,$version,'regular_low')];
    }
    public function testKeyShareImportsOnlyAuthenticatedOwnDeviceAndDoesNotQuery(): void
    {
        $events=[]; $service=$this->service(null,function($name,$data)use(&$events){$events[]=$name;});
        $message=['protocolMessage'=>['type'=>6,'appStateSyncKeyShare'=>['keys'=>[['keyId'=>['keyId'=>'KEY'],'keyData'=>['keyData'=>str_repeat('k',32),'timestamp'=>123]]]]]];
        try { $service->handleProtocol($message,['from'=>'other@s.whatsapp.net']); self::fail('Accepted foreign share'); }
        catch(\UnexpectedValueException $e){self::assertStringContainsString('own-account',$e->getMessage());}
        self::assertNull($service->getKey('KEY'));
        self::assertTrue($service->handleProtocol($message,['from'=>'me:1@s.whatsapp.net','authenticatedOwnDevice'=>true]));
        self::assertSame(str_repeat('k',32),$service->getKey('KEY')['keyData']); self::assertTrue($service->needsSync()); self::assertSame(['appstate_keys'],$events);
    }
    public function testOwnDevicePredicateMustApproveEvenWhenContextIsFromMe(): void
    {
        $service=new AppStateService($this->store(),static fn()=>throw new \LogicException(),null,null,static fn($sender)=>$sender==='me:1@lid');
        $this->expectException(\UnexpectedValueException::class);
        $service->handleProtocol(['appStateSyncKeyShare'=>['keys'=>[]]],['from'=>'other:1@lid','fromMe'=>true]);
    }
    public function testKeyInventoryUsesHighestEpochLowerDeviceAndCannotReplaceKeyData(): void
    {
        $service=$this->service();
        $service->importKeys([['keyId'=>hex2bin('000200000002'),'keyData'=>str_repeat('b',32)],['keyId'=>hex2bin('000100000002'),'keyData'=>str_repeat('a',32)],['keyId'=>hex2bin('000000000001'),'keyData'=>str_repeat('c',32)]]);
        self::assertSame(str_repeat('a',32),$service->activeKey()['keyData']);
        self::assertSame(0,$service->importKeys([['keyId'=>hex2bin('000100000002'),'keyData'=>str_repeat('a',32)]]));
        $this->expectException(\UnexpectedValueException::class);
        $service->importKeys([['keyId'=>hex2bin('000100000002'),'keyData'=>str_repeat('z',32)]]);
    }
    public function testInvalidBatchKeyImportRollsBackAllKeys(): void
    {
        $service=$this->service();
        try{$service->importKeys([['keyId'=>'GOOD','keyData'=>str_repeat('k',32)],['keyId'=>'BAD','keyData'=>'short']]);self::fail();}catch(\InvalidArgumentException){}
        self::assertNull($service->getKey('GOOD'));self::assertNull($service->activeKey());
    }
    public function testVerifiedSnapshotThenReplacementAndRemovalPreserveHashState(): void
    {
        $service=$this->keyed(); $first=AppStateSchema::mutation('Archive',['chatJid'=>'123@s.whatsapp.net'],['archived'=>true],1);
        $snapshot=self::snapshot($service,[$first]);
        self::assertCount(1,$service->applySnapshot('regular_low',$snapshot));
        $replacement=AppStateSchema::mutation('Archive',['chatJid'=>'123@s.whatsapp.net'],['archived'=>false],2);
        $built=$service->buildPatch('regular_low',[$replacement]);$patch=$built['patch']+['version'=>['version'=>2]];
        $service->applyPatch('regular_low',$patch);
        self::assertSame($built['state'],$service->collectionState('regular_low'));self::assertCount(1,$built['state']['indexValueMap']);
        $remove=AppStateSchema::mutation('Archive',['chatJid'=>'123@s.whatsapp.net'],null,3,true);$built=$service->buildPatch('regular_low',[$remove]);
        $service->applyPatch('regular_low',$built['patch']+['version'=>['version'=>3]]);
        self::assertSame(str_repeat("\0",128),$service->collectionState('regular_low')['hash']);self::assertSame([],$service->collectionState('regular_low')['indexValueMap']);
    }
    public function testSnapshotMacFailureDoesNotPersistPartialState(): void
    {
        $service=$this->keyed();$snapshot=self::snapshot($service);$snapshot['mac'][0]=chr(ord($snapshot['mac'][0])^1);
        try{$service->applySnapshot('regular_low',$snapshot);self::fail();}catch(\UnexpectedValueException $e){self::assertStringContainsString('snapshot MAC mismatch',$e->getMessage());}
        self::assertFalse($service->collectionState('regular_low')['initialized']);
    }
    public function testPatchMacFailureDoesNotAdvanceVersion(): void
    {
        $service=$this->keyed();$service->applySnapshot('regular_low',self::snapshot($service));
        $before=$service->collectionState('regular_low');
        $input=AppStateSchema::mutation('Pin',['chatJid'=>'123'],['pinned'=>true],3);
        $patch=$service->buildPatch('regular_low',[$input])['patch']+['version'=>['version'=>2]];
        $validMac=$patch['patchMac'];
        // Flip a bit: replacing a random byte with a constant can leave the MAC unchanged.
        $patch['patchMac'][0]=chr(ord($patch['patchMac'][0])^1);
        self::assertNotSame($validMac,$patch['patchMac']);
        try{$service->applyPatch('regular_low',$patch);self::fail('Accepted a patch with an invalid MAC');}
        catch(\UnexpectedValueException $error){self::assertStringContainsString('patch MAC mismatch',$error->getMessage());}
        self::assertSame($before,$service->collectionState('regular_low'));
    }
    public function testPatchRejectsSkippedVersionBeforeApplyingAnyRecord(): void
    {
        $service=$this->keyed();$service->applySnapshot('regular_low',self::snapshot($service));
        $this->expectException(\UnexpectedValueException::class);$service->applyPatch('regular_low',['version'=>['version'=>4]]);
    }
    public function testDurablePendingCompactsIndexAndSurvivesServiceRecreation(): void
    {
        $store=$this->store();$service=new AppStateService($store,static fn()=>throw new \RuntimeException('offline'));
        $service->mutate('Pin',['chatJid'=>'123'],['pinned'=>true],false,false);$service->mutate('Pin',['chatJid'=>'123'],['pinned'=>false],false,false);
        $restored=new AppStateService($store,static fn()=>throw new \RuntimeException('offline'));
        self::assertCount(1,$restored->pendingMutations());self::assertFalse($restored->pendingMutations()[0]['value']['pinAction']['pinned']);
    }
    public function testBootstrapThenUploadRequiresAcknowledgmentBeforeRemovingPending(): void
    {
        $calls=[];$service=$this->keyed(function(BinaryNode $request,float $timeout)use(&$calls){
            $collection=$request->child('sync')->content[0];$calls[]=$collection;self::assertSame(30.0,$timeout);
            if(count($calls)===1){self::assertSame('true',$collection->attrs['return_snapshot']);self::assertNull($collection->child('patch'));return self::response('regular_low',0);}
            self::assertNotNull($collection->child('patch'));$patch=SchemaCodec::decode('SyncdPatch',$collection->child('patch')->content);self::assertArrayNotHasKey('version',$patch);
            return self::response('regular_low',1);
        });
        $service->mutate('Pin',['chatJid'=>'123'],['pinned'=>true]);
        self::assertCount(2,$calls);self::assertSame([],$service->pendingMutations());self::assertSame(1,$service->collectionState('regular_low')['version']);
    }
    public function testLostUploadAcknowledgmentIsReconciledFromAuthenticatedServerPatch(): void
    {
        $calls=0;$serverPatch=null;$lost=true;
        $service=$this->keyed(function(BinaryNode $request)use(&$calls,&$serverPatch,&$lost){
            ++$calls;$node=$request->child('sync')->content[0];
            if($calls===1)return self::response('regular_low',0);
            if($lost){$serverPatch=SchemaCodec::decode('SyncdPatch',$node->child('patch')->content)+['version'=>['version'=>1]];$lost=false;throw new \RuntimeException('response lost');}
            self::assertNull($node->child('patch'));return self::response('regular_low',1,[$serverPatch]);
        });
        try{$service->mutate('Pin',['chatJid'=>'123'],['pinned'=>true]);self::fail();}catch(\RuntimeException $e){self::assertSame('response lost',$e->getMessage());}
        self::assertCount(1,$service->pendingMutations());self::assertSame(0,$service->collectionState('regular_low')['version']);
        $service->flushMutations();self::assertSame(3,$calls);self::assertSame([],$service->pendingMutations());self::assertSame(1,$service->collectionState('regular_low')['version']);
    }
    public function testExternalSnapshotUsesAppStateMediaKeysAndChecksMac(): void
    {
        $snapshot=null;$downloaded=[];
        $service=$this->keyed(function()use(&$snapshot){return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('sync',[],[new BinaryNode('collection',['name'=>'regular_low','version'=>'1'],[new BinaryNode('snapshot',[],SchemaCodec::encode('ExternalBlobReference',['directPath'=>'/snapshot','mediaKey'=>str_repeat('m',32),'fileSizeBytes'=>strlen(SchemaCodec::encode('SyncdSnapshot',$snapshot))]))])])]);},null,function($reference)use(&$snapshot,&$downloaded){$downloaded=$reference;return SchemaCodec::encode('SyncdSnapshot',$snapshot);});
        $snapshot=self::snapshot($service);$result=$service->sync(['regular_low']);
        self::assertSame('success',$result['collections'][0]['state']);self::assertSame('md-app-state',$downloaded['mediaType']);self::assertSame($downloaded['fileSizeBytes'],$downloaded['fileLength']);self::assertSame(1,$service->collectionState('regular_low')['version']);
    }
    public function testMissingKeyBlocksWithoutPersistingUnauthenticatedSnapshot(): void
    {
        $donor=$this->keyed();$snapshot=self::snapshot($donor);$events=[];
        $service=$this->service(static fn()=>throw new \LogicException(),function($name,$value)use(&$events){$events[$name]=$value;});
        try{$service->applySnapshot('regular_low',$snapshot);self::fail();}catch(MissingAppStateKey $e){self::assertSame(hex2bin('000100000001'),$e->keyId);}
        self::assertFalse($service->collectionState('regular_low')['initialized']);
    }
    public function testSnapshotRequestsAllUnknownRecordKeysBeforeCommittingAnything(): void
    {
        $events=[]; $snapshot=[];
        $service=$this->keyed(static fn()=>new BinaryNode('iq',['type'=>'result'],[new BinaryNode('sync',[],[
            new BinaryNode('collection',['name'=>'regular_low','version'=>'1'],[
                new BinaryNode('snapshot',[],SchemaCodec::encode('ExternalBlobReference',['directPath'=>'/snapshot','mediaKey'=>str_repeat('m',32)]))
            ])
        ])]),function($name,$data)use(&$events){$events[$name]=$data;},function()use(&$snapshot){return SchemaCodec::encode('SyncdSnapshot',$snapshot);});
        $active=$service->activeKey(); $snapshot=self::snapshot($service); $hash=str_repeat("\0",128);
        $ids=["old\0one","old\0two","old\0three"];
        foreach([...$ids,$ids[0]] as $number=>$id) {
            $input=AppStateSchema::mutation('Archive',['chatJid'=>(string)(100+$number)],['archived'=>true],$number+1);
            $encrypted=AppStateCrypto::encryptMutation(0,$id,str_repeat((string)(array_search($id,$ids,true)+1),32),$input['index'],$input['value'],$input['version']);
            $snapshot['records'][]=['keyId'=>['id'=>$id],'index'=>['blob'=>$encrypted['indexMac']],'value'=>['blob'=>$encrypted['valueBlob']]];
            $hash=AppStateCrypto::ltHash($hash,[$encrypted['valueMac']]);
        }
        $snapshot['mac']=AppStateCrypto::snapshotMac($active['keyData'],$hash,1,'regular_low');
        $before=$service->collectionState('regular_low');
        try{$service->applySnapshot('regular_low',$snapshot);self::fail('Accepted unknown keys');}
        catch(MissingAppStateKey $error){self::assertSame($ids,$error->keyIds);self::assertSame($ids[0],$error->keyId);}
        self::assertSame($before,$service->collectionState('regular_low'));
        $result=$service->sync(['regular_low']);
        self::assertSame('blocked',$result['collections'][0]['state']);
        self::assertSame(['keyIds'=>$ids,'collections'=>['regular_low']],$events['appstate_missing_keys']);
        self::assertArrayNotHasKey('appstate_mutation',$events);
        self::assertSame($before,$service->collectionState('regular_low'));
        self::assertSame($active,$service->activeKey());
        foreach($ids as $number=>$id) $service->importKeys([['keyId'=>$id,'keyData'=>str_repeat((string)($number+1),32)]]);
        self::assertCount(4,$service->applySnapshot('regular_low',$snapshot));
        self::assertSame(1,$service->collectionState('regular_low')['version']);
    }
    public function testExternalPatchReportsMissingRecordAndMacKeysTogether(): void
    {
        $ids=["old\0one","old\0two","old\0mac"]; $body='';
        $service=$this->keyed(null,null,function()use(&$body){return $body;});
        $service->applySnapshot('regular_low',self::snapshot($service));
        $before=$service->collectionState('regular_low'); $mutations=[];
        foreach(array_slice($ids,0,2) as $number=>$id) {
            $input=AppStateSchema::mutation('Archive',['chatJid'=>(string)(100+$number)],['archived'=>true],$number+1);
            $encrypted=AppStateCrypto::encryptMutation(0,$id,str_repeat('k',32),$input['index'],$input['value'],$input['version']);
            $mutations[]=['operation'=>0,'record'=>['keyId'=>['id'=>$id],'index'=>['blob'=>$encrypted['indexMac']],'value'=>['blob'=>$encrypted['valueBlob']]]];
        }
        $body=SchemaCodec::encode('SyncdMutations',['mutations'=>$mutations]);
        $patch=['version'=>['version'=>2],'keyId'=>['id'=>$ids[2]],'externalMutations'=>['directPath'=>'/patch','mediaKey'=>str_repeat('m',32)]];
        try{$service->applyPatch('regular_low',$patch);self::fail('Accepted unknown keys');}
        catch(MissingAppStateKey $error){self::assertSame($ids,$error->keyIds);}
        self::assertSame($before,$service->collectionState('regular_low'));
    }
    public function testRequestForOwnKeysOnlyEmitsDeferredEncryptedReply(): void
    {
        $events=[];$service=$this->keyed(null,function($name,$value)use(&$events){$events[$name]=$value;});
        $service->handleProtocol(['appStateSyncKeyRequest'=>['keyIds'=>[['keyId'=>hex2bin('000100000001')],['keyId'=>'missing']]]],['from'=>'me:2@lid','fromMe'=>true]);
        $reply=$events['appstate_key_share_requested'];self::assertSame('me:2@lid',$reply['to']);self::assertCount(1,$reply['message']['protocolMessage']['appStateSyncKeyShare']['keys']);self::assertSame(6,$reply['message']['protocolMessage']['type']);
    }
    public function testAllUpstreamSchemasAreAvailableAndKnownActionsMatchCollectionsAndVersions(): void
    {
        self::assertCount(73,AppStateSchema::all());
        foreach(['Archive'=>['regular_low',3],'Mute'=>['regular_high',2],'Pin'=>['regular_low',5],'Star'=>['regular_high',2],'DeleteMessageForMe'=>['regular_high',3],'LabelEdit'=>['regular',3],'Contact'=>['critical_unblock_low',2],'LabelSublist'=>['regular',1],'ContactManagerMetadata'=>['regular_low',1],'GroupHistoryToggle'=>['regular_low',1],'BBProPendingCustomerBase'=>['regular_low',7],'DeviceCapabilitiesDevice'=>['regular_low',7],'DeviceCapabilitiesV2'=>['regular_low',7]] as $name=>$expected) {
            $schema=AppStateSchema::all()[$name];self::assertSame($expected,[$schema['collection'],$schema['version']]);
        }
    }
    public function testGroupMessageMutationRequiresIncomingParticipant(): void
    {
        $service=$this->keyed();$this->expectException(\InvalidArgumentException::class);$service->setMessageStar(['chatJid'=>'123@g.us','id'=>'ID','fromMe'=>false],true);
    }
    public function testUnknownCollectionAndSchemaAreRejected(): void
    {
        $service=$this->service();$this->expectException(\InvalidArgumentException::class);$service->sync(['invented']);
    }
    public function testConflictAppliesRemotePatchAndRebuildsPendingAtNewVersion(): void
    {
        $remote=$this->keyed();$remote->applySnapshot('regular_low',self::snapshot($remote,[],0));
        $remoteInput=AppStateSchema::mutation('Archive',['chatJid'=>'456'],['archived'=>true],20);
        $remotePatch=$remote->buildPatch('regular_low',[$remoteInput])['patch']+['version'=>['version'=>1]];
        $calls=0;$service=$this->keyed(function(BinaryNode $request)use(&$calls,$remotePatch){
            ++$calls;$collection=$request->child('sync')->content[0];
            if($calls===1)return self::response('regular_low',0);
            if($calls===2)return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('sync',[],[new BinaryNode('collection',['name'=>'regular_low','version'=>'1','type'=>'error'],[new BinaryNode('error',['code'=>'409']),new BinaryNode('patches',[],[new BinaryNode('patch',[],SchemaCodec::encode('SyncdPatch',$remotePatch))])])])]);
            self::assertSame('1',$collection->attrs['version']);self::assertNotNull($collection->child('patch'));return self::response('regular_low',2);
        });
        $service->mutate('Pin',['chatJid'=>'123'],['pinned'=>true]);
        self::assertSame(3,$calls);self::assertSame(2,$service->collectionState('regular_low')['version']);self::assertCount(2,$service->collectionState('regular_low')['indexValueMap']);self::assertSame([],$service->pendingMutations());
    }
    public function testHasMorePagesFollowAuthenticatedContiguousVersions(): void
    {
        $remote=$this->keyed();$remote->applySnapshot('regular_low',self::snapshot($remote,[],0));$patches=[];
        foreach([1,2] as $version){$input=AppStateSchema::mutation('Pin',['chatJid'=>(string)$version],['pinned'=>true],$version);$patch=$remote->buildPatch('regular_low',[$input])['patch']+['version'=>['version'=>$version]];$remote->applyPatch('regular_low',$patch);$patches[]=$patch;}
        $calls=0;$service=$this->keyed(function(BinaryNode $request)use(&$calls,$patches){$collection=$request->child('sync')->content[0];self::assertSame((string)$calls,$collection->attrs['version']);return self::response('regular_low',++$calls,[$patches[$calls-1]],['has_more_patches'=>$calls===1?'true':'false']);});
        $result=$service->sync(['regular_low']);self::assertSame(2,$calls);self::assertSame('success',$result['collections'][0]['state']);self::assertSame(2,$service->collectionState('regular_low')['version']);
    }
    public function testExternalPatchMutationsUseAuthenticatedDownloadAndPatchMac(): void
    {
        $downloaded=[];$body='';$service=$this->keyed(null,null,function($reference)use(&$body,&$downloaded){$downloaded=$reference;return $body;});
        $service->applySnapshot('regular_low',self::snapshot($service,[],0));$input=AppStateSchema::mutation('Pin',['chatJid'=>'123'],['pinned'=>true],10);$patch=$service->buildPatch('regular_low',[$input])['patch']+['version'=>['version'=>1]];
        $body=SchemaCodec::encode('SyncdMutations',['mutations'=>$patch['mutations']]);unset($patch['mutations']);$patch['externalMutations']=['directPath'=>'/patch','mediaKey'=>str_repeat('m',32),'fileSizeBytes'=>strlen($body)];
        $service->applyPatch('regular_low',$patch);self::assertSame('md-app-state',$downloaded['mediaType']);self::assertSame(1,$service->collectionState('regular_low')['version']);
    }
}
