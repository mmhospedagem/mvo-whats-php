<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Media\MediaTransfer;
use MVO\Protocol\BinaryNode;
use MVO\Transport\{NodeSession,SessionRestartRequired};
use MVO\WaClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Service facades and media completion belong to the login which created them. */
final class WaClientServiceGenerationTest extends TestCase
{
    private const IMAGE='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jL/QAAAAASUVORK5CYII=';
    private string $directory;
    private ?WaClient $client=null;
    private ServiceGenerationPeer $old;
    private ServiceGenerationPeer $replacement;
    private int $httpRequests=0;
    private bool $replaceDuringHttp=false;
    private bool $stopDuringHttp=false;
    private ?\Throwable $httpFailure=null;
    private float $time=0;

    protected function setUp():void
    {
        $this->directory=sys_get_temp_dir().'/mvo-service-generation-'.bin2hex(random_bytes(8));mkdir($this->directory,0700);
        $credentials=CredentialStore::fresh();$credentials['registered']=true;$credentials['serverHasPreKeys']=true;
        $credentials['meJid']='5511999999999:12@s.whatsapp.net';$credentials['meDisplayName']='Original';
        $store=new CredentialStore($this->directory);$store->save($credentials);$store->close();
        $this->old=new ServiceGenerationPeer();$this->replacement=new ServiceGenerationPeer();$opens=0;
        $this->client=new WaClient(authDirectory:$this->directory,automaticMaintenance:false,
            clock:fn():float=>$this->time+=0.01,random:static fn():float=>0.5,
            sessionFactory:function()use(&$opens):NodeSession{return ++$opens===1?$this->old:$this->replacement;},
            mediaTransferFactory:fn(callable $query):MediaTransfer=>new MediaTransfer($query,function(array $request):array{
                ++$this->httpRequests;
                if($this->replaceDuringHttp){$this->replaceDuringHttp=false;$this->replace();}
                if($this->stopDuringHttp)$this->client->stop();
                if($this->httpFailure!==null)throw $this->httpFailure;
                return ['status'=>200,'body'=>'{"fbid":"123","ts":12345,"meta_hmac":"synthetic-hmac","url":"https://mmg.whatsapp.net/test-image","direct_path":"/test-image"}'];
            }));
        $this->client->connect();$this->old->incoming[]=new BinaryNode('success');$this->client->poll(0);
        self::assertTrue($this->client->isConnected());$this->old->sent=[];
    }

    protected function tearDown():void
    {
        $this->client?->stop();$this->client=null;gc_collect_cycles();
        foreach(glob($this->directory.'/*/*')?:[]as$path)unlink($path);
        foreach(glob($this->directory.'/*')?:[]as$path)rmdir($path);
        rmdir($this->directory);
    }

    private function replace():void
    {
        $this->client->stop();$this->client->connect();$this->replacement->incoming[]=new BinaryNode('success');$this->client->poll(0);
        self::assertTrue($this->client->isConnected());$this->replacement->sent=[];
    }

    private function assertInterrupted(callable $operation):void
    {
        $failure=null;
        try{$operation();}catch(\Throwable $error){$failure=$error;}
        self::assertInstanceOf(SessionRestartRequired::class,$failure);
        self::assertTrue($this->client->isConnected());self::assertSame(0,$this->replacement->closes);
        self::assertSame([],$this->replacement->sent,'The old facade must not send or replay on the replacement login');
    }

    public function testCoverUploadCompletionCannotBindCoverOnReplacementLogin():void
    {
        $business=$this->client->business();$this->replaceDuringHttp=true;
        $this->assertInterrupted(static fn()=>$business->updateCoverPhoto(['bytes'=>base64_decode(self::IMAGE,true)]));
        self::assertSame(1,$this->httpRequests);
        self::assertSame([],array_values(array_filter($this->old->sent,static fn(BinaryNode $node):bool=>$node->child('business_profile')!==null)));
    }

    public function testCatalogUploadCompletionCannotCreateProductOnReplacementLogin():void
    {
        $catalog=$this->client->catalog();$this->replaceDuringHttp=true;
        $this->assertInterrupted(static fn()=>$catalog->createProduct(['name'=>'Test','description'=>'Local fixture','price'=>'100','currency'=>'BRL','images'=>[['bytes'=>base64_decode(self::IMAGE,true)]]]));
        self::assertSame(1,$this->httpRequests);
    }

    /** @return iterable<string,array{string}> */
    public static function failingUploadLifecycles():iterable
    {
        yield'replacement login'=>['replace'];yield'local stop'=>['stop'];yield'unchanged login'=>['unchanged'];
    }

    #[DataProvider('failingUploadLifecycles')]
    public function testUploadFailurePreservesCauseAndCannotAffectReplacement(string $lifecycle):void
    {
        $business=$this->client->business();$cause=new \RuntimeException('Synthetic HTTP connection ended after write');
        $this->httpFailure=$cause;$this->replaceDuringHttp=$lifecycle==='replace';$this->stopDuringHttp=$lifecycle==='stop';$failure=null;
        try{$business->updateCoverPhoto(['bytes'=>base64_decode(self::IMAGE,true)]);}catch(\Throwable $error){$failure=$error;}
        self::assertSame(1,$this->httpRequests);self::assertInstanceOf(\RuntimeException::class,$failure);
        if($lifecycle==='unchanged'){
            self::assertNotInstanceOf(SessionRestartRequired::class,$failure);
            self::assertSame($cause,$failure->getPrevious());self::assertSame(0,$this->old->closes);
        }else{
            self::assertInstanceOf(SessionRestartRequired::class,$failure);
            $uploadError=$failure->getPrevious();self::assertInstanceOf(\RuntimeException::class,$uploadError);
            self::assertStringStartsWith('Business media upload failed:',$uploadError->getMessage());
            self::assertSame($cause,$uploadError->getPrevious());self::assertSame(1,$this->old->closes);
        }
        self::assertSame($lifecycle==='stop'?'stopped':'connected',$this->client->getState());
        self::assertSame(0,$this->replacement->closes);self::assertSame([],$this->replacement->sent);
        self::assertSame([],array_values(array_filter($this->old->sent,static fn(BinaryNode $node):bool=>$node->child('business_profile')!==null)));
    }

    public function testChannelUploadCompletionCannotPublishOnReplacementLogin():void
    {
        $channel=$this->client->channels();$this->replaceDuringHttp=true;
        $this->assertInterrupted(static fn()=>$channel->send('123@newsletter',['type'=>'image','media'=>['bytes'=>base64_decode(self::IMAGE,true)]]));
        self::assertSame(1,$this->httpRequests);
    }

    /** @return iterable<string,array{string}> */
    public static function staleServices():iterable
    {
        foreach(['query','limited-query','credentials','send','app-state','status-store','channel-store','cover-upload','catalog-upload','bot-publish','broadcast','group-status','group-revoke']as$operation)yield$operation=>[$operation];
    }

    #[DataProvider('staleServices')]
    public function testRetainedFacadeCannotReadWriteOrUploadOnReplacementLogin(string $operation):void
    {
        $client=$this->client;
        $run=match($operation){
            'query'=>($service=$client->business())->deleteCoverPhoto(...),
            'limited-query'=>($service=$client->business(0.1))->deleteCoverPhoto(...),
            'credentials'=>($service=$client->presence())->send(...),
            'send'=>($service=$client->presence())->sendChatstate(...),
            'app-state'=>($service=$client->profile())->setPushName(...),
            'status-store'=>($service=$client->status())->listStatuses(...),
            'channel-store'=>($service=$client->channels())->listLocalMessages(...),
            'cover-upload'=>($service=$client->business())->updateCoverPhoto(...),
            'catalog-upload'=>($service=$client->catalog())->createProduct(...),
            'bot-publish'=>($service=$client->bot())->sendPrompt(...),
            'broadcast'=>($service=$client->broadcastList())->send(...),
            'group-status'=>($service=$client->status())->sendToGroups(...),
            'group-revoke'=>($service=$client->status())->deleteForGroup(...),
        };
        $arguments=match($operation){
            'query','limited-query'=>['123'],'credentials'=>['available'],'send'=>['5511888888888@s.whatsapp.net'],
            'app-state'=>['Stale name'],'status-store'=>[],'channel-store'=>['123@newsletter'],
            'cover-upload'=>[['bytes'=>base64_decode(self::IMAGE,true)]],
            'catalog-upload'=>[['name'=>'Test','description'=>'Local fixture','price'=>'100','currency'=>'BRL','images'=>[['bytes'=>base64_decode(self::IMAGE,true)]]]],
            'bot-publish'=>['867051314767696@bot','Local fixture'],
            'broadcast'=>['123@broadcast','Local fixture',['5511888888888@s.whatsapp.net']],
            'group-status'=>['120363000000000001@g.us','Local fixture'],
            'group-revoke'=>['120363000000000001@g.us','LOCAL-GROUP-STATUS'],
        };
        $this->replace();$this->assertInterrupted(static fn()=>$run(...$arguments));
        self::assertSame(0,$this->httpRequests);self::assertSame('Original',$client->accountInfo()['displayName']);
    }

    public function testFreshFacadeAndOrdinaryCoverUploadStillWork():void
    {
        self::assertSame('123',$this->client->business()->updateCoverPhoto(['bytes'=>base64_decode(self::IMAGE,true)]));
        self::assertSame(1,$this->httpRequests);
        $this->replace();$this->client->presence()->send('available');
        self::assertSame('presence',$this->replacement->sent[0]->tag);self::assertSame('Original',$this->replacement->sent[0]->attrs['name']);
    }

    public function testLimitedServiceTimeoutPreservesConnectionWithoutRetry():void
    {
        $profile=$this->client->profile(0.1);$this->old->autoReply=false;$failure=null;
        try{$profile->deleteProfilePicture();}catch(\Throwable $error){$failure=$error;}
        self::assertInstanceOf(\RuntimeException::class,$failure);
        self::assertNotInstanceOf(SessionRestartRequired::class,$failure);
        self::assertTrue($this->client->isConnected());self::assertSame(0,$this->old->closes);
        self::assertCount(1,$this->old->sent);self::assertSame([],$this->replacement->sent);
    }

    public function testGroupAuthorizationCallbackCannotStartPublicationOnReplacementLogin():void
    {
        $status=$this->client->status();
        // Clear the guard after replacing; it belongs to this attempted publication only.
        $this->client->setGroupStatusGuard(function():void{$this->client->setGroupStatusGuard(null);$this->replace();});
        $this->assertInterrupted(static fn()=>$status->sendToGroups('120363000000000001@g.us','Local fixture'));
        self::assertSame([],$this->old->sent);
    }
}

/** In-memory server; no sockets, account operations or real media requests. */
final class ServiceGenerationPeer implements NodeSession
{
    /** @var list<BinaryNode> */public array $incoming=[];
    /** @var list<BinaryNode> */public array $sent=[];
    public int $closes=0;
    public bool $autoReply=true;
    public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string{return str_repeat('s',32);}
    public function sendNode(BinaryNode $node):void
    {
        $this->sent[]=$node;
        if($node->tag==='message'){$this->incoming[]=new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id'],'server_id'=>'1','t'=>'1700000000']);return;}
        if($node->tag!=='iq'||!$this->autoReply)return;
        $content=null;
        if(($node->attrs['xmlns']??'')==='w:sync:app:state')$content=[new BinaryNode('sync',[],array_map(static fn(BinaryNode $collection):BinaryNode=>new BinaryNode('collection',['name'=>$collection->attrs['name'],'version'=>$collection->attrs['version']]),$node->child('sync')->content))];
        if(($node->attrs['xmlns']??'')==='w:m')$content=[new BinaryNode('media_conn',['auth'=>'synthetic','ttl'=>'3600'],[new BinaryNode('host',['hostname'=>'mmg.whatsapp.net'])])];
        $this->incoming[]=new BinaryNode('iq',['type'=>'result','id'=>$node->attrs['id']],$content);
    }
    public function receiveNode(float $timeoutSeconds=1.0):?BinaryNode{return array_shift($this->incoming);}
    public function close():void{++$this->closes;}
}
