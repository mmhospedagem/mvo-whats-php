<?php
declare(strict_types=1);

use MVO\Messenger\Auth\{SessionStore, WebLoginService};
use MVO\Messenger\MessengerClient;
use MVO\Messenger\MessengerException;
use MVO\Messenger\Transport\{WebLoginResponse, WebLoginTransport};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

/** Synthetic, transport-isolated tests: no account, SMS, email or app is contacted. */
final class WebLoginMethodsIntegrationTest extends TestCase
{
    private const ACCOUNT = '100000000000123';
    private const URL = 'https://www.facebook.com/two_step_verification/?flow=two_factor_login&encrypted_context=synthetic-method-private-context&next=%2Fmessages%2F';
    private const GRAPH = 'https://www.facebook.com/api/graphql/';
    private ?string $privateDirectory = null;

    protected function tearDown(): void
    {
        gc_collect_cycles();
        $root = $this->privateDirectory;
        if ($root !== null && is_dir($root)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
            rmdir($root);
        }
    }

    #[DataProvider('codeMethods')]
    public function testSelectedSmsOrEmailCompletesOnlyAfterIndependentIdentity(string $type, string $native, string $contact): void
    {
        $transport = new MethodLoginReplayTransport([...self::startSteps(), self::graph(self::methodsData()),
            self::graph(['xfb_two_step_verification_send_notification' => ['is_success' => true]]),
            self::graph(['xfb_two_factor_login_validate_code' => ['is_code_valid' => true]], self::cookies()),
            ['method' => 'GET', 'url' => 'https://www.facebook.com/messages/', 'response' => new WebLoginResponse(200, self::identity())],
        ]);
        $service = self::pending($transport);
        $state = $service->refreshMethods();
        self::assertSame(['totp', 'sms', 'email', 'app_approval'], array_column($state['methods'], 'type'));
        self::assertFalse(self::hasAuthCookie($transport));
        $method = self::method($state, $type);
        $selected = $service->requestMethod($method['id']);
        self::assertSame($method['id'], $selected['selected_method']);
        self::assertTrue($selected['ready']); self::assertTrue($selected['requires_code']); self::assertFalse($selected['can_check']);
        self::assertFalse(self::hasAuthCookie($transport));
        $send = json_decode($transport->requests[4]['form']['variables'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($native, $send['challenge']); self::assertSame($contact, $send['maskedContactPoint']);
        self::assertReason('login_method_cooldown', static fn () => $service->requestMethod($method['id']));
        self::assertCount(5, $transport->requests);

        $jar = $service->confirm('00123456');
        self::assertSame(self::ACCOUNT, $jar->accountId());
        $code = json_decode($transport->requests[5]['form']['variables'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($native, $code['method']); self::assertSame($contact, $code['maskedContactPoint']);
        self::assertSame('00123456', $code['code']['sensitive_string_value']);
        self::assertNull($service->challenge()); self::assertSame([], $transport->cookies());
        self::assertSame(1, $transport->passwordPosts());
        self::assertCount(7, $transport->requests);
    }

    public static function codeMethods(): iterable
    {
        yield 'SMS' => ['sms', 'SMS', '***1234'];
        yield 'Email' => ['email', 'EMAIL', 'o***@example.test'];
    }

    public function testAppApprovalRequiresExplicitCheckAndIndependentAuthenticatedPage(): void
    {
        $transport = new MethodLoginReplayTransport([...self::startSteps(), self::graph(self::methodsData()),
            self::graph(['xfb_two_step_verification_complete_if_approval_state_allowed' => ['state' => 'PENDING']]),
            self::graph(['xfb_two_step_verification_complete_if_approval_state_allowed' => ['state' => 'ALLOWED']], self::cookies()),
            ['method' => 'GET', 'url' => 'https://www.facebook.com/messages/', 'response' => new WebLoginResponse(200, self::identity())],
        ]);
        $service = self::pending($transport); $state = $service->refreshMethods();
        $state = $service->requestMethod(self::method($state, 'app_approval')['id']);
        self::assertTrue($state['can_check']); self::assertFalse($state['requires_code']);
        self::assertCount(4, $transport->requests, 'Selecting app approval must not invent a code-delivery POST.');
        self::assertReason('login_challenge_unavailable', static fn () => $service->confirm('001234'));
        self::assertNull($service->checkApproval()); self::assertFalse(self::hasAuthCookie($transport));
        self::assertReason('login_approval_cooldown', static fn () => $service->checkApproval());
        $service->requestMethod(self::method($state, 'app_approval')['id']);
        self::assertReason('login_approval_cooldown', static fn () => $service->checkApproval());
        self::assertCount(5, $transport->requests);
        self::expireLocalCooldown($service, 'approvalCheckAt');
        self::expireMethodPoll($service);
        $jar = $service->checkApproval(); self::assertNotNull($jar);
        self::assertSame(self::ACCOUNT, $jar->accountId()); self::assertNull($service->challenge());
        self::assertCount(7, $transport->requests); self::assertSame([], $transport->cookies());
        self::assertSame(1, $transport->passwordPosts());
    }

    public function testRefreshPreservesSelectedSmsAndItsServerCooldown(): void
    {
        $transport = new MethodLoginReplayTransport([...self::startSteps(), self::graph(self::methodsData()),
            self::graph(['xfb_two_step_verification_send_notification' => ['is_success' => true]]),
            self::graph(self::methodsData()),
        ]);
        $service = self::pending($transport); $state = $service->refreshMethods();
        $sms = self::method($state, 'sms'); $service->requestMethod($sms['id']);
        self::expireLocalCooldown($service, 'methodRefreshAt');
        self::expireLocalCooldown($service, 'methodRequestAt');
        $refreshed = $service->refreshMethods();
        self::assertSame($sms['id'], $refreshed['selected_method'], 'Refreshing a picker cannot silently return the user to TOTP.');
        self::assertGreaterThan(500, self::method($refreshed, 'sms')['retry_after']);
        self::assertReason('login_two_factor_cooldown', static fn () => $service->requestMethod($sms['id']));
        self::assertCount(6, $transport->requests, 'A refresh must not erase a successful SMS send cooldown.');
        $service->cancel();
    }

    public function testUnknownSendResultCannotBeReplayedAfterRefreshingMethods(): void
    {
        $transport = new MethodLoginReplayTransport([...self::startSteps(), self::graph(self::methodsData()),
            ['method' => 'POST', 'url' => self::GRAPH, 'error' => new MessengerException('login_transport_failed', 'Synthetic timeout')],
            self::graph(self::methodsData()),
        ]);
        $service = self::pending($transport); $state = $service->refreshMethods();
        self::assertReason('login_method_outcome_uncertain', static fn () => $service->requestMethod(self::method($state, 'sms')['id']));
        self::assertFalse($service->challenge()['ready']); self::assertTrue($service->challenge()['outcome_uncertain']);
        self::expireLocalCooldown($service, 'methodRefreshAt');
        $service->refreshMethods();
        self::assertFalse($service->challenge()['ready']);
        self::assertReason('login_method_outcome_uncertain', static fn () => $service->requestMethod(self::method($service->challenge(), 'email')['id']));
        self::assertReason('login_method_outcome_uncertain', static fn () => $service->confirm('001234'));
        self::assertCount(6, $transport->requests); self::assertSame(1, $transport->passwordPosts());
        $service->cancel();
    }

    public function testRefreshingAnOfferedListCannotKeepAnUnlistedMethodArmed(): void
    {
        $newList = self::methodsData();
        $newList['xfb_two_factor_login_methods']['methods'] = [['method' => 'TOTP']];
        $transport = new MethodLoginReplayTransport([...self::startSteps(), self::graph(self::methodsData()),
            self::graph(['xfb_two_step_verification_send_notification' => ['is_success' => true]]),
            self::graph($newList),
        ]);
        $service = self::pending($transport); $state = $service->refreshMethods();
        $service->requestMethod(self::method($state, 'sms')['id']);
        self::expireLocalCooldown($service, 'methodRefreshAt');
        $state = $service->refreshMethods();
        self::assertNull($state['selected_method']);
        self::assertFalse($state['ready'], 'A removed SMS method cannot remain armed in a stale code spec.');
        self::assertFalse($state['requires_code']);
        self::assertReason('login_challenge_unavailable', static fn () => $service->confirm('00123456'));
        self::assertCount(6, $transport->requests);
        $service->cancel();
    }

    public function testUnknownOtpResultCannotBeRearmedByMethodDiscovery(): void
    {
        $transport = new MethodLoginReplayTransport([...self::startSteps(),
            ['method' => 'POST', 'url' => self::GRAPH, 'error' => new MessengerException('login_transport_failed', 'Synthetic timeout')],
            self::graph(self::methodsData()),
        ]);
        $service = self::pending($transport);
        self::assertReason('login_two_factor_outcome_uncertain', static fn () => $service->confirm('001234'));
        self::assertFalse($service->challenge()['ready']);
        try { $service->refreshMethods(); } catch (MessengerException) { /* A consumed attempt may refuse refresh entirely. */ }
        self::assertFalse($service->challenge()['ready'], 'Discovery must not arm a second confirmation after uncertain OTP execution.');
        $before = count($transport->requests);
        try { $service->confirm('001234'); self::fail('An uncertain OTP must not be repeated.'); }
        catch (MessengerException) { self::assertCount($before, $transport->requests); }
        self::assertSame(1, $transport->passwordPosts());
        $service->cancel();
    }

    public function testArbitraryUnlistedMethodNeverTouchesNetwork(): void
    {
        $transport = new MethodLoginReplayTransport([...self::startSteps(), self::graph(self::methodsData())]);
        $service = self::pending($transport); $service->refreshMethods();
        self::assertReason('login_method_unavailable', static fn () => $service->requestMethod('sms'));
        self::assertCount(4, $transport->requests); self::assertFalse(self::hasAuthCookie($transport));
        $public = json_encode($service->challenge(), JSON_THROW_ON_ERROR) . print_r($service, true);
        foreach (['synthetic-method-private-context', 'synthetic-lsd', 'synthetic-datr', self::URL] as $private) self::assertStringNotContainsString($private, $public);
        $service->cancel();
        self::assertNull($service->challenge()); self::assertSame([], $transport->cookies());
    }

    public function testApprovalWithoutAuthenticatedIdentityIsNeverPersisted(): void
    {
        $root = $this->privateDirectory = sys_get_temp_dir() . '/mvo-method-integration-' . bin2hex(random_bytes(8));
        $client = null;
        try {
            $transport = new MethodLoginReplayTransport([...self::startSteps(), self::graph(self::methodsData()),
                self::graph(['xfb_two_step_verification_complete_if_approval_state_allowed' => ['state' => 'ALLOWED']], self::cookies()),
                ['method' => 'GET', 'url' => 'https://www.facebook.com/messages/', 'response' => new WebLoginResponse(200, '<html>Anonymous continuation</html>')],
                ['method' => 'GET', 'url' => 'https://www.facebook.com/messages/', 'response' => new WebLoginResponse(200, '<html>No identity</html>')],
            ]);
            $service = new WebLoginService($transport);
            $client = new MessengerClient('demo', $root);
            self::assertReason('login_two_factor_required', static function () use (&$client, $service): void { $client->login('owner@example.test', 'SYNTHETIC-PASSWORD-PRIVATE', $service); });
            $state = $client->refreshLoginMethods(); $client->requestLoginMethod(self::method($state, 'app_approval')['id']);
            self::assertFalse($client->accountInfo()['has_saved_session']);
            self::assertReason('login_unconfirmed', static function () use (&$client): void { $client->checkLoginApproval(); });
            self::assertFalse($client->accountInfo()['has_saved_session']);
            $store = new SessionStore($root, 'demo');
            self::assertNull($store->get('cookies')); self::assertNull($store->get('account_id')); unset($store);
            $before = count($transport->requests);
            self::assertReason('login_continuation_required', static function () use (&$client): void { $client->checkLoginApproval(); });
            self::assertCount($before, $transport->requests);
        } finally {
            $client?->stop(); $client = null; gc_collect_cycles();
        }
    }

    public function testAppApprovalFalseThenTruePersistsOnlyTheVerifiedIdentity(): void
    {
        $root = $this->privateDirectory = sys_get_temp_dir() . '/mvo-method-integration-' . bin2hex(random_bytes(8));
        $client = null;
        try {
            $transport = new MethodLoginReplayTransport([...self::startSteps(), self::graph(self::methodsData()),
                self::graph(['xfb_two_step_verification_complete_if_approval_state_allowed' => ['state' => 'PENDING']]),
                self::graph(['xfb_two_step_verification_complete_if_approval_state_allowed' => ['state' => 'ALLOWED']], self::cookies()),
                ['method' => 'GET', 'url' => 'https://www.facebook.com/messages/', 'response' => new WebLoginResponse(200, self::identity())],
            ]);
            $service = new WebLoginService($transport);
            $client = new MessengerClient('demo', $root);
            self::assertReason('login_two_factor_required', static function () use (&$client, $service): void { $client->login('owner@example.test', 'SYNTHETIC-PASSWORD-PRIVATE', $service); });
            $state = $client->refreshLoginMethods(); $client->requestLoginMethod(self::method($state, 'app_approval')['id']);
            self::assertFalse($client->checkLoginApproval()); self::assertFalse($client->accountInfo()['has_saved_session']);
            $store = new SessionStore($root, 'demo'); self::assertNull($store->get('cookies')); unset($store);
            self::expireLocalCooldown($service, 'approvalCheckAt'); self::expireMethodPoll($service);
            self::assertTrue($client->checkLoginApproval()); self::assertSame('session_imported', $client->getState());
            self::assertFalse($client->isConnected()); self::assertNull($client->loginChallenge());
            self::assertTrue($client->accountInfo()['has_saved_session']); self::assertSame([], $transport->cookies());
            $store = new SessionStore($root, 'demo'); $store->acquire();
            self::assertSame(self::ACCOUNT, $store->get('account_id')); self::assertSame(self::ACCOUNT, $store->loadCookies()->accountId());
            $store->release(); unset($store);
            self::assertSame(1, $transport->passwordPosts()); self::assertCount(7, $transport->requests);
        } finally {
            $client?->stop(); $client = null; gc_collect_cycles();
        }
    }

    #[DataProvider('terminalApprovalStates')]
    public function testDeniedOrExpiredApprovalReleasesTheOwnerWithoutSavingCookies(string $status, string $reason): void
    {
        $root = $this->privateDirectory = sys_get_temp_dir() . '/mvo-method-integration-' . bin2hex(random_bytes(8));
        $client = null;
        try {
            $transport = new MethodLoginReplayTransport([...self::startSteps(), self::graph(self::methodsData()),
                self::graph(['xfb_two_step_verification_complete_if_approval_state_allowed' => ['state' => $status]]),
            ]);
            $service = new WebLoginService($transport); $client = new MessengerClient('demo', $root);
            self::assertReason('login_two_factor_required', static function () use (&$client, $service): void { $client->login('owner@example.test', 'SYNTHETIC-PASSWORD-PRIVATE', $service); });
            $state = $client->refreshLoginMethods(); $client->requestLoginMethod(self::method($state, 'app_approval')['id']);
            self::assertReason($reason, static function () use (&$client): void { $client->checkLoginApproval(); });
            self::assertNull($client->loginChallenge()); self::assertSame([], $transport->cookies());
            self::assertFalse($client->accountInfo()['has_saved_session']);
            $store = new SessionStore($root, 'demo'); $store->acquire();
            self::assertNull($store->loadCookies()); $store->release(); unset($store);
        } finally { $client?->stop(); $client = null; gc_collect_cycles(); }
    }

    public static function terminalApprovalStates(): iterable
    {
        yield 'denied' => ['DENIED', 'login_two_factor_approval_denied'];
        yield 'expired' => ['EXPIRED', 'login_challenge_expired'];
    }

    public function testExpiredMethodAttemptCannotRequestOrPollAndClearsItsJar(): void
    {
        $transport = new MethodLoginReplayTransport([...self::startSteps(), self::graph(self::methodsData())]);
        $service = self::pending($transport); $state = $service->refreshMethods();
        (new ReflectionProperty($service, 'challengeExpiresAt'))->setValue($service, time() - 1);
        self::assertReason('login_challenge_expired', static fn () => $service->requestMethod(self::method($state, 'sms')['id']));
        self::assertReason('login_challenge_expired', static fn () => $service->checkApproval());
        self::assertNull($service->challenge()); self::assertSame([], $transport->cookies()); self::assertCount(4, $transport->requests);
    }

    private static function pending(MethodLoginReplayTransport $transport): WebLoginService
    {
        $service = new WebLoginService($transport);
        self::assertReason('login_two_factor_required', static fn () => $service->login('owner@example.test', 'SYNTHETIC-PASSWORD-PRIVATE'));
        self::assertSame('two_factor', $service->challenge()['type']);
        self::assertGreaterThan(time(), $service->challenge()['expires_at']);
        return $service;
    }

    private static function assertReason(string $reason, callable $call): void
    {
        try { $call(); self::fail('Expected rejected synthetic continuation.'); }
        catch (MessengerException $error) { self::assertSame($reason, $error->reason); }
    }

    private static function method(array $state, string $type): array
    {
        foreach ($state['methods'] as $method) if ($method['type'] === $type) return $method;
        throw new LogicException('Synthetic method missing: ' . $type);
    }

    private static function expireLocalCooldown(WebLoginService $service, string $property): void
    {
        (new ReflectionProperty($service, $property))->setValue($service, 0);
    }

    private static function expireMethodPoll(WebLoginService $service): void
    {
        $property = new ReflectionProperty($service, 'methodSpec');
        $spec = $property->getValue($service); $spec['next_poll_at'] = 0.0; $property->setValue($service, $spec);
    }

    private static function hasAuthCookie(MethodLoginReplayTransport $transport): bool
    {
        return in_array('c_user', array_column($transport->cookies(), 'name'), true);
    }

    private static function startSteps(): array
    {
        $key = bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_seed_keypair(str_repeat("\x08", 32))));
        $login = '<form id="login_form" action="/login/password/" method="post"><input name="email"><input type="password" name="pass"><input type="hidden" name="lsd" value="synthetic-login-lsd"></form><script type="application/json">'
            . json_encode(['require' => [['MWLoginEncryptPasswordOnSubmit', 'init', [], [['keyId' => 1, 'publicKey' => $key]]]]], JSON_THROW_ON_ERROR) . '</script>';
        return [['method' => 'GET', 'url' => 'https://www.messenger.com/', 'response' => new WebLoginResponse(200, $login)],
            ['method' => 'POST', 'url' => 'https://www.messenger.com/login/password/', 'response' => new WebLoginResponse(302, '', ['location' => [self::URL]])],
            ['method' => 'GET', 'url' => self::URL, 'response' => new WebLoginResponse(200, self::challengePage()), 'cookies' => [['name' => 'datr', 'value' => 'synthetic-datr', 'domain' => '.facebook.com']]],
        ];
    }

    private static function graph(array $data, ?array $cookies = null): array
    {
        $step = ['method' => 'POST', 'url' => self::GRAPH, 'response' => new WebLoginResponse(200, json_encode(['data' => $data], JSON_THROW_ON_ERROR))];
        if ($cookies !== null) $step['cookies'] = $cookies;
        return $step;
    }

    private static function challengePage(): string
    {
        $data = ['define' => [['LSD', [], ['token' => 'synthetic-lsd'], 1], ['RelayAPIConfigDefaults', [], ['graphURI' => '/api/graphql/'], 2], ['CurrentUserInitialData', [], ['USER_ID' => '0'], 3]],
            'payload' => json_encode(['data' => ['xfb_two_factor_login_default_method' => ['method' => ['method' => 'TOTP']]]], JSON_THROW_ON_ERROR)];
        $operations = '';
        foreach (['TwoStepVerificationChallengePickerDialogQuery', 'useTwoStepVerificationSendCodeMutation', 'useTwoFactorLoginValidateCodeMutation', 'TwoStepVerificationUpdateLoginMutation'] as $index => $operation) {
            $operations .= '__d("' . $operation . '_facebookRelayOperation",[],(function(t,n,r,o,a,i){a.exports="2712345678901234' . $index . '"}),null);';
        }
        return '<script type="application/json">' . json_encode($data, JSON_THROW_ON_ERROR) . '</script><script>' . $operations . '</script>';
    }

    private static function methodsData(): array
    {
        return ['xfb_two_factor_login_methods' => ['methods' => [['method' => 'TOTP'], ['method' => 'SMS', 'method_representation' => '***1234'], ['method' => 'EMAIL', 'method_representation' => 'o***@example.test'], ['method' => 'APPROVE_FROM_ANOTHER_DEVICE']]],
            'xfb_two_step_verification_send_rate_limit_cooldown' => ['rate_limits' => [['challenge' => 'SMS', 'cooldown_seconds' => 600]]],
            'xfb_two_step_verification_afad_polling_interval' => ['polling_interval' => 7],
        ];
    }

    private static function cookies(): array
    {
        return [['name' => 'c_user', 'value' => self::ACCOUNT, 'domain' => '.facebook.com'], ['name' => 'xs', 'value' => 'synthetic-final-xs', 'domain' => '.facebook.com']];
    }

    private static function identity(): string
    {
        return '<script type="application/json">' . json_encode(['define' => [['CurrentUserInitialData', [], ['USER_ID' => self::ACCOUNT], 1]]], JSON_THROW_ON_ERROR) . '</script>';
    }
}

final class MethodLoginReplayTransport implements WebLoginTransport
{
    public array $requests = [];
    private array $jar = [];
    public function __construct(private array $steps) {}
    public function request(string $method, #[SensitiveParameter] string $url, #[SensitiveParameter] array $form = []): WebLoginResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'form' => $form];
        $step = array_shift($this->steps);
        if ($step === null) throw new LogicException('Unexpected HTTP beyond synthetic method replay.');
        TestCase::assertSame($step['method'], $method); TestCase::assertSame($step['url'], $url);
        if (isset($step['cookies'])) $this->jar = $step['cookies'];
        if (isset($step['error'])) throw $step['error'];
        return $step['response'];
    }
    public function cookies(): array { return $this->jar; }
    public function clear(): void { $this->jar = []; }
    public function passwordPosts(): int
    {
        $posts = array_filter($this->requests, static fn (array $r): bool => isset($r['form']['pass']) || isset($r['form']['encpass']));
        foreach ($posts as $post) TestCase::assertStringStartsWith('#PWD_BROWSER:5:', $post['form']['pass'] ?? $post['form']['encpass']);
        TestCase::assertStringNotContainsString('SYNTHETIC-PASSWORD-PRIVATE', json_encode($this->requests, JSON_THROW_ON_ERROR));
        return count($posts);
    }
}
