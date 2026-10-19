<?php
declare(strict_types=1);

namespace MVO\Messenger\Protocol;

use MVO\Messenger\MessengerException;

/**
 * Original PHP codec for the observed DGW v5 wire layout.
 *
 * Protocol facts: one-byte type; uint16 LE stream; uint24 LE body length;
 * data has a uint15 acknowledgement ID plus the high-bit acknowledgement flag;
 * a standalone ACK frame carries its complete uint16 acknowledgement field.
 * The DGWVER_BIG_IDS=5 name does not establish 32-bit stream/ACK fields: the
 * pinned current frames.go explicitly declares StreamID uint16 and masks DATA
 * AckID with 0x7fff. It agrees with these widths in the public shipped codec.
 * Reference: mautrix/meta 1b3bd5d, pkg/messagix/dgw/frames.go. This is an
 * implementation-reference comparison, not an authenticated traffic capture.
 */
final class DgwCodec
{
    public const DRAIN = 3;
    public const DEAUTH = 4;
    public const PING = 9;
    public const PONG = 10;
    public const ACK = 12;
    public const DATA = 13;
    public const END = 14;
    public const ESTABLISH = 15;
    public const END_REASON = 16;
    public const EXTENDED_DATA = 17;

    /** @param array<string, mixed> $frame */
    public static function encode(array $frame): string
    {
        $type = self::integer($frame, 'type', 0, 255);
        if (in_array($type, [self::DEAUTH, self::PING, self::PONG], true)) {
            return chr($type);
        }
        if ($type === self::DRAIN) {
            return chr($type) . "\x01\x00\x00" . chr(self::integer($frame, 'reason', 0, 255));
        }
        $stream = pack('v', self::integer($frame, 'stream_id', 0, 65535));
        if ($type === self::END) {
            return chr($type) . $stream;
        }
        switch ($type) {
            case self::ESTABLISH:
                $body = self::jsonEncode($frame['parameters'] ?? new \stdClass());
                break;
            case self::ACK:
                $body = pack('v', self::integer($frame, 'ack_id', 0, 65535));
                break;
            case self::END_REASON:
                $body = chr(self::integer($frame, 'reason', 0, 255));
                break;
            case self::DATA:
            case self::EXTENDED_DATA:
                $payload = $frame['payload'] ?? null;
                if (!is_string($payload) || !is_bool($frame['requires_ack'] ?? false)) {
                    throw self::invalid();
                }
                $ack = self::integer($frame, 'ack_id', 0, 32767);
                $body = pack('v', $ack | (($frame['requires_ack'] ?? false) ? 0x8000 : 0));
                if ($type === self::EXTENDED_DATA) {
                    $body .= chr(self::integer($frame, 'content_type', 0, 255));
                }
                $body .= $payload;
                break;
            default:
                throw new MessengerException('dgw_unsupported_frame', 'Tipo de frame DGW não implementado.');
        }
        $length = strlen($body);
        if ($length > 0xffffff) {
            throw new MessengerException('dgw_limit', 'O frame DGW excede o limite do protocolo.');
        }
        return chr($type) . $stream . self::uint24($length) . $body;
    }

    /** @return list<array<string, mixed>> */
    public static function decode(string $message, int $maxPayloadBytes = 8388608, int $maxFrames = 1024): array
    {
        if ($maxPayloadBytes < 1 || $maxPayloadBytes > 0xffffff || $maxFrames < 1) {
            throw new \InvalidArgumentException('Limites DGW inválidos.');
        }
        if ($message === '' || strlen($message) > $maxPayloadBytes) {
            throw new MessengerException('dgw_limit', 'A mensagem DGW está vazia ou excede o limite configurado.');
        }
        $frames = [];
        $offset = 0;
        $total = strlen($message);
        while ($offset < $total) {
            if (count($frames) >= $maxFrames) {
                throw new MessengerException('dgw_limit', 'Quantidade de frames DGW acima do limite.');
            }
            $type = ord($message[$offset]);
            $frame = ['type' => $type];
            if (in_array($type, [self::DEAUTH, self::PING, self::PONG], true)) {
                ++$offset;
                $frames[] = $frame;
                continue;
            }
            if ($type === self::DRAIN) {
                self::requireBytes($total - $offset, 5);
                if (substr($message, $offset + 1, 3) !== "\x01\x00\x00") {
                    throw self::invalid();
                }
                $frame['reason'] = ord($message[$offset + 4]);
                $offset += 5;
                $frames[] = $frame;
                continue;
            }
            if (!in_array($type, [self::END, self::ESTABLISH, self::ACK, self::DATA, self::EXTENDED_DATA, self::END_REASON], true)) {
                throw new MessengerException('dgw_unsupported_frame', 'Tipo de frame DGW não implementado.');
            }
            self::requireBytes($total - $offset, 3);
            $frame['stream_id'] = unpack('v', substr($message, $offset + 1, 2))[1];
            if ($type === self::END) {
                $offset += 3;
                $frames[] = $frame;
                continue;
            }
            self::requireBytes($total - $offset, 6);
            $length = ord($message[$offset + 3]) | (ord($message[$offset + 4]) << 8) | (ord($message[$offset + 5]) << 16);
            if ($length > $maxPayloadBytes) {
                throw new MessengerException('dgw_limit', 'O payload DGW excede o limite configurado.');
            }
            self::requireBytes($total - $offset - 6, $length);
            $body = substr($message, $offset + 6, $length);
            $offset += 6 + $length;
            switch ($type) {
                case self::ESTABLISH:
                    try {
                        $frame['parameters'] = json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                    } catch (\JsonException) {
                        throw self::invalid();
                    }
                    break;
                case self::ACK:
                    if ($length !== 2) {
                        throw self::invalid();
                    }
                    $frame['ack_id'] = unpack('v', $body)[1];
                    break;
                case self::END_REASON:
                    if ($length !== 1) {
                        throw self::invalid();
                    }
                    $frame['reason'] = ord($body[0]);
                    break;
                case self::DATA:
                case self::EXTENDED_DATA:
                    $header = $type === self::DATA ? 2 : 3;
                    self::requireBytes($length, $header);
                    $flags = unpack('v', substr($body, 0, 2))[1];
                    $frame['ack_id'] = $flags & 0x7fff;
                    $frame['requires_ack'] = ($flags & 0x8000) !== 0;
                    if ($type === self::EXTENDED_DATA) {
                        $frame['content_type'] = ord($body[2]);
                    }
                    $frame['payload'] = substr($body, $header);
                    break;
            }
            $frames[] = $frame;
        }
        return $frames;
    }

    /** @param array<string, mixed> $data */
    private static function integer(array $data, string $key, int $min, int $max): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw self::invalid();
        }
        return $value;
    }

    private static function uint24(int $value): string
    {
        return chr($value & 255) . chr(($value >> 8) & 255) . chr(($value >> 16) & 255);
    }

    private static function requireBytes(int $available, int $required): void
    {
        if ($available < $required) {
            throw self::invalid();
        }
    }

    private static function jsonEncode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            throw self::invalid();
        }
    }

    private static function invalid(): MessengerException
    {
        return new MessengerException('dgw_invalid_frame', 'Frame DGW inválido ou incompleto.');
    }
}
