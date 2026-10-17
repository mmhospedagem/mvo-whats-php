<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Testing\ServerWebSocket;
use PHPUnit\Framework\TestCase;

final class ServerWebSocketTest extends TestCase
{
    public function testValidPeerCloseIsAcknowledgedAndEndsConnection():void
    {
        [$server,$peer]=$this->pair();$socket=new ServerWebSocket($server);
        try {
            $body=pack('n',1000);fwrite($peer,self::closeFrame($body));
            self::assertNull($socket->receive(.5));self::assertTrue($socket->isClosed());
            self::assertSame("\x88\x02".$body,stream_get_contents($peer));
            self::assertNull($socket->receive(0));
            $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('Fake WebSocket closed');
            $socket->send('application data');
        } finally {$socket->close();fclose($peer);}
    }

    public function testCloseAcknowledgementFailureDoesNotLeakWarning():void
    {
        [$server,$peer]=$this->pair();CloseAcknowledgementFailureStream::$source=$server;CloseAcknowledgementFailureStream::$writes=0;
        self::assertTrue(stream_wrapper_register('mvowsclose',CloseAcknowledgementFailureStream::class));
        $stream=fopen('mvowsclose://reset','r+b');self::assertIsResource($stream);$socket=new ServerWebSocket($stream);
        fwrite($peer,self::closeFrame(pack('n',1000)));
        $warnings=[];
        set_error_handler(static function(int $severity,string $message)use(&$warnings):bool {
            if((error_reporting()&$severity)!==0)$warnings[]=$message;
            return true;
        });
        try {self::assertNull($socket->receive(.5));self::assertTrue($socket->isClosed());}
        finally {restore_error_handler();$socket->close();fclose($peer);stream_wrapper_unregister('mvowsclose');CloseAcknowledgementFailureStream::$source=null;}
        self::assertSame(1,CloseAcknowledgementFailureStream::$writes);self::assertSame([],$warnings);
    }

    public function testApplicationWriteFailureRemainsAnError():void
    {
        $path=tempnam(sys_get_temp_dir(),'mvo-ws-write-');self::assertNotFalse($path);
        $stream=fopen($path,'rb');self::assertIsResource($stream);$socket=new ServerWebSocket($stream);$warnings=[];$failure=null;
        set_error_handler(static function(int $severity,string $message)use(&$warnings):bool {$warnings[]=$message;return true;});
        try {$socket->send('application data');}
        catch(\RuntimeException $error){$failure=$error;}
        finally {restore_error_handler();$socket->close();unlink($path);}
        self::assertInstanceOf(\RuntimeException::class,$failure);self::assertSame('Fake WebSocket write failed',$failure->getMessage());
        self::assertNotEmpty($warnings);
    }

    /** @return array{resource,resource} */
    private function pair():array
    {
        $listener=stream_socket_server('tcp://127.0.0.1:0',$code,$error);self::assertIsResource($listener);
        try {
            $address=stream_socket_get_name($listener,false);self::assertIsString($address);
            $peer=stream_socket_client('tcp://'.$address,$code,$error,2);self::assertIsResource($peer);
            $server=stream_socket_accept($listener,2);self::assertIsResource($server);stream_set_timeout($peer,2);
            return [$server,$peer];
        } finally {fclose($listener);}
    }

    private static function closeFrame(string $body):string
    {
        $mask='abcd';$masked=$body;for($i=0;$i<strlen($body);$i++)$masked[$i]=$body[$i]^$mask[$i%4];
        return "\x88".chr(128|strlen($body)).$mask.$masked;
    }
}

/** Deterministically fails only the close reply after an actual TCP frame was read. */
final class CloseAcknowledgementFailureStream
{
    public mixed $context;
    /** @var resource|null */
    public static $source=null;
    public static int $writes=0;
    /** @var resource|null */
    private $socket=null;
    public function stream_open(string $path,string $mode,int $options,?string &$openedPath):bool {$this->socket=self::$source;return is_resource($this->socket);}
    public function stream_read(int $count):string|false {return fread($this->socket,$count);}
    public function stream_write(string $data):int {self::$writes++;trigger_error('Simulated close acknowledgement: connection reset by peer',E_USER_WARNING);return 0;}
    public function stream_eof():bool {return feof($this->socket);}
    public function stream_set_option(int $option,int $arg1,?int $arg2):bool {return true;}
    public function stream_cast(int $castAs):mixed {return $this->socket;}
    public function stream_close():void {if(is_resource($this->socket))fclose($this->socket);$this->socket=null;}
}
