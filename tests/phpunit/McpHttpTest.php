<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Mcp\{HttpClient,HttpException,HttpServer,StdioServer,ToolRegistry};

final class McpHttpTest extends TestCase
{
    private const TOKEN='test-bearer-token-32-characters-long';
    /** @var resource */
    private $process;
    /** @var array<int,resource> */
    private array $pipes=[];
    private string $url;
    protected function setUp():void
    {
        $ini=php_ini_loaded_file();
        $process=proc_open([PHP_BINARY,...($ini===false?[]:['-c',$ini]),__DIR__.'/McpHttpProcess.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,array_merge(getenv(),['XDEBUG_MODE'=>'off']),['bypass_shell'=>true,'create_new_console'=>false]);self::assertIsResource($process);$this->process=$process;$this->pipes=$pipes;stream_set_timeout($pipes[1],5);$line=fgets($pipes[1]);self::assertIsString($line);$this->url=json_decode($line,true,32,JSON_THROW_ON_ERROR)['url'];
    }
    protected function tearDown():void
    {
        try{$client=new HttpClient($this->url,self::TOKEN,timeout:2);$client->initialize();$client->call('tools/call',['name'=>'stop']);}catch(\Throwable){}
        foreach($this->pipes as $pipe)if(is_resource($pipe))fclose($pipe);$status=proc_get_status($this->process);if($status['running'])proc_terminate($this->process);proc_close($this->process);
    }
    private function client():HttpClient {return new HttpClient($this->url,self::TOKEN,maxBytes:16384,timeout:5);}
    /** @param array<string,mixed> $arguments
     * @return array<string,mixed> */
    private static function tool(HttpClient $client,string $name,array $arguments=[]):array {$response=$client->call('tools/call',['name'=>$name,'arguments'=>$arguments]);self::assertFalse($response['result']['isError']);return json_decode($response['result']['content'][0]['text'],true,32,JSON_THROW_ON_ERROR);}
    /** @param list<string> $headers
     * @return array{status:int,body:string} */
    private function raw(string $method,string $body='',array $headers=[]):array
    {
        $curl=curl_init($this->url);curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.self::TOKEN,...$headers],CURLOPT_RETURNTRANSFER=>true,CURLOPT_PROXY=>'',CURLOPT_TIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false]);if($body!=='')curl_setopt($curl,CURLOPT_POSTFIELDS,$body);$data=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);unset($curl);return ['status'=>$status,'body'=>is_string($data) ? $data : ''];
    }
    public function testActualTcpHandshakeSharedRuntimeSseReplayAndDelete():void
    {
        $first=$this->client();$second=$this->client();self::assertSame('2025-06-18',$first->initialize()['result']['protocolVersion']);$second->initialize();self::assertNotSame($first->sessionId(),$second->sessionId());self::assertSame(['value'=>'Olá'],self::tool($first,'echo',['value'=>'Olá']));self::assertSame(['value'=>1],self::tool($first,'counter'));self::assertSame(['value'=>2],self::tool($second,'counter'));self::assertCount(5,$first->call('tools/list')['result']['tools']);
        self::tool($first,'publish',['session'=>$first->sessionId(),'text'=>'first']);self::tool($first,'publish',['session'=>$first->sessionId(),'text'=>'second']);$stream=$first->events();self::assertStringContainsString('"text":"first"',$stream);self::assertStringContainsString('"text":"second"',$stream);self::assertStringContainsString('"privateKey":"[redacted]"',$stream);self::assertStringNotContainsString('fixture secret',$stream);preg_match_all('/^id: (.+)$/m',$stream,$ids);self::assertCount(2,$ids[1]);self::assertSame(": connected\n\n",$first->events());
        self::tool($first,'publish',['session'=>$first->sessionId(),'text'=>'other stream']);self::assertStringContainsString('other stream',$first->events());$resumed=$first->events($ids[1][0]);self::assertStringNotContainsString('"text":"first"',$resumed);self::assertStringContainsString('"text":"second"',$resumed);self::assertStringNotContainsString('other stream',$resumed);
        $old=$first->sessionId();$first->disconnect();self::assertNull($first->sessionId());self::assertSame(['value'=>1],self::tool($second,'cleanup'));self::assertSame(404,$this->raw('GET',headers:['Accept: text/event-stream','Mcp-Session-Id: '.$old])['status']);
    }
    public function testAuthOriginHostVersionAndSessionRejectionsOverTcp():void
    {
        foreach([[new HttpClient($this->url,'wrong-bearer-token-long'),401],[new HttpClient($this->url,self::TOKEN,'https://attacker.invalid'),403]] as [$client,$expected]){try{$client->initialize();self::fail('Unexpected HTTP access');}catch(HttpException $e){self::assertSame($expected,$e->status);}}
        $client=$this->client();$client->initialize();$session=$client->sessionId();self::assertSame(403,$this->raw('GET',headers:['Host: attacker.invalid','Accept: text/event-stream','Mcp-Session-Id: '.$session])['status']);self::assertSame(400,$this->raw('GET',headers:['Accept: text/event-stream','Mcp-Session-Id: '.$session,'MCP-Protocol-Version: 1900-01-01'])['status']);self::assertSame(400,$this->raw('GET',headers:['Accept: text/event-stream'])['status']);self::assertSame(406,$this->raw('GET',headers:['Accept: application/json','Mcp-Session-Id: '.$session])['status']);self::assertSame(404,$this->raw('GET',headers:['Accept: text/event-stream','Mcp-Session-Id: '.str_repeat('a',48)])['status']);
    }
    public function testContentAndByteLimitsAreEnforcedByRealServerAndClient():void
    {
        self::assertSame(406,$this->raw('POST','{}',['Content-Type: application/json','Accept: application/json'])['status']);self::assertSame(415,$this->raw('POST','{}',['Content-Type: text/plain','Accept: application/json,text/event-stream'])['status']);self::assertSame(400,$this->raw('POST','[{}]',['Content-Type: application/json','Accept: application/json,text/event-stream'])['status']);self::assertSame(413,$this->raw('POST',str_repeat('x',9000),['Content-Type: application/json','Accept: application/json,text/event-stream'])['status']);
        $client=$this->client();$client->initialize();$small=new HttpClient($this->url,self::TOKEN,maxBytes:1024);$small->initialize();try{$small->call('tools/list');self::fail('Oversize response accepted');}catch(\RuntimeException $e){self::assertStringContainsString('transfer failed',$e->getMessage());}self::assertSame(404,$this->raw('GET',headers:['Accept: text/event-stream','Mcp-Session-Id: invalid'])['status']);
    }
    public function testIncompleteSocketDoesNotBlockOtherHttpConnections():void
    {
        $parts=parse_url($this->url);$slow=stream_socket_client('tcp://'.$parts['host'].':'.$parts['port'],$code,$error,5);self::assertIsResource($slow);fwrite($slow,"POST /mcp HTTP/1.1\r\nHost: 127.0.0.1:{$parts['port']}\r\nContent-Length: 100\r\n");try{$client=$this->client();$client->initialize();self::assertSame(['value'=>'concurrent'],self::tool($client,'echo',['value'=>'concurrent']));}finally{fclose($slow);}
    }
    public function testOversizeUploadResponsesSurviveUnreadTcpTails():void
    {
        foreach([8193,9000,16385,250000] as $length)for($attempt=0;$attempt<5;$attempt++)self::assertSame(413,$this->raw('POST',str_repeat('x',$length),['Content-Type: application/json','Accept: application/json,text/event-stream'])['status'],'Unread upload tail lost the HTTP rejection');
        $parts=parse_url($this->url);$slow=stream_socket_client('tcp://'.$parts['host'].':'.$parts['port'],$code,$error,5);self::assertIsResource($slow);stream_set_timeout($slow,2);fwrite($slow,"POST /mcp HTTP/1.1\r\nHost: 127.0.0.1:{$parts['port']}\r\nContent-Length: 99999999\r\n\r\n");try{self::assertStringStartsWith('HTTP/1.1 413 ',fgets($slow));$client=$this->client();$client->initialize();self::assertSame(['value'=>'drain is nonblocking'],self::tool($client,'echo',['value'=>'drain is nonblocking']));}finally{fclose($slow);}
    }
    public function testHttpSessionCapacityExpirationCleanupAndInvalidInitialize():void
    {
        $cleaned=[];$server=new HttpServer(static fn():StdioServer=>new StdioServer(new ToolRegistry()),port:0,maxSessions:1,sessionTtl:1,cleanup:static function(string $id)use(&$cleaned):void{$cleaned[]=$id;});$server->listen();$authority=parse_url($server->url(),PHP_URL_HOST).':'.parse_url($server->url(),PHP_URL_PORT);$headers=['host'=>$authority,'accept'=>'application/json,text/event-stream','content-type'=>'application/json'];
        try{self::assertSame(400,$server->handle('POST','/mcp',$headers,'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}')['status']);$body='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1"}}}';$response=$server->handle('POST','/mcp',$headers,$body);self::assertSame(200,$response['status']);self::assertSame(503,$server->handle('POST','/mcp',$headers,$body)['status']);usleep(1100000);self::assertSame(404,$server->handle('GET','/mcp',$headers+['mcp-session-id'=>$response['headers']['Mcp-Session-Id']])['status']);self::assertCount(2,$cleaned);self::assertSame(200,$server->handle('POST','/mcp',$headers,$body)['status']);}finally{$server->close();}
    }
    public function testExternalBindingRequiresAuthAndSseQueueIsBounded():void
    {
        try{new HttpServer(static fn():StdioServer=>new StdioServer(new ToolRegistry()),host:'0.0.0.0');self::fail('External bind without authentication accepted');}catch(\InvalidArgumentException $e){self::assertStringContainsString('bearer',$e->getMessage());}
        $client=$this->client();$client->initialize();try{$client->events('unknown-event-cursor');self::fail('Unknown cursor accepted');}catch(HttpException $e){self::assertSame(400,$e->status);}
        $server=new HttpServer(static fn():StdioServer=>new StdioServer(new ToolRegistry()),port:0);$server->listen();$authority=parse_url($server->url(),PHP_URL_HOST).':'.parse_url($server->url(),PHP_URL_PORT);try{$result=$server->handle('POST','/mcp',['host'=>$authority,'accept'=>'application/json,text/event-stream','content-type'=>'application/json'],'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{}}}');$id=$result['headers']['Mcp-Session-Id'];for($n=0;$n<200;$n++)$server->publish($id,['jsonrpc'=>'2.0','method'=>'notifications/message','params'=>['n'=>$n]]);$this->expectException(\LengthException::class);$server->publish($id,['jsonrpc'=>'2.0','method'=>'notifications/message']);}finally{$server->close();}
    }
}
