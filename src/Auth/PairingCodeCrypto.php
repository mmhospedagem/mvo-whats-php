<?php
declare(strict_types=1);

namespace MVO\Auth;

use MVO\Crypto\Crypto;

/** Native companion-side cryptography for WhatsApp's eight-character pairing code. */
final class PairingCodeCrypto
{
    public const PBKDF2_ITERATIONS = 131072;
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTVWXYZ';

    public static function normalizeCode(string $code): string
    {
        $code = strtoupper(str_replace('-', '', $code));
        if (strlen($code) !== 8 || strspn($code, self::ALPHABET) !== 8) {
            throw new \InvalidArgumentException('Pairing code must contain eight characters from ' . self::ALPHABET);
        }
        return $code;
    }

    public static function encodeCrockford(string $bytes): string
    {
        $bits = $value = 0;
        $result = '';
        foreach (unpack('C*', $bytes) ?: [] as $byte) {
            $value = ($value << 8) | $byte;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $result .= self::ALPHABET[($value >> $bits) & 31];
            }
            $value &= (1 << $bits) - 1;
        }
        if ($bits !== 0) $result .= self::ALPHABET[($value << (5 - $bits)) & 31];
        return $result;
    }

    /** @return array{pairingCode:string,companionEphemeralKeyPair:array{private:string,public:string},wrappedCompanionEphemeralPub:string} */
    public static function createHello(?string $customCode = null): array
    {
        $code = $customCode === null ? self::encodeCrockford(random_bytes(5)) : self::normalizeCode($customCode);
        $keyPair = Crypto::x25519KeyPair();
        $salt = random_bytes(32);
        $counter = random_bytes(16);
        $key = hash_pbkdf2('sha256', $code, $salt, self::PBKDF2_ITERATIONS, 32, true);
        $encrypted = self::ctr($key, $counter, $keyPair['public']);
        return [
            'pairingCode' => $code,
            'companionEphemeralKeyPair' => $keyPair,
            'wrappedCompanionEphemeralPub' => $salt . $counter . $encrypted,
        ];
    }

    /**
     * @param array{private:string,public:string} $identityKeyPair
     * @return array{wrappedKeyBundle:string,companionIdentityPublic:string,advSecret:string}
     */
    public static function completeFinish(
        string $pairingCode,
        string $wrappedPrimaryEphemeralPub,
        string $primaryIdentityPub,
        string $companionEphemeralPrivate,
        array $identityKeyPair,
    ): array {
        $pairingCode = self::normalizeCode($pairingCode);
        Crypto::length($wrappedPrimaryEphemeralPub, 80);
        Crypto::length($primaryIdentityPub, 32);
        Crypto::length($identityKeyPair['private'], 32);
        Crypto::length($identityKeyPair['public'], 32);
        $key = hash_pbkdf2('sha256', $pairingCode, substr($wrappedPrimaryEphemeralPub, 0, 32), self::PBKDF2_ITERATIONS, 32, true);
        $primaryEphemeral = self::ctr($key, substr($wrappedPrimaryEphemeralPub, 32, 16), substr($wrappedPrimaryEphemeralPub, 48));
        $sharedEphemeral = Crypto::x25519($companionEphemeralPrivate, $primaryEphemeral);
        $sharedIdentity = Crypto::x25519($identityKeyPair['private'], $primaryIdentityPub);
        $salt = random_bytes(32);
        $secret = random_bytes(32);
        $iv = random_bytes(12);
        $bundleKey = Crypto::hkdf($sharedEphemeral, 32, 'link_code_pairing_key_bundle_encryption_key', $salt);
        $plain = $identityKeyPair['public'] . $primaryIdentityPub . $secret;
        return [
            'wrappedKeyBundle' => $salt . $iv . Crypto::encryptGcm($bundleKey, $iv, $plain),
            'companionIdentityPublic' => $identityKeyPair['public'],
            'advSecret' => Crypto::hkdf($sharedEphemeral . $sharedIdentity . $secret, 32, 'adv_secret'),
        ];
    }

    private static function ctr(string $key, string $counter, string $bytes): string
    {
        $result = openssl_encrypt($bytes, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $counter);
        if ($result === false) throw new \RuntimeException('Pairing AES-CTR operation failed');
        return $result;
    }
}
