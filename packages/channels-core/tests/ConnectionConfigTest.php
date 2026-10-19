<?php
declare(strict_types=1);

namespace MVO\Channels\Tests;

use MVO\Channels\Capabilities;
use MVO\Channels\ConnectionConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectionConfigTest extends TestCase
{
    public function testDescriptorIsImmutableAndSerializesOnlyItsExplicitPublicFields(): void
    {
        $config = new ConnectionConfig('customer-42', 'facebook', 'mvo_web', 'session');
        self::assertSame([
            'id' => 'customer-42', 'channel' => 'facebook', 'provider' => 'mvo_web', 'auth_mode' => 'session',
        ], json_decode(json_encode($config, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['id', 'channel', 'provider', 'authMode'], array_keys(get_object_vars($config)));
        $this->expectException(\Error::class);
        $config->id = 'another-customer';
    }

    public static function invalidDescriptors(): iterable
    {
        yield 'empty connection' => ['', 'facebook', 'mvo_web', 'session'];
        yield 'traversal' => ['../account', 'facebook', 'mvo_web', 'session'];
        yield 'invalid channel' => ['account', 'Facebook', 'mvo_web', 'session'];
        yield 'invalid provider' => ['account', 'facebook', "mvo_web\0", 'session'];
        yield 'invalid authentication mode' => ['account', 'facebook', 'mvo_web', 'cookie=secret'];
        yield 'separator ambiguity' => ['account', 'facebook:mvo', 'web', 'session'];
    }

    #[DataProvider('invalidDescriptors')]
    public function testInvalidDescriptorsAreRejected(string $id, string $channel, string $provider, string $authMode): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConnectionConfig($id, $channel, $provider, $authMode);
    }

    public function testCapabilitiesAreConservativeImmutableDeclarations(): void
    {
        $capabilities = new Capabilities(['message.text', 'events.native', 'message.text']);
        self::assertTrue($capabilities->supports('message.text'));
        self::assertFalse($capabilities->supports('calls.video'));
        self::assertSame(['message.text', 'events.native'], $capabilities->all());
        $copy = $capabilities->all();
        $copy[] = 'calls.video';
        self::assertFalse($capabilities->supports('calls.video'));
        self::assertSame('["message.text","events.native"]', json_encode($capabilities, JSON_THROW_ON_ERROR));
    }

    public function testCapabilitiesCannotBeMistakenForArbitraryOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Capabilities(['cookie' => 'secret']);
    }
}
