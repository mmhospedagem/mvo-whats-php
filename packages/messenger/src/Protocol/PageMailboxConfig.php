<?php
declare(strict_types=1);
namespace MVO\Messenger\Protocol;

/** Public IDs from the current BizWeb bootstrap; contains no cookies or token fallback. */
final class PageMailboxConfig implements \JsonSerializable
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $pageId,
        public readonly string $dgwAppId,
        public readonly string $dgwAppVersion,
        public readonly string $serverAppId,
        public readonly string $schemaVersion,
        public readonly string $deviceId,
        public readonly string $origin = 'https://business.facebook.com',
    ) {
        foreach ([$accountId, $pageId, $dgwAppId, $serverAppId, $schemaVersion] as $id) TaskBuilder::decimalId($id);
        if (!preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $dgwAppVersion) || $deviceId === '' || strlen($deviceId) > 200 || preg_match('/[\x00-\x20\x7f]/', $deviceId)) throw new \InvalidArgumentException('Use the current BizWeb gateway/device configuration');
        if (!in_array($origin, ['https://business.facebook.com', 'https://www.facebook.com'], true)) throw new \InvalidArgumentException('Unsupported Page mailbox Web origin');
    }

    /** DGWUtils prefixes streamGroupAppHeaders with x-dgw-app-.
     * A personal stream without this PAGE asset binding is not interchangeable.
     */
    public function gatewayUrl(): string
    {
        return 'wss://gateway.facebook.com/ws/lightspeed?' . http_build_query(['x-dgw-appid' => $this->dgwAppId,
            'x-dgw-appversion' => $this->dgwAppVersion, 'x-dgw-authtype' => '1:0', 'x-dgw-version' => '5', 'x-dgw-uuid' => $this->accountId,
            'x-dgw-tier' => 'prod', 'x-dgw-deviceid' => $this->deviceId,
            'x-dgw-app-asset_ids' => json_encode(['PAGE' => $this->pageId], JSON_THROW_ON_ERROR)], '', '&', PHP_QUERY_RFC3986);
    }
    /** @return array<string,string> */
    public function jsonSerialize(): array
    {
        return ['account_id' => $this->accountId, 'page_id' => $this->pageId, 'dgw_app_id' => $this->dgwAppId,
            'dgw_app_version' => $this->dgwAppVersion, 'server_app_id' => $this->serverAppId, 'schema_version' => $this->schemaVersion,
            'device_id' => $this->deviceId, 'origin' => $this->origin];
    }
}
