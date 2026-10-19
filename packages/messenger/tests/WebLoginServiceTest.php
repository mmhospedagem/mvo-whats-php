<?php
declare(strict_types=1);

use MVO\Messenger\Auth\{WebLoginPageParser, WebLoginService};
use MVO\Messenger\MessengerException;
use MVO\Messenger\Transport\{WebLoginCurlTransport, WebLoginResponse, WebLoginTransport};
use MVO\Messenger\Transport\CurlTransportCapture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/fixtures/curl-capture.php';

final class WebLoginServiceTest extends TestCase
{
    public function testPrivateCurlCookieExportRetainsHttpOnlyAndHostOnlyWithoutNetwork():void
    {
        $handle=\curl_init();self::assertInstanceOf(\CurlHandle::class,$handle);
        \curl_setopt($handle,CURLOPT_COOKIEFILE,'');
        \curl_setopt($handle,CURLOPT_COOKIELIST,"#HttpOnly_.facebook.com\tTRUE\t/\tTRUE\t0\tdatr\tSYNTHETIC_PRIVATE_DEVICE");
        \curl_setopt($handle,CURLOPT_COOKIELIST,"www.facebook.com\tFALSE\t/login\tTRUE\t0\tlocale\tpt_BR");
        $transport=new WebLoginCurlTransport();
        (new \ReflectionProperty($transport,'handle'))->setValue($transport,$handle);
        $cookies=$transport->cookies();self::assertCount(2,$cookies);
        $byName=array_column($cookies,null,'name');
        self::assertTrue($byName['datr']['httpOnly']);self::assertFalse($byName['datr']['hostOnly']);
        self::assertSame('.facebook.com',$byName['datr']['domain']);self::assertNull($byName['datr']['expires']);
        self::assertFalse($byName['locale']['httpOnly']);self::assertTrue($byName['locale']['hostOnly']);
        self::assertSame('/login',$byName['locale']['path']);self::assertTrue($byName['locale']['secure']);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE_DEVICE',print_r($transport,true));
        $transport->clear();self::assertSame([],$transport->cookies());
    }
    public function testMessengerFormLoginSendsOnlyEncryptedPasswordAndVerifiesRealCookieDomain(): void
    {
        $fake = new WebLoginFixtureTransport([
            ['response' => new WebLoginResponse(200, self::page())],
            ['response' => new WebLoginResponse(302, '', ['location' => ['/']]), 'cookies' => self::cookies()],
            ['response' => new WebLoginResponse(200, self::identity('100000000001'))],
            ['response' => new WebLoginResponse(200, self::identity('100000000001'))],
        ]);
        $jar = (new WebLoginService($fake))->login('demo@example.test', 'PRIVATE-FAKE-PASSWORD');
        self::assertSame('100000000001', $jar->accountId());
        self::assertStringContainsString('xs=synthetic-session', $jar->headerFor('https://www.messenger.com/'));
        self::assertSame('', $jar->headerFor('https://www.facebook.com/messages/'));
        self::assertSame('POST', $fake->requests[1]['method']);
        self::assertSame('https://www.messenger.com/login/password/', $fake->requests[1]['url']);
        $form = $fake->requests[1]['form'];
        self::assertStringStartsWith('#PWD_BROWSER:5:', $form['pass']);
        self::assertSame('demo@example.test', $form['email']);
        self::assertSame('nonce-synthetic', $form['lsd']);
        self::assertSame('2' . array_sum(array_map(ord(...), str_split('nonce-synthetic'))), $form['jazoest']);
        self::assertArrayNotHasKey('encpass', $form);
        self::assertArrayNotHasKey('login',$form);
        self::assertStringNotContainsString('PRIVATE-FAKE-PASSWORD', json_encode($fake->requests, JSON_THROW_ON_ERROR));
        self::assertSame(2, $fake->clearCount);
        self::assertSame([], $fake->cookies());
    }

    public function testTraditionalFacebookFormUsesEncpassWithoutPlainPass(): void
    {
        $response = (new WebLoginPageParser())->parse(self::page(true), 'https://www.facebook.com/login.php');
        self::assertSame('encpass', $response['password_field']);
        self::assertSame('https://www.facebook.com/login/password/', $response['url']);
        self::assertArrayNotHasKey('pass', $response['fields']);
        self::assertSame('1',$response['fields']['login']);
    }

    public function testCaaWithoutObservedHtmlFormFailsBeforeAnyCredentialPost(): void
    {
        $fake = new WebLoginFixtureTransport([['response' => new WebLoginResponse(200, '<html><div>CAA/Relay login surface</div></html>')]]);
        try { (new WebLoginService($fake))->login('demo@example.test', 'fake-pass'); self::fail('Expected unsupported current flow.'); }
        catch (MessengerException $error) { self::assertSame('login_flow_unavailable', $error->reason); }
        self::assertCount(1, $fake->requests);
        self::assertSame('GET', $fake->requests[0]['method']);
    }

    #[DataProvider('challengeLocations')]
    public function testSecurityChallengesStopWithoutAutomaticBypass(string $location, string $reason): void
    {
        $responses = [
            ['response' => new WebLoginResponse(200, self::page())],
            ['response' => new WebLoginResponse(302, '', ['location' => [$location]])],
        ];
        $twoFactor=$reason==='login_two_factor_required';
        if($twoFactor)$responses[]=['response'=>new WebLoginResponse(200,'<html>Private challenge document</html>')];
        $fake=new WebLoginFixtureTransport($responses);$service=new WebLoginService($fake);
        try { $service->login('demo@example.test', 'fake-pass'); self::fail('Expected challenge.'); }
        catch (MessengerException $error) { self::assertSame($reason, $error->reason); }
        self::assertCount($twoFactor?3:2, $fake->requests);
        if($twoFactor){self::assertSame('two_factor',$service->challenge()['type']);self::assertStringNotContainsString('Private challenge document',print_r($service,true));$service->cancel();self::assertNull($service->challenge());}
        self::assertSame([], $fake->cookies());
    }

    /** @return iterable<array{string,string}> */
    public static function challengeLocations(): iterable
    {
        yield ['https://www.facebook.com/checkpoint/123', 'login_challenge_required'];
        yield ['https://www.facebook.com/two_step_verification/two_factor/', 'login_two_factor_required'];
        yield ['https://www.facebook.com/captcha/', 'login_captcha_required'];
        yield ['https://www.facebook.com/consent/', 'login_consent_required'];
    }

    public function testRejectedCredentialsDoNotBecomeAnAuthenticatedSession(): void
    {
        $fake = new WebLoginFixtureTransport([
            ['response' => new WebLoginResponse(200, self::page())],
            ['response' => new WebLoginResponse(200, '<div id="error_box">Credentials rejected</div>')],
        ]);
        try { (new WebLoginService($fake))->login('demo@example.test', 'fake-pass'); self::fail('Expected rejected credentials.'); }
        catch (MessengerException $error) { self::assertSame('login_rejected', $error->reason); }
    }

    public function testCookiesWithoutAuthenticatedUserIdentityAreInsufficient(): void
    {
        $fake = new WebLoginFixtureTransport([
            ['response' => new WebLoginResponse(200, self::page())],
            ['response' => new WebLoginResponse(200, ''), 'cookies' => self::cookies()],
            ['response' => new WebLoginResponse(200, '<html>Anonymous</html>')],
        ]);
        try { (new WebLoginService($fake))->login('demo@example.test', 'fake-pass'); self::fail('Expected unconfirmed identity.'); }
        catch (MessengerException $error) { self::assertSame('login_unconfirmed', $error->reason); }
    }

    public function testDifferentConfirmedIdentityFailsClosed(): void
    {
        $fake = new WebLoginFixtureTransport([
            ['response' => new WebLoginResponse(200, self::page())],
            ['response' => new WebLoginResponse(200, ''), 'cookies' => self::cookies()],
            ['response' => new WebLoginResponse(200, self::identity('100000000002'))],
        ]);
        try { (new WebLoginService($fake))->login('demo@example.test', 'fake-pass'); self::fail('Expected mismatch.'); }
        catch (MessengerException $error) { self::assertSame('login_identity_mismatch', $error->reason); }
    }

    public function testReturnsRotatedCookiesFromVerificationInsteadOfAStaleSession(): void
    {
        $rotated = self::cookies(); $rotated[1]['value'] = 'synthetic-rotated';
        $fake = new WebLoginFixtureTransport([
            ['response' => new WebLoginResponse(200, self::page())],
            ['response' => new WebLoginResponse(200, ''), 'cookies' => self::cookies()],
            ['response' => new WebLoginResponse(200, self::identity('100000000001')), 'cookies' => $rotated],
        ]);
        $jar = (new WebLoginService($fake))->login('demo@example.test', 'fake-pass');
        self::assertStringContainsString('xs=synthetic-rotated', $jar->headerFor('https://www.messenger.com/'));
    }

    public function testVerificationDeletingCookiesDoesNotConfirmLoginFromCachedIdentity(): void
    {
        $fake = new WebLoginFixtureTransport([
            ['response' => new WebLoginResponse(200, self::page())],
            ['response' => new WebLoginResponse(200, ''), 'cookies' => self::cookies()],
            ['response' => new WebLoginResponse(200, self::identity('100000000001')), 'cookies' => []],
        ]);
        try { (new WebLoginService($fake))->login('demo@example.test', 'fake-pass'); self::fail('Expected deleted session.'); }
        catch (MessengerException $error) { self::assertSame('login_unconfirmed', $error->reason); }
    }

    public function testRateLimitStopsBeforeCredentialsArePosted(): void
    {
        $fake = new WebLoginFixtureTransport([['response' => new WebLoginResponse(429, '')]]);
        try { (new WebLoginService($fake))->login('demo@example.test', 'fake-pass'); self::fail('Expected rate limit.'); }
        catch (MessengerException $error) { self::assertSame('login_rate_limited', $error->reason); }
        self::assertCount(1, $fake->requests);
    }

    public function testRedirectCannotExfiltrateCredentialsToAnotherOrigin(): void
    {
        $fake = new WebLoginFixtureTransport([['response' => new WebLoginResponse(302, '', ['location' => ['https://www.messenger.com.evil.example/login/']])]]);
        try { (new WebLoginService($fake))->login('demo@example.test', 'fake-pass'); self::fail('Expected unsafe redirect.'); }
        catch (MessengerException $error) { self::assertSame('login_unsafe_redirect', $error->reason); }
        self::assertCount(1, $fake->requests);
    }

    public function testPostRedirectDoesNotResendCredentials(): void
    {
        $fake = new WebLoginFixtureTransport([
            ['response' => new WebLoginResponse(200, self::page())],
            ['response' => new WebLoginResponse(307, '', ['location' => ['/login/retry/']])],
        ]);
        try { (new WebLoginService($fake))->login('demo@example.test', 'fake-pass'); self::fail('Expected no repeated POST.'); }
        catch (MessengerException $error) { self::assertSame('login_redirect_unsupported', $error->reason); }
        self::assertCount(2, $fake->requests);
    }

    public function testMissingEncryptionConfigDoesNotFallBackToPlainPassword(): void
    {
        $page = preg_replace('/<script>.*<\/script>/s', '', self::page());
        $fake = new WebLoginFixtureTransport([['response' => new WebLoginResponse(200, $page)]]);
        try { (new WebLoginService($fake))->login('demo@example.test', 'fake-pass'); self::fail('Expected missing key.'); }
        catch (MessengerException $error) { self::assertSame('login_encryption_unavailable', $error->reason); }
        self::assertCount(1, $fake->requests);
    }

    public function testNativeCookieEngineKeepsTrueDomainAndNeverUsesADiskCookieFile(): void
    {
        $handle = curl_init();
        curl_setopt($handle, CURLOPT_COOKIEFILE, '');
        curl_setopt($handle, CURLOPT_COOKIELIST, ".messenger.com\tTRUE\t/\tTRUE\t0\tc_user\t100000000001");
        curl_setopt($handle, CURLOPT_COOKIELIST, "#HttpOnly_.messenger.com\tTRUE\t/\tTRUE\t0\txs\tsynthetic-session");
        $transport = new WebLoginCurlTransport();
        (new ReflectionProperty($transport, 'handle'))->setValue($transport, $handle);
        $cookies = $transport->cookies();
        self::assertCount(2, $cookies);
        self::assertSame('.messenger.com', $cookies[0]['domain']);
        self::assertFalse($cookies[0]['hostOnly']);
        self::assertTrue($cookies[0]['secure']);
        $transport->clear();
        self::assertSame([], $transport->cookies());
    }

    public function testLoginGetUsesDocumentNavigationHeadersAndPostRetainsItsOrigin(): void
    {
        CurlTransportCapture::$active = true;
        CurlTransportCapture::$status = 200;
        CurlTransportCapture::$body = '<html>synthetic</html>';
        CurlTransportCapture::$headers = ["HTTP/1.1 200 OK\r\n", "\r\n"];
        $transport = new WebLoginCurlTransport();
        try {
            $transport->request('GET', 'https://www.messenger.com/');
            $options = CurlTransportCapture::$options;
            self::assertSame('', $options[CURLOPT_COOKIEFILE]);
            self::assertArrayNotHasKey(CURLOPT_COOKIEJAR, $options);
            self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
            self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
            self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
            self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
            self::assertContains('Sec-Fetch-Dest: document', $options[CURLOPT_HTTPHEADER]);
            self::assertContains('Sec-Fetch-Mode: navigate', $options[CURLOPT_HTTPHEADER]);
            self::assertContains('Sec-Fetch-Site: none', $options[CURLOPT_HTTPHEADER]);
            self::assertContains('Accept-Language: pt-BR,pt;q=0.9,en;q=0.8', $options[CURLOPT_HTTPHEADER]);
            self::assertSame([], array_filter($options[CURLOPT_HTTPHEADER], static fn (string $header): bool => str_starts_with($header, 'Origin:') || str_starts_with($header, 'Referer:')));

            $transport->request('POST', 'https://www.messenger.com/login/password/', ['pass' => 'synthetic-encrypted-value']);
            self::assertContains('Origin: https://www.messenger.com', CurlTransportCapture::$options[CURLOPT_HTTPHEADER]);
            self::assertContains('Referer: https://www.messenger.com/', CurlTransportCapture::$options[CURLOPT_HTTPHEADER]);
            self::assertContains('Sec-Fetch-Site: same-origin', CurlTransportCapture::$options[CURLOPT_HTTPHEADER]);
            self::assertSame('pass=synthetic-encrypted-value', CurlTransportCapture::$options[CURLOPT_POSTFIELDS]);
        } finally {
            $transport->clear();
            CurlTransportCapture::$active = false;
            CurlTransportCapture::$options = CurlTransportCapture::$headers = [];
            CurlTransportCapture::$body = '';
        }
    }

    private static function page(bool $facebook = false): string
    {
        $public = bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_seed_keypair(str_repeat("\x07", 32))));
        $call = $facebook
            ? ['LoginFormController@synthetic', 'init', [], [null, null, null, null, ['pubKey' => ['keyId' => 237, 'publicKey' => $public]]]]
            : ['MWLoginEncryptPasswordOnSubmit@synthetic', 'init', [], [['keyId' => 237, 'publicKey' => $public]]];
        $data = ['define' => [['LSD', [], ['token' => 'nonce-synthetic'], 323], ['SprinkleConfig', [], ['version' => 2, 'should_randomize' => false], 2111]], 'require' => [$call]];
        return '<form id="login_form" action="/login/password/" method="post"><input type="hidden" name="lsd"><input type="hidden" name="jazoest"><input type="hidden" name="lgnjs"><input type="text" name="email"><input type="password" name="pass"><button type="submit" name="login" value="1">Login</button></form><script>requireLazy(["ServerJS"],function(s){s.handle(' . json_encode($data, JSON_THROW_ON_ERROR) . ');});</script>';
    }

    private static function identity(string $id): string
    {
        return '<script type="application/json">' . json_encode(['define' => [['CurrentUserInitialData', [], ['USER_ID' => $id], 1]]], JSON_THROW_ON_ERROR) . '</script>';
    }

    /** @return list<array<string,mixed>> */
    private static function cookies(): array
    {
        return [['name' => 'c_user', 'value' => '100000000001', 'domain' => '.messenger.com'], ['name' => 'xs', 'value' => 'synthetic-session', 'domain' => '.messenger.com']];
    }
}

final class WebLoginFixtureTransport implements WebLoginTransport
{
    /** @var list<array<string,mixed>> */ public array $requests = [];
    /** @var list<array<string,mixed>> */ private array $cookieRows = [];
    public int $clearCount = 0;
    /** @param list<array{response:WebLoginResponse,cookies?:list<array<string,mixed>>}> $steps */
    public function __construct(private array $steps) {}
    public function request(string $method, string $url, #[SensitiveParameter] array $form = []): WebLoginResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'form' => $form];
        $step = array_shift($this->steps) ?? throw new LogicException('Unexpected fixture request');
        if (isset($step['cookies'])) $this->cookieRows = $step['cookies'];
        return $step['response'];
    }
    public function cookies(): array { return $this->cookieRows; }
    public function clear(): void { ++$this->clearCount; $this->cookieRows = []; }
}
