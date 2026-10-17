<?php
declare(strict_types=1);
namespace MVO\Voip;

/** Maps arbitrary host capture epochs, drift and reordering onto the media clock. */
final class HostCaptureTimeMapper
{
    private bool $learned=false;
    private float $offset=0;
    private float $lastHost=0;
    private float $lastNow=0;
    private float $lastInstant=-INF;
    private float $blockLag=INF;
    private float $previousLag=INF;
    private float $blockStart=0;
    public function __construct(private readonly float $minStepMs)
    {
        if(!is_finite($minStepMs) || $minStepMs<=0) throw new \InvalidArgumentException('Capture clock step must be positive');
    }
    public function map(float $hostMs,float $nowMs):float
    {
        if(!is_finite($hostMs) || !is_finite($nowMs)) throw new \InvalidArgumentException('Invalid capture timestamp');
        if(!$this->learned || $this->lastHost-$hostMs>=1000) {
            $this->learned=true; $this->offset=$nowMs-$hostMs; $this->blockLag=0; $this->previousLag=INF; $this->blockStart=$nowMs; $this->lastHost=$hostMs;
            return $this->accept($nowMs,$nowMs);
        }
        if($hostMs<$this->lastHost) return $this->accept($nowMs,$hostMs+$this->offset);
        $this->lastHost=$hostMs; $instant=$hostMs+$this->offset;
        if($instant>$nowMs) { $this->moveOffset($nowMs-$instant); $instant=$nowMs; }
        $lag=$nowMs-$instant;
        if($nowMs-$this->blockStart>=4000) { $this->previousLag=$this->blockLag; $this->blockLag=$lag; $this->blockStart=$nowMs; }
        else $this->blockLag=min($this->blockLag,$lag);
        $windowLag=min($this->blockLag,$this->previousLag);
        if($windowLag>25) { $catchUp=min($windowLag-25,max(0,$nowMs-$this->lastNow)*0.005); $this->moveOffset($catchUp); $instant+=$catchUp; }
        return $this->accept($nowMs,$instant);
    }
    private function moveOffset(float $delta):void { $this->offset+=$delta; $this->blockLag-=$delta; $this->previousLag-=$delta; }
    private function accept(float $now,float $instant):float { $this->lastNow=$now; return $this->lastInstant=max($instant,$this->lastInstant+$this->minStepMs); }
}
