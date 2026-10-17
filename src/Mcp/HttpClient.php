<?php
declare(strict_types=1);
namespace MVO\Mcp;

/** Bounded cURL Streamable HTTP client, also used in TCP integration tests. */
final class HttpClient
{
    private ?string $sessionId=null;
    private int $sequence=0;
    public function __construct(private readonly string $url,private readonly ?string $bearerToken=null,private readonly ?string $origin=null,private readonly int $maxBytes=2097152,private readonly float $timeout=30)
    {
        $parts=parse_url($url);if(!is_array($parts)||!in_array($parts['scheme'] ?? '',['http','https'],true)||!isset($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||$maxBytes<1024||$maxBytes>16777216||!is_finite($timeout)||$timeout<=0||$timeout>300)throw new \InvalidArgumentException('Invalid MCP HTTP client configuration');
        foreach([$bearerToken,$origin] as $value)if($value!==null&&preg_match('/[\x00-\x1f\x7f]/',$value))throw new \InvalidArgumentException('Invalid HTTP authentication/origin header');
    }
    public function sessionId():?string {return $this->sessionId;}
    /** @return array<string,mixed> */
    public function initialize(string $name='mvo-php',string $version='1.0.0'):array
    {
        $response=$this->call('initialize',['protocolVersion'=>'2025-06-18','capabilities'=>new \stdClass(),'clientInfo'=>['name'=>$name,'version'=>$version]]);
        if(($response['result']['protocolVersion'] ?? '')!=='2025-06-18'||$this->sessionId===null)throw new \UnexpectedValueException('MCP HTTP initialization failed');$this->notify('notifications/initialized');return $response;
    }
    /** @param array<string,mixed> $params
     * @return array<string,mixed> */
    public function call(string $method,array $params=[]):array
    {
        $result=$this->exchange('POST',json_encode(['jsonrpc'=>'2.0','id'=>++$this->sequence,'method'=>$method,'params'=>$params===[] ? new \stdClass() : $params],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $response=json_decode($result['body'],true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);if(!is_array($response)||array_is_list($response)||($response['jsonrpc'] ?? '')!=='2.0'||($response['id'] ?? null)!==$this->sequence)throw new \UnexpectedValueException('Invalid MCP HTTP JSON-RPC response');
        if($method==='initialize' && isset($response['result']['protocolVersion'])){$id=$result['headers']['mcp-session-id'] ?? null;if(!is_string($id)||!preg_match('/^[\x21-\x7e]{1,512}$/D',$id))throw new \UnexpectedValueException('Invalid MCP HTTP session header');$this->sessionId=$id;}return $response;
    }
    /** @param array<string,mixed> $params */
    public function notify(string $method,array $params=[]):void
    {
        $result=$this->exchange('POST',json_encode(['jsonrpc'=>'2.0','method'=>$method,'params'=>$params===[] ? new \stdClass() : $params],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));if($result['status']!==202||$result['body']!=='')throw new \UnexpectedValueException('MCP notification was not accepted');
    }
    public function events(?string $lastEventId=null):string
    {
        if($lastEventId!==null&&(!preg_match('/^[\x21-\x7e]{1,512}$/D',$lastEventId)))throw new \InvalidArgumentException('Invalid SSE cursor');$response=$this->exchange('GET',null,$lastEventId===null ? [] : ['Last-Event-ID: '.$lastEventId]);if(!str_starts_with(strtolower($response['headers']['content-type'] ?? ''),'text/event-stream'))throw new \UnexpectedValueException('Expected an SSE response');return $response['body'];
    }
    public function disconnect():void {$this->exchange('DELETE');$this->sessionId=null;}
    /** @param list<string> $extra
     * @return array{status:int,headers:array<string,string>,body:string} */
    private function exchange(string $method,?string $body=null,array $extra=[]):array
    {
        if($body!==null&&strlen($body)>$this->maxBytes)throw new \LengthException('MCP HTTP request exceeds byte limit');$curl=curl_init($this->url);if($curl===false)throw new \RuntimeException('Cannot initialize MCP HTTP client');$headers=['Accept: application/json, text/event-stream',...$extra];if($body!==null)$headers[]='Content-Type: application/json';if($this->sessionId!==null){$headers[]='Mcp-Session-Id: '.$this->sessionId;$headers[]='MCP-Protocol-Version: 2025-06-18';}if($this->bearerToken!==null)$headers[]='Authorization: Bearer '.$this->bearerToken;if($this->origin!==null)$headers[]='Origin: '.$this->origin;
        $received='';$responseHeaders=[];$headerBytes=0;curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_CONNECTTIMEOUT_MS=>(int)min(10000,$this->timeout*1000),CURLOPT_TIMEOUT_MS=>(int)($this->timeout*1000),CURLOPT_PROXY=>'',CURLOPT_HEADERFUNCTION=>static function(\CurlHandle $handle,string $line)use(&$responseHeaders,&$headerBytes):int{$headerBytes+=strlen($line);if($headerBytes>16384)return 0;if(preg_match('/^([^:]+):\s*(.*)\r\n$/D',$line,$m))$responseHeaders[strtolower($m[1])]=trim($m[2]);return strlen($line);},CURLOPT_WRITEFUNCTION=>function(\CurlHandle $handle,string $chunk)use(&$received):int{if(strlen($received)+strlen($chunk)>$this->maxBytes)return 0;$received.=$chunk;return strlen($chunk);}]);
        if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,$body);try{$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);if($ok===false)throw new \RuntimeException('MCP HTTP transfer failed');if($status<200||$status>=300)throw new HttpException($status);return ['status'=>$status,'headers'=>$responseHeaders,'body'=>$received];}finally{curl_close($curl);}
    }
}
