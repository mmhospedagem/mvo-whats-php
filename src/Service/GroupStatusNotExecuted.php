<?php
declare(strict_types=1);
namespace MVO\Service;

/** Application authorization was withdrawn before this message's transport write. */
final class GroupStatusNotExecuted extends \RuntimeException
{
    public function __construct(public readonly string $messageId,\Throwable $previous){parent::__construct('Group Status publication authorization was withdrawn before transport write',0,$previous);}
}
