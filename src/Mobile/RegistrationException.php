<?php
declare(strict_types=1);
namespace MVO\Mobile;

/** Registration failures expose only a stable reason and a sanitized public state. */
final class RegistrationException extends \RuntimeException
{
    /** @param array<string,mixed> $registrationState */
    public function __construct(public readonly string $reason,string $message,public readonly int $retryAfter=0,public readonly array $registrationState=[],?\Throwable $previous=null)
    {
        parent::__construct($message,0,$previous);
    }
}
