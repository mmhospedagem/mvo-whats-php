<?php
declare(strict_types=1);

// This example deliberately loads only the local Messenger package. Composer
// consumers should load their application's vendor/autoload.php instead.
ini_set('zend.exception_ignore_args', '1');
$packageDirectory = dirname(__DIR__);
$composerAutoload = $packageDirectory . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
} else {
    spl_autoload_register(static function (string $class) use ($packageDirectory): void {
        $prefix = 'MVO\\Messenger\\';
        if (!str_starts_with($class, $prefix)) return;
        $path = $packageDirectory . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) require_once $path;
    });
}

use MVO\Messenger\Auth\CookieJar;
use MVO\Messenger\MessengerClient;
use MVO\Messenger\MessengerException;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['help', 'session:', 'auth-dir:', 'cookies-file:', 'seconds:']);
if (isset($options['help'])) {
    echo <<<'HELP'
MVO Messenger PHP — experimento local, sem envio automático

Uso:
  php packages/messenger/examples/connect.php --session=demo --auth-dir=/diretorio/privado --cookies-file=/arquivo/privado.json --seconds=30
  php packages/messenger/examples/connect.php --session=demo --auth-dir=/diretorio/privado --seconds=30

--session       ID local da sessão; padrão: demo.
--auth-dir      Diretório privado de armazenamento, fora da pasta HTTP pública.
--cookies-file  Caminho local de um JSON de cookies da sua própria sessão Web.
                Opcional para restaurar uma sessão já importada. Nunca passe
                os valores dos cookies na linha de comando ou nos logs.
--seconds       Tempo do loop depois da conexão: 0 a 3600; padrão: 30.
--help          Mostra esta ajuda sem conectar ou criar sessão.

Requer PHP 8.2+ de 64 bits e curl/json/openssl/pdo/pdo_sqlite.
O estado atual é metadata_connected, com e2ee_ready=false.
Conversas pessoais criptografadas ainda não são suportadas; não há fallback.
Este exemplo não envia mensagens e não imprime conteúdo recebido.
HELP;
    echo PHP_EOL;
    exit(0);
}

$client = null;
$status = 0;
try {
    $sessionId = $options['session'] ?? 'demo';
    $authDirectory = $options['auth-dir'] ?? null;
    $seconds = $options['seconds'] ?? '30';
    if (!is_string($sessionId) || !is_string($authDirectory) || $authDirectory === '' ||
        preg_match('~^[a-z][a-z0-9+.-]*://~i', $authDirectory) === 1 ||
        !is_string($seconds) || preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $seconds) !== 1 || (float) $seconds > 3600) {
        throw new InvalidArgumentException('Informe --auth-dir local e --seconds entre 0 e 3600; consulte --help.');
    }
    $client = new MessengerClient(sessionId: $sessionId, authDirectory: $authDirectory);
    $cookiesFile = $options['cookies-file'] ?? null;
    if ($cookiesFile !== null) {
        if (!is_string($cookiesFile) || preg_match('~^[a-z][a-z0-9+.-]*://~i', $cookiesFile) === 1 || !is_file($cookiesFile) || !is_readable($cookiesFile)) {
            throw new InvalidArgumentException('--cookies-file precisa apontar para um arquivo local legível.');
        }
        $json = file_get_contents($cookiesFile, false, null, 0, 1048577);
        if ($json === false || strlen($json) > 1048576) throw new InvalidArgumentException('Arquivo de cookies ausente ou acima de 1 MiB.');
        $client->importCookies(CookieJar::fromJson($json));
        unset($json);
    }

    $pending = [];
    $client->on('connection', static function (array $event) use (&$pending): void {
        $pending[] = ['event' => 'connection', 'state' => $event['state'] ?? null, 'e2ee_ready' => false];
    });
    $client->on('message', static function (array $event) use (&$pending): void {
        if (count($pending) >= 1000) throw new OverflowException('A fila local de diagnóstico atingiu o limite.');
        // Explicit projection: never log the message text, raw LS or cookies.
        $pending[] = ['event' => 'message', 'id' => $event['id'] ?? null, 'type' => $event['type'] ?? null];
    });

    $client->connect();
    echo json_encode(['account' => $client->accountInfo(), 'threads_count' => count($client->threads())], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    $deadline = microtime(true) + (float) $seconds;
    do {
        foreach ($pending as $event) echo json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        $pending = [];
        $remaining = $deadline - microtime(true);
        if (!$client->isConnected() || $remaining <= 0) break;
        $client->poll(min(0.25, $remaining));
        // Application commands belong here, outside listener callbacks.
        // This example intentionally does not call sendText().
    } while (true);
} catch (MessengerException $error) {
    fwrite(STDERR, json_encode(['error' => true, 'code' => $error->reason, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    $status = 1;
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, json_encode(['error' => true, 'code' => 'invalid_local_input', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    $status = 1;
} catch (Throwable) {
    fwrite(STDERR, "Falha local. Confira o arquivo privado, as extensões e permissões; valores privados e stack traces foram omitidos.\n");
    $status = 1;
} finally {
    try { $client?->stop(); }
    catch (Throwable) {
        fwrite(STDERR, "Não foi possível concluir o encerramento local; verifique o armazenamento privado.\n");
        $status = 1;
    }
}
exit($status);
