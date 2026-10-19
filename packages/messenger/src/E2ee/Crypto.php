<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee;
final class Crypto
{
    public static function length(string $bytes, int $length): void { if (strlen($bytes) !== $length) throw new \InvalidArgumentException("Expected $length bytes"); }
    public static function sha256(string $data): string { return hash('sha256', $data, true); }
    public static function hmac(string $key, string $data): string { return hash_hmac('sha256', $data, $key, true); }
    public static function hkdf(string $key, int $length, string $info = '', string $salt = ''): string {
        if ($length < 1 || $length > 8160) throw new \InvalidArgumentException('Invalid HKDF length');
        // hash_hkdf rejects empty IKM; Noise split explicitly requires it.
        $prk = self::hmac($salt === '' ? str_repeat("\0",32) : $salt,$key);
        $result = $previous = '';
        for ($i = 1; strlen($result) < $length; $i++) { $previous = self::hmac($prk,$previous.$info.chr($i)); $result .= $previous; }
        return substr($result,0,$length);
    }
    public static function nonce(int $counter): string {
        if ($counter < 0 || $counter > 0xffffffff) throw new \OverflowException('Noise nonce exhausted');
        return str_repeat("\0",8).pack('N',$counter);
    }
    public static function encryptGcm(string $key, string $nonce, string $plain, string $aad = ''): string {
        self::length($key,32); self::length($nonce,12);
        $cipher = openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
        if ($cipher === false) throw new \RuntimeException('AES-GCM encryption failed');
        return $cipher.$tag;
    }
    public static function decryptGcm(string $key, string $nonce, string $cipher, string $aad = ''): string {
        self::length($key,32); self::length($nonce,12);
        if (strlen($cipher) < 16) throw new \UnexpectedValueException('Truncated GCM tag');
        $plain = openssl_decrypt(substr($cipher,0,-16),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,substr($cipher,-16),$aad);
        if ($plain === false) throw new \UnexpectedValueException('GCM authentication failed');
        return $plain;
    }
    /** @return array{private:string,public:string} */
    public static function x25519KeyPair(): array {
        $private = random_bytes(32);
        return ['private'=>$private,'public'=>sodium_crypto_scalarmult_base($private)];
    }
    public static function x25519(string $private, string $public): string {
        self::length($private,32); self::length($public,32);
        return sodium_crypto_scalarmult($private,$public);
    }
}
