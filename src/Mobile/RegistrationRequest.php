<?php
declare(strict_types=1);
namespace MVO\Mobile;

/** Private transport request. It can contain an OTP or an authentication proof; never log it. */
final readonly class RegistrationRequest
{
    /** @param array<string,string> $parameters
     * @param array<string,mixed> $deviceInfo */
    public function __construct(public string $operation,public array $parameters,public array $deviceInfo)
    {
        if(!in_array($operation,['code','register','security'],true))throw new \InvalidArgumentException('Unknown registration operation');
    }
}
