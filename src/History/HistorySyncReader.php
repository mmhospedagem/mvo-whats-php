<?php
declare(strict_types=1);
namespace MVO\History;

use MVO\Protocol\SchemaCodec;

/** Incremental WAProto HistorySync reader over an already authenticated, seekable stream. */
final class HistorySyncReader
{
    private const MAX_RECORD_BYTES = 16777215;
    private const MAX_FIELDS = 1000000;

    /**
     * Inspect root metadata before invoking application callbacks. Missing fields
     * are omitted, allowing the caller to use the carrier notification's defaults.
     * The original stream position is restored, including on failure.
     * @param resource $stream
     * @return array{syncType?:int,chunkOrder?:int,progress?:int}
     */
    public static function metadata($stream): array
    {
        [$position, $length] = self::streamBounds($stream);
        $out = []; $count = 0; $fields = self::fieldsById('HistorySync');
        try {
            foreach (self::spans($stream, 0, $length, $count) as $span) {
                $name = $fields[$span['id']][0] ?? null;
                if (!in_array($name, ['syncType', 'chunkOrder', 'progress'], true)) continue;
                $decoded = SchemaCodec::decode('HistorySync', self::raw($stream, $span));
                if (isset($decoded[$name])) $out[$name] = $decoded[$name];
            }
            return $out;
        } finally { self::seek($stream, $position); }
    }

    /**
     * Known top-level field occurrences are delivered in wire order. Each
     * repeated element is an individual item, including packed scalar elements.
     * Conversation messages precede their header, without collecting messages.
     * Do not close or modify the stream while this generator is active.
     * @param resource $stream
     * @return \Generator<int,array{kind:string,value:mixed,conversationId?:string}>
     */
    public static function items($stream): \Generator
    {
        [$position, $length] = self::streamBounds($stream);
        $count = 0; $fields = self::fieldsById('HistorySync');
        try {
            foreach (self::spans($stream, 0, $length, $count) as $span) {
                if (!isset($fields[$span['id']])) continue;
                [$name, $field] = $fields[$span['id']];
                if ($name === 'syncType') {
                    // Validate metadata occurrences without exposing them as items.
                    SchemaCodec::decode('HistorySync', self::raw($stream, $span));
                    continue;
                }
                if ($name === 'conversations') {
                    if ($span['wire'] !== 2) throw new \UnexpectedValueException('Invalid history conversation wire type');
                    yield from self::conversation($stream, $span, $count);
                    continue;
                }
                $decoded = SchemaCodec::decode('HistorySync', self::raw($stream, $span));
                if (!array_key_exists($name, $decoded)) continue;
                if ($field['repeated']) {
                    foreach ($decoded[$name] as $value) yield ['kind' => $name, 'value' => $value];
                } else yield ['kind' => $name, 'value' => $decoded[$name]];
            }
        } finally { self::seek($stream, $position); }
    }

    /**
     * A seekable spool permits a metadata pass when the ID follows messages,
     * without parking any message bodies. Only the bounded header is collected.
     * @param resource $stream
     * @param array{id:int,wire:int,start:int,payload:int,end:int} $span
     * @return \Generator<int,array{kind:string,value:mixed,conversationId?:string}>
     */
    private static function conversation($stream, array $span, int &$count): \Generator
    {
        $fields = self::fieldsById('Conversation');
        $header = '';
        foreach (self::spans($stream, $span['payload'], $span['end'], $count) as $part) {
            if (!isset($fields[$part['id']])) continue;
            if ($fields[$part['id']][0] === 'messages') {
                if ($part['wire'] !== 2) throw new \UnexpectedValueException('Invalid history message wire type');
                self::recordLength($part);
                continue;
            }
            if ($part['end'] - $part['start'] > self::MAX_RECORD_BYTES - strlen($header)) {
                throw new \LengthException('History conversation header exceeds 16 MiB');
            }
            $header .= self::raw($stream, $part);
        }
        $conversation = SchemaCodec::decode('Conversation', $header);
        unset($conversation['_unknown'], $conversation['messages']);
        $id = $conversation['id'] ?? null;
        if (!is_string($id) || $id === '') throw new \UnexpectedValueException('History conversation missing ID');
        // The structural fields were counted in the first pass, not twice.
        foreach (self::spans($stream, $span['payload'], $span['end'], $count, false) as $part) {
            if (($fields[$part['id']][0] ?? null) !== 'messages') continue;
            $decoded = SchemaCodec::decode('Conversation', self::raw($stream, $part));
            foreach ($decoded['messages'] ?? [] as $message) {
                yield ['kind' => 'conversations.messages', 'conversationId' => $id, 'value' => $message];
            }
        }
        yield ['kind' => 'conversations', 'value' => $conversation];
    }

    /** @return array<int,array{string,array{id:int,type:string,repeated:bool,packed:bool,mapKey?:string,oneof?:string}} > */
    private static function fieldsById(string $type): array
    {
        $out = [];
        foreach (SchemaCodec::fields($type) as $name => $field) $out[$field['id']] = [$name, $field];
        return $out;
    }

    /**
     * Inspect only wire framing; a length-delimited conversation may exceed the
     * individual record limit because its messages are consumed separately.
     * @param resource $stream
     * @return \Generator<int,array{id:int,wire:int,start:int,payload:int,end:int}>
     */
    private static function spans($stream, int $start, int $end, int &$count, bool $countFields = true): \Generator
    {
        $position = $start;
        while ($position < $end) {
            if ($countFields && ++$count > self::MAX_FIELDS) throw new \LengthException('Too many history protobuf fields');
            self::seek($stream, $position);
            $tag = self::unsigned(self::varint($stream, $end), 4294967295);
            $id = $tag >> 3; $wire = $tag & 7;
            if ($id < 1) throw new \UnexpectedValueException('Invalid history protobuf field number');
            if ($wire === 0) {
                $payload = self::position($stream);
                self::varint($stream, $end);
                $next = self::position($stream);
            } else {
                if ($wire === 2) $size = self::unsigned(self::varint($stream, $end), PHP_INT_MAX);
                else $size = match ($wire) { 1 => 8, 5 => 4, default => throw new \UnexpectedValueException('Unsupported history protobuf wire type') };
                $payload = self::position($stream);
                if ($size > $end - $payload) throw new \UnexpectedValueException('Truncated history protobuf field');
                $next = $payload + $size;
                self::seek($stream, $next);
            }
            yield ['id' => $id, 'wire' => $wire, 'start' => $position, 'payload' => $payload, 'end' => $next];
            $position = $next;
        }
    }

    /** @param resource $stream */
    private static function varint($stream, int $end): string
    {
        $bytes = '';
        for ($index = 0; $index < 10; ++$index) {
            if (self::position($stream) >= $end) throw new \UnexpectedValueException('Truncated history protobuf varint');
            $byte = fread($stream, 1);
            if ($byte === false || $byte === '') throw new \UnexpectedValueException('Truncated history protobuf varint');
            $value = ord($byte); $bytes .= $byte;
            if ($index === 9 && $value > 1) throw new \OverflowException('History protobuf varint exceeds uint64');
            if (($value & 128) === 0) return $bytes;
        }
        throw new \OverflowException('History protobuf varint exceeds uint64');
    }

    private static function unsigned(string $bytes, int $max): int
    {
        $value = 0;
        for ($index = strlen($bytes) - 1; $index >= 0; --$index) {
            $part = ord($bytes[$index]) & 127;
            if ($part > $max || $value > intdiv($max - $part, 128)) throw new \OverflowException('History protobuf integer exceeds bounds');
            $value = $value * 128 + $part;
        }
        return $value;
    }

    /** @param array{id:int,wire:int,start:int,payload:int,end:int} $span */
    private static function recordLength(array $span): void
    {
        if ($span['end'] - $span['start'] > self::MAX_RECORD_BYTES) throw new \LengthException('History protobuf record exceeds 16 MiB');
    }

    /**
     * @param resource $stream
     * @param array{id:int,wire:int,start:int,payload:int,end:int} $span
     */
    private static function raw($stream, array $span): string
    {
        self::recordLength($span);
        self::seek($stream, $span['start']);
        $remaining = $span['end'] - $span['start']; $bytes = '';
        while ($remaining > 0) {
            $part = fread($stream, min(65536, $remaining));
            if ($part === false || $part === '') throw new \UnexpectedValueException('Truncated history protobuf record');
            $bytes .= $part; $remaining -= strlen($part);
        }
        return $bytes;
    }

    /**
     * @param resource $stream
     * @return array{int,int}
     */
    private static function streamBounds($stream): array
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream' || !stream_get_meta_data($stream)['seekable']) {
            throw new \InvalidArgumentException('History protobuf reader requires a seekable stream');
        }
        $position = self::position($stream);
        if (fseek($stream, 0, SEEK_END) !== 0) throw new \RuntimeException('Cannot seek history stream');
        $end = self::position($stream);
        self::seek($stream, $position);
        return [$position, $end];
    }

    /** @param resource $stream */
    private static function position($stream): int
    {
        $position = ftell($stream);
        if ($position === false) throw new \RuntimeException('Cannot inspect history stream position');
        return $position;
    }

    /** @param resource $stream */
    private static function seek($stream, int $position): void
    {
        if (fseek($stream, $position, SEEK_SET) !== 0) throw new \RuntimeException('Cannot seek history stream');
    }
}
