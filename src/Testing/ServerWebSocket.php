<?php
declare(strict_types=1);
namespace MVO\Testing;

/** RFC6455 responder for the loopback-only development server. */
final class ServerWebSocket
{
    /** @var resource|null */
    private $stream;
    private string $fragment='';
    private bool $fragmented=false;
    /** @param resource $stream */
    public function __construct($stream,private readonly int $maxBytes=1048576)
    {
        if(!is_resource($stream) || $maxBytes<1 || $maxBytes>16777215) throw new \InvalidArgumentException('Invalid server socket');$this->stream=$stream;stream_set_blocking($stream,true);stream_set_timeout($stream,10);
    }
    public function upgrade(): void
    {
        $headers='';while(!str_ends_with($headers,"\r\n\r\n")) { if(strlen($headers)>32768) throw new \LengthException('WebSocket headers too large');$headers.=$this->read(1); }
        $lines=explode("\r\n",substr($headers,0,-4));if(!preg_match('/^GET \/[^ ]* HTTP\/1\.1$/D',array_shift($lines) ?? '')) throw new \UnexpectedValueException('Expected WebSocket GET');$attrs=[];
        foreach($lines as $line) { if(!str_contains($line,':')) throw new \UnexpectedValueException('Malformed WebSocket header');[$key,$value]=explode(':',$line,2);$key=strtolower(trim($key));if(isset($attrs[$key])) throw new \UnexpectedValueException('Duplicate WebSocket header');$attrs[$key]=trim($value); }
        $key=$attrs['sec-websocket-key'] ?? '';$decoded=base64_decode($key,true);if($decoded===false || strlen($decoded)!==16 || ($attrs['sec-websocket-version'] ?? '')!=='13' || strtolower($attrs['upgrade'] ?? '')!=='websocket' || !in_array('upgrade',array_map('trim',explode(',',strtolower($attrs['connection'] ?? ''))),true)) throw new \UnexpectedValueException('Invalid WebSocket upgrade');
        $accept=base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true));$this->write("HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n\r\n");
    }
    public function receive(float $timeout=10.0): ?string
    {
        if(!is_finite($timeout) || $timeout<0) throw new \InvalidArgumentException('Invalid fake WebSocket timeout');
        if($this->stream===null) return null;$r=[$this->stream];$w=$e=[];$seconds=(int)$timeout;$micros=(int)(($timeout-$seconds)*1e6);$ready=stream_select($r,$w,$e,$seconds,$micros);if($ready===0) return null;if($ready===false) throw new \RuntimeException('Fake WebSocket select failed');
        while(true) {
            $h=$this->read(2);$a=ord($h[0]);$b=ord($h[1]);$fin=($a&128)!==0;$op=$a&15;if(($a&112)!==0 || ($b&128)===0 || !in_array($op,[0,2,8,9,10],true)) throw new \UnexpectedValueException('Invalid/missing masked client frame');$length=$b&127;
            if($length===126) { $length=unpack('n',$this->read(2))[1];if($length<126) throw new \UnexpectedValueException('Nonminimal frame length'); }
            elseif($length===127) { $parts=unpack('Nhigh/Nlow',$this->read(8));if($parts['high']!==0 || $parts['low']<65536) throw new \LengthException('Invalid/oversized 64-bit frame');$length=$parts['low']; }
            if($length>$this->maxBytes || ($op>=8 && (!$fin || $length>125))) throw new \LengthException('Fake WebSocket frame exceeds limit');$mask=$this->read(4);$body=$this->read($length);for($i=0;$i<$length;$i++) $body[$i]=$body[$i]^$mask[$i%4];
            if($op===8) {
                if($length===1) throw new \UnexpectedValueException('Invalid close payload');
                if($length>0) { $code=unpack('n',substr($body,0,2))[1];if($code<1000 || $code>=5000 || in_array($code,[1004,1005,1006,1015],true) || ($code>1014 && $code<3000) || preg_match('//u',substr($body,2))!==1) throw new \UnexpectedValueException('Invalid close code/reason'); }
                // A peer may close TCP immediately after its valid CLOSE frame.
                // Only this acknowledgement is best effort; application writes stay strict.
                try { $this->write("\x88".chr($length).$body,true); }
                finally { $this->close(); }
                return null;
            }if($op===9) {$this->send($body,10);continue;}if($op===10)continue;
            if($op===2) { if($this->fragmented) throw new \UnexpectedValueException('Nested WebSocket fragments');if($fin)return $body;$this->fragmented=true;$this->fragment=$body; }
            else { if(!$this->fragmented) throw new \UnexpectedValueException('Unexpected continuation');if(strlen($this->fragment)+$length>$this->maxBytes) throw new \LengthException('Fragment limit');$this->fragment.=$body;if($fin) {$out=$this->fragment;$this->fragment='';$this->fragmented=false;return $out;} }
        }
    }
    public function send(string $payload,int $opcode=2): void
    {
        $length=strlen($payload);if($length>$this->maxBytes)throw new \LengthException('Fake WebSocket send limit');$header=chr(128|$opcode).($length<126 ? chr($length) : ($length<65536 ? chr(126).pack('n',$length) : chr(127).pack('N2',0,$length)));$this->write($header.$payload);
    }
    private function read(int $n): string { $out='';while(strlen($out)<$n) { if($this->stream===null)throw new \RuntimeException('Fake WebSocket closed');$v=fread($this->stream,$n-strlen($out));if($v===false || $v==='')throw new \RuntimeException('Fake WebSocket interrupted');$out.=$v; }return $out; }
    private function write(string $bytes,bool $closing=false): void { $pos=0;while($pos<strlen($bytes)) { if($this->stream===null)throw new \RuntimeException('Fake WebSocket closed');$n=$closing ? @fwrite($this->stream,substr($bytes,$pos)) : fwrite($this->stream,substr($bytes,$pos));if($n===false || $n===0){if($closing)return;throw new \RuntimeException('Fake WebSocket write failed');}$pos+=$n; } }
    public function isClosed(): bool { return $this->stream===null; }
    public function close(): void { if(is_resource($this->stream))fclose($this->stream);$this->stream=null; }
}
