<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use MVO\Auth\CredentialStore;
use MVO\Protocol\BinaryNode;
use MVO\Testing\{FakePairing,FakeProtocol,FakeServer};
$primary=CredentialStore::fresh();$primary['registered']=true;$primary['meJid']='5511999999999@s.whatsapp.net';$primary['meLid']='123456@lid';$pairing=new FakePairing($primary,7);$protocol=new FakeProtocol();$protocol->configurePairing($pairing);$server=new FakeServer(protocol:$protocol);$server->route('iq','test:pairing',function(BinaryNode $node)use($pairing,$server):array{
    if($server->authenticatedDevice()!=='__pairing__')throw new \UnexpectedValueException('Pairing control requires a pending test registration');$input=$node->child('approve')??throw new \UnexpectedValueException('Missing test pairing approval');$text=is_string($input->content)?$input->content:'';$out=($input->attrs['mode']??'')==='qr'?$pairing->scanQr($text):$pairing->approveCode($text);return [new BinaryNode('iq',['type'=>'result','id'=>$node->attrs['id'],'from'=>'s.whatsapp.net']),$out];
});$server->listen();fwrite(STDOUT,json_encode(['url'=>$server->url(),'root'=>base64_encode($server->rootPublicKey())],JSON_THROW_ON_ERROR)."\n");fflush(STDOUT);
try{for($i=0;$i<(int)($argv[1] ?? 2);$i++)if(!$server->serveOnce(5,100))throw new \RuntimeException('Expected fake pairing/restart connection was not made');}catch(\Throwable $error){fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");exit(1);}finally{$server->close();}
