<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use MVO\Protocol\SchemaCodec;
use MVO\Message\{ContentBuilder,ContentDecoder};

final class SchemaCodecTest extends TestCase
{
    public function testUint64SignedInt64AndFixedTypesMatchWireFixtures(): void
    {
        $max = '18446744073709551615';
        self::assertSame('28ffffffffffffffffff01',bin2hex(SchemaCodec::encode('Message.DocumentMessage',['fileLength'=>$max])));
        self::assertSame($max,SchemaCodec::decode('Message.DocumentMessage',hex2bin('28ffffffffffffffffff01'))['fileLength']);
        self::assertSame('20ffffffffffffffffff01',bin2hex(SchemaCodec::encode('Message.ReactionMessage',['senderTimestampMs'=>-1])));
        self::assertSame(-1,SchemaCodec::decode('Message.ReactionMessage',hex2bin('20ffffffffffffffffff01'))['senderTimestampMs']);
        self::assertSame('20' . '80808080808080808001',bin2hex(SchemaCodec::encode('Message.ReactionMessage',['senderTimestampMs'=>PHP_INT_MIN])));
        self::assertSame(PHP_INT_MIN,SchemaCodec::decode('Message.ReactionMessage',hex2bin('2080808080808080808001'))['senderTimestampMs']);
        self::assertSame('11ffffffffffffffff',bin2hex(SchemaCodec::encode('EphemeralSetting',['timestamp'=>-1])));
        self::assertSame(-1,SchemaCodec::decode('EphemeralSetting',hex2bin('11ffffffffffffffff'))['timestamp']);
        self::assertSame('4d00000080',bin2hex(SchemaCodec::encode('ClientPayload',['sessionId'=>-2147483648])));
        self::assertSame(-2147483648,SchemaCodec::decode('ClientPayload',hex2bin('4d00000080'))['sessionId']);
    }

    public function testCoordinatesEncodeAsLittleEndianDoubles(): void
    {
        $wire = hex2bin('0900000000008036c01100000000008047c0'); // -22.5, -47
        self::assertSame($wire,SchemaCodec::encode('Message.LocationMessage',['degreesLatitude'=>-22.5,'degreesLongitude'=>-47.0]));
        self::assertSame(['degreesLatitude'=>-22.5,'degreesLongitude'=>-47.0],SchemaCodec::decode('Message.LocationMessage',$wire));
    }

    public function testPackedAndUnpackedIntegerRepresentationsAreAccepted(): void
    {
        self::assertSame('220401960103',bin2hex(SchemaCodec::encode('ADVKeyIndexList',['validIndexes'=>[1,150,3]])));
        self::assertSame([1,150,3],SchemaCodec::decode('ADVKeyIndexList',hex2bin('220401960103'))['validIndexes']);
        self::assertSame([1,150,3],SchemaCodec::decode('ADVKeyIndexList',hex2bin('20012096012003'))['validIndexes']);
    }

    public function testUnknownFieldsAndEnumValuesSurviveDecodeAndReencode(): void
    {
        $wire = hex2bin('10639a0603667574'); // protocol type=99, unknown field99='fut'
        $data = SchemaCodec::decode('Message.ProtocolMessage',$wire);
        self::assertSame(99,$data['type']);
        self::assertSame([hex2bin('9a0603667574')],$data['_unknown']);
        self::assertSame($wire,SchemaCodec::encode('Message.ProtocolMessage',$data));
    }

    public function testMapEntriesRetainBinaryValues(): void
    {
        $data = ['field'=>[12=>['minVersion'=>3,'subfield'=>[4=>['isMessage'=>true]]]]];
        self::assertSame($data,SchemaCodec::decode('Config',SchemaCodec::encode('Config',$data)));
    }

    public function testDuplicateEmbeddedMessagesMergeRepeatedFieldsInsteadOfReplacingThem(): void
    {
        $first = SchemaCodec::encode('Message',['extendedTextMessage'=>['text'=>'hello','contextInfo'=>['mentionedJid'=>['1@lid']]]]);
        $next = SchemaCodec::encode('Message',['extendedTextMessage'=>['contextInfo'=>['mentionedJid'=>['2@lid']]]]);
        $decoded = SchemaCodec::decode('Message',$first.$next);
        self::assertSame('hello',$decoded['extendedTextMessage']['text']);
        self::assertSame(['1@lid','2@lid'],$decoded['extendedTextMessage']['contextInfo']['mentionedJid']);
    }

    public static function invalidValues(): iterable
    {
        yield 'uint64 overflow'=>['Message.DocumentMessage',['fileLength'=>'18446744073709551616']];
        yield 'uint64 negative'=>['Message.DocumentMessage',['fileLength'=>-1]];
        yield 'int64 overflow'=>['Message.ReactionMessage',['senderTimestampMs'=>'9223372036854775808']];
        yield 'uint32 overflow'=>['Message.DocumentMessage',['pageCount'=>4294967296]];
        yield 'invalid enum name'=>['Message.ProtocolMessage',['type'=>'NOT_REAL']];
        yield 'invalid UTF8'=>['Message',['conversation'=>"\xff"]];
        yield 'bool strict'=>['MessageKey',['fromMe'=>1]];
        yield 'unknown field'=>['Message',['madeUpMessage'=>[]]];
        yield 'unknown nested field'=>['Message',['imageMessage'=>['madeUp'=>1]]];
        yield 'repetition strict'=>['Message.PollVoteMessage',['selectedOptions'=>'bytes']];
        yield 'nonfinite location'=>['Message.LocationMessage',['degreesLatitude'=>INF]];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRejected(string $type, array $data): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SchemaCodec::encode($type,$data);
    }

    public static function malformedWire(): iterable
    {
        yield 'uint64 overflow'=>['28ffffffffffffffffff02'];
        yield 'truncated varint'=>['2880'];
        yield 'truncated nested'=>['0a056162'];
        yield 'invalid field number'=>['0000'];
        yield 'wrong wire'=>['0d00000000'];
        yield 'unsupported groups'=>['0b'];
    }

    #[DataProvider('malformedWire')]
    public function testMalformedFieldsAreRejected(string $hex): void
    {
        $this->expectException(\Throwable::class);
        SchemaCodec::decode('Message',hex2bin($hex));
    }

    public function testEverySchemaMessageCanBeEncodedAndDecodedWithoutASeparateRuntime(): void
    {
        self::assertCount(771,SchemaCodec::messageTypes());
        foreach (SchemaCodec::messageTypes() as $type) {
            self::assertSame([],SchemaCodec::decode($type,SchemaCodec::encode($type,[])),$type);
        }
    }

    public function testEveryTopLevelContentFieldIsAvailableThroughRawProto(): void
    {
        foreach (SchemaCodec::fields('Message') as $field => $descriptor) {
            if ($field === 'conversation') $data = 'hello';
            elseif (in_array($descriptor['type'],['Message.FutureProofMessage','Message.DeviceSentMessage'],true)) $data = ['message'=>['conversation'=>'wrapped']];
            else $data = [];
            $built = ContentBuilder::build(['type'=>'raw','message'=>[$field=>$data]],'123@s.whatsapp.net');
            $decoded = ContentDecoder::decode($built['bytes']);
            self::assertArrayHasKey($field,$decoded['proto'],$field);
            self::assertSame($data,$decoded['proto'][$field],$field.' raw value must survive without being converted to text or dropped');
            self::assertNotSame('unsupported',$decoded['kind'],$field);
        }
    }

    public function testFutureUnknownTopLevelContentIsPreservedForStructuredEvents(): void
    {
        $raw = hex2bin('a21f03667574'); // future Message field 500='fut'
        $built = ContentBuilder::build(['type'=>'raw','message'=>['_unknown'=>[$raw]]],'123@s.whatsapp.net');
        self::assertSame($raw,$built['bytes']);
        $decoded = ContentDecoder::decode($raw);
        self::assertSame([500],$decoded['fields']);
        self::assertSame(['_unknown'=>[$raw]],$decoded['proto']);
        self::assertSame([$raw],$decoded['data']);
    }
}
