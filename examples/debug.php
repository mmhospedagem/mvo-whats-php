<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use MVO\Media\MediaCrypto;
use MVO\Protocol\{BinaryCodec,BinaryNode,Jid};

// F5 in VS Code: "Depurar exemplo PHP". Set a breakpoint on any executable line below.
$recipient = Jid::recipient('+55 (11) 99999-0000');
$codec = new BinaryCodec();
$node = new BinaryNode('message',['to'=>(string)$recipient,'id'=>'debug-local'],'payload de teste');
$encoded = $codec->encode($node,true);
$decoded = $codec->decode($encoded,true);
$encrypted = MediaCrypto::encrypt('bytes locais','image');
$plaintext = MediaCrypto::decrypt($encrypted['body'],'image',$encrypted['mediaKey']);

echo json_encode([
    'recipient'=>(string)$recipient,
    'stanzaHex'=>bin2hex($encoded),
    'decodedTag'=>$decoded->tag,
    'mediaDecrypted'=>$plaintext,
    'network'=>'Nenhuma conexao WhatsApp neste exemplo'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . PHP_EOL;
