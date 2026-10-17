<?php
declare(strict_types=1);

namespace MVO\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Transport\FrameCodec;

final class FrameCodecTest extends TestCase
{
    public function testCoalescedFramesAndBytewiseFragmentsProduceTheSamePayloads(): void
    {
        $wire = hex2bin('00000361626300000000000378797a');
        $expected = ['abc', '', 'xyz'];
        $decoder = new FrameCodec();
        $actual = [];
        foreach (str_split($wire) as $byte) {
            array_push($actual, ...$decoder->push($byte));
        }

        self::assertSame($expected, $actual);
        $decoder->finish();
        self::assertSame($expected, (new FrameCodec())->push($wire));
    }

    public function testOutgoingProtocolIntroIsSentOnlyOnce(): void
    {
        $codec = new FrameCodec(FrameCodec::HEADER);
        self::assertSame("WA\x06\x03\0\0\1a", $codec->encode('a'));
        self::assertSame("\0\0\1b", $codec->encode('b'));
    }

    public function testIncompletePayloadWaitsForMoreInput(): void
    {
        $codec = new FrameCodec();
        self::assertSame([], $codec->push("\0\0\3ab"));
        self::assertSame(['abc'], $codec->push('c'));
        $codec->finish();
    }

    #[DataProvider('truncatedFrames')]
    public function testFinishDetectsPartialHeaderOrPayload(string $bytes): void
    {
        $codec = new FrameCodec();
        self::assertSame([], $codec->push($bytes));
        $this->expectException(\UnexpectedValueException::class);
        $codec->finish();
    }

    public static function truncatedFrames(): iterable
    {
        yield 'one header byte' => ["\0"];
        yield 'two header bytes' => ["\0\0"];
        yield 'partial payload' => ["\0\0\3ab"];
    }

    public function testOversizedIncomingFrameIsRejectedAtHeader(): void
    {
        $this->expectException(\LengthException::class);
        (new FrameCodec(maxLength: 2))->push("\0\0\3");
    }

    public function testOversizedOutgoingFrameIsRejected(): void
    {
        $this->expectException(\LengthException::class);
        (new FrameCodec(maxLength: 2))->encode('abc');
    }
}
