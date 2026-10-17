<?php
declare(strict_types=1);
namespace MVO\Protocol;

/** Argo 1.2 signed labels and variable-length bitsets. */
final class ArgoReader
{
    private int $position=0;
    public function __construct(private readonly string $data) {}
    public function remaining():int { return strlen($this->data)-$this->position; }
    public function bytes(int $length):string {
        if ($length<0 || $length>$this->remaining()) throw new \UnexpectedValueException('Truncated Argo segment');
        $result=substr($this->data,$this->position,$length); $this->position+=$length; return $result;
    }
    public function label():int|string {
        $raw=gmp_init(0);
        for ($i=0;$i<10;$i++) {
            $byte=ord($this->bytes(1));
            if ($i===9 && $byte>1) throw new \UnexpectedValueException('Argo label overflows 64 bits');
            $raw=gmp_add($raw,gmp_mul($byte & 127,gmp_pow(2,$i*7)));
            if (($byte & 128)===0) {
                $value=gmp_div_q($raw,2);
                if (gmp_testbit($raw,0)) $value=gmp_sub(gmp_neg($value),1);
                return gmp_cmp($value,PHP_INT_MAX)>0 || gmp_cmp($value,PHP_INT_MIN)<0 ? gmp_strval($value) : gmp_intval($value);
            }
        }
        throw new \UnexpectedValueException('Unterminated Argo label');
    }
    public function count(int $maximum=100000):int {
        $value=$this->label();
        if (!is_int($value) || $value<0 || $value>$maximum) throw new \LengthException('Invalid Argo element count'); return $value;
    }
    public function bitset():int {
        $result=0;
        for ($i=0;$i<9;$i++) {
            $byte=ord($this->bytes(1)); $result |= ($byte >> 1) << ($i*7);
            if (($byte & 1)===0) return $result;
        }
        throw new \LengthException('Argo flags overflow');
    }
}
