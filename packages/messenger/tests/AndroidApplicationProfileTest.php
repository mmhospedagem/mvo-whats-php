<?php
declare(strict_types=1);

namespace MVO\Messenger\Tests;

use MVO\Messenger\Auth\AndroidApplicationProfile;
use PHPUnit\Framework\TestCase;

final class AndroidApplicationProfileTest extends TestCase
{
    public function testKeyFetchUsesOnlyApplicationAccessAndCommonParameters():void
    {
        $profile=new AndroidApplicationProfile('123','SYNTHETIC_CLIENT_VALUE','pt_BR','BR');
        $result=$profile->authorize('POST','/pwd_key_fetch',['method'=>'GET','version'=>'2','device_id'=>'test-device','flow'=>'CONTROLLER_INITIALIZATION','fb_api_req_friendly_name'=>'pwdKeyFetch']);
        self::assertSame('GET',$result['form']['method']);
        self::assertSame('123|SYNTHETIC_CLIENT_VALUE',$result['form']['access_token']);
        self::assertSame('pt_BR',$result['form']['locale']);self::assertSame('BR',$result['form']['client_country_code']);
        self::assertArrayNotHasKey('api_key',$result['form']);self::assertArrayNotHasKey('sig',$result['form']);
        self::assertSame([],$result['headers']);
    }

    public function testSignatureUsesCaseInsensitiveNamesAndOriginalUtf8ValuesBeforeFormEncoding():void
    {
        $profile=new AndroidApplicationProfile('123','TEST_SIGNING_VALUE');
        $form=['zLast'=>'&=é 😀','ALPHA'=>'a+b c','password'=>'#PWD_MSGR:2:123:synthetic','fb_api_req_friendly_name'=>'authenticate'];
        $signed=$profile->authorize('POST','/auth/login',$form)['form'];
        // Independent fixed ordering, not a copy of the implementation's sorter.
        $snapshot='ALPHA=a+b capi_key=123fb_api_caller_class='.AndroidApplicationProfile::class
            .'fb_api_req_friendly_name=authenticatelocale=pt_BRpassword=#PWD_MSGR:2:123:syntheticzLast=&=é 😀TEST_SIGNING_VALUE';
        self::assertSame(md5($snapshot),$signed['sig']);
        self::assertSame('123|TEST_SIGNING_VALUE',$signed['access_token']);
        foreach($form as $name=>$value)self::assertSame($value,$signed[$name]);
        $other=$profile->authorize('POST','/auth/login',array_replace($form,['ALPHA'=>'different']))['form'];
        self::assertNotSame($signed['sig'],$other['sig']);
    }

    public function testNativeApprovalGetRetainsPrivateParametersAndCommonApplicationAuthorizationWithoutLoginSignature():void
    {
        foreach(['https://graph.facebook.com','https://b-graph.facebook.com']as$origin){
            $profile=new AndroidApplicationProfile('123','SYNTHETIC_CLIENT_VALUE','pt_BR','BR',$origin);
            $result=$profile->authorize('GET','/check_approved_machine',['u'=>'123456789','m'=>'SYNTHETIC_MACHINE + &=']);
            self::assertSame($origin,$profile->origin());
            self::assertSame('123456789',$result['form']['u']);self::assertSame('SYNTHETIC_MACHINE + &=',$result['form']['m']);
            self::assertSame('123|SYNTHETIC_CLIENT_VALUE',$result['form']['access_token']);
            self::assertSame('pt_BR',$result['form']['locale']);self::assertSame('BR',$result['form']['client_country_code']);
            self::assertSame(AndroidApplicationProfile::class,$result['form']['fb_api_caller_class']);
            self::assertArrayNotHasKey('sig',$result['form']);self::assertArrayNotHasKey('api_key',$result['form']);
            self::assertArrayNotHasKey('method',$result['form']);self::assertSame([],$result['headers']);
        }
    }

    public function testNativeSmsPostUsesApplicationAuthorizationWithoutTheAuthLoginSignature():void
    {
        foreach(['https://graph.facebook.com','https://b-graph.facebook.com']as$origin){
            $profile=new AndroidApplicationProfile('123','SYNTHETIC_CLIENT_VALUE','pt_BR','BR',$origin);
            foreach(['1','1234567890123456789012345']as$uid){
                $result=$profile->authorize('POST','/'.$uid.'/twofacsms',['first_factor'=>'SYNTHETIC_FIRST_FACTOR + &=']);
                self::assertSame('SYNTHETIC_FIRST_FACTOR + &=',$result['form']['first_factor']);
                self::assertSame('123|SYNTHETIC_CLIENT_VALUE',$result['form']['access_token']);
                self::assertSame('pt_BR',$result['form']['locale']);self::assertSame('BR',$result['form']['client_country_code']);
                self::assertSame(AndroidApplicationProfile::class,$result['form']['fb_api_caller_class']);
                self::assertArrayNotHasKey('sig',$result['form']);self::assertArrayNotHasKey('api_key',$result['form']);
                self::assertArrayNotHasKey('method',$result['form']);self::assertSame([],$result['headers']);
            }
        }
    }

    /** @param array<mixed> $form */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRequests')]
    public function testRejectsAmbiguousOrUnsupportedRequests(string $method,string $path,array $form):void
    {
        $profile=new AndroidApplicationProfile('123','TEST_SIGNING_VALUE');
        $this->expectException(\InvalidArgumentException::class);
        $profile->authorize($method,$path,$form);
    }
    /** @return iterable<array{string,string,array<mixed>}> */
    public static function invalidRequests():iterable
    {
        yield ['GET','/pwd_key_fetch',[]];yield ['POST','/other',[]];yield ['POST','/pwd_key_fetch',['method'=>'POST']];
        yield ['POST','/auth/login',['method'=>'GET']];yield ['POST','/auth/login',['access_token'=>'consumer-key']];
        yield ['POST','/auth/login',['SIG'=>'forged']];yield ['POST','/auth/login',['locale'=>'xx_XX']];
        yield ['POST','/auth/login',['A'=>'one','a'=>'two']];yield ['POST','/auth/login',['não'=>'bad-name']];
        yield ['POST','/auth/login',['value'=>"\xff"]];
        yield ['POST','/check_approved_machine',['u'=>'123','m'=>'machine']];
        yield ['get','/check_approved_machine',['u'=>'123','m'=>'machine']];
        yield ['GET','/check_approved_machine?m=PRIVATE_MACHINE',['u'=>'123']];
        yield ['GET','/check_approved_machine/',['u'=>'123','m'=>'machine']];
        yield ['GET','/check_approved_machine',['method'=>'GET','u'=>'123','m'=>'machine']];
        yield ['GET','/check_approved_machine',['METHOD'=>'POST','u'=>'123','m'=>'machine']];
        yield ['GET','/check_approved_machine',['access_token'=>'consumer-key','u'=>'123','m'=>'machine']];
        yield ['GET','/check_approved_machine',['sig'=>'forged','u'=>'123','m'=>'machine']];
        yield ['GET','/123/twofacsms',['first_factor'=>'SYNTHETIC_FIRST_FACTOR']];
        yield ['PUT','/123/twofacsms',[]];yield ['post','/123/twofacsms',[]];
        yield ['POST','/123/twofacsms',['method'=>'GET']];yield ['POST','/123/twofacsms',['METHOD'=>'GET']];
        yield ['POST','/123/twofacsms',['sig'=>'forged']];yield ['POST','/123/twofacsms',['access_token'=>'consumer-key']];
        yield ['POST','/0/twofacsms',[]];yield ['POST','/0123/twofacsms',[]];
        yield ['POST','/12345678901234567890123456/twofacsms',[]];yield ['POST','/-123/twofacsms',[]];
        yield ['POST','/+123/twofacsms',[]];yield ['POST','/1e3/twofacsms',[]];yield ['POST','/１２３/twofacsms',[]];
        yield ['POST','/%31%32%33/twofacsms',[]];yield ['POST','//123/twofacsms',[]];
        yield ['POST','/123/../123/twofacsms',[]];yield ['POST','/123/twofacsms/',[]];
        yield ['POST','/123/twofacsms?first_factor=secret',[]];yield ['POST','/123/twofacsms#secret',[]];
        yield ['POST','https://graph.facebook.com/123/twofacsms',[]];yield ['POST',"/123/twofacsms\n",[]];
        yield ['GET','/me',[]];yield ['POST','/me',[]];
        yield ['GET','/me',['access_token'=>'SYNTHETIC_USER_TOKEN']];
    }

    public function testDebugAndSerializationDoNotExposeApplicationConfiguration():void
    {
        $profile=new AndroidApplicationProfile('123','TEST_SIGNING_VALUE');
        self::assertStringNotContainsString('TEST_SIGNING_VALUE',print_r($profile,true));
        self::assertSame('https://graph.facebook.com',$profile->origin());self::assertSame(2,$profile->passwordVersion());
        self::assertSame('CONTROLLER_INITIALIZATION',$profile->keyFetchFlow());
        $this->expectException(\LogicException::class);serialize($profile);
    }
}
