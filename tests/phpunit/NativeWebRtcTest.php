<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Voip\{NativeDataChannel,MediaSession,OpusCodec,RelayTransport,FfmpegBinary,FfmpegCapture,VideoOutput};

final class NativeWebRtcTest extends TestCase
{
    protected function setUp():void {
        if(!extension_loaded('FFI') || !is_file(NativeDataChannel::defaultLibrary()))self::markTestSkipped('Build the optional native WebRTC bridge with tools/setup-voip.ps1');
    }
    public function testNativeIceDtlsSctpMovesBinaryPacketsInBothDirections():void {
        $a=new NativeDataChannel(bindAddress:'127.0.0.1');$b=new NativeDataChannel(bindAddress:'127.0.0.1');
        try{
            $a->createOffer();$offer=$a->localDescription(true);self::assertStringContainsString('a=fingerprint:sha-256',$offer);
            $b->setRemoteDescription($offer,'offer');$a->setRemoteDescription($b->localDescription(true));
            $deadline=microtime(true)+8;while((!$a->isOpen() || !$b->isOpen()) && microtime(true)<$deadline)usleep(1000);
            self::assertTrue($a->isOpen(),$a->state());self::assertTrue($b->isOpen(),$b->state());
            $bytes="\0\xffPHP-ICE-DTLS-SCTP\0";$a->send($bytes);self::assertSame($bytes,$b->receive(2));
            $b->send(str_repeat('encoded-media',80));self::assertSame(str_repeat('encoded-media',80),$a->receive(2));
            self::assertNull($a->receive());
        }finally{$a->close();$b->close();}
        self::assertSame('closed',$a->state());self::assertSame('closed',$b->state());
    }
    public function testNativeDtlsRejectsAnIncorrectRemoteCertificateFingerprint():void {
        $a=new NativeDataChannel(bindAddress:'127.0.0.1');$b=new NativeDataChannel(bindAddress:'127.0.0.1');
        try{
            $a->createOffer();$b->setRemoteDescription($a->localDescription(true),'offer');
            $answer=preg_replace('/a=fingerprint:sha-256 [^\r\n]+/','a=fingerprint:sha-256 '.implode(':',array_fill(0,32,'00')),$b->localDescription(true));
            $a->setRemoteDescription($answer);$deadline=microtime(true)+5;
            $opened=false;while(!in_array($a->state(),['failed','closed'],true) && microtime(true)<$deadline){$opened=$opened||$a->isOpen();usleep(1000);}
            self::assertFalse($opened||$a->isOpen());self::assertContains($a->state(),['failed','closed']);
        }finally{$a->close();$b->close();}
    }
    public function testRepeatedNativePeerDisposalKeepsCallbacksOutOfPhp():void {
        for($i=0;$i<8;$i++){$peer=new NativeDataChannel(bindAddress:'127.0.0.1');$peer->createOffer();self::assertStringStartsWith('v=0',$peer->localDescription());$peer->close();$peer->close();self::assertSame('closed',$peer->state());}
    }
    public function testPcmOpusSrtpAndNativeSctpReachAuthenticatedAudioPlayout():void {
        if(!is_file(OpusCodec::defaultLibrary()))self::markTestSkipped('Install optional native Opus');
        $a=new NativeDataChannel(bindAddress:'127.0.0.1');$b=new NativeDataChannel(bindAddress:'127.0.0.1');
        $key=random_bytes(32);$events=[];
        $sender=new MediaSession('LOCAL-MEDIA',$key,'111:1@lid','222:2@lid');
        $receiver=new MediaSession('LOCAL-MEDIA',$key,'222:2@lid','111:1@lid',function(string $event,array $data)use(&$events):void{$events[]=[$event,$data];});
        try{
            $a->createOffer();$b->setRemoteDescription($a->localDescription(true),'offer');$a->setRemoteDescription($b->localDescription(true));
            $deadline=microtime(true)+8;while((!$a->isOpen() || !$b->isOpen()) && microtime(true)<$deadline)usleep(1000);
            self::assertTrue($a->isOpen());self::assertTrue($b->isOpen());
            $sender->connectTransport(new LocalRtcMediaLeg($a));$receiver->connectTransport(new LocalRtcMediaLeg($b));
            $captureMs=0.0;$out=$sender->mediaEngine(new OpusCodec(),['now'=>static function()use(&$captureMs):float{return $captureMs;}]);$in=$receiver->mediaEngine(new OpusCodec());$pcm=[];
            for($i=0;$i<960;$i++)$pcm[]=sin(2*M_PI*440*$i/16000)*0.2;
            self::assertCount(1,$out->sendAudioPcm($pcm));
            $deadline=microtime(true)+2;while(array_filter($events,static fn(array $entry):bool=>$entry[0]==='voip_audio_frame')===[] && microtime(true)<$deadline)$receiver->poll(0.01);
            self::assertTrue($receiver->isReady());$frames=array_values(array_filter($events,static fn(array $entry):bool=>$entry[0]==='voip_audio_frame'));
            self::assertCount(1,$frames);self::assertCount(960,$frames[0][1]['pcm']);self::assertGreaterThan(0.01,max(array_map('abs',$frames[0][1]['pcm'])));
            $playout=$in->pullAudio(960);self::assertSame(960,$playout['real']);self::assertCount(960,$playout['samples']);
            $sender->setAudioEnabled(false);self::assertSame([],$out->sendAudioPcm($pcm));
            $sender->setAudioEnabled(true);$captureMs=60.0;self::assertCount(1,$out->sendAudioPcm($pcm,$captureMs));
        }finally{$sender->close();$receiver->close();$a->close();$b->close();}
    }
    public function testSyntheticVideoCrossesNativeSctpAndSrtpAndDecodesAsARealMp4():void {
        if(!is_file(OpusCodec::defaultLibrary()) || !is_file(FfmpegBinary::tool('ffmpeg')) || !is_file(FfmpegBinary::tool('ffprobe')))self::markTestSkipped('Install optional native AV tools');
        $capture=FfmpegCapture::lavfiVideo('color=c=blue:size=160x120:rate=5:duration=0.2',160,120,5);$frames=[];$path=sys_get_temp_dir().'/mvo-native-rtc-video-'.bin2hex(random_bytes(8)).'.mp4';
        $a=new NativeDataChannel(bindAddress:'127.0.0.1');$b=new NativeDataChannel(bindAddress:'127.0.0.1');$key=random_bytes(32);$received=[];
        $sender=new MediaSession('LOCAL-VIDEO',$key,'111:1@lid','222:2@lid');$receiver=new MediaSession('LOCAL-VIDEO',$key,'222:2@lid','111:1@lid',function(string $event,array $data)use(&$received):void{if($event==='voip_video_frame')$received[]=$data;});$output=VideoOutput::videoFile($path,5);
        try{
            $capture->start();$deadline=microtime(true)+5;while(!$capture->isEnded() && microtime(true)<$deadline){$frames=[...$frames,...$capture->pollVideo()];usleep(1000);}self::assertCount(1,$frames);
            $a->createOffer();$b->setRemoteDescription($a->localDescription(true),'offer');$a->setRemoteDescription($b->localDescription(true));$deadline=microtime(true)+8;while((!$a->isOpen() || !$b->isOpen()) && microtime(true)<$deadline)usleep(1000);self::assertTrue($a->isOpen());self::assertTrue($b->isOpen());
            $sender->connectTransport(new LocalRtcMediaLeg($a));$receiver->connectTransport(new LocalRtcMediaLeg($b));$sender->setVideoEnabled(true);
            $out=$sender->mediaEngine(new OpusCodec(),['maxPayload'=>60]);$receiver->mediaEngine(new OpusCodec());self::assertGreaterThan(1,count($out->sendVideoAnnexB($frames[0],90000)));
            $deadline=microtime(true)+3;while($received===[] && microtime(true)<$deadline)$receiver->poll(0.01);self::assertCount(1,$received);self::assertTrue($received[0]['keyFrame']);
            $output->start();$output->write($received[0]['data']);$output->finish();
            $process=proc_open([FfmpegBinary::tool('ffprobe'),'-v','error','-count_frames','-show_streams','-of','json',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);self::assertIsResource($process);fclose($pipes[0]);$json=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$error);$probe=json_decode($json,true,512,JSON_THROW_ON_ERROR);self::assertSame('h264',$probe['streams'][0]['codec_name']);self::assertSame(160,$probe['streams'][0]['width']);self::assertSame(120,$probe['streams'][0]['height']);self::assertSame('1',$probe['streams'][0]['nb_read_frames']);
        }finally{$capture->cancel();$output->cancel();$sender->close();$receiver->close();$a->close();$b->close();if(is_file($path))unlink($path);}
    }
}
final class LocalRtcMediaLeg implements RelayTransport
{
    public function __construct(private NativeDataChannel $channel){}
    public function connect(array $selfSsrcs,array $peerSsrcs=[]):void{}
    public function send(string $packet):void{$this->channel->send($packet);}
    public function receive(float $timeout=0.0):?string{return $this->channel->receive($timeout);}
    public function tick():void{}
    public function reregister():void{}
    public function updateSubscriptions(array $selfSsrcs,array $peerSsrcs,?string $streamDescriptor=null,?string $rxSubscriptions=null):void{}
    public function metrics():array{return ['open'=>$this->channel->isOpen(),'allocated'=>true,'lastPongAt'=>microtime(true),'openedAt'=>microtime(true),'rttMs'=>null];}
    public function close():void{$this->channel->close();}
}
