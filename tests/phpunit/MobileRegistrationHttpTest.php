<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Mobile\{RegistrationHttpTransport,RegistrationRequest,RegistrationResponse,RegistrationWire};
use PHPUnit\Framework\TestCase;

final class MobileRegistrationHttpTest extends TestCase
{
    private function info():array {return ['os'=>'android','appVersion'=>'2.26.39.79','osVersion'=>'12','manufacturer'=>'motorola','device'=>'moto_g52'];}
    public function testRegistrationEnvelopeMatchesIndependentPythonCryptographyVector():void {
        $ephemeral=implode('',array_map(chr(...),range(0,31)));$server=hex2bin('358072d6365880d1aeea329adf9121383851ed21a28e3b75e965d0d2cd166254');
        $sealed=RegistrationWire::seal(['cc'=>'55','in'=>'11999990000','id'=>"\0\xff",'code'=>'123456'],$ephemeral,$server);
        self::assertSame('j0DFrbaPJWJK5bIU6nZ6bslNgp09e14a0bpvPiE4KF_-i6zkpEglr6hxfPHCF-P6qMSk3B4Hk6qI-2lYlk6giooTM9ByeRtOHb_OidksI0vR_bh_7eF8HdDd',$sealed);
    }
    public function testFixedHttpsPathsNeverPutTheOtpIntoTheUrlHeadersOrPlaintextBody():void {
        $calls=[];$transport=new RegistrationHttpTransport(exchange:static function(string $url,array $headers,string $body)use(&$calls):RegistrationResponse{$calls[]=[$url,$headers,$body];return new RegistrationResponse(['status'=>'sent']);});
        foreach(['code','register','security'] as$operation)$transport->send(new RegistrationRequest($operation,['cc'=>'55','in'=>'11999990000','code'=>'020202'],$this->info()));
        self::assertSame(['https://v.whatsapp.net/v2/code','https://v.whatsapp.net/v2/register','https://v.whatsapp.net/v2/security'],array_column($calls,0));
        foreach($calls as[$url,$headers,$body]){self::assertStringNotContainsString('020202',$url.implode('\n',$headers).$body);self::assertStringStartsWith('ENC=',$body);self::assertStringEndsWith('&H=',$body);self::assertCount(3,$headers);self::assertSame('Content-Type: application/x-www-form-urlencoded',$headers[0]);}
        self::assertNotSame($calls[0][2],$calls[1][2]); // Zero nonce is safe only with a new ephemeral key per request.
    }
    public function testOptionalAttestationUsesOnlyTheSuppliedGenuineProviderResult():void {
        $signed=null;$transport=new RegistrationHttpTransport(static function(string $encoded)use(&$signed):array{$signed=$encoded;return ['signature'=>"\xffsignature",'certificateChain'=>"\0DER-chain"];},static function(string $url,array $headers,string $body)use(&$signed):RegistrationResponse{
            self::assertContains('Authorization: '.base64_encode("\0DER-chain"),$headers);parse_str($body,$parameters);self::assertSame($signed,$parameters['ENC']);self::assertSame(RegistrationWire::base64url("\xffsignature"),$parameters['H']);return new RegistrationResponse(['status'=>'ok','login'=>'5511999990000']);
        });$transport->send(new RegistrationRequest('register',['code'=>'123456'],$this->info()));self::assertNotNull($signed);
    }
    public function testInvalidExternalAttestationNeverIssuesAnyHttpRequest():void {
        $calls=0;$transport=new RegistrationHttpTransport(static fn():array=>['signature'=>'','certificateChain'=>''],static function()use(&$calls):RegistrationResponse{++$calls;return new RegistrationResponse(['status'=>'sent']);});
        try{$transport->send(new RegistrationRequest('code',[],$this->info()));self::fail('Invalid attestation proof accepted');}catch(\UnexpectedValueException){}self::assertSame(0,$calls);
    }
    public function testHeaderInjectionAndUnsupportedIosFailBeforeHttp():void {
        $calls=0;$transport=new RegistrationHttpTransport(exchange:static function()use(&$calls):RegistrationResponse{++$calls;return new RegistrationResponse(['status'=>'sent']);});
        foreach([['os'=>'ios'],['manufacturer'=>"device\r\nX-Header: bad"]] as$override){try{$transport->send(new RegistrationRequest('code',[],[...$this->info(),...$override]));self::fail('Invalid registration device accepted');}catch(\InvalidArgumentException){}}self::assertSame(0,$calls);
    }
    public function testAndroidUserAgentUsesTheObservedPublicClientSanitization():void {
        $headers=[];$transport=new RegistrationHttpTransport(exchange:static function(string $url,array $received)use(&$headers):RegistrationResponse{$headers=$received;return new RegistrationResponse(['status'=>'sent']);});
        $transport->send(new RegistrationRequest('code',[],[...$this->info(),'osVersion'=>'14 / Q','manufacturer'=>'Brand / Name','device'=>'Model 5+(2026)']));
        self::assertContains('User-Agent: WhatsApp/2.26.39.79 Android/14___Q Device/Brand___Name-Model_5_(2026)',$headers);
    }
}
