<?php
declare(strict_types=1);

use MVO\Messenger\Auth\{AndroidDeviceIdentity,AndroidLoginProfile,AndroidLoginService,CookieJar,SessionStore};
use MVO\Messenger\{MessengerClient,MessengerException};
use MVO\Messenger\Transport\{AndroidLoginTransport,HttpClient,HttpResponse};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

/** Entirely offline: every native/Web request terminates in an in-memory fixture. */
final class AndroidLoginServiceTest extends TestCase
{
    private const UID = '12345678901234567890';
    private const EMAIL = 'owner@example.invalid';
    private const PASSWORD = 'SYNTHETIC_NATIVE_PASSWORD_ç🔐';
    private static OpenSSLAsymmetricKey $private;
    private static string $public;
    /** @var list<string> */ private array $roots = [];
    /** @var list<SessionStore> */ private array $stores = [];
    /** @var list<MessengerClient> */ private array $clients = [];

    public static function setUpBeforeClass(): void
    {
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $private);
        $details = openssl_pkey_get_details($private);
        self::assertIsArray($details);
        self::$private = $private;
        self::$public = $details['key'];
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
        $temporary = realpath(sys_get_temp_dir());
        foreach ($this->roots as $root) {
            $resolved = realpath($root);
            self::assertIsString($temporary);
            self::assertIsString($resolved);
            self::assertStringStartsWith($temporary . DIRECTORY_SEPARATOR, $resolved);
            self::assertMatchesRegularExpression('/\Amvo-android-service-[a-f0-9]{16}\z/', basename($resolved));
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
                else unlink($file->getPathname());
            }
            rmdir($resolved);
        }
    }

    public function testWireKeyFetchAndEncryptedLoginAreVerifiedBeforeClientPersistsCookies(): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([self::key(), self::success()], [self::identity()]);
        self::assertSame(0, $profile->callbacks);
        self::assertSame([], $native->requests);
        self::legacyLogin($client,$profile,$native,$web);
        self::assertCount(2, $native->requests);
        $key = $native->requests[0];
        self::assertSame('POST', $key['method']);
        self::assertSame('https://graph.facebook.com/pwd_key_fetch', $key['url']);
        self::assertSame('GET', $key['form']['method']);
        self::assertSame('CONTROLLER_INITIALIZATION', $key['form']['flow']);
        self::assertSame('2', $key['form']['version']);
        self::assertSame('pwdKeyFetch', $key['headers']['X-FB-Friendly-Name']);
        self::assertArrayNotHasKey('email', $key['form']);
        self::assertArrayNotHasKey('password', $key['form']);
        $login = $native->requests[1];
        self::assertSame('POST', $login['method']);
        self::assertSame('https://graph.facebook.com/auth/login', $login['url']);
        self::assertSame('authenticate', $login['headers']['X-FB-Friendly-Name']);
        self::assertSame(self::EMAIL, $login['form']['email']);
        self::assertSame('password', $login['form']['credentials_type']);
        self::assertSame($key['form']['device_id'], $login['form']['device_id']);
        self::assertNotSame($login['form']['device_id'], $login['form']['adid']);
        self::assertSame('1', $login['form']['generate_machine_id']);
        self::assertArrayNotHasKey('method', $login['form']);
        self::assertSame(self::PASSWORD, self::decrypt($login['form']['password']));
        self::assertSame(['POST', 'POST'], array_column($profile->authorized, 'method'));
        self::assertSame(['/pwd_key_fetch', '/auth/login'], array_column($profile->authorized, 'path'));
        self::assertCount(1, $web->requests);
        self::assertSame('GET', $web->requests[0]['method']);
        self::assertSame('https://www.facebook.com/messages/', $web->requests[0]['url']);
        self::assertStringContainsString('c_user=' . self::UID, $web->requests[0]['cookies']);
        self::assertStringContainsString('xs=SYNTHETIC_WEB_COOKIE', $web->requests[0]['cookies']);
        self::assertSame(self::UID, $store->loadCookies()?->accountId());
        self::assertSame(self::UID, $store->get('account_id'));
        self::assertSame('session_imported', $client->getState());
        self::assertFalse($client->isConnected());
        self::assertFalse($client->accountInfo()['e2ee_ready']);
        self::assertNull($client->loginChallenge());
        self::assertFalse($store->ownsLock());
        $store->acquire();
        $device = new AndroidDeviceIdentity($store);
        self::assertSame($login['form']['device_id'], $device->getDeviceId());
        self::assertSame($login['form']['adid'], $device->getAdvertisingId());
        $public = json_encode($store->events(), JSON_THROW_ON_ERROR) . json_encode($client->accountInfo(), JSON_THROW_ON_ERROR);
        foreach ([self::PASSWORD, self::EMAIL, 'SYNTHETIC_NATIVE_TOKEN', 'SYNTHETIC_NATIVE_SECRET', 'SYNTHETIC_APP_AUTH', $device->getDeviceId(), $device->getAdvertisingId()] as $private) self::assertStringNotContainsString($private, $public);
        $saved = ($store->get('cookies') ?? '') . ($store->get('android.device_identity') ?? '');
        foreach ([self::PASSWORD, self::EMAIL, 'SYNTHETIC_NATIVE_TOKEN', 'SYNTHETIC_NATIVE_SECRET', 'SYNTHETIC_APP_AUTH'] as $private) self::assertStringNotContainsString($private, $saved);
        $store->release();
    }

    public function testNative406ContinuationSubmitsOnlyTheCodeAndBoundFirstFactor(): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([self::key(), self::twoFactor(), self::success()], [self::identity()]);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_two_factor_required');
        self::assertSame('awaiting_two_factor', $client->getState());
        self::assertTrue($client->loginChallenge()['ready']);
        self::assertFalse($client->loginChallenge()['browser_continuation_available']);
        self::assertSame([], $client->loginChallenge()['methods']);
        self::assertNull($store->loadCookies());
        self::assertNull($store->get('account_id'));
        self::assertCount(2, $native->requests);
        $firstPassword = $native->requests[1]['form']['password'];
        $client->confirmLogin('091827');
        self::assertCount(3, $native->requests);
        $code = $native->requests[2]['form'];
        self::assertSame(self::EMAIL, $code['email']);
        self::assertSame('two_factor', $code['credentials_type']);
        self::assertSame('091827', $code['password']);
        self::assertSame('091827', $code['twofactor_code']);
        self::assertSame(self::UID, $code['userid']);
        self::assertSame('SYNTHETIC_FIRST_FACTOR', $code['first_factor']);
        self::assertSame('SYNTHETIC_MACHINE', $code['machine_id']);
        self::assertArrayNotHasKey('generate_machine_id', $code);
        self::assertSame($native->requests[1]['form']['device_id'], $code['device_id']);
        self::assertSame($native->requests[1]['form']['adid'], $code['adid']);
        self::assertStringNotContainsString(self::PASSWORD, json_encode($code, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($firstPassword, json_encode($code, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('SYNTHETIC_UNUSED_AUTH_TOKEN', json_encode($code, JSON_THROW_ON_ERROR));
        self::assertCount(1, $web->requests);
        $savedCookies = $store->get('cookies');
        self::assertIsString($savedCookies);
        self::assertSame(self::UID, CookieJar::fromJson($savedCookies)->accountId());
        self::assertNull($client->loginChallenge());
        $store->acquire();
        self::assertSame('SYNTHETIC_MACHINE', (new AndroidDeviceIdentity($store))->privateMachineId());
        $public = json_encode($store->events(), JSON_THROW_ON_ERROR) . print_r($client->accountInfo(), true);
        foreach ([self::PASSWORD, '091827', 'SYNTHETIC_FIRST_FACTOR', 'SYNTHETIC_MACHINE', 'SYNTHETIC_UNUSED_AUTH_TOKEN'] as $private) self::assertStringNotContainsString($private, $public);
        $store->release();
    }

    public function testNativeAppApprovalExchangesOnlyTheBoundTransientTokenAndVerifiesCookies(): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([
            self::key(), self::twoFactor(), new HttpResponse(200, '{"data":[{"approved":false}]}'),
            new HttpResponse(200, '{"data":[{"approved":true}]}'), self::success(),
        ], [self::identity()]);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_two_factor_required');
        $deadline = $client->loginChallenge()['expires_at'];
        self::assertTrue($client->loginChallenge()['can_check']);
        self::assertFalse($client->checkLoginApproval());
        self::assertCount(3, $native->requests, 'A pending approval never exchanges credentials');
        self::assertSame([], $web->requests);
        self::assertNull($store->loadCookies());
        $status = $native->requests[2];
        self::assertSame('GET', $status['method']);
        self::assertSame('https://graph.facebook.com/check_approved_machine', $status['url']);
        self::assertSame(self::UID, $status['form']['u']);
        self::assertSame('SYNTHETIC_MACHINE', $status['form']['m']);
        self::assertArrayNotHasKey('password', $status['form']);
        self::assertArrayNotHasKey('email', $status['form']);
        self::assertFalse($client->loginChallenge()['can_check']);
        self::reason(fn() => $client->checkLoginApproval(), 'login_method_cooldown');
        self::assertCount(3, $native->requests);
        $service = (new ReflectionProperty(MessengerClient::class, 'loginService'))->getValue($client);
        (new ReflectionProperty(AndroidLoginService::class, 'approvalCheckAt'))->setValue($service, 0);
        self::assertTrue($client->checkLoginApproval());
        self::assertCount(5, $native->requests);
        $exchange = $native->requests[4];
        self::assertSame('POST', $exchange['method']);
        self::assertSame('https://graph.facebook.com/auth/login', $exchange['url']);
        self::assertSame(self::UID, $exchange['form']['email']);
        self::assertSame('transient_token', $exchange['form']['credentials_type']);
        self::assertSame('SYNTHETIC_UNUSED_AUTH_TOKEN', $exchange['form']['password']);
        self::assertSame($native->requests[1]['form']['device_id'], $exchange['form']['device_id']);
        foreach (['first_factor', 'twofactor_code', 'userid'] as $field) self::assertArrayNotHasKey($field, $exchange['form']);
        $persisted = $store->get('cookies');self::assertIsString($persisted);
        self::assertSame(self::UID, CookieJar::fromJson($persisted)->accountId());
        self::assertSame('session_imported', $client->getState());
        self::assertNull($client->loginChallenge());
        self::assertFalse($client->accountInfo()['e2ee_ready']);
        self::assertCount(1, $web->requests);
        self::assertNull((new ReflectionProperty(AndroidLoginService::class, 'approval'))->getValue($service));
        $public = json_encode([$client->accountInfo(), $client->events()], JSON_THROW_ON_ERROR) . print_r($service, true);
        $saved = ($store->get('cookies') ?? '') . ($store->get('android.device_identity') ?? '');
        foreach ([self::EMAIL, self::PASSWORD, 'SYNTHETIC_FIRST_FACTOR', 'SYNTHETIC_UNUSED_AUTH_TOKEN'] as $private) {
            self::assertStringNotContainsString($private, $public);
            self::assertStringNotContainsString($private, $saved);
        }
        self::assertGreaterThan(time(), $deadline);
    }

    public function testNativeApprovalWithoutPrivateTokenKeepsCodeFlowButCannotCheckApproval(): void
    {
        $withoutToken = new HttpResponse(400, json_encode(['error' => ['code' => 406, 'error_data' => [
            'uid' => self::UID, 'login_first_factor' => 'SYNTHETIC_FIRST_FACTOR', 'machine_id' => 'SYNTHETIC_MACHINE',
        ]]], JSON_THROW_ON_ERROR));
        [$client, $profile, $native, $web, $store] = $this->client([self::key(), $withoutToken, self::success()], [self::identity()]);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_two_factor_required');
        self::assertTrue($client->loginChallenge()['ready']);
        self::assertFalse($client->loginChallenge()['can_check']);
        self::reason(fn() => $client->checkLoginApproval(), 'login_method_unavailable');
        self::assertCount(2, $native->requests);
        $client->confirmLogin('091827');
        self::assertSame(self::UID, $store->loadCookies()?->accountId());
    }

    public function testApprovalOnly406UsesActualContextWithoutPretendingThatACodeIsReady(): void
    {
        $approvalOnly = new HttpResponse(400, json_encode(['error' => ['code' => 406, 'error_data' => [
            'uid' => self::UID, 'machine_id' => 'SYNTHETIC_MACHINE', 'auth_token' => 'SYNTHETIC_APPROVAL_ONLY',
        ]]], JSON_THROW_ON_ERROR));
        [$client, $profile, $native, $web, $store] = $this->client([
            self::key(), $approvalOnly, new HttpResponse(200, '{"data":[{"approved":true}]}'), self::success(),
        ], [self::identity()]);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_two_factor_required');
        self::assertFalse($client->loginChallenge()['ready']);
        self::assertFalse($client->loginChallenge()['requires_code']);
        self::assertTrue($client->loginChallenge()['can_check']);
        self::assertTrue($client->checkLoginApproval());
        self::assertSame('SYNTHETIC_APPROVAL_ONLY', $native->requests[3]['form']['password']);
        self::assertSame(self::UID, $store->loadCookies()?->accountId());
    }

    public function testApprovalMayUseOnlyAnActualPersistedMachineWhen406OmitsIt(): void
    {
        $root = $this->root();
        $store = $this->store($root);$store->acquire();
        (new AndroidDeviceIdentity($store))->rememberMachineIdIfEmpty('SYNTHETIC_SAVED_MACHINE');
        $profile = new AndroidServiceFixtureProfile();
        $native = new AndroidServiceFixtureTransport([
            self::key(), new HttpResponse(400, json_encode(['error' => ['code' => 406, 'error_data' => [
                'uid' => self::UID, 'login_first_factor' => 'SYNTHETIC_FIRST_FACTOR', 'auth_token' => 'SYNTHETIC_APPROVAL_TOKEN',
            ]]], JSON_THROW_ON_ERROR)), new HttpResponse(200, '{"data":[{"approved":false}]}'),
        ]);
        $service = new AndroidLoginService($store, $profile, $native, new AndroidServiceFixtureHttp([]));
        self::reason(fn() => $service->login(self::EMAIL, self::PASSWORD), 'login_two_factor_required');
        self::assertTrue($service->challenge()['can_check']);
        self::assertNull($service->checkApproval());
        self::assertSame('SYNTHETIC_SAVED_MACHINE', $native->requests[2]['form']['m']);
        $service->cancel();$store->release();
    }

    public function testApprovalUsesTheReturnedMachineWithoutReplacingTheSavedDeviceOrResettingCooldown(): void
    {
        $root = $this->root();$store = $this->store($root);$store->acquire();
        $device = new AndroidDeviceIdentity($store);$device->rememberMachineIdIfEmpty('SYNTHETIC_MACHINE_A');
        $profile = new AndroidServiceFixtureProfile();
        $native = new AndroidServiceFixtureTransport([
            self::key(), self::twoFactor(machine: 'SYNTHETIC_MACHINE_B'),
            new HttpResponse(200, '{"data":[{"approved":true}]}'), self::twoFactor(machine: 'SYNTHETIC_MACHINE_B'),
        ]);
        $service = new AndroidLoginService($store, $profile, $native, new AndroidServiceFixtureHttp([]));
        self::reason(fn() => $service->login(self::EMAIL, self::PASSWORD), 'login_two_factor_required');
        $deadline = $service->challenge()['expires_at'];
        self::reason(fn() => $service->checkApproval(), 'login_two_factor_required');
        self::assertSame('SYNTHETIC_MACHINE_B', $native->requests[2]['form']['m']);
        self::assertSame('SYNTHETIC_MACHINE_B', $native->requests[3]['form']['machine_id']);
        self::assertSame('SYNTHETIC_MACHINE_A', $device->privateMachineId());
        self::assertSame($deadline, $service->challenge()['expires_at']);
        self::assertFalse($service->challenge()['can_check']);
        self::reason(fn() => $service->checkApproval(), 'login_method_cooldown');
        self::assertCount(4, $native->requests);
        $service->cancel();$store->release();
    }

    #[DataProvider('uncertainConfirmations')]
    public function testUncertainTransientExchangeCannotReplayTokenOrFallBackToCode(HttpResponse|Throwable $outcome): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([
            self::key(), self::twoFactor(), new HttpResponse(200, '{"data":[{"approved":true}]}'), $outcome,
        ], []);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_two_factor_required');
        try { $client->checkLoginApproval(); self::fail('Synthetic transient exchange must fail'); }
        catch (MessengerException $error) { self::assertContains($error->reason, ['login_native_transport_failed', 'login_native_response_invalid', 'login_native_http_failed']); }
        self::assertCount(4, $native->requests);
        self::assertFalse($client->loginChallenge()['ready']);
        self::assertFalse($client->loginChallenge()['can_check']);
        self::assertTrue($client->loginChallenge()['outcome_uncertain']);
        self::reason(fn() => $client->checkLoginApproval(), 'login_approval_outcome_uncertain');
        self::reason(fn() => $client->confirmLogin('091827'), 'login_code_outcome_uncertain');
        self::assertCount(4, $native->requests);
        self::assertSame([], $web->requests);
        self::assertNull($store->loadCookies());
    }

    public function testApprovalCannotAuthenticateAnotherAccountOrSkipIndependentWebIdentity(): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([
            self::key(), self::twoFactor(), new HttpResponse(200, '{"data":[{"approved":true}]}'), self::success(),
        ], [self::identity('99999999999999999999')]);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_two_factor_required');
        self::reason(fn() => $client->checkLoginApproval(), 'login_identity_mismatch');
        self::assertCount(4, $native->requests);
        self::assertCount(1, $web->requests);
        self::assertNull($store->loadCookies());
        self::assertNull($store->get('account_id'));
        self::assertNull($client->loginChallenge());
    }

    #[DataProvider('uncertainConfirmations')]
    public function testUncertainCodeOutcomeCannotBeResubmittedAutomaticallyOrExplicitly(HttpResponse|Throwable $outcome): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([self::key(), self::twoFactor(), $outcome], []);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_two_factor_required');
        try { $client->confirmLogin('091827'); self::fail('The confirmation fixture must fail'); }
        catch (MessengerException $error) { self::assertContains($error->reason, ['login_native_transport_failed', 'login_native_response_invalid', 'login_native_http_failed']); }
        self::assertCount(3, $native->requests);
        self::assertTrue($client->loginChallenge()['outcome_uncertain']);
        self::assertFalse($client->loginChallenge()['ready']);
        self::reason(fn() => $client->confirmLogin('091827'), 'login_code_outcome_uncertain');
        self::assertCount(3, $native->requests);
        self::assertSame([], $web->requests);
        self::assertNull($store->loadCookies());
        $client->stop();
        self::assertNull($client->loginChallenge());
        $store->acquire();
        $store->release();
    }

    /** @return iterable<string,array{HttpResponse|Throwable}> */
    public static function uncertainConfirmations(): iterable
    {
        yield 'transport timeout' => [new MessengerException('login_native_transport_failed', 'Synthetic timeout after submission')];
        yield 'malformed response' => [new HttpResponse(200, '{SYNTHETIC_INVALID_JSON')];
        yield 'unclassified upstream failure' => [new HttpResponse(503, '{"unconfirmed":true}')];
    }

    #[DataProvider('failedKeyResponses')]
    public function testFailedKeyPreflightNeverSendsAnAccountPassword(HttpResponse $response, string $reason): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([$response], []);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), $reason);
        self::assertCount(1, $native->requests);
        self::assertSame('/pwd_key_fetch', parse_url($native->requests[0]['url'], PHP_URL_PATH));
        self::assertArrayNotHasKey('password', $native->requests[0]['form']);
        self::assertArrayNotHasKey('email', $native->requests[0]['form']);
        self::assertSame([], $web->requests);
        self::assertNull($store->loadCookies());
        self::assertNull($client->loginChallenge());
        $store->acquire();
        $store->release();
    }

    /** @return iterable<string,array{HttpResponse,string}> */
    public static function failedKeyResponses(): iterable
    {
        yield 'denied app capability' => [new HttpResponse(400, '{"error":{"code":10}}'), 'login_native_profile_rejected'];
        yield 'error wrapped in HTTP 200' => [new HttpResponse(200, '{"error":{"code":10}}'), 'login_native_key_invalid'];
        yield 'invalid JSON' => [new HttpResponse(200, '{malformed'), 'login_native_key_invalid'];
        yield 'invalid key shape' => [new HttpResponse(200, '{"key_id":1,"public_key":"SYNTHETIC_INVALID_KEY","seconds_to_live":600}'), 'login_native_key_invalid'];
        yield 'zero TTL' => [new HttpResponse(200, '{"key_id":1,"public_key":"SYNTHETIC_INVALID_KEY","seconds_to_live":0}'), 'login_native_key_invalid'];
    }

    public function testConstructorAndUnlockedLoginCannotCallProfileOrTransport(): void
    {
        $store = $this->store($this->root());
        $profile = new AndroidServiceFixtureProfile();
        $native = new AndroidServiceFixtureTransport([]);
        $web = new AndroidServiceFixtureHttp([]);
        $service = new AndroidLoginService($store, $profile, $native, $web);
        self::assertSame(0, $profile->callbacks);
        self::assertNull($store->get('android.device_identity'));
        try { $service->login(self::EMAIL, self::PASSWORD); self::fail('Owner is required'); }
        catch (LogicException $error) { self::assertStringContainsString('owner', $error->getMessage()); }
        self::assertSame(0, $profile->callbacks);
        self::assertSame([], $native->requests);
        self::assertSame([], $web->requests);
        self::assertNull($store->get('android.device_identity'));
        try { $service->prepare(); self::fail('Preflight also requires ownership'); }
        catch (LogicException $error) { self::assertStringContainsString('owner', $error->getMessage()); }
        self::assertSame(0, $profile->callbacks);
        self::assertSame([], $native->requests);
    }

    public function testPrepareIsCredentialFreeAndLoginRefetchesInsteadOfCachingAStaleKey(): void
    {
        $store = $this->store($this->root());
        $store->acquire();
        $guard = static fn() => self::assertTrue($store->ownsLock());
        $profile = new AndroidServiceFixtureProfile($guard);
        $native = new AndroidServiceFixtureTransport([self::key(), self::key(), self::success()], $guard);
        $web = new AndroidServiceFixtureHttp([self::identity()], $guard);
        $service = new AndroidLoginService($store, $profile, $native, $web, allowWebCheckpoint:true);
        $key = $service->prepare();
        self::assertSame(237, $key->id);
        self::assertSame(self::$public, $key->publicKey());
        self::assertCount(1, $native->requests);
        self::assertSame('POST', $native->requests[0]['method']);
        self::assertSame('GET', $native->requests[0]['form']['method']);
        self::assertArrayNotHasKey('email', $native->requests[0]['form']);
        self::assertArrayNotHasKey('password', $native->requests[0]['form']);
        self::assertSame([], $web->requests);
        self::assertNull($store->loadCookies());
        self::assertNull($service->challenge());
        $cookies = $service->login(self::EMAIL, self::PASSWORD);
        self::assertSame(self::UID, $cookies->accountId());
        self::assertCount(3, $native->requests);
        self::assertSame('/pwd_key_fetch', parse_url($native->requests[1]['url'], PHP_URL_PATH));
        self::assertSame($native->requests[0]['form']['device_id'], $native->requests[1]['form']['device_id']);
        self::assertNull($store->loadCookies(), 'Only the owning client imports a verified service result');
    }

    public function testUncertainInitialPasswordPostHasNoAutomaticRetryOrReusableChallenge(): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([self::key(), new MessengerException('login_native_transport_failed', 'Synthetic initial timeout')], []);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_native_transport_failed');
        self::assertCount(2, $native->requests);
        self::assertSame([], $web->requests);
        self::assertNull($client->loginChallenge());
        self::assertNull($store->loadCookies());
        self::reason(fn() => $client->confirmLogin('091827'), 'login_challenge_expired');
        self::assertCount(2, $native->requests);
    }

    public function testConflictingClientOwnerRejectsBeforeAnyProfileCallbackOrRequest(): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([], []);
        $store->acquire();
        try { self::legacyLogin($client,$profile,$native,$web); self::fail('Expected competing owner'); }
        catch (RuntimeException $error) { self::assertStringContainsString('active owner', $error->getMessage()); }
        self::assertSame(0, $profile->callbacks);
        self::assertSame([], $native->requests);
        self::assertSame([], $web->requests);
        self::assertNull($store->get('android.device_identity'));
        self::assertNull($store->loadCookies());
        $store->release();
    }

    public function testExpiredAndCancelledChallengesCannotSubmitCodesOrKeepTheAccountPassword(): void
    {
        $store = $this->store($this->root());
        $store->acquire();
        $profile = new AndroidServiceFixtureProfile();
        $native = new AndroidServiceFixtureTransport([self::key(), self::twoFactor()]);
        $web = new AndroidServiceFixtureHttp([]);
        $service = new AndroidLoginService($store, $profile, $native, $web);
        self::reason(fn() => $service->login(self::EMAIL, self::PASSWORD), 'login_two_factor_required');
        self::assertStringNotContainsString(self::PASSWORD, print_r($service, true));
        self::reason(fn() => $service->prepare(), 'login_verification_pending');
        self::reason(fn() => $service->refreshMethods(), 'login_method_unavailable');
        self::reason(fn() => $service->requestMethod('invented-method'), 'login_method_unavailable');
        (new ReflectionProperty(AndroidLoginService::class, 'expiresAt'))->setValue($service, time() - 1);
        self::assertNull($service->challenge());
        self::reason(fn() => $service->confirm('091827'), 'login_challenge_expired');
        self::assertCount(2, $native->requests);
        self::assertNull((new ReflectionProperty(AndroidLoginService::class, 'email'))->getValue($service));
        self::assertNull((new ReflectionProperty(AndroidLoginService::class, 'twoFactor'))->getValue($service));
        self::assertNull((new ReflectionProperty(AndroidLoginService::class, 'approval'))->getValue($service));
        $native->responses = [self::key(), self::twoFactor()];
        self::reason(fn() => $service->login(self::EMAIL, self::PASSWORD), 'login_two_factor_required');
        $service->cancel();
        self::assertNull($service->challenge());
        self::reason(fn() => $service->confirm('091827'), 'login_challenge_expired');
        self::assertCount(4, $native->requests);
        self::assertNull($store->loadCookies());
    }

    public function testNative401AndBareHttp406DoNotInventTwoFactorState(): void
    {
        foreach ([new HttpResponse(400, '{"error":{"code":401,"error_data":{"uid":"123","login_first_factor":"SYNTHETIC_FACTOR"}}}'), new HttpResponse(406, '{"unconfirmed":true}')] as $response) {
            [$client, $profile, $native, $web, $store] = $this->client([self::key(), $response], []);
            $expected = $response->status === 406 ? 'login_native_http_failed' : 'login_native_rejected';
            self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), $expected);
            self::assertNull($client->loginChallenge());
            self::reason(fn() => $client->confirmLogin('091827'), 'login_challenge_expired');
            self::assertCount(2, $native->requests);
            self::assertSame([], $web->requests);
            self::assertNull($store->loadCookies());
        }
    }

    public function testNative406WithoutFirstFactorCannotSubmitAUsableContinuation(): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([self::key(), new HttpResponse(400, '{"error_code":406,"error_data":{"uid":"123"}}')], []);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_native_verification_unavailable');
        self::assertNull($client->loginChallenge());
        self::reason(fn() => $client->confirmLogin('091827'), 'login_challenge_expired');
        self::assertCount(2, $native->requests);
        self::assertSame([], $web->requests);
        self::assertNull($store->loadCookies());
    }

    #[DataProvider('switchedFactorAccounts')]
    public function testFirstFactorUidCannotChangeInFinalSuccessOrRepeated406(HttpResponse $switched): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([self::key(), self::twoFactor(), $switched], []);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_two_factor_required');
        self::reason(fn() => $client->confirmLogin('091827'), 'login_identity_mismatch');
        self::assertCount(3, $native->requests);
        self::assertSame(self::UID, $native->requests[2]['form']['userid']);
        self::assertSame([], $web->requests);
        self::assertNull($store->loadCookies());
        self::assertNull($store->get('account_id'));
        self::assertNull($client->loginChallenge());
        self::reason(fn() => $client->confirmLogin('091827'), 'login_challenge_expired');
        self::assertCount(3, $native->requests);
        $store->acquire();
        $store->release();
    }

    /** @return iterable<string,array{HttpResponse}> */
    public static function switchedFactorAccounts(): iterable
    {
        yield 'final native success changes account' => [self::success('99999999999999999999')];
        yield 'repeated 406 changes account' => [self::twoFactor('99999999999999999999')];
    }

    public function testPendingNativeChallengeSurvivesRejectedAndroidAndAlternativeLoginRestarts(): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([self::key(), self::twoFactor(), self::success()], [self::identity()]);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_two_factor_required');
        $callbacks = $profile->callbacks;
        $replacementProfile = new AndroidServiceFixtureProfile();
        $replacementNative = new AndroidServiceFixtureTransport([]);
        self::reason(fn() => $client->loginAndroid('other@example.invalid', 'SYNTHETIC_REPLACEMENT_PASSWORD', $replacementProfile, $replacementNative), 'login_verification_pending');
        $other = new class implements \MVO\Messenger\Auth\LoginService {
            public int $attempts = 0;
            public function login(#[SensitiveParameter] string $email, #[SensitiveParameter] string $password): CookieJar { ++$this->attempts; throw new LogicException('Unexpected alternative fixture login'); }
            public function challenge(): ?array { return null; }
            public function refreshMethods(): array { throw new LogicException('Not offered'); }
            public function requestMethod(string $methodId): array { throw new LogicException('Not offered'); }
            public function checkApproval(): ?CookieJar { throw new LogicException('Not offered'); }
            public function confirm(#[SensitiveParameter] string $code): CookieJar { throw new LogicException('Not offered'); }
            public function cancel(): void {}
            public function exportPrivateChallenge(): array { throw new LogicException('Not offered'); }
        };
        self::reason(fn() => $client->login('other@example.invalid', 'SYNTHETIC_REPLACEMENT_PASSWORD', $other), 'login_verification_pending');
        self::assertSame(0, $other->attempts);
        self::assertSame(0, $replacementProfile->callbacks);
        self::assertSame([], $replacementNative->requests);
        self::assertSame($callbacks, $profile->callbacks);
        self::assertCount(2, $native->requests);
        self::assertTrue($client->loginChallenge()['ready']);
        self::assertSame([], $web->requests);
        $client->confirmLogin('091827');
        self::assertCount(3, $native->requests);
        self::assertSame(self::EMAIL, $native->requests[2]['form']['email']);
        $saved = $store->get('cookies');
        self::assertIsString($saved);
        self::assertSame(self::UID, CookieJar::fromJson($saved)->accountId());
    }

    public function testRepeatedBound406RefreshesFirstFactorWithoutReplacingStoredMachineOrRetryingItself(): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([self::key(), self::twoFactor(), self::twoFactor(self::UID, 'SYNTHETIC_REFRESHED_FACTOR', 'SYNTHETIC_NEW_MACHINE'), self::success()], [self::identity()]);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_two_factor_required');
        self::reason(fn() => $client->confirmLogin('091827'), 'login_two_factor_required');
        self::assertCount(3, $native->requests);
        self::assertTrue($client->loginChallenge()['ready']);
        self::assertSame([], $web->requests);
        $client->confirmLogin('076543');
        self::assertCount(4, $native->requests);
        $form = $native->requests[3]['form'];
        self::assertSame('076543', $form['password']);
        self::assertSame('076543', $form['twofactor_code']);
        self::assertSame('SYNTHETIC_REFRESHED_FACTOR', $form['first_factor']);
        self::assertSame('SYNTHETIC_MACHINE', $form['machine_id']);
        self::assertSame(self::UID, $form['userid']);
        self::assertCount(1, $web->requests);
        $store->acquire();
        self::assertSame('SYNTHETIC_MACHINE', (new AndroidDeviceIdentity($store))->privateMachineId());
        self::assertStringNotContainsString('SYNTHETIC_REFRESHED_FACTOR', ($store->get('cookies') ?? '') . ($store->get('android.device_identity') ?? ''));
        $store->release();
    }

    public function testClientExpirationClearsTheChallengeAndReleasesItsOwnerBeforeAnyFurtherCodeRequest(): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([self::key(), self::twoFactor()], []);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_two_factor_required');
        $service = (new ReflectionProperty(MessengerClient::class, 'loginService'))->getValue($client);
        self::assertInstanceOf(AndroidLoginService::class, $service);
        (new ReflectionProperty(AndroidLoginService::class, 'expiresAt'))->setValue($service, time() - 1);
        self::assertNull($client->loginChallenge());
        self::assertSame('login_challenge_expired', $client->getState());
        self::reason(fn() => $client->confirmLogin('091827'), 'login_challenge_expired');
        self::assertCount(2, $native->requests);
        self::assertSame([], $web->requests);
        self::assertNull($store->loadCookies());
        $store->acquire();
        $store->release();
    }

    #[DataProvider('unconfirmedWebPages')]
    public function testDivergentIdentityAndVisibleInterstitialNeverPersistNativeCookies(HttpResponse $page, string $reason, bool $checkpoint): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([self::key(), self::success()], [$page]);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), $reason);
        self::assertNull($store->loadCookies());
        self::assertNull($store->get('account_id'));
        self::assertCount(2, $native->requests);
        self::assertCount(1, $web->requests);
        if ($checkpoint) {
            self::assertSame('checkpoint', $client->loginChallenge()['type']);
            self::assertFalse($client->loginChallenge()['ready']);
            self::assertTrue($client->loginChallenge()['browser_continuation_available']);
            $projection = json_encode($client->loginChallenge(), JSON_THROW_ON_ERROR);
            foreach ([self::EMAIL, self::PASSWORD, 'SYNTHETIC_WEB_COOKIE', 'SYNTHETIC_NATIVE_TOKEN'] as $private) self::assertStringNotContainsString($private, $projection);
            self::reason(fn() => $client->confirmLogin('091827'), 'login_challenge_expired');
            self::assertCount(2, $native->requests);
        } else self::assertNull($client->loginChallenge());
        $client->stop();
        $store->acquire();
        $store->release();
    }

    /** @return iterable<string,array{HttpResponse,string,bool}> */
    public static function unconfirmedWebPages(): iterable
    {
        yield 'different live identity' => [self::identity('99999999999999999999'), 'login_identity_mismatch', false];
        yield 'no independent identity' => [new HttpResponse(200, '<html>Unconfirmed synthetic page</html>'), 'login_unconfirmed', false];
        yield 'login form with matching uid' => [new HttpResponse(200, self::identity()->body . '<form id="login_form" action="/login/"><input type="password" name="pass"></form>'), 'auth_challenge_required', true];
        yield 'consent form with matching uid' => [new HttpResponse(200, self::identity()->body . '<form action="/privacy/consent/"><input name="accept"></form>'), 'auth_challenge_required', true];
        yield 'checkpoint redirect' => [new HttpResponse(302, '', ['location' => ['/checkpoint/?flow=synthetic']]), 'auth_challenge_required', true];
        yield 'challenge redirect' => [new HttpResponse(302, '', ['location' => ['/challenge/123/']]), 'auth_challenge_required', true];
        yield 'captcha redirect' => [new HttpResponse(302, '', ['location' => ['/captcha/']]), 'auth_challenge_required', true];
        yield 'foreign redirect' => [new HttpResponse(302, '', ['location' => ['https://example.invalid/messages/']]), 'login_unconfirmed', false];
    }

    #[DataProvider('browserChallengePaths')]
    public function testNativeAccountCannotBecomeDifferentBrowserAccountEvenWithMatchingBrowserBootstrap(string $path): void
    {
        $other = '99999999999999999999';
        [$client, $profile, $native, $web, $store] = $this->client([self::key(), self::success()], [new HttpResponse(302, '', ['location' => [$path]]), self::identity($other)]);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'auth_challenge_required');
        $handoff = $client->exportPrivateLoginChallenge();
        self::assertSame('https://www.facebook.com' . $path, $handoff['url']);
        self::assertSame(self::UID, CookieJar::fromJson(json_encode($handoff['cookies'], JSON_THROW_ON_ERROR))->accountId());
        self::assertCount(1, $web->requests);
        self::reason(fn() => $client->completeBrowserLogin(self::browserCookies($other)), 'login_identity_mismatch');
        self::assertCount(1, $web->requests, 'A different native-bound account must fail before browser HTTP');
        self::assertCount(1, $web->responses, 'The different-account bootstrap fixture must remain unused');
        self::assertNull($store->get('cookies'));
        self::assertNull($store->get('account_id'));
        self::assertNotSame('session_imported', $client->getState());
        self::assertCount(2, $native->requests);
        $client->stop();
        $store->acquire();
        $store->release();
    }

    #[DataProvider('browserChallengePaths')]
    public function testSameNativeAccountCanFinishItsBrowserChallengeAfterIndependentWebConfirmation(string $path): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([self::key(), self::success()], [new HttpResponse(302, '', ['location' => [$path]]), self::identity()]);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'auth_challenge_required');
        self::assertSame('checkpoint', $client->loginChallenge()['type']);
        self::assertFalse($client->loginChallenge()['ready']);
        self::assertTrue($client->loginChallenge()['browser_continuation_available']);
        self::assertNull($store->loadCookies());
        $callbacks = $profile->callbacks;
        $client->completeBrowserLogin(self::browserCookies());
        self::assertCount(2, $native->requests, 'Browser completion must not repeat native password/code submission');
        self::assertSame($callbacks, $profile->callbacks);
        self::assertCount(2, $web->requests);
        self::assertSame('https://www.facebook.com/messages/', $web->requests[1]['url']);
        self::assertStringContainsString('xs=SYNTHETIC_BROWSER_CONFIRMED_COOKIE', $web->requests[1]['cookies']);
        self::assertStringNotContainsString('SYNTHETIC_WEB_COOKIE', $web->requests[1]['cookies']);
        $saved = $store->get('cookies');
        self::assertIsString($saved);
        $cookies = CookieJar::fromJson($saved);
        self::assertSame(self::UID, $cookies->accountId());
        self::assertStringContainsString('SYNTHETIC_BROWSER_CONFIRMED_COOKIE', $cookies->headerFor('https://www.facebook.com/'));
        self::assertSame('session_imported', $client->getState());
        self::assertNull($client->loginChallenge());
        self::assertFalse($client->isConnected());
        self::assertFalse($client->accountInfo()['e2ee_ready']);
        $store->acquire();
        self::assertSame(self::UID, $store->get('account_id'));
        $store->release();
    }

    /** @return iterable<string,array{string}> */
    public static function browserChallengePaths(): iterable
    {
        yield 'checkpoint' => ['/checkpoint/?flow=synthetic'];
        yield 'challenge' => ['/challenge/123/'];
        yield 'captcha' => ['/captcha/'];
    }

    #[DataProvider('nativeCheckpointWithoutBrowserContext')]
    public function testNative405WithoutEstablishedBrowserContextCannotCompleteAnyBrowserSession(HttpResponse $response): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([self::key(), $response], [self::identity()]);
        self::reason(fn() => $client->loginAndroid(self::EMAIL, self::PASSWORD, $profile, $native), 'login_native_verification_unsupported');
        self::assertSame('checkpoint', $client->loginChallenge()['type']);
        self::assertFalse($client->loginChallenge()['ready']);
        self::assertFalse($client->loginChallenge()['browser_continuation_available']);
        self::reason(fn() => $client->exportPrivateLoginChallenge(), 'login_native_browser_unavailable');
        self::reason(fn() => $client->completeBrowserLogin(self::browserCookies()), 'login_native_browser_unavailable');
        self::reason(fn() => $client->confirmLogin('091827'), 'login_native_verification_unsupported');
        self::assertSame([], $web->requests);
        self::assertCount(2, $native->requests);
        self::assertNull($store->get('cookies'));
        self::assertNull($store->get('account_id'));
        $client->stop();
        $store->acquire();
        $store->release();
    }

    /** @return iterable<string,array{HttpResponse}> */
    public static function nativeCheckpointWithoutBrowserContext(): iterable
    {
        yield 'bare native 405' => [new HttpResponse(400, '{"error_code":405}')];
        yield 'missing flow' => [new HttpResponse(400, '{"error_code":405,"error_data":{"url":"https://www.facebook.com/checkpoint/"}}')];
        yield 'foreign URL' => [self::checkpoint(url: 'https://facebook.com.evil.invalid/checkpoint/')];
        yield 'insecure URL' => [self::checkpoint(url: 'http://www.facebook.com/checkpoint/')];
        yield 'userinfo' => [self::checkpoint(url: 'https://owner@www.facebook.com/checkpoint/')];
        yield 'fragment' => [self::checkpoint(url: 'https://www.facebook.com/checkpoint/#synthetic')];
        yield 'nonstandard port' => [self::checkpoint(url: 'https://www.facebook.com:8443/checkpoint/')];
        yield 'control character' => [self::checkpoint(url: "https://www.facebook.com/checkpoint/?x=\r\n")];
        yield 'mobile URL outside existing browser allowlist' => [self::checkpoint(url: 'https://m.facebook.com/checkpoint/')];
        yield 'malformed supplied UID' => [self::checkpoint(uid: 'not-an-account')];
    }

    public function testActual405UrlStartsCookieFreeHandoffAndOnlyVerifiedHttpsCanBindTheFirstAccount(): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([self::key(), self::checkpoint()], [self::identity()]);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_checkpoint_required');
        $challenge = $client->loginChallenge();
        self::assertSame('checkpoint', $challenge['type']);
        self::assertFalse($challenge['ready']);
        self::assertFalse($challenge['requires_code']);
        self::assertTrue($challenge['browser_continuation_available']);
        $handoff = $client->exportPrivateLoginChallenge();
        self::assertSame(['url' => 'https://www.facebook.com/checkpoint/?server=SYNTHETIC_NATIVE_URL', 'cookies' => [], 'expires_at' => $challenge['expires_at']], $handoff);
        self::assertSame([], $web->requests, 'Exporting a human handoff must not navigate or poll');
        self::assertNull($store->loadCookies());
        self::assertNull($store->get('account_id'));
        self::reason(fn() => $client->confirmLogin('091827'), 'login_checkpoint_required');
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_verification_pending');
        $callbacks = $profile->callbacks;
        $public = json_encode([$challenge, $client->accountInfo(), $client->events()], JSON_THROW_ON_ERROR);
        $service = (new ReflectionProperty(MessengerClient::class, 'loginService'))->getValue($client);
        self::assertInstanceOf(AndroidLoginService::class, $service);
        $public .= print_r($service, true);
        foreach (['SYNTHETIC_NATIVE_URL', 'SYNTHETIC_NATIVE_FLOW', self::EMAIL, self::PASSWORD] as $private) self::assertStringNotContainsString($private, $public);
        try { serialize($service); self::fail('The checkpoint must stay in RAM'); }
        catch (LogicException) {}
        $client->completeBrowserLogin(self::browserCookies());
        self::assertCount(2, $native->requests, 'No native credential/code replay during human completion');
        self::assertSame($callbacks, $profile->callbacks);
        self::assertCount(1, $web->requests);
        self::assertSame('https://www.facebook.com/messages/', $web->firstRequest()['url']);
        self::assertSame('session_imported', $client->getState());
        self::assertSame(self::UID, $store->get('account_id'));
        $persisted = $store->get('cookies');self::assertIsString($persisted);
        self::assertSame(self::UID, CookieJar::fromJson($persisted)->accountId());
        self::assertNull($client->loginChallenge());
        self::assertFalse($client->isConnected());
        self::assertFalse($client->accountInfo()['e2ee_ready']);
        self::assertNull((new ReflectionProperty(AndroidLoginService::class, 'nativeCheckpoint'))->getValue($service));
        self::assertNull((new ReflectionProperty(AndroidLoginService::class, 'browserUrl'))->getValue($service));
    }

    #[DataProvider('unverified405BrowserResponses')]
    public function testUnknown405UidDoesNotBypassIndependentHttpsVerification(HttpResponse|Throwable $page, string $reason): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([self::key(), self::checkpoint()], [$page, self::identity()]);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_checkpoint_required');
        $handoff = $client->exportPrivateLoginChallenge();
        self::reason(fn() => $client->completeBrowserLogin(self::browserCookies()), $reason);
        self::assertNull($store->get('account_id'));
        self::assertNull($store->loadCookies());
        self::assertSame($handoff, $client->exportPrivateLoginChallenge(), 'A failed check cannot replace or extend the original attempt');
        self::assertCount(1, $web->requests);
        self::assertCount(2, $native->requests);
        $client->completeBrowserLogin(self::browserCookies());
        self::assertSame(self::UID, $store->get('account_id'));
        self::assertCount(2, $web->requests);
        self::assertCount(2, $native->requests);
    }

    /** @return iterable<string,array{HttpResponse|Throwable,string}> */
    public static function unverified405BrowserResponses(): iterable
    {
        yield 'different HTTPS identity' => [self::identity('99999999999999999999'), 'login_identity_mismatch'];
        yield 'unconfirmed document' => [new HttpResponse(200, '<html>Synthetic unconfirmed</html>'), 'login_unconfirmed'];
        yield 'matching ID inside checkpoint' => [new HttpResponse(200, self::identity()->body . '<form action="/checkpoint/"><input name="checkpoint_data"></form>'), 'auth_challenge_required'];
        yield 'challenge redirect' => [new HttpResponse(302, '', ['location' => ['/checkpoint/']]), 'auth_challenge_required'];
        yield 'foreign redirect' => [new HttpResponse(302, '', ['location' => ['https://example.invalid/']]), 'unexpected_auth_redirect'];
        yield 'HTTPS transport failure' => [new MessengerException('transport_failed', 'Synthetic HTTPS failure'), 'transport_failed'];
    }

    #[DataProvider('known405AccountSources')]
    public function test405BrowserCannotReplaceSavedFirstFactorOrActualCheckpointUid(string $source): void
    {
        $nativeResponses = $source === 'first factor' ? [self::key(), self::twoFactor(), self::checkpoint()] : [self::key(), self::checkpoint(uid: $source === 'checkpoint' ? self::UID : null)];
        [$client, $profile, $native, $web, $store] = $this->client($nativeResponses, [self::identity()]);
        $saved = self::browserCookies();
        if ($source === 'saved') $client->importCookies($saved);
        if ($source === 'first factor') {
            self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_two_factor_required');
            $service = (new ReflectionProperty(MessengerClient::class, 'loginService'))->getValue($client);
            self::assertInstanceOf(AndroidLoginService::class, $service);
            $originalExpiry = time() + 45;
            (new ReflectionProperty(AndroidLoginService::class, 'expiresAt'))->setValue($service, $originalExpiry);
            self::reason(fn() => $client->confirmLogin('091827'), 'login_checkpoint_required');
            self::assertSame($originalExpiry, $client->loginChallenge()['expires_at']);
        } else self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_checkpoint_required');
        self::assertSame([], $client->exportPrivateLoginChallenge()['cookies']);
        self::reason(fn() => $client->completeBrowserLogin(self::browserCookies('99999999999999999999')), 'login_identity_mismatch');
        self::assertSame([], $web->requests, 'A conflicting known UID must fail before identity HTTP');
        self::assertSame($source === 'saved' ? $saved->exportPrivate() : null, $store->get('cookies'));
        self::assertSame($source === 'saved' ? self::UID : null, $store->get('account_id'));
        $client->completeBrowserLogin(self::browserCookies());
        self::assertCount(1, $web->requests);
        self::assertCount($source === 'first factor' ? 3 : 2, $native->requests);
        self::assertSame(self::UID, $store->get('account_id'));
    }

    /** @return iterable<string,array{string}> */
    public static function known405AccountSources(): iterable
    {
        yield 'saved account' => ['saved'];
        yield 'previous native 406' => ['first factor'];
        yield 'actual native 405 UID' => ['checkpoint'];
    }

    #[DataProvider('conflicting405AccountSources')]
    public function testConflictingActual405UidCancelsBeforeAnyBrowserHttp(string $source): void
    {
        $nativeResponses = $source === 'first factor' ? [self::key(), self::twoFactor(), self::checkpoint(uid: '99999999999999999999')] : [self::key(), self::checkpoint(uid: '99999999999999999999')];
        [$client, $profile, $native, $web, $store] = $this->client($nativeResponses, []);
        $saved = self::browserCookies();
        if ($source === 'saved') {
            $client->importCookies($saved);
            self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_identity_mismatch');
        } else {
            self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_two_factor_required');
            self::reason(fn() => $client->confirmLogin('091827'), 'login_identity_mismatch');
        }
        self::assertNull($client->loginChallenge());
        self::assertSame([], $web->requests);
        self::assertCount($source === 'first factor' ? 3 : 2, $native->requests);
        self::assertSame($source === 'saved' ? self::UID : null, $store->get('account_id'));
        self::assertSame($source === 'saved' ? $saved->exportPrivate() : null, $store->get('cookies'));
    }

    /** @return iterable<string,array{string}> */
    public static function conflicting405AccountSources(): iterable
    {
        yield 'saved account' => ['saved'];
        yield 'previous 406' => ['first factor'];
    }

    #[DataProvider('checkpointEndings')]
    public function test405CancellationAndExpirationDestroyThePrivateHandoffWithoutIo(string $ending): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([self::key(), self::checkpoint(uid: self::UID)], []);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_checkpoint_required');
        $service = (new ReflectionProperty(MessengerClient::class, 'loginService'))->getValue($client);
        self::assertInstanceOf(AndroidLoginService::class, $service);
        if ($ending === 'expired') (new ReflectionProperty(AndroidLoginService::class, 'expiresAt'))->setValue($service, time() - 1);
        else $client->stop();
        self::assertNull($client->loginChallenge());
        self::reason(fn() => $client->exportPrivateLoginChallenge(), 'login_challenge_expired');
        self::reason(fn() => $client->completeBrowserLogin(self::browserCookies()), 'login_challenge_expired');
        self::reason(fn() => $client->confirmLogin('091827'), 'login_challenge_expired');
        foreach (['nativeCheckpoint', 'browserUrl', 'browserCookies', 'expectedAccountId', 'email', 'twoFactor'] as $field) self::assertNull((new ReflectionProperty(AndroidLoginService::class, $field))->getValue($service));
        self::assertSame([], $web->requests);
        self::assertCount(2, $native->requests);
        self::assertNull($store->loadCookies());
        $store->acquire();$store->release();
    }

    /** @return iterable<string,array{string}> */
    public static function checkpointEndings(): iterable
    {
        yield 'explicit stop' => ['cancelled'];
        yield 'deadline reached' => ['expired'];
    }

    public function test406SuccessFollowedByWebCheckpointDoesNotRenewItsOriginalDeadline(): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([self::key(), self::twoFactor(), self::success()], [new HttpResponse(302, '', ['location' => ['/checkpoint/']])]);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_two_factor_required');
        $service = (new ReflectionProperty(MessengerClient::class, 'loginService'))->getValue($client);
        self::assertInstanceOf(AndroidLoginService::class, $service);
        $originalExpiry = time() + 45;
        (new ReflectionProperty(AndroidLoginService::class, 'expiresAt'))->setValue($service, $originalExpiry);
        self::reason(fn() => $client->confirmLogin('091827'), 'auth_challenge_required');
        self::assertSame($originalExpiry, $client->exportPrivateLoginChallenge()['expires_at']);
        self::assertSame(self::UID, CookieJar::fromJson(json_encode($client->exportPrivateLoginChallenge()['cookies'], JSON_THROW_ON_ERROR))->accountId());
        self::assertNull($store->get('account_id'));
        self::assertCount(3, $native->requests);
        self::assertCount(1, $web->requests);
    }

    public function testSavedAccountMismatchStopsBeforeIndependentHttpAndKeepsOldCookies(): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([self::key(), self::success()], []);
        $old = CookieJar::fromJson('{"c_user":"999","xs":"SYNTHETIC_EXISTING_SESSION"}');
        $client->importCookies($old);
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_identity_mismatch');
        $restored = $store->loadCookies();
        self::assertNotNull($restored);
        self::assertSame('999', $restored->accountId());
        self::assertSame($old->exportPrivate(), $restored->exportPrivate());
        self::assertSame([], $web->requests);
        self::assertCount(2, $native->requests);
    }

    #[DataProvider('protectedProfileFields')]
    public function testProfileCannotReplaceBuiltCredentialDeviceOrLogicalMethodFields(string $path, string $field): void
    {
        [$client, $profile, $native, $web, $store] = $this->client([self::key()], []);
        $profile->replaceOnPath = $path;
        $profile->replaceField = $field;
        self::reason(fn() => self::legacyLogin($client,$profile,$native,$web), 'login_native_profile_invalid');
        self::assertCount($path === '/pwd_key_fetch' ? 0 : 1, $native->requests);
        self::assertSame([], $web->requests);
        self::assertNull($store->loadCookies());
    }

    /** @return iterable<string,array{string,string}> */
    public static function protectedProfileFields(): iterable
    {
        yield 'logical method' => ['/pwd_key_fetch', 'method'];
        yield 'device binding' => ['/auth/login', 'device_id'];
        yield 'password envelope' => ['/auth/login', 'password'];
        yield 'account identifier' => ['/auth/login', 'email'];
    }

    public function testDefaultNative405NeverOffersOrExecutesWebOr406Continuations():void
    {
        [$client,$profile,$native,$web,$store]=$this->client([self::key(),self::checkpoint(uid:self::UID)],[]);
        self::reason(fn()=>$client->loginAndroid(self::EMAIL,self::PASSWORD,$profile,$native),'login_native_verification_unsupported');
        $state=$client->loginChallenge();self::assertIsArray($state);
        self::assertTrue($state['native_only']);self::assertFalse($state['verification_supported']);self::assertSame(405,$state['native_code']);
        self::assertSame('unsupported_native_checkpoint',$state['continuation']);
        foreach(['ready','can_check','can_refresh','requires_code','browser_continuation_available']as$key)self::assertFalse($state[$key]);
        self::assertSame([],$state['methods']);
        foreach([fn()=>$client->refreshLoginMethods(),fn()=>$client->requestLoginMethod('sms'),fn()=>$client->checkLoginApproval(),fn()=>$client->requestLoginSms(),fn()=>$client->confirmLogin('001234')]as$operation){
            self::reason($operation,'login_native_verification_unsupported');
        }
        self::reason(fn()=>$client->exportPrivateLoginChallenge(),'login_native_browser_unavailable');
        self::reason(fn()=>$client->completeBrowserLogin(self::browserCookies()),'login_native_browser_unavailable');
        self::assertCount(2,$native->requests);self::assertSame([],$web->requests);self::assertNull($store->loadCookies());
        self::assertSame($state['expires_at'],$client->loginChallenge()['expires_at']);
        $public=json_encode($state,JSON_THROW_ON_ERROR);
        foreach(['SYNTHETIC_NATIVE_URL','SYNTHETIC_NATIVE_FLOW','SYNTHETIC_NATIVE_PASSWORD',self::EMAIL]as$secret)self::assertStringNotContainsString($secret,$public);
    }

    public function testNativeSmsIsExplicitSingleUseAndDoesNotClaimDeliveryOrSaveSession():void
    {
        [$client,$profile,$native,$web,$store]=$this->client([self::key(),self::twoFactor(),new HttpResponse(200,'{}'),self::success(),new HttpResponse(200,json_encode(['id'=>self::UID],JSON_THROW_ON_ERROR))],[]);
        self::reason(fn()=>$client->loginAndroid(self::EMAIL,self::PASSWORD,$profile,$native),'login_two_factor_required');
        $state=$client->loginChallenge();self::assertTrue($state['can_request_sms']);self::assertSame([],$state['methods']);
        self::assertCount(2,$native->requests,'Login never requests SMS automatically');
        $result=$client->requestLoginSms();
        self::assertSame(['outcome'=>'request_submitted','experimental'=>true,'delivery_confirmed'=>false],$result);
        self::assertSame('POST',$native->requests[2]['method']);
        self::assertSame('https://graph.facebook.com/'.self::UID.'/twofacsms',$native->requests[2]['url']);
        self::assertSame('SYNTHETIC_FIRST_FACTOR',$native->requests[2]['form']['first_factor']);
        foreach(['email','password','twofactor_code','userid','machine_id']as$key)self::assertArrayNotHasKey($key,$native->requests[2]['form']);
        self::assertSame([],$web->requests);self::assertNull($store->loadCookies());self::assertFalse($client->isConnected());
        $after=$client->loginChallenge();self::assertIsArray($after);self::assertFalse($after['can_request_sms']);self::assertSame('submitted',$after['sms_request_state']);
        self::assertSame($state['expires_at'],$after['expires_at']);self::assertSame([],$after['methods']);self::assertTrue($after['ready']);
        self::reason(fn()=>$client->requestLoginSms(),'login_sms_already_requested');self::assertCount(3,$native->requests);
        $client->confirmLogin('001234');self::assertCount(5,$native->requests);
        self::assertSame('001234',$native->requests[3]['form']['twofactor_code']);$saved=$store->get('cookies');self::assertIsString($saved);self::assertSame(self::UID,CookieJar::fromJson($saved)->accountId());self::assertSame('/me',parse_url($native->requests[4]['url'],PHP_URL_PATH));self::assertSame([],$web->requests);
    }

    public function testUncertainSmsCannotBeRepeatedButDoesNotConsumeAnAlreadyReceivedCode():void
    {
        [$client,$profile,$native,$web,$store]=$this->client([self::key(),self::twoFactor(),new MessengerException('login_transport_failed','Synthetic timeout'),self::success(),new HttpResponse(200,json_encode(['id'=>self::UID],JSON_THROW_ON_ERROR))],[]);
        self::reason(fn()=>$client->loginAndroid(self::EMAIL,self::PASSWORD,$profile,$native),'login_two_factor_required');
        self::reason(fn()=>$client->requestLoginSms(),'login_sms_outcome_uncertain');
        $state=$client->loginChallenge();self::assertIsArray($state);self::assertSame('uncertain',$state['sms_request_state']);
        self::assertFalse($state['can_request_sms']);self::assertTrue($state['ready']);self::assertFalse($state['outcome_uncertain']);
        self::reason(fn()=>$client->requestLoginSms(),'login_sms_already_requested');self::assertCount(3,$native->requests);self::assertSame([],$web->requests);
        self::assertNull($store->loadCookies());$client->confirmLogin('001234');self::assertCount(5,$native->requests);
        self::assertSame('two_factor',$native->requests[3]['form']['credentials_type']);self::assertNotNull($store->loadCookies());self::assertSame('/me',parse_url($native->requests[4]['url'],PHP_URL_PATH));self::assertSame([],$web->requests);
    }

    public function testSmsNeedsTheCodeFirstFactorAndCurrentOwner():void
    {
        $approvalOnly=new HttpResponse(400,json_encode(['error'=>['code'=>406,'error_data'=>['uid'=>self::UID,'machine_id'=>'SYNTHETIC_MACHINE','auth_token'=>'SYNTHETIC_APPROVAL']]],JSON_THROW_ON_ERROR));
        [$client,$profile,$native,$web,$store]=$this->client([self::key(),$approvalOnly],[]);
        self::reason(fn()=>$client->loginAndroid(self::EMAIL,self::PASSWORD,$profile,$native),'login_two_factor_required');
        self::assertFalse($client->loginChallenge()['can_request_sms']);self::reason(fn()=>$client->requestLoginSms(),'login_method_unavailable');
        self::assertCount(2,$native->requests);self::assertSame([],$web->requests);$client->stop();
        self::reason(fn()=>$client->requestLoginSms(),'login_challenge_expired');
        $service=new AndroidLoginService($store,$profile,$native,$web);
        try{$service->requestSms();self::fail('An unlocked owner cannot request SMS');}catch(LogicException){}
        self::assertCount(2,$native->requests);
    }

    /** @param list<HttpResponse|Throwable> $responses
     * @param list<HttpResponse|Throwable> $pages
     * @return array{MessengerClient,AndroidServiceFixtureProfile,AndroidServiceFixtureTransport,AndroidServiceFixtureHttp,SessionStore}
     */
    private function client(array $responses, array $pages): array
    {
        $root = $this->root();
        $store = $this->store($root);
        $guard = static function () use ($store): void {
            try { $store->acquire(); $store->release(); self::fail('The client must own its session before profile or HTTP I/O'); }
            catch (RuntimeException $error) { self::assertStringContainsString('active owner', $error->getMessage()); }
        };
        $profile = new AndroidServiceFixtureProfile($guard);
        $native = new AndroidServiceFixtureTransport($responses, $guard);
        $web = new AndroidServiceFixtureHttp($pages, $guard);
        $client = new MessengerClient('tenant', $root, $web);
        $this->clients[] = $client;
        return [$client, $profile, $native, $web, $store];
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mvo-android-service-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $this->roots[] = $root;
        return $root;
    }

    /** Historical compatibility contracts are opt-in. The public loginAndroid
     * default is exercised by the native-only/SMS tests below and AndroidNativeOnlyLoginTest.
     */
    private static function legacyLogin(MessengerClient $client,AndroidServiceFixtureProfile $profile,AndroidServiceFixtureTransport $native,AndroidServiceFixtureHttp $web):void
    {
        $store=(new ReflectionProperty($client,'store'))->getValue($client);
        self::assertInstanceOf(SessionStore::class,$store);
        $client->login(self::EMAIL,self::PASSWORD,new AndroidLoginService($store,$profile,$native,$web,allowWebCheckpoint:true));
    }

    private function store(string $root): SessionStore
    {
        $store = new SessionStore($root, 'tenant');
        $this->stores[] = $store;
        return $store;
    }

    private static function key(): HttpResponse
    {
        return new HttpResponse(200, json_encode(['key_id' => 237, 'public_key' => self::$public, 'seconds_to_live' => 600], JSON_THROW_ON_ERROR));
    }

    private static function success(string $uid = self::UID): HttpResponse
    {
        return new HttpResponse(200, json_encode(['uid' => $uid, 'access_token' => 'SYNTHETIC_NATIVE_TOKEN', 'secret' => 'SYNTHETIC_NATIVE_SECRET', 'session_cookies' => [
            ['name' => 'c_user', 'value' => $uid, 'domain' => '.facebook.com', 'path' => '/', 'secure' => true],
            ['name' => 'xs', 'value' => 'SYNTHETIC_WEB_COOKIE', 'domain' => '.facebook.com', 'path' => '/', 'secure' => true, 'HttpOnly' => true],
        ]], JSON_THROW_ON_ERROR));
    }

    private static function twoFactor(string $uid = self::UID, string $factor = 'SYNTHETIC_FIRST_FACTOR', string $machine = 'SYNTHETIC_MACHINE'): HttpResponse
    {
        return new HttpResponse(400, json_encode(['error' => ['code' => 406, 'error_data' => ['uid' => $uid,
            'login_first_factor' => $factor, 'machine_id' => $machine, 'auth_token' => 'SYNTHETIC_UNUSED_AUTH_TOKEN']]], JSON_THROW_ON_ERROR));
    }

    private static function checkpoint(?string $uid = null, string $url = 'https://www.facebook.com/checkpoint/?server=SYNTHETIC_NATIVE_URL'): HttpResponse
    {
        $data = ['url' => $url, 'flow_id' => 'SYNTHETIC_NATIVE_FLOW'];
        if ($uid !== null) $data['uid'] = $uid;
        return new HttpResponse(400, json_encode(['error_code' => 405, 'error_data' => $data], JSON_THROW_ON_ERROR));
    }

    private static function identity(string $uid = self::UID): HttpResponse
    {
        return new HttpResponse(200, '<script type="application/json">' . json_encode(['define' => [['CurrentUserInitialData', [], ['USER_ID' => $uid], 1]]], JSON_THROW_ON_ERROR) . '</script>');
    }

    private static function browserCookies(string $uid = self::UID): CookieJar
    {
        return CookieJar::fromJson(json_encode([
            ['name' => 'c_user', 'value' => $uid, 'domain' => '.facebook.com', 'path' => '/', 'secure' => true],
            ['name' => 'xs', 'value' => 'SYNTHETIC_BROWSER_CONFIRMED_COOKIE', 'domain' => '.facebook.com', 'path' => '/', 'secure' => true, 'httpOnly' => true],
        ], JSON_THROW_ON_ERROR));
    }

    private static function decrypt(string $encoded): string
    {
        $parts = explode(':', $encoded, 4);
        self::assertSame(['#PWD_MSGR', '2'], array_slice($parts, 0, 2));
        $frame = base64_decode($parts[3], true);
        self::assertIsString($frame);
        self::assertSame("\x01\xed", substr($frame, 0, 2));
        self::assertSame("\x00\x01", substr($frame, 14, 2));
        self::assertTrue(openssl_private_decrypt(substr($frame, 16, 256), $aesKey, self::$private, OPENSSL_PKCS1_PADDING));
        self::assertSame(32, strlen($aesKey));
        $plain = openssl_decrypt(substr($frame, 288), 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, substr($frame, 2, 12), substr($frame, 272, 16), $parts[2]);
        self::assertIsString($plain);
        sodium_memzero($aesKey);
        return $plain;
    }

    private static function reason(callable $operation, string $reason): void
    {
        try { $operation(); self::fail('Expected ' . $reason); }
        catch (MessengerException $error) { self::assertSame($reason, $error->reason); }
    }
}

/** No actual application ID, signing key, attestation or token is represented. */
final class AndroidServiceFixtureProfile implements AndroidLoginProfile
{
    public int $callbacks = 0;
    public ?string $replaceField = null;
    public ?string $replaceOnPath = null;
    /** @var list<array{method:string,path:string,form:array<string,string>}> */ public array $authorized = [];
    public function __construct(private readonly ?Closure $guard = null) {}
    private function mark(): void { ++$this->callbacks; ($this->guard)?->__invoke(); }
    public function origin(): string { $this->mark(); return 'https://graph.facebook.com'; }
    public function passwordVersion(): int { $this->mark(); return 2; }
    public function keyFetchFlow(): string { $this->mark(); return 'CONTROLLER_INITIALIZATION'; }
    public function authorize(string $method, string $path, #[SensitiveParameter] array $form): array
    {
        $this->mark();
        $this->authorized[] = ['method' => $method, 'path' => $path, 'form' => $form];
        if ($this->replaceField !== null && ($this->replaceOnPath === null || $path === $this->replaceOnPath)) $form[$this->replaceField] = 'SYNTHETIC_ILLEGAL_REPLACEMENT';
        $form['access_token'] = 'SYNTHETIC_APP_AUTH';
        return ['form' => $form, 'headers' => ['X-Synthetic-Profile' => 'offline-only']];
    }
}

final class AndroidServiceFixtureTransport implements AndroidLoginTransport
{
    /** @var list<array{method:string,url:string,form:array<string,string>,headers:array<string,string>}> */ public array $requests = [];
    /** @param list<HttpResponse|Throwable> $responses */
    public function __construct(public array $responses, private readonly ?Closure $guard = null) {}
    public function request(string $method, #[SensitiveParameter] string $url, #[SensitiveParameter] array $form = [], #[SensitiveParameter] array $headers = []): HttpResponse
    {
        ($this->guard)?->__invoke();
        $this->requests[] = compact('method', 'url', 'form', 'headers');
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) throw $response;
        if (!$response instanceof HttpResponse) throw new LogicException('Unexpected synthetic native request');
        return $response;
    }
}

final class AndroidServiceFixtureHttp implements HttpClient
{
    /** @var list<array{method:string,url:string,cookies:string,form:array<string,string>}> */ public array $requests = [];
    /** @param list<HttpResponse|Throwable> $responses */
    public function __construct(public array $responses, private readonly ?Closure $guard = null) {}
    /** Read the live recorder after the client operation mutates this fixture.
     * @return array{method:string,url:string,cookies:string,form:array<string,string>}
     */
    public function firstRequest():array
    {
        if($this->requests===[])throw new LogicException('No synthetic Web request was recorded');
        return $this->requests[0];
    }
    public function request(string $method, string $url, CookieJar $cookies, array $form = []): HttpResponse
    {
        ($this->guard)?->__invoke();
        $this->requests[] = ['method' => $method, 'url' => $url, 'cookies' => $cookies->headerFor($url), 'form' => $form];
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) throw $response;
        if (!$response instanceof HttpResponse) throw new LogicException('Unexpected synthetic Web identity request');
        return $response;
    }
}
