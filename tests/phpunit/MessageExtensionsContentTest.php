<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Message\{CarouselContent,ContentBuilder,ContentDecoder,QuoteSelection};
use MVO\Media\{MediaCrypto,MediaTransfer};
use MVO\Protocol\{BinaryNode,SchemaCodec};
use PHPUnit\Framework\TestCase;

final class MessageExtensionsContentTest extends TestCase
{
    private static function card(string $bytes='image'):array{return ['type'=>'image','media'=>['bytes'=>$bytes],'title'=>'MVO','caption'=>'Plano Cloud','buttons'=>[['type'=>'url','label'=>'Conhecer','url'=>'https://mvo.host']]];}
    private static function image():array{return ['mimetype'=>'image/jpeg','directPath'=>'/image','mediaKey'=>str_repeat('K',32),'fileSha256'=>str_repeat('P',32),'fileEncSha256'=>str_repeat('E',32),'fileLength'=>5];}
    public function testCarouselPreparesEveryCardBeforeEncodingItsSingleMessageAndCompanion():void
    {
        $uploads=[];$content=CarouselContent::prepare(['type'=>'carousel','text'=>'Nossos planos','footer'=>'MVO','cards'=>[self::card('first'),self::card('second')]],static function(array $input)use(&$uploads):array{$uploads[]=$input['media']['bytes'];return ['imageMessage'=>self::image()];});
        self::assertSame(['first','second'],$uploads);self::assertArrayNotHasKey('media',$content['cards'][0]);
        $built=ContentBuilder::build($content,'222@s.whatsapp.net');$decoded=ContentDecoder::decode($built['bytes']);
        self::assertSame(2,$decoded['carousel']['cardCount']);self::assertSame('Nossos planos',$decoded['carousel']['text']);self::assertSame(32,strlen($built['secrets']['messageSecret']));
        self::assertSame('text',$built['attrs']['type']);self::assertSame('2',$built['companionNodes'][0]->attrs['host_storage']);
        $cards=$decoded['data']['carouselMessage']['cards'];self::assertSame('Plano Cloud',$cards[0]['body']['text']);self::assertSame('cta_url',$cards[0]['nativeFlowMessage']['buttons'][0]['name']);
        self::assertSame('https://mvo.host',json_decode($cards[0]['nativeFlowMessage']['buttons'][0]['buttonParamsJson'],true)['url']);
    }
    public function testEverySourceIsReadAndAllButtonsValidateBeforeAnyUpload():void
    {
        $calls=0;$cards=[self::card(),self::card()];$cards[1]['media']=['bytes'=>''];
        try{CarouselContent::prepare(['type'=>'carousel','cards'=>$cards],static function()use(&$calls):array{++$calls;return [];});self::fail('Empty second source accepted');}catch(\InvalidArgumentException){self::assertSame(0,$calls);}
        $cards[1]=self::card();$cards[1]['buttons'][0]['url']='javascript:alert(1)';
        try{CarouselContent::prepare(['type'=>'carousel','cards'=>$cards],static function()use(&$calls):array{++$calls;return [];});self::fail('Invalid second button accepted');}catch(\InvalidArgumentException){self::assertSame(0,$calls);}
        $this->expectException(\LengthException::class);CarouselContent::prepare(['type'=>'carousel','cards'=>[self::card('123'),self::card('456')]],static fn():array=>[],5);
    }
    public function testCarouselRejectsUnpreparedMediaAndConflictingButtonFields():void
    {
        try{ContentBuilder::build(['type'=>'carousel','cards'=>[self::card(),self::card()]],'222@lid');self::fail('Pure builder ignored unprepared source');}catch(\InvalidArgumentException){self::assertTrue(true);}
        $cards=[self::card(),self::card()];$cards[0]['buttons'][0]['copyCode']='x';$this->expectException(\InvalidArgumentException::class);CarouselContent::normalize(['type'=>'carousel','cards'=>$cards]);
    }
    public function testSpoilerAndSelectedQuoteSurviveWireAndKeepOriginalMessage():void
    {
        $original=['extendedTextMessage'=>['text'=>'Olá! Conheça o plano Cloud MVO.']];
        $built=ContentBuilder::build(['type'=>'text','text'=>'Quero saber mais','spoiler'=>true,'quote'=>['key'=>['id'=>'PARENT123','participant'=>'111@lid'],'message'=>$original,'selectedText'=>'plano Cloud']],'222@lid');
        $decoded=ContentDecoder::decode($built['bytes']);self::assertTrue($decoded['spoiler']);self::assertSame(['spoilerMessage'],$decoded['wrappers']);self::assertSame('plano Cloud',$decoded['quote']['selectedText']);self::assertSame('PARENT123',$decoded['quote']['id']);self::assertEquals($original,$decoded['context']['quotedMessage']);self::assertSame('Quero saber mais',$decoded['text']);
        $this->expectException(\InvalidArgumentException::class);QuoteSelection::validate($original,'plano inventado');
    }
    public function testSpoilerAliasDoesNotRecurseAndNoHintIsAddedToOrdinaryText():void
    {
        self::assertFalse(ContentDecoder::decode(ContentBuilder::build('Normal','222@lid')['bytes'])['spoiler']);
        self::assertTrue(ContentDecoder::decode(ContentBuilder::build(['type'=>'spoiler','content'=>'Segredo visual'],'222@lid')['bytes'])['spoiler']);
        $this->expectException(\InvalidArgumentException::class);ContentBuilder::build(['type'=>'text','text'=>'x','spoiler'=>'true'],'222@lid');
    }
    public function testInstantImageUsesAuthenticatedImageMediaCryptographyThroughSpoilerWrapper():void
    {
        $encrypted=MediaCrypto::encrypt('instant-image-original','image',str_repeat('M',32));$fields=array_diff_key($encrypted,['body'=>true])+['directPath'=>'/instant','mimetype'=>'image/jpeg','caption'=>'Foto instantânea'];
        $proto=['spoilerMessage'=>['message'=>['instantImageMessage'=>$fields]]];$decoded=ContentDecoder::decode(SchemaCodec::encode('Message',$proto));
        self::assertSame('instant-image',$decoded['kind']);self::assertSame('instant-image',$decoded['media']['variant']);self::assertSame('image',$decoded['media']['mediaType']);self::assertSame('Foto instantânea',$decoded['instantImage']['caption']);
        $transfer=new MediaTransfer(static fn():BinaryNode=>throw new \LogicException('No query needed'),static fn():array=>['status'=>200,'body'=>$encrypted['body']]);
        self::assertSame('instant-image-original',$transfer->download($decoded['media']));
    }
    public function testNativeLottiePackageIsEncryptedAsStickerWithoutConvertingTheOriginalBytes():void
    {
        $original="native-was-fixture\0\xff";$uploaded=null;
        $transfer=new MediaTransfer(static fn():BinaryNode=>new BinaryNode('iq',['type'=>'result'],[new BinaryNode('media_conn',['auth'=>'fixture','ttl'=>'3600'],[new BinaryNode('host',['hostname'=>'mmg.whatsapp.net'])])]),static function(array $request)use(&$uploaded):array{$uploaded=$request;return ['status'=>200,'body'=>'{"direct_path":"/sticker","url":"https://mmg.whatsapp.net/sticker"}'];});
        $proto=$transfer->prepare(['type'=>'lottie-sticker','media'=>['bytes'=>$original],'width'=>512,'height'=>512]);$sticker=$proto['stickerMessage'];
        self::assertTrue($sticker['isLottie']);self::assertSame('application/was',$sticker['mimetype']);self::assertStringContainsString('/mms/sticker/',$uploaded['url']);
        self::assertSame($original,MediaCrypto::decrypt($uploaded['body'],'sticker',$sticker['mediaKey'],$sticker['fileSha256'],$sticker['fileEncSha256']));
        $this->expectException(\InvalidArgumentException::class);$transfer->prepare(['type'=>'lottie-sticker','media'=>['bytes'=>$original],'mimetype'=>'application/json']);
    }
}
