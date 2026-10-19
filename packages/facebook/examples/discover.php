<?php
declare(strict_types=1);

use MVO\Facebook\FacebookClient;
use MVO\Facebook\FacebookException;
use MVO\Messenger\MessengerClient;
use MVO\Messenger\MessengerException;

ini_set('zend.exception_ignore_args', '1');
ini_set('display_errors', '0');

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['help', 'session:', 'auth-dir:', 'data-dir:', 'ads', 'ad-ids:']);
if (isset($options['help'])) {
    echo <<<'HELP'
MVO Facebook PHP — descoberta somente de leitura

Uso:
  php packages/facebook/examples/discover.php --session=demo --auth-dir=/diretorio/privado/auth --data-dir=/diretorio/privado/facebook
  php packages/facebook/examples/discover.php --session=demo --auth-dir=/diretorio/privado/auth --data-dir=/diretorio/privado/facebook --ads
  php packages/facebook/examples/discover.php --session=demo --auth-dir=/diretorio/privado/auth --data-dir=/diretorio/privado/facebook --ads --ad-ids=10001,10002

--session   Obrigatório. ID de uma sessão Messenger/Facebook já autenticada.
--auth-dir  Obrigatório. Mesma pasta privada usada para salvar essa sessão.
--data-dir  Obrigatório. Pasta privada para os serviços Facebook, fora da raiz HTTP.
--ads       Além das Páginas, consulta as contas de anúncios disponíveis no contexto.
            O contrato observado retorna no máximo duas contas; não é inventário completo.
--ad-ids    Opcional com --ads. De 1 a 20 IDs de anúncios conhecidos, separados por vírgula.
            Consulta metadados desses anúncios; não enumera todos os anúncios da conta.
--help      Exibe esta ajuda sem carregar pacotes, abrir armazenamento ou conectar.

Saída: JSON por linha com projeções de Páginas, contas e anúncios solicitados.
O exemplo restaura a sessão existente; não recebe senha, código nem cookies.
Não envia mensagens, não publica conteúdo, não altera anúncios e não faz logout.
Os dados de sessão não são impressos. Autenticação expirada exige novo login na aplicação.
O protocolo e as permissões continuam experimentais; consulte os limites retornados.
Requer PHP 8.2+ de 64 bits e as extensões declaradas pelos pacotes locais.
HELP;
    echo PHP_EOL;
    exit(0);
}

$messenger = null;
$facebook = null;
$status = 0;

/** @param array<string,mixed> $value */
function mvoFacebookDiscoveryWrite(array $value): void
{
    fwrite(STDOUT, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
}

try {
    $session = $options['session'] ?? null;
    $authDirectory = $options['auth-dir'] ?? null;
    $dataDirectory = $options['data-dir'] ?? null;
    if (!is_string($session) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $session) !== 1) {
        throw new InvalidArgumentException('Informe o ID de uma sessão já autenticada.');
    }
    foreach ([$authDirectory, $dataDirectory] as $directory) {
        if (!is_string($directory) || trim($directory) === '' || str_contains($directory, "\0")
            || preg_match('~^[a-z][a-z0-9+.-]*://~i', $directory) === 1) {
            throw new InvalidArgumentException('Informe os dois diretórios locais privados.');
        }
    }
    $authDirectory = realpath($authDirectory);
    if ($authDirectory === false || !is_dir($authDirectory)) throw new InvalidArgumentException('A pasta de autenticação existente não foi encontrada.');
    $savedSession = $authDirectory.DIRECTORY_SEPARATOR.'messenger'.DIRECTORY_SEPARATOR.$session.DIRECTORY_SEPARATOR.'state.sqlite';
    if (!is_file($savedSession) || !is_readable($savedSession)) {
        throw new InvalidArgumentException('Este exemplo requer uma sessão previamente salva e legível.');
    }

    $withAds = isset($options['ads']);
    $adIds = [];
    if (array_key_exists('ad-ids', $options)) {
        $ids = $options['ad-ids'];
        if (!$withAds || !is_string($ids)) throw new InvalidArgumentException('Use --ad-ids somente com --ads.');
        $adIds = explode(',', $ids);
        if ($adIds === [] || count($adIds) > 20) throw new InvalidArgumentException('Informe de um a vinte IDs de anúncios.');
        foreach ($adIds as $id) {
            if (preg_match('/^[1-9][0-9]{0,24}$/D', $id) !== 1) throw new InvalidArgumentException('Preserve os IDs exatos dos anúncios como strings.');
        }
        $adIds = array_values(array_unique($adIds));
    }

    // Composer consumers may use their application's vendor/autoload.php instead.
    $packageDirectory = dirname(__DIR__);
    $composerAutoload = $packageDirectory.'/vendor/autoload.php';
    if (is_file($composerAutoload)) require_once $composerAutoload;
    spl_autoload_register(static function (string $class) use ($packageDirectory): void {
        foreach (['MVO\\Facebook\\' => $packageDirectory.'/src/', 'MVO\\Messenger\\' => dirname($packageDirectory).'/messenger/src/'] as $prefix => $directory) {
            if (!str_starts_with($class, $prefix)) continue;
            $file = $directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) require_once $file;
            return;
        }
    });

    $messenger = new MessengerClient(sessionId: $session, authDirectory: $authDirectory);
    $messenger->connect();
    if (!$messenger->isConnected()) throw new RuntimeException('The existing session is not connected.');
    $facebook = new FacebookClient($messenger->webSession(), $dataDirectory);

    $pages = $facebook->listPages();
    mvoFacebookDiscoveryWrite(['resource' => 'pages', 'items' => $pages['items'],
        'page_info' => $pages['page_info'], 'exhaustive' => $pages['exhaustive']]);

    if ($withAds) {
        $accounts = $facebook->listAdAccounts();
        mvoFacebookDiscoveryWrite(['resource' => 'ad_accounts', 'items' => $accounts['items'],
            'partial' => $accounts['partial'], 'exhaustive' => $accounts['exhaustive'], 'maximum_items' => $accounts['maximum_items']]);
        if ($adIds !== []) {
            $items = [];
            foreach ($facebook->ads()->ads($adIds) as $row) {
                // Never dump raw GraphQL nodes, request fields or credential-bearing context.
                $item = [];
                foreach (['id', 'name', 'effective_status', 'configured_status', 'status'] as $field) {
                    $value = $row[$field] ?? null;
                    if (is_scalar($value) || $value === null) $item[$field] = $value;
                }
                $items[] = $item;
            }
            mvoFacebookDiscoveryWrite(['resource' => 'ads', 'items' => $items, 'requested_ids' => $adIds,
                'maximum_requested_ids' => 20, 'exhaustive' => false, 'scope' => 'known_ids_only']);
        } else {
            mvoFacebookDiscoveryWrite(['resource' => 'ads', 'queried' => false,
                'message' => 'Para consultar anúncios existentes, acrescente --ad-ids=ID1,ID2; não há enumeração completa neste exemplo.']);
        }
    }
} catch (FacebookException $error) {
    $code = preg_match('/^[a-z0-9_]{1,80}$/D', $error->reason) === 1 ? $error->reason : 'facebook_error';
    fwrite(STDERR, json_encode(['error' => true, 'code' => $code, 'message' => 'A consulta Facebook não foi confirmada. Confira a sessão, as permissões e os contratos atuais.'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL);
    $status = 1;
} catch (MessengerException $error) {
    $code = preg_match('/^[a-z0-9_]{1,80}$/D', $error->reason) === 1 ? $error->reason : 'messenger_error';
    fwrite(STDERR, json_encode(['error' => true, 'code' => $code, 'message' => 'Não foi possível restaurar a sessão existente. Verifique a autenticação na aplicação.'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL);
    $status = 1;
} catch (InvalidArgumentException) {
    fwrite(STDERR, "Parâmetros locais inválidos ou sessão salva ausente; consulte --help.\n");
    $status = 1;
} catch (Throwable) {
    fwrite(STDERR, "Falha local. Verifique as extensões, o armazenamento privado e o estado da sessão. Dados privados e stack traces foram omitidos.\n");
    $status = 1;
} finally {
    // The service storage closes before releasing the owner of the Web session.
    try { $facebook?->close(); }
    catch (Throwable) { fwrite(STDERR, "Não foi possível concluir o fechamento do armazenamento Facebook.\n"); $status = 1; }
    try { $messenger?->stop(); }
    catch (Throwable) { fwrite(STDERR, "Não foi possível concluir o encerramento local do Messenger.\n"); $status = 1; }
}
exit($status);
