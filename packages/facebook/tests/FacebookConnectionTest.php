<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\{FacebookClient,FacebookConnection};
use MVO\Messenger\Auth\{CookieJar,SessionStore,WebLoginService};
use MVO\Messenger\MessengerException;
use MVO\Messenger\Transport\Socket;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/FacebookConnectionFixture.php';

final class FacebookConnectionTest extends TestCase
{
    private string $root;
    /** @var list<FacebookConnection> */ private array $clients=[];

    protected function setUp():void{$this->root=sys_get_temp_dir().'/mvo-facebook-connection-'.bin2hex(random_bytes(8));mkdir($this->root,0700);}
    protected function tearDown():void
    {
        foreach($this->clients as $client)$client->stop();unset($client);$this->clients=[];gc_collect_cycles();
        $entries=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($entries as $entry){if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($this->root);
    }

    private function client(?FacebookConnectionHttp $http=null,?FacebookConnectionPeer $peer=null,string $id='demo'):FacebookConnection
    {
        $http??=new FacebookConnectionHttp();$peer??=new FacebookConnectionPeer();
        $client=new FacebookConnection($id,$this->root.'/auth',$this->root.'/services',$http,
            static function(string $url,string $origin,array $headers)use($peer):Socket{
                TestCase::assertStringStartsWith('wss://gateway.facebook.com/ws/lightspeed?',$url);
                TestCase::assertSame('https://www.facebook.com',$origin);
                TestCase::assertStringContainsString('xs='.FacebookConnectionFixture::SESSION,$headers['Cookie']);return $peer;
            },static fn(array $urls):array=>[],.1);
        $this->clients[]=$client;return $client;
    }

    private function login(FacebookConnection $client,?FacebookConnectionLoginTransport $transport=null):FacebookConnectionLoginTransport
    {
        $transport??=new FacebookConnectionLoginTransport();
        $client->login('person@example.invalid',FacebookConnectionFixture::PASSWORD,new WebLoginService($transport,static fn(array $urls):array=>[]));
        return $transport;
    }

    public function testAnUnconnectedLibraryDoesNotExposeServicesOrStartAnImplicitLogin():void
    {
        $http=new FacebookConnectionHttp();$client=$this->client($http);
        foreach(['services','listPages','posts','comments','reactions','inbox','ads','selectedContext','monitor'] as $method){
            try{$client->$method();self::fail('A disconnected library exposed an authenticated service');}
            catch(MessengerException $error){self::assertSame('not_connected',$error->reason);}
        }
        try{$client->connect();self::fail('A missing session must require explicit login');}
        catch(MessengerException $error){self::assertSame('session_required',$error->reason);}
        self::assertSame([],$http->urls);self::assertFalse($client->accountInfo()['has_saved_session']);
    }

    public function testLoginPersistsTheSessionAndANewConnectionRestoresItWithoutAnotherPasswordPost():void
    {
        $firstPeer=new FacebookConnectionPeer();$first=$this->client(peer:$firstPeer);
        self::assertFalse($first->accountInfo()['has_saved_session']);$login=$this->login($first);
        self::assertTrue($first->accountInfo()['has_saved_session']);self::assertFalse($first->isConnected());
        self::assertSame(FacebookConnectionFixture::ACCOUNT,$first->accountInfo()['account_id']);
        self::assertSame(1,$login->passwordPosts);self::assertSame([],$login->cookies());
        $connections=[];$first->on('connection',static function(array $event)use(&$connections):void{$connections[]=$event['state'];});
        $first->connect();self::assertTrue($first->isConnected());self::assertSame('metadata_connected',$first->getState());
        self::assertFalse($first->accountInfo()['e2ee_ready']);self::assertContains('metadata_connected',$connections);
        self::assertNull($firstPeer->syncRequests[0]['last_applied_cursor']);$first->poll(0);$first->stop();
        self::assertFalse($firstPeer->isConnected());self::assertTrue($first->accountInfo()['has_saved_session']);
        $secondPeer=new FacebookConnectionPeer();$second=$this->client(peer:$secondPeer);$second->connect();
        self::assertTrue($second->isConnected());self::assertSame(FacebookConnectionFixture::ACCOUNT,$second->accountInfo()['account_id']);
        self::assertSame('synthetic-cursor-1',$secondPeer->syncRequests[0]['last_applied_cursor']);
        self::assertSame(1,$login->passwordPosts,'Restoration must not repeat password authentication');
        self::assertSame(0,$login->otpPosts);self::assertNotEmpty($second->events());
    }

    public function testFacebookServicesUseTheSameOwnerAndPreferencesSurviveAStopAndReconnect():void
    {
        $client=$this->client();$this->login($client);$client->connect();$services=$client->services();
        self::assertInstanceOf(FacebookClient::class,$services);self::assertSame(FacebookConnectionFixture::ACCOUNT,$services->accountId());
        self::assertSame(FacebookConnectionFixture::PAGE,$client->listPages()['items'][0]['id']);$client->selectPage(FacebookConnectionFixture::PAGE);
        self::assertSame(FacebookConnectionFixture::PAGE,$client->selectedContext()['page']['id']);$client->stop();
        try{$services->listPages();self::fail('The stopped connection must close its old facade');}
        catch(\LogicException $error){self::assertStringContainsString('closed',$error->getMessage());}
        $client->connect();self::assertNotSame($services,$client->services());
        self::assertSame(FacebookConnectionFixture::PAGE,$client->selectedContext()['page']['id']);
        self::assertSame(FacebookConnectionFixture::ACCOUNT,$client->services()->accountId());
    }

    public function testCompetingConnectionCannotAuthenticateOrConnectBeforeTheExistingOwnerStops():void
    {
        $first=$this->client();$this->login($first);$first->connect();
        $http=new FacebookConnectionHttp();$second=$this->client($http);$login=new FacebookConnectionLoginTransport();
        foreach(['login','connect'] as $operation){
            try{if($operation==='login')$this->login($second,$login);else $second->connect();self::fail('A second owner acquired the active session');}
            catch(\RuntimeException $error){self::assertStringContainsString('active owner',$error->getMessage());}
        }
        self::assertSame([],$login->requests);self::assertSame([],$http->urls);self::assertTrue($first->isConnected());
        $first->stop();$second->connect();self::assertTrue($second->isConnected());
    }

    public function testRecognizedTwoFactorLoginSavesOnlyTheConfirmedSessionAndRestoresAfterRestart():void
    {
        $first=$this->client();$login=new FacebookConnectionLoginTransport('otp');
        try{$this->login($first,$login);self::fail('A two-factor challenge must remain pending');}
        catch(MessengerException $error){self::assertSame('login_two_factor_required',$error->reason);}
        self::assertFalse($first->accountInfo()['has_saved_session']);self::assertFalse($first->isConnected());
        self::assertSame('two_factor',$first->loginChallenge()['type']);self::assertTrue($first->loginChallenge()['ready']);
        $first->confirmLogin(FacebookConnectionFixture::CODE);self::assertNull($first->loginChallenge());
        self::assertTrue($first->accountInfo()['has_saved_session']);self::assertSame(1,$login->passwordPosts);self::assertSame(1,$login->otpPosts);
        $first->stop();$restored=$this->client();$restored->connect();self::assertTrue($restored->isConnected());
        self::assertSame(FacebookConnectionFixture::ACCOUNT,$restored->services()->accountId());
        self::assertSame(1,$login->otpPosts,'Restoration must not resend the OTP');
    }

    public function testCaptchaDoesNotAcceptOtpOrPersistPartialCookiesAndStopReleasesTheOwner():void
    {
        $client=$this->client();$login=new FacebookConnectionLoginTransport('captcha');
        try{$this->login($client,$login);self::fail('A CAPTCHA must remain pending');}
        catch(MessengerException $error){self::assertSame('login_captcha_required',$error->reason);}
        self::assertSame('captcha',$client->loginChallenge()['type']);self::assertFalse($client->loginChallenge()['ready']);
        self::assertFalse($client->accountInfo()['has_saved_session']);$requests=count($login->requests);
        try{$client->confirmLogin(FacebookConnectionFixture::CODE);self::fail('A CAPTCHA must not accept a TOTP');}
        catch(MessengerException $error){self::assertSame('login_captcha_required',$error->reason);}
        self::assertCount($requests,$login->requests);$client->stop();self::assertNull($client->loginChallenge());self::assertSame([],$login->cookies());
        $store=new SessionStore($this->root.'/auth','demo');$store->acquire();
        self::assertNull($store->loadCookies());self::assertNull($store->get('account_id'));$store->release();unset($store);
        $http=new FacebookConnectionHttp();$other=$this->client($http);
        try{$other->connect();self::fail('A partial CAPTCHA must not create a saved session');}
        catch(MessengerException $error){self::assertSame('session_required',$error->reason);}
        self::assertSame([],$http->urls);
    }

    public function testHumanBrowserCompletionConfirmsIdentityBeforeTheSessionBecomesRestorable():void
    {
        $client=$this->client();$login=new FacebookConnectionLoginTransport('captcha');
        try{$this->login($client,$login);self::fail('A CAPTCHA must remain pending');}
        catch(MessengerException $error){self::assertSame('login_captcha_required',$error->reason);}
        $handoff=$client->exportPrivateLoginChallenge();self::assertSame(FacebookConnectionFixture::CHALLENGE,$handoff['url']);
        self::assertFalse($client->accountInfo()['has_saved_session']);
        $client->completeBrowserLogin(new CookieJar(FacebookConnectionFixture::cookies()));
        self::assertNull($client->loginChallenge());self::assertTrue($client->accountInfo()['has_saved_session']);
        self::assertFalse($client->isConnected());$client->stop();$restored=$this->client();$restored->connect();
        self::assertSame(FacebookConnectionFixture::ACCOUNT,$restored->accountInfo()['account_id']);self::assertTrue($restored->isConnected());
    }

    public function testExpiredSessionRemainsSavedAndNeverCausesAutomaticPasswordAuthentication():void
    {
        $first=$this->client();$login=$this->login($first);$first->stop();
        $http=new FacebookConnectionHttp();$http->expired=true;$restored=$this->client($http);
        try{$restored->connect();self::fail('A login redirect cannot become an authenticated connection');}
        catch(MessengerException $error){self::assertSame('session_expired',$error->reason);self::assertStringNotContainsString('SYNTHETIC_PRIVATE_EXPIRED_BODY',$error->getMessage());}
        self::assertFalse($restored->isConnected());self::assertTrue($restored->accountInfo()['has_saved_session']);
        self::assertSame(1,$login->passwordPosts);self::assertSame(['https://www.facebook.com/messages/'],$http->urls);
        $store=new SessionStore($this->root.'/auth','demo');$store->acquire();
        self::assertSame(FacebookConnectionFixture::ACCOUNT,$store->loadCookies()?->accountId());$store->release();unset($store);
    }

    public function testPasswordsAndOtpDoNotEnterSessionStorageOrPublicDiagnostics():void
    {
        $client=$this->client();$login=new FacebookConnectionLoginTransport('otp');
        try{$this->login($client,$login);self::fail('A two-factor challenge must remain pending');}
        catch(MessengerException $error){self::assertSame('login_two_factor_required',$error->reason);}
        $client->confirmLogin(FacebookConnectionFixture::CODE);$client->connect();
        $public=json_encode([$client->accountInfo(),$client->events()],JSON_THROW_ON_ERROR).print_r($client,true);
        foreach([FacebookConnectionFixture::PASSWORD,FacebookConnectionFixture::SESSION,FacebookConnectionFixture::TOKEN,'SYNTHETIC_PRIVATE_NONCE','SYNTHETIC_PRIVATE_CHALLENGE'] as $secret)self::assertStringNotContainsString($secret,$public);
        $database=new \PDO('sqlite:'.$this->root.'/auth/messenger/demo/state.sqlite');
        $rows=$database->query('SELECT name,value FROM settings')->fetchAll(\PDO::FETCH_ASSOC);$settings=json_encode($rows,JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(FacebookConnectionFixture::PASSWORD,$settings);self::assertStringNotContainsString('approvals_code',$settings);
        self::assertNotContains(FacebookConnectionFixture::CODE,array_column($rows,'value'));unset($database);
    }

    public function testProtocolCallbacksCannotStartFacebookQueriesAndThePrivateConnectionCannotBeSerialized():void
    {
        $client=$this->client();$this->login($client);$guarded=false;
        $client->on('connection',static function(array $event)use($client,&$guarded):void{
            if($event['state']!=='metadata_connected')return;
            try{$client->listPages();TestCase::fail('A protocol callback started a Facebook query');}
            catch(\LogicException){$guarded=true;}
        });
        $client->connect();self::assertTrue($guarded);self::assertTrue($client->isConnected());
        self::assertSame(FacebookConnectionFixture::PAGE,$client->listPages()['items'][0]['id']);
        try{serialize($client);self::fail('A private connection cannot be serialized');}
        catch(\LogicException $error){self::assertStringContainsString('cannot be serialized',$error->getMessage());}
    }
}
