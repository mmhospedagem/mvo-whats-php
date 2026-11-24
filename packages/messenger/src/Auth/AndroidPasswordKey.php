<?php
declare(strict_types=1);

namespace MVO\Messenger\Auth;

use MVO\Messenger\MessengerException;

/** Server-issued password key metadata; no embedded/default key fallback. */
final class AndroidPasswordKey
{
    private function __construct(
        public readonly int $id,
        private readonly string $publicKey,
        public readonly int $expiresAt,
    ) {}

    public static function fromJson(string $json, ?int $now = null): self
    {
        $now ??= time();
        if ($now < 0 || strlen($json) > 65536) throw self::invalid();
        try { $result = json_decode($json, true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw self::invalid(); }
        if (!is_array($result) || array_key_exists('error', $result) || array_key_exists('error_code', $result)) throw self::invalid();
        $id = $result['key_id'] ?? null;
        $publicKey = $result['public_key'] ?? null;
        $ttl = $result['seconds_to_live'] ?? null;
        if (!is_int($id) || $id < 0 || $id > 2147483647 || !is_int($ttl) || $ttl < 1 || $ttl > PHP_INT_MAX - $now
            || !is_string($publicKey) || $publicKey === '' || strlen($publicKey) > 16384 || str_contains($publicKey, "\0")) {
            throw self::invalid();
        }
        return new self($id, $publicKey, $now + $ttl);
    }

    /** The cipher must separately validate the server's public-key algorithm. */
    public function publicKey(?int $now = null): string
    {
        if (($now ?? time()) >= $this->expiresAt) {
            throw new MessengerException('login_native_key_expired', 'Fetch a current Android password encryption key before authenticating');
        }
        return $this->publicKey;
    }

    public function __debugInfo(): array { return ['id'=>$this->id, 'expiresAt'=>$this->expiresAt]; }

    private static function invalid(): MessengerException
    {
        return new MessengerException('login_native_key_invalid', 'Facebook did not provide a usable Android password encryption key');
    }
}
