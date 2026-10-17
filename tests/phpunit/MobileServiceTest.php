<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\AppState\AppStateService;
use MVO\Auth\{CredentialStore,PairingCodeCrypto};
use MVO\Crypto\Crypto;
use MVO\Mobile\MobileService;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Signal\SignalStore;

final class MobileServiceTest extends TestCase
{
    private SignalStore $store;
    private array $credentials;
    private array $queries=[];
    private array $peer=[];
    private array $events=[];
    private float $clock=1000.0;
    private bool $primary=true;
    private bool $failPair=false;
    private int $peerFailures=0;
    private int $keyShareFailures=0;
    private string $deviceJid='5511987654321:12@s.whatsapp.net';
    private ?\Closure $primaryHello=null;
    protected function setUp():void { $this->store=new SignalStore(':memory:');$this->credentials=CredentialStore::fresh();$this->credentials['registered']=true;$this->credentials['meJid']='5511987654321@s.whatsapp.net';$this->credentials['meLid']='123@lid';$this->credentials['pushName']='Conta'; }
    protected function tearDown():void { $this->store->close(); }
    private function service():MobileService
    {
        $query=$this->query(...);$state=new AppStateService($this->store,$query);
        return new MobileService($this->store,fn()=>$this->credentials,$query,function($jid,$message){$this->peer[]=['jid'=>$jid,'message'=>$message];if($this->peerFailures>0){--$this->peerFailures;throw new \RuntimeException('Companion has no prekeys yet');}if($this->keyShareFailures>0 && $message['protocolMessage']['type']==='APP_STATE_SYNC_KEY_SHARE'){--$this->keyShareFailures;throw new \RuntimeException('Key-share response lost');}},$state,function($name,$data){$this->events[]=['name'=>$name,'data'=>$data];},fn()=>$this->primary,null,null,false,fn()=>$this->clock);
    }
    private function query(BinaryNode $request,float $timeout):BinaryNode
    {
        $this->queries[]=$request;$child=$request->content[0];
        if($child->tag==='sync') {
            self::assertSame('3',$child->attrs['data_namespace']);$collections=[];
            foreach($child->content as $collection){$version=(int)$collection->attrs['version']+($collection->child('patch')===null?0:1);$collections[]=new BinaryNode('collection',['name'=>$collection->attrs['name'],'version'=>(string)$version]);}
            return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('sync',[],$collections)]);
        }
        if($child->tag==='pair-device') {
            self::assertSame(32.0,$timeout);if($this->failPair)throw new \RuntimeException('Pair-device response lost');
            return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('pair-device',[],[new BinaryNode('device',['jid'=>$this->deviceJid])])]);
        }
        if($child->tag==='link_code_companion_reg' && $this->primaryHello!==null)($this->primaryHello)($child);
        return new BinaryNode('iq',['type'=>'result']);
    }
    private function qr():string { return 'REF,'.base64_encode(Crypto::x25519KeyPair()['public']).','.base64_encode(Crypto::x25519KeyPair()['public']).','.base64_encode(random_bytes(32)).',CHROME'; }
    private function findQuery(string $tag):?BinaryNode { foreach(array_reverse($this->queries) as $node)if($node->child($tag)!==null)return $node->child($tag);return null; }
    public function testQrLinkStoresDurableInventoryDeclaresLidMigrationAndProvisionsCompanion():void
    {
        $service=$this->service();$linked=$service->linkCompanion($this->qr());self::assertSame(['deviceJid'=>$this->deviceJid,'keyIndex'=>1],$linked);self::assertCount(1,$service->listCompanions());
        $props=SchemaCodec::decode('ClientPairingProps',$this->findQuery('pair-device')->child('client-props')->content);self::assertTrue($props['isChatDbLidMigrated']);self::assertTrue($props['isSyncdPureLidSession']);
        self::assertSame([],$this->peer);$service->drainPending();self::assertCount(2,$this->peer);self::assertSame('HISTORY_SYNC_NOTIFICATION',$this->peer[0]['message']['protocolMessage']['type']);self::assertSame('APP_STATE_SYNC_KEY_SHARE',$this->peer[1]['message']['protocolMessage']['type']);
        $restored=$this->service();self::assertSame($service->listCompanions(),$restored->listCompanions());self::assertSame([],$this->store->entries('mobile-provision',10));
    }
    public function testUnknownPrimaryRoleAndWebCompanionCredentialsCannotHostDevices():void
    {
        $this->primary=false;$service=$this->service();try{$service->linkCompanion($this->qr());self::fail();}catch(\LogicException){}self::assertSame([],$this->queries);self::assertNull($this->store->get('mobile','epoch'));
        $this->primary=true;$this->credentials['meJid']='5511987654321:52@s.whatsapp.net';$this->expectException(\InvalidArgumentException::class);$service->listCompanions();
    }
    public function testReservedKeyIndexSurvivesLostAcknowledgmentAndIsNeverReused():void
    {
        $service=$this->service();$this->failPair=true;try{$service->linkCompanion($this->qr());self::fail();}catch(\RuntimeException $e){self::assertSame('Pair-device response lost',$e->getMessage());}
        self::assertSame([],$service->listCompanions());$this->failPair=false;$restored=$this->service();self::assertSame(2,$restored->linkCompanion($this->qr())['keyIndex']);
    }
    public function testAccountSyncKeyIndexesPreventCollisionAndRevokePublishesOnlyValidIndexes():void
    {
        $service=$this->service();$service->handleNode(new BinaryNode('notification',['from'=>'s.whatsapp.net','type'=>'account_sync'],[new BinaryNode('devices',[],[new BinaryNode('device',['jid'=>'5511987654321@s.whatsapp.net','key-index'=>'0']),new BinaryNode('device',['jid'=>'5511987654321:20@s.whatsapp.net','key-index'=>'20'])])]));self::assertSame([],$this->queries);
        self::assertSame(21,$service->linkCompanion($this->qr())['keyIndex']);$service->revokeCompanion($this->deviceJid);self::assertSame([],$service->listCompanions());$list=SchemaCodec::decode('ADVSignedKeyIndexList',$this->findQuery('key-index-list')->content);self::assertSame([0,20],SchemaCodec::decode('ADVKeyIndexList',$list['details'])['validIndexes']);
        self::assertSame(22,$service->linkCompanion($this->qr())['keyIndex']);
    }
    public function testAccountSyncPrunesRevokedDeviceWithoutNetworkReentry():void
    {
        $service=$this->service();$service->linkCompanion($this->qr());$before=count($this->queries);
        $service->handleNode(new BinaryNode('notification',['from'=>'s.whatsapp.net','type'=>'account_sync'],[new BinaryNode('devices',[],[new BinaryNode('device',['jid'=>'5511987654321@s.whatsapp.net','key-index'=>'0'])])]));
        self::assertSame($before,count($this->queries));self::assertSame([],$service->listCompanions());self::assertSame('companion_host_revoked',$this->events[array_key_last($this->events)]['name']);
    }
    public function testLinkByCodeAuthenticatesCompanionFinishThenUsesSamePairDeviceFlow():void
    {
        $service=$this->service();$companionIdentity=Crypto::x25519KeyPair();$noise=Crypto::x25519KeyPair();$hello=PairingCodeCrypto::createHello('12345678');
        $service->handleNode(new BinaryNode('notification',['from'=>'s.whatsapp.net','type'=>'link_code_companion_reg'],[new BinaryNode('link_code_companion_reg',['stage'=>'companion_hello'],[new BinaryNode('link_code_pairing_ref',[],'REF'),new BinaryNode('link_code_pairing_wrapped_companion_ephemeral_pub',[],$hello['wrappedCompanionEphemeralPub']),new BinaryNode('companion_server_auth_key_pub',[],$noise['public'])])]));
        self::assertSame([],$this->queries);
        $this->primaryHello=function(BinaryNode $primary)use($hello,$companionIdentity,$service){
            $finish=PairingCodeCrypto::completeFinish($hello['pairingCode'],$primary->child('link_code_pairing_wrapped_primary_ephemeral_pub')->content,$primary->child('primary_identity_pub')->content,$hello['companionEphemeralKeyPair']['private'],$companionIdentity);
            $service->handleNode(new BinaryNode('notification',['from'=>'s.whatsapp.net','type'=>'link_code_companion_reg'],[new BinaryNode('link_code_companion_reg',['stage'=>'companion_finish'],[new BinaryNode('link_code_pairing_ref',[],'REF'),new BinaryNode('link_code_pairing_wrapped_key_bundle',[],$finish['wrappedKeyBundle']),new BinaryNode('companion_identity_public',[],$finish['companionIdentityPublic'])])]));
        };
        self::assertSame(1,$service->linkCompanionByCode('1234-5678')['keyIndex']);self::assertSame($companionIdentity['public'],$service->listCompanions()[0]['companionIdentityPublicKey']);
    }
    public function testProvisionRetryPersistsCompletionAndDoesNotDuplicateHistoryAfterKeyShareFailure():void
    {
        $service=$this->service();$service->linkCompanion($this->qr());$this->keyShareFailures=1;$service->drainPending();self::assertCount(2,$this->peer);self::assertCount(1,$this->store->entries('mobile-provision',10));
        $service->drainPending();self::assertCount(2,$this->peer);$this->clock+=2;$service->drainPending();self::assertCount(3,$this->peer);self::assertSame('APP_STATE_SYNC_KEY_SHARE',$this->peer[2]['message']['protocolMessage']['type']);self::assertSame([],$this->store->entries('mobile-provision',10));
    }
    public function testNoAccountKeysOrHistoryCanBeSharedToUntrackedForeignDevice():void
    {
        $service=$this->service();$service->initializeAppState();
        foreach(['shareAppStateSyncKeys','sendHistorySyncBootstrap'] as $method)try{$service->$method('999:2@s.whatsapp.net');self::fail();}catch(\InvalidArgumentException){self::assertTrue(true);}
        self::assertSame([],$this->peer);
    }
    public function testForeignPairDeviceResponseIsRejectedWithoutPersistingCompanion():void
    {
        $service=$this->service();$this->deviceJid='999:2@s.whatsapp.net';try{$service->linkCompanion($this->qr());self::fail();}catch(\UnexpectedValueException){}self::assertSame([],$service->listCompanions());
    }
    public function testForeignNotificationsCannotInfluencePrimaryDeviceInventory():void
    {
        $service=$this->service();$this->expectException(\UnexpectedValueException::class);$service->handleNode(new BinaryNode('notification',['from'=>'999@s.whatsapp.net','type'=>'account_sync'],[new BinaryNode('devices',[])]));
    }
    public function testRevokeAllSendsAccountRequestAndKeepsAllocationHighWaterMark():void
    {
        $service=$this->service();$service->linkCompanion($this->qr());$service->revokeAllCompanions();self::assertSame('true',$this->findQuery('remove-companion-device')->attrs['all']);self::assertSame([],$service->listCompanions());self::assertSame(2,$service->linkCompanion($this->qr())['keyIndex']);
    }
}
