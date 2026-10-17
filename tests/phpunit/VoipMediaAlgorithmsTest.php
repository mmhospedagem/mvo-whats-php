<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Voip\{AudioCodecNegotiation,AudioDecoder,AudioReceiveSet,H264,H264Depacketizer,HostCaptureTimeMapper,JitterBuffer,MediaClock,MediaPayload,MLowPacket,Rtcp,RtpReception,SenderReportSchedule};
use MVO\Protocol\BinaryNode;

final class VoipMediaAlgorithmsTest extends TestCase
{
    public function testFeedbackMatchesIndependentRfcWireVectors():void
    {
        self::assertSame('81ce00020102030411223344',bin2hex(Rtcp::pli(0x01020304,0x11223344)));
        self::assertSame('91ce00020102030411223344',bin2hex(Rtcp::pli(0x01020304,0x11223344,true)));
        self::assertSame('84ce0004010203040000000011223344ff000000',bin2hex(Rtcp::fir(0x01020304,0x11223344,255)));
        self::assertSame('81cd0003010203041122334400648003',bin2hex(Rtcp::nack(0x01020304,0x11223344,[100,101,102,116])));
        self::assertSame([65535,0,1,14],Rtcp::parse(Rtcp::nack(1,2,[65535,0,1,14]))[0]['sequences']);
        self::assertSame('8fce0005010203040000000052454d42010649f011223344',bin2hex(Rtcp::remb(0x01020304,0x11223344,300000)));
        $compound=Rtcp::pli(1,2,true).Rtcp::fir(1,2,3).Rtcp::remb(1,[2,3],300000).Rtcp::nack(1,2,[5,6]);
        $parsed=Rtcp::parse($compound);
        self::assertSame(['pli','fir','remb','nack'],array_column($parsed,'kind'));
        self::assertSame(300000,$parsed[2]['bitsPerSecond']);
        self::assertSame([2,3],$parsed[2]['ssrcs']);
    }
    public function testReceiverReportSignedLossAndSaturation():void
    {
        $wire=Rtcp::receiverReport(1,[['ssrc'=>2,'fractionLost'=>32,'packetsLost'=>-2,'cycles'=>3,'highestSequence'=>65535,'jitter'=>19,'lastSenderReport'=>20,'delaySinceLastSenderReport'=>65536]]);
        self::assertSame('81c90007000000010000000220fffffe0003ffff000000130000001400010000',bin2hex($wire));
        $report=Rtcp::parse($wire)[0]['reports'][0];
        self::assertSame(-2,$report['packetsLost']); self::assertSame(3,$report['cycles']);
        self::assertSame(0x7fffff,Rtcp::parse(Rtcp::receiverReport(1,[['packetsLost'=>PHP_INT_MAX]]))[0]['reports'][0]['packetsLost']);
    }
    public function testWhatsappSenderReportMatchesUpstreamCapturedImage():void
    {
        $seed=''; for($i=0;$i<18;$i++) $seed.=chr($i*7+1);
        $reception=['ssrc'=>0x0a0b0c0d,'fractionLost'=>42,'packetsLost'=>0x010203,'highestSequence'=>0xbeef,'cycles'=>3,'jitter'=>0x1234,'lastSenderReport'=>0xaabbccdd,'delaySinceLastSenderReport'=>65536];
        $packet=Rtcp::senderReportWithSdes(0x01020304,25,2400,0x0a0b0c0d,$seed,[$reception],0x01020304,true,false,0);
        $image=substr_replace($packet,str_repeat("\0",8),8,8);
        self::assertSame('81c800120102030400000000000000000a0b0c0d00000019000009600a0b0c0d2a0102030003beef00001234aabbccdd0001000001020304000248000000000000000000000000000000500081ca0007010203040112326233323340706a3934303437342e6f726700000000',bin2hex($image));
        $parsed=Rtcp::parse($packet); self::assertCount(2,$parsed); self::assertSame($reception+['blockCounter'=>0x01020304,'converged'=>0x24800],$parsed[0]['reports'][0]);
        $video=Rtcp::parse(Rtcp::senderReportWithSdes(1,2,3,4,$seed,[$reception],null,false,true,1500))[0];
        self::assertTrue($video['whatsappVideoProfile']); self::assertSame(0x80000000,$video['ntpFraction']);
    }
    public function testRtcpRejectsMalformedCompoundPaddingAndFeedback():void
    {
        foreach(["",hex2bin('81ce0003').str_repeat("\0",8),hex2bin('81ce00020102030411223344ff'),hex2bin('81cd00020000000100000002'),hex2bin('8fce0004000000010000000052454d4201000000'),hex2bin('a1ce0002000000010000000200')] as $packet) {
            try { Rtcp::parse($packet); self::fail('Accepted malformed RTCP '.bin2hex($packet)); } catch(\UnexpectedValueException) { self::addToAssertionCount(1); }
        }
    }
    public function testLossJitterSequenceWrapDuplicatesAndReportTiming():void
    {
        $tracker=new RtpReception(16000);
        $tracker->observe(7,65534,0xfffffc40,0); $tracker->observe(7,0,960,120);
        $report=$tracker->report(120); self::assertSame(1,$report['packetsLost']); self::assertSame(85,$report['fractionLost']); self::assertSame(1,$report['cycles']); self::assertSame(0,$report['jitter']);
        $tracker->observe(7,65535,0,130); $tracker->observe(7,1,1920,180);
        self::assertSame(0.0,$tracker->lossPercent()); self::assertSame(0,$tracker->report(180)['packetsLost']);
        $tracker->observe(7,1,1920,190); self::assertSame(-1,$tracker->report(190)['packetsLost']);
        $senderReport=Rtcp::senderReportWithSdes(7,1,1,1,str_repeat('a',18),[],null,true,false,1000.5);
        self::assertTrue($tracker->observeSenderReport($senderReport,0));
        $report=$tracker->report(1000); self::assertSame(65536,$report['delaySinceLastSenderReport']); self::assertNotSame(0,$report['lastSenderReport']);
        self::assertFalse($tracker->observeSenderReport(Rtcp::receiverReport(7),100));
        $tracker->observe(8,5,0,200); self::assertSame(8,$tracker->sourceSsrc()); self::assertSame(0,$tracker->report(200)['lastSenderReport']);
    }
    public function testReceiverEstimatesAndFastExtensionSaturate():void
    {
        self::assertSame(300000,Rtcp::nextVideoBitrate(0,0,0,0)); self::assertSame(450000,Rtcp::nextVideoBitrate(300000,0,1000,0));
        self::assertSame(68000,Rtcp::nextVideoBitrate(300000,10000,1000,12)); self::assertSame(300000,Rtcp::nextVideoBitrate(300000,0,1000,5));
        self::assertSame(26400,Rtcp::nextAudioBitrate(24000,0)); self::assertSame(16000,Rtcp::nextAudioBitrate(16000,50));
        self::assertSame('d301ffffff',bin2hex(Rtcp::fastRembExtension(PHP_INT_MAX))); self::assertSame('d301000000',bin2hex(Rtcp::fastRembExtension(-1)));
    }
    public function testSharedMediaClockMapperDriftAndReportScheduleWrap():void
    {
        $clock=new MediaClock(static fn()=>1000); self::assertFalse($clock->isStarted()); $clock->start(); $clock->start(9999);
        self::assertSame(4000,$clock->ticksAt(1000,16000)); self::assertSame(22500,$clock->ticksAt(1000,90000)); self::assertSame(0,$clock->ticksAt(700,16000));
        self::assertSame(0,$clock->ticksAt(750+4294967296/16000*1000,16000));
        $mapper=new HostCaptureTimeMapper(1/90); self::assertSame(1000.0,$mapper->map(100000,1000)); self::assertSame(1030.0,$mapper->map(100030,1030));
        self::assertGreaterThan(1030,$mapper->map(100029,1031)); self::assertSame(1050.0,$mapper->map(0,1050));
        $schedule=new SenderReportSchedule(100,static fn()=>0.5); self::assertFalse($schedule->shouldReport(0xfffffff0)); self::assertFalse($schedule->shouldReport(50)); self::assertTrue($schedule->shouldReport(84));
    }
    public function testJitterOverflowWrapUnderrunAndResetCounters():void
    {
        $buffer=new JitterBuffer(4); $buffer->write([1,2,3]); self::assertSame(['samples'=>[1.0,2.0],'real'=>2],$buffer->read(2));
        $buffer->write([4,5,6,7]); self::assertSame(['samples'=>[4.0,5.0,6.0,7.0,0.0],'real'=>4],$buffer->read(5));
        $buffer->write([1,2,3,4,5,6]); self::assertSame(3,$buffer->stats()['dropped']); $buffer->reset(); self::assertSame(0,$buffer->buffered()); self::assertSame(1,$buffer->stats()['underruns']);
    }
    public function testH264PacketizationMatchesIndependentNalAndFuVectors():void
    {
        $frame=hex2bin('000000016742c01f0000000168ce3c8000000001658884');
        self::assertSame(['7800046742c01f000468ce3c800003658884'],array_map(bin2hex(...),H264::packetize($frame)['payloads']));
        self::assertSame(['7c85010203','7c05040506','7c450708'],array_map(bin2hex(...),H264::packetize(hex2bin('650102030405060708'),5)['payloads']));
        self::assertFalse(H264::isKeyFrame(hex2bin('000001674200000168ce000001419a')));
        $depacketizer=new H264Depacketizer(); $packets=H264::packetize($frame,7)['payloads']; $frames=[];
        foreach($packets as $i=>$packet) $frames=[...$frames,...$depacketizer->push($packet,90,$i===count($packets)-1,(65534+$i)&65535)];
        self::assertCount(1,$frames); self::assertSame($frame,$frames[0]['data']); self::assertTrue($frames[0]['keyFrame']);
    }
    public function testH264GapsMalformedStapAndMemoryBoundNeverEmitTruncatedNal():void
    {
        $d=new H264Depacketizer(); self::assertSame([],$d->push(hex2bin('7c850102'),10,false,0)); self::assertSame([],$d->push(hex2bin('7c450304'),10,true,2));
        self::assertSame([],$d->push(hex2bin('7800026701000868ff'),11,true,3));
        self::assertSame([], (new H264Depacketizer(10))->push(str_repeat('a',20),12,true,4));
        $frames=$d->push(hex2bin('7cc742c00000000168ce000001658884'),13,true,5); self::assertTrue($frames[0]['keyFrame']);
    }
    public function testMlowRedAndMultiframeIndependentHeaderVectors():void
    {
        $a=hex2bin('500102'); $b=hex2bin('50030405');
        self::assertSame('92020350010250030405',bin2hex(MLowPacket::pack([$a,$b]))); self::assertSame(1920,MLowPacket::samples(MLowPacket::pack([$a,$b])));
        $red=MLowPacket::writeRed($b,1920,[['packet'=>$a,'timestamp'=>960]]); self::assertSame('86030050010250030405',bin2hex($red));
        self::assertSame(['primary'=>$b,'copies'=>[['packet'=>$a,'delta'=>960]]],MLowPacket::readRed($red));
        self::assertSame([],MLowPacket::frames(hex2bin('9202ff50'))); self::assertNull(MLowPacket::readRed(hex2bin('86050050')));
        self::assertSame('audio',MediaPayload::kind(121)); self::assertSame('reed-solomon-parity',MediaPayload::kind(106)); self::assertSame('h264',MediaPayload::kind(97)); self::assertSame('unknown',MediaPayload::kind(100));
    }
    public function testAudioReceiveSetKeepsPerSenderDecodersAndClipsOnlyMix():void
    {
        $created=[]; $set=new AudioReceiveSet(function($id)use(&$created){return $created[$id]=new class implements AudioDecoder {
            public int $closed=0; public int $resets=0;
            public function decodeSequenced(int $sequence,string $packet,?int $timestamp=null,int $stream=0):array { return ['outcome'=>'decoded','frames'=>[[0.8,-0.8,0.2]]]; }
            public function stats():array { return ['success'=>1]; } public function resetSequence():void { ++$this->resets; } public function close():void { ++$this->closed; }
        };},16000,90000,10);
        $specs=[['id'=>'a','audio'=>1,'streams'=>[2,3]],['id'=>'b','audio'=>4,'streams'=>[5,6]]]; $set->sync($specs); self::assertSame(2,$set->size());
        self::assertSame('a',$set->noteHeard(1,100)); self::assertNull($set->noteHeard(2,101));
        $set->receive(1,0,0,'packet',0,100); $set->receive(4,0,0,'packet',0,100);
        self::assertSame(['samples'=>[1.0,-1.0,0.4,0.0],'real'=>3],$set->pullPlayout(4));
        $set->sync($specs); self::assertCount(2,$created); self::assertSame(['a'],$set->takeSilent(200,99));
        $set->observeVideo(2,1,0,0); $set->observeVideo(3,50,0,1); self::assertSame([2],$set->videoSourceSsrcs());
        self::assertTrue($set->noteAuthFailure(2)); self::assertSame(1,$set->stats()[0]['authFailures']);
        $set->resetSequences(); self::assertSame(1,$created['a']->resets); $set->sync([$specs[1]]); self::assertSame(1,$created['a']->closed); $set->clear(); self::assertSame(1,$created['b']->closed);
    }
    public function testAudioCodecNegotiationHonorsLocalPreferenceAndMlowRate():void
    {
        $offer=new BinaryNode('offer',[],[new BinaryNode('audio',['enc'=>'opus','rate'=>'8000']),new BinaryNode('audio',['enc'=>'opus','rate'=>'16000'])]);
        self::assertSame(['enc'=>'opus','rate'=>16000,'channels'=>1,'payloadType'=>120,'implementation'=>'mlow'],AudioCodecNegotiation::select($offer,['opus'=>[8000,16000]],true));
        $this->expectException(\UnexpectedValueException::class); AudioCodecNegotiation::select($offer,['mlow'=>[16000]]);
    }
}
