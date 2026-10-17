<?php
declare(strict_types=1);
namespace MVO\Store;

/** Bounded session snapshots make Signal ratchets and outbox commit in one remote atomic write. */
abstract class RemoteSnapshotStore implements KeyValueStore
{
    /** @var array<string,string>|null Encoded namespace/key => binary value. */
    private ?array $active=null;
    private bool $closed=false;
    private bool $poisoned=false;
    protected function __construct(protected readonly string $sessionId,protected readonly int $maxBytes)
    {
        if($sessionId==='' || $maxBytes<1024 || $maxBytes>536870912) throw new \InvalidArgumentException('Invalid session/snapshot limit');
    }
    /** @return array{version:string,data:array<string,string>} */
    abstract protected function readSnapshot(): array;
    abstract protected function commitSnapshot(string $expectedVersion,string $nextVersion,string $payload): bool;
    abstract protected function disconnect(): void;
    public function get(string $namespace,string $key): ?string { $this->available();$data=$this->active ?? $this->readSnapshot()['data'];return $data[self::key($namespace,$key)] ?? null; }
    public function set(string $namespace,string $key,string $value): void
    {
        $this->available();if(strlen($value)>$this->maxBytes) throw new \LengthException('Value exceeds snapshot byte limit');$field=self::key($namespace,$key);
        if($this->active!==null) { $this->active[$field]=$value;return; }$this->transaction(function()use($field,$value):void { $this->active[$field]=$value; });
    }
    public function delete(string $namespace,string $key): void
    {
        $this->available();$field=self::key($namespace,$key);if($this->active!==null) { unset($this->active[$field]);return; }$this->transaction(function()use($field):void { unset($this->active[$field]); });
    }
    /** @return array<string,string> */
    public function entries(string $namespace,int $limit=1000,string $after=''): array
    {
        $this->available();if($limit<1 || $limit>10000) throw new \InvalidArgumentException('Store page limit must be between 1 and 10000');$data=$this->active ?? $this->readSnapshot()['data'];$prefix=base64_encode($namespace).':';$out=[];
        foreach($data as $field=>$value) if(str_starts_with($field,$prefix)) { $key=base64_decode(substr($field,strlen($prefix)),true);if($key===false) throw new \UnexpectedValueException('Invalid snapshot key');if(strcmp($key,$after)>0) $out[$key]=$value; }
        uksort($out,static fn(string|int $a,string|int $b):int=>strcmp((string)$a,(string)$b));return array_slice($out,0,$limit,true);
    }
    /** @template T
     * @param callable(static):T $operation
     * @return T */
    public function transaction(callable $operation): mixed
    {
        $this->available();if($this->active!==null) throw new \LogicException('Nested store transaction');$snapshot=$this->readSnapshot();$this->active=$snapshot['data'];
        try {
            $result=$operation($this);$payload=$this->encode($this->active);
            if($this->active!==$snapshot['data']) {
                $next=bin2hex(random_bytes(16));
                try { $committed=$this->commitSnapshot($snapshot['version'],$next,$payload); }
                catch(RemoteCommitUnknown $e) { $this->poisoned=true;throw $e; }
                if(!$committed) throw new RemoteStoreConflict('Concurrent remote store transaction; no changes committed by this operation');
            }
            return $result;
        } finally { $this->active=null; }
    }
    public function close(): void { if($this->closed) return;if($this->active!==null) throw new \LogicException('Cannot close during transaction');$this->disconnect();$this->closed=true; }
    private function available(): void { if($this->closed) throw new \LogicException('Remote store is closed');if($this->poisoned) throw new RemoteCommitUnknown('Previous commit acknowledgement was lost; reopen and reconcile outbox before continuing'); }
    private static function key(string $namespace,string $key): string { return base64_encode($namespace).':'.base64_encode($key); }
    /** @param array<string,string> $data */
    private function encode(array $data): string
    {
        $encoded=[];foreach($data as $key=>$value) $encoded[$key]=base64_encode($value);$json=json_encode($encoded===[] ? new \stdClass() : $encoded,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);if(strlen($json)>$this->maxBytes) throw new \LengthException('Session snapshot exceeds configured byte limit');return $json;
    }
    /** @return array<string,string> */
    protected function decode(string $payload): array
    {
        if(strlen($payload)>$this->maxBytes) throw new \LengthException('Remote session snapshot exceeds configured byte limit');$json=json_decode($payload,true,512,JSON_THROW_ON_ERROR);if(!is_array($json)) throw new \UnexpectedValueException('Invalid remote snapshot');$out=[];
        foreach($json as $field=>$value) { if(!is_string($field) || !str_contains($field,':') || !is_string($value)) throw new \UnexpectedValueException('Invalid remote snapshot field');$bytes=base64_decode($value,true);if($bytes===false) throw new \UnexpectedValueException('Invalid remote snapshot bytes');$out[$field]=$bytes; }return $out;
    }
}
