<?php
declare(strict_types=1);

namespace MVO\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Crypto\Crypto;

final class CryptoTest extends TestCase
{
    public function testHkdfMatchesRfc5869CaseOne(): void
    {
        self::assertSame(
            '3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865',
            bin2hex(Crypto::hkdf(
                str_repeat("\x0b", 22),
                42,
                hex2bin('f0f1f2f3f4f5f6f7f8f9'),
                hex2bin('000102030405060708090a0b0c'),
            )),
        );
    }

    public function testAes256GcmMatchesNistEmptyPlaintextVector(): void
    {
        $key = str_repeat("\0", 32);
        $nonce = str_repeat("\0", 12);
        $cipher = hex2bin('530f8afbc74536b9a963b4f1c4cb738b');

        self::assertSame($cipher, Crypto::encryptGcm($key, $nonce, ''));
        self::assertSame('', Crypto::decryptGcm($key, $nonce, $cipher));
    }

    public function testX25519MatchesBothSidesOfRfc7748SharedSecret(): void
    {
        $alicePrivate = hex2bin('77076d0a7318a57d3c16c17251b26645df4c2f87ebc0992ab177fba51db92c2a');
        $alicePublic = hex2bin('8520f0098930a754748b7ddcb43ef75a0dbf3a0d26381af4eba4a98eaa9b4e6a');
        $bobPrivate = hex2bin('5dab087e624a8a4b79e17f8b83800ee66f3bb1292618b6fd1c2f8b27ff88e0eb');
        $bobPublic = hex2bin('de9edb7d7b7dc1b4d35b61c2ece435373f8343c85b78674dadfc7e146f882b4f');
        $sharedSecret = hex2bin('4a5d9d5ba4ce2de1728e3bf480350f25e07e21c947d19e3376f09b3c1e161742');

        self::assertSame($sharedSecret, Crypto::x25519($alicePrivate, $bobPublic));
        self::assertSame($sharedSecret, Crypto::x25519($bobPrivate, $alicePublic));
    }

    public function testGcmRejectsAlteredAdditionalAuthenticatedData(): void
    {
        $key = str_repeat('k', 32);
        $nonce = Crypto::nonce(42);
        $cipher = Crypto::encryptGcm($key, $nonce, "payload\0\xff", 'session-a');
        self::assertSame("payload\0\xff", Crypto::decryptGcm($key, $nonce, $cipher, 'session-a'));

        $this->expectException(\UnexpectedValueException::class);
        Crypto::decryptGcm($key, $nonce, $cipher, 'session-b');
    }

    public function testGcmRejectsTruncatedAuthenticationTag(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Crypto::decryptGcm(str_repeat('k', 32), Crypto::nonce(0), str_repeat('x', 15));
    }

    public function testNoiseNonceHasExpectedByteOrder(): void
    {
        self::assertSame('000000000000000001020304', bin2hex(Crypto::nonce(0x01020304)));
        self::assertSame('0000000000000000ffffffff', bin2hex(Crypto::nonce(0xffffffff)));
    }

    #[DataProvider('invalidNonceCounters')]
    public function testNoiseNonceRejectsCounterOutsideUnsigned32Bits(int $counter): void
    {
        $this->expectException(\OverflowException::class);
        Crypto::nonce($counter);
    }

    public static function invalidNonceCounters(): iterable
    {
        yield 'negative' => [-1];
        yield 'exhausted' => [0x100000000];
    }

    #[DataProvider('invalidHkdfLengths')]
    public function testHkdfRejectsUnsupportedOutputLength(int $length): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Crypto::hkdf('key', $length);
    }

    public static function invalidHkdfLengths(): iterable
    {
        yield 'empty' => [0];
        yield 'negative' => [-1];
        yield 'beyond RFC maximum' => [8161];
    }

    #[DataProvider('invalidGcmSizes')]
    public function testGcmRejectsInvalidKeyOrNonceSize(string $key, string $nonce): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Crypto::encryptGcm($key, $nonce, 'payload');
    }

    public static function invalidGcmSizes(): iterable
    {
        yield 'short key' => [str_repeat('k', 31), str_repeat('n', 12)];
        yield 'long key' => [str_repeat('k', 33), str_repeat('n', 12)];
        yield 'short nonce' => [str_repeat('k', 32), str_repeat('n', 11)];
        yield 'long nonce' => [str_repeat('k', 32), str_repeat('n', 13)];
    }
}
