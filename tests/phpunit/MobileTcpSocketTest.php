<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Mobile\TcpSocket;
use MVO\Transport\WebSocket;

final class MobileTcpSocketTest extends TestCase
{
    public function testLocalTcpRoundTripSendsRawBytesCompatibleWithNoiseWithoutWebSocketFrames():void
    {
        $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);self::assertIsResource($listener);$address=stream_socket_get_name($listener,false);$client=new TcpSocket('tcp://'.$address,1.0);
        try {
            self::assertInstanceOf(WebSocket::class,$client);$client->connect();$server=stream_socket_accept($listener,1.0);self::assertIsResource($server);stream_set_timeout($server,1);
            $bytes="WA\x06\x03\x00\x00\x03abc";$client->send($bytes);self::assertSame($bytes,fread($server,strlen($bytes)));self::assertNull($client->receive(0.0));
            fwrite($server,"\x00\x00\x03xyz");self::assertSame("\x00\x00\x03xyz",$client->receive(1.0));fclose($server);
        }finally{$client->close();fclose($listener);}
        self::assertFalse($client->isConnected());
    }
    public function testInvalidEndpointAndProxyAreRejected():void
    {
        foreach(['https://g.whatsapp.net','tcp://user@g.whatsapp.net:443','tcp://g.whatsapp.net:443/path','tcp://g.whatsapp.net:443?token=x'] as $url)try{new TcpSocket($url);self::fail();}catch(\InvalidArgumentException){self::assertTrue(true);}
        $client=new TcpSocket('tcp://127.0.0.1:9',1.0,'socks5://127.0.0.1:1080');$this->expectException(\InvalidArgumentException::class);$client->connect();
    }
}
