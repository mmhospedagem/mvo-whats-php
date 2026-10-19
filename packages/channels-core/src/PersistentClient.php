<?php
declare(strict_types=1);

namespace MVO\Channels;

/** Implement only when the provider requires a persistent session loop. */
interface PersistentClient extends ChannelClient
{
    /** Start or restore; this does not imply authentication has completed. */
    public function connect(): void;

    public function poll(float $timeoutSeconds = 0.5): void;

    /** Stop locally while preserving usable authentication state. */
    public function stop(): void;

    public function isConnected(): bool;
}
