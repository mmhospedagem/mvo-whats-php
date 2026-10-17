<?php
declare(strict_types=1);
namespace MVO\Telemetry;

final class WamBatch
{
    private string $bytes;
    private int $events=0;
    /** @var array<int,int|string|bool|null> */
    private array $globals=[];
    /** @param array<int,int|string|bool|null> $globals */
    public function __construct(public readonly string $channel='regular',int $streamId=1,int $sequenceNumber=1,array $globals=[])
    {
        $code=match($channel) {'regular'=>0,'realtime'=>1,'private'=>2,default=>throw new \InvalidArgumentException('Invalid WAM channel')};
        if($streamId<0 || $streamId>255 || $sequenceNumber<0 || $sequenceNumber>65535) throw new \InvalidArgumentException('Invalid WAM stream/sequence');
        $this->bytes='WAM'.chr(5).chr($streamId).pack('n',$sequenceNumber).chr($code);foreach($globals as $id=>$v) $this->setGlobal($id,$v);
    }
    public function setGlobal(int $id,int|string|bool|null $value): void { if(array_key_exists($id,$this->globals) && $this->globals[$id]===$value) return;$this->append(WamEncoder::global($id,$value));$this->globals[$id]=$value; }
    /** @param list<array{id:int,kind:string,value:int|float|string|bool}> $fields */
    public function writeEvent(int $commitTimeMs,int $eventId,int $weight,array $fields=[]): void
    {
        if($commitTimeMs<0) throw new \InvalidArgumentException('Invalid WAM timestamp');$bytes=WamEncoder::global(47,intdiv($commitTimeMs,1000)).WamEncoder::event($eventId,$weight,$fields!==[]);$seen=[];
        foreach($fields as $i=>$field) { if(isset($seen[$field['id']])) throw new \InvalidArgumentException('Duplicate WAM field');$seen[$field['id']]=true;$bytes.=WamEncoder::field($field['id'],$field['kind'],$field['value'],$i===count($fields)-1); }
        $this->append($bytes);$this->events++;
    }
    private function append(string $bytes): void { if(strlen($this->bytes)+strlen($bytes)>64000) throw new \LengthException('WAM batch exceeds 64000 byte upload limit');$this->bytes.=$bytes; }
    public function toBytes(): string { return $this->bytes; }
    public function size(): int { return strlen($this->bytes); }
    public function hasEvents(): bool { return $this->events>0; }
}
