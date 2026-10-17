<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Auth\{CredentialStore,PairingFlow};
use MVO\Protocol\{BinaryNode,ClientPayload};
use MVO\Transport\{NoiseSession,WebSocket};

final class FakePairingSocketTest extends TestCase
{
    public function testQrPairingAndIkRestartOverActualLoopbackWebSocket():void { $this->runPairing('qr'); }
    public function testCodePairingAndIkRestartOverActualLoopbackWebSocket():void { $this->runPairing('code'); }
    private function runPairing(string $mode):void
    {
        $ini=php_ini_loaded_file();
        $process=proc_open([PHP_BINARY,...($ini===false ? [] : ['-c',$ini]),__DIR__.'/FakePairingProcess.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,array_merge(getenv(),['XDEBUG_MODE'=>'off']),['bypass_shell'=>true,'create_new_console'=>false]);self::assertIsResource($process);$config=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);$credentials=CredentialStore::fresh();$directory=sys_get_temp_dir().'/mvo-fake-socket-'.bin2hex(random_bytes(8));$store=new CredentialStore($directory);$store->save($credentials);$session=null;$second=null;
        try{
            $ws=new WebSocket($config['url'],5);$ws->connect();$session=new NoiseSession($ws,5,base64_decode($config['root'],true));$static=$session->start($credentials['noiseKeyPair']['private'],$credentials['noiseKeyPair']['public'],ClientPayload::registration($credentials,'2.3000.100'));$qr=null;
            $query=function(BinaryNode $node)use($session):BinaryNode{$id='QUERY-'.bin2hex(random_bytes(8));$session->sendNode(new BinaryNode($node->tag,[...$node->attrs,'id'=>$id],$node->content));$response=$session->receiveNode(5);if($response===null || ($response->attrs['id']??null)!==$id)throw new \UnexpectedValueException('Fake socket query ID mismatch');return $response;};
            $flow=new PairingFlow(function()use(&$credentials){return $credentials;},function($next)use(&$credentials,$store){$credentials=$next;$store->save($next);},$session->sendNode(...),$query,function($event,$data)use(&$qr){if($event==='auth_qr')$qr=$data['qr'];});$flow->handleNode($session->receiveNode(5));self::assertIsString($qr);$approval=$qr;if($mode==='code')$approval=$flow->requestCode('5511999999999','ABCD1234');$control=$query(new BinaryNode('iq',['type'=>'set','xmlns'=>'test:pairing'],[new BinaryNode('approve',['mode'=>$mode],$approval)]));self::assertSame('result',$control->attrs['type']);$flow->handleNode($session->receiveNode(5));if($mode==='code')$flow->handleNode($session->receiveNode(5));self::assertTrue($credentials['registered']);self::assertSame('5511999999999:7@s.whatsapp.net',$credentials['meJid']);self::assertSame('515',$session->receiveNode(5)->attrs['code']);$session->close();$store->close();
            $reopened=new CredentialStore($directory);$saved=$reopened->load();$ws=new WebSocket($config['url'],5);$ws->connect();$second=new NoiseSession($ws,5,base64_decode($config['root'],true),serverStaticKey:$static,routingInfo:'fake-persisted-route');self::assertSame($static,$second->start($saved['noiseKeyPair']['private'],$saved['noiseKeyPair']['public'],ClientPayload::login($saved,'2.3000.100')));self::assertSame('success',$second->receiveNode(5)->tag);$second->sendNode(new BinaryNode('iq',['type'=>'get','xmlns'=>'urn:xmpp:ping','id'=>'RESTART-PING'],[new BinaryNode('ping')]));$pong=$second->receiveNode(5);self::assertSame('RESTART-PING',$pong->attrs['id']);self::assertSame('result',$pong->attrs['type']);$second->close();$reopened->close();
        }finally{$session?->close();$second?->close();$store->close();foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);self::assertSame(0,proc_close($process));foreach(['state.sqlite','state.sqlite-wal','state.sqlite-shm','connection.lock'] as $file)if(is_file($directory.'/default/'.$file))unlink($directory.'/default/'.$file);if(is_dir($directory.'/default'))rmdir($directory.'/default');if(is_dir($directory))rmdir($directory);}
    }
}
