<?php
declare(strict_types=1);

namespace MVO\Facebook\Service;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\WebGraphQL;

/** Source-established Web reads only. A contract does not prove account permissions. */
final class AdsService
{
    private const ACCOUNTS = 'useFetchAdAccountsForL4ContextQuery';
    private const ACCOUNT = 'useFetchAdAccountsNameByIdQuery';
    private const CAMPAIGNS = 'AdsMgmtCampaignGroupDynamicStoreSourceTier2ServerQuery';
    private const ADSETS = 'AdsManagerLiveDataCampaignQuery';
    private const ADS = 'AdsMgmtAdgroupDynamicStoreSourceTier1ServerQuery';
    private const PREVIEWS = 'AdsMgmtAdgroupDynamicStoreSourceTier3V2ServerQuery';
    private const INSIGHTS = 'AdsInsightsAdInsightsShadowValidationQuery';
    private const DEFAULT_METRICS = ['SPEND', 'IMPRESSIONS', 'REACH', 'COST_PER_RESULT', 'PERFORMANCE_INDICATOR', 'ANCHOR_EVENT_ATTRIBUTION_SETTING'];

    public function __construct(private readonly WebGraphQL $graph) {}

    /** Context-scoped choices: first=2 is fixed in the observed document; no pagination is established.
     * @return array{items:list<array{id:string,name:?string,currency:?string,status:int|string|null}>,partial:bool,exhaustive:bool,maximum_items:int,source:string}
     */
    public function accounts(): array
    {
        // The omitted LWI branch has auto_create:true and must never be selected by this read.
        $data = $this->graph->execute(self::ACCOUNTS, ['skip' => false, 'skipLwiDefaultAdAccount' => true]);
        if (!array_key_exists('maiba_ad_accounts', $data)) {
            $this->invalidResponse();
        }
        $connection = $data['maiba_ad_accounts'];
        if ($connection === null) {
            $edges = [];
        } elseif (is_array($connection) && is_array($connection['edges'] ?? null) && array_is_list($connection['edges'])) {
            $edges = $connection['edges'];
        } else {
            $this->invalidResponse();
        }
        if (count($edges) > 2) {
            $this->invalidResponse();
        }
        $items = [];
        foreach ($edges as $edge) {
            if (!is_array($edge) || !array_key_exists('node', $edge)) {
                $this->invalidResponse();
            }
            if ($edge['node'] === null) {
                continue;
            }
            if (!is_array($edge['node'])) {
                $this->invalidResponse();
            }
            $node = $edge['node'];
            $id = $node['legacy_account_id'] ?? null;
            if (!is_string($id) || !self::validId($id)) {
                $this->invalidResponse();
            }
            $items[] = $this->accountRow($id, $node);
        }
        return ['items' => $items, 'partial' => true, 'exhaustive' => false, 'maximum_items' => 2, 'source' => self::ACCOUNTS];
    }

    /** @return array{id:string,name:?string,currency:?string,status:int|string|null}|null */
    public function account(string $id): ?array
    {
        self::requireId($id);
        $data = $this->graph->execute(self::ACCOUNT, ['adAccountID' => $id, 'skip' => false]);
        if (!array_key_exists('maiba_ad_account', $data)) {
            $this->invalidResponse();
        }
        if ($data['maiba_ad_account'] === null) {
            return null;
        }
        if (!is_array($data['maiba_ad_account'])) {
            $this->invalidResponse();
        }
        return $this->accountRow($id, $data['maiba_ad_account']);
    }

    /** Known-ID campaign delivery/status and structure; this is not account-wide enumeration.
     * @param list<string> $ids
     * @return list<array<string,mixed>>
     */
    public function campaigns(array $ids): array
    {
        return $this->nodes($this->graph->execute(self::CAMPAIGNS, ['ids' => self::ids($ids)]), ['AdCampaignGroup']);
    }

    /** @param list<string> $ids
     * @return list<array<string,mixed>>
     */
    public function adsets(array $ids): array
    {
        return $this->nodes($this->graph->execute(self::ADSETS, ['ids' => self::ids($ids), 'skip_data_transform' => false,
            '__relay_internal__pv__GraphQLIncludeAdgroupActiveStudiesrelayprovider' => true]), ['AdCampaign']);
    }

    /** Ad delivery/status metadata; no creative editor or publication is implemented.
     * @param list<string> $ids
     * @return list<array<string,mixed>>
     */
    public function ads(array $ids): array
    {
        return $this->nodes($this->graph->execute(self::ADS, ['ids' => self::ids($ids)]), ['Adgroup']);
    }

    /** @param list<string> $ids
     * @return list<array<string,mixed>>
     */
    public function previews(array $ids): array
    {
        return $this->nodes($this->graph->execute(self::PREVIEWS, ['ids' => self::ids($ids)]), ['Adgroup']);
    }

    /** Aggregate insights only; preserves raw monetary amounts and metric values.
     * @param list<string> $ids
     * @param list<string> $metrics
     * @return list<array<string,mixed>>
     */
    public function insights(array $ids, string $datePreset = 'today', array $metrics = self::DEFAULT_METRICS): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $datePreset)) {
            throw new \InvalidArgumentException('Use an upstream advertising date preset');
        }
        return $this->insightQuery($ids, $datePreset, null, null, $metrics);
    }

    /** Inclusive input dates become the observed [since midnight, day-after-until midnight) variables.
     * @param list<string> $ids
     * @param list<string> $metrics
     * @return list<array<string,mixed>>
     */
    public function insightsRange(array $ids, string $since, string $until, array $metrics = self::DEFAULT_METRICS): array
    {
        $start = self::date($since);
        $end = self::date($until);
        if ($end < $start || $end->format('Y') === '9999') {
            throw new \InvalidArgumentException('Invalid advertising insight date range');
        }
        return $this->insightQuery($ids, null, $since . 'T00:00', $end->modify('+1 day')->format('Y-m-d') . 'T00:00', $metrics);
    }

    /** Availability describes established SDK contracts, not account permissions or successful remote calls.
     * @return array{read_only:bool,validated_in_account:bool,account_list_exhaustive:bool,operations:array<string,array{available:bool,document:?string,limit:string}>}
     */
    public function availability(): array
    {
        return ['read_only' => true, 'validated_in_account' => false, 'account_list_exhaustive' => false, 'operations' => [
            'accounts' => ['available' => true, 'document' => self::ACCOUNTS, 'limit' => 'Context-scoped first=2; no pagination or complete account inventory established'],
            'account' => ['available' => true, 'document' => self::ACCOUNT, 'limit' => 'Exact legacy account ID; requires account access'],
            'campaigns' => ['available' => true, 'document' => self::CAMPAIGNS, 'limit' => 'Known IDs only; delivery/status and structure metadata'],
            'adsets' => ['available' => true, 'document' => self::ADSETS, 'limit' => 'Known IDs only; raw server fields rather than client Relay resolver output'],
            'ads' => ['available' => true, 'document' => self::ADS, 'limit' => 'Known IDs only; delivery/status metadata'],
            'previews' => ['available' => true, 'document' => self::PREVIEWS, 'limit' => 'Existing preview link; does not create or publish a creative'],
            'insights' => ['available' => true, 'document' => self::INSIGHTS, 'limit' => 'Aggregate first=1, filter_zero_delivery=true; no arbitrary breakdowns or account-level reporting'],
            'accounts.all' => ['available' => false, 'document' => null, 'limit' => 'Complete paginated Web account chooser contract not yet established'],
            'campaigns.create' => ['available' => false, 'document' => null, 'limit' => 'No established Web mutation; Marketing Graph API disabled'],
            'adsets.create' => ['available' => false, 'document' => null, 'limit' => 'No established Web mutation; Marketing Graph API disabled'],
            'ads.create' => ['available' => false, 'document' => null, 'limit' => 'No established Web mutation; Marketing Graph API disabled'],
            'creatives.create' => ['available' => false, 'document' => null, 'limit' => 'No established Web mutation; Marketing Graph API disabled'],
            'budget.update' => ['available' => false, 'document' => null, 'limit' => 'No established Web mutation; Marketing Graph API disabled'],
            'status.update' => ['available' => false, 'document' => null, 'limit' => 'No established Web mutation; Marketing Graph API disabled'],
            'publish' => ['available' => false, 'document' => null, 'limit' => 'No established Web mutation; Marketing Graph API disabled'],
        ]];
    }

    /** @param array<string,mixed> $row
     * @return array{id:string,name:?string,currency:?string,status:int|string|null}
     */
    private function accountRow(string $id, array $row): array
    {
        $name = $row['name'] ?? null;
        $currency = $row['currency'] ?? null;
        $status = $row['account_status'] ?? null;
        if (($name !== null && !is_string($name)) || ($currency !== null && !is_string($currency)) || ($status !== null && !is_string($status) && !is_int($status))) {
            $this->invalidResponse();
        }
        return ['id' => $id, 'name' => $name, 'currency' => $currency, 'status' => $status];
    }

    /** @param array<string,mixed> $data
     * @param list<string> $types
     * @return list<array<string,mixed>>
     */
    private function nodes(array $data, array $types): array
    {
        if (!is_array($data['nodes'] ?? null) || !array_is_list($data['nodes']) || count($data['nodes']) > 1000) {
            $this->invalidResponse();
        }
        $nodes = [];
        foreach ($data['nodes'] as $node) {
            if ($node === null) {
                continue;
            }
            if (!is_array($node) || !is_string($node['id'] ?? null) || !self::validId($node['id']) || !in_array($node['__typename'] ?? null, $types, true)) {
                $this->invalidResponse();
            }
            $nodes[] = $node;
        }
        return $nodes;
    }

    /** @param list<string> $ids
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        if (!array_is_list($ids) || $ids === [] || count($ids) > 100) {
            throw new \InvalidArgumentException('Use a list of 1 to 100 exact advertising object IDs');
        }
        foreach ($ids as $id) {
            if (!is_string($id)) {
                throw new \InvalidArgumentException('Preserve advertising object IDs as strings');
            }
            self::requireId($id);
        }
        return $ids;
    }

    private static function validId(string $id): bool
    {
        return preg_match('/^[1-9][0-9]{0,24}$/D', $id) === 1;
    }

    private static function requireId(string $id): void
    {
        if (!self::validId($id)) {
            throw new \InvalidArgumentException('Use an exact native advertising object ID');
        }
    }

    private static function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || $date->format('Y-m-d') !== $value || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))) {
            throw new \InvalidArgumentException('Use a valid YYYY-MM-DD advertising insight date');
        }
        return $date;
    }

    /** @param list<string> $ids
     * @param list<string> $metrics
     * @return list<array<string,mixed>>
     */
    private function insightQuery(array $ids, ?string $datePreset, ?string $from, ?string $to, array $metrics): array
    {
        $ids = self::ids($ids);
        if (!array_is_list($metrics) || $metrics === [] || count($metrics) > 7) {
            throw new \InvalidArgumentException('Use only the established advertising metric enum values');
        }
        foreach ($metrics as $metric) {
            if (!is_string($metric) || !in_array($metric, [...self::DEFAULT_METRICS, 'RESULTS'], true)) {
                throw new \InvalidArgumentException('Use only the established advertising metric enum values');
            }
        }
        return $this->nodes($this->graph->execute(self::INSIGHTS, ['ids' => $ids, 'datePreset' => $datePreset, 'from' => $from, 'to' => $to, 'metrics' => $metrics]), ['AdCampaignGroup', 'AdCampaign', 'Adgroup']);
    }

    private function invalidResponse(): never
    {
        throw new FacebookException('facebook_ads_invalid_response', 'Facebook did not return the established advertising response shape');
    }
}
