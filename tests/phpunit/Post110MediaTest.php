<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Voip\{AudioDecoder,AudioReceiveSet,CallMediaEngine,H264,H264Depacketizer,MLowCodec,MLowPacket,OpusCodec,Rtcp,Rtp,RtpReception,SenderReportSchedule,WarpFrame};
use PHPUnit\Framework\TestCase;

final class Post110MediaTest extends TestCase
{
    public function testIndependentVideoExtensionWireVectors():void
    {
        self::assertSame('32080000510000610000910000000000',bin2hex(Rtp::videoExtension(true,true,0,0)));
        self::assertSame('300851000061000091000100',bin2hex(Rtp::videoExtension(true,false,0,1)));
        self::assertSame('3220123451000061000091abcdd3010493e00000',bin2hex(Rtp::videoExtension(false,true,0x1234,0xabcd,300000)));
        $rtp=Rtp::encode('h264',7,3,9000,97,true,[],Rtp::videoExtension(true,true,0,0),Rtp::EXTENSION_PROFILE);
        self::assertSame('debe0004',bin2hex(substr($rtp,12,4)));
        self::assertSame('h264',Rtp::decode($rtp)['payload']);
        $withSources=Rtp::encode('p',7,3,9000,97,true,[0x01020304,0xfefdfcfb],Rtp::videoExtension(true,false,0,1));
        self::assertSame([0x01020304,0xfefdfcfb],Rtp::decode($withSources)['csrc']);
        self::assertSame('p',Rtp::decode($withSources)['payload']);
    }

    public function testKeyframeSpsFuShapeAndCodecFromAnnexB():void
    {
        $frame=hex2bin('000000010901000000016742e01f000000016801020000000165030405060708');
        $packets=H264::packetize($frame,9);
        self::assertTrue($packets['keyFrame']);
        self::assertSame(hex2bin('0901'),$packets['payloads'][0]);
        self::assertSame(28,ord($packets['payloads'][1][0])&31);
        self::assertSame(7,ord($packets['payloads'][1][1])&31);
        self::assertSame(128,ord($packets['payloads'][1][1])&128);
        self::assertSame(64,ord($packets['payloads'][array_key_last($packets['payloads'])][1])&64);
        $dep=new H264Depacketizer(); $frames=[];
        foreach($packets['payloads'] as $i=>$packet) $frames=[...$frames,...$dep->push($packet,7,$i===count($packets['payloads'])-1,$i)];
        self::assertSame($frame,$frames[0]['data']);
        self::assertSame('avc1.42E01F',H264::readCodec($frame));
        self::assertNull(H264::readCodec(hex2bin('6742e01f')));
        self::assertSame(H264::CONSTRAINED_BASELINE_CODEC,H264::readCodec($frame));
    }

    public function testUnsupportedNalMarkerCannotFlushPendingFrame():void
    {
        $dep=new H264Depacketizer();
        self::assertSame([],$dep->push(hex2bin('650102'),90,false,0));
        self::assertSame([],$dep->push(hex2bin('790000'),90,true,1));
        $frames=$dep->push(hex2bin('610304'),90,true,2);
        self::assertSame(hex2bin('0000000165010200000001610304'),$frames[0]['data']);
    }

    public function testReportClockFactoriesAndIndependentLossIntervals():void
    {
        $schedule=SenderReportSchedule::onMediaClock(1000,16000,static fn():float=>0.5);
        self::assertFalse($schedule->shouldReport(0xfffff000));
        self::assertFalse($schedule->shouldReport((0xfffff000+15999)&0xffffffff));
        self::assertTrue($schedule->shouldReport((0xfffff000+16000)&0xffffffff));
        $wall=SenderReportSchedule::onWallClock(1000,static fn():float=>0.0);
        self::assertFalse($wall->shouldReport(0)); self::assertTrue($wall->shouldReport(900));
        $track=new RtpReception(16000);
        $track->observe(7,0,0,0); $track->observe(7,3,2880,180);
        self::assertSame(128,$track->report(180)['fractionLost']);
        self::assertSame(50.0,$track->takeLossPercent());
        for($i=4;$i<14;$i++) $track->observe(7,$i,$i*960,$i*60);
        self::assertSame(0.0,$track->takeLossPercent());
        self::assertGreaterThan(10,$track->lossPercent());
        self::assertSame(0,$track->report(780)['fractionLost']);
    }

    public function testSameSsrcSequenceRestartRetainsAuthenticatedSenderReportTiming():void
    {
        $track=new RtpReception(90000);
        foreach([65534,65535,0,1] as $i=>$seq) $track->observe(7,$seq,$i*3000,$i*33);
        $sr=Rtcp::senderReportWithSdes(7,4,400,9000,str_repeat('x',18),[],null,true,true,1234567);
        self::assertTrue($track->observeSenderReport($sr,500));
        $lsr=$track->report(600)['lastSenderReport']; self::assertNotSame(0,$lsr);
        $track->observe(7,50000,300,700); $track->observe(7,50001,600,733);
        $report=$track->report(1500);
        self::assertSame(50001,$report['highestSequence']); self::assertSame(0,$report['cycles']);
        self::assertSame($lsr,$report['lastSenderReport']); self::assertSame(65536,$report['delaySinceLastSenderReport']);
        $track->observe(8,0,0,1600); self::assertSame(0,$track->report(2000)['lastSenderReport']);
    }

    public function testRevokeDuringPcmCallbackStopsRemainingDecodedFrames():void
    {
        $frames=[]; $set=null;
        $set=new AudioReceiveSet(static fn(string $id):AudioDecoder=>new Post110Decoder([[0.25],[0.5]]),16000,90000,16,2,function(string $id,array $pcm)use(&$set,&$frames):void { $frames[]=$pcm; $set->sync([]); });
        $set->sync([['id'=>'a','audio'=>11,'streams'=>[12]]]);
        $set->receive(11,0,0,'packet',0,0);
        self::assertSame([[0.25]],$frames); self::assertSame(0,$set->pullPlayout(4)['real']);
    }

    public function testSenderRosterRetiresReportSourcesAndSilentActivity():void
    {
        $set=new AudioReceiveSet(static fn(string $id):AudioDecoder=>new Post110Decoder());
        $set->sync([['id'=>'a','audio'=>11,'streams'=>[12]]]);
        $set->receive(11,0,0,'audio',0,0); $set->observeVideo(12,0,0,0);
        self::assertSame('a',$set->noteHeard(12,0)); self::assertNull($set->noteHeard(12,1));
        self::assertSame([],$set->takeSilent(19999,20000)); self::assertSame(['a'],$set->takeSilent(20001,20000));
        $set->sync([['id'=>'a','audio'=>21,'streams'=>[22]]]);
        self::assertSame([],$set->audioSourceSsrcs()); self::assertSame([],$set->videoSourceSsrcs());
        self::assertFalse($set->has(12)); $set->clear();
    }

    public function testVideoWireExtensionKeyframeGateHoldAndTimestampWrap():void
    {
        $now=0.0; $sent=[]; $controls=[]; $engine=$this->engine($now,$sent,$controls);
        self::assertSame([],$engine->sendVideoAnnexB(hex2bin('000000016101'),0));
        $frame=hex2bin('0000000165').str_repeat('v',1900);
        $packets=$engine->sendVideoAnnexB($frame,0xffffffff);
        self::assertCount(3,$packets);
        foreach($packets as $i=>$packet) {
            $rtp=Rtp::decode($packet); self::assertSame(97,$rtp['payloadType']); self::assertSame(202,$rtp['ssrc']); self::assertLessThanOrEqual(800,strlen($rtp['payload']));
            self::assertSame(0xdebe,$rtp['extensionProfile']);
            self::assertSame($i===0, str_contains($rtp['extensionData'],hex2bin('d3010493e0')));
        }
        self::assertSame(0,Rtp::decode($engine->sendVideoAnnexB(hex2bin('000000016101'),0xffffffff)[0])['timestamp']);
        $engine->setVideoEnabled(false); $engine->setVideoEnabled(true);
        self::assertSame([],$engine->sendVideoAnnexB(hex2bin('000000016101'),1));
        self::assertNotEmpty($engine->sendVideoAnnexB(hex2bin('000000016501'),1));
        self::assertTrue($engine->stats()['videoExtensionsEnabled']); $engine->close();
    }

    public function testSenderReportAdvancesCaptureTimestampAndGroupReportsOmitTrailer():void
    {
        $now=0.0; $sent=[]; $controls=[]; $delay=false;
        $engine=$this->engine($now,$sent,$controls,['protectVideoPayload'=>function(string $p,int $ts,int $pt,int $slot,bool $marker,string $extension,int $profile)use(&$now,&$delay):string { if($delay) $now+=50; return Rtp::encode($p,202,0,$ts,$pt,$marker,[],$extension,$profile); }]);
        $engine->configureSenders([['id'=>'a','audio'=>11,'streams'=>[12,13]],['id'=>'b','audio'=>21,'streams'=>[22]]]);
        foreach([[12,0],[13,0],[22,0]] as [$ssrc,$seq]) $engine->handlePacket(self::inbound(hex2bin('6501'),$ssrc,$seq,22500,97,true));
        $engine->sendVideoAnnexB(hex2bin('000000016501'),22500);
        $engine->tick(); $now=1000; $engine->tick();
        self::assertSame([],$this->controls($controls,static fn(array $c):bool=>$c['type']===200));
        $delay=true; $engine->sendVideoAnnexB(hex2bin('000000016501'),112500);
        $reports=$this->controls($controls,static fn(array $c):bool=>$c['type']===200);
        self::assertCount(1,$reports); self::assertSame(117000,$reports[0]['rtpTimestamp']);
        self::assertSame(2208988801,$reports[0]['ntpSeconds']); self::assertSame(0,$reports[0]['ntpFraction']);
        self::assertSame([12,22],array_column($reports[0]['reports'],'ssrc')); self::assertArrayNotHasKey('trailer',$reports[0]);
        $engine->close();
    }

    public function testAudioRembUsesIntervalLossAndLocalAudioSsrc():void
    {
        $now=0.0; $sent=[]; $controls=[]; $engine=$this->engine($now,$sent,$controls);
        $engine->configureSenders([['id'=>'peer','audio'=>11,'streams'=>[]]]);
        $engine->handlePacket(self::inbound('audio',11,0,0,120)); $engine->handlePacket(self::inbound('audio',11,3,2880,120));
        $engine->tick(); $now=1000; $engine->tick();
        for($i=4;$i<14;$i++) $engine->handlePacket(self::inbound('audio',11,$i,$i*960,120));
        $now=2000; $engine->tick();
        $remb=$this->controls($controls,static fn(array $c):bool=>($c['kind']??'')==='remb');
        self::assertSame([24000,26400],array_column($remb,'bitsPerSecond')); self::assertSame([200,200],array_column($remb,'senderSsrc')); $engine->close();
    }

    public function testVideoRembGateLeavesFastRembAndFifoStreamAdmission():void
    {
        $now=0.0; $sent=[]; $controls=[]; $events=[]; $engine=$this->engine($now,$sent,$controls,['maxVideoStreams'=>2,'videoRembDisabled'=>true,'emit'=>function(string $name,array $data)use(&$events):void { $events[$name][]=$data; }]);
        foreach([11,12,13] as $ssrc) { $engine->handlePacket(self::inbound(hex2bin('6101'),$ssrc,0,0,97,true)); $now+=200; }
        self::assertCount(3,$events['voip_video_frame']); self::assertSame(1,$engine->stats()['videoStreamsEvicted']);
        self::assertSame(3,$engine->stats()['pliSent']); self::assertSame(3,$engine->stats()['firSent']);
        $now=1000; $engine->tick(); $now=2000; $engine->tick();
        self::assertSame([],$this->controls($controls,static fn(array $c):bool=>($c['kind']??'')==='remb'));
        self::assertSame(450000,$engine->stats()['videoBitrate']);
        $rtp=Rtp::decode($engine->sendVideoAnnexB(hex2bin('000000016501'))[0]);
        self::assertStringContainsString(hex2bin('d30106ddd0'),$rtp['extensionData']); $engine->close();
    }

    public function testCallbackRevocationCannotDeliverQueuedVideoOrActivity():void
    {
        $now=0.0; $sent=[]; $controls=[]; $frames=[]; $engine=null;
        $engine=$this->engine($now,$sent,$controls,['emit'=>function(string $name,array $data)use(&$engine,&$frames):void { if($name==='voip_video_frame') { $frames[]=$data; $engine->configureSenders([]); } }]);
        $engine->configureSenders([['id'=>'peer','audio'=>11,'streams'=>[12]]]);
        $engine->handlePacket(self::inbound(hex2bin('6501'),12,0,0,97,false));
        $engine->handlePacket(self::inbound(hex2bin('6502'),12,2,180,97,true));
        $engine->handlePacket(self::inbound(hex2bin('6503'),12,1,90,97,true));
        self::assertCount(1,$frames); self::assertSame('peer',$frames[0]['senderId']);
        $now=1000; $engine->tick(); self::assertCount(1,$frames); $engine->close();
    }

    public function testGroupVideoBoundAccommodatesTwoStreamsPerRetainedSender():void
    {
        $now=0.0; $sent=[]; $controls=[]; $engine=$this->engine($now,$sent,$controls); $plan=[];
        for($i=1;$i<=10;$i++) $plan[]=['id'=>'sender'.$i,'audio'=>$i*10,'streams'=>[$i*10+1,$i*10+2]];
        $engine->configureSenders($plan);
        foreach($plan as $sender) foreach($sender['streams'] as $ssrc) $engine->handlePacket(self::inbound(hex2bin('6501'),$ssrc,0,0,97,true));
        self::assertSame(20,$engine->stats()['videoFrames']); self::assertSame(0,$engine->stats()['videoStreamsEvicted']);
        $engine->configureSenders(array_slice($plan,0,1));
        $engine->handlePacket(self::inbound(hex2bin('6502'),101,1,90,97,true)); self::assertSame(20,$engine->stats()['videoFrames']); self::assertSame(1,$engine->stats()['unknownDiscarded']); $engine->close();
    }

    public function testSettingsRejectUnsupportedMlowFrameAtomically():void
    {
        $now=0.0; $sent=[]; $controls=[]; $engine=$this->engine($now,$sent,$controls,[],true);
        foreach([['rtcpIntervalMs'=>2000,'audioFrameMs'=>20]] as $settings) {
            try { $engine->applyVoipSettings($settings); self::fail('Unsupported settings accepted'); } catch(\LogicException) {}
            self::assertSame(1000,$engine->stats()['reportIntervalMs']);
        }
        $engine->applyVoipSettings(['rtcpIntervalMs'=>1500,'disableRtcpRemb'=>true,'audioFrameMs'=>60,'audioMaxFramesPerPacket'=>3]);
        self::assertSame(1500,$engine->stats()['reportIntervalMs']); self::assertSame(2,$engine->stats()['audioFramesPerPacket']); self::assertSame(960,$engine->stats()['audioFrameSamples']); self::assertSame([],$controls); self::assertSame([],$sent); $engine->close();
    }

    public function testSframeRequiresBothGatesAndDoesNotBlockRtpMedia():void
    {
        $now=0.0; $sent=[]; $controls=[]; $engine=$this->engine($now,$sent,$controls);
        foreach([[false,false,false],[true,false,false],[false,true,false],[true,true,true]] as [$send,$receive,$expected]) {
            $engine->applyVoipSettings(['sframe'=>$send,'sframeRx'=>$receive]);
            self::assertSame($expected,$engine->stats()['appDataSframeRequested']);
            self::assertFalse($engine->stats()['appDataSframeSupported']);
            self::assertSame([],$sent); self::assertSame([],$controls);
        }
        self::assertCount(1,$engine->sendAudioPcm(self::pcm()));
        self::assertCount(1,$engine->sendVideoAnnexB(hex2bin('000000016501')));
        self::assertSame(120,Rtp::decode($sent[0])['payloadType']); self::assertSame(97,Rtp::decode($sent[1])['payloadType']); $engine->close();
    }

    public function testNativeMlowCopyFirstRedRecoversPreviousFrameWithoutDuplicatePlayout():void
    {
        $now=0.0; $sent=[]; $controls=[]; $engine=$this->engine($now,$sent,$controls,['audioRed'=>true,'audioRedMode'=>'copy-first'],true);
        $engine->sendAudioPcm(self::pcm()); $now=60; $engine->sendAudioPcm(self::pcm());
        $first=Rtp::decode($sent[0]); $second=Rtp::decode($sent[1]);
        self::assertSame(120,$second['payloadType']); self::assertSame($first['timestamp'],$second['timestamp']); self::assertFalse($second['marker']); self::assertSame(1920,MLowPacket::samples($second['payload']));
        $decoder=(new MLowCodec())->newDecoder();
        self::assertCount(1,$decoder->decodeSequenced(0,$first['payload'],$first['timestamp'])['frames']);
        self::assertCount(1,$decoder->decodeSequenced(1,$second['payload'],$second['timestamp'])['frames']); $decoder->close();
        $recover=(new MLowCodec())->newDecoder(); self::assertCount(2,$recover->decodeSequenced(1,$second['payload'],$second['timestamp'])['frames']); $recover->close();
        $now=300; $engine->sendAudioPcm(self::pcm()); $afterGap=Rtp::decode($sent[2]); self::assertTrue($afterGap['marker']); self::assertSame(960,MLowPacket::samples($afterGap['payload'])); $engine->close();
    }

    public function testNativeSpeechAggregationDropsPendingFrameOnRekeyAndMute():void
    {
        $now=0.0; $sent=[]; $controls=[]; $engine=$this->engine($now,$sent,$controls,[],true);
        $engine->applyVoipSettings(['audioMaxFramesPerPacket'=>2]);
        self::assertSame([],$engine->sendAudioPcm(self::pcm())); $now=60;
        $combined=$engine->sendAudioPcm(self::pcm()); self::assertCount(1,$combined); self::assertSame(1920,MLowPacket::samples(Rtp::decode($combined[0])['payload']));
        $now=120; self::assertSame([],$engine->sendAudioPcm(self::pcm())); $engine->resetReceiveState(); self::assertSame(1,$engine->stats()['audioPendingDropped']);
        $now=180; self::assertSame([],$engine->sendAudioPcm(self::pcm())); $engine->setAudioEnabled(false); self::assertSame(2,$engine->stats()['audioPendingDropped']);
        $engine->setAudioEnabled(true); $now=240; self::assertSame([],$engine->sendAudioPcm(self::pcm())); $now=300;
        $combined=$engine->sendAudioPcm(self::pcm()); self::assertCount(1,$combined); self::assertTrue(Rtp::decode($combined[0])['marker']); $engine->close();
    }

    public function testAudioSenderReportsPaceOnMediaTicksAndFailureMarksNextPacket():void
    {
        if(!extension_loaded('FFI') || !is_file(OpusCodec::defaultLibrary())) self::markTestSkipped('Native Opus not installed');
        $now=0.0; $controls=[]; $sent=[]; $failure=true; $seq=0;
        $engine=new CallMediaEngine(new OpusCodec(),function(string $p,int $ts,int $pt,int $slot,bool $marker)use(&$seq):string { return Rtp::encode($p,200,$seq++,$ts,$pt,$marker); },function(string $packet)use(&$failure,&$sent):void { if($failure) { $failure=false; throw new \RuntimeException('Synthetic transport send failure'); } $sent[]=$packet; },function(string $p)use(&$controls):void { $controls[]=$p; },['now'=>function()use(&$now):float { return $now; },'random'=>static fn():float=>0.5]);
        try { $engine->sendAudioPcm(self::pcm()); self::fail('Transport failure was swallowed'); } catch(\RuntimeException $e) { self::assertSame('Synthetic transport send failure',$e->getMessage()); }
        $now=60; $engine->sendAudioPcm(self::pcm()); self::assertTrue(Rtp::decode($sent[0])['marker']);
        $engine->tick(); $now=1060; $engine->tick(); self::assertSame([],$this->controls($controls,static fn(array $c):bool=>$c['type']===200));
        $engine->sendAudioPcm(self::pcm()); $reports=$this->controls($controls,static fn(array $c):bool=>$c['type']===200); self::assertCount(1,$reports); self::assertSame(20960,$reports[0]['rtpTimestamp']); $engine->close();
    }

    public function testMuteFromSendCallbackStopsRemainingVideoFragments():void
    {
        if(!extension_loaded('FFI') || !is_file(OpusCodec::defaultLibrary())) self::markTestSkipped('Native Opus not installed');
        $engine=null; $sent=[];
        $engine=new CallMediaEngine(new OpusCodec(),static fn(string $p,int $ts,int $pt,int $slot,bool $marker):string=>Rtp::encode($p,202,0,$ts,$pt,$marker),function(string $p)use(&$engine,&$sent):void { $sent[]=$p; $engine->setVideoEnabled(false); },static function():void {});
        self::assertCount(1,$engine->sendVideoAnnexB(hex2bin('0000000165').str_repeat('v',1900)));
        self::assertCount(1,$sent); $engine->setVideoEnabled(true);
        self::assertSame([],$engine->sendVideoAnnexB(hex2bin('000000016101'))); $engine->close();
    }

    public function testWarpHeaderFlagIsNotPartOfLengthAndControlIsNotRtp():void
    {
        $rtp=Rtp::encode('payload',1,2,3,120);
        self::assertSame($rtp,WarpFrame::unwrap(hex2bin('0982000000000000').$rtp));
        self::assertSame($rtp,WarpFrame::unwrap(hex2bin('09070000').str_repeat("\0",14).$rtp));
        self::assertNull(WarpFrame::unwrap(hex2bin('09000000').str_repeat("\0",12)));
        $this->expectException(\UnexpectedValueException::class); WarpFrame::unwrap(hex2bin('09070000'));
    }

    private static function pcm():array { $pcm=[]; for($i=0;$i<960;$i++) $pcm[]=0.3*sin(2*M_PI*440*$i/16000); return $pcm; }
    private static function inbound(string $payload,int $ssrc,int $sequence,int $timestamp,int $pt,bool $marker=false):array { return ['kind'=>'rtp','payload'=>$payload,'ssrc'=>$ssrc,'sequence'=>$sequence,'timestamp'=>$timestamp,'payloadType'=>$pt,'marker'=>$marker]; }
    private function engine(float &$now,array &$sent,array &$controls,array $options=[],bool $mlow=false):CallMediaEngine
    {
        if(!extension_loaded('FFI') || !is_file(OpusCodec::defaultLibrary()) || ($mlow && !is_file(MLowCodec::defaultMLowLibrary()))) self::markTestSkipped('Native audio codec not installed');
        $seq=0;
        $protect=function(string $payload,int $timestamp,int $pt,int $slot,bool $marker,?string $extension=null,int $profile=Rtp::EXTENSION_PROFILE)use(&$seq):string { return Rtp::encode($payload,200+$slot,$seq++,$timestamp,$pt,$marker,[],$extension,$profile); };
        return new CallMediaEngine($mlow?new MLowCodec():new OpusCodec(),$protect,function(string $packet)use(&$sent):void { $sent[]=$packet; },function(string $packet)use(&$controls):void { $controls[]=$packet; },['now'=>function()use(&$now):float { return $now; },'unixMs'=>static fn():float=>1000,'random'=>static fn():float=>0.5,'protectVideoPayload'=>$protect,'createDecoder'=>static fn(string $id):AudioDecoder=>new Post110Decoder(),'localAudioSsrc'=>200,'localVideoSsrc'=>202,...$options]);
    }
    private function controls(array $packets,callable $predicate):array { $out=[]; foreach($packets as $packet) foreach(Rtcp::parse($packet) as $control) if($predicate($control)) $out[]=$control; return $out; }
}

/** Deterministic codec double: transport/revocation tests do not decode untrusted byte patterns. */
final class Post110Decoder implements AudioDecoder
{
    public function __construct(private readonly array $frames=[]) {}
    public function decodeSequenced(int $sequence,string $packet,?int $timestamp=null,int $stream=0):array { return ['outcome'=>'decoded','frames'=>$this->frames]; }
    public function stats():array { return []; }
    public function resetSequence():void {}
    public function close():void {}
}
