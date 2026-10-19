<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee;

use MVO\Messenger\MessengerException;
use MVO\Messenger\Protocol\BootstrapConfig;

/** Private, short-lived CAT. It is never written into the device store. */
final class CryptoAuthToken implements \JsonSerializable
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $appId,
        #[\SensitiveParameter] private readonly string $token,
        public readonly int $expiresAt,
    ) {
        foreach ([$accountId, $appId] as $id) if (!preg_match('/^[1-9][0-9]{0,24}$/D', $id)) throw new \InvalidArgumentException('Invalid Messenger CAT identity');
        if ($token === '' || strlen($token) > 65536 || preg_match('/[\x00-\x1f\x7f]/', $token)) throw new \InvalidArgumentException('Invalid Messenger CAT');
        if ($expiresAt < 1) throw new \InvalidArgumentException('Invalid Messenger CAT expiration');
    }

    public static function fromBootstrap(#[\SensitiveParameter] BootstrapConfig $bootstrap): self
    {
        $init = $bootstrap->module('MessengerWebInitData');
        $cat = is_array($init) ? ($init['cryptoAuthToken'] ?? null) : null;
        if (!is_array($cat) || !is_string($cat['encryptedSerializedCat'] ?? null)
            || !(is_string($init['appId'] ?? null) || is_int($init['appId'] ?? null))
            || !(is_int($cat['expirationTimeInSeconds'] ?? null) || (is_string($cat['expirationTimeInSeconds'] ?? null) && ctype_digit($cat['expirationTimeInSeconds'])))) {
            throw new MessengerException('messenger_cat_unavailable', 'The authenticated bootstrap did not provide an expiring Messenger crypto token and application ID');
        }
        return new self($bootstrap->userId, (string)$init['appId'], $cat['encryptedSerializedCat'], (int)$cat['expirationTimeInSeconds']);
    }

    /** Internal protocol access only; never return these fields from an application API.
     * @return array<string,string>
     */
    public function privateForm(string $accountId, string $deviceUuid, ?int $now = null): array
    {
        if (!hash_equals($this->accountId, $accountId)) throw new MessengerException('messenger_device_identity_mismatch', 'The crypto token belongs to another Messenger account');
        if (($now ?? time()) >= $this->expiresAt) throw new MessengerException('messenger_cat_expired', 'Refresh the authenticated Messenger bootstrap before registering this device');
        return ['fbid' => $accountId, 'fb_cat' => $this->token, 'app_id' => $this->appId, 'device_id' => $deviceUuid];
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array { return ['account_id' => $this->accountId, 'app_id' => $this->appId, 'expires_at' => $this->expiresAt]; }
    /** @return array<string,mixed> */
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    /** Token-bearing objects cannot enter a serialized job/session accidentally.
     * @return array<never,never>
     */
    public function __serialize(): array { throw new \LogicException('Do not serialize a private Messenger crypto token'); }
}
