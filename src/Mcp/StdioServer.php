<?php
declare(strict_types=1);
namespace MVO\Mcp;

/** MCP JSON-RPC stdio transport, newline-delimited UTF-8, with bounded input/output. */
final class StdioServer
{
    private bool $initialized=false;
    private bool $ready=false;
    /** @var \Closure():array<string,array<string,mixed>> */
    private \Closure $resources;
    /** @var \Closure(string):string */
    private \Closure $readResource;
    /** @param callable():array<string,array<string,mixed>>|null $resources
     * @param callable(string):string|null $readResource */
    public function __construct(private readonly ToolRegistry $tools,?callable $resources=null,?callable $readResource=null,private readonly int $maxBytes=2097152)
    {
        if($maxBytes<1024 || $maxBytes>16777216) throw new \InvalidArgumentException('Invalid MCP frame limit');$this->resources=$resources===null ? static fn():array=>[] : \Closure::fromCallable($resources);$this->readResource=$readResource===null ? static fn(string $uri):string=>throw new \InvalidArgumentException('Resource unavailable') : \Closure::fromCallable($readResource);
    }
    /** @return array<string,mixed>|null */
    public function handle(string $line): ?array
    {
        if(strlen($line)>$this->maxBytes) return self::error(null,-32600,'MCP frame exceeds byte limit');
        try { $request=json_decode($line,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING); }
        catch(\JsonException) { return self::error(null,-32700,'Invalid JSON'); }
        if(!is_array($request) || array_is_list($request) || ($request['jsonrpc'] ?? '')!=='2.0' || !is_string($request['method'] ?? null) || (isset($request['id']) && !is_string($request['id']) && !is_int($request['id']))) return self::error(null,-32600,'Invalid JSON-RPC request');
        $id=$request['id'] ?? null;$notification=!array_key_exists('id',$request);$method=$request['method'];$params=$request['params'] ?? [];
        if(!is_array($params) || ($params!==[] && array_is_list($params))) return $notification ? null : self::error($id,-32602,'Object params required');
        if($notification) { if($method==='notifications/initialized' && $this->initialized) $this->ready=true;return null; }
        try {
            if($method==='initialize') {
                if($this->initialized) return self::error($id,-32600,'MCP session is already initialized');
                if(!is_string($params['protocolVersion'] ?? null) || !is_array($params['capabilities'] ?? null) || !is_array($params['clientInfo'] ?? null)) return self::error($id,-32602,'Invalid initialize parameters');
                $this->initialized=true;return self::result($id,['protocolVersion'=>'2025-06-18','serverInfo'=>['name'=>'mvo-php','version'=>'1.0.0'],'capabilities'=>['tools'=>['listChanged'=>false],'resources'=>['subscribe'=>false,'listChanged'=>false]],'instructions'=>'MVO native PHP WhatsApp library. Tools operate only on explicitly configured sessions.']);
            }
            if($method==='ping') return self::result($id,new \stdClass());
            if(!$this->ready) return self::error($id,-32002,'Initialize and send notifications/initialized first');
            if($method==='tools/list') return self::result($id,['tools'=>$this->tools->definitions()]);
            if($method==='tools/call') {
                if(!is_string($params['name'] ?? null) || !is_array($params['arguments'] ?? [])) return self::error($id,-32602,'Invalid tool parameters');
                try { $output=$this->tools->invoke($params['name'],$params['arguments'] ?? []);$json=json_encode(JsonValue::encode($output),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(strlen($json)>$this->maxBytes-1024) throw new \LengthException('MCP result exceeds byte limit');return self::result($id,['content'=>[['type'=>'text','text'=>$json]],'isError'=>false]); }
                catch(\Throwable $e) { return self::result($id,['content'=>[['type'=>'text','text'=>$e instanceof \InvalidArgumentException || $e instanceof \LogicException || $e instanceof \LengthException ? $e->getMessage() : 'Library operation failed: '.get_class($e)]],'isError'=>true]); }
            }
            if($method==='resources/list') return self::result($id,['resources'=>array_values(($this->resources)())]);
            if($method==='resources/read') { if(!is_string($params['uri'] ?? null)) return self::error($id,-32602,'Resource URI required');$resources=($this->resources)();$definition=$resources[$params['uri']] ?? throw new \InvalidArgumentException('Unknown resource');$text=($this->readResource)($params['uri']);if(strlen($text)>$this->maxBytes-1024) throw new \LengthException('Resource exceeds byte limit');return self::result($id,['contents'=>[['uri'=>$params['uri'],'mimeType'=>$definition['mimeType'] ?? 'application/json','text'=>$text]]]); }
            return self::error($id,-32601,'Unknown method');
        } catch(\InvalidArgumentException $e) { return self::error($id,-32602,$e->getMessage()); }
        catch(\Throwable) { return self::error($id,-32603,'Internal operation failed'); }
    }
    /** @param resource $input
     * @param resource $output
     * @param callable():void|null $tick */
    public function run($input,$output,?callable $tick=null): void
    {
        if(!is_resource($input) || !is_resource($output)) throw new \InvalidArgumentException('MCP streams required');$buffer='';$discard=false;
        while(!feof($input)) {
            $read=[$input];$write=$except=[];$available=stream_select($read,$write,$except,0,100000);
            if($available===false) throw new \RuntimeException('MCP input select failed');
            if($available>0) {
                $chunk=fread($input,8192);if($chunk===false) throw new \RuntimeException('MCP input read failed');$buffer.=$chunk;
                while(($end=strpos($buffer,"\n"))!==false) { $line=substr($buffer,0,$end);$buffer=substr($buffer,$end+1);$response=$discard ? self::error(null,-32600,'MCP frame exceeds byte limit') : $this->handle($line);$discard=false;if($response!==null) $this->write($output,$response); }
                if(strlen($buffer)>$this->maxBytes) { $buffer='';$discard=true; }
            }
            if($tick!==null) $tick();
        }
        if($buffer!=='' || $discard) $this->write($output,self::error(null,-32700,'Incomplete MCP frame'));
    }
    /** @param resource $output
     * @param array<string,mixed> $message */
    private function write($output,array $message): void { $wire=json_encode($message,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";if(strlen($wire)>$this->maxBytes) $wire=json_encode(self::error($message['id'] ?? null,-32603,'MCP output exceeds byte limit'),JSON_THROW_ON_ERROR)."\n";$offset=0;while($offset<strlen($wire)) { $n=fwrite($output,substr($wire,$offset));if($n===false || $n===0) throw new \RuntimeException('MCP output interrupted');$offset+=$n; }fflush($output); }
    /** @return array<string,mixed> */
    private static function result(int|string|null $id,mixed $value): array { return ['jsonrpc'=>'2.0','id'=>$id,'result'=>$value]; }
    /** @return array<string,mixed> */
    private static function error(int|string|null $id,int $code,string $message): array { return ['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$message]]; }
}
