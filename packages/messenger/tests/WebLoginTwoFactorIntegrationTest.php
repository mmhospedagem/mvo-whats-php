<?php
declare(strict_types=1);

use MVO\Messenger\Auth\WebLoginService;
use MVO\Messenger\MessengerException;
use MVO\Messenger\Transport\{WebLoginResponse, WebLoginTransport};
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

/** Synthetic HTTPS boundary replay. Never opens a socket or reads a real account. */
final class WebLoginTwoFactorIntegrationTest extends TestCase
{
    private const ACCOUNT = '100000000001';
    private const OTHER_ACCOUNT = '100000000002';
    private const CHALLENGE = 'https://www.facebook.com/two_step_verification/?flow=two_factor_login&encrypted_context=synthetic-current-context&next=%2Fmessages%2F';
    private const MUTATION_URL = 'https://static.xx.fbcdn.net/rsrc.php/v4/synthetic-otp.js';

    public function testNativeGoogleRecaptchaNeverLoadsTotpBundlesOrAcceptsAnAuthenticatorCode(): void
    {
        $url = str_replace('flow=two_factor_login', 'flow=pre_authentication', self::CHALLENGE);
        $transport = new LoginContinuationFixtureTransport(self::baseSteps(self::challengePage('GOOGLE_RECAPTCHA'), $url));
        $loaded = [];
        $service = self::service($transport, $loaded);
        self::assertPending($service, 'login_captcha_required');
        self::assertSame('captcha', $service->challenge()['type']);
        self::assertFalse($service->challenge()['ready']);
        self::assertSame([], $loaded);
        self::assertFailure('login_captcha_required', static fn () => $service->confirm('012345'));
        self::assertCount(3, $transport->requests);
        self::assertPasswordPosts($transport, 1);
        self::assertCount(1, $transport->posts());
        self::assertSame(1, $transport->clearCount);
    }

    public function testGraphqlTotpResumesTheExistingJarAndConfirmsIdentityAfterTheServerContinuation(): void
    {
        $steps = self::baseSteps(self::challengePage());
        $steps[] = self::step('POST', 'https://www.facebook.com/api/graphql/', new WebLoginResponse(200, self::otpResponse(true, self::ACCOUNT)));
        $steps[] = self::step('GET', 'https://www.facebook.com/two_factor/remember_browser/', new WebLoginResponse(200, '<html>Server continuation</html>'), self::authenticatedCookies());
        $steps[] = self::step('GET', 'https://www.facebook.com/messages/', new WebLoginResponse(200, self::identity(self::ACCOUNT)));
        $transport = new LoginContinuationFixtureTransport($steps); $loaded = [];
        $service = self::service($transport, $loaded);
        self::assertPending($service);
        self::assertTrue($service->challenge()['ready']);
        self::assertSame([[self::MUTATION_URL]], $loaded);
        $jar = $service->confirm('012345');
        self::assertSame(self::ACCOUNT, $jar->accountId());
        self::assertNull($service->challenge());
        self::assertSame(2, $transport->clearCount);
        self::assertSame([], $transport->cookies());
        self::assertCount(6, $transport->requests);
        self::assertSame(self::pendingCookies(), $transport->requests[3]['incoming_cookies']);
        self::assertSame(self::pendingCookies(), $transport->requests[4]['incoming_cookies']);
        self::assertSame(self::authenticatedCookies(), $transport->requests[5]['incoming_cookies']);
        $variables = json_decode($transport->requests[3]['form']['variables'], true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('012345', $variables['code']['sensitive_string_value']);
        self::assertSame('synthetic-current-context', $variables['encryptedContext']);
        self::assertFalse($variables['trust_this_device']);
        self::assertArrayNotHasKey('input', $variables);
        self::assertPasswordPosts($transport, 1);
        self::assertCount(2, $transport->posts());
        self::assertFailure('login_challenge_expired', static fn () => $service->confirm('012345'));
        self::assertCount(6, $transport->requests);
    }

    public function testHtmlOtpResumesWithoutAnySecondPasswordPostOrBundleDownload(): void
    {
        $steps = self::baseSteps(self::htmlForm('synthetic-first-nh'));
        $steps[] = self::step('POST', 'https://www.facebook.com/two_factor/confirm/', new WebLoginResponse(302, '', ['location' => ['/two_factor/remember_browser/']]));
        $steps[] = self::step('GET', 'https://www.facebook.com/two_factor/remember_browser/', new WebLoginResponse(200, '<html>No automatic remember-device selection</html>'), self::authenticatedCookies());
        $steps[] = self::step('GET', 'https://www.facebook.com/messages/', new WebLoginResponse(200, self::identity(self::ACCOUNT)));
        $transport = new LoginContinuationFixtureTransport($steps); $loaded = [];
        $service = self::service($transport, $loaded);
        self::assertPending($service);
        self::assertTrue($service->challenge()['ready']);
        $jar = $service->confirm('000123');
        self::assertSame(self::ACCOUNT, $jar->accountId());
        self::assertNull($service->challenge());
        self::assertSame([], $loaded);
        self::assertSame('000123', $transport->requests[3]['form']['approvals_code']);
        self::assertSame('synthetic-first-nh', $transport->requests[3]['form']['nh']);
        self::assertSame(self::pendingCookies(), $transport->requests[3]['incoming_cookies']);
        self::assertPasswordPosts($transport, 1);
        self::assertCount(2, $transport->posts());
    }

    public function testInvalidGraphqlCodePreservesTheContextForAnExplicitNewCodeWithoutAutomaticReplay(): void
    {
        $steps = self::baseSteps(self::challengePage());
        $steps[] = self::step('POST', 'https://www.facebook.com/api/graphql/', new WebLoginResponse(200, self::otpResponse(false)));
        $steps[] = self::step('POST', 'https://www.facebook.com/api/graphql/', new WebLoginResponse(200, self::otpResponse(true, self::ACCOUNT)));
        $steps[] = self::step('GET', 'https://www.facebook.com/two_factor/remember_browser/', new WebLoginResponse(200, '<html>Continue</html>'), self::authenticatedCookies());
        $steps[] = self::step('GET', 'https://www.facebook.com/messages/', new WebLoginResponse(200, self::identity(self::ACCOUNT)));
        $transport = new LoginContinuationFixtureTransport($steps); $loaded = [];
        $service = self::service($transport, $loaded);
        self::assertPending($service);
        self::assertFailure('login_two_factor_invalid_code', static fn () => $service->confirm('012345'));
        self::assertCount(4, $transport->requests);
        self::assertTrue($service->challenge()['ready']);
        self::assertSame(1, $transport->clearCount);
        self::assertSame(self::pendingCookies(), $transport->cookies());
        self::assertSame(self::ACCOUNT, $service->confirm('023456')->accountId());
        self::assertCount(7, $transport->requests);
        self::assertSame([[self::MUTATION_URL]], $loaded);
        self::assertSame(self::pendingCookies(), $transport->requests[4]['incoming_cookies']);
        self::assertSame('023456', json_decode($transport->requests[4]['form']['variables'], true, 64, JSON_THROW_ON_ERROR)['code']['sensitive_string_value']);
        self::assertPasswordPosts($transport, 1);
    }

    public function testInvalidHtmlCodeUsesTheFreshServerFormForTheNextExplicitConfirmation(): void
    {
        $steps = self::baseSteps(self::htmlForm('synthetic-first-nh'));
        $steps[] = self::step('POST', 'https://www.facebook.com/two_factor/confirm/', new WebLoginResponse(200, self::htmlForm('synthetic-refreshed-nh')));
        $steps[] = self::step('POST', 'https://www.facebook.com/two_factor/confirm/', new WebLoginResponse(302, '', ['location' => ['/messages/']]), self::authenticatedCookies());
        $steps[] = self::step('GET', 'https://www.facebook.com/messages/', new WebLoginResponse(200, self::identity(self::ACCOUNT)));
        $steps[] = self::step('GET', 'https://www.facebook.com/messages/', new WebLoginResponse(200, self::identity(self::ACCOUNT)));
        $transport = new LoginContinuationFixtureTransport($steps); $loaded = [];
        $service = self::service($transport, $loaded);
        self::assertPending($service);
        self::assertFailure('login_two_factor_invalid_code', static fn () => $service->confirm('012345'));
        self::assertCount(4, $transport->requests);
        self::assertTrue($service->challenge()['ready']);
        self::assertSame(self::ACCOUNT, $service->confirm('023456')->accountId());
        self::assertSame('synthetic-refreshed-nh', $transport->requests[4]['form']['nh']);
        self::assertSame('023456', $transport->requests[4]['form']['approvals_code']);
        self::assertSame([], $loaded);
        self::assertPasswordPosts($transport, 1);
    }

    public function testInvalidHtmlCodeAfterRedirectPreservesTheRefreshedServerForm(): void
    {
        $steps = self::baseSteps(self::htmlForm('synthetic-first-nh'));
        $steps[] = self::step('POST', 'https://www.facebook.com/two_factor/confirm/', new WebLoginResponse(302, '', ['location' => [self::CHALLENGE]]));
        $steps[] = self::step('GET', self::CHALLENGE, new WebLoginResponse(200, self::htmlForm('synthetic-redirect-refreshed-nh')));
        $steps[] = self::step('POST', 'https://www.facebook.com/two_factor/confirm/', new WebLoginResponse(302, '', ['location' => ['/messages/']]), self::authenticatedCookies());
        $steps[] = self::step('GET', 'https://www.facebook.com/messages/', new WebLoginResponse(200, self::identity(self::ACCOUNT)));
        $steps[] = self::step('GET', 'https://www.facebook.com/messages/', new WebLoginResponse(200, self::identity(self::ACCOUNT)));
        $transport = new LoginContinuationFixtureTransport($steps); $loaded = [];
        $service = self::service($transport, $loaded);
        self::assertPending($service);
        self::assertFailure('login_two_factor_invalid_code', static fn () => $service->confirm('012345'));
        self::assertCount(5, $transport->requests);
        self::assertTrue($service->challenge()['ready']);
        self::assertSame(self::ACCOUNT, $service->confirm('023456')->accountId());
        self::assertSame('synthetic-redirect-refreshed-nh', $transport->requests[5]['form']['nh']);
        self::assertPasswordPosts($transport, 1);
    }

    public function testValidatedGraphqlCodeWithAnotherFinalIdentityCannotCreateASessionOrBeReposted(): void
    {
        $steps = self::baseSteps(self::challengePage());
        $steps[] = self::step('POST', 'https://www.facebook.com/api/graphql/', new WebLoginResponse(200, self::otpResponse(true, self::ACCOUNT)));
        $steps[] = self::step('GET', 'https://www.facebook.com/two_factor/remember_browser/', new WebLoginResponse(200, '<html>Continue</html>'), self::authenticatedCookies(self::OTHER_ACCOUNT));
        $steps[] = self::step('GET', 'https://www.facebook.com/messages/', new WebLoginResponse(200, self::identity(self::OTHER_ACCOUNT)));
        $transport = new LoginContinuationFixtureTransport($steps); $loaded = [];
        $service = self::service($transport, $loaded);
        self::assertPending($service);
        self::assertFailure('login_identity_mismatch', static fn () => $service->confirm('012345'));
        self::assertFalse($service->challenge()['ready']);
        self::assertFailure('login_continuation_required', static fn () => $service->confirm('012345'));
        self::assertCount(6, $transport->requests);
        self::assertCount(2, $transport->posts());
        self::assertPasswordPosts($transport, 1);
    }

    public function testValidatedGraphqlCodeWithoutAuthenticatedCookiesCannotCreateASessionOrBeReposted(): void
    {
        $steps = self::baseSteps(self::challengePage());
        $steps[] = self::step('POST', 'https://www.facebook.com/api/graphql/', new WebLoginResponse(200, self::otpResponse(true, self::ACCOUNT)));
        $steps[] = self::step('GET', 'https://www.facebook.com/two_factor/remember_browser/', new WebLoginResponse(200, '<html>No cookie grant</html>'));
        $transport = new LoginContinuationFixtureTransport($steps); $loaded = [];
        $service = self::service($transport, $loaded);
        self::assertPending($service);
        self::assertFailure('login_continuation_required', static fn () => $service->confirm('012345'));
        self::assertFalse($service->challenge()['ready']);
        self::assertFailure('login_continuation_required', static fn () => $service->confirm('012345'));
        self::assertCount(5, $transport->requests);
        self::assertCount(2, $transport->posts());
    }

    public function testCompletedHtmlOtpWithAnUnconfirmedIdentityCannotBeReposted(): void
    {
        $steps = self::baseSteps(self::htmlForm('synthetic-first-nh'));
        $steps[] = self::step('POST', 'https://www.facebook.com/two_factor/confirm/', new WebLoginResponse(302, '', ['location' => ['/messages/']]), self::authenticatedCookies());
        $steps[] = self::step('GET', 'https://www.facebook.com/messages/', new WebLoginResponse(200, self::identity(self::ACCOUNT)));
        $steps[] = self::step('GET', 'https://www.facebook.com/messages/', new WebLoginResponse(200, self::identity(self::OTHER_ACCOUNT)));
        $transport = new LoginContinuationFixtureTransport($steps); $loaded = [];
        $service = self::service($transport, $loaded);
        self::assertPending($service);
        self::assertFailure('login_identity_mismatch', static fn () => $service->confirm('012345'));
        self::assertFalse($service->challenge()['ready'], 'An OTP that completed its server form must not remain ready for replay after identity verification failed.');
        self::assertFailure('login_continuation_required', static fn () => $service->confirm('012345'));
        self::assertCount(6, $transport->requests);
        self::assertCount(2, $transport->posts());
    }

    public function testUncertainOtpTransportDisablesRepostingInsteadOfRepeatingLoginOrCode(): void
    {
        $steps = self::baseSteps(self::challengePage());
        $steps[] = ['method' => 'POST', 'url' => 'https://www.facebook.com/api/graphql/', 'error' => new MessengerException('login_transport_failed', 'Synthetic transport disconnect')];
        $transport = new LoginContinuationFixtureTransport($steps); $loaded = [];
        $service = self::service($transport, $loaded);
        self::assertPending($service);
        self::assertFailure('login_two_factor_outcome_uncertain', static fn () => $service->confirm('012345'));
        self::assertFalse($service->challenge()['ready']);
        self::assertFailure('login_challenge_unavailable', static fn () => $service->confirm('012345'));
        self::assertCount(4, $transport->requests);
        self::assertPasswordPosts($transport, 1);
    }

    public function testHttp500AfterPostingOtpIsUncertainAndCannotRepostTheCode(): void
    {
        self::assertUnknownOtpResponseCannotReplay(new WebLoginResponse(500, '{"error":"Synthetic server failure after POST"}'));
    }

    public function testUnknownHttp200JsonAfterPostingOtpIsUncertainAndCannotRepostTheCode(): void
    {
        self::assertUnknownOtpResponseCannotReplay(new WebLoginResponse(200, '{"data":{"unrecognized_future_operation":{"accepted":true}}}'));
    }

    public function testMalformedCodeIsRejectedLocallyAndExplicitCancelDestroysThePendingJar(): void
    {
        $transport = new LoginContinuationFixtureTransport(self::baseSteps(self::challengePage())); $loaded = [];
        $service = self::service($transport, $loaded);
        self::assertPending($service);
        self::assertFailure('login_code_invalid', static fn () => $service->confirm('not-a-code'));
        self::assertCount(3, $transport->requests);
        self::assertTrue($service->challenge()['ready']);
        $service->cancel();
        self::assertNull($service->challenge());
        self::assertSame([], $transport->cookies());
        self::assertFailure('login_challenge_expired', static fn () => $service->confirm('012345'));
        self::assertCount(3, $transport->requests);
    }

    public function testExpiredPendingChallengeDestroysTheMemoryJarWithoutPostingCode(): void
    {
        $transport = new LoginContinuationFixtureTransport(self::baseSteps(self::challengePage())); $loaded = [];
        $service = self::service($transport, $loaded);
        self::assertPending($service);
        (new ReflectionProperty($service, 'challengeExpiresAt'))->setValue($service, time() - 1);
        self::assertFailure('login_challenge_expired', static fn () => $service->confirm('012345'));
        self::assertNull($service->challenge());
        self::assertSame([], $transport->cookies());
        self::assertCount(3, $transport->requests);
        self::assertSame(2, $transport->clearCount);
    }

    private static function assertPending(WebLoginService $service, string $reason = 'login_two_factor_required'): void
    {
        self::assertFailure($reason, static fn () => $service->login('owner@example.test', 'SYNTHETIC-PRIVATE-PASSWORD'));
        $projection = $service->challenge(); self::assertNotNull($projection);
        self::assertSame(['type', 'expires_at', 'ready'], array_keys($projection));
        self::assertGreaterThan(time(), $projection['expires_at']);
        self::assertLessThanOrEqual(time() + 600, $projection['expires_at']);
        self::assertStringNotContainsString('synthetic-current-context', json_encode($projection, JSON_THROW_ON_ERROR));
    }

    private static function assertUnknownOtpResponseCannotReplay(WebLoginResponse $response): void
    {
        $steps = self::baseSteps(self::challengePage());
        $steps[] = self::step('POST', 'https://www.facebook.com/api/graphql/', $response);
        $transport = new LoginContinuationFixtureTransport($steps); $loaded = [];
        $service = self::service($transport, $loaded);
        self::assertPending($service);
        self::assertFailure('login_two_factor_outcome_uncertain', static fn () => $service->confirm('012345'));
        self::assertFalse($service->challenge()['ready']);
        self::assertCount(4, $transport->requests);
        self::assertFailure('login_challenge_unavailable', static fn () => $service->confirm('012345'));
        self::assertCount(4, $transport->requests);
        self::assertCount(2, $transport->posts());
        self::assertPasswordPosts($transport, 1);
        self::assertSame([[self::MUTATION_URL]], $loaded);
    }

    private static function assertFailure(string $reason, callable $action): void
    {
        try { $action(); self::fail('Expected a rejected login continuation.'); }
        catch (MessengerException $error) { self::assertSame($reason, $error->reason); }
    }

    private static function assertPasswordPosts(LoginContinuationFixtureTransport $transport, int $expected): void
    {
        $passwordPosts = array_values(array_filter($transport->posts(), static fn (array $request): bool => isset($request['form']['pass']) || isset($request['form']['encpass'])));
        self::assertCount($expected, $passwordPosts);
        foreach ($passwordPosts as $request) self::assertStringStartsWith('#PWD_BROWSER:5:', $request['form']['pass'] ?? $request['form']['encpass']);
        self::assertStringNotContainsString('SYNTHETIC-PRIVATE-PASSWORD', json_encode($transport->requests, JSON_THROW_ON_ERROR));
    }

    /** @param list<list<string>> $loaded */
    private static function service(LoginContinuationFixtureTransport $transport, array &$loaded): WebLoginService
    {
        return new WebLoginService($transport, static function (array $urls) use (&$loaded): array {
            $loaded[] = $urls;
            return ['__d("useTwoFactorLoginValidateCodeMutation_facebookRelayOperation",[],(function(t,n,r,o,a,i){a.exports="27123456789012345"}),null);'];
        });
    }

    /** @return list<array<string,mixed>> */
    private static function baseSteps(string $challenge, string $url = self::CHALLENGE): array
    {
        return [self::step('GET', 'https://www.messenger.com/', new WebLoginResponse(200, self::loginPage()), [self::pendingCookies()[0]]),
            self::step('POST', 'https://www.messenger.com/login/password/', new WebLoginResponse(302, '', ['location' => [$url]])),
            self::step('GET', $url, new WebLoginResponse(200, $challenge), self::pendingCookies())];
    }

    /** @param list<array<string,mixed>>|null $cookies
     * @return array<string,mixed>
     */
    private static function step(string $method, string $url, WebLoginResponse $response, ?array $cookies = null): array
    {
        $step = ['method' => $method, 'url' => $url, 'response' => $response];
        if ($cookies !== null) $step['cookies'] = $cookies;
        return $step;
    }

    private static function loginPage(): string
    {
        $key = bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_seed_keypair(str_repeat("\x09", 32))));
        return '<form id="login_form" action="/login/password/" method="post"><input name="email"><input type="password" name="pass"><input type="hidden" name="lsd" value="synthetic-login-lsd"></form><script type="application/json">'
            . json_encode(['require' => [['MWLoginEncryptPasswordOnSubmit@synthetic', 'init', [], [['keyId' => 4, 'publicKey' => $key]]]]], JSON_THROW_ON_ERROR) . '</script>';
    }

    private static function challengePage(string $method = 'TOTP'): string
    {
        return '<script type="application/json">' . json_encode(['define' => [
            ['LSD', [], ['token' => 'synthetic-challenge-lsd'], 1], ['RelayAPIConfigDefaults', [], ['graphURI' => '/api/graphql/'], 2],
            ['CurrentUserInitialData', [], ['USER_ID' => '0'], 3]],
            'payload' => json_encode(['data' => ['xfb_two_factor_login_default_method' => ['method' => ['method' => $method]]]], JSON_THROW_ON_ERROR),
            'compMap' => ['TwoStepVerificationRoot.react' => ['r' => ['otp-bundle']]],
            'rsrcMap' => ['otp-bundle' => ['type' => 'js', 'src' => self::MUTATION_URL]]], JSON_THROW_ON_ERROR) . '</script>';
    }

    private static function htmlForm(string $nonce): string
    {
        return '<form method="post" action="/two_factor/confirm/"><input type="hidden" name="nh" value="' . $nonce . '"><input name="approvals_code" maxlength="6"></form>';
    }

    private static function otpResponse(bool $valid, ?string $id = null): string
    {
        return json_encode(['data' => ['xfb_two_factor_login_validate_code' => ['is_code_valid' => $valid, 'next' => $valid ? '/two_factor/remember_browser/' : null, 'user_id' => $id]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function identity(string $id): string
    {
        return '<script type="application/json">' . json_encode(['define' => [['CurrentUserInitialData', [], ['USER_ID' => $id], 1]]], JSON_THROW_ON_ERROR) . '</script>';
    }

    /** @return list<array<string,mixed>> */
    private static function pendingCookies(): array
    {
        return [['name' => 'datr', 'value' => 'synthetic-messenger-device', 'domain' => '.messenger.com'], ['name' => 'datr', 'value' => 'synthetic-facebook-device', 'domain' => '.facebook.com']];
    }

    /** @return list<array<string,mixed>> */
    private static function authenticatedCookies(string $id = self::ACCOUNT): array
    {
        return [...self::pendingCookies(), ['name' => 'c_user', 'value' => $id, 'domain' => '.facebook.com'], ['name' => 'xs', 'value' => 'synthetic-final-session', 'domain' => '.facebook.com']];
    }
}

final class LoginContinuationFixtureTransport implements WebLoginTransport
{
    /** @var list<array{method:string,url:string,form:array<string,string>,incoming_cookies:list<array<string,mixed>>}> */
    public array $requests = [];
    /** @var list<array<string,mixed>> */ private array $jar = [];
    public int $clearCount = 0;
    /** @param list<array<string,mixed>> $steps */
    public function __construct(private array $steps) {}
    public function request(string $method, #[SensitiveParameter] string $url, #[SensitiveParameter] array $form = []): WebLoginResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'form' => $form, 'incoming_cookies' => $this->jar];
        $step = array_shift($this->steps);
        if ($step === null) throw new LogicException('Unexpected extra HTTP request in synthetic login replay.');
        TestCase::assertSame($step['method'], $method); TestCase::assertSame($step['url'], $url);
        if (isset($step['cookies'])) $this->jar = $step['cookies'];
        if (isset($step['error'])) throw $step['error'];
        return $step['response'];
    }
    public function cookies(): array { return $this->jar; }
    public function clear(): void { ++$this->clearCount; $this->jar = []; }
    /** @return list<array{method:string,url:string,form:array<string,string>,incoming_cookies:list<array<string,mixed>>}> */
    public function posts(): array { return array_values(array_filter($this->requests, static fn (array $request): bool => $request['method'] === 'POST')); }
}
