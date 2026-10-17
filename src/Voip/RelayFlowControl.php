<?php
declare(strict_types=1);
namespace MVO\Voip;

interface RelayFlowControl
{
    /** An accepted call uses the one-second media keepalive cadence. */
    public function setMediaFlowing(bool $flowing=true):void;
}
