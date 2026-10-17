<?php
declare(strict_types=1);
namespace MVO\Transport;
use MVO\Protocol\BinaryNode;

interface NodeSession
{
    public function start(string $staticPrivate, string $staticPublic, string $clientPayload): string;
    public function sendNode(BinaryNode $node): void;
    public function receiveNode(float $timeoutSeconds = 1.0): ?BinaryNode;
    public function close(): void;
}
