<?php
declare(strict_types=1);
namespace MVO\Service;

/** A caller-supplied schedule ID is already bound to a different immutable request. */
final class MessageScheduleConflict extends \InvalidArgumentException {}
