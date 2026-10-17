<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\{CredentialStore,PairingFlow};
use MVO\Protocol\BinaryNode;
use MVO\Testing\FakePairing;
use MVO\Transport\{NodeSession,NoiseSession,WebSocket};
use MVO\WaClient;
use PHPUnit\Framework\TestCase;

final class WaClientPairingTransportTest extends TestCase
{
    private string $directory;
    private float $time=0.0;
    /** @var list<WaClient> */
    private array $clients=[];

    protected function setUp():void
    {
        $this->directory=sys_get_temp_dir().'/mvo-pairing-transport-'.bin2hex(random_bytes(8));
        mkdir($this->directory,0700);
    }

    protected function tearDown():void
    {
        foreach ($this->clients as $client) $client->disconnect();
        $this->clients=[];gc_collect_cycles();
        foreach (glob($this->directory.'/*/*') ?: [] as $file) unlink($file);
        foreach (glob($this->directory.'/*') ?: [] as $directory) rmdir($directory);
        rmdir($this->directory);
    }

    private function peer():PairingKeepAliveSession
    {
        $primary=CredentialStore::fresh();$primary['registered']=true;$primary['meJid']='5511999999999@s.whatsapp.net';
        return new PairingKeepAliveSession(new FakePairing($primary,7,fn():float=>$this->time));
    }

    /** @param callable():NodeSession $factory */
    private function client(callable $factory,bool $autoReconnect=true):WaClient
    {
        $client=new WaClient(authDirectory:$this->directory,sessionFactory:$factory,autoReconnect:$autoReconnect,clock:fn():float=>$this->time,random:static fn():float=>0.5,automaticMaintenance:false);
        $this->clients[]=$client;return $client;
    }

    public function testCodeCanWaitFiftySecondsAndThenVerifyThePrimaryWithoutClaimingConnected():void
    {
        $peer=$this->peer();$client=$this->client(fn():NodeSession=>$peer);$states=[];
        $client->on('connection',static function(array $event)use(&$states):void{$states[]=$event['state'];});
        $client->connect();$client->poll(0);
        $code=$client->requestPairingCode('5511999999999','ABCD1234');
        foreach ([19.9,20.0,39.9,40.0,50.0] as $time) {$this->time=$time;$client->poll(0);}
        self::assertSame(2,$peer->pings);self::assertSame('pairing',$client->getState());
        self::assertFalse($client->isConnected());self::assertFalse($client->isRegistered());
        self::assertSame(['connecting','pairing'],$states);
        self::assertCount(3,array_filter($peer->sent,static fn(BinaryNode $node):bool=>($node->attrs['type']??'')==='get' || $node->child('link_code_companion_reg')!==null));
        $peer->incoming[]=$peer->pairing->approveCode($code);
        $client->poll(0);$client->poll(0);
        self::assertTrue($client->isRegistered());self::assertFalse($client->isConnected());
        self::assertSame('5511999999999:7@s.whatsapp.net',$client->accountJid());
        self::assertNotNull($peer->pairing->completeCredentials());
        self::assertSame(1,$peer->finishes);
    }

    public function testPrimaryApprovalAnd515DuringPingReconnectWithoutReplayingQueries():void
    {
        $peer=$this->peer();$resume=new PairingKeepAliveSession();$opens=0;
        $client=$this->client(static function()use($peer,$resume,&$opens):NodeSession{return ++$opens===1?$peer:$resume;});
        $client->connect();$client->poll(0);$code=$client->requestPairingCode('5511999999999','ABCD1234');
        $peer->onPing=static function()use($peer,$code):void{$peer->incoming[]=$peer->pairing->approveCode($code);};
        $peer->replyToPing=false;$this->time=20.0;$client->poll(0);
        self::assertSame(2,$opens);self::assertSame('authenticating',$client->getState());
        self::assertTrue($client->isRegistered());self::assertFalse($client->isConnected());
        self::assertTrue($peer->closed);self::assertSame(1,$peer->pings);self::assertSame(1,$peer->finishes);
        self::assertSame([],$resume->sent);self::assertNotNull($peer->pairing->completeCredentials());
    }

    public function testStoppingInThePingReplyCannotSendMoreQueriesOrMarkConnected():void
    {
        $peer=$this->peer();$client=$this->client(fn():NodeSession=>$peer);$client->connect();$client->poll(0);
        $client->on('node',static function(array $event)use($client):void{if(($event['node']->attrs['id']??'')==='stopping-ping')$client->stop();});
        $peer->onPing=static function()use($peer):void{$peer->incoming[]=new BinaryNode('notice',['id'=>'stopping-ping']);};
        $this->time=20.0;$client->poll(0);
        self::assertSame('stopped',$client->getState());self::assertFalse($client->isRunning());
        self::assertFalse($client->isConnected());self::assertSame(1,$peer->pings);
    }

    public function testUnregisteredFactoryUsesXxWhileRetainingCachedKeysAndRouting():void
    {
        $credentials=CredentialStore::fresh();$credentials['serverStaticKey']=str_repeat('k',32);$credentials['routingInfo']='saved-route';
        $this->save($credentials);$peer=$this->peer();$client=$this->client(fn():NodeSession=>$peer);$client->connect();
        $session=$this->productionSession($client);
        self::assertNull((new \ReflectionProperty(NoiseSession::class,'serverStaticKey'))->getValue($session));
        self::assertSame('saved-route',(new \ReflectionProperty(NoiseSession::class,'routingInfo'))->getValue($session));
        $saved=$this->load();self::assertSame($credentials['noiseKeyPair'],$saved['noiseKeyPair']);
        self::assertSame($credentials['identityKeyPair'],$saved['identityKeyPair']);self::assertSame($credentials['advSecretKey'],$saved['advSecretKey']);
        self::assertIsString($saved['serverStaticKey']);self::assertFalse($saved['registered']);
    }

    public function testRegisteredFactoryCanResumeWithItsAuthenticatedServerKey():void
    {
        $credentials=CredentialStore::fresh();$credentials['registered']=true;$credentials['meJid']='5511999999999:7@s.whatsapp.net';
        $this->save($credentials);$client=$this->client(static fn():NodeSession=>new PairingKeepAliveSession());$client->connect();
        $session=$this->productionSession($client);
        self::assertSame(str_repeat('s',32),(new \ReflectionProperty(NoiseSession::class,'serverStaticKey'))->getValue($session));
        self::assertSame('authenticating',$client->getState());self::assertFalse($client->isConnected());
    }

    public function testLastQrCanExpireWhileTheCodeRemainsValidAndItsPrimaryProofStillWorks():void
    {
        $peer=$this->peer();$opens=0;$client=$this->client(static function()use($peer,&$opens):NodeSession{++$opens;return $peer;});
        $client->connect();$client->poll(0);$code=$client->requestPairingCode('5511999999999','ABCD1234');
        $this->expireQrRefs($client);$client->poll(0);
        self::assertSame(1,$opens);self::assertSame('pairing',$client->getState());self::assertFalse($peer->closed);
        $peer->incoming[]=$peer->pairing->approveCode($code);$client->poll(0);$client->poll(0);
        self::assertTrue($client->isRegistered());self::assertNotNull($peer->pairing->completeCredentials());self::assertSame(1,$opens);
    }

    public function testCodeExpiryAfterLastQrRestartsAndObtainsNewReferencesWithoutHelloTimeout():void
    {
        $peers=[$this->peer(),$this->peer(),$this->peer()];$opens=0;$reconnects=[];
        $client=$this->client(static function()use($peers,&$opens):NodeSession{return $peers[$opens++];});
        $client->on('reconnect',static function(array $event)use(&$reconnects):void{$reconnects[]=$event;});
        $client->connect();$client->poll(0);$client->requestPairingCode('5511999999999','ABCD1234');
        $original=$this->load();
        for($round=0;$round<2;++$round) {
            $this->expireQrRefs($client);$client->poll(0);self::assertSame($round+1,$opens);
            $this->expireCode($client);$client->poll(0);self::assertSame($round+2,$opens);
            self::assertTrue($peers[$round]->closed);self::assertSame('pairing',$client->getState());
            // No references have been read yet on the new transport. The public
            // request waits for them and sends exactly one fresh companion hello.
            self::assertSame('ABCD1234',$client->requestPairingCode('5511999999999','ABCD1234'));
            self::assertSame(1,$peers[$round+1]->hellos);
        }
        self::assertSame([0.0,0.0],array_column($reconnects,'delay'));
        $saved=$this->load();self::assertSame($original['identityKeyPair'],$saved['identityKeyPair']);self::assertSame($original['noiseKeyPair'],$saved['noiseKeyPair']);
        self::assertFalse($client->isConnected());self::assertFalse($client->isRegistered());
    }

    public function testExpiredQrWithoutAnActiveCodeRefreshesBeforeAnotherHelloIsRequested():void
    {
        $peers=[$this->peer(),$this->peer()];$opens=0;
        $client=$this->client(static function()use($peers,&$opens):NodeSession{return $peers[$opens++];});
        $client->connect();$client->poll(0);$this->expireQrRefs($client);$client->poll(0);
        self::assertSame(2,$opens);self::assertTrue($peers[0]->closed);self::assertSame(0,$peers[0]->hellos);
        self::assertSame('ABCD1234',$client->requestPairingCode('5511999999999','ABCD1234'));self::assertSame(1,$peers[1]->hellos);
    }

    public function testCodeHelloInFlightCanOutliveTheLastQrWithoutBeingDiscarded():void
    {
        $peer=$this->peer();$opens=0;$client=$this->client(static function()use($peer,&$opens):NodeSession{++$opens;return $peer;});
        $client->connect();$client->poll(0);$peer->onHello=function()use($client):void{$this->expireQrRefs($client);};
        self::assertSame('ABCD1234',$client->requestPairingCode('5511999999999','ABCD1234'));$client->poll(0);
        self::assertSame(1,$opens);self::assertFalse($peer->closed);self::assertSame(1,$peer->hellos);
        $peer->incoming[]=$peer->pairing->approveCode('ABCD1234');$client->poll(0);$client->poll(0);
        self::assertTrue($client->isRegistered());self::assertSame(1,$peer->finishes);
    }

    public function testDisabledReconnectKeepsAnExpiredRegistrationManual():void
    {
        $peer=$this->peer();$opens=0;$client=$this->client(static function()use($peer,&$opens):NodeSession{++$opens;return $peer;},false);
        $client->connect();$client->poll(0);$client->requestPairingCode('5511999999999','ABCD1234');
        $this->expireQrRefs($client);$this->expireCode($client);$client->poll(0);
        self::assertSame(1,$opens);self::assertSame('pairing',$client->getState());self::assertFalse($peer->closed);self::assertTrue($client->isRunning());
    }

    public function testStoppingInCodeExpiryCallbackCannotRefreshExhaustedReferences():void
    {
        $peer=$this->peer();$opens=0;$client=$this->client(static function()use($peer,&$opens):NodeSession{++$opens;return $peer;});
        $client->connect();$client->poll(0);$client->requestPairingCode('5511999999999','ABCD1234');
        $client->on('auth_code_expired',static function()use($client):void{$client->stop();});
        $this->expireQrRefs($client);$this->expireCode($client);$client->poll(0);
        self::assertSame(1,$opens);self::assertSame('stopped',$client->getState());self::assertFalse($client->isRunning());self::assertTrue($peer->closed);
    }

    public function testServerCodeRefreshAlsoRenewsExhaustedReferencesOutsideCallbacks():void
    {
        foreach(['false','true'] as $manual) {
            $peers=[$this->peer(),$this->peer()];$opens=0;$observed=[];
            $client=$this->client(static function()use($peers,&$opens):NodeSession{return $peers[$opens++];});
            $client->connect();$client->poll(0);$client->requestPairingCode('5511999999999','ABCD1234');
            $flow=$this->flow($client);$session=(new \ReflectionProperty(PairingFlow::class,'session'))->getValue($flow);
            $this->expireQrRefs($client);$client->poll(0);
            $client->on('auth_code_refresh',static function(array $event)use(&$observed,&$opens):void{$observed[]=[$opens,$event['forceManual']];});
            $peers[0]->incoming[]=new BinaryNode('notification',['id'=>'refresh-test','type'=>'link_code_companion_reg','from'=>'s.whatsapp.net'],[
                new BinaryNode('link_code_companion_reg',['stage'=>'refresh_code','force_manual_refresh'=>$manual],[new BinaryNode('link_code_pairing_ref',[],$session['ref'])]),
            ]);
            $client->poll(0);
            self::assertSame([[1,$manual==='true']],$observed,'Callbacks must see the current transport; renewal happens afterwards');
            self::assertSame(2,$opens);self::assertTrue($peers[0]->closed);self::assertFalse($client->isConnected());
            $client->disconnect();
        }
    }

    public function testExpiredCodeCanReuseQrReferencesThatAreStillValid():void
    {
        $peer=$this->peer();$opens=0;$client=$this->client(static function()use($peer,&$opens):NodeSession{++$opens;return $peer;});
        $client->connect();$client->poll(0);$client->requestPairingCode('5511999999999','ABCD1234');
        $this->expireCode($client);$client->poll(0);self::assertSame(1,$opens);self::assertFalse($peer->closed);
        self::assertSame('ABCD1234',$client->requestPairingCode('5511999999999','ABCD1234'));self::assertSame(2,$peer->hellos);
    }

    private function flow(WaClient $client):PairingFlow
    {
        $flow=(new \ReflectionProperty(WaClient::class,'pairing'))->getValue($client);self::assertInstanceOf(PairingFlow::class,$flow);return $flow;
    }
    private function expireQrRefs(WaClient $client):void
    {
        $flow=$this->flow($client);$refs=(new \ReflectionProperty(PairingFlow::class,'qrRefs'))->getValue($flow);
        (new \ReflectionProperty(PairingFlow::class,'qrIndex'))->setValue($flow,count($refs));
        (new \ReflectionProperty(PairingFlow::class,'qrDeadline'))->setValue($flow,microtime(true)-1);
    }
    private function expireCode(WaClient $client):void
    {
        $flow=$this->flow($client);$property=new \ReflectionProperty(PairingFlow::class,'session');$session=$property->getValue($flow);
        self::assertIsArray($session);$session['createdAt']=microtime(true)-181;$property->setValue($flow,$session);
    }

    private function productionSession(WaClient $client):NoiseSession
    {
        return (new \ReflectionMethod(WaClient::class,'createNoiseSession'))->invoke($client,new WebSocket());
    }
    /** @param array<string,mixed> $credentials */
    private function save(array $credentials):void {$store=new CredentialStore($this->directory);$store->save($credentials);$store->close();}
    /** @return array<string,mixed> */
    private function load():array {$store=new CredentialStore($this->directory);$credentials=$store->load();$store->close();self::assertNotNull($credentials);return $credentials;}
}

/** Only the Noise socket is substituted; QR/code identity proofs use production crypto. */
final class PairingKeepAliveSession implements NodeSession
{
    /** @var list<BinaryNode> */
    public array $incoming=[];
    /** @var list<BinaryNode> */
    public array $sent=[];
    public int $pings=0;
    public int $hellos=0;
    public int $finishes=0;
    public bool $replyToPing=true;
    public bool $closed=false;
    public ?\Closure $onPing=null;
    public ?\Closure $onHello=null;
    public function __construct(public readonly ?FakePairing $pairing=null) {}
    public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string
    {
        $this->incoming=$this->pairing?->start($clientPayload,$staticPublic)??[];
        return str_repeat('s',32);
    }
    public function sendNode(BinaryNode $node):void
    {
        $this->sent[]=$node;
        if (($node->attrs['xmlns']??'')==='w:p') {
            ++$this->pings;if($this->onPing!==null)($this->onPing)();
            if($this->replyToPing)$this->incoming[]=new BinaryNode('iq',['type'=>'result','id'=>$node->attrs['id']]);
            return;
        }
        $stage=$node->child('link_code_companion_reg')?->attrs['stage']??'';
        if($stage==='companion_hello'){++$this->hellos;if($this->onHello!==null)($this->onHello)();}
        if($stage==='companion_finish')++$this->finishes;
        foreach ($this->pairing?->handle($node)??[] as $reply)$this->incoming[]=$reply;
    }
    public function receiveNode(float $timeoutSeconds=1.0):?BinaryNode {return array_shift($this->incoming);}
    public function close():void {$this->closed=true;}
}
