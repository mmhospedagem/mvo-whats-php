<?php
declare(strict_types=1);
namespace MVO\Messenger\Tests;

use MVO\Messenger\Auth\SessionStore;
use MVO\Messenger\E2ee\{CryptoAuthToken,DeviceKeys,Runtime};
use MVO\Messenger\E2ee\Protocol\{BinaryNode,BlueWebVersion,MessageEnvelope,RosterVerifier};
use MVO\Messenger\E2ee\Signal\{SignalProtocol,SignalState,SignalStore};
use MVO\Messenger\MessengerException;
use MVO\Messenger\Protocol\BootstrapConfig;
use MVO\Messenger\Transport\Socket;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/fixtures/NoisePeer.php';

final class E2eeOrchestratorTest extends TestCase
{
    private string $directory;private ?SessionStore $owner=null;private DeviceKeys $alice;private DeviceKeys $bob;
    private MessengerNoisePeer $peer;private int $factoryCalls=0;private bool $dropUpload=false;private bool $ownerAllowed=true;private string $bobHash='synthetic-bob-hash';
    /** @var list<array{string,array<string,mixed>}> */private array $events=[];
    protected function setUp():void
    {
        if(!extension_loaded('gmp')||!extension_loaded('zlib'))self::markTestSkipped('Encrypted runtime optional extensions required');
        $this->directory=sys_get_temp_dir().'/mvo-runtime-'.bin2hex(random_bytes(8));$this->owner=new SessionStore($this->directory,'alice');$this->owner->acquire();$this->owner->set('account_id','10001');
        $this->alice=DeviceKeys::generate('10001');$this->bob=DeviceKeys::generate('20002');$this->saveRegistration();$this->newPeer();
    }
    protected function tearDown():void
    {
        $this->owner?->release();$this->owner=null;gc_collect_cycles();if(!isset($this->directory)||!is_dir($this->directory))return;
        $files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($files as$file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($this->directory);
    }
    private function saveRegistration():void
    {
        $this->owner->set('e2ee.device.keys.v1',$this->alice->exportPrivateJson());$this->owner->set('e2ee.device.registration.v1',json_encode(['state'=>'registered','device_id'=>7,'device_uuid'=>$this->alice->uuid,'identity_fingerprint'=>hash('sha256',$this->alice->identityPublic())],JSON_THROW_ON_ERROR));
    }
    private function bootstrap(bool $withRevision=true,string $account='10001'):BootstrapConfig
    {
        return new BootstrapConfig($account,'123','synthetic-ls-device','999999','analytics-version-is-not-maw','https://www.facebook.com',[
            'SiteData'=>$withRevision?['client_revision'=>1033300011]:[],
            'MessengerWebInitData'=>['appId'=>'123','cryptoAuthToken'=>['encryptedSerializedCat'=>'SYNTHETIC-CAT','expirationTimeInSeconds'=>time()+600]],
        ]);
    }
    private function runtime(?RosterVerifier $verifier=null,?BootstrapConfig $bootstrap=null,?callable $handler=null):Runtime
    {
        return new Runtime($this->owner,'10001',fn()=>$bootstrap??$this->bootstrap(),'Synthetic current user agent',
            function(){if(!$this->ownerAllowed)throw new \LogicException('Parent stopped');},
            function(string $url,string $origin,array $headers):Socket{self::assertSame('wss://web-chat-e2ee.facebook.com/ws/chat',$url);self::assertSame('https://www.facebook.com',$origin);self::assertSame(['User-Agent'=>'Synthetic current user agent'],$headers);++$this->factoryCalls;return$this->peer;},
            $verifier,$handler??function(string $event,array $payload){$this->events[]=[$event,$payload];},0.1,$this->peer->root['public']);
    }
    private function newPeer():void
    {
        $this->peer=new MessengerNoisePeer();$this->peer->onNode=function(BinaryNode $node,MessengerNoisePeer $peer):void{
            if($node->tag==='iq'&&($node->attrs['xmlns']??null)==='encrypt'&&($node->attrs['type']??null)==='set'){if(!$this->dropUpload)$peer->enqueue(new BinaryNode('iq',['type'=>'result','id'=>$node->attrs['id']]));return;}
            if($node->tag==='iq'&&($node->attrs['xmlns']??null)==='fbid:devices'){$peer->enqueue(new BinaryNode('iq',['type'=>'result','id'=>$node->attrs['id']],[new BinaryNode('users',[],[
                new BinaryNode('user',['jid'=>'20002@msgr'],[new BinaryNode('devices',['dhash'=>$this->bobHash],[new BinaryNode('device',['id'=>'4'])])]),
                new BinaryNode('user',['jid'=>'10001@msgr'],[new BinaryNode('devices',['dhash'=>'synthetic-alice-hash'],[new BinaryNode('device',['id'=>'7'])])]),
            ])]));return;}
            if($node->tag==='iq'&&($node->attrs['xmlns']??null)==='encrypt'){$peer->enqueue(new BinaryNode('iq',['type'=>'result','id'=>$node->attrs['id']],[new BinaryNode('list',[],[$this->bundleNode($this->bob,'20002:4@msgr')])]));return;}
            if($node->tag==='message')$peer->enqueue(new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id'],'from'=>'20002@msgr']));
        };
    }
    private function bundleNode(DeviceKeys $keys,string $jid):BinaryNode
    {
        $signed=$keys->signalCredentials()['signedPreKey'];return new BinaryNode('user',['jid'=>$jid],[new BinaryNode('registration',[],pack('N',$keys->registrationId)),new BinaryNode('identity',[],$keys->identityPublic()),new BinaryNode('skey',[],[new BinaryNode('id',[],substr(pack('N',$signed['id']),1)),new BinaryNode('value',[],SignalState::rawPublicKey($signed['public'])),new BinaryNode('signature',[],$signed['signature'])])]);
    }
    private function reason(callable $action,string $reason):void
    {
        try{$action();self::fail('Expected '.$reason);}catch(MessengerException $error){self::assertSame($reason,$error->reason);}
    }
    public function testVersionUsesCurrentSiteDataAndDoesNotUseAnalyticsOrLsVersion():void
    {
        self::assertSame([302,0,1033300011],BlueWebVersion::fromBootstrap($this->bootstrap()));
        $this->reason(fn()=>BlueWebVersion::fromBootstrap($this->bootstrap(false)),'messenger_blue_web_version_unavailable');
    }
    public function testUnconfirmedRegistrationAndMissingVersionBlockBeforeSocketFactory():void
    {
        $runtime=$this->runtime(bootstrap:$this->bootstrap(false));$this->reason(fn()=>$runtime->connect(2),'messenger_blue_web_version_unavailable');self::assertSame(0,$this->factoryCalls);
        $this->owner->set('e2ee.device.registration.v1','{"state":"registration_uncertain"}');$runtime=$this->runtime();$this->reason(fn()=>$runtime->connect(2),'messenger_device_registration_required');self::assertSame(0,$this->factoryCalls);
    }
    public function testNoiseAloneTimeoutFailureAndWrongAccountNeverUploadPrekeys():void
    {
        foreach(['absent','failure','wrong-device']as$type){$this->newPeer();if($type==='absent')$this->peer->omitLogin=true;elseif($type==='failure')$this->peer->loginResult=new BinaryNode('failure',['reason'=>'synthetic']);else$this->peer->loginResult=new BinaryNode('success',['jid'=>'20002:4@msgr']);
            $runtime=$this->runtime();$this->reason(fn()=>$runtime->connect(2),match($type){'absent'=>'messenger_encrypted_login_timeout','failure'=>'messenger_encrypted_login_rejected',default=>'messenger_device_identity_mismatch'});self::assertSame([],$this->peer->received);self::assertFalse($runtime->status()['login_confirmed']);self::assertTrue($this->peer->closed);}
    }
    public function testMatchingPrekeyIqInitializesOnlyTransportAndNoDefaultIcdcBypassExists():void
    {
        $runtime=$this->runtime();$status=$runtime->connect(2);self::assertTrue($status['login_confirmed']);self::assertTrue($status['prekeys_confirmed']);self::assertFalse($status['e2ee_ready']);self::assertSame([], (new SignalStore($this->owner,'10001'))->pendingPreKeys());
        $count=count($this->peer->received);$this->reason(fn()=>$runtime->sendText('20002@msgr','Test','command-1'),'messenger_icdc_verification_unavailable');self::assertCount($count,$this->peer->received);
        $review=$runtime->reviewPeer('20002@msgr');self::assertFalse($review['native_roster_verified']);self::assertSame(hash('sha256',$this->bob->identityPublic()),$review['device_fingerprints']['20002:4@msgr']);$this->reason(fn()=>$runtime->approvePeer($review['snapshot_hash']),'messenger_icdc_verification_unavailable');self::assertFalse($runtime->status()['e2ee_ready']);
    }
    public function testUncertainPrekeyPublicationNeverRegeneratesOrAutomaticallyResends():void
    {
        $this->dropUpload=true;$runtime=$this->runtime();$this->reason(fn()=>$runtime->connect(2),'messenger_prekey_upload_uncertain');$state=new SignalStore($this->owner,'10001');$pending=$state->pendingPreKeys();self::assertCount(2,$pending);self::assertSame(1,$this->factoryCalls);
        $this->newPeer();$runtime=$this->runtime();$this->reason(fn()=>$runtime->connect(2),'messenger_prekey_upload_uncertain');self::assertSame(1,$this->factoryCalls);self::assertSame($pending,$state->pendingPreKeys());
    }
    public function testSyntheticVerifierStillRequiresExactExplicitReviewAndReplayDoesNoIo():void
    {
        $verifier=new SyntheticRuntimeRosterVerifier($this->bob->identityPublic());$runtime=$this->runtime($verifier);$runtime->connect(2);
        $this->reason(fn()=>$runtime->sendText('20002@msgr','Synthetic text','command-2'),'messenger_peer_roster_review_required');self::assertCount(0,array_filter($this->peer->received,fn($node)=>$node->tag==='message'));
        $review=$runtime->reviewPeer('20002@msgr');self::assertTrue($review['native_roster_verified']);$runtime->approvePeer($review['snapshot_hash']);$result=$runtime->sendText('20002@msgr','Synthetic text','command-2','SYNTHETIC-OUT-1');self::assertSame('submitted',$result['state']);self::assertSame('server_ack',$runtime->poll()['result']['state']);self::assertFalse($runtime->outgoing('SYNTHETIC-OUT-1')['delivery_confirmed']);
        $count=count($this->peer->received);self::assertSame('server_ack',$runtime->sendText('20002@msgr','Synthetic text','command-2')['state']);self::assertCount($count,$this->peer->received);$this->reason(fn()=>$runtime->sendText('20002@msgr','different','command-2'),'messenger_idempotency_conflict');
        $this->peer->enqueue(new BinaryNode('receipt',['id'=>'SYNTHETIC-OUT-1','from'=>'20002:4@msgr','type'=>'read']));self::assertSame('read',$runtime->poll()['result']['state']);self::assertTrue($runtime->outgoing('SYNTHETIC-OUT-1')['read_confirmed']);self::assertSame('receipt',end($this->peer->received)->attrs['class']);
    }
    public function testVerifierRejectionCannotApproveOrSend():void
    {
        $runtime=$this->runtime(new SyntheticRuntimeRosterVerifier(str_repeat('x',32)));$runtime->connect(2);
        $this->expectException(\UnexpectedValueException::class);$runtime->reviewPeer('20002@msgr');
    }
    public function testChangedRosterBlocksUntilNewReviewAndSubmittedReplayDoesNotDoDiscovery():void
    {
        $runtime=$this->runtime(new SyntheticRuntimeRosterVerifier($this->bob->identityPublic()));$runtime->connect(2);$review=$runtime->reviewPeer('20002@msgr');$runtime->approvePeer($review['snapshot_hash']);
        $this->bobHash='synthetic-new-roster';$this->reason(fn()=>$runtime->sendText('20002@msgr','Synthetic','roster-change'),'messenger_peer_roster_review_required');self::assertCount(0,array_filter($this->peer->received,fn($node)=>$node->tag==='message'));
        $review=$runtime->reviewPeer('20002@msgr');$runtime->approvePeer($review['snapshot_hash']);$original=$this->peer->onNode;$this->peer->onNode=function(BinaryNode $node,MessengerNoisePeer $peer)use($original){if($node->tag!=='message')$original($node,$peer);};
        self::assertSame('submitted',$runtime->sendText('20002@msgr','Synthetic','roster-change')['state']);$count=count($this->peer->received);$this->reason(fn()=>$runtime->sendText('20002@msgr','Synthetic','roster-change'),'messenger_encrypted_send_uncertain');self::assertCount($count,$this->peer->received);
    }
    public function testUnrelatedIqCannotConfirmThePendingPrekeyPublication():void
    {
        $original=$this->peer->onNode;$this->peer->onNode=function(BinaryNode $node,MessengerNoisePeer $peer)use($original){if($node->tag==='iq'&&($node->attrs['type']??null)==='set'){$peer->enqueue(new BinaryNode('iq',['id'=>'unrelated-id','type'=>'result']));return;}$original($node,$peer);};
        $runtime=$this->runtime();$this->reason(fn()=>$runtime->connect(2),'messenger_prekey_upload_uncertain');self::assertCount(2,(new SignalStore($this->owner,'10001'))->pendingPreKeys());self::assertFalse($runtime->status()['prekeys_confirmed']);
    }
    public function testOwnerGuardClosesSocketAndCallbackCannotIssueCommands():void
    {
        $blocked=false;$runtime=null;$runtime=$this->runtime(handler:function(string $event,array $payload)use(&$runtime,&$blocked){if($event==='encrypted_transport_connected'){try{$runtime->reviewPeer('20002@msgr');}catch(\LogicException){$blocked=true;}}});$runtime->connect(2);self::assertTrue($blocked);
        $this->ownerAllowed=false;try{$runtime->poll(0);self::fail('Owner stopped');}catch(\LogicException){self::assertTrue($this->peer->closed);self::assertFalse($runtime->status()['login_confirmed']);}
    }
    public function testPendingIncomingIsDurableAndDecryptsOnlyAfterVerifiedReview():void
    {
        $bobOwner=new SessionStore($this->directory,'bob');$bobOwner->acquire();$bobOwner->set('account_id','20002');
        try{
            $bobStore=new SignalStore($bobOwner,'20002');$bobSignal=new SignalProtocol($bobStore,$this->bob->signalCredentials());$bobStore->approveIdentity('10001:7@msgr',$this->alice->identityPublic(),hash('sha256',$this->alice->identityPublic()));$signed=$this->alice->signalCredentials()['signedPreKey'];$bobSignal->ensureSession('10001:7@msgr',['registrationId'=>$this->alice->registrationId,'identityKey'=>$this->alice->identityPublic(),'signedPreKey'=>['id'=>$signed['id'],'public'=>$signed['public'],'signature'=>$signed['signature']]]);
            $envelope=MessageEnvelope::text('Private synthetic inbound');$cipher=$bobSignal->encrypt('10001:7@msgr',MessageEnvelope::transport($envelope['application']));$incoming=new BinaryNode('message',['id'=>'SYNTHETIC-IN-1','from'=>'20002:4@msgr'],[new BinaryNode('enc',['v'=>'3','type'=>$cipher['type']],$cipher['ciphertext']),new BinaryNode('franking',[],[new BinaryNode('franking_tag',[],$envelope['franking_tag'])])]);
            $runtime=$this->runtime(new SyntheticRuntimeRosterVerifier($this->bob->identityPublic()));$runtime->connect(2);$this->peer->enqueue($incoming);self::assertSame('message_blocked',$runtime->poll()['type']);self::assertSame([],$runtime->messages());$runtime->ownerStopped();
            $this->newPeer();$runtime=$this->runtime(new SyntheticRuntimeRosterVerifier($this->bob->identityPublic()));$runtime->connect(2);self::assertSame(0,count(array_filter($this->peer->received,fn($node)=>$node->tag==='iq'&&($node->attrs['type']??null)==='set')));$review=$runtime->reviewPeer('20002@msgr');$runtime->approvePeer($review['snapshot_hash']);$result=$runtime->retryPendingIncoming();self::assertSame('Private synthetic inbound',$result[0]['message']['text']);self::assertSame(1,$runtime->flushReceipts());self::assertSame([],$runtime->retryPendingIncoming());self::assertCount(1,$runtime->messages());
        }finally{$bobOwner->release();unset($bobSignal,$bobStore,$bobOwner);}
    }
    public function testSerializationAndDebugDoNotExposePrivateBootstrap():void
    {
        $runtime=$this->runtime();self::assertStringNotContainsString('SYNTHETIC-CAT',print_r($runtime,true).json_encode($runtime,JSON_THROW_ON_ERROR));$this->expectException(\LogicException::class);serialize($runtime);
    }
    public function testDroppingTheLastRuntimeReferenceClosesTheRetainedInjectedSocketWithoutGuard():void
    {
        $runtime=$this->runtime();$runtime->connect(2);self::assertTrue($this->peer->isConnected());$this->ownerAllowed=false;unset($runtime);gc_collect_cycles();
        self::assertTrue($this->peer->closed);self::assertFalse($this->peer->isConnected());self::assertTrue($this->owner->ownsLock());
    }
}

/** Test-only policy: verifies exact fixture scope/key. NOT an implementation of Meta ICDC. */
final class SyntheticRuntimeRosterVerifier implements RosterVerifier
{
    public function __construct(private readonly string $expectedIdentity){}
    public function verify(BinaryNode $directoryResult,array $rosters,array $bundles):void
    {
        if($directoryResult->tag!=='iq'||array_keys($bundles)!==['20002:4@msgr']||$rosters['10001@msgr']['devices']!==['10001:7@msgr']||!hash_equals($this->expectedIdentity,$bundles['20002:4@msgr']['identityKey']))throw new \UnexpectedValueException('Synthetic roster rejected');
    }
}
