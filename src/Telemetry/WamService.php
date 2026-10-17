<?php
declare(strict_types=1);
namespace MVO\Telemetry;
use MVO\Protocol\BinaryNode;
use MVO\Service\ServiceTransport;

/** Explicit opt-in WAM. Collecting or flushing disabled telemetry never sends a stanza. */
final class WamService
{
    /** @var \Closure(BinaryNode,float):BinaryNode */
    private \Closure $query;
    /** @var array<string,WamBatch> */
    private array $batches=[];
    /** @var array<string,int> */
    private array $sequences=[];
    private int $streamId;
    /** @param callable(BinaryNode,float):BinaryNode $query
     * @param array<string,int|string|bool|null> $globals */
    public function __construct(callable $query,private bool $enabled=false,private readonly array $globals=[],?int $streamId=null)
    {
        $this->query=\Closure::fromCallable($query);$this->streamId=$streamId ?? random_int(1,255);if($this->streamId<1 || $this->streamId>255) throw new \InvalidArgumentException('Invalid WAM stream id');
    }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): void { $this->enabled=$enabled;if(!$enabled) $this->batches=[]; }
    /** Caller records only actual activity. No synthetic UI or automatic client observers.
     * @param array<string,mixed> $payload */
    public function commit(string $name,array $payload=[],?int $commitTimeMs=null): bool
    {
        if(!$this->enabled) return false;$d=WamRegistry::event($name);return $this->commitById($d['id'],WamRegistry::fields($name,$payload),$d['channel'],$d['weight']['default'] ?? 1,$commitTimeMs);
    }
    /** @param list<array{id:int,kind:string,value:int|float|string|bool}> $fields */
    public function commitById(int $eventId,array $fields=[],string $channel='regular',int $weight=1,?int $commitTimeMs=null): bool
    {
        if(!$this->enabled) return false;$batch=$this->batches[$channel] ?? null;
        if($batch===null) { $sequence=($this->sequences[$channel] ?? 0)%65535+1;$batch=new WamBatch($channel,$this->streamId,$sequence,WamRegistry::globals($this->globals,$channel));$batch->writeEvent($commitTimeMs ?? (int)floor(microtime(true)*1000),$eventId,$weight,$fields);$this->sequences[$channel]=$sequence;$this->batches[$channel]=$batch; }
        else $batch->writeEvent($commitTimeMs ?? (int)floor(microtime(true)*1000),$eventId,$weight,$fields);return true;
    }
    /** Upload is explicit, one IQ per nonempty channel. Rejected batches remain queued.
     * @return array<string,int> Bytes accepted per channel. */
    public function flush(): array
    {
        if(!$this->enabled) return [];$done=[];foreach($this->batches as $channel=>$batch) { if(!$batch->hasEvents()) continue;$bytes=$batch->toBytes();$node=new BinaryNode('iq',['type'=>'set','to'=>'s.whatsapp.net','xmlns'=>'w:stats'],[new BinaryNode('add',['t'=>(string)time()],$bytes)]);$result=($this->query)($node,15.0);ServiceTransport::assertResult($result,'wam.upload');$done[$channel]=strlen($bytes);unset($this->batches[$channel]); }return $done;
    }
    /** @return array<string,int> */
    public function pending(): array { return array_map(static fn(WamBatch $b):int=>$b->size(),$this->batches); }
}
