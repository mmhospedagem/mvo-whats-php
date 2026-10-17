<?php
declare(strict_types=1);
namespace MVO\Mobile;

use MVO\Transport\WebSocket;

/** Raw mobile TCP adapter. NoiseSession supplies authenticated end-to-end transport encryption. */
final class TcpSocket extends WebSocket
{
    /** @var resource|null */
    private $tcp=null;
    private string $buffer='';
    public function __construct(private readonly string $tcpUrl='tcp://g.whatsapp.net:443',private readonly float $tcpTimeout=20.0,private readonly ?string $httpProxy=null)
    {
        if($tcpTimeout<=0 || !is_finite($tcpTimeout))throw new \InvalidArgumentException('Invalid mobile TCP timeout');
        self::endpoint($tcpUrl);
    }
    public function connect():void
    {
        if($this->isConnected())return;
        [$host,$port]=self::endpoint($this->tcpUrl);$proxy=$this->httpProxy===null?null:parse_url($this->httpProxy);
        if($this->httpProxy!==null && ($proxy===false || !isset($proxy['host']) || ($proxy['scheme']??'')!=='http' || preg_match('/[\r\n]/',$this->httpProxy)))throw new \InvalidArgumentException('Mobile TCP proxy must be an HTTP CONNECT URL');
        $target=$proxy===null?'tcp://'.$host.':'.$port:'tcp://'.$proxy['host'].':'.($proxy['port']??8080);
        $socket=@stream_socket_client($target,$errno,$error,$this->tcpTimeout,STREAM_CLIENT_CONNECT);
        if($socket===false)throw new \RuntimeException('Mobile TCP connection failed: '.$error,$errno);
        $this->tcp=$socket;stream_set_blocking($socket,false);$this->buffer='';
        try {
            if($proxy!==null) {
                $authority=$host.':'.$port;$header="CONNECT ".$authority." HTTP/1.1\r\nHost: ".$authority."\r\nProxy-Connection: Keep-Alive\r\n";
                if(isset($proxy['user']))$header.='Proxy-Authorization: Basic '.base64_encode(rawurldecode($proxy['user']).':'.rawurldecode($proxy['pass']??''))."\r\n";
                $this->send($header."\r\n");$deadline=microtime(true)+$this->tcpTimeout;$response='';
                do {
                    $chunk=$this->receive(max(0.0,$deadline-microtime(true)));
                    if($chunk===null)throw new \RuntimeException('Mobile proxy CONNECT timed out');
                    $response.=$chunk;$end=strpos($response,"\r\n\r\n");
                    if(($end===false?strlen($response):$end)>65536)throw new \LengthException('Mobile proxy headers exceed 64 KiB');
                }while($end===false);
                $status=strtok($response,"\r\n");
                if(!is_string($status) || !preg_match('~^HTTP/1\.[01] 2[0-9][0-9](?:\s|$)~',$status))throw new \RuntimeException('Mobile proxy CONNECT was refused');
                $this->buffer=substr($response,$end+4);
            }
        }catch(\Throwable $error){$this->close();throw $error;}
    }
    public function isConnected():bool { return is_resource($this->tcp) && !feof($this->tcp); }
    public function send(string $payload):void
    {
        if(!$this->isConnected())throw new \LogicException('Mobile TCP socket is not connected');
        if(strlen($payload)>33554432)throw new \LengthException('Mobile TCP write exceeds 32 MiB');
        $deadline=microtime(true)+$this->tcpTimeout;$offset=0;
        while($offset<strlen($payload)) {
            if(!$this->ready(true,max(0.0,$deadline-microtime(true))))throw new \RuntimeException('Mobile TCP write timed out');
            $written=@fwrite($this->tcp,substr($payload,$offset));
            if($written===false || $written===0){$this->close();throw new \RuntimeException('Mobile TCP write failed');}
            $offset+=$written;
        }
    }
    public function receive(float $timeoutSeconds=20.0):?string
    {
        if($timeoutSeconds<0 || !is_finite($timeoutSeconds))throw new \InvalidArgumentException('Invalid mobile TCP receive timeout');
        if($this->buffer!==''){$bytes=$this->buffer;$this->buffer='';return $bytes;}
        if(!$this->isConnected())throw new \RuntimeException('Mobile TCP socket closed');
        if(!$this->ready(false,$timeoutSeconds))return null;
        $bytes=@fread($this->tcp,65536);
        if($bytes===false || ($bytes==='' && feof($this->tcp))){$this->close();throw new \RuntimeException('Mobile TCP read failed or connection closed');}
        return $bytes===''?null:$bytes;
    }
    public function close():void { if(is_resource($this->tcp))fclose($this->tcp);$this->tcp=null;$this->buffer=''; }
    private function ready(bool $write,float $timeout):bool
    {
        if(!is_resource($this->tcp))throw new \LogicException('Mobile TCP socket closed');
        $read=$write?[]:[$this->tcp];$writes=$write?[$this->tcp]:[];$except=[];$seconds=(int)floor($timeout);$micros=(int)(($timeout-$seconds)*1000000);
        $selected=@stream_select($read,$writes,$except,$seconds,$micros);
        if($selected===false)throw new \RuntimeException('Mobile TCP select failed');return $selected>0;
    }
    /** @return array{string,int} */
    private static function endpoint(string $url):array
    {
        $parts=parse_url($url);
        if($parts===false || ($parts['scheme']??'')!=='tcp' || !is_string($parts['host']??null) || $parts['host']==='' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\r\n]/',$url) || !in_array($parts['path']??'',['','/'],true))throw new \InvalidArgumentException('Expected a tcp://host:port mobile endpoint');
        $port=$parts['port']??443;if($port<1 || $port>65535)throw new \InvalidArgumentException('Invalid mobile TCP port');return [$parts['host'],$port];
    }
}
