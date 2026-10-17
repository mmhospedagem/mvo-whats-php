<?php
declare(strict_types=1);
namespace MVO\Service;
use MVO\Protocol\BinaryNode;

/** Offline stanza windows. tick() runs from the client's poll loop; no threads or timers required. */
final class OfflineResumeService
{
    /** @var \Closure(BinaryNode):void */
    private \Closure $send;
    /** @var \Closure(array<string,mixed>):void */
    private \Closure $emit;
    /** @var \Closure():float */
    private \Closure $clock;
    private string $state='init';
    private int $total=0;
    private int $pending=0;
    private bool $batchInFlight=false;
    private int $retries=0;
    private ?float $nextBatch=null;
    private ?float $deadline=null;
    private float $lastRequest=-INF;
    /** @param callable(BinaryNode):void $send
     * @param callable(array<string,mixed>):void $emit
     * @param callable():float|null $clock */
    public function __construct(callable $send,callable $emit,?callable $clock=null)
    {
        $this->send=\Closure::fromCallable($send);$this->emit=\Closure::fromCallable($emit);$this->clock=$clock === null ? static fn():float=>hrtime(true)/1e9 : \Closure::fromCallable($clock);
    }
    public function isComplete(): bool { return $this->state==='complete'; }
    public function isResuming(): bool { return $this->state==='resuming'; }
    public function reset(): void { $this->state='init';$this->total=$this->pending=$this->retries=0;$this->nextBatch=$this->deadline=null;$this->batchInFlight=false;$this->lastRequest=-INF; }
    public function handleNode(BinaryNode $node): void
    {
        if(isset($node->attrs['offline'])) $this->trackOfflineStanza();
        if($node->tag!=='ib') return;
        foreach(ServiceTransport::children($node) as $child) {
            $count=ctype_digit($child->attrs['count'] ?? '') ? (int)$child->attrs['count'] : 0;
            if($child->tag==='offline_preview') $this->handleOfflinePreview($count);
            elseif($child->tag==='offline') $this->handleOfflineComplete($count);
        }
    }
    public function handleOfflinePreview(int $count): void
    {
        if($count<0) throw new \InvalidArgumentException('Negative offline count');$this->reset();$this->state='resuming';$this->total=$this->pending=$count;$this->deadline=($this->clock)()+60.0;$this->nextBatch=($this->clock)();$this->emit(false);$this->tick();
    }
    public function handleOfflineComplete(int $serverCount=0): void { if($this->isResuming()) $this->complete(false,$serverCount); }
    public function trackOfflineStanza(): void
    {
        if(!$this->isResuming()) return;$now=($this->clock)();$this->pending=max(0,$this->pending-1);$this->batchInFlight=false;$this->deadline=$now+60.0;
        $this->nextBatch=max($now,$this->lastRequest+0.1);
    }
    public function tick(): void
    {
        if(!$this->isResuming()) return;$now=($this->clock)();
        if($this->deadline!==null && $now >= $this->deadline) { $this->complete(true);return; }
        if($this->batchInFlight || $this->nextBatch===null || $now<$this->nextBatch) return;
        $this->batchInFlight=true;$this->nextBatch=null;$this->lastRequest=$now;
        try { ($this->send)(new BinaryNode('ib',[],[new BinaryNode('offline_batch',['count'=>'200'])]));$this->retries=0; }
        catch(\Throwable $error) {
            $this->batchInFlight=false;$this->retries++;
            if($this->retries<=3) $this->nextBatch=$now+0.1;
            ($this->emit)(['status'=>'batch_error','totalStanzas'=>$this->total,'remainingStanzas'=>$this->pending,'attempt'=>$this->retries,'willRetry'=>$this->retries<=3,'message'=>$error->getMessage()]);
        }
    }
    private function complete(bool $forced,?int $serverCount=null): void { $this->state='complete';$this->batchInFlight=false;$this->nextBatch=$this->deadline=null;$this->emit($forced,$serverCount); }
    private function emit(bool $forced,?int $serverCount=null): void { $event=['status'=>$this->state,'totalStanzas'=>$this->total,'remainingStanzas'=>$this->pending,'forced'=>$forced];if($serverCount!==null) $event['serverStanzaCount']=$serverCount;($this->emit)($event); }
}
