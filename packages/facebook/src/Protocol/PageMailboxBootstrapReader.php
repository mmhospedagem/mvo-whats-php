<?php
declare(strict_types=1);
namespace MVO\Facebook\Protocol;

use MVO\Facebook\FacebookException;
use MVO\Messenger\Protocol\{BootstrapParser,PageMailboxConfig,TaskBuilder};

/** Bounded, inert BizWeb bootstrap reader. Never executes scripts or imports browser storage. */
final class PageMailboxBootstrapReader
{
    private int $nodes = 0;
    /** @var array<string,mixed> */ private array $modules = [];
    private ?string $version = null;
    private ?string $selectedPage = null;

    /** Scope is independently verified by InboxService's selected-page/thread query.
     * If the HTML also includes a selected Page, it must agree exactly.
     * @param list<string> $publicScripts Public CDN bytes actually linked by the current document.
     */
    public function read(#[\SensitiveParameter] string $html, string $expectedAccount, string $pageId, string $deviceId,
        array $publicScripts = [], string $origin = 'https://business.facebook.com'): PageMailboxConfig
    {
        TaskBuilder::decimalId($expectedAccount); TaskBuilder::decimalId($pageId);
        if (strlen($html) > 16777216 || count($publicScripts) > 64) throw new FacebookException('facebook_document_limit', 'The Page inbox bootstrap exceeds its local parsing limit');
        $this->nodes = 0; $this->modules = []; $this->version = null; $this->selectedPage = null;
        preg_match_all('~<script\b[^>]*>(.*?)</script\s*>~is', $html, $matches);
        foreach ($matches[1] as $script) {
            $script = trim($script); if ($script === '' || !in_array($script[0], ['{', '['], true)) continue;
            try { $value = json_decode($script, true, 128, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); } catch (\JsonException) { continue; }
            $this->walk($value, 0);
        }
        $identity = $this->modules['CurrentUserInitialData'] ?? null;
        if (!is_array($identity) || self::id($identity['USER_ID'] ?? null) !== $expectedAccount
            || isset($identity['ACCOUNT_ID']) && self::id($identity['ACCOUNT_ID']) !== $expectedAccount) throw new FacebookException('facebook_identity_unconfirmed', 'The Page inbox bootstrap did not confirm the current authenticated account');
        if ($this->selectedPage !== null && $this->selectedPage !== $pageId) throw new FacebookException('facebook_inbox_selection_mismatch', 'The Page inbox document selected a different Page');
        $dgw = $this->modules['DGWWebConfig'] ?? null; $server = $this->modules['ServerAppID'] ?? null;
        $dgwApp = is_array($dgw) ? self::id($dgw['appId'] ?? null) : null;
        $serverApp = is_array($server) ? self::id($server['app_id'] ?? null) : null;
        $appVersion = is_array($dgw) && (is_string($dgw['appVersion'] ?? null) || is_int($dgw['appVersion'] ?? null)) ? (string)$dgw['appVersion'] : null;
        if ($dgwApp === null || $serverApp === null || $appVersion === null || !preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $appVersion)) throw new FacebookException('facebook_page_gateway_config_unavailable', 'The current Page bootstrap did not provide DGWWebConfig and ServerAppID');
        if (isset($dgw['fbId']) && $dgw['fbId'] !== '0' && $dgw['fbId'] !== 0 && self::id($dgw['fbId']) !== $expectedAccount) throw new FacebookException('facebook_identity_unconfirmed', 'The Page gateway configuration belongs to a different account');
        $total = 0;
        foreach ($publicScripts as $script) {
            if (!is_string($script) || strlen($script) > 8388608 || ($total += strlen($script)) > 33554432) throw new FacebookException('facebook_document_limit', 'The Page schema artifacts exceed their parsing limit');
            $version = BootstrapParser::extractVersion($script); if ($version !== null) $this->version($version);
        }
        if ($this->version === null) throw new FacebookException('facebook_page_schema_unavailable', 'The current Page document or its observed public artifacts did not supply LSVersion');
        return new PageMailboxConfig($expectedAccount, $pageId, $dgwApp, $appVersion, $serverApp, $this->version, $deviceId, $origin);
    }

    private function walk(mixed $value, int $depth): void
    {
        if (++$this->nodes > 200000 || $depth > 100) throw new FacebookException('facebook_document_limit', 'The Page bootstrap exceeds the local nesting limit');
        if (!is_array($value)) return;
        $definitions = is_array($value['define'] ?? null) ? $value['define'] : [];
        foreach ($definitions as $definition) {
            if (!is_array($definition) || !in_array($definition[0] ?? null, ['CurrentUserInitialData', 'DGWWebConfig', 'ServerAppID', 'LSVersion'], true) || !array_key_exists(2, $definition)) continue;
            $name = $definition[0]; $data = $definition[2];
            if (array_key_exists($name, $this->modules) && $this->modules[$name] !== $data) throw new FacebookException('facebook_bootstrap_conflict', 'The Page bootstrap supplied conflicting identity or protocol configuration');
            $this->modules[$name] = $data;
            if ($name === 'LSVersion') { $version = self::id(is_array($data) ? ($data['version'] ?? null) : $data); if ($version !== null) $this->version($version); }
        }
        $data = $value['__bbox']['result']['data']['xfb_biz_inbox_unified_thread_list'] ?? null;
        if (is_array($data) && isset($data['selected_page']['id'])) {
            $page = self::id($data['selected_page']['id']);
            if ($page === null || $this->selectedPage !== null && $this->selectedPage !== $page) throw new FacebookException('facebook_bootstrap_conflict', 'The Page bootstrap supplied conflicting mailbox identities');
            $this->selectedPage = $page;
        }
        if (is_string($value['requestPayload'] ?? null)) {
            try { $request = json_decode($value['requestPayload'], true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); } catch (\JsonException) { $request = null; }
            if (is_array($request) && in_array($request['database'] ?? null, [2, 205], true)) { $version = self::id($request['version'] ?? null); if ($version !== null) $this->version($version); }
        }
        foreach ($value as $child) if (is_array($child)) $this->walk($child, $depth + 1);
    }

    private function version(string $version): void
    {
        if ($this->version !== null && $this->version !== $version) throw new FacebookException('facebook_bootstrap_conflict', 'The Page document and artifacts disagree on LSVersion');
        $this->version = $version;
    }
    private static function id(mixed $value): ?string
    {
        if (is_int($value)) $value = (string)$value;
        if (!is_string($value)) return null;
        try { TaskBuilder::decimalId($value); return $value; } catch (\InvalidArgumentException) { return null; }
    }
}
