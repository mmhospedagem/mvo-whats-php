<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Message\{ContentBuilder, ModernContent};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MusicCardValidationTest extends TestCase
{
    /** Synthetic references for offline protocol tests only. */
    private static function card():array
    {
        return ['type'=>'music','embeddedMusic'=>[
            'musicContentMediaId'=>'90000001','songId'=>'90000002','title'=>'Fixture','author'=>'Fixture Artist',
        ],'songUri'=>'https://music.example.whatsapp.net/audio?token=signed%2Fvalue'];
    }

    public function testCompleteCardPreservesSignedReferenceAndDefaultsToVinyl():void
    {
        $input=self::card();$input['artworkUri']='https://art.example.fbcdn.net/cover?signature=abc%2Fdef';
        $built=ContentBuilder::build($input,'222@s.whatsapp.net');$body=$built['proto']['musicMessage'];
        self::assertSame($input['embeddedMusic'],$body['embeddedMusic']);
        self::assertSame($input['songUri'],$body['songUri']);
        self::assertSame($input['artworkUri'],$body['artworkUri']);
        self::assertSame(1,$body['style']);
        self::assertSame(['mediatype'=>'music'],$built['encAttrs']);
        self::assertSame('media',$built['attrs']['type']);
    }

    public static function invalidCards():iterable
    {
        foreach(['songId','musicContentMediaId','author','title']as$field){
            $input=self::card();unset($input['embeddedMusic'][$field]);yield 'missing '.$field=>[$input];
            $input=self::card();$input['embeddedMusic'][$field]=' ';yield 'empty '.$field=>[$input];
        }
        foreach(['TRACK_ID','SONG_ID','MUSIC_CONTENT_MEDIA_ID','string','<ID_REAL>','id with space']as$placeholder){
            $input=self::card();$input['embeddedMusic']['songId']=$placeholder;yield 'placeholder '.$placeholder=>[$input];
        }
        $input=self::card();unset($input['songUri']);yield 'missing audio reference'=>[$input];
        foreach(['UNKNOWN','MESSAGE_BUBBLE','LYRICS','ROUND',0,2,3]as$style){
            $input=self::card();$input['style']=$style;yield 'unsupported style '.(string)$style=>[$input];
        }
        foreach([
            'https://example.com/song','http://music.whatsapp.net/song','file:///song','javascript:alert(1)',
            'https://fbcdn.net/song','https://fakefbcdn.net/song','https://cdn.fbcdn.net.example.com/song',
            'https://user:password@music.whatsapp.net/song','https://music.whatsapp.net:444/song',
            'https://music.whatsapp.net/song#fragment',"https://music.whatsapp.net/song\n",
        ]as$uri){
            $input=self::card();$input['songUri']=$uri;yield 'invalid song '.$uri=>[$input];
            $input=self::card();$input['artworkUri']=$uri;yield 'invalid artwork '.$uri=>[$input];
        }
    }

    #[DataProvider('invalidCards')]
    public function testIncompleteOrUnsupportedReferencesFailLocally(array $content):void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModernContent::validateMusicCard($content);
    }

    public function testStatusMetadataRemainsIndependentOfStandaloneCardRequirements():void
    {
        $status=ModernContent::decorateStatus(['extendedTextMessage'=>['text'=>'Status']],['musicMetadata'=>['songId'=>'1']]);
        self::assertSame(['songId'=>'1'],$status['extendedTextMessage']['musicMetadata']);
    }
}
