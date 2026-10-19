<?php
declare(strict_types=1);

namespace MVO\Messenger;

/** Only a public diagnostic belongs here; cookies and response bodies are private. */
final class MessengerException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
