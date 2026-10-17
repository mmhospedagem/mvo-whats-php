<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Voip\{CallCrypto,CallMediaEngine,H264,MLowCodec,OpusCodec,Rtcp,Rtp,SrtpSession};

final class CallMediaEngineTest extends TestCase
{
    protected function setUp():void { if(!extension_loaded('FFI') || !is_file(OpusCodec::defaultLibrary()) || !is_file(MLowCodec::defaultMLowLibrary())) self::markTestSkipped('Native audio codecs not installed'); }
    private static function pcm():array { $samples=[]; for($i=0;$i<960;$i++) $samples[]=0.3*sin(2*M_PI*440*$i/16000); return $samples; }
    private static function inbound(string $payload,int $ssrc,int $sequence,int $timestamp,int $pt=97,bool $marker=false):array { return ['kind'=>'rtp','payload'=>$payload,'ssrc'=>$ssrc,'sequence'=>$sequence,'timestamp'=>$timestamp,'payloadType'=>$pt,'marker'=>$marker]; }
    public function testNackRetransmitsExactProtectedPacketWithoutAdvancingSrtpIndex():void
    {
        $now=0.0; $protected=0; $sent=[]; $controls=[]; $sequence=10;
        $srtp=new SrtpSession(str_repeat('k',16),str_repeat('s',14));
        $engine=new CallMediaEngine(new OpusCodec(),function($payload,$timestamp,$pt,$slot,$marker)use(&$protected,&$sequence,$srtp){++$protected; return $srtp->protect(Rtp::encode($payload,$slot===2?77:78,$sequence++,$timestamp,$pt,$marker));},function($packet)use(&$sent){$sent[]=$packet;},function($packet)use(&$controls){$controls[]=$packet;},['now'=>function()use(&$now){return $now;},'cacheLimit'=>2,'cacheMs'=>100]);
        $ciphertext=$engine->sendVideoAnnexB(hex2bin('0000000165010203'),90)[0]; self::assertSame(1,$protected);
        for($i=0;$i<4;$i++) $engine->handlePacket(['kind'=>'rtcp','bytes'=>Rtcp::nack(99,77,[10])]);
        self::assertSame([$ciphertext,$ciphertext,$ciphertext,$ciphertext],$sent); self::assertSame(1,$protected); self::assertSame(3,$engine->stats()['retransmitted']);
        $engine->sendVideoAnnexB(hex2bin('0000000165010203'),180); $now=101;
        $engine->handlePacket(['kind'=>'rtcp','bytes'=>Rtcp::nack(99,77,[11])]); self::assertSame(5,count($sent)); self::assertSame(2,$protected);
        self::assertSame(0,$engine->stats()['retransmitCached']); $engine->close();
    }
    public function testVideoNackRepairReordersFragmentsBeforeH264Reassembly():void
    {
        $now=1000.0; $controls=[]; $events=[];
        $engine=new CallMediaEngine(new OpusCodec(),static fn($payload,$timestamp,$pt,$slot,$marker)=>Rtp::encode($payload,77,10,$timestamp,$pt,$marker),static function(){},function($packet)use(&$controls){$controls[]=$packet;},['now'=>function()use(&$now){return $now;},'emit'=>function($event,$data)use(&$events){$events[$event][]=$data;}]);
        $frame=hex2bin('00000001650102030405060708'); $payloads=H264::packetize($frame,5)['payloads'];
        $engine->handlePacket(self::inbound($payloads[0],22,65535,90)); $engine->handlePacket(self::inbound($payloads[2],22,1,90,97,true));
        self::assertArrayNotHasKey('voip_video_frame',$events); self::assertSame('nack',Rtcp::parse($controls[0])[0]['kind']); self::assertSame([0],Rtcp::parse($controls[0])[0]['sequences']);
        $now+=20; $engine->handlePacket(self::inbound($payloads[1],22,0,90));
        self::assertCount(1,$events['voip_video_frame']); self::assertSame($frame,$events['voip_video_frame'][0]['data']);
        self::assertSame(1,$engine->stats()['nackSent']); self::assertSame(1,$engine->stats()['pliSent']); $engine->close();
    }
    public function testUnrepairedVideoGapExpiresAndDoesNotEmitPartialFrame():void
    {
        $now=0.0; $events=[];
        $engine=new CallMediaEngine(new OpusCodec(),static fn($payload,$timestamp,$pt,$slot,$marker)=>Rtp::encode($payload,77,10,$timestamp,$pt,$marker),static function(){},static function(){},['now'=>function()use(&$now){return $now;},'emit'=>function($event,$data)use(&$events){$events[$event][]=$data;}]);
        $engine->handlePacket(self::inbound(hex2bin('7c850102'),22,0,90)); $engine->handlePacket(self::inbound(hex2bin('7c450304'),22,2,90,97,true));
        $now=101; $engine->tick(); self::assertArrayNotHasKey('voip_video_frame',$events);
        $engine->handlePacket(self::inbound(hex2bin('650506'),22,3,180,97,true)); self::assertSame(hex2bin('00000001650506'),$events['voip_video_frame'][0]['data']); $engine->close();
    }
    public function testAudioPcmReceptionSenderReportFeedbackAndActiveToggles():void
    {
        $now=1000.0; $events=[]; $sent=[]; $controls=[]; $sequence=0;
        $engine=new CallMediaEngine(new MLowCodec(),function($payload,$timestamp,$pt,$slot,$marker)use(&$sequence){return Rtp::encode($payload,77+$slot,$sequence++,$timestamp,$pt,$marker);},function($packet)use(&$sent){$sent[]=$packet;},function($packet)use(&$controls){$controls[]=$packet;},['now'=>function()use(&$now){return $now;},'random'=>static fn()=>0.5,'emit'=>function($event,$data)use(&$events){$events[$event][]=$data;}]);
        $engine->setAudioEnabled(false); self::assertSame([],$engine->sendAudioPcm(self::pcm())); self::assertSame([],$sent);
        $engine->setAudioEnabled(true); $engine->sendAudioPcm(self::pcm()); self::assertSame(120,Rtp::decode($sent[0])['payloadType']); self::assertSame(4000,Rtp::decode($sent[0])['timestamp']);
        $encoder=new MLowCodec(); $payload=$encoder->encode(self::pcm()); $engine->handlePacket(self::inbound($payload,99,0,0,120));
        $pcm=$engine->pullAudio(960); self::assertSame(960,$pcm['real']); self::assertGreaterThan(1,array_sum(array_map(static fn($x)=>$x*$x,$pcm['samples'])));
        self::assertCount(1,$events['voip_audio_frame']);
        $engine->handlePacket(self::inbound('opaque-parity',199,1,0,106)); self::assertSame(1,$engine->stats()['fecDiscarded']); self::assertSame(0,$engine->stats()['videoReceived']);
        $engine->tick(); $now=2000; $engine->tick(); $engine->sendAudioPcm(self::pcm()); $parsed=[]; foreach($controls as $control) $parsed=[...$parsed,...Rtcp::parse($control)];
        self::assertContains(200,array_column($parsed,'type')); self::assertContains('remb',array_column($parsed,'kind'));
        $engine->handlePacket(['kind'=>'rtcp','bytes'=>Rtcp::pli(99,77).Rtcp::remb(99,77,300000)]); self::assertCount(1,$events['voip_keyframe_requested']); self::assertCount(1,$events['voip_bitrate_estimate']);
        $engine->setVideoEnabled(false); self::assertSame([],$engine->sendVideoAnnexB(hex2bin('000000016501'))); $engine->close(); $encoder->close();
        $this->expectException(\LogicException::class); $engine->tick();
    }
    public function testExplicitRedOptionTransmitsNativeLowBitratePreviousFrameOnPt121():void
    {
        $now=0.0; $sent=[]; $sequence=0;
        $engine=new CallMediaEngine(new MLowCodec(),function($payload,$timestamp,$pt,$slot,$marker)use(&$sequence){return Rtp::encode($payload,77,$sequence++,$timestamp,$pt,$marker);},function($packet)use(&$sent){$sent[]=$packet;},static function(){},['now'=>function()use(&$now){return $now;},'audioRed'=>true]);
        $engine->sendAudioPcm(self::pcm()); $now=60; $engine->sendAudioPcm(self::pcm());
        $packet=Rtp::decode($sent[1]); self::assertSame(121,$packet['payloadType']);
        $red=\MVO\Voip\MLowPacket::readRed($packet['payload']); self::assertCount(1,$red['copies']); self::assertSame(960,$red['copies'][0]['delta']);
        self::assertLessThan(strlen($red['primary']),strlen($red['copies'][0]['packet'])); $engine->close();
    }
    public function testVideoUsesUpstreamSlotTwoAndRemovedSenderCannotFlushPendingVideo():void
    {
        $now=0.0; $slots=[]; $events=[]; $controls=[];
        $engine=new CallMediaEngine(new OpusCodec(),function($payload,$timestamp,$pt,$slot,$marker)use(&$slots){$slots[]=$slot; return Rtp::encode($payload,CallCrypto::ssrc('reference-call','me:1@s.whatsapp.net',$slot),1,$timestamp,$pt,$marker);},static function(){},function($packet)use(&$controls){$controls[]=$packet;},['now'=>function()use(&$now){return $now;},'emit'=>function($event,$data)use(&$events){$events[$event][]=$data;}]);
        $sent=$engine->sendVideoAnnexB(hex2bin('00000001650102'),90); self::assertSame([2],$slots); self::assertSame(CallCrypto::ssrc('reference-call','me:1@s.whatsapp.net',2),Rtp::decode($sent[0])['ssrc']);
        $engine->configureSenders([['id'=>'departing','audio'=>11,'streams'=>[22]],['id'=>'kept','audio'=>33,'streams'=>[44]]]);
        $engine->handlePacket(self::inbound(hex2bin('7c850102'),22,0,90)); $engine->handlePacket(self::inbound(hex2bin('7c450304'),22,2,90,97,true));
        self::assertSame(CallCrypto::ssrc('reference-call','me:1@s.whatsapp.net',2),Rtcp::parse($controls[0])[0]['senderSsrc']);
        $engine->configureSenders([['id'=>'kept','audio'=>33,'streams'=>[44]]]); $now=200; $engine->tick();
        $engine->handlePacket(self::inbound(hex2bin('650506'),22,3,180,97,true)); self::assertArrayNotHasKey('voip_video_frame',$events);
        $engine->handlePacket(self::inbound(hex2bin('650708'),44,0,180,97,true)); self::assertCount(1,$events['voip_video_frame']);
        $engine->resetReceiveState(); self::assertSame(0,$engine->stats()['retransmitCached']); $engine->close();
    }
    public function testFeedbackBeforeAnyMediaSendUsesConfiguredLocalSsrc():void
    {
        $controls=[]; $local=CallCrypto::ssrc('receive-first-call','me:1@s.whatsapp.net',0);
        $engine=new CallMediaEngine(new OpusCodec(),static fn()=>throw new \LogicException('Unexpected outgoing media'),static function(){},function($packet)use(&$controls){$controls[]=$packet;},['localAudioSsrc'=>$local]);
        $engine->handlePacket(self::inbound(hex2bin('7c850102'),22,0,90)); $engine->handlePacket(self::inbound(hex2bin('7c450304'),22,2,90,97,true));
        self::assertCount(3,$controls); self::assertSame(['nack','pli','fir'],array_map(static fn($packet)=>Rtcp::parse($packet)[0]['kind'],$controls)); foreach($controls as $control) self::assertSame($local,Rtcp::parse($control)[0]['senderSsrc']); $engine->close();
    }
}
