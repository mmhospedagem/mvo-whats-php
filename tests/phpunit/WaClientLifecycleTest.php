<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Auth\CredentialStore;
use MVO\Protocol\{BinaryNode,Protobuf};
use MVO\Transport\{ConnectionFailure,NodeSession};
use MVO\WaClient;

final class WaClientLifecycleTest extends TestCase
{
    private string $directory;
    private float $time = 0.0;
    /** @var list<WaClient> */ private array $clients = [];
    protected function setUp(): void {
        $this->directory = sys_get_temp_dir().'/mvo-lifecycle-'.bin2hex(random_bytes(8));
        mkdir($this->directory,0700);
    }
    protected function tearDown(): void {
        foreach ($this->clients as $client) $client->disconnect();
        $this->clients = []; gc_collect_cycles();
        foreach (glob($this->directory.'/*/*') ?: [] as $path) unlink($path);
        foreach (glob($this->directory.'/*') ?: [] as $path) rmdir($path);
        rmdir($this->directory);
    }
    /** @return array<string,mixed> */
    private function paired(bool $serverHasPreKeys = true): array {
        $credentials = CredentialStore::fresh();
        $credentials['registered'] = true;
        $credentials['meJid'] = '5511999999999:12@s.whatsapp.net';
        $credentials['serverHasPreKeys'] = $serverHasPreKeys;
        $store = new CredentialStore($this->directory); $store->save($credentials); $store->close();
        return $credentials;
    }
    /** @return array<string,mixed> */
    private function saved(): array {
        $store = new CredentialStore($this->directory); $credentials = $store->load(); $store->close();
        self::assertNotNull($credentials); return $credentials;
    }
    /** @param callable():NodeSession $factory */
    private function client(callable $factory,bool $autoReconnect = true): WaClient {
        $client = new WaClient(authDirectory:$this->directory,sessionFactory:$factory,autoReconnect:$autoReconnect,
            reconnectMaxSeconds:8.0,clock:fn():float=>$this->time,random:static fn():float=>0.5,automaticMaintenance:false);
        $this->clients[] = $client; return $client;
    }
    public function testProcessRestartReusesLinkedKeysAndUploadedPreKeysWithoutQr(): void {
        $credentials = $this->paired(false); $qrCount = 0;
        $a = new LifecycleNodeSession();
        $first = $this->client(fn()=>$a);
        $first->on('auth_qr',static function() use (&$qrCount):void { $qrCount++; });
        $first->connect(); $a->incoming[] = new BinaryNode('success',['lid'=>'123:12@lid']); $first->poll(0);
        self::assertTrue($first->isConnected());
        self::assertSame(['encrypt','passive','w:sync:app:state'],array_column(array_map(static fn(BinaryNode $n):array=>$n->attrs,$a->sent),'xmlns'));
        $first->stop();
        $b = new LifecycleNodeSession(); $second = $this->client(fn()=>$b);
        $second->on('auth_qr',static function() use (&$qrCount):void { $qrCount++; });
        $second->connect(); $b->incoming[] = new BinaryNode('success'); $second->poll(0);
        self::assertSame('5511999999999:12@s.whatsapp.net',$second->accountJid());
        self::assertSame($a->staticPrivate,$b->staticPrivate);
        self::assertSame($credentials['noiseKeyPair']['private'],$b->staticPrivate);
        self::assertSame($credentials['identityKeyPair'],$this->saved()['identityKeyPair']);
        self::assertSame(2,$this->saved()['loginCounter']); self::assertSame(0,$qrCount);
        self::assertCount(2,$b->sent); self::assertSame('passive',$b->sent[0]->attrs['xmlns']); self::assertSame('w:sync:app:state',$b->sent[1]->attrs['xmlns']);
        $payload = []; foreach (Protobuf::decode($b->payload) as $field) $payload[$field['number']] = $field['value'];
        self::assertSame(0,$payload[16]); self::assertSame(1,$payload[24]); self::assertArrayNotHasKey(19,$payload);
    }
    public function testRecoveryRetainsExclusiveLockAndBacksOffRepeatedDrops(): void {
        $credentials = $this->paired(); $a = new LifecycleNodeSession(); $b = new LifecycleNodeSession(); $calls = 0;
        $client = $this->client(static function() use (&$calls,$a,$b):NodeSession {
            $calls++; if ($calls===2) throw new \RuntimeException('WebSocket connection failed: offline');
            return $calls===1 ? $a : $b;
        });
        $attempts = []; $client->on('reconnect',static function(array $event) use (&$attempts):void { $attempts[]=$event; });
        $client->connect(); $a->incoming[] = new BinaryNode('success'); $client->poll(0);
        $a->incoming[] = new \RuntimeException('WebSocket connection ended'); $client->poll(0);
        self::assertSame('reconnecting',$client->getState()); self::assertTrue($client->isRunning());
        $other = new CredentialStore($this->directory);
        try { $other->acquire(); self::fail('Recovery released the session lock'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('already in use',$error->getMessage()); }
        $other->close(); $client->poll(0); self::assertSame(1,$calls);
        $this->time=1.0; $client->poll(0); self::assertSame(2,$calls);
        $this->time=2.9; $client->poll(0); self::assertSame(2,$calls);
        $this->time=3.0; $client->poll(0); self::assertSame(3,$calls);
        $b->incoming[] = new BinaryNode('success'); $client->poll(0);
        $b->incoming[] = new \RuntimeException('WebSocket connection ended'); $client->poll(0);
        self::assertSame([1,2,3],array_column($attempts,'attempt'));
        self::assertSame([1.0,2.0,4.0],array_column($attempts,'delay'));
        self::assertSame($credentials['noiseKeyPair']['private'],$b->staticPrivate);
    }
    public function testRevocationIsDurableDoesNotRetryAndDoesNotEraseIdentity(): void {
        $credentials = $this->paired(); $session = new LifecycleNodeSession(); $calls = 0; $loggedOut = [];
        $client = $this->client(static function() use (&$calls,$session):NodeSession { $calls++; return $session; });
        $client->on('logged_out',static function(array $event) use (&$loggedOut):void { $loggedOut[]=$event; });
        $client->connect(); $session->incoming[] = new BinaryNode('failure',['reason'=>'401']);
        try { $client->poll(0); self::fail('Revoked login did not fail'); } catch (ConnectionFailure) { }
        self::assertSame('logged_out',$client->getState()); self::assertFalse($client->isRunning());
        self::assertCount(1,$loggedOut); self::assertTrue($this->saved()['revoked']);
        self::assertSame($credentials['identityKeyPair'],$this->saved()['identityKeyPair']);
        self::assertSame($credentials['noiseKeyPair'],$this->saved()['noiseKeyPair']);
        $again = $this->client(static function() use (&$calls,$session):NodeSession { $calls++; return $session; });
        try { $again->connect(); self::fail('Retried revoked identity'); } catch (ConnectionFailure) { }
        self::assertSame(1,$calls);
    }
    /** @return iterable<string,array{string,bool}> */
    public static function serverCodes(): iterable {
        yield 'timeout'=>['408',true]; yield 'server'=>['500',true]; yield 'unavailable'=>['503',true];
        yield 'bad-request'=>['400',false]; yield 'forbidden'=>['403',false];
    }
    #[DataProvider('serverCodes')]
    public function testOnlyTransientServerCodesRetry(string $code,bool $retryable): void {
        $this->paired(); $session = new LifecycleNodeSession(); $client = $this->client(fn()=>$session);
        $client->connect(); $session->incoming[] = new BinaryNode('stream:error',['code'=>$code]);
        try { $client->poll(0); self::assertTrue($retryable); }
        catch (ConnectionFailure) { self::assertFalse($retryable); }
        self::assertSame($retryable ? 'reconnecting' : 'disconnected',$client->getState());
    }
    public function testDeviceRemovalDoesNotRetryEvenWithServerErrorCode(): void {
        $this->paired(); $session = new LifecycleNodeSession(); $client = $this->client(fn()=>$session);
        $client->connect(); $session->incoming[] = new BinaryNode('stream:error',['code'=>'500'],[new BinaryNode('conflict',['type'=>'device_removed'])]);
        try { $client->poll(0); self::fail('Device removal accepted'); } catch (ConnectionFailure) { }
        self::assertSame('logged_out',$client->getState()); self::assertTrue($this->saved()['revoked']);
    }
    public function testStopCancelsScheduledRetryAndReleasesLock(): void {
        $this->paired(); $session = new LifecycleNodeSession(); $calls=0;
        $client = $this->client(static function() use (&$calls,$session):NodeSession { $calls++; return $session; });
        $client->connect(); $session->incoming[] = new \RuntimeException('WebSocket connection ended'); $client->poll(0);
        $client->stop(); $this->time=100.0; $client->run();
        self::assertSame('stopped',$client->getState()); self::assertFalse($client->isRunning()); self::assertSame(1,$calls);
        $store = new CredentialStore($this->directory); $store->acquire(); $store->close();
    }
    public function testStoppingDuringEventCannotReopenOrMarkConnected(): void {
        $this->paired(); $session = new LifecycleNodeSession(); $client = $this->client(fn()=>$session);
        $client->on('node',static function(array $event) use ($client):void { $client->stop(); });
        $client->connect(); $session->incoming[] = new BinaryNode('success'); $client->poll(0);
        self::assertSame('stopped',$client->getState()); self::assertSame([],$session->sent);
        $calls=0; $duringOpen = $this->client(static function() use (&$calls):NodeSession { $calls++; return new LifecycleNodeSession(); });
        $duringOpen->on('connection',static function(array $event) use ($duringOpen):void { if ($event['state']==='connecting') $duringOpen->stop(); });
        $duringOpen->connect(); self::assertSame(0,$calls); self::assertSame('stopped',$duringOpen->getState());
    }
    public function testSuccessIsProcessedOnceEvenWhenRepeatedDuringActiveQuery(): void {
        $this->paired(); $session = new LifecycleNodeSession(); $states=[]; $client = $this->client(fn()=>$session);
        $client->on('connection',static function(array $event) use (&$states):void { $states[]=$event['state']; });
        $client->connect(); $session->incoming=[new BinaryNode('success'),new BinaryNode('success')];
        $client->poll(0); $client->poll(0);
        self::assertSame(1,$this->saved()['loginCounter']); self::assertCount(2,$session->sent);
        self::assertSame(['connecting','authenticating','connected'],$states);
    }
    public function testKeepAliveRunsDuringAuthenticationAndResetsAfterActiveLogin(): void {
        $this->paired(); $session = new LifecycleNodeSession(); $client = $this->client(fn()=>$session);
        $client->connect(); $this->time=100.0; $client->poll(0); self::assertCount(1,$session->sent);
        self::assertSame('w:p',$session->sent[0]->attrs['xmlns']);self::assertSame('authenticating',$client->getState());self::assertFalse($client->isConnected());
        $session->incoming[] = new BinaryNode('success'); $client->poll(0);
        $this->time=119.9; $client->poll(0); self::assertCount(3,$session->sent);
        $this->time=120.0; $client->poll(0); self::assertCount(4,$session->sent);
        self::assertSame('w:p',$session->sent[3]->attrs['xmlns']); self::assertNotNull($session->sent[3]->child('ping'));
    }
    public function testMessageQueryCorrelatesOnlyMessageAckAndRejectsServerError(): void {
        $session = new LifecycleNodeSession(); $client = $this->client(fn()=>$session); $client->connect();
        $session->incoming=[new BinaryNode('ack',['id'=>'msg','class'=>'notification']),new BinaryNode('ack',['id'=>'msg','class'=>'message'])];
        $response = $client->query(new BinaryNode('message',['id'=>'msg']));
        self::assertSame('message',$response->attrs['class']);
        $session->incoming=[new BinaryNode('ack',['id'=>'bad','class'=>'message','error'=>'406'])];
        try { $client->query(new BinaryNode('message',['id'=>'bad'])); self::fail('Accepted rejected message'); }
        catch (\MVO\Service\ServiceException $error) { self::assertSame('message',$error->operation);self::assertSame('406',$error->serverCode);self::assertSame('WhatsApp rejected outgoing message',$error->reason); }
        self::assertNotSame('reconnecting',$client->getState());
        $session->incoming=[new BinaryNode('ack',['id'=>'nested-bad','class'=>'message'],[new BinaryNode('error',['code'=>'403','text'=>'private server detail'])])];
        try { $client->query(new BinaryNode('message',['id'=>'nested-bad']));self::fail('Accepted rejected message'); }
        catch (\MVO\Service\ServiceException $error) { self::assertSame('403',$error->serverCode);self::assertStringNotContainsString('private server detail',$error->getMessage()); }
    }
    public function testQueryFailureSchedulesRecoveryButNeverReplaysUncertainMessage(): void {
        $this->paired(); $a=new LifecycleNodeSession(); $b=new LifecycleNodeSession(); $index=0;
        $client = $this->client(static function() use (&$index,$a,$b):NodeSession { return $index++===0 ? $a : $b; });
        $client->connect(); $a->sendFailure = new \RuntimeException('WebSocket write failed');
        try { $client->query(new BinaryNode('message',['id'=>'uncertain'])); self::fail('Write failure ignored'); }
        catch (\RuntimeException $error) { self::assertSame('WebSocket write failed',$error->getMessage()); }
        self::assertSame('reconnecting',$client->getState()); $this->time=1.0; $client->poll(0);
        self::assertSame([],$b->sent); self::assertSame(2,$index);
    }
    public function testCallQueryCorrelatesOnlyCallAckAndRejectsServerError():void {
        $session=new LifecycleNodeSession(); $client=$this->client(fn()=>$session); $client->connect();
        $session->incoming=[new BinaryNode('ack',['id'=>'call-id','class'=>'message']),new BinaryNode('ack',['id'=>'call-id','class'=>'call'])];
        $result=$client->query(new BinaryNode('call',['id'=>'call-id']));
        self::assertSame('call',$result->attrs['class']);
        $session->incoming=[new BinaryNode('ack',['id'=>'bad-call','class'=>'call','error'=>'406'])];
        try { $client->query(new BinaryNode('call',['id'=>'bad-call'])); self::fail('Accepted rejected call'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('call error 406',$error->getMessage()); }
    }
    public function testInitialTransportFailureCanRecoverWithoutNewIdentity(): void {
        $credentials=$this->paired(); $calls=0; $session=new LifecycleNodeSession();
        $client = $this->client(static function() use (&$calls,$session):NodeSession {
            if (++$calls===1) throw new \RuntimeException('WebSocket connection failed: offline'); return $session;
        });
        $client->connect(); self::assertSame('reconnecting',$client->getState());
        $this->time=1.0; $client->poll(0); self::assertSame('authenticating',$client->getState());
        self::assertSame($credentials['noiseKeyPair']['private'],$session->staticPrivate);
    }
    public function testProtocolCorruptionAndDisabledRecoveryAreFatal(): void {
        $this->paired(); $session=new LifecycleNodeSession(); $client=$this->client(fn()=>$session);
        $client->connect(); $session->incoming[]=new \UnexpectedValueException('Noise MAC mismatch');
        try { $client->poll(0); self::fail('Corruption was retried'); } catch (\UnexpectedValueException) { }
        self::assertSame('disconnected',$client->getState());
        $disabled=new LifecycleNodeSession(); $manual=$this->client(fn()=>$disabled,false); $manual->connect();
        $disabled->incoming[]=new BinaryNode('stream:error',['code'=>'515']);
        try { $manual->poll(0); self::fail('Disabled recovery was ignored'); } catch (ConnectionFailure) { }
        self::assertSame('disconnected',$manual->getState());
    }
    public function testTransientIqErrorSchedulesOneRecoveryEvenInsideNodeCallback(): void {
        $this->paired(); $session=new LifecycleNodeSession(); $client=$this->client(fn()=>$session); $reconnects=[];
        $client->on('reconnect',static function(array $event) use (&$reconnects):void { $reconnects[]=$event; });
        $client->on('node',static function(array $event) use ($client):void {
            if ($event['node']->tag==='nested-query') $client->query(new BinaryNode('iq',['id'=>'nested','type'=>'get']));
        });
        $client->connect();
        $session->incoming=[new BinaryNode('nested-query'),new BinaryNode('iq',['id'=>'nested','type'=>'error'],[new BinaryNode('error',['code'=>'503'])])];
        $client->poll(0); self::assertCount(1,$reconnects); self::assertSame('reconnecting',$client->getState());
    }
    /** @return iterable<string,array{string,bool}> */
    public static function upgradeCodes(): iterable {
        yield 'service-unavailable'=>['503',true]; yield 'timeout'=>['408',true]; yield 'unauthorized'=>['401',false]; yield 'invalid-upgrade'=>['101',false];
    }
    #[DataProvider('upgradeCodes')]
    public function testOnlyTransientHttpUpgradeRejectionsRetry(string $code,bool $retryable): void {
        $this->paired(); $client=$this->client(static function() use ($code):NodeSession {
            throw new \UnexpectedValueException('Server refused WebSocket upgrade: HTTP/1.1 '.$code.' rejected');
        });
        try { $client->connect(); self::assertTrue($retryable); }
        catch (\UnexpectedValueException) { self::assertFalse($retryable); }
        self::assertSame($retryable ? 'reconnecting' : 'disconnected',$client->getState());
    }
    public function testExponentialRetryDelayIsCapped(): void {
        $this->paired(); $client=$this->client(static function():NodeSession { throw new \RuntimeException('WebSocket connection failed: offline'); });
        $delays=[]; $client->on('reconnect',static function(array $event) use (&$delays):void { $delays[]=$event['delay']; });
        $client->connect();
        foreach ([1.0,3.0,7.0,15.0] as $time) { $this->time=$time; $client->poll(0); }
        self::assertSame([1.0,2.0,4.0,8.0,8.0],$delays);
    }
}

final class LifecycleNodeSession implements NodeSession
{
    /** @var list<BinaryNode|\Throwable> */ public array $incoming=[];
    /** @var list<BinaryNode> */ public array $sent=[];
    public string $staticPrivate=''; public string $payload=''; public bool $closed=false;
    public ?\Throwable $sendFailure=null;
    public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string {
        $this->staticPrivate=$staticPrivate; $this->payload=$clientPayload; return str_repeat('s',32);
    }
    public function sendNode(BinaryNode $node):void {
        $this->sent[]=$node;
        if ($this->sendFailure!==null) throw $this->sendFailure;
        if ($node->tag==='iq' && in_array($node->attrs['type'] ?? '',['get','set'],true)) {
            $content=null;
            if (($node->attrs['xmlns'] ?? '')==='w:sync:app:state') $content=[new BinaryNode('sync',[],array_map(static fn(BinaryNode $collection):BinaryNode=>new BinaryNode('collection',['name'=>$collection->attrs['name'],'version'=>$collection->attrs['version']]),$node->child('sync')->content))];
            $this->incoming[]=new BinaryNode('iq',['id'=>$node->attrs['id'],'type'=>'result'],$content);
        }
    }
    public function receiveNode(float $timeoutSeconds=1):?BinaryNode {
        $next=array_shift($this->incoming); if ($next instanceof \Throwable) throw $next; return $next;
    }
    public function close():void { $this->closed=true; }
}
