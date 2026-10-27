<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Testing\FakePairing;
use MVO\Transport\NodeSession;
use MVO\WaClient;
use PHPUnit\Framework\TestCase;

/** Production pairing crypto with only the socket and clocks substituted. */
final class WaClientPairingRecoveryTest extends TestCase
{
    private string $directory;
    private float $time=0;
    private float $wall=1000;
    private array $primary;
    private array $clients=[];

    protected function setUp():void
    {
        $this->directory=sys_get_temp_dir().'/mvo-pairing-recovery-'.bin2hex(random_bytes(8));
        mkdir($this->directory,0700);
        $this->primary=CredentialStore::fresh();$this->primary['registered']=true;$this->primary['meJid']='5511999999999@s.whatsapp.net';
    }
    protected function tearDown():void
    {
        foreach($this->clients as$client)$client->disconnect();
        $this->clients=[];gc_collect_cycles();
        foreach(glob($this->directory.'/*/*')?:[] as$file)unlink($file);
        foreach(glob($this->directory.'/*')?:[] as$dir)rmdir($dir);
        rmdir($this->directory);
    }
    private function peer(string $restart='server'):PairingRecoverySession
    {
        return new PairingRecoverySession(new FakePairing($this->primary,7,fn():float=>$this->wall),$restart);
    }
    private function client(array $peers,bool $fullSync=false,bool $autoReconnect=true,?\Closure $clock=null):WaClient
    {
        $index=0;
        $client=new WaClient(authDirectory:$this->directory,sessionFactory:static function()use($peers,&$index):NodeSession{return $peers[$index++];},
            clock:$clock??fn():float=>$this->time,wallClock:fn():float=>$this->wall,random:static fn():float=>0.5,automaticMaintenance:false,autoReconnect:$autoReconnect,
            historyRequireFullSync:$fullSync);
        $this->clients[]=$client;return $client;
    }
    private function pairQr(WaClient $client,PairingRecoverySession $peer):void
    {
        $qr=null;$client->on('auth_qr',static function(array $event)use(&$qr):void{$qr=$event['qr'];});
        $client->connect();$client->poll(0);self::assertIsString($qr);
        $peer->incoming[]=$peer->pairing->scanQr($qr);$client->poll(0);
    }
    public function testSilentServerKeepsRegistrationSocketUntilFifteenSecondFallback():void
    {
        $old=$this->peer('silent');$next=new PairingRecoverySession();$client=$this->client([$old,$next]);
        $this->pairQr($client,$old);
        self::assertTrue($client->isRegistered());self::assertFalse($client->isConnected());self::assertFalse($old->closed);self::assertSame(0,$next->starts);
        $this->time=14.999;$client->poll(0);self::assertFalse($old->closed);self::assertSame(0,$next->starts);
        $this->time=15.0;$client->poll(0);self::assertTrue($old->closed);self::assertSame(1,$next->starts);
        self::assertSame('authenticating',$client->getState());self::assertSame([],$next->sent);
        $client->poll(0);self::assertSame(1,$next->starts);
    }
    public function testServer515OwnsTheRestartWithoutWaitingForFallback():void
    {
        $old=$this->peer();$next=new PairingRecoverySession();$client=$this->client([$old,$next]);
        $this->pairQr($client,$old);self::assertFalse($old->closed);
        $client->poll(0);self::assertTrue($old->closed);self::assertSame(1,$next->starts);
        self::assertSame('authenticating',$client->getState());
        $this->time=16;$client->poll(0);self::assertSame(1,$next->starts);self::assertFalse($next->closed);
    }
    public function testNormalAuthenticatedSuccessCancelsTheRestartWindow():void
    {
        $old=$this->peer('silent');$old->autoIq=true;$next=new PairingRecoverySession();$client=$this->client([$old,$next]);
        $this->pairQr($client,$old);$old->incoming[]=new BinaryNode('success');$client->poll(0);
        self::assertTrue($client->isConnected());$this->time=16;$client->poll(0);
        self::assertTrue($client->isConnected());self::assertFalse($old->closed);self::assertSame(0,$next->starts);
    }
    public function testServerSocketCloseAfterAdvRestartsImmediatelyWithSavedIdentity():void
    {
        $old=$this->peer('close');$next=new PairingRecoverySession();$client=$this->client([$old,$next]);
        $this->pairQr($client,$old);$before=$this->saved();$delays=[];
        $client->on('reconnect',static function(array $event)use(&$delays):void{$delays[]=$event['delay'];});
        $client->poll(0);self::assertSame([0.0],$delays);$client->poll(0);
        self::assertSame(1,$next->starts);self::assertSame($before['noiseKeyPair']['public'],$next->staticPublic);
        self::assertSame($before['identityKeyPair'],$this->saved()['identityKeyPair']);self::assertFalse($client->isConnected());
    }
    public function testExplicitRejectionDuringRestartWaitIsNotReinterpretedAsSuccess():void
    {
        $old=$this->peer('silent');$next=new PairingRecoverySession();$client=$this->client([$old,$next]);
        $this->pairQr($client,$old);$old->incoming[]=new BinaryNode('stream:error',['code'=>'401']);
        try{$client->poll(0);self::fail('Revocation was ignored');}catch(\MVO\Transport\ConnectionFailure $error){self::assertSame('401',$error->serverCode);}
        self::assertSame('logged_out',$client->getState());self::assertSame(0,$next->starts);self::assertTrue($this->saved()['revoked']);
    }
    public function testStopInPairedCallbackCancelsTheOldFallbackAndPreservesNewLogin():void
    {
        $old=$this->peer('silent');$next=new PairingRecoverySession();$client=$this->client([$old,$next]);
        $client->on('auth_paired',static function()use($client):void{$client->stop();$client->connect();});
        $this->pairQr($client,$old);self::assertSame(1,$next->starts);self::assertSame('authenticating',$client->getState());
        $this->time=30;$next->autoPing=true;$client->poll(0);self::assertFalse($next->closed);self::assertSame(1,$next->starts);
    }
    public function testPendingCodeIsRegisteredOnNewTransportWithoutPushAndOldProofIsIgnored():void
    {
        $old=$this->peer();$next=$this->peer();$client=$this->client([$old,$next]);$qrSecret=null;
        $client->on('auth_qr',static function(array $event)use(&$qrSecret):void{$qrSecret=explode(',',$event['qr'])[3];});
        $client->connect();$client->poll(0);$code=$client->requestPairingCode('5511999999999','ABCD1234');
        $stale=$old->pairing->approveCode($code);$this->reconnect($client,$old);
        self::assertSame(0,$next->hellos);$client->poll(0);
        self::assertSame(1,$next->hellos);self::assertSame('false',$next->lastHello->child('link_code_companion_reg')->attrs['should_show_push_notification']);
        self::assertSame($qrSecret,base64_encode($this->saved()['advSecretKey']));
        $next->incoming[]=$stale;$client->poll(0);self::assertSame(0,$next->finishes);
        $next->incoming[]=$next->pairing->approveCode($code);$client->poll(0);$client->poll(0);
        self::assertSame(1,$next->finishes);self::assertTrue($client->isRegistered());self::assertNotNull($next->pairing->completeCredentials());
    }
    public function testFailedReregistrationRequestsManualRefreshAndCanAcceptANewCode():void
    {
        $old=$this->peer();$next=$this->peer();$next->rejectRearm=true;$client=$this->client([$old,$next]);$refresh=[];
        $client->on('auth_code_refresh',static function(array $event)use(&$refresh):void{$refresh[]=$event;});
        $client->connect();$client->poll(0);$client->requestPairingCode('5511999999999','ABCD1234');$this->reconnect($client,$old);$client->poll(0);
        self::assertSame([['forceManual'=>true,'reason'=>'code_reregistration_failed']],$refresh);
        self::assertSame('pairing',$client->getState());self::assertFalse($client->isRegistered());
        self::assertSame('ABCD1234',$client->requestPairingCode('5511999999999','ABCD1234'));self::assertSame(2,$next->hellos);
    }
    public function testAutomaticRearmNeverExtendsTheOriginalCodeExpiry():void
    {
        $old=$this->peer();$next=$this->peer();$client=$this->client([$old,$next]);$expired=0;
        $client->on('auth_code_expired',static function()use(&$expired):void{++$expired;});
        $client->connect();$client->poll(0);$client->requestPairingCode('5511999999999','ABCD1234');
        $this->wall=1120;$this->reconnect($client,$old);$client->poll(0);self::assertSame(1,$next->hellos);
        $this->wall=1181;$client->poll(0);self::assertSame(1,$expired);self::assertSame(1,$next->hellos);
    }
    public function testStoppedRearmCannotClearOrNotifyANewerConnection():void
    {
        $old=$this->peer();$next=$this->peer();$replacement=$this->peer();$client=$this->client([$old,$next,$replacement]);$refresh=[];
        $client->on('auth_code_refresh',static function(array $event)use(&$refresh):void{$refresh[]=$event;});
        $client->connect();$client->poll(0);$client->requestPairingCode('5511999999999','ABCD1234');$this->reconnect($client,$old);
        $next->onHello=static function()use($client):void{$client->stop();$client->connect();};
        $client->poll(0);$client->poll(0);
        self::assertSame([],$refresh);self::assertSame(0,$replacement->hellos);self::assertFalse($replacement->closed);self::assertSame('pairing',$client->getState());
    }
    public function testFullSyncOptionReachesPairingDevicePropsAndNotRegisteredLogin():void
    {
        $peer=$this->peer('silent');$resume=new PairingRecoverySession();$client=$this->client([$peer,$resume],true);
        $this->pairQr($client,$peer);
        $registration=SchemaCodec::decode('ClientPayload',$peer->payload);
        $props=SchemaCodec::decode('DeviceProps',$registration['devicePairingData']['deviceProps']);
        self::assertTrue($props['requireFullSync']);
        $this->time=15;$client->poll(0);$login=SchemaCodec::decode('ClientPayload',$resume->payload);
        self::assertArrayNotHasKey('devicePairingData',$login);self::assertSame(5511999999999,$login['username']);
    }
    public function testSignedAdvDuringFinishQueryCannotWaitPastPairingRestartDeadline():void
    {
        $old=$this->peer('silent');$old->deferFinishResult=true;$next=new PairingRecoverySession();$client=$this->client([$old,$next]);$stages=[];
        $client->on('auth_code_progress',static function(array $event)use(&$stages):void{$stages[]=$event['stage'];});
        $client->connect();$client->poll(0);$code=$client->requestPairingCode('5511999999999','ABCD1234');
        $old->idleReader=function(float $timeout,?BinaryNode $result):?BinaryNode{$this->time+=$timeout;return $this->time>=20?$result:null;};
        $old->incoming[]=$old->pairing->approveCode($code);$client->poll(0);
        self::assertSame(15.0,$this->time);self::assertTrue($old->closed);self::assertSame(1,$next->starts);
        self::assertTrue($client->isRegistered());self::assertFalse($client->isConnected());self::assertSame(1,$old->finishes);
        self::assertNotNull($old->pairing->completeCredentials());self::assertNotContains('companion_finish_accepted',$stages);
        $client->poll(0);self::assertSame(1,$next->starts,'Interrupted finish must never be replayed');
    }
    public function testLateTransportResultCannotEmitNodeOrConfirmInterruptedFinish():void
    {
        $old=$this->peer('silent');$old->deferFinishResult=true;$next=new PairingRecoverySession();$client=$this->client([$old,$next]);$stages=[];$lateNodes=0;
        $client->on('auth_code_progress',static function(array $event)use(&$stages):void{$stages[]=$event['stage'];});
        $client->on('node',static function(array $event)use($old,&$lateNodes):void{if($old->heldFinishResult!==null&&($event['node']->attrs['id']??'')===($old->heldFinishResult->attrs['id']??null))++$lateNodes;});
        $client->connect();$client->poll(0);$code=$client->requestPairingCode('5511999999999','ABCD1234');
        $old->idleReader=function(float $timeout,?BinaryNode $result):?BinaryNode{$this->time=20;return $result;};
        $old->incoming[]=$old->pairing->approveCode($code);$client->poll(0);
        self::assertSame(0,$lateNodes);self::assertNotContains('companion_finish_accepted',$stages);self::assertTrue($old->closed);self::assertSame(1,$next->starts);
    }
    public function testDisabledReconnectStillInterruptsFinishAtDeadlineWithoutNewTransport():void
    {
        $old=$this->peer('silent');$old->deferFinishResult=true;$next=new PairingRecoverySession();$client=$this->client([$old,$next],autoReconnect:false);
        $client->connect();$client->poll(0);$code=$client->requestPairingCode('5511999999999','ABCD1234');
        $old->idleReader=function(float $timeout,?BinaryNode $result):?BinaryNode{$this->time+=$timeout;return null;};
        $old->incoming[]=$old->pairing->approveCode($code);
        try{$client->poll(0);self::fail('Expected the explicitly disabled restart');}
        catch(\MVO\Transport\ConnectionFailure $error){self::assertSame('515',$error->serverCode);}
        self::assertSame(15.0,$this->time);self::assertTrue($old->closed);self::assertSame(0,$next->starts);
        self::assertFalse($client->isRunning());self::assertTrue($client->isRegistered());self::assertFalse($this->saved()['revoked']??false);
    }
    public function testStopAndReplaceInsideDeadlineClockCannotRestartTheNewConnection():void
    {
        $old=$this->peer('silent');$next=new PairingRecoverySession();$armed=false;$client=null;
        $clock=function()use(&$client,&$armed):float{if($armed){$armed=false;$client->stop();$client->connect();}return $this->time;};
        $client=$this->client([$old,$next],clock:$clock);$this->pairQr($client,$old);
        $this->time=15;$armed=true;$client->poll(0);
        self::assertSame(1,$next->starts);self::assertFalse($next->closed);self::assertSame('authenticating',$client->getState());
        $this->time=16;$client->poll(0);self::assertSame(1,$next->starts);self::assertFalse($next->closed);
    }
    public function testNewQueryAfterDeadlineWritesNothingAndOuterPollOwnsOneRestart():void
    {
        $old=$this->peer('silent');$next=new PairingRecoverySession();$client=$this->client([$old,$next]);$this->pairQr($client,$old);$sent=count($old->sent);
        $this->time=16;
        try{$client->query(new BinaryNode('iq',['type'=>'get','xmlns'=>'test']));self::fail('Expired transport accepted a new query');}
        catch(\MVO\Transport\SessionRestartRequired){}
        self::assertCount($sent,$old->sent);$client->poll(0);self::assertTrue($old->closed);self::assertSame(1,$next->starts);
        $client->poll(0);self::assertSame(1,$next->starts);
    }
    private function reconnect(WaClient $client,PairingRecoverySession $old):void
    {
        $old->failReceive=true;$client->poll(0);$this->time+=1;$client->poll(0);
    }
    private function saved():array
    {
        $store=new CredentialStore($this->directory);$saved=$store->load();$store->close();self::assertNotNull($saved);return $saved;
    }
}

final class PairingRecoverySession implements NodeSession
{
    public array $incoming=[];
    public array $sent=[];
    public int $starts=0;
    public int $hellos=0;
    public int $finishes=0;
    public bool $closed=false;
    public bool $failReceive=false;
    public bool $autoPing=false;
    public bool $autoIq=false;
    public bool $rejectRearm=false;
    public ?\Closure $onHello=null;
    public ?BinaryNode $lastHello=null;
    public bool $deferFinishResult=false;
    public ?BinaryNode $heldFinishResult=null;
    public ?\Closure $idleReader=null;
    public string $payload='';
    public string $staticPublic='';
    public function __construct(public readonly ?FakePairing $pairing=null,private readonly string $restart='server'){}
    public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string
    {
        ++$this->starts;$this->payload=$clientPayload;$this->staticPublic=$staticPublic;
        $this->incoming=$this->pairing?->start($clientPayload,$staticPublic)??[];return str_repeat('s',32);
    }
    public function sendNode(BinaryNode $node):void
    {
        $this->sent[]=$node;
        if($this->autoIq&&$node->tag==='iq'&&in_array($node->attrs['type']??'',['get','set'],true)&&$node->child('link_code_companion_reg')===null){$this->incoming[]=new BinaryNode('iq',['type'=>'result','id'=>$node->attrs['id']]);return;}
        if($this->autoPing&&($node->attrs['xmlns']??'')==='w:p'){$this->incoming[]=new BinaryNode('iq',['type'=>'result','id'=>$node->attrs['id']]);return;}
        $link=$node->child('link_code_companion_reg');$stage=$link?->attrs['stage']??'';
        if($stage==='companion_hello'){
            ++$this->hellos;$this->lastHello=$node;if($this->onHello!==null)($this->onHello)();
            if($this->rejectRearm&&($link->attrs['should_show_push_notification']??'')==='false'){$this->incoming[]=new BinaryNode('iq',['type'=>'error','id'=>$node->attrs['id']],[new BinaryNode('error',['code'=>'403'])]);return;}
        }
        if($stage==='companion_finish')++$this->finishes;
        foreach($this->pairing?->handle($node)??[] as$reply){
            if($this->deferFinishResult&&$stage==='companion_finish'&&$reply->tag==='iq'&&($reply->attrs['type']??'')==='result'){$this->heldFinishResult=$reply;continue;}
            if($reply->tag==='stream:error'&&$this->restart!=='server'){if($this->restart==='close')$this->failReceive=true;continue;}
            $this->incoming[]=$reply;
        }
    }
    public function receiveNode(float $timeoutSeconds=1):?BinaryNode
    {
        if($this->failReceive){$this->failReceive=false;throw new \RuntimeException('WebSocket closed by peer (1000)');}
        if($this->incoming!==[])return array_shift($this->incoming);
        return $this->idleReader===null?null:($this->idleReader)($timeoutSeconds,$this->heldFinishResult);
    }
    public function close():void{$this->closed=true;}
}
