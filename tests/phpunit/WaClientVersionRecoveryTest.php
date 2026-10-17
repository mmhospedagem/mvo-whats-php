<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\History\HistoryStore;
use MVO\Protocol\{BinaryNode,Protobuf};
use MVO\Signal\SignalStore;
use MVO\Transport\{ConnectionFailure,NodeSession};
use MVO\WaClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Offline lifecycle regressions: no WhatsApp endpoint or account is contacted. */
final class WaClientVersionRecoveryTest extends TestCase
{
    private const FIRST_VERSION='2.3000.1045000001';
    private const SECOND_VERSION='2.3000.1045000002';
    private const CONTACT='5511888888888@s.whatsapp.net';
    private string $directory;
    private float $time=0.0;
    /** @var list<WaClient> */ private array $clients=[];

    protected function setUp():void
    {
        $this->directory=sys_get_temp_dir().'/mvo-version-recovery-'.bin2hex(random_bytes(8));
        mkdir($this->directory,0700);
    }

    protected function tearDown():void
    {
        foreach($this->clients as $client)$client->stop();
        $this->clients=[];gc_collect_cycles();
        $entries=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($entries as $entry){if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}
        rmdir($this->directory);
    }

    /** @return array<string,mixed> */
    private function paired(bool $mobile=false):array
    {
        $credentials=CredentialStore::fresh();$credentials['registered']=true;$credentials['serverHasPreKeys']=true;
        $credentials['meJid']=$mobile?'5511999999999@s.whatsapp.net':'5511999999999:12@s.whatsapp.net';
        if($mobile)$credentials['role']='primary';
        $store=new CredentialStore($this->directory);$store->save($credentials);$store->close();return $credentials;
    }

    /** @return array<string,mixed> */
    private function saved():array
    {
        $store=new CredentialStore($this->directory);try{$credentials=$store->load();self::assertNotNull($credentials);return $credentials;}finally{$store->close();}
    }

    /** @param callable():NodeSession $factory
     * @param callable():string $resolver
     * @param array<string,mixed>|null $mobileInfo */
    private function client(callable $factory,callable $resolver,bool $autoReconnect=true,?array $mobileInfo=null):WaClient
    {
        $client=new WaClient(authDirectory:$this->directory,sessionFactory:$factory,autoReconnect:$autoReconnect,
            reconnectMaxSeconds:8.0,clock:fn():float=>$this->time,random:static fn():float=>0.5,
            versionResolver:$resolver,mobileDeviceInfo:$mobileInfo,automaticMaintenance:false);
        $this->clients[]=$client;return $client;
    }

    private function login(WaClient $client,VersionRecoveryNodeSession $peer):void
    {
        $peer->incoming[]=new BinaryNode('success');$client->poll(0);self::assertTrue($client->isConnected());
    }

    private function reject(WaClient $client,VersionRecoveryNodeSession $peer,string $code='405'):void
    {
        $peer->incoming[]=new BinaryNode('failure',['reason'=>$code]);
        try{$client->poll(0);self::fail('Rejected connection unexpectedly remained running');}
        catch(ConnectionFailure $error){self::assertSame($code,$error->serverCode);}
    }

    /** @return iterable<string,array{string}> */
    public static function rejectionOrigins():iterable
    {
        yield 'stream stanza'=>['stream'];yield 'authentication stanza'=>['failure'];yield 'Noise handshake'=>['handshake'];
    }

    #[DataProvider('rejectionOrigins')]
    public function test405AutomaticallyResumesSameSessionAndPersistsVersion(string $origin):void
    {
        $credentials=$this->paired();$first=new VersionRecoveryNodeSession();$next=new VersionRecoveryNodeSession();$index=0;$resolved=0;$qr=0;
        if($origin==='handshake')$first->startFailure=new ConnectionFailure('Obsolete Web version','405',false);
        $signal=new SignalStore($this->directory.'/default/state.sqlite');
        $signal->trustIdentity(self::CONTACT,$credentials['identityKeyPair']['public']);
        $signal->putPreKey(500,$credentials['noiseKeyPair']);
        $history=new HistoryStore($signal);$history->upsertMessage(['chat'=>self::CONTACT,'id'=>'kept-message','timestamp'=>123,'text'=>'Stored before recovery']);
        $trusted=$signal->getIdentity(self::CONTACT);$preKey=$signal->getPreKey(500);$message=$history->getMessage(self::CONTACT,'kept-message');$signal->close();
        $client=$this->client(static function()use(&$index,$first,$next):NodeSession{return $index++===0?$first:$next;},static function()use(&$resolved,$first):string{self::assertTrue($first->closed);$resolved++;return self::FIRST_VERSION;});
        $client->on('auth_qr',static function()use(&$qr):void{$qr++;});
        $updates=[];$client->on('version_updated',static function(array $event)use(&$updates):void{$updates[]=$event;});
        $client->connect();
        if($origin!=='handshake'){
            $first->incoming[]=$origin==='stream'?new BinaryNode('stream:error',['code'=>'405']):new BinaryNode('failure',['reason'=>'405']);
            $client->poll(0);
        }
        self::assertTrue($first->closed);self::assertTrue($client->isRunning());self::assertSame('reconnecting',$client->getState());
        self::assertSame(1,$resolved);self::assertCount(1,$updates);self::assertSame(self::FIRST_VERSION,$updates[0]['version']);
        $locked=new CredentialStore($this->directory);
        try{$locked->acquire();self::fail('Version recovery released the exclusive session lock');}catch(\RuntimeException $error){self::assertStringContainsString('already in use',$error->getMessage());}finally{$locked->close();}
        $client->poll(0);self::assertSame(2,$index);self::assertSame(self::FIRST_VERSION,$next->webVersion());
        self::assertSame($credentials['noiseKeyPair']['private'],$next->staticPrivate);$this->login($client,$next);
        self::assertSame(0,$qr);self::assertSame(self::FIRST_VERSION,$this->saved()['webVersion']);
        foreach(['noiseKeyPair','identityKeyPair','signedPreKey','advSecretKey','registrationId','meJid']as $field)self::assertSame($credentials[$field],$this->saved()[$field]);
        self::assertFalse($this->saved()['revoked']??false);
        $signal=new SignalStore($this->directory.'/default/state.sqlite');
        try{self::assertSame($trusted,$signal->getIdentity(self::CONTACT));self::assertSame($preKey,$signal->getPreKey(500));self::assertSame($message,(new HistoryStore($signal))->getMessage(self::CONTACT,'kept-message'));}finally{$signal->close();}
        $client->stop();$processPeer=new VersionRecoveryNodeSession();
        $process=$this->client(fn()=>$processPeer,static function():string{self::fail('A valid cached version must not be fetched during process restart');});
        $process->connect();self::assertSame(self::FIRST_VERSION,$processPeer->webVersion());
        self::assertSame($credentials['noiseKeyPair']['private'],$processPeer->staticPrivate);$this->login($process,$processPeer);
    }

    public function testAnother405BeforeStableConnectionDoesNotRefreshForever():void
    {
        $credentials=$this->paired();$first=new VersionRecoveryNodeSession();$next=new VersionRecoveryNodeSession();$index=0;$resolved=0;
        $client=$this->client(static function()use(&$index,$first,$next):NodeSession{return $index++===0?$first:$next;},static function()use(&$resolved):string{return ++$resolved===1?self::FIRST_VERSION:self::SECOND_VERSION;});
        $client->connect();$first->incoming[]=new BinaryNode('failure',['reason'=>'405']);$client->poll(0);$client->poll(0);
        $this->login($client,$next);$this->time=59.0;$this->reject($client,$next);
        self::assertSame(1,$resolved);self::assertSame(2,$index);self::assertFalse($client->isRunning());self::assertSame('disconnected',$client->getState());
        self::assertSame($credentials['identityKeyPair'],$this->saved()['identityKeyPair']);self::assertFalse($this->saved()['revoked']??false);
    }

    public function testStableAuthenticatedConnectionRearmsRecoveryAfter60Seconds():void
    {
        $this->paired();$peers=[new VersionRecoveryNodeSession(),new VersionRecoveryNodeSession(),new VersionRecoveryNodeSession()];$index=0;$resolved=0;
        $client=$this->client(static function()use(&$index,$peers):NodeSession{return $peers[$index++];},static function()use(&$resolved):string{return ++$resolved===1?self::FIRST_VERSION:self::SECOND_VERSION;});
        $client->connect();$peers[0]->incoming[]=new BinaryNode('failure',['reason'=>'405']);$client->poll(0);$client->poll(0);$this->login($client,$peers[1]);
        $this->time=60.0;$client->poll(0);self::assertNotEmpty(array_filter($peers[1]->sent,static fn(BinaryNode $node):bool=>$node->child('ping')!==null));
        $peers[1]->incoming[]=new BinaryNode('stream:error',['code'=>'405']);$client->poll(0);
        self::assertSame(2,$resolved);self::assertTrue($client->isRunning());$client->poll(0);
        self::assertSame(3,$index);self::assertSame(self::SECOND_VERSION,$peers[2]->webVersion());$this->login($client,$peers[2]);
    }

    /** @return iterable<string,array{string}> */
    public static function unusableVersions():iterable
    {
        yield 'unchanged'=>[WaClient::VERSION];yield 'malformed'=>['2.3000.bad'];yield 'overflow'=>['2.3000.4294967296'];yield 'missing components'=>['2.3000'];yield 'request failed'=>['throw'];
    }

    #[DataProvider('unusableVersions')]
    public function testUnusableVersionStopsWithoutErasingIdentityOrCachingBadVersion(string $version):void
    {
        $credentials=$this->paired();$peer=new VersionRecoveryNodeSession();$resolved=0;$errors=[];
        $client=$this->client(fn()=>$peer,static function()use($version,&$resolved):string{$resolved++;if($version==='throw')throw new \RuntimeException('Offline resolver failure');return $version;});
        $client->on('version_error',static function(array $event)use(&$errors):void{$errors[]=$event;});$client->connect();$this->reject($client,$peer);
        self::assertSame(1,$resolved);self::assertCount(1,$errors);self::assertFalse($client->isRunning());self::assertTrue($peer->closed);
        $saved=$this->saved();self::assertArrayNotHasKey('webVersion',$saved);self::assertSame($credentials['noiseKeyPair'],$saved['noiseKeyPair']);
        self::assertSame($credentials['identityKeyPair'],$saved['identityKeyPair']);self::assertFalse($saved['revoked']??false);
    }

    /** @return iterable<string,array{string}> */
    public static function stopEvents():iterable
    {
        yield 'updated version'=>['version_updated'];yield 'resolver failure'=>['version_error'];yield 'reconnecting state'=>['connection'];
    }

    #[DataProvider('stopEvents')]
    public function testStoppingDuringRecoveryCallbacksCancelsReopen(string $event):void
    {
        $this->paired();$peer=new VersionRecoveryNodeSession();$opens=0;$resolved=0;
        $client=$this->client(static function()use(&$opens,$peer):NodeSession{$opens++;return $peer;},static function()use(&$resolved,$event):string{$resolved++;if($event==='version_error')throw new \RuntimeException('Offline resolver failure');return self::FIRST_VERSION;});
        $client->on($event,static function(array $payload)use($client,$event):void{if($event!=='connection'||$payload['state']==='reconnecting')$client->stop();});
        $client->connect();$peer->incoming[]=new BinaryNode('failure',['reason'=>'405']);$client->poll(0);
        self::assertSame('stopped',$client->getState());self::assertFalse($client->isRunning());self::assertTrue($peer->closed);
        $this->time=100;$client->run();self::assertSame(1,$opens);self::assertSame($event==='connection'?0:1,$resolved);
        $other=new CredentialStore($this->directory);$other->acquire();$other->close();
    }

    public function testDisabledRecoveryDoesNotFetchOrRestart():void
    {
        $this->paired();$peer=new VersionRecoveryNodeSession();$resolved=0;
        $client=$this->client(fn()=>$peer,static function()use(&$resolved):string{$resolved++;return self::FIRST_VERSION;},false);
        $client->connect();$this->reject($client,$peer);self::assertSame(0,$resolved);self::assertFalse($client->isRunning());
    }

    public function testMobile405DoesNotReplaceMobileVersion():void
    {
        $this->paired(true);$peer=new VersionRecoveryNodeSession();$resolved=0;
        $info=['manufacturer'=>'motorola','device'=>'moto_g52','osVersion'=>'12','osBuildNumber'=>'ABC','appVersion'=>'2.26.15.11'];
        $client=$this->client(fn()=>$peer,static function()use(&$resolved):string{$resolved++;return self::FIRST_VERSION;},mobileInfo:$info);
        $client->connect();$this->reject($client,$peer);self::assertSame(0,$resolved);self::assertArrayNotHasKey('webVersion',$this->saved());
    }

    public function testRevoked401NeverFetchesVersionOrReconnects():void
    {
        $credentials=$this->paired();$peer=new VersionRecoveryNodeSession();$resolved=0;
        $client=$this->client(fn()=>$peer,static function()use(&$resolved):string{$resolved++;return self::FIRST_VERSION;});
        $client->connect();$this->reject($client,$peer,'401');self::assertSame(0,$resolved);self::assertSame('logged_out',$client->getState());
        self::assertTrue($this->saved()['revoked']);self::assertSame($credentials['identityKeyPair'],$this->saved()['identityKeyPair']);
    }

    public function testDeviceRemovalWith405NeverFetchesVersionOrReconnects():void
    {
        $credentials=$this->paired();$peer=new VersionRecoveryNodeSession();$resolved=0;
        $client=$this->client(fn()=>$peer,static function()use(&$resolved):string{$resolved++;return self::FIRST_VERSION;});
        $client->connect();$peer->incoming[]=new BinaryNode('stream:error',['code'=>'405'],[new BinaryNode('conflict',['type'=>'device_removed'])]);
        try{$client->poll(0);self::fail('Device removal unexpectedly remained running');}catch(ConnectionFailure $error){self::assertTrue($error->revoked);}
        self::assertSame(0,$resolved);self::assertSame('logged_out',$client->getState());self::assertTrue($this->saved()['revoked']);
        self::assertSame($credentials['identityKeyPair'],$this->saved()['identityKeyPair']);
    }

    public function testMalformedCachedVersionFallsBackWithoutErasingIdentity():void
    {
        $credentials=$this->paired();$credentials['webVersion']='2.3000.4294967296';
        $store=new CredentialStore($this->directory);$store->save($credentials);$store->close();$peer=new VersionRecoveryNodeSession();$errors=[];
        $client=$this->client(fn()=>$peer,static function():string{self::fail('Cache validation must not fetch a remote version');});
        $client->on('version_error',static function(array $event)use(&$errors):void{$errors[]=$event;});$client->connect();
        self::assertSame(WaClient::VERSION,$peer->webVersion());self::assertCount(1,$errors);self::assertTrue($client->isRunning());
        self::assertSame($credentials['identityKeyPair'],$this->saved()['identityKeyPair']);self::assertFalse($this->saved()['revoked']??false);
    }

    public function testResolverMayStopRecoveryBeforeVersionIsApplied():void
    {
        $this->paired();$peer=new VersionRecoveryNodeSession();$opens=0;$client=null;
        $client=$this->client(static function()use(&$opens,$peer):NodeSession{$opens++;return $peer;},static function()use(&$client):string{$client->stop();return self::FIRST_VERSION;});
        $client->connect();$peer->incoming[]=new BinaryNode('failure',['reason'=>'405']);$client->poll(0);
        self::assertSame('stopped',$client->getState());self::assertFalse($client->isRunning());self::assertTrue($peer->closed);
        $client->run();self::assertSame(1,$opens);self::assertArrayNotHasKey('webVersion',$this->saved());
    }

    public function testNewUnpairedSessionKeepsItsIdentityWhileRegistrationVersionChanges():void
    {
        $first=new VersionRecoveryNodeSession();$next=new VersionRecoveryNodeSession();$index=0;$qr=0;
        $client=$this->client(static function()use(&$index,$first,$next):NodeSession{return $index++===0?$first:$next;},static fn():string=>self::FIRST_VERSION);
        $client->on('auth_qr',static function()use(&$qr):void{$qr++;});$client->connect();$credentials=$this->saved();
        $first->incoming[]=new BinaryNode('failure',['reason'=>'405']);$client->poll(0);$client->poll(0);
        self::assertSame('pairing',$client->getState());self::assertSame(self::FIRST_VERSION,$next->webVersion());self::assertSame(0,$qr);
        self::assertSame($credentials['identityKeyPair'],$this->saved()['identityKeyPair']);self::assertSame($credentials['noiseKeyPair']['private'],$next->staticPrivate);
        $fields=static function(string $bytes):array{$out=[];foreach(Protobuf::decode($bytes)as $field)$out[$field['number']]=$field['value'];return $out;};
        self::assertSame(md5(self::FIRST_VERSION,true),$fields($fields($next->payload)[19])[7]);self::assertFalse($this->saved()['registered']);
    }
}

final class VersionRecoveryNodeSession implements NodeSession
{
    /** @var list<BinaryNode> */public array $incoming=[];
    /** @var list<BinaryNode> */public array $sent=[];
    public string $staticPrivate='';public string $payload='';public bool $closed=false;
    public ?ConnectionFailure $startFailure=null;
    public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string
    {
        $this->staticPrivate=$staticPrivate;$this->payload=$clientPayload;if($this->startFailure!==null)throw $this->startFailure;return str_repeat('s',32);
    }
    public function sendNode(BinaryNode $node):void
    {
        $this->sent[]=$node;if($node->tag==='iq'&&in_array($node->attrs['type']??'',['get','set'],true)){
            $body=null;if(($node->attrs['xmlns']??'')==='w:sync:app:state')$body=[new BinaryNode('sync',[],array_map(static fn(BinaryNode $collection):BinaryNode=>new BinaryNode('collection',['name'=>$collection->attrs['name'],'version'=>$collection->attrs['version']]),$node->child('sync')->content))];
            $this->incoming[]=new BinaryNode('iq',['id'=>$node->attrs['id'],'type'=>'result'],$body);
        }
    }
    public function receiveNode(float $timeoutSeconds=1.0):?BinaryNode{return array_shift($this->incoming);}
    public function close():void{$this->closed=true;}
    public function webVersion():string
    {
        $fields=static function(string $bytes):array{$out=[];foreach(Protobuf::decode($bytes)as $field)$out[$field['number']]=$field['value'];return $out;};
        return implode('.',array_values($fields($fields($fields($this->payload)[5])[2])));
    }
}
