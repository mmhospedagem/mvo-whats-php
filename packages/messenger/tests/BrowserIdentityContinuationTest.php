<?php
declare(strict_types=1);
namespace MVO\Messenger\Tests;

use MVO\Messenger\Auth\{CookieJar,SessionStore,WebLoginService};
use MVO\Messenger\{MessengerClient,MessengerException};
use MVO\Messenger\Transport\{HttpClient,HttpResponse,WebLoginResponse,WebLoginTransport};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** No live browser, account or network: identity and challenge boundaries are independent fixtures. */
final class BrowserIdentityContinuationTest extends TestCase
{
    private string $root;
    private ?MessengerClient $client=null;

    protected function setUp():void
    {
        $this->root=sys_get_temp_dir().'/mvo-browser-identity-'.bin2hex(random_bytes(8));
        mkdir($this->root,0700);
    }

    protected function tearDown():void
    {
        $this->client?->stop();$this->client=null;gc_collect_cycles();
        $files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as$file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}
        rmdir($this->root);
    }

    #[DataProvider('humanDocuments')]
    public function testDirectHumanChallengePreservesCookiesLockAndPrivateHandoff(string $html,string $type,string $reason):void
    {
        $transport=new HumanChallengeTransport($html);$service=new WebLoginService($transport);
        $http=new IdentityContinuationHttp([new HttpResponse(200,self::identity().$html),new HttpResponse(200,self::identity())]);
        $client=$this->client=new MessengerClient('test',$this->root,$http);
        try{$client->login('person@example.invalid','SYNTHETIC_PASSWORD',$service);self::fail('A human challenge cannot be skipped');}
        catch(MessengerException $error){self::assertSame($reason,$error->reason);}
        self::assertSame($type==='captcha'?'awaiting_captcha':'awaiting_login',$client->getState());
        self::assertSame($type,$client->loginChallenge()['type']);
        self::assertFalse($client->loginChallenge()['ready']);self::assertSame([],$client->loginChallenge()['methods']);
        self::assertFalse($client->loginChallenge()['requires_code']);
        self::assertSame(['GET'],$transport->methods);
        self::assertSame('https://www.messenger.com/',$client->exportPrivateLoginChallenge()['url']);
        self::assertSame($transport->cookies(),$client->exportPrivateLoginChallenge()['cookies']);
        $this->assertUnconfirmedOwner();
        try{$client->confirmLogin('123456');self::fail('Human verification must not send an OTP');}
        catch(MessengerException $error){self::assertSame($reason,$error->reason);}
        self::assertSame(['GET'],$transport->methods);self::assertNotNull($client->loginChallenge());
        $projection=json_encode([$client->accountInfo(),$client->events()],JSON_THROW_ON_ERROR).print_r($service,true);
        self::assertStringNotContainsString('SYNTHETIC_DEVICE',$projection);self::assertStringNotContainsString('SYNTHETIC_PASSWORD',$projection);
        $handoff=$client->exportPrivateLoginChallenge();
        try{$client->completeBrowserLogin(self::cookies());self::fail('An interstitial must preserve the original challenge');}
        catch(MessengerException $error){self::assertSame('auth_challenge_required',$error->reason);}
        self::assertSame($handoff,$client->exportPrivateLoginChallenge());$this->assertUnconfirmedOwner();
        $client->completeBrowserLogin(self::cookies());
        self::assertSame('session_imported',$client->getState());self::assertNull($client->loginChallenge());self::assertSame([],$transport->cookies());
        $store=new SessionStore($this->root,'test');$store->acquire();self::assertSame('123',$store->get('account_id'));$store->release();
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function humanDocuments():iterable
    {
        yield 'checkpoint form'=>['<form action="/checkpoint/123/"><input name="checkpoint_data"></form>','checkpoint','login_challenge_required'];
        yield 'captcha in checkpoint'=>['<form action="/checkpoint/123/"><iframe src="https://www.facebook.com/captcha/iframe/"></iframe></form>','captcha','login_captcha_required'];
        yield 'captcha input'=>["<input name='captcha_response' type='text'>",'captcha','login_captcha_required'];
        yield 'consent form'=>['<form action="/privacy/consent/"><input name="choice"></form>','consent','login_consent_required'];
    }

    #[DataProvider('humanDocuments')]
    public function testDirectHumanChallengeExpiresWithoutNetworkAndReleasesOwnership(string $html,string $type,string $reason):void
    {
        $transport=new HumanChallengeTransport($html);$service=new WebLoginService($transport);
        $client=$this->client=new MessengerClient('test',$this->root);
        try{$client->login('person@example.invalid','SYNTHETIC_PASSWORD',$service);self::fail('Expected human challenge');}
        catch(MessengerException $error){self::assertSame($reason,$error->reason);}
        self::assertSame($type,$client->loginChallenge()['type']);
        (new \ReflectionProperty($service,'challengeExpiresAt'))->setValue($service,time()-1);
        $client->poll(0);self::assertSame('login_challenge_expired',$client->getState());self::assertNull($client->loginChallenge());
        self::assertSame([],$transport->cookies());self::assertSame(['GET'],$transport->methods);
        $store=new SessionStore($this->root,'test');$store->acquire();self::assertNull($store->get('cookies'));self::assertNull($store->get('account_id'));$store->release();
    }

    #[DataProvider('identityInterstitals')]
    public function testMatchingIdInHttp200InterstitialIsNotSavedAndSameAttemptCanContinue(string $document):void
    {
        $http=new IdentityContinuationHttp([new HttpResponse(200,self::identity().$document),new HttpResponse(200,self::identity())]);
        $client=$this->client=new MessengerClient('test',$this->root,$http);$handoff=$client->beginBrowserLogin();
        try{$client->completeBrowserLogin(self::cookies());self::fail('An ID inside a pending interstitial is insufficient');}
        catch(MessengerException $error){self::assertSame('auth_challenge_required',$error->reason);}
        self::assertSame('awaiting_login',$client->getState());self::assertFalse($client->isConnected());
        $this->assertUnconfirmedOwner();
        self::assertGreaterThan(time(),$handoff['expires_at']);
        $client->completeBrowserLogin(self::cookies());self::assertSame('session_imported',$client->getState());
        self::assertCount(2,$http->urls);
        $store=new SessionStore($this->root,'test');$store->acquire();self::assertSame('123',$store->get('account_id'));$store->release();
    }

    /** @return iterable<string,array{string}> */
    public static function identityInterstitals():iterable
    {
        yield 'checkpoint'=>['<form action="/checkpoint/123/"><input name="checkpoint_data"></form>'];
        yield 'captcha'=>["<iframe src='https://www.facebook.com/captcha/iframe/'></iframe>"];
        yield 'two factor'=>['<form action="/two_step_verification/two_factor/"><input name="approvals_code"></form>'];
        yield 'consent'=>['<form action="/consent/"><input name="choice"></form>'];
        yield 'privacy consent'=>['<form action=/privacy/consent/><input name=choice></form>'];
        yield 'login form'=>['<form id="login_form" action="/login/"><input name="email"></form>'];
        yield 'password control'=>['<input TYPE="password" name="pass">'];
        yield 'encoded form action'=>['<form action="&#47;checkpoint&#47;123&#47;"><input name="confirmation"></form>'];
    }

    #[DataProvider('pendingRedirects')]
    public function testBrowserIdentityRedirectStaysPendingWithoutFollowingOrReplayingLogin(string $location):void
    {
        $http=new IdentityContinuationHttp([new HttpResponse(302,'',['location'=>[$location]]),new HttpResponse(200,self::identity())]);
        $client=$this->client=new MessengerClient('test',$this->root,$http);$client->beginBrowserLogin();
        try{$client->completeBrowserLogin(self::cookies());self::fail('The browser identity check is still pending');}
        catch(MessengerException $error){self::assertSame('auth_challenge_required',$error->reason);}
        self::assertSame(['https://www.facebook.com/messages/'],$http->urls);self::assertSame('awaiting_login',$client->getState());
        $this->assertUnconfirmedOwner();
        $client->completeBrowserLogin(self::cookies());self::assertSame('session_imported',$client->getState());
        self::assertSame(['https://www.facebook.com/messages/','https://www.facebook.com/messages/'],$http->urls);
    }

    /** @return iterable<string,array{string}> */
    public static function pendingRedirects():iterable
    {
        yield 'relative login'=>['/login.php?next=%2Fmessages%2F'];
        yield 'absolute login'=>['https://www.facebook.com/login/'];
        yield 'other supported auth origin'=>['https://www.messenger.com/login/'];
        yield 'checkpoint'=>['/checkpoint/123/'];
        yield 'challenge'=>['/challenge/123/'];
        yield 'captcha'=>['/captcha/'];
        yield 'two factor'=>['/two_factor/'];
        yield 'two step'=>['/two_step_verification/two_factor/'];
        yield 'consent'=>['/consent/'];
        yield 'privacy consent'=>['/privacy/consent/'];
    }

    public function testOrdinarySavedSessionLoginRedirectStillMeansSessionExpired():void
    {
        $http=new IdentityContinuationHttp([new HttpResponse(302,'',['location'=>['/login/']])]);
        $client=$this->client=new MessengerClient('test',$this->root,$http);$client->importCookies(self::cookies());
        try{$client->connect();self::fail('The restored session is expired');}
        catch(MessengerException $error){self::assertSame('session_expired',$error->reason);}
        self::assertSame('session_expired',$client->getState());
        $store=new SessionStore($this->root,'test');$store->acquire();self::assertSame('123',$store->get('account_id'));$store->release();
    }

    public function testForeignLoginRedirectNeverBecomesAnAllowedPendingChallenge():void
    {
        $http=new IdentityContinuationHttp([new HttpResponse(302,'',['location'=>['https://www.facebook.com.evil.invalid/login/']])]);
        $client=$this->client=new MessengerClient('test',$this->root,$http);$client->beginBrowserLogin();
        try{$client->completeBrowserLogin(self::cookies());self::fail('Foreign redirects must be rejected');}
        catch(MessengerException $error){self::assertSame('unexpected_auth_redirect',$error->reason);}
        self::assertCount(1,$http->urls);$this->assertUnconfirmedOwner();
    }

    public function testInertMarkupAndLoginInMessagesQueryDoNotPreventConfirmedIdentity():void
    {
        $inert='<script>const example="<form action=\'/checkpoint/\'><input type=\'password\'></form>";</script>'
            .'<!-- <form action="/consent/"></form> -->'
            .'<template><input name="captcha_response"></template>'
            .'<a href="/login/">Login settings link</a>';
        $http=new IdentityContinuationHttp([new HttpResponse(302,'',['location'=>['/messages/?next=/login/']]),new HttpResponse(200,self::identity().$inert)]);
        $client=$this->client=new MessengerClient('test',$this->root,$http);$client->beginBrowserLogin();$client->completeBrowserLogin(self::cookies());
        self::assertSame('session_imported',$client->getState());
        self::assertSame(['https://www.facebook.com/messages/','https://www.facebook.com/messages/?next=/login/'],$http->urls);
    }

    private function assertUnconfirmedOwner():void
    {
        $store=new SessionStore($this->root,'test');self::assertNull($store->get('cookies'));self::assertNull($store->get('account_id'));
        try{$store->acquire();self::fail('Pending browser identity verification must retain its owner');}
        catch(\RuntimeException $error){self::assertStringContainsString('active owner',$error->getMessage());}
    }

    private static function cookies():CookieJar{return CookieJar::fromJson('{"c_user":"123","xs":"SYNTHETIC_BROWSER_XS"}');}
    private static function identity():string{return '<script type="application/json">'.json_encode(['define'=>[['CurrentUserInitialData',[],['USER_ID'=>'123'],1]]],JSON_THROW_ON_ERROR).'</script>';}
}

final class IdentityContinuationHttp implements HttpClient
{
    /** @var list<string> */ public array $urls=[];
    /** @param list<HttpResponse> $responses */ public function __construct(private array $responses){}
    public function request(string $method,string $url,CookieJar $cookies,array $form=[]):HttpResponse
    {
        TestCase::assertSame('GET',$method);TestCase::assertSame([],$form);TestCase::assertSame('123',$cookies->accountId());
        $this->urls[]=$url;return array_shift($this->responses)??throw new \LogicException('Unexpected identity request');
    }
}

final class HumanChallengeTransport implements WebLoginTransport
{
    /** @var list<string> */ public array $methods=[];
    /** @var list<array<string,mixed>> */ private array $cookies=[];
    public function __construct(private readonly string $document){}
    public function request(string $method,string $url,array $form=[]):WebLoginResponse
    {
        $this->methods[]=$method;
        if(count($this->methods)!==1)throw new \LogicException('Human verification must not repeat login or send a code');
        $this->cookies=[['name'=>'datr','value'=>'SYNTHETIC_DEVICE','domain'=>'.facebook.com']];
        return new WebLoginResponse(200,$this->document);
    }
    public function cookies():array{return $this->cookies;}
    public function clear():void{$this->cookies=[];}
}
