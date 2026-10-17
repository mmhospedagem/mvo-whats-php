<?php
declare(strict_types=1);

namespace MVO\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Auth\PairingCodeCrypto;
use MVO\Crypto\Crypto;

final class PairingCodeCryptoTest extends TestCase
{
    public function testCustomCodeNormalizationAndCrockfordKnownValues(): void
    {
        self::assertSame('ABCD1234', PairingCodeCrypto::normalizeCode('abcd-1234'));
        self::assertSame('11111111', PairingCodeCrypto::encodeCrockford(str_repeat("\0", 5)));
        self::assertSame('ZZZZZZZZ', PairingCodeCrypto::encodeCrockford(str_repeat("\xff", 5)));
        self::assertSame('15', PairingCodeCrypto::encodeCrockford("\x01"));
    }

    #[DataProvider('invalidCodes')]
    public function testRejectsInvalidCustomCodes(string $code): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PairingCodeCrypto::normalizeCode($code);
    }

    public static function invalidCodes(): iterable
    {
        yield 'too short' => ['ABCD123'];
        yield 'too long' => ['ABCD12345'];
        yield 'zero' => ['ABCD0123'];
        yield 'I' => ['ABCDI123'];
        yield 'O' => ['ABCDO123'];
        yield 'U' => ['ABCDU123'];
        yield 'spaces' => ['ABCD 123'];
    }

    public function testHelloWrapCanBeDecodedByThePrimary(): void
    {
        $hello = PairingCodeCrypto::createHello('abcd-1234');
        $wrapped = $hello['wrappedCompanionEphemeralPub'];
        self::assertSame(80, strlen($wrapped));
        self::assertSame('ABCD1234', $hello['pairingCode']);
        $key = hash_pbkdf2('sha256', 'ABCD1234', substr($wrapped, 0, 32), 131072, 32, true);
        self::assertSame($hello['companionEphemeralKeyPair']['public'], openssl_decrypt(substr($wrapped, 48), 'aes-256-ctr', $key, OPENSSL_RAW_DATA, substr($wrapped, 32, 16)));
        self::assertSame($hello['companionEphemeralKeyPair']['public'], sodium_crypto_scalarmult_base($hello['companionEphemeralKeyPair']['private']));
        self::assertMatchesRegularExpression('/^[123456789ABCDEFGHJKLMNPQRSTVWXYZ]{8}$/', PairingCodeCrypto::createHello()['pairingCode']);
    }

    public function testCompanionFinishAgreesWithPrimaryAndAuthenticatesBundle(): void
    {
        $ephemeral = Crypto::x25519KeyPair();
        $primaryEphemeral = Crypto::x25519KeyPair();
        $identity = Crypto::x25519KeyPair();
        $primaryIdentity = Crypto::x25519KeyPair();
        $salt = str_repeat('s', 32);
        $counter = str_repeat('c', 16);
        $key = hash_pbkdf2('sha256', 'ABCD1234', $salt, 131072, 32, true);
        $wrappedPrimary = $salt . $counter . openssl_encrypt($primaryEphemeral['public'], 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $counter);
        $finish = PairingCodeCrypto::completeFinish('ABCD1234', $wrappedPrimary, $primaryIdentity['public'], $ephemeral['private'], $identity);
        self::assertSame(156, strlen($finish['wrappedKeyBundle']));
        self::assertSame($identity['public'], $finish['companionIdentityPublic']);
        $wrapped = $finish['wrappedKeyBundle'];
        $sharedEphemeral = sodium_crypto_scalarmult($primaryEphemeral['private'], $ephemeral['public']);
        $bundleKey = hash_hkdf('sha256', $sharedEphemeral, 32, 'link_code_pairing_key_bundle_encryption_key', substr($wrapped, 0, 32));
        $encrypted = substr($wrapped, 44);
        $plain = openssl_decrypt(substr($encrypted, 0, -16), 'aes-256-gcm', $bundleKey, OPENSSL_RAW_DATA, substr($wrapped, 32, 12), substr($encrypted, -16));
        self::assertIsString($plain);
        self::assertSame($identity['public'], substr($plain, 0, 32));
        self::assertSame($primaryIdentity['public'], substr($plain, 32, 32));
        $sharedIdentity = sodium_crypto_scalarmult($primaryIdentity['private'], $identity['public']);
        self::assertSame(hash_hkdf('sha256', $sharedEphemeral . $sharedIdentity . substr($plain, 64), 32, 'adv_secret'), $finish['advSecret']);
        $this->expectException(\UnexpectedValueException::class);
        Crypto::decryptGcm($bundleKey, substr($wrapped, 32, 12), substr($wrapped, 44, -1) . ($wrapped[-1] ^ "\x01"));
    }

    public function testRejectsWrongLengthPrimaryPayloadBeforeCrypto(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PairingCodeCrypto::completeFinish('ABCD1234', str_repeat('x', 79), str_repeat('p', 32), str_repeat('e', 32), Crypto::x25519KeyPair());
    }
}
