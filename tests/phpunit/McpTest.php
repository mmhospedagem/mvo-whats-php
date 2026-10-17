<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Mcp\{ToolRegistry,StdioServer,ClientRuntime,JsonValue};
use MVO\WaClient;
use MVO\Protocol\BinaryNode;

final class McpTest extends TestCase
{
    private function server(?ToolRegistry $tools=null): StdioServer
    {
        $tools ??=new ToolRegistry();return new StdioServer($tools,static fn():array=>['mvo://test'=>['uri'=>'mvo://test','name'=>'Test','mimeType'=>'application/json']],static fn(string $u):string=>'{}');
    }
    private function ready(StdioServer $s): void
    {
        $r=$s->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1"}}}');self::assertSame('2025-06-18',$r['result']['protocolVersion']);self::assertNull($s->handle('{"jsonrpc":"2.0","method":"notifications/initialized"}'));
    }
    public function testLifecycleAndToolSchemaValidateBeforeHandler(): void
    {
        $calls=0;$tools=new ToolRegistry();$tools->add('sum','Sum integers',['type'=>'object','properties'=>['a'=>['type'=>'integer'],'b'=>['type'=>'integer']],'required'=>['a','b'],'additionalProperties'=>false],static function(array $a)use(&$calls):array{$calls++;return ['sum'=>$a['a']+$a['b']];},true);$s=$this->server($tools);self::assertSame(-32002,$s->handle('{"jsonrpc":"2.0","id":2,"method":"tools/list"}')['error']['code']);$this->ready($s);$r=$s->handle('{"jsonrpc":"2.0","id":2,"method":"tools/list"}');self::assertSame('sum',$r['result']['tools'][0]['name']);
        $r=$s->handle('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"sum","arguments":{"a":2,"b":3}}}');self::assertFalse($r['result']['isError']);self::assertSame('{"sum":5}',$r['result']['content'][0]['text']);$r=$s->handle('{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"sum","arguments":{"a":"2","b":3}}}');self::assertTrue($r['result']['isError']);self::assertSame(1,$calls);
        self::assertNull($s->handle('{"jsonrpc":"2.0","method":"tools/call","params":{"name":"sum","arguments":{"a":2,"b":3}}}'));self::assertSame(1,$calls);
    }
    public function testResourcesAreAllowlistedAndUnknownMethodsHaveRpcErrors(): void
    {
        $s=$this->server();$this->ready($s);$r=$s->handle('{"jsonrpc":"2.0","id":"R","method":"resources/list"}');self::assertSame('mvo://test',$r['result']['resources'][0]['uri']);$r=$s->handle('{"jsonrpc":"2.0","id":"R","method":"resources/read","params":{"uri":"mvo://test"}}');self::assertSame('{}',$r['result']['contents'][0]['text']);self::assertSame(-32602,$s->handle('{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"file:///secret"}}')['error']['code']);self::assertSame(-32601,$s->handle('{"jsonrpc":"2.0","id":1,"method":"exec"}')['error']['code']);
    }
    public static function malformed(): iterable {yield ['invalid',-32700];yield ['[]',-32600];yield ['{"jsonrpc":"1.0","id":1,"method":"ping"}',-32600];yield ['{"jsonrpc":"2.0","id":{},"method":"ping"}',-32600];yield ['{"jsonrpc":"2.0","id":1,"method":"initialize","params":[]}',-32602];}
    #[DataProvider('malformed')]
    public function testMalformedJsonRpcFails(string $input,int $code): void {self::assertSame($code,$this->server()->handle($input)['error']['code']);}
    public function testBinarySerializationRedactsSecretsAndPreservesBytes(): void
    {
        $v=JsonValue::encode(['secret'=>str_repeat('a',32),'nested'=>['messageSecret'=>'secret'],'data'=>"\xff\0",'node'=>new BinaryNode('x',[],"\0bytes")]);self::assertSame('[redacted]',$v['secret']);self::assertSame('[redacted]',$v['nested']['messageSecret']);self::assertSame("\xff\0",JsonValue::decode($v['data']));self::assertSame(['$base64'=>base64_encode("\0bytes")],$v['node']['content']);
    }
    public function testUsernameAccessPinIsRedactedInMcpToolResult(): void
    {
        $tools=new ToolRegistry();$tools->add('profile_get_own_username','Username fixture',['type'=>'object'],static fn(array $input):array=>['username'=>'test.user','state'=>'CONFIRMED','pin'=>'1234'],true);
        $server=$this->server($tools);$this->ready($server);
        $response=$server->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"profile_get_own_username","arguments":{}}}');
        self::assertFalse($response['result']['isError']);
        self::assertSame(['username'=>'test.user','state'=>'CONFIRMED','pin'=>'[redacted]'],json_decode($response['result']['content'][0]['text'],true,512,JSON_THROW_ON_ERROR));
        self::assertSame(['username_info'=>['PIN'=>'[redacted]']],JsonValue::encode(['username_info'=>['PIN'=>'1234']]));
    }
    public function testMessageAndHistorySerializationRedactsCryptographicPayloads(): void
    {
        $record=['id'=>'message-id','proto'=>['conversation'=>'Mensagem normal',
            'senderKeyDistributionMessage'=>['groupId'=>'123@g.us','axolotlSenderKeyDistributionMessage'=>"\xffsecret sender chain"],
            'extendedTextMessage'=>['text'=>'Texto citado','contextInfo'=>['ephemeralSharedSecret'=>"\0ephemeral secret"]],
            'call'=>['callKey'=>str_repeat('k',32)]],
            'publicKey'=>'public signing key'];
        $safe=JsonValue::encode(['history'=>[$record]]);
        self::assertSame('message-id',$safe['history'][0]['id']);
        self::assertSame('Mensagem normal',$safe['history'][0]['proto']['conversation']);
        self::assertSame('123@g.us',$safe['history'][0]['proto']['senderKeyDistributionMessage']['groupId']);
        self::assertSame('[redacted]',$safe['history'][0]['proto']['senderKeyDistributionMessage']['axolotlSenderKeyDistributionMessage']);
        self::assertSame('[redacted]',$safe['history'][0]['proto']['extendedTextMessage']['contextInfo']['ephemeralSharedSecret']);
        self::assertSame('[redacted]',$safe['history'][0]['proto']['call']['callKey']);
        self::assertSame('Texto citado',$safe['history'][0]['proto']['extendedTextMessage']['text']);
        self::assertSame('public signing key',$safe['history'][0]['publicKey']);
    }
    public function testHistoryToolDoesNotExposeAppStateKeyShare(): void
    {
        $protocol=['type'=>6,'appStateSyncKeyShare'=>['keys'=>[
            ['keyId'=>['keyId'=>'key-id'],'keyData'=>['keyData'=>str_repeat('a',32),'timestamp'=>123]]]]];
        $record=['id'=>'keyshare-id','proto'=>['protocolMessage'=>$protocol],
            'data'=>$protocol,'webMessageInfo'=>['message'=>['protocolMessage'=>$protocol]]];
        $tools=new ToolRegistry();$tools->add('history_list_messages','History fixture',['type'=>'object'],static fn(array $input):array=>[$record],true);
        $server=$this->server($tools);$this->ready($server);
        $response=$server->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"history_list_messages","arguments":{}}}');
        self::assertFalse($response['result']['isError']);
        $safe=json_decode($response['result']['content'][0]['text'],true,512,JSON_THROW_ON_ERROR)[0];
        self::assertSame('keyshare-id',$safe['id']);
        self::assertSame(6,$safe['proto']['protocolMessage']['type']);
        self::assertSame('[redacted]',$safe['proto']['protocolMessage']['appStateSyncKeyShare']);
        self::assertSame('[redacted]',$safe['data']['appStateSyncKeyShare']);
        self::assertSame('[redacted]',$safe['webMessageInfo']['message']['protocolMessage']['appStateSyncKeyShare']);
    }
    public function testHistoryPrivacyTokensAndSaltAreRedacted():void
    {
        $safe=JsonValue::encode(['chat'=>['jid'=>'12@lid','tcToken'=>"\xfftoken",'tcTokenTimestamp'=>123,'nctSalt'=>str_repeat('s',32)],'tctoken'=>'receiver bytes','cstoken'=>'hash bytes']);
        self::assertSame('12@lid',$safe['chat']['jid']);self::assertSame(123,$safe['chat']['tcTokenTimestamp']);
        foreach(['tcToken','nctSalt'] as $field)self::assertSame('[redacted]',$safe['chat'][$field]);
        self::assertSame('[redacted]',$safe['tctoken']);self::assertSame('[redacted]',$safe['cstoken']);
        self::assertSame('[redacted]',JsonValue::encode(new BinaryNode('tctoken',[],"\xffreceiver"))['content']);
        self::assertSame('[redacted]',JsonValue::encode(new BinaryNode('cstoken',[],'hash bytes'))['content']);
    }
    public function testRuntimeReusesSessionAndEnforcesMaximumWithoutConnecting(): void
    {
        $made=[];$runtime=new ClientRuntime(static function(string $id)use(&$made):WaClient{$made[]=$id;return new WaClient($id,sys_get_temp_dir().'/mvo-mcp-test');},maxSessions:1);$s=$runtime->server();$this->ready($s);$wire='{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"client_get_state","arguments":{}}}';self::assertSame('"disconnected"',$s->handle($wire)['result']['content'][0]['text']);self::assertFalse($s->handle($wire)['result']['isError']);self::assertSame(['default'],$made);$r=$s->handle('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"client_get_state","arguments":{"session":"other"}}}');self::assertTrue($r['result']['isError']);self::assertCount(1,$made);$defs=$runtime->tools->definitions();self::assertLessThanOrEqual(256,count($defs));self::assertContains('client_send_message',array_column($defs,'name'));self::assertNotContains('client_query',array_column($defs,'name'));self::assertNotContains('client_logout',array_column($defs,'name'));$runtime->close();
    }
    public function testSubprocessStdioUsesOnlyJsonAndWorksOnWindowsPipes(): void
    {
        $ini=php_ini_loaded_file();
        $process=proc_open([PHP_BINARY,...($ini===false ? [] : ['-c',$ini]),dirname(__DIR__,2).'/examples/mcp.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,array_merge(getenv(),['XDEBUG_MODE'=>'off']),['bypass_shell'=>true,'create_new_console'=>false]);self::assertIsResource($process);
        fwrite($pipes[0],'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1"}}}'."\n".'{"jsonrpc":"2.0","method":"notifications/initialized"}'."\n".'{"jsonrpc":"2.0","id":2,"method":"tools/list"}'."\n");fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);self::assertSame(0,$code,$stderr);$lines=array_values(array_filter(explode("\n",$stdout),static fn(string $l):bool=>$l!==''));self::assertCount(2,$lines,$stdout);$a=json_decode($lines[0],true,512,JSON_THROW_ON_ERROR);$b=json_decode($lines[1],true,512,JSON_THROW_ON_ERROR);self::assertSame('2025-06-18',$a['result']['protocolVersion']);self::assertGreaterThan(80,count($b['result']['tools']));
    }
}
