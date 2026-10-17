<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Protocol\BinaryNode;
use MVO\Voip\{VoipService,MediaSession,UdpRelay,Stun};

/** Independent review regressions. No real account, outside endpoint or live call. */
final class VoipRegressionTest extends TestCase
{
    private function service(): VoipService
    {
        return new VoipService(
            static fn():array=>['meLid'=>'1:1@lid'],
            static fn(BinaryNode $n,float $timeout):BinaryNode=>new BinaryNode('ack',['class'=>'call','id'=>$n->attrs['id']]),
            static function(BinaryNode $n):void {},
            static fn(string $jid):array=>['2:2@lid'],
            static fn(array $devices,string $plain):array=>['nodes'=>array_map(static fn(string $jid):BinaryNode=>new BinaryNode('to',['jid'=>$jid],[new BinaryNode('enc',['type'=>'msg'],'opaque encrypted call key')]),$devices),'deviceIdentity'=>null],
            static fn(string $from,string $type,string $cipher):string=>throw new \LogicException('No incoming offer fixture'),
            static function(string $event,array $data):void {},
        );
    }
    private function incoming(string $tag,array $call): BinaryNode {return new BinaryNode('call',['from'=>'2:2@lid','id'=>'NOTIFY'],[new BinaryNode($tag,['call-id'=>$call['callId'],'call-creator'=>$call['callCreator']])]);}
    public function testLatePreacceptCannotResurrectEndedCall(): void
    {
        $s=$this->service();$call=$s->offer('2@lid');$s->terminate($call['callId']);self::assertSame('ended',$s->getCall($call['callId'])['state']);$s->handleNode($this->incoming('preaccept',$call));self::assertSame('ended',$s->getCall($call['callId'])['state']);$s->handleNode($this->incoming('accept',$call));self::assertSame('ended',$s->getCall($call['callId'])['state']);
    }
    public function testPreacceptCannotRegressAnAlreadyAcceptedCall(): void
    {
        $s=$this->service();$call=$s->offer('2@lid');$s->handleNode($this->incoming('accept',$call));self::assertSame('connecting',$s->getCall($call['callId'])['state']);$s->handleNode($this->incoming('preaccept',$call));self::assertSame('connecting',$s->getCall($call['callId'])['state']);
    }
    public function testRetainedMediaReferenceCannotBecomeReadyAfterClose(): void
    {
        $key=random_bytes(32);$peer=new MediaSession('CALL',$key,'2:2@lid','1:1@lid');$events=[];$media=new MediaSession('CALL',$key,'1:1@lid','2:2@lid',static function(string $event,array $data)use(&$events):void{$events[]=$event;});$packet=$peer->protectPayload('codec frame',1);$media->close();
        try{$media->handleDatagram($packet);self::fail('Closed media reference accepted a fresh peer packet');}catch(\LogicException){self::assertFalse($media->isReady());self::assertSame([],$events);}
    }
    public function testReservedMuxPayloadTypesAreRejectedBeforeEncrypting(): void
    {
        $session=new MediaSession('CALL',random_bytes(32),'1:1@lid','2:2@lid');
        foreach([64,72,95] as $type) {
            try{$session->protectPayload('frame',1,$type,0,true);self::fail('RTP type colliding with RTCP mux accepted');}
            catch(\InvalidArgumentException){self::addToAssertionCount(1);}
        }
        self::assertNotSame('frame',substr($session->protectPayload('frame',1,120,0,true),12,-4));
    }
    public function testAcceptFromAnotherAccountCannotChangeTheOfferedCall(): void
    {
        $s=$this->service();$call=$s->offer('2@lid');$other=new BinaryNode('call',['from'=>'3:3@lid','id'=>'N'],[new BinaryNode('accept',['call-id'=>$call['callId'],'call-creator'=>$call['callCreator']])]);
        try{$s->handleNode($other);self::fail('Unrelated sender accepted another account call');}
        catch(\UnexpectedValueException){self::assertSame('offered',$s->getCall($call['callId'])['state']);}
    }
    public function testOnlyAuthenticatedAllocateSuccessEstablishesUdpRelay(): void
    {
        $server=stream_socket_server('udp://127.0.0.1:0',$code,$error,STREAM_SERVER_BIND);self::assertIsResource($server);stream_set_timeout($server,1);$port=(int)substr(strrchr(stream_socket_get_name($server,false),':'),1);$relay=new UdpRelay(['ip'=>'127.0.0.1','port'=>$port,'rawToken'=>'token','key'=>'secret']);
        try {
            $relay->connect([1],[2]);$allocation=stream_socket_recvfrom($server,65535,0,$peer);$id=Stun::decode($allocation,'secret')['transactionId'];
            stream_socket_sendto($server,Stun::encode(0x0103,$id),0,$peer);
            try{$relay->receive(1);}catch(\UnexpectedValueException){}self::assertFalse($relay->allocated,'An unsigned STUN success established the relay');
            stream_socket_sendto($server,Stun::encode(0x0101,$id,[],'secret'),0,$peer);$relay->receive(1);self::assertFalse($relay->allocated,'A Binding response established an Allocate request');
            stream_socket_sendto($server,Stun::encode(0x0103,$id,[],'secret'),0,$peer);$relay->receive(1);self::assertTrue($relay->allocated);
        }finally{$relay->close();fclose($server);}
    }
}
