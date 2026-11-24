# Autenticação Android experimental em PHP

O checkout de desenvolvimento acrescenta `MessengerClient::loginAndroid()` ao mesmo cliente e armazenamento Messenger. O fluxo foi reconstruído a partir do APK Messenger **584.0.0.29.98**, com implementação própria em PHP para requisições, cifra da senha, resposta nativa e continuação por código. Não faz parte das releases Messenger 0.1.1/Facebook 0.1.0.

**Política atual de `loginAndroid()`: autenticação somente por endpoints nativos JSON, sem HTML, WebView ou continuação de navegador.** O caminho usa chave dinâmica, login, confirmação 406 por código/aprovação e verificação independente `GET /me` com o token do usuário somente em memória. Checkpoint sem continuação nativa estabelecida é recusado; não há fallback silencioso para Web. Isso se refere à autenticação: bootstrap, metadados DGW, inbox e serviços de Fanpage continuam usando o protocolo Web existente. `e2ee_ready=false` permanece inalterado.

**Evidência histórica, anterior à política nativa de 21/10/2026:** em uma conta autorizada em 20/10/2026, o login Android foi concluído com código. O operador informou senha e código de verificação somente na entrada privada da aplicação. Naquela implementação, o PHP recebeu a chave, cifrou a senha, tratou a resposta nativa 406, confirmou o código e verificou a identidade Web antes de persistir os cookies. O transporte chegou a `metadata_connected`; um novo worker restaurou a sessão sem outra senha ou código. A descoberta retornou sete Fanpages. Isso não garante todas as contas, métodos de verificação, operações de Página ou mensagens E2EE.

Nessa mesma sessão, o snapshot de uma Página autorizada retornou oito conversas, sete nomes e oito URLs de fotos. A consulta de uma conversa carregada retornou uma mensagem com remetente identificado e anexo projetado como `file`; não houve download do arquivo ou envio de mensagens. A leitura continua usando o conector Web existente e cobre somente o documento carregado, sem comprovar histórico completo ou recebimento contínuo.

| Evidência | Alcance |
| --- | --- |
| Java e JNI do APK 584, com hashes verificados | Rotas, método HTTP efetivo, campos, assinatura de requisição, envelope CryptoPub e interpretação das respostas. |
| Testes PHP com material sintético | Cifra/decifra independente, metadados da chave, parsers, perfil de aplicativo e identidade local persistente. |
| Laboratório HTTPS em loopback, com CA própria | Codificação do formulário, TLS, bloqueio de redirects/cookies e limites de resposta do transporte cURL. |
| Consulta de chave ao servidor pelo SDK PHP, sem conta | Resposta com chave RSA válida e TTL; não tentou `/auth/login` ou uma verificação 2FA. |
| Inicialização do APK em VM isolada, offline e sem conta | Extração das bibliotecas nativas para análise; não foi executada uma cifra JNI com vetor sintético. |
| Autenticação Android real de 20/10/2026, implementação anterior | Código e aprovação 406, seguidos de identidade Web/cookies confirmados; DGW/metadados e restauração verificados. Essa observação não valida o novo `GET /me`. Não houve envio de mensagens. |
| Política nativa de 21/10/2026 | Rotas e autorização derivadas do APK, com fixtures PHP e HTTPS loopback. O parser JSON de identidade e o pedido experimental de SMS ainda precisam de validação real própria. |

A VM e as ferramentas de pesquisa não são dependências da biblioteca. O runtime do SDK continua em PHP 8.2+ com as extensões declaradas no [Composer Messenger](../../packages/messenger/composer.json).

## Perfil de aplicativo privado

A assinatura pública é:

```text
MessengerClient::loginAndroid(
    string $email,
    string $password,
    AndroidLoginProfile $profile,
    ?AndroidLoginTransport $transport = null,
): void;
```

`FacebookConnection` oferece a mesma assinatura `loginAndroid()`. `AndroidLoginProfile` pertence a `MVO\Messenger\Auth`; `AndroidLoginTransport`, a `MVO\Messenger\Transport`. O transporte cURL é criado quando o último argumento é omitido. Uma implementação injetada do transporte é útil para testes e precisa preservar o contrato de destinos, TLS e privacidade.

O SDK **não inclui credenciais de aplicativo Meta, valores cliente, chaves de atestação ou identificadores de aplicativo padrão**. O operador fornece uma configuração privada compatível com o protocolo. Essa configuração não é uma chave de integração do consumidor nem uma credencial pública adicional da conexão.

`AndroidApplicationProfile` implementa `AndroidLoginProfile` e recebe:

| Argumento | Uso |
| --- | --- |
| `applicationId`, `clientValue` | Configuração privada de autorização. A aceitação pelo servidor precisa ser verificada; o SDK não fornece valores prontos. |
| `locale = 'pt_BR'`, `countryCode = null` | Campos de idioma/país, com validação local de formato. |
| `endpoint = 'https://graph.facebook.com'` | Somente essa origem ou `https://b-graph.facebook.com`. |
| `version = 2` | Versão externa do envelope `#PWD_MSGR`; aceita 1 ou 2, conforme configuração explicitamente fornecida. |
| `flow = 'CONTROLLER_INITIALIZATION'` | Campo da consulta da chave de senha. |

Exemplo de configuração por ambiente privado, sem valores Meta embutidos:

```php
use MVO\Messenger\Auth\AndroidApplicationProfile;
use MVO\Messenger\MessengerClient;

$applicationId = getenv('MVO_ANDROID_APPLICATION_ID');
$clientValue = getenv('MVO_ANDROID_CLIENT_VALUE');
if (!is_string($applicationId) || !is_string($clientValue)) {
    throw new RuntimeException('Configuração privada do aplicativo ausente.');
}

$profile = new AndroidApplicationProfile(
    applicationId: $applicationId,
    clientValue: $clientValue,
    locale: 'pt_BR',
    countryCode: 'BR',
);

$client = new MessengerClient(
    sessionId: 'facebook-demo',
    authDirectory: __DIR__ . '/var/private-auth',
);

// $email e $password vêm da entrada privada da sua aplicação.
// Esta sequência só chega a connect() se o login terminar e salvar a sessão.
$client->loginAndroid($email, $password, $profile);
$client->connect();
// Mantenha poll() no loop externo. Ao encerrar, chame stop().
```

O exemplo mostra o caminho sem desafio, não um worker completo. Trate a verificação pendente como descrito abaixo antes de encerrar o processo. Mantenha configuração, senha, código e respostas privadas fora de código público, argumentos CLI, logs, eventos, ferramentas e contextos de LLMs.

Uma implementação própria de `AndroidLoginProfile` fornece `origin()`, `passwordVersion()`, `keyFetchFlow()` e `authorize($method, $path, $form)`. O último retorna `['form' => ..., 'headers' => ...]`: pode acrescentar a autorização do aplicativo, mas não trocar conta, dispositivo, senha cifrada ou contexto 2FA da tentativa. O serviço recusa essas alterações.

O perfil concreto aplica a assinatura legada observada aos campos de `/auth/login`. Essa assinatura de requisição não é a cifra da senha. Não serialize o perfil nem publique o formulário autorizado; ambos contêm material privado.

## Sequência implementada

1. O cliente adquire o lock da mesma sessão antes de gerar identidade local, consultar o perfil ou iniciar I/O. Uma sessão não troca de conta; outra conta exige outro ID.
2. `AndroidDeviceIdentity` cria IDs aleatórios de sessão para `device_id` e `adid`, persistidos no armazenamento privado. Eles não comprovam hardware Android nem atestação. Um `machine_id` retornado no contexto reconhecido é mantido como valor opaco.
3. A chave dinâmica da senha é solicitada ao servidor, com validação de ID, chave pública e tempo de vida. Não há chave padrão nem fallback para senha em texto puro.
4. `AndroidPasswordEncryption` constrói `#PWD_MSGR`, com AES-256-GCM, chave AES protegida por RSA PKCS#1 v1.5, IV de 12 bytes, tag de 16 bytes e timestamp como AAD. A senha usa UTF-8 sem normalização; a versão binária interna é 1, independente da versão externa 1/2.
5. `/auth/login` recebe a senha cifrada e a solicitação explícita de cookies de sessão. O parser exige resultado completo, cookies compatíveis e identidade consistente.
6. `GET /me`, na mesma origem nativa fixa, verifica a identidade com `Authorization: OAuth <token do usuário>` e friendly name `getMeUser`, sem formulário ou query. O ID JSON precisa coincidir com o UID do resultado nativo, com o `c_user` dos cookies e com a conta já vinculada, quando houver. Só depois os cookies são salvos no mesmo armazenamento. O token de usuário não é persistido, exposto ou enviado ao perfil de autorização do aplicativo.

Os métodos HTTP efetivos são:

| Operação | Método no transporte | Caminho | Particularidade |
| --- | --- | --- | --- |
| Obter chave da senha | **POST** | `/pwd_key_fetch` | `method=GET` é um campo do formulário POST, junto de `device_id`, `version` e `flow`. Não é um GET HTTP. |
| Enviar senha ou continuar 2FA | **POST** | `/auth/login` | Formulário nativo, com `credentials_type` correspondente à etapa. |
| Consultar aprovação nativa 406 | **GET** | `/check_approved_machine` | Somente UID e máquina reais da tentativa, com a autorização privada do perfil. |
| Pedir SMS experimental explicitamente | **POST** | `/{UID}/twofacsms` | Primeiro fator do 406 corrente, uma tentativa, friendly name `login_approval_resend_code`; não confirma entrega. |
| Verificar identidade nativa | **GET** | `/me` | Sem query/corpo; autorização pelo token de usuário do resultado, não pelo perfil de aplicativo. |

`AndroidLoginCurlTransport` admite apenas esses caminhos nas duas origens HTTPS fixas, sem query fornecida pelo chamador. Usa formulário RFC3986, valida os cabeçalhos permitidos, não segue redirects, não mantém cookie jar e não repete requisições. Os limites locais são 120 segundos, 2 MiB de corpo descomprimido e 64 KiB de cabeçalhos. O User-Agent padrão identifica `MVO-Messenger-PHP/0.1`; não atesta um aparelho.

Um HTTP 200 isolado, uma chave de senha retornada ou um access token nativo não confirmam a sessão. O verificador exige resposta JSON reconhecida com `id` decimal positivo consistente, rejeita erros, formato desconhecido e conta divergente, e preserva o token somente em RAM. A requisição `GET /me`/`getMeUser` tem evidência estática no APK; o schema de `id` exigido pelo SDK é validado por fixtures desta revisão e ainda não foi demonstrado numa nova autenticação real. O `BSl` do solicitador observado no APK não prova esse schema: ele retorna `null`.

`loginAndroid()` não inicia DGW nem registra dispositivo E2EE. Após a sessão salva, `connect()` ainda faz bootstrap Web e conecta DGW; isso não transforma o inbox ou todos os serviços Facebook em protocolo Android.

## Código 406 e confirmação no mesmo dono

Uma resposta nativa reconhecida de código **406** pode fornecer `uid`, `login_first_factor` e `machine_id`. Esses campos são privados. O serviço conserva o contexto em memória por até dez minutos e retorna `login_two_factor_required`; o cliente permanece em `awaiting_two_factor` com o lock.

Na aplicação, intercepte esse motivo e mantenha **o mesmo objeto e processo** aguardando a ação do dono. Não chame `stop()` nem um novo `loginAndroid()` entre a solicitação e a confirmação. Consulte somente a projeção segura:

```php
$challenge = $client->loginChallenge();
if ($challenge !== null
    && $challenge['type'] === 'two_factor'
    && $challenge['ready']
    && $challenge['requires_code']) {
    // $code é recebido pela entrada privada da aplicação, preservando zeros iniciais.
    $client->confirmLogin($code);
    // Só após o retorno bem-sucedido e a verificação independente da identidade:
    $client->connect();
}
```

`confirmLogin()` envia uma continuação nativa com `credentials_type=two_factor`, o código e o primeiro fator da tentativa. Não inicia outro login nem escolhe o meio de entrega do código. O parser exige um contexto 406 utilizável; um erro 406 incompleto retorna `login_native_verification_unavailable`.

Não existe catálogo nativo de métodos implementado: `methods=[]`, `selected_method=null` e `can_refresh=false`; `refreshLoginMethods()` e `requestLoginMethod()` não inventam uma lista Android. Isso não impede confirmar um código já recebido quando `ready=true` e `requires_code=true`. O pedido específico de SMS abaixo é experimental e separado dessa descoberta.

### Pedido experimental de SMS no fluxo 406

`MessengerClient` e `FacebookConnection` oferecem `requestLoginSms(): array`. Execute somente por ação explícita do dono e quando `loginChallenge()['can_request_sms']` for `true`, no mesmo processo e contexto 406 que iniciou o login:

```php
$challenge = $client->loginChallenge();
if (($challenge['can_request_sms'] ?? false) === true) {
    // Somente após o dono escolher pedir SMS; nunca ao carregar a página.
    $result = $client->requestLoginSms();
    // request_submitted não significa entrega nem autenticação.
}
```

O serviço envia `POST /{UID}/twofacsms` com o UID decimal e o `first_factor` reais dessa tentativa. O resultado reconhecido é `['outcome'=>'request_submitted', 'experimental'=>true, 'delivery_confirmed'=>false]`. `sms_request_state` passa de `not_requested` para `submitted`, `rejected` ou `uncertain`; o pedido não é repetido nessa tentativa, nem manualmente após resultado incerto. Ele não autentica, não consome o código e não reinicia o login. Se o dono receber um código, use o mesmo `confirmLogin()` e preserve zeros iniciais.

`can_request_sms` indica que há contexto local para fazer esse experimento, **não que o Facebook oferece SMS à conta**. Não há descoberta comprovada de disponibilidade ou destino. O solicitador do APK verifica erros e não fornece confirmação de entrega; o contrato do SDK tem testes sintéticos. Em 21/10/2026, uma tentativa nativa autorizada chegou a 406 e um único pedido explícito de SMS recebeu HTTP 400, erro nativo 3. O estado ficou `rejected`, sem reenvio e sem entrega comprovada. Isso é rejeição real do pedido, não autenticação ou confirmação de disponibilidade; a causa exata não foi estabelecida. Não substituir o contexto nem acrescentar parâmetros especulativos para repetir a chamada. Não use URL/`flow_id` de um 405, token de aprovação ou destinatário arbitrário em lugar do UID/primeiro fator.

### Aprovação pelo aplicativo no fluxo 406

Quando o próprio `406` também fornece `auth_token`, UID e uma máquina real utilizável, `can_check=true` habilita a consulta de aprovação. O token é distinto de `login_first_factor`: permanece somente em memória, não aparece no desafio público e não é persistido junto aos cookies ou ao dispositivo. Não é necessário conservar a senha original.

```php
// Depois que o dono aprovar a notificação no aplicativo do Facebook:
$challenge = $client->loginChallenge();
if (($challenge['can_check'] ?? false) === true) {
    if ($client->checkLoginApproval()) {
        $client->connect(); // Identidade verificada e sessão salva.
    }
    // false mantém a espera; consulte novamente apenas quando can_check=true.
}
```

O serviço faz `GET /check_approved_machine` com a dupla real UID/máquina. Somente `approved=true` permite uma única troca nativa com `credentials_type=transient_token`, UID e o `auth_token` daquele `406`. A resposta final ainda precisa fornecer cookies completos e passar pela verificação nativa independente `GET /me` da mesma identidade. Aprovar uma notificação, isoladamente, não equivale a uma sessão salva.

As consultas respeitam intervalo mínimo de cinco segundos e o prazo original da tentativa. Resultado incerto da troca final bloqueia repetição do token e envio de código. Código e consulta de aprovação podem estar disponíveis juntos; a aplicação apresenta as capacidades atuais sem inventar métodos ou selecionar uma automaticamente. Um `406` sem token de aprovação continua podendo oferecer somente código. Um token de aprovação sem primeiro fator de código pode oferecer apenas `can_check=true`, com `ready=false` e `requires_code=false`.

A cadeia de aprovação tem evidência estática do APK e testes PHP com transportes sintéticos. Na implementação anterior, em 20/10/2026, uma nova tentativa em uma conta autorizada retornou 406; após o dono aprovar no aplicativo, a consulta nativa retornou `approved=true`, a autenticação `transient_token` retornou HTTP 200 e o SDK confirmou a identidade Web por HTTPS, salvou a sessão e conectou os metadados. Essa evidência antecede a troca do verificador por `GET /me`, cuja validação real permanece pendente. Outro worker restaurou essa sessão sem senha, código ou navegador. As primeiras consultas ainda retornaram `false`: a notificação e a confirmação do protocolo são resultados distintos. Essa evidência não garante outras contas ou métodos e não se aplica ao checkpoint `405`, CAPTCHA ou Google.

Antes do I/O de confirmação, o serviço marca o resultado como potencialmente incerto. Se uma falha impedir saber se o servidor consumiu o código, a projeção mantém `outcome_uncertain=true` e `ready=false`; uma nova tentativa de confirmação é bloqueada com `login_code_outcome_uncertain`. Não reenvie automaticamente código, senha ou solicitação. Se o código já foi aceito e a verificação de identidade posterior falhar, o primeiro fator consumido também não é restaurado para replay.

## Checkpoint, expiração e erros

| Motivo | Conduta |
| --- | --- |
| `login_native_profile_invalid` / `login_native_profile_rejected` | Corrija a configuração privada; uma consulta de chave rejeitada não prova senha incorreta. |
| `login_native_key_invalid` / `login_native_key_expired` | Não use chave fixa nem senha sem cifra. |
| `login_two_factor_required` | Preserve dono, processo e prazo da tentativa; consulte as capacidades nativas atuais. |
| `login_code_outcome_uncertain` / `login_approval_outcome_uncertain` | Não repita o código, token ou senha de resultado desconhecido. |
| `login_sms_already_requested` / `login_sms_outcome_uncertain` | Não repita o pedido de SMS nessa tentativa. |
| `login_native_sms_rejected` | O servidor não aceitou o pedido experimental; não declare envio ou autenticação. |
| `login_native_identity_unconfirmed` | O JSON/estado de `/me` não confirmou o UID; não salve a sessão nem repita uma confirmação consumida. |
| `login_native_verification_unsupported` | Checkpoint sem continuação nativa estabelecida, incluindo o 405. Fallback Web desabilitado. |
| `login_native_browser_unavailable` | A política nativa não permite exportar/concluir a tentativa por navegador. |
| `login_identity_mismatch` | Resultado nativo, cookies, `/me` e conta vinculada não coincidem; não troque a conta do armazenamento. |
| `login_challenge_expired` | O contexto em RAM foi descartado; não reutilize código ou primeiro fator. |

Por padrão, um **405** conserva um desafio `checkpoint` com `native_only=true`, `verification_supported=false`, `continuation=unsupported_native_checkpoint` e `browser_continuation_available=false`. `ready`, `can_refresh` e `can_check` são `false`, com `methods=[]`. Nenhuma página HTML é consultada, navegador aberto, SMS disparado ou aprovação fabricada para transformá-lo em 406. O mesmo limite vale para CAPTCHA/Google sem continuação nativa estabelecida.

`stop()`, expiração ou reinício descartam a verificação pendente. Os IDs privados do dispositivo podem permanecer salvos; isso não restaura um código, token temporário ou primeiro fator consumido. O prazo original de até dez minutos não é renovado por consulta ou seleção.

## Consultar a etapa HTTP de um checkpoint 405

**Compatibilidade legada isolada, desabilitada em `loginAndroid()`.** O serviço de baixo nível `AndroidLoginService` conserva o argumento explícito de construtor `allowWebCheckpoint: true` para integrações legadas/testes que escolham permitir Web. Ele não é habilitado pela fachada `MessengerClient::loginAndroid()` ou `FacebookConnection::loginAndroid()` e não é o fluxo da API consumidora com política nativa.

Somente nessa opção legada, uma URL/`flow_id` realmente recebidos podem alimentar a consulta HTTP `http_checkpoint` e um handoff privado de navegador. Continuam obrigatórios prazo original, UID vinculado, contrato 2FA reconhecido e identidade consistente. Essa opção teve testes locais; conclusão real do checkpoint 405 não foi demonstrada. Não use esse mecanismo como fallback automático da autenticação nativa.

O método separado `login()` continua podendo usar `WebLoginService` por escolha explícita da aplicação. Seus formulários, catálogo Web de métodos e bridge humano não se aplicam à política de `loginAndroid()` descrita neste guia.

## Retomar a sessão e utilizar Fanpages

Depois que a sessão for confirmada e salva, restaure com **o mesmo `sessionId` e diretório**:

```php
$client = new MessengerClient('facebook-demo', __DIR__ . '/var/private-auth');
try {
    $client->connect();
    $until = microtime(true) + 30;
    while ($client->isConnected() && microtime(true) < $until) {
        $client->poll(0.25);
        // Consultas/comandos da aplicação pertencem a este loop, fora dos callbacks.
    }
} finally {
    $client->stop();
}
```

A restauração não precisa repetir senha nem o perfil Android. Se a sessão expirar, uma nova autenticação continua pertencendo à mesma conta e ao mesmo dono de lock.

O login Android não implementa outro conector de Fanpage. Os [serviços Facebook existentes](../FACEBOOK.md#compartilhar-um-cliente-messenger-existente) continuam utilizando o `WebSession` privado do mesmo `MessengerClient`, com os recursos e limites Web já documentados. A fachada oferece `FacebookConnection::loginAndroid()` explicitamente, com autenticação nativa sem fallback Web; `FacebookConnection::login()` permanece uma opção Web separada. O bootstrap, inbox e os serviços de Fanpage continuam Web/DGW e `e2ee_ready=false`.

**`e2ee_ready` permanece `false`.** Sessão salva, autenticação nativa ou metadados conectados não demonstram envio pessoal criptografado, entrega, leitura ou acesso ao inbox de uma Página. Consulte também os [limites](LIMITES.md) e o [contrato Messenger](../FACEBOOK-MESSENGER.md).
