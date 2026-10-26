<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Protocol\BinaryNode;
use MVO\Message\MessageCodec;
use MVO\Signal\{SignalProtocol,SignalStore};
use MVO\Transport\{NodeSession,SessionRestartRequired};
use MVO\WaClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A decoded stanza or failed operation must remain bound to its original login. */
final class WaClientConnectionGenerationTest extends TestCase
{
    private string $directory;
    private float $time=0.0;
    private ?WaClient $client=null;

    protected function setUp():void
    {
        $this->directory=sys_get_temp_dir().'/mvo-generation-'.bin2hex(random_bytes(8));
        mkdir($this->directory,0700);
        $credentials=CredentialStore::fresh();$credentials['registered']=true;
        $credentials['meJid']='5511999999999:12@s.whatsapp.net';$credentials['serverHasPreKeys']=true;
        $store=new CredentialStore($this->directory);$store->save($credentials);$store->close();
    }

    protected function tearDown():void
    {
        $this->client?->stop();$this->client=null;gc_collect_cycles();
        foreach(glob($this->directory.'/*/*')?:[]as$path)unlink($path);
        foreach(glob($this->directory.'/*')?:[]as$path)rmdir($path);
        rmdir($this->directory);
    }

    /** @param callable():NodeSession $factory */
    private function create(callable $factory):WaClient
    {
        return $this->client=new WaClient(authDirectory:$this->directory,sessionFactory:$factory,
            automaticMaintenance:false,clock:fn():float=>$this->time+=0.01,random:static fn():float=>0.5);
    }

    /** @return array<string,mixed> */
    private function saved():array
    {
        $store=new CredentialStore($this->directory);$saved=$store->load();$store->close();
        self::assertNotNull($saved);return $saved;
    }

    /** @return array{WaClient,GenerationNodeSession,GenerationNodeSession} */
    private function pair():array
    {
        $old=new GenerationNodeSession('a');$new=new GenerationNodeSession('b');$opens=0;
        $client=$this->create(static function()use($old,$new,&$opens):NodeSession{return ++$opens===1?$old:$new;});
        return[$client,$old,$new];
    }

    private function finishReplacementLogin(WaClient $client,GenerationNodeSession $new):void
    {
        self::assertTrue($client->isRunning());self::assertSame('authenticating',$client->getState());
        self::assertSame(0,$new->closes);
        $new->incoming[]=new BinaryNode('success',['lid'=>'222@lid']);$client->poll(0);
        self::assertTrue($client->isConnected());self::assertSame(0,$new->closes);
    }

    public function testOldSuccessCannotAuthenticateReplacementLogin():void
    {
        [$client,$old,$new]=$this->pair();$client->connect();
        $client->once('node',static function()use($client):void{$client->stop();$client->connect();});
        $old->incoming[]=new BinaryNode('success',['lid'=>'111@lid']);$client->poll(0);
        self::assertSame('authenticating',$client->getState());self::assertFalse($client->isConnected());
        self::assertSame([],$new->sent);self::assertSame(0,$this->saved()['loginCounter']);
        $this->finishReplacementLogin($client,$new);
        self::assertSame(1,$this->saved()['loginCounter']);self::assertSame('222@lid',$this->saved()['meLid']);
    }

    /** @return iterable<string,array{BinaryNode}> */
    public static function staleFailures():iterable
    {
        yield'transient stream error'=>[new BinaryNode('stream:error',['code'=>'503'])];
        yield'revoked stream'=>[new BinaryNode('stream:error',['code'=>'401'])];
        yield'authentication rejection'=>[new BinaryNode('failure',['reason'=>'401'])];
        yield'restart request'=>[new BinaryNode('stream:error',['code'=>'515'])];
    }

    #[DataProvider('staleFailures')]
    public function testOldFailureCannotCloseOrRevokeReplacement(BinaryNode $failure):void
    {
        [$client,$old,$new]=$this->pair();$reconnects=0;$loggedOut=0;
        $client->on('reconnect',static function()use(&$reconnects):void{++$reconnects;});
        $client->on('logged_out',static function()use(&$loggedOut):void{++$loggedOut;});
        $client->connect();$client->once('node',static function()use($client):void{$client->stop();$client->connect();});
        $old->incoming[]=$failure;$client->poll(0);
        self::assertSame(1,$old->closes);self::assertSame(0,$new->closes);
        self::assertSame(0,$reconnects);self::assertSame(0,$loggedOut);self::assertFalse($this->saved()['revoked']??false);
        $this->finishReplacementLogin($client,$new);
    }

    public function testFailureThrownAfterNodeListenerReconnectDoesNotCloseNewSocket():void
    {
        [$client,$old,$new]=$this->pair();$client->connect();
        $client->once('node',static function()use($client):void{
            $client->stop();$client->connect();throw new \RuntimeException('WebSocket connection ended');
        });
        $old->incoming[]=new BinaryNode('unhandled-node');
        try{$client->poll(0);}catch(\RuntimeException $error){self::assertSame('WebSocket connection ended',$error->getMessage());}
        $this->finishReplacementLogin($client,$new);
    }

    public function testOldReceiveFailureAfterTransportCallbackReconnectIsIsolated():void
    {
        [$client,$old,$new]=$this->pair();$client->connect();
        $old->onReceive=static function()use($client):?BinaryNode{
            $client->stop();$client->connect();throw new \RuntimeException('WebSocket connection ended');
        };
        try{$client->poll(0);}catch(\RuntimeException $error){self::assertSame('WebSocket connection ended',$error->getMessage());}
        $this->finishReplacementLogin($client,$new);
    }

    public function testPayloadReturnedByReplacedTransportIsNotEmittedOrProcessed():void
    {
        [$client,$old,$new]=$this->pair();$client->connect();$nodes=0;
        $client->on('node',static function()use(&$nodes):void{++$nodes;});
        $old->onReceive=static function()use($client):?BinaryNode{
            $client->stop();$client->connect();return new BinaryNode('success',['lid'=>'111@lid']);
        };
        $client->poll(0);self::assertSame(0,$nodes);self::assertSame([],$new->sent);
        $this->finishReplacementLogin($client,$new);self::assertGreaterThan(0,$nodes);
    }

    public function testOldWriteFailureCannotCloseReplacementOrReplayMessage():void
    {
        [$client,$old,$new]=$this->pair();$client->connect();
        $old->onSend=static function(BinaryNode $node)use($client):void{
            self::assertSame('uncertain-message',$node->attrs['id']);
            $client->stop();$client->connect();throw new \RuntimeException('WebSocket write failed');
        };
        $error=null;
        try{$client->query(new BinaryNode('message',['id'=>'uncertain-message']),0.3);}catch(\Throwable $failure){$error=$failure;}
        self::assertInstanceOf(SessionRestartRequired::class,$error);
        self::assertCount(1,$old->sent);self::assertSame([],$new->sent);
        $this->finishReplacementLogin($client,$new);
        self::assertSame([],array_values(array_filter($new->sent,static fn(BinaryNode $node):bool=>$node->tag==='message')));
    }

    public function testLowLevelHandlerReconnectCannotReplyToOldPingOnNewSocket():void
    {
        [$client,$old,$new]=$this->pair();$client->connect();
        $client->registerIncomingHandler(static function(BinaryNode $node)use($client):bool{
            if(($node->attrs['id']??'')==='old-ping'){$client->stop();$client->connect();}
            return false;
        });
        $old->incoming[]=new BinaryNode('iq',['id'=>'old-ping','type'=>'get','from'=>'s.whatsapp.net'],[new BinaryNode('ping')]);
        $client->poll(0);self::assertSame([],$new->sent);$this->finishReplacementLogin($client,$new);
    }

    public function testStanzaFilterReconnectCannotAcknowledgeOldNotificationOnNewSocket():void
    {
        [$client,$old,$new]=$this->pair();$client->connect();
        $client->registerIncomingStanzaFilter(static function(BinaryNode $node)use($client):bool{
            if(($node->attrs['id']??'')==='old-notification'){$client->stop();$client->connect();return true;}
            return false;
        });
        $old->incoming[]=new BinaryNode('notification',['id'=>'old-notification','from'=>'s.whatsapp.net','type'=>'fixture']);
        $client->poll(0);self::assertSame([],$new->sent);$this->finishReplacementLogin($client,$new);
    }

    public function testAuthenticatedMessageCallbackCannotSendOldReceiptThroughReplacement():void
    {
        [$client,$old,$new]=$this->pair();$client->connect();
        $old->incoming[]=new BinaryNode('success');$client->poll(0);self::assertTrue($client->isConnected());
        $own=$this->saved();$peerCredentials=CredentialStore::fresh();$peerStore=new SignalStore(':memory:');
        try{
            $peer=new SignalProtocol($peerStore,$peerCredentials);
            $peer->ensureSession($own['meJid'],['registrationId'=>$own['registrationId'],'identityKey'=>$own['identityKeyPair']['public'],
                'signedPreKey'=>['id'=>$own['signedPreKey']['id'],'public'=>$own['signedPreKey']['public'],'signature'=>$own['signedPreKey']['signature']]]);
            $encrypted=$peer->encrypt($own['meJid'],MessageCodec::pad(MessageCodec::text('Authenticated old-session message')));
            $delivered=[];$client->once('message',static function(array $event)use($client,&$delivered):void{
                $delivered[]=$event;$client->stop();$client->connect();
            });
            $old->incoming[]=new BinaryNode('message',['id'=>'old-authenticated-message','from'=>'5522000000000:2@s.whatsapp.net','t'=>'1700000000'],[
                new BinaryNode('enc',['type'=>$encrypted['type'],'v'=>'2'],$encrypted['ciphertext'])]);
            $client->poll(0);self::assertCount(1,$delivered);self::assertSame('Authenticated old-session message',$delivered[0]['text']);
            self::assertSame([],$new->sent,'The old MessageService must not send its receipt while the replacement is still authenticating');
            $this->finishReplacementLogin($client,$new);self::assertCount(1,$delivered);
        }finally{$peerStore->close();}
    }

    public function testOldQueryStopsBeforeReadingReplacementSession():void
    {
        [$client,$old,$new]=$this->pair();$client->connect();$old->autoReply=false;
        $old->incoming[]=new BinaryNode('iq',['id'=>'old-query','type'=>'result']);
        $new->incoming[]=new BinaryNode('iq',['id'=>'old-query','type'=>'result','source'=>'new-login']);
        $client->once('node',static function()use($client):void{$client->stop();$client->connect();});
        $error=null;
        try{$client->query(new BinaryNode('iq',['id'=>'old-query','type'=>'get']),0.3);}catch(\Throwable $failure){$error=$failure;}
        self::assertInstanceOf(SessionRestartRequired::class,$error);
        self::assertSame(0,$new->receives,'The stale query must not read a replacement transport');
        self::assertSame(0,$new->closes);self::assertSame([],$new->sent);
        $new->incoming=[];$this->finishReplacementLogin($client,$new);
    }

    public function testQueryRecoveryListenerReconnectInterruptsOldQueryWithOriginalCause():void
    {
        [$client,$old,$new]=$this->pair();$client->connect();$old->autoReply=false;
        $cause=new \RuntimeException('WebSocket connection ended');
        $old->onReceive=static function()use($cause):?BinaryNode{throw $cause;};
        $client->once('reconnect',static function()use($client):void{$client->stop();$client->connect();});
        $failure=null;
        try{$client->query(new BinaryNode('iq',['id'=>'old-recovery-query','type'=>'get']),0.3);}catch(\Throwable $error){$failure=$error;}
        self::assertInstanceOf(SessionRestartRequired::class,$failure);self::assertSame($cause,$failure->getPrevious());
        self::assertSame(1,$old->closes);self::assertCount(1,$old->sent);self::assertSame([],$new->sent);
        $this->finishReplacementLogin($client,$new);
    }

    public function testQueryRecoveryWithoutReplacementPreservesOriginalErrorAndNeverReplays():void
    {
        [$client,$old,$new]=$this->pair();$client->connect();$old->autoReply=false;
        $cause=new \RuntimeException('WebSocket connection ended');
        $old->onReceive=static function()use($cause):?BinaryNode{throw $cause;};
        $failure=null;
        try{$client->query(new BinaryNode('iq',['id'=>'failed-query-no-replay','type'=>'get']),0.3);}catch(\Throwable $error){$failure=$error;}
        self::assertSame($cause,$failure);self::assertSame('reconnecting',$client->getState());
        self::assertSame(1,$old->closes);self::assertCount(1,$old->sent);self::assertSame(0,$new->starts);
        $this->time+=2;$client->poll(0);self::assertSame([],$new->sent);
        $this->finishReplacementLogin($client,$new);
        self::assertSame([],array_values(array_filter($new->sent,static fn(BinaryNode $node):bool=>($node->attrs['id']??'')==='failed-query-no-replay')));
    }

    public function testOldQueryCleanupCannotEraseSameIdPendingInReplacementFiber():void
    {
        [$client,$old,$new]=$this->pair();$client->connect();$old->autoReply=false;$new->autoReply=false;
        $old->incoming[]=new BinaryNode('iq',['id'=>'shared-id','type'=>'result','source'=>'old']);
        $newQuery=null;$newResponse=null;
        $client->once('node',static function()use($client,$new,&$newQuery,&$newResponse):void{
            $client->stop();$client->connect();
            $new->onReceive=static function():?BinaryNode{\Fiber::suspend('new-query-pending');return new BinaryNode('iq',['id'=>'shared-id','type'=>'result','source'=>'new']);};
            $newQuery=new \Fiber(static function()use($client,&$newResponse):void{$newResponse=$client->query(new BinaryNode('iq',['id'=>'shared-id','type'=>'get']),0.3);});
            self::assertSame('new-query-pending',$newQuery->start());
        });
        $oldError=null;
        try{$client->query(new BinaryNode('iq',['id'=>'shared-id','type'=>'get']),0.3);}catch(\Throwable $error){$oldError=$error;}
        self::assertInstanceOf(SessionRestartRequired::class,$oldError);
        self::assertInstanceOf(\Fiber::class,$newQuery);self::assertTrue($newQuery->isSuspended());
        $newQuery->resume();self::assertTrue($newQuery->isTerminated());
        self::assertInstanceOf(BinaryNode::class,$newResponse);self::assertSame('new',$newResponse->attrs['source']);
        self::assertSame(0,$new->closes);$new->autoReply=true;
        $this->finishReplacementLogin($client,$new);
    }

    /** @return iterable<string,array{bool}> */
    public static function outcome():iterable{yield'late success'=>[false];yield'late failure'=>[true];}

    #[DataProvider('outcome')]
    public function testOldHandshakeResultOrFailureCannotAffectNewLogin(bool $throw):void
    {
        [$client,$old,$new]=$this->pair();
        $old->onStart=static function()use($client,$throw):void{
            $client->stop();$client->connect();
            if($throw)throw new \RuntimeException('Noise server hello timed out');
        };
        try{$client->connect();}catch(\RuntimeException $error){self::assertSame('Noise server hello timed out',$error->getMessage());}
        self::assertSame(str_repeat('b',32),$this->saved()['serverStaticKey']);
        self::assertSame(1,$old->starts);self::assertSame(1,$old->closes);
        $this->finishReplacementLogin($client,$new);
    }

    #[DataProvider('outcome')]
    public function testOldFactoryResultOrFailureCannotReplaceNewTransport(bool $throw):void
    {
        $old=new GenerationNodeSession('a');$new=new GenerationNodeSession('b');$opens=0;$client=null;
        $client=$this->create(static function()use(&$client,&$opens,$old,$new,$throw):NodeSession{
            if(++$opens===1){
                $client->stop();$client->connect();
                if($throw)throw new \RuntimeException('WebSocket connection failed: obsolete attempt');
                return $old;
            }
            return $new;
        });
        try{$client->connect();}catch(\RuntimeException $error){self::assertSame('WebSocket connection failed: obsolete attempt',$error->getMessage());}
        self::assertSame(0,$old->starts);self::assertSame($throw?0:1,$old->closes);
        self::assertSame(2,$opens);self::assertSame(str_repeat('b',32),$this->saved()['serverStaticKey']);
        $this->finishReplacementLogin($client,$new);
    }

    public function testReconnectDuringConnectingEventDoesNotOpenSecondOuterSocket():void
    {
        $session=new GenerationNodeSession('b');$opens=0;
        $client=$this->create(static function()use($session,&$opens):NodeSession{++$opens;return $session;});
        $client->once('connection',static function(array $event)use($client):void{
            self::assertSame('connecting',$event['state']);$client->stop();$client->connect();
        });
        $client->connect();self::assertSame(1,$opens);self::assertSame(1,$session->starts);
        $this->finishReplacementLogin($client,$session);
    }

    public function testFatalPollErrorListenerCanReconnectWithoutOldFailureEscaping():void
    {
        [$client,$old,$new]=$this->pair();$client->connect();$errors=0;
        $client->once('error',static function(array $event)use($client,&$errors):void{
            ++$errors;self::assertFalse($event['retrying']);$client->connect();
        });
        $old->onReceive=static function():?BinaryNode{throw new \UnexpectedValueException('Noise MAC mismatch');};
        $client->poll(0);
        self::assertSame(1,$errors);self::assertSame(1,$old->closes);
        $this->finishReplacementLogin($client,$new);
    }

    public function testFatalFactoryErrorListenerCanConnectReplacementWithoutOldFailureEscaping():void
    {
        $new=new GenerationNodeSession('b');$opens=0;$errors=0;
        $client=$this->create(static function()use($new,&$opens):NodeSession{
            if(++$opens===1)throw new \UnexpectedValueException('Server refused WebSocket upgrade: HTTP/1.1 403 refused');
            return $new;
        });
        $client->once('error',static function(array $event)use($client,&$errors):void{
            ++$errors;self::assertFalse($event['retrying']);$client->connect();
        });
        $client->connect();
        self::assertSame(2,$opens);self::assertSame(1,$errors);self::assertSame(1,$new->starts);
        $this->finishReplacementLogin($client,$new);
    }

    public function testStoppingInNodeCallbackDoesNotRestartOrAuthenticate():void
    {
        [$client,$old,$new]=$this->pair();$client->connect();
        $client->once('node',static function()use($client):void{$client->stop();});
        $old->incoming[]=new BinaryNode('success');$client->poll(0);
        self::assertFalse($client->isRunning());self::assertFalse($client->isConnected());
        self::assertSame('stopped',$client->getState());self::assertSame(1,$old->closes);self::assertSame(0,$new->starts);
    }

    public function testOrdinaryTransientReconnectStillRestoresSameIdentity():void
    {
        [$client,$old,$new]=$this->pair();$before=$this->saved();$client->connect();
        $old->incoming[]=new BinaryNode('success');$client->poll(0);self::assertTrue($client->isConnected());
        $old->incoming[]=new BinaryNode('stream:error',['code'=>'503']);$client->poll(0);
        self::assertSame('reconnecting',$client->getState());self::assertSame(1,$old->closes);
        $this->time+=2;$client->poll(0);$this->finishReplacementLogin($client,$new);
        self::assertSame($before['identityKeyPair'],$this->saved()['identityKeyPair']);
        self::assertSame($before['noiseKeyPair'],$this->saved()['noiseKeyPair']);self::assertSame(2,$this->saved()['loginCounter']);
    }
}

/** Test peer: callbacks can replace a transport while an old operation is on the stack. */
final class GenerationNodeSession implements NodeSession
{
    /** @var list<BinaryNode> */public array $incoming=[];
    /** @var list<BinaryNode> */public array $sent=[];
    public int $starts=0;public int $closes=0;public int $receives=0;
    public bool $autoReply=true;
    public ?\Closure $onStart=null;public ?\Closure $onReceive=null;public ?\Closure $onSend=null;
    public function __construct(private readonly string $serverKeyCharacter){}
    public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string
    {++$this->starts;($this->onStart)?->__invoke();return str_repeat($this->serverKeyCharacter,32);}
    public function sendNode(BinaryNode $node):void
    {
        $this->sent[]=$node;
        ($this->onSend)?->__invoke($node);
        if($this->autoReply&&$node->tag==='iq'&&in_array($node->attrs['type']??'',['get','set'],true)){
            $content=null;
            if(($node->attrs['xmlns']??'')==='w:sync:app:state')$content=[new BinaryNode('sync',[],array_map(static fn(BinaryNode $collection):BinaryNode=>new BinaryNode('collection',['name'=>$collection->attrs['name'],'version'=>$collection->attrs['version']]),$node->child('sync')->content))];
            $this->incoming[]=new BinaryNode('iq',['id'=>$node->attrs['id'],'type'=>'result'],$content);
        }
    }
    public function receiveNode(float $timeoutSeconds=1.0):?BinaryNode
    {
        ++$this->receives;
        if($this->onReceive!==null){$callback=$this->onReceive;$this->onReceive=null;return $callback();}
        return array_shift($this->incoming);
    }
    public function close():void{++$this->closes;}
}
