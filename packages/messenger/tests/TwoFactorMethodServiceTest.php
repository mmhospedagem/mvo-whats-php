<?php
declare(strict_types=1);

use MVO\Messenger\Auth\{TwoFactorMethodService, TwoFactorService};
use MVO\Messenger\MessengerException;
use MVO\Messenger\Transport\{WebLoginResponse, WebLoginTransport};
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

final class TwoFactorMethodServiceTest extends TestCase
{
    public function testPrepareExposesOnlyDefaultOfferedMethodAndNoSecretsOrIo(): void
    {
        $transport = new MethodFixtureTransport(); $service = new TwoFactorMethodService($transport);
        $state = self::prepare($service);
        $methods = $service->methods($state);
        self::assertCount(1, $methods); self::assertSame('totp', $methods[0]['type']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $methods[0]['id']);
        self::assertTrue($methods[0]['selected']); self::assertTrue($methods[0]['code_supported']);
        self::assertFalse($methods[0]['approval_supported']); self::assertNull($methods[0]['masked_contact']);
        self::assertSame($methods[0]['id'], $service->selectedInfo($state)['selected_method']);
        self::assertTrue($service->selectedInfo($state)['requires_code']);
        self::assertSame([], $transport->requests);
        $projection = json_encode([$methods, $service->selectedInfo($state), $service], JSON_THROW_ON_ERROR);
        foreach (['private-current-context', 'private-csrf-token', 'private-lsd-token', 'encrypted_context', 'doc_id'] as $secret) self::assertStringNotContainsString($secret, $projection);
        self::assertStringNotContainsString('private-current-context', print_r($service, true));
    }

    public function testPickerUsesActualDynamicOperationAndOnlyExplicitlyDiscoversMethods(): void
    {
        $transport = new MethodFixtureTransport([self::response(self::picker())]); $service = new TwoFactorMethodService($transport);
        $state = $service->discover(self::prepare($service));
        self::assertCount(1, $transport->requests);
        [$verb, $url, $fields] = $transport->requests[0];
        self::assertSame('POST', $verb); self::assertSame('https://www.facebook.com/api/graphql/', $url);
        self::assertSame('99100000000000001', $fields['doc_id']);
        self::assertSame('TwoStepVerificationChallengePickerDialogQuery', $fields['fb_api_req_friendly_name']);
        self::assertSame(['encryptedContext' => 'private-current-context', 'loginAccountType' => 'FACEBOOK'], self::variables($fields));
        self::assertSame(['totp', 'sms', 'email', 'app_approval'], array_column($service->methods($state), 'type'));
        self::assertTrue($service->methods($state)[0]['selected']);
        self::assertSame([], $transport->cookies());
    }

    public function testTotpSelectionIsLocalAndCodeKeepsLeadingZeroes(): void
    {
        $transport = new MethodFixtureTransport([self::response(self::picker()), self::response(['xfb_two_factor_login_validate_code' => ['is_code_valid' => true]])]);
        $service = new TwoFactorMethodService($transport); $state = $service->discover(self::prepare($service));
        $state = $service->request($state, self::id($service, $state, 'totp'));
        self::assertCount(1, $transport->requests);
        $code = $service->codeSpec($state); self::assertNotNull($code);
        (new TwoFactorService($transport))->submit($code, '012 345');
        $variables = self::variables($transport->requests[1][2]);
        self::assertSame(['sensitive_string_value' => '012345'], $variables['code']);
        self::assertSame('TOTP', $variables['method']); self::assertFalse($variables['trust_this_device']);
    }

    public function testSmsAndEmailSelectThenNotifyOnceAndPreserveExactContactForValidation(): void
    {
        foreach (['sms' => ['SMS', '+55 *** **1234'], 'email' => ['EMAIL', 'm***@example.test']] as $type => [$enum, $contact]) {
            $transport = new MethodFixtureTransport([self::response(self::picker()), self::response(['xfb_two_step_verification_send_notification' => ['is_success' => true]]), self::response(['xfb_two_factor_login_validate_code' => ['is_code_valid' => true]])]);
            $service = new TwoFactorMethodService($transport); $state = $service->discover(self::prepare($service));
            $id = self::id($service, $state, $type); $state = $service->request($state, $id);
            self::assertCount(2, $transport->requests);
            self::assertSame('99100000000000002', $transport->requests[1][2]['doc_id']);
            self::assertSame(['challenge' => $enum, 'encryptedContext' => 'private-current-context', 'maskedContactPoint' => $contact], self::variables($transport->requests[1][2]));
            self::assertSame($id, $service->selectedInfo($state)['selected_method']);
            self::assertTrue($service->selectedInfo($state)['requires_code']);
            self::assertGreaterThan(0, $service->selectedInfo($state)['retry_after']);
            $spec = $service->codeSpec($state); self::assertNotNull($spec);
            (new TwoFactorService($transport))->submit($spec, '00123456');
            $variables = self::variables($transport->requests[2][2]);
            self::assertSame($enum, $variables['method']); self::assertSame($contact, $variables['maskedContactPoint']);
            self::assertSame(['sensitive_string_value' => '00123456'], $variables['code']);
            try { $service->request($state, $id); self::fail('Cooldown must reject a repeated notification.'); }
            catch (MessengerException $error) { self::assertSame('login_two_factor_cooldown', $error->reason); }
            self::assertCount(3, $transport->requests);
        }
    }

    public function testExplicitAppApprovalChecksPendingAndAllowedWithoutAcceptingSession(): void
    {
        $transport = new MethodFixtureTransport([self::response(self::picker()), self::response(['xfb_two_step_verification_complete_if_approval_state_allowed' => ['state' => 'PENDING']]), self::response(['xfb_two_step_verification_complete_if_approval_state_allowed' => ['state' => 'ALLOWED']])]);
        $service = new TwoFactorMethodService($transport); $state = $service->discover(self::prepare($service));
        $state = $service->request($state, self::id($service, $state, 'app_approval'));
        self::assertCount(1, $transport->requests); self::assertNull($service->codeSpec($state));
        self::assertFalse($service->selectedInfo($state)['requires_code']); self::assertTrue($service->selectedInfo($state)['can_check']);
        $result = $service->approval($state);
        self::assertFalse($result['approved']); self::assertNull($result['next']); self::assertNull($result['user_id']);
        self::assertSame('pending', $result['status']); self::assertSame(7, $result['interval_seconds']);
        self::assertSame(['encrypted_context' => 'private-current-context', 'trust_this_device' => false], self::variables($transport->requests[1][2]));
        self::assertSame('99100000000000004', $transport->requests[1][2]['doc_id']);
        $result = $service->approval($result['state']); // Rate limited locally, no additional request.
        self::assertCount(2, $transport->requests); self::assertFalse($result['approved']);
        $state = $result['state']; $state['next_poll_at'] = 0.0;
        $allowed = $service->approval($state);
        self::assertTrue($allowed['approved']); self::assertSame('https://www.facebook.com/messages/', $allowed['next']);
        self::assertNull($allowed['user_id']); self::assertSame([], $transport->cookies());
        self::assertCount(3, $transport->requests);
    }

    public function testDeniedExpiredUnknownAndGraphqlErrorsNeverBecomeApproved(): void
    {
        foreach (['DENIED' => 'login_two_factor_approval_denied', 'EXPIRED' => 'login_challenge_expired', 'UNRECOGNIZED' => 'login_two_factor_protocol_unavailable'] as $status => $reason) {
            $transport = new MethodFixtureTransport([self::response(self::picker()), self::response(['xfb_two_step_verification_complete_if_approval_state_allowed' => ['state' => $status]])]);
            $service = new TwoFactorMethodService($transport); $state = $service->discover(self::prepare($service)); $state = $service->request($state, self::id($service, $state, 'app_approval'));
            try { $service->approval($state); self::fail('Should fail closed.'); }
            catch (MessengerException $error) { self::assertSame($reason, $error->reason); }
            self::assertCount(2, $transport->requests);
        }
        $transport = new MethodFixtureTransport([new WebLoginResponse(200, '{"errors":[{"message":"PRIVATE-REMOTE-TEXT"}],"data":{"xfb_two_factor_login_methods":{"methods":[]}}}')]);
        $service = new TwoFactorMethodService($transport);
        try { $service->discover(self::prepare($service)); self::fail('Partial GraphQL failure.'); }
        catch (MessengerException $error) { self::assertSame('login_two_factor_rejected', $error->reason); self::assertStringNotContainsString('PRIVATE-REMOTE-TEXT', $error->getMessage()); }
    }

    public function testUnavailableOrInventedMethodNeverTriggersIo(): void
    {
        $picker = self::picker(); $picker['xfb_two_factor_login_methods']['methods'][1]['is_disabled'] = true;
        $picker['xfb_two_factor_login_methods']['methods'][] = ['method' => 'WHATSAPP', 'is_disabled' => false];
        $transport = new MethodFixtureTransport([self::response($picker)]); $service = new TwoFactorMethodService($transport);
        $state = $service->discover(self::prepare($service));
        self::assertSame(['totp', 'sms', 'email', 'app_approval'], array_column($service->methods($state), 'type'));
        foreach (['SMS', 'email', str_repeat('0', 32), self::id($service, $state, 'sms')] as $id) {
            try { $service->request($state, $id); self::fail('Must reject an unavailable method.'); }
            catch (MessengerException $error) { self::assertSame('login_two_factor_method_unavailable', $error->reason); }
        }
        self::assertCount(1, $transport->requests);
    }

    public function testNoStaticOperationFallbackAndConflictingIdsFailBeforeIo(): void
    {
        $transport = new MethodFixtureTransport(); $service = new TwoFactorMethodService($transport);
        $state = $service->prepare(new WebLoginResponse(200, self::page()), self::url()); self::assertNotNull($state);
        self::assertFalse($service->canRefresh($state)); self::assertFalse($service->methods($state)[0]['request_supported']); self::assertNull($service->codeSpec($state));
        try { $service->discover($state); self::fail('No operation ID.'); }
        catch (MessengerException $error) { self::assertSame('login_two_factor_protocol_unavailable', $error->reason); }
        try { $service->prepare(new WebLoginResponse(200, self::page()), self::url(), [self::operations(), str_replace('99100000000000001', '99100000000000009', self::operations())]); self::fail('Conflicting ID.'); }
        catch (MessengerException $error) { self::assertSame('login_two_factor_protocol_unavailable', $error->reason); }
        self::assertSame([], $transport->requests);
    }

    public function testUnsafeOriginsAndCaptchaAreRejectedBeforeIo(): void
    {
        $transport = new MethodFixtureTransport(); $service = new TwoFactorMethodService($transport);
        self::assertNull($service->prepare(new WebLoginResponse(200, str_replace('TOTP', 'GOOGLE_RECAPTCHA', self::page())), self::url(), [self::operations()]));
        self::assertNull($service->prepare(new WebLoginResponse(200, self::page()), str_replace('two_factor_login', 'pre_authentication', self::url()), [self::operations()]));
        foreach ([str_replace('/api/graphql/', 'https://www.messenger.com/api/graphql/', self::page()), str_replace('/api/graphql/', 'https://evil.example/api/graphql/', self::page())] as $body) {
            try { $service->prepare(new WebLoginResponse(200, $body), self::url(), [self::operations()]); self::fail('Cross origin.'); }
            catch (MessengerException $error) { self::assertSame('login_unsafe_redirect', $error->reason); }
        }
        try { $service->prepare(new WebLoginResponse(200, self::page()), self::url() . '&encrypted_context=duplicate', [self::operations()]); self::fail('Duplicate context.'); }
        catch (MessengerException $error) { self::assertSame('login_two_factor_protocol_unavailable', $error->reason); }
        self::assertSame([], $transport->requests);
    }

    public function testContactChooserKeepsOpaqueIdsAndExactNativePrivateValues(): void
    {
        $picker = self::picker();
        $picker['xfb_two_step_verification_challenge_contact_points']['contact_points'] = [['challenge' => 'SMS', 'contact_points' => [['contact_point' => '+55 *** 1234'], ['contact_point' => '+55 *** 5678']]]];
        $transport = new MethodFixtureTransport([self::response($picker), self::response(['xfb_two_step_verification_send_notification' => ['is_success' => true]])]); $service = new TwoFactorMethodService($transport);
        $state = $service->discover(self::prepare($service)); $sms = array_values(array_filter($service->methods($state), static fn(array $item): bool => $item['type'] === 'sms'));
        self::assertCount(2, $sms); self::assertNotSame($sms[0]['id'], $sms[1]['id']); self::assertSame('+55 *** 5678', $sms[1]['masked_contact']);
        $state = $service->request($state, $sms[1]['id']);
        self::assertSame('+55 *** 5678', self::variables($transport->requests[1][2])['maskedContactPoint']);
        self::assertSame('+55 *** 5678', $service->codeSpec($state)['variables']['maskedContactPoint']);
    }

    public function testFullContactIsRedactedButOriginalPreservedInPrivateRequest(): void
    {
        $picker = self::picker(); $picker['xfb_two_factor_login_methods']['methods'][2]['method_representation'] = 'private-contact@example.test';
        $transport = new MethodFixtureTransport([self::response($picker), self::response(['xfb_two_step_verification_send_notification' => ['is_success' => true]])]); $service = new TwoFactorMethodService($transport);
        $state = $service->discover(self::prepare($service)); $email = self::id($service, $state, 'email');
        self::assertStringNotContainsString('private-contact@example.test', json_encode($service->methods($state), JSON_THROW_ON_ERROR));
        $state = $service->request($state, $email);
        self::assertSame('private-contact@example.test', self::variables($transport->requests[1][2])['maskedContactPoint']);
    }

    public function testRefreshRetainsStableOpaqueIdsAndCooldownButRemovesWithdrawnMethods(): void
    {
        $picker = self::picker(); $changed = self::picker(); array_splice($changed['xfb_two_factor_login_methods']['methods'], 1, 1);
        $transport = new MethodFixtureTransport([self::response($picker), self::response(['xfb_two_step_verification_send_notification' => ['is_success' => true]]), self::response($picker), self::response($changed)]);
        $service = new TwoFactorMethodService($transport); $state = $service->discover(self::prepare($service));
        $smsId = self::id($service, $state, 'sms'); $state = $service->request($state, $smsId);
        $state = $service->discover($state); self::assertSame($smsId, self::id($service, $state, 'sms')); self::assertGreaterThan(0, $service->selectedInfo($state)['retry_after']);
        $state = $service->discover($state); self::assertSame([], $service->selectedInfo($state)); self::assertNull($service->codeSpec($state));
        try { $service->request($state, $smsId); self::fail('Withdrawn method ID.'); }
        catch (MessengerException $error) { self::assertSame('login_two_factor_method_unavailable', $error->reason); }
    }

    public function testNotificationFailureUnknownAndRateLimitedDoNotRetryOrSelect(): void
    {
        foreach ([new WebLoginResponse(429, ''), self::response(['xfb_two_step_verification_send_notification' => ['is_success' => false, 'error_message' => 'PRIVATE-REMOTE']]), new WebLoginResponse(200, '{}')] as $response) {
            $transport = new MethodFixtureTransport([self::response(self::picker()), $response]); $service = new TwoFactorMethodService($transport); $state = $service->discover(self::prepare($service));
            try { $service->request($state, self::id($service, $state, 'sms')); self::fail('Notification must be confirmed.'); }
            catch (MessengerException $error) { self::assertContains($error->reason, ['login_rate_limited', 'login_two_factor_send_rejected', 'login_two_factor_protocol_unavailable']); self::assertStringNotContainsString('PRIVATE-REMOTE', $error->getMessage()); }
            self::assertSame('totp', $service->selectedInfo($state)['type']); self::assertCount(2, $transport->requests);
        }
    }

    public function testOnlySupportedNumericCodeMethodsReachTransport(): void
    {
        $transport = new MethodFixtureTransport(); $service = new TwoFactorMethodService($transport); $state = self::prepare($service); $spec = $service->codeSpec($state); self::assertNotNull($spec);
        foreach (['TOTP' => ['1234567', 'abc123', '12345'], 'SMS' => ['12345', '123456789', 'ABC123'], 'EMAIL' => ['12345', '000000000', 'x01234'], 'BACKUP_CODES' => ['123456']] as $method => $codes) foreach ($codes as $code) {
            $changed = $spec; $changed['variables']['method'] = $method;
            try { (new TwoFactorService($transport))->submit($changed, $code); self::fail('Invalid/unsupported code.'); }
            catch (MessengerException $error) { self::assertContains($error->reason, ['login_two_factor_invalid_code', 'login_two_factor_protocol_unavailable']); }
        }
        self::assertSame([], $transport->requests);
    }

    public function testBundleResourcesIncludePickerNotificationApprovalAndSkipUnrelatedContent(): void
    {
        $service = new TwoFactorMethodService(new MethodFixtureTransport());
        $maps = ['rsrcMap' => ['picker' => ['type' => 'js', 'src' => 'https://static.xx.fbcdn.net/rsrc.php/v4/picker.js'], 'send' => ['type' => 'js', 'src' => 'https://static.xx.fbcdn.net/rsrc.php/v4/send.js'], 'evil' => ['type' => 'js', 'src' => 'https://evil.example/private.js']],
            'compMap' => ['TwoStepVerificationChallengePickerDialog.react' => ['r' => ['picker']], 'useTwoStepVerificationSendCodeMutation.graphql' => ['r' => ['send']], 'TwoStepVerificationUpdateLogin.react' => ['r' => ['combined', 'evil']], 'UnrelatedAds.react' => ['r' => ['unrelated']]]];
        $html = '<script type="application/json">' . json_encode($maps, JSON_THROW_ON_ERROR) . '</script><script src="https://static.xx.fbcdn.net/rsrc.php/v4/approval.js" data-bootloader-hash="combined"></script>';
        self::assertSame(['https://static.xx.fbcdn.net/rsrc.php/v4/picker.js', 'https://static.xx.fbcdn.net/rsrc.php/v4/approval.js', 'https://static.xx.fbcdn.net/rsrc.php/v4/send.js'], $service->bundleUrls($html));
    }

    public function testAnUnsupportedDefaultCanStillDiscoverSupportedAlternatives(): void
    {
        $transport = new MethodFixtureTransport([self::response(self::picker())]);
        $service = new TwoFactorMethodService($transport);
        $state = $service->prepare(new WebLoginResponse(200, str_replace('TOTP', 'PASSKEY', self::page())), self::url(), [self::operations()]);
        self::assertNotNull($state); self::assertSame([], $service->methods($state));
        self::assertNull($service->codeSpec($state));
        $state = $service->discover($state);
        self::assertSame(['totp', 'sms', 'email', 'app_approval'], array_column($service->methods($state), 'type'));
        self::assertNull($service->codeSpec($state), 'Discovery cannot select or bypass the unsupported default.');
        self::assertCount(1, $transport->requests);
    }

    public function testApprovalUsesObservedRememberBrowserRouteOnlyWhenCurrentRootAndFeaturePermit(): void
    {
        foreach ([false => ['XCometTwoStepVerificationTwoFactorRememberBrowserControllerRouteBuilder', '/two_factor/remember_browser/'], true => ['XPolarisRememberBrowserControllerRouteBuilder', '/accounts/login/two_step_verification/remember_browser/']] as $gate => [$module, $path]) {
            $transport = new MethodFixtureTransport([self::response(self::picker()), self::response(['xfb_two_step_verification_complete_if_approval_state_allowed' => ['state' => 'ALLOWED']])]);
            $service = new TwoFactorMethodService($transport);
            $page = self::page(false);
            self::assertNotSame(self::page(), $page);
            $page .= '<script type="application/json">' . json_encode(['gkxData' => ['22979' => ['result' => (bool) $gate]]], JSON_THROW_ON_ERROR) . '</script>';
            $source = '__d("' . $module . '",["jsRouteBuilder"],(function(t,n,r,o,a,i,l){var e=r("jsRouteBuilder")("' . $path . '",Object.freeze({}),void 0);l.default=e}),98);';
            $state = $service->prepare(new WebLoginResponse(200, $page), self::url(), [self::operations(), $source]); self::assertNotNull($state);
            $state = $service->discover($state); $state = $service->request($state, self::id($service, $state, 'app_approval'));
            $result = $service->approval($state);
            self::assertTrue($result['approved']);
            self::assertSame('https://www.facebook.com' . $path . '?encrypted_context=private-current-context&next=%2Fmessages%2F', $result['next']);
            self::assertStringNotContainsString('private-current-context', json_encode($service->methods($state), JSON_THROW_ON_ERROR));
        }
    }

    public function testApprovalDoesNotInventRememberBrowserContinuationWithoutProof(): void
    {
        $transport = new MethodFixtureTransport([self::response(self::picker()), self::response(['xfb_two_step_verification_complete_if_approval_state_allowed' => ['state' => 'ALLOWED']])]); $service = new TwoFactorMethodService($transport);
        $page = self::page(false);
        $state = $service->prepare(new WebLoginResponse(200, $page), self::url(), [self::operations()]); self::assertNotNull($state);
        $state = $service->discover($state); $state = $service->request($state, self::id($service, $state, 'app_approval'));
        $result = $service->approval($state); self::assertTrue($result['approved']); self::assertNull($result['next']);
        self::assertCount(2, $transport->requests); // Owner must independently verify final identity.
    }

    public function testChangedMalformedAndAmbiguousNativePayloadsFailClosed(): void
    {
        foreach ([['xfb_two_step_verification_challenge_contact_points' => ['contact_points' => 'malformed']], ['xfb_two_step_verification_send_rate_limit_cooldown' => ['rate_limits' => 'malformed']], ['xfb_two_step_verification_afad_polling_interval' => ['polling_interval' => 0]], ['xfb_two_step_verification_afad_polling_interval' => ['polling_interval' => 5000]]] as $override) {
            $transport = new MethodFixtureTransport([self::response(array_replace(self::picker(), $override))]); $service = new TwoFactorMethodService($transport);
            try { $service->discover(self::prepare($service)); self::fail('Malformed native payload.'); }
            catch (MessengerException $error) { self::assertSame('login_two_factor_protocol_unavailable', $error->reason); }
        }
        $picker = self::picker(); $entry = $picker['xfb_two_factor_login_methods']['methods'][0];
        $picker['xfb_two_factor_login_methods']['methods'][] = $entry;
        $transport = new MethodFixtureTransport([self::response($picker)]); $service = new TwoFactorMethodService($transport);
        self::assertCount(4, $service->methods($service->discover(self::prepare($service))));
        $picker['xfb_two_factor_login_methods']['methods'][4]['is_disabled'] = true;
        $transport = new MethodFixtureTransport([self::response($picker)]); $service = new TwoFactorMethodService($transport);
        try { $service->discover(self::prepare($service)); self::fail('Conflicting method enabled state.'); }
        catch (MessengerException $error) { self::assertSame('login_two_factor_protocol_unavailable', $error->reason); }
    }

    /** @return array<string,mixed> */
    private static function prepare(TwoFactorMethodService $service): array
    {
        $state = $service->prepare(new WebLoginResponse(200, self::page()), self::url(), [self::operations()]); self::assertNotNull($state); return $state;
    }
    /** @param array<string,mixed> $state */
    private static function id(TwoFactorMethodService $service, array $state, string $type): string
    {
        foreach ($service->methods($state) as $item) if ($item['type'] === $type) return $item['id'];
        throw new RuntimeException('Fixture type missing.');
    }
    /** @param array<string,string> $fields @return array<string,mixed> */
    private static function variables(array $fields): array { return json_decode($fields['variables'], true, 64, JSON_THROW_ON_ERROR); }
    /** @param array<string,mixed> $data */
    private static function response(array $data): WebLoginResponse { return new WebLoginResponse(200, json_encode(['data' => $data], JSON_THROW_ON_ERROR)); }
    private static function url(): string { return 'https://www.facebook.com/two_step_verification/?flow=two_factor_login&encrypted_context=private-current-context&next=%2Fmessages%2F'; }
    private static function operations(): string
    {
        $result = '';
        foreach (['TwoStepVerificationChallengePickerDialogQuery', 'useTwoStepVerificationSendCodeMutation', 'useTwoFactorLoginValidateCodeMutation', 'TwoStepVerificationUpdateLoginMutation'] as $i => $name) $result .= '__d("' . $name . '_facebookRelayOperation",[],(function(t,n,r,o,a,i){a.exports="9910000000000000' . ($i + 1) . '"}),null);';
        return $result;
    }
    private static function page(bool $skipRemember = true): string
    {
        return '<script type="application/json">' . json_encode(['define' => [['DTSGInitialData', [], ['token' => 'private-csrf-token'], 1], ['LSD', [], ['token' => 'private-lsd-token'], 2], ['RelayAPIConfigDefaults', [], ['graphURI' => '/api/graphql/'], 3], ['CurrentUserInitialData', [], ['USER_ID' => '0'], 4]],
            'payload' => json_encode(['data' => ['xfb_two_factor_login_default_method' => ['method' => ['method' => 'TOTP']], 'xfb_two_step_verification_skip_remember_browser' => $skipRemember]], JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . '</script>';
    }
    /** @return array<string,mixed> */
    private static function picker(): array
    {
        return ['xfb_two_factor_login_methods' => ['methods' => [['method' => 'TOTP', 'method_representation' => null, 'is_disabled' => false], ['method' => 'SMS', 'method_representation' => '+55 *** **1234', 'is_disabled' => false], ['method' => 'EMAIL', 'method_representation' => 'm***@example.test', 'is_disabled' => false], ['method' => 'APPROVE_FROM_ANOTHER_DEVICE', 'method_representation' => null, 'is_disabled' => false]]],
            'xfb_two_step_verification_afad_polling_interval' => ['polling_interval' => 7], 'xfb_two_step_verification_send_rate_limit_cooldown' => ['rate_limits' => [['challenge' => 'SMS', 'cooldown_seconds' => 180], ['challenge' => 'EMAIL', 'cooldown_seconds' => 60]]]];
    }
}

final class MethodFixtureTransport implements WebLoginTransport
{
    /** @var list<array{string,string,array<string,string>}> */ public array $requests = [];
    /** @param list<WebLoginResponse> $responses */ public function __construct(private array $responses = []) {}
    public function request(string $method, string $url, array $form = []): WebLoginResponse
    {
        $this->requests[] = [$method, $url, $form];
        return array_shift($this->responses) ?? throw new RuntimeException('Unexpected fixture request.');
    }
    public function cookies(): array { return []; }
    public function clear(): void {}
}
