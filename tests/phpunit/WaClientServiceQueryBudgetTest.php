<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Protocol\BinaryNode;
use MVO\Transport\{NodeSession,ConnectionFailure};
use MVO\Service\ServiceException;
use MVO\WaClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WaClientServiceQueryBudgetTest extends TestCase
{
    private string $directory;
    private float $time=0.0;
    private ?WaClient $client=null;

    protected function setUp():void {
        $this->directory=sys_get_temp_dir().'/mvo-service-budget-'.bin2hex(random_bytes(8));
        mkdir($this->directory,0700);
    }
    protected function tearDown():void {
        $this->client?->stop();$this->client=null;gc_collect_cycles();
        $files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}
        rmdir($this->directory);
    }
    private function connected():ServiceBudgetNodeSession {
        $credentials=CredentialStore::fresh();$credentials['registered']=true;$credentials['meJid']='5511999999999:12@s.whatsapp.net';$credentials['serverHasPreKeys']=true;
        $store=new CredentialStore($this->directory);$store->save($credentials);$store->close();
        $peer=new ServiceBudgetNodeSession(function(float $seconds):void {$this->time+=$seconds;});
        $this->client=new WaClient(authDirectory:$this->directory,sessionFactory:static fn():NodeSession=>$peer,clock:fn():float=>$this->time,automaticMaintenance:false);
        $this->client->connect();$peer->incoming[]=new BinaryNode('success');$this->client->poll(0);
        self::assertTrue($this->client->isConnected());return $peer;
    }
    /** @return iterable<string,array{string,float}> */
    public static function metadataQueries():iterable {
        yield 'picture'=>['picture',30.0];yield 'status'=>['status',30.0];yield 'username MEX'=>['username',32.0];yield 'business'=>['business',30.0];
    }
    private function metadata(string $operation,?float $budget):void {
        $client=$this->client??throw new \LogicException('Missing client');
        match($operation) {
            'picture'=>$client->profile($budget)->getProfilePicture('5511999999999@s.whatsapp.net'),
            'status'=>$client->profile($budget)->getStatus('5511999999999@s.whatsapp.net'),
            'username'=>$client->profile($budget)->getOwnUsername(),
            'business'=>$client->business($budget)->getBusinessProfile(['5511999999999@s.whatsapp.net']),
            default=>throw new \LogicException('Unknown test query'),
        };
    }
    #[DataProvider('metadataQueries')]
    public function testMetadataQueriesUseExplicitBudgetWithoutChangingDefaultTimeout(string $operation,float $defaultTimeout):void {
        $peer=$this->connected();$peer->respond=false;$start=$this->time;
        try {$this->metadata($operation,5.0);self::fail('Silent peer unexpectedly answered');}
        catch (\RuntimeException $error) {self::assertStringStartsWith('WhatsApp IQ timeout:',$error->getMessage());}
        self::assertSame(5.0,$this->time-$start);self::assertTrue($this->client->isConnected());self::assertFalse($peer->closed);
        $this->client->stop();$peer=$this->connected();$peer->respond=false;
        $start=$this->time;
        try {$this->metadata($operation,null);self::fail('Silent peer unexpectedly answered');}
        catch (\RuntimeException $error) {self::assertStringStartsWith('WhatsApp IQ timeout:',$error->getMessage());}
        self::assertSame($defaultTimeout,$this->time-$start);self::assertSame('reconnecting',$this->client->getState());
    }
    public function testOptionalTimeoutCleansPendingQueryAndLateAckDoesNotReplayIt():void {
        $peer=$this->connected();$client=$this->client;self::assertNotNull($client);$peer->respond=false;
        try {$client->profile(5.0)->getProfilePicture('5511999999999@s.whatsapp.net');self::fail('Silent peer unexpectedly answered');}
        catch (\RuntimeException $error) {self::assertStringStartsWith('WhatsApp IQ timeout:',$error->getMessage());}
        $request=$peer->sent[array_key_last($peer->sent)];$sent=count($peer->sent);
        self::assertSame([],(new \ReflectionProperty($client,'pending'))->getValue($client));
        self::assertSame([],(new \ReflectionProperty($client,'pendingTags'))->getValue($client));
        self::assertFalse((new \ReflectionProperty($client,'queryInFlight'))->getValue($client));
        $peer->respond=true;$peer->incoming[]=new BinaryNode('iq',['id'=>$request->attrs['id'],'type'=>'result']);$client->poll(0);
        self::assertTrue($client->isConnected());self::assertSame($sent,count($peer->sent));
        $client->profile(5.0)->getProfilePicture('5511999999999@s.whatsapp.net');
        self::assertCount($sent+1,$peer->sent);
        self::assertCount(1,array_filter($peer->sent,static fn(BinaryNode $node):bool=>($node->attrs['id']??'')===$request->attrs['id']));
    }
    public function testOptionalTimeoutPolicyDoesNotSuppressRealTransportFailures():void {
        $peer=$this->connected();$peer->respond=false;$peer->readFailure=new \RuntimeException('WebSocket connection ended');
        try {$this->client->profile(5.0)->getProfilePicture('5511999999999@s.whatsapp.net');self::fail('Broken transport unexpectedly answered');}
        catch (\RuntimeException $error) {self::assertSame('WebSocket connection ended',$error->getMessage());}
        self::assertSame('reconnecting',$this->client->getState());self::assertTrue($peer->closed);
        self::assertSame([],(new \ReflectionProperty($this->client,'pending'))->getValue($this->client));
    }
    public function testOptionalServer503StaysLocalWhileDefaultServicePreservesRecovery():void {
        $peer=$this->connected();$peer->errorCode='503';$client=$this->client;self::assertNotNull($client);
        try {$client->profile(5.0)->getProfilePicture('5511999999999@s.whatsapp.net');self::fail('Server error unexpectedly succeeded');}
        catch (ServiceException $error) {self::assertSame('503',$error->serverCode);}
        self::assertTrue($client->isConnected());self::assertFalse($peer->closed);
        self::assertSame([],(new \ReflectionProperty($client,'pending'))->getValue($client));
        try {$client->profile()->getProfilePicture('5511999999999@s.whatsapp.net');self::fail('Server error unexpectedly succeeded');}
        catch (ConnectionFailure $error) {self::assertSame('503',$error->serverCode);}
        self::assertSame('reconnecting',$client->getState());self::assertTrue($peer->closed);
    }
    public function testAllowErrorResponseThirdArgumentRemainsIndependentOfTimeoutRecoveryFlag():void {
        $peer=$this->connected();$peer->errorCode='503';$client=$this->client;self::assertNotNull($client);
        $result=$client->query(new BinaryNode('iq',['type'=>'get','xmlns'=>'w:profile:picture']),5.0,true,false);
        self::assertSame('error',$result->attrs['type']);self::assertSame('503',$result->child('error')->attrs['code']);
        self::assertTrue($client->isConnected());self::assertFalse($peer->closed);
    }
    public function testExplicitFacadesDoNotReplaceCachedServicesAndPreservePushNameSetter():void {
        $this->connected();$client=$this->client;self::assertNotNull($client);
        $defaultProfile=$client->profile();$defaultBusiness=$client->business();
        self::assertSame($defaultProfile,$client->profile());self::assertSame($defaultBusiness,$client->business());
        self::assertNotSame($defaultProfile,$client->profile(5.0));self::assertNotSame($defaultBusiness,$client->business(5.0));
        $client->chat()->importKeys([['keyId'=>'KEY1','keyData'=>str_repeat('k',32),'timestamp'=>1]]);
        $client->profile(5.0)->setPushName('Explicit facade name');
        self::assertSame('Explicit facade name',$client->accountInfo()['displayName']);
        $defaultProfile->setPushName('Default facade name');
        self::assertSame('Default facade name',$client->accountInfo()['displayName']);
        self::assertSame($defaultProfile,$client->profile());self::assertSame($defaultBusiness,$client->business());
    }
    /** @return iterable<string,array{float}> */
    public static function invalidBudgets():iterable {
        yield 'zero'=>[0.0];yield 'negative'=>[-1.0];yield 'over maximum'=>[30.1];yield 'not finite'=>[INF];yield 'not a number'=>[NAN];
    }
    #[DataProvider('invalidBudgets')]
    public function testInvalidBudgetsFailBeforeOpeningAConnection(float $budget):void {
        $factoryCalls=0;
        $this->client=new WaClient(authDirectory:$this->directory,sessionFactory:static function()use(&$factoryCalls):never {$factoryCalls++;throw new \LogicException('Unexpected transport');});
        foreach(['profile','business'] as $method) {
            try {$this->client->$method($budget);self::fail('Invalid timeout accepted');}
            catch (\InvalidArgumentException $error) {self::assertSame('Invalid service query timeout',$error->getMessage());}
        }
        self::assertSame(0,$factoryCalls);self::assertSame('disconnected',$this->client->getState());
    }
}

final class ServiceBudgetNodeSession implements NodeSession
{
    /** @var list<BinaryNode> */ public array $incoming=[];
    /** @var list<BinaryNode> */ public array $sent=[];
    public bool $respond=true;
    public bool $closed=false;
    public ?string $errorCode=null;
    public ?\RuntimeException $readFailure=null;
    /** @var \Closure(float):void */ private \Closure $advance;
    /** @param callable(float):void $advance */
    public function __construct(callable $advance) {$this->advance=\Closure::fromCallable($advance);}
    public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string {return str_repeat('s',32);}
    public function sendNode(BinaryNode $node):void {
        $this->sent[]=$node;if(!$this->respond || $node->tag!=='iq')return;
        if($this->errorCode!==null){$this->incoming[]=new BinaryNode('iq',['id'=>$node->attrs['id'],'type'=>'error'],[new BinaryNode('error',['code'=>$this->errorCode])]);return;}
        $content=null;
        if(($node->attrs['xmlns']??'')==='w:sync:app:state')$content=[new BinaryNode('sync',[],array_map(static function(BinaryNode $collection):BinaryNode {
            $version=(int)$collection->attrs['version']+($collection->child('patch')===null ? 0 : 1);
            return new BinaryNode('collection',['name'=>$collection->attrs['name'],'version'=>(string)$version]);
        },$node->child('sync')->content))];
        $this->incoming[]=new BinaryNode('iq',['id'=>$node->attrs['id'],'type'=>'result'],$content);
    }
    public function receiveNode(float $timeoutSeconds=1.0):?BinaryNode {
        if($this->readFailure!==null)throw $this->readFailure;
        if($this->incoming!==[])return array_shift($this->incoming);
        ($this->advance)($timeoutSeconds);return null;
    }
    public function close():void {$this->closed=true;}
}
