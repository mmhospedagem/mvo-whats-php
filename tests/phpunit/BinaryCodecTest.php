<?php
declare(strict_types=1);

namespace MVO\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Protocol\BinaryCodec;
use MVO\Protocol\BinaryNode;

final class BinaryCodecTest extends TestCase
{
    public function testFixedWapWireVector(): void
    {
        $codec = new BinaryCodec();
        $node = new BinaryNode('iq', ['type' => 'get']);
        $wire = hex2bin('f803190429');

        self::assertSame($wire, $codec->encode($node));
        self::assertEquals($node, $codec->decode($wire));
    }

    public function testEveryDictionaryTokenDecodesWithItsOriginalSpelling(): void
    {
        $path = dirname(__DIR__, 2).'/resources/tokens.json';
        $tables = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $codec = new BinaryCodec();

        foreach ($tables['single'] as $index => $word) {
            self::assertSame($word, $codec->decode("\xf8\x01".chr($index + 1))->tag);
        }
        foreach ($tables['dictionaries'] as $dictionary => $words) {
            foreach ($words as $index => $word) {
                self::assertSame($word, $codec->decode("\xf8\x01".chr(236 + $dictionary).chr($index))->tag);
            }
        }
    }

    #[DataProvider('attributeValues')]
    public function testPackedStringsAndJidEncodingsPreserveValues(string $value): void
    {
        $codec = new BinaryCodec();
        $node = new BinaryNode('message', ['to' => $value], "\0\xffpayload");
        self::assertEquals($node, $codec->decode($codec->encode($node)));
    }

    public static function attributeValues(): iterable
    {
        foreach (['12345', '12-34.', 'ABCDEF', 'custom', '123:255@hosted', '123:256@lid', '123@s.whatsapp.net'] as $value) {
            yield $value => [$value];
        }
    }

    #[DataProvider('binaryPayloadBoundaries')]
    public function testBinaryPayloadLengthBoundaries(int $size): void
    {
        $codec = new BinaryCodec();
        $node = new BinaryNode('data', [], str_repeat('z', $size));
        self::assertEquals($node, $codec->decode($codec->encode($node)));
    }

    public static function binaryPayloadBoundaries(): iterable
    {
        foreach ([0, 1, 255, 256, 1048575, 1048576] as $size) {
            yield (string) $size => [$size];
        }
    }

    public function testChildListBeyondOneByteLengthPreservesAllChildren(): void
    {
        $codec = new BinaryCodec();
        $children = [];
        for ($index = 0; $index < 256; $index++) {
            $children[] = new BinaryNode('item', ['id' => (string) $index]);
        }
        $node = new BinaryNode('list', [], $children);
        self::assertEquals($node, $codec->decode($codec->encode($node)));
    }

    public function testStanzasAcceptZlibAndRawDeflateCompression(): void
    {
        $codec = new BinaryCodec();
        $node = new BinaryNode('iq', ['type' => 'get']);
        $wire = hex2bin('f803190429');

        self::assertSame("\0".$wire, $codec->encode($node, stanza: true));
        self::assertEquals($node, $codec->decode("\0".$wire, stanza: true));
        self::assertEquals($node, $codec->decode("\2".gzcompress($wire), stanza: true));
        self::assertEquals($node, $codec->decode("\2".gzdeflate($wire), stanza: true));
    }

    #[DataProvider('malformedNodes')]
    public function testMalformedNodeIsRejectedAndCodecRemainsUsable(string $hex): void
    {
        $codec = new BinaryCodec();
        try {
            $codec->decode(hex2bin($hex));
            self::fail('Malformed binary node was accepted.');
        } catch (\UnexpectedValueException) {
            self::assertSame('iq', $codec->decode(hex2bin('f80119'))->tag);
        }
    }

    public static function malformedNodes(): iterable
    {
        yield 'empty' => [''];
        yield 'truncated list header' => ['f8'];
        yield 'truncated token' => ['f801'];
        yield 'truncated dictionary token' => ['f801ec'];
        yield 'truncated raw bytes' => ['f801fc0361'];
        yield 'unsupported token' => ['f801f0'];
        yield 'trailing bytes' => ['f8011900'];
        yield 'invalid packed padding' => ['f801ff811e'];
        yield 'invalid packed length' => ['f801ff80'];
        yield 'duplicate attribute' => ['f8051904290429'];
    }

    public function testUnsupportedStanzaFlagsAreRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new BinaryCodec())->decode("\4".hex2bin('f80119'), stanza: true);
    }

    public function testInvalidCompressedStanzaIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new BinaryCodec())->decode("\2invalid", stanza: true);
    }

    public function testNodeDepthLimitIsEnforced(): void
    {
        $node = new BinaryNode('iq', [], [new BinaryNode('iq')]);
        $wire = (new BinaryCodec())->encode($node);

        $this->expectException(\LengthException::class);
        (new BinaryCodec(maxDepth: 1))->decode($wire);
    }

    public function testDecompressionSizeLimitIsEnforced(): void
    {
        $node = new BinaryNode('data', [], str_repeat('a', 4096));
        $compressed = "\2".gzcompress((new BinaryCodec())->encode($node));
        self::assertLessThan(128, strlen($compressed));

        $this->expectException(\UnexpectedValueException::class);
        (new BinaryCodec(maxBytes: 128))->decode($compressed, stanza: true);
    }
}
