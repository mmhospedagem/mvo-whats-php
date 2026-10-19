<?php
declare(strict_types=1);

use MVO\Messenger\Auth\PasswordEncryption;
use MVO\Messenger\MessengerException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

final class PasswordEncryptionTest extends TestCase
{
    public function testEnvelopeDecryptsWithIndependentReceiverAndTimestampIsAuthenticated(): void
    {
        $pair = sodium_crypto_box_seed_keypair(str_repeat("\x06", SODIUM_CRYPTO_BOX_SEEDBYTES));
        $public = bin2hex(sodium_crypto_box_publickey($pair));
        $value = PasswordEncryption::encrypt(237, $public, 'Senha sintética ç🔐', '1792355334');
        $parts = explode(':', $value, 4);
        self::assertSame(['#PWD_BROWSER', '5', '1792355334'], array_slice($parts, 0, 3));
        $bytes = base64_decode($parts[3], true);
        self::assertSame(1, ord($bytes[0]));
        self::assertSame(237, ord($bytes[1]));
        self::assertSame("\x50\x00", substr($bytes, 2, 2));
        $key = sodium_crypto_box_seal_open(substr($bytes, 4, 80), $pair);
        self::assertIsString($key);
        self::assertSame(32, strlen($key));
        $plain = openssl_decrypt(substr($bytes, 100), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, str_repeat("\0", 12), substr($bytes, 84, 16), $parts[2]);
        self::assertSame('Senha sintética ç🔐', $plain);
        self::assertFalse(openssl_decrypt(substr($bytes, 100), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, str_repeat("\0", 12), substr($bytes, 84, 16), '1792355335'));
        self::assertNotSame($value, PasswordEncryption::encrypt(237, $public, 'Senha sintética ç🔐', '1792355334'));
    }

    public function testRejectsInvalidServerKeyBeforeAttemptingEncryption(): void
    {
        $this->expectException(MessengerException::class);
        PasswordEncryption::encrypt(256, str_repeat('g', 64), 'test');
    }

    public function testPasswordArgumentIsSensitive(): void
    {
        $argument = (new ReflectionMethod(PasswordEncryption::class, 'encrypt'))->getParameters()[2];
        self::assertCount(1, $argument->getAttributes(SensitiveParameter::class));
    }
}
