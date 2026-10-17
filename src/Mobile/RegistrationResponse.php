<?php
declare(strict_types=1);
namespace MVO\Mobile;

/** Private upstream reply. Only RegistrationService creates public response data. */
final readonly class RegistrationResponse
{
    /** @param array<string,mixed> $body */
    public function __construct(public array $body,public int $httpStatus=200,public int $retryAfter=0)
    {
        if($httpStatus<100 || $httpStatus>599 || $retryAfter<0)throw new \InvalidArgumentException('Invalid registration HTTP response');
    }
}
