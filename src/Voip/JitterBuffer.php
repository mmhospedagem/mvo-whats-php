<?php
declare(strict_types=1);
namespace MVO\Voip;

/** Bounded PCM playout ring. Overflow drops oldest samples; underruns produce silence. */
final class JitterBuffer
{
    /** @var list<float> */
    private array $ring;
    private int $writePosition=0;
    private int $readPosition=0;
    private int $length=0;
    private int $dropped=0;
    private int $underruns=0;
    public function __construct(private readonly int $capacity)
    {
        if($capacity<=0 || $capacity>1048576) throw new \InvalidArgumentException('Jitter capacity must be 1..1048576 samples');
        $this->ring=array_fill(0,$capacity,0.0);
    }
    /** @param list<float|int> $samples */
    public function write(array $samples):void
    {
        if(count($samples)>$this->capacity) { $this->dropped+=count($samples)-$this->capacity; $samples=array_slice($samples,-$this->capacity); }
        foreach($samples as $sample) if(!is_finite((float)$sample)) throw new \InvalidArgumentException('PCM samples must be finite');
        $overflow=$this->length+count($samples)-$this->capacity;
        if($overflow>0) { $this->readPosition=($this->readPosition+$overflow)%$this->capacity; $this->length-=$overflow; $this->dropped+=$overflow; }
        foreach($samples as $sample) { $this->ring[$this->writePosition]=(float)$sample; $this->writePosition=($this->writePosition+1)%$this->capacity; }
        $this->length+=count($samples);
    }
    /** @return array{samples:list<float>,real:int} */
    public function read(int $wanted):array
    {
        if($wanted<0 || $wanted>1048576) throw new \InvalidArgumentException('Invalid playout block size');
        $real=min($wanted,$this->length); $samples=array_fill(0,$wanted,0.0);
        for($i=0;$i<$real;$i++) { $samples[$i]=$this->ring[$this->readPosition]; $this->readPosition=($this->readPosition+1)%$this->capacity; }
        $this->length-=$real; if($real<$wanted) ++$this->underruns;
        return ['samples'=>$samples,'real'=>$real];
    }
    public function buffered():int { return $this->length; }
    /** @return array{buffered:int,capacity:int,dropped:int,underruns:int} */
    public function stats():array { return ['buffered'=>$this->length,'capacity'=>$this->capacity,'dropped'=>$this->dropped,'underruns'=>$this->underruns]; }
    public function reset():void { $this->writePosition=0; $this->readPosition=0; $this->length=0; }
}
