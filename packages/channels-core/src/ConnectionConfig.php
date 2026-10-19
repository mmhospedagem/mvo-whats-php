<?php
declare(strict_types=1);

namespace MVO\Channels;

/** Public connection descriptor. Secrets and storage paths belong to a provider's private configuration. */
final readonly class ConnectionConfig implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $channel,
        public string $provider,
        public string $authMode,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid connection ID');
        }
        foreach ([$channel, $provider, $authMode] as $name) {
            if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name) !== 1) {
                throw new \InvalidArgumentException('Invalid channel, provider or authentication mode');
            }
        }
    }

    /** Exact tuple: neither channel nor account IDs are normalized into another provider's namespace.
     * @return array{id: string, channel: string, provider: string, auth_mode: string}
     */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'channel' => $this->channel, 'provider' => $this->provider, 'auth_mode' => $this->authMode];
    }
}
