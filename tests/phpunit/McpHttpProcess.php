<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use MVO\Mcp\{HttpServer,StdioServer,ToolRegistry};

$tools=new ToolRegistry();$counter=0;$cleaned=0;$server=null;$start=microtime(true);
$tools->add('echo','Echo fixture',['type'=>'object','properties'=>['value'=>['type'=>'string']],'required'=>['value'],'additionalProperties'=>false],static fn(array $p):array=>['value'=>$p['value']],true);
$tools->add('counter','Shared runtime fixture',['type'=>'object'],static function(array $p)use(&$counter):array{return ['value'=>++$counter];});
$tools->add('publish','Queue server notification fixture',['type'=>'object','properties'=>['session'=>['type'=>'string'],'text'=>['type'=>'string']],'required'=>['session','text'],'additionalProperties'=>false],static function(array $p)use(&$server):array{$server->publish($p['session'],['jsonrpc'=>'2.0','method'=>'notifications/message','params'=>['text'=>$p['text'],'privateKey'=>'fixture secret']]);return ['queued'=>true];});
$tools->add('cleanup','Cleanup counter fixture',['type'=>'object'],static function(array $p)use(&$cleaned):array{return ['value'=>$cleaned];},true);
$tools->add('stop','Stop fixture',['type'=>'object'],static function(array $p)use(&$server):array{$server->stop();return ['stopped'=>true];});
$server=new HttpServer(static fn():StdioServer=>new StdioServer($tools),port:0,bearerToken:'test-bearer-token-32-characters-long',maxBytes:8192,tick:static function()use(&$server,$start):void{if(microtime(true)-$start>20)$server->stop();},cleanup:static function(string $id)use(&$cleaned):void{$cleaned++;});
try{$server->listen();fwrite(STDOUT,json_encode(['url'=>$server->url()],JSON_THROW_ON_ERROR)."\n");fflush(STDOUT);$server->run();}finally{$server->close();}
