<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Signal\SignalStore;
use MVO\Transport\{ConnectionFailure,NodeSession};
use MVO\WaClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WaClientStatusAckTest extends TestCase
{
    private string $directory;
    private float $time=0.0;
    /** @var list<WaClient> */ private array $clients=[];
    /** @var array<string,mixed> */ private array $credentials;

    protected function setUp():void
    {
        $this->directory=sys_get_temp_dir().'/mvo-status-ack-'.bin2hex(random_bytes(8));
        $this->credentials=CredentialStore::fresh();$this->credentials['registered']=true;
        $this->credentials['meJid']='5511999999999:12@s.whatsapp.net';$this->credentials['serverHasPreKeys']=true;
        $store=new CredentialStore($this->directory);$store->save($this->credentials);$store->close();
    }

    protected function tearDown():void
    {
        foreach($this->clients as$client)$client->stop();$this->clients=[];gc_collect_cycles();
        foreach(glob($this->directory.'/default/*')?:[]as$file)unlink($file);
        rmdir($this->directory.'/default');rmdir($this->directory);
    }

    /** @param callable():NodeSession $factory */
    private function client(callable $factory,bool $autoReconnect=true):WaClient
    {
        $client=new WaClient(authDirectory:$this->directory,sessionFactory:$factory,autoReconnect:$autoReconnect,
            reconnectMinSeconds:1.0,reconnectMaxSeconds:8.0,clock:fn():float=>$this->time,random:static fn():float=>0.5,
            automaticMaintenance:false,versionResolver:static fn():string=>WaClient::VERSION);
        $this->clients[]=$client;return $client;
    }

    private function login(WaClient $client,StatusAckNodeSession $session):void
    {
        $session->incoming[]=new BinaryNode('success');$client->poll(0);self::assertTrue($client->isConnected());
    }

    /** @return array<string,array{string,bool}> */
    public static function statusOrigins():array
    {
        return ['contact live'=>['5511888888888@s.whatsapp.net',false],'contact offline'=>['5511888888888@s.whatsapp.net',true],
            'LID contact'=>['42000000000@lid',false],'newsletter live'=>['123@newsletter',false],'newsletter offline'=>['123@newsletter',true]];
    }

    #[DataProvider('statusOrigins')]
    public function testTopLevelStatusWritesExactAckWithoutInventingMessageOrView(string $from,bool $offline):void
    {
        $session=new StatusAckNodeSession();$client=$this->client(fn()=>$session);$client->connect();$this->login($client,$session);
        $events=[];foreach(['message','status','newsletter_message']as$name)$client->on($name,static function()use(&$events,$name):void{$events[]=$name;});
        $attrs=['id'=>'TOP-STATUS','from'=>$from,'type'=>'media','participant'=>'77770000000:3@lid','recipient'=>'88880000000@lid'];if($offline)$attrs['offline']='1';
        $body=str_ends_with($from,'@newsletter') ? new BinaryNode('plaintext',[],SchemaCodec::encode('Message',['conversation'=>'Not an application message'])) : new BinaryNode('opaque',[],"\x00not-a-message\xff");
        $node=new BinaryNode('status',$attrs,[$body]);
        $session->sent=[];$session->incoming[]=$node;$client->poll(0);
        self::assertCount(1,$session->sent);self::assertSame('ack',$session->sent[0]->tag);
        self::assertSame(['class'=>'status','id'=>'TOP-STATUS','to'=>$from,'type'=>'media','participant'=>'77770000000:3@lid','recipient'=>'88880000000@lid'],$session->sent[0]->attrs);
        self::assertSame([],$events);self::assertTrue($client->isConnected());
        $session->incoming[]=$node;$client->poll(0);self::assertCount(2,$session->sent,'Offline redelivery must be acknowledged again');
        self::assertSame($session->sent[0]->attrs,$session->sent[1]->attrs);
    }

    public function testAckPrecedesCustomConsumerAndCannotBeSuppressedByIgnoreFilter():void
    {
        $session=new StatusAckNodeSession();$client=$this->client(fn()=>$session);$client->connect();$this->login($client,$session);$session->sent=[];
        $client->ignoreKey(['id'=>'CUSTOM-STATUS']);$handled=0;
        $client->registerIncomingHandler(function(BinaryNode $node)use($session,&$handled):bool {
            if($node->tag!=='status')return false;++$handled;
            self::assertCount(1,$session->sent);self::assertSame('status',$session->sent[0]->attrs['class']);return true;
        });
        $session->incoming[]=new BinaryNode('status',['id'=>'CUSTOM-STATUS','from'=>'123@newsletter']);$client->poll(0);
        self::assertSame(1,$handled);self::assertSame(['class'=>'status','id'=>'CUSTOM-STATUS','to'=>'123@newsletter'],$session->sent[0]->attrs);
    }

    public function testRawNodeCallbackStopPreventsAnyWriteAfterStop():void
    {
        $session=new StatusAckNodeSession();$client=$this->client(fn()=>$session);$client->connect();$this->login($client,$session);$session->sent=[];
        $client->on('node',static function(array $event)use($client):void{if($event['node']->tag==='status')$client->stop();});
        $session->incoming[]=new BinaryNode('status',['id'=>'STOP-STATUS','from'=>'123@newsletter']);$client->poll(0);
        self::assertSame([],$session->sent);self::assertFalse($client->isRunning());
    }

    public function testStopDuringStatusAckWriteDoesNotInvokeApplicationConsumerAfterward():void
    {
        $session=new StatusAckNodeSession();$client=$this->client(fn()=>$session);$client->connect();$this->login($client,$session);$session->sent=[];$handled=0;
        $session->afterStatusAck=static function()use($client):void{$client->stop();};
        $client->registerIncomingHandler(static function()use(&$handled):bool{++$handled;return true;});
        $session->incoming[]=new BinaryNode('status',['id'=>'STOP-DURING-ACK','from'=>'123@newsletter']);$client->poll(0);
        self::assertCount(1,$session->sent);self::assertSame(0,$handled);self::assertFalse($client->isRunning());
    }

    public function testOfflineStatusStillContributesToOfflineBatchAccounting():void
    {
        $session=new StatusAckNodeSession();$client=$this->client(fn()=>$session);$client->connect();$this->login($client,$session);$events=[];
        $client->on('offline_resume',static function(array $event)use(&$events):void{$events[]=$event;});
        $session->incoming[]=new BinaryNode('ib',[],[new BinaryNode('offline_preview',['count'=>'1'])]);$client->poll(0);
        $session->incoming[]=new BinaryNode('status',['id'=>'OFFLINE-STATUS','from'=>'123@newsletter','offline'=>'1']);$client->poll(0);
        $session->incoming[]=new BinaryNode('ib',[],[new BinaryNode('offline',['count'=>'1'])]);$client->poll(0);
        $last=$events[array_key_last($events)];self::assertSame('complete',$last['status']);self::assertSame(0,$last['remainingStanzas']);
        self::assertFalse($last['forced']);
    }

    public function testOrdinaryNewsletterMessageKeepsItsOwnMessageAckClass():void
    {
        $session=new StatusAckNodeSession();$client=$this->client(fn()=>$session);$client->connect();$this->login($client,$session);$session->sent=[];
        $session->incoming[]=new BinaryNode('message',['id'=>'CHANNEL-POST','from'=>'123@newsletter','server_id'=>'8'],[
            new BinaryNode('plaintext',[],SchemaCodec::encode('Message',['conversation'=>'Control channel message']))]);
        $client->poll(0);self::assertCount(1,$session->sent);self::assertSame('message',$session->sent[0]->attrs['class']);
        self::assertSame('Control channel message',$client->history()->getMessage('123@newsletter','CHANNEL-POST')['text']);
    }

    public function testStatusAckStreamErrorReconnectsWithSameIdentityAndPersistentState():void
    {
        $first=new StatusAckNodeSession();$second=new StatusAckNodeSession();$opens=0;
        $client=$this->client(static function()use($first,$second,&$opens):NodeSession{return ++$opens===1?$first:$second;});
        $qr=0;$reconnects=[];$client->on('auth_qr',static function()use(&$qr):void{++$qr;});
        $client->on('reconnect',static function(array $event)use(&$reconnects):void{$reconnects[]=$event;});
        $client->connect();$this->login($client,$first);
        $store=new SignalStore($this->directory.'/default/state.sqlite');$key=$store->generatePreKeys(1)[0];$store->set('status-test','sentinel','kept');$store->close();
        $first->incoming[]=new BinaryNode('stream:error',[],[new BinaryNode('ack',['class'=>'message']),new BinaryNode('ack',['class'=>'status','type'=>'media','id'=>'OLD-STATUS'])]);$client->poll(0);
        self::assertTrue($client->isRunning());self::assertFalse($client->isConnected());self::assertTrue($first->closed);
        self::assertCount(1,$reconnects);self::assertSame(1.0,$reconnects[0]['delay']);self::assertNull($reconnects[0]['serverCode']);
        self::assertSame('WhatsApp stream error: unacknowledged status',$reconnects[0]['reason']);
        $client->poll(0);self::assertSame(1,$opens);$this->time=1.0;$client->poll(0);self::assertSame(2,$opens);
        $this->login($client,$second);self::assertSame($first->staticPrivate,$second->staticPrivate);self::assertSame(0,$qr);
        $auth=new CredentialStore($this->directory);$saved=$auth->load();$auth->close();
        foreach(['noiseKeyPair','identityKeyPair','signedPreKey','registrationId','advSecretKey','meJid']as$field)self::assertSame($this->credentials[$field],$saved[$field]);
        self::assertSame(2,$saved['loginCounter']);self::assertFalse($saved['revoked'] ?? false);
        $store=new SignalStore($this->directory.'/default/state.sqlite');self::assertSame($key['private'],$store->getPreKey($key['id'])['private']);
        self::assertSame('kept',$store->get('status-test','sentinel'));$store->close();
    }

    public function testFailedStatusAckWriteCanReconnectAndAcknowledgeOfflineRedelivery():void
    {
        $first=new StatusAckNodeSession();$second=new StatusAckNodeSession();$opens=0;
        $client=$this->client(static function()use($first,$second,&$opens):NodeSession{return ++$opens===1?$first:$second;});
        $client->connect();$this->login($client,$first);$first->sent=[];$first->failStatusAck=true;
        $status=new BinaryNode('status',['id'=>'WRITE-FAIL-STATUS','from'=>'123@newsletter','type'=>'media','offline'=>'1']);
        $first->incoming[]=$status;$client->poll(0);self::assertSame([],$first->sent);self::assertTrue($client->isRunning());
        self::assertFalse($client->isConnected());$this->time=1.0;$client->poll(0);$this->login($client,$second);$second->sent=[];
        $second->incoming[]=$status;$client->poll(0);self::assertCount(1,$second->sent);
        self::assertSame(['class'=>'status','id'=>'WRITE-FAIL-STATUS','to'=>'123@newsletter','type'=>'media'],$second->sent[0]->attrs);
        self::assertSame($first->staticPrivate,$second->staticPrivate);
    }

    public function testOrdinaryStatusAckDoesNotCauseRecovery():void
    {
        $session=new StatusAckNodeSession();$client=$this->client(fn()=>$session);$client->connect();$this->login($client,$session);$session->sent=[];
        $session->incoming[]=new BinaryNode('ack',['class'=>'status','id'=>'UNRELATED-ACK']);$client->poll(0);
        self::assertTrue($client->isConnected());self::assertSame([],$session->sent);
    }

    public function testStatusAckStreamErrorDuringQueryDoesNotAutomaticallyReplayCommand():void
    {
        $first=new StatusAckNodeSession();$second=new StatusAckNodeSession();$opens=0;
        $client=$this->client(static function()use($first,$second,&$opens):NodeSession{return ++$opens===1?$first:$second;});
        $client->connect();$this->login($client,$first);
        $first->replaceNextResult=new BinaryNode('stream:error',[],[new BinaryNode('ack',['class'=>'status'])]);
        try{$client->query(new BinaryNode('iq',['type'=>'set','xmlns'=>'synthetic:side-effect'],[new BinaryNode('operation')]));self::fail('Query outcome must remain visible as uncertain');}
        catch(ConnectionFailure $error){self::assertTrue($error->retryable);self::assertSame('',$error->serverCode);}
        self::assertTrue($client->isRunning());self::assertFalse($client->isConnected());
        self::assertCount(1,array_filter($first->sent,static fn(BinaryNode $n):bool=>($n->attrs['xmlns'] ?? '')==='synthetic:side-effect'));
        $this->time=1.0;$client->poll(0);$this->login($client,$second);
        self::assertSame([],array_values(array_filter($second->sent,static fn(BinaryNode $n):bool=>($n->attrs['xmlns'] ?? '')==='synthetic:side-effect')));
    }

    public function testStatusAckRecoveryStillRespectsDisabledAutoReconnect():void
    {
        $session=new StatusAckNodeSession();$client=$this->client(fn()=>$session,false);$client->connect();$this->login($client,$session);
        $session->incoming[]=new BinaryNode('stream:error',[],[new BinaryNode('ack',['class'=>'status'])]);
        try{$client->poll(0);self::fail('Expected explicit failure with autoReconnect disabled');}
        catch(ConnectionFailure $error){self::assertTrue($error->retryable);self::assertFalse($error->revoked);self::assertSame('',$error->serverCode);}
        self::assertFalse($client->isRunning());
    }

    /** @return array<string,array{string,list<BinaryNode>,bool}> */
    public static function permanentErrors():array
    {
        $status=new BinaryNode('ack',['class'=>'status']);
        return ['unknown without ACK'=>['',[],false],'other ACK class'=>['',[new BinaryNode('ack',['class'=>'message'])],false],
            'nested status ACK'=>['',[new BinaryNode('nested',[],[$status])],false],
            'explicit 401'=>['401',[$status],true],'device removed'=>['',[new BinaryNode('conflict',['type'=>'device_removed']),$status],true],
            'other conflict'=>['',[new BinaryNode('conflict',['type'=>'replaced']),$status],false],
            'explicit 403'=>['403',[$status],false],'explicit 405'=>['405',[$status],false]];
    }

    #[DataProvider('permanentErrors')]
    public function testStatusChildNeverOverridesExplicitRejectionOrConflict(string $code,array $children,bool $revoked):void
    {
        $session=new StatusAckNodeSession();$client=$this->client(fn()=>$session);$client->connect();$this->login($client,$session);
        $session->incoming[]=new BinaryNode('stream:error',$code===''?[]:['code'=>$code],$children);
        try{$client->poll(0);self::fail('Permanent rejection must not reconnect');}
        catch(ConnectionFailure $error){self::assertSame($code,$error->serverCode);self::assertFalse($error->retryable);self::assertSame($revoked,$error->revoked);}
        self::assertFalse($client->isRunning());
    }

    public function testExplicit515KeepsItsImmediateRestartWithStatusAckChild():void
    {
        $first=new StatusAckNodeSession();$second=new StatusAckNodeSession();$opens=0;
        $client=$this->client(static function()use($first,$second,&$opens):NodeSession{return ++$opens===1?$first:$second;});
        $client->connect();$this->login($client,$first);
        $first->incoming[]=new BinaryNode('stream:error',['code'=>'515'],[new BinaryNode('ack',['class'=>'status'])]);$client->poll(0);
        self::assertSame(2,$opens);self::assertTrue($client->isRunning());$this->login($client,$second);
        self::assertSame($first->staticPrivate,$second->staticPrivate);
    }
}

final class StatusAckNodeSession implements NodeSession
{
    /** @var list<BinaryNode> */public array $incoming=[];
    /** @var list<BinaryNode> */public array $sent=[];
    public string $staticPrivate='';
    public bool $closed=false;
    public bool $failStatusAck=false;
    public ?\Closure $afterStatusAck=null;
    public ?BinaryNode $replaceNextResult=null;
    public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string{$this->staticPrivate=$staticPrivate;return str_repeat('s',32);}
    public function sendNode(BinaryNode $node):void
    {
        if($node->tag==='ack' && ($node->attrs['class'] ?? '')==='status' && $this->failStatusAck){$this->failStatusAck=false;throw new \RuntimeException('WebSocket write failed: synthetic status acknowledgement');}
        $this->sent[]=$node;
        if($node->tag==='ack' && ($node->attrs['class'] ?? '')==='status')($this->afterStatusAck)?->__invoke();
        if($node->tag!=='iq')return;$content=null;
        if($this->replaceNextResult!==null){$this->incoming[]=$this->replaceNextResult;$this->replaceNextResult=null;return;}
        if(($node->attrs['xmlns'] ?? '')==='w:sync:app:state')$content=[new BinaryNode('sync',[],array_map(static fn(BinaryNode $collection):BinaryNode=>new BinaryNode('collection',['name'=>$collection->attrs['name'],'version'=>$collection->attrs['version']]),$node->child('sync')->content))];
        $this->incoming[]=new BinaryNode('iq',['id'=>$node->attrs['id'],'type'=>'result'],$content);
    }
    public function receiveNode(float $timeoutSeconds=1):?BinaryNode{return array_shift($this->incoming);}
    public function close():void{$this->closed=true;}
}
