<?php
declare(strict_types=1);

// Local synthetic HTTPS CONNECT peer. This fixture never forwards connections.
[$script, $mode, $directory, $count] = $argv;
$context = stream_context_create(['ssl' => [
    'local_cert' => $directory . '/certificate.pem', 'local_pk' => $directory . '/key.pem',
    'verify_peer' => false, 'allow_self_signed' => true,
]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) exit(2);
file_put_contents($directory . '/ready.json', json_encode(['address' => stream_socket_get_name($server, false)]));
$received = [];
for ($index = 0; $index < (int) $count; $index++) {
    $peer = @stream_socket_accept($server, 15);
    if ($peer === false) break;
    stream_set_timeout($peer, 5);
    $connect = '';
    while (!str_ends_with($connect, "\r\n\r\n") && strlen($connect) < 16384) {
        $line = fgets($peer);
        if ($line === false) break;
        $connect .= $line;
    }
    if (!preg_match('~\ACONNECT (?:b-)?graph\.facebook\.com:443 HTTP/1\.[01]\r\n~', $connect)) {
        $received[] = ['rejected_connect' => true]; fclose($peer); break;
    }
    fwrite($peer, "HTTP/1.1 200 Connection established\r\n\r\n");
    if (@stream_socket_enable_crypto($peer, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
        $received[] = ['tls_failed' => true]; fclose($peer); break;
    }
    $request = fgets($peer);
    // A TLS 1.3 client may reject the peer certificate after the server's
    // handshake call completed; no HTTP bytes must be accepted in that case.
    if ($request === false || $request === '') {
        $received[] = ['tls_failed' => true]; fclose($peer); break;
    }
    $headers = [];
    while (($line = fgets($peer)) !== false && $line !== "\r\n") {
        if (!str_contains($line, ':')) continue;
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower($name)] = trim($value);
    }
    $body = ''; $remaining = (int) ($headers['content-length'] ?? 0);
    while ($remaining > 0) {
        $chunk = fread($peer, min($remaining, 8192));
        if ($chunk === false || $chunk === '') break;
        $body .= $chunk; $remaining -= strlen($chunk);
    }
    $received[] = ['request' => trim((string) $request), 'headers' => $headers, 'body' => $body];
    $response = '{"ok":true}';
    $status = '200 OK';
    $extra = "X-Case: first\r\nX-Case: second\r\nSet-Cookie: synthetic=do-not-reuse; Path=/; Secure\r\n";
    if ($mode === 'redirect') { $status = '302 Found'; $extra .= "Location: https://untrusted.example.test/steal\r\n"; }
    if ($mode === 'body') $response = str_repeat('X', 16385);
    if ($mode === 'compressed') { $response = gzencode(str_repeat('X', 16385)); $extra .= "Content-Encoding: gzip\r\n"; }
    if ($mode === 'headers') $extra .= 'X-Large: ' . str_repeat('Y', 2048) . "\r\n";
    if ($mode === 'http-error') { $status = '401 Unauthorized'; $response = '{"error":"SYNTHETIC_PRIVATE_BODY"}'; }
    @fwrite($peer, "HTTP/1.1 $status\r\nContent-Type: application/json\r\nConnection: close\r\n" . $extra . 'Content-Length: ' . strlen($response) . "\r\n\r\n" . $response);
    fclose($peer);
}
file_put_contents($directory . '/received.json', json_encode($received));
fclose($server);
