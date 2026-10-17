<?php
declare(strict_types=1);

namespace MVO\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Protocol\Protobuf;

final class ProtobufTest extends TestCase
{
    public function testWireVectorPreservesRepeatedFieldsAndOrder(): void
    {
        $fields = [
            ['number' => 1, 'wire' => 0, 'value' => 150],
            ['number' => 2, 'wire' => 2, 'value' => 'testing'],
            ['number' => 1, 'wire' => 0, 'value' => 0],
        ];
        $wire = hex2bin('089601120774657374696e670800');

        self::assertSame($wire, Protobuf::encode($fields));
        self::assertSame($fields, Protobuf::decode($wire));
    }

    public function testFixedFieldsPreserveRawLittleEndianBytes(): void
    {
        $wire = hex2bin('0901020304050607081501020304');
        $fields = [
            ['number' => 1, 'wire' => 1, 'value' => hex2bin('0102030405060708')],
            ['number' => 2, 'wire' => 5, 'value' => hex2bin('01020304')],
        ];

        self::assertSame($fields, Protobuf::decode($wire));
        self::assertSame($wire, Protobuf::encode($fields));
    }

    #[DataProvider('malformedWireData')]
    public function testMalformedWireDataIsRejected(string $hex, string $exception): void
    {
        $this->expectException($exception);
        Protobuf::decode(hex2bin($hex));
    }

    public static function malformedWireData(): iterable
    {
        yield 'field zero' => ['00', \UnexpectedValueException::class];
        yield 'missing integer' => ['08', \UnexpectedValueException::class];
        yield 'truncated bytes' => ['1203ff', \UnexpectedValueException::class];
        yield 'unsupported group' => ['0b', \UnexpectedValueException::class];
        yield 'truncated fixed64' => ['090102', \UnexpectedValueException::class];
        yield 'truncated fixed32' => ['0d0102', \UnexpectedValueException::class];
        yield 'overflow integer' => ['0880808080808080808080', \OverflowException::class];
    }

    public function testSigned63BitMaximumIsSupported(): void
    {
        $fields = [['number' => 536870911, 'wire' => 0, 'value' => PHP_INT_MAX]];
        self::assertSame($fields, Protobuf::decode(Protobuf::encode($fields)));
    }

    public function testDecodedFieldCountLimitIsEnforced(): void
    {
        $this->expectException(\LengthException::class);
        Protobuf::decode(hex2bin('08010802'), maxFields: 1);
    }

    public function testDecodedByteLimitIsEnforcedBeforeParsing(): void
    {
        $this->expectException(\LengthException::class);
        Protobuf::decode(hex2bin('0801'), maxBytes: 1);
    }
}
