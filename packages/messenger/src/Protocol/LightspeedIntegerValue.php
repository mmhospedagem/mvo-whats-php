<?php
declare(strict_types=1);
namespace MVO\Messenger\Protocol;

/** Internal signed integer value; public projections retain its decimal string. */
final class LightspeedIntegerValue
{
    public function __construct(public readonly string $decimal){}
}
