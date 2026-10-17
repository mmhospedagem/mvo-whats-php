<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use MVO\Protocol\{BinaryData,BinaryNode};
use MVO\Signal\SignalStore;
use MVO\Testing\{FakeAppState,FakeMediaServer,FakePeer,FakeProtocol,FakeServer};

$directory=$argv[1];$credentials=BinaryData::decode(file_get_contents($directory.'/client.json'));
$protocol=new FakeProtocol(new SignalStore($directory.'/registry.sqlite','fake-registry'));$protocol->registerClient($credentials);
$peer=$protocol->createPeer('5522000000000@s.whatsapp.net',$directory.'/peer.sqlite');
$protocol->createGroup('120363000000000001@g.us','Offline group',[$credentials['meJid'],$peer->jid]);
$peer->onMessage(function(FakePeer $peer,array $message):void {if(in_array($message['type'],['document','image'],true))$peer->sendMessage($message['chat'],['type'=>'raw','message'=>$message['proto']]);elseif(is_string($message['text'] ?? null))$peer->sendConversation($message['chat'],'Echo: '.$message['text']);});
$media=new FakeMediaServer($directory.'/tls',advertisedOrigin:'https://localhost');$media->listen();$protocol->configureMedia($media);$appstate=new FakeAppState(new SignalStore($directory.'/appstate.sqlite','fake-appstate'),media:$media);$protocol->configureAppState($appstate);
$appstate->appendMutation('Star',['remote'=>$peer->jid,'id'=>'FAKE-SNAPSHOT','fromMe'=>false],['starred'=>true]);
$server=new FakeServer(protocol:$protocol);$server->listen();
$protocol->route('test:fixture',function(BinaryNode $node,string $sender)use($protocol,$appstate):array {$op=$node->child('op');if(($op?->attrs['name'] ?? '')==='appstate-snapshot')return [FakeProtocol::result($node,[new BinaryNode('key',[],BinaryData::encode($appstate->key()))])];return [FakeProtocol::error($node,501,'unknown-fixture-command')];});
fwrite(STDOUT,BinaryData::encode(['url'=>$server->url(),'root'=>$server->rootPublicKey(),'mediaAddress'=>$media->address(),'mediaCa'=>$media->caFile()])."\n");fflush(STDOUT);
try {for($i=0;$i<2;$i++)if(!$server->serveOnce(5,1000,function()use($media):void{$media->poll();}))throw new \RuntimeException('Missing expected fake client connection');file_put_contents($directory.'/peer-messages.json',BinaryData::encode($peer->messages()));}
catch(\Throwable $error){fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");exit(1);}
finally {$server->close();$media->close();$appstate->close();$protocol->close();}
