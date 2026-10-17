<?php
declare(strict_types=1);
namespace MVO\Transport;
/** Server requested reconnect after durable pairing; pending IQ must not be replayed. */
final class SessionRestartRequired extends \RuntimeException {}
