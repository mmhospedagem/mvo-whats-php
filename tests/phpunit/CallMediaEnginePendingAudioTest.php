<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Message\MessageCodec;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Voip\{CallCrypto,CallMediaEngine,GroupCallState,GroupMediaSession,MediaSession,MLowCodec,MLowPacket,RelayTransport,Rtcp,Rtp,SrtpSession,VoipService};
use PHPUnit\Framework\TestCase;

/** Finite native MLow sources must not leave an unpaired speech frame in the engine. */
final class CallMediaEnginePendingAudioTest extends TestCase
{
    private float $now=0;
    private int $protected=0;
    /** @var list<string> */private array $sent=[];
    private ?\Closure $onSend=null;
    private ?CallMediaEngine $engine=null;
    private SrtpSession $receiver;

    protected function setUp():void
    {
        if(!extension_loaded('FFI')||!is_file(MLowCodec::defaultMLowLibrary()))self::markTestSkipped('Optional native MLow codec is not installed');
        $sender=new SrtpSession(str_repeat('k',16),str_repeat('s',14));
        $this->receiver=new SrtpSession(str_repeat('k',16),str_repeat('s',14));$sequence=3200;
        $this->engine=new CallMediaEngine(new MLowCodec(),
            function(string $payload,int $timestamp,int $pt,int $slot,bool $marker)use($sender,&$sequence):string{
                ++$this->protected;return $sender->protect(Rtp::encode($payload,77+$slot,$sequence++,$timestamp,$pt,$marker));
            },
            function(string $packet):void{$this->sent[]=$packet;($this->onSend)?->__invoke($packet);},
            static function(string $packet):void{},
            ['now'=>fn():float=>$this->now,'random'=>static fn():float=>0.5,
                'voipSettings'=>['audioFrameMs'=>60,'audioMaxFramesPerPacket'=>2]]);
    }

    protected function tearDown():void{$this->engine?->close();}

    /** @return list<float> */
    private static function speech(int $offset=0):array
    {
        $pcm=[];for($i=0;$i<960;$i++)$pcm[]=0.3*sin(2*M_PI*440*($offset+$i)/16000);return $pcm;
    }

    /** @return array<string,mixed> */
    private function packet(int $index):array
    {
        $plain=$this->receiver->unprotect($this->sent[$index]);self::assertNotNull($plain);return Rtp::decode($plain);
    }

    public function testSingleFrameFlushesAtPacketizationDeadlineAndRetransmitsExactCiphertext():void
    {
        self::assertSame([],$this->engine->sendAudioPcm(self::speech(),0));
        $this->now=119.999;$this->engine->tick();self::assertSame([],$this->sent);self::assertSame(0,$this->protected);
        $this->now=120;$this->engine->tick();self::assertCount(1,$this->sent);self::assertSame(1,$this->protected);
        $packet=$this->packet(0);self::assertSame(4000,$packet['timestamp']);self::assertSame(120,$packet['payloadType']);self::assertTrue($packet['marker']);
        self::assertSame(960,MLowPacket::samples($packet['payload']));self::assertSame(1,$this->engine->stats()['audioSent']);
        $decoder=new MLowCodec(options:['decodeOnly'=>true]);
        try{$decoded=$decoder->decodeSequenced($packet['sequence'],$packet['payload'],$packet['timestamp']);
            self::assertSame('decoded',$decoded['outcome']);self::assertCount(960,$decoded['frames'][0]);
            self::assertGreaterThan(1,array_sum(array_map(static fn(float $x):float=>$x*$x,$decoded['frames'][0])));
        }finally{$decoder->close();}
        $this->now=1000;$this->engine->tick();self::assertCount(1,$this->sent);
        $this->engine->handlePacket(['kind'=>'rtcp','bytes'=>Rtcp::nack(99,77,[$packet['sequence']])]);
        self::assertCount(2,$this->sent);self::assertSame($this->sent[0],$this->sent[1]);self::assertSame(1,$this->protected);
    }

    public function testSecondFrameBeforeDeadlineKeepsTwoFrameAggregation():void
    {
        self::assertSame([],$this->engine->sendAudioPcm(self::speech(),0));
        $this->now=60;$result=$this->engine->sendAudioPcm(self::speech(960),60);self::assertCount(1,$result);self::assertSame($result[0],$this->sent[0]);
        $packet=$this->packet(0);self::assertSame(4000,$packet['timestamp']);self::assertTrue($packet['marker']);
        self::assertSame(1920,MLowPacket::samples($packet['payload']));self::assertCount(2,MLowPacket::frames($packet['payload']));
        $this->now=250;$this->engine->tick();self::assertCount(1,$this->sent);self::assertSame(1,$this->protected);
    }

    public function testMuteDropsPendingFrameAndUnmuteDoesNotResurrectIt():void
    {
        $this->engine->sendAudioPcm(self::speech(),0);$this->engine->setAudioEnabled(false);
        $this->now=250;$this->engine->tick();self::assertSame([],$this->sent);self::assertSame(1,$this->engine->stats()['audioPendingDropped']);
        $this->engine->setAudioEnabled(true);$this->now=300;$this->engine->tick();self::assertSame([],$this->sent);
        self::assertSame([],$this->engine->sendAudioPcm(self::speech(960),300));$this->now=420;$this->engine->tick();
        self::assertCount(1,$this->sent);$packet=$this->packet(0);self::assertSame(8800,$packet['timestamp']);self::assertTrue($packet['marker']);
    }

    public function testCloseDropsPendingFrameWithoutProtectingOrSendingIt():void
    {
        $this->engine->sendAudioPcm(self::speech(),0);$this->engine->close();$this->now=250;
        try{$this->engine->tick();self::fail('A closed engine cannot tick');}catch(\LogicException){}
        self::assertSame([],$this->sent);self::assertSame(0,$this->protected);
    }

    public function testUncertainSendFailureDoesNotReplayPendingFrameOnNextTick():void
    {
        $cause=new \RuntimeException('Synthetic transport failure after write');$this->onSend=static function()use($cause):void{throw $cause;};
        $this->engine->sendAudioPcm(self::speech(),0);$this->now=120;$failure=null;
        try{$this->engine->tick();}catch(\Throwable $error){$failure=$error;}
        self::assertSame($cause,$failure);self::assertCount(1,$this->sent);self::assertSame(1,$this->protected);
        $this->onSend=null;$this->now=250;$this->engine->tick();self::assertCount(1,$this->sent);self::assertSame(1,$this->protected);
        self::assertSame([],$this->engine->sendAudioPcm(self::speech(960),250));$this->now=370;$this->engine->tick();
        self::assertCount(2,$this->sent);self::assertSame(2,$this->protected);self::assertNotSame($this->sent[0],$this->sent[1]);
    }

    public function testDeadlineUsesQueueTimeRatherThanOlderCaptureTimestamp():void
    {
        $this->now=1000;self::assertSame([],$this->engine->sendAudioPcm(self::speech(),0));
        $this->now=1119.999;$this->engine->tick();self::assertSame([],$this->sent);
        $this->now=1120;$this->engine->tick();self::assertCount(1,$this->sent);self::assertSame(4000,$this->packet(0)['timestamp']);
    }

    /** @return array{VoipService,MediaSession,PendingAudioReadinessRelay,CallMediaEngine} */
    private function acceptedCall():array
    {
        $plain=MessageCodec::pad(SchemaCodec::encode('Message',['call'=>['callKey'=>str_repeat('k',32)]]));
        $voip=new VoipService(static fn():array=>['meJid'=>'222:2@lid','meLid'=>'222:2@lid'],
            static fn(BinaryNode $node,float $timeout):BinaryNode=>new BinaryNode('ack',['class'=>'call','id'=>$node->attrs['id']]),
            static function(BinaryNode $node):void{},static fn():array=>['111:1@lid'],
            static fn():array=>throw new \LogicException('This fixture never distributes call keys'),
            static fn():string=>$plain,static function(string $event,array $data):void{},fn():float=>$this->now/1000);
        $voip->handleNode(new BinaryNode('call',['from'=>'111:1@lid','id'=>'fixture-offer'],[
            new BinaryNode('offer',['call-id'=>'PENDING-CALL','call-creator'=>'111:1@lid'],[
                new BinaryNode('audio',['enc'=>'opus','rate'=>'16000']),new BinaryNode('enc',['type'=>'msg'],'fixture-key')])]));
        $voip->accept('PENDING-CALL');$media=$voip->media('PENDING-CALL');self::assertFalse($media->isTransportReady());
        $relay=new PendingAudioReadinessRelay();$media->connectTransport($relay);
        $engine=$media->mediaEngine(new MLowCodec(),['now'=>fn():float=>$this->now,
            'voipSettings'=>['audioFrameMs'=>60,'audioMaxFramesPerPacket'=>2]]);
        return [$voip,$media,$relay,$engine];
    }

    public function testPendingFrameWaitsForRelayAllocationWithoutEndingTheCall():void
    {
        [$voip,$media,$relay,$engine]=$this->acceptedCall();
        try{
            self::assertFalse($media->isTransportReady());self::assertFalse($media->isReady());
            $engine->sendAudioPcm(self::speech(),0);$this->now=120;$voip->tick();
            self::assertSame('connecting',$voip->getCall('PENDING-CALL')['state']);self::assertSame(0,$relay->attempts);self::assertSame(0,$relay->closes);
            self::assertGreaterThan(0,$relay->ticks);self::assertGreaterThan(0,$relay->receives);
            $relay->allocated=true;self::assertTrue($media->isTransportReady());self::assertFalse($media->isReady());
            $this->now=250;$voip->tick();self::assertSame('connecting',$voip->getCall('PENDING-CALL')['state']);self::assertCount(1,$relay->sent);
            $keys=CallCrypto::perDevice(str_repeat('k',32),'222:2@lid');$receiver=new SrtpSession($keys['masterKey'],$keys['masterSalt']);
            $plain=$receiver->unprotect($relay->sent[0]);self::assertNotNull($plain);$packet=Rtp::decode($plain);
            self::assertSame(4000,$packet['timestamp']);self::assertSame(960,MLowPacket::samples($packet['payload']));
            $this->now=500;$voip->tick();self::assertCount(1,$relay->sent);self::assertSame(1,$relay->attempts);
        }finally{$voip->close();}
        self::assertFalse($media->isTransportReady());
    }

    public function testReadyRelayWriteFailureRemainsFatalAndDoesNotReplay():void
    {
        [$voip,$media,$relay,$engine]=$this->acceptedCall();
        try{
            $relay->allocated=true;$relay->onSend=static function():void{throw new \RuntimeException('Synthetic failure after media write');};
            $engine->sendAudioPcm(self::speech(),0);$this->now=120;$voip->tick();
            self::assertSame('ended',$voip->getCall('PENDING-CALL')['state']);self::assertSame('transport_failed',$voip->getCall('PENDING-CALL')['endReason']);
            self::assertCount(1,$relay->sent);self::assertSame(1,$relay->attempts);self::assertFalse($media->isTransportReady());
            $relay->onSend=null;$this->now=250;$voip->tick();self::assertCount(1,$relay->sent);self::assertSame(1,$relay->attempts);
        }finally{$voip->close();}
    }

    public function testGroupPollKeepsPendingAudioUntilItsRelayResponds():void
    {
        $state=new GroupCallState('PENDING-GROUP');$state->applyRoster(new BinaryNode('group_info',['media'=>'audio','connected_limit'=>'32','transaction-id'=>'1','rekey'=>'1'],[
            new BinaryNode('user',['jid'=>'111@lid','state'=>'connected'],[new BinaryNode('device',['jid'=>'111:1@lid','pid'=>'0'])]),
            new BinaryNode('user',['jid'=>'222@lid','state'=>'connected'],[new BinaryNode('device',['jid'=>'222:2@lid','pid'=>'1'])])]),'PENDING-GROUP@call','222:2@lid');
        $media=new GroupMediaSession('PENDING-GROUP','222:2@lid',$state);$relay=new PendingAudioReadinessRelay();
        try{
            self::assertFalse($media->isTransportReady());$media->rotateKey(str_repeat('k',32),1);$media->connectTransport($relay);
            $engine=$media->mediaEngine(new MLowCodec(),['now'=>fn():float=>$this->now,
                'voipSettings'=>['audioFrameMs'=>60,'audioMaxFramesPerPacket'=>2]]);
            $engine->sendAudioPcm(self::speech(),0);$this->now=120;$media->poll();self::assertSame(0,$relay->attempts);self::assertFalse($media->isTransportReady());
            $relay->lastPongAt=1.0;self::assertTrue($media->isTransportReady());self::assertSame([],$media->readyDevices());
            $this->now=250;$media->poll();self::assertCount(1,$relay->sent);self::assertSame(4000,Rtp::decode($relay->sent[0])['timestamp']);
            $this->now=500;$media->poll();self::assertSame(1,$relay->attempts);
        }finally{$media->close();}
        self::assertFalse($media->isTransportReady());
    }
}

/** Allocation/readiness and post-write failures are independent of peer authentication. */
final class PendingAudioReadinessRelay implements RelayTransport
{
    public bool $open=true;public bool $allocated=false;public float $lastPongAt=0;
    public int $attempts=0;public int $closes=0;public int $ticks=0;public int $receives=0;
    /** @var list<string> */public array $sent=[];
    public ?\Closure $onSend=null;
    public function connect(array $selfSsrcs,array $peerSsrcs=[]):void{}
    public function send(string $packet):void{++$this->attempts;if(!$this->open||(!$this->allocated&&$this->lastPongAt<=0))throw new \RuntimeException('No responsive call relay is ready');$this->sent[]=$packet;($this->onSend)?->__invoke();}
    public function receive(float $timeout=0.0):?string{++$this->receives;return null;}
    public function tick():void{++$this->ticks;}
    public function close():void{++$this->closes;$this->open=false;}
    public function reregister():void{}
    public function updateSubscriptions(array $selfSsrcs,array $peerSsrcs,?string $streamDescriptor=null,?string $rxSubscriptions=null):void{}
    public function metrics():array{return ['open'=>$this->open,'allocated'=>$this->allocated,'lastPongAt'=>$this->lastPongAt,'openedAt'=>0.0,'rttMs'=>null];}
}
