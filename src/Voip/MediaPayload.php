<?php
declare(strict_types=1);
namespace MVO\Voip;

/** Payload classification precedes stream reception statistics, avoiding parity SSRC pollution. */
final class MediaPayload
{
    public static function kind(int $payloadType):string
    {
        if($payloadType<0 || $payloadType>127) throw new \InvalidArgumentException('RTP payload type must be uint7');
        return match(true) { in_array($payloadType,[120,121,122],true)=>'audio', $payloadType===97=>'h264', $payloadType>=103 && ($payloadType-103)%3===0=>'reed-solomon-parity', default=>'unknown' };
    }
}
