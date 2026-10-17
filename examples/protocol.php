<?php
declare(strict_types=1);
require __DIR__.'/../autoload.php';
use MVO\Protocol\{BinaryCodec,BinaryNode,Jid,Protobuf};
use MVO\Media\MediaCrypto;
use MVO\Transport\FrameCodec;

// Local protocol demonstration. Does not connect to WhatsApp.
$jid = Jid::recipient('+55 (11) 99999-0000');
echo "Recipient: $jid\n";
$codec = new BinaryCodec();
$node = new BinaryNode('iq',['id'=>'demo','type'=>'get','xmlns'=>'w:p','to'=>'s.whatsapp.net'],[new BinaryNode('ping')]);
$stanza = $codec->encode($node,true);
echo 'Stanza: '.bin2hex($stanza)."\n";
echo 'Decoded tag: '.$codec->decode($stanza,true)->tag."\n";
$frames = new FrameCodec();
echo 'Framed: '.bin2hex($frames->encode($stanza))."\n";
$encrypted = MediaCrypto::encrypt('example bytes','image');
echo 'Media plaintext: '.MediaCrypto::decrypt($encrypted['body'],'image',$encrypted['mediaKey'],$encrypted['fileSha256'],$encrypted['fileEncSha256'])."\n";
// WAProto.Message conversation is field 1, length-delimited. This serializes only the payload.
$message = Protobuf::encode([['number'=>1,'wire'=>2,'value'=>'Ola, PHP!']]);
echo 'Message payload: '.bin2hex($message)."\n";
