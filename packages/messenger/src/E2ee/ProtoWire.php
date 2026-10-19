<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee;

/** Bounded protobuf wire primitives for the Messenger device protocol only. */
final class ProtoWire
{
    public static function integer(int $field, int $value): string
    {
        if ($field < 1 || $field > 536870911 || $value < 0) throw new \InvalidArgumentException('Invalid protobuf unsigned integer');
        return self::varint($field << 3) . self::varint($value);
    }

    public static function bytes(int $field, string $value): string
    {
        if ($field < 1 || $field > 536870911 || strlen($value) > 1048576) throw new \InvalidArgumentException('Invalid protobuf bytes');
        return self::varint(($field << 3) | 2) . self::varint(strlen($value)) . $value;
    }

    /** @return array<int,list<string|int>> */
    public static function decode(string $bytes): array
    {
        if (strlen($bytes) > 1048576) throw new \UnexpectedValueException('Messenger protobuf exceeds its limit');
        $fields = []; $offset = 0; $count = 0; $length = strlen($bytes);
        while ($offset < $length) {
            if (++$count > 4096) throw new \UnexpectedValueException('Too many Messenger protobuf fields');
            $tag = self::readVarint($bytes, $offset); $field = $tag >> 3; $wire = $tag & 7;
            if ($field < 1 || $field > 536870911) throw new \UnexpectedValueException('Invalid Messenger protobuf field');
            if ($wire === 0) $value = self::readVarint($bytes, $offset);
            elseif ($wire === 2) {
                $size = self::readVarint($bytes, $offset);
                if ($size > $length - $offset) throw new \UnexpectedValueException('Truncated Messenger protobuf');
                $value = substr($bytes, $offset, $size); $offset += $size;
            } elseif ($wire === 1 || $wire === 5) {
                $size = $wire === 1 ? 8 : 4;
                if ($size > $length - $offset) throw new \UnexpectedValueException('Truncated Messenger protobuf');
                $value = substr($bytes, $offset, $size); $offset += $size;
            } else throw new \UnexpectedValueException('Unsupported Messenger protobuf wire type');
            $fields[$field][] = $value;
        }
        return $fields;
    }

    private static function varint(int $value): string
    {
        $result = '';
        do { $byte = $value & 127; $value >>= 7; $result .= chr($byte | ($value > 0 ? 128 : 0)); } while ($value > 0);
        return $result;
    }

    private static function readVarint(string $bytes, int &$offset): int
    {
        $result = 0;
        for ($shift = 0; $shift <= 56; $shift += 7) {
            if ($offset >= strlen($bytes)) throw new \UnexpectedValueException('Truncated Messenger protobuf varint');
            $byte = ord($bytes[$offset++]); $result |= ($byte & 127) << $shift;
            if (($byte & 128) === 0) return $result;
        }
        throw new \UnexpectedValueException('Messenger protobuf integer exceeds signed 64 bits');
    }
}
