<?php
declare(strict_types=1);
namespace MVO\Transport;

/** A server connection rejection with explicit recovery semantics. */
final class ConnectionFailure extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $serverCode,
        public readonly bool $retryable = false,
        public readonly bool $revoked = false,
    ) { parent::__construct($message); }
}
