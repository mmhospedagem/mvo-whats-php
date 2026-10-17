<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Auth\CredentialStore;
use MVO\Protocol\BinaryNode;
use MVO\Transport\{NoiseSession,WebSocket};
use MVO\WaClient;

final class FakeWaClientPairingTest extends TestCase
{
    public function testProductionWaClientQrPairingAndNewInstanceWithoutQr():void {$this->pair('qr');}
    public function testProductionWaClientCodePairingAndNewInstanceWithoutQr():void {$this->pair('code');}
    private function pair(string $mode):void
    {
        $directory=sys_get_temp_dir().'/mvo-fake-waclient-'.bin2hex(random_bytes(8));$process=proc_open([PHP_BINARY,'-c',dirname(__DIR__,2).'/.dev/php.ini',__DIR__.'/FakePairingProcess.php','3'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,null,['bypass_shell'=>true,'create_new_console'=>false]);self::assertIsResource($process);$config=json_decode(fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);$qr=null;$qrCount=0;$client=null;
        $factory=function()use($directory,$config):NoiseSession {$store=new CredentialStore($directory);$credentials=$store->load();$store->close();$ws=new WebSocket($config['url'],5);$ws->connect();return new NoiseSession($ws,5,base64_decode($config['root'],true),serverStaticKey:$credentials['serverStaticKey'] ?? null,routingInfo:'fake-routing-info');};
        $make=function()use($directory,$factory,&$qr,&$qrCount):WaClient {$client=new WaClient(authDirectory:$directory,sessionFactory:$factory,reconnectMinSeconds:0.01,reconnectMaxSeconds:0.05);$client->on('auth_qr',function(array $data)use(&$qr,&$qrCount):void {$qr=$data['qr'];$qrCount++;});return $client;};
        try {$client=$make();$client->connect();$this->until($client,function()use(&$qr):bool{return is_string($qr);});$approval=$mode==='qr' ? $qr : $client->requestPairingCode('5511999999999','ABCD1234');$result=$client->query(new BinaryNode('iq',['xmlns'=>'test:pairing','type'=>'set'],[new BinaryNode('approve',['mode'=>$mode],$approval)]));self::assertSame('result',$result->attrs['type']);$this->until($client,fn():bool=>$client->isConnected());self::assertSame('5511999999999:7@s.whatsapp.net',$client->accountJid());self::assertTrue($client->isRegistered());$count=$qrCount;$client->stop();
            $client=$make();$client->connect();$this->until($client,fn():bool=>$client->isConnected());self::assertSame($count,$qrCount);$ping=$client->query(new BinaryNode('iq',['xmlns'=>'urn:xmpp:ping','type'=>'get'],[new BinaryNode('ping')]));self::assertSame('result',$ping->attrs['type']);$client->stop();fclose($pipes[0]);self::assertSame('',stream_get_contents($pipes[2]));fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process));$process=null;
        }finally {$client?->stop();if(is_resource($process)){foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_terminate($process);proc_close($process);}self::cleanup($directory);}
    }
    private function until(WaClient $client,callable $done):void {$end=microtime(true)+6;while(!$done()&&microtime(true)<$end)$client->poll(0.02);self::assertTrue($done(),'Fake pairing did not complete');}
    private static function cleanup(string $directory):void {if(!is_dir($directory))return;foreach(new \FilesystemIterator($directory) as $file)if($file->isDir())self::cleanup($file->getPathname());else unlink($file->getPathname());rmdir($directory);}
}
