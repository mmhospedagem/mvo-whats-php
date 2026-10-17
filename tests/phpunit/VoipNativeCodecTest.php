<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Voip\{MLowCodec,MLowPacket,OpusCodec};

final class VoipNativeCodecTest extends TestCase
{
    protected function setUp():void
    {
        if(!extension_loaded('FFI') || !is_file(OpusCodec::defaultLibrary()) || !is_file(MLowCodec::defaultMLowLibrary())) self::markTestSkipped('Install native codecs using tools/setup-codecs.ps1 and enable PHP FFI');
    }
    private static function tone(int $samples=960,int $offset=0):array
    {
        $pcm=[]; for($i=0;$i<$samples;$i++) $pcm[]=0.3*sin(2*M_PI*440*($offset+$i)/16000); return $pcm;
    }
    private static function energy(array $pcm):float { $sum=0.0; foreach($pcm as $sample) { self::assertTrue(is_finite($sample)); $sum+=$sample*$sample; } return $sum; }
    public function testRealNativeOpusEncodesDecodesAndSupportsKnownSilencePacket():void
    {
        $codec=new OpusCodec(); self::assertStringContainsString('libopus',$codec->version());
        $packet=$codec->encode(self::tone()); self::assertGreaterThan(1,strlen($packet)); self::assertLessThanOrEqual(4000,strlen($packet));
        $pcm=$codec->decode($packet); self::assertCount(960,$pcm); self::assertGreaterThan(1,self::energy($pcm));
        $codec->resetSequence(); $silence=$codec->decode(hex2bin('f8fffe')); self::assertCount(320,$silence); self::assertLessThan(0.0001,self::energy($silence));
        $codec->close(); $this->expectException(\LogicException::class); $codec->decode($packet);
    }
    public function testRealNativeMlowEncodesAndDecodesSixtyMilliseconds():void
    {
        $codec=new MLowCodec(); self::assertStringContainsString('1.0.1',$codec->version());
        $packet=$codec->encode(self::tone()); self::assertSame(960,MLowPacket::samples($packet));
        $pcm=$codec->decode($packet); self::assertCount(960,$pcm); self::assertGreaterThan(1,self::energy($pcm));
        $concealed=$codec->decode(null,960); self::assertCount(960,$concealed); self::assertGreaterThan(0.01,self::energy($concealed));
        $codec->resetSequence(); self::assertCount(960,$codec->decode($packet)); $codec->close(); $codec->close();
    }
    public function testCapturedOfficialWhatsappWebMlowAudioDecodesWithoutNodeOrWasm():void
    {
        $fixture=file_get_contents(dirname(__DIR__).'/fixtures/voip/mlow-web-capture.json');
        $packets=json_decode(ltrim($fixture,"\xef\xbb\xbf"),true,512,JSON_THROW_ON_ERROR); self::assertCount(8,$packets);
        $codec=new MLowCodec(); $samples=0; $energy=0.0;
        foreach($packets as $packet) {
            $result=$codec->decodeSequenced($packet['sequence'],hex2bin($packet['hex']),$packet['timestamp']); self::assertSame('decoded',$result['outcome']);
            foreach($result['frames'] as $pcm) { $samples+=count($pcm); foreach($pcm as $sample) { if(!is_finite($sample)) self::fail('Captured PCM contains non-finite sample'); $energy+=$sample*$sample; } }
        }
        self::assertSame(15360,$samples); self::assertGreaterThan(1,$energy); self::assertSame(0,$codec->stats()['errors']); self::assertSame(8,$codec->stats()['success']);
        $last=$packets[7]; self::assertSame('late',$codec->decodeSequenced($last['sequence'],hex2bin($last['hex']),$last['timestamp'])['outcome']);
        $codec->close();
    }
    public function testNativeMlowSecondaryActuallyRepairsMissingAudioWithCompactRed():void
    {
        $encoder=new MLowCodec(); $encoder->enableSecondary(); $primary=[]; $secondary=[];
        for($i=0;$i<3;$i++) { $primary[]=$encoder->encode(self::tone(960,$i*960)); $secondary[]=$encoder->encodeSecondary(); }
        self::assertLessThan(strlen($primary[1]),strlen($secondary[1])); self::assertSame(960,MLowPacket::samples($secondary[1]));
        $receiver=new MLowCodec(); self::assertSame('decoded',$receiver->decodeSequenced(0,$primary[0],0)['outcome']);
        $red=MLowPacket::writeRed($primary[2],1920,[['packet'=>$secondary[1],'timestamp'=>960]]);
        $result=$receiver->decodeSequenced(2,$red,1920,1); self::assertSame('decoded',$result['outcome']);
        // RED has a separate sequence space; establish its earlier packet before testing loss.
        $receiver->resetSequence(); $receiver->decodeSequenced(0,MLowPacket::writeRed($primary[0],0),0,1);
        $result=$receiver->decodeSequenced(2,$red,1920,1);
        self::assertCount(2,$result['frames']); self::assertCount(960,$result['frames'][0]); self::assertCount(960,$result['frames'][1]);
        self::assertSame(1,$receiver->stats()['redRecovered']); self::assertSame(0,$receiver->stats()['fecAttempts']); self::assertSame(0,$receiver->stats()['plc']);
        $reference=new MLowCodec(); $reference->decode($primary[0]); $expectedCopy=$reference->decode($secondary[1]); $expectedPrimary=$reference->decode($primary[2]);
        self::assertSame($expectedCopy,$result['frames'][0]); self::assertSame($expectedPrimary,$result['frames'][1]);
        $encoder->close(); $receiver->close(); $reference->close();
    }
    public function testNativeFecAndPlcFollowExactDecoderStateAndSequenceWrap():void
    {
        $encoder=new OpusCodec(null,16000,1,20000); $encoder->setExpectedPacketLossPercent(30); $packets=[];
        for($i=0;$i<6;$i++) $packets[]=$encoder->encode(self::tone(320,$i*320));
        $receiver=new OpusCodec(); $reference=new OpusCodec();
        $receiver->decodeSequenced(65535,$packets[3],0); $reference->decode($packets[3]);
        $result=$receiver->decodeSequenced(1,$packets[5],640); self::assertSame('decoded',$result['outcome']); self::assertCount(2,$result['frames']);
        self::assertSame($reference->decode($packets[5],320,true),$result['frames'][0]); self::assertSame($reference->decode($packets[5]),$result['frames'][1]); self::assertSame(1,$receiver->stats()['fecAttempts']);
        self::assertCount(320,$receiver->decode(null,320)); self::assertSame('late',$receiver->decodeSequenced(0,$packets[4],320)['outcome']);
        $encoder->close(); $receiver->close(); $reference->close();
    }
    public function testDtxTimestampPauseDoesNotCreateFakePacketLoss():void
    {
        $encoder=new MLowCodec(); $packet=$encoder->encode(self::tone()); $receiver=new MLowCodec();
        $receiver->decodeSequenced(1,$packet,0); $result=$receiver->decodeSequenced(2,$packet,160000);
        self::assertCount(1,$result['frames']); self::assertSame(0,$receiver->stats()['plc']); self::assertSame(0,$receiver->stats()['fecAttempts']);
        $encoder->close(); $receiver->close();
    }
    public function testMultiFrameGapConcealmentIsBoundedAndNotRepeatedWithoutTimestamp():void
    {
        $encoder=new MLowCodec(); $frame=$encoder->encode(self::tone()); $packet=MLowPacket::pack([$frame,$frame]); $receiver=new MLowCodec();
        $receiver->decodeSequenced(1,$packet); $result=$receiver->decodeSequenced(3,$packet);
        self::assertCount(3,$result['frames']); self::assertSame(1,$receiver->stats()['fecAttempts']);
        $result=$receiver->decodeSequenced(2000,$packet); self::assertLessThanOrEqual(7,count($result['frames'])); self::assertGreaterThan(0,$receiver->stats()['concealCapped']);
        $encoder->close(); $receiver->close();
    }
    public function testMalformedPayloadCannotPoisonSequenceAndInvalidPcmNeverEntersNativeCode():void
    {
        $encoder=new MLowCodec(); $packet=$encoder->encode(self::tone()); $receiver=new MLowCodec();
        self::assertSame('rejected',$receiver->decodeSequenced(100,hex2bin('9202ff50'),0,1)['outcome']); self::assertSame('decoded',$receiver->decodeSequenced(1,$packet,0,1)['outcome']);
        self::assertSame(1,$receiver->stats()['errors']);
        try { $encoder->encode([NAN,...array_fill(0,959,0)]); self::fail('Accepted NaN PCM'); } catch(\InvalidArgumentException) { self::addToAssertionCount(1); }
        try { $receiver->decode(str_repeat('x',4001)); self::fail('Accepted oversized packet'); } catch(\InvalidArgumentException) { self::addToAssertionCount(1); }
        $encoder->close(); $receiver->close();
    }
}
