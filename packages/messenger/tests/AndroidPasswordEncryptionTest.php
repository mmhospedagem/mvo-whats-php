<?php
declare(strict_types=1);

use MVO\Messenger\Auth\AndroidPasswordEncryption;
use MVO\Messenger\Auth\AndroidPasswordKey;
use MVO\Messenger\MessengerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

final class AndroidPasswordEncryptionTest extends TestCase
{
    private static OpenSSLAsymmetricKey $private;
    private static string $public;

    public static function setUpBeforeClass(): void
    {
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $private);
        $details = openssl_pkey_get_details($private);
        self::assertIsArray($details);
        self::$private = $private;
        self::$public = $details['key'];
    }

    public function testIndependentReceiverDecodesAndAuthenticatesNativeFrame(): void
    {
        $password = "Senha sintética ç🔐\0fim";
        $encoded = AndroidPasswordEncryption::encrypt(self::key(237), $password, 2, 1792355334);
        [$version, $timestamp, $frame] = self::frame($encoded);
        self::assertSame('2', $version);
        self::assertSame('1792355334', $timestamp);
        self::assertSame("\x01\xed", substr($frame, 0, 2));
        self::assertSame("\x00\x01", substr($frame, 14, 2)); // uint16 LE 256, after IV12.
        self::assertSame(32 + 256 + strlen($password), strlen($frame));
        $wrappedKey = substr($frame, 16, 256);
        $aesKey = '';
        self::assertTrue(openssl_private_decrypt($wrappedKey, $aesKey, self::$private, OPENSSL_PKCS1_PADDING));
        self::assertSame(32, strlen($aesKey));
        self::assertTrue(openssl_private_decrypt($wrappedKey, $rsaBlock, self::$private, OPENSSL_NO_PADDING));
        self::assertSame("\x00\x02", substr($rsaBlock, 0, 2));
        $separator = strpos($rsaBlock, "\0", 2);
        self::assertSame(223, $separator); // 256 - 32-byte AES key - separator.
        self::assertSame($aesKey, substr($rsaBlock, $separator + 1));
        $iv = substr($frame, 2, 12);
        $tag = substr($frame, 272, 16);
        $ciphertext = substr($frame, 288);
        self::assertSame($password, openssl_decrypt($ciphertext, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag, $timestamp));
        self::assertFalse(openssl_decrypt($ciphertext, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag, '1792355335'));
        $badTag = $tag;
        $badTag[0] = chr(ord($badTag[0]) ^ 1);
        self::assertFalse(openssl_decrypt($ciphertext, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $badTag, $timestamp));
        $ciphertext[0] = chr(ord($ciphertext[0]) ^ 1);
        self::assertFalse(openssl_decrypt($ciphertext, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag, $timestamp));
        self::assertFalse(@openssl_private_decrypt($wrappedKey, $unused, self::$private, OPENSSL_PKCS1_OAEP_PADDING));
        sodium_memzero($rsaBlock);
        sodium_memzero($aesKey);
        self::assertSame("Senha sintética ç🔐\0fim", $password);
    }

    public function testFreshRandomAesKeyAndIvForEveryPasswordEnvelope(): void
    {
        $key = self::key(1);
        $first = self::frame(AndroidPasswordEncryption::encrypt($key, 'synthetic', 1, 42));
        $second = self::frame(AndroidPasswordEncryption::encrypt($key, 'synthetic', 1, 42));
        self::assertSame('1', $first[0]);
        self::assertSame("\x01\x01", substr($first[2], 0, 2));
        self::assertNotSame(substr($first[2], 2, 12), substr($second[2], 2, 12));
        self::assertTrue(openssl_private_decrypt(substr($first[2], 16, 256), $a, self::$private, OPENSSL_PKCS1_PADDING));
        self::assertTrue(openssl_private_decrypt(substr($second[2], 16, 256), $b, self::$private, OPENSSL_PKCS1_PADDING));
        self::assertNotSame($a, $b);
        sodium_memzero($a);
        sodium_memzero($b);
    }

    public function testSupportedInputBoundariesArePreservedWithoutNormalization(): void
    {
        foreach ([0, 255] as $id) {
            foreach ([str_repeat('p', 4096), "e\u{0301}🔐"] as $password) {
                $value = AndroidPasswordEncryption::encrypt(self::key($id), $password, 2, 0);
                [, $timestamp, $frame] = self::frame($value);
                self::assertSame($id, ord($frame[1]));
                self::assertSame('0', $timestamp);
                self::assertTrue(openssl_private_decrypt(substr($frame, 16, 256), $aes, self::$private, OPENSSL_PKCS1_PADDING));
                self::assertSame($password, openssl_decrypt(substr($frame, 288), 'aes-256-gcm', $aes, OPENSSL_RAW_DATA, substr($frame, 2, 12), substr($frame, 272, 16), $timestamp));
                sodium_memzero($aes);
            }
        }
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputsFailClosed(int $id, string $password, int $version, int $timestamp): void
    {
        $this->expectException(MessengerException::class);
        $this->expectExceptionMessage('parameters are invalid');
        AndroidPasswordEncryption::encrypt(self::key($id), $password, $version, $timestamp);
    }

    /** @return iterable<string,array{int,string,int,int}> */
    public static function invalidInputs(): iterable
    {
        yield 'id cannot wrap into a byte' => [256, 'synthetic', 2, 42];
        yield 'empty password' => [1, '', 2, 42];
        yield 'local byte limit' => [1, str_repeat('p', 4097), 2, 42];
        yield 'malformed UTF8' => [1, "\xc0\xaf", 2, 42];
        yield 'isolated surrogate encoding' => [1, "\xed\xa0\x80", 2, 42];
        yield 'no raw version zero' => [1, 'synthetic', 0, 42];
        yield 'no unknown version' => [1, 'synthetic', 3, 42];
        yield 'negative timestamp' => [1, 'synthetic', 2, -1];
    }

    public function testExplicitTimestampCannotBypassKeyExpiry(): void
    {
        $key = AndroidPasswordKey::fromJson(json_encode(['key_id' => 1, 'public_key' => self::$public, 'seconds_to_live' => 1], JSON_THROW_ON_ERROR), 1000);
        try {
            AndroidPasswordEncryption::encrypt($key, 'synthetic', 2, 1000);
            self::fail('Expired key must fail');
        } catch (MessengerException $error) {
            self::assertSame('login_native_key_expired', $error->reason);
        }
    }

    public function testMalformedNonRsaWeakAndPrivateKeysAreRejectedWithoutWarningsOrKeyLeaks(): void
    {
        $ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $weak = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $ec);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $weak);
        self::assertTrue(openssl_pkey_export(self::$private, $privatePem));
        foreach (['malformed-public-key', "-----BEGIN PUBLIC KEY-----\nINVALID\n-----END PUBLIC KEY-----", openssl_pkey_get_details($ec)['key'], openssl_pkey_get_details($weak)['key'], $privatePem] as $pem) {
            try {
                AndroidPasswordEncryption::encrypt(self::key(1, $pem), 'NEVER-PRINT-THIS-SYNTHETIC-PASSWORD', 2, 42);
                self::fail('Invalid key must fail');
            } catch (MessengerException $error) {
                self::assertSame('login_native_key_invalid', $error->reason);
                self::assertStringNotContainsString('NEVER-PRINT', $error->getMessage());
                self::assertStringNotContainsString($pem, $error->getMessage());
            }
        }
        sodium_memzero($privatePem);
    }

    public function testPasswordIsSensitiveAndEncryptionHasNoRetainedInstanceState(): void
    {
        $method = new ReflectionMethod(AndroidPasswordEncryption::class, 'encrypt');
        self::assertCount(1, $method->getParameters()[1]->getAttributes(SensitiveParameter::class));
        self::assertFalse($method->getParameters()[2]->isOptional());
        self::assertSame([], (new ReflectionClass(AndroidPasswordEncryption::class))->getProperties());
    }

    public function testFailureTraceRedactsPasswordWhenArgumentsAreEnabled(): void
    {
        $original = ini_set('zend.exception_ignore_args', '0');
        try {
            AndroidPasswordEncryption::encrypt(self::key(1), 'NEVER-PRINT-THIS-SYNTHETIC-PASSWORD', 0, 42);
            self::fail('Invalid version must fail');
        } catch (MessengerException $error) {
            $argument = $error->getTrace()[0]['args'][1];
            self::assertInstanceOf(SensitiveParameterValue::class, $argument);
            self::assertStringNotContainsString('NEVER-PRINT', $error->getTraceAsString());
        } finally {
            if ($original !== false) ini_set('zend.exception_ignore_args', $original);
        }
    }

    private static function key(int $id, ?string $pem = null): AndroidPasswordKey
    {
        return AndroidPasswordKey::fromJson(json_encode(['key_id' => $id, 'public_key' => $pem ?? self::$public, 'seconds_to_live' => 600], JSON_THROW_ON_ERROR));
    }

    /** @return array{string,string,string} */
    private static function frame(string $encoded): array
    {
        $parts = explode(':', $encoded, 4);
        self::assertCount(4, $parts);
        self::assertSame('#PWD_MSGR', $parts[0]);
        $frame = base64_decode($parts[3], true);
        self::assertIsString($frame);
        return [$parts[1], $parts[2], $frame];
    }
}
