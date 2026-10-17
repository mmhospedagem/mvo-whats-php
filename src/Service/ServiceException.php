<?php
declare(strict_types=1);
namespace MVO\Service;

/** An explicit server refusal is not a successful operation. */
final class ServiceException extends \RuntimeException
{
    public function __construct(public readonly string $operation, public readonly ?string $serverCode, public readonly string $reason)
    {
        parent::__construct($operation.': '.$reason.($serverCode !== null ? ' ('.$serverCode.')' : ''), ctype_digit($serverCode ?? '') ? (int)$serverCode : 0);
    }
}
