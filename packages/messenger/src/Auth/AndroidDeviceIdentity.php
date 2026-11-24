<?php
declare(strict_types=1);

namespace MVO\Messenger\Auth;

use MVO\Messenger\MessengerException;

/**
 * Session-local random Android login identifiers, not hardware/account IDs.
 * Internal private state; only the owning SessionStore lock may access it.
 * The constructor performs no reads, writes, random generation or network I/O.
 */
final class AndroidDeviceIdentity
{
    private const SETTING = 'android.device_identity';

    public function __construct(private readonly SessionStore $store) {}

    public function ensure(): void
    {
        $this->loadOrCreate();
    }

    public function getDeviceId(): string
    {
        return $this->loadOrCreate()['device_id'];
    }

    public function getAdvertisingId(): string
    {
        return $this->loadOrCreate()['adid'];
    }

    /** Internal login request material; never put this value into events/jobs. */
    public function privateMachineId(): ?string
    {
        return $this->loadOrCreate()['machine_id'];
    }

    /** Remember a server-issued error-406 machine ID only when none is retained. */
    public function rememberMachineIdIfEmpty(#[\SensitiveParameter] string $machineId): bool
    {
        $this->requireOwner();
        if (!self::validMachineId($machineId)) {
            throw new \InvalidArgumentException('Invalid Android login machine ID');
        }
        $state = $this->loadOrCreate();
        if ($state['machine_id'] !== null) return false;
        $state['machine_id'] = $machineId;
        $this->save($state);
        return true;
    }

    /** @return array{initialized:bool,machine_id_retained:bool} */
    public function safeProjection(): array
    {
        $state = $this->load();
        return ['initialized' => $state !== null, 'machine_id_retained' => ($state['machine_id'] ?? null) !== null];
    }

    /** @return array{private_state:string} */
    public function __debugInfo(): array
    {
        return ['private_state' => 'redacted'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Android device identity belongs only to its private session store');
    }

    /** @return array{version:1,device_id:string,adid:string,machine_id:?string} */
    private function loadOrCreate(): array
    {
        $state = $this->load();
        if ($state !== null) return $state;
        $state = ['version' => 1, 'device_id' => self::uuid(), 'adid' => self::uuid(), 'machine_id' => null];
        if ($state['device_id'] === $state['adid']) {
            throw new \RuntimeException('Could not create distinct Android login identifiers');
        }
        $this->save($state);
        return $state;
    }

    /** @return array{version:1,device_id:string,adid:string,machine_id:?string}|null */
    private function load(): ?array
    {
        $this->requireOwner();
        $json = $this->store->get(self::SETTING);
        if ($json === null) return null;
        if (strlen($json) > 32768) throw self::invalidState();
        try { $state = json_decode($json, true, 8, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw self::invalidState(); }
        if (!is_array($state) || count($state) !== 4 || ($state['version'] ?? null) !== 1
            || !is_string($state['device_id'] ?? null) || !self::validUuid($state['device_id'])
            || !is_string($state['adid'] ?? null) || !self::validUuid($state['adid'])
            || $state['device_id'] === $state['adid'] || !array_key_exists('machine_id', $state)
            || ($state['machine_id'] !== null && (!is_string($state['machine_id']) || !self::validMachineId($state['machine_id'])))) {
            throw self::invalidState();
        }
        // Re-read under the owner on every call: two helpers cannot overwrite a
        // machine ID through stale cached state, or read after releasing a lock.
        return ['version' => 1, 'device_id' => $state['device_id'], 'adid' => $state['adid'], 'machine_id' => $state['machine_id']];
    }

    /** @param array{version:1,device_id:string,adid:string,machine_id:?string} $state */
    private function save(#[\SensitiveParameter] array $state): void
    {
        $this->requireOwner();
        // One settings-row write persists the complete versioned identity.
        $this->store->set(self::SETTING, json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function requireOwner(): void
    {
        if (!$this->store->ownsLock()) {
            throw new \LogicException('Acquire the Messenger session lock before using the Android device identity');
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function validUuid(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $value) === 1;
    }

    /** The byte bound is local storage policy; the server value stays opaque. */
    private static function validMachineId(#[\SensitiveParameter] string $value): bool
    {
        return $value !== '' && strlen($value) <= 4096 && preg_match('//u', $value) === 1 && preg_match('/[\x00-\x1f\x7f]/', $value) === 0;
    }

    private static function invalidState(): MessengerException
    {
        return new MessengerException('login_native_identity_invalid', 'The saved Android device identity is invalid');
    }
}
