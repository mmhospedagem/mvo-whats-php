<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Voip\{AnnexBAccessUnitBuffer,AudioOutput,FfmpegBinary,FfmpegCapture,H264,VideoOutput};

final class VoipAvPipelineTest extends TestCase
{
    private array $outputs=[];
    protected function tearDown():void { foreach($this->outputs as $path) if(is_file($path)) unlink($path); }
    private function native():void { if(!is_file(FfmpegBinary::tool('ffmpeg')) || !is_file(FfmpegBinary::tool('ffprobe'))) self::markTestSkipped('Install native FFmpeg with tools/setup-av.ps1'); }
    private function outputPath(string $suffix):string { $path=sys_get_temp_dir().'/mvo-av-test-'.bin2hex(random_bytes(8)).$suffix; $this->outputs[]=$path; return $path; }
    private static function collect(FfmpegCapture $capture,bool $video=false):array
    {
        $capture->start(); $result=[]; $deadline=microtime(true)+5;
        try { while(!$capture->isEnded()) { $result=[...$result,...($video?$capture->pollVideo():$capture->pollAudio())]; if(microtime(true)>=$deadline) self::fail('Synthetic capture did not finish'); usleep(1000); } }
        finally { $capture->stop(); }
        return $result;
    }
    private function probe(string $path):array
    {
        $out=$this->outputPath('.json'); $error=$this->outputPath('.log'); $null=PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null';
        $process=proc_open([FfmpegBinary::tool('ffprobe'),'-v','error','-count_frames','-show_entries','format=duration:stream=codec_name,codec_type,sample_rate,channels,width,height,nb_read_frames','-of','json',$path],[0=>['file',$null,'r'],1=>['file',$out,'w'],2=>['file',$error,'w']],$pipes,null,null,['bypass_shell'=>true,'create_no_window'=>true]);
        self::assertIsResource($process); $deadline=microtime(true)+5;
        try { do { $status=proc_get_status($process); if(!$status['running']) break; if(microtime(true)>=$deadline) self::fail('ffprobe timed out'); usleep(1000); } while(true); self::assertSame(0,$status['exitcode'],file_get_contents($error)); }
        finally { proc_terminate($process); proc_close($process); }
        return json_decode(file_get_contents($out),true,512,JSON_THROW_ON_ERROR);
    }
    public function testAnnexBAccessUnitParserHandlesEveryByteBoundaryAndDropsAudOnly():void
    {
        $a=hex2bin('000000010910000000016701020000000165aabb'); $b=hex2bin('000001093000000141ccdd'); $c=hex2bin('000000010950');
        for($split=1;$split<strlen($a.$b.$c);$split++) {
            $parser=new AnnexBAccessUnitBuffer(); $all=$a.$b.$c; $frames=$parser->push(substr($all,0,$split)); $frames=[...$frames,...$parser->push(substr($all,$split)),...$parser->finish()]; self::assertSame([$a,$b],$frames,'Split '.$split);
        }
        $this->expectException(\LengthException::class); (new AnnexBAccessUnitBuffer(16))->push(str_repeat('x',17));
    }
    public function testLavfiToneCaptureProduces960SampleFramesAndWritesRealWaveFile():void
    {
        $this->native(); $capture=FfmpegCapture::lavfiAudio('sine=frequency=440:sample_rate=16000:duration=0.18'); $frames=self::collect($capture);
        self::assertCount(3,$frames); $energy=0.0; foreach($frames as $frame) { self::assertCount(960,$frame); foreach($frame as $sample) { self::assertTrue(is_finite($sample)); $energy+=$sample*$sample; } }
        self::assertGreaterThan(1,$energy); self::assertSame(0,$capture->stats()['paddedSamples']);
        $path=$this->outputPath('.wav'); $output=AudioOutput::waveFile($path); $output->start();
        try { foreach($frames as $frame) $output->write($frame); $output->finish(); } finally { $output->cancel(); }
        self::assertSame(11520,$output->stats()['writtenBytes']); $probe=$this->probe($path); self::assertSame('pcm_s16le',$probe['streams'][0]['codec_name']); self::assertSame('16000',$probe['streams'][0]['sample_rate']); self::assertSame(1,$probe['streams'][0]['channels']); self::assertEqualsWithDelta(0.18,(float)$probe['format']['duration'],0.001);
    }
    public function testLavfiColorCaptureProducesAudAccessUnitsAndWritesRealH264Mp4():void
    {
        $this->native(); $frames=self::collect(FfmpegCapture::lavfiVideo('color=c=red:size=160x120:rate=5:duration=0.6',160,120,5),true); self::assertCount(3,$frames);
        self::assertTrue(H264::isKeyFrame($frames[0])); foreach($frames as $frame) { self::assertSame(9,ord(H264::nals($frame)[0]['data'][0])&31); self::assertLessThan(8388608,strlen($frame)); }
        $path=$this->outputPath('.mp4'); $output=VideoOutput::videoFile($path,5); $output->start();
        try { foreach($frames as $frame) $output->write($frame); $output->finish(); } finally { $output->cancel(); }
        $probe=$this->probe($path); self::assertSame('h264',$probe['streams'][0]['codec_name']); self::assertSame(160,$probe['streams'][0]['width']); self::assertSame(120,$probe['streams'][0]['height']); self::assertSame('3',$probe['streams'][0]['nb_read_frames']);
    }
    public function testPartialFinalAudioIsPaddedAndCancelStopsSyntheticProcess():void
    {
        $this->native(); $capture=FfmpegCapture::lavfiAudio('sine=frequency=440:sample_rate=16000:duration=0.07'); $frames=self::collect($capture);
        self::assertCount(2,$frames); self::assertCount(960,$frames[1]); self::assertSame(800,$capture->stats()['paddedSamples']); self::assertSame(0.0,$frames[1][959]);
        $endless=FfmpegCapture::lavfiAudio('sine=frequency=440:sample_rate=16000'); $endless->start(); $start=microtime(true); $endless->cancel(); self::assertLessThan(1.0,microtime(true)-$start); self::assertTrue($endless->isEnded()); $endless->cancel();
        $this->expectException(\LogicException::class); $endless->pollAudio();
    }
    public function testInvalidFilterReportsNativeFailureAndOutputsNeverOverwrite():void
    {
        $this->native(); $capture=FfmpegCapture::lavfiAudio('mvo_filter_that_does_not_exist');
        try { $capture->start(2); self::fail('Accepted invalid FFmpeg filter'); } catch(\RuntimeException $error) { self::assertStringContainsString('AV process ended',$error->getMessage()); } finally { $capture->cancel(); }
        $path=$this->outputPath('.wav'); file_put_contents($path,'preserve');
        try { AudioOutput::waveFile($path); self::fail('Accepted existing output file'); } catch(\InvalidArgumentException) { self::assertSame('preserve',file_get_contents($path)); }
        // Constructing hardware endpoints is deliberately not followed by start().
        $microphone=FfmpegCapture::microphone('EXPLICIT UNUSED TEST DEVICE'); $camera=FfmpegCapture::camera('EXPLICIT UNUSED TEST DEVICE'); $screen=FfmpegCapture::screen('EXPLICIT UNUSED TEST TARGET');
        $microphone->cancel(); $camera->cancel(); $screen->cancel();
        $playback=AudioOutput::playback(); $render=VideoOutput::render(); $playback->cancel(); $render->cancel(); self::assertTrue($playback->stats()['closed']); self::assertTrue($render->stats()['closed']);
    }
    public function testOutputBackpressureRejectsOversizedBlockWithoutStartingSpeaker():void
    {
        $this->native(); $path=$this->outputPath('.wav'); $output=AudioOutput::waveFile($path,null,4096); $output->start();
        try { $output->write(array_fill(0,2000,0.1)); self::fail('Accepted oversized queued PCM'); } catch(\LengthException) { self::assertSame(0,$output->stats()['writtenBytes']); } finally { $output->cancel(); }
    }
}
