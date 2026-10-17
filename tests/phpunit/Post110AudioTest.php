<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Voip\{AudioOutput,FfmpegBinary,FfmpegCapture,MLowCodec,MLowPacket,NativeAvProcess,OpusCodec};
use PHPUnit\Framework\TestCase;

/** Offline regressions translated from the post-1.10 audio changes, without hardware. */
final class Post110AudioTest extends TestCase
{
    /** @var list<string> */
    private array $files=[];
    protected function tearDown():void { foreach($this->files as $file) if(is_file($file)) unlink($file); }
    private function native():void
    {
        if(!extension_loaded('FFI') || !is_file(MLowCodec::defaultMLowLibrary()) || !is_file(OpusCodec::defaultLibrary())) self::markTestSkipped('Optional native codecs are not installed');
    }
    private function ffmpeg():void { if(!is_file(FfmpegBinary::tool('ffmpeg'))) self::markTestSkipped('Optional FFmpeg is not installed'); }
    private function path(string $extension='.wav'):string { $path=sys_get_temp_dir().'/mvo-post110-'.bin2hex(random_bytes(8)).$extension; $this->files[]=$path; return $path; }
    /** @return list<float> */
    private static function tone(int $offset=0,int $samples=960):array
    {
        $pcm=[]; for($i=0;$i<$samples;$i++) $pcm[]=0.3*sin(2*M_PI*440*($offset+$i)/16000); return $pcm;
    }
    /** All-zero test tensors exercise the published native ABI; these are not WhatsApp weights.
     * Dimensions follow companion.h/c and companion_net.c at 0f50232, not learned data.
     */
    private static function syntheticCompanionContainer():string
    {
        $layers=['pitch_embedding'=>[351,64],'fnet_conv1'=>[165,96],'fnet_conv2'=>[768,160],'fnet_tconv'=>[160,640],'fnet_gru_input'=>[160,480],'fnet_gru_recurrent'=>[160,480],'af1_kernel'=>[160,32],'af1_gain'=>[160,2],'af4_kernel'=>[160,32],'af4_gain'=>[160,1],'tdshape1_alpha1_f'=>[320,80],'tdshape1_alpha1_t'=>[42,80],'tdshape1_alpha2'=>[160,80],'ft1'=>[320,160],'ft2'=>[320,160]];
        $model='';
        foreach($layers as $name=>[$inputs,$outputs]) foreach(['_bias'=>$outputs,'_weights_float'=>$inputs*$outputs] as $suffix=>$floats) {
            $bytes=$floats*4; $padded=intdiv($bytes+127,128)*128;
            $model.=str_pad('DNNw'.pack('V4',0,0,$bytes,$padded).'mlowcompanion_'.$name.$suffix."\0",128,"\0").str_repeat("\0",$padded);
        }
        return $model;
    }
    /** @return list<list<float>> */
    private static function drain(FfmpegCapture $capture):array
    {
        $frames=[]; $deadline=microtime(true)+5;
        while(!$capture->isEnded()) { $frames=[...$frames,...$capture->pollAudio()]; if(microtime(true)>$deadline) self::fail('Offline audio did not finish'); usleep(1000); }
        return $frames;
    }
    /** @return list<int> */
    private static function waveSamples(string $path):array
    {
        $bytes=file_get_contents($path); self::assertIsString($bytes); self::assertSame('RIFF',substr($bytes,0,4));
        for($offset=12;$offset+8<=strlen($bytes);) {
            $size=unpack('V',substr($bytes,$offset+4,4))[1];
            if(substr($bytes,$offset,4)==='data') { $values=unpack('v*',substr($bytes,$offset+8,$size)); self::assertIsArray($values); return array_values($values); }
            $offset+=8+$size+($size&1);
        }
        self::fail('WAV has no PCM data');
    }
    public function testPerParticipantDecodersHaveNoEncoderAndReturnedPcmSurvivesScratchReuse():void
    {
        $this->native(); $encoder=new MLowCodec(); $decoder=$encoder->newDecoder();
        try {
            self::assertTrue($decoder->isDecodeOnly()); self::assertNull((new \ReflectionProperty(OpusCodec::class,'encoder'))->getValue($decoder));
            $first=$decoder->decode($encoder->encode(self::tone())); $saved=$first;
            $second=$decoder->decode($encoder->encode(self::tone(960)));
            self::assertSame($saved,$first); self::assertCount(960,$second); self::assertNotSame($first,$second);
            try { $decoder->encode(self::tone()); self::fail('Decode-only codec allocated an encoder'); } catch(\LogicException) { self::addToAssertionCount(1); }
        } finally { $encoder->close(); $decoder->close(); }
    }
    public function testSecondaryIsAutomaticallyEncodedOncePerFrameAndNativeTunablesApply():void
    {
        $this->native(); $codec=new MLowCodec(options:['red'=>true,'fec'=>true,'packetLossPercent'=>20,'tunables'=>['subframeImportance'=>100,'useSpeechActivityFlatness'=>1,'vadNonBinary'=>50,'vadHighpassSharpness'=>50]]);
        try {
            $first=$codec->encode(self::tone()); $copy=$codec->encodeSecondary();
            self::assertTrue($codec->redActive()); self::assertSame($copy,$codec->encodeSecondary()); self::assertSame($copy,$codec->lastSecondary());
            self::assertSame(960,MLowPacket::samples($copy)); self::assertLessThan(strlen($first),strlen($copy));
            $second=$codec->encode(self::tone(960)); self::assertSame(1920,MLowPacket::samples($codec->packFrames([$first,$second])));
            $codec->setExpectedPacketLossPercent(50); self::assertSame(960,MLowPacket::samples($codec->encode(self::tone(1920))));
            foreach(['setInbandFec','setDtx'] as $method) { try { $codec->{$method}(true); self::fail('Accepted an unsafe secondary encoder mode'); } catch(\LogicException) { self::addToAssertionCount(1); } }
            try { $codec->applyEncoderTunables(['vadNonBinary'=>101]); self::fail('Accepted out-of-range native ctl'); } catch(\InvalidArgumentException) { self::addToAssertionCount(1); }
        } finally { $codec->close(); }
    }
    public function testCopyFirstMultiframeUsesLostSlotWithoutReplayingPreviousAudio():void
    {
        $this->native(); $encoder=new MLowCodec(options:['red'=>true]); $receiver=new MLowCodec(); $primary=[]; $secondary=[];
        try {
            for($i=0;$i<4;$i++) { $primary[]=$encoder->encode(self::tone($i*960)); $secondary[]=$encoder->encodeSecondary(); }
            $receiver->decodeSequenced(0,$primary[0],0);
            $second=$receiver->decodeSequenced(1,MLowPacket::pack([$primary[0],$primary[1]]),0);
            self::assertCount(1,$second['frames']); self::assertSame(1,$receiver->stats()['redundant']);
            $recovered=$receiver->decodeSequenced(3,MLowPacket::pack([$secondary[2],$primary[3]]),1920);
            self::assertSame('decoded',$recovered['outcome']); self::assertCount(2,$recovered['frames']);
            self::assertSame(1,$receiver->stats()['redRecovered']); self::assertSame(0,$receiver->stats()['plc']); self::assertSame(0,$receiver->stats()['fecAttempts']);
        } finally { $encoder->close(); $receiver->close(); }
    }
    public function testCompactRedCopyCannotOverlapThePrimarySlot():void
    {
        $this->native(); $encoder=new MLowCodec(options:['red'=>true]); $receiver=new MLowCodec();
        try {
            $first=$encoder->encode(self::tone()); $encoder->encode(self::tone(960)); $copy=$encoder->encodeSecondary(); $third=$encoder->encode(self::tone(1920));
            $receiver->decodeSequenced(0,MLowPacket::writeRed($first,0),0,1);
            $invalidPlacement=MLowPacket::writeRed($third,1920,[['packet'=>$copy,'timestamp'=>1760]]);
            $decoded=$receiver->decodeSequenced(2,$invalidPlacement,1920,1);
            self::assertSame('decoded',$decoded['outcome']); self::assertCount(2,$decoded['frames']); self::assertCount(960,$decoded['frames'][1]);
            self::assertSame(0,$receiver->stats()['redRecovered']); self::assertSame(1,$receiver->stats()['fecAttempts']);
        } finally { $encoder->close(); $receiver->close(); }
    }
    public function testFecFailureUsesNativePlcAndDoesNotDiscardTheFollowingPrimary():void
    {
        $this->native(); $encoder=new OpusCodec();
        $receiver=new class extends OpusCodec {
            public function decode(?string $packet,int $frameSize=0,bool $fec=false):array { if($fec) throw new \RuntimeException('Packet has no usable native FEC'); return parent::decode($packet,$frameSize,$fec); }
        };
        try {
            $first=$encoder->encode(self::tone(0,320)); $encoder->encode(self::tone(320,320)); $third=$encoder->encode(self::tone(640,320));
            $receiver->decodeSequenced(1,$first,0); $result=$receiver->decodeSequenced(3,$third,640);
            self::assertSame('decoded',$result['outcome']); self::assertCount(2,$result['frames']); self::assertSame(1,$receiver->stats()['plc']); self::assertSame(0,$receiver->stats()['errors']);
        } finally { $encoder->close(); $receiver->close(); }
    }
    public function testZeroConcealmentLimitAndTimestampWrapRemainBounded():void
    {
        $this->native(); $encoder=new MLowCodec(); $receiver=new MLowCodec(options:['maxConcealFrames'=>0]);
        try {
            $packet=$encoder->encode(self::tone()); $receiver->decodeSequenced(65535,$packet,0xfffffc40);
            $result=$receiver->decodeSequenced(1,$packet,960); self::assertSame('decoded',$result['outcome']); self::assertCount(1,$result['frames']); self::assertSame(0,$receiver->stats()['plc']); self::assertSame(0,$receiver->stats()['fecAttempts']); self::assertSame(1,$receiver->stats()['concealCapped']);
        } finally { $encoder->close(); $receiver->close(); }
    }
    public function testCompanionWeightsAreNeverReportedAsAppliedByAnUnsupportedAbi():void
    {
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('model was not applied');
        $this->native(); new MLowCodec(MLowCodec::defaultMLowLibrary(),options:['companionModel'=>'DNNw'.str_repeat("\0",32)]);
    }
    public function testNativeCompanionUsesExplicitTestModelAndResetsDetachesAndIsolatesEachDecoder():void
    {
        $this->native(); if(!is_file(MLowCodec::defaultCompanionLibrary())) self::markTestSkipped('Build tools/setup-companion.ps1 to test the optional native Companion ABI');
        $model=self::syntheticCompanionContainer(); $encoder=new MLowCodec();
        $receiver=new MLowCodec(options:['decodeOnly'=>true,'companionModel'=>$model]); $plain=new MLowCodec(MLowCodec::defaultCompanionLibrary(),options:['decodeOnly'=>true]); $child=null;
        try {
            self::assertTrue($receiver->supportsCompanion()); self::assertTrue($receiver->hasCompanion()); self::assertTrue($receiver->isDecodeOnly());
            $packet=$encoder->encode(self::tone()); $filtered=$receiver->decode($packet); $unfiltered=$plain->decode($packet);
            self::assertCount(960,$filtered); self::assertNotSame($unfiltered,$filtered,'The native excitation hook must actually run');
            foreach($filtered as $sample) self::assertTrue(is_finite($sample));
            $receiver->resetSequence(); self::assertSame($filtered,$receiver->decode($packet));
            $child=$receiver->newDecoder(); self::assertTrue($child->hasCompanion()); self::assertSame($filtered,$child->decode($packet));
            $property=new \ReflectionProperty(MLowCodec::class,'companion');
            $castApi=\FFI::cdef('typedef unsigned long long uintptr_t;');
            self::assertNotSame($castApi->cast('uintptr_t',$property->getValue($receiver))->cdata,$castApi->cast('uintptr_t',$property->getValue($child))->cdata);
            try { $receiver->setCompanionModel('DNNw'.str_repeat("\0",32)); self::fail('Malformed replacement was attached'); } catch(\InvalidArgumentException) { self::assertTrue($receiver->hasCompanion()); }
            $receiver->setCompanionModel(null); self::assertFalse($receiver->hasCompanion()); $receiver->resetSequence(); self::assertSame($unfiltered,$receiver->decode($packet));
            self::assertTrue($child->hasCompanion(),'Detaching one participant must not detach another');
        } finally { $encoder->close(); $receiver->close(); $plain->close(); $child?->close(); }
    }
    public function testCompanionContainerValidationRejectsZeroWidthsNanAndTruncationBeforeNativeCode():void
    {
        $model=self::syntheticCompanionContainer(); MLowCodec::validateCompanionModel($model);
        foreach([substr_replace($model,pack('V',0),12,4),substr_replace($model,pack('g',NAN),128,4),substr($model,0,-1)] as $malformed) {
            try { MLowCodec::validateCompanionModel($malformed); self::fail('Malformed DNNw container was accepted'); } catch(\InvalidArgumentException) { self::addToAssertionCount(1); }
        }
    }
    public function testMlowPacketsOverOneHundredTwentyMillisecondsAreRejectedBeforeDecoding():void
    {
        $this->native(); $codec=new MLowCodec();
        try {
            $frame=$codec->encode(self::tone()); $packet=MLowPacket::pack([$frame,$frame,$frame]);
            self::assertSame('rejected',$codec->decodeSequenced(1,$packet,0)['outcome']); self::assertSame(0,$codec->stats()['success']);
            $this->expectException(\InvalidArgumentException::class); $codec->packFrames([$frame,$frame,$frame]);
        } finally { $codec->close(); }
    }
    public function testMutedPlayoutConsumesTheTimelineAndOutputSwitchKeepsTheCurrentSinkOnFailure():void
    {
        $this->ffmpeg(); $first=$this->path(); $second=$this->path(); $failed=$this->path(); $output=AudioOutput::waveFile($first); $output->start();
        try {
            $output->write(array_fill(0,960,0.25));
            try { $output->setOutput(AudioOutput::waveFile($failed,'C:/mvo-absent-ffmpeg.exe')); self::fail('Missing output executable was accepted'); } catch(\RuntimeException) { self::assertFalse($output->stats()['closed']); }
            $output->write(array_fill(0,960,0.25)); $output->setOutput(AudioOutput::waveFile($second));
            $output->setPlayoutMuted(true,0); $output->write(array_fill(0,960,0.25));
            $output->setPlayoutMuted(false,0); $output->write(array_fill(0,960,0.25)); $output->finish();
            self::assertCount(1920,self::waveSamples($first)); $samples=self::waveSamples($second); self::assertCount(1920,$samples);
            self::assertSame(array_fill(0,960,0),array_slice($samples,0,960)); self::assertGreaterThan(8000,$samples[960]); self::assertSame(960,$output->stats()['mutedSamples']);
        } finally { $output->cancel(); }
    }
    public function testMicrophoneSwitchUsesFreshNativeSourceAndPreservesOldSourceOnFailure():void
    {
        $this->ffmpeg(); $capture=FfmpegCapture::lavfiAudio('sine=frequency=440:sample_rate=16000'); $capture->start();
        try {
            try { $capture->setMicrophone(FfmpegCapture::lavfiAudio('mvo_missing_audio_filter'),2); self::fail('Invalid source was accepted'); } catch(\RuntimeException) { self::assertFalse($capture->isEnded()); }
            $capture->setMicrophone(FfmpegCapture::lavfiAudio('sine=frequency=880:sample_rate=16000:duration=0.12'));
            $frames=self::drain($capture); self::assertCount(2,$frames); self::assertCount(960,$frames[0]); self::assertGreaterThan(0.1,array_sum(array_map(static fn(float $x):float=>$x*$x,$frames[0])));
        } finally { $capture->cancel(); }
    }
    public function testLocalAudioFileIsStreamedAsCallFramesAndEnforcesItsDecodedByteBound():void
    {
        $this->ffmpeg(); $path=$this->path(); $output=AudioOutput::waveFile($path); $output->start();
        try { $output->write(self::tone()); $output->write(self::tone(960)); $output->finish(); } finally { $output->cancel(); }
        $capture=FfmpegCapture::audioFile($path); $capture->start();
        try { $frames=self::drain($capture); self::assertCount(2,$frames); self::assertCount(960,$frames[0]); } finally { $capture->cancel(); }
        $bounded=FfmpegCapture::audioFile($path,maxDecodedBytes:3840); $bounded->start();
        try { self::drain($bounded); self::fail('Decoded audio bound was ignored'); } catch(\LengthException) { self::assertTrue($bounded->isEnded()); } finally { $bounded->cancel(); }
    }
    public function testAvProcessRejectsAmbiguousSocketsAndNonFiniteTimeoutBeforeLaunchingAnything():void
    {
        try { new NativeAvProcess(PHP_BINARY,[NativeAvProcess::SOCKET,NativeAvProcess::SOCKET]); self::fail('Duplicate media sockets were accepted'); } catch(\InvalidArgumentException) { self::addToAssertionCount(1); }
        $process=new NativeAvProcess(PHP_BINARY,[NativeAvProcess::SOCKET]);
        $this->expectException(\InvalidArgumentException::class); $process->start(NAN);
    }
    public function testNativeOutputDeviceSelectionIsExplicitAndDoesNotOpenHardwareOnConstruction():void
    {
        foreach(['alsa'=>'hw:1,7','pulse'=>'explicit-sink-name','audiotoolbox'=>'2','oss'=>'/dev/dsp','sndio'=>'snd/0'] as $backend=>$name) {
            $output=AudioOutput::device($backend,$name); self::assertSame(0,$output->stats()['writtenBytes']); $output->cancel(); self::assertTrue($output->stats()['closed']);
        }
        $this->expectException(\InvalidArgumentException::class); AudioOutput::device('browser-sink-id','device');
    }
}
