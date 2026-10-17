<?php
declare(strict_types=1);
namespace MVO\Store;

/** Minimal binary-safe RESP2 transport. No PHP Redis extension is required. */
final class RedisConnection
{
    /** @var resource|null */
    private $socket=null;
    private string $host;
    private int $port;
    private bool $tls;
    private ?string $password;
    private ?string $username;
    private int $database;
    public function __construct(string $url='redis://127.0.0.1:6379',private readonly float $timeout=5.0,private readonly int $maxResponseBytes=33554432)
    {
        $p=parse_url($url);if($p===false || !in_array($p['scheme'] ?? '',['redis','rediss'],true) || empty($p['host']) || $timeout<=0 || !is_finite($timeout) || $maxResponseBytes<1024) throw new \InvalidArgumentException('Invalid Redis endpoint/options');
        $this->host=$p['host'];$this->port=$p['port'] ?? 6379;$this->tls=$p['scheme']==='rediss';$this->password=isset($p['pass']) ? rawurldecode($p['pass']) : null;$this->username=isset($p['user']) && $p['user']!=='' ? rawurldecode($p['user']) : null;$db=ltrim($p['path'] ?? '0','/');if($db==='') $db='0';if(!ctype_digit($db)) throw new \InvalidArgumentException('Invalid Redis database');$this->database=(int)$db;
    }
    /** @param list<string> $arguments
     * @return string|int|array<mixed>|null */
    public function command(array $arguments): string|int|array|null
    {
        if($arguments===[]) throw new \InvalidArgumentException('Empty Redis command');$this->connect();$wire='*'.count($arguments)."\r\n";foreach($arguments as $arg) $wire.='$'.strlen($arg)."\r\n".$arg."\r\n";
        try { $written=0;while($written<strlen($wire)) { $n=fwrite($this->socket,substr($wire,$written));if($n===false || $n===0) throw new \RuntimeException('Redis command write failed');$written+=$n; }$budget=$this->maxResponseBytes;return $this->read($budget); }
        catch(\Throwable $e) { $this->close();throw $e; }
    }
    private function connect(): void
    {
        if(is_resource($this->socket)) return;$context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$this->host]]);$endpoint=($this->tls ? 'tls://' : 'tcp://').(str_contains($this->host,':') ? '['.trim($this->host,'[]').']' : $this->host).':'.$this->port;
        $socket=@stream_socket_client($endpoint,$code,$error,$this->timeout,STREAM_CLIENT_CONNECT,$context);if($socket===false) throw new \RuntimeException('Redis connection failed');$this->socket=$socket;stream_set_timeout($socket,(int)$this->timeout,(int)(($this->timeout-floor($this->timeout))*1e6));
        try { if($this->password!==null) { $auth=$this->username===null ? ['AUTH',$this->password] : ['AUTH',$this->username,$this->password];if($this->command($auth)!=='OK') throw new \RuntimeException('Redis authentication failed'); }if($this->database!==0 && $this->command(['SELECT',(string)$this->database])!=='OK') throw new \RuntimeException('Redis database selection failed'); }
        catch(\Throwable $e) { $this->close();throw $e; }
    }
    /** @return string|int|array<mixed>|null */
    private function read(int &$budget,int $depth=0): string|int|array|null
    {
        if($depth>16) throw new \UnexpectedValueException('Redis response depth exceeded');$type=$this->bytes(1,$budget);$line='';while(!str_ends_with($line,"\r\n")) { if(strlen($line)>1024) throw new \UnexpectedValueException('Redis response line too long');$line.=$this->bytes(1,$budget); }$line=substr($line,0,-2);
        if($type==='+') return $line;if($type==='-') throw new \RuntimeException('Redis server rejected command');
        if(!preg_match('/^-?[0-9]+$/D',$line) || strlen($line)>18) throw new \UnexpectedValueException('Invalid Redis integer/length');$n=(int)$line;if($type===':') return $n;if($n===-1 && in_array($type,['$','*'],true)) return null;if($n<0) throw new \UnexpectedValueException('Invalid Redis length');
        if($type==='$') { $payload=$this->bytes($n,$budget);if($this->bytes(2,$budget)!=="\r\n") throw new \UnexpectedValueException('Invalid Redis bulk terminator');return $payload; }
        if($type==='*') { if($n>1000000) throw new \LengthException('Redis array too large');$out=[];for($i=0;$i<$n;$i++) $out[]=$this->read($budget,$depth+1);return $out; }throw new \UnexpectedValueException('Unknown Redis RESP prefix');
    }
    private function bytes(int $n,int &$budget): string
    {
        if($n<0 || $n>$budget) throw new \LengthException('Redis response exceeds byte limit');$budget-=$n;$out='';while(strlen($out)<$n) { $chunk=fread($this->socket,$n-strlen($out));if($chunk===false || $chunk==='') throw new \RuntimeException('Redis response interrupted or timed out');$out.=$chunk; }return $out;
    }
    public function close(): void { if(is_resource($this->socket)) fclose($this->socket);$this->socket=null; }
}
