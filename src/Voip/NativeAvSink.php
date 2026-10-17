<?php
declare(strict_types=1);
namespace MVO\Voip;

/** Bounded nonblocking backpressure for an explicit native renderer or file encoder. @internal */
final class NativeAvSink
{
    private string $pending='';
    private bool $started=false;
    private bool $closed=false;
    private int $written=0;
    public function __construct(private readonly NativeAvProcess $process,private readonly int $maxQueuedBytes=1048576)
    {
        if($maxQueuedBytes<4096 || $maxQueuedBytes>33554432) throw new \InvalidArgumentException('Invalid AV output queue bound');
    }
    public function start(float $timeout=10):void { if($this->started || $this->closed) throw new \LogicException('AV output can only start once'); $this->process->start($timeout); $this->started=true; }
    public function write(string $bytes):void
    {
        $this->active();
        if(strlen($this->pending)+strlen($bytes)>$this->maxQueuedBytes) throw new \LengthException('AV output queue is full; poll before writing more');
        $this->pending.=$bytes; $this->poll();
    }
    public function poll():void
    {
        $this->active();
        if(!$this->process->running()) { $error=$this->process->diagnostics(); $this->cancel(); throw new \RuntimeException('AV output process ended: '.$error); }
        for($i=0;$i<4 && $this->pending!=='';$i++) { $written=$this->process->write(substr($this->pending,0,65536)); if($written===0) break; $this->pending=substr($this->pending,$written); $this->written+=$written; }
    }
    public function finish(float $timeout=5):void
    {
        $this->active(); if(!is_finite($timeout) || $timeout<=0 || $timeout>30) throw new \InvalidArgumentException('Invalid AV output finish timeout');
        $deadline=microtime(true)+$timeout;
        try { while($this->pending!=='') { $this->poll(); if(microtime(true)>=$deadline) throw new \RuntimeException('AV output queue did not drain in time'); usleep(1000); } $this->process->finish(max(0.01,$deadline-microtime(true))); }
        finally { $this->cancel(); }
    }
    public function cancel():void { $this->closed=true; $this->pending=''; $this->process->cancel(); }
    /** @return array{queuedBytes:int,writtenBytes:int,closed:bool} */
    public function stats():array { return ['queuedBytes'=>strlen($this->pending),'writtenBytes'=>$this->written,'closed'=>$this->closed]; }
    private function active():void { if(!$this->started || $this->closed) throw new \LogicException('AV output is not started or already closed'); }
    public function __destruct() { $this->cancel(); }
}
