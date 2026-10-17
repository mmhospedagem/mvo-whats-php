<?php
declare(strict_types=1);
namespace MVO\Service;

/** A publisher proved that this reserved message has neither an outbox record nor a transport write. */
final class ScheduledMessagePreparationFailed extends \RuntimeException
{
    public function __construct(\Throwable $previous){parent::__construct('Scheduled message preparation failed before publication',0,$previous);}
}
