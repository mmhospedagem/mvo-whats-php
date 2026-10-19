<?php
declare(strict_types=1);

namespace MVO\Messenger\Auth;

use MVO\Messenger\MessengerException;

/** Original PHP encoding of the public FBBrowserPasswordEncryption/EnvelopeEncryption format. */
final class PasswordEncryption
{
    public static function encrypt(int $keyId, string $publicKeyHex, #[\SensitiveParameter] string $password, ?string $timestamp = null): string
    {
        $timestamp ??= (string) time();
        if ($keyId < 0 || $keyId > 255 || preg_match('/^[a-fA-F0-9]{64}$/D', $publicKeyHex) !== 1 ||
            preg_match('/^[0-9]{1,15}$/D', $timestamp) !== 1 || $password === '' || strlen($password) > 4096 || preg_match('//u', $password) !== 1) {
            throw new MessengerException('login_encryption_invalid', 'A configuração de criptografia ou a senha não é válida.');
        }
        if (!function_exists('sodium_crypto_box_seal') || !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            throw new MessengerException('login_encryption_unavailable', 'O login precisa de Sodium e AES-256-GCM no PHP.');
        }
        $aesKey = random_bytes(32);
        try {
            $tag = '';
            $ciphertext = openssl_encrypt($password, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, str_repeat("\0", 12), $tag, $timestamp, 16);
            if ($ciphertext === false || strlen($tag) !== 16) throw new MessengerException('login_encryption_failed', 'Não foi possível proteger a senha para o login.');
            $sealedKey = sodium_crypto_box_seal($aesKey, hex2bin($publicKeyHex));
            if (strlen($sealedKey) !== 80) throw new MessengerException('login_encryption_failed', 'O envelope da chave de login é inválido.');
            $envelope = "\x01" . chr($keyId) . pack('v', strlen($sealedKey)) . $sealedKey . $tag . $ciphertext;
            return '#PWD_BROWSER:5:' . $timestamp . ':' . base64_encode($envelope);
        } catch (\SodiumException|\ValueError) {
            throw new MessengerException('login_encryption_failed', 'Não foi possível proteger a senha com a chave fornecida pelo servidor.');
        } finally {
            sodium_memzero($aesKey);
            sodium_memzero($password);
        }
    }
}
