# Primeira conexão — MVO WHATS PHP

[Início](../README.md) · [Instalação](INSTALLATION.md) · [Exemplos](EXAMPLES.md)

Este guia usa a biblioteca diretamente no PHP. O Composer instala as dependências; o seu worker controla conexão, QR, código, recebimento e envio.

## 1. Defina a sessão e a pasta persistente

```php
require __DIR__.'/vendor/autoload.php';

$client = new MVO\WaClient(
    sessionId: 'cliente-demo',
    authDirectory: __DIR__.'/storage/whatsapp',
);
```

Use um ID estável para cada conta. O ID aceita de 1 a 64 caracteres, começando por letra ou número, com letras, números, ponto, `_` e `-`. Um ID identifica a sessão da aplicação; ele não é o telefone WhatsApp.

O diretório padrão, caso você não informe `authDirectory`, fica dentro da instalação da biblioteca. Em aplicações e deployments, configure uma pasta própria, privada e persistente. A biblioteca cria um subdiretório por sessão com suas credenciais e estado SQLite.

## 2. Escute os eventos antes de conectar

```php
$client->on('connection', static function (array $event): void {
    echo 'Estado: '.$event['state'].PHP_EOL;
});

$client->on('message', static function (array $event): void {
    // Grave/enfileire o evento para sua aplicação; evite consultas neste callback.
    echo ($event['text'] ?? '[conteúdo sem texto]').PHP_EOL;
});
```

Os callbacks são executados enquanto a biblioteca processa o transporte. Faça neles apenas trabalho curto e local, como atualizar estado ou colocar comandos em fila. Envio de mensagens e outras operações que consultam o WhatsApp devem acontecer no loop externo, depois que o callback retornar.

`connect()` inicia o transporte e carrega a sessão; não significa que o WhatsApp já terminou de autenticar. Aguarde `connection.state = connected` ou `isConnected() === true` antes de enviar.

## 3. Conecte e mantenha o loop ativo

```php
try {
    $client->connect();
    while ($client->isRunning()) {
        $client->poll(0.25);
        // Execute comandos da aplicação aqui, quando isConnected() for true.
    }
} finally {
    $client->disconnect();
}
```

`poll()` recebe eventos, processa autenticação, mantém o transporte e executa a recuperação de quedas temporárias. Se sua aplicação precisa apenas escutar, `run()` oferece o loop bloqueante. O processo precisa continuar ativo para concluir o pareamento e receber mensagens.

### Conectar por QR

Registre o listener antes de `connect()`:

```php
use MVO\Auth\QrCode;

$client->on('auth_qr', static function (array $event): void {
    $svg = QrCode::svg($event['qr']);
    file_put_contents(__DIR__.'/storage/qr-demo.svg', $svg, LOCK_EX);
    echo 'QR atualizado; validade de '.$event['expiresIn'].' segundos.'.PHP_EOL;
});
```

O evento `auth_qr` entrega um novo payload em cada rotação. Substitua a imagem anterior no painel e considere a validade `expiresIn`; o payload textual não é uma URL de imagem. `QrCode::svg()` produz SVG e `QrCode::terminal()` produz a representação de terminal.

No celular, abra **WhatsApp → Aparelhos conectados → Conectar um aparelho**. Para um teste pronto com QR que se atualiza no terminal, execute na cópia do repositório:

```sh
php examples/connect.php --session=demo --mode=qr
```

Em um frontend próprio, publique o SVG apenas para o usuário autorizado. Remova-o quando chegar `auth_qr_expired`, `auth_paired` ou quando a sessão deixar o pareamento. Não deixe QR e códigos em um diretório HTTP aberto ou em logs públicos.

### Conectar por código

Depois de `connect()`, uma sessão ainda não registrada pode solicitar um código:

```php
$client->connect();
if (!$client->isRegistered()) {
    $code = $client->requestPairingCode('5511999999999');
    echo 'Código: '.implode('-', str_split($code, 4)).PHP_EOL;
}

while ($client->isRunning()) {
    $client->poll(0.25);
}
```

O número é fictício: substitua pelo telefone da conta que será vinculada, incluindo DDI e DDD, somente dígitos. O método aguarda as referências de pareamento necessárias; não o chame de dentro de um listener.

No celular, selecione **Aparelhos conectados → Conectar um aparelho → Conectar com número de telefone** e digite os oito caracteres. Esse é um código de vínculo, não um SMS de registro. Sua emissão é informada também pelo evento `auth_code`; apenas `connected` confirma uma conexão pronta para uso.

O código tem validade limitada. Acompanhe `auth_code_expired`, `auth_code_refresh` e mudanças de conexão para descartar valores antigos; solicite um novo quando necessário, sem abrir outra conexão para a mesma sessão. `auth_code_progress` informa etapas como `primary_hello_received`, `companion_finish_sent` e `pair_success_validated`.

Exemplo de terminal equivalente:

```sh
php examples/connect.php --session=demo --mode=code --phone=5511999999999
```

Uma sessão já registrada tenta restaurar as credenciais e não precisa solicitar outro código.

## Enviar e receber na mesma conexão

Salve o exemplo completo abaixo como `worker.php` na raiz da sua aplicação. Por padrão, ele mostra o QR no terminal e salva o SVG; com `--mode=code --phone=...`, solicita o código. O argumento opcional `--to` envia uma única mensagem de teste após conectar.

```php
<?php
declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use MVO\Auth\QrCode;
use MVO\WaClient;

$options = getopt('', ['session:', 'mode:', 'phone:', 'to:']);
$session = $options['session'] ?? 'demo';
$mode = $options['mode'] ?? 'qr';
$phone = $options['phone'] ?? null;
$to = $options['to'] ?? null;

if (!is_string($session) || !in_array($mode, ['qr', 'code'], true)
    || ($mode === 'code' && (!is_string($phone) || $phone === ''))
    || ($to !== null && (!is_string($to) || $to === ''))) {
    throw new InvalidArgumentException('Informe sessão, modo e telefone válidos.');
}

$client = new WaClient(
    sessionId: $session,
    authDirectory: __DIR__.'/storage/whatsapp',
);

$client->on('auth_qr', static function (array $event) use ($client, $mode): void {
    if ($mode !== 'qr') return;
    $path = $client->sessionDirectory().'/qr.svg';
    file_put_contents($path, QrCode::svg($event['qr']), LOCK_EX);
    echo QrCode::terminal($event['qr']);
    echo 'QR vigente por '.$event['expiresIn'].'s; SVG: '.$path.PHP_EOL;
});
$client->on('auth_code', static function (array $event): void {
    echo 'Código: '.implode('-', str_split($event['code'], 4)).PHP_EOL;
});
$client->on('connection', static function (array $event): void {
    echo 'Estado: '.$event['state'].PHP_EOL;
});
$client->on('message', static function (array $event): void {
    $summary = [
        'id' => $event['id'] ?? null,
        'chat' => $event['chat'] ?? null,
        'from' => $event['from'] ?? null,
        'fromMe' => $event['fromMe'] ?? false,
        'type' => $event['type'] ?? null,
        'text' => $event['text'] ?? null,
    ];
    echo json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL;
});
$client->on('message_receipt', static function (array $event): void {
    echo 'Recibo: '.($event['id'] ?? '').' '.($event['type'] ?? '').PHP_EOL;
});
$client->on('logged_out', static function (array $event): void {
    echo 'Vínculo revogado; será necessário novo pareamento.'.PHP_EOL;
});

$stopping = false;
$stop = static function () use (&$stopping): void { $stopping = true; };
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, $stop);
    pcntl_signal(SIGTERM, $stop);
}
if (function_exists('sapi_windows_set_ctrl_handler')) {
    sapi_windows_set_ctrl_handler(static function (int $event) use ($stop): bool {
        if (in_array($event, [PHP_WINDOWS_EVENT_CTRL_C, PHP_WINDOWS_EVENT_CTRL_BREAK], true)) {
            $stop();
            return true;
        }
        return false;
    });
}

$attempted = false;
try {
    $client->connect();
    if ($mode === 'code' && !$client->isRegistered()) {
        $client->requestPairingCode($phone);
    }
    while (!$stopping && $client->isRunning()) {
        $client->poll(0.25);
        if (!$stopping && $client->isConnected() && $to !== null && !$attempted) {
            $attempted = true;
            $sent = $client->sendText($to, 'Olá! Mensagem de teste pela MVO WHATS PHP.');
            echo 'Mensagem '.$sent['id'].': '.$sent['status'].PHP_EOL;
        }
    }
} finally {
    $client->disconnect();
}
```

```sh
php worker.php --session=cliente-demo
php worker.php --session=cliente-demo --mode=code --phone=5511999999999
php worker.php --session=cliente-demo --to=5511888888888
```

Os comandos são **alternativas**, não devem executar simultaneamente para a mesma sessão. Substitua os números fictícios antes de enviar. Cada execução com `--to` tenta um novo envio após autenticar; não use esse argumento em um serviço que reinicia automaticamente.

`server_ack` é a confirmação de aceitação do servidor; não comprova entrega ou leitura. Acompanhe `message_receipt` e consulte `getMessageStatus($id)` quando necessário. Se houver timeout de envio, confira o estado antes de repetir para evitar duplicatas.

## Restaurar depois de reiniciar

Inicie o mesmo worker com o mesmo `sessionId` e `authDirectory`. `connect()` lê a identidade e as chaves persistidas e tenta restaurar a conexão. Não existe uma chamada `restore()` separada.

| Operação | Resultado |
| --- | --- |
| `disconnect()` / `stop()` | Encerra o transporte, libera o lock e preserva o vínculo salvo. |
| Queda temporária enquanto o loop continua | A biblioteca tenta reconectar com espera exponencial e jitter. |
| Reinício do PHP ou do servidor | O supervisor da sua aplicação precisa iniciar o worker novamente. |
| `logout()` | Solicita desvincular o aparelho; exige sessão autenticada. |
| Dispositivo removido no celular / `logged_out` | O vínculo não pode ser restaurado; exige novo pareamento explícito. |

Para um vínculo revogado, encerre o worker e arquive os dados antigos antes de preparar uma nova sessão. Evite apagar ou sobrescrever automaticamente credenciais quando ocorrer uma queda. Leia [Conexão](CONEXAO.md) para diagnóstico e recuperação.

## Eventos para sua aplicação

| Evento | Uso |
| --- | --- |
| `connection` | Atualizar estado; `connected` indica transporte autenticado e inicializado. |
| `auth_qr` / `auth_qr_expired` | Exibir/substituir ou invalidar o QR. |
| `auth_code` / `auth_code_expired` / `auth_code_refresh` | Exibir ou descartar o código de vínculo. |
| `auth_code_progress` | Acompanhar a confirmação do pareamento por código. |
| `auth_paired` | Vínculo validado e salvo; o payload inclui credenciais privadas. |
| `message` | Mensagem recebida, com conteúdo, contexto e chave. |
| `message_receipt` | Confirmações de entrega/leitura observadas. |
| `reconnect` | Acompanhar a tentativa e o intervalo de reconexão. |
| `logged_out` | Interromper uso e orientar novo vínculo. |
| `version_updated` / `version_error` | Observar a atualização de versão e eventuais falhas. |

Publique somente campos permitidos de cada evento. `auth_paired` não deve ser serializado integralmente para logs, frontend ou webhook. Mensagens podem conter bytes binários e chaves de mídia; monte um envelope de integração próprio com os campos necessários. Veja [exemplos de eventos JSON](EXAMPLES.md#eventos-para-uma-api).

O recebimento pode ser reemitido após uma queda antes de concluir o callback. Deduplicate efeitos externos pelo par conversa/ID e confirme o processamento na sua própria persistência. O SDK não oferece uma transação única entre SQLite e o banco da sua aplicação.

## Várias contas na mesma instalação

```text
aplicacao/
├── vendor/                    uma instalação da biblioteca
├── worker.php                 um código de worker
└── storage/whatsapp/
    ├── cliente-001/            credenciais e estado isolados
    ├── cliente-002/
    └── cliente-003/
```

```sh
php worker.php --session=cliente-001
php worker.php --session=cliente-002
php worker.php --session=cliente-003
```

Cada comando roda em um processo próprio. Para 60 contas, mantenha uma cópia do código e 60 identidades de sessão. O cliente utiliza I/O bloqueante; um supervisor de workers é a organização recomendada para isolar falhas e manter o loop de cada conta ativo. Compartilhe a instalação, preserve os diretórios e não compartilhe chaves entre sessões.

Dimensione recursos a partir das suas próprias cargas de mensagens, mídias e histórico. A quantidade de sessões não define sozinha o consumo. Consulte a [metodologia de desempenho](DESEMPENHO.md).

## Integrar com uma API

Use rotas HTTP para autenticar o cliente, enfileirar comandos e consultar o estado persistido pelo worker. O worker mantém o `WaClient`, atualiza QR/código/estado e processa os comandos fora dos callbacks. Seu sistema pode entregar os eventos por webhook, SSE, WebSocket ou fila.

Não termine um request PHP-FPM depois de criar o `WaClient` esperando que a conexão permaneça aberta: a vida útil do cliente acompanha o processo. Também não inicie um novo cliente em cada request para uma sessão já usada por outro worker.

O exemplo [session-events.php](../examples/session-events.php) publica eventos selecionados em JSON Lines e aceita `--auth-dir`, `--session` e `--mode`. Ele é um ponto de integração, não uma API HTTP com autenticação pronta. Configure isolamento de clientes, autorização de comandos e tratamento de credenciais na sua aplicação.

Continue em [Exemplos](EXAMPLES.md) ou consulte a [referência de conexão](CONEXAO.md).
