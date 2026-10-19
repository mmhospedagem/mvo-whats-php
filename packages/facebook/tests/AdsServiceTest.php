<?php
declare(strict_types=1);

namespace MVO\Facebook\Tests;

use MVO\Facebook\Catalog\AdsDocuments;
use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\{DocumentRegistry, WebGraphQL};
use MVO\Facebook\Service\AdsService;
use MVO\Messenger\Auth\WebSession;
use MVO\Messenger\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/FacebookException.php';
require_once __DIR__ . '/../src/Protocol/Document.php';
require_once __DIR__ . '/../src/Protocol/DocumentRegistry.php';
require_once __DIR__ . '/../src/Protocol/GraphQLDecoder.php';
require_once __DIR__ . '/../src/Protocol/WebGraphQL.php';
require_once __DIR__ . '/../src/Catalog/AdsDocuments.php';
require_once __DIR__ . '/../src/Service/AdsService.php';

final class AdsServiceTest extends TestCase
{
    private function service(AdsFixtureSession $session): AdsService
    {
        return new AdsService(new WebGraphQL($session, new DocumentRegistry(AdsDocuments::documents())));
    }

    private function assertVariables(array $expected, AdsFixtureSession $session): void
    {
        $actual = $session->variables();ksort($expected);ksort($actual);
        self::assertSame($expected, $actual);
    }

    public function testCatalogContainsOnlySourcePinnedReadDocuments(): void
    {
        $documents = AdsDocuments::documents();
        self::assertCount(7, $documents);
        foreach ($documents as $document) {
            self::assertSame('query', $document->kind);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $document->sourceSha256);
            self::assertStringStartsWith('https://static.xx.fbcdn.net/rsrc.php/', $document->sourceUrl);
            self::assertStringNotContainsString('?', $document->sourceUrl);
        }
    }

    public function testAccountChoicesPreserveIdsAndExplicitlyRemainPartialWithoutAutoCreation(): void
    {
        $session = new AdsFixtureSession();
        $session->data = ['maiba_ad_accounts' => ['edges' => [['node' => [
            'legacy_account_id' => '9007199254740993', 'name' => 'Synthetic account', 'currency' => 'BRL', 'account_status' => 1,
        ]]]]];
        $result = $this->service($session)->accounts();
        self::assertSame([['id' => '9007199254740993', 'name' => 'Synthetic account', 'currency' => 'BRL', 'status' => 1]], $result['items']);
        self::assertTrue($result['partial']);
        self::assertFalse($result['exhaustive']);
        self::assertSame(2, $result['maximum_items']);
        self::assertSame(['skip' => false, 'skipLwiDefaultAdAccount' => true], $session->variables());
        self::assertSame('useFetchAdAccountsForL4ContextQuery', $session->last()['fb_api_req_friendly_name']);
        self::assertSame('27589643617296549', $session->last()['doc_id']);
        self::assertCount(1, $session->requests);
    }

    public function testGenericDocumentCannotEnableDefaultAccountAutoCreation(): void
    {
        $session = new AdsFixtureSession();
        $graph = new WebGraphQL($session, new DocumentRegistry(AdsDocuments::documents()));
        try {
            $graph->execute('useFetchAdAccountsForL4ContextQuery', ['skip' => false, 'skipLwiDefaultAdAccount' => false]);
            self::fail('The read catalog must not enable default account creation');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $session->requests);
        }
        $document = (new DocumentRegistry(AdsDocuments::documents()))->get('useFetchAdAccountsForL4ContextQuery');
        self::assertSame(['skip' => null, 'skipLwiDefaultAdAccount' => null], $document->defaults);
        self::assertSame(['skipLwiDefaultAdAccount' => true], $document->fixedVariables);
        self::assertSame(['skip' => false, 'skipLwiDefaultAdAccount' => true], $document->arguments(['skip' => false]));
    }

    public function testAccountLookupUsesTheExactLegacyIdAndProjectsOnlyItsKnownFields(): void
    {
        $session = new AdsFixtureSession();
        $session->data = ['maiba_ad_account' => ['name' => 'Account example', 'currency' => 'USD', 'account_status' => 'ACTIVE', 'private_extra' => 'PRIVATE']];
        self::assertSame(['id' => '9007199254740993', 'name' => 'Account example', 'currency' => 'USD', 'status' => 'ACTIVE'], $this->service($session)->account('9007199254740993'));
        self::assertSame(['adAccountID' => '9007199254740993', 'skip' => false], $session->variables());
        self::assertSame('27797550453214383', $session->last()['doc_id']);
    }

    public function testNullAccountAccessDoesNotBecomeAnExhaustiveEmptyInventory(): void
    {
        $session = new AdsFixtureSession();$service = $this->service($session);
        $session->data = ['maiba_ad_accounts' => null];
        self::assertSame([], $service->accounts()['items']);
        self::assertFalse($service->accounts()['exhaustive']);
        $session->data = ['maiba_ad_account' => null];
        self::assertNull($service->account('10001'));
    }

    public function testKnownIdReadsKeepTheNativeCampaignAdsetAdTypesAndRawServerFields(): void
    {
        $session = new AdsFixtureSession();$service = $this->service($session);
        foreach ([
            ['campaigns', 'AdCampaignGroup', 'AdsMgmtCampaignGroupDynamicStoreSourceTier2ServerQuery'],
            ['adsets', 'AdCampaign', 'AdsManagerLiveDataCampaignQuery'],
            ['ads', 'Adgroup', 'AdsMgmtAdgroupDynamicStoreSourceTier1ServerQuery'],
            ['previews', 'Adgroup', 'AdsMgmtAdgroupDynamicStoreSourceTier3V2ServerQuery'],
        ] as [$method, $type, $document]) {
            $row = ['id' => '10001', '__typename' => $type, 'effective_status' => 'PAUSED', 'preview_link' => null];
            $session->data = ['nodes' => [$row, null]];
            self::assertSame([$row], $service->{$method}(['10001']));
            self::assertSame($document, $session->last()['fb_api_req_friendly_name']);
            self::assertSame(['10001'], $session->variables()['ids']);
            if ($method === 'adsets') {
                self::assertSame(false, $session->variables()['skip_data_transform']);
                self::assertTrue($session->variables()['__relay_internal__pv__GraphQLIncludeAdgroupActiveStudiesrelayprovider']);
            }
        }
    }

    public function testAggregateInsightsPreserveRawMoneyAndCounterPrecision(): void
    {
        $session = new AdsFixtureSession();
        $row = ['id' => '10001', '__typename' => 'Adgroup', 'ad_insights' => ['nodes' => [[
            'spend' => '12300', 'impressions_as_string' => '9007199254740993', 'cost_per_result' => '110',
            'metrics' => [['metric_id' => 'performance_indicator', 'metric_value_raw' => 'link_click']],
        ]]]];
        $session->data = ['nodes' => [$row]];
        self::assertSame([$row], $this->service($session)->insights(['10001'], 'today', ['SPEND', 'IMPRESSIONS', 'RESULTS']));
        $this->assertVariables(['ids' => ['10001'], 'datePreset' => 'today', 'from' => null, 'to' => null, 'metrics' => ['SPEND', 'IMPRESSIONS', 'RESULTS']], $session);
        self::assertSame('28492576643768953', $session->last()['doc_id']);
    }

    public function testInsightRangeUsesTheObservedExclusiveDayAfterUntilIncludingLeapDay(): void
    {
        $session = new AdsFixtureSession();$session->data = ['nodes' => []];
        self::assertSame([], $this->service($session)->insightsRange(['10001'], '2024-02-28', '2024-02-29', ['REACH']));
        $this->assertVariables(['ids' => ['10001'], 'datePreset' => null, 'from' => '2024-02-28T00:00', 'to' => '2024-03-01T00:00', 'metrics' => ['REACH']], $session);
    }

    public function testInvalidIdsDatesAndMetricsAreRejectedBeforeIo(): void
    {
        $session = new AdsFixtureSession();$service = $this->service($session);
        foreach ([
            static fn() => $service->account('act_10001'),
            static fn() => $service->campaigns([]),
            static fn() => $service->ads(['field' => '10001']),
            static fn() => $service->ads([10001]),
            static fn() => $service->ads(['10001@facebook']),
            static fn() => $service->insights(['10001'], 'today', ['INVENTED_METRIC']),
            static fn() => $service->insights(['10001'], 'today', [[]]),
            static fn() => $service->insights(['10001'], 'today', []),
            static fn() => $service->insightsRange(['10001'], '2024-02-30', '2024-03-01'),
            static fn() => $service->insightsRange(['10001'], '2024-03-01', '2024-02-01'),
            static fn() => $service->insightsRange(['10001'], '2024-01-01', '9999-12-31'),
        ] as $call) {
            try {$call();self::fail('Invalid arguments must be rejected');}
            catch (\InvalidArgumentException) {self::assertCount(0, $session->requests);}
        }
    }

    public function testWrongResponseShapesCannotBeReportedAsSuccessfulInventory(): void
    {
        $session = new AdsFixtureSession();$service = $this->service($session);
        foreach ([
            [],
            ['maiba_ad_accounts' => ['edges' => [['node' => ['legacy_account_id' => 9007199254740993]]]]],
            ['maiba_ad_accounts' => ['edges' => array_fill(0, 3, ['node' => null])]],
            ['maiba_ad_accounts' => ['edges' => [['node' => ['legacy_account_id' => '10001', 'name' => ['PRIVATE']]]]]],
        ] as $data) {
            $session->data = $data;
            try {$service->accounts();self::fail('Invalid inventory must be rejected');}
            catch (FacebookException $error) {self::assertSame('facebook_ads_invalid_response', $error->reason);self::assertStringNotContainsString('PRIVATE', $error->getMessage());}
        }
        $session->data = ['nodes' => [['id' => '10001', '__typename' => 'AdAccount']]];
        $this->expectException(FacebookException::class);$service->insights(['10001']);
    }

    public function testPermissionFailureMakesOneWebRequestAndNeverFallsBackToGraph(): void
    {
        $session = new AdsFixtureSession();$session->error = true;
        try {$this->service($session)->accounts();self::fail('Expected permission failure');}
        catch (FacebookException $error) {self::assertSame('facebook_operation_rejected', $error->reason);self::assertStringNotContainsString('PRIVATE', $error->getMessage());}
        self::assertCount(1, $session->requests);
        self::assertSame('https://www.facebook.com/api/graphql/', $session->requests[0]['url']);
    }

    public function testAvailabilityNeverAdvertisesUnsupportedAdvertisingWritesOrRealValidation(): void
    {
        $session = new AdsFixtureSession();$state = $this->service($session)->availability();
        self::assertTrue($state['read_only']);self::assertFalse($state['validated_in_account']);self::assertFalse($state['account_list_exhaustive']);
        foreach (['accounts.all', 'campaigns.create', 'adsets.create', 'ads.create', 'creatives.create', 'budget.update', 'status.update', 'publish'] as $name) {
            self::assertFalse($state['operations'][$name]['available']);self::assertNull($state['operations'][$name]['document']);
        }
        self::assertSame([], $session->requests);
    }

    public function testAnUnregisteredDocumentIsAnExplicitUnavailableOperationWithoutIo(): void
    {
        $session = new AdsFixtureSession();$service = new AdsService(new WebGraphQL($session, new DocumentRegistry()));
        try {$service->accounts();self::fail('Expected unavailable catalog document');}
        catch (FacebookException $error) {self::assertSame('facebook_document_unavailable', $error->reason);}
        self::assertSame([], $session->requests);
    }
}

final class AdsFixtureSession implements WebSession
{
    public array $data = [];
    public bool $error = false;
    public array $requests = [];
    public function accountId(): string {return '10001';}
    public function privateRequestFields(): array {return ['__user' => '10001', 'fb_dtsg' => 'SYNTHETIC_PRIVATE'];}
    public function request(string $method, string $url, array $form = []): HttpResponse
    {
        TestCase::assertSame('POST', $method);TestCase::assertSame('https://www.facebook.com/api/graphql/', $url);
        $this->requests[] = ['url' => $url, 'form' => $form];
        return new HttpResponse(200, json_encode($this->error ? ['errors' => [['message' => 'SYNTHETIC_PRIVATE']]] : ['data' => $this->data], JSON_THROW_ON_ERROR));
    }
    public function last(): array {return $this->requests[array_key_last($this->requests)]['form'];}
    public function variables(): array {return json_decode($this->last()['variables'], true, 32, JSON_THROW_ON_ERROR);}
}
