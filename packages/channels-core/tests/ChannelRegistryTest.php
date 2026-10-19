<?php
declare(strict_types=1);

namespace MVO\Channels\Tests;

use MVO\Channels\Capabilities;
use MVO\Channels\ChannelClient;
use MVO\Channels\ChannelRegistry;
use MVO\Channels\ConnectionConfig;
use MVO\Channels\PersistentClient;
use PHPUnit\Framework\TestCase;

final class ChannelRegistryTest extends TestCase
{
    public function testExactProviderSelectionAndIndependentConnectionsDoNotRequirePolling(): void
    {
        $seen = [];
        $registry = new ChannelRegistry();
        foreach ([['whatsapp', 'mvo_protocol'], ['facebook', 'mvo_web'], ['facebook', 'fixture']] as [$channel, $provider]) {
            $registry->register($channel, $provider, static function (ConnectionConfig $config) use (&$seen): ChannelClient {
                $seen[] = $config;
                return new FixtureClient($config);
            });
        }
        $a = new ConnectionConfig('same-id', 'whatsapp', 'mvo_protocol', 'qr');
        $b = new ConnectionConfig('same-id', 'facebook', 'mvo_web', 'session');
        $c = new ConnectionConfig('another-id', 'facebook', 'fixture', 'session');
        $wa = $registry->create($a);
        $facebook = $registry->create($b);
        $alternate = $registry->create($c);
        self::assertSame([$a, $b, $c], $seen);
        self::assertSame('whatsapp', $wa->channel());
        self::assertSame('facebook', $facebook->channel());
        self::assertSame('fixture', $alternate->provider());
        self::assertNotInstanceOf(PersistentClient::class, $facebook);
        self::assertNotSame($facebook, $registry->create($b), 'The registry does not cache or share sessions implicitly');
        $result = $facebook->sendText('000123', 'Text');
        self::assertSame('000123', $result['to'], 'Provider IDs must stay strings and preserve their native representation');
        self::assertSame('same-id', $result['connection']);
    }

    public function testAnUnknownProviderDoesNotFallBackToAnotherRegisteredFactory(): void
    {
        $called = false;
        $registry = new ChannelRegistry();
        $registry->register('facebook', 'mvo_web', static function (ConnectionConfig $config) use (&$called): ChannelClient {
            $called = true;
            return new FixtureClient($config);
        });
        self::assertTrue($registry->has('facebook', 'mvo_web'));
        self::assertFalse($registry->has('facebook', 'other'));
        try {
            $registry->create(new ConnectionConfig('account', 'facebook', 'other', 'session'));
            self::fail('An unknown provider was selected');
        } catch (\InvalidArgumentException) {
            self::assertFalse($called);
        }
    }

    public function testDuplicateFactoryCannotSilentlyReplaceExistingAuthenticationConfiguration(): void
    {
        $registry = new ChannelRegistry();
        $factory = static fn (ConnectionConfig $config): ChannelClient => new FixtureClient($config);
        $registry->register('facebook', 'mvo_web', $factory);
        $this->expectException(\LogicException::class);
        $registry->register('facebook', 'mvo_web', $factory);
    }

    public function testWrongProviderReturnedByFactoryIsRejected(): void
    {
        $registry = new ChannelRegistry();
        $registry->register('facebook', 'mvo_web', static fn (ConnectionConfig $config): ChannelClient => new FixtureClient(
            new ConnectionConfig($config->id, 'whatsapp', 'mvo_protocol', $config->authMode),
        ));
        $this->expectException(\UnexpectedValueException::class);
        $registry->create(new ConnectionConfig('account', 'facebook', 'mvo_web', 'session'));
    }

    public function testAuthenticationModeIsPassedUnchangedForFactoryValidation(): void
    {
        $registry = new ChannelRegistry();
        $registry->register('whatsapp', 'mvo_protocol', static function (ConnectionConfig $config): ChannelClient {
            if (!in_array($config->authMode, ['qr', 'pairing_code'], true)) {
                throw new \InvalidArgumentException('Unsupported authentication mode');
            }
            return new FixtureClient($config);
        });
        $this->expectException(\InvalidArgumentException::class);
        $registry->create(new ConnectionConfig('account', 'whatsapp', 'mvo_protocol', 'oauth'));
    }
}

/** An HTTP or webhook-based provider can implement only ChannelClient. */
final class FixtureClient implements ChannelClient
{
    public function __construct(private readonly ConnectionConfig $config) {}
    public function channel(): string { return $this->config->channel; }
    public function provider(): string { return $this->config->provider; }
    public function capabilities(): Capabilities { return new Capabilities(['message.text']); }
    public function accountInfo(): array { return ['id' => $this->config->id]; }
    public function on(string $event, callable $handler): static { return $this; }
    public function sendText(string $to, string $text): array
    {
        return ['connection' => $this->config->id, 'to' => $to, 'text' => $text];
    }
}
