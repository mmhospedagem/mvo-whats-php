<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee;

use MVO\Messenger\Auth\SessionStore;
use MVO\Messenger\MessengerException;
use MVO\Messenger\Transport\HttpResponse;

/**
 * Explicit Messenger device provisioning. Registering an identity does not open
 * Noise, establish Signal sessions, recover secure history or enable messaging.
 */
final class DeviceManager implements \JsonSerializable
{
    private \Closure $tokenProvider;
    private ?\Closure $ownerGuard;

    /** @param callable():CryptoAuthToken $tokenProvider Current private bootstrap token.
     * @param callable():void|null $ownerGuard Parent client lifecycle/callback guard.
     */
    public function __construct(
        private readonly SessionStore $store,
        private readonly string $accountId,
        private readonly RegistrationTransport $transport,
        callable $tokenProvider,
        ?callable $ownerGuard = null,
    ) {
        if (!preg_match('/^[1-9][0-9]{0,24}$/D', $accountId)) throw new \InvalidArgumentException('Invalid Messenger device account');
        if ($store->get('account_id') !== $accountId) throw new MessengerException('messenger_device_identity_mismatch', 'The saved session belongs to another Messenger account');
        $this->tokenProvider = \Closure::fromCallable($tokenProvider);
        $this->ownerGuard = $ownerGuard === null ? null : \Closure::fromCallable($ownerGuard);
    }

    /** Generates/saves fresh independent keys locally. No network or registration.
     * @return array<string,mixed>
     */
    public function prepare(): array { $this->requireOwner(); $this->keys(); return $this->status(); }

    /** Authenticated read only. Review the snapshot before explicitly registering.
     * A fetched roster is not cryptographic verification of remote-device trust.
     * @return array<string,mixed>
     */
    public function registrationRoster(): array
    {
        $this->requireOwner(); $keys = $this->keys(); $roster = $this->fetchRoster($keys);
        return $roster->jsonSerialize();
    }

    /** Account-changing request, never called by connect()/poll().
     * Refetches the roster and rejects any change since explicit review.
     * @return array<string,mixed>
     */
    public function register(string $expectedRosterHash): array
    {
        $this->requireOwner();
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedRosterHash)) throw new \InvalidArgumentException('Review a Messenger identity roster before registration');
        $previous = $this->registration();
        if (($previous['state'] ?? null) === 'registration_uncertain') throw new MessengerException('messenger_device_registration_uncertain', 'A previous registration has an uncertain outcome; reconcile the registered device before another attempt');
        if (($previous['state'] ?? null) === 'registered') return $this->status();
        $keys = $this->keys(); $roster = $this->fetchRoster($keys);
        if (!hash_equals($expectedRosterHash, $roster->snapshotHash())) throw new MessengerException('messenger_identity_roster_changed', 'The Messenger identity roster changed after review; review its current fingerprints before registration');
        $token = ($this->tokenProvider)();
        $fields = $token->privateForm($this->accountId, $keys->uuid) + $keys->registrationFields($roster, time());
        // The persisted boundary precedes I/O. A lost response must never
        // generate a second device identity or silently retry registration.
        $this->saveRegistration(['state' => 'registration_uncertain', 'attempt_id' => bin2hex(random_bytes(16)), 'requested_at' => time(), 'roster_hash' => $expectedRosterHash]);
        try {
            $response = $this->transport->request('register', $fields);
            $result = $this->response($response);
            if (is_int($result['status'] ?? null) && $result['status'] !== 200) {
                $this->saveRegistration(['state' => 'registration_rejected', 'rejected_at' => time()]);
                throw new MessengerException('messenger_device_registration_rejected', 'Messenger rejected the encrypted device registration');
            }
            if (($result['status'] ?? null) !== 200 || ($result['product'] ?? null) !== 'msgr' || ($result['icdc_success'] ?? true) !== true
                || !is_int($result['wa_device_id'] ?? null) || $result['wa_device_id'] < 1 || $result['wa_device_id'] > 65535) {
                throw new MessengerException('messenger_device_registration_unconfirmed', 'Messenger did not return a complete encrypted device registration confirmation');
            }
            $this->saveRegistration(['state' => 'registered', 'device_id' => $result['wa_device_id'], 'confirmed_at' => time(),
                'roster_hash' => $expectedRosterHash, 'device_uuid' => $keys->uuid,
                'identity_fingerprint' => hash('sha256', $keys->identityPublic())]);
        } catch (\Throwable $error) {
            if ($error instanceof MessengerException && $error->reason === 'messenger_device_registration_rejected') throw $error;
            throw new MessengerException('messenger_device_registration_uncertain', 'The encrypted device registration outcome is uncertain; keep its saved keys and reconcile before retrying');
        }
        return $this->status();
    }

    /** Safe local projection; registration alone never sets e2ee_ready.
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $registered = $this->registration();
        $savedKeys = $this->store->get('e2ee.device.keys.v1');
        $hasKeys = $savedKeys !== null;
        $confirmed = ($registered['state'] ?? null) === 'registered' && $hasKeys;
        if ($confirmed) {
            $keys = DeviceKeys::fromPrivateJson($savedKeys, $this->accountId);
            if (($registered['device_uuid'] ?? null) !== $keys->uuid
                || !is_string($registered['identity_fingerprint'] ?? null)
                || !hash_equals($registered['identity_fingerprint'], hash('sha256', $keys->identityPublic()))) {
                throw new MessengerException('messenger_device_identity_mismatch', 'The saved registration does not confirm the current Messenger device keys');
            }
        }
        return ['state' => $registered['state'] ?? ($hasKeys ? 'keys_prepared' : 'uninitialized'), 'keys_present' => $hasKeys,
            'registration_confirmed' => $confirmed, 'device_id' => $confirmed ? ($registered['device_id'] ?? null) : null,
            'device_address' => $confirmed ? $this->accountId . ':' . $registered['device_id'] . '@msgr' : null,
            'e2ee_ready' => false, 'encrypted_send_available' => false, 'encrypted_receive_available' => false,
            'secure_history_available' => false, 'missing' => ['active_encrypted_runtime_login', 'current_blue_web_bootstrap_validation', 'native_icdc_roster_verification', 'live_server_interoperability_validation']];
    }

    private function requireOwner(): void
    {
        ($this->ownerGuard)?->__invoke();
        if (!$this->store->ownsLock()) throw new \LogicException('Provision a Messenger device only through the session owner');
        if ($this->store->get('account_id') !== $this->accountId) throw new MessengerException('messenger_device_identity_mismatch', 'The saved session belongs to another Messenger account');
    }

    private function keys(): DeviceKeys
    {
        $saved = $this->store->get('e2ee.device.keys.v1');
        if ($saved !== null) return DeviceKeys::fromPrivateJson($saved, $this->accountId);
        $keys = DeviceKeys::generate($this->accountId);
        $this->store->transaction(function () use ($keys): void { $this->store->set('e2ee.device.keys.v1', $keys->exportPrivateJson()); });
        return $keys;
    }

    private function fetchRoster(DeviceKeys $keys): IdentityRoster
    {
        $token = ($this->tokenProvider)();
        try { return IdentityRoster::fromResponse($this->response($this->transport->request('fetch', $token->privateForm($this->accountId, $keys->uuid)))); }
        catch (MessengerException $error) { throw $error; }
        catch (\Throwable) { throw new MessengerException('messenger_identity_roster_unavailable', 'Messenger did not return a recognized encrypted device identity roster'); }
    }

    /** @return array<string,mixed> */
    private function response(#[\SensitiveParameter] HttpResponse $response): array
    {
        if ($response->status !== 200 || strlen($response->body) > 1048576) throw new MessengerException('messenger_device_http_failed', 'Messenger did not confirm the device protocol response');
        try { $result = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new MessengerException('messenger_device_response_invalid', 'Messenger returned an unrecognized device protocol response'); }
        if (!is_array($result) || array_is_list($result)) throw new MessengerException('messenger_device_response_invalid', 'Messenger returned an unrecognized device protocol response');
        return $result;
    }

    /** @return array<string,mixed> */
    private function registration(): array
    {
        $raw = $this->store->get('e2ee.device.registration.v1');
        if ($raw === null) return [];
        try { $value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new MessengerException('messenger_device_corrupt', 'The saved Messenger registration state cannot be decoded'); }
        if (!is_array($value) || !in_array($value['state'] ?? null, ['registered', 'registration_uncertain', 'registration_rejected'], true)
            || (($value['state'] ?? null) === 'registered' && (!is_int($value['device_id'] ?? null) || $value['device_id'] < 1 || $value['device_id'] > 65535))) throw new MessengerException('messenger_device_corrupt', 'The saved Messenger registration state is invalid');
        return $value;
    }

    /** @param array<string,mixed> $registration */
    private function saveRegistration(array $registration): void { $this->store->set('e2ee.device.registration.v1', json_encode($registration, JSON_THROW_ON_ERROR)); }
    /** @return array<string,mixed> */
    public function jsonSerialize(): array { return $this->status(); }
    /** @return array<string,mixed> */
    public function __debugInfo(): array { return $this->status(); }
}
