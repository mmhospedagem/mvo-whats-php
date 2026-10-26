<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Plugin\{PluginContext,PluginDefinition};
use MVO\Protocol\BinaryNode;
use MVO\Transport\NodeSession;
use MVO\WaClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Cleanup may stop again; only a genuinely new login supersedes the outer stop. */
final class WaClientReentrantStopTest extends TestCase
{
    private string $directory;
    private ?WaClient $client=null;
    private bool $reconnectDuringCleanup=false;
    private int $cleanups=0;
    private float $time=0;
    /** @var list<string> */private array $states=[];

    protected function setUp():void
    {
        $this->directory=sys_get_temp_dir().'/mvo-reentrant-stop-'.bin2hex(random_bytes(8));
        mkdir($this->directory,0700);
        $credentials=CredentialStore::fresh();$credentials['registered']=true;
        $credentials['meJid']='5511999999999:12@s.whatsapp.net';$credentials['serverHasPreKeys']=true;
        $store=new CredentialStore($this->directory);$store->save($credentials);$store->close();
    }

    protected function tearDown():void
    {
        $this->reconnectDuringCleanup=false;$this->client?->stop();$this->client=null;gc_collect_cycles();
        foreach(glob($this->directory.'/*/*')?:[]as$path)unlink($path);
        foreach(glob($this->directory.'/*')?:[]as$path)rmdir($path);
        rmdir($this->directory);
    }

    /** @return iterable<string,array{bool}> */
    public static function loginStates():iterable {yield 'authenticating'=>[false];yield 'connected'=>[true];}

    /** @return array{WaClient,ReentrantStopNodeSession,ReentrantStopNodeSession} */
    private function create(bool $plugin=true):array
    {
        $first=new ReentrantStopNodeSession();$second=new ReentrantStopNodeSession();$opens=0;
        $plugins=$plugin?[new PluginDefinition('cleanup-stop',function(PluginContext $context):void{
            $context->registerDispose(function()use($context):void{
                ++$this->cleanups;$context->client->stop();
                if($this->reconnectDuringCleanup){$this->reconnectDuringCleanup=false;$context->client->connect();}
            });
        })]:[];
        $client=$this->client=new WaClient(authDirectory:$this->directory,
            sessionFactory:static function()use(&$opens,$first,$second):NodeSession{return ++$opens===1?$first:$second;},
            plugins:$plugins,automaticMaintenance:false,clock:fn():float=>$this->time+=0.01);
        $client->on('connection',function(array $event):void{$this->states[]=$event['state'];});
        return[$client,$first,$second];
    }

    private function login(WaClient $client,ReentrantStopNodeSession $session,bool $finish):void
    {
        $client->connect();
        if($finish){$session->incoming[]=new BinaryNode('success');$client->poll(0);}
        self::assertSame($finish?'connected':'authenticating',$client->getState());
    }

    private function assertStopped(WaClient $client,ReentrantStopNodeSession $session):void
    {
        self::assertFalse($client->isRunning());self::assertFalse($client->isConnected());
        self::assertSame('stopped',$client->getState());self::assertSame(1,$session->closes);
        self::assertSame(1,count(array_filter($this->states,static fn(string $state):bool=>$state==='stopped')));
        $observer=new CredentialStore($this->directory);
        try{$observer->acquire();self::assertTrue($observer->ownsLock(),'The completed stop must release the session lock');}
        finally{$observer->close();}
    }

    #[DataProvider('loginStates')]
    public function testPluginCleanupCanStopAgainWithoutSuppressingStoppedState(bool $finish):void
    {
        [$client,$first]=$this->create();$this->login($client,$first,$finish);
        $client->stop();$this->assertStopped($client,$first);self::assertSame(1,$this->cleanups);
        // Stop is also idempotent after the callback has completed.
        $client->stop();$client->disconnect();$this->assertStopped($client,$first);self::assertSame(1,$this->cleanups);
    }

    #[DataProvider('loginStates')]
    public function testPluginCleanupCanStillOpenAnActualReplacement(bool $finish):void
    {
        [$client,$first,$second]=$this->create();$this->login($client,$first,$finish);
        $this->reconnectDuringCleanup=true;$client->stop();
        self::assertTrue($client->isRunning());self::assertSame('authenticating',$client->getState());
        self::assertSame(1,$first->closes);self::assertSame(0,$second->closes);self::assertSame(1,$second->starts);
        self::assertNotContains('stopped',$this->states,'The outer stop must not override a genuine new login');
        $observer=new CredentialStore($this->directory);
        try{$observer->acquire();self::fail('The replacement must keep its session lock');}
        catch(\RuntimeException $error){self::assertStringContainsString('already in use',$error->getMessage());}
        finally{$observer->close();}
        $second->incoming[]=new BinaryNode('success');$client->poll(0);self::assertTrue($client->isConnected());
        $client->stop();$this->assertStopped($client,$second);self::assertSame(2,$this->cleanups);
    }

    #[DataProvider('loginStates')]
    public function testTransportCloseCanStopAgainAndStillReleaseTheLock(bool $finish):void
    {
        [$client,$first]=$this->create(false);$this->login($client,$first,$finish);
        $first->onClose=static function()use($client):void{$client->stop();};
        $client->stop();$this->assertStopped($client,$first);
    }

    #[DataProvider('loginStates')]
    public function testFatalFailureCleanupDoesNotLeaveAConnectedState(bool $finish):void
    {
        [$client,$first]=$this->create();$this->login($client,$first,$finish);$errors=0;
        $client->on('error',static function()use(&$errors):void{++$errors;});
        $first->failure=new \UnexpectedValueException('Invalid WebSocket close payload');
        $caught=null;try{$client->poll(0);}catch(\UnexpectedValueException $error){$caught=$error;}
        self::assertSame($first->failure,$caught);self::assertSame(1,$errors);
        self::assertFalse($client->isRunning());self::assertFalse($client->isConnected());
        self::assertSame('disconnected',$client->getState());self::assertSame(1,$this->cleanups);
        $observer=new CredentialStore($this->directory);
        try{$observer->acquire();self::assertTrue($observer->ownsLock());}finally{$observer->close();}
    }
}

final class ReentrantStopNodeSession implements NodeSession
{
    /** @var list<BinaryNode> */public array $incoming=[];
    public int $starts=0;public int $closes=0;
    public ?\Closure $onClose=null;
    public ?\Throwable $failure=null;
    public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string{++$this->starts;return str_repeat('s',32);}
    public function sendNode(BinaryNode $node):void
    {
        if($node->tag==='iq'&&in_array($node->attrs['type']??'',['get','set'],true)){
            $content=null;
            if(($node->attrs['xmlns']??'')==='w:sync:app:state')$content=[new BinaryNode('sync',[],array_map(static fn(BinaryNode $collection):BinaryNode=>new BinaryNode('collection',['name'=>$collection->attrs['name'],'version'=>$collection->attrs['version']]),$node->child('sync')->content))];
            $this->incoming[]=new BinaryNode('iq',['id'=>$node->attrs['id'],'type'=>'result'],$content);
        }
    }
    public function receiveNode(float $timeoutSeconds=1.0):?BinaryNode{if($this->failure!==null)throw $this->failure;return array_shift($this->incoming);}
    public function close():void{++$this->closes;($this->onClose)?->__invoke();}
}
