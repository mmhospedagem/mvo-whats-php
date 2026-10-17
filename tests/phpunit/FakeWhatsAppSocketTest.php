<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Auth\CredentialStore;
use MVO\AppState\AppStateService;
use MVO\Media\MediaTransfer;
use MVO\Protocol\{BinaryData,BinaryNode};
use MVO\Signal\SignalStore;
use MVO\Transport\{NoiseSession,WebSocket};
use MVO\WaClient;
use MVO\Testing\FakeMediaClient;

final class FakeWhatsAppSocketTest extends TestCase
{
    public function testProductionClientSignalGroupHttpsAppStateAndDurableRestart():void
    {
        $directory=sys_get_temp_dir().'/mvo-fake-whatsapp-'.bin2hex(random_bytes(8));mkdir($directory,0700);$credentials=CredentialStore::fresh();$credentials['registered']=true;$credentials['meJid']='5511000000000:5@s.whatsapp.net';$credentials['signedIdentity']=['details'=>'test-public-registration','accountSignatureKey'=>random_bytes(32),'accountSignature'=>random_bytes(64),'deviceSignature'=>random_bytes(64)];
        file_put_contents($directory.'/client.json',BinaryData::encode($credentials));chmod($directory.'/client.json',0600);$saved=new CredentialStore($directory.'/auth');$saved->save($credentials);$saved->close();
        $ini=php_ini_loaded_file();
        $process=proc_open([PHP_BINARY,...($ini===false ? [] : ['-c',$ini]),__DIR__.'/FakeWhatsAppProcess.php',$directory],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,array_merge(getenv(),['XDEBUG_MODE'=>'off']),['bypass_shell'=>true,'create_new_console'=>false]);self::assertIsResource($process);$line=fgets($pipes[1]);self::assertIsString($line);$config=BinaryData::decode($line);$client=null;$events=[];
        $http=new FakeMediaClient($config['mediaCa'],$config['mediaAddress']);
        $make=function()use($directory,$config,$http,&$events):WaClient {$factory=function()use($directory,$config):NoiseSession {$store=new CredentialStore($directory.'/auth');$c=$store->load();$store->close();$ws=new WebSocket($config['url'],5);$ws->connect();return new NoiseSession($ws,5,$config['root'],serverStaticKey:$c['serverStaticKey'] ?? null,routingInfo:'offline-fixture-route');};$client=new WaClient(authDirectory:$directory.'/auth',sessionFactory:$factory,autoReconnect:false,mediaTransferFactory:fn(callable $query):MediaTransfer=>new MediaTransfer($query,$http,allowedHosts:['localhost']));foreach(['message','message_error','service_error','auth_qr'] as $event)$client->on($event,function(array $data)use($event,&$events):void{$events[]=[$event,$data];});return $client;};
        try {
            $client=$make();$client->connect();$this->until($client,fn():bool=>$client->isConnected());$first=$client->sendText('5522000000000@s.whatsapp.net','first encrypted message');self::assertSame('server_ack',$first['status']);$this->until($client,function()use(&$events):bool{return in_array('Echo: first encrypted message',array_column(array_column($events,1),'text'),true);});
            $group=$client->sendText('120363000000000001@g.us','group ciphertext');self::assertSame('server_ack',$group['status']);$this->until($client,function()use(&$events):bool{return in_array('Echo: group ciphertext',array_column(array_column($events,1),'text'),true);});
            $transfer=new MediaTransfer($client->query(...),$http,allowedHosts:['localhost']);$plain="Actual TLS encrypted bytes\0".random_bytes(2000);$descriptor=$transfer->upload($plain,'document');self::assertSame($plain,$transfer->download([...$descriptor,'mediaType'=>'document']));
            $client->sendMessage('5522000000000@s.whatsapp.net',['type'=>'document','media'=>['bytes'=>$plain],'fileName'=>'offline.bin']);$this->until($client,function()use(&$events):bool{return in_array('document',array_column(array_column($events,1),'type'),true);});$mediaEvents=array_values(array_filter(array_column($events,1),fn(array $e):bool=>($e['type'] ?? '')==='document'));self::assertSame($plain,$client->downloadMedia($mediaEvents[0]));
            $image=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aDi8AAAAASUVORK5CYII=',true);$client->sendMessage('5522000000000@s.whatsapp.net',['type'=>'image','media'=>['bytes'=>$image]]);$this->until($client,function()use(&$events):bool{return in_array('image',array_column(array_column($events,1),'type'),true);});$mediaEvents=array_values(array_filter(array_column($events,1),fn(array $e):bool=>($e['type'] ?? '')==='image'));self::assertSame($image,$client->downloadMedia($mediaEvents[0]));
            $stream=fopen('php://temp','w+b');fwrite($stream,$plain);rewind($stream);$streamed=$transfer->uploadStream($stream,'document');fclose($stream);$target=$directory.'/download.bin';$transfer->downloadStream([...$streamed,'mediaType'=>'document'],$target);self::assertSame($plain,file_get_contents($target));
            $key=$client->query(new BinaryNode('iq',['xmlns'=>'test:fixture','type'=>'get'],[new BinaryNode('op',['name'=>'appstate-snapshot'])]))->child('key')->content;$stateStore=new SignalStore(':memory:','snapshot-client');$appstate=new AppStateService($stateStore,$client->query(...),downloadExternal:fn(array $d):string=>$transfer->download($d));$appstate->importKeys([BinaryData::decode($key)]);$result=$appstate->sync(['regular_high']);self::assertSame('success',$result['collections'][0]['state']);self::assertSame(1,$appstate->collectionState('regular_high')['version']);$stateStore->close();
            $client->stop();$client=$make();$client->connect();$this->until($client,fn():bool=>$client->isConnected());$second=$client->sendText('5522000000000@s.whatsapp.net','after durable restart');self::assertSame('server_ack',$second['status']);$this->until($client,function()use(&$events):bool{return in_array('Echo: after durable restart',array_column(array_column($events,1),'text'),true);});self::assertSame([],array_values(array_filter($events,fn(array $e):bool=>in_array($e[0],['auth_qr','message_error','service_error'],true))));$client->stop();
            fclose($pipes[0]);$error=stream_get_contents($pipes[2]);self::assertSame('',$error);foreach([1,2] as $index)fclose($pipes[$index]);self::assertSame(0,proc_close($process));$process=null;$peerMessages=BinaryData::decode(file_get_contents($directory.'/peer-messages.json'));self::assertCount(5,$peerMessages);
        } finally {$client?->stop();if(is_resource($process)){foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_terminate($process);proc_close($process);}self::cleanup($directory);}
    }
    private function until(WaClient $client,callable $done):void {$end=microtime(true)+6;while(!$done()&&microtime(true)<$end)$client->poll(0.02);self::assertTrue($done(),'Offline client did not reach expected condition');}
    private static function cleanup(string $directory):void {if(!is_dir($directory))return;foreach(new \FilesystemIterator($directory) as $file)if($file->isDir())self::cleanup($file->getPathname());else unlink($file->getPathname());rmdir($directory);}
}
