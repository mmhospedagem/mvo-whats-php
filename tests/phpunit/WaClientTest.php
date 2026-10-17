<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Auth\CredentialStore;
use MVO\Protocol\BinaryNode;
use MVO\Transport\NodeSession;
use MVO\WaClient;

final class WaClientTest extends TestCase
{
    private string $directory;
    protected function setUp(): void { $this->directory = sys_get_temp_dir().'/mvo-client-'.bin2hex(random_bytes(8)); mkdir($this->directory,0700); }
    protected function tearDown(): void {
        gc_collect_cycles();
        foreach (glob($this->directory.'/*/*') ?: [] as $file) unlink($file);
        foreach (glob($this->directory.'/*') ?: [] as $dir) rmdir($dir);
        rmdir($this->directory);
    }
    public function testNoiseEstablishedDoesNotMeanAuthenticatedLogin(): void {
        $session = $this->session(); $states = [];
        $client = new WaClient(authDirectory:$this->directory,sessionFactory:fn()=>$session);
        $client->on('connection',static function(array $e) use (&$states):void { $states[] = $e['state']; });
        $client->connect(); self::assertSame(['connecting','pairing'],$states); self::assertFalse($client->isConnected());
        $session->incoming[] = new BinaryNode('success');
        try { $client->poll(0); self::fail('Accepted unauthenticated success'); } catch (\RuntimeException $e) { self::assertStringContainsString('without a verified pairing',$e->getMessage()); }
        self::assertSame('disconnected',$client->getState());
    }
    public function testReceivesQrAndOnlyLockOwnerRemovesImage(): void {
        $session = $this->session(); $qr = null;
        $client = new WaClient(authDirectory:$this->directory,sessionFactory:fn()=>$session);
        $client->on('auth_qr',static function(array $e) use (&$qr):void { $qr=$e; }); $client->connect();
        $session->incoming[] = new BinaryNode('iq',['type'=>'set','id'=>'pair','from'=>'s.whatsapp.net'],[new BinaryNode('pair-device',[],[new BinaryNode('ref',[],'server-ref')])]);
        $client->poll(0); self::assertNotNull($qr); self::assertCount(5,explode(',',$qr['qr']));
        self::assertSame('result',$session->sent[0]->attrs['type']);
        $path = $client->sessionDirectory().'/qr.svg'; file_put_contents($path,'active-qr');
        $other = new WaClient(authDirectory:$this->directory,sessionFactory:fn()=>$this->session());
        try { $other->connect(); self::fail('Allowed concurrent session'); } catch (\RuntimeException) { }
        $other->disconnect(); self::assertFileExists($path); unset($other);
        $client->disconnect(); self::assertFileDoesNotExist($path);
    }
    public function testIqCorrelationAndErrors(): void {
        $session = $this->session(); $client = new WaClient(authDirectory:$this->directory,sessionFactory:fn()=>$session); $client->connect();
        $answer = $client->query(new BinaryNode('iq',['type'=>'get','id'=>'q'])); self::assertSame('q',$answer->attrs['id']);
        $session->autoReply = false; $session->incoming[] = new BinaryNode('iq',['type'=>'error','id'=>'bad'],[new BinaryNode('error',['code'=>'400','text'=>'bad-request'])]);
        try { $client->query(new BinaryNode('iq',['id'=>'bad']),0.1); self::fail('Accepted IQ error'); } catch (\RuntimeException $e) { self::assertStringContainsString('400',$e->getMessage()); }
        $client->disconnect();
    }
    public function testPairedLoginRequiresSuccessAndActiveIqThenReconnects515(): void {
        $store = new CredentialStore($this->directory); $creds = CredentialStore::fresh();
        $creds['registered']=true; $creds['meJid']='5511999999999:12@s.whatsapp.net'; $store->save($creds); unset($store);
        $sessions = [$this->session(),$this->session()]; $index=0;
        $client = new WaClient(authDirectory:$this->directory,sessionFactory:static function() use (&$index,$sessions):NodeSession { return $sessions[$index++]; });
        $client->connect(); self::assertTrue($client->isRegistered()); self::assertFalse($client->isConnected());
        $sessions[0]->incoming[] = new BinaryNode('success',['lid'=>'123@lid']); $client->poll(0);
        self::assertTrue($client->isConnected()); self::assertSame('encrypt',$sessions[0]->sent[0]->attrs['xmlns']);
        self::assertSame('passive',$sessions[0]->sent[1]->attrs['xmlns']);
        $sessions[0]->incoming[] = new BinaryNode('stream:error',['code'=>'515']); $client->poll(0);
        self::assertSame('authenticating',$client->getState()); self::assertTrue($sessions[0]->closed);
        $sessions[1]->incoming[] = new BinaryNode('success'); $client->poll(0); self::assertTrue($client->isConnected()); $client->disconnect();
    }
    public function test515DuringReentrantQueryReconnectsWithoutReplayingIq(): void {
        $store = new CredentialStore($this->directory); $creds=CredentialStore::fresh(); $creds['registered']=true; $creds['meJid']='5511999999999:12@s.whatsapp.net'; $store->save($creds); $store->close();
        $sessions=[$this->session(),$this->session()]; $sessions[0]->autoReply=false; $index=0;
        $client=new WaClient(authDirectory:$this->directory,sessionFactory:static function() use (&$index,$sessions):NodeSession { return $sessions[$index++]; });
        $client->on('node',static function(array $e) use ($client):void {
            if ($e['node']->tag==='test-reentrant') $client->query(new BinaryNode('iq',['type'=>'set','id'=>'finish']),0.1);
        });
        $client->connect(); $sessions[0]->incoming=[new BinaryNode('test-reentrant'),new BinaryNode('stream:error',['code'=>'515'])];
        $client->poll(0); self::assertSame(2,$index); self::assertSame('authenticating',$client->getState()); self::assertCount(0,$sessions[1]->sent); $client->disconnect();
    }
    private function session(): FakeNodeSession { return new FakeNodeSession(); }
}

final class FakeNodeSession implements NodeSession
{
    /** @var list<BinaryNode> */ public array $incoming=[];
    /** @var list<BinaryNode> */ public array $sent=[];
    public bool $closed=false;
    public bool $autoReply=true;
    public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string { return str_repeat('s',32); }
    public function sendNode(BinaryNode $node):void {
        $this->sent[]=$node;
        if ($this->autoReply && $node->tag==='iq' && in_array($node->attrs['type'] ?? '',['get','set'],true)) $this->incoming[]=new BinaryNode('iq',['id'=>$node->attrs['id'],'type'=>'result']);
    }
    public function receiveNode(float $timeoutSeconds=1):?BinaryNode { return array_shift($this->incoming); }
    public function close():void { $this->closed=true; }
}
