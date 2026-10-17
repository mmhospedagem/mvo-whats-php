<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Media\MediaProcessor;
final class MediaProcessorTest extends TestCase
{
    public function testThumbnailPreservesRatioAndHonorsEdge():void {
        if (!extension_loaded('gd')) self::markTestSkipped('Optional ext-gd is not installed');
        $img=imagecreatetruecolor(300,150); $red=imagecolorallocate($img,255,0,0); imagefill($img,0,0,$red); ob_start(); imagepng($img); $bytes=ob_get_clean(); imagedestroy($img);
        $processor=new MediaProcessor(); $thumb=$processor->thumbnail($bytes);
        self::assertNotNull($thumb); $size=getimagesizefromstring($thumb); self::assertSame([96,48],[$size[0],$size[1]]); self::assertSame('image/jpeg',$size['mime']);
        $png=$processor->process($bytes,'sticker'); self::assertArrayHasKey('pngThumbnail',$png['fields']); self::assertSame('image/png',getimagesizefromstring($png['fields']['pngThumbnail'])['mime']);
    }
    public function testWaveformSilenceAndAmplitudeBuckets():void {
        self::assertSame(str_repeat("\0",64),MediaProcessor::waveform(str_repeat(pack('g',0),128)));
        $pcm=str_repeat(pack('g',0.25),64).str_repeat(pack('g',1.0),64);
        $wave=MediaProcessor::waveform($pcm); self::assertSame(str_repeat(chr(25),32).str_repeat(chr(100),32),$wave);
    }
    public function testMissingEncoderFailsExplicitVoiceConversion():void { $this->expectException(\LogicException::class); (new MediaProcessor())->normalizeVoice('wav'); }
}
