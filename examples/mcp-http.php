<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use MVO\Mcp\{ClientRuntime,HttpServer};
use MVO\WaClient;

ini_set('display_errors','stderr');
$session=getenv('MCP_SESSION_ID') ?: 'default';$auth=getenv('MCP_AUTH_DIRECTORY') ?: dirname(__DIR__).'/.auth';
$runtime=new ClientRuntime(static fn(string $id):WaClient=>new WaClient(sessionId:$id,authDirectory:$auth),$session);
$port=filter_var(getenv('MCP_HTTP_PORT') ?: '8788',FILTER_VALIDATE_INT);if($port===false)throw new InvalidArgumentException('Invalid MCP_HTTP_PORT');
$origins=array_values(array_filter(explode(',',getenv('MCP_HTTP_ORIGINS') ?: '')));$hosts=array_values(array_filter(explode(',',getenv('MCP_HTTP_HOSTS') ?: '')));
$server=new HttpServer($runtime->server(...),host:getenv('MCP_HTTP_HOST') ?: '127.0.0.1',port:$port,path:getenv('MCP_HTTP_PATH') ?: '/mcp',bearerToken:getenv('MCP_BEARER_TOKEN') ?: null,allowedOrigins:$origins,allowedHosts:$hosts,tick:$runtime->tick(...));
try{$server->listen();fwrite(STDERR,'MCP HTTP listening at '.$server->url().PHP_EOL);$server->run();}finally{$server->close();$runtime->close();}
