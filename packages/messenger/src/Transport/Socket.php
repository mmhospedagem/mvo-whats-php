<?php
declare(strict_types=1);
namespace MVO\Messenger\Transport;

interface Socket
{
    public function connect(): void;
    public function send(string $payload): void;
    public function receive(float $timeoutSeconds = 0.5): ?string;
    public function close(): void;
    public function isConnected(): bool;
}
