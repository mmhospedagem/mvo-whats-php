<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Console\MessageInput;
final class MessageInputTest extends TestCase
{
    private string $file;
    protected function setUp():void { $this->file=tempnam(sys_get_temp_dir(),'mvo-input-'); }
    protected function tearDown():void { if(is_file($this->file))unlink($this->file); }
    public function testTextFileAndAbsoluteMediaPath():void {
        file_put_contents($this->file,'Olá pelo PHP');
        self::assertSame('Olá pelo PHP',MessageInput::parse(['text-file'=>$this->file]));
        self::assertSame(['type'=>'image','media'=>['path'=>realpath($this->file)],'caption'=>'Foto'],MessageInput::parse(['file'=>$this->file,'type'=>'image','caption'=>'Foto']));
    }
    public function testJsonPollAndBinaryBytes():void {
        file_put_contents($this->file,'{"type":"raw","message":{"imageMessage":{"mediaKey":{"$base64":"AP8="}}}}');
        self::assertSame("\0\xff",MessageInput::parse(['payload-file'=>$this->file])['message']['imageMessage']['mediaKey']);
    }
    public function testAmbiguousSourceIsRejected():void { $this->expectException(\InvalidArgumentException::class); MessageInput::parse(['text'=>'Oi','file'=>$this->file]); }
    public function testListJsonIsRejected():void { file_put_contents($this->file,'[1,2]'); $this->expectException(\InvalidArgumentException::class); MessageInput::parse(['payload-file'=>$this->file]); }
    public function testShippedPayloadExamplesBuildCompleteMessages():void {
        foreach(glob(dirname(__DIR__,2).'/examples/payloads/*.json') as $file) {
            $content=MessageInput::parse(['payload-file'=>$file]);
            $built=\MVO\Message\ContentBuilder::build($content,'5511999999999@s.whatsapp.net',['meJid'=>'5511000000000:2@s.whatsapp.net','outgoingId'=>'example']);
            self::assertNotSame('',$built['bytes']);
        }
    }
}
