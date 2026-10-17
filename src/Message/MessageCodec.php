<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Protocol\Protobuf;

/** The Message fields used by direct text traffic, from WAProto.proto. */
final class MessageCodec
{
    public static function text(string $text): string
    {
        if ($text === '' || strlen($text) > 65536 || preg_match('//u', $text) !== 1) {
            throw new \InvalidArgumentException('Text must contain 1 to 65536 UTF-8 bytes');
        }
        return self::bytes(1, $text);
    }

    public static function deviceSent(string $message, string $destination): string
    {
        $wrapper = self::bytes(31, self::bytes(1, $destination).self::bytes(2, $message));
        // Companion clients also receive the original MessageContextInfo at
        // the outer level of the device-sent envelope.
        foreach (Protobuf::decode($message,16777216,10000) as $field) {
            if ($field['number'] === 35 && $field['wire'] === 2 && is_string($field['value'])) $wrapper .= self::bytes(35,$field['value']);
        }
        return $wrapper;
    }

    public static function pad(string $bytes): string
    {
        $length = random_int(1, 16);
        return $bytes.str_repeat(chr($length), $length);
    }

    public static function unpad(string $bytes): string
    {
        if ($bytes === '') throw new \UnexpectedValueException('Empty padded message');
        $length = ord($bytes[strlen($bytes)-1]);
        if ($length < 1 || $length > 16 || $length > strlen($bytes) ||
            !hash_equals(str_repeat(chr($length), $length), substr($bytes, -$length))) {
            throw new \UnexpectedValueException('Invalid message padding');
        }
        return substr($bytes, 0, -$length);
    }

    /** @return array<string,mixed> */
    public static function decode(string $bytes, int $depth = 0): array
    {
        return ContentDecoder::decode($bytes, $depth);
    }

    /** @param array<string,mixed> $message */
    public static function encode(array $message): string
    {
        return \MVO\Protocol\SchemaCodec::encode('Message', $message);
    }

    public static function bytes(int $number, string $value): string
    {
        return Protobuf::encode([['number'=>$number,'wire'=>2,'value'=>$value]]);
    }

}
