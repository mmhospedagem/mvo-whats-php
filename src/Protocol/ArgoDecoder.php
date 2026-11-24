<?php
declare(strict_types=1);
namespace MVO\Protocol;

/** Decodes WhatsApp Argo results with or without an operation wire type. */
final class ArgoDecoder
{
    private const INLINE=0x01;
    private const SELF_DESCRIBING=0x02;
    private const OUT_OF_BAND_ERRORS=0x04;
    private const SELF_DESCRIBING_ERRORS=0x08;
    private const TERMINATED_STRINGS=0x10;
    private const NO_DEDUPLICATION=0x20;
    private const USER_FLAGS=0x40;
    private const CONTINUATION=0x80;
    private const MAX_BYTES=8388608;

    private ArgoReader $core;
    /** @var list<ArgoReader> */
    private array $slices=[];
    private int $nextSlice=0;
    /** @var array<string,ArgoReader> */
    private array $blocks=[];
    /** @var array<string,list<string>> */
    private array $seen=[];
    private int $flags;
    private int $elements=0;
    private bool $legacy;
    /** @var array<string,mixed>|null */
    private ?array $pathRoot=null;
    /** @var list<array<string,mixed>> */
    private array $errors=[];

    /**
     * @param array<string,mixed>|null $wire Response wire type for schema-driven results.
     * @return array{data:mixed,errors:list<array<string,mixed>>}
     */
    public static function decode(string $bytes,?array $wire=null):array
    {
        if (strlen($bytes)>self::MAX_BYTES) throw new \LengthException('Argo payload exceeds 8 MiB');
        $header=ord($bytes[0] ?? throw new \UnexpectedValueException('Missing Argo header'));

        if (($header & self::SELF_DESCRIBING)!==0) {
            try {
                return (new self())->read($bytes,$wire,false);
            } catch (\UnexpectedValueException|\LengthException $error) {
                // Older self-describing payloads use the shifted BitSet flags.
                if (($header & 0x04)===0) throw $error;
                return (new self())->read($bytes,null,true);
            }
        }
        if ($wire!==null) return (new self())->read($bytes,$wire,false);
        if (($header & 0x04)!==0) return (new self())->read($bytes,null,true);
        throw new \UnexpectedValueException('Argo response requires an operation wire type');
    }

    /** @param array<string,mixed>|null $wire
     * @return array{data:mixed,errors:list<array<string,mixed>>}
     */
    private function read(string $bytes,?array $wire,bool $legacy):array
    {
        $reader=new ArgoReader($bytes);
        $this->legacy=$legacy;
        if ($legacy) {
            $this->flags=$reader->bitset();
            if (($this->flags & self::SELF_DESCRIBING)===0) throw new \UnexpectedValueException('Expected legacy self-describing Argo');
        } else {
            $this->flags=ord($reader->bytes(1));
            if (($this->flags & self::CONTINUATION)!==0) throw new \UnexpectedValueException('Unsupported Argo header continuation');
        }
        if (($this->flags & self::USER_FLAGS)!==0) $reader->bitset();
        $this->core=$reader;
        if (($this->flags & self::INLINE)===0) {
            while ($reader->remaining()>0) {
                if (count($this->slices)>=128) throw new \LengthException('Too many Argo blocks');
                $length=$reader->label();
                if (!is_int($length)) throw new \UnexpectedValueException('Invalid Argo segment length');
                $this->slices[]=new ArgoReader($reader->bytes($length));
            }
            $this->core=array_pop($this->slices) ?? throw new \UnexpectedValueException('Argo core segment missing');
        }

        if ($legacy) {
            $data=$this->readLegacyEnvelope();
        } else {
            $this->pathRoot=$wire['fields']['data'] ?? null;
            $value=($this->flags & self::SELF_DESCRIBING)!==0
                ? $this->desc(0)
                : $this->wireValue($wire ?? throw new \UnexpectedValueException('Argo response requires an operation wire type'),0);
            $data=is_array($value) && array_key_exists('data',$value) ? $value['data'] : $value;
            $errors=$value['errors'] ?? [];
            if (!is_array($errors)) throw new \UnexpectedValueException('Invalid Argo errors field');
            foreach ($errors as $error) {
                if (!is_array($error)) throw new \UnexpectedValueException('Invalid Argo error');
                $this->errors[]=$error;
            }
        }
        if ($this->core->remaining()!==0 || $this->nextSlice!==count($this->slices)) {
            throw new \UnexpectedValueException('Trailing/unreferenced Argo data');
        }
        foreach ($this->blocks as $key=>$block) {
            if ($block->remaining()!==0) throw new \UnexpectedValueException('Trailing Argo block data: '.$key);
        }
        return ['data'=>$data,'errors'=>$this->errors];
    }

    private function readLegacyEnvelope():mixed
    {
        $data=$this->desc(0);
        if ($this->core->remaining()>0) {
            $count=$this->core->count(10000);
            for ($i=0;$i<$count;$i++) $this->errors[]=$this->readError(0);
        }
        if ($this->core->remaining()>0) {
            $marker=$this->core->label();
            if (!in_array($marker,[-1,-2],true)) {
                if ($marker!==0) throw new \UnexpectedValueException('Invalid Argo envelope extension marker');
                $this->descObject(0);
            }
        }
        return $data;
    }

    /** @param array<string,mixed> $wire */
    private function wireValue(array $wire,int $depth,?string $key=null,bool $dedupe=false):mixed
    {
        $this->checkLimit($depth);
        $type=$wire['type'] ?? null;
        switch ($type) {
            case 'BLOCK':
                $inner=$wire['of'];
                $blockKey=$wire['key'];
                if ($inner['type']!=='BOOLEAN') $this->block($blockKey);
                return $this->wireValue($inner,$depth+1,$blockKey,$wire['dedupe']);
            case 'STRING':
                return $this->string($key ?? 'String',true,$key===null || $dedupe);
            case 'BYTES':
                return $this->string($key ?? 'Bytes',false,$key===null || $dedupe);
            case 'BOOLEAN':
                return match ($this->core->label()) {
                    0=>false, 1=>true,
                    default=>throw new \UnexpectedValueException('Invalid Argo boolean label'),
                };
            case 'VARINT':
                return ($key===null ? $this->core : $this->block($key))->label();
            case 'FLOAT64':
                return $this->float($key===null ? $this->core : $this->block($key));
            case 'FIXED':
                return ($key===null ? $this->core : $this->block($key))->bytes($wire['length']);
            case 'DESC':
                return $this->desc($depth+1);
            case 'EXTENSIONS':
                return $this->descObject($depth+1);
            case 'PATH':
                return $this->readPath($this->core->count(10000));
            case 'ERROR':
                return $this->readError($depth+1);
            case 'NULLABLE':
                $marker=$this->presence($wire['of'],[-1,-3]);
                if ($marker===-3 && ($this->flags & self::OUT_OF_BAND_ERRORS)===0) {
                    $count=$this->core->count(10000);
                    for ($i=0;$i<$count;$i++) $this->errors[]=$this->readError($depth+1);
                }
                return $marker===0 ? $this->wireValue($wire['of'],$depth+1,$key,$dedupe) : null;
            case 'ARRAY':
                $count=$this->core->count();
                $items=[];
                for ($i=0;$i<$count;$i++) $items[]=$this->wireValue($wire['of'],$depth+1,$key,$dedupe);
                return $items;
            case 'RECORD':
                $fields=[];
                foreach ($wire['fields'] as $name=>$field) {
                    if (($field['optional'] ?? false) && $this->presence($field,[-2])===-2) continue;
                    $fields[$name]=$this->wireValue($field,$depth+1);
                }
                return $fields;
            default:
                throw new \UnexpectedValueException('Unknown Argo wire type');
        }
    }

    /** @param array<string,mixed> $wire
     * @param list<int> $missing
     */
    private function presence(array $wire,array $missing):int
    {
        while ($wire['type']==='BLOCK') $wire=$wire['of'];
        if (in_array($wire['type'],['STRING','BYTES','BOOLEAN','ARRAY','NULLABLE'],true)) {
            $marker=$this->core->peekLabel();
            if (!in_array($marker,$missing,true)) return 0;
            $this->core->label();
            return (int)$marker;
        }
        $marker=$this->core->label();
        if ($marker===0 || in_array($marker,$missing,true)) return (int)$marker;
        throw new \UnexpectedValueException('Invalid Argo presence marker');
    }

    /** @return array<string,mixed> */
    private function readError(int $depth):array
    {
        $this->checkLimit($depth);
        if (($this->flags & self::SELF_DESCRIBING_ERRORS)!==0) return $this->descObject($depth+1);
        $message=$this->string('String');
        $locationCount=$this->optionalCount();
        $locations=null;
        if ($locationCount!==null) {
            $locations=[];
            if (!$this->legacy || $locationCount>0) {
                $ints=$this->block('Int');
                for ($i=0;$i<$locationCount;$i++) {
                    $locations[]=['line'=>$ints->count(PHP_INT_MAX),'column'=>$ints->count(PHP_INT_MAX)];
                }
            }
        }
        $pathCount=$this->optionalCount();
        $path=$pathCount===null ? null : $this->readPath($pathCount);
        $marker=$this->core->label();
        $absent=$this->legacy ? [-1,-2] : [-2];
        if ($marker!==0 && !in_array($marker,$absent,true)) throw new \UnexpectedValueException('Invalid Argo error extension marker');
        $extensions=$marker===0 ? $this->descObject($depth+1) : null;
        $error=['message'=>$message,'path'=>$path,'extensions'=>$extensions];
        if (!$this->legacy || $locations!==null) $error[$this->legacy ? 'location' : 'locations']=$locations;
        return $error;
    }

    /** @return list<string|int> */
    private function readPath(int $count):array
    {
        $path=[];
        if ($count===0 && $this->legacy) return $path;
        $ints=$this->block('Int');
        $wire=$this->pathRoot;
        for ($i=0;$i<$count;$i++) {
            $index=$ints->count(PHP_INT_MAX);
            while (in_array($wire['type'] ?? null,['BLOCK','NULLABLE'],true)) $wire=$wire['of'];
            if (($wire['type'] ?? null)==='ARRAY') {
                $path[]=$index;
                $wire=$wire['of'];
                continue;
            }
            $name=($wire['type'] ?? null)==='RECORD' ? (array_keys($wire['fields'])[$index] ?? null) : null;
            $path[]=$name ?? $index;
            $wire=$name===null ? null : $wire['fields'][$name];
        }
        return $path;
    }

    private function optionalCount():?int
    {
        $count=$this->core->label();
        if ($count===-2 || ($this->legacy && $count===-1)) return null;
        if (!is_int($count) || $count<0 || $count>10000) throw new \LengthException('Invalid Argo optional array count');
        return $count;
    }

    private function block(string $key):ArgoReader
    {
        if (($this->flags & self::INLINE)!==0) return $this->core;
        if (isset($this->blocks[$key])) return $this->blocks[$key];
        return $this->blocks[$key]=$this->slices[$this->nextSlice++]
            ?? throw new \UnexpectedValueException('Argo block missing: '.$key);
    }

    private function string(string $key,bool $text=true,bool $dedupe=true):string
    {
        $block=$this->block($key);
        $length=$this->core->label();
        $dedupe=$dedupe && ($this->flags & self::NO_DEDUPLICATION)===0;
        if (!is_int($length)) throw new \LengthException('Argo string length overflow');
        if ($length<=-4 && $dedupe) {
            return $this->seen[$key][-($length+4)] ?? throw new \UnexpectedValueException('Invalid Argo backreference');
        }
        $bytes=$block->bytes($length);
        if ($text) {
            if (($this->flags & self::TERMINATED_STRINGS)!==0 && $block->bytes(1)!=="\0") {
                throw new \UnexpectedValueException('Argo string terminator missing');
            }
            if (preg_match('//u',$bytes)!==1) throw new \UnexpectedValueException('Argo string is not UTF-8');
        }
        if ($dedupe) $this->seen[$key][]=$bytes;
        return $bytes;
    }

    private function float(ArgoReader $reader):float
    {
        $value=unpack('e',$reader->bytes(8))[1];
        if (!is_finite($value)) throw new \UnexpectedValueException('Non-finite Argo float');
        return $value;
    }

    /** @return array<string,mixed> */
    private function descObject(int $depth):array
    {
        if ($this->core->peekLabel()!==2) throw new \UnexpectedValueException('Expected Argo DESC object');
        return $this->desc($depth);
    }

    private function desc(int $depth):mixed
    {
        $this->checkLimit($depth);
        $marker=$this->core->label();
        if ($marker===-1) return null;
        if ($marker===0) return false;
        if ($marker===1) return true;
        if ($marker===4) return $this->string('String');
        if ($marker===5) return $this->string('Bytes',false);
        if ($marker===6) return $this->block('Int')->label();
        if ($marker===7) return $this->float($this->block('Float'));
        if ($marker!==2 && $marker!==3) throw new \UnexpectedValueException('Unknown Argo DESC marker');
        if ($marker===2) $this->block('String');
        $count=$this->core->count();
        $result=[];
        for ($i=0;$i<$count;$i++) {
            if ($marker===3) {
                $result[]=$this->desc($depth+1);
            } else {
                $key=$this->string('String');
                if (array_key_exists($key,$result)) throw new \UnexpectedValueException('Duplicate Argo field');
                $result[$key]=$this->desc($depth+1);
            }
        }
        return $result;
    }

    private function checkLimit(int $depth):void
    {
        if ($depth>64 || ++$this->elements>100000) throw new \LengthException('Argo structure limit exceeded');
    }
}
