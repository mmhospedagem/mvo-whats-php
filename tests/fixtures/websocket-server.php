<?php
declare(strict_types=1);
// Isolated RFC 6455 peer for integration tests. No WhatsApp network access.
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) throw new RuntimeException($error, $errno);
echo stream_socket_get_name($server, false), "\n";
flush();
$client = stream_socket_accept($server, 5);
if ($client === false) exit(2);
stream_set_timeout($client, 5);
$mode = $argv[1] ?? 'valid';
if ($mode === 'proxy-http') {
    $connect=''; while (!str_contains($connect,"\r\n\r\n")) { $line=fgets($client); if ($line===false) exit(10); $connect.=$line; }
    if (!str_starts_with($connect,'CONNECT peer.invalid:80 HTTP/1.1') || !str_contains($connect,'Proxy-Authorization: Basic '.base64_encode('test:pass'))) exit(11);
    fwrite($client,"HTTP/1.1 200 Connection Established\r\n\r\n"); $mode='valid';
} elseif ($mode === 'proxy-socks') {
    $take=static function(int $size) use($client):string { $value=''; while (strlen($value)<$size) { $chunk=fread($client,$size-strlen($value)); if ($chunk===false || $chunk==='') exit(12); $value.=$chunk; } return $value; };
    if ($take(3)!=="\x05\x01\x02") exit(13); fwrite($client,"\x05\x02");
    if ($take(1)!=="\x01" || $take(ord($take(1)))!=='test' || $take(ord($take(1)))!=='pass') exit(14); fwrite($client,"\x01\x00");
    if ($take(4)!=="\x05\x01\x00\x03" || $take(ord($take(1)))!=='peer.invalid' || $take(2)!==pack('n',80)) exit(15);
    fwrite($client,"\x05\x00\x00\x01\x7f\x00\x00\x01\x00\x50"); $mode='valid';
}
$request = '';
while (!str_contains($request, "\r\n\r\n")) {
    $line = fgets($client);
    if ($line === false) exit(3);
    $request .= $line;
}
preg_match('/Sec-WebSocket-Key: ([^\r\n]+)/i', $request, $match);
$accept = base64_encode(sha1(($match[1] ?? '') . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
if ($mode === 'bad-accept') $accept = 'incorrect';
$extra = $mode === 'bad-extension' ? "Sec-WebSocket-Extensions: permessage-deflate\r\n" : '';
fwrite($client, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: keep-alive, Upgrade\r\nSec-WebSocket-Accept: $accept\r\n$extra\r\n");
$read = static function (int $size) use ($client): string {
    $out = '';
    while (strlen($out) < $size) {
        $bytes = fread($client, $size - strlen($out));
        if ($bytes === false || $bytes === '') throw new RuntimeException('Peer failed to send frame');
        $out .= $bytes;
    }
    return $out;
};
$receive = static function () use ($read): array {
    $head = $read(2);
    $length = ord($head[1]) & 127;
    if ($length === 126) $length = unpack('n', $read(2))[1];
    elseif ($length === 127) $length = unpack('Nhigh/Nlow', $read(8))['low'];
    $masked = (bool) (ord($head[1]) & 128);
    $mask = $masked ? $read(4) : '';
    $payload = $read($length);
    if ($masked) $payload ^= substr(str_repeat($mask, intdiv($length + 3, 4)), 0, $length);
    return ['opcode' => ord($head[0]) & 15, 'masked' => $masked, 'payload' => $payload];
};
if ($mode === 'valid' || $mode === 'large') {
    $message = $receive();
    if ($mode === 'valid') {
        fwrite($client, "\x02\x03hel\x89\x01p\x80\x02lo");
        $pong = $receive();
        echo json_encode(['message' => $message, 'pong' => $pong], JSON_THROW_ON_ERROR), "\n";
    } else {
        echo json_encode(['length' => strlen($message['payload']), 'masked' => $message['masked']], JSON_THROW_ON_ERROR), "\n";
        fwrite($client, "\x82\x7f" . pack('NN', 0, 70000) . str_repeat('r', 70000));
    }
    flush();
    $receive(); // clean client close
} elseif ($mode === 'masked-frame') fwrite($client, "\x82\x80abcd");
elseif ($mode === 'bad-opcode') fwrite($client, "\x83\x00");
elseif ($mode === 'bad-control') fwrite($client, "\x09\x00");
elseif ($mode === 'noncanonical') fwrite($client, "\x82\x7e\x00\x01x");
elseif ($mode === 'oversized') fwrite($client, "\x82\x7f" . pack('NN', 1, 0));
elseif ($mode === 'close') fwrite($client, "\x88\x02" . pack('n', 1001));
elseif ($mode === 'idle') { $receive(); }
fclose($client);
fclose($server);
