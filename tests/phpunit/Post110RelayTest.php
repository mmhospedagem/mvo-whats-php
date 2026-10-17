<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Voip\{CallCrypto,DataChannel,MediaSession,RelayCredentialUpdater,RelayFlowControl,RelayPool,RelayTransport,Rtp,SrtpSession,Stun,UdpRelay,WebRtcRelay};
use PHPUnit\Framework\TestCase;

final class Post110RelayTest extends TestCase
{
    private float $now=100;
    /** @var list<Post110Leg> */ private array $legs=[];
    private function descriptor(int $port,string $token='first',string $transport='webrtc'):array {
        return ['ip'=>'127.0.0.1','port'=>$port,'key'=>str_repeat('k',32),'rawToken'=>$token,'token'=>base64_encode($token),'authTokenId'=>'0','transport'=>$transport];
    }
    private function pool(array $descriptors):RelayPool {
        return new RelayPool($descriptors,function(array $descriptor):Post110Leg{$leg=new Post110Leg($descriptor,fn():float=>$this->now);$this->legs[]=$leg;return $leg;},fn():float=>$this->now);
    }
    public function testReissuedCredentialsKeepActualWebRtcChannelAndCurrentAllocationReplays():void {
        $channel=new Post110Channel();$descriptor=$this->descriptor(3480);$descriptor['authToken']='old-auth';
        $relay=new WebRtcRelay($descriptor,fn()=>$channel,fn():float=>$this->now);$relay->connect([1],[2]);$relay->tick();
        $initial=Stun::decode($channel->sent[2],$descriptor['key']);$channel->sent=[];
        $descriptor['authToken']='new-auth';$descriptor['rawToken']='new-binary-token';$relay->updateCredentials($descriptor);
        $this->now+=0.06;$relay->tick();self::assertSame(1,$channel->offers);self::assertFalse($channel->closed);
        $allocates=0;foreach($channel->sent as $packet){$parsed=Stun::decode($packet,$descriptor['key']);if($parsed['type']!==3)continue;++$allocates;self::assertSame('new-binary-token',$parsed['attributes'][0x4000][0]);self::assertSame($initial['transactionId'],$parsed['transactionId']);}
        self::assertGreaterThanOrEqual(2,$allocates);
        self::assertSame('new-auth:local-ufrag',Stun::decode($channel->sent[0],$descriptor['key'])['attributes'][6][0]);$relay->close();
    }
    public function testRawCredentialRotationKeepsUdpReturnAddressAndChangesEveryReplay():void {
        $server=stream_socket_server('udp://127.0.0.1:0',$errno,$error,STREAM_SERVER_BIND);self::assertIsResource($server);stream_set_timeout($server,1);
        $port=(int)substr(strrchr(stream_socket_get_name($server,false),':'),1);$descriptor=$this->descriptor($port,'old','raw-udp');$relay=new UdpRelay($descriptor,fn():float=>$this->now);
        try{
            $relay->connect([11],[22]);$first=stream_socket_recvfrom($server,65535,0,$address);$id=Stun::decode($first,$descriptor['key'])['transactionId'];
            $descriptor['rawToken']='rotated';$descriptor['key']=str_repeat('n',32);$relay->updateCredentials($descriptor);
            $rotated=stream_socket_recvfrom($server,65535,0,$nextAddress);self::assertSame($address,$nextAddress);
            $parsed=Stun::decode($rotated,$descriptor['key'],$id);self::assertSame('rotated',$parsed['attributes'][0x4000][0]);self::assertTrue($parsed['authenticated']);
            $this->now+=0.06;$relay->tick();$replayed=stream_socket_recvfrom($server,65535);self::assertSame($rotated,$replayed);
        }finally{$relay->close();fclose($server);}
    }
    public function testNewestGenerationIsTriedThenFollowsOlderPeerUntilNewestAuthenticated():void {
        $pool=$this->pool([$this->descriptor(1001)]);$pool->connect([1],[2]);$old=$this->legs[0];$pool->send('initial');
        $pool->configure([$this->descriptor(1002)],2);$new=$this->legs[1];$pool->send('new-first');self::assertSame(['new-first'],$new->sent);
        $this->now=100.1;$old->incoming=['old'];$pool->receive();$pool->notePeerMedia();$pool->send('hold-new');self::assertSame(['new-first','hold-new'],$new->sent);
        $this->now=100.5;$old->incoming=['old-again'];$pool->receive();$pool->notePeerMedia();$pool->send('follow-old');self::assertContains('follow-old',$old->sent);
        $new->incoming=['new-proof'];$pool->receive();$pool->notePeerMedia();$pool->send('new-proved');self::assertContains('new-proved',$new->sent);self::assertFalse($old->closed);
        $pool->configure([$this->descriptor(1003)],1);self::assertCount(2,$this->legs,'Stale relay batch must not dial or roll back generation');
        $this->now=105.6;$pool->tick();self::assertTrue($old->closed);$pool->close();
    }
    public function testCurrentSubscriptionsAndFlowSurviveNewLegsAndCredentialRebind():void {
        $descriptor=$this->descriptor(1001);$pool=$this->pool([$descriptor]);$pool->connect([1],[2]);$pool->setMediaFlowing();
        $pool->updateSubscriptions([3],[4],'streams','rx');$held=$this->legs[0];$pool->updateSubscriptions([3],[4],'streams','rx');self::assertCount(1,$held->subscriptions);
        $descriptor['rawToken']='rotated';$pool->configure([$descriptor],2);self::assertCount(1,$this->legs);self::assertSame(1,$held->rebinds);self::assertSame('streams',$held->descriptor['streamDescriptor']);
        $pool->configure([$this->descriptor(1002)],3);$new=$this->legs[1];self::assertTrue($new->flowing);self::assertSame([[3],[4],'streams','rx'],$new->subscriptions[0]);$pool->close();
    }
    public function testWatchdogNeedsAcceptedSendingAndRedialsOnlyNewestWebRtcGeneration():void {
        $pool=$this->pool([$this->descriptor(1001),$this->descriptor(1002,'raw','raw-udp')]);$pool->connect([1],[2]);$old=$this->legs[0];$raw=$this->legs[1];
        $pool->send('setup');$this->now+=100;$pool->tick();self::assertSame(0,$old->registrations);
        $pool->configure([$this->descriptor(1003)],2);$new=$this->legs[2];$pool->setMediaFlowing();$pool->send('accepted');
        $this->now+=5;$pool->send('still-sending');self::assertSame(1,$new->registrations);self::assertSame(0,$raw->registrations);
        $this->now+=5;$pool->send('redial');self::assertTrue($new->closed);self::assertFalse($old->closed);self::assertFalse($raw->closed);self::assertCount(4,$this->legs);
        $this->now+=100;$pool->tick();self::assertCount(4,$this->legs,'A stopped sender does not trigger another redial');$pool->close();
    }
    public function testIndependentPeerKeyDoesNotChangeTxAndIdenticalReinstallDoesNotResetReplay():void {
        $tx=random_bytes(32);$rx=random_bytes(32);$session=new MediaSession('call',$tx,'111:1@s.whatsapp.net','222:2@s.whatsapp.net');$session->setPeerCallKey($rx);
        $peer=CallCrypto::perDevice($rx,'222:2@s.whatsapp.net');$sender=new SrtpSession($peer['masterKey'],$peer['masterSalt']);$packet=$sender->protect(Rtp::encode('peer',123,1,100));
        self::assertSame('peer',$session->handleDatagram($packet)['payload']);$session->setPeerCallKey($rx);self::assertNull($session->handleDatagram($packet),'Same key must not erase the replay window');
        $local=CallCrypto::perDevice($tx,'111:1@s.whatsapp.net');$receiver=new SrtpSession($local['masterKey'],$local['masterSalt']);self::assertSame('local',Rtp::decode($receiver->unprotect($session->protectPayload('local',200)))['payload']);$session->close();
    }
    public function testAcceptBeforeTransportKeepsMediaCadenceAndEmptyAuthUsesPrimaryToken():void {
        $session=new MediaSession('call',random_bytes(32),'111:1@s.whatsapp.net','222:2@s.whatsapp.net');$session->setMediaFlowing();$transport=new Post110Leg($this->descriptor(1001),fn():float=>$this->now);$session->connectTransport($transport);self::assertTrue($transport->flowing);$session->close();
        $descriptor=$this->descriptor(3480);$descriptor['authToken']='';$channel=new Post110Channel();$relay=new WebRtcRelay($descriptor,fn()=>$channel,fn():float=>$this->now);$relay->connect([1],[2]);$relay->tick();self::assertSame($descriptor['token'].':local-ufrag',Stun::decode($channel->sent[0],$descriptor['key'])['attributes'][6][0]);$relay->close();
    }
    public function testTransportTransferDoesNotCloseOrReconnectTheAcceptedChannel():void {
        $session=new MediaSession('call',random_bytes(32),'111:1@s.whatsapp.net','222:2@s.whatsapp.net');$leg=new Post110Leg($this->descriptor(1001),fn():float=>$this->now);$session->connectTransport($leg);
        self::assertSame($leg,$session->releaseTransport());$session->close();self::assertFalse($leg->closed);$leg->send('new-group-context');self::assertSame(['new-group-context'],$leg->sent);$leg->close();
    }
    public function testWatchdogBackoffCapsRedialsAtThreeAndDoesNotRestartOnTick():void {
        $pool=$this->pool([$this->descriptor(1001)]);$pool->connect([1],[2]);$pool->setMediaFlowing();$pool->send('start');
        foreach([105,110,115,125,130,150,155,300] as $now){$this->now=$now;$pool->send('media');}
        self::assertCount(4,$this->legs);self::assertTrue($this->legs[0]->closed);self::assertTrue($this->legs[1]->closed);self::assertTrue($this->legs[2]->closed);self::assertFalse($this->legs[3]->closed);
        $this->now=1000;$pool->tick();self::assertCount(4,$this->legs);$pool->close();
    }
    public function testReceiveElectionHoldsForFourHundredMillisecondsAndWatchdogWorksAtClockZero():void {
        $this->now=0;$pool=$this->pool([$this->descriptor(1001),$this->descriptor(1002)]);$pool->connect([1],[2]);$pool->setMediaFlowing();$pool->send('first');
        $this->legs[0]->incoming=['a'];$pool->receive();$pool->notePeerMedia();$pool->send('restart-watchdog');
        $this->now=0.1;$this->legs[1]->incoming=['b'];$pool->receive();$pool->notePeerMedia();$pool->send('hold-a');self::assertContains('hold-a',$this->legs[0]->sent);self::assertSame([],$this->legs[1]->sent);
        $this->now=0.5;$this->legs[1]->incoming=['b-again'];$pool->receive();$pool->notePeerMedia();$pool->send('follow-b');self::assertSame(['follow-b'],$this->legs[1]->sent);
        $this->now=5.5;$pool->send('stalled');self::assertSame(1,$this->legs[1]->registrations);$pool->close();
    }
    public function testSframeNegotiationBlocksOnlyAppDataAndRequiresBothFlags():void {
        foreach([[false,false],[true,false],[false,true],[true,true]] as [$send,$receive]){
            $key=random_bytes(32);$events=[];$session=new MediaSession('call',$key,'111:1@s.whatsapp.net','222:2@s.whatsapp.net',function(string $event,array $data)use(&$events):void{$events[]=[$event,$data];});
            $session->setVoipSettings(['sframe'=>$send,'sframeRx'=>$receive]);
            self::assertNotSame('',$session->protectPayload('audio',100));
            $peer=CallCrypto::perDevice($key,'222:2@s.whatsapp.net');$sender=new SrtpSession($peer['masterKey'],$peer['masterSalt']);
            $packet=$sender->protect(Rtp::encode('app-data',123,1,100,119));
            if($send && $receive){
                self::assertNull($session->handleDatagram($packet));self::assertFalse($session->isReady());
                self::assertSame('unsupported_app_data_sframe',$events[0][1]['reason']);
                try{$session->protectPayload('app-data',200,119,6);self::fail('Unsupported SFrame app-data was sent');}catch(\LogicException $error){self::assertStringContainsString('app-data',$error->getMessage());}
                self::assertSame('audio',$session->handleDatagram($sender->protect(Rtp::encode('audio',123,2,200,120)))['payload']);
            }else{self::assertSame('app-data',$session->handleDatagram($packet)['payload']);self::assertNotSame('',$session->protectPayload('app-data',200,119,6));}
            $session->close();
        }
    }
    public function testCredentialBatchPreservesAnOldEndpointStillNamedInEitherOrder():void {
        foreach([true,false] as $newFirst){
            $this->legs=[];$old=$this->descriptor(1001,'old','raw-udp');$new=$this->descriptor(1001,'new','raw-udp');
            $pool=$this->pool([$old]);$pool->connect([1],[2]);$original=$this->legs[0];
            $pool->configure($newFirst?[$new,$old]:[$old,$new],2);
            self::assertCount(2,$this->legs);self::assertSame(0,$original->rebinds);self::assertSame('old',$original->descriptor['rawToken']);self::assertFalse($original->closed);
            self::assertSame([2,2],array_column($pool->stats(),'generation'));$pool->close();
        }
    }
    public function testWatchdogReportsTransportLossWhenReplacementConstructionFails():void {
        $events=[];$attempt=0;$leg=null;$pool=new RelayPool([$this->descriptor(1001)],function(array $descriptor)use(&$attempt,&$leg):RelayTransport{
            if(++$attempt>1)throw new \RuntimeException('Native channel construction failed');return $leg=new Post110Leg($descriptor,fn():float=>$this->now);
        },fn():float=>$this->now,function(string $event,array $data)use(&$events):void{$events[]=$event;});
        $pool->connect([1],[2]);$pool->setMediaFlowing();$pool->send('start');$this->now=105;$pool->send('reallocate');$this->now=110;$pool->send('redial');
        self::assertTrue($leg->closed);self::assertFalse($pool->metrics()['open']);self::assertSame(['voip_relay_failed','voip_transport_lost'],$events);$pool->close();
    }
    public function testReissuedDescriptorPromotesItsExistingFallbackWithoutDialingPrimaryAgain():void {
        $attempts=0;$descriptor=$this->descriptor(1001);$descriptor['fallbackPort']=1002;$leg=null;
        $pool=new RelayPool([$descriptor],function(array $endpoint)use(&$attempts,&$leg):RelayTransport{
            ++$attempts;if($endpoint['port']===1001)throw new \RuntimeException('Primary unavailable');return $leg=new Post110Leg($endpoint,fn():float=>$this->now);
        },fn():float=>$this->now);
        $pool->connect([1],[2]);self::assertSame(2,$attempts);$pool->configure([$descriptor],2);
        self::assertSame(2,$attempts);self::assertCount(1,$pool->stats());self::assertSame(2,$pool->stats()[0]['generation']);self::assertFalse($leg->closed);$pool->close();
    }
}

final class Post110Leg implements RelayTransport,RelayCredentialUpdater,RelayFlowControl
{
    public bool $closed=false,$flowing=false;public int $registrations=0,$rebinds=0;
    public array $sent=[],$incoming=[],$subscriptions=[];
    public function __construct(public array $descriptor,private readonly \Closure $clock){}
    public function connect(array $selfSsrcs,array $peerSsrcs=[]):void{}
    public function send(string $packet):void{$this->sent[]=$packet;}
    public function receive(float $timeout=0):?string{return array_shift($this->incoming);}
    public function tick():void{}
    public function reregister():void{++$this->registrations;}
    public function close():void{$this->closed=true;}
    public function updateSubscriptions(array $selfSsrcs,array $peerSsrcs,?string $streamDescriptor=null,?string $rxSubscriptions=null):void{$this->subscriptions[]=[$selfSsrcs,$peerSsrcs,$streamDescriptor,$rxSubscriptions];}
    public function updateCredentials(array $descriptor):void{$this->descriptor=$descriptor;++$this->rebinds;}
    public function setMediaFlowing(bool $flowing=true):void{$this->flowing=$flowing;}
    public function metrics():array{return ['open'=>!$this->closed,'allocated'=>true,'lastPongAt'=>($this->clock)(),'openedAt'=>100.0,'rttMs'=>10];}
}
final class Post110Channel implements DataChannel
{
    public array $sent=[];public bool $closed=false;public int $offers=0;
    public function createOffer():void{++$this->offers;}
    public function localDescription(bool $waitForCandidates=false,float $timeout=5):string{return "v=0\r\na=ice-ufrag:local-ufrag\r\na=ice-pwd:local-password-123456789\r\na=fingerprint:sha-256 AA\r\na=setup:actpass\r\n";}
    public function setRemoteDescription(string $sdp,string $type='answer'):void{}
    public function isOpen():bool{return !$this->closed;}
    public function state():string{return $this->closed?'closed':'connected';}
    public function send(string $bytes):void{$this->sent[]=$bytes;}
    public function receive(float $timeout=0):?string{return null;}
    public function close():void{$this->closed=true;}
}
