<?php
declare(strict_types=1);
namespace MVO\Service;

/** An explicit server refusal is not a successful operation. */
final class ServiceException extends \RuntimeException
{
    /** Optional fixed diagnostic enum; never stores an arbitrary server message. */
    public readonly ?string $errorKind;
    public function __construct(public readonly string $operation, public readonly ?string $serverCode, public readonly string $reason, ?string $errorKind=null)
    {
        $this->errorKind=$errorKind==='missing_required_variable_value'?$errorKind:null;
        parent::__construct($operation.': '.$reason.($serverCode !== null ? ' ('.$serverCode.')' : ''), ctype_digit($serverCode ?? '') ? (int)$serverCode : 0);
    }
}
