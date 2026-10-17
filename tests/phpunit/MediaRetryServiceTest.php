<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Message\MediaRetryService;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Signal\SignalStore;

final class MediaRetryServiceTest extends TestCase
{
    private string $path;
    private SignalStore $store;
    protected function setUp():void { $this->path=tempnam(sys_get_temp_dir(),'mvo-media-retry-'); $this->store=new SignalStore($this->path); }
    protected function tearDown():void { $this->store->close(); foreach([$this->path,$this->path.'-wal',$this->path.'-shm'] as $file)if(is_file($file))unlink($file); }
    public function testRequestUsesIndependentHkdfGcmVectorAndCorrelatesSealedAnswer():void
    {
        $mediaKey=random_bytes(32); $sent=[];
        $service=new MediaRetryService($this->store,static fn():array=>['meJid'=>'100:5@s.whatsapp.net','meLid'=>'900:5@lid'],
            function(BinaryNode $node)use(&$sent):void{$sent[]=$node;},static function():void{});
        $input=['messageId'=>'original','chatJid'=>'100-200@g.us','mediaKey'=>$mediaKey,'fromMe'=>false,'participant'=>'200:4@s.whatsapp.net'];
        $service->begin($input,30); $service->begin($input,30); self::assertCount(1,$sent);
        $node=$sent[0]; self::assertSame(['id'=>'original','to'=>'900@lid','type'=>'server-error'],$node->attrs);
        self::assertSame(['jid'=>'100-200@g.us','from_me'=>'false','participant'=>'200@s.whatsapp.net'],$node->child('rmr')->attrs);
        $key=hash_hkdf('sha256',$mediaKey,32,'WhatsApp Media Retry Notification');
        $payload=$node->child('encrypt')->child('enc_p')->content; $iv=$node->child('encrypt')->child('enc_iv')->content;
        $plain=openssl_decrypt(substr($payload,0,-16),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,substr($payload,-16),'original');
        self::assertSame('original',SchemaCodec::decode('ServerErrorReceipt',$plain)['stanzaId']);
        $reply=self::answer($mediaKey,'original','900@lid',['stanzaId'=>'original','result'=>'SUCCESS','directPath'=>'/fresh']);
        self::assertTrue($service->handleNotification($reply));
        self::assertSame(['messageId'=>'original','result'=>'success','resultCode'=>1,'directPath'=>'/fresh'],$service->result('original'));
        self::assertSame([],$this->store->entries('media-retry-pending'));
    }
    public function testForeignNotificationsCannotSettlePendingRequestAndSealedIdMismatchFails():void
    {
        $key=random_bytes(32); $events=[];
        $service=new MediaRetryService($this->store,static fn():array=>['meJid'=>'100:5@s.whatsapp.net'],static function():void{},
            function(string $event,array $payload)use(&$events):void{$events[]=[$event,$payload];});
        $service->begin(['messageId'=>'id','chatJid'=>'200@s.whatsapp.net','mediaKey'=>$key],30);
        $service->handleNotification(self::answer($key,'id','999@s.whatsapp.net',['stanzaId'=>'id','result'=>'SUCCESS','directPath'=>'/foreign']));
        self::assertNull($service->result('id')); self::assertSame('foreign_notification',$events[0][1]['reason']);
        $service->handleNotification(self::answer($key,'id','100@s.whatsapp.net',['stanzaId'=>'different','result'=>'SUCCESS','directPath'=>'/fresh']));
        self::assertSame('Mediaretry stanza ID mismatch',$service->result('id')['error']);
    }
    public function testExplicitNotFoundIsAResultAndConflictingMediaKeysAreRejected():void
    {
        $key=random_bytes(32); $service=new MediaRetryService($this->store,static fn():array=>['meJid'=>'100@s.whatsapp.net'],static function():void{},static function():void{});
        $input=['messageId'=>'id','chatJid'=>'200@s.whatsapp.net','mediaKey'=>$key]; $service->begin($input,30);
        try { $service->begin([...$input,'mediaKey'=>random_bytes(32)],30); self::fail('Expected conflicting key'); } catch(\LogicException) {}
        $service->handleNotification(new BinaryNode('notification',['id'=>'id','from'=>'100@s.whatsapp.net','type'=>'mediaretry'],[new BinaryNode('error',['code'=>'2'])]));
        self::assertSame('not_found',$service->result('id')['result']);
    }
    private static function answer(string $mediaKey,string $id,string $from,array $payload):BinaryNode
    {
        $iv=random_bytes(12); $tag=''; $key=hash_hkdf('sha256',$mediaKey,32,'WhatsApp Media Retry Notification');
        $cipher=openssl_encrypt(SchemaCodec::encode('MediaRetryNotification',$payload),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$id);
        return new BinaryNode('notification',['id'=>$id,'type'=>'mediaretry','from'=>$from],[new BinaryNode('encrypt',[],[new BinaryNode('enc_p',[],$cipher.$tag),new BinaryNode('enc_iv',[],$iv)])]);
    }
}
