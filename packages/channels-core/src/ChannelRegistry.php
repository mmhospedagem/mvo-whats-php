<?php
declare(strict_types=1);

namespace MVO\Channels;

/** Factories are explicit and application-owned; there is no fallback to a different provider. */
final class ChannelRegistry
{
    /** @var array<string, array<string, \Closure(ConnectionConfig): ChannelClient>> */
    private array $factories = [];

    /** @param callable(ConnectionConfig): ChannelClient $factory */
    public function register(string $channel, string $provider, callable $factory): self
    {
        // Reuse descriptor validation without storing application secrets or a connection instance.
        new ConnectionConfig('registry', $channel, $provider, 'registry');
        if (isset($this->factories[$channel][$provider])) {
            throw new \LogicException('A factory is already registered for this channel and provider');
        }
        $this->factories[$channel][$provider] = \Closure::fromCallable($factory);
        return $this;
    }

    public function has(string $channel, string $provider): bool
    {
        return isset($this->factories[$channel][$provider]);
    }

    /** Factories decide authentication support and private storage. Creating does not connect. */
    public function create(ConnectionConfig $config): ChannelClient
    {
        $factory = $this->factories[$config->channel][$config->provider] ?? null;
        if ($factory === null) {
            throw new \InvalidArgumentException('No factory is registered for this channel and provider');
        }
        $client = $factory($config);
        if ($client->channel() !== $config->channel || $client->provider() !== $config->provider) {
            throw new \UnexpectedValueException('The factory returned a client for another channel or provider');
        }
        return $client;
    }
}
