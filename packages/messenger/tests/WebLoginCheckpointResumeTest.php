<?php
declare(strict_types=1);

use MVO\Messenger\Auth\WebLoginService;
use MVO\Messenger\MessengerException;
use MVO\Messenger\Transport\{WebLoginResponse,WebLoginTransport};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/TwoFactorServiceTest.php';

/** Inert ServerJS fixtures and an in-memory HTTPS boundary; no account or socket. */
final class WebLoginCheckpointResumeTest extends TestCase
{
    private const START='https://www.facebook.com/checkpoint/?flow_id=synthetic-private-flow';
    private const BUNDLE='https://static.xx.fbcdn.net/rsrc.php/v4/synthetic-resume.js';
    private const ACCOUNT='100000000001';

    public function testOriginalCheckpointIsFetchedThroughRedirectsBeforeTheRealTotpDocumentIsCaptured():void
    {
        $url=self::twoFactorUrl();$deadline=time()+43;
        $transport=new CheckpointResumeTransport([
            self::step(self::START,new WebLoginResponse(302,'<form action="/captcha/"></form>',['location'=>[$url]]),self::pendingCookies()),
            self::step($url,new WebLoginResponse(200,self::nativePage())),
        ]);
        $loaded=[];$service=self::service($transport,$loaded);
        self::failure('login_two_factor_required',static fn()=>$service->resumeCheckpoint(self::START,$deadline));
        self::assertTrue($service->challenge()['ready']);self::assertSame('two_factor',$service->challenge()['type']);
        self::assertSame($deadline,$service->challenge()['expires_at']);self::assertTrue($service->challenge()['requires_code']);
        self::assertSame([[self::BUNDLE]],$loaded);self::assertCount(2,$transport->requests);
        self::assertSame(self::pendingCookies(),$transport->requests[1]['incoming_cookies']);
        $private=$service->exportPrivateChallenge();self::assertSame($url,$private['url']);self::assertSame($deadline,$private['expires_at']);
        self::assertStringNotContainsString('synthetic-private-flow',print_r($service,true));self::assertGetsOnly($transport);
    }

    public function testKnownCodeFormOnCheckpointRouteBecomesReadyWithoutSubmittingIt():void
    {
        $html='<form method="post" action="/checkpoint/"><input type="hidden" name="nh" value="synthetic"><input name="approvals_code" maxlength="6"></form>';
        $transport=new CheckpointResumeTransport([self::step(self::START,new WebLoginResponse(200,$html))]);$loaded=[];$service=self::service($transport,$loaded);
        self::failure('login_two_factor_required',static fn()=>$service->resumeCheckpoint(self::START,time()+60));
        self::assertTrue($service->challenge()['ready']);self::assertTrue($service->challenge()['requires_code']);self::assertSame([],$loaded);
        self::assertCount(1,$transport->requests);self::assertGetsOnly($transport);
    }

    public function testUnrecognizedTwoFactorDocumentStaysPendingWithoutInventingAReadyCodeContract():void
    {
        $url=self::twoFactorUrl();$transport=new CheckpointResumeTransport([self::step($url,new WebLoginResponse(200,'<main>Unknown security component</main>'))]);$loaded=[];$service=self::service($transport,$loaded);
        self::failure('login_two_factor_required',static fn()=>$service->resumeCheckpoint($url,time()+50));
        self::assertSame('two_factor',$service->challenge()['type']);self::assertFalse($service->challenge()['ready']);self::assertFalse($service->challenge()['requires_code']);
        self::assertSame([],$loaded);self::assertCount(1,$transport->requests);self::assertGetsOnly($transport);
    }

    public function testConflictingNativeMethodDocumentsNeverChooseOneOrLoadBundles():void
    {
        $url=self::twoFactorUrl();$body=self::nativePage().str_replace('TOTP','SMS',self::nativePage());
        $transport=new CheckpointResumeTransport([self::step($url,new WebLoginResponse(200,$body))]);$loaded=[];$service=self::service($transport,$loaded);
        self::failure('login_two_factor_protocol_unavailable',static fn()=>$service->resumeCheckpoint($url,time()+50));
        self::assertNull($service->challenge());self::assertSame([],$loaded);self::assertSame(1,$transport->clearCount);self::assertGetsOnly($transport);
    }

    #[DataProvider('humanPages')]
    public function testHumanChallengeIsPreservedWithoutPostingOrTreatingItAsOtp(string $url,string $body,string $reason,string $type):void
    {
        $transport=new CheckpointResumeTransport([self::step($url,new WebLoginResponse(200,$body),self::pendingCookies())]);$loaded=[];$service=self::service($transport,$loaded);$deadline=time()+35;
        self::failure($reason,static fn()=>$service->resumeCheckpoint($url,$deadline));
        self::assertSame($type,$service->challenge()['type']);self::assertFalse($service->challenge()['ready']);self::assertFalse($service->challenge()['requires_code']);self::assertSame($deadline,$service->challenge()['expires_at']);
        self::assertSame($url,$service->exportPrivateChallenge()['url']);self::assertSame([],$loaded);self::assertCount(1,$transport->requests);
        self::failure($reason,static fn()=>$service->confirm('001234'));self::assertCount(1,$transport->requests);self::assertGetsOnly($transport);
    }
    /** @return iterable<array{string,string,string,string}> */
    public static function humanPages():iterable
    {
        yield 'checkpoint'=>[self::START,'<form action="/checkpoint/"><input name="checkpoint_data"></form>','login_challenge_required','checkpoint'];
        yield 'captcha'=>['https://www.facebook.com/captcha/','<iframe src="https://captcha.example.test/challenge"></iframe>','login_captcha_required','captcha'];
        yield 'consent'=>['https://www.facebook.com/privacy/consent/','<form action="/privacy/consent/"></form>','login_consent_required','consent'];
        yield 'native recaptcha'=>[str_replace('flow=two_factor_login','flow=pre_authentication',self::twoFactorUrl()),str_replace('TOTP','GOOGLE_RECAPTCHA',self::nativePage()),'login_captcha_required','captcha'];
    }

    #[DataProvider('unsafeStarts')]
    public function testUnsupportedInitialUrlsFailBeforeAnyRequest(string $url,string $reason):void
    {
        $transport=new CheckpointResumeTransport([]);$loaded=[];$service=self::service($transport,$loaded);
        self::failure($reason,static fn()=>$service->resumeCheckpoint($url,time()+60));self::assertSame([],$transport->requests);self::assertSame([],$loaded);
    }
    /** @return iterable<array{string,string}> */
    public static function unsafeStarts():iterable
    {
        foreach(['https://foreign.example/checkpoint/','https://accounts.google.com/checkpoint/','http://www.facebook.com/checkpoint/','https://www.facebook.com:444/checkpoint/','https://user@www.facebook.com/checkpoint/','https://www.facebook.com/checkpoint/#fragment']as$url)yield [$url,'login_unsafe_redirect'];
        yield ['https://www.facebook.com/settings/','login_challenge_unavailable'];
        yield ['https://www.facebook.com/l.php?u=https%3A%2F%2Fforeign.example%2F','login_challenge_unavailable'];
    }

    public function testInvalidOrExpiredOriginalDeadlineFailsBeforeIo():void
    {
        foreach([time()-1,0,time()+601,PHP_INT_MAX]as$deadline){
            $transport=new CheckpointResumeTransport([]);$loaded=[];$service=self::service($transport,$loaded);
            self::failure('login_challenge_expired',static fn()=>$service->resumeCheckpoint(self::START,$deadline));self::assertSame([],$transport->requests);
        }
    }

    public function testExistingPendingChallengeIsNotReplacedOrCleared():void
    {
        $url=self::twoFactorUrl();$deadline=time()+45;$transport=new CheckpointResumeTransport([self::step($url,new WebLoginResponse(200,'<main>Unrecognized 2FA</main>'),self::pendingCookies())]);$loaded=[];$service=self::service($transport,$loaded);
        self::failure('login_two_factor_required',static fn()=>$service->resumeCheckpoint($url,$deadline));$before=$service->exportPrivateChallenge();
        self::failure('login_verification_pending',static fn()=>$service->resumeCheckpoint(self::START,time()+60));
        self::assertSame($before,$service->exportPrivateChallenge());self::assertSame(0,$transport->clearCount);self::assertCount(1,$transport->requests);
    }

    public function testForeignServerRedirectIsRejectedBeforeFollowingIt():void
    {
        $transport=new CheckpointResumeTransport([self::step(self::START,new WebLoginResponse(302,'',['location'=>['https://foreign.example/collect']]))]);$loaded=[];$service=self::service($transport,$loaded);
        self::failure('login_unsafe_redirect',static fn()=>$service->resumeCheckpoint(self::START,time()+60));self::assertCount(1,$transport->requests);self::assertNull($service->challenge());self::assertGetsOnly($transport);
    }

    public function testSixthRedirectStopsAfterSixGetsWithoutCapturingRedirectPlaceholders():void
    {
        $steps=[];for($index=0;$index<6;++$index)$steps[]=self::step($index===0?self::START:'https://www.facebook.com/checkpoint/'.$index,new WebLoginResponse(302,'',['location'=>['/checkpoint/'.($index+1)]]));
        $transport=new CheckpointResumeTransport($steps);$loaded=[];$service=self::service($transport,$loaded);
        self::failure('login_redirect_limit',static fn()=>$service->resumeCheckpoint(self::START,time()+60));self::assertCount(6,$transport->requests);self::assertNull($service->challenge());self::assertGetsOnly($transport);
    }

    public function testFiveRedirectsAndAnIndependentIdentityGetReturnOnlyRealCookieScopes():void
    {
        $steps=[];for($index=0;$index<5;++$index)$steps[]=self::step($index===0?self::START:'https://www.facebook.com/checkpoint/'.$index,new WebLoginResponse(302,'',['location'=>[$index===4?'/messages/':'/checkpoint/'.($index+1)]]));
        $steps[]=self::step('https://www.facebook.com/messages/',new WebLoginResponse(200,self::identity(self::ACCOUNT)),self::cookies());
        $steps[]=self::step('https://www.facebook.com/messages/',new WebLoginResponse(200,self::identity(self::ACCOUNT)));
        $transport=new CheckpointResumeTransport($steps);$loaded=[];$service=self::service($transport,$loaded);
        $jar=$service->resumeCheckpoint(self::START,time()+60);self::assertSame(self::ACCOUNT,$jar->accountId());self::assertSame('',$jar->headerFor('https://www.messenger.com/'));
        self::assertCount(7,$transport->requests);self::assertNull($service->challenge());self::assertSame([],$transport->cookies());self::assertSame(1,$transport->clearCount);self::assertGetsOnly($transport);
    }

    public function testMatchingCookieAndPageDuringRedirectDoesNotSkipIndependentHttpsIdentityVerification():void
    {
        $transport=self::authenticatedTransport(self::identity('100000000002'));$loaded=[];$service=self::service($transport,$loaded);
        self::failure('login_identity_mismatch',static fn()=>$service->resumeCheckpoint(self::START,time()+60));self::assertCount(3,$transport->requests);self::assertSame([],$transport->cookies());self::assertGetsOnly($transport);
    }

    public function testVisibleLoginFormWithMatchingUidDoesNotBecomeAnAuthenticatedIdentity():void
    {
        $body='<form id="login_form" method="post" action="/login/"><input type="password" name="pass"></form>'.self::identity(self::ACCOUNT);
        $transport=self::authenticatedTransport($body);$loaded=[];$service=self::service($transport,$loaded);
        self::failure('auth_challenge_required',static fn()=>$service->resumeCheckpoint(self::START,time()+60));self::assertCount(3,$transport->requests);self::assertSame([],$transport->cookies());self::assertGetsOnly($transport);
    }

    public function testMissingAuthenticatedCookiesNeverMakesAnExtraIdentityRequest():void
    {
        $transport=new CheckpointResumeTransport([self::step(self::START,new WebLoginResponse(302,'',['location'=>['/messages/']])),self::step('https://www.facebook.com/messages/',new WebLoginResponse(200,self::identity(self::ACCOUNT)),self::pendingCookies())]);$loaded=[];$service=self::service($transport,$loaded);
        self::failure('login_rejected',static fn()=>$service->resumeCheckpoint(self::START,time()+60));self::assertCount(2,$transport->requests);self::assertSame([],$transport->cookies());self::assertGetsOnly($transport);
    }

    public function testExpiryDuringFetchStopsBeforeReadingSecurityOrLoadingBundles():void
    {
        $url=self::twoFactorUrl();$transport=new CheckpointResumeTransport([self::step($url,new WebLoginResponse(200,self::nativePage()))]);$loaded=[];$service=self::service($transport,$loaded);
        $transport->beforeResponse=static function():void{usleep(1100000);};
        self::failure('login_challenge_expired',static fn()=>$service->resumeCheckpoint($url,time()+1));self::assertNull($service->challenge());self::assertSame([],$loaded);self::assertCount(1,$transport->requests);
    }

    /** Reuse the canonical inert ServerJS fixture exercised by TwoFactorServiceTest. */
    private static function nativePage():string
    {
        $page=(new ReflectionMethod(TwoFactorServiceTest::class,'page'))->invoke(null);
        return $page.'<script type="application/json">'.json_encode(['compMap'=>['TwoStepVerificationRoot.react'=>['r'=>['selected']]],'rsrcMap'=>['selected'=>['type'=>'js','src'=>self::BUNDLE]]],JSON_THROW_ON_ERROR).'</script>';
    }
    private static function twoFactorUrl():string{return (new ReflectionMethod(TwoFactorServiceTest::class,'url'))->invoke(null);}
    /** @param list<list<string>> $loaded */
    private static function service(CheckpointResumeTransport $transport,array &$loaded):WebLoginService
    {
        return new WebLoginService($transport,static function(array $urls)use(&$loaded):array{$loaded[]=$urls;return [(new ReflectionMethod(TwoFactorServiceTest::class,'operation'))->invoke(null)];});
    }
    private static function identity(string $id):string{return '<script type="application/json">'.json_encode(['define'=>[['CurrentUserInitialData',[],['USER_ID'=>$id],1]]],JSON_THROW_ON_ERROR).'</script>';}
    /** @return list<array<string,mixed>> */
    private static function pendingCookies():array{return [['name'=>'datr','value'=>'synthetic-private-device','domain'=>'.facebook.com']];}
    /** @return list<array<string,mixed>> */
    private static function cookies():array{return [...self::pendingCookies(),['name'=>'c_user','value'=>self::ACCOUNT,'domain'=>'.facebook.com'],['name'=>'xs','value'=>'synthetic-private-session','domain'=>'.facebook.com']];}
    /** @param list<array<string,mixed>>|null $cookies
     * @return array{url:string,response:WebLoginResponse,cookies?:list<array<string,mixed>>}
     */
    private static function step(string $url,WebLoginResponse $response,?array $cookies=null):array{$step=['url'=>$url,'response'=>$response];if($cookies!==null)$step['cookies']=$cookies;return $step;}
    private static function authenticatedTransport(string $identity):CheckpointResumeTransport{return new CheckpointResumeTransport([self::step(self::START,new WebLoginResponse(302,'',['location'=>['/messages/']])),self::step('https://www.facebook.com/messages/',new WebLoginResponse(200,self::identity(self::ACCOUNT)),self::cookies()),self::step('https://www.facebook.com/messages/',new WebLoginResponse(200,$identity))]);}
    private static function failure(string $reason,callable $operation):void{try{$operation();self::fail('Expected a bounded continuation failure');}catch(MessengerException $error){self::assertSame($reason,$error->reason);}}
    private static function assertGetsOnly(CheckpointResumeTransport $transport):void{foreach($transport->requests as$request){self::assertSame('GET',$request['method']);self::assertSame([],$request['form']);}}
}

final class CheckpointResumeTransport implements WebLoginTransport
{
    /** @var list<array{method:string,url:string,form:array<string,string>,incoming_cookies:list<array<string,mixed>>}> */ public array $requests=[];
    /** @var list<array<string,mixed>> */ private array $jar=[];
    public int $clearCount=0;
    public ?Closure $beforeResponse=null;
    /** @param list<array{url:string,response:WebLoginResponse,cookies?:list<array<string,mixed>>}> $steps */
    public function __construct(private array $steps){}
    public function request(string $method,#[SensitiveParameter]string $url,#[SensitiveParameter]array $form=[]):WebLoginResponse
    {
        TestCase::assertSame('GET',$method,'Checkpoint adoption must never submit a form');TestCase::assertSame([],$form);
        $this->requests[]=['method'=>$method,'url'=>$url,'form'=>$form,'incoming_cookies'=>$this->jar];$step=array_shift($this->steps);
        if($step===null)throw new LogicException('Unexpected extra synthetic request');TestCase::assertSame($step['url'],$url);
        if($this->beforeResponse!==null)($this->beforeResponse)();if(isset($step['cookies']))$this->jar=$step['cookies'];return $step['response'];
    }
    public function cookies():array{return $this->jar;}
    public function clear():void{$this->clearCount++;$this->jar=[];}
}
