<?php
declare(strict_types=1);

// Synthetic loopback-only RFC6455 peer. Contains no Meta credentials or traffic.
$mode = $argv[1] ?? 'roundtrip';
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) { fwrite(STDERR, 'Unable to bind loopback peer'); exit(2); }
$address = stream_socket_get_name($server, false);
echo substr($address, strrpos($address, ':') + 1) . "\n";
flush();
$client = stream_socket_accept($server, 5);
fclose($server);
if ($client === false) exit(3);
stream_set_timeout($client, 3);
$request = '';
while (!str_contains($request, "\r\n\r\n") && strlen($request) < 65536) {
    $part = fread($client, 4096);
    if ($part === false || $part === '') break;
    $request .= $part;
}
preg_match('/^Sec-WebSocket-Key:\s*(.+)\r$/mi', $request, $match);
$accept = base64_encode(sha1(trim($match[1] ?? '') . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
$response = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n";
if ($mode === 'bad-accept') $response = str_replace($accept, 'invalid', $response);
if ($mode === 'large-headers') $response .= 'X-Padding: ' . str_repeat('a', 33000) . "\r\n";
if ($mode === 'refusal') $response = "HTTP/1.1 403 PRIVATE-REFLECTED-VALUE\r\nContent-Length: 0\r\n";
if ($mode === 'subprotocol') $response .= "Sec-WebSocket-Protocol: dgwmock\r\n";
fwrite($client, $response . "\r\n");
$frames = [];
if ($mode === 'roundtrip' || $mode === 'subprotocol') {
    // Split a binary message around a ping and across TCP writes. Binary bytes
    // deliberately include non-UTF8 data: the connector is a binary transport.
    fwrite($client, "\x02\x03\x00he\x89\x01p\x80");
    usleep(10000);
    fwrite($client, "\x04llo\xff");
    for ($i = 0; $i < 3; ++$i) {
        $frame = readClientFrame($client);
        if ($frame === null) break;
        $frames[] = $frame;
        if ($frame['opcode'] === 8) break;
    }
} elseif ($mode === 'masked-server') fwrite($client, "\x82\x81\x00\x00\x00\x00x");
elseif ($mode === 'text-server') fwrite($client, "\x81\x02hi");
elseif ($mode === 'bad-close-utf8') fwrite($client, "\x88\x03\x03\xe8\xff");
elseif ($mode === 'noncanonical') fwrite($client, "\x82\x7e\x00\x01x");
elseif ($mode === 'oversize') fwrite($client, "\x82\x7f\x00\x00\x00\x00\x00\x01\x00\x00");
elseif ($mode === 'fragment-overflow') fwrite($client, "\x02\x04abcd\x80\x04efgh");
fclose($client);
echo json_encode(['request' => $request, 'frames' => $frames], JSON_THROW_ON_ERROR) . "\n";

/** @param resource $stream */
function exact($stream, int $length): ?string
{
    $value = '';
    while (strlen($value) < $length) {
        $part = fread($stream, $length - strlen($value));
        if ($part === false || $part === '') return null;
        $value .= $part;
    }
    return $value;
}

/** @param resource $stream @return array<string,mixed>|null */
function readClientFrame($stream): ?array
{
    $head = exact($stream, 2);
    if ($head === null) return null;
    $length = ord($head[1]) & 127;
    if ($length === 126) { $extra = exact($stream, 2); if ($extra === null) return null; $length = unpack('n', $extra)[1]; }
    if ($length === 127) { $extra = exact($stream, 8); if ($extra === null) return null; $length = unpack('Nhigh/Nlow', $extra)['low']; }
    if ($length > 100000) return null;
    $masked = (ord($head[1]) & 128) !== 0;
    $mask = $masked ? exact($stream, 4) : null;
    $payload = exact($stream, $length);
    if ($payload === null || ($masked && $mask === null)) return null;
    if ($mask !== null) for ($i = 0; $i < $length; ++$i) $payload[$i] = $payload[$i] ^ $mask[$i % 4];
    return ['opcode' => ord($head[0]) & 15, 'masked' => $masked, 'hex' => bin2hex($payload)];
}
