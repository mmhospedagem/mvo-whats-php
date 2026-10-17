<?php
declare(strict_types=1);

namespace MVO\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Crypto\XEdDsa;

final class XEdDsaTest extends TestCase
{
    public function testSigningMatchesIndependentSignalCurve25519SignatureVector(): void
    {
        // signalapp/curve25519-java, android/jni/ed25519/tests/internal_fast_tests.c,
        // curvesigs_fast_test(): the format used by the upstream reference's xeddsa.ts.
        // https://github.com/signalapp/curve25519-java/blob/main/android/jni/ed25519/tests/internal_fast_tests.c
        $private = str_repeat("\0", 32);
        $private[8] = "\xbd";
        $message = str_repeat("\0", 200);
        $signature = XEdDsa::sign($private, $message, str_repeat("\0", 64));

        self::assertSame(
            'cf873d0379ac20e8893e5567ee0f8951f8db840d26b243b463526689d01ca718'
            . 'ac189fb1678574ebdde569330659448b0bd6c1973f7d780ab39518626803d782',
            bin2hex($signature),
        );
        self::assertTrue(XEdDsa::verify(sodium_crypto_scalarmult_base($private), $message, $signature));
        self::assertSame("\0", $private[31], 'Signing must not modify the caller\'s key');
    }

    public function testVerificationAcceptsRfc8032Ed25519VectorConvertedToMontgomery(): void
    {
        // RFC 8032 section 7.1, TEST 1. Independent verification and point-map vector.
        $edPublic = hex2bin('d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a');
        $signature = hex2bin(
            'e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e06522490155'
            . '5fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b',
        );
        $public = sodium_crypto_sign_ed25519_pk_to_curve25519($edPublic);

        self::assertTrue(XEdDsa::verify($public, '', $signature));
        self::assertFalse(XEdDsa::verify($public, 'altered', $signature));
    }

    public function testRoundTripWorksForBothEmbeddedPublicKeySignBits(): void
    {
        $observedBits = [];
        for ($i = 0; $i < 12; ++$i) {
            $private = hash('sha256', 'test key ' . $i, true);
            $message = "\0binary\xffmessage" . chr($i);
            $signature = XEdDsa::sign($private, $message, str_repeat(chr($i), 64));
            $public = sodium_crypto_scalarmult_base($private);
            $observedBits[ord($signature[63]) & 128] = true;
            self::assertTrue(XEdDsa::verify($public, $message, $signature));
        }
        self::assertCount(2, $observedBits);
    }

    public function testEverySignatureByteIsAuthenticatedAndInputIsNotModified(): void
    {
        $private = str_repeat('p', 32);
        $message = 'authenticated message';
        $signature = XEdDsa::sign($private, $message, str_repeat('r', 64));
        $original = $signature;
        $public = sodium_crypto_scalarmult_base($private);
        for ($index = 0; $index < 64; ++$index) {
            $altered = $signature;
            $altered[$index] = chr(ord($altered[$index]) ^ 1);
            self::assertFalse(XEdDsa::verify($public, $message, $altered), 'Alteration at byte ' . $index);
        }
        self::assertFalse(XEdDsa::verify(sodium_crypto_scalarmult_base(str_repeat('q', 32)), $message, $signature));
        self::assertTrue(XEdDsa::verify($public, $message, $signature));
        self::assertSame($original, $signature);
    }

    public function testDefaultSigningUsesFreshRandomness(): void
    {
        $private = str_repeat('k', 32);
        $first = XEdDsa::sign($private, 'message');
        $second = XEdDsa::sign($private, 'message');

        self::assertNotSame($first, $second);
        self::assertTrue(XEdDsa::verify(sodium_crypto_scalarmult_base($private), 'message', $first));
        self::assertTrue(XEdDsa::verify(sodium_crypto_scalarmult_base($private), 'message', $second));
    }

    public function testVerificationIgnoresMontgomeryHighBitAsX25519Requires(): void
    {
        $private = str_repeat('k', 32);
        $signature = XEdDsa::sign($private, 'message', str_repeat('r', 64));
        $public = sodium_crypto_scalarmult_base($private);
        $public[31] = chr(ord($public[31]) | 128);
        self::assertTrue(XEdDsa::verify($public, 'message', $signature));
    }

    public function testVerificationRejectsNoncanonicalScalarAndReservedBits(): void
    {
        $private = str_repeat('k', 32);
        $signature = XEdDsa::sign($private, 'message', str_repeat('r', 64));
        $public = sodium_crypto_scalarmult_base($private);
        $noncanonicalS = substr($signature, 0, 32)
            . hex2bin('edd3f55c1a631258d69cf7a2def9de1400000000000000000000000000000010');

        self::assertFalse(XEdDsa::verify($public, 'message', $noncanonicalS));
        foreach ([32, 64] as $reservedBit) {
            $altered = $signature;
            $altered[63] = chr(ord($altered[63]) | $reservedBit);
            self::assertFalse(XEdDsa::verify($public, 'message', $altered));
        }
    }

    #[DataProvider('invalidVerificationShapes')]
    public function testVerificationRejectsWrongLengthsWithoutThrowing(string $public, string $signature): void
    {
        self::assertFalse(XEdDsa::verify($public, '', $signature));
    }

    public static function invalidVerificationShapes(): iterable
    {
        yield 'empty public' => ['', str_repeat('s', 64)];
        yield 'short public' => [str_repeat('p', 31), str_repeat('s', 64)];
        yield 'long public' => [str_repeat('p', 33), str_repeat('s', 64)];
        yield 'versioned public' => ["\x05" . str_repeat('p', 32), str_repeat('s', 64)];
        yield 'empty signature' => [str_repeat('p', 32), ''];
        yield 'short signature' => [str_repeat('p', 32), str_repeat('s', 63)];
        yield 'long signature' => [str_repeat('p', 32), str_repeat('s', 65)];
    }

    #[DataProvider('invalidSigningShapes')]
    public function testSigningRejectsWrongLengths(string $private, ?string $random): void
    {
        $this->expectException(\InvalidArgumentException::class);
        XEdDsa::sign($private, 'message', $random);
    }

    public static function invalidSigningShapes(): iterable
    {
        yield 'empty private' => ['', null];
        yield 'short private' => [str_repeat('p', 31), null];
        yield 'long private' => [str_repeat('p', 33), null];
        yield 'empty random' => [str_repeat('p', 32), ''];
        yield 'short random' => [str_repeat('p', 32), str_repeat('r', 63)];
        yield 'long random' => [str_repeat('p', 32), str_repeat('r', 65)];
    }

    #[DataProvider('weakPublicKeys')]
    public function testVerificationRejectsSmallOrderMontgomeryPoints(string $public): void
    {
        // R = B, S = 1: this is a forgery under the Edwards identity if weak
        // public keys are accepted. libsodium must reject all weak images.
        $forged = hex2bin('5866666666666666666666666666666666666666666666666666666666666666')
            . "\x01" . str_repeat("\0", 31);
        self::assertFalse(XEdDsa::verify($public, '', $forged));
    }

    public static function weakPublicKeys(): iterable
    {
        yield 'u zero' => [str_repeat("\0", 32)];
        yield 'u one' => ["\x01" . str_repeat("\0", 31)];
        yield 'u p minus one' => ["\xec" . str_repeat("\xff", 30) . "\x7f"];
        yield 'u p' => ["\xed" . str_repeat("\xff", 30) . "\x7f"];
        yield 'u p plus one' => ["\xee" . str_repeat("\xff", 30) . "\x7f"];
        yield 'u p minus one high bit' => ["\xec" . str_repeat("\xff", 31)];
    }
}
