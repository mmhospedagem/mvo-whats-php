<?php
declare(strict_types=1);

namespace MVO\Messenger\Auth;

use MVO\Messenger\MessengerException;

/** Private result of native authentication, not proof of a usable messaging transport.
 * Only the success fields observed in the Android authentication parser are consumed.
 * A checkpoint, partial identity or error must never become a saved Web session.
 */
final class AndroidLoginResult
{
    private function __construct(
        public readonly string $accountId,
        private readonly CookieJar $cookies,
        #[\SensitiveParameter] private ?string $accessToken,
    ) {}

    public static function fromJson(#[\SensitiveParameter] string $json): self
    {
        if (strlen($json) > 2097152) throw self::invalid();
        try {
            $result = json_decode($json, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw self::invalid();
        }
        if (!is_array($result) || array_is_list($result) || array_key_exists('error', $result)
            || array_key_exists('error_code', $result) || array_key_exists('checkpoint_url', $result)) {
            throw self::invalid();
        }
        $uid = $result['uid'] ?? null;
        if (is_int($uid)) $uid = (string) $uid;
        if (!is_string($uid) || preg_match('/^[1-9][0-9]{0,24}$/D', $uid) !== 1
            || !is_string($result['access_token'] ?? null) || $result['access_token'] === ''
            || strlen($result['access_token']) > 16384 || preg_match('/[\x00-\x20\x7f]/', $result['access_token'])) {
            throw self::invalid();
        }
        // Android requests generate_session_cookies explicitly. Preserve each
        // server cookie's scope/expiry; do not synthesize xs or c_user from uid.
        $entries = $result['session_cookies'] ?? null;
        if (!is_array($entries) || !array_is_list($entries) || $entries === []) throw self::invalid();
        foreach ($entries as &$entry) {
            if (!is_array($entry) || !isset($entry['domain'], $entry['path'])
                || !is_string($entry['domain']) || !is_string($entry['path'])) throw self::invalid();
            // SessionCookie.mExpires in Android is a string, unlike browser
            // cookie exports. Accept a strict HTTP date without interpreting
            // relative date expressions or replacing a real expiry with null.
            if (isset($entry['expires']) && is_string($entry['expires']) && !is_numeric($entry['expires'])) {
                $entry['expires'] = self::httpExpiry($entry['expires']);
            }
        }
        unset($entry);
        try {
            $cookies = new CookieJar($entries);
        } catch (\InvalidArgumentException) {
            throw self::invalid();
        }
        if ($cookies->accountId() !== $uid) {
            throw new MessengerException('login_identity_mismatch', 'The Android authentication identity does not match its session cookies');
        }
        // Retain only until the independent native /me identity check. It is
        // never serialized or persisted with the cookies/device identity.
        return new self($uid, $cookies, $result['access_token']);
    }

    /** Private input to identity verification; never serialize into an API result. */
    public function cookies(): CookieJar { return $this->cookies; }
    /** @internal RAM-only native identity authorization, never an API result. */
    public function accessTokenPrivate():string
    {
        return $this->accessToken??throw new \LogicException('The native identity token was already cleared');
    }
    public function __destruct()
    {
        if($this->accessToken!==null&&$this->accessToken!=='')sodium_memzero($this->accessToken);
    }

    public function __debugInfo(): array
    {
        return ['accountId'=>$this->accountId, 'cookies'=>'[PRIVATE]'];
    }

    /** @return array<never,never> */
    public function __serialize(): array
    {
        throw new \LogicException('Native authentication results must remain private and in memory');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Native authentication results cannot be restored from serialized input');
    }

    private static function httpExpiry(string $expiry): int
    {
        foreach (['D, d M Y H:i:s \\G\\M\\T', 'D, d-M-Y H:i:s \\G\\M\\T'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $expiry, new \DateTimeZone('UTC'));
            if ($date !== false && $date->format($format) === $expiry) return $date->getTimestamp();
        }
        throw self::invalid();
    }

    private static function invalid(): MessengerException
    {
        return new MessengerException('login_native_response_invalid', 'The Android login did not return a complete authenticated session');
    }
}
