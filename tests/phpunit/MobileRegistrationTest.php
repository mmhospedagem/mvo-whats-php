<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Mobile\{RegistrationException,RegistrationRequest,RegistrationResponse,RegistrationService,RegistrationWire};
use PHPUnit\Framework\TestCase;

final class MobileRegistrationTest extends TestCase
{
    private string $directory;
    private float $clock=1000.0;
    private array $stores=[];
    private array $requests=[];
    protected function setUp():void {$this->directory=sys_get_temp_dir().'/mvo-registration-'.bin2hex(random_bytes(8));mkdir($this->directory,0700);}
    protected function tearDown():void {
        foreach($this->stores as$store)$store->close();$this->stores=[];gc_collect_cycles();
        $files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($files as$file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($this->directory);
    }
    private function store():CredentialStore {$store=new CredentialStore($this->directory);$this->stores[]=$store;return $store;}
    private function info():array {return ['os'=>'android','manufacturer'=>'motorola','device'=>'moto_g52','osVersion'=>'12','osBuildNumber'=>'fixture-build','appVersion'=>'2.26.39.79'];}
    private function input(string $method='sms'):array {return ['cc'=>'55','phoneNumber'=>'11999990000','method'=>$method];}
    private function service(?\Closure $transport=null,?\Closure $guard=null):RegistrationService {
        return new RegistrationService($this->store(),$this->info(),$transport??function(RegistrationRequest $request):RegistrationResponse{$this->requests[]=$request;return new RegistrationResponse($request->operation==='code'?['status'=>'sent','sms_wait'=>60]:['status'=>'ok','login'=>'5511999990000','lid'=>'123456789']);},fn():float=>$this->clock,$guard);
    }
    private function failure(\Closure $action,string $reason):RegistrationException {
        try{$action();self::fail('Expected registration failure');}catch(RegistrationException $error){self::assertSame($reason,$error->reason);return $error;}
    }
    public function testRegistrationSurvivesRestartWithExactlyTheSameKeysAndNeverPersistsTheOtp():void {
        $first=$this->service();$sent=$first->requestCode($this->input());self::assertSame('code_sent',$sent['status']);self::assertFalse($sent['registered']);self::assertSame('+5511999990000',$sent['phoneNumber']);self::assertSame(60,$sent['retryAfter']);
        $before=$this->store()->load();self::assertFalse($before['registered']);self::assertArrayNotHasKey('role',$before);$second=$this->service();$done=$second->verifyCode(['code'=>'020202']);
        self::assertSame('registered',$done['status']);self::assertSame('5511999990000@s.whatsapp.net',$done['accountJid']);$after=$this->store()->load();
        foreach(['noiseKeyPair','identityKeyPair','signedPreKey','registrationId','advSecretKey'] as$key)self::assertSame($before[$key],$after[$key]);
        self::assertSame('primary',$after['role']);self::assertSame('123456789@lid',$after['meLid']);self::assertSame($before['mobileRegistration']['deviceInfo'],$after['mobileDeviceInfo']);
        self::assertFalse(self::containsValue($after,'020202'));self::assertArrayNotHasKey('code',$after['mobileRegistration']);self::assertSame('020202',$this->requests[1]->parameters['code']);
        self::assertSame($done,$this->service()->state());self::assertCount(2,$this->requests);
    }
    public function testCodeRequestAndVerificationSharePersistedPublicKeyBundle():void {
        $service=$this->service();$service->requestCode($this->input('voice'));$service->verifyCode(['code'=>'124578']);
        self::assertSame('voice',$this->requests[0]->parameters['method']);self::assertSame('voice',$this->requests[1]->parameters['method']);
        foreach(['authkey','e_ident','e_keytype','e_regid','e_skey_id','e_skey_val','e_skey_sig','fdid'] as$key)self::assertSame($this->requests[0]->parameters[$key],$this->requests[1]->parameters[$key]);
        self::assertSame('BQ',$this->requests[0]->parameters['e_keytype']);self::assertSame('AAAB',$this->requests[0]->parameters['e_skey_id']);self::assertArrayNotHasKey('code',$this->requests[0]->parameters);
    }
    public function testUnknownDeliveryRequiresAnExplicitResendAndPreservesKeys():void {
        $count=0;$service=$this->service(function()use(&$count):RegistrationResponse{++$count;throw new \RuntimeException('Fixture connection closed after writing');});
        $error=$this->failure(fn()=>$service->requestCode($this->input()),'registration_result_unknown');self::assertSame('request_unknown',$error->registrationState['status']);$before=$this->store()->load();
        $this->failure(fn()=>$this->service()->requestCode($this->input()),'registration_result_unknown');self::assertSame(1,$count);self::assertCount(0,$this->requests);
        $sent=$this->service()->requestCode([...$this->input(),'confirmResend'=>true]);self::assertSame('code_sent',$sent['status']);$after=$this->store()->load();self::assertSame($before['identityKeyPair'],$after['identityKeyPair']);self::assertSame($before['noiseKeyPair'],$after['noiseKeyPair']);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('uncertainRequestStates')]
    public function testReceivedOtpCanConfirmAnUncertainRequestWithoutSendingAnotherCode(string $status):void {
        $operations=[];$first=$this->service(static function(RegistrationRequest $request)use(&$operations):RegistrationResponse{$operations[]=$request->operation;throw new \RuntimeException('Fixture response read timeout after writing');});
        $this->failure(fn()=>$first->requestCode($this->input()),'registration_result_unknown');$store=$this->store();$before=$store->load();$before['mobileRegistration']['status']=$status;$store->save($before);$store->close();
        $second=$this->service(static function(RegistrationRequest $request)use(&$operations):RegistrationResponse{$operations[]=$request->operation;self::assertSame('123456',$request->parameters['code']);return new RegistrationResponse(['status'=>'ok','login'=>'5511999990000']);});
        $result=$second->verifyCode(['code'=>'123456']);self::assertTrue($result['registered']);self::assertSame(['code','register'],$operations);$after=$this->store()->load();
        foreach(['noiseKeyPair','identityKeyPair','signedPreKey','registrationId','advSecretKey'] as$key)self::assertSame($before[$key],$after[$key]);
        foreach(['id','backupToken','experimentId','accessSessionId'] as$key)self::assertSame($before['mobileRegistration'][$key],$after['mobileRegistration'][$key]);self::assertFalse(self::containsValue($after,'123456'));
    }
    public static function uncertainRequestStates():array {return ['response timeout'=>['request_unknown'],'interrupted process'=>['requesting']];}
    public function testCooldownIsDurableAndCheckedBeforeAnyHttpRequest():void {
        $this->service()->requestCode($this->input());$error=$this->failure(fn()=>$this->service()->requestCode($this->input()),'registration_cooldown');self::assertSame(60,$error->retryAfter);self::assertCount(1,$this->requests);
        $this->clock+=61;$this->service()->requestCode($this->input());self::assertCount(2,$this->requests);
    }
    public function testVoiceAndSmsRespectTheirOwnServerCooldowns():void {
        $this->service(static fn():RegistrationResponse=>new RegistrationResponse(['status'=>'sent','sms_wait'=>0,'voice_wait'=>180]))->requestCode($this->input());
        $error=$this->failure(fn()=>$this->service()->requestCode($this->input('voice')),'registration_cooldown');self::assertSame(180,$error->retryAfter);self::assertSame([],$this->requests);
        $result=$this->service()->requestCode($this->input());self::assertSame('code_sent',$result['status']);self::assertCount(1,$this->requests);
    }
    public function testRegisteredCompanionCannotBeOverwritten():void {
        $credentials=CredentialStore::fresh();$credentials['registered']=true;$credentials['meJid']='5511999990000:5@s.whatsapp.net';$store=$this->store();$store->save($credentials);$store->close();
        $this->failure(fn()=>$this->service()->requestCode($this->input()),'registration_session_in_use');self::assertSame($credentials,$this->store()->load());self::assertSame([],$this->requests);
    }
    public function testRevokedPrimaryIsNeverReportedRegisteredOrSilentlyReplaced():void {
        $this->service()->requestCode($this->input());$this->service()->verifyCode(['code'=>'123456']);$store=$this->store();$revoked=$store->load();$revoked['revoked']=true;$store->save($revoked);$store->close();
        $state=$this->service()->state();self::assertSame('revoked',$state['status']);self::assertFalse($state['registered']);self::assertNull($state['accountJid']);self::assertSame('registration_session_revoked',$state['reason']);self::assertSame(0,$state['retryAfter']);
        $this->failure(fn()=>$this->service()->requestCode($this->input()),'registration_session_in_use');self::assertSame($revoked,$this->store()->load());self::assertCount(2,$this->requests);
    }
    public function testApplicationFenceFailureAfterHttpPreventsCredentialPromotion():void {
        $this->service()->requestCode($this->input());$allowed=true;$service=$this->service(function()use(&$allowed):RegistrationResponse{$allowed=false;return new RegistrationResponse(['status'=>'ok','login'=>'5511999990000']);},static function()use(&$allowed):void{if(!$allowed)throw new \DomainException('Generation changed');});
        try{$service->verifyCode(['code'=>'123456']);self::fail('Stale generation promoted credentials');}catch(\DomainException $error){self::assertSame('Generation changed',$error->getMessage());}
        $credentials=$this->store()->load();self::assertFalse($credentials['registered']);self::assertArrayNotHasKey('role',$credentials);self::assertSame('verifying',$credentials['mobileRegistration']['status']);self::assertFalse(self::containsValue($credentials,'123456'));
    }
    public function testChallengesAreTypedAndRawProofsNeverLeakIntoPublicStateOrCredentials():void {
        $service=$this->service(static fn():RegistrationResponse=>new RegistrationResponse(['status'=>'fail','reason'=>'challenge','retry_after'=>'12','challenge'=>['token'=>'private-attestation'],'wipe_token'=>'private-wipe']));
        $error=$this->failure(fn()=>$service->requestCode($this->input()),'challenge');self::assertSame(['type'=>'unsupported'],$error->registrationState['challenge']);self::assertSame(12,$error->retryAfter);
        $credentials=$this->store()->load();self::assertFalse(self::containsValue($credentials,'private-attestation'));self::assertFalse(self::containsValue($credentials,'private-wipe'));self::assertFalse($credentials['registered']);self::assertSame('challenge',$service->state()['status']);
    }
    public function testTwoFactorPinIsNeverSentAsTheSmsCodeOrPersisted():void {
        $this->service()->requestCode($this->input());$this->failure(fn()=>$this->service()->verifyCode(['code'=>'020202','twoFactorPin'=>'454545']),'registration_two_factor_not_requested');self::assertCount(1,$this->requests);$credentials=$this->store()->load();self::assertFalse(self::containsValue($credentials,'020202'));self::assertFalse(self::containsValue($credentials,'454545'));
    }
    public function testTwoStepChallengeUsesItsOwnSecurityEndpointAndNeverReplaysTheSmsCode():void {
        $this->service()->requestCode($this->input());$challenge=$this->service(static fn():RegistrationResponse=>new RegistrationResponse(['status'=>'fail','reason'=>'security_code','wipe_token'=>'private-reset-token']));
        $error=$this->failure(fn()=>$challenge->verifyCode(['code'=>'020202']),'security_code');self::assertSame(['type'=>'two_factor'],$error->registrationState['challenge']);
        $result=$this->service()->verifyCode(['twoFactorPin'=>'454545']);self::assertTrue($result['registered']);$request=end($this->requests);self::assertSame('security',$request->operation);self::assertSame('454545',$request->parameters['code']);self::assertFalse(in_array('020202',$request->parameters,true));self::assertArrayNotHasKey('method',$request->parameters);self::assertArrayNotHasKey('wipe_token',$request->parameters);self::assertArrayNotHasKey('reset',$request->parameters);
        $credentials=$this->store()->load();self::assertFalse(self::containsValue($credentials,'020202'));self::assertFalse(self::containsValue($credentials,'454545'));self::assertFalse(self::containsValue($credentials,'private-reset-token'));
    }
    public function testUnknownVerificationDoesNotTriggerAnotherSmsRequest():void {
        $this->service()->requestCode($this->input());$service=$this->service(static function():RegistrationResponse{throw new \RuntimeException('Fixture timeout');});$this->failure(fn()=>$service->verifyCode(['code'=>'123456']),'registration_result_unknown');self::assertSame('verification_unknown',$service->state()['status']);self::assertCount(1,$this->requests);self::assertFalse($this->store()->load()['registered']);
    }
    public function testIncompleteSuccessNeverMarksTheAccountRegistered():void {
        $this->service()->requestCode($this->input());$service=$this->service(static fn():RegistrationResponse=>new RegistrationResponse(['status'=>'ok']));$this->failure(fn()=>$service->verifyCode(['code'=>'123456']),'registration_response_invalid');self::assertFalse($this->store()->load()['registered']);self::assertSame('verification_unknown',$service->state()['status']);
    }
    public function testSuccessForAnotherPhoneCannotPromoteOrReplaceThePendingIdentity():void {
        $this->service()->requestCode($this->input());$before=$this->store()->load();$service=$this->service(static fn():RegistrationResponse=>new RegistrationResponse(['status'=>'ok','login'=>'5511999999999']));
        $this->failure(fn()=>$service->verifyCode(['code'=>'123456']),'registration_response_invalid');$after=$this->store()->load();self::assertFalse($after['registered']);self::assertNull($after['meJid']);self::assertSame($before['identityKeyPair'],$after['identityKeyPair']);
    }
    public function testIntegerLoginMatchesTheAndroidJsonStringConversionWithoutAcceptingFloats():void {
        $this->service()->requestCode($this->input());$service=$this->service(static fn():RegistrationResponse=>new RegistrationResponse(['status'=>'ok','login'=>5511999990000]));
        $result=$service->verifyCode(['code'=>'123456']);self::assertTrue($result['registered']);self::assertSame('5511999990000@s.whatsapp.net',$result['accountJid']);
    }
    public function testFloatingPointLoginIsNeverAcceptedAsAnAuthenticatedAccount():void {
        $this->service()->requestCode($this->input());$service=$this->service(static fn():RegistrationResponse=>new RegistrationResponse(['status'=>'ok','login'=>5511999990000.0]));
        $this->failure(fn()=>$service->verifyCode(['code'=>'123456']),'registration_response_invalid');self::assertFalse($this->store()->load()['registered']);
    }
    public function testAnotherNumberCannotReplaceAnInFlightRegistration():void {
        $this->service()->requestCode($this->input());$this->clock+=61;$this->failure(fn()=>$this->service()->requestCode([...$this->input(),'phoneNumber'=>'11999990001']),'registration_number_conflict');self::assertCount(1,$this->requests);
    }
    public function testRegistrationDoesNotShareTheConnectionCredentialLock():void {
        $store=$this->store();$store->acquire();$service=new RegistrationService($store,$this->info(),static fn():RegistrationResponse=>new RegistrationResponse(['status'=>'sent']));try{$service->requestCode($this->input());self::fail('Registration used an active client lock');}catch(\LogicException){}self::assertTrue($store->ownsLock());self::assertNull($store->load());
        $other=$this->service();try{$other->requestCode($this->input());self::fail('Registration ignored another process lock');}catch(\RuntimeException $error){self::assertStringContainsString('already in use',$error->getMessage());}self::assertNull($store->load());
    }
    public function testInvalidLocalInputsNeverCreateAnAttemptOrCallHttp():void {
        foreach([['code'=>'ABCDEFGH'],['code'=>'12345'],['code'=>'123456','twoFactorPin'=>'x']] as$input){try{$this->service()->verifyCode($input);self::fail('Invalid registration code accepted');}catch(\InvalidArgumentException){}}
        try{$this->service()->requestCode([...$this->input(),'phoneNumber'=>'+441234567890']);self::fail('Mismatched country accepted');}catch(\InvalidArgumentException){}self::assertSame([],$this->requests);self::assertNull($this->store()->load());
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBrazilianRegistrationNumbers')]
    public function testInvalidBrazilianMobileNumberFailsBeforeStateLockOrHttp(string $number):void {
        $guardCalls=0;$service=$this->service(guard:static function()use(&$guardCalls):void{++$guardCalls;});
        try{$service->requestCode([...$this->input(),'phoneNumber'=>$number]);self::fail('Unsupported Brazilian number reached registration');}catch(\InvalidArgumentException $error){self::assertStringContainsString('DDD + celular com 9 dígitos',$error->getMessage());self::assertStringContainsString('não é inserido automaticamente',$error->getMessage());}
        self::assertSame(0,$guardCalls);self::assertSame([],$this->requests);self::assertFileDoesNotExist($this->directory.'/default/state.sqlite');self::assertFileDoesNotExist($this->directory.'/default/connection.lock');
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBrazilianRegistrationNumbers')]
    public function testInvalidBrazilianMobileNumberPreservesAnExistingAttemptWithoutHttp(string $number):void {
        $this->service()->requestCode($this->input());$before=$this->store()->load();
        try{$this->service()->requestCode([...$this->input(),'phoneNumber'=>$number]);self::fail('Unsupported number replaced a registration attempt');}catch(\InvalidArgumentException){}
        self::assertSame($before,$this->store()->load());self::assertCount(1,$this->requests);
    }
    public static function invalidBrazilianRegistrationNumbers():array {
        return ['old national mobile'=>['6288880000'],'old E.164 mobile'=>['+556288880000'],'national fixed line'=>['6233334444'],'E.164 fixed line'=>['+556233334444'],'eleven digits without mobile prefix'=>['11888887777']];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('nanpRegistrationNumbers')]
    public function testNanpNationalAndE164NumbersRemainValidWithoutAddingBrazilianDigits(string $number):void {
        $service=$this->service(function(RegistrationRequest $request):RegistrationResponse{$this->requests[]=$request;return new RegistrationResponse($request->operation==='code'?['status'=>'sent']:['status'=>'ok','login'=>'12025550123']);});
        $sent=$service->requestCode(['cc'=>'1','phoneNumber'=>$number,'method'=>'sms']);self::assertSame('+12025550123',$sent['phoneNumber']);self::assertSame('1',$this->requests[0]->parameters['cc']);self::assertSame('2025550123',$this->requests[0]->parameters['in']);
        $result=$service->verifyCode(['code'=>'123456']);self::assertTrue($result['registered']);self::assertSame('12025550123@s.whatsapp.net',$result['accountJid']);self::assertCount(2,$this->requests);
    }
    public static function nanpRegistrationNumbers():array {return ['national'=>['2025550123'],'E.164'=>['+12025550123']];}
    public function testRegistrationFormEncodesBinaryOnceAndDoesNotUsePlusForSpaces():void {
        self::assertSame('id=%00%FF%25%2B%26%3D&backup_token=a%20b%2F&code=123456',RegistrationWire::form(['id'=>"\0\xff%+&=",'backup_token'=>'a b/','code'=>'123456']));self::assertSame('_-4',RegistrationWire::base64url("\xff\xee"));
    }
    private static function containsValue(array $data,string $expected):bool {foreach($data as$value)if($value===$expected || (is_array($value) && self::containsValue($value,$expected)))return true;return false;}
}
