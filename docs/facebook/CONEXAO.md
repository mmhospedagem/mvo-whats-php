# Conectar e guardar a sessão Facebook

Use `FacebookConnection` para autenticar, persistir a sessão e manter a conexão. A aplicação informa um ID local por conta, uma pasta privada para autenticação e outra para os serviços. O mesmo ID não pode ser usado por duas conexões ativas ao mesmo tempo.

## Primeiro login

No checkout em desenvolvimento, use `loginAndroid()` para autenticação nativa sem fallback Web; consulte [o guia Android](AUTENTICACAO-ANDROID.md) para configuração e alcance da evidência. O método separado `login()` continua uma opção Web explícita, fora desse fluxo.

O exemplo abaixo recebe credenciais do ambiente privado do processo; não coloque senha em código, argumentos CLI ou logs. Ele não publica nem envia mensagens.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use MVO\Facebook\FacebookConnection;
use MVO\Messenger\Auth\AndroidApplicationProfile;

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

$applicationId = getenv('MVO_ANDROID_APPLICATION_ID');
$clientValue = getenv('MVO_ANDROID_CLIENT_VALUE');
if (!is_string($applicationId) || !is_string($clientValue)) {
    throw new RuntimeException('Configuração privada do aplicativo ausente.');
}
$nativeProfile = new AndroidApplicationProfile($applicationId, $clientValue);

try {
    try {
        $facebook->loginAndroid($email, $password, $nativeProfile);
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

`loginAndroid()` confirma a identidade nativa por `GET /me` e salva os cookies da sessão; não conecta automaticamente e não persiste a senha como credencial. O armazenamento privado preserva os atributos `HttpOnly` e `SameSite` recebidos, além do escopo e da expiração. O exemplo encerra a tentativa se houver desafio; para continuar 2FA no mesmo processo, use o fluxo abaixo em sua aplicação. O [exemplo CLI session.php](../../packages/facebook/examples/session.php) usa a alternativa Web `login()`, diagnostica desafios e encerra sem persistir login incompleto; não é um cliente Android.

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

Uma resposta 406 reconhecida deixa a tentativa em memória no mesmo objeto/processo e sob o mesmo lock, por até dez minutos. Preserve a projeção segura de `loginChallenge()`; não repita `loginAndroid()` para continuar. Senha, código, primeiro fator e tokens não vão para filas em texto claro, logs ou respostas públicas.

| Gatilho PHP | Responsabilidade no fluxo Android |
| --- | --- |
| `loginChallenge()` | Lê capacidades nativas já disponíveis, sem enviar nada. `methods=[]` não representa um catálogo conhecido. |
| `confirmLogin($code)` | Confirma um código já recebido, somente com `ready=true` e `requires_code=true`. |
| `checkLoginApproval()` | Consulta a aprovação feita pelo dono no aplicativo quando `can_check=true`; `true` só após autenticação e identidade confirmadas. |
| `requestLoginSms()` | Pedido experimental explícito, somente se `can_request_sms=true`; uma tentativa, sem comprovar disponibilidade ou entrega. |
| `refreshLoginMethods()`, `requestLoginMethod($id)` | Não fornecem catálogo/seleção Android. A descoberta Web pertence ao método opcional `login()`. |

```php
// Mesmo objeto que iniciou loginAndroid() e tratou login_two_factor_required.
$challenge = $facebook->loginChallenge();
if (($challenge['ready'] ?? false) && ($challenge['requires_code'] ?? false)) {
    // $code é uma string da entrada privada; preserve zeros iniciais.
    $facebook->confirmLogin($code);
    $facebook->connect();
}
// Alternativa: depois da aprovação pessoal no aplicativo do Facebook,
// consulte checkLoginApproval() somente quando can_check=true.
```

Código e consulta de aprovação podem estar disponíveis juntos. As consultas respeitam intervalo mínimo de cinco segundos e o prazo original; `false` mantém a espera. Resultado incerto bloqueia repetição de código/token. A aprovação real foi demonstrada na implementação anterior de 20/10/2026, seguida de verificação Web de identidade; essa prova não valida o novo verificador `/me`.

`requestLoginSms()` usa somente UID e primeiro fator reais do 406 atual. `can_request_sms` é capacidade local experimental, não anúncio de que a conta oferece SMS. O resultado `request_submitted`, com `experimental=true` e `delivery_confirmed=false`, não autentica nem comprova entrega. `sms_request_state` registra `not_requested`, `submitted`, `rejected` ou `uncertain`. Nenhum reenvio ocorre nessa tentativa; um código recebido continua por `confirmLogin()`. Não há escolha de destinatário ou catálogo nativo inventado. Veja [o contrato Android](AUTENTICACAO-ANDROID.md).

Reinício, expiração e `stop()` descartam o contexto pendente. O exemplo de primeiro login acima encerra se houver desafio; a aplicação real deve conservar o dono vivo enquanto aguarda a entrada privada, antes do `finally` de encerramento.

## CAPTCHA e verificação humana

Na política nativa de `loginAndroid()`, checkpoint 405/CAPTCHA/Google sem continuação nativa estabelecida retorna `login_native_verification_unsupported`. O desafio informa `native_only=true`, `verification_supported=false` e `browser_continuation_available=false`, sem habilitar código, consulta de métodos ou navegador. Não use um SMS recebido para transformar esse estágio em 406.

O método separado `login()` ainda oferece o fluxo Web opcional, com formulários reconhecidos e um handoff humano privado da aplicação. O construtor de baixo nível de `AndroidLoginService` conserva `allowWebCheckpoint: true` como opção legada explícita para integrações/testes; as fachadas `loginAndroid()` não a habilitam. Esses caminhos não fazem parte da política nativa da API consumidora. Não publique URL, cookies ou `flow_id` de handoff. Consulte [autenticação Messenger](../FACEBOOK-MESSENGER.md) para a separação dos contratos.

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

Não serialize a conexão nem exponha `WebSession`. `e2ee_ready=false` permanece um limite real: os componentes experimentais de dispositivo, Noise, Signal e journal não habilitam mensagens sem o verificador remoto ICDC de produção e validação real; [provisionamento do dispositivo](DISPOSITIVO-E2EE.md) é uma operação explícita separada. Veja [limites e erros](LIMITES.md) e continue com [Páginas](PAGINAS.md).
