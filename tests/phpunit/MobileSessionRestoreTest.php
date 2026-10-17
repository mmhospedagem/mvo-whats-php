<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Transport\NodeSession;
use MVO\WaClient;
use PHPUnit\Framework\TestCase;

final class MobileSessionRestoreTest extends TestCase
{
    private string $directory;
    private array $clients=[];
    protected function setUp():void {$this->directory=sys_get_temp_dir().'/mvo-mobile-restore-'.bin2hex(random_bytes(8));mkdir($this->directory,0700);}
    protected function tearDown():void {
        foreach($this->clients as$client)$client->disconnect();$this->clients=[];gc_collect_cycles();
        $files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as$file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($this->directory);
    }
    private function info():array {return ['os'=>'android','manufacturer'=>'motorola','device'=>'moto_g52','osVersion'=>'12','osBuildNumber'=>'fixture-build','appVersion'=>'2.26.39.79','phoneId'=>'00000000-0000-4000-8000-000000000001'];}
    private function credentials():array {$c=CredentialStore::fresh();$c['registered']=true;$c['meJid']='5511999990000@s.whatsapp.net';return $c;}
    private function client(?array $info,MobileRestoreNodeSession $session):WaClient {
        $client=new WaClient(authDirectory:$this->directory,mobileDeviceInfo:$info,sessionFactory:static fn():NodeSession=>$session,automaticMaintenance:false);$this->clients[]=$client;return $client;
    }
    public function testImportedPrimaryRestoresDeviceConfigurationAndIdentitiesAcrossNewClients():void {
        $original=$this->credentials();$first=$this->client($this->info(),new MobileRestoreNodeSession());$first->importPrimaryCredentials($original);
        $store=new CredentialStore($this->directory);$persisted=$store->load();$store->close();self::assertSame($this->info(),$persisted['mobileDeviceInfo']);
        $session=new MobileRestoreNodeSession();$second=$this->client(null,$session);$second->connect();
        self::assertTrue($second->isPrimary());self::assertSame($original['meJid'],$second->accountJid());
        self::assertSame(0,$session->payload['device']);self::assertSame(0,$session->payload['userAgent']['platform']);self::assertSame(79,$session->payload['userAgent']['appVersion']['quaternary']);
        $account=$second->accountInfo();self::assertSame('mobile_tcp',$account['transport']);self::assertSame('primary',$account['role']);self::assertSame('2.26.39.79',$account['mobileVersion']);self::assertNull($account['webVersion']);
        $second->disconnect();$session2=new MobileRestoreNodeSession();$third=$this->client(null,$session2);$third->connect();self::assertTrue($third->isPrimary());
        self::assertSame($session->publicKey,$session2->publicKey);self::assertSame($original['noiseKeyPair']['public'],$session2->publicKey);
    }
    public function testPrimaryWithoutDeviceConfigurationFailsBeforeOpeningAnyTransport():void {
        $store=new CredentialStore($this->directory);$credentials=$this->credentials();$credentials['role']='primary';$store->save($credentials);$store->close();
        $session=new MobileRestoreNodeSession();$client=$this->client(null,$session);
        try{$client->connect();self::fail('Primary session fell back to Web');}catch(\LogicException $error){self::assertStringContainsString('device information',$error->getMessage());}
        self::assertNull($session->payload);
        $store=new CredentialStore($this->directory);self::assertSame($credentials,$store->load());$store->close();
    }
    public function testMobileConfigurationCannotOverwriteAnExistingCompanion():void {
        $store=new CredentialStore($this->directory);$credentials=$this->credentials();$credentials['meJid']='5511999990000:4@s.whatsapp.net';$store->save($credentials);$store->close();
        $session=new MobileRestoreNodeSession();$client=$this->client($this->info(),$session);
        try{$client->importPrimaryCredentials($this->credentials());self::fail('Companion overwritten');}catch(\LogicException){}
        $store=new CredentialStore($this->directory);self::assertSame($credentials,$store->load());$store->close();self::assertNull($session->payload);
    }
    public function testRegistrationFacadePersistsThePrimaryAndRestoresItWithoutExplicitDeviceInfo():void {
        $session=new MobileRestoreNodeSession();$client=$this->client(null,$session);
        $transport=static fn(\MVO\Mobile\RegistrationRequest $r):\MVO\Mobile\RegistrationResponse=>new \MVO\Mobile\RegistrationResponse($r->operation==='code'?['status'=>'sent']:['status'=>'ok','login'=>'5511999990000']);
        $client->mobileRegistration(transport:$transport)->requestCode(['cc'=>'55','phoneNumber'=>'11999990000','method'=>'sms']);
        self::assertFalse($client->mobileRegistration()->state()['registered']);
        $second=$this->client(null,$session);
        $second->mobileRegistration(transport:$transport)->verifyCode(['code'=>'123456']);
        $second->connect();self::assertTrue($second->isPrimary());self::assertSame(0,$session->payload['device']);
        self::assertSame('2.26.39.79',$second->accountInfo()['mobileVersion']);
    }
    public function testPendingMobileRegistrationCannotAccidentallyStartWebQr():void {
        $session=new MobileRestoreNodeSession();$client=$this->client(null,$session);
        $client->mobileRegistration(transport:static fn():\MVO\Mobile\RegistrationResponse=>new \MVO\Mobile\RegistrationResponse(['status'=>'sent']))->requestCode(['cc'=>'55','phoneNumber'=>'11999990000']);
        $store=new CredentialStore($this->directory);$before=$store->load();$store->close();
        try{$client->connect();self::fail('Pending mobile registration started Web');}catch(\LogicException $error){self::assertStringContainsString('pending mobile registration',$error->getMessage());}
        self::assertNull($session->payload);$store=new CredentialStore($this->directory);self::assertSame($before,$store->load());$store->close();
    }
}

final class MobileRestoreNodeSession implements NodeSession
{
    public ?array $payload=null;
    public ?string $publicKey=null;
    public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string {$this->publicKey=$staticPublic;$this->payload=SchemaCodec::decode('ClientPayload',$clientPayload);return str_repeat('s',32);}
    public function sendNode(BinaryNode $node):void {}
    public function receiveNode(float $timeoutSeconds=1):?BinaryNode {return null;}
    public function close():void {}
}
