<?php
declare(strict_types=1);

use MVO\Messenger\MessengerException;
use MVO\Messenger\Protocol\DgwCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/MessengerException.php';
require_once __DIR__ . '/../src/Protocol/DgwCodec.php';

final class DgwCodecTest extends TestCase
{
    public function testV5DataWireUsesLittleEndianStreamLengthAndAcknowledgementFlag(): void
    {
        // Independent byte fixture: type 13, stream 0x1234, length 3,
        // acknowledgement 0x0201 with required-ACK high bit, ASCII A.
        $bytes = hex2bin('0d3412030000018241');
        $frame = [
            'type' => DgwCodec::DATA, 'stream_id' => 0x1234,
            'ack_id' => 0x0201, 'requires_ack' => true, 'payload' => 'A',
        ];
        self::assertSame($bytes, DgwCodec::encode($frame));
        self::assertSame([$frame], DgwCodec::decode($bytes));
    }

    public function testV5EstablishmentAndAckAreSeparateFrames(): void
    {
        $bytes = hex2bin('0f34120c00007b22636f6465223a3230307d0c34120200000000');
        self::assertSame([
            ['type' => DgwCodec::ESTABLISH, 'stream_id' => 0x1234, 'parameters' => ['code' => 200]],
            ['type' => DgwCodec::ACK, 'stream_id' => 0x1234, 'ack_id' => 0],
        ], DgwCodec::decode($bytes));
        self::assertSame(hex2bin('0f00000200007b7d'), DgwCodec::encode([
            'type' => DgwCodec::ESTABLISH, 'stream_id' => 0, 'parameters' => new stdClass(),
        ]));
    }

    public function testExtendedDataKeepsContentTypeAndFullUint15AckId(): void
    {
        $bytes = hex2bin('11ffff040000ffff0042');
        $frame = [
            'type' => DgwCodec::EXTENDED_DATA, 'stream_id' => 65535,
            'ack_id' => 32767, 'requires_ack' => true, 'content_type' => 0, 'payload' => 'B',
        ];
        self::assertSame($bytes, DgwCodec::encode($frame));
        self::assertSame([$frame], DgwCodec::decode($bytes));
    }

    public function testControlFramesHaveTheirOwnLengths(): void
    {
        $bytes = hex2bin('090a040e3412103412010000040301000005');
        $frames = [
            ['type' => DgwCodec::PING], ['type' => DgwCodec::PONG], ['type' => DgwCodec::DEAUTH],
            ['type' => DgwCodec::END, 'stream_id' => 0x1234],
            ['type' => DgwCodec::END_REASON, 'stream_id' => 0x1234, 'reason' => 4],
            ['type' => DgwCodec::DRAIN, 'reason' => 5],
        ];
        self::assertSame($frames, DgwCodec::decode($bytes));
        self::assertSame($bytes, implode('', array_map(DgwCodec::encode(...), $frames)));
    }

    #[DataProvider('invalidFrames')]
    public function testRejectsTruncationInvalidLengthUnknownTypeAndMalformedJson(string $hex): void
    {
        $this->expectException(MessengerException::class);
        DgwCodec::decode(hex2bin($hex));
    }

    /** @return iterable<string,array{string}> */
    public static function invalidFrames(): iterable
    {
        yield 'missing stream ID' => ['0d01'];
        yield 'missing length' => ['0d010002'];
        yield 'truncated payload' => ['0d01000300000000'];
        yield 'data missing ack' => ['0d010001000000'];
        yield 'extended missing content type' => ['1101000200000000'];
        yield 'ack length zero' => ['0c0100000000'];
        yield 'bad drain length' => ['0302000000'];
        yield 'bad reason length' => ['1001000200000000'];
        yield 'invalid JSON' => ['0f01000100007b'];
        yield 'unknown type has no reliable skip boundary' => ['ff'];
    }

    public function testFrameAndMessageLimitsRejectBeforeDispatch(): void
    {
        try {
            DgwCodec::decode("\x09\x0a", 8, 1);
            self::fail('Expected frame-count limit.');
        } catch (MessengerException $error) {
            self::assertSame('dgw_limit', $error->reason);
        }
        $this->expectException(MessengerException::class);
        DgwCodec::decode(str_repeat("\x09", 9), 8);
    }

    public function testPreservesLargeJsonIdentifiersAsStrings(): void
    {
        $json = '{"code":200,"id":18446744073709551615}';
        $bytes = "\x0f\x01\x00" . chr(strlen($json)) . "\x00\x00" . $json;
        self::assertSame('18446744073709551615', DgwCodec::decode($bytes)[0]['parameters']['id']);
    }

    public function testRefusesAckOverflowInsteadOfChangingItsMeaning(): void
    {
        $this->expectException(MessengerException::class);
        DgwCodec::encode(['type' => DgwCodec::DATA, 'stream_id' => 0, 'ack_id' => 32768, 'payload' => '', 'requires_ack' => true]);
    }

    public function testStandaloneAckKeepsFullUint16WhileDataUsesHighBitAsFlag(): void
    {
        // Current reference AckFrame writes/reads uint16 without the DATA flag mask.
        $bytes=hex2bin('0c3412020000ffff');
        $frame=['type'=>DgwCodec::ACK,'stream_id'=>0x1234,'ack_id'=>65535];
        self::assertSame($bytes,DgwCodec::encode($frame));
        self::assertSame([$frame],DgwCodec::decode($bytes));
        $data=DgwCodec::decode(hex2bin('0d3412020000ffff'))[0];
        self::assertSame(32767,$data['ack_id']);self::assertTrue($data['requires_ack']);
    }
}
