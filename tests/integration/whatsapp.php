<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';

use MVO\Auth\QrCode;
use MVO\WaClient;

// Explicit opt-in live check. Does not use a phone number or approve an account link.
if (getenv('MVO_LIVE_TEST') !== '1') {
    fwrite(STDERR,"Defina MVO_LIVE_TEST=1 para conectar ao servidor real do WhatsApp.\n"); exit(2);
}
$client = new WaClient('live-'.bin2hex(random_bytes(4)));
$received = false;
$client->on('auth_qr',static function(array $event) use (&$received):void {
    $parts=explode(',',$event['qr']);
    if (count($parts)!==5 || $parts[0]==='') throw new RuntimeException('Formato QR invalido');
    for($i=1;$i<=3;$i++) {
        $key=base64_decode($parts[$i],true);
        if ($key===false || strlen($key)!==32) throw new RuntimeException('Chave publica/ADV no QR invalida');
    }
    $svg=QrCode::svg($event['qr']);
    if (!str_contains($svg,'<svg')) throw new RuntimeException('Falha na renderizacao QR');
    $received=true;
});
try {
    $client->connect(); $deadline=microtime(true)+25;
    while (!$received && microtime(true)<$deadline) $client->poll(0.5);
    if (!$received) throw new RuntimeException('Servidor nao enviou QR no prazo');
    echo "[OK] WhatsApp real: TLS, WebSocket, certificado Noise, referencia QR e SVG validos.\n";
    echo "Vinculo da conta nao realizado; o teste para antes da confirmacao no celular.\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
finally { $client->disconnect(); }
