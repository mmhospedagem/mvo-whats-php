<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Media\MediaTransfer;
use MVO\Service\{NewsletterService,ServiceTransport,MexService};
use MVO\Protocol\{BinaryNode,SchemaCodec};

final class NewsletterMediaTest extends TestCase {
    public function testPlaintextChannelUploadDoesNotReuseEncryptedDescriptors():void {
        $uploaded=[]; $sent=[];
        $transfer=new MediaTransfer(static fn():BinaryNode=>new BinaryNode('iq',['type'=>'result'],[new BinaryNode('media_conn',['auth'=>'a','ttl'=>'3600'],[new BinaryNode('host',['hostname'=>'mmg.whatsapp.net'])])]),static function(array $r) use(&$uploaded):array {
            $uploaded[]=$r; return ['status'=>200,'body'=>'{"url":"https://mmg.whatsapp.net/a","direct_path":"/a"}'];
        });
        $wire=new ServiceTransport(static function(BinaryNode $n) use(&$sent):BinaryNode { $sent[]=$n; return new BinaryNode('ack',['id'=>$n->attrs['id'],'class'=>'message']); },static function():void{},static fn():array=>[]);
        $service=new NewsletterService($wire,new MexService($wire),$transfer->prepareNewsletter(...));
        $service->send('123@newsletter',['type'=>'ptv','media'=>['bytes'=>'mp4 test bytes'],'mimetype'=>'video/mp4']);
        self::assertStringContainsString('/newsletter/newsletter-ptv/',$uploaded[0]['url']); self::assertSame('mp4 test bytes',$uploaded[0]['body']);
        $proto=SchemaCodec::decode('Message',$sent[0]->child('plaintext')->content); self::assertArrayHasKey('ptvMessage',$proto); self::assertArrayNotHasKey('mediaKey',$proto['ptvMessage']); self::assertSame('ptv',$sent[0]->child('plaintext')->attrs['mediatype']);
        $service->send('123@newsletter',['type'=>'sticker-pack','name'=>'Pack','publisher'=>'PHP','stickers'=>[['fileName'=>'a.webp','media'=>['bytes'=>'sticker']]],'trayIcon'=>['fileName'=>'tray.png','media'=>['bytes'=>'tray']]]);
        self::assertStringContainsString('/newsletter/newsletter-sticker-pack/',$uploaded[1]['url']); self::assertStringStartsWith("PK\x03\x04",$uploaded[1]['body']);
        $pack=SchemaCodec::decode('Message',$sent[1]->child('plaintext')->content)['stickerPackMessage']; self::assertArrayNotHasKey('mediaKey',$pack); self::assertArrayNotHasKey('thumbnailEncSha256',$pack); self::assertSame('Pack',$pack['name']);
    }
    public function testPublicDownloadChecksHashBeforeWritingDestination():void {
        $transfer=new MediaTransfer(static fn()=>throw new \LogicException(),static fn()=>['status'=>200,'body'=>'public image']);
        $descriptor=['url'=>'https://mmg.whatsapp.net/a','fileSha256'=>hash('sha256','public image',true),'fileLength'=>12];
        self::assertSame('public image',$transfer->downloadNewsletter($descriptor));
        $path=tempnam(sys_get_temp_dir(),'mvo-channel-'); file_put_contents($path,'original');
        try { $descriptor['fileSha256']=str_repeat('x',32); try { $transfer->downloadNewsletter($descriptor,$path); self::fail(); } catch(\UnexpectedValueException) { self::assertSame('original',file_get_contents($path)); } }
        finally { unlink($path); }
    }
}
