<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Message\{ContentBuilder,ContentDecoder,ImagePollSender,PollOptionHash};
use MVO\Protocol\SchemaCodec;
use PHPUnit\Framework\TestCase;

final class ImagePollSenderTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function content():array{return ['type'=>'image-poll','name'=>'Escolha uma imagem','options'=>[['name'=>'Azul','media'=>'first'],['name'=>'Verde','media'=>'second']],'selectableCount'=>1];}
    /** @return array<string,mixed> */
    private static function image(string $seed):array{return ['directPath'=>'/image/'.$seed,'url'=>'https://mmg.whatsapp.net/image/'.$seed,'mimetype'=>'image/png','fileSha256'=>hash('sha256',$seed,true),'fileLength'=>10];}

    public function testAllImagesArePreparedBeforeParentAndWrappedChildrenBindToItsRealId():void
    {
        $operations=[];$sent=[];$sender=new ImagePollSender(static function(string $jid,array $message,array $options)use(&$operations,&$sent):array{
            $operations[]='send';$built=ContentBuilder::build(['type'=>'raw','message'=>$message],$jid);$sent[]=['proto'=>SchemaCodec::decode('Message',$built['bytes']),'attrs'=>$built['attrs'],'enc'=>$built['encAttrs']];
            return ['id'=>$options['id'],'to'=>$jid,'status'=>'server_ack'];
        },static function(array $item)use(&$operations):array{$operations[]='upload';return ['imageMessage'=>self::image($item['media'])];});
        $result=$sender->send('123@newsletter',self::content(),['id'=>'PARENT']);
        self::assertSame(['upload','upload','send','send','send'],$operations);self::assertTrue($result['complete']);self::assertSame(2,$result['sentCount']);
        $parent=$sent[0]['proto']['pollCreationMessageV3'];self::assertSame(2,$parent['pollContentType']);self::assertSame('poll',$sent[0]['attrs']['type']);
        foreach(['Azul','Verde']as$index=>$name){
            $image=self::image($index===0?'first':'second');$expected=hash('sha256',hash('sha256',$name).base64_encode($image['fileSha256']));
            self::assertSame($expected,$parent['options'][$index]['optionHash']);
            $child=$sent[$index+1]['proto'];self::assertSame($image['fileSha256'],$child['pollCreationOptionImageMessage']['message']['imageMessage']['fileSha256']);
            self::assertSame(['associationType'=>7,'parentMessageKey'=>['remoteJid'=>'123@newsletter','fromMe'=>true,'id'=>'PARENT']],$child['messageContextInfo']['messageAssociation']);
            self::assertSame('media',$sent[$index+1]['attrs']['type']);self::assertSame('image',$sent[$index+1]['enc']['mediatype']);
            $decoded=ContentDecoder::decode(SchemaCodec::encode('Message',$child));self::assertSame('media_poll',$decoded['association']['type']);
            self::assertArrayNotHasKey('messageSecret',$child['messageContextInfo']);
        }
    }
    public function testImageAssociationHashIsDifferentFromTheVoteHash():void
    {
        $option=['optionName'=>'Azul','optionHash'=>PollOptionHash::image('Azul',str_repeat('A',32))];
        self::assertSame(hash('sha256','Azul',true),PollOptionHash::vote($option));self::assertNotSame($option['optionHash'],bin2hex(PollOptionHash::vote($option)));
        self::assertNotSame($option['optionHash'],PollOptionHash::image('Azul',str_repeat('B',32)));
    }
    public function testInvalidSecondOptionNeverStartsUploadOrPublication():void
    {
        $calls=0;$sender=new ImagePollSender(static function()use(&$calls):array{++$calls;return [];},static function()use(&$calls):array{++$calls;return [];});
        $input=self::content();$input['options'][1]['media']='';
        try{$sender->send('123@newsletter',$input);self::fail('Accepted missing image');}catch(\InvalidArgumentException){self::assertSame(0,$calls);}
    }
    public function testDirectChatDestinationFailsBeforeAnyUpload():void
    {
        $calls=0;$sender=new ImagePollSender(static fn():array=>[],static function()use(&$calls):array{++$calls;return [];});
        try{$sender->send('123@lid',self::content());self::fail('Published unsupported direct photo poll');}catch(\InvalidArgumentException){self::assertSame(0,$calls);}
    }
    public function testPreparationFailureDoesNotPublishParent():void
    {
        $sent=0;$sender=new ImagePollSender(static function()use(&$sent):array{++$sent;return [];},static fn():array=>['imageMessage'=>[...self::image('x'),'mediaKey'=>str_repeat('S',32)]]);
        try{$sender->send('123@newsletter',self::content());self::fail('Accepted encrypted newsletter upload');}catch(\InvalidArgumentException){self::assertSame(0,$sent);}
    }
    public function testUncertainParentStopsChildrenWithoutLosingReservedId():void
    {
        $calls=0;$sender=new ImagePollSender(static function(string $jid,array $message,array $options)use(&$calls):array{++$calls;return ['id'=>$options['id'],'status'=>'unknown'];},static fn(array $item):array=>['imageMessage'=>self::image($item['media'])]);
        $result=$sender->send('123@newsletter',self::content(),['id'=>'UNKNOWN']);self::assertSame(1,$calls);self::assertFalse($result['complete']);self::assertSame('UNKNOWN',$result['failure']['messageId']);self::assertSame(0,$result['sentCount']);self::assertFalse($result['failure']['retryWholePoll']);
    }
    public function testChildTimeoutPreservesParentAndAttemptedChildId():void
    {
        $calls=0;$ids=[];$sender=new ImagePollSender(static function(string $jid,array $message,array $options)use(&$calls,&$ids):array{$ids[]=$options['id'];if(++$calls===2)throw new \RuntimeException('timeout');return ['id'=>$options['id'],'status'=>'server_ack'];},static fn(array $item):array=>['imageMessage'=>self::image($item['media'])]);
        $result=$sender->send('123@newsletter',self::content(),['id'=>'PARENT']);self::assertSame(2,$calls);self::assertFalse($result['complete']);self::assertSame('PARENT',$result['parentKey']['id']);self::assertSame($ids[1],$result['failure']['messageId']);self::assertSame('unknown',$result['failure']['outcome']);self::assertSame(0,$result['sentCount']);
    }
    public function testInvalidReservedIdFailsBeforeUploads():void
    {
        $calls=0;$sender=new ImagePollSender(static fn():array=>[],static function()use(&$calls):array{++$calls;return [];});
        try{$sender->send('123@newsletter',self::content(),['id'=>"bad\n"]);self::fail('Accepted invalid reserved id');}catch(\InvalidArgumentException){self::assertSame(0,$calls);}
    }
    public function testQuizCorrectAnswerIncludesTheAssociatedImageHash():void
    {
        $sent=[];$sender=new ImagePollSender(static function(string $jid,array $message,array $options)use(&$sent):array{$sent[]=$message;return ['id'=>$options['id'],'status'=>'server_ack'];},static fn(array $item):array=>['imageMessage'=>self::image($item['media'])]);
        $sender->send('123@newsletter',[...self::content(),'pollType'=>'QUIZ','correctAnswer'=>'Verde']);$poll=$sent[0]['pollCreationMessageV3'];self::assertSame('QUIZ',$poll['pollType']);self::assertSame($poll['options'][1],$poll['correctAnswer']);
    }
    public function testExplicitServerNackIsFailedRatherThanUnknownAndStopsChildren():void
    {
        $calls=0;$sender=new ImagePollSender(static function()use(&$calls):array{++$calls;throw new \MVO\Service\ServiceException('publish','405','private server reason');},static fn(array $item):array=>['imageMessage'=>self::image($item['media'])]);
        $result=$sender->send('123@newsletter',self::content(),['id'=>'REJECTED']);self::assertSame(1,$calls);self::assertSame('failed',$result['parent']['status']);self::assertSame('405',$result['failure']['serverCode']);self::assertSame('failed',$result['failure']['outcome']);self::assertSame('REJECTED',$result['failure']['messageId']);self::assertFalse($result['complete']);self::assertStringNotContainsString('private server reason',json_encode($result,JSON_THROW_ON_ERROR));
    }
}
