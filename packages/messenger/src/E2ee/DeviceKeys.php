<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee;

use MVO\Messenger\MessengerException;

/** Independent Messenger identity, Noise key and signed prekey; no WhatsApp credential reuse. */
final class DeviceKeys implements \JsonSerializable
{
    private function __construct(
        public readonly string $accountId,
        public readonly string $uuid,
        public readonly int $registrationId,
        #[\SensitiveParameter] private readonly string $identityPrivate,
        #[\SensitiveParameter] private readonly string $noisePrivate,
        #[\SensitiveParameter] private readonly string $signedPrekeyPrivate,
        public readonly int $signedPrekeyId,
        private readonly string $signedPrekeySignature,
    ) {}

    public static function generate(string $accountId): self
    {
        self::requireCrypto();
        if (!preg_match('/^[1-9][0-9]{0,24}$/D', $accountId)) throw new \InvalidArgumentException('Invalid Messenger device account');
        $uuid = random_bytes(16); $uuid[6] = chr((ord($uuid[6]) & 15) | 64); $uuid[8] = chr((ord($uuid[8]) & 63) | 128);
        $hex = bin2hex($uuid); $id = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
        $identity = self::privateKey(); $noise = self::privateKey(); $prekey = self::privateKey();
        return new self($accountId, $id, random_int(1, 16383), $identity, $noise, $prekey, 1,
            XEdDsa::sign($identity, "\x05" . sodium_crypto_scalarmult_base($prekey)));
    }

    public static function fromPrivateJson(#[\SensitiveParameter] string $json, string $accountId): self
    {
        self::requireCrypto();
        try { $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new MessengerException('messenger_device_corrupt', 'The saved Messenger device cannot be decoded'); }
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || ($data['account_id'] ?? null) !== $accountId
            || !is_string($data['uuid'] ?? null) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $data['uuid'])
            || !is_int($data['registration_id'] ?? null) || $data['registration_id'] < 1 || $data['registration_id'] > 16383
            || !is_int($data['signed_prekey_id'] ?? null) || $data['signed_prekey_id'] < 1 || $data['signed_prekey_id'] > 16777215) {
            throw new MessengerException('messenger_device_corrupt', 'The saved Messenger device does not match its account or format');
        }
        $keys = [];
        foreach (['identity_private', 'noise_private', 'signed_prekey_private', 'signed_prekey_signature'] as $name) {
            $encoded = $data[$name] ?? null; $key = is_string($encoded) ? base64_decode($encoded, true) : false;
            if ($key === false || strlen($key) !== ($name === 'signed_prekey_signature' ? 64 : 32) || base64_encode($key) !== $encoded) throw new MessengerException('messenger_device_corrupt', 'A saved Messenger device key has an invalid format');
            $keys[$name] = $key;
        }
        if (!XEdDsa::verify(sodium_crypto_scalarmult_base($keys['identity_private']), "\x05" . sodium_crypto_scalarmult_base($keys['signed_prekey_private']), $keys['signed_prekey_signature'])) throw new MessengerException('messenger_device_corrupt', 'The saved Messenger signed prekey did not verify');
        return new self($accountId, $data['uuid'], $data['registration_id'], $keys['identity_private'], $keys['noise_private'], $keys['signed_prekey_private'], $data['signed_prekey_id'], $keys['signed_prekey_signature']);
    }

    public function identityPublic(): string { return sodium_crypto_scalarmult_base($this->identityPrivate); }

    /** Internal private protocol boundary, never an event, API response or job payload.
     * @return array<string,mixed>
     */
    public function signalCredentials():array
    {
        return ['accountId'=>$this->accountId,'registrationId'=>$this->registrationId,'identityKeyPair'=>['private'=>$this->identityPrivate,'public'=>$this->identityPublic()],
            'signedPreKey'=>['id'=>$this->signedPrekeyId,'private'=>$this->signedPrekeyPrivate,'public'=>sodium_crypto_scalarmult_base($this->signedPrekeyPrivate),'signature'=>$this->signedPrekeySignature]];
    }
    /** @return array{private:string,public:string} Internal secret Noise keypair. */
    public function noiseKeyPair():array{return ['private'=>$this->noisePrivate,'public'=>sodium_crypto_scalarmult_base($this->noisePrivate)];}

    /** Secret persistence material. Never put this in events, diagnostics or an application API. */
    public function exportPrivateJson(): string
    {
        return json_encode(['version' => 1, 'account_id' => $this->accountId, 'uuid' => $this->uuid, 'registration_id' => $this->registrationId,
            'identity_private' => base64_encode($this->identityPrivate), 'noise_private' => base64_encode($this->noisePrivate),
            'signed_prekey_private' => base64_encode($this->signedPrekeyPrivate), 'signed_prekey_id' => $this->signedPrekeyId,
            'signed_prekey_signature' => base64_encode($this->signedPrekeySignature)], JSON_THROW_ON_ERROR);
    }

    /** @return array<string,string> */
    public function registrationFields(IdentityRoster $roster, int $timestamp): array
    {
        $list = $roster->append($this->identityPublic(), $timestamp);
        $signature = XEdDsa::sign($this->identityPrivate, $list['details']);
        $signed = ProtoWire::bytes(1, $list['details']) . ProtoWire::bytes(2, $signature);
        return ['e_regid' => base64_encode(pack('N', $this->registrationId)), 'e_keytype' => base64_encode("\x05"),
            'e_ident' => base64_encode($this->identityPublic()), 'e_skey_id' => base64_encode(substr(pack('N', $this->signedPrekeyId), 1)),
            'e_skey_val' => base64_encode(sodium_crypto_scalarmult_base($this->signedPrekeyPrivate)), 'e_skey_sig' => base64_encode($this->signedPrekeySignature),
            'icdc_list' => base64_encode($signed), 'icdc_ts' => (string)$timestamp, 'icdc_seq' => (string)$list['sequence'], 'ihash' => base64_encode($list['identities_hash'])];
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return ['account_id' => $this->accountId, 'device_uuid' => $this->uuid, 'identity_fingerprint' => hash('sha256', $this->identityPublic()), 'has_private_keys' => true];
    }
    /** @return array<string,mixed> */
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    /** Persist only through exportPrivateJson() into the private session store.
     * @return array<never,never>
     */
    public function __serialize(): array { throw new \LogicException('Use explicit private Messenger key persistence'); }

    private static function privateKey(): string
    {
        $key = random_bytes(32); $key[0] = chr(ord($key[0]) & 248); $key[31] = chr((ord($key[31]) & 127) | 64); return $key;
    }
    private static function requireCrypto(): void
    {
        if (!extension_loaded('gmp') || !extension_loaded('sodium')) throw new MessengerException('messenger_device_crypto_unavailable', 'Messenger device signatures require the optional GMP and Sodium extensions');
    }
}
