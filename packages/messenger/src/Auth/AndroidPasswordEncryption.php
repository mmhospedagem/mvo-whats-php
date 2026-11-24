<?php
declare(strict_types=1);

namespace MVO\Messenger\Auth;

use MVO\Messenger\MessengerException;

/**
 * Original PHP implementation of the Android CryptoPub password envelope.
 *
 * The caller selects the Java wrapper version explicitly; the inner frame is
 * version 1 for both supported wrappers. No raw/default-key fallback exists.
 * Passwords are valid UTF-8, without normalization. The 4096-byte password and
 * 2048..8192-bit RSA bounds are local SDK policy, not claimed server limits.
 */
final class AndroidPasswordEncryption
{
    public static function encrypt(
        AndroidPasswordKey $key,
        #[\SensitiveParameter] string $password,
        int $version,
        ?int $timestamp = null,
    ): string {
        $aesKey = '';
        try {
            $timestamp ??= time();
            if (($version !== 1 && $version !== 2) || $timestamp < 0 || $key->id < 0 || $key->id > 255
                || $password === '' || strlen($password) > 4096 || preg_match('//u', $password) !== 1) {
                throw new MessengerException('login_native_encryption_invalid', 'The Android password encryption parameters are invalid');
            }
            if (!in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
                throw new MessengerException('login_native_encryption_unavailable', 'Android password encryption requires AES-256-GCM in PHP');
            }

            // Expiry uses the current clock, independently of an explicit AAD timestamp.
            $pem = trim($key->publicKey());
            if (preg_match('/\A-----BEGIN PUBLIC KEY-----\r?\n[A-Za-z0-9+\/=\r\n]+-----END PUBLIC KEY-----\z/D', $pem) !== 1) {
                throw self::invalidKey();
            }
            $public = @openssl_pkey_get_public($pem);
            $details = $public === false ? false : openssl_pkey_get_details($public);
            if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA
                || $details['bits'] < 2048 || $details['bits'] > 8192) {
                throw self::invalidKey();
            }

            $aesKey = random_bytes(32);
            $iv = random_bytes(12);
            $wrappedKey = '';
            if (!@openssl_public_encrypt($aesKey, $wrappedKey, $public, OPENSSL_PKCS1_PADDING)
                || strlen($wrappedKey) !== intdiv($details['bits'] + 7, 8) || strlen($wrappedKey) > 65535) {
                throw self::failed();
            }
            $aad = (string) $timestamp;
            $tag = '';
            $ciphertext = openssl_encrypt($password, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
            if ($ciphertext === false || strlen($tag) !== 16 || strlen($ciphertext) !== strlen($password)) {
                throw self::failed();
            }
            $frame = "\x01" . chr($key->id) . $iv . pack('v', strlen($wrappedKey)) . $wrappedKey . $tag . $ciphertext;
            return '#PWD_MSGR:' . $version . ':' . $aad . ':' . base64_encode($frame);
        } catch (\ValueError|\Random\RandomException) {
            throw self::failed();
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($aesKey);
                sodium_memzero($password);
            }
        }
    }

    private static function invalidKey(): MessengerException
    {
        return new MessengerException('login_native_key_invalid', 'The Android password key must be a supported RSA public key');
    }

    private static function failed(): MessengerException
    {
        return new MessengerException('login_native_encryption_failed', 'Could not protect the Android login password');
    }
}
