<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Protocol\SchemaCodec;
use PHPUnit\Framework\TestCase;

final class SchemaOneofTest extends TestCase
{
    private mixed $previousSchema;

    protected function setUp(): void
    {
        $property = new \ReflectionProperty(SchemaCodec::class, 'schema');
        $this->previousSchema = $property->getValue();
        $field = static fn(int $id, string $type, ?string $oneof = null, bool $repeated = false): array =>
            ['id'=>$id,'type'=>$type,'repeated'=>$repeated,'packed'=>false,...($oneof === null ? [] : ['oneof'=>$oneof])];
        $property->setValue(null, ['messages'=>[
            'Envelope'=>['text'=>$field(1,'string','content'),'child'=>$field(2,'Child','content'),'nested'=>$field(3,'Nested')],
            'Child'=>['label'=>$field(1,'string','value'),'number'=>$field(2,'int32','value'),'items'=>$field(3,'string',null,true)],
            'Nested'=>['child'=>$field(1,'Child','choice'),'other'=>$field(2,'bool','choice')],
            'MapContainer'=>['values'=>[...$field(1,'Child'),'mapKey'=>'string']],
            'Message.ChatSolidColorWallpaper'=>['color'=>$field(1,'fixed32'),'darkColor'=>$field(2,'fixed32')],
        ],'enums'=>[],'legacyWireTypes'=>['Message.ChatSolidColorWallpaper'=>[1=>[2],2=>[2]]]]);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(SchemaCodec::class, 'schema'))->setValue(null, $this->previousSchema);
    }

    public function testWireOrderSelectsTheLastScalarOrMessageMember(): void
    {
        // text="old", child={number=0}: the explicitly present default selects child.
        self::assertSame(['child'=>['number'=>0]], SchemaCodec::decode('Envelope', hex2bin('0a036f6c6412021000')));
        self::assertSame(['text'=>'new'], SchemaCodec::decode('Envelope', hex2bin('120210000a036e6577')));
        // Switching away and back must not resurrect the old child's contents.
        self::assertSame(['child'=>['number'=>7]], SchemaCodec::decode('Envelope', hex2bin('12030a01610a017812021007')));
    }

    public function testRepeatedOccurrencesOfTheSameMessageMemberMerge(): void
    {
        // child={label="a",items=["x"]}, then child={items=["y"]}.
        self::assertSame(['child'=>['label'=>'a','items'=>['x','y']]],
            SchemaCodec::decode('Envelope', hex2bin('12060a01611a017812031a0179')));
    }

    public function testOneofSelectionIsAppliedAcrossMergedEmbeddedMessages(): void
    {
        // nested={child={label="a",items=["x"]}}, then nested={child={number=7,items=["y"]}}.
        self::assertSame(['nested'=>['child'=>['items'=>['x','y'],'number'=>7]]],
            SchemaCodec::decode('Envelope', hex2bin('1a080a060a01611a01781a070a0510071a0179')));
    }

    public function testIntermediateOneofSelectionInMergedMessageCannotResurrectClearedData(): void
    {
        // nested.child.label="a"; then nested.other=true, nested.child.number=7.
        self::assertSame(['nested'=>['child'=>['number'=>7]]],
            SchemaCodec::decode('Envelope', hex2bin('1a050a030a01611a0610010a021007')));
        // If there is no switch, the singular child must continue merging.
        self::assertSame(['nested'=>['child'=>['label'=>'a','items'=>['y']]]],
            SchemaCodec::decode('Envelope', hex2bin('1a050a030a01611a050a031a0179')));
    }

    public function testDuplicateMapKeysReplaceWholeValuesAndKeepUnknownFieldsOfTheSelectedValue(): void
    {
        // Two independent map entries for "k"; last value replaces the first.
        $wire = hex2bin('0a080a016b12030a01610a0b0a016b120610079a060178');
        self::assertSame(['values'=>['k'=>['number'=>7,'_unknown'=>[hex2bin('9a060178')]]]],
            SchemaCodec::decode('MapContainer', $wire));
    }

    public function testUnknownFieldsSurviveWhenKnownOneofSelectionChanges(): void
    {
        $decoded = SchemaCodec::decode('Envelope', hex2bin('0a01789a060366757412021007'));
        self::assertSame(['_unknown'=>[hex2bin('9a0603667574')],'child'=>['number'=>7]], $decoded);
        self::assertSame('120210079a0603667574', bin2hex(SchemaCodec::encode('Envelope', $decoded)));
    }

    public function testEncodeRejectsAmbiguousSelectionsEvenWhenOneValueIsDefault(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Multiple values for WAProto oneof Child.value');
        SchemaCodec::encode('Child', ['label'=>'','number'=>0]);
    }

    public function testNullMembersDoNotCountAsSelectedAndWireMatchesIndependentFixture(): void
    {
        self::assertSame('12021000', bin2hex(SchemaCodec::encode('Envelope', ['text'=>null,'child'=>['number'=>0]])));
        self::assertSame('0a00', bin2hex(SchemaCodec::encode('Envelope', ['text'=>''])));
    }

    public function testDeclaredHistoricalWallpaperStringsRemainRawWithoutColorConversion(): void
    {
        // Historical string fields color="a", darkColor="b" use wire 2.
        $wire = hex2bin('0a0161120162');
        $decoded = SchemaCodec::decode('Message.ChatSolidColorWallpaper', $wire);
        self::assertSame(['_unknown'=>[hex2bin('0a0161'),hex2bin('120162')]], $decoded);
        self::assertSame($wire, SchemaCodec::encode('Message.ChatSolidColorWallpaper', $decoded));
        // Current fixed32 representation is little endian and remains typed.
        $current = hex2bin('0d4433221115ddccbbaa');
        self::assertSame(['color'=>0x11223344,'darkColor'=>0xaabbccdd],
            SchemaCodec::decode('Message.ChatSolidColorWallpaper', $current));
        self::assertSame($current, SchemaCodec::encode('Message.ChatSolidColorWallpaper', ['color'=>0x11223344,'darkColor'=>0xaabbccdd]));
    }

    public function testUnlistedWrongWireTypeRemainsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        SchemaCodec::decode('Message.ChatSolidColorWallpaper', hex2bin('0801')); // wire 0 was never declared.
    }

    public function testLegacyExceptionDoesNotRelaxAnotherMessage(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        SchemaCodec::decode('Child', hex2bin('120178')); // number is int32, not length-delimited.
    }
}
