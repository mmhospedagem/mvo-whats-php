<?php
declare(strict_types=1);

namespace MVO\Channels\WhatsApp;

use MVO\Channels\Capabilities;
use MVO\Channels\PersistentClient;
use MVO\WaClient;

/** Optional facade: the existing WaClient remains the sole owner of session and protocol state. */
final class WhatsAppClient implements PersistentClient
{
    public function __construct(private readonly WaClient $client)
    {
    }

    public function channel(): string
    {
        return 'whatsapp';
    }

    public function provider(): string
    {
        return 'mvo_protocol';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(['message.text', 'events.native', 'connection.persistent', 'native_client']);
    }

    /** @return array<string, mixed> */
    public function accountInfo(): array
    {
        return $this->client->accountInfo();
    }

    /** Payload and callback timing are preserved, including private auth_paired data.
     * @param callable(array<string, mixed>): void $handler
     */
    public function on(string $event, callable $handler): static
    {
        $this->client->on($event, $handler);
        return $this;
    }

    /** @return array<string, mixed> */
    public function sendText(string $to, string $text): array
    {
        return $this->client->sendText($to, $text);
    }

    public function connect(): void
    {
        $this->client->connect();
    }

    public function poll(float $timeoutSeconds = 0.5): void
    {
        $this->client->poll($timeoutSeconds);
    }

    public function stop(): void
    {
        $this->client->stop();
    }

    public function isConnected(): bool
    {
        return $this->client->isConnected();
    }

    /** Access provider-specific features without inventing a common contract for every WaClient method. */
    public function nativeClient(): WaClient
    {
        return $this->client;
    }
}
