<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use MVO\Auth\CredentialStore;
use MVO\WaClient;

$options=getopt('',['session:']); $session=(string)($options['session'] ?? 'default');
$store=new CredentialStore(dirname(__DIR__,2).'/.auth',$session);
$credentials=$store->load(); $store->close();
if (!is_array($credentials) || !($credentials['registered'] ?? false)) { fwrite(STDERR,"Vincule esta sessao antes do teste de retomada.\n"); exit(2); }
$identity=hash('sha256',$credentials['identityKeyPair']['public']);
$noise=hash('sha256',$credentials['noiseKeyPair']['public']);
$client=new WaClient($session,autoReconnect:false);
$sawQr=false; $messages=0;
$client->on('auth_qr',static function() use (&$sawQr):void { $sawQr=true; });
$client->on('message',static function() use (&$messages):void { $messages++; });
try {
    $client->connect(); $deadline=microtime(true)+35;
    while (!$client->isConnected() && microtime(true)<$deadline) $client->poll(0.5);
    if (!$client->isConnected() || $sawQr) throw new RuntimeException('Retomada nao confirmou login sem novo QR');
    $client->run(2);
    $client->disconnect();
    $restored=$store->load();
    if (hash('sha256',$restored['identityKeyPair']['public'])!==$identity || hash('sha256',$restored['noiseKeyPair']['public'])!==$noise) throw new RuntimeException('Identidade da sessao mudou durante retomada');
    echo "[OK] Sessao $session retomada sem QR; identidades preservadas; login e inicializacao confirmados.\n";
    echo "Mensagens de texto processadas: $messages. Nenhuma mensagem de teste foi enviada.\n";
} catch (Throwable $e) { fwrite(STDERR,'Falha na retomada: '.$e->getMessage().PHP_EOL); exit(1); }
finally { $client->disconnect(); $store->close(); }
