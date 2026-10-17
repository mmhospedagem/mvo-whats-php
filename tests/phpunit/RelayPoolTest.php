<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Voip\{RelayPool,RelayTransport,WebRtcRelay,DataChannel,RelaySdp,Stun,WarpFrame};

final class RelayPoolTest extends TestCase
{
    private float $now=100;
    private array $legs=[];
    private function descriptor(int $port,string $token='token'):array{return ['ip'=>'127.0.0.1','port'=>$port,'key'=>str_repeat('k',32),'rawToken'=>$token,'token'=>base64_encode($token),'transport'=>'webrtc'];}
    private function pool(array $descriptors):RelayPool {
        return new RelayPool($descriptors,function(array $d):PoolLeg{$leg=new PoolLeg($this->now);$this->legs[$d['port'].':'.$d['rawToken']]=$leg;return $leg;},fn():float=>$this->now);
    }
    public function testResponsiveElectionAndAuthenticatedNewGenerationRetirement():void {
        $pool=$this->pool([$this->descriptor(1001),$this->descriptor(1002)]);$pool->connect([1],[2]);
        $a=$this->legs['1001:token'];$b=$this->legs['1002:token'];$a->rtt=10;$b->rtt=20;
        $pool->send('one');self::assertSame(['one'],$a->sent);self::assertSame([],$b->sent);
        $b->incoming=['authenticated-old'];self::assertSame('authenticated-old',$pool->receive());$pool->notePeerMedia();
        $pool->configure([$this->descriptor(1003,'new')]);$c=$this->legs['1003:new'];
        self::assertFalse($a->closed);self::assertFalse($b->closed); // Allocation ACK alone retires nothing.
        $c->incoming=['authenticated-new'];self::assertSame('authenticated-new',$pool->receive());$pool->notePeerMedia();
        self::assertTrue($a->closed);self::assertFalse($b->closed); // Old relay still hearing another sender is receive-only.
        $pool->send('new-leg');self::assertSame(['new-leg'],$c->sent);self::assertSame([],$b->sent);
        $this->now+=5.1;$c->pong=$this->now;$pool->tick();self::assertTrue($b->closed);$pool->close();
    }
    public function testPortFallbackAndSubscriptionReissueAreBounded():void {
        $d=$this->descriptor(1001);$d['fallbackPort']=3480;$pool=$this->pool([$d]);$pool->connect([1],[2]);
        $a=$this->legs['1001:token'];$a->open=false;$this->now+=5.1;$pool->tick();
        self::assertTrue($a->closed);self::assertArrayHasKey('3480:token',$this->legs);
        $fallback=$this->legs['3480:token'];$pool->updateSubscriptions([3],[4],'streams','rx');
        self::assertSame([[3],[4],'streams','rx'],$fallback->subscription);
        $fallback->open=false;$this->now+=5.1;$pool->tick();self::assertCount(2,$this->legs);$pool->close();
    }
    public function testNoResponsiveLegCannotClaimMediaWasSent():void {
        $pool=$this->pool([$this->descriptor(1001)]);$pool->connect([1],[2]);$leg=$this->legs['1001:token'];$leg->allocated=false;$leg->pong=0;
        try{$pool->send('data');self::fail();}catch(\RuntimeException $e){self::assertStringContainsString('No responsive',$e->getMessage());}
        self::assertSame([],$leg->sent);$pool->close();
    }
    public function testWebRtcRelayBuildsPinnedSdpAndRegistrationLadder():void {
        $channel=new RelayChannel();$d=$this->descriptor(3480);$relay=new WebRtcRelay($d,fn()=>$channel,fn():float=>$this->now);
        $relay->connect([10],[20]);self::assertStringContainsString('a=fingerprint:'.RelaySdp::FINGERPRINT,$channel->remote);self::assertStringContainsString('127.0.0.1 3480 typ host',$channel->remote);self::assertStringNotContainsString('10.0.0.1',$channel->remote);
        $relay->tick();self::assertCount(3,$channel->sent);
        $binding=Stun::decode($channel->sent[0],$d['key']);self::assertSame(1,$binding['type']);self::assertSame($d['token'].':local-ufrag',$binding['attributes'][6][0]);self::assertTrue($binding['authenticated']);
        $allocate=Stun::decode($channel->sent[2],$d['key']);self::assertSame(3,$allocate['type']);self::assertSame('token',$allocate['attributes'][0x4000][0]);
        $id=$allocate['transactionId'];$channel->incoming=[Stun::encode(0x0111,$id,[9=>"\0\0\x04\x38"]),Stun::encode(0x0103,$id,[],null)];
        self::assertNull($relay->receive());self::assertNull($relay->receive());self::assertTrue($relay->metrics()['allocated']);
        // Its inner allocation response is already authenticated by the pinned DTLS channel.
        $packet="\x80\x78".str_repeat("\0",10);$channel->incoming=["\x09\x82\0\1\0\0\0\0".$packet];self::assertSame($packet,$relay->receive());
        $relay->close();self::assertTrue($channel->closed);
    }
    public function testWarpControlIsDiscardedAndMalformedSdpCredentialsRejected():void {
        self::assertNull(WarpFrame::unwrap("\x09\0\0\1control"));
        $d=$this->descriptor(3480);$d['key']="password\r\na=evil";$this->expectException(\InvalidArgumentException::class);RelaySdp::answer(RelayChannel::SDP,$d);
    }
    public function testNewAuthTokenStartsANewLegEvenWhenEndpointAndMediaTokenAreUnchanged():void {
        $descriptor=$this->descriptor(1001);$descriptor['authToken']='auth-old';$pool=$this->pool([$descriptor]);$pool->connect([1],[2]);$old=$this->legs['1001:token'];
        $descriptor['authToken']='auth-new';$pool->configure([$descriptor]);$new=$this->legs['1001:token'];self::assertNotSame($old,$new);self::assertCount(2,$pool->stats());self::assertFalse($old->closed);
        $new->incoming=['verified'];self::assertSame('verified',$pool->receive());$pool->notePeerMedia();self::assertTrue($old->closed);$pool->close();
    }
    public function testMalformedRelayPacketsAreDiscardedWithoutClosingTheLegAndWithABound():void {
        $pool=$this->pool([$this->descriptor(1001)]);$pool->connect([1],[2]);$leg=$this->legs['1001:token'];
        $leg->incoming=[new \UnexpectedValueException('bad packet'),'valid'];self::assertSame('valid',$pool->receive());self::assertFalse($leg->closed);
        $leg->incoming=array_fill(0,65,new \UnexpectedValueException('bad packet'));$leg->incoming[]='valid';self::assertNull($pool->receive());self::assertCount(2,$leg->incoming);self::assertSame('valid',$pool->receive());self::assertFalse($leg->closed);$pool->close();
    }
    public function testDefaultUdpLegSharesPoolClockForRetryRttPongsAndElection():void {
        $server=stream_socket_server('udp://127.0.0.1:0',$errno,$error,STREAM_SERVER_BIND); self::assertIsResource($server); stream_set_timeout($server,1);
        $port=(int)substr(strrchr(stream_socket_get_name($server,false),':'),1); $descriptor=$this->descriptor($port); $descriptor['transport']='raw-udp';
        $pool=new RelayPool([$descriptor],clock:fn():float=>$this->now);
        try {
            $pool->connect([1],[2]); $allocation=stream_socket_recvfrom($server,65535,0,$peer); $id=Stun::decode($allocation,$descriptor['key'])['transactionId'];
            self::assertSame(100.0,$pool->stats()[0]['openedAt']);
            $pool->tick(); $read=[$server]; $write=[]; $except=[]; self::assertSame(0,stream_select($read,$write,$except,0,0));
            $this->now=100.025; stream_socket_sendto($server,Stun::encode(0x0103,$id,[],$descriptor['key']),0,$peer); self::assertNull($pool->receive());
            self::assertSame(100.025,$pool->metrics()['lastPongAt']); self::assertSame(25,$pool->metrics()['rttMs']); self::assertTrue($pool->metrics()['open']);
            $this->now=100.051; $pool->tick(); self::assertSame($allocation,stream_socket_recvfrom($server,65535));
            $this->now=101.1; $pong=Stun::ping($id); $pong[1]="\x02"; stream_socket_sendto($server,$pong,0,$peer); self::assertNull($pool->receive()); self::assertSame(101.1,$pool->metrics()['lastPongAt']);
            $pool->send('opaque encrypted media'); self::assertSame('opaque encrypted media',stream_socket_recvfrom($server,65535));
            $this->now=105.11; self::assertFalse($pool->metrics()['open'],'A stale pong must expire on the same clock as the pool');
        } finally { $pool->close(); fclose($server); }
    }
}
final class PoolLeg implements RelayTransport
{
    public bool $open=true,$allocated=true,$closed=false;public int $rtt=10;public float $pong;
    public array $sent=[],$incoming=[],$subscription=[];
    public function __construct(private float $at){$this->pong=$at;}
    public function connect(array $selfSsrcs,array $peerSsrcs=[]):void{}
    public function send(string $packet):void{$this->sent[]=$packet;}
    public function receive(float $timeout=0.0):?string{$packet=array_shift($this->incoming);if($packet instanceof \Throwable)throw $packet;return $packet;}
    public function tick():void{}
    public function reregister():void{}
    public function updateSubscriptions(array $selfSsrcs,array $peerSsrcs,?string $streamDescriptor=null,?string $rxSubscriptions=null):void{$this->subscription=[$selfSsrcs,$peerSsrcs,$streamDescriptor,$rxSubscriptions];}
    public function metrics():array{return ['open'=>$this->open,'allocated'=>$this->allocated,'lastPongAt'=>$this->pong,'openedAt'=>$this->at,'rttMs'=>$this->rtt];}
    public function close():void{$this->open=false;$this->closed=true;}
}
final class RelayChannel implements DataChannel
{
    public const SDP="v=0\r\nm=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\na=ice-ufrag:local-ufrag\r\na=ice-pwd:local-password-123456789\r\na=fingerprint:sha-256 AA:BB\r\na=setup:actpass\r\na=max-message-size:65535\r\na=candidate:1 1 udp 1 10.0.0.1 9000 typ host\r\na=ice-options:trickle\r\n";
    public string $remote='';public bool $closed=false;public array $sent=[],$incoming=[];
    public function createOffer():void{}
    public function localDescription(bool $waitForCandidates=false,float $timeout=5):string{return self::SDP;}
    public function setRemoteDescription(string $sdp,string $type='answer'):void{$this->remote=$sdp;}
    public function isOpen():bool{return !$this->closed;}
    public function state():string{return $this->closed?'closed':'connected';}
    public function send(string $bytes):void{$this->sent[]=$bytes;}
    public function receive(float $timeout=0):?string{return array_shift($this->incoming);}
    public function close():void{$this->closed=true;}
}
