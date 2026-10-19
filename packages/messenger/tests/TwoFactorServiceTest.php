<?php
declare(strict_types=1);

use MVO\Messenger\Auth\TwoFactorService;
use MVO\Messenger\MessengerException;
use MVO\Messenger\Transport\{WebLoginResponse, WebLoginTransport};
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

final class TwoFactorServiceTest extends TestCase
{
    public function testCaaConfirmationUsesObservedVariableNamesAndTheSameMemoryTransport(): void
    {
        $transport = new TwoFactorFixtureTransport();
        $service = new TwoFactorService($transport);
        $spec = $service->prepare(new WebLoginResponse(200, self::page()), self::url(), [self::operation()]);
        self::assertNotNull($spec);
        $response = $service->submit($spec, '012 345');
        self::assertSame($transport->response, $response);
        self::assertCount(1, $transport->requests);
        [$method, $url, $fields] = $transport->requests[0];
        self::assertSame('POST', $method);
        self::assertSame('https://www.facebook.com/api/graphql/', $url);
        self::assertSame('27123456789012345', $fields['doc_id']);
        self::assertSame('useTwoFactorLoginValidateCodeMutation', $fields['fb_api_req_friendly_name']);
        self::assertSame('synthetic-csrf', $fields['fb_dtsg']);
        self::assertSame('2' . array_sum(array_map(ord(...), str_split('synthetic-csrf'))), $fields['jazoest']);
        $variables = json_decode($fields['variables'], true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(['sensitive_string_value' => '012345'], $variables['code']);
        self::assertSame('TWO_FACTOR_LOGIN', $variables['flow']);
        self::assertSame('TOTP', $variables['method']);
        self::assertSame('synthetic-current-context', $variables['encryptedContext']);
        self::assertSame('/messages/', $variables['next_uri']);
        self::assertNull($variables['maskedContactPoint']); self::assertNull($variables['shared_prefs_data']);
        self::assertFalse($variables['trust_this_device']);
        self::assertArrayNotHasKey('input', $variables);
        self::assertSame([], $transport->cookies());
    }

    public function testUnavailableProtocolNeverPostsACodeOrFabricatesAMutationId(): void
    {
        $transport = new TwoFactorFixtureTransport(); $service = new TwoFactorService($transport);
        self::assertNull($service->prepare(new WebLoginResponse(200, self::page()), self::url()));
        self::assertNull($service->prepare(new WebLoginResponse(200, self::page()), 'https://www.facebook.com/two_step_verification/?flow=two_factor_login', [self::operation()]));
        self::assertNull($service->prepare(new WebLoginResponse(200, str_replace('TOTP', 'SMS', self::page())), self::url(), [self::operation()]));
        self::assertNull($service->prepare(new WebLoginResponse(200, str_replace('RelayAPIConfigDefaults', 'UnknownConfig', self::page())), self::url(), [self::operation()]));
        self::assertSame([], $transport->requests);
    }

    public function testChangingOrDuplicatedMutationIdsAreRejected(): void
    {
        $service = new TwoFactorService(new TwoFactorFixtureTransport());
        try { $service->prepare(new WebLoginResponse(200, self::page()), self::url(), [self::operation(), str_replace('27123456789012345', '27123456789012346', self::operation())]); self::fail('Expected conflicting operation.'); }
        catch (MessengerException $error) { self::assertSame('login_two_factor_protocol_unavailable', $error->reason); }
    }

    public function testInvalidCodeIsRejectedBeforeAnyHttpPost(): void
    {
        $transport = new TwoFactorFixtureTransport(); $service = new TwoFactorService($transport);
        $spec = $service->prepare(new WebLoginResponse(200, self::page()), self::url(), [self::operation()]);
        self::assertNotNull($spec);
        try { $service->submit($spec, '12345'); self::fail('Expected invalid code.'); }
        catch (MessengerException $error) { self::assertSame('login_two_factor_invalid_code', $error->reason); }
        self::assertSame([], $transport->requests);
    }

    public function testInvalidServerOtpDoesNotBecomeAuthenticatedOrRetryAutomatically(): void
    {
        $service = new TwoFactorService(new TwoFactorFixtureTransport());
        try { $service->result(new WebLoginResponse(200, self::resultPayload(false)), self::url()); self::fail('Expected invalid code.'); }
        catch (MessengerException $error) { self::assertSame('login_two_factor_invalid_code', $error->reason); }
    }

    public function testValidOtpReturnsAContinuationAndNativeIdWithoutCreatingACookieJar(): void
    {
        $service = new TwoFactorService(new TwoFactorFixtureTransport());
        self::assertSame(['validated' => true, 'next' => 'https://www.facebook.com/messages/', 'user_id' => '100000000000000001'], $service->result(new WebLoginResponse(200, 'for (;;);' . self::resultPayload(true)), self::url()));
    }

    public function testExternalContinuationAndPartialGraphqlErrorsAreNotAccepted(): void
    {
        $service = new TwoFactorService(new TwoFactorFixtureTransport());
        foreach ([str_replace('/messages/', 'https://evil.example/steal', self::resultPayload(true)) => 'login_unsafe_redirect', json_encode(['errors' => [['message' => 'PRIVATE-SERVER-TEXT']], 'data' => ['xfb_two_factor_login_validate_code' => ['is_code_valid' => true]]], JSON_THROW_ON_ERROR) => 'login_two_factor_rejected'] as $body => $reason) {
            try { $service->result(new WebLoginResponse(200, $body), self::url()); self::fail('Expected unsafe/error response.'); }
            catch (MessengerException $error) { self::assertSame($reason, $error->reason); self::assertStringNotContainsString('PRIVATE-SERVER-TEXT', $error->getMessage()); }
        }
    }

    public function testServerFormContinuationUsesTheExistingParserWithoutRepeatingPasswordLogin(): void
    {
        $transport = new TwoFactorFixtureTransport(); $service = new TwoFactorService($transport);
        $spec = $service->prepare(new WebLoginResponse(200, '<form method="post" action="/checkpoint/"><input type="hidden" name="nh" value="synthetic"><input name="approvals_code" maxlength="6"></form>'), 'https://www.facebook.com/checkpoint/');
        self::assertNotNull($spec);
        $service->submit($spec, '012345');
        self::assertSame([['POST', 'https://www.facebook.com/checkpoint/', ['nh' => 'synthetic', 'approvals_code' => '012345']]], $transport->requests);
    }

    public function testNativeCaptchaClassificationDoesNotPretendTheContextIsTotp(): void
    {
        $transport = new TwoFactorFixtureTransport(); $service = new TwoFactorService($transport);
        $page = new WebLoginResponse(200, str_replace('TOTP', 'GOOGLE_RECAPTCHA', self::page()));
        $url = str_replace('flow=two_factor_login', 'flow=pre_authentication', self::url());
        self::assertSame('GOOGLE_RECAPTCHA', $service->method($page, $url));
        self::assertNull($service->prepare($page, $url, [self::operation()]));
        self::assertSame([], $transport->requests);
        self::assertNull($service->method(new WebLoginResponse(200, '<script type="application/json">{"method":"TOTP"}</script>'), self::url()));
    }

    public function testOnlyTheActualServerDefaultMethodIsClassifiedAndConflictingResultsFail(): void
    {
        $service = new TwoFactorService(new TwoFactorFixtureTransport());
        self::assertSame('TOTP', $service->method(new WebLoginResponse(200, self::page()), self::url()));
        try { $service->method(new WebLoginResponse(200, self::page() . str_replace('TOTP', 'SMS', self::page())), self::url()); self::fail('Expected conflicting method.'); }
        catch (MessengerException $error) { self::assertSame('login_two_factor_protocol_unavailable', $error->reason); }
    }

    public function testServerJsRefreshesMutableLsdAndSiteDataInDocumentOrder(): void
    {
        $service = new TwoFactorService(new TwoFactorFixtureTransport());
        $updates = '<script type="application/json">' . json_encode(['define' => [['LSD', [], ['token' => 'synthetic-rotated-lsd'], 1], ['SiteData', [], ['client_revision' => 1234], 2]]], JSON_THROW_ON_ERROR) . '</script>';
        $page = new WebLoginResponse(200, self::page() . $updates);
        self::assertSame('TOTP', $service->method($page, self::url()));
        $spec = $service->prepare($page, self::url(), [self::operation()]);
        self::assertNotNull($spec);
        self::assertSame('synthetic-rotated-lsd', $spec['fields']['lsd']);
        self::assertSame('1234', $spec['fields']['__rev']);
    }

    public function testBundleSelectionMergesCombinedScriptTagsWithComponentResourcesAndSkipsUnrelatedDeferredCode(): void
    {
        $service = new TwoFactorService(new TwoFactorFixtureTransport());
        $maps = ['rsrcMap' => [
            'known' => ['type' => 'js', 'src' => 'https://static.xx.fbcdn.net/rsrc.php/v4/code.js'],
            'css' => ['type' => 'css', 'src' => 'https://static.xx.fbcdn.net/rsrc.php/v4/theme.css'],
            'unrelated' => ['type' => 'js', 'src' => 'https://static.xx.fbcdn.net/rsrc.php/v4/ads.js']],
            'compMap' => ['TwoStepVerificationRoot.react' => ['r' => ['known', 'combined', 'css'], 'rds' => ['r' => ['unrelated'], 'm' => ['UnrelatedAds.react']]], 'UnrelatedAds.react' => ['r' => array_fill(0, 1500, 'unrelated')]]];
        $html = '<script type="application/json">' . json_encode($maps, JSON_THROW_ON_ERROR) . '</script><script src="https://static.xx.fbcdn.net/rsrc.php/v4/combined.js" data-bootloader-hash="combined"></script>';
        self::assertSame(['https://static.xx.fbcdn.net/rsrc.php/v4/code.js', 'https://static.xx.fbcdn.net/rsrc.php/v4/combined.js'], $service->bundleUrls($html));
    }

    public function testBundleSelectionRejectsPrivateCrossDomainAndNonJavascriptUrls(): void
    {
        $service = new TwoFactorService(new TwoFactorFixtureTransport());
        foreach (['http://static.xx.fbcdn.net/rsrc.php/v4/plain.js', 'https://static.xx.fbcdn.net.evil.example/rsrc.php/v4/steal.js', 'https://www.facebook.com/private.js', 'https://static.xx.fbcdn.net/rsrc.php/v4/file.js#fragment', 'https://user:pass@static.xx.fbcdn.net/rsrc.php/v4/code.js', 'https://static.xx.fbcdn.net/other/code.js'] as $url) {
            $html = '<script type="application/json">' . json_encode(['compMap' => ['TwoStepVerificationRoot.react' => ['r' => ['selected']]]], JSON_THROW_ON_ERROR) . '</script><script src="' . $url . '" data-bootloader-hash="selected"></script>';
            self::assertSame([], $service->bundleUrls($html));
        }
    }

    public function testBundleHashWithConflictingSourcesFailsBeforeAnyFetch(): void
    {
        $service = new TwoFactorService(new TwoFactorFixtureTransport());
        $html = '<script type="application/json">' . json_encode(['rsrcMap' => ['same' => ['type' => 'js', 'src' => 'https://static.xx.fbcdn.net/rsrc.php/v4/first.js']], 'compMap' => ['TwoStepVerificationRoot.react' => ['r' => ['same']]]], JSON_THROW_ON_ERROR) . '</script><script src="https://static.xx.fbcdn.net/rsrc.php/v4/second.js" data-bootloader-hash="same"></script>';
        try { $service->bundleUrls($html); self::fail('Expected ambiguity.'); }
        catch (MessengerException $error) { self::assertSame('login_two_factor_protocol_unavailable', $error->reason); }
    }

    private static function url(): string { return 'https://www.facebook.com/two_step_verification/?flow=two_factor_login&encrypted_context=synthetic-current-context&next=%2Fmessages%2F'; }
    private static function operation(): string { return '__d("useTwoFactorLoginValidateCodeMutation_facebookRelayOperation",[],(function(t,n,r,o,a,i){a.exports="27123456789012345"}),null);'; }
    private static function page(): string
    {
        return '<script type="application/json">' . json_encode(['define' => [
            ['DTSGInitialData', [], ['token' => 'synthetic-csrf'], 1], ['LSD', [], ['token' => 'synthetic-lsd'], 2],
            ['SprinkleConfig', [], ['version' => 2, 'should_randomize' => false], 3],
            ['RelayAPIConfigDefaults', [], ['graphURI' => '/api/graphql/', 'actorID' => '0'], 4],
            ['CurrentUserInitialData', [], ['USER_ID' => '0'], 5]],
            'payload' => json_encode(['data' => ['xfb_two_factor_login_default_method' => ['method' => ['method' => 'TOTP']]]], JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR) . '</script>';
    }
    private static function resultPayload(bool $valid): string
    {
        return json_encode(['data' => ['xfb_two_factor_login_validate_code' => ['is_code_valid' => $valid, 'next' => '/messages/', 'user_id' => '100000000000000001']]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}

final class TwoFactorFixtureTransport implements WebLoginTransport
{
    /** @var list<array{string,string,array<string,string>}> */ public array $requests = [];
    public readonly WebLoginResponse $response;
    public function __construct() { $this->response = new WebLoginResponse(200, '{"data":{"xfb_two_factor_login_validate_code":{"is_code_valid":true}}}'); }
    public function request(string $method, #[SensitiveParameter] string $url, #[SensitiveParameter] array $form = []): WebLoginResponse { $this->requests[] = [$method, $url, $form]; return $this->response; }
    public function cookies(): array { return []; }
    public function clear(): void {}
}
