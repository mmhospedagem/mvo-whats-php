<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Protocol\BinaryNode;
use MVO\Service\{PresenceService,ServiceTransport};
use MVO\Transport\{NodeSession,ConnectionFailure};
use MVO\WaClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PresenceLifecycleTest extends TestCase
{
    private string $directory;
    private float $time=0;
    /** @var list<WaClient> */ private array $clients=[];
    protected function setUp():void
    {
        $this->directory=sys_get_temp_dir().'/mvo-presence-lifecycle-'.bin2hex(random_bytes(8));
        $credentials=CredentialStore::fresh();$credentials['registered']=true;$credentials['meJid']='5511000000000:4@s.whatsapp.net';$credentials['meDisplayName']='MVO example';$credentials['serverHasPreKeys']=true;
        $store=new CredentialStore($this->directory);$store->save($credentials);$store->close();
    }
    protected function tearDown():void
    {
        foreach($this->clients as$client)$client->disconnect();$this->clients=[];gc_collect_cycles();
        foreach(glob($this->directory.'/*/*')?:[]as$file)unlink($file);
        foreach(glob($this->directory.'/*')?:[]as$folder)rmdir($folder);rmdir($this->directory);
    }
    private function client(callable $factory,?bool $online=null):WaClient
    {
        $options=['authDirectory'=>$this->directory,'sessionFactory'=>$factory,'clock'=>fn():float=>$this->time,'random'=>static fn():float=>0.5,'automaticMaintenance'=>false];
        if($online!==null)$options['markOnlineOnConnect']=$online;
        $client=new WaClient(...$options);$this->clients[]=$client;return $client;
    }
    /** @return list<BinaryNode> */
    private static function announcements(PresenceLifecyclePeer $peer):array
    { return array_values(array_filter($peer->sent,static fn(BinaryNode $node):bool=>$node->tag==='presence')); }
    public static function policies():iterable
    { yield 'existing default'=>[null,0];yield 'disabled'=>[false,0];yield 'enabled'=>[true,1]; }
    #[DataProvider('policies')]
    public function testLoginPolicyAnnouncesOnlyAfterActiveAndBeforeConnected(?bool $online,int $expected):void
    {
        $peer=new PresenceLifecyclePeer();$client=$this->client(fn()=>$peer,$online);$atConnected=[];
        $client->on('connection',static function(array $event)use($peer,&$atConnected):void{if($event['state']==='connected')$atConnected=self::announcements($peer);});
        $client->connect();self::assertSame([],$peer->sent);self::assertSame($online??false,$client->isMarkOnlineOnConnect());
        $client->poll(0);self::assertSame([],$peer->sent);self::assertFalse($client->isConnected());
        $peer->incoming[]=new BinaryNode('success');$client->poll(0);
        self::assertTrue($client->isConnected());self::assertCount($expected,self::announcements($peer));self::assertCount($expected,$atConnected);
        if($expected===1){
            self::assertSame(['type'=>'available','name'=>'MVO example'],self::announcements($peer)[0]->attrs);
            $tags=array_map(static fn(BinaryNode $node):string=>$node->tag==='iq'?($node->attrs['xmlns']??'iq'):$node->tag,$peer->sent);
            self::assertLessThan(array_search('presence',$tags,true),array_search('passive',$tags,true));
        }
        $client->poll(0);$peer->incoming[]=new BinaryNode('success');$client->poll(0);self::assertCount($expected,self::announcements($peer));
    }
    #[DataProvider('policies')]
    public function testReconnectAppliesPolicyOncePerAuthenticatedLogin(?bool $online,int $expected):void
    {
        $first=new PresenceLifecyclePeer();$second=new PresenceLifecyclePeer();$index=0;
        $client=$this->client(static function()use($first,$second,&$index):NodeSession{return $index++===0?$first:$second;},$online);
        $client->connect();$first->incoming[]=new BinaryNode('success');$client->poll(0);self::assertCount($expected,self::announcements($first));
        $first->incoming[]=new \RuntimeException('WebSocket connection ended');$client->poll(0);self::assertSame('reconnecting',$client->getState());
        $this->time=1;$client->poll(0);self::assertSame([],$second->sent);self::assertFalse($client->isConnected());
        $second->incoming[]=new BinaryNode('success');$client->poll(0);self::assertTrue($client->isConnected());self::assertCount($expected,self::announcements($second));
    }
    public function testSetterOnlyChangesFutureLoginPolicyAndNeverSendsPresence():void
    {
        $first=new PresenceLifecyclePeer();$second=new PresenceLifecyclePeer();$index=0;
        $client=$this->client(static function()use($first,$second,&$index):NodeSession{return $index++===0?$first:$second;});
        $client->setMarkOnlineOnConnect(true);self::assertTrue($client->isMarkOnlineOnConnect());self::assertSame([],$first->sent);
        $client->connect();$first->incoming[]=new BinaryNode('success');$client->poll(0);self::assertCount(1,self::announcements($first));
        $count=count($first->sent);$client->setMarkOnlineOnConnect(false);self::assertFalse($client->isMarkOnlineOnConnect());self::assertCount($count,$first->sent);
        $client->poll(0);self::assertCount($count,$first->sent);
        $first->incoming[]=new \RuntimeException('WebSocket connection ended');$client->poll(0);$this->time=1;$client->poll(0);$second->incoming[]=new BinaryNode('success');$client->poll(0);
        self::assertTrue($client->isConnected());self::assertSame([],self::announcements($second));
        $count=count($second->sent);$client->setMarkOnlineOnConnect(true);self::assertCount($count,$second->sent);
    }
    public function testPolicyCanChangeWhileAuthenticatingBeforeTheAnnouncement():void
    {
        $peer=new PresenceLifecyclePeer();$client=$this->client(fn()=>$peer,true);
        $client->on('node',static function(array $event)use($client):void{if(($event['node']->attrs['fixtureActive']??'')==='yes')$client->setMarkOnlineOnConnect(false);});
        $client->connect();$peer->incoming[]=new BinaryNode('success');$client->poll(0);
        self::assertTrue($client->isConnected());self::assertSame([],self::announcements($peer));
    }
    public function testStoppingBeforeSuccessOrDuringActivationPreventsOnlineAnnouncement():void
    {
        foreach(['success','active']as$phase){
            $peer=new PresenceLifecyclePeer();$client=$this->client(fn()=>$peer,true);
            $client->on('node',static function(array $event)use($client,$phase):void{
                if(($phase==='success'&&$event['node']->tag==='success')||($phase==='active'&&($event['node']->attrs['fixtureActive']??'')==='yes'))$client->disconnect();
            });
            $client->connect();$peer->incoming[]=new BinaryNode('success');$client->poll(0);
            self::assertFalse($client->isRunning());self::assertFalse($client->isConnected());self::assertSame([],self::announcements($peer));self::assertSame('stopped',$client->getState());
        }
    }
    public function testStoppingFromThePresenceSendCannotEmitConnectedAfterwards():void
    {
        $peer=new PresenceLifecyclePeer();$client=$this->client(fn()=>$peer,true);$connected=0;
        $peer->onSend=static function(BinaryNode $node)use($client):void{if($node->tag==='presence')$client->disconnect();};
        $client->on('connection',static function(array $event)use(&$connected):void{if($event['state']==='connected')$connected++;});
        $client->connect();$peer->incoming[]=new BinaryNode('success');$client->poll(0);
        self::assertSame(0,$connected);self::assertFalse($client->isRunning());self::assertSame('stopped',$client->getState());self::assertCount(1,self::announcements($peer));
    }
    public function testActivationFailureDoesNotAnnounceOnlineAndUsesExistingRecovery():void
    {
        $peer=new PresenceLifecyclePeer();$peer->rejectActive=true;$client=$this->client(fn()=>$peer,true);
        $client->connect();$peer->incoming[]=new BinaryNode('success');$client->poll(0);
        self::assertSame('reconnecting',$client->getState());self::assertFalse($client->isConnected());self::assertSame([],self::announcements($peer));
    }
    public function testReplacingTheLoginDuringAnnouncementDoesNotMarkTheNewTransportConnectedEarly():void
    {
        $first=new PresenceLifecyclePeer();$second=new PresenceLifecyclePeer();$index=0;$connected=0;
        $client=$this->client(static function()use($first,$second,&$index):NodeSession{return $index++===0?$first:$second;},true);
        $first->onSend=static function(BinaryNode $node)use($client):void{if($node->tag==='presence'){$client->disconnect();$client->connect();}};
        $client->on('connection',static function(array $event)use(&$connected):void{if($event['state']==='connected')$connected++;});
        $client->connect();$first->incoming[]=new BinaryNode('success');$client->poll(0);
        self::assertSame(0,$connected);self::assertSame('authenticating',$client->getState());self::assertSame([],$second->sent);
        $second->incoming[]=new BinaryNode('success');$client->poll(0);self::assertSame(1,$connected);self::assertTrue($client->isConnected());
        self::assertCount(1,self::announcements($first));self::assertCount(1,self::announcements($second));
    }
    public function testPresenceWriteFailureRetriesThroughANewLoginWithoutReplayingOnTheOldTransport():void
    {
        $first=new PresenceLifecyclePeer();$first->presenceFailure=new \RuntimeException('WebSocket write failed');$second=new PresenceLifecyclePeer();$index=0;$connected=0;
        $client=$this->client(static function()use($first,$second,&$index):NodeSession{return $index++===0?$first:$second;},true);
        $client->on('connection',static function(array $event)use(&$connected):void{if($event['state']==='connected')$connected++;});
        $client->connect();$first->incoming[]=new BinaryNode('success');$client->poll(0);
        self::assertSame(0,$connected);self::assertSame('reconnecting',$client->getState());self::assertCount(1,self::announcements($first));
        $this->time=1;$client->poll(0);self::assertSame([],self::announcements($second));
        $second->incoming[]=new BinaryNode('success');$client->poll(0);self::assertSame(1,$connected);self::assertCount(1,self::announcements($second));self::assertCount(1,self::announcements($first));
    }
    public function testUnverifiedSuccessCannotAnnounceOnline():void
    {
        $clientStore=new CredentialStore($this->directory);$clientStore->save(CredentialStore::fresh());$clientStore->close();
        $peer=new PresenceLifecyclePeer();$client=$this->client(fn()=>$peer,true);$client->connect();$peer->incoming[]=new BinaryNode('success');
        try{$client->poll(0);self::fail('Accepted unverified login');}catch(\RuntimeException $error){self::assertStringContainsString('verified pairing identity',$error->getMessage());}
        self::assertFalse($client->isConnected());self::assertSame([],self::announcements($peer));
    }
    public function testRegisteredAuthenticationFailureCannotAnnounceOnline():void
    {
        $peer=new PresenceLifecyclePeer();$client=$this->client(fn()=>$peer,true);$client->connect();$peer->incoming[]=new BinaryNode('failure',['reason'=>'401']);
        try{$client->poll(0);self::fail('Accepted refused login');}catch(ConnectionFailure){}
        self::assertFalse($client->isConnected());self::assertSame([],self::announcements($peer));
    }
    public function testSubscriptionsRemainManualAfterReconnect():void
    {
        $first=new PresenceLifecyclePeer();$second=new PresenceLifecyclePeer();$index=0;
        $client=$this->client(static function()use($first,$second,&$index):NodeSession{return $index++===0?$first:$second;},true);
        $client->connect();$first->incoming[]=new BinaryNode('success');$client->poll(0);$client->presence()->subscribe('100@lid');self::assertCount(2,self::announcements($first));
        $first->incoming[]=new \RuntimeException('WebSocket connection ended');$client->poll(0);$this->time=1;$client->poll(0);$second->incoming[]=new BinaryNode('success');$client->poll(0);
        self::assertCount(1,self::announcements($second));self::assertSame('available',self::announcements($second)[0]->attrs['type']);
    }
    public function testRecordingAliasMatchesTheExistingComposingAudioWireFormat():void
    {
        $sent=[];$service=new PresenceService(new ServiceTransport(static fn():BinaryNode=>new BinaryNode('iq'),static function(BinaryNode $node)use(&$sent):void{$sent[]=$node;},static fn():array=>[]));
        $service->sendChatstate('100@lid','recording');$service->sendChatstate('100@lid','recording','audio');$service->sendChatstate('100@lid','composing','audio');$service->sendChatstate('100@lid','paused');
        foreach(array_slice($sent,0,3)as$node){self::assertSame('chatstate',$node->tag);self::assertSame(['to'=>'100@lid'],$node->attrs);self::assertNotNull($node->child('composing'));self::assertSame(['media'=>'audio'],$node->child('composing')->attrs);}
        self::assertNotNull($sent[3]->child('paused'));self::assertSame([],$sent[3]->child('paused')->attrs);
    }
    public static function invalidChatstates():iterable
    {
        yield 'conflicting recording media'=>['100@lid','recording','video'];yield 'empty recording media'=>['100@lid','recording',''];
        yield 'paused audio'=>['100@lid','paused','audio'];yield 'invalid state'=>['100@lid','typing',null];yield 'broadcast'=>['status@broadcast','recording',null];yield 'channel'=>['123@newsletter','recording',null];
    }
    #[DataProvider('invalidChatstates')]
    public function testMalformedChatstateDoesNotReachTheTransport(string $jid,string $state,?string $media):void
    {
        $sent=[];$service=new PresenceService(new ServiceTransport(static fn():BinaryNode=>new BinaryNode('iq'),static function(BinaryNode $node)use(&$sent):void{$sent[]=$node;},static fn():array=>[]));
        try{$service->sendChatstate($jid,$state,$media);self::fail('Invalid chatstate accepted');}catch(\InvalidArgumentException){self::assertSame([],$sent);}
    }
}

final class PresenceLifecyclePeer implements NodeSession
{
    /** @var list<BinaryNode|\Throwable> */ public array $incoming=[];
    /** @var list<BinaryNode> */ public array $sent=[];
    public ?\Closure $onSend=null;
    public ?\Throwable $presenceFailure=null;
    public bool $rejectActive=false;
    public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string{return str_repeat('s',32);}
    public function sendNode(BinaryNode $node):void
    {
        $this->sent[]=$node;if($this->onSend!==null)($this->onSend)($node);
        if($node->tag==='presence'&&$this->presenceFailure!==null)throw $this->presenceFailure;
        if($node->tag!=='iq')return;
        $attrs=['id'=>$node->attrs['id'],'type'=>'result'];$body=null;
        if(($node->attrs['xmlns']??'')==='passive'){$attrs['fixtureActive']='yes';if($this->rejectActive){$attrs['type']='error';$body=[new BinaryNode('error',['code'=>'503'])];}}
        elseif(($node->attrs['xmlns']??'')==='w:sync:app:state')$body=[new BinaryNode('sync',[],array_map(static fn(BinaryNode $collection):BinaryNode=>new BinaryNode('collection',['name'=>$collection->attrs['name'],'version'=>$collection->attrs['version']]),$node->child('sync')->content))];
        $this->incoming[]=new BinaryNode('iq',$attrs,$body);
    }
    public function receiveNode(float $timeoutSeconds=1):?BinaryNode{$next=array_shift($this->incoming);if($next instanceof \Throwable)throw $next;return $next;}
    public function close():void{}
}
