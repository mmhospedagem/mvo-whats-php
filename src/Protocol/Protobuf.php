<?php
declare(strict_types=1);
namespace MVO\Protocol;
/** Schema-independent wire codec. Decoded fields preserve repetition and wire type. */
final class Protobuf
{
    public static function varint(int $value): string {
        if ($value < 0) throw new \InvalidArgumentException('Unsigned integers only; maximum PHP_INT_MAX');
        $out = '';
        do { $b = $value & 127; $value >>= 7; $out .= chr($b | ($value ? 128 : 0)); } while ($value);
        return $out;
    }
    private static function readVarint(string $bytes, int &$offset): int {
        $value = 0;
        for ($shift=0;$shift<=56;$shift+=7) {
            if (!isset($bytes[$offset])) throw new \UnexpectedValueException('Truncated varint');
            $b = ord($bytes[$offset++]); $value |= ($b & 127)<<$shift;
            if (!($b & 128)) return $value;
        }
        throw new \OverflowException('Varint exceeds supported signed 63-bit range');
    }
    /** @param list<array{number:int,wire:int,value:int|string}> $fields */
    public static function encode(array $fields): string {
        $out = '';
        foreach ($fields as $field) {
            ['number'=>$n,'wire'=>$wire,'value'=>$v] = $field;
            if ($n < 1 || $n > 536870911) throw new \InvalidArgumentException('Invalid field number');
            $out .= self::varint(($n<<3)|$wire);
            $out .= match($wire) {
                0=>is_int($v) ? self::varint($v) : throw new \InvalidArgumentException('Expected integer'),
                2=>is_string($v) ? self::varint(strlen($v)).$v : throw new \InvalidArgumentException('Expected bytes'),
                1,5=>self::fixed($v,$wire === 1 ? 8 : 4),
                default=>throw new \InvalidArgumentException('Unsupported wire type')
            };
        }
        return $out;
    }
    private static function fixed(int|string $bytes, int $length): string {
        if (!is_string($bytes) || strlen($bytes) !== $length) throw new \InvalidArgumentException('Fixed fields require raw little-endian bytes');
        return $bytes;
    }
    /** @return list<array{number:int,wire:int,value:int|string}> */
    public static function decode(string $bytes, int $maxBytes = 16777215, int $maxFields = 100000): array {
        if (strlen($bytes)>$maxBytes || $maxFields < 1) throw new \LengthException('Protobuf limit exceeded');
        $out = []; $offset = 0; $total = strlen($bytes);
        while ($offset<$total) {
            if (count($out)>=$maxFields) throw new \LengthException('Too many fields');
            $tag = self::readVarint($bytes,$offset); $n = $tag>>3; $wire = $tag & 7;
            if ($n<1 || $n>536870911) throw new \UnexpectedValueException('Invalid field number');
            if ($wire === 0) $v = self::readVarint($bytes,$offset);
            else {
                $length = match($wire) {1=>8,5=>4,2=>self::readVarint($bytes,$offset),default=>throw new \UnexpectedValueException('Unsupported wire type')};
                if ($length>$total-$offset) throw new \UnexpectedValueException('Truncated protobuf field');
                $v = substr($bytes,$offset,$length); $offset += $length;
            }
            $out[] = ['number'=>$n,'wire'=>$wire,'value'=>$v];
        }
        return $out;
    }
}
