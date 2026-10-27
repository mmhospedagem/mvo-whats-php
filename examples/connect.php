<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use MVO\Auth\QrCode;
use MVO\WaClient;
use MVO\Console\PairingDisplay;
use MVO\Console\CommandQueue;

$options = getopt('', ['mode:','session:','phone:','timeout:','version:','verbose','no-terminal','full-history','to:','text:','text-file:','exit-after-send']);
$mode = $options['mode'] ?? 'qr';
if (!in_array($mode,['qr','code'],true)) { fwrite(STDERR,"Use --mode=qr ou --mode=code\n"); exit(2); }
$phone = $options['phone'] ?? null;
if ($mode === 'code' && (!is_string($phone) || $phone === '')) { fwrite(STDERR,"Informe --phone=5511999999999 (DDI + DDD + numero).\n"); exit(2); }
$timeout = filter_var($options['timeout'] ?? (isset($options['exit-after-send']) ? '60' : '0'),FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>86400]]);
if ($timeout === false) { fwrite(STDERR,"Timeout deve ser de 0 a 86400 segundos (0 = continuo).\n"); exit(2); }
$to=$options['to'] ?? null; $text=$options['text'] ?? null;
if (isset($options['text-file'])) {
    if ($text!==null || !is_string($options['text-file']) || !is_file($options['text-file'])) { fwrite(STDERR,"Use --text OU --text-file com um arquivo existente.\n"); exit(2); }
    $text=file_get_contents($options['text-file']);
}
$sendRequested=$to!==null || $text!==null || isset($options['exit-after-send']);
if ($sendRequested && (!is_string($to) || $to==='' || !is_string($text) || trim($text)==='')) {
    fwrite(STDERR,"Para enviar, informe --to=NUMERO e --text=mensagem (ou --text-file=arquivo).\n"); exit(2);
}
$client = new WaClient((string)($options['session'] ?? 'default'),version:(string)($options['version'] ?? WaClient::VERSION),
    historyRequireFullSync:isset($options['full-history']));
$commands = new CommandQueue($client->sessionDirectory().'/commands');
$display = $mode === 'qr' && !isset($options['no-terminal'])
    ? new PairingDisplay(static function(string $output):void { echo $output; fflush(STDOUT); }) : null;
if ($display !== null && PHP_OS_FAMILY === 'Windows') {
    if (function_exists('sapi_windows_cp_set')) sapi_windows_cp_set(65001);
    if (function_exists('sapi_windows_vt100_support') && stream_isatty(STDOUT)) sapi_windows_vt100_support(STDOUT,true);
}
register_shutdown_function(static function() use ($display):void { $display?->close(); });
$stopping=false;
if (function_exists('sapi_windows_set_ctrl_handler')) {
    sapi_windows_set_ctrl_handler(static function(int $event) use (&$stopping,$display,$client):void {
        if ($event===PHP_WINDOWS_EVENT_CTRL_C || $event===PHP_WINDOWS_EVENT_CTRL_BREAK) {
            $stopping=true; $display?->close(); $client->disconnect();
        }
    });
}
$client->on('connection',static function (array $event) use ($display): void {
    if ($display !== null) $display->state($event['state']);
    else echo '['.date('H:i:s').'] '.$event['state'].PHP_EOL;
});
$client->on('auth_qr',static function (array $event) use ($client,$mode,$display): void {
    if ($mode !== 'qr') return;
    $path = $client->sessionDirectory().'/qr.svg';
    if (file_put_contents($path,QrCode::svg($event['qr']),LOCK_EX) === false) throw new RuntimeException('Cannot save QR image');
    if ($display !== null) $display->showQr($event['qr'],$event['expiresIn']);
    else {
        echo 'QR Code valido por '.$event['expiresIn'].'s: '.$path.PHP_EOL;
        echo "No celular: WhatsApp > Aparelhos conectados > Conectar um aparelho.\n";
    }
});
$client->on('auth_code',static function (array $event): void {
    echo PHP_EOL.'Codigo de pareamento: '.implode('-',str_split($event['code'],4)).PHP_EOL;
    echo "No celular: Aparelhos conectados > Conectar um aparelho > Conectar com numero de telefone.\n";
});
$client->on('auth_paired',static function (array $event) use ($display): void {
    if ($display !== null) $display->paired($event['credentials']['meJid']);
    else echo 'Vinculo verificado: '.$event['credentials']['meJid'].'. Aguardando login autenticado...'.PHP_EOL;
});
$client->on('auth_code_expired',static function (): void { echo "Codigo expirou. Execute novamente para solicitar outro.\n"; });
$client->on('auth_passkey_required',static function () use ($display): void { $display?->close(); echo "O WhatsApp solicitou passkey. Configure signPasskeyAssertion na API PHP para fornecer a assinatura WebAuthn.\n"; });
$client->on('auth_verification_code',static function(array $event) use ($display):void { $display?->close(); echo 'Codigo de verificacao passkey: '.($event['code'] ?? '').PHP_EOL; });
$client->on('history_sync_error',static function(array $event):void { fwrite(STDERR,'Falha na sincronizacao de historico: '.($event['error'] ?? 'erro').PHP_EOL); });
$client->on('appstate_error',static function(array $event):void { fwrite(STDERR,'Falha na sincronizacao das conversas: '.($event['error'] ?? 'erro').PHP_EOL); });
$client->on('auth_qr_expired',static function () use ($display): void { $display?->close(); echo "Referencias QR expiraram. Execute novamente para obter novas.\n"; });
$client->on('reconnect',static function(array $event) use ($display):void {
    $display?->close();
    echo 'Reconexao #'.($event['attempt'] ?? '?').' em '.($event['delay'] ?? '?').'s: '.($event['reason'] ?? 'transporte').PHP_EOL;
});
$client->on('version_updated',static function(array $event) use ($display):void {
    $display?->close();echo 'WhatsApp Web atualizado para '.$event['version'].'. Retomando a mesma sessao automaticamente...'.PHP_EOL;
});
$client->on('version_error',static function(array $event) use ($display):void {
    $display?->close();fwrite(STDERR,'Falha ao atualizar a versao do WhatsApp Web: '.$event['message'].PHP_EOL);
});
$client->on('logged_out',static function(array $event) use ($display):void {
    $display?->close(); echo 'Sessao revogada no WhatsApp: '.($event['reason'] ?? 'logout').PHP_EOL;
});
$client->on('message',static function(array $event) use ($display):void {
    $display?->close();
    $direction=($event['fromMe'] ?? false) ? 'Minha conta' : 'Recebida';
    $type = $event['type'] ?? 'text';
    $description = $event['text'] ?? $event['data']['caption'] ?? $event['data']['name'] ?? $event['data']['fileName'] ?? '';
    if (!is_string($description)) $description = '';
    echo '['.date('H:i:s').'] '.$direction.' de '.($event['from'] ?? '?').' ['.$type.']: '.$description.PHP_EOL;
});
$client->on('message_error',static function(array $event):void {
    fwrite(STDERR,'Falha ao processar mensagem '.($event['id'] ?? '').': '.($event['message'] ?? $event['reason'] ?? 'erro').PHP_EOL);
});
$client->on('message_receipt',static function(array $event):void {
    echo 'Recibo '.($event['type'] ?? '').' da mensagem '.($event['id'] ?? '').PHP_EOL;
});
$client->on('message_unsupported',static function(array $event):void {
    echo 'Conteudo nao suportado de '.($event['from'] ?? '?').': '.($event['reason'] ?? 'tipo desconhecido').PHP_EOL;
});
if (isset($options['verbose'])) $client->on('node',static function (array $event) use ($display): void {
    if ($display?->isActive()) return;
    // Never log binary payloads, identity secrets or QR contents.
    $node = $event['node'];
    echo '[node] '.$node->tag.' type='.($node->attrs['type'] ?? '').' code='.($node->attrs['code'] ?? '').PHP_EOL;
});
try {
    $client->connect();
    if ($mode === 'code' && is_string($phone) && !$client->isRegistered()) $client->requestPairingCode($phone);
    $deadline=$timeout>0 ? microtime(true)+$timeout : INF;
    $sent=false;
    while (!$stopping && microtime(true)<$deadline && !in_array($client->getState(),['disconnected','stopped','logged_out'],true)) {
        $client->poll(0.25);
        if ($client->isConnected()) $commands->process(fn(string $to,string|array $content):array=>$client->sendMessage($to,$content));
        if ($sendRequested && !$sent && $client->isConnected()) {
            // A server ACK confirms acceptance, not delivery or reading on the destination phone.
            $result=$client->sendText($to,$text); $sent=true;
            echo 'Mensagem '.$result['id'].' para '.$result['to'].': '.$result['status'].PHP_EOL;
            if (isset($options['exit-after-send'])) break;
        }
        $display?->tick();
    }
    $display?->close();
    if ($sendRequested && !$sent) throw new RuntimeException('Envio nao executado: a sessao nao ficou conectada no prazo.');
    echo 'Execucao encerrada. Estado: '.$client->getState().PHP_EOL;
} catch (Throwable $e) {
    $display?->close();
    fwrite(STDERR,'Falha: '.$e->getMessage().PHP_EOL);
    exit(1);
} finally { $display?->close(); $client->disconnect(); }
