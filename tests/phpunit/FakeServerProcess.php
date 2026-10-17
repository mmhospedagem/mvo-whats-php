<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use MVO\Testing\FakeServer;
$server=new FakeServer(corruptCertificate:($argv[1] ?? '')==='corrupt');$server->listen();fwrite(STDOUT,json_encode(['url'=>$server->url(),'root'=>base64_encode($server->rootPublicKey())],JSON_THROW_ON_ERROR)."\n");fflush(STDOUT);
try {$server->serveOnce(5,2);}catch(\Throwable $e){fwrite(STDERR,get_class($e)."\n");}finally{$server->close();}
