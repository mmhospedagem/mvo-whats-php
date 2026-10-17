<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Message\{ContentBuilder,GroupStatusContent};
use MVO\Protocol\SchemaCodec;
use MVO\Service\StatusService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GroupStatusContentTest extends TestCase
{
    #[DataProvider('invalidInput')]
    public function testInvalidInputsAreRejectedBeforePublisherOrUpload(mixed $groups,mixed $content,array $options=[]):void
    {
        $calls=0;$status=new StatusService(static fn():never=>throw new \LogicException('No app state expected'),static fn():never=>throw new \LogicException('No broadcast expected'));
        $status->configureGroupPublishing(static function()use(&$calls):array{$calls++;return [];},static fn():array=>[]);
        try{$status->sendToGroups($groups,$content,$options);self::fail('Invalid Group Status accepted');}catch(\InvalidArgumentException){self::assertSame(0,$calls);}
    }
    public static function invalidInput():iterable
    {
        yield [[], 'text'];yield [['status@broadcast'],'text'];yield [['200@lid'],'text'];yield [['120:4@g.us'],'text'];yield [['one'=>'123@g.us'],'text'];yield [array_fill(0,51,'123@g.us'),'text'];yield [['123@g.us'], ''];
        yield [['123@g.us'],['type'=>'raw','message'=>['conversation'=>'x']]];yield [['123@g.us'],['type'=>'poll','name'=>'x']];yield [['123@g.us'],['type'=>'text','text'=>'x','ephemeralExpiration'=>86400]];
        yield [['123@g.us'],['type'=>'text','text'=>'x','linkPreview'=>['matchedText'=>'https://mvo.host']]];yield [['123@g.us'],['type'=>'image','media'=>['bytes'=>'x'],'musicAnnotation'=>[]]];yield [['123@g.us'],['type'=>'image','media'=>[]]];
        yield [['123@g.us'],'text',['isGroupStatus'=>true]];yield [['123@g.us'],'text',['audienceType'=>'CLOSE_FRIENDS']];yield [['123@g.us'],'text',['listName'=>'']];yield [['123@g.us'],'text',['listEmoji'=>str_repeat('a',65)]];
    }
    public function testV2EnvelopeKeepsMessageContextExternalAndAudienceDisplayDoesNotChangeTransport():void
    {
        $secret=str_repeat('S',32);$message=['extendedTextMessage'=>['text'=>'Olá'],'messageContextInfo'=>['messageSecret'=>$secret]];
        $wrapped=GroupStatusContent::wrap($message,['listName'=>'Equipe','listEmoji'=>'⭐']);
        self::assertSame($secret,$wrapped['messageContextInfo']['messageSecret']);self::assertArrayNotHasKey('messageContextInfo',$wrapped['groupStatusMessageV2']['message']);
        $built=ContentBuilder::build(['type'=>'raw','message'=>$wrapped],'123@g.us');self::assertSame('true',$built['metaAttrs']['is_group_status']);self::assertSame('text',$built['attrs']['type']);
        $decoded=SchemaCodec::decode('Message',$built['bytes']);self::assertSame(1,$decoded['groupStatusMessageV2']['message']['extendedTextMessage']['contextInfo']['statusAudienceMetadata']['audienceType']);
        self::assertSame(['123@g.us','456@g.us'],GroupStatusContent::normalizeGroups(['123@g.us','456@g.us','123@g.us']));
    }
    public function testAllMediaVariantsValidateAndVoiceRemainsPtt():void
    {
        foreach(['image'=>'image','video'=>'video','audio'=>'audio','voice'=>'ptt','ptt'=>'ptt']as$type=>$prepared){$content=GroupStatusContent::normalizeContent(['type'=>$type,'media'=>['bytes'=>'fixture']]);self::assertSame($prepared,$content['type']);}
        $voice=GroupStatusContent::normalizeContent(['type'=>'voice','media'=>['bytes'=>'voice'],'backgroundColor'=>'#0000ff','seconds'=>1]);self::assertSame(0xff0000ff,$voice['backgroundArgb']);
        self::assertSame($voice,GroupStatusContent::normalizeContent($voice));
    }
}
