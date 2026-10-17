<?php
declare(strict_types=1);
namespace MVO\Protocol;

/** Self-describing MEX payloads and GraphQL errors, without an external codec runtime. */
final class ArgoDecoder
{
    private ArgoReader $core;
    /** @var list<ArgoReader> */
    private array $slices=[];
    /** @var array<string,ArgoReader> */
    private array $blocks=[];
    /** @var array<string,list<string>> */
    private array $seen=[];
    private int $flags=0;
    private int $elements=0;
    /** @return array{data:mixed,errors:list<array<string,mixed>>} */
    public static function decode(string $bytes):array {
        if (strlen($bytes)>8388608) throw new \LengthException('Argo payload exceeds 8 MiB');
        return (new self())->read($bytes);
    }
    /** @return array{data:mixed,errors:list<array<string,mixed>>} */
    private function read(string $bytes):array {
        $reader=new ArgoReader($bytes); $this->flags=$reader->bitset();
        if (($this->flags & 64)!==0) $reader->bitset();
        if (($this->flags & 2)===0) throw new \UnexpectedValueException('MEX Argo must be self-describing');
        if (($this->flags & 1)!==0) $this->core=$reader;
        else {
            while ($reader->remaining()>0) { if (count($this->slices)>=5) throw new \LengthException('Too many Argo blocks'); $this->slices[]=new ArgoReader($reader->bytes($reader->count(8388608))); }
            $this->core=array_pop($this->slices) ?? throw new \UnexpectedValueException('Argo core segment missing');
        }
        $data=$this->desc(0); $errors=[];
        if ($this->core->remaining()>0) {
            $count=$this->core->count(10000);
            for ($i=0;$i<$count;$i++) {
                if (($this->flags & 8)!==0) { $error=$this->desc(0); if (!is_array($error)) throw new \UnexpectedValueException('Invalid self-describing Argo error'); $errors[]=$error; continue; }
                $message=$this->string('String');
                $locationCount=$this->optionalCount(); $locations=null;
                if ($locationCount!==null) {
                    $locations=[];
                    for($index=0;$index<$locationCount;$index++) {
                        $line=$this->block('Int')->count(PHP_INT_MAX); $column=$this->block('Int')->count(PHP_INT_MAX);
                        $locations[]=['line'=>$line,'column'=>$column];
                    }
                }
                // MEX represents an error path as ARRAY<BLOCK<Int>>, not a scalar
                // label. Captured paths consume the first two zeroes in the Int
                // block; the following 400 is the second error's error_code.
                $pathCount=$this->optionalCount(); $path=null;
                if ($pathCount!==null) {
                    $path=[];
                    for($index=0;$index<$pathCount;$index++) $path[]=$this->block('Int')->count(PHP_INT_MAX);
                }
                $present=$this->core->label(); $extensions=in_array($present,[-1,-2],true) ? null : $this->desc(0);
                if (!in_array($present,[-1,-2,0],true)) throw new \UnexpectedValueException('Invalid Argo error extension marker');
                if ($extensions!==null && !is_array($extensions)) throw new \UnexpectedValueException('Invalid Argo error extensions');
                $error=['message'=>$message,'path'=>$path,'extensions'=>$extensions];
                if ($locations!==null) $error['location']=$locations;
                $errors[]=$error;
            }
        }
        // Captured MEX responses carry a final optional envelope extension.
        if ($this->core->remaining()>0) {
            $present=$this->core->label();
            if (!in_array($present,[-1,-2],true)) {
                if ($present!==0 || !is_array($this->desc(0))) throw new \UnexpectedValueException('Invalid Argo envelope extensions');
            }
        }
        if ($this->core->remaining()!==0 || $this->slices!==[]) throw new \UnexpectedValueException('Trailing/unreferenced Argo data');
        if (($this->flags & 1)===0) foreach ($this->blocks as $type=>$block) if ($block->remaining()!==0) throw new \UnexpectedValueException('Trailing Argo block data: '.$type);
        return ['data'=>$data,'errors'=>$errors];
    }
    private function optionalCount(): ?int {
        $count=$this->core->label();
        if(in_array($count,[-1,-2],true)) return null;
        if(!is_int($count) || $count<0 || $count>10000) throw new \LengthException('Invalid Argo optional array count');
        return $count;
    }
    private function block(string $type):ArgoReader {
        if (isset($this->blocks[$type])) return $this->blocks[$type];
        $block=($this->flags & 1)!==0 ? $this->core : (array_shift($this->slices) ?? throw new \UnexpectedValueException('Argo block missing: '.$type));
        return $this->blocks[$type]=$block;
    }
    private function string(string $type):string {
        $block=$this->block($type); $length=$this->core->label();
        if (!is_int($length)) throw new \LengthException('Argo string length overflow');
        if ($length<=-4) {
            if (($this->flags & 32)!==0) throw new \UnexpectedValueException('Argo backref when deduplication disabled');
            return $this->seen[$type][-$length-4] ?? throw new \UnexpectedValueException('Invalid Argo backreference');
        }
        if ($length<0) throw new \UnexpectedValueException('Invalid Argo string label');
        $bytes=$block->bytes($length);
        if ($type==='String') {
            if (($this->flags & 16)!==0 && $block->bytes(1)!=="\0") throw new \UnexpectedValueException('Argo string terminator missing');
            if (preg_match('//u',$bytes)!==1) throw new \UnexpectedValueException('Argo string is not UTF-8');
        }
        if (($this->flags & 32)===0) $this->seen[$type][]=$bytes;
        return $bytes;
    }
    private function desc(int $depth):mixed {
        if ($depth>64 || ++$this->elements>100000) throw new \LengthException('Argo structure limit exceeded');
        $marker=$this->core->label();
        if ($marker===-1) return null;
        if ($marker===0) return false;
        if ($marker===1) return true;
        if ($marker===4) return $this->string('String');
        if ($marker===5) return $this->string('Bytes');
        if ($marker===6) return $this->block('Int')->label();
        if ($marker===7) { $value=unpack('e',$this->block('Float')->bytes(8))[1]; if (!is_finite($value)) throw new \UnexpectedValueException('Non-finite Argo float'); return $value; }
        if ($marker!==2 && $marker!==3) throw new \UnexpectedValueException('Unknown Argo DESC marker');
        // DESC_OBJECT allocates the implicit String block even for an empty
        // record. That empty segment still participates in encoder block order.
        if ($marker===2) $this->block('String');
        $count=$this->core->count(); $result=[];
        for ($i=0;$i<$count;$i++) {
            if ($marker===3) $result[]=$this->desc($depth+1);
            else { $key=$this->string('String'); if (array_key_exists($key,$result)) throw new \UnexpectedValueException('Duplicate Argo field'); $result[$key]=$this->desc($depth+1); }
        }
        return $result;
    }
}
