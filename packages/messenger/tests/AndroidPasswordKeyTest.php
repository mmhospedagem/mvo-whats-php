<?php
declare(strict_types=1);

namespace MVO\Messenger\Tests;

use MVO\Messenger\Auth\AndroidPasswordKey;
use MVO\Messenger\MessengerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AndroidPasswordKeyTest extends TestCase
{
    public function testServerKeyAndItsOriginalDeadlineAreRetainedAndExpireExactly(): void
    {
        $key = AndroidPasswordKey::fromJson('{"key_id":17,"public_key":"SYNTHETIC_PUBLIC_KEY","seconds_to_live":60}', 1000);
        self::assertSame(17, $key->id); self::assertSame(1060, $key->expiresAt);
        self::assertSame('SYNTHETIC_PUBLIC_KEY', $key->publicKey(1059));
        $this->expectException(MessengerException::class);
        $this->expectExceptionMessage('Fetch a current');
        $key->publicKey(1060);
    }

    public function testRotationDoesNotSilentlySubstituteAnOlderOrEmbeddedKey(): void
    {
        $old = AndroidPasswordKey::fromJson('{"key_id":17,"public_key":"OLD_KEY","seconds_to_live":10}', 1000);
        $current = AndroidPasswordKey::fromJson('{"key_id":18,"public_key":"CURRENT_KEY","seconds_to_live":60}', 1010);
        self::assertSame('CURRENT_KEY', $current->publicKey(1010));
        try { $old->publicKey(1010); self::fail('The expired key must not be returned'); }
        catch (MessengerException $error) { self::assertSame('login_native_key_expired', $error->reason); }
        self::assertSame(18, $current->id);
    }

    #[DataProvider('invalidKeys')]
    public function testIncompleteAndExpiredResponsesHaveNoDefaultKeyFallback(array $changes): void
    {
        $data = array_replace(['key_id'=>17,'public_key'=>'SYNTHETIC_PUBLIC_KEY','seconds_to_live'=>60], $changes);
        try { AndroidPasswordKey::fromJson(json_encode($data, JSON_THROW_ON_ERROR), 1000); self::fail('Invalid key'); }
        catch (MessengerException $error) { self::assertSame('login_native_key_invalid', $error->reason); }
    }

    public static function invalidKeys(): iterable
    {
        yield 'no key'=>[['public_key'=>null]];
        yield 'empty key'=>[['public_key'=>'']];
        yield 'invalid key ID'=>[['key_id'=>-1]];
        yield 'fractional key ID'=>[['key_id'=>1.5]];
        yield 'no TTL'=>[['seconds_to_live'=>null]];
        yield 'expired'=>[['seconds_to_live'=>0]];
        yield 'overflow'=>[['seconds_to_live'=>PHP_INT_MAX]];
        yield 'provider error with key fields'=>[['error'=>['code'=>10]]];
    }
}
