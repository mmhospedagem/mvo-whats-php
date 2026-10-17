<?php
declare(strict_types=1);
namespace MVO\Voip;

/** Shared monotonic origin for both audio and video RTP timestamps. */
final class MediaClock
{
    private ?float $origin=null;
    private readonly \Closure $now;
    /** @param (callable():(float|int))|null $now Milliseconds on a monotonic clock. */
    public function __construct(?callable $now=null) { $this->now=($now??static fn():float=>hrtime(true)/1000000)(...); }
    public function now():float { return (float)($this->now)(); }
    public function isStarted():bool { return $this->origin!==null; }
    public function start(?float $atMs=null):void
    {
        if($this->origin!==null) return;
        $at=$atMs??$this->now(); if(!is_finite($at)) throw new \InvalidArgumentException('Invalid media clock instant');
        $this->origin=$at-250;
    }
    public function ticksAt(float $instantMs,int $clockRate):int
    {
        if(!is_finite($instantMs) || $clockRate<=0) throw new \InvalidArgumentException('Invalid media timestamp or clock rate');
        $this->start();
        return ((int)round(max(0,$instantMs-$this->origin)*$clockRate/1000))&0xffffffff;
    }
}
