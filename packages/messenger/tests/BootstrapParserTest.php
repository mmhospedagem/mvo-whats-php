<?php
declare(strict_types=1);
namespace MVO\Messenger\Tests;

use MVO\Messenger\MessengerException;
use MVO\Messenger\Protocol\BootstrapParser;
use PHPUnit\Framework\TestCase;

final class BootstrapParserTest extends TestCase
{
    private function html(array $overrides=[],array $extra=[]): string
    {
        $modules=[
            'CurrentUserInitialData'=>['USER_ID'=>'12345678901234567','NAME'=>'Synthetic account'],
            'DGWWebConfig'=>['appId'=>'456789','appVersion'=>'0','fbId'=>'12345678901234567'],
            'MqttWebDeviceID'=>['clientID'=>'synthetic-device-42'],
            'DTSGInitialData'=>['token'=>'TOKEN-PRIVATE'], 'LSD'=>['token'=>'LSD-PRIVATE'],
            'SprinkleConfig'=>['version'=>'2','should_randomize'=>false],
            'MessengerWebInitData'=>['cryptoAuthToken'=>'CAT-PRIVATE'],
            'LSPlatformMessengerSyncParams'=>['mailbox'=>'{"locale":"pt_BR"}','contact'=>'{}'],
            'SiteData'=>['client_revision'=>42,'haste_session'=>'test_session','__spin_r'=>42,'__spin_b'=>'trunk','__spin_t'=>1],
        ];
        $modules=array_replace($modules,$overrides);
        $definitions=[];
        foreach($modules as $name=>$value) if($value!==null) $definitions[]=[$name,[],$value,0];
        $json=['require'=>[['ScheduledServerJS','handle',null,[['__bbox'=>['define'=>$definitions,...$extra]]]]]];
        return '<html><script type="application/json" data-sjs>'.json_encode($json,JSON_THROW_ON_ERROR).'</script></html>';
    }
    public function testNestedScheduledJsonProducesVerifiedIdentityAndNoInventedVersion():void
    {
        $config=(new BootstrapParser())->parse($this->html(),'12345678901234567');
        self::assertSame('12345678901234567',$config->userId);self::assertSame('456789',$config->appId);
        self::assertSame('synthetic-device-42',$config->deviceId);self::assertSame('0',$config->appVersion);
        self::assertNull($config->versionId);self::assertSame('{"locale":"pt_BR"}',$config->syncParams()['mailbox']);
        self::assertFalse($config->jsonSerialize()['encryptedMessagingReady']);
    }
    public function testTokensAreAvailableOnlyForProtocolAndAbsentFromDebugAndJson():void
    {
        $config=(new BootstrapParser())->parse($this->html(),'12345678901234567');
        self::assertSame('TOKEN-PRIVATE',$config->requestFields()['fb_dtsg']);
        self::assertSame('LSD-PRIVATE',$config->requestFields()['lsd']);
        self::assertSame('2'.array_sum(array_map(ord(...),str_split('TOKEN-PRIVATE'))),$config->requestFields()['jazoest']);
        $public=json_encode($config,JSON_THROW_ON_ERROR).print_r($config,true);
        foreach(['TOKEN-PRIVATE','LSD-PRIVATE','CAT-PRIVATE'] as $secret) self::assertStringNotContainsString($secret,$public);
        self::assertTrue($config->jsonSerialize()['hasCryptoAuthToken']);
    }
    public function testVersionComesFromPreloaderRequestAndPayloadPreservesBigIds():void
    {
        $request=json_encode(['version'=>'98765432101234567']);
        $payload=json_encode(['step'=>[1,[5,'LSUpsertMessage','text',[19,'9223372036854775807']]]]);
        $extra=['require'=>[['CometPlatformRootClient','initialize',null,[['expectedPreloaders'=>[['variables'=>['requestPayload'=>$request]]]]]],
            ['RelayPrefetchedStreamCache','next',null,[['__bbox'=>['result'=>['data'=>['viewer'=>['lightspeed_web_request'=>['payload'=>$payload,'dependencies'=>[['name'=>'m','value'=>['__dr'=>'LSUpsertMessage']]]]]]]]]]]]];
        $config=(new BootstrapParser())->parse($this->html([],$extra),'12345678901234567');
        self::assertSame('98765432101234567',$config->versionId);
        self::assertCount(1,$config->initialLightspeedPayloads());
        self::assertSame('9223372036854775807',$config->initialLightspeedPayloads()[0]['payload']['step'][1][3][1]);
    }
    public function testCatObjectIsRecognizedWithoutExposingItsValueOrClaimingE2eeReadiness():void
    {
        $config=(new BootstrapParser())->parse($this->html(['MessengerWebInitData'=>[
            'cryptoAuthToken'=>['encryptedSerializedCat'=>'OBJECT-CAT-PRIVATE','expirationTimeInSeconds'=>1792360000],
        ]]),'12345678901234567');
        self::assertTrue($config->jsonSerialize()['hasCryptoAuthToken']);
        self::assertFalse($config->jsonSerialize()['encryptedMessagingReady']);
        $diagnostics=json_encode($config,JSON_THROW_ON_ERROR).print_r($config,true);
        self::assertStringNotContainsString('OBJECT-CAT-PRIVATE',$diagnostics);
        self::assertStringNotContainsString('expirationTimeInSeconds',$diagnostics);
        foreach([[],['encryptedSerializedCat'=>''],['encryptedSerializedCat'=>false],['expirationTimeInSeconds'=>1792360000]] as $cat){
            $empty=(new BootstrapParser())->parse($this->html(['MessengerWebInitData'=>['cryptoAuthToken'=>$cat]]),'12345678901234567');
            self::assertFalse($empty->jsonSerialize()['hasCryptoAuthToken']);
        }
    }
    public function testSeparateLiteralBundleVersionAndNoExecution():void
    {
        $script='__d("LSVersion",[],function(a,b,c,d,e,f){e.exports="987654321"},null);throw new Error("never execute")';
        $config=(new BootstrapParser())->parse($this->html(),'12345678901234567','https://www.facebook.com',[$script]);
        self::assertSame('987654321',$config->versionId);
        self::assertNull(BootstrapParser::extractVersion('__d("LSVersion",[],function(a,b,c,d,e){e.exports=getDynamicVersion()})'));
    }
    public function testInitialSyncRequestUsesOnlyCapturedExpectedPreloaderRequest():void
    {
        $extra=['expectedPreloaders'=>[
            ['variables'=>['requestPayload'=>json_encode(['database'=>1,'epoch_id'=>'0','version'=>'123','last_applied_cursor'=>null])]],
            ['variables'=>['requestPayload'=>json_encode(['database'=>2,'epoch_id'=>'9223372036854775807','version'=>'123','last_applied_cursor'=>'private-cursor-2'])]],
            ['variables'=>['requestPayload'=>json_encode(['database'=>3,'epoch_id'=>'9223372036854775808'])]],
            ['variables'=>['requestPayload'=>json_encode(['database'=>4,'epoch_id'=>1.5])]],
        ],'other'=>['requestPayload'=>json_encode(['database'=>9,'epoch_id'=>'999'])]];
        $config=(new BootstrapParser())->parse($this->html([],$extra),'12345678901234567');
        self::assertSame([1=>['epochId'=>'0','failureCount'=>0,'cursor'=>null],2=>['epochId'=>'9223372036854775807','failureCount'=>0,'cursor'=>'private-cursor-2']],$config->initialSyncRequests());
        $diagnostics=json_encode($config,JSON_THROW_ON_ERROR).print_r($config,true);
        self::assertStringNotContainsString('epochId',$diagnostics);self::assertStringNotContainsString('private-cursor-2',$diagnostics);
        self::assertSame([], (new BootstrapParser())->parse($this->html(),'12345678901234567')->initialSyncRequests());
    }
    public function testConflictingInitialRequestEpochIsNotInferredOrSilentlyChosen():void
    {
        $extra=['expectedPreloaders'=>[
            ['variables'=>['requestPayload'=>json_encode(['database'=>1,'epoch_id'=>'0'])]],
            ['variables'=>['requestPayload'=>json_encode(['database'=>1,'epoch_id'=>'1'])]],
        ]];
        $this->expectException(MessengerException::class);
        $this->expectExceptionMessage('initial sync requests');
        (new BootstrapParser())->parse($this->html([],$extra),'12345678901234567');
    }
    public function testAnonymousLoginPageCannotBeMisreportedAsConnected():void
    {
        $this->expectException(MessengerException::class);$this->expectExceptionMessage('unauthenticated');
        (new BootstrapParser())->parse($this->html(['CurrentUserInitialData'=>['USER_ID'=>'0']]),'12345678901234567');
    }
    public function testImportedAccountMustMatchBootstrapAndGateway():void
    {
        foreach([
            ['CurrentUserInitialData'=>['USER_ID'=>'222']],
            ['DGWWebConfig'=>['appId'=>'456789','appVersion'=>'0','fbId'=>'222']],
        ] as $overrides) {
            try{(new BootstrapParser())->parse($this->html($overrides),'12345678901234567');self::fail('Expected identity mismatch');}
            catch(MessengerException $e){self::assertSame('account_mismatch',$e->reason);}
        }
    }
    public function testNoFallbackDeviceOrAppIdIsFabricated():void
    {
        foreach(['DGWWebConfig','MqttWebDeviceID'] as $module) {
            try{(new BootstrapParser())->parse($this->html([$module=>null]),'12345678901234567');self::fail('Expected incomplete bootstrap');}
            catch(MessengerException $e){self::assertSame('bootstrap_incomplete',$e->reason);}
        }
    }
    public function testConflictingIdentityAndVersionAreRejected():void
    {
        $extra='<script type="application/json">'.json_encode(['define'=>[['CurrentUserInitialData',[],['USER_ID'=>'222'],0]]]).'</script>';
        try{(new BootstrapParser())->parse($this->html().$extra,'12345678901234567');self::fail('Expected conflicting identity');}
        catch(MessengerException $e){self::assertSame('bootstrap_conflict',$e->reason);}
        $this->expectException(MessengerException::class);
        (new BootstrapParser())->parse($this->html(['LSVersion'=>'111']),'12345678901234567','https://www.facebook.com',['__d("LSVersion",[],function(a,b,c,d,e){e.exports="222"})']);
    }
    public function testOnlyPublicCdnScriptsAreCandidatesForCookieFreeFetch():void
    {
        $html='<script src="https://static.xx.fbcdn.net/rsrc.php/v4/y/r/script.js?a=1&amp;b=2"></script>'.
            '<script src="https://static.xx.fbcdn.net.evil.invalid/rsrc.php/x"></script>'.
            '<script src="https://user:pass@static.xx.fbcdn.net/rsrc.php/x"></script>'.
            '<script src="http://static.xx.fbcdn.net/rsrc.php/x"></script>'.
            '<script src="https://static.xx.fbcdn.net:444/rsrc.php/x"></script>'.
            '<script src="https://static.xx.fbcdn.net/untrusted.js"></script>';
        self::assertSame(['https://static.xx.fbcdn.net/rsrc.php/v4/y/r/script.js?a=1&b=2'],BootstrapParser::scriptUrls($html));
    }
    public function testExecutableScriptWithFakeDataIsNotEvaluated():void
    {
        $this->expectException(MessengerException::class);
        (new BootstrapParser())->parse('<script>globalThis.connected=true;({define:[["CurrentUserInitialData",[],{"USER_ID":"123"}]]})</script>','123');
    }
}
