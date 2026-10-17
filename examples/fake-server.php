<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use MVO\Testing\FakeServer;

$port=isset($argv[1]) ? filter_var($argv[1],FILTER_VALIDATE_INT) : 8787;
if($port===false)throw new \InvalidArgumentException('Port must be an integer');
$server=new FakeServer($port);$server->listen();
fwrite(STDOUT,json_encode(['url'=>$server->url(),'rootPublicKeyBase64'=>base64_encode($server->rootPublicKey()),'mode'=>'development-only'],JSON_THROW_ON_ERROR)."\n");fflush(STDOUT);
try { while(true) { try {$server->serveOnce();}catch(\Throwable $e){fwrite(STDERR,'Fake peer closed: '.get_class($e)."\n");} } }
finally {$server->close();}
