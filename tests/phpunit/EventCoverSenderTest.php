<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Message\{ContentBuilder,ContentDecoder,EventCoverSender};
use MVO\Protocol\SchemaCodec;
use PHPUnit\Framework\TestCase;

final class EventCoverSenderTest extends TestCase
{
    private static function content():array{return ['type'=>'event','name'=>'Encontro MVO','startTime'=>time()+3600,'cover'=>['media'=>'fixture']];}
    private static function image():array{return ['directPath'=>'/cover','mimetype'=>'image/png','fileSha256'=>str_repeat('P',32),'fileEncSha256'=>str_repeat('E',32),'mediaKey'=>str_repeat('K',32),'fileLength'=>10];}
    public function testCoverIsUploadedBeforeEventAndAssociatedWithCanonicalParent():void
    {
        $actions=[];$sent=[];$sender=new EventCoverSender(static function(string $to,array $proto,array $options)use(&$actions,&$sent):array{$actions[]='send';$sent[]=ContentBuilder::build(['type'=>'raw','message'=>$proto],$to);return ['id'=>$options['id'],'to'=>'222@lid','status'=>'server_ack','key'=>['id'=>$options['id'],'remoteJid'=>'222@lid','fromMe'=>true]];},static function()use(&$actions):array{$actions[]='upload';return ['imageMessage'=>self::image()];});
        $result=$sender->send('222@s.whatsapp.net',self::content());self::assertSame(['upload','send','send'],$actions);self::assertTrue($result['complete']);self::assertSame(1,$result['sentCount']);
        self::assertSame('event',$sent[0]['attrs']['type']);self::assertSame(32,strlen($sent[0]['secrets']['messageSecret']));
        $decoded=ContentDecoder::decode($sent[1]['bytes']);self::assertSame(['eventCoverImage'],$decoded['wrappers']);self::assertSame('image',$decoded['kind']);self::assertEquals($result['parentKey'],$decoded['eventCover']['parent']);self::assertTrue($decoded['eventCover']['hasImage']);self::assertSame('image',$sent[1]['encAttrs']['mediatype']);
        self::assertSame(3,$decoded['messageContextInfo']['messageAssociation']['associationType']);self::assertArrayNotHasKey('messageIndex',$decoded['messageContextInfo']['messageAssociation']);self::assertSame(self::image()['mediaKey'],$decoded['data']['mediaKey']);
    }
    public function testGroupCoverKeepsParentParticipant():void
    {
        $sent=[];$sender=new EventCoverSender(static function(string $to,array $proto,array $options)use(&$sent):array{$sent[]=$proto;return ['id'=>$options['id'],'status'=>'server_ack','key'=>['id'=>$options['id'],'remoteJid'=>$to,'fromMe'=>true,'participant'=>'111@lid']];},static fn():array=>['imageMessage'=>self::image()]);
        $result=$sender->send('123@g.us',self::content());self::assertSame('111@lid',$result['parentKey']['participant']);self::assertSame($result['parentKey'],$sent[1]['messageContextInfo']['messageAssociation']['parentMessageKey']);
    }
    public function testBadCoverFailsBeforeUploadAndPublication():void
    {
        $calls=0;$sender=new EventCoverSender(static function()use(&$calls):array{++$calls;return [];},static function()use(&$calls):array{++$calls;return [];});$content=self::content();$content['cover']['mimetype']='video/mp4';
        try{$sender->send('222@lid',$content);self::fail('Accepted video cover');}catch(\InvalidArgumentException){self::assertSame(0,$calls);}
    }
    public function testUnknownGroupParentDoesNotPublishCoverOrLoseId():void
    {
        $calls=0;$sender=new EventCoverSender(static function(string $to,array $proto,array $options)use(&$calls):array{++$calls;return ['id'=>$options['id'],'to'=>$to,'status'=>'unknown'];},static fn():array=>['imageMessage'=>self::image()]);
        $result=$sender->send('123@g.us',self::content());self::assertSame(1,$calls);self::assertFalse($result['complete']);self::assertSame($result['parentKey']['id'],$result['failure']['messageId']);self::assertSame(0,$result['sentCount']);
    }
    public function testCoverTimeoutRetainsBothIdsAndDoesNotResendParent():void
    {
        $calls=0;$ids=[];$sender=new EventCoverSender(static function(string $to,array $proto,array $options)use(&$calls,&$ids):array{$ids[]=$options['id'];if(++$calls===2)throw new \RuntimeException('timeout');return ['id'=>$options['id'],'to'=>$to,'status'=>'server_ack'];},static fn():array=>['imageMessage'=>self::image()]);
        $result=$sender->send('222@lid',self::content());self::assertSame(2,$calls);self::assertFalse($result['complete']);self::assertSame($ids[0],$result['parentKey']['id']);self::assertSame($ids[1],$result['failure']['messageId']);self::assertFalse($result['failure']['retryWholeEvent']);
    }
    public function testPureBuilderDoesNotSilentlyDiscardAnUnpreparedCover():void
    {$this->expectException(\InvalidArgumentException::class);ContentBuilder::build(self::content(),'222@lid');}
    public function testExplicitCoverNackPreservesParentAndReportsKnownFailure():void
    {
        $calls=0;$sender=new EventCoverSender(static function(string $to,array $proto,array $options)use(&$calls):array{if(++$calls===2)throw new \MVO\Service\ServiceException('send','406','private server reason');return ['id'=>$options['id'],'to'=>$to,'status'=>'server_ack'];},static fn():array=>['imageMessage'=>self::image()]);
        $result=$sender->send('222@lid',self::content());self::assertSame(2,$calls);self::assertSame('server_ack',$result['parent']['status']);self::assertSame('failed',$result['messages'][0]['status']);self::assertSame('406',$result['failure']['serverCode']);self::assertSame($result['messages'][0]['id'],$result['failure']['messageId']);self::assertFalse($result['failure']['retryWholeEvent']);self::assertFalse($result['complete']);self::assertStringNotContainsString('private server reason',json_encode($result,JSON_THROW_ON_ERROR));
    }
}
