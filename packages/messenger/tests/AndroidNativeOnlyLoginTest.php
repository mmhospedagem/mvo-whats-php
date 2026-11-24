<?php
declare(strict_types=1);

use MVO\Messenger\Auth\{AndroidLoginProfile, AndroidLoginResult, AndroidLoginService, CookieJar, SessionStore};
use MVO\Messenger\{MessengerClient, MessengerException};
use MVO\Messenger\Transport\{AndroidLoginTransport, HttpClient, HttpResponse};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

/** Native-only facade contracts. Every response is an explicit offline fixture. */
final class AndroidNativeOnlyLoginTest extends TestCase
{
    private const UID = '12345678901234567890';
    private const TOKEN = 'SYNTHETIC_NATIVE_IDENTITY_TOKEN';
    private const PASSWORD = 'SYNTHETIC_ACCOUNT_PASSWORD';
    private static string $publicKey;
    /** @var list<string> */ private array $roots = [];
    /** @var list<MessengerClient> */ private array $clients = [];
    /** @var list<SessionStore> */ private array $stores = [];

    public static function setUpBeforeClass(): void
    {
        $private = openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA, 'private_key_bits'=>2048]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $private);
        $details = openssl_pkey_get_details($private);
        self::assertIsArray($details);
        self::$publicKey = $details['key'];
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) $client->stop();
        unset($client);
        $this->clients = [];
        foreach ($this->stores as $store) $store->release();
        unset($store);
        $this->stores = [];
        gc_collect_cycles();
        foreach ($this->roots as $root) {
            $resolved = realpath($root);
            $temporary = realpath(sys_get_temp_dir());
            self::assertIsString($resolved);
            self::assertIsString($temporary);
            self::assertStringStartsWith($temporary.DIRECTORY_SEPARATOR, $resolved);
            self::assertMatchesRegularExpression('/\Amvo-native-only-[a-f0-9]{16}\z/', basename($resolved));
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
                else unlink($file->getPathname());
            }
            rmdir($resolved);
        }
    }

    public function testFacadeAuthenticatesWithNativeUserTokenAndNeverUsesWebIdentity(): void
    {
        [$client,$profile,$native,$web,$store,$root] = $this->client([self::key(),self::success(),self::identity()]);
        $client->loginAndroid('owner@example.invalid',self::PASSWORD,$profile,$native);
        self::assertCount(3,$native->requests());
        self::assertSame(['/pwd_key_fetch','/auth/login','/me'],array_map(static fn(array $r)=>parse_url($r['url'],PHP_URL_PATH),$native->requests()));
        self::assertSame(['POST','POST','GET'],array_column($native->requests(),'method'));
        $me = $native->requests()[2];
        self::assertSame([],$me['form']);
        self::assertSame(['Authorization'=>'OAuth '.self::TOKEN,'X-FB-Friendly-Name'=>'getMeUser'],$me['headers']);
        self::assertSame(['/pwd_key_fetch','/auth/login'],$profile->paths);
        self::assertSame(0,$web->calls);
        $saved = $store->get('cookies');
        self::assertIsString($saved);
        self::assertSame(self::UID,CookieJar::fromJson($saved)->accountId());
        self::assertSame('session_imported',$client->getState());
        self::assertFalse($client->isConnected());
        self::assertFalse($client->accountInfo()['e2ee_ready']);
        self::assertNull($client->loginChallenge());
        self::assertFalse($store->ownsLock());
        $public = json_encode([$client->accountInfo(),$client->events()],JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::TOKEN,$public);
        self::assertStringNotContainsString(self::PASSWORD,$public);
        $client->stop();
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $contents = file_get_contents($file->getPathname());
                self::assertIsString($contents);
                self::assertStringNotContainsString(self::TOKEN,$contents,'The native user token is not durable session state');
                self::assertStringNotContainsString(self::PASSWORD,$contents);
            }
        }
    }

    public function testCodeConfirmationUsesItsOwnNativeIdentityResponseBeforeSaving(): void
    {
        [$client,$profile,$native,$web,$store] = $this->client([self::key(),self::twoFactor(),self::success(),self::identity()]);
        self::reason(fn()=>$client->loginAndroid('owner@example.invalid',self::PASSWORD,$profile,$native),'login_two_factor_required');
        self::assertCount(2,$native->requests());
        self::assertNull($store->loadCookies());
        self::assertTrue($client->loginChallenge()['native_only']);
        self::assertFalse($client->loginChallenge()['browser_continuation_available']);
        $client->confirmLogin('001234');
        self::assertCount(4,$native->requests());
        self::assertSame('two_factor',$native->requests()[2]['form']['credentials_type']);
        self::assertSame('001234',$native->requests()[2]['form']['password']);
        self::assertSame('001234',$native->requests()[2]['form']['twofactor_code']);
        self::assertSame('SYNTHETIC_FIRST_FACTOR',$native->requests()[2]['form']['first_factor']);
        self::assertSame('/me',parse_url($native->requests()[3]['url'],PHP_URL_PATH));
        self::assertSame('OAuth '.self::TOKEN,$native->requests()[3]['headers']['Authorization']);
        self::assertSame(0,$web->calls);
        $saved = $store->get('cookies');
        self::assertIsString($saved);
        self::assertSame(self::UID,CookieJar::fromJson($saved)->accountId());
        self::assertNull($client->loginChallenge());
        self::reason(fn()=>$client->confirmLogin('001234'),'login_challenge_expired');
        self::assertCount(4,$native->requests());
    }

    public function testApplicationApprovalChecksNativeIdentityAfterOneTransientExchange(): void
    {
        [$client,$profile,$native,$web,$store] = $this->client([
            self::key(),self::twoFactor(),new HttpResponse(200,'{"data":[{"approved":false}]}'),
            new HttpResponse(200,'{"data":[{"approved":true}]}'),self::success(),self::identity(),
        ]);
        self::reason(fn()=>$client->loginAndroid('owner@example.invalid',self::PASSWORD,$profile,$native),'login_two_factor_required');
        self::assertFalse($client->checkLoginApproval());
        self::assertCount(3,$native->requests());
        self::assertNull($store->loadCookies());
        $service = self::service($client);
        (new ReflectionProperty(AndroidLoginService::class,'approvalCheckAt'))->setValue($service,0);
        self::assertTrue($client->checkLoginApproval());
        self::assertCount(6,$native->requests());
        self::assertSame('/check_approved_machine',parse_url($native->requests()[3]['url'],PHP_URL_PATH));
        $exchange = $native->requests()[4];
        self::assertSame('transient_token',$exchange['form']['credentials_type']);
        self::assertSame('SYNTHETIC_TRANSIENT_TOKEN',$exchange['form']['password']);
        foreach (['first_factor','twofactor_code','userid'] as $key) self::assertArrayNotHasKey($key,$exchange['form']);
        self::assertStringNotContainsString(self::PASSWORD,json_encode($exchange,JSON_THROW_ON_ERROR));
        self::assertSame('/me',parse_url($native->requests()[5]['url'],PHP_URL_PATH));
        self::assertSame('OAuth '.self::TOKEN,$native->requests()[5]['headers']['Authorization']);
        self::assertSame(0,$web->calls);
        $saved = $store->get('cookies');
        self::assertIsString($saved);
        self::assertSame(self::UID,CookieJar::fromJson($saved)->accountId());
        self::assertNull($client->loginChallenge());
        self::reason(fn()=>$client->checkLoginApproval(),'login_challenge_expired');
        self::assertCount(6,$native->requests());
    }

    #[DataProvider('unconfirmedIdentities')]
    public function testUnconfirmedNativeIdentityCannotSaveCookiesOrFallBackToWeb(HttpResponse|Throwable $identity,string $reason): void
    {
        [$client,$profile,$native,$web,$store] = $this->client([self::key(),self::success(),$identity]);
        self::reason(fn()=>$client->loginAndroid('owner@example.invalid',self::PASSWORD,$profile,$native),$reason);
        self::assertCount(3,$native->requests());
        self::assertSame('/me',parse_url($native->requests()[2]['url'],PHP_URL_PATH));
        self::assertSame(0,$web->calls);
        self::assertNull($store->loadCookies());
        self::assertNull($store->get('account_id'));
        self::assertNull($client->loginChallenge());
        self::reason(fn()=>$client->confirmLogin('001234'),'login_challenge_expired');
        self::assertCount(3,$native->requests());
        self::assertFalse($client->isConnected());
    }

    /** @return iterable<string,array{HttpResponse|Throwable,string}> */
    public static function unconfirmedIdentities(): iterable
    {
        yield 'another account' => [self::identity('99999999999999999999'),'login_identity_mismatch'];
        foreach ([
            'malformed'=>'{broken','list'=>'[{"id":"'.self::UID.'"}]','null'=>'null','string'=>'"'.self::UID.'"',
            'no id'=>'{}','zero'=>'{"id":"0"}','leading zero'=>'{"id":"0123"}','float'=>'{"id":123.5}',
            'negative'=>'{"id":-123}','boolean'=>'{"id":true}','too long'=>'{"id":"12345678901234567890123456"}',
            'error even with id'=>'{"id":"'.self::UID.'","error":null}',
            'native406'=>'{"error":{"code":406,"error_data":{"uid":"'.self::UID.'"}}}',
            'HTML'=>'<html><script>CurrentUserInitialData</script></html>',
        ] as $label=>$body) yield $label => [new HttpResponse(200,$body),'login_native_identity_unconfirmed'];
        yield 'redirect never followed' => [new HttpResponse(302,'{"id":"'.self::UID.'"}',['location'=>['https://www.facebook.com/messages/']]),'login_native_identity_unconfirmed'];
        yield 'HTTP forbidden with matching id' => [new HttpResponse(403,'{"id":"'.self::UID.'"}'),'login_native_identity_unconfirmed'];
        yield 'oversized body' => [new HttpResponse(200,'{"id":"'.self::UID.'","padding":"'.str_repeat('x',2097152).'"}'),'login_native_identity_unconfirmed'];
        yield 'transport failure' => [new MessengerException('login_native_transport_failed','Synthetic identity timeout'),'login_native_transport_failed'];
    }

    public function testCheckpointFromNativeIdentityIsBlockedWithoutBrowserOrCodeReplay(): void
    {
        $checkpoint = new HttpResponse(400,json_encode(['error'=>['code'=>405,'error_data'=>['url'=>'https://www.facebook.com/checkpoint/?private=SYNTHETIC_CONTEXT','flow_id'=>'SYNTHETIC_FLOW']]],JSON_THROW_ON_ERROR));
        [$client,$profile,$native,$web,$store] = $this->client([self::key(),self::twoFactor(),self::success(),$checkpoint]);
        self::reason(fn()=>$client->loginAndroid('owner@example.invalid',self::PASSWORD,$profile,$native),'login_two_factor_required');
        $deadline = $client->loginChallenge()['expires_at'];
        self::reason(fn()=>$client->confirmLogin('001234'),'login_native_verification_unsupported');
        self::assertCount(4,$native->requests());
        $state = $client->loginChallenge();
        self::assertIsArray($state);
        self::assertSame($deadline,$state['expires_at']);
        self::assertTrue($state['native_only']);
        self::assertSame('unsupported_native_checkpoint',$state['continuation']);
        foreach (['ready','can_check','can_refresh','requires_code','browser_continuation_available'] as $key) self::assertFalse($state[$key]);
        self::assertNull($store->loadCookies());
        self::assertSame(0,$web->calls);
        self::reason(fn()=>$client->confirmLogin('001234'),'login_native_verification_unsupported');
        self::reason(fn()=>$client->checkLoginApproval(),'login_native_verification_unsupported');
        self::reason(fn()=>$client->requestLoginSms(),'login_native_verification_unsupported');
        self::reason(fn()=>$client->exportPrivateLoginChallenge(),'login_native_browser_unavailable');
        self::assertCount(4,$native->requests());
        $public = json_encode([$state,$client->accountInfo(),$client->events()],JSON_THROW_ON_ERROR);
        foreach ([self::TOKEN,'SYNTHETIC_CONTEXT','SYNTHETIC_FLOW','001234','SYNTHETIC_FIRST_FACTOR'] as $secret) self::assertStringNotContainsString($secret,$public);
    }

    public function testFailedIdentityAfterAcceptedCodeCannotResubmitTheCode(): void
    {
        [$client,$profile,$native,$web,$store] = $this->client([self::key(),self::twoFactor(),self::success(),new HttpResponse(200,'{}')]);
        self::reason(fn()=>$client->loginAndroid('owner@example.invalid',self::PASSWORD,$profile,$native),'login_two_factor_required');
        self::reason(fn()=>$client->confirmLogin('001234'),'login_native_identity_unconfirmed');
        self::assertNull($client->loginChallenge());
        self::reason(fn()=>$client->confirmLogin('001234'),'login_challenge_expired');
        self::assertCount(4,$native->requests());
        self::assertNull($store->loadCookies());
        self::assertSame(0,$web->calls);
    }

    public function testSavedAccountMismatchStopsBeforeIdentityAndPreservesItsCookies(): void
    {
        [$client,$profile,$native,$web,$store] = $this->client([self::key(),self::success()]);
        $saved = CookieJar::fromJson('{"c_user":"999","xs":"SYNTHETIC_EXISTING_COOKIE"}');
        $client->importCookies($saved);
        self::reason(fn()=>$client->loginAndroid('owner@example.invalid',self::PASSWORD,$profile,$native),'login_identity_mismatch');
        self::assertCount(2,$native->requests());
        self::assertSame($saved->exportPrivate(),$store->loadCookies()?->exportPrivate());
        self::assertSame(0,$web->calls);
    }

    public function testNativeCookieMismatchStopsBeforeIdentityAndPersistsNothing(): void
    {
        [$client,$profile,$native,$web,$store] = $this->client([self::key(),self::success(cookieUid:'999')]);
        self::reason(fn()=>$client->loginAndroid('owner@example.invalid',self::PASSWORD,$profile,$native),'login_identity_mismatch');
        self::assertCount(2,$native->requests());
        self::assertNull($store->loadCookies());
        self::assertSame(0,$web->calls);
    }

    public function testExpiryBeforeIdentityPreventsEvenTheNativeGet(): void
    {
        [$client,$profile,$native,$web,$store] = $this->client([self::key(),self::twoFactor(),self::success()]);
        self::reason(fn()=>$client->loginAndroid('owner@example.invalid',self::PASSWORD,$profile,$native),'login_two_factor_required');
        $service = self::service($client);
        $native->onRequest = static function(array $request) use ($service): void {
            if (($request['form']['credentials_type']??null)==='two_factor') (new ReflectionProperty(AndroidLoginService::class,'expiresAt'))->setValue($service,time()-1);
        };
        self::reason(fn()=>$client->confirmLogin('001234'),'login_challenge_expired');
        self::assertCount(3,$native->requests());
        self::assertSame(0,$web->calls);
        self::assertNull($store->loadCookies());
        self::assertNull($client->loginChallenge());
    }

    public function testExpiryDuringIdentityRejectsTheLateMatchingIdentity(): void
    {
        [$client,$profile,$native,$web,$store] = $this->client([self::key(),self::twoFactor(),self::success(),self::identity()]);
        self::reason(fn()=>$client->loginAndroid('owner@example.invalid',self::PASSWORD,$profile,$native),'login_two_factor_required');
        $service = self::service($client);
        (new ReflectionProperty(AndroidLoginService::class,'expiresAt'))->setValue($service,time()+2);
        $native->onRequest = static function(array $request): void { if (parse_url($request['url'],PHP_URL_PATH)==='/me') sleep(3); };
        self::reason(fn()=>$client->confirmLogin('001234'),'login_challenge_expired');
        self::assertCount(4,$native->requests());
        self::assertSame(0,$web->calls);
        self::assertNull($store->loadCookies());
        self::assertNull($client->loginChallenge());
    }

    public function testNativeResultTokenIsPrivateAndCannotBeSerialized(): void
    {
        $result = AndroidLoginResult::fromJson(self::success()->body);
        self::assertSame(self::TOKEN,$result->accessTokenPrivate());
        self::assertStringNotContainsString(self::TOKEN,print_r($result,true));
        self::assertStringNotContainsString(self::TOKEN,json_encode($result,JSON_THROW_ON_ERROR));
        try { serialize($result); self::fail('Native token cannot be serialized'); }
        catch (LogicException $error) { self::assertStringContainsString('private',$error->getMessage()); }
    }

    public function testUnreferencedNativeResultIsNotRetainedInACache(): void
    {
        // PHP can retain a failed serialize() receiver internally. Exercise the
        // SDK's ownership separately from that engine-owned serialization state.
        $result = AndroidLoginResult::fromJson(self::success()->body);
        $weak = WeakReference::create($result);
        unset($result);
        gc_collect_cycles();
        self::assertNull($weak->get());
    }

    private static function service(MessengerClient $client): AndroidLoginService
    {
        $service = (new ReflectionProperty(MessengerClient::class,'loginService'))->getValue($client);
        self::assertInstanceOf(AndroidLoginService::class,$service);
        return $service;
    }

    /** @param list<HttpResponse|Throwable> $responses
     * @return array{MessengerClient,NativeOnlyProfileFixture,NativeOnlyTransportFixture,NativeOnlyForbiddenWebFixture,SessionStore,string}
     */
    private function client(array $responses): array
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mvo-native-only-'.bin2hex(random_bytes(8));
        mkdir($root,0700); $this->roots[]=$root;
        $store = new SessionStore($root,'tenant'); $this->stores[]=$store;
        $web = new NativeOnlyForbiddenWebFixture();
        $client = new MessengerClient('tenant',$root,$web); $this->clients[]=$client;
        $native = new NativeOnlyTransportFixture($responses);
        return [$client,new NativeOnlyProfileFixture(),$native,$web,$store,$root];
    }

    private static function key(): HttpResponse
    {
        return new HttpResponse(200,json_encode(['key_id'=>237,'public_key'=>self::$publicKey,'seconds_to_live'=>600],JSON_THROW_ON_ERROR));
    }

    private static function success(string $cookieUid=self::UID): HttpResponse
    {
        return new HttpResponse(200,json_encode(['uid'=>self::UID,'access_token'=>self::TOKEN,'session_cookies'=>[
            ['name'=>'c_user','value'=>$cookieUid,'domain'=>'.facebook.com','path'=>'/','secure'=>true],
            ['name'=>'xs','value'=>'SYNTHETIC_SESSION_COOKIE','domain'=>'.facebook.com','path'=>'/','secure'=>true,'HttpOnly'=>true],
        ]],JSON_THROW_ON_ERROR));
    }

    private static function identity(string $uid=self::UID): HttpResponse
    {
        return new HttpResponse(200,json_encode(['id'=>$uid,'name'=>'Synthetic Native Account'],JSON_THROW_ON_ERROR));
    }

    private static function twoFactor(): HttpResponse
    {
        return new HttpResponse(400,json_encode(['error'=>['code'=>406,'error_data'=>['uid'=>self::UID,'login_first_factor'=>'SYNTHETIC_FIRST_FACTOR','machine_id'=>'SYNTHETIC_MACHINE','auth_token'=>'SYNTHETIC_TRANSIENT_TOKEN']]],JSON_THROW_ON_ERROR));
    }

    private static function reason(callable $operation,string $reason): void
    {
        try { $operation(); self::fail('Expected '.$reason); }
        catch (MessengerException $error) { self::assertSame($reason,$error->reason); }
    }
}

final class NativeOnlyProfileFixture implements AndroidLoginProfile
{
    /** @var list<string> */ public array $paths=[];
    public function origin(): string { return 'https://graph.facebook.com'; }
    public function passwordVersion(): int { return 2; }
    public function keyFetchFlow(): string { return 'CONTROLLER_INITIALIZATION'; }
    public function authorize(string $method,string $path,#[SensitiveParameter] array $form): array
    {
        $this->paths[]=$path;
        $form['access_token']='SYNTHETIC_APPLICATION_TOKEN';
        return ['form'=>$form,'headers'=>[]];
    }
}

final class NativeOnlyTransportFixture implements AndroidLoginTransport
{
    /** @var list<array{method:string,url:string,form:array<string,string>,headers:array<string,string>}> */ private array $records=[];
    /** @var Closure(array{method:string,url:string,form:array<string,string>,headers:array<string,string>}):void|null */ public ?Closure $onRequest=null;
    /** @param list<HttpResponse|Throwable> $responses */ public function __construct(private array $responses) {}
    /** @return list<array{method:string,url:string,form:array<string,string>,headers:array<string,string>}> */
    public function requests(): array { return $this->records; }
    public function request(string $method,#[SensitiveParameter] string $url,#[SensitiveParameter] array $form=[],#[SensitiveParameter] array $headers=[]): HttpResponse
    {
        $request=compact('method','url','form','headers'); $this->records[]=$request;
        ($this->onRequest)?->__invoke($request);
        $response=array_shift($this->responses);
        if ($response instanceof Throwable) throw $response;
        if (!$response instanceof HttpResponse) throw new LogicException('Unexpected native fixture request: '.parse_url($url,PHP_URL_PATH));
        return $response;
    }
}

final class NativeOnlyForbiddenWebFixture implements HttpClient
{
    public int $calls=0;
    public function request(string $method,string $url,CookieJar $cookies,array $form=[]): HttpResponse
    {
        ++$this->calls;
        throw new LogicException('Native-only login must not perform Web HTTP');
    }
}
