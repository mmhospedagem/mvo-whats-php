<?php
declare(strict_types=1);
namespace MVO\Voip;

/** Per-SSRC RTP loss/jitter and SR timing, in the stream's own clock units. */
final class RtpReception
{
    private int $ssrc=0;
    private ?int $base=null;
    private int $highest=0;
    private int $received=0;
    private int $expectedPrior=0;
    private int $receivedPrior=0;
    private int $expectedPriorLoss=0;
    private int $receivedPriorLoss=0;
    private int $lastTimestamp=0;
    private float $lastArrivalTicks=0;
    private float $jitter=0;
    private int $lsr=0;
    private ?float $senderReportArrivalMs=null;
    private ?int $pendingJump=null;

    public function __construct(private readonly int $clockRate)
    {
        if($clockRate<=0) throw new \InvalidArgumentException('RTP clock rate must be positive');
    }

    public function sourceSsrc():int { return $this->ssrc; }

    public function observe(int $ssrc,int $sequence,int $timestamp,float $arrivalMs):void
    {
        if($ssrc<0 || $ssrc>0xffffffff || $sequence<0 || $sequence>65535 || $timestamp<0 || $timestamp>0xffffffff || !is_finite($arrivalMs)) throw new \InvalidArgumentException('Invalid reception sample');
        $arrival=$arrivalMs*$this->clockRate/1000;
        if($this->base===null || $this->ssrc!==$ssrc) { $this->restart($ssrc,$sequence,$timestamp,$arrival); return; }
        ++$this->received;
        $timestampDelta=($timestamp-$this->lastTimestamp)&0xffffffff;
        if($timestampDelta>=0x80000000) $timestampDelta-=0x100000000;
        $drift=abs($arrival-$this->lastArrivalTicks-$timestampDelta);
        $this->jitter+=($drift-$this->jitter)/16;
        $this->lastTimestamp=$timestamp; $this->lastArrivalTicks=$arrival;
        $delta=($sequence-($this->highest&65535))&65535;
        if($delta>=32768) $delta-=65536;
        $extended=$this->highest+$delta;
        // Two consecutive packets far behind a wrapped stream confirm a sender restart.
        if($this->highest>=65536 && $sequence-($this->highest&65535)>32768) {
            $previous=$this->pendingJump; $this->pendingJump=$sequence;
            if($previous!==null && $sequence===(($previous+1)&65535)) $this->restart($ssrc,$sequence,$timestamp,$arrival);
            return;
        }
        $this->pendingJump=null;
        if($extended>$this->highest) $this->highest=$extended;
    }

    public function observeSenderReport(string $packet,float $arrivalMs):bool
    {
        try { $report=Rtcp::parse($packet)[0]; } catch(\UnexpectedValueException) { return false; }
        if($report['type']!==200 || $this->base===null || $report['senderSsrc']!==$this->ssrc) return false;
        $this->lsr=(($report['ntpSeconds']&65535)<<16)|($report['ntpFraction']>>16);
        $this->senderReportArrivalMs=$arrivalMs;
        return true;
    }

    /** Consumes the report loss interval, independently of takeLossPercent().
     * @return array<string,int>
     */
    public function report(float $nowMs):array
    {
        $expected=$this->expected(); $interval=$expected-$this->expectedPrior; $lost=$interval-($this->received-$this->receivedPrior);
        $this->expectedPrior=$expected; $this->receivedPrior=$this->received;
        return ['ssrc'=>$this->ssrc,'fractionLost'=>$interval>0 && $lost>0?min(255,(int)floor($lost*256/$interval)):0,'packetsLost'=>$expected-$this->received,'highestSequence'=>$this->highest&65535,'cycles'=>intdiv($this->highest,65536),'jitter'=>(int)floor($this->jitter),'lastSenderReport'=>$this->lsr,'delaySinceLastSenderReport'=>$this->senderReportArrivalMs===null?0:(int)min(0xffffffff,max(0,round(($nowMs-$this->senderReportArrivalMs)*65.536)))];
    }

    public function lossPercent():float { $expected=$this->expected(); return $expected>0?max(0,($expected-$this->received)/$expected*100):0; }
    public function takeLossPercent():float
    {
        $expected=$this->expected(); $interval=$expected-$this->expectedPriorLoss; $lost=$interval-($this->received-$this->receivedPriorLoss);
        $this->expectedPriorLoss=$expected; $this->receivedPriorLoss=$this->received;
        return $interval>0?max(0,$lost/$interval*100):0;
    }

    public function reset():void
    {
        $this->ssrc=0; $this->base=null; $this->highest=0; $this->received=0; $this->expectedPrior=0; $this->receivedPrior=0;
        $this->expectedPriorLoss=0; $this->receivedPriorLoss=0; $this->lastTimestamp=0; $this->lastArrivalTicks=0; $this->jitter=0;
        $this->lsr=0; $this->senderReportArrivalMs=null; $this->pendingJump=null;
    }
    private function expected():int { return $this->base===null?0:$this->highest-$this->base+1; }
    private function restart(int $ssrc,int $sequence,int $timestamp,float $arrivalTicks):void
    {
        $same=$this->ssrc===$ssrc; $lsr=$this->lsr; $arrival=$this->senderReportArrivalMs;
        $this->reset(); $this->ssrc=$ssrc; $this->base=$sequence; $this->highest=$sequence; $this->received=1; $this->lastTimestamp=$timestamp; $this->lastArrivalTicks=$arrivalTicks;
        if($same) { $this->lsr=$lsr; $this->senderReportArrivalMs=$arrival; }
    }
}
