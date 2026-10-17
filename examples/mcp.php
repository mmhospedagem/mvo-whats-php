<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use MVO\Mcp\ClientRuntime;
use MVO\WaClient;

// stdout belongs exclusively to MCP JSON-RPC. Diagnostics go to stderr.
ini_set('display_errors','stderr');
$session=getenv('MCP_SESSION_ID') ?: 'default';
$auth=getenv('MCP_AUTH_DIRECTORY') ?: dirname(__DIR__).'/.auth';
$runtime=new ClientRuntime(static fn(string $id):WaClient=>new WaClient(sessionId:$id,authDirectory:$auth),$session);
try { $runtime->server()->run(STDIN,STDOUT,$runtime->tick(...)); }
finally { $runtime->close(); }
