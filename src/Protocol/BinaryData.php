<?php
declare(strict_types=1);
namespace MVO\Protocol;

/** Binary-safe JSON for application events; accepts old plain JSON records too. */
final class BinaryData
{
    public static function encode(mixed $value): string { return json_encode(self::pack($value),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION); }
    public static function decode(string $json): mixed { return self::unpack(json_decode($json,true,128,JSON_THROW_ON_ERROR)); }
    private static function pack(mixed $value): mixed {
        if (is_string($value)) return ['$bytes'=>base64_encode($value)];
        if (is_array($value)) return array_map(self::pack(...),$value);
        if (is_int($value) || is_bool($value) || $value===null || (is_float($value) && is_finite($value))) return $value;
        throw new \InvalidArgumentException('Unsupported application data value');
    }
    private static function unpack(mixed $value): mixed {
        if (is_array($value) && array_keys($value)===['$bytes']) {
            if (!is_string($value['$bytes'])) throw new \UnexpectedValueException('Invalid binary application value');
            $decoded = base64_decode($value['$bytes'],true);
            if ($decoded === false) throw new \UnexpectedValueException('Invalid binary base64');
            return $decoded;
        }
        if (is_array($value)) return array_map(self::unpack(...),$value);
        if (is_string($value) || is_int($value) || is_bool($value) || $value===null || (is_float($value) && is_finite($value))) return $value;
        throw new \UnexpectedValueException('Invalid application data value');
    }
    /** JSON API form: bytes use an explicit {"$base64":"..."} object. */
    public static function fromJsonInput(mixed $value): mixed {
        if (is_array($value) && array_keys($value)===['$base64']) {
            if (!is_string($value['$base64'])) throw new \InvalidArgumentException('Expected base64 text');
            $bytes=base64_decode($value['$base64'],true);
            if ($bytes===false) throw new \InvalidArgumentException('Invalid base64 input');
            return $bytes;
        }
        if (is_array($value)) return array_map(self::fromJsonInput(...),$value);
        return $value;
    }
}
