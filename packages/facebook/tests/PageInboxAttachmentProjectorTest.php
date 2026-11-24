<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\PageInboxAttachmentProjector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/PageInboxAttachmentFixture.php';

final class PageInboxAttachmentProjectorTest extends TestCase
{
    private function projector():PageInboxAttachmentProjector{return new PageInboxAttachmentProjector(1000);}

    public static function nativeTypes():iterable
    {
        yield 'photo'=>[2,'image','image/jpeg'];yield 'animated image'=>[3,'image','image/gif'];
        yield 'video'=>[4,'video','video/mp4'];yield 'audio'=>[5,'audio','audio/mp4'];yield 'file'=>[6,'file','application/pdf'];
        yield 'sticker'=>[1,'sticker','image/webp'];yield 'selfie sticker'=>[10,'sticker','image/webp'];yield 'third party sticker'=>[15,'sticker','image/webp'];
    }

    #[DataProvider('nativeTypes')]
    public function testNativeTypesKeepExactMessageAndThreadBinding(int $nativeType,string $type,string $mime):void
    {
        $projector=$this->projector();$blob=PageInboxAttachmentFixture::blob($nativeType,[6=>$mime]);
        $result=$projector->project(PageInboxAttachmentFixture::calls([$blob]),PageInboxAttachmentFixture::messages(),'30003');
        self::assertSame(['mid.fixture.1'],array_keys($result));self::assertCount(1,$result['mid.fixture.1']);$attachment=$result['mid.fixture.1'][0];
        self::assertSame('90009',$attachment['id']);self::assertSame($type,$attachment['type']);self::assertSame($mime,$attachment['mime_type']);
        self::assertSame('https://scontent.xx.fbcdn.net/v/t1/synthetic.jpg',$attachment['url']);self::assertSame('available',$attachment['availability']);self::assertNull($attachment['unavailable_reason']);
        self::assertSame(12345,$attachment['size_bytes']);self::assertSame(640,$attachment['width']);self::assertSame(480,$attachment['height']);self::assertSame(1234,$attachment['duration_ms']);
        self::assertArrayNotHasKey('filesize',$attachment);self::assertArrayNotHasKey('thread_id',$attachment);
        self::assertSame(['partial'=>false,'unsupported_procedures'=>[]],$projector->coverage());
    }

    public function testNativeStickerWithoutMediaUrlHasAnExplicitUnavailableProjection():void
    {
        $result=$this->projector()->project(PageInboxAttachmentFixture::calls([PageInboxAttachmentFixture::sticker()]),PageInboxAttachmentFixture::messages(),'30003');
        $item=$result['mid.fixture.1'][0];self::assertSame('sticker',$item['type']);self::assertNull($item['url']);self::assertNull($item['preview_url']);
        self::assertSame('unavailable',$item['availability']);self::assertSame('media_url_unavailable',$item['unavailable_reason']);self::assertSame('figurinha-sintetica.webp',$item['filename']);
    }

    public function testAuthorityDeduplicatesNativeOptimisticAndConfirmedRows():void
    {
        $calls=PageInboxAttachmentFixture::calls([PageInboxAttachmentFixture::sticker(),PageInboxAttachmentFixture::blob(15),PageInboxAttachmentFixture::sticker(),PageInboxAttachmentFixture::blob(15)]);
        $result=$this->projector()->project($calls,PageInboxAttachmentFixture::messages(),'30003');self::assertCount(1,$result['mid.fixture.1']);self::assertSame('available',$result['mid.fixture.1'][0]['availability']);
        $calls[]=PageInboxAttachmentFixture::blob(15,[0=>'different-synthetic-filename.webp']);
        $this->expectException(FacebookException::class);$this->projector()->project($calls,PageInboxAttachmentFixture::messages(),'30003');
    }

    public static function unsafeUrls():iterable
    {
        foreach(['javascript:alert(1)','data:image/png;base64,PHNjcmlwdD4=','http://scontent.xx.fbcdn.net/image.jpg',
            'https://fbcdn.net.example.invalid/image.jpg','https://example.invalid/fbcdn.net/image.jpg',
            'https://u:PRIVATE_SECRET@scontent.xx.fbcdn.net/image.jpg','https://scontent.xx.fbcdn.net:443/image.jpg',
            'https://scontent.xx.fbcdn.net/image.jpg#fragment','https://localhost/image.jpg','//scontent.xx.fbcdn.net/image.jpg',
            "https://scontent.xx.fbcdn.net/image.jpg\nPRIVATE_SECRET",'https://scontent.xx.fbcdn.net/%XX/image.jpg',
            'https://scontent.xx.fbcdn.net\\@example.invalid/image.jpg']as $index=>$url)yield 'unsafe '.$index=>[$url];
    }

    #[DataProvider('unsafeUrls')]
    public function testMaliciousOrUnestablishedMediaOriginCannotEscapeTheProjection(string $url):void
    {
        $blob=PageInboxAttachmentFixture::blob(2,[3=>$url,4=>null,8=>$url,9=>null,45=>$url]);
        $result=$this->projector()->project(PageInboxAttachmentFixture::calls([$blob]),PageInboxAttachmentFixture::messages(),'30003');
        $item=$result['mid.fixture.1'][0];self::assertNull($item['url']);self::assertNull($item['preview_url']);self::assertSame('unavailable',$item['availability']);
        self::assertStringNotContainsString($url,json_encode($item,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }

    public function testNativeCdnExpirationFallbackAndPreviewOnlyRemainExplicit():void
    {
        $blob=PageInboxAttachmentFixture::blob(4,[3=>'https://video.xx.fbcdn.net/video.mp4',4=>'https://attachment.fbsbx.com/native-fallback.mp4',5=>500,6=>'video/mp4',
            8=>'https://scontent.xx.fbcdn.net/preview.jpg',10=>500,9=>'https://lookaside.fbsbx.com/preview-fallback.jpg']);
        $item=$this->projector()->project(PageInboxAttachmentFixture::calls([$blob]),PageInboxAttachmentFixture::messages(),'30003')['mid.fixture.1'][0];
        self::assertSame('https://attachment.fbsbx.com/native-fallback.mp4',$item['url']);self::assertSame('https://lookaside.fbsbx.com/preview-fallback.jpg',$item['preview_url']);
        self::assertNull($item['url_expires_at_ms']);self::assertNull($item['preview_expires_at_ms']);
        $blob['args'][4]=null;$blob['args'][5]=null;$blob['args'][3]=null;
        $item=$this->projector()->project(PageInboxAttachmentFixture::calls([$blob]),PageInboxAttachmentFixture::messages(),'30003')['mid.fixture.1'][0];
        self::assertNull($item['url']);self::assertSame('preview_only',$item['availability']);self::assertNotNull($item['preview_url']);
    }

    public function testAnimatedMp4UsesNativeImagePreviewRatherThanInventingAnImageUrl():void
    {
        $blob=PageInboxAttachmentFixture::blob(3,[3=>'https://video.xx.fbcdn.net/animated.mp4',6=>'video/mp4']);
        $item=$this->projector()->project(PageInboxAttachmentFixture::calls([$blob]),PageInboxAttachmentFixture::messages(),'30003')['mid.fixture.1'][0];
        self::assertSame('image',$item['type']);self::assertSame('https://scontent.xx.fbcdn.net/v/t1/synthetic-preview.jpg',$item['url']);self::assertSame('image/jpeg',$item['mime_type']);
    }

    public function testCrossThreadOrUnknownMessageAttachmentsAreNotReassigned():void
    {
        foreach([[27=>'90009'],[32=>'mid.unrelated.message'],[27=>'90009',32=>'mid.fixture.1']]as $fields){
            $result=$this->projector()->project(PageInboxAttachmentFixture::calls([PageInboxAttachmentFixture::blob(2,$fields)]),PageInboxAttachmentFixture::messages(),'30003');self::assertSame([],$result);
        }
    }

    public function testFalsePageOwnerOrSpoofedMessageProjectionIsRejected():void
    {
        foreach(['90009','10001']as $falseOwner){
            try{$this->projector()->project(PageInboxAttachmentFixture::calls([PageInboxAttachmentFixture::blob()]),PageInboxAttachmentFixture::messages(),$falseOwner);self::fail('A different Page or personal account was accepted as owner');}
            catch(FacebookException $error){self::assertSame('facebook_inbox_attachment_unconfirmed',$error->reason);}
        }
        $messages=PageInboxAttachmentFixture::messages();$messages[0]['sender_id']='90009';
        $this->expectException(FacebookException::class);$this->projector()->project(PageInboxAttachmentFixture::calls([PageInboxAttachmentFixture::blob()]),$messages,'30003');
    }

    public function testUnknownProcedureIsOnlyASnapshotWideCoverageGapWithoutInventedCorrelation():void
    {
        $projector=$this->projector();$calls=PageInboxAttachmentFixture::calls([['name'=>'LSInsertStickerAttachment','args'=>['PRIVATE_UNKNOWN_STICKER_FIELDS']]]);
        self::assertSame([],$projector->project($calls,PageInboxAttachmentFixture::messages(),'30003'));
        self::assertSame(['partial'=>true,'unsupported_procedures'=>['LSInsertStickerAttachment']],$projector->coverage());
        self::assertStringNotContainsString('PRIVATE_UNKNOWN_STICKER_FIELDS',print_r($projector,true));
        $projector->project(PageInboxAttachmentFixture::calls(),PageInboxAttachmentFixture::messages(),'30003');self::assertFalse($projector->coverage()['partial']);
    }

    public function testUnsupportedTypeOrUnestablishedBlobScopeCannotPublishMediaUrls():void
    {
        $projector=$this->projector();$result=$projector->project(PageInboxAttachmentFixture::calls([PageInboxAttachmentFixture::blob(999)]),PageInboxAttachmentFixture::messages(),'30003');
        self::assertNull($result['mid.fixture.1'][0]['url']);self::assertSame('unsupported_attachment_type',$result['mid.fixture.1'][0]['unavailable_reason']);self::assertTrue($projector->coverage()['partial']);
        self::assertSame([],$projector->project(PageInboxAttachmentFixture::calls([PageInboxAttachmentFixture::blob(2,[28=>1])]),PageInboxAttachmentFixture::messages(),'30003'));
        self::assertSame(['partial'=>true,'unsupported_procedures'=>['unsupported_blob_scope']],$projector->coverage());
    }

    public function testShippedXmaIdentityAndOpaqueAttachmentIdHaveExplicitNoMediaFallback():void
    {
        $projector=$this->projector();$result=$projector->project(PageInboxAttachmentFixture::calls([PageInboxAttachmentFixture::xma()]),PageInboxAttachmentFixture::messages(),'30003');
        self::assertSame(['mid.fixture.1'],array_keys($result));self::assertCount(1,$result['mid.fixture.1']);$item=$result['mid.fixture.1'][0];
        self::assertSame('xma.synthetic.attachment-opaque',$item['id']);self::assertSame('xma',$item['native_type']);self::assertSame('unavailable',$item['availability']);
        self::assertSame('native_card_media_unavailable',$item['unavailable_reason']);self::assertNull($item['url']);self::assertNull($item['preview_url']);self::assertSame(0,$item['size_bytes']);
        self::assertFalse($projector->coverage()['partial']);
    }

    public function testXmaMediaFieldsUseTheirOwnShippedOffsetsAndExcludeActionUrls():void
    {
        $xma=PageInboxAttachmentFixture::xma([1=>'synthetic-video.mp4',2=>'54321',4=>'https://video.xx.fbcdn.net/synthetic.mp4',6=>3000,7=>'video/mp4',
            8=>'https://scontent.xx.fbcdn.net/synthetic-preview.jpg',10=>3000,11=>'image/jpeg',13=>320,14=>240,
            57=>'https://www.facebook.com/story.php?story_fbid=SYNTHETIC',58=>'Cartão sintético',63=>'Descrição sintética',68=>'PRIVATE_UNKNOWN_XMA_DATA']);
        $item=$this->projector()->project(PageInboxAttachmentFixture::calls([$xma]),PageInboxAttachmentFixture::messages(),'30003')['mid.fixture.1'][0];
        self::assertSame('video',$item['type']);self::assertSame('https://video.xx.fbcdn.net/synthetic.mp4',$item['url']);self::assertSame('video/mp4',$item['mime_type']);
        self::assertSame(320,$item['width']);self::assertSame(240,$item['height']);self::assertSame(54321,$item['size_bytes']);self::assertSame('Cartão sintético',$item['title']);
        self::assertSame('Descrição sintética',$item['description']);self::assertArrayNotHasKey('action_url',$item);
        $json=json_encode($item,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);self::assertStringNotContainsString('facebook.com/story',$json);self::assertStringNotContainsString('PRIVATE_UNKNOWN_XMA_DATA',$json);
    }

    public function testXmaImageAndAudioNativeFieldsStayCdnBoundAndRespectExpiration():void
    {
        $xma=PageInboxAttachmentFixture::xma([54=>'https://scontent.xx.fbcdn.net/expired-image.jpg',55=>'https://lookaside.fbsbx.com/native-image.jpg',56=>500,11=>'image/jpeg']);
        $item=$this->projector()->project(PageInboxAttachmentFixture::calls([$xma]),PageInboxAttachmentFixture::messages(),'30003')['mid.fixture.1'][0];
        self::assertSame('image',$item['type']);self::assertSame('https://lookaside.fbsbx.com/native-image.jpg',$item['url']);self::assertNull($item['url_expires_at_ms']);
        $xma=PageInboxAttachmentFixture::xma([126=>'https://attachment.fbsbx.com/native-audio.mp4']);
        $item=$this->projector()->project(PageInboxAttachmentFixture::calls([$xma]),PageInboxAttachmentFixture::messages(),'30003')['mid.fixture.1'][0];
        self::assertSame('audio',$item['type']);self::assertSame('https://attachment.fbsbx.com/native-audio.mp4',$item['url']);
        $xma=PageInboxAttachmentFixture::xma([4=>'https://evil.invalid/synthetic.mp4',7=>'video/mp4',8=>'javascript:alert(1)',54=>'https://www.facebook.com/photo.php',126=>'data:audio/mp4;base64,AAAA']);
        $item=$this->projector()->project(PageInboxAttachmentFixture::calls([$xma]),PageInboxAttachmentFixture::messages(),'30003')['mid.fixture.1'][0];
        self::assertNull($item['url']);self::assertNull($item['preview_url']);self::assertSame('unavailable',$item['availability']);
    }

    public function testXmaPdfRemainsADownloadableFileAlongsideItsSeparateJpegPreview():void
    {
        $xma=PageInboxAttachmentFixture::xma([1=>'documento-sintetico.pdf',2=>'54321',
            4=>'https://attachment.fbsbx.com/synthetic-document.pdf',6=>3000,7=>'application/pdf',
            8=>'https://scontent.xx.fbcdn.net/synthetic-document-preview.jpg',10=>4000,11=>'image/jpeg']);
        $item=$this->projector()->project(PageInboxAttachmentFixture::calls([$xma]),PageInboxAttachmentFixture::messages(),'30003')['mid.fixture.1'][0];
        self::assertSame('file',$item['type']);self::assertSame('application/pdf',$item['mime_type']);
        self::assertSame('https://attachment.fbsbx.com/synthetic-document.pdf',$item['url']);
        self::assertSame('https://scontent.xx.fbcdn.net/synthetic-document-preview.jpg',$item['preview_url']);
        self::assertSame('documento-sintetico.pdf',$item['filename']);self::assertSame(54321,$item['size_bytes']);
        self::assertSame(3000,$item['url_expires_at_ms']);self::assertSame(4000,$item['preview_expires_at_ms']);
        self::assertSame('available',$item['availability']);self::assertNull($item['unavailable_reason']);
    }

    public function testXmaFromAnotherThreadOrUnconfirmedMessageIsNotReassigned():void
    {
        foreach([[25=>'90009'],[30=>'mid.unrelated.message']]as $fields){
            $result=$this->projector()->project(PageInboxAttachmentFixture::calls([PageInboxAttachmentFixture::xma($fields)]),PageInboxAttachmentFixture::messages(),'30003');self::assertSame([],$result);
        }
    }
}
