<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Protocol\BinaryNode;
use MVO\Service\{BusinessFormAuthenticationService,ServiceException,ServiceTransport};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BusinessFormAuthenticationServiceTest extends TestCase
{
    private BusinessFormAuthenticationService $service;
    private float $clock=100.0;
    private bool $connected=true;
    private string $owner='551199990001:3@s.whatsapp.net';
    /** @var list<array{node:BinaryNode,timeout:float}> */
    private array $queries=[];
    private int $polls=0;
    private bool $nonceBeforeAck=false;
    private bool $nonceOnPoll=true;
    private ?\Closure $queryHook=null;
    private ?\Closure $pollHook=null;
    private const NONCE='test-native-nonce-0001';
    private const TOKEN='opaque-auth-token-fixture';

    protected function setUp():void
    {
        $transport=new ServiceTransport(function(BinaryNode $node,float $timeout):BinaryNode{
            $this->queries[]=['node'=>$node,'timeout'=>$timeout];
            if($this->queryHook!==null)return ($this->queryHook)($node,$timeout);
            if(($node->attrs['smax_id']??null)==='118'){
                if($this->nonceBeforeAck){
                    self::assertTrue($this->service->ingestServerNotification(self::notification()));
                    self::assertCount(1,$this->queries,'Notification handler cannot send the token exchange from inside the callback');
                }
                return self::started();
            }
            return self::authenticated();
        },static function():void{self::fail('Authentication must not send fire-and-forget nodes');},fn():array=>['meJid'=>$this->owner]);
        $this->service=new BusinessFormAuthenticationService($transport,function(float $wait):void{
            ++$this->polls;self::assertGreaterThan(0,$wait);self::assertLessThanOrEqual(0.25,$wait);$this->clock+=$wait;
            if($this->pollHook!==null){($this->pollHook)();return;}
            if($this->nonceOnPoll)$this->service->ingestServerNotification(self::notification());
        },fn():bool=>$this->connected,fn():float=>$this->clock);
    }

    private static function started(string $status='Success'):BinaryNode
    {
        return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('result',[],[new BinaryNode('status',[],$status)])]);
    }
    private static function authenticated(?string $cookies='[{"name":"c_user","value":"10001"},{"name":"xs","value":"cookie-fixture"}]'):BinaryNode
    {
        $children=[new BinaryNode('business_person',['id'=>'10001']),new BinaryNode('access_token',[],self::TOKEN)];
        if($cookies!==null)$children[]=new BinaryNode('session_cookies',[],$cookies);
        return new BinaryNode('iq',['type'=>'result'],$children);
    }
    private static function notification(string $nonce=self::NONCE,?string $from='s.whatsapp.net',string $type='business'):BinaryNode
    {
        $attrs=['type'=>$type];if($from!==null)$attrs['from']=$from;
        return new BinaryNode('notification',$attrs,[new BinaryNode('wa_ad_account_nonce',[],$nonce)]);
    }
    private function assertSafeStatus():void
    {
        $json=json_encode($this->service->status(),JSON_THROW_ON_ERROR);
        foreach([self::NONCE,self::TOKEN,'cookie-fixture',$this->owner,'session_cookies','access_token']as$secret)self::assertStringNotContainsString($secret,$json);
        ob_start();var_dump($this->service);$debug=ob_get_clean();
        self::assertIsString($debug);self::assertStringNotContainsString(self::TOKEN,$debug);self::assertStringNotContainsString(self::NONCE,$debug);
    }

    public function testExactNativeIqsAndNonceBeforeFirstResponse():void
    {
        $this->nonceBeforeAck=true;
        self::assertSame(self::TOKEN,$this->service->token());self::assertSame(0,$this->polls);self::assertCount(2,$this->queries);
        [$start,$exchange]=array_column($this->queries,'node');
        foreach([$start,$exchange]as$node){
            self::assertSame('iq',$node->tag);self::assertSame('fb:thrift_iq',$node->attrs['xmlns']);
            self::assertSame('s.whatsapp.net',$node->attrs['to']);self::assertSame('551199990001@s.whatsapp.net',$node->attrs['from']);
            self::assertSame('get',$node->attrs['type'],'DGC.A0Z completes both native requests as IQ get');
            self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/D',$node->attrs['id']);
        }
        self::assertNotSame($start->attrs['id'],$exchange->attrs['id']);
        self::assertSame('118',$start->attrs['smax_id']);self::assertNull($start->content);
        self::assertSame('104',$exchange->attrs['smax_id']);self::assertCount(1,$exchange->content);
        self::assertSame('parameters',$exchange->content[0]->tag);self::assertCount(1,$exchange->content[0]->content);
        self::assertSame('code',$exchange->content[0]->content[0]->tag);self::assertSame(self::NONCE,$exchange->child('parameters')->child('code')->content);
        self::assertSame(['state'=>'authenticated','authenticated'=>true,'pending'=>false,'recoveryRequired'=>false,'serverCode'=>null,'retryAfterSeconds'=>null,'supportVerified'=>false],$this->service->status());
        $this->assertSafeStatus();
    }

    public function testNonceAfterAckUsesPollAndEachIqHasItsOwnNativeBudget():void
    {
        self::assertSame(self::TOKEN,$this->service->token());self::assertSame(1,$this->polls);
        self::assertEqualsWithDelta(32.0,$this->queries[0]['timeout'],0.00001);
        self::assertEqualsWithDelta(32.0,$this->queries[1]['timeout'],0.00001);
        self::assertSame(self::TOKEN,$this->service->token());self::assertCount(2,$this->queries,'Cached token requires no new authentication');
    }

    public function testThreeNativePhasesDoNotShareOneThirtyTwoSecondDeadline():void
    {
        $this->queryHook=function(BinaryNode $node,float $timeout):BinaryNode{
            self::assertEqualsWithDelta(32.0,$timeout,0.00001);$this->clock+=31.0;
            return $node->attrs['smax_id']==='118'?self::started():self::authenticated();
        };
        $this->pollHook=function():void{
            $this->clock+=31.0;self::assertTrue($this->service->ingestServerNotification(self::notification()));
        };
        self::assertSame(self::TOKEN,$this->service->token());self::assertCount(2,$this->queries);
        self::assertEqualsWithDelta(193.25,$this->clock,0.00001);
    }

    public static function invalidNotifications():iterable
    {
        yield 'message body'=>[new BinaryNode('message',['from'=>'s.whatsapp.net','type'=>'business'],[new BinaryNode('wa_ad_account_nonce',[],self::NONCE)])];
        yield 'peer sender'=>[self::notification(self::NONCE,'551199990002@s.whatsapp.net')];
        yield 'peer category'=>[new BinaryNode('notification',['from'=>'s.whatsapp.net','type'=>'business','category'=>'peer'],[new BinaryNode('wa_ad_account_nonce',[],self::NONCE)])];
        yield 'sender suffix'=>[self::notification(self::NONCE,'s.whatsapp.net.evil.example')];
        yield 'sender device'=>[self::notification(self::NONCE,'s.whatsapp.net:1')];
        yield 'wrong type'=>[self::notification(self::NONCE,'s.whatsapp.net','w:biz')];
        yield 'missing type'=>[new BinaryNode('notification',['from'=>'s.whatsapp.net'],[new BinaryNode('wa_ad_account_nonce',[],self::NONCE)])];
        yield 'attribute nonce'=>[new BinaryNode('notification',['from'=>'s.whatsapp.net','type'=>'business'],[new BinaryNode('wa_ad_account_nonce',['code'=>self::NONCE])])];
        yield 'nested nonce'=>[new BinaryNode('notification',['from'=>'s.whatsapp.net','type'=>'business'],[new BinaryNode('result',[],[new BinaryNode('wa_ad_account_nonce',[],self::NONCE)])])];
        yield 'duplicate children'=>[new BinaryNode('notification',['from'=>'s.whatsapp.net','type'=>'business'],[new BinaryNode('wa_ad_account_nonce',[],self::NONCE),new BinaryNode('wa_ad_account_nonce',[],'other-nonce')])];
        yield 'other auth protocol'=>[new BinaryNode('notification',['from'=>'s.whatsapp.net','type'=>'business'],[new BinaryNode('native_auth_nonce',[],self::NONCE)])];
        yield 'empty'=>[self::notification('')];yield 'short'=>[self::notification('123')];yield 'oversized'=>[self::notification(str_repeat('n',65))];
        yield 'binary'=>[self::notification("123\0nonce")];
    }
    #[DataProvider('invalidNotifications')]
    public function testUntrustedOrMalformedNonceCannotStartExchange(BinaryNode $notification):void
    {
        self::assertFalse($this->service->ingestServerNotification($notification));
        $this->pollHook=function()use($notification):void{
            self::assertFalse($this->service->ingestServerNotification($notification));
            self::assertCount(1,$this->queries);
            self::assertTrue($this->service->ingestServerNotification(self::notification()));
        };
        self::assertSame(self::TOKEN,$this->service->token());self::assertCount(2,$this->queries);
    }

    public function testMissingSenderOnAuthenticatedBusinessTransportAndUnrelatedIdAreAccepted():void
    {
        $this->pollHook=function():void{
            $node=self::notification(self::NONCE,null);
            self::assertTrue($this->service->ingestServerNotification(new BinaryNode($node->tag,[...$node->attrs,'id'=>'unrelated-notification-id'],$node->content)));
        };
        self::assertSame(self::TOKEN,$this->service->token());
    }

    public function testOnlyFirstNonceIsUsedAndObservedReplayIsRejectedAfterInvalidation():void
    {
        $this->pollHook=function():void{
            self::assertTrue($this->service->ingestServerNotification(self::notification()));
            self::assertFalse($this->service->ingestServerNotification(self::notification('replacement-nonce')));
        };
        self::assertSame(self::TOKEN,$this->service->token());self::assertFalse($this->service->ingestServerNotification(self::notification()));
        $this->service->invalidate();self::assertFalse($this->service->ingestServerNotification(self::notification('unsolicited-nonce')));
        $this->pollHook=function():void{
            self::assertFalse($this->service->ingestServerNotification(self::notification()));
            self::assertTrue($this->service->ingestServerNotification(self::notification('new-nonce-0002')));
        };
        self::assertSame(self::TOKEN,$this->service->token());self::assertCount(4,$this->queries);
        self::assertSame('new-nonce-0002',$this->queries[3]['node']->child('parameters')->child('code')->content);
    }

    public function testTimeoutStopsWithoutTokenExchangeAndRejectsLateNonce():void
    {
        $this->nonceOnPoll=false;
        try{$this->service->token();self::fail('Missing nonce accepted');}catch(ServiceException $error){self::assertStringContainsString('timed out',$error->reason);}
        self::assertCount(1,$this->queries);self::assertSame('timed_out',$this->service->status()['state']);
        self::assertFalse($this->service->status()['pending']);self::assertFalse($this->service->ingestServerNotification(self::notification()));
        self::assertEqualsWithDelta(132.0,$this->clock,0.00001);$this->assertSafeStatus();
    }

    public function testNonceReceivedAtDeadlineCannotExtendTheTimeout():void
    {
        $this->pollHook=function():void{$this->clock=132.0;self::assertFalse($this->service->ingestServerNotification(self::notification()));};
        try{$this->service->token();self::fail('Expired nonce accepted');}catch(ServiceException){}
        self::assertCount(1,$this->queries);self::assertSame('timed_out',$this->service->status()['state']);
    }

    public function testConcurrentReentryDoesNotInvalidatePendingOperation():void
    {
        $this->pollHook=function():void{
            try{$this->service->token();self::fail('Concurrent authentication started');}catch(\LogicException $error){self::assertStringContainsString('already pending',$error->getMessage());}
            self::assertTrue($this->service->status()['pending']);self::assertCount(1,$this->queries);
            self::assertTrue($this->service->ingestServerNotification(self::notification()));
        };
        self::assertSame(self::TOKEN,$this->service->token());self::assertCount(2,$this->queries);
    }

    public function testInvalidateFromPollCannotCacheTheOldAttempt():void
    {
        $this->pollHook=function():void{$this->service->invalidate();self::assertFalse($this->service->ingestServerNotification(self::notification()));};
        try{$this->service->token();self::fail('Invalidated attempt completed');}catch(ServiceException){}
        self::assertCount(1,$this->queries);self::assertFalse($this->service->status()['authenticated']);self::assertFalse($this->service->status()['pending']);
    }

    public function testDisconnectDuringTokenResponsePreventsCachingAndFutureQueries():void
    {
        $this->queryHook=function(BinaryNode $node):BinaryNode{
            if($node->attrs['smax_id']==='118'){$this->service->ingestServerNotification(self::notification());return self::started();}
            $this->connected=false;return self::authenticated();
        };
        try{$this->service->token();self::fail('Disconnected token accepted');}catch(ServiceException){}
        self::assertFalse($this->service->status()['authenticated']);self::assertSame('disconnected',$this->service->status()['state']);
        try{$this->service->token();self::fail('Disconnected authentication started');}catch(ServiceException){}
        self::assertCount(2,$this->queries);$this->assertSafeStatus();
    }

    public function testTokenIsBoundToOwnPhoneAndCannotBeReusedAfterIdentityChange():void
    {
        self::assertSame(self::TOKEN,$this->service->token());$this->owner='551199990002:4@s.whatsapp.net';
        self::assertFalse($this->service->status()['authenticated']);self::assertSame('identity_changed',$this->service->status()['state']);
        $this->pollHook=function():void{$this->service->ingestServerNotification(self::notification('other-owner-nonce'));};
        self::assertSame(self::TOKEN,$this->service->token());self::assertCount(4,$this->queries);
        self::assertSame('551199990002@s.whatsapp.net',$this->queries[2]['node']->attrs['from']);
    }

    public function testLidCannotSupplyTheRequiredPhoneIdentity():void
    {
        $this->owner='998877665544@lid';
        try{$this->service->token();self::fail('LID was used as a phone identity');}catch(ServiceException $error){self::assertStringContainsString('phone identity',$error->reason);}
        self::assertCount(0,$this->queries);
    }

    public function testRecoveryRequiredIsExplicitAndNoAutomaticRetryOrExchangeOccurs():void
    {
        $this->queryHook=static fn():BinaryNode=>new BinaryNode('iq',['type'=>'result'],[new BinaryNode('result',[],[new BinaryNode('status',[],'RecoveryRequired'),new BinaryNode('email',[],'private@example.invalid')])]);
        for($i=0;$i<2;++$i){try{$this->service->token();self::fail('Recovery ignored');}catch(ServiceException $error){self::assertStringContainsString('official application',$error->reason);self::assertStringNotContainsString('private@',$error->getMessage());}}
        self::assertCount(1,$this->queries);self::assertTrue($this->service->status()['recoveryRequired']);self::assertSame('recovery_required',$this->service->status()['state']);
        self::assertStringNotContainsString('private@',json_encode($this->service->status(),JSON_THROW_ON_ERROR));$this->assertSafeStatus();
    }

    public static function lockoutCodes():iterable{yield['429'];yield['431'];}
    #[DataProvider('lockoutCodes')]
    public function testNativeLockoutCooldownSurvivesInvalidationAndEscalatesWithoutRetries(string $code):void
    {
        $this->queryHook=static fn():never=>throw new ServiceException('fb:thrift_iq',$code,'Private raw rejection');
        for($i=0;$i<2;++$i){try{$this->service->token();self::fail('Lockout ignored');}catch(ServiceException $error){self::assertSame($code,$error->serverCode);}}
        self::assertCount(1,$this->queries);self::assertSame(600,$this->service->status()['retryAfterSeconds']);
        $this->service->invalidate();
        try{$this->service->token();self::fail('Reconnect bypassed cooldown');}catch(ServiceException){}
        self::assertCount(1,$this->queries);$this->clock+=601;
        try{$this->service->token();self::fail('Second rejection ignored');}catch(ServiceException){}
        self::assertCount(2,$this->queries);self::assertSame(86400,$this->service->status()['retryAfterSeconds']);
        $this->clock+=86401;$this->queryHook=null;
        self::assertSame(self::TOKEN,$this->service->token());self::assertCount(4,$this->queries);self::assertNull($this->service->status()['retryAfterSeconds']);
    }

    public function testServerRejectionCannotLeakOpaqueDetailsAndIsNotAutomaticallyRetried():void
    {
        $this->queryHook=static fn():never=>throw new ServiceException('fb:thrift_iq','403',self::TOKEN.' '.self::NONCE);
        try{$this->service->token();self::fail('Rejection ignored');}catch(ServiceException $error){self::assertSame('403',$error->serverCode);self::assertStringNotContainsString(self::TOKEN,$error->getMessage());self::assertStringNotContainsString(self::NONCE,$error->getMessage());}
        self::assertCount(1,$this->queries);self::assertSame('failed',$this->service->status()['state']);self::assertSame('403',$this->service->status()['serverCode']);$this->assertSafeStatus();
    }

    public static function invalidCookies():iterable
    {
        yield 'missing'=>[null];yield 'malformed'=>['not-json'];yield 'object'=>['{}'];yield 'array of scalars'=>['["cookie"]'];yield 'null'=>['null'];yield 'nested array'=>['[[]]'];
    }
    #[DataProvider('invalidCookies')]
    public function testInvalidSessionCookieEnvelopeNeverCachesTheToken(?string $cookies):void
    {
        $this->queryHook=function(BinaryNode $node)use($cookies):BinaryNode{
            if($node->attrs['smax_id']==='118'){$this->service->ingestServerNotification(self::notification());return self::started();}
            return self::authenticated($cookies);
        };
        try{$this->service->token();self::fail('Invalid cookies accepted');}catch(ServiceException){}
        self::assertFalse($this->service->status()['authenticated']);self::assertCount(2,$this->queries);$this->assertSafeStatus();
    }

    public function testEmptyCookieArrayIsValidButIsNeverPersisted():void
    {
        $this->queryHook=function(BinaryNode $node):BinaryNode{
            if($node->attrs['smax_id']==='118'){$this->service->ingestServerNotification(self::notification());return self::started();}
            return self::authenticated('[]');
        };
        self::assertSame(self::TOKEN,$this->service->token());$this->assertSafeStatus();
    }

    public static function invalidTokens():iterable
    {
        yield 'missing business person'=>[new BinaryNode('iq',['type'=>'result'],[new BinaryNode('access_token',[],self::TOKEN),new BinaryNode('session_cookies',[],'[]')])];
        yield 'invalid business id'=>[new BinaryNode('iq',['type'=>'result'],[new BinaryNode('business_person',['id'=>'not-an-id']),new BinaryNode('access_token',[],self::TOKEN),new BinaryNode('session_cookies',[],'[]')])];
        yield 'missing token'=>[new BinaryNode('iq',['type'=>'result'],[new BinaryNode('business_person',['id'=>'10001']),new BinaryNode('session_cookies',[],'[]')])];
        yield 'empty token'=>[new BinaryNode('iq',['type'=>'result'],[new BinaryNode('business_person',['id'=>'10001']),new BinaryNode('access_token',[],''),new BinaryNode('session_cookies',[],'[]')])];
        yield 'token exceeds HTTP envelope bound'=>[new BinaryNode('iq',['type'=>'result'],[new BinaryNode('business_person',['id'=>'10001']),new BinaryNode('access_token',[],str_repeat('t',65537)),new BinaryNode('session_cookies',[],'[]')])];
        yield 'duplicate token'=>[new BinaryNode('iq',['type'=>'result'],[new BinaryNode('business_person',['id'=>'10001']),new BinaryNode('access_token',[],self::TOKEN),new BinaryNode('access_token',[],'second'),new BinaryNode('session_cookies',[],'[]')])];
    }
    #[DataProvider('invalidTokens')]
    public function testIncompleteTokenResponseNeverClaimsAuthentication(BinaryNode $response):void
    {
        $this->queryHook=function(BinaryNode $node)use($response):BinaryNode{
            if($node->attrs['smax_id']==='118'){$this->service->ingestServerNotification(self::notification());return self::started();}
            return $response;
        };
        try{$this->service->token();self::fail('Incomplete token accepted');}catch(ServiceException){}
        self::assertFalse($this->service->status()['authenticated']);$this->assertSafeStatus();
    }

    public function testUnknownStartResponseNeverExecutesTokenExchangeEvenWithEarlyNonce():void
    {
        $this->queryHook=function():BinaryNode{$this->service->ingestServerNotification(self::notification());return self::started('Unknown');};
        try{$this->service->token();self::fail('Unknown start status accepted');}catch(ServiceException){}
        self::assertCount(1,$this->queries);self::assertFalse($this->service->status()['authenticated']);
    }

    public function testTimeoutConfigurationCannotExceedNativeBudget():void
    {
        $transport=new ServiceTransport(static fn():never=>throw new \LogicException(),static function():void{},static fn():array=>[]);
        foreach([0.0,-1.0,32.01,INF,NAN]as$timeout){
            try{new BusinessFormAuthenticationService($transport,static function():void{},static fn():bool=>true,null,$timeout);self::fail('Invalid timeout accepted');}catch(\InvalidArgumentException $error){self::assertStringContainsString('32 seconds',$error->getMessage());}
        }
    }
}
