<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\History\HistorySyncReader;
use MVO\Message\MessageCodec;
use MVO\Protocol\SchemaCodec;
use PHPUnit\Framework\TestCase;

final class HistorySyncReaderTest extends TestCase
{
    /** @return resource */
    private static function stream(string $bytes)
    {
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        if ($stream === false) throw new \RuntimeException('Cannot open test stream');
        fwrite($stream, $bytes); rewind($stream);
        return $stream;
    }

    public function testKnownFieldsAreEmittedOnceInWireOrderAndMetadataPreservesPosition(): void
    {
        $data = [
            'syncType' => 'RECENT', 'chunkOrder' => 3, 'progress' => 65,
            'statusV3Messages' => [[]],
            'pushnames' => [['id' => '1@s.whatsapp.net', 'pushname' => 'First'], ['id' => '2@s.whatsapp.net', 'pushname' => 'Second']],
            'globalSettings' => ['lightThemeWallpaper' => ['filename' => 'test']],
            'threadIdUserSecret' => "\0secret\xff", 'threadDsTimeframeOffset' => 10,
            'recentStickers' => [[]], 'pastParticipants' => [[]], 'callLogRecords' => [[]],
            'aiWaitListState' => 1, 'phoneNumberToLidMappings' => [['pnJid' => '1@s.whatsapp.net', 'lidJid' => '2@lid']],
            'companionMetaNonce' => 'nonce', 'shareableChatIdentifierEncryptionKey' => "\0key",
            'accounts' => [[]], 'nctSalt' => "\xffsalt", 'inlineContacts' => [['pnJid' => '1@s.whatsapp.net', 'fullName' => 'Name']],
            'inlineContactsProvided' => true, 'favoriteStickers' => [[]], 'omittedConversations' => [[]], 'omittedConversationsProvided' => false,
        ];
        $bytes = SchemaCodec::encode('HistorySync', $data);
        $stream = self::stream($bytes . MessageCodec::bytes(4000, 'not exposed'));
        try {
            fseek($stream, 2);
            self::assertSame(['syncType' => 3, 'chunkOrder' => 3, 'progress' => 65], HistorySyncReader::metadata($stream));
            self::assertSame(2, ftell($stream));
            $items = iterator_to_array(HistorySyncReader::items($stream), false);
            self::assertSame(2, ftell($stream));
            $decoded = SchemaCodec::decode('HistorySync', $bytes);
            $expected = [];
            foreach ($decoded as $name => $value) {
                if ($name === 'syncType') continue;
                if (SchemaCodec::fields('HistorySync')[$name]['repeated']) {
                    foreach ($value as $record) $expected[] = ['kind' => $name, 'value' => $record];
                } else $expected[] = ['kind' => $name, 'value' => $value];
            }
            self::assertSame($expected, $items);
            self::assertNotContains('syncType', array_column($items, 'kind'));
            self::assertNotContains('_unknown', array_column($items, 'kind'));
        } finally { fclose($stream); }
    }

    public function testConversationMessagesBeforeIdPreserveStubsOrderAndRawRecordFields(): void
    {
        $records = [
            ['msgOrderId' => '18446744073709551615'],
            ['message' => ['key' => ['id' => 'stub'], 'messageStubType' => 1], 'msgOrderId' => 2],
            ['message' => ['key' => ['id' => 'text'], 'message' => ['conversation' => 'Hello']], 'msgOrderId' => 3],
        ];
        $conversation = '';
        foreach ($records as $record) $conversation .= MessageCodec::bytes(2, SchemaCodec::encode('HistorySyncMsg', $record));
        $conversation .= MessageCodec::bytes(1, '1-2@g.us') . MessageCodec::bytes(13, 'Group');
        $bytes = MessageCodec::bytes(2, $conversation) . SchemaCodec::encode('HistorySync', ['syncType' => 'RECENT', 'progress' => 100]);
        $stream = self::stream($bytes);
        try {
            self::assertSame(['syncType' => 3, 'progress' => 100], HistorySyncReader::metadata($stream));
            $items = iterator_to_array(HistorySyncReader::items($stream), false);
            self::assertCount(5, $items);
            foreach ($records as $index => $record) {
                self::assertSame(['kind' => 'conversations.messages', 'conversationId' => '1-2@g.us', 'value' => $record], $items[$index]);
            }
            self::assertSame(['kind' => 'conversations', 'value' => ['id' => '1-2@g.us', 'name' => 'Group']], $items[3]);
            self::assertSame(['kind' => 'progress', 'value' => 100], $items[4]);
        } finally { fclose($stream); }
    }

    public function testMetadataAndItemsSupportEmptyStreamAndMissingSyncType(): void
    {
        foreach (['', SchemaCodec::encode('HistorySync', ['progress' => 1])] as $bytes) {
            $stream = self::stream($bytes);
            try {
                self::assertArrayNotHasKey('syncType', HistorySyncReader::metadata($stream));
                self::assertCount($bytes === '' ? 0 : 1, iterator_to_array(HistorySyncReader::items($stream), false));
            } finally { fclose($stream); }
        }
    }

    public function testMissingOrEmptyConversationIdFailsBeforeDeliveringMessages(): void
    {
        $message = MessageCodec::bytes(2, SchemaCodec::encode('HistorySyncMsg', ['msgOrderId' => 1]));
        foreach ([$message, $message . MessageCodec::bytes(1, ''), ''] as $conversation) {
            $stream = self::stream(MessageCodec::bytes(2, $conversation)); $delivered = 0;
            try {
                $failed = false;
                try {
                    foreach (HistorySyncReader::items($stream) as $_item) ++$delivered;
                } catch (\UnexpectedValueException $error) {
                    $failed = true; self::assertSame('History conversation missing ID', $error->getMessage());
                }
                self::assertTrue($failed); self::assertSame(0, $delivered);
            } finally { fclose($stream); }
        }
    }

    public function testRetainedBytesAndMessageValuesDoNotChangeAsReaderAdvances(): void
    {
        $stream = self::stream(SchemaCodec::encode('HistorySync', [
            'threadIdUserSecret' => "\0first\xff", 'nctSalt' => str_repeat('second', 10000),
            'conversations' => [['id' => '1@s.whatsapp.net', 'messages' => [['message' => ['message' => ['conversation' => 'retained']]]]]],
        ]));
        try {
            $items = iterator_to_array(HistorySyncReader::items($stream), false);
            self::assertSame("\0first\xff", $items[2]['value']);
            self::assertSame('retained', $items[0]['value']['message']['message']['conversation']);
        } finally { fclose($stream); }
    }

    public function testFramingRejectsTruncationInvalidTagsWiresAndOversizedVarints(): void
    {
        $invalid = ["\x00", "\x0b", "\x0e", "\x0f", "\x08\x80", "\x12\x80", "\x12\x02x", "\x09x", "\x0dx", str_repeat("\xff", 10), "\x08" . str_repeat("\xff", 10)];
        foreach ($invalid as $bytes) {
            $stream = self::stream($bytes);
            try {
                try { HistorySyncReader::metadata($stream); self::fail('Expected invalid framing: ' . bin2hex($bytes)); }
                catch (\UnexpectedValueException|\OverflowException) {}
                self::assertSame(0, ftell($stream));
            } finally { fclose($stream); }
        }
    }

    public function testNestedMalformedDataAndWrongKnownWireAreRejected(): void
    {
        $invalid = [MessageCodec::bytes(2, "\x12\x80"), MessageCodec::bytes(2, "\x10\x01"), "\x10\x01", MessageCodec::bytes(7, "\x00"), "\x28\x01\x30\x01" . MessageCodec::bytes(2, MessageCodec::bytes(1, "\xff"))];
        foreach ($invalid as $bytes) {
            $stream = self::stream($bytes);
            try {
                try { iterator_to_array(HistorySyncReader::items($stream), false); self::fail('Expected invalid record: ' . bin2hex($bytes)); }
                catch (\UnexpectedValueException|\OverflowException) {}
                self::assertSame(0, ftell($stream));
            } finally { fclose($stream); }
        }
    }

    public function testUnknownLargeFieldsAreSkippedWithoutDecodingTheirPayload(): void
    {
        $stream = tmpfile();
        self::assertIsResource($stream);
        try {
            fwrite($stream, MessageCodec::bytes(300, str_repeat("\xff", 17000000)));
            fwrite($stream, SchemaCodec::encode('HistorySync', ['progress' => 5]));
            rewind($stream);
            self::assertSame(['progress' => 5], HistorySyncReader::metadata($stream));
            self::assertSame([['kind' => 'progress', 'value' => 5]], iterator_to_array(HistorySyncReader::items($stream), false));
        } finally { fclose($stream); }
    }

    public function testLargeConversationIsReadIncrementallyWithoutAccumulatingMessages(): void
    {
        $stream = tmpfile();
        self::assertIsResource($stream);
        try {
            $message = MessageCodec::bytes(2, SchemaCodec::encode('HistorySyncMsg', ['message' => ['message' => ['conversation' => str_repeat('x', 8192)]]]));
            $id = MessageCodec::bytes(1, '1-2@g.us');
            $recordCount = 4096;
            $size = strlen($message) * $recordCount + strlen($id);
            fwrite($stream, self::tagAndSize(2, $size));
            for ($index = 0; $index < $recordCount; ++$index) fwrite($stream, $message);
            fwrite($stream, $id); rewind($stream);
            // Warm the static schema before measuring reader allocations.
            SchemaCodec::fields('HistorySync');
            $before = memory_get_usage(true); $max = $before; $messages = 0; $headers = 0;
            foreach (HistorySyncReader::items($stream) as $item) {
                $max = max($max, memory_get_usage(true));
                if ($item['kind'] === 'conversations.messages') {
                    ++$messages;
                    self::assertSame('1-2@g.us', $item['conversationId']);
                    self::assertSame(8192, strlen($item['value']['message']['message']['conversation']));
                } elseif ($item['kind'] === 'conversations') {
                    ++$headers; self::assertSame(['id' => '1-2@g.us'], $item['value']);
                }
            }
            self::assertSame($recordCount, $messages); self::assertSame(1, $headers);
            self::assertLessThan(8 * 1024 * 1024, $max - $before, 'Reader must not retain a 32 MiB conversation or its messages');
        } finally { fclose($stream); }
    }

    public function testOversizedIndividualKnownRecordAndHeaderAreRejected(): void
    {
        foreach ([
            MessageCodec::bytes(9, str_repeat('x', 16777216)),
            MessageCodec::bytes(2, MessageCodec::bytes(13, str_repeat('x', 16777216))),
            MessageCodec::bytes(2, MessageCodec::bytes(2, str_repeat('x', 16777216))),
        ] as $bytes) {
            $stream = self::stream($bytes);
            try {
                $caught = false;
                try { iterator_to_array(HistorySyncReader::items($stream), false); }
                catch (\LengthException) { $caught = true; }
                self::assertTrue($caught);
            } finally { fclose($stream); }
        }
    }

    public function testNonStreamInputsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HistorySyncReader::metadata('not a stream');
    }

    public function testConversationAndRootShareOneStructuralFieldBudget(): void
    {
        $stream = tmpfile(); self::assertIsResource($stream);
        try {
            $unknown = "\xa0\x06\x00";
            $half = str_repeat($unknown, 500000); $id = MessageCodec::bytes(1, '1@g.us');
            fwrite($stream, self::tagAndSize(2, strlen($half) + strlen($id)));
            fwrite($stream, $half); fwrite($stream, $id); fwrite($stream, $half); unset($half);
            rewind($stream);
            // The first scan has only 500001 root fields; the item pass also
            // counts 500000 conversation fields, which exceeds the shared cap.
            self::assertSame([], HistorySyncReader::metadata($stream));
            $this->expectException(\LengthException::class);
            iterator_to_array(HistorySyncReader::items($stream), false);
        } finally { fclose($stream); }
    }

    public function testGeneratorRestoresStreamPositionWhenConsumerStopsEarly(): void
    {
        $stream = self::stream(SchemaCodec::encode('HistorySync', ['progress' => 10, 'pushnames' => [['id' => '1@s.whatsapp.net']]]));
        try {
            fseek($stream, 1); $items = HistorySyncReader::items($stream);
            $items->rewind(); self::assertSame('progress', $items->current()['kind']);
            unset($items); self::assertSame(1, ftell($stream));
        } finally { fclose($stream); }
    }

    private static function tagAndSize(int $number, int $length): string
    {
        $out = '';
        foreach ([($number << 3) | 2, $length] as $value) {
            do { $byte = $value & 127; $value >>= 7; $out .= chr($byte | ($value > 0 ? 128 : 0)); } while ($value > 0);
        }
        return $out;
    }
}
