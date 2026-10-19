<?php
declare(strict_types=1);
namespace MVO\Facebook;

final class FacebookException extends \RuntimeException
{
    public function __construct(public readonly string $reason,string $message,public readonly string $outcome='not_executed',public readonly bool $retryable=false,public readonly ?string $commandId=null){parent::__construct($message);}
}
