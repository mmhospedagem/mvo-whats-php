<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use MVO\Media\MediaTransfer;
use MVO\Protocol\{BinaryData,BinaryNode,Jid,SchemaCodec};
use MVO\Signal\SignalStore;
use MVO\Testing\{FakeAppState,FakeMediaClient,FakeMediaServer,FakePeer,FakeProtocol,FakeServer};
use MVO\Transport\{NoiseSession,WebSocket};
use MVO\Auth\CredentialStore;
use MVO\WaClient;

// Two foreground terminals, entirely isolated from .auth/default and WhatsApp infrastructure.
$mode=$argv[1] ?? 'server';$directory=$argv[2] ?? dirname(__DIR__).'/.cache/fake-whatsapp';
if(!in_array($mode,['server','client','client-code'],true))throw new InvalidArgumentException('Use server, client or client-code');
if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('Cannot create isolated fake workspace');
$directory=realpath($directory);if($directory===false)throw new RuntimeException('Cannot resolve fake workspace');

if($mode==='server'){
    $protocol=new FakeProtocol(new SignalStore($directory.'/registry.sqlite','fake-registry'));
    $primary=$protocol->createPeer('5511000000000@s.whatsapp.net',$directory.'/primary.sqlite');
    $peer=$protocol->createPeer('5522000000000@s.whatsapp.net',$directory.'/peer.sqlite');
    $devices=$protocol->devices([$primary->jid])[$primary->jid];$index=1;foreach($devices as $device)$index=max($index,Jid::parse($device)->device+1);
    $pairing=$primary->primaryPairing($index);$protocol->configurePairing($pairing);
    $media=new FakeMediaServer($directory.'/tls',advertisedOrigin:'https://localhost');$media->listen();$protocol->configureMedia($media);
    $appstate=new FakeAppState(new SignalStore($directory.'/appstate.sqlite','fake-appstate'),media:$media);$protocol->configureAppState($appstate);
    $peer->onMessage(function(FakePeer $peer,array $message):void {
        if(in_array($message['type'],['document','image','audio','video','sticker'],true))$peer->sendMessage($message['chat'],['type'=>'raw','message'=>$message['proto']]);
        elseif(is_string($message['text'] ?? null))$peer->sendConversation($message['chat'],'Eco PHP: '.$message['text']);
    });
    $server=new FakeServer(protocol:$protocol);$server->listen();$control=bin2hex(random_bytes(24));
    // Test control routes are explicitly opt-in and are not WhatsApp protocol operations.
    $server->route('iq','test:pairing',function(BinaryNode $node)use($server,$pairing,$control):array {
        if(!hash_equals($control,$node->attrs['token'] ?? '')||$server->authenticatedDevice()!=='__pairing__')return [FakeProtocol::error($node,403,'fake-control-denied')];
        $approve=$node->child('approve');if(!is_string($approve?->content))return [FakeProtocol::error($node,400,'fake-control-missing-proof')];$next=($approve->attrs['mode'] ?? '')==='qr' ? $pairing->scanQr($approve->content) : $pairing->approveCode($approve->content);return [FakeProtocol::result($node),$next];
    });
    $protocol->route('test:fixtures',function(BinaryNode $node,string $sender)use($protocol,$primary,$peer,$appstate,$control):array {
        if(!hash_equals($control,$node->attrs['token'] ?? ''))return [FakeProtocol::error($node,403,'fake-control-denied')];
        $protocol->createGroup('120363000000000001@g.us','Grupo local PHP',[$sender,$peer->jid]);$primary->sendAppStateSyncKeyShare($sender,$appstate->keyShare());
        $primary->sendHistorySync($sender,SchemaCodec::encode('HistorySync',['syncType'=>3,'conversations'=>[['id'=>$peer->jid,'messages'=>[['message'=>['key'=>['id'=>'FAKE-HISTORY','remoteJid'=>$peer->jid,'fromMe'=>false],'message'=>['conversation'=>'Histórico local autenticado'],'messageTimestamp'=>time()-60]]]]]]));
        return [FakeProtocol::result($node)];
    });
    $public=['url'=>$server->url(),'root'=>$server->rootPublicKey(),'mediaAddress'=>$media->address(),'mediaCa'=>$media->caFile(),'control'=>$control];$configPath=$directory.'/connection.json';file_put_contents($configPath,BinaryData::encode($public));chmod($configPath,0600);
    fwrite(STDOUT,"Servidor falso em ".$server->url().". Configuração isolada: ".$configPath."\nCtrl+C encerra. Nenhum número ou serviço real é acessado.\n");
    try{while(true)$server->serveOnce(1,10000,function()use($media):void{$media->poll();});}
    finally{$server->close();$media->close();$appstate->close();$protocol->close();}
    exit;
}

$bytes=file_get_contents($directory.'/connection.json');if($bytes===false)throw new RuntimeException('Start the fake server first');$config=BinaryData::decode($bytes);$auth=$directory.'/client-auth';
$factory=function()use($config,$auth):NoiseSession {$store=new CredentialStore($auth,'fake-client');$credentials=$store->load();$store->close();$socket=new WebSocket($config['url'],5);$socket->connect();return new NoiseSession($socket,5,$config['root'],serverStaticKey:$credentials['serverStaticKey'] ?? null,routingInfo:'isolated-fake-route');};
$mediaHttp=new FakeMediaClient($config['mediaCa'],$config['mediaAddress']);$client=new WaClient('fake-client',$auth,sessionFactory:$factory,reconnectMinSeconds:0.05,reconnectMaxSeconds:1,mediaTransferFactory:fn(callable $query):MediaTransfer=>new MediaTransfer($query,$mediaHttp,allowedHosts:['localhost']));
$qr=null;$code=null;$approved=false;$echoes=0;
$client->on('auth_qr',function(array $event)use(&$qr):void{$qr=$event['qr'];});
$client->on('connection',function(array $event):void{fwrite(STDOUT,'Estado: '.$event['state']."\n");});
$client->on('message',function(array $event)use(&$echoes):void{$echoes++;fwrite(STDOUT,'Recebido: '.($event['text'] ?? $event['type'])."\n");});
try{
    $client->connect();$deadline=microtime(true)+20;
    while(!$client->isConnected()&&microtime(true)<$deadline){$client->poll(0.05);if(!$client->isRegistered()&&is_string($qr)&&!$approved){$approval=$qr;$pairMode='qr';if($mode==='client-code'){$pairMode='code';$code=$client->requestPairingCode('5511000000000');$approval=$code;}$client->query(new BinaryNode('iq',['xmlns'=>'test:pairing','type'=>'set','token'=>$config['control']],[new BinaryNode('approve',['mode'=>$pairMode],$approval)]));$approved=true;}}
    if(!$client->isConnected())throw new RuntimeException('Fake client login timed out');
    $client->query(new BinaryNode('iq',['xmlns'=>'test:fixtures','type'=>'set','token'=>$config['control']]));
    $client->sendText('5522000000000@s.whatsapp.net','Mensagem Signal local');$client->sendText('120363000000000001@g.us','Mensagem SenderKey local');
    $client->sendMessage('5522000000000@s.whatsapp.net',['type'=>'document','fileName'=>'teste.txt','media'=>['bytes'=>'Arquivo enviado por HTTPS local']]);
    $deadline=microtime(true)+8;while($echoes<3&&microtime(true)<$deadline)$client->poll(0.05);
    fwrite(STDOUT,"Execute novamente o cliente para retomar a sessão persistida sem QR.\n");
}finally{$client->stop();}
