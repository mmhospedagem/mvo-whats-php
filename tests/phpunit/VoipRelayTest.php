<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Protocol\BinaryNode;
use MVO\Voip\{RelayDescriptor,Stun,UdpRelay};

final class VoipRelayTest extends TestCase
{
    public function testDualStackRelayDescriptorCarriesBinaryTokensAndParticipantIds():void
    {
        $address=inet_pton('127.0.0.1').pack('n',3480).inet_pton('2001:db8::1').pack('n',3481);
        $node=new BinaryNode('ack',[],[new BinaryNode('call',[],[new BinaryNode('relay',['self_pid'=>'3','peer_pid'=>'4'],[new BinaryNode('key',[],'relay-secret'),new BinaryNode('token',['id'=>'5'],"\x00\xfftoken"),new BinaryNode('te2',['token_id'=>'5','relay_id'=>'2','relay_name'=>'relay'], $address)])])]);
        $descriptors=RelayDescriptor::parse($node);self::assertCount(2,$descriptors);self::assertSame('127.0.0.1',$descriptors[0]['ip']);self::assertSame('2001:db8::1',$descriptors[1]['ip']);self::assertSame("\x00\xfftoken",$descriptors[1]['rawToken']);self::assertSame(3,$descriptors[0]['selfPid']);
    }
    public function testRawUdpLoopbackAllocateResponseAndWarpMediaAreActualDatagrams():void
    {
        $server=stream_socket_server('udp://127.0.0.1:0',$errno,$error,STREAM_SERVER_BIND);self::assertIsResource($server);stream_set_timeout($server,1);$address=stream_socket_get_name($server,false);$port=(int)substr(strrchr($address,':'),1);$relay=new UdpRelay(['ip'=>'127.0.0.1','port'=>$port,'rawToken'=>'token','key'=>'secret']);
        try {
            $relay->connect([1],[2]);$allocate=stream_socket_recvfrom($server,65535,0,$peer);$decoded=Stun::decode($allocate,'secret');self::assertSame(3,$decoded['type']);self::assertTrue($decoded['authenticated']);
            stream_socket_sendto($server,Stun::encode(0x0103,$decoded['transactionId'],[],'secret'),0,$peer);self::assertNull($relay->receive(1));self::assertTrue($relay->allocated);
            $media="\x80\x78".str_repeat('r',14);stream_socket_sendto($server,"\x09\x02\0\0".str_repeat("\0",4).$media,0,$peer);self::assertSame($media,$relay->receive(1));
            $relay->send('raw-media');self::assertSame('raw-media',stream_socket_recvfrom($server,65535));
        }finally{$relay->close();fclose($server);}
    }
}
