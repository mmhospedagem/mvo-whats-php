<?php
declare(strict_types=1);
namespace MVO\Telemetry;

/** WAM v5 TLV encoder. All multi-byte scalars are network byte order. */
final class WamEncoder
{
    public static function global(int $id,int|string|bool|null $value): string { return $value===null ? self::tag($id,0) : (is_string($value) ? self::text($id,0,$value) : self::integer($id,0,is_bool($value) ? (int)$value : $value)); }
    public static function event(int $id,int $weight,bool $hasFields): string { if($weight<1) throw new \InvalidArgumentException('WAM weight must be positive');return self::integer($id,$hasFields ? 1 : 5,$weight); }
    public static function field(int $id,string $kind,int|float|string|bool $value,bool $last): string
    {
        $marker=$last ? 6 : 2;
        return match($kind) {
            'int'=>is_int($value) ? self::integer($id,$marker,$value) : throw new \InvalidArgumentException('WAM integer required'),
            'bool'=>is_bool($value) ? self::integer($id,$marker,(int)$value) : throw new \InvalidArgumentException('WAM boolean required'),
            'string'=>is_string($value) ? self::text($id,$marker,$value) : throw new \InvalidArgumentException('WAM text required'),
            'float'=>(is_int($value) || is_float($value)) && is_finite((float)$value) ? self::tag($id,112|$marker).pack('E',(float)$value) : throw new \InvalidArgumentException('Finite WAM number required'),
            default=>throw new \InvalidArgumentException('Unknown WAM kind'),
        };
    }
    private static function tag(int $id,int $marker): string { if($id<0 || $id>65535) throw new \InvalidArgumentException('WAM id must fit uint16');return $id<256 ? chr($marker).chr($id) : chr($marker|8).pack('n',$id); }
    private static function integer(int $id,int $marker,int $value): string
    {
        return match(true) {
            $value===0=>self::tag($id,16|$marker),$value===1=>self::tag($id,32|$marker),
            $value>=-128 && $value<128=>self::tag($id,48|$marker).pack('c',$value),
            $value>=-32768 && $value<32768=>self::tag($id,64|$marker).pack('n',$value&65535),
            $value>=-2147483648 && $value<2147483648=>self::tag($id,80|$marker).pack('N',$value&0xffffffff),
            default=>self::tag($id,96|$marker).pack('N2',($value>>32)&0xffffffff,$value&0xffffffff),
        };
    }
    private static function text(int $id,int $marker,string $text): string
    {
        if(preg_match('//u',$text)!==1) throw new \InvalidArgumentException('WAM text must be UTF-8');$size=strlen($text);if($size>1048576) throw new \LengthException('WAM scalar exceeds one MiB');
        return ($size<256 ? self::tag($id,128|$marker).chr($size) : ($size<65536 ? self::tag($id,144|$marker).pack('n',$size) : self::tag($id,160|$marker).pack('N',$size))).$text;
    }
}
