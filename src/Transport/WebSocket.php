<?php
declare(strict_types=1);

namespace MVO\Transport;

/** Native RFC 6455 binary client. TLS peer verification is always enabled. */
class WebSocket
{
    /** @var resource|null */
    private $stream = null;
    private string $buffer = '';
    private string $fragment = '';
    private ?int $fragmentOpcode = null;
    private bool $connected = false;

    public function __construct(
        private readonly string $url = 'wss://web.whatsapp.com/ws/chat',
        private readonly float $timeoutSeconds = 20.0,
        private readonly int $maxMessageBytes = 16777215,
        private readonly ?string $proxy = null,
    ) {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $maxMessageBytes < 1) {
            throw new \InvalidArgumentException('Invalid WebSocket limits');
        }
    }

    public function connect(): void
    {
        if ($this->connected) return;
        $parts = parse_url($this->url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) ||
            !in_array($parts['scheme'], ['ws', 'wss'], true) || isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['fragment']) || preg_match('/[\r\n]/', $this->url)) {
            throw new \InvalidArgumentException('Expected a ws:// or wss:// URL without credentials');
        }
        $secure = $parts['scheme'] === 'wss';
        $host = $parts['host'];
        $port = $parts['port'] ?? ($secure ? 443 : 80);
        $target = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true,
            'peer_name' => trim($host, '[]'), 'SNI_enabled' => true,
            'disable_compression' => true,
        ]]);
        $proxy = $this->proxy === null ? null : parse_url($this->proxy);
        if ($this->proxy !== null && ($proxy === false || !isset($proxy['host'],$proxy['scheme']) ||
            !in_array($proxy['scheme'],['http','socks5','socks5h'],true) || preg_match('/[\r\n]/',$this->proxy))) throw new \InvalidArgumentException('Proxy must be http:// or socks5://');
        $endpoint = $proxy === null ? ($secure ? 'tls' : 'tcp').'://'.$host.':'.$port : 'tcp://'.$proxy['host'].':'.($proxy['port'] ?? ($proxy['scheme']==='http' ? 8080 : 1080));
        $stream = @stream_socket_client($endpoint,
            $errorCode, $errorMessage, $this->timeoutSeconds, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) throw new \RuntimeException('WebSocket connection failed: ' . $errorMessage, $errorCode);
        $this->stream = $stream;
        $this->buffer = $this->fragment = '';
        $this->fragmentOpcode = null;
        stream_set_blocking($stream, false);
        try {
            if ($proxy !== null) {
                $this->tunnel($proxy,$host,$port);
                if ($secure) {
                    $deadline=microtime(true)+$this->timeoutSeconds;
                    do {
                        $ready=@stream_socket_enable_crypto($stream,true,STREAM_CRYPTO_METHOD_TLS_CLIENT);
                        if ($ready===true) break;
                        if ($ready===false || microtime(true)>=$deadline) throw new \RuntimeException('WebSocket proxy TLS negotiation failed');
                        $read=[$stream]; $write=[]; $except=[]; @stream_select($read,$write,$except,0,100000);
                    } while (true);
                }
            }
            $key = base64_encode(random_bytes(16));
            $authority = $host . (($secure && $port === 443) || (!$secure && $port === 80) ? '' : ':' . $port);
            $request = "GET $target HTTP/1.1\r\nHost: $authority\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n" .
                "Sec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\nOrigin: https://web.whatsapp.com\r\n" .
                "User-Agent: Mozilla/5.0\r\n\r\n";
            $this->writeAll($request);
            $deadline = microtime(true) + $this->timeoutSeconds;
            while (($end = strpos($this->buffer, "\r\n\r\n")) === false) {
                if (strlen($this->buffer) > 32768) throw new \LengthException('WebSocket upgrade headers too large');
                if (!$this->readMore($deadline)) throw new \RuntimeException('WebSocket upgrade timed out');
            }
            if ($end > 32768) throw new \LengthException('WebSocket upgrade headers too large');
            $headers = substr($this->buffer, 0, $end);
            $this->buffer = substr($this->buffer, $end + 4);
            $lines = explode("\r\n", $headers);
            if (!preg_match('/^HTTP\/1\.[01] 101(?: |$)/D', array_shift($lines) ?? '')) {
                throw new \UnexpectedValueException('Server refused WebSocket upgrade: ' . strtok($headers, "\r\n"));
            }
            $values = [];
            foreach ($lines as $line) {
                if (!str_contains($line, ':')) throw new \UnexpectedValueException('Malformed WebSocket upgrade header');
                [$name, $value] = explode(':', $line, 2);
                $name = strtolower(trim($name));
                $values[$name] = isset($values[$name]) ? $values[$name] . ',' . trim($value) : trim($value);
            }
            $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            if (!hash_equals($accept, $values['sec-websocket-accept'] ?? '') ||
                strtolower($values['upgrade'] ?? '') !== 'websocket' ||
                !in_array('upgrade', array_map('trim', explode(',', strtolower($values['connection'] ?? ''))), true) ||
                isset($values['sec-websocket-extensions']) || isset($values['sec-websocket-protocol'])) {
                throw new \UnexpectedValueException('Invalid WebSocket upgrade response');
            }
            $this->connected = true;
        } catch (\Throwable $error) {
            $this->disconnect();
            throw $error;
        }
    }

    /** @param array{scheme:string,host:string,port?:int,user?:string,pass?:string,...} $proxy */
    private function tunnel(array $proxy,string $host,int $port):void {
        $this->buffer='';
        if ($proxy['scheme']==='http') {
            $authority=$host.':'.$port;
            $auth=isset($proxy['user']) ? 'Proxy-Authorization: Basic '.base64_encode(rawurldecode($proxy['user']).':'.rawurldecode($proxy['pass'] ?? ''))."\r\n" : '';
            $this->writeAll("CONNECT $authority HTTP/1.1\r\nHost: $authority\r\n$auth\r\n");
            $deadline=microtime(true)+$this->timeoutSeconds;
            while (($end=strpos($this->buffer,"\r\n\r\n"))===false) {
                if (strlen($this->buffer)>32768) throw new \LengthException('Proxy headers too large');
                if (!$this->readMore($deadline)) throw new \RuntimeException('WebSocket proxy timed out');
            }
            if (!preg_match('/^HTTP\/1\.[01] 200(?: |\r)/D',$this->buffer)) throw new \RuntimeException('WebSocket proxy refused CONNECT');
            if (strlen($this->buffer)!==$end+4) throw new \UnexpectedValueException('Unexpected bytes before proxy tunnel');
            $this->buffer=''; return;
        }
        $user=isset($proxy['user']) ? rawurldecode($proxy['user']) : null;
        $pass=rawurldecode($proxy['pass'] ?? '');
        if (($user!==null && strlen($user)>255) || strlen($pass)>255) throw new \InvalidArgumentException('SOCKS credentials too long');
        $this->writeAll($user===null ? "\x05\x01\x00" : "\x05\x01\x02");
        $method=$this->proxyBytes(2);
        if ($method!==($user===null ? "\x05\x00" : "\x05\x02")) throw new \RuntimeException('SOCKS authentication method rejected');
        if ($user!==null) {
            $this->writeAll("\x01".chr(strlen($user)).$user.chr(strlen($pass)).$pass);
            if ($this->proxyBytes(2)!=="\x01\x00") throw new \RuntimeException('SOCKS credentials rejected');
        }
        $domain=trim($host,'[]'); $ip=@inet_pton($domain);
        $address=$ip!==false ? (strlen($ip)===4 ? "\x01" : "\x04").$ip : "\x03".chr(strlen($domain)).$domain;
        if (strlen($domain)>255) throw new \InvalidArgumentException('SOCKS target host too long');
        $this->writeAll("\x05\x01\x00".$address.pack('n',$port));
        $reply=$this->proxyBytes(4);
        if (substr($reply,0,3)!=="\x05\x00\x00") throw new \RuntimeException('SOCKS CONNECT rejected');
        $length=match(ord($reply[3])) { 1=>4, 4=>16, 3=>ord($this->proxyBytes(1)), default=>throw new \UnexpectedValueException('Invalid SOCKS address') };
        $this->proxyBytes($length+2);
        if ($this->buffer!=='') throw new \UnexpectedValueException('Unexpected bytes before SOCKS tunnel');
    }
    private function proxyBytes(int $length):string {
        $deadline=microtime(true)+$this->timeoutSeconds;
        while (strlen($this->buffer)<$length) if (!$this->readMore($deadline)) throw new \RuntimeException('WebSocket proxy timed out');
        $result=substr($this->buffer,0,$length); $this->buffer=substr($this->buffer,$length); return $result;
    }

    public function isConnected(): bool { return $this->connected && is_resource($this->stream); }

    public function send(string $payload): void
    {
        $this->assertConnected();
        if (strlen($payload) > $this->maxMessageBytes) throw new \LengthException('WebSocket message too large');
        try { $this->writeFrame(2, $payload); }
        catch (\Throwable $error) { $this->disconnect(); throw $error; }
    }

    /** Returns null on timeout; EOF and protocol failures throw. */
    public function receive(float $timeoutSeconds = 20.0): ?string
    {
        $this->assertConnected();
        if (!is_finite($timeoutSeconds) || $timeoutSeconds < 0) throw new \InvalidArgumentException('Invalid receive timeout');
        $deadline = microtime(true) + $timeoutSeconds;
        try {
            while (true) {
                $frame = $this->parseFrame();
                if ($frame === null) {
                    if (!$this->readMore($deadline)) return null;
                    continue;
                }
                [$opcode, $final, $payload] = $frame;
                if ($opcode === 8) {
                    if (strlen($payload) === 1) throw new \UnexpectedValueException('Invalid WebSocket close payload');
                    $code = $payload === '' ? 1000 : unpack('n', substr($payload, 0, 2))[1];
                    if ($payload !== '' && (($code < 1000 || $code >= 5000 ||
                        in_array($code, [1004, 1005, 1006, 1015], true) || ($code > 1014 && $code < 3000)) ||
                        preg_match('//u', substr($payload, 2)) !== 1)) {
                        throw new \UnexpectedValueException('Invalid WebSocket close code/reason');
                    }
                    $this->writeFrame(8, $payload);
                    throw new \RuntimeException('WebSocket closed by peer (' . $code . ')');
                }
                if ($opcode === 9) { $this->writeFrame(10, $payload); continue; }
                if ($opcode === 10) continue;
                if ($opcode === 0) {
                    if ($this->fragmentOpcode === null) throw new \UnexpectedValueException('Unexpected WebSocket continuation');
                    if (strlen($this->fragment) + strlen($payload) > $this->maxMessageBytes) throw new \LengthException('WebSocket message too large');
                    $this->fragment .= $payload;
                    if (!$final) continue;
                    $payload = $this->fragment;
                    $this->fragment = '';
                    $this->fragmentOpcode = null;
                    return $payload;
                }
                if ($this->fragmentOpcode !== null) throw new \UnexpectedValueException('Interleaved WebSocket data frames');
                if ($opcode !== 2) throw new \UnexpectedValueException('Expected binary WebSocket message');
                if ($final) return $payload;
                $this->fragmentOpcode = $opcode;
                $this->fragment = $payload;
            }
        } catch (\Throwable $error) { $this->disconnect(); throw $error; }
    }

    public function close(): void
    {
        if ($this->isConnected()) {
            try { $this->writeFrame(8, pack('n', 1000)); } catch (\Throwable) { }
        }
        $this->disconnect();
    }

    private function assertConnected(): void
    {
        if (!$this->isConnected()) throw new \LogicException('WebSocket is not connected');
    }

    private function disconnect(): void
    {
        if (is_resource($this->stream)) fclose($this->stream);
        $this->stream = null;
        $this->connected = false;
        $this->buffer = $this->fragment = '';
        $this->fragmentOpcode = null;
    }

    private function writeFrame(int $opcode, string $payload): void
    {
        $length = strlen($payload);
        $header = chr(0x80 | $opcode);
        $header .= $length < 126 ? chr(0x80 | $length) :
            ($length <= 65535 ? "\xfe" . pack('n', $length) : "\xff" . pack('NN', 0, $length));
        $mask = random_bytes(4);
        $repeated = str_repeat($mask, intdiv($length + 3, 4));
        $this->writeAll($header . $mask . ($payload ^ substr($repeated, 0, $length)));
    }

    private function writeAll(string $bytes): void
    {
        if (!is_resource($this->stream)) throw new \LogicException('WebSocket stream is unavailable');
        $offset = 0;
        $deadline = microtime(true) + $this->timeoutSeconds;
        while ($offset < strlen($bytes)) {
            $written = @fwrite($this->stream, substr($bytes, $offset));
            if ($written === false || feof($this->stream)) throw new \RuntimeException('WebSocket write failed');
            $offset += $written;
            if ($offset < strlen($bytes) && !$this->waitReady($deadline, true)) throw new \RuntimeException('WebSocket write timed out');
        }
    }

    private function readMore(float $deadline): bool
    {
        if (!is_resource($this->stream)) throw new \LogicException('WebSocket stream is unavailable');
        // OpenSSL may have already decrypted another TLS record internally while
        // the OS descriptor is no longer readable. Drain that buffer before select.
        $chunk = @fread($this->stream, 65536);
        if ($chunk === false || ($chunk === '' && feof($this->stream))) throw new \RuntimeException('WebSocket connection ended');
        if ($chunk !== '') { $this->buffer .= $chunk; return true; }
        if (!$this->waitReady($deadline, false)) return false;
        $chunk = @fread($this->stream, 65536);
        if ($chunk === false || ($chunk === '' && feof($this->stream))) throw new \RuntimeException('WebSocket connection ended');
        $this->buffer .= $chunk;
        return true;
    }

    private function waitReady(float $deadline, bool $write): bool
    {
        if (!is_resource($this->stream)) throw new \LogicException('WebSocket stream is unavailable');
        $remaining = max(0.0, $deadline - microtime(true));
        $read = $write ? [] : [$this->stream];
        $writes = $write ? [$this->stream] : [];
        $except = [];
        $seconds = (int) floor($remaining);
        $microseconds = (int) (($remaining - $seconds) * 1000000);
        $ready = @stream_select($read, $writes, $except, $seconds, $microseconds);
        if ($ready === false) throw new \RuntimeException('WebSocket stream selection failed');
        return $ready > 0;
    }

    /** @return array{int,bool,string}|null */
    private function parseFrame(): ?array
    {
        if (strlen($this->buffer) < 2) return null;
        $first = ord($this->buffer[0]);
        $second = ord($this->buffer[1]);
        $opcode = $first & 15;
        $final = (bool) ($first & 128);
        if (($first & 112) !== 0 || ($second & 128) !== 0 || !in_array($opcode, [0, 1, 2, 8, 9, 10], true)) {
            throw new \UnexpectedValueException('Invalid WebSocket frame flags/opcode');
        }
        $length = $second & 127;
        $offset = 2;
        if ($opcode >= 8 && (!$final || $length > 125)) throw new \UnexpectedValueException('Invalid WebSocket control frame');
        if ($length === 126) {
            if (strlen($this->buffer) < 4) return null;
            $length = unpack('n', substr($this->buffer, 2, 2))[1];
            if ($length < 126) throw new \UnexpectedValueException('Non-canonical WebSocket length');
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($this->buffer) < 10) return null;
            $parts = unpack('Nhigh/Nlow', substr($this->buffer, 2, 8));
            if ($parts['high'] !== 0) throw new \LengthException('WebSocket frame too large');
            $length = $parts['low'];
            if ($length <= 65535) throw new \UnexpectedValueException('Non-canonical WebSocket length');
            $offset = 10;
        }
        if ($length > $this->maxMessageBytes) throw new \LengthException('WebSocket frame too large');
        if (strlen($this->buffer) - $offset < $length) return null;
        $payload = substr($this->buffer, $offset, $length);
        $this->buffer = substr($this->buffer, $offset + $length);
        return [$opcode, $final, $payload];
    }
}
