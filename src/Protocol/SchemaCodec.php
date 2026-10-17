<?php
declare(strict_types=1);
namespace MVO\Protocol;

/** Complete WAProto schema codec. Bytes use native binary strings; large integers use decimal strings. */
final class SchemaCodec
{
    /** @var array<string,mixed>|null */
    private static ?array $schema = null;

    /** @return array<string,mixed> */
    private static function schema(): array
    {
        if (self::$schema === null) {
            $json = file_get_contents(dirname(__DIR__, 2).'/resources/WAProto.schema.json');
            if ($json === false) throw new \RuntimeException('Missing WAProto schema descriptor');
            self::$schema = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        }
        return self::$schema;
    }

    /** @return array<string,array{id:int,type:string,repeated:bool,packed:bool,mapKey?:string,oneof?:string}> */
    public static function fields(string $type = 'Message'): array
    {
        return self::schema()['messages'][$type] ?? throw new \InvalidArgumentException('Unknown WAProto message '.$type);
    }

    /** @return list<string> */
    public static function messageTypes(): array { return array_keys(self::schema()['messages']); }

    /** @param array<string,mixed> $data */
    public static function encode(string $type, array $data, int $depth = 0): string
    {
        self::depth($depth);
        $fields = self::fields($type);
        $oneofs = [];
        foreach ($data as $name => $value) {
            $group = $fields[$name]['oneof'] ?? null;
            if ($value === null || $group === null) continue;
            if (isset($oneofs[$group])) throw new \InvalidArgumentException('Multiple values for WAProto oneof '.$type.'.'.$group);
            $oneofs[$group] = $name;
        }
        uksort($data, static fn(string $a, string $b):int => ($fields[$a]['id'] ?? PHP_INT_MAX) <=> ($fields[$b]['id'] ?? PHP_INT_MAX));
        $out = '';
        foreach ($data as $name => $value) {
            if ($value === null) continue;
            if ($name === '_unknown') {
                if (!is_array($value)) throw new \InvalidArgumentException('_unknown must contain raw wire fields');
                foreach ($value as $raw) {
                    if (!is_string($raw) || count(self::scan($raw)) !== 1) throw new \InvalidArgumentException('Invalid unknown wire field');
                    $out .= $raw;
                }
                continue;
            }
            $field = $fields[$name] ?? throw new \InvalidArgumentException('Unknown WAProto field '.$type.'.'.$name);
            if (isset($field['mapKey'])) {
                if (!is_array($value)) throw new \InvalidArgumentException('Map field must be an array');
                foreach ($value as $key => $mapValue) {
                    $entry = self::wireField(1, $field['mapKey'], (string)$key, $depth + 1).
                        self::wireField(2, $field['type'], $mapValue, $depth + 1);
                    $out .= self::tag($field['id'], 2).self::varint(strlen($entry)).$entry;
                }
            } elseif ($field['repeated']) {
                if (!is_array($value) || !array_is_list($value)) throw new \InvalidArgumentException('Repeated field '.$name.' must be a list');
                if ($field['packed'] && $value !== []) {
                    $packed = '';
                    foreach ($value as $item) $packed .= self::scalar($field['type'], $item);
                    $out .= self::tag($field['id'], 2).self::varint(strlen($packed)).$packed;
                } else {
                    foreach ($value as $item) $out .= self::wireField($field['id'], $field['type'], $item, $depth + 1);
                }
            } else {
                $out .= self::wireField($field['id'], $field['type'], $value, $depth + 1);
            }
            if (strlen($out) > 16777215) throw new \LengthException('WAProto message exceeds 16 MiB');
        }
        return $out;
    }

    private static function wireField(int $id, string $type, mixed $value, int $depth): string
    {
        $wire = self::wire($type);
        if (isset(self::schema()['messages'][$type])) {
            if (!is_array($value)) throw new \InvalidArgumentException($type.' must be an array');
            $bytes = self::encode($type, $value, $depth);
        } else $bytes = self::scalar($type, $value);
        return self::tag($id, $wire).($wire === 2 ? self::varint(strlen($bytes)) : '').$bytes;
    }

    private static function scalar(string $type, mixed $value): string
    {
        $enum = self::schema()['enums'][$type] ?? null;
        if ($enum !== null) {
            if (is_string($value) && array_key_exists($value, $enum)) $value = $enum[$value];
            return self::integer('int32', $value);
        }
        if ($type === 'bytes' || $type === 'string') {
            if (!is_string($value)) throw new \InvalidArgumentException($type.' field requires a string');
            if ($type === 'string' && preg_match('//u', $value) !== 1) throw new \InvalidArgumentException('Invalid protobuf UTF-8');
            return $value;
        }
        if ($type === 'bool') {
            if (!is_bool($value)) throw new \InvalidArgumentException('Boolean field requires true or false');
            return $value ? "\x01" : "\x00";
        }
        if ($type === 'double' || $type === 'float') {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) throw new \InvalidArgumentException('Finite numeric value required');
            return pack($type === 'double' ? 'e' : 'g', (float)$value);
        }
        return self::integer($type, $value);
    }

    private static function integer(string $type, mixed $value): string
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^-?(?:0|[1-9][0-9]*)$/D', (string)$value) !== 1) {
            throw new \InvalidArgumentException('Integer requires an int or canonical decimal string');
        }
        $n = gmp_init((string)$value, 10);
        $bits = str_contains($type, '64') ? 64 : 32;
        $signed = !str_starts_with($type, 'u') && !str_starts_with($type, 'fixed');
        $min = $signed ? gmp_neg(gmp_pow(2, $bits - 1)) : gmp_init(0);
        $max = gmp_sub(gmp_pow(2, $signed ? $bits - 1 : $bits), 1);
        if (gmp_cmp($n, $min) < 0 || gmp_cmp($n, $max) > 0) throw new \InvalidArgumentException($type.' out of range');
        if (str_contains($type, 'fixed')) {
            if (gmp_cmp($n, 0) < 0) $n = gmp_add($n, gmp_pow(2, $bits));
            return str_pad(gmp_export($n, 1, GMP_LSW_FIRST | GMP_LITTLE_ENDIAN), intdiv($bits, 8), "\0");
        }
        if (str_starts_with($type, 'sint')) $n = gmp_cmp($n, 0) < 0 ? gmp_sub(gmp_mul(gmp_abs($n), 2), 1) : gmp_mul($n, 2);
        elseif (gmp_cmp($n, 0) < 0) $n = gmp_add($n, gmp_pow(2, 64));
        return self::varint($n);
    }

    /** @return array<string,mixed> */
    public static function decode(string $type, string $bytes, int $depth = 0): array
    {
        return self::decodeInto($type, $bytes, [], $depth);
    }

    /**
     * Apply each wire occurrence to the existing singular-message state. A
     * separate decode followed by a structural merge loses intermediate oneof
     * selections, which can incorrectly restore data cleared by the sender.
     * @param array<string,mixed> $out
     * @return array<string,mixed>
     */
    private static function decodeInto(string $type, string $bytes, array $out, int $depth): array
    {
        self::depth($depth);
        $fields = self::fields($type);
        $byId = [];
        foreach ($fields as $name => $field) $byId[$field['id']] = [$name, $field];
        foreach (self::scan($bytes) as $item) {
            [$number, $wire, $value, $raw] = $item;
            if (!isset($byId[$number])) { $out['_unknown'][] = $raw; continue; }
            [$name, $field] = $byId[$number];
            // A few documented historical fields changed wire type. Preserve
            // their original bytes without guessing a conversion or accepting
            // arbitrary malformed wire types for other known fields.
            $expectedWire = isset($field['mapKey']) ? 2 : self::wire($field['type']);
            $packedWire = $field['repeated'] && $wire === 2 && $expectedWire !== 2;
            if ($wire !== $expectedWire && !$packedWire && in_array($wire, self::schema()['legacyWireTypes'][$type][$number] ?? [], true)) {
                $out['_unknown'][] = $raw;
                continue;
            }
            if (isset($field['mapKey'])) {
                if ($wire !== 2 || !is_string($value)) throw new \UnexpectedValueException('Invalid map wire type');
                $mapKey = ''; $mapValue = null;
                foreach (self::scan($value) as [$en, $ew, $ev]) {
                    if ($en === 1) $mapKey = self::decodeValue($field['mapKey'], $ew, $ev, $depth + 1);
                    if ($en === 2) $mapValue = self::decodeValue($field['type'], $ew, $ev, $depth + 1);
                }
                $out[$name][is_bool($mapKey) ? (int)$mapKey : (string)$mapKey] = $mapValue;
            } elseif ($field['repeated'] && $wire === 2 && self::wire($field['type']) !== 2) {
                if (!is_string($value)) throw new \UnexpectedValueException('Invalid packed value');
                $offset = 0; $out[$name] ??= [];
                $scalarWire = self::wire($field['type']);
                while ($offset < strlen($value)) {
                    $packed = $scalarWire === 0 ? self::readVarint($value, $offset) : self::readBytes($value, $offset, $scalarWire === 1 ? 8 : 4);
                    $out[$name][] = self::decodeValue($field['type'], $scalarWire, $packed, $depth + 1);
                }
            } else {
                self::selectOneof($fields, $name, $out);
                if (!$field['repeated'] && isset(self::schema()['messages'][$field['type']], $out[$name])) {
                    if ($wire !== 2 || !is_string($value)) throw new \UnexpectedValueException('Invalid message bytes');
                    $decoded = self::decodeInto($field['type'], $value, $out[$name], $depth + 1);
                } else $decoded = self::decodeValue($field['type'], $wire, $value, $depth + 1);
                if ($field['repeated']) $out[$name][] = $decoded;
                else $out[$name] = $decoded;
            }
        }
        return $out;
    }

    /**
     * A different member clears the previous selection; the same message member
     * remains available for protobuf's singular-message merge semantics.
     * @param array<string,array{id:int,type:string,repeated:bool,packed:bool,mapKey?:string,oneof?:string}> $fields
     * @param array<string,mixed> $data
     */
    private static function selectOneof(array $fields, string $name, array &$data): void
    {
        $group = $fields[$name]['oneof'] ?? null;
        if ($group === null) return;
        foreach ($fields as $other => $field) {
            if ($other !== $name && ($field['oneof'] ?? null) === $group) unset($data[$other]);
        }
    }

    private static function decodeValue(string $type, int $wire, \GMP|string $value, int $depth): mixed
    {
        if (self::wire($type) !== $wire) throw new \UnexpectedValueException('Incorrect wire type for '.$type);
        if (isset(self::schema()['messages'][$type])) {
            if (!is_string($value)) throw new \UnexpectedValueException('Invalid message bytes');
            return self::decode($type, $value, $depth);
        }
        if ($type === 'string' || $type === 'bytes') {
            if (!is_string($value)) throw new \UnexpectedValueException('Invalid string bytes');
            if ($type === 'string' && preg_match('//u', $value) !== 1) throw new \UnexpectedValueException('Invalid message UTF-8');
            return $value;
        }
        if ($type === 'double' || $type === 'float') return unpack($type === 'double' ? 'e' : 'g', (string)$value)[1];
        if (is_string($value)) $value = gmp_import($value, 1, GMP_LSW_FIRST | GMP_LITTLE_ENDIAN);
        if ($type === 'bool') return gmp_cmp($value, 0) !== 0;
        if (isset(self::schema()['enums'][$type])) $type = 'int32';
        $bits = str_contains($type, '64') ? 64 : 32;
        if (str_starts_with($type, 'sint')) $value = gmp_testbit($value, 0) ? gmp_neg(gmp_add(gmp_div_q($value, 2), 1)) : gmp_div_q($value, 2);
        elseif (!str_starts_with($type, 'u') && !str_starts_with($type, 'fixed')) {
            $value = gmp_mod($value, gmp_pow(2, $bits));
            if (gmp_testbit($value, $bits - 1)) $value = gmp_sub($value, gmp_pow(2, $bits));
        } elseif (gmp_cmp($value, gmp_sub(gmp_pow(2, $bits), 1)) > 0) throw new \OverflowException($type.' exceeds range');
        return gmp_cmp($value, PHP_INT_MAX) <= 0 && gmp_cmp($value, PHP_INT_MIN) >= 0 ? gmp_intval($value) : gmp_strval($value);
    }

    private static function wire(string $type): int
    {
        return match ($type) {
            'double','fixed64','sfixed64' => 1,
            'float','fixed32','sfixed32' => 5,
            'string','bytes' => 2,
            default => isset(self::schema()['messages'][$type]) ? 2 : 0,
        };
    }

    private static function tag(int $number, int $wire): string { return self::varint(($number << 3) | $wire); }

    private static function varint(int|\GMP $value): string
    {
        if (is_int($value)) $value = gmp_init($value);
        $out = '';
        do {
            $byte = gmp_intval(gmp_and($value, 127)); $value = gmp_div_q($value, 128);
            $out .= chr($byte | (gmp_cmp($value, 0) !== 0 ? 128 : 0));
        } while (gmp_cmp($value, 0) !== 0);
        return $out;
    }

    private static function readVarint(string $bytes, int &$offset): \GMP
    {
        $n = gmp_init(0);
        for ($i = 0; $i < 10; ++$i) {
            if (!isset($bytes[$offset])) throw new \UnexpectedValueException('Truncated protobuf varint');
            $b = ord($bytes[$offset++]);
            if ($i === 9 && $b > 1) throw new \OverflowException('Varint exceeds uint64');
            $n = gmp_or($n, gmp_mul($b & 127, gmp_pow(2, $i * 7)));
            if (($b & 128) === 0) return $n;
        }
        throw new \OverflowException('Varint exceeds uint64');
    }

    private static function readBytes(string $bytes, int &$offset, int $length): string
    {
        if ($length < 0 || $length > strlen($bytes) - $offset) throw new \UnexpectedValueException('Truncated protobuf field');
        $value = substr($bytes, $offset, $length); $offset += $length;
        return $value;
    }

    /** @return list<array{int,int,\GMP|string,string}> */
    private static function scan(string $bytes): array
    {
        if (strlen($bytes) > 16777215) throw new \LengthException('WAProto message exceeds 16 MiB');
        $offset = 0; $out = [];
        while ($offset < strlen($bytes)) {
            if (count($out) >= 100000) throw new \LengthException('Too many WAProto fields');
            $start = $offset; $tag = self::readVarint($bytes, $offset);
            if (gmp_cmp($tag, 4294967295) > 0) throw new \UnexpectedValueException('Invalid protobuf tag');
            $tag = gmp_intval($tag); $number = $tag >> 3; $wire = $tag & 7;
            if ($number < 1) throw new \UnexpectedValueException('Invalid field number');
            if ($wire === 0) $value = self::readVarint($bytes, $offset);
            else {
                if ($wire === 2) {
                    $length = self::readVarint($bytes, $offset);
                    if (gmp_cmp($length, strlen($bytes) - $offset) > 0) throw new \UnexpectedValueException('Truncated protobuf field');
                    $length = gmp_intval($length);
                } else $length = match ($wire) {1 => 8, 5 => 4, default => throw new \UnexpectedValueException('Unsupported protobuf wire type')};
                $value = self::readBytes($bytes, $offset, $length);
            }
            $out[] = [$number, $wire, $value, substr($bytes, $start, $offset - $start)];
        }
        return $out;
    }

    /** @return list<int> */
    public static function fieldNumbers(string $bytes): array { return array_values(array_unique(array_column(self::scan($bytes), 0))); }

    private static function depth(int $depth): void
    {
        if ($depth > 32) throw new \LengthException('WAProto nesting limit exceeded');
    }
}
