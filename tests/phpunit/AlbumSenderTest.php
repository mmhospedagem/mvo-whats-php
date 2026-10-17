<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Message\AlbumSender;
use MVO\Protocol\SchemaCodec;
use PHPUnit\Framework\TestCase;

final class AlbumSenderTest extends TestCase
{
    public function testUploadsAllChildrenBeforeParentAndUsesAcknowledgedCanonicalKey():void
    {
        $operations=[]; $sent=[];
        $sender=new AlbumSender(static function(string $to,array $proto,array $options) use(&$operations,&$sent):array {
            $operations[]='send'; $sent[]=SchemaCodec::decode('Message',SchemaCodec::encode('Message',$proto));
            return ['id'=>count($sent)===1 ? 'PARENT' : 'CHILD'.count($sent),'to'=>'456@lid','status'=>'server_ack'];
        },static function(array $item) use(&$operations):array {$operations[]='prepare';return [$item['type'].'Message'=>['url'=>'https://mmg.whatsapp.net/media','caption'=>$item['caption'] ?? '']];});
        $result=$sender->send('5511999999999@s.whatsapp.net',[['type'=>'image','caption'=>'First'],['type'=>'video','caption'=>'Second'],['type'=>'image','caption'=>'Third']]);
        self::assertSame(['prepare','prepare','prepare','send','send','send','send'],$operations);
        self::assertSame(['expectedImageCount'=>2,'expectedVideoCount'=>1],$sent[0]['albumMessage']);
        foreach ([0,1,2] as $index) {
            $assoc=$sent[$index+1]['messageContextInfo']['messageAssociation'];
            self::assertSame(1,$assoc['associationType']); self::assertSame($index,$assoc['messageIndex']);
            self::assertSame(['remoteJid'=>'456@lid','fromMe'=>true,'id'=>'PARENT'],$assoc['parentMessageKey']);
        }
        self::assertTrue($result['complete']); self::assertSame(3,$result['sentCount']);
    }

    public function testFailedPreparationNeverPublishesParent():void
    {
        $sent=0; $sender=new AlbumSender(static function() use(&$sent):array {++$sent;return [];},static function():array {throw new \RuntimeException('Upload failed');});
        try {$sender->send('123@lid',[['type'=>'image'],['type'=>'video']]);self::fail('Accepted failed upload');}
        catch (\RuntimeException $error) {self::assertSame('Upload failed',$error->getMessage());self::assertSame(0,$sent);}
    }

    public function testInvalidChildIsRejectedBeforeAnyUpload():void
    {
        $prepared=0; $sender=new AlbumSender(static fn():array=>[],static function() use(&$prepared):array {++$prepared;return [];});
        try {$sender->send('123@lid',[['type'=>'image'],['type'=>'audio']]);self::fail('Accepted audio');}
        catch (\InvalidArgumentException) {self::assertSame(0,$prepared);}
    }

    public function testChildFailureReportsPublishedIdsAndNeverResendsParent():void
    {
        $calls=0; $sender=new AlbumSender(static function() use(&$calls):array {
            ++$calls;if ($calls===3) throw new \RuntimeException('ACK timeout');return ['id'=>$calls===1 ? 'PARENT' : 'CHILD1','to'=>'123@lid','status'=>'server_ack'];
        },static fn():array=>['imageMessage'=>['url'=>'https://mmg.whatsapp.net/media']]);
        $result=$sender->send('123@lid',[['type'=>'image'],['type'=>'image'],['type'=>'image']]);
        self::assertFalse($result['complete']);self::assertSame(1,$result['sentCount']);self::assertSame(1,$result['failure']['index']);
        self::assertSame('PARENT',$result['parentKey']['id']);self::assertFalse($result['failure']['retryWholeAlbum']);self::assertSame(3,$calls);
    }

    public function testGroupAssociationRetainsSenderParticipant():void
    {
        $sent=[];$sender=new AlbumSender(static function(string $to,array $proto) use(&$sent):array {$sent[]=$proto;return ['id'=>'ID'.count($sent),'to'=>$to,'status'=>'server_ack'];},static fn():array=>['imageMessage'=>['url'=>'https://mmg.whatsapp.net/media']]);
        $sender->send('123@g.us',[['type'=>'image'],['type'=>'image']],['participant'=>'456:2@lid']);
        self::assertSame('456@lid',$sent[1]['messageContextInfo']['messageAssociation']['parentMessageKey']['participant']);
    }

    public function testUncertainParentStopsChildrenAndPreservesItsId():void
    {
        $calls=0; $sender=new AlbumSender(static function() use(&$calls):array {++$calls;return ['id'=>'PARENT','to'=>'123@lid','status'=>'unknown'];},static fn():array=>['imageMessage'=>['url'=>'https://mmg.whatsapp.net/media']]);
        $result=$sender->send('123@lid',[['type'=>'image'],['type'=>'image']]);
        self::assertSame(1,$calls); self::assertFalse($result['complete']);self::assertSame('PARENT',$result['parentKey']['id']); self::assertSame(0,$result['sentCount']);
    }

    public function testRejectedChildStopsRemainingChildrenAndPreservesFailedId():void
    {
        $calls=0; $sender=new AlbumSender(static function() use(&$calls):array {++$calls;return ['id'=>'ID'.$calls,'to'=>'123@lid','status'=>$calls===2 ? 'rejected' : 'server_ack'];},static fn():array=>['imageMessage'=>['url'=>'https://mmg.whatsapp.net/media']]);
        $result=$sender->send('123@lid',[['type'=>'image'],['type'=>'image'],['type'=>'image']]);
        self::assertSame(2,$calls);self::assertFalse($result['complete']);self::assertSame('ID2',$result['failure']['messageId']);self::assertSame('rejected',$result['failure']['outcome']);self::assertSame(0,$result['sentCount']);
    }

    public function testAlbumCaptionOnlyFillsFirstMissingItemCaption():void
    {
        foreach ([null,'Custom'] as $caption) {
            $prepared=[];$sent=0;$sender=new AlbumSender(static function() use(&$sent):array {++$sent;return ['id'=>'ID'.$sent,'to'=>'123@lid','status'=>'server_ack'];},static function(array $content) use(&$prepared):array {$prepared[]=$content;return ['imageMessage'=>['caption'=>$content['caption'] ?? '']];});
            $first=['type'=>'image'];if ($caption!==null) $first['caption']=$caption;
            $sender->send('123@lid',[$first,['type'=>'image']],['caption'=>'Album caption']);
            self::assertSame($caption ?? 'Album caption',$prepared[0]['caption']);self::assertArrayNotHasKey('caption',$prepared[1]);
        }
    }
}
