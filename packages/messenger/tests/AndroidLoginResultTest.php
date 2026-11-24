<?php
declare(strict_types=1);

namespace MVO\Messenger\Tests;

use MVO\Messenger\Auth\AndroidLoginResult;
use MVO\Messenger\MessengerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AndroidLoginResultTest extends TestCase
{
    /** Synthetic protocol fixture; no real account or credentials.
     * @return array{uid:string,access_token:string,session_cookies:list<array<string,string|bool>>,secret:string}
     */
    private static function success(): array
    {
        return ['uid'=>'12345678901234567890', 'access_token'=>'synthetic-private-token',
            'session_cookies'=>[
                ['name'=>'c_user', 'value'=>'12345678901234567890', 'domain'=>'.facebook.com', 'path'=>'/', 'secure'=>true],
                ['name'=>'xs', 'value'=>'synthetic-xs', 'domain'=>'.facebook.com', 'path'=>'/', 'secure'=>true, 'HttpOnly'=>true, 'SameSite'=>'Lax'],
                ['name'=>'locale', 'value'=>'pt_BR', 'domain'=>'.facebook.com', 'path'=>'/'],
            ], 'secret'=>'synthetic-discarded-secret'];
    }

    public function testSuccessPreservesExactIdentityAndScopedCookiesWithoutLeakingNativeSecrets(): void
    {
        $result = AndroidLoginResult::fromJson(json_encode(self::success(), JSON_THROW_ON_ERROR));
        self::assertSame('12345678901234567890', $result->accountId);
        self::assertSame('c_user=12345678901234567890; xs=synthetic-xs; locale=pt_BR', $result->cookies()->headerFor('https://www.facebook.com/'));
        self::assertSame('', $result->cookies()->headerFor('https://www.messenger.com/'));
        $private = json_decode($result->cookies()->exportPrivate(), true, 16, JSON_THROW_ON_ERROR);
        self::assertTrue($private[1]['httpOnly']);
        self::assertSame('Lax', $private[1]['sameSite']);
        ob_start(); var_dump($result); $debug = ob_get_clean();
        self::assertStringNotContainsString('synthetic-private-token', $debug);
        self::assertStringNotContainsString('synthetic-discarded-secret', $debug);
        self::assertStringNotContainsString('synthetic-xs', $debug);
    }

    public function testJsonLargeIntegerIdentityIsNotRounded(): void
    {
        $json = str_replace('"uid":"12345678901234567890"', '"uid":12345678901234567890', json_encode(self::success(), JSON_THROW_ON_ERROR));
        self::assertSame('12345678901234567890', AndroidLoginResult::fromJson($json)->accountId);
    }

    public function testHttpCookieExpiryIsConvertedWithoutLosingItsDeadline(): void
    {
        $response = self::success();
        $response['session_cookies'][0]['expires'] = 'Tue, 19 Jan 2038 03:14:07 GMT';
        $response['session_cookies'][1]['expires'] = 'Tue, 19-Jan-2038 03:14:07 GMT';
        $cookies = AndroidLoginResult::fromJson(json_encode($response, JSON_THROW_ON_ERROR))->cookies();
        self::assertStringContainsString('xs=synthetic-xs', $cookies->headerFor('https://www.facebook.com/', 2147483646));
        self::assertSame('locale=pt_BR', $cookies->headerFor('https://www.facebook.com/', 2147483647));
    }

    public function testNativeResultCannotAccidentallyBeSerializedIntoJobStorage(): void
    {
        $result = AndroidLoginResult::fromJson(json_encode(self::success(), JSON_THROW_ON_ERROR));
        $this->expectException(\LogicException::class);
        serialize($result);
    }

    /** @param array<string,mixed> $changes Partial and malformed native response fields. */
    #[DataProvider('incompleteResponses')]
    public function testPartialOrChallengeResponseNeverBecomesSession(array $changes): void
    {
        $response = array_replace(self::success(), $changes);
        try {
            AndroidLoginResult::fromJson(json_encode($response, JSON_THROW_ON_ERROR));
            self::fail('An incomplete response must not authenticate');
        } catch (MessengerException $error) {
            self::assertSame('login_native_response_invalid', $error->reason);
            self::assertStringNotContainsString('synthetic-private-token', $error->getMessage());
        }
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function incompleteResponses(): iterable
    {
        yield 'missing token'=>[['access_token'=>null]];
        yield 'error with identity'=>[['error'=>['message'=>'private challenge']]];
        yield 'legacy error code'=>[['error_code'=>401]];
        yield 'checkpoint with cookies'=>[['checkpoint_url'=>'https://www.facebook.com/checkpoint/']];
        yield 'no cookies'=>[['session_cookies'=>[]]];
        yield 'cookie map'=>[['session_cookies'=>['c_user'=>'123', 'xs'=>'value']]];
        yield 'float uid'=>[['uid'=>123.5]];
        yield 'token contains newline'=>[['access_token'=>"private\nvalue"]];
        yield 'no real cookie scope'=>[['session_cookies'=>[['name'=>'c_user','value'=>'123'], ['name'=>'xs','value'=>'value']]]];
        yield 'relative expiry'=>[['session_cookies'=>[
            ['name'=>'c_user','value'=>'123','domain'=>'.facebook.com','path'=>'/','expires'=>'tomorrow'],
            ['name'=>'xs','value'=>'value','domain'=>'.facebook.com','path'=>'/'],
        ]]];
        yield 'invalid HTTP date'=>[['session_cookies'=>[
            ['name'=>'c_user','value'=>'123','domain'=>'.facebook.com','path'=>'/','expires'=>'Tue, 39 Jan 2038 03:14:07 GMT'],
            ['name'=>'xs','value'=>'value','domain'=>'.facebook.com','path'=>'/'],
        ]]];
        yield 'expired cookies'=>[['session_cookies'=>[
            ['name'=>'c_user','value'=>'123','domain'=>'.facebook.com','path'=>'/','expires'=>1],
            ['name'=>'xs','value'=>'value','domain'=>'.facebook.com','path'=>'/','expires'=>1],
        ]]];
    }

    public function testDifferentCookieIdentityIsRejected(): void
    {
        $response = self::success(); $response['uid'] = '999';
        $this->expectException(MessengerException::class);
        $this->expectExceptionMessage('identity does not match');
        AndroidLoginResult::fromJson(json_encode($response, JSON_THROW_ON_ERROR));
    }

    public function testMalformedJsonHasNoPrivateBodyInDiagnostic(): void
    {
        try { AndroidLoginResult::fromJson('invalid synthetic-private-token'); self::fail('Invalid JSON'); }
        catch (MessengerException $error) {
            self::assertSame('login_native_response_invalid', $error->reason);
            self::assertStringNotContainsString('synthetic-private-token', $error->getMessage());
        }
    }
}
