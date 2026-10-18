<?php
declare(strict_types=1);

// Captura texto livre ou a opção explícita Cancelar consulta.
// --help não conecta nem abre uma sessão. A consulta/validação do documento pertence à aplicação.
$options = getopt('', ['help', 'to:', 'session:', 'auth-dir:', 'duration:']);
if (isset($options['help'])) {
    echo "Formulário portável de CPF/CNPJ com Cancelar consulta\n"
        ."php examples/native-form.php --to=5511999999999 --session=form-demo\n"
        ."O destino do exemplo é fictício: informe um destinatário autorizado.\n"
        ."--auth-dir=PASTA: pasta privada de autenticação (padrão: var/whatsapp-auth do projeto)\n"
        ."--duration=SEGUNDOS: tempo máximo do worker (padrão: 300)\n"
        ."Use a mesma sessão/pasta para retomada e apenas um worker por sessão.\n"
        ."Casos reais verificados: texto livre no Web e Cancelar consulta no Android.\n"
        ."O ensaio não cobre todas as versões/combinações; veja docs/FORMULARIOS.md.\n";
    exit(0);
}

$to = $options['to'] ?? null;
$session = $options['session'] ?? 'form-demo';
$authDirectory = $options['auth-dir'] ?? dirname(__DIR__).'/var/whatsapp-auth';
$duration = filter_var($options['duration'] ?? '300', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 86400],
]);
if (!is_string($to) || trim($to) === '' || !is_string($session)
    || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/D', $session) !== 1
    || !is_string($authDirectory) || $authDirectory === '' || $duration === false) {
    fwrite(STDERR, "Opções inválidas; use --help.\n");
    exit(2);
}

require dirname(__DIR__).'/vendor/autoload.php';

use MVO\Auth\QrCode;
use MVO\WaClient;

$requestId = 'consulta_'.bin2hex(random_bytes(8));
$fieldId = $requestId.'_cpf_cnpj';
$cancelId = $requestId.'_cancelar';
$pending = [];
$seen = [];
$client = new WaClient(sessionId: $session, authDirectory: $authDirectory);
$client->on('auth_qr', static function (array $event): void {
    fwrite(STDERR, QrCode::terminal($event['qr'])); // Mostre somente ao dono da sessão.
});
$client->on('message', static function (array $event) use (&$pending, $fieldId): void {
    $params = $event['parsedFormResponse']['params'] ?? null;
    if (!$params instanceof stdClass || !property_exists($params, $fieldId)) return;
    $pending[] = ['id' => $event['id'] ?? null, 'value' => $params->{$fieldId}];
});

$sendAttempted = false;
$exitCode = 0;
try {
    $client->connect();
    $deadline = hrtime(true) / 1_000_000_000 + $duration;
    while ($client->isRunning() && hrtime(true) / 1_000_000_000 < $deadline) {
        $client->poll(0.25);
        if ($client->isConnected() && !$sendAttempted) {
            // Marque antes da chamada: uma exceção/timeout pode deixar o envio incerto.
            $sendAttempted = true;
            $client->sendMessage($to, [
                'type' => 'form',
                'platform' => 'portable',
                'text' => 'Digite o CPF/CNPJ ou selecione Cancelar consulta.',
                'cta' => 'Informar documento',
                'steps' => [[
                    'id' => $requestId,
                    'kind' => 'input',
                    'label' => 'Consulta de cadastro',
                    'button' => 'Enviar',
                    'cancel_option' => ['id' => $cancelId, 'title' => 'Cancelar consulta'],
                    'fields' => [[
                        'id' => $fieldId,
                        'label' => 'CPF ou CNPJ',
                        'type' => 'text',
                        'required' => true,
                    ]],
                ]],
            ]);
            echo "Envio retornou; aguardando a resposta. O retorno não comprova exibição.\n";
        }
        while ($response = array_shift($pending)) {
            $id = $response['id'];
            if (is_string($id) && isset($seen[$id])) continue;
            if (is_string($id)) $seen[$id] = true;
            // Em produção, persista a idempotência por sessão/conversa/ID e confira o remetente.
            $value = $response['value'];
            if ($value === $cancelId) {
                echo "Consulta cancelada pela resposta enviada.\n";
                continue;
            }
            if (!is_string($value) || trim($value) === '') {
                echo "Resposta sem texto válido.\n";
                continue;
            }
            // Valide o CPF/CNPJ e a autorização antes de consultar seu serviço no loop externo.
            // Por exemplo, 000.000.000-00 é texto fictício inválido e deve ser rejeitado.
            echo "Texto recebido para validação da aplicação; nenhuma consulta foi executada.\n";
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Falha ('.get_class($error)."); confira o estado/outbox antes de reenviar.\n");
    $exitCode = 1;
} finally {
    $client->stop(); // Preserva as credenciais para a próxima execução; não desvincula.
}
exit($exitCode);
