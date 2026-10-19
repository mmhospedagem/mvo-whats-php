<?php
declare(strict_types=1);

namespace MVO\Messenger\Protocol;

/** Authenticated Web bootstrap. Token-bearing modules are intentionally private. */
final class BootstrapConfig implements \JsonSerializable
{
    /**
     * @param array<string,mixed> $modules
     * @param list<array<string,mixed>> $initialPayloads
     * @param list<string> $scriptUrls
     * @param array<int,array{epochId:string,failureCount:int,cursor?:?string}> $initialRequests
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $appId,
        public readonly string $deviceId,
        public readonly ?string $versionId,
        public readonly string $appVersion,
        public readonly string $origin,
        #[\SensitiveParameter] private readonly array $modules,
        #[\SensitiveParameter] private readonly array $initialPayloads = [],
        public readonly array $scriptUrls = [],
        public readonly ?string $lightspeedAppId = null,
        #[\SensitiveParameter] private readonly array $initialRequests = [],
    ) {}

    /** Internal protocol access only. Do not log or return these modules in an API. */
    public function module(string $name): mixed { return $this->modules[$name] ?? null; }

    /** @return array<string,mixed> */
    public function syncParams(): array
    {
        $params = $this->module('LSPlatformMessengerSyncParams');
        return is_array($params) ? $params : [];
    }

    /** @return list<array<string,mixed>> */
    public function initialLightspeedPayloads(): array { return $this->initialPayloads; }

    /**
     * Requests actually present in expectedPreloaders; never inferred from a response.
     * Epoch values are private session state and excluded from diagnostics.
     * @return array<int,array{epochId:string,failureCount:int,cursor?:?string}>
     */
    public function initialSyncRequests(): array { return $this->initialRequests; }

    /**
     * Private request fields; token values never belong in diagnostics.
     * @return array<string,string>
     */
    public function requestFields(): array
    {
        $fields = ['__user'=>$this->userId, '__a'=>'1'];
        $site = $this->module('SiteData');
        if (is_array($site)) {
            foreach (['__spin_r'=>'__spin_r', '__spin_b'=>'__spin_b', '__spin_t'=>'__spin_t', 'client_revision'=>'__rev', 'haste_session'=>'__hs', 'hsi'=>'__hsi', 'comet_env'=>'__comet_req'] as $source=>$target) {
                if (isset($site[$source]) && (is_string($site[$source]) || is_int($site[$source]))) $fields[$target] = (string)$site[$source];
            }
        }
        foreach (['DTSGInitialData','DTSGInitData'] as $name) {
            $data = $this->module($name);
            if (is_array($data) && isset($data['token']) && is_string($data['token']) && $data['token'] !== '') {
                $fields['fb_dtsg'] = $data['token'];
                $sprinkle = $this->module('SprinkleConfig');
                if (is_array($sprinkle) && isset($sprinkle['version']) && ($sprinkle['should_randomize'] ?? false) === false) {
                    $sum = 0;
                    // DTSG tokens are ASCII. Reject rather than invent UTF-16 coercion for another token alphabet.
                    if (!preg_match('/[^\x20-\x7e]/', $data['token'])) {
                        foreach (str_split($data['token']) as $character) $sum += ord($character);
                        $fields['jazoest'] = (string)$sprinkle['version'] . $sum;
                    }
                }
                break;
            }
        }
        $lsd = $this->module('LSD');
        if (is_array($lsd) && isset($lsd['token']) && is_string($lsd['token'])) $fields['lsd'] = $lsd['token'];
        return $fields;
    }

    /**
     * Public projection deliberately excludes CAT, CSRF tokens, raw modules, payloads and URLs with queries.
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        $init = $this->module('MessengerWebInitData');
        $cat = is_array($init) ? ($init['cryptoAuthToken'] ?? null) : null;
        if (is_array($cat)) $cat = $cat['encryptedSerializedCat'] ?? null;
        return ['userId'=>$this->userId, 'appId'=>$this->appId, 'lightspeedAppId'=>$this->lightspeedAppId, 'deviceId'=>$this->deviceId,
            'versionId'=>$this->versionId, 'appVersion'=>$this->appVersion, 'origin'=>$this->origin,
            'hasLightspeedVersion'=>$this->versionId !== null,
            'hasCryptoAuthToken'=>is_string($cat) && $cat !== '',
            'encryptedMessagingReady'=>false];
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array { return $this->jsonSerialize(); }
}
