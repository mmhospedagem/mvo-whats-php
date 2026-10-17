<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Testing\FakeServer;
use MVO\Crypto\Crypto;
use MVO\Transport\{WebSocket,NoiseSession};
use MVO\Protocol\BinaryNode;

final class FakeServerTest extends TestCase
{
    private function peer(string $mode=''): array
    {
        $ini=php_ini_loaded_file();
        $process=proc_open([PHP_BINARY,...($ini===false ? [] : ['-c',$ini]),__DIR__.'/FakeServerProcess.php',$mode],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,array_merge(getenv(),['XDEBUG_MODE'=>'off']),['bypass_shell'=>true,'create_new_console'=>false]);self::assertIsResource($process);$data=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);return [$process,$pipes,$data];
    }
    private function closePeer($process,array $pipes): void {foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);self::assertSame(0,proc_close($process));}
    public function testActualLoopbackWebSocketNoiseCertificateAndEncryptedBidirectionalFrames(): void
    {
        [$process,$pipes,$data]=$this->peer();$socket=new WebSocket($data['url'],5);$socket->connect();$session=new NoiseSession($socket,5,base64_decode($data['root'],true));$key=Crypto::x25519KeyPair();
        try {
            self::assertSame(32,strlen($session->start($key['private'],$key['public'],'offline debug payload')));self::assertSame('success',$session->receiveNode(5)->tag);
            $session->sendNode(new BinaryNode('iq',['type'=>'get','xmlns'=>'test:echo','id'=>'IQ1'],[new BinaryNode('data',[],"\xff\0".str_repeat('x',300))]));$response=$session->receiveNode(5);self::assertSame('result',$response->attrs['type']);self::assertSame('IQ1',$response->attrs['id']);self::assertSame("\xff\0".str_repeat('x',300),$response->child('data')->content);
            $session->sendNode(new BinaryNode('message',['id'=>'MSG1','to'=>'8@lid','type'=>'text'],[new BinaryNode('enc',['type'=>'msg','v'=>'2'],'fake ciphertext bytes')]));$ack=$session->receiveNode(5);self::assertSame(['class'=>'message','id'=>'MSG1','from'=>'8@lid'],array_diff_key($ack->attrs,['t'=>true]));
        }finally{$session->close();$this->closePeer($process,$pipes);}
    }
    public function testDefaultProductionTrustRootRejectsFakeServer(): void
    {
        [$process,$pipes,$data]=$this->peer();$socket=new WebSocket($data['url'],5);$socket->connect();$session=new NoiseSession($socket,5);$key=Crypto::x25519KeyPair();
        try {$session->start($key['private'],$key['public'],'debug');self::fail('Fake CA accepted by production defaults');}catch(\UnexpectedValueException $e){self::assertStringContainsString('signature',$e->getMessage());}finally{$session->close();$this->closePeer($process,$pipes);}
    }
    public function testExplicitTestRootStillRejectsTamperedCertificate(): void
    {
        [$process,$pipes,$data]=$this->peer('corrupt');$socket=new WebSocket($data['url'],5);$socket->connect();$session=new NoiseSession($socket,5,base64_decode($data['root'],true));$key=Crypto::x25519KeyPair();
        try {$session->start($key['private'],$key['public'],'debug');self::fail('Tampered fake certificate accepted');}catch(\UnexpectedValueException $e){self::assertStringContainsString('signature',$e->getMessage());}finally{$session->close();$this->closePeer($process,$pipes);}
    }
    public function testUnknownIqIsRefusedAndRoutesAreExplicit(): void
    {
        $server=new FakeServer();$n=new BinaryNode('iq',['id'=>'1','xmlns'=>'w:biz','type'=>'get']);self::assertSame('501',$server->dispatch($n)[0]->child('error')->attrs['code']);$server->route('iq','w:biz',static fn(BinaryNode $n):array=>[new BinaryNode('iq',['type'=>'result','id'=>$n->attrs['id']],[new BinaryNode('business_profile')])]);self::assertNotNull($server->dispatch($n)[0]->child('business_profile'));self::assertSame([],$server->dispatch(new BinaryNode('presence')));
    }
    public function testListenIsLoopbackOnlyAndCloseCanRelisten(): void
    {
        $server=new FakeServer();$server->listen();self::assertStringStartsWith('ws://127.0.0.1:',$server->url());self::assertSame(32,strlen($server->rootPublicKey()));$server->close();$server->listen();self::assertStringStartsWith('ws://127.0.0.1:',$server->url());$server->close();
    }
}
