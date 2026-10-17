<?php
declare(strict_types=1);
namespace MVO\Voip;

/** Incremental Annex-B framing when the encoder emits Access Unit Delimiters (NAL type 9). */
final class AnnexBAccessUnitBuffer
{
    private string $buffer='';
    private int $scan=0;
    private bool $aud=false;
    public function __construct(private readonly int $maximum=8388608) { if($maximum<16 || $maximum>67108864) throw new \InvalidArgumentException('Invalid access unit bound'); }
    /** @return list<string> */
    public function push(string $bytes):array
    {
        $this->buffer.=$bytes; if(strlen($this->buffer)>$this->maximum) { $this->reset(); throw new \LengthException('H264 capture access unit exceeds bound'); }
        $frames=[];
        while(($at=strpos($this->buffer,"\0\0\1",$this->scan))!==false) {
            if($at+3>=strlen($this->buffer)) { $this->scan=$at; return $frames; }
            $start=$at>0 && $this->buffer[$at-1]==="\0"?$at-1:$at;
            if((ord($this->buffer[$at+3])&31)===9) {
                if($this->aud && $start>0) { $frame=substr($this->buffer,0,$start); if(self::hasPicture($frame)) $frames[]=$frame; $this->buffer=substr($this->buffer,$start); $at-=$start; }
                $this->aud=true;
            }
            $this->scan=$at+4;
        }
        $this->scan=max(0,strlen($this->buffer)-3); return $frames;
    }
    /** Natural EOF only; cancelled/truncated captures must reset instead.
     * @return list<string>
     */
    public function finish():array { $result=$this->aud && self::hasPicture($this->buffer)?[$this->buffer]:[]; $this->reset(); return $result; }
    public function reset():void { $this->buffer=''; $this->scan=0; $this->aud=false; }
    private static function hasPicture(string $frame):bool { foreach(H264::nals($frame) as $nal) if(in_array(ord($nal['data'][0])&31,[1,5],true)) return true; return false; }
}
