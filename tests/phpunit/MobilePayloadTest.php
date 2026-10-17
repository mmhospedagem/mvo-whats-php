<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Auth\CredentialStore;
use MVO\Mobile\{ClientPayload,PrimaryCredentials};
use MVO\Protocol\SchemaCodec;

final class MobilePayloadTest extends TestCase
{
    private function credentials():array { $c=CredentialStore::fresh();$c['registered']=true;$c['meJid']='5511987654321@s.whatsapp.net';return $c; }
    private function info():array { return ['manufacturer'=>'motorola','device'=>'moto_g52','osVersion'=>'12','osBuildNumber'=>'ABC','appVersion'=>'2.26.15.11','mcc'=>'724','mnc'=>'03','phoneId'=>'00000000-0000-0000-0000-000000000001']; }
    public function testAndroidPrimaryPayloadCarriesIdentityAndMobileDeviceFlags():void
    {
        $decoded=SchemaCodec::decode('ClientPayload',ClientPayload::login($this->credentials(),$this->info(),['pushName'=>'Conta','yearClass'=>2022]));
        self::assertSame(5511987654321,$decoded['username']);self::assertSame(0,$decoded['device']);self::assertFalse($decoded['passive']);self::assertTrue($decoded['pull']);self::assertSame('Conta',$decoded['pushName']);self::assertSame(2022,$decoded['yearClass']);
        $agent=$decoded['userAgent'];self::assertSame(0,$agent['platform']);self::assertSame('724',$agent['mcc']);self::assertSame('03',$agent['mnc']);self::assertSame(11,$agent['appVersion']['quaternary']);self::assertArrayNotHasKey('distributionChannel',$agent);
    }
    public function testIosPayloadOmitsAndroidSpecificFieldsAndUsesIosBusinessPlatform():void
    {
        $info=$this->info()+['os'=>'ios','business'=>true,'deviceModelType'=>'iPhone16,1','distributionChannel'=>'testflight'];$decoded=SchemaCodec::decode('ClientPayload',ClientPayload::login($this->credentials(),$info));$agent=$decoded['userAgent'];
        self::assertSame(12,$agent['platform']);self::assertSame('iPhone16,1',$agent['deviceModelType']);self::assertArrayNotHasKey('mcc',$agent);self::assertArrayNotHasKey('mnc',$agent);self::assertArrayNotHasKey('deviceBoard',$agent);self::assertSame(2,$agent['distributionChannel']);
    }
    public function testWebCompanionCannotBePromotedByMobileOptions():void
    {
        $c=$this->credentials();$c['meJid']='5511987654321:52@s.whatsapp.net';$this->expectException(\InvalidArgumentException::class);ClientPayload::login($c,$this->info());
    }
    public function testUnregisteredAndInvalidAppVersionsFailBeforeTransport():void
    {
        foreach(['2.26.15','2.26.bad.11','2.26.15.11.1'] as $version){$info=$this->info();$info['appVersion']=$version;try{ClientPayload::login($this->credentials(),$info);self::fail();}catch(\InvalidArgumentException $e){self::assertStringContainsString('version',$e->getMessage());}}
        $this->expectException(\InvalidArgumentException::class);PrimaryCredentials::validate(CredentialStore::fresh());
    }
    public function testReferenceBinaryCredentialsMapWithoutRegeneratingIdentity():void
    {
        $c=$this->credentials();$pair=static fn($p)=>['privKey'=>$p['private'],'pubKey'=>"\x05".$p['public']];
        $source=['meJid'=>$c['meJid'],'noiseKeyPair'=>$pair($c['noiseKeyPair']),'registrationInfo'=>['registrationId'=>$c['registrationId'],'identityKeyPair'=>$pair($c['identityKeyPair'])],
            'signedPreKey'=>['keyId'=>$c['signedPreKey']['id'],'keyPair'=>$pair($c['signedPreKey']),'signature'=>$c['signedPreKey']['signature']],'advSecretKey'=>$c['advSecretKey']];
        $mapped=PrimaryCredentials::fromReference($source);self::assertSame($c['noiseKeyPair'],$mapped['noiseKeyPair']);self::assertSame($c['identityKeyPair'],$mapped['identityKeyPair']);self::assertSame($c['signedPreKey'],$mapped['signedPreKey']);self::assertTrue($mapped['registered']);
    }
}
