<?php
declare(strict_types=1);
namespace MVO\Voip;

/** RTCP pacing with 10% jitter and uint32 counter wrap (RTP or elapsed milliseconds). */
final class SenderReportSchedule
{
    private float $threshold;
    private ?int $last=null;
    private readonly \Closure $random;
    /** @param callable():float|null $random Uniform value in [0,1]. */
    public function __construct(private readonly float $interval,?callable $random=null)
    {
        if(!is_finite($interval) || $interval<=0 || $interval>=2147483648) throw new \InvalidArgumentException('Invalid sender report interval');
        $this->random=($random??static fn():float=>random_int(0,1000000)/1000000)(...); $this->redraw();
    }
    /** @param callable():float|null $random */
    public static function onMediaClock(float $intervalMs,int $clockRate,?callable $random=null):self
    {
        if($clockRate<=0) throw new \InvalidArgumentException('Report media clock rate must be positive');
        return new self($intervalMs*$clockRate/1000,$random);
    }
    /** @param callable():float|null $random */
    public static function onWallClock(float $intervalMs,?callable $random=null):self { return new self($intervalMs,$random); }
    public function shouldReport(int $current):bool
    {
        $current&=0xffffffff;
        if($this->last===null) { $this->last=$current; return false; }
        if((($current-$this->last)&0xffffffff)<$this->threshold) return false;
        $this->last=$current; $this->redraw(); return true;
    }
    public function reset():void { $this->last=null; $this->redraw(); }
    private function redraw():void { $value=($this->random)(); if(!is_finite($value) || $value<0 || $value>1) throw new \UnexpectedValueException('Invalid report schedule random value'); $this->threshold=round($this->interval*(1+0.1*($value*2-1))); }
}
