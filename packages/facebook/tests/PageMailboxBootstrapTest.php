<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\Catalog\PageDocuments;
use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\{PageMailboxBootstrapReader,WebGraphQL};
use MVO\Facebook\Service\InboxService;
use MVO\Facebook\Storage\CommandLedger;
use MVO\Messenger\Auth\WebSession;
use MVO\Messenger\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

final class PageMailboxBootstrapTest extends TestCase
{
    private string $directory; private ?CommandLedger $ledger = null; private ?InboxService $service = null;
    private PageBootstrapTestSession $session;
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mvo-page-context-' . bin2hex(random_bytes(8)); mkdir($this->directory, 0700);
        $this->ledger = new CommandLedger($this->directory . '/commands.sqlite'); $this->session = new PageBootstrapTestSession();
        $this->session->html = self::html();
        $this->service = new InboxService(new WebGraphQL($this->session, PageDocuments::registry(), $this->ledger), $this->session, $this->ledger);
    }
    protected function tearDown(): void
    {
        $this->service = null; $this->ledger?->close(); $this->ledger = null; gc_collect_cycles();
        foreach (glob($this->directory . '/commands.sqlite*') ?: [] as $file) unlink($file); rmdir($this->directory);
    }
    public function testInertReaderUsesDistinctCurrentAppIdsAndNeverReturnsTokens(): void
    {
        $config = (new PageMailboxBootstrapReader())->read(self::html(), '10001', '30003', 'client-owned-device');
        self::assertSame('123', $config->dgwAppId); self::assertSame('456', $config->serverAppId);
        self::assertSame('28761380846847381', $config->schemaVersion); self::assertSame('client-owned-device', $config->deviceId);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($config, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('x-dgw-app-asset_ids=%7B%22PAGE%22%3A%2230003%22%7D', $config->gatewayUrl());
    }
    public function testContextAcquisitionProvesScopeThenOnlyReadsCurrentBizInbox(): void
    {
        $context = $this->service->lightspeedContext('20002', '30003', '40004', static function(array $urls): array { self::fail('No bundle is needed when the current document supplies LSVersion'); });
        self::assertSame('50005', $context['recipient_id']); self::assertSame('https://business.facebook.com', $context['origin']);
        self::assertSame('123', $context['dgw_app_id']); self::assertSame('456', $context['server_app_id']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $context['device_id']);
        self::assertMatchesRegularExpression('/^[1-9][0-9]{1,18}$/D', $context['offline_threading_id']);
        self::assertCount(2, $this->session->requests);
        self::assertSame('POST', $this->session->requests[0]['method']);
        self::assertSame('BizInboxUnifiedThreadsQuery', $this->session->requests[0]['form']['fb_api_req_friendly_name']);
        $variables = json_decode($this->session->requests[0]['form']['variables'], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('30003', $variables['page_id']); self::assertSame('40004', $variables['selected_item_id']);
        self::assertFalse($variables['load_messages']); self::assertFalse($variables['loadThreads']);
        self::assertSame(['method' => 'GET', 'url' => 'https://business.facebook.com/latest/inbox/all?asset_id=30003&selected_item_id=40004', 'form' => []], $this->session->requests[1]);
    }
    public function testSdkDeviceUuidPersistsAcrossLedgerRestartAndAccountsStaySeparate(): void
    {
        $first = $this->service->lightspeedContext('20002', '30003', '40004');
        $second = $this->service->lightspeedContext('20002', '30003', '40004');
        self::assertSame($first['device_id'], $second['device_id']); self::assertNotSame($first['offline_threading_id'], $second['offline_threading_id']);
        $this->service = null; $this->ledger->close(); $this->ledger = new CommandLedger($this->directory . '/commands.sqlite');
        self::assertSame($first['device_id'], $this->ledger->clientDeviceId('10001'));
        self::assertNotSame($first['device_id'], $this->ledger->clientDeviceId('20002'));
    }
    public function testAnonymousLoaderOnlyReceivesCanonicalCurrentDocumentCdnUrls(): void
    {
        $this->session->html = self::html(version: null) . '<script src="https://static.xx.fbcdn.net/rsrc.php/v4/aa/r/current.js?a=1&amp;b=2"></script>'
            . '<script src="https://example.invalid/rsrc.php/private.js"></script><script src="https://static.xx.fbcdn.net/private.js"></script>'
            . '<script src="http://static.xx.fbcdn.net/rsrc.php/insecure.js"></script><script src="https://user@static.xx.fbcdn.net/rsrc.php/credentials.js"></script>';
        $calls = 0;
        $context = $this->service->lightspeedContext('20002', '30003', '40004', static function(array $urls) use (&$calls): array {
            ++$calls; self::assertSame(['https://static.xx.fbcdn.net/rsrc.php/v4/aa/r/current.js?a=1&b=2'], $urls);
            return ['__d("LSVersion",[],function(a,b,c,d,e,f){e.exports="28761380846847381"},66);throw new Error("MUST_NOT_EXECUTE");'];
        });
        self::assertSame(1, $calls); self::assertSame('28761380846847381', $context['schema_version']);
    }
    public function testWrongAccountAndMissingServerAppNeverTriggerArtifactDownload(): void
    {
        foreach ([self::html(user: '99999'), self::html(serverApp: null)] as $html) {
            $this->session->html = $html;
            try { $this->service->lightspeedContext('20002', '30003', '40004', static function(array $urls): array { self::fail('Identity/configuration must be verified before CDN loading'); }); self::fail('Invalid bootstrap accepted'); }
            catch (FacebookException $error) { self::assertContains($error->reason, ['facebook_identity_unconfirmed', 'facebook_page_gateway_config_unavailable']); }
        }
    }
    public function testWrongPageOrDisabledComposerPreventsBootstrapGet(): void
    {
        $this->session->page = '99999';
        try { $this->service->lightspeedContext('20002', '30003', '40004'); self::fail('Wrong Page accepted'); }
        catch (FacebookException $error) { self::assertSame('facebook_inbox_context_unconfirmed', $error->reason); }
        $this->session->page = '30003'; $this->session->disabled = true;
        try { $this->service->lightspeedContext('20002', '30003', '40004'); self::fail('Disabled composer accepted'); }
        catch (FacebookException $error) { self::assertSame('facebook_inbox_reply_unavailable', $error->reason); }
        self::assertCount(2, $this->session->requests); foreach ($this->session->requests as $request) self::assertSame('POST', $request['method']);
    }
    public function testRedirectOrAuthenticationFailureDoesNotRepeatOrFollowRequests(): void
    {
        foreach ([302, 401] as $status) {
            $this->session->status = $status;
            try { $this->service->lightspeedContext('20002', '30003', '40004'); self::fail('Authentication boundary ignored'); }
            catch (FacebookException $error) { self::assertSame('facebook_authentication_required', $error->reason); }
        }
        self::assertCount(4, $this->session->requests);
    }
    public function testPageBootstrapRefusesWrongGatewayOwnerAndConflictingModulesOrVersions(): void
    {
        $cases = [
            [self::html(gatewayUser: '99999'), [], 'facebook_identity_unconfirmed'],
            [self::html() . self::moduleHtml([['ServerAppID', [], ['app_id' => '99999']]]), [], 'facebook_bootstrap_conflict'],
            [self::html(), ['__d("LSVersion",[],function(a,b,c,d,e){e.exports="99999"},66);'], 'facebook_bootstrap_conflict'],
            [self::html(selectedPage: '99999'), [], 'facebook_inbox_selection_mismatch'],
            [self::html(version: null), ['__d("LSVersion",[],function(a,b,c,d,e){e.exports=computeVersion()},66);'], 'facebook_page_schema_unavailable'],
        ];
        foreach ($cases as [$html, $scripts, $reason]) {
            try { (new PageMailboxBootstrapReader())->read($html, '10001', '30003', 'device', $scripts); self::fail('Invalid artifact accepted'); }
            catch (FacebookException $error) { self::assertSame($reason, $error->reason); }
        }
    }
    public function testMalformedMetadataDoesNotRaiseWarningsOrExecuteJavascript(): void
    {
        $html = '<script>globalThis.__MVO_MUST_NOT_RUN=true;</script><script>{"define":"malformed"}</script>' . self::html(version: null)
            . '<script>{"requestPayload":"{\"database\":205,\"version\":\"28761380846847381\"}"}</script>';
        self::assertSame('28761380846847381', (new PageMailboxBootstrapReader())->read($html, '10001', '30003', 'device')->schemaVersion);
        $this->expectException(FacebookException::class);
        (new PageMailboxBootstrapReader())->read(str_repeat('x', 16777217), '10001', '30003', 'device');
    }
    private static function html(string $user = '10001', ?string $serverApp = '456', ?string $version = '28761380846847381', string $gatewayUser = '10001', ?string $selectedPage = null): string
    {
        $definitions = [['CurrentUserInitialData', [], ['USER_ID' => $user, 'ACCOUNT_ID' => $user]], ['DGWWebConfig', [], ['appId' => '123', 'appVersion' => '1', 'fbId' => $gatewayUser]], ['DTSGInitialData', [], ['token' => 'SYNTHETIC_PRIVATE']]];
        if ($serverApp !== null) $definitions[] = ['ServerAppID', [], ['app_id' => $serverApp]];
        if ($version !== null) $definitions[] = ['LSVersion', [], $version];
        $html = self::moduleHtml($definitions);
        if ($selectedPage !== null) $html .= '<script>' . json_encode(['__bbox' => ['result' => ['data' => ['xfb_biz_inbox_unified_thread_list' => ['selected_page' => ['id' => $selectedPage]]]]]], JSON_THROW_ON_ERROR) . '</script>';
        return $html;
    }
    /** @param list<array<mixed>> $definitions */
    private static function moduleHtml(array $definitions): string { return '<script type="application/json" data-sjs>' . json_encode(['require' => [['ScheduledServerJS', 'handle', null, [['__bbox' => ['define' => $definitions]]]]]], JSON_THROW_ON_ERROR) . '</script>'; }
}

final class PageBootstrapTestSession implements WebSession
{
    /** @var list<array{method:string,url:string,form:array<string,string>}> */ public array $requests = [];
    public string $html = ''; public int $status = 200; public string $page = '30003'; public bool $disabled = false;
    public function accountId(): string { return '10001'; }
    public function privateRequestFields(): array { return ['__user' => '10001', 'fb_dtsg' => 'SYNTHETIC_PRIVATE']; }
    public function request(string $method, string $url, array $form = []): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'form' => $form];
        if ($method === 'GET') return new HttpResponse($this->status, $this->html);
        return new HttpResponse(200, json_encode(['data' => ['xfb_biz_inbox_unified_thread_list' => ['selected_page' => ['id' => $this->page],
            'selected_thread' => ['selected_message_thread' => ['id' => base64_encode('MessageThread:40004'), 'thread_key' => ['thread_fbid' => '40004', 'other_user_id' => '50005'],
                'composer_input_disabled' => $this->disabled, 'cannot_reply_reason' => null]]]]], JSON_THROW_ON_ERROR));
    }
}
