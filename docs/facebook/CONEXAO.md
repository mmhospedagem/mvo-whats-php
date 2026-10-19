# Conectar e guardar a sessão Facebook

Use `FacebookConnection` para autenticar, persistir a sessão e manter a conexão. A aplicação informa um ID local por conta, uma pasta privada para autenticação e outra para os serviços. O mesmo ID não pode ser usado por duas conexões ativas ao mesmo tempo.

## Primeiro login

O exemplo abaixo recebe credenciais do ambiente privado do processo; não coloque senha em código, argumentos CLI ou logs. Ele não publica nem envia mensagens.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use MVO\Facebook\FacebookConnection;

ini_set('zend.exception_ignore_args', '1');
ini_set('display_errors', '0');

$facebook = new FacebookConnection(
    sessionId: 'facebook-demo',
    authDirectory: __DIR__ . '/var/private-auth',
    privateStorageDirectory: __DIR__ . '/var/private-facebook',
);

$email = getenv('MVO_FACEBOOK_EMAIL');
$password = getenv('MVO_FACEBOOK_PASSWORD');
if (!is_string($email) || $email === '' || !is_string($password) || $password === '') {
    throw new RuntimeException('Entrada privada de login ausente.');
}

try {
    try {
        $facebook->login($email, $password);
    } finally {
        sodium_memzero($password);
        unset($email, $password);
    }
    $facebook->connect();

    $deadline = microtime(true) + 30;
    while ($facebook->isConnected() && microtime(true) < $deadline) {
        $facebook->poll(0.25);
        // Consultas e comandos pertencem a este loop, fora dos callbacks.
    }
} finally {
    $facebook->stop();
}
```

`login()` confirma a identidade e salva os cookies da sessão; não conecta automaticamente e não persiste a senha como credencial. O exemplo encerra a tentativa se houver desafio; para continuar 2FA no mesmo processo, use o fluxo abaixo em sua aplicação. O [exemplo CLI session.php](../../packages/facebook/examples/session.php) também diagnostica desafios e encerra sem persistir um login incompleto.

## Restaurar sem e-mail e senha

Nas próximas execuções, preserve ID e pastas e chame somente `connect()`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use MVO\Facebook\FacebookConnection;

$facebook = new FacebookConnection(
    'facebook-demo',
    __DIR__ . '/var/private-auth',
    __DIR__ . '/var/private-facebook',
);

try {
    $facebook->connect();
    $info = $facebook->accountInfo();
    echo $facebook->getState() . PHP_EOL;

    $deadline = microtime(true) + 30;
    while ($facebook->isConnected() && microtime(true) < $deadline) {
        $facebook->poll(0.25);
    }
} finally {
    $facebook->stop();
}
```

`accountInfo()['has_saved_session']` informa armazenamento local; não garante que o servidor ainda aceite os cookies. Se houver expiração ou revogação, autentique novamente a mesma conta. Outra conta exige outro ID local. `stop()` libera o lock e preserva sessão, preferências e comandos; não faz logout remoto.

## Autenticador em duas etapas

Um erro `MessengerException` pode deixar um desafio pendente. A aplicação precisa manter o mesmo objeto e processo, com seu lock, durante a confirmação. O contexto expira em até dez minutos. `loginChallenge()` retorna somente `type`, `expires_at` e `ready`.

O trecho abaixo é uma função de continuação; o código é uma string recebida pela entrada privada da aplicação. Chame-a somente depois de tratar o desafio do login, antes de encerrar a conexão:

```php
use MVO\Facebook\FacebookConnection;

function confirmarAutenticador(FacebookConnection $facebook, string $codigo): void
{
    $challenge = $facebook->loginChallenge();
    if ($challenge === null || $challenge['type'] !== 'two_factor' || !$challenge['ready']) {
        throw new RuntimeException('A tentativa não aceita confirmação por autenticador.');
    }
    try {
        $facebook->confirmLogin($codigo);
    } finally {
        sodium_memzero($codigo);
    }
    $facebook->connect();
}
```

Preserve zeros iniciais. Não repita códigos automaticamente. `ready=false` indica um formato que não forneceu os campos necessários; um CAPTCHA não aceita TOTP. Reinício, expiração e `stop()` descartam o contexto pendente, sem salvar uma sessão incompleta. O teste local do contrato 2FA não comprova todos os desafios em contas reais.

## CAPTCHA e verificação humana

CAPTCHA/checkpoints exigem ação humana no Facebook. A biblioteca não abre janela automaticamente nem resolve o desafio. Uma verificação em outro navegador não necessariamente confirma a tentativa PHP.

Uma integração privada opcional pode usar `exportPrivateLoginChallenge()` para continuar a mesma tentativa num navegador isolado. Esse retorno contém URL e cookies secretos: não o exponha em respostas HTTP, eventos, logs ou ferramentas. Depois da ação humana, `completeBrowserLogin(CookieJar $cookies)` verifica por HTTPS se a identidade coincide com a conta esperada antes de salvar. O bridge pertence à aplicação; veja os detalhes em [autenticação Messenger](../FACEBOOK-MESSENGER.md).

## Loop, callbacks e estados

| Método/estado | Significado |
| --- | --- |
| `connect()` | Inicia ou restaura o transporte e aguarda o sync inicial. |
| `poll($timeoutSeconds = 0.5)` | Mantém streams e cursores e recebe eventos. |
| `on($event, $handler)` | Registra listeners; consultas/comandos ficam no loop externo. |
| `events($after = 0, $limit = 100)` | Consulta eventos persistidos do runtime. |
| `isConnected()` | Consulta o transporte utilizável, não a completude dos serviços. |
| `metadata_connected` | Metadados sincronizados; não equivale a E2EE pronto. |
| `stop()` | Encerra localmente e preserva a sessão. |

Não serialize a conexão nem exponha `WebSession`. `e2ee_ready=false` permanece um limite real: o transporte de mensagens criptografadas não foi implementado; [provisionamento do dispositivo](DISPOSITIVO-E2EE.md) é uma operação explícita separada. Veja [limites e erros](LIMITES.md) e continue com [Páginas](PAGINAS.md).
