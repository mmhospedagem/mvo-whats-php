<?php
declare(strict_types=1);
namespace MVO\Mcp;

/** Native Streamable HTTP carrier. JSON-RPC and MCP lifecycle stay in StdioServer. */
final class HttpServer
{
    /** @var \Closure():StdioServer */
    private \Closure $factory;
    /** @var (\Closure():void)|null */
    private ?\Closure $tick;
    /** @var (\Closure(string):void)|null */
    private ?\Closure $cleanup;
    /** @var resource|null */
    private $listener=null;
    /** @var array<int,array{stream:resource,buffer:string,deadline:float,closing:bool,drainRemaining:int,drainBudget:int}> */
    private array $connections=[];
    /** @var array<string,array{server:StdioServer,last:float,sequence:int,events:list<array{id:string,stream:?string,json:string}>,bytes:int}> */
    private array $sessions=[];
    private bool $running=false;
    private string $url='';
    private int $boundPort=0;
    /** @param callable():StdioServer $protocolFactory A fresh protocol instance per MCP session; shared tools/runtime are allowed.
     * @param list<string> $allowedOrigins Exact origins, including non-default ports.
     * @param list<string> $allowedHosts Exact Host authorities, including the bound port.
     * @param callable():void|null $tick
     * @param callable(string):void|null $cleanup */
    public function __construct(callable $protocolFactory,private readonly string $host='127.0.0.1',private readonly int $port=8788,private readonly string $path='/mcp',private readonly ?string $bearerToken=null,private readonly array $allowedOrigins=[],private readonly array $allowedHosts=[],private readonly int $maxBytes=2097152,private readonly int $maxSessions=16,private readonly float $sessionTtl=3600,?callable $tick=null,?callable $cleanup=null)
    {
        if($port<0||$port>65535||($host!=='localhost'&&filter_var($host,FILTER_VALIDATE_IP)===false)||!preg_match('~^/[A-Za-z0-9_/-]{1,128}$~D',$path)||$maxBytes<1024||$maxBytes>16777216||$maxSessions<1||$maxSessions>128||!is_finite($sessionTtl)||$sessionTtl<1||$sessionTtl>86400)throw new \InvalidArgumentException('Invalid MCP HTTP configuration');
        if($bearerToken!==null && (!preg_match('/^[\x21-\x7e]{16,512}$/D',$bearerToken)))throw new \InvalidArgumentException('MCP bearer token requires 16..512 visible ASCII characters');
        if(!self::loopback($host)&&$bearerToken===null)throw new \InvalidArgumentException('External MCP HTTP bind requires an explicit bearer token');
        foreach($allowedOrigins as $origin)if(!is_string($origin)||!self::validOrigin($origin))throw new \InvalidArgumentException('Invalid MCP allowed origin');
        foreach($allowedHosts as $authority)if(!is_string($authority)||!preg_match('/^[a-zA-Z0-9.\[\]:_-]{1,255}$/D',$authority))throw new \InvalidArgumentException('Invalid MCP allowed Host');
        $this->factory=\Closure::fromCallable($protocolFactory);$this->tick=$tick===null ? null : \Closure::fromCallable($tick);$this->cleanup=$cleanup===null ? null : \Closure::fromCallable($cleanup);
    }
    public function listen():void
    {
        if(is_resource($this->listener))return;$bind=$this->host==='localhost' ? '127.0.0.1' : $this->host;$authority=str_contains($bind,':') ? '['.$bind.']' : $bind;
        $socket=@stream_socket_server('tcp://'.$authority.':'.$this->port,$code,$error);if($socket===false)throw new \RuntimeException('Cannot bind MCP HTTP listener');stream_set_blocking($socket,false);$address=stream_socket_get_name($socket,false);if($address===false){fclose($socket);throw new \RuntimeException('Cannot identify MCP HTTP listener');}
        $this->listener=$socket;$this->boundPort=(int)substr($address,strrpos($address,':')+1);$this->url='http://'.$authority.':'.$this->boundPort.$this->path;
    }
    public function url():string {if($this->url==='')throw new \LogicException('Call listen() first');return $this->url;}
    public function run():void {$this->listen();$this->running=true;try{while($this->running)$this->poll(0.1);}finally{$this->close();}}
    public function stop():void {$this->running=false;}
    public function close():void
    {
        $this->running=false;foreach($this->connections as $connection)fclose($connection['stream']);$this->connections=[];if(is_resource($this->listener))fclose($this->listener);$this->listener=null;
        foreach(array_keys($this->sessions) as $id)$this->drop($id);$this->url='';
    }
    /** Accepts multiple sockets without allowing an incomplete request to block the runtime poll. */
    public function poll(float $timeout=0.1):void
    {
        if(!is_finite($timeout)||$timeout<0||$timeout>1)throw new \InvalidArgumentException('Invalid MCP HTTP poll timeout');$this->listen();$this->expire();$read=[$this->listener];foreach($this->connections as $connection)if(!$connection['closing'] || $connection['drainBudget']>0)$read[]=$connection['stream'];$write=$except=[];
        $selected=stream_select($read,$write,$except,(int)$timeout,(int)(($timeout-floor($timeout))*1000000));if($selected===false)throw new \RuntimeException('MCP HTTP select failed');
        foreach($read as $stream){if($stream===$this->listener){while(($peer=@stream_socket_accept($stream,0))!==false){if(count($this->connections)>=32){fclose($peer);continue;}stream_set_blocking($peer,false);stream_set_write_buffer($peer,0);$this->connections[get_resource_id($peer)]=['stream'=>$peer,'buffer'=>'','deadline'=>microtime(true)+10,'closing'=>false,'drainRemaining'=>0,'drainBudget'=>0];}continue;}
            $id=get_resource_id($stream);if($this->connections[$id]['closing']){$chunk=fread($stream,min(8192,$this->connections[$id]['drainBudget']));if($chunk===false || ($chunk==='' && feof($stream))){$this->disconnect($id);continue;}$this->connections[$id]['drainBudget']-=strlen($chunk);$this->connections[$id]['drainRemaining']-=strlen($chunk);if($this->connections[$id]['drainRemaining']<=0)$this->disconnect($id);continue;}$chunk=fread($stream,8192);if($chunk===false||($chunk===''&&feof($stream))){$this->disconnect($id);continue;}$this->connections[$id]['buffer'].=$chunk;$buffer=$this->connections[$id]['buffer'];
            if(strlen($buffer)>$this->maxBytes+16384){$this->send($id,self::failure(413,'Request exceeds byte limit'));continue;}
            $end=strpos($buffer,"\r\n\r\n");if($end===false){if(strlen($buffer)>16384)$this->send($id,self::failure(431,'Headers exceed byte limit'));continue;}
            if($end>16384){$this->send($id,self::failure(431,'Headers exceed byte limit'));continue;}
            try{[$method,$target,$headers,$length]=$this->parseHead(substr($buffer,0,$end));}catch(\InvalidArgumentException){$this->send($id,self::failure(400,'Malformed HTTP request'));continue;}
            if($length>$this->maxBytes){$this->send($id,self::failure(413,'Request exceeds byte limit'),max(0,$length-(strlen($buffer)-$end-4)));continue;}if(strlen($buffer)<$end+4+$length)continue;
            try{$response=$this->handle($method,$target,$headers,substr($buffer,$end+4,$length));}catch(\Throwable){$response=self::failure(500,'MCP HTTP operation failed');}$this->send($id,$response);
        }
        foreach($this->connections as $id=>$connection)if($connection['deadline']<=microtime(true)){if($connection['closing'])$this->disconnect($id);else $this->send($id,self::failure(408,'Request timed out'));}
        if($this->tick!==null)($this->tick)();
    }
    /** @param array<string,string> $headers
     * @return array{status:int,headers:array<string,string>,body:string} */
    public function handle(string $method,string $target,array $headers,string $body=''):array
    {
        $this->expire();$headers=array_change_key_case($headers,CASE_LOWER);$authority=$headers['host'] ?? '';$defaultHosts=$this->defaultHosts();
        if(!in_array(strtolower($authority),array_map('strtolower',[...$defaultHosts,...$this->allowedHosts]),true))return self::failure(403,'Host not allowed');
        $origin=$headers['origin'] ?? null;$origins=[...array_map(static fn(string $h):string=>'http://'.$h,$defaultHosts),...$this->allowedOrigins];
        if($origin!==null && (!self::validOrigin($origin)||!in_array($origin,$origins,true)))return self::failure(403,'Origin not allowed');
        $cors=$origin===null ? [] : ['Access-Control-Allow-Origin'=>$origin,'Vary'=>'Origin','Access-Control-Expose-Headers'=>'Mcp-Session-Id,MCP-Protocol-Version'];
        if(explode('?',$target,2)[0]!==$this->path)return self::failure(404,'Endpoint not found',$cors);
        if($method==='OPTIONS')return ['status'=>204,'headers'=>$cors+['Allow'=>'POST,GET,DELETE,OPTIONS','Access-Control-Allow-Methods'=>'POST,GET,DELETE','Access-Control-Allow-Headers'=>'Content-Type,Accept,Authorization,Mcp-Session-Id,MCP-Protocol-Version,Last-Event-ID'],'body'=>''];
        if($this->bearerToken!==null && !hash_equals('Bearer '.$this->bearerToken,$headers['authorization'] ?? ''))return self::failure(401,'Bearer authentication required',$cors+['WWW-Authenticate'=>'Bearer']);
        if(!in_array($method,['POST','GET','DELETE'],true))return self::failure(405,'Method not allowed',$cors+['Allow'=>'POST,GET,DELETE,OPTIONS']);
        $version=$headers['mcp-protocol-version'] ?? null;if($version!==null && $version!=='2025-06-18')return self::failure(400,'Unsupported MCP protocol version',$cors);
        if(strlen($body)>$this->maxBytes)return self::failure(413,'Request exceeds byte limit',$cors);
        $id=$headers['mcp-session-id'] ?? null;if($id!==null && !preg_match('/^[a-f0-9]{48}$/D',$id))return self::failure(404,'MCP session not found',$cors);
        if($id!==null && !isset($this->sessions[$id]))return self::failure(404,'MCP session not found',$cors);
        if($method==='DELETE'){if($id===null)return self::failure(400,'Mcp-Session-Id required',$cors);$this->drop($id);return ['status'=>204,'headers'=>$cors,'body'=>''];}
        if($method==='GET'){if($id===null)return self::failure(400,'Mcp-Session-Id required',$cors);if(!self::accepts($headers['accept'] ?? '','text/event-stream'))return self::failure(406,'Accept text/event-stream required',$cors);return $this->events($id,$headers['last-event-id'] ?? null,$cors);}
        if(!self::accepts($headers['accept'] ?? '','application/json')||!self::accepts($headers['accept'] ?? '','text/event-stream'))return self::failure(406,'Accept application/json and text/event-stream required',$cors);
        if(strtolower(trim(explode(';',$headers['content-type'] ?? '',2)[0]))!=='application/json')return self::failure(415,'Content-Type application/json required',$cors);
        try{$request=json_decode($body,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException){return self::failure(400,'Invalid JSON',$cors);}
        if(!is_array($request)||array_is_list($request)||($request['jsonrpc'] ?? '')!=='2.0'||!is_string($request['method'] ?? null))return self::failure(400,'Single JSON-RPC request or notification required',$cors);
        $new=$id===null;if($new){if($request['method']!=='initialize'||!array_key_exists('id',$request))return self::failure(400,'Mcp-Session-Id required after initialization',$cors);if(count($this->sessions)>=$this->maxSessions)return self::failure(503,'MCP session limit reached',$cors);$id=bin2hex(random_bytes(24));$this->sessions[$id]=['server'=>($this->factory)(),'last'=>microtime(true),'sequence'=>0,'events'=>[],'bytes'=>0];}
        $session=&$this->sessions[$id];$session['last']=microtime(true);$response=$session['server']->handle($body);
        if($new && ($response===null || !isset($response['result']['protocolVersion']))){$this->drop($id);return self::json(400,$response ?? ['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'Invalid initialization']],$cors);}
        $extra=$cors+['Mcp-Session-Id'=>$id,'MCP-Protocol-Version'=>'2025-06-18'];return $response===null ? ['status'=>202,'headers'=>$extra,'body'=>''] : $this->boundedJson(200,$response,$extra);
    }
    /** Queue a sanitized server notification. Responses are emitted only on their originating POST.
     * @param array<string,mixed> $notification */
    public function publish(string $sessionId,array $notification):void
    {
        if(!isset($this->sessions[$sessionId]))throw new \OutOfBoundsException('MCP session not found');if(($notification['jsonrpc'] ?? '')!=='2.0'||!is_string($notification['method'] ?? null)||array_key_exists('id',$notification))throw new \InvalidArgumentException('Only JSON-RPC notifications may be queued');
        $json=json_encode(JsonValue::encode($notification),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$session=&$this->sessions[$sessionId];if(strlen($json)>min(65536,$this->maxBytes-512)||count($session['events'])>=200||$session['bytes']+strlen($json)>1048576)throw new \LengthException('MCP event queue limit');
        $session['events'][]=['id'=>bin2hex(random_bytes(8)).'.'.(++$session['sequence']),'stream'=>null,'json'=>$json];$session['bytes']+=strlen($json);
    }
    /** @param array<string,string> $headers
     * @return array{status:int,headers:array<string,string>,body:string} */
    private function events(string $id,?string $cursor,array $headers):array
    {
        $session=&$this->sessions[$id];$session['last']=microtime(true);$stream=bin2hex(random_bytes(12));$start=-1;
        if($cursor!==null){$found=false;foreach($session['events'] as $index=>$event)if(hash_equals($event['id'],$cursor)){$found=true;$stream=$event['stream'];$start=$index;break;}if(!$found||$stream===null)return self::failure(400,'Unknown SSE event cursor',$headers);}
        $body=": connected\n\n";foreach($session['events'] as $index=>&$event){if($index<=$start || ($event['stream']!==null&&$event['stream']!==$stream))continue;$wire='id: '.$event['id']."\nevent: message\ndata: ".$event['json']."\n\n";if(strlen($body)+strlen($wire)>$this->maxBytes)break;if($event['stream']===null)$event['stream']=$stream;$body.=$wire;}unset($event);
        // Delivered events remain available for bounded resumption. Keep the newest 100 records.
        while(count($session['events'])>100 && $session['events'][0]['stream']!==null){$removed=array_shift($session['events']);$session['bytes']-=strlen($removed['json']);}
        return ['status'=>200,'headers'=>$headers+['Content-Type'=>'text/event-stream','Cache-Control'=>'no-cache','Mcp-Session-Id'=>$id,'MCP-Protocol-Version'=>'2025-06-18'],'body'=>$body];
    }
    /** @return array{string,string,array<string,string>,int} */
    private function parseHead(string $head):array
    {
        $lines=explode("\r\n",$head);$first=array_shift($lines);if(!is_string($first)||!preg_match('~^([A-Z]{1,16}) (/[^ ]{0,2047}) HTTP/1\.[01]$~D',$first,$m)||count($lines)>100)throw new \InvalidArgumentException('Malformed request line');$headers=[];
        foreach($lines as $line){if(!preg_match('/^([A-Za-z0-9!#$%&\x27*+.^_`|~-]+):[ \t]*([^\x00-\x08\x0a-\x1f\x7f]*)$/D',$line,$h))throw new \InvalidArgumentException('Malformed header');$key=strtolower($h[1]);if(isset($headers[$key]))throw new \InvalidArgumentException('Duplicate header');$headers[$key]=trim($h[2]);}
        if(isset($headers['transfer-encoding']))throw new \InvalidArgumentException('Chunked requests are not supported');$length=$headers['content-length'] ?? '0';if(!preg_match('/^(0|[1-9]\d{0,8})$/D',$length))throw new \InvalidArgumentException('Invalid content length');return [$m[1],$m[2],$headers,(int)$length];
    }
    /** @param array{status:int,headers:array<string,string>,body:string} $response */
    private function send(int $id,array $response,int $unreadBodyBytes=0):void
    {
        $peer=$this->connections[$id]['stream'] ?? null;if(!is_resource($peer))return;$body=$response['body'];if(strlen($body)>$this->maxBytes)$response=self::failure(500,'Response exceeds byte limit');$body=$response['body'];$headers=$response['headers']+['Content-Type'=>'application/json','Content-Length'=>(string)strlen($body),'Connection'=>'close','X-Content-Type-Options'=>'nosniff'];$wire='HTTP/1.1 '.$response['status'].' '.self::reason($response['status'])."\r\n";foreach($headers as $key=>$value)$wire.=$key.': '.$value."\r\n";$wire.="\r\n".$body;stream_set_blocking($peer,true);stream_set_timeout($peer,5);$offset=0;
        while($offset<strlen($wire)){$written=@fwrite($peer,substr($wire,$offset,65536));if($written===false||$written===0)break;$offset+=$written;}
        // Closing TCP with unread request bytes can reset the connection and erase the HTTP
        // rejection at the client. Send FIN first, then drain a bounded tail asynchronously.
        if($unreadBodyBytes>0 && $offset===strlen($wire) && @stream_socket_shutdown($peer,STREAM_SHUT_WR)){stream_set_blocking($peer,false);$this->connections[$id]['buffer']='';$this->connections[$id]['closing']=true;$this->connections[$id]['drainRemaining']=$unreadBodyBytes;$this->connections[$id]['drainBudget']=min($unreadBodyBytes,65536);$this->connections[$id]['deadline']=microtime(true)+0.25;return;}$this->disconnect($id);
    }
    private function disconnect(int $id):void {if(isset($this->connections[$id])){fclose($this->connections[$id]['stream']);unset($this->connections[$id]);}}
    private function expire():void {foreach($this->sessions as $id=>$session)if(microtime(true)-$session['last']>$this->sessionTtl)$this->drop($id);}
    private function drop(string $id):void {unset($this->sessions[$id]);if($this->cleanup!==null)($this->cleanup)($id);}
    /** @return list<string> */
    private function defaultHosts():array {$port=$this->boundPort ?: $this->port;$hosts=self::loopback($this->host) ? ['127.0.0.1','localhost','[::1]'] : [(str_contains($this->host,':') ? '['.$this->host.']' : $this->host)];return [...array_map(static fn(string $host):string=>$host.':'.$port,$hosts),...($port===80 ? $hosts : [])];}
    private static function loopback(string $host):bool {return in_array($host,['127.0.0.1','localhost','::1'],true);}
    private static function validOrigin(string $origin):bool {return preg_match('~^https?://(?:[a-zA-Z0-9.-]+|\[[0-9a-fA-F:]+\])(?::[0-9]{1,5})?$~D',$origin)===1;}
    private static function accepts(string $header,string $type):bool {foreach(explode(',',$header) as $part){$pieces=explode(';',$part);if(strtolower(trim($pieces[0]))!==$type)continue;foreach(array_slice($pieces,1) as $param)if(preg_match('/^\s*q\s*=\s*0(?:\.0*)?\s*$/D',$param))continue 2;return true;}return false;}
    /** @param array<string,mixed> $value
     * @param array<string,string> $headers
     * @return array{status:int,headers:array<string,string>,body:string} */
    private function boundedJson(int $status,array $value,array $headers):array {$response=self::json($status,$value,$headers);return strlen($response['body'])>$this->maxBytes ? self::failure(500,'Response exceeds byte limit',$headers) : $response;}
    /** @param array<string,mixed> $value
     * @param array<string,string> $headers
     * @return array{status:int,headers:array<string,string>,body:string} */
    private static function json(int $status,array $value,array $headers=[]):array {return ['status'=>$status,'headers'=>$headers+['Content-Type'=>'application/json'],'body'=>json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)];}
    /** @param array<string,string> $headers
     * @return array{status:int,headers:array<string,string>,body:string} */
    private static function failure(int $status,string $message,array $headers=[]):array {return self::json($status,['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32000,'message'=>$message]],$headers);}
    private static function reason(int $status):string {return match($status){200=>'OK',202=>'Accepted',204=>'No Content',400=>'Bad Request',401=>'Unauthorized',403=>'Forbidden',404=>'Not Found',405=>'Method Not Allowed',406=>'Not Acceptable',408=>'Request Timeout',413=>'Content Too Large',415=>'Unsupported Media Type',431=>'Request Header Fields Too Large',503=>'Service Unavailable',default=>'Internal Server Error'};}
}
