<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Protocol\ClientPayload;
use MVO\Protocol\Protobuf;

final class ClientPayloadTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function credentials(): array
    {
        return ['registrationId' => 258, 'identityKeyPair' => ['public' => str_repeat('i', 32)],
            'signedPreKey' => ['id' => 0x010203, 'public' => str_repeat('p', 32), 'signature' => str_repeat('s', 64)],
            'meJid' => '5511999999999:17@s.whatsapp.net', 'loginCounter' => 3];
    }

    /** @return array<int,int|string> */
    private static function fields(string $bytes): array
    {
        $values = [];
        foreach (Protobuf::decode($bytes) as $field) $values[$field['number']] = $field['value'];
        return $values;
    }

    public function testRegistrationMatchesProtoSchemaAndNetworkByteOrder(): void
    {
        $version = '2.3000.1030123456';
        $payload = self::fields(ClientPayload::registration(self::credentials(), $version));
        self::assertSame(0, $payload[3]);
        self::assertSame(0, $payload[33]);
        self::assertSame(1, $payload[12]);
        self::assertSame(1, $payload[13]);
        self::assertArrayNotHasKey(1, $payload);
        $pairing = self::fields($payload[19]);
        self::assertSame("\x00\x00\x01\x02", $pairing[1]);
        self::assertSame("\x05", $pairing[2]);
        self::assertSame(str_repeat('i', 32), $pairing[3]);
        self::assertSame("\x01\x02\x03", $pairing[4]);
        self::assertSame(str_repeat('p', 32), $pairing[5]);
        self::assertSame(str_repeat('s', 64), $pairing[6]);
        self::assertSame(md5($version, true), $pairing[7]);
        $device = self::fields($pairing[8]);
        self::assertSame('Windows', $device[1]);
        self::assertSame(1, $device[3]);
        self::assertSame(0, $device[4]);
        $agent = self::fields($payload[5]);
        self::assertSame(14, $agent[1]);
        self::assertSame([1 => 2, 2 => 3000, 3 => 1030123456], self::fields($agent[2]));
        self::assertSame('000', $agent[3]);
        self::assertSame([4 => 0], self::fields($payload[6]));
    }

    public function testLoginUsesStoredUsernameDeviceAndCounters(): void
    {
        $credentials = self::credentials() + ['connectAttemptCount' => 2, 'lidDbMigrated' => true];
        $payload = self::fields(ClientPayload::login($credentials, '2.3000.1000000001.4.5'));
        self::assertSame(5511999999999, $payload[1]);
        self::assertSame(17, $payload[18]);
        self::assertSame(2, $payload[16]);
        self::assertSame(3, $payload[24]);
        self::assertSame(1, $payload[33]);
        self::assertSame(1, $payload[41]);
        self::assertArrayNotHasKey(19, $payload);
        self::assertSame([1 => 2, 2 => 3000, 3 => 1000000001, 4 => 4, 5 => 5], self::fields(self::fields($payload[5])[2]));
    }

    public function testDeviceMetadataUsesRequestedBrowserAndOs(): void
    {
        $props = self::fields(ClientPayload::deviceProps('edge', 'Linux'));
        self::assertSame('Linux', $props[1]);
        self::assertSame(6, $props[3]);
        self::assertSame(114149, self::fields($props[5])[3]);
        self::assertSame(60, self::fields($props[5])[19]);
    }

    public function testFullHistoryRequestIsExplicitAndDefaultsToRecentHistory(): void
    {
        foreach ([false, true] as $full) {
            $payload=self::fields(ClientPayload::registration(self::credentials(),'2.3000.1',requireFullSync:$full));
            $props=self::fields(self::fields($payload[19])[8]);
            self::assertSame($full ? 1 : 0,$props[4]);
            self::assertSame('Windows',$props[1]);
            self::assertSame(114149,self::fields($props[5])[3]);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function invalidVersion(): iterable
    {
        yield 'two parts' => ['2.3000'];
        yield 'non numeric' => ['2.3000.beta'];
        yield 'overflows uint32' => ['2.3000.4294967296'];
        yield 'negative' => ['2.3000.-1'];
        yield 'empty component' => ['2.3000.1.'];
    }

    #[DataProvider('invalidVersion')]
    public function testInvalidVersionsFailBeforeSending(string $version): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClientPayload::registration(self::credentials(), $version);
    }

    public function testUnregisteredCredentialsCannotBuildLogin(): void
    {
        $credentials = self::credentials();
        unset($credentials['meJid']);
        $this->expectException(\InvalidArgumentException::class);
        ClientPayload::login($credentials, '2.3000.1');
    }

    public function testIdentityPublicKeyMustBeRaw32Bytes(): void
    {
        $credentials = self::credentials();
        $credentials['identityKeyPair']['public'] = "\x05" . str_repeat('i', 32);
        $this->expectException(\InvalidArgumentException::class);
        ClientPayload::registration($credentials, '2.3000.1');
    }
}
