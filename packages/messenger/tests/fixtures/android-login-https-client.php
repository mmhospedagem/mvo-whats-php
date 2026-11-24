<?php
declare(strict_types=1);

namespace MVO\Messenger\Transport {
    // Inject only the fixture CA into the real native handle in this child process.
    // Peer/hostname verification and all transport request options remain unchanged.
    function curl_init() {
        $handle = \curl_init();
        if ($handle !== false) \curl_setopt($handle, CURLOPT_CAINFO, \ini_get('curl.cainfo'));
        return $handle;
    }
}
namespace {

require dirname(__DIR__) . '/bootstrap.php';

use MVO\Messenger\Transport\AndroidLoginCurlTransport;
use MVO\Messenger\MessengerException;

// Only synthetic test data travels to the loopback TLS peer. CA/proxy are child-local.
ini_set('zend.exception_ignore_args', '1');
$input = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
$transport = new AndroidLoginCurlTransport(5.0, $input['max_body'] ?? 2097152, $input['max_headers'] ?? 65536);
$results = [];
try {
    for ($i = 0; $i < ($input['count'] ?? 1); $i++) {
        $response = $transport->request($input['method'], $input['url'], $input['form'] ?? [], $input['headers'] ?? []);
        $results[] = ['status' => $response->status, 'body' => $response->body, 'first_case' => $response->header('X-CaSe'), 'location' => $response->header('Location')];
    }
    echo json_encode(['ok' => true, 'responses' => $results], JSON_THROW_ON_ERROR);
} catch (MessengerException $error) {
    echo json_encode(['ok' => false, 'reason' => $error->reason, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
}
}
