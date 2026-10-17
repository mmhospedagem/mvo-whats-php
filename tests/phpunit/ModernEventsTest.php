<?php
declare(strict_types=1);
namespace MVO\Tests;
use MVO\Message\ModernEvents;
use PHPUnit\Framework\TestCase;
final class ModernEventsTest extends TestCase
{
    public function testIncomingMusicAndAudioStickerAreStructured():void
    {
        $music=ModernEvents::normalize(['musicMessage'=>['embeddedMusic'=>['songId'=>'1'],'style'=>3]]);
        self::assertSame('music',$music['kind']);self::assertSame('lyrics',$music['music']['styleName']);
        $sticker=ModernEvents::normalize(['audioStickerMessage'=>['message'=>['stickerMessage'=>['directPath'=>'/s','audioMessage'=>['directPath'=>'/a']]]]]);
        self::assertSame('audio-sticker',$sticker['kind']);self::assertSame('/a',$sticker['audioSticker']['audio']['directPath']);
        self::assertArrayNotHasKey('audioMessage',$sticker['audioSticker']['sticker']);
    }
    public function testLocationCancellationHasTargetAndTimestamp():void
    {
        $event=ModernEvents::normalize(['requestLocationUpdateMessage'=>['key'=>['id'=>'P'],'updateType'=>1,'senderTimestampMs'=>10]]);
        self::assertSame('cancel',$event['locationRequest']['action']);self::assertSame('P',$event['target']['id']);self::assertSame(10,$event['locationRequest']['timestampMs']);
    }
    public function testThemeAndStatusMetadataAreSeparateFromRawProtocol():void
    {
        $theme=ModernEvents::normalize(['protocolMessage'=>['type'=>34,'chatThemeSetting'=>['animatedWallpaper'=>['animatedWallpaperId'=>'a']]]]);
        self::assertSame('animated',$theme['chatTheme']['wallpaperType']);self::assertSame('set',$theme['chatTheme']['action']);
        $status=ModernEvents::normalize(['questionMessage'=>['message'=>['extendedTextMessage'=>['text'=>'Question','contextInfo'=>['isQuestion'=>true,'statusAttributions'=>[['type'=>3]]]]]]]);
        self::assertTrue($status['statusContent']['question']);self::assertSame('status-question',$status['kind']);self::assertSame([['type'=>3]],$status['statusContent']['attributions']);
    }
    public function testAssociatedMusicAndAnswerAreExposedWithoutDiscardingIds():void
    {
        $result=ModernEvents::normalize(['imageMessage'=>['annotations'=>[['embeddedContent'=>['embeddedMusic'=>['songId'=>'1']]]]],'messageContextInfo'=>['messageAssociation'=>['associationType'=>16,'parentMessageKey'=>['id'=>'P']]]]);
        self::assertSame('1',$result['statusContent']['musicAnnotations'][0]['embeddedContent']['embeddedMusic']['songId']);self::assertSame('P',$result['association']['parent']['id']);
        $answer=ModernEvents::normalize(['statusQuestionAnswerMessage'=>['key'=>['id'=>'Q'],'text'=>'Resposta']]);
        self::assertSame('Resposta',$answer['statusContent']['answer']['text']);self::assertSame('Q',$answer['target']['id']);
    }
    public function testOrdinaryContentDoesNotInventMetadata():void
    {self::assertSame([],ModernEvents::normalize(['conversation'=>'Text']));}
    public function testAssociationMatchesSharedNormalizedContract():void
    {
        $message=['imageMessage'=>[],'messageContextInfo'=>['messageAssociation'=>['associationType'=>1,'parentMessageKey'=>['id'=>'P'],'messageIndex'=>2]]];
        self::assertSame(\MVO\Message\AdvancedEvents::normalize($message,'image',[])['association'],ModernEvents::normalize($message)['association']);
        self::assertSame('media_album',ModernEvents::normalize($message)['association']['type']);
    }
}
