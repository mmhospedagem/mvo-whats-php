<?php
declare(strict_types=1);
namespace MVO\Messenger\Tests;

use MVO\Messenger\Auth\{CookieJar,SessionStore,WebLoginService};
use MVO\Messenger\MessengerClient;
use MVO\Messenger\MessengerException;
use MVO\Messenger\Transport\{HttpClient,HttpResponse,WebLoginResponse,WebLoginTransport};
use PHPUnit\Framework\TestCase;

final class LoginChallengeLifecycleTest extends TestCase
{
    private string $root;
    private ?MessengerClient $client=null;
    protected function setUp():void{$this->root=sys_get_temp_dir().'/mvo-messenger-challenge-'.bin2hex(random_bytes(8));mkdir($this->root,0700);}
    protected function tearDown():void
    {
        $this->client?->stop();$this->client=null;gc_collect_cycles();
        $files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($this->root);
    }
    /** @return array{MessengerClient,WebLoginService,ChallengeLifecycleTransport} */
    private function pending(?HttpClient $http=null):array
    {
        $transport=new ChallengeLifecycleTransport();$service=new WebLoginService($transport);
        $client=$this->client=new MessengerClient('test',$this->root,$http);
        try{$client->login('person@example.invalid','SYNTHETIC_PRIVATE_PASSWORD',$service);self::fail('Expected two-factor challenge');}
        catch(MessengerException $error){self::assertSame('login_two_factor_required',$error->reason);}
        return [$client,$service,$transport];
    }
    public function testPendingChallengeKeepsTheSessionOwnerAndOnlySafeMetadataIsProjected():void
    {
        [$client,$service,$transport]=$this->pending();
        self::assertSame('awaiting_two_factor',$client->getState());self::assertFalse($client->isConnected());
        self::assertSame('two_factor',$client->loginChallenge()['type']);self::assertFalse($client->accountInfo()['has_saved_session']);
        self::assertCount(3,$transport->requests);self::assertNotEmpty($transport->cookies());
        $public=json_encode([$client->accountInfo(),$client->events()],JSON_THROW_ON_ERROR).print_r($service,true);
        foreach(['SYNTHETIC_PRIVATE_PASSWORD','SYNTHETIC_PRIVATE_CONTEXT','SYNTHETIC_PRIVATE_DEVICE'] as $secret)self::assertStringNotContainsString($secret,$public);
        $other=new SessionStore($this->root,'test');
        try{$other->acquire();self::fail('Pending login must retain exclusive ownership');}
        catch(\RuntimeException $error){self::assertStringContainsString('active owner',$error->getMessage());}
        unset($other);
    }
    public function testStopCancelsThePendingContextAndReleasesTheSession():void
    {
        [$client,$service,$transport]=$this->pending();$client->stop();
        self::assertNull($service->challenge());self::assertNull($client->loginChallenge());self::assertSame([],$transport->cookies());
        $other=new SessionStore($this->root,'test');$other->acquire();self::assertTrue($other->ownsLock());$other->release();unset($other);
    }
    public function testExpiredChallengeIsDiscardedByPollWithoutNetworkOrPersistingPartialCookies():void
    {
        [$client,$service,$transport]=$this->pending();$requests=count($transport->requests);
        (new \ReflectionProperty($service,'challengeExpiresAt'))->setValue($service,time()-1);
        $client->poll(0);self::assertSame('login_challenge_expired',$client->getState());self::assertNull($client->loginChallenge());
        self::assertSame($requests,count($transport->requests));self::assertSame([],$transport->cookies());
        $other=new SessionStore($this->root,'test');$other->acquire();self::assertNull($other->get('cookies'));self::assertNull($other->get('account_id'));$other->release();unset($other);
    }
    public function testOtherConnectionCommandsCannotReplaceAnUnfinishedVerification():void
    {
        [$client,,$transport]=$this->pending();$requests=count($transport->requests);
        foreach(['connect','import'] as $operation){
            try{
                if($operation==='connect')$client->connect();else $client->importCookies(CookieJar::fromJson('{"c_user":"123","xs":"SYNTHETIC_OTHER"}'));
                self::fail('Expected pending verification guard');
            }catch(MessengerException $error){self::assertSame('login_verification_pending',$error->reason);}
        }
        self::assertSame($requests,count($transport->requests));self::assertSame('awaiting_two_factor',$client->getState());
    }
    public function testMalformedCodeDoesNotSendAnAuthenticationRequestOrLoseTheChallenge():void
    {
        [$client,,$transport]=$this->pending();$requests=count($transport->requests);
        try{$client->confirmLogin('12A456');self::fail('Expected local invalid-code validation');}
        catch(MessengerException $error){self::assertSame('login_code_invalid',$error->reason);}
        self::assertSame($requests,count($transport->requests));self::assertNotNull($client->loginChallenge());
    }
    public function testPrivateBrowserHandoffIsExplicitAndPreservesOnlyTheCurrentChallenge():void
    {
        [$client,$service,$transport]=$this->pending();$requests=count($transport->requests);
        $handoff=$client->exportPrivateLoginChallenge();
        self::assertSame('https://www.facebook.com/two_step_verification/two_factor/?context=SYNTHETIC_PRIVATE_CONTEXT',$handoff['url']);
        self::assertSame($transport->cookies(),$handoff['cookies']);
        self::assertSame($client->loginChallenge()['expires_at'],$handoff['expires_at']);
        self::assertSame($requests,count($transport->requests));
        $public=json_encode([$client->accountInfo(),$client->events()],JSON_THROW_ON_ERROR).print_r($client,true).print_r($service,true);
        foreach(['SYNTHETIC_PRIVATE_CONTEXT','SYNTHETIC_PRIVATE_DEVICE','https://www.facebook.com/two_step_verification'] as $secret)self::assertStringNotContainsString($secret,$public);
        $client->stop();
        try{$client->exportPrivateLoginChallenge();self::fail('Cancelled browser challenges cannot be exported');}
        catch(MessengerException $error){self::assertSame('login_challenge_expired',$error->reason);}
    }
    public function testBrowserSessionIsSavedOnlyAfterIndependentMatchingHttpsIdentity():void
    {
        $http=new BrowserCompletionHttp('123');[$client,$service,$transport]=$this->pending($http);
        $client->completeBrowserLogin(CookieJar::fromJson('{"c_user":"123","xs":"SYNTHETIC_BROWSER_XS"}'));
        self::assertSame(['https://www.facebook.com/messages/'],$http->urls);
        self::assertSame('session_imported',$client->getState());self::assertFalse($client->isConnected());
        self::assertNull($client->loginChallenge());self::assertNull($service->challenge());self::assertSame([],$transport->cookies());
        $other=new SessionStore($this->root,'test');$other->acquire();
        self::assertSame('123',$other->get('account_id'));self::assertSame('123',$other->loadCookies()->accountId());
        self::assertStringContainsString('SYNTHETIC_BROWSER_XS',$other->loadCookies()->headerFor('https://www.facebook.com/'));
        $other->release();unset($other);
        $public=json_encode([$client->accountInfo(),$client->events()],JSON_THROW_ON_ERROR).print_r($client,true);
        self::assertStringNotContainsString('SYNTHETIC_BROWSER_XS',$public);
    }
    public function testMissingOrMismatchedBrowserIdentityKeepsChallengeAndNeverSavesCookies():void
    {
        $http=new BrowserCompletionHttp(null);[$client,,$transport]=$this->pending($http);
        foreach([null=>'login_unconfirmed','456'=>'login_identity_mismatch'] as $id=>$reason){
            $http->identity=$id===''?null:(string)$id;
            try{$client->completeBrowserLogin(CookieJar::fromJson('{"c_user":"123","xs":"SYNTHETIC_BROWSER_XS"}'));self::fail('Unverified identity cannot become durable');}
            catch(MessengerException $error){self::assertSame($reason,$error->reason);}
            self::assertNotNull($client->loginChallenge());self::assertNotEmpty($transport->cookies());
            $other=new SessionStore($this->root,'test');self::assertNull($other->get('cookies'));self::assertNull($other->get('account_id'));
            try{$other->acquire();self::fail('The pending login must keep its exclusive owner');}
            catch(\RuntimeException $error){self::assertStringContainsString('active owner',$error->getMessage());}
            unset($other);
        }
    }
    public function testBrowserCompletionCannotSwitchAnExistingSessionsAccount():void
    {
        $seed=new SessionStore($this->root,'test');$seed->acquire();$seed->saveCookies(CookieJar::fromJson('{"c_user":"456","xs":"SYNTHETIC_OLD_XS"}'));$seed->release();unset($seed);
        [$client,,$transport]=$this->pending(new BrowserCompletionHttp('123'));
        try{$client->completeBrowserLogin(CookieJar::fromJson('{"c_user":"123","xs":"SYNTHETIC_BROWSER_XS"}'));self::fail('Browser completion cannot switch a session account');}
        catch(\InvalidArgumentException $error){self::assertStringContainsString('new Messenger session ID',$error->getMessage());}
        $other=new SessionStore($this->root,'test');self::assertSame('456',$other->get('account_id'));
        self::assertStringContainsString('SYNTHETIC_OLD_XS',$other->get('cookies'));self::assertStringNotContainsString('SYNTHETIC_BROWSER_XS',$other->get('cookies'));unset($other);
        self::assertNotNull($client->loginChallenge());self::assertNotEmpty($transport->cookies());
    }
    public function testExpiredBrowserCompletionPerformsNoNetworkAndReleasesPendingOwnership():void
    {
        $http=new BrowserCompletionHttp('123');[$client,$service,$transport]=$this->pending($http);
        (new \ReflectionProperty($service,'challengeExpiresAt'))->setValue($service,time()-1);
        try{$client->completeBrowserLogin(CookieJar::fromJson('{"c_user":"123","xs":"SYNTHETIC_BROWSER_XS"}'));self::fail('Expired handoff cannot complete');}
        catch(MessengerException $error){self::assertSame('login_challenge_expired',$error->reason);}
        self::assertSame([],$http->urls);self::assertSame([],$transport->cookies());
        $other=new SessionStore($this->root,'test');$other->acquire();self::assertNull($other->get('cookies'));$other->release();unset($other);
    }
}

final class BrowserCompletionHttp implements HttpClient
{
    /** @var list<string> */ public array $urls=[];
    public function __construct(public ?string $identity){}
    public function request(string $method,string $url,CookieJar $cookies,array $form=[]):HttpResponse
    {
        TestCase::assertSame('GET',$method);TestCase::assertSame([],$form);TestCase::assertSame('123',$cookies->accountId());
        $this->urls[]=$url;
        return new HttpResponse(200,'<script type="application/json">'.json_encode(['define'=>[['CurrentUserInitialData',[],['USER_ID'=>$this->identity??'0'],1]]],JSON_THROW_ON_ERROR).'</script>');
    }
}

final class ChallengeLifecycleTransport implements WebLoginTransport
{
    /** @var list<array{method:string,url:string}> */ public array $requests=[];
    /** @var list<array<string,mixed>> */ private array $rows=[];
    public function request(string $method,string $url,array $form=[]):WebLoginResponse
    {
        $this->requests[]=['method'=>$method,'url'=>$url];
        if(count($this->requests)===1){
            $key=bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_seed_keypair(str_repeat("\x08",32))));
            $data=['require'=>[['MWLoginEncryptPasswordOnSubmit','init',[],[['keyId'=>1,'publicKey'=>$key]]]]];
            return new WebLoginResponse(200,'<form id="login_form" method="post" action="/login/password/"><input name="email"><input name="pass" type="password"></form><script>'.json_encode($data,JSON_THROW_ON_ERROR).'</script>');
        }
        if(count($this->requests)===2){$this->rows=[['name'=>'datr','value'=>'SYNTHETIC_PRIVATE_DEVICE','domain'=>'.facebook.com']];return new WebLoginResponse(302,'',['location'=>['https://www.facebook.com/two_step_verification/two_factor/?context=SYNTHETIC_PRIVATE_CONTEXT']]);}
        if(count($this->requests)===3)return new WebLoginResponse(200,'<html>Private challenge not yet supported</html>');
        throw new \LogicException('Unexpected request during pending challenge');
    }
    public function cookies():array{return $this->rows;}
    public function clear():void{$this->rows=[];}
}
