<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Message\ModernContent;
use MVO\Message\ContentBuilder;
use MVO\Protocol\SchemaCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModernContentTest extends TestCase
{
    public function testMusicMatchesLiteralWireFixtureAndHasTypedMetadata():void
    {
        $message=ModernContent::build(['type'=>'music','embeddedMusic'=>['musicContentMediaId'=>'content','songId'=>'song','author'=>'art','title'=>'Title'],
            'songUri'=>'https://music.whatsapp.net/song','style'=>'VINYL'],'u',[],1);
        // Literal protobuf fixture: musicMessage129, EmbeddedMusic1..4, songUri2, style4.
        self::assertSame('8a08400a1b0a07636f6e74656e741204736f6e671a0361727422055469746c65121f68747470733a2f2f6d757369632e77686174736170702e6e65742f736f6e672001',bin2hex(SchemaCodec::encode('Message',$message)));
        self::assertSame('song',$message['musicMessage']['embeddedMusic']['songId']);
    }
    public function testLocationRequestAndCancellationUseTheirOwnNativeFields():void
    {
        $request=ModernContent::build(['type'=>'request-location','format'=>'protocol'],'u',[],7);
        self::assertSame('c20800',bin2hex(SchemaCodec::encode('Message',$request)));
        $cancel=ModernContent::build(['type'=>'cancel-location-request','target'=>['id'=>'I']],'u',[],7);
        self::assertSame('d2080e0a080a017510001a014910011807',bin2hex(SchemaCodec::encode('Message',$cancel)));
    }
    public function testLocationRequestUsesTheAndroidEncryptedMediaDiscriminator():void
    {
        // Android AqP.A06 maps Message bitField3_ & 32768 to request_location.
        // SendE2EMessageJob keeps type=media and passes this discriminator to enc.
        foreach(['request-location','location-request']as$type){
            $built=ContentBuilder::build(['type'=>$type,'format'=>'protocol'],'5511999999999@s.whatsapp.net');
            self::assertSame('media',$built['attrs']['type']);
            self::assertSame(['mediatype'=>'request_location'],$built['encAttrs']);
            self::assertSame('c20800',bin2hex(SchemaCodec::encode('Message',$built['proto'])));
        }
        $wrapped=['viewOnceMessage'=>['message'=>['requestLocationMessage'=>[]]]];
        self::assertSame(['attrs'=>['type'=>'media'],'encAttrs'=>['mediatype'=>'request_location'],'metaAttrs'=>[]],ModernContent::attributes($wrapped));
    }
    public function testLocationRequestDefaultsToTheExistingAndroidAction():void
    {
        foreach(['request-location','location-request']as$type){
            $built=ContentBuilder::build(['type'=>$type],'5511999999999@s.whatsapp.net');
            $flow=$built['proto']['interactiveMessage']['nativeFlowMessage'];
            self::assertSame([['name'=>'send_location','buttonParamsJson'=>'{}']],$flow['buttons']);
            self::assertSame(1,$flow['messageVersion']);
            self::assertNotEmpty($built['proto']['interactiveMessage']['body']['text']);
            self::assertSame('text',$built['attrs']['type']);
            self::assertArrayNotHasKey('mediatype',$built['encAttrs']);
            self::assertArrayNotHasKey('requestLocationMessage',$built['proto']);
            $biz=$built['companionNodes'][0];
            self::assertNull($biz->child('biz'));
            self::assertSame('location_request_message',$biz->child('interactive')?->child('native_flow')?->attrs['name']);
            self::assertArrayNotHasKey('v',$biz->child('interactive')?->child('native_flow')?->attrs??[]);
        }
    }
    public function testLocationRequestTextIsAnExplicitManualInstruction():void
    {
        $text='Abra a conversa no celular e compartilhe sua localização.';
        $interactive=ContentBuilder::build(['type'=>'request-location','format'=>'interactive','text'=>$text],'u');
        self::assertSame($text,$interactive['proto']['interactiveMessage']['body']['text']);
        $plain=ContentBuilder::build(['type'=>'request-location','format'=>'text','text'=>$text],'u');
        self::assertSame(['extendedTextMessage'=>['text'=>$text]],$plain['proto']);
        self::assertSame('text',$plain['attrs']['type']);
        self::assertSame([],$plain['companionNodes']);
    }
    public function testAudioStickerKeepsBothIndependentEncryptedAssets():void
    {
        $asset=['directPath'=>'/asset','fileSha256'=>str_repeat('h',32),'fileEncSha256'=>str_repeat('e',32),'mediaKey'=>str_repeat('k',32),'fileLength'=>32];
        $message=ModernContent::build(['type'=>'audio-sticker','preparedSticker'=>$asset+['mimetype'=>'image/webp'],'preparedAudio'=>$asset+['mimetype'=>'audio/ogg','seconds'=>3]],'u',[],1);
        $decoded=SchemaCodec::decode('Message',SchemaCodec::encode('Message',$message));
        self::assertSame('image/webp',$decoded['stickerMessage']['mimetype']);
        self::assertSame('audio/ogg',$decoded['stickerMessage']['audioMessage']['mimetype']);
        self::assertSame(str_repeat('k',32),$decoded['stickerMessage']['audioMessage']['mediaKey']);
        self::assertArrayNotHasKey('audioStickerMessage',$decoded);
    }
    public function testWallpaperUsesCurrentNumericArgbFieldsAndOneof():void
    {
        $message=ModernContent::build(['type'=>'chat-theme','theme'=>['wallpaper'=>['type'=>'solid-color','backgroundLightArgb'=>0xff010203,'backgroundDarkArgb'=>0xff040506]]],'u',[],5);
        self::assertSame('CHAT_THEME_SETTING',$message['protocolMessage']['type']);
        self::assertSame(5,$message['protocolMessage']['chatThemeSetting']['settingTimestampMs']);
        self::assertSame('0d030201ff15060504ff',bin2hex(SchemaCodec::encode('Message.ChatSolidColorWallpaper',$message['protocolMessage']['chatThemeSetting']['solidColor'])));
        $animated=ModernContent::theme(['wallpaper'=>['type'=>'animated','animatedWallpaperId'=>'a','dimLevel'=>0.5]],1);
        self::assertSame(['animatedWallpaperId'=>'a','dimLevel'=>0.5],$animated['animatedWallpaper']);
    }
    public function testStatusPreviewMetadataIsSiblingAndPreservesTextAndContext():void
    {
        $message=ModernContent::decorateStatus(['extendedTextMessage'=>['text'=>'Veja','contextInfo'=>['mentionedJid'=>['u@lid']]]],
            ['linkPreview'=>['matchedText'=>'https://example.com','title'=>'Example'],'linkPreviewStyle'=>'IMMERSIVE','musicMetadata'=>['songId'=>'1','title'=>'Song']]);
        self::assertSame(['style'=>'IMMERSIVE'],$message['statusLinkPreviewMetadata']);
        self::assertSame('Veja',$message['extendedTextMessage']['text']);
        self::assertSame(['u@lid'],$message['extendedTextMessage']['contextInfo']['mentionedJid']);
        self::assertSame('1',$message['extendedTextMessage']['musicMetadata']['songId']);
    }
    public function testImageMusicAnnotationAndAssociationsPreserveOriginalAsset():void
    {
        $body=['directPath'=>'/image','fileSha256'=>str_repeat('h',32),'mediaKey'=>str_repeat('k',32)];
        $message=ModernContent::decorateStatus(['imageMessage'=>$body],[
            'musicAnnotation'=>['embeddedMusic'=>['songId'=>'1','title'=>'Song']],
            'association'=>['associationType'=>'STATUS_QUESTION','parentMessageKey'=>['id'=>'P','remoteJid'=>'status@broadcast']]]);
        self::assertSame('/image',$message['imageMessage']['directPath']);
        self::assertSame(['songId'=>'1','title'=>'Song'],$message['imageMessage']['annotations'][0]['embeddedContent']['embeddedMusic']);
        self::assertTrue($message['imageMessage']['annotations'][0]['embeddedAction']);
        self::assertSame('P',$message['messageContextInfo']['messageAssociation']['parentMessageKey']['id']);
    }
    public function testQuestionAnswerAndShareUseNativeEnvelopeAndAttribution():void
    {
        $question=ModernContent::build(['type'=>'status-question','text'=>'Como foi?'],'status@broadcast',[],1);
        self::assertTrue($question['questionMessage']['message']['extendedTextMessage']['contextInfo']['isQuestion']);
        $answer=ModernContent::build(['type'=>'status-answer','target'=>['id'=>'Q'],'text'=>'Ótimo'],'u@lid',[],1);
        self::assertSame('Q',$answer['statusQuestionAnswerMessage']['key']['id']);
        $share=ModernContent::build(['type'=>'status-share','message'=>['extendedTextMessage'=>['text'=>'Original']],'source'=>'CHANNEL_RESHARE','metadata'=>['channelJid'=>'1@newsletter','channelMessageId'=>2]],'status@broadcast',[],1);
        self::assertSame('Original',$share['extendedTextMessage']['text']);
        self::assertSame('RESHARED_FROM_POST',$share['extendedTextMessage']['contextInfo']['statusAttributionType']);
        self::assertSame('CHANNEL_RESHARE',$share['extendedTextMessage']['contextInfo']['statusAttributions'][0]['statusReshare']['source']);
    }
    public static function invalidContent():iterable
    {
        $music=['type'=>'music','embeddedMusic'=>['musicContentMediaId'=>'90000001','songId'=>'90000002','title'=>'Fixture','author'=>'Fixture Artist'],
            'songUri'=>'https://music.example.whatsapp.net/song'];
        yield 'music missing identity'=>[['type'=>'music','embeddedMusic'=>['title'=>'Song']]];
        yield 'invalid music style'=>[[...$music,'style'=>'ROUND']];
        yield 'music key wrong size'=>[[...$music,'embeddedMusic'=>[...$music['embeddedMusic'],'artworkMediaKey'=>'bad']]];
        yield 'cancel without key'=>[['type'=>'cancel-location-request']];
        yield 'invalid location request format'=>[['type'=>'request-location','format'=>'unknown']];
        yield 'empty location request text'=>[['type'=>'request-location','text'=>'']];
        yield 'location protocol cannot include text'=>[['type'=>'request-location','format'=>'protocol','text'=>'Ignored text']];
        yield 'audio sticker without prepared asset'=>[['type'=>'audio-sticker','media'=>'file.webp']];
        yield 'conflicting wallpaper'=>[['type'=>'chat-theme','theme'=>['defaultWallpaper'=>[],'animatedWallpaper'=>['animatedWallpaperId'=>'a']]]];
        yield 'empty theme'=>[['type'=>'chat-theme','theme'=>[]]];
        yield 'clear with wallpaper'=>[['type'=>'chat-theme','theme'=>['clearTheme'=>true,'defaultWallpaper'=>[]]]];
        yield 'legacy wallpaper fields'=>[['type'=>'chat-theme','theme'=>['wallpaper'=>['type'=>'solid-color','colorLight'=>'blue']]]];
        yield 'custom wallpaper missing keys'=>[['type'=>'chat-theme','theme'=>['wallpaper'=>['type'=>'custom-image','directPath'=>'/image']]]];
        yield 'negative music time'=>[[...$music,'embeddedMusic'=>[...$music['embeddedMusic'],'overlapDurationInMs'=>-1]]];
        yield 'invalid uri text'=>[[...$music,'songUri'=>"\xff"]];
    }
    #[DataProvider('invalidContent')]
    public function testInvalidInputsAreRejectedBeforeTransport(array $content):void
    { $this->expectException(\InvalidArgumentException::class);ModernContent::build($content,'u',[],1); }
    public function testUnsupportedTypeReturnsNullToContinueOtherBuilders():void
    {self::assertNull(ModernContent::build(['type'=>'text','text'=>'ok'],'u',[],1));}
    public function testThemeCannotBePublishedToGroupsOrBroadcasts():void
    { $this->expectException(\InvalidArgumentException::class);ModernContent::build(['type'=>'reset-chat-theme'],'1@g.us',[],1); }
    public function testMusicUsesObservedStanzaMediaType():void
    {self::assertSame(['attrs'=>['type'=>'media'],'encAttrs'=>['mediatype'=>'music'],'metaAttrs'=>[]],ModernContent::attributes(['musicMessage'=>['embeddedMusic'=>['songId'=>'1']]]));}
    public function testStatusMetadataDoesNotSilentlyAddMusicToUnsupportedBody():void
    { $this->expectException(\InvalidArgumentException::class);ModernContent::decorateStatus(['imageMessage'=>[]],['musicMetadata'=>['songId'=>'1']]); }
}
