<?php
declare(strict_types=1);
namespace MVO\Voip;

/** Receive state remains isolated by remote sender, even in group calls. @internal */
final class AudioReceiveStream
{
    public readonly RtpReception $audioReception;
    public readonly RtpReception $videoReception;
    public readonly JitterBuffer $playout;
    public int $received=0;
    public int $authFailures=0;
    public int $pt122Received=0;
    public int $pt122Decoded=0;
    public int $pt122Rejected=0;
    public bool $receiving=false;
    public float $lastHeardMs=0;
    public function __construct(public readonly string $id,public int $audioSsrc,public readonly AudioDecoder $decoder,int $clockRate,int $videoClockRate,int $capacity)
    {
        $this->audioReception=new RtpReception($clockRate); $this->videoReception=new RtpReception($videoClockRate); $this->playout=new JitterBuffer($capacity);
    }
    public function close():void { $this->decoder->close(); $this->playout->reset(); }
}
