<?php
declare(strict_types=1);
namespace MVO\Service;

/** Only used when authorization fails before the scheduled publication's first transport write. */
final class ScheduledPublicationNotExecuted extends \RuntimeException
{
    public function __construct(\Throwable $previous){parent::__construct('Scheduled publication authorization was revoked before execution',0,$previous);}
}
