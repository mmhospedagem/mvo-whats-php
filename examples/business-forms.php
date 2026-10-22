<?php
declare(strict_types=1);

// --help and an invocation without arguments exit before autoload, storage or connection.
$options = getopt('', ['help', 'action:', 'session:', 'auth-dir:', 'form-file:', 'id:', 'name:', 'country:', 'request-id:', 'to:', 'cta:', 'text:', 'title:', 'footer:', 'message-id:', 'connect-timeout:']);
if (isset($options['help']) || count($argv) === 1) {
    echo <<<'HELP'
Formulários Business pela biblioteca PHP — contrato desta revisão

php examples/business-forms.php --action=templates --session=cliente-demo
php examples/business-forms.php --action=list --session=cliente-demo
php examples/business-forms.php --action=create --session=cliente-demo --form-file=./formulario.json
php examples/business-forms.php --action=get --session=cliente-demo --id=ID_LOCAL
php examples/business-forms.php --action=rename --session=cliente-demo --id=ID_LOCAL --name="Cadastro"
php examples/business-forms.php --action=delete --session=cliente-demo --id=ID_LOCAL
php examples/business-forms.php --action=publish --session=cliente-demo --id=ID_LOCAL --country=BR --request-id=publicacao-001
php examples/business-forms.php --action=publication-status --session=cliente-demo --request-id=publicacao-001
php examples/business-forms.php --action=authentication-status --session=cliente-demo
php examples/business-forms.php --action=send --session=cliente-demo --id=ID_LOCAL --country=BR --request-id=envio-001 --to=5511999999999 --cta="Preencher formulário"
php examples/business-forms.php --action=send-status --session=cliente-demo --request-id=envio-001
php examples/business-forms.php --action=send-publication --session=cliente-demo --request-id=publicacao-001 --to=5511999999999 --message-id=MENSAGEM_NOVA_001

--auth-dir=PASTA          Pasta privada de sessões; padrão: ./var/whatsapp-auth.
--connect-timeout=60      Espera por connected; entre 1 e 300 segundos.
--cta/--text/--title/--footer personalizam a apresentação do envio.

Execute em uma sessão livre: apenas um worker pode possuir o lock de cada sessão.
Os destinos são fictícios; substitua somente por contas autorizadas.
CRUD é local à sessão SDK e não sincroniza a lista "Meus formulários" do celular.
publish cria um recurso remoto; send publica e envia; send-publication envia uma NOVA
mensagem usando uma publicação já confirmada. Ele não é uma repetição idempotente.
Timeout/unknown não autoriza reenviar automaticamente. Consulte os estados salvos.
publish usa autenticação Business automática, sem app key/token/cookies manuais.
A primeira operação pode aguardar até 96s na autenticação nativa + 30s HTTP.
authentication-status mostra só a conexão atual e não solicita autorização.
O token fica em RAM e é limpo ao encerrar; não persiste entre execuções do CLI.
Recuperação, 403 e cooldown não são contornados. Companion ainda não homologado.
Os formatos foram estudados estaticamente; isso não comprova compatibilidade real.
Este exemplo pressupõe um checkout que já contém WaClient::forms().

HELP;
    exit(0);
}

use MVO\Auth\QrCode;
use MVO\WaClient;

/** @param array<string,mixed> $values */
function businessFormArgument(array $values, string $key): string
{
    $value = $values[$key] ?? null;
    if (!is_string($value) || trim($value) === '') throw new InvalidArgumentException('Informe --'.$key.'; consulte --help.');
    return $value;
}

/** @param array<string,mixed>|null $record
 * @return array<string,mixed>|null */
function businessFormPublication(?array $record): ?array
{
    if ($record === null) return null;
    $out = array_intersect_key($record, array_flip(['requestId', 'formId', 'state', 'countryCode', 'createdAt', 'updatedAt', 'error', 'serverCode']));
    if (is_array($record['publication'] ?? null)) $out['publication'] = array_intersect_key($record['publication'], array_flip(['flowId', 'wellVersion', 'flowName', 'category']));
    return $out;
}

$client = null;
$forms = null;
$exitCode = 0;
try {
    $action = businessFormArgument($options, 'action');
    if (!in_array($action, ['templates', 'list', 'create', 'get', 'rename', 'delete', 'publish', 'send', 'send-publication', 'publication-status', 'send-status', 'authentication-status'], true)) {
        throw new InvalidArgumentException('Ação desconhecida; consulte --help.');
    }
    $session = businessFormArgument($options, 'session');
    $authDirectory = $options['auth-dir'] ?? dirname(__DIR__).'/var/whatsapp-auth';
    if (!is_string($authDirectory) || trim($authDirectory) === '') throw new InvalidArgumentException('Pasta de sessão inválida.');
    $timeout = filter_var($options['connect-timeout'] ?? '60', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 300]]);
    if ($timeout === false) throw new InvalidArgumentException('connect-timeout deve ficar entre 1 e 300 segundos.');
    $form = null;
    if ($action === 'create') {
        $path = businessFormArgument($options, 'form-file');
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $path) || !is_file($path) || !is_readable($path)) throw new InvalidArgumentException('form-file deve ser um arquivo local legível.');
        $bytes = file_get_contents($path, false, null, 0, 2097153);
        if ($bytes === false || strlen($bytes) > 2097152) throw new InvalidArgumentException('O arquivo de formulário excede 2 MiB ou não pôde ser lido.');
        $form = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($form) || array_is_list($form)) throw new InvalidArgumentException('form-file deve conter um objeto JSON.');
    }
    if (in_array($action, ['get', 'rename', 'delete', 'publish', 'send'], true)) businessFormArgument($options, 'id');
    if ($action === 'rename') businessFormArgument($options, 'name');
    if (in_array($action, ['publish', 'send'], true)) businessFormArgument($options, 'country');
    if (in_array($action, ['publish', 'send', 'send-publication', 'publication-status', 'send-status'], true)) businessFormArgument($options, 'request-id');
    if (in_array($action, ['send', 'send-publication'], true)) businessFormArgument($options, 'to');
    $messageOptions = [];
    foreach (['cta', 'text', 'title', 'footer'] as $key) if (array_key_exists($key, $options)) $messageOptions[$key] = businessFormArgument($options, $key);
    if (array_key_exists('message-id', $options)) $messageOptions['messageId'] = businessFormArgument($options, 'message-id');

    require dirname(__DIR__).'/vendor/autoload.php';
    if (!method_exists(WaClient::class, 'forms')) throw new LogicException('Este checkout ainda não contém WaClient::forms(); use a revisão do recurso.');
    $client = new WaClient(sessionId: $session, authDirectory: $authDirectory);
    $client->on('auth_qr', static function (array $event): void {
        fwrite(STDERR, QrCode::terminal($event['qr']));
    });
    $client->connect();
    $deadline = hrtime(true) / 1000000000 + $timeout;
    while ($client->isRunning() && !$client->isConnected() && hrtime(true) / 1000000000 < $deadline) $client->poll(0.25);
    if (!$client->isConnected()) throw new RuntimeException('A sessão não chegou a connected dentro do prazo; nenhuma ação de formulário foi iniciada.');
    // Commands execute outside callbacks, on the process that owns this session's lock.
    $forms = $client->forms();
    $result = match ($action) {
        'templates' => $forms->templates(),
        'list' => $forms->list(),
        'create' => $forms->create($form),
        'get' => $forms->get($options['id']),
        'rename' => $forms->rename($options['id'], $options['name']),
        'delete' => ['deleted' => $forms->delete($options['id'])],
        'authentication-status' => $forms->authenticationStatus(),
        'publish' => businessFormPublication($forms->publish($options['id'], $options['country'], $options['request-id'])),
        'publication-status' => businessFormPublication($forms->publicationStatus($options['request-id'])),
        'send-status' => (static function (?array $attempt): ?array {
            return $attempt === null ? null : array_intersect_key($attempt, array_flip(['requestId', 'messageId', 'to', 'state', 'createdAt']));
        })($forms->sendStatus($options['request-id'])),
        'send', 'send-publication' => (static function (array $result): array {
            return ['publication' => businessFormPublication($result['publication']), 'message' => array_intersect_key($result['message'], array_flip(['id', 'to', 'status', 'timestamp', 'type']))];
        })($action === 'send'
            ? $forms->send($options['id'], $options['to'], $options['country'], $messageOptions, $options['request-id'])
            : $forms->sendPublication($options['request-id'], $options['to'], $messageOptions)),
    };
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    // Never print credentials or raw auth_paired events.
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
    if ($forms !== null) {
        // Safe projection only; this lookup does not trigger a new authorization.
        try {
            fwrite(STDERR, json_encode(['authentication' => $forms->authenticationStatus()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
        } catch (Throwable) {
            // Keep the original command failure if even the status is unavailable.
        }
    }
    $exitCode = 1;
} finally {
    $client?->disconnect(); // Preserves credentials; does not logout or delete remote forms.
}
exit($exitCode);
