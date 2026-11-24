# MVO Messenger PHP — experimental

Cliente PHP independente para o protocolo Web do **Facebook Messenger**. A implementação usa HTTPS, WebSocket DGW e Lightspeed, sem a API oficial da Meta e sem runtime Node.js ou Go. O pacote é `mmhospedagem/mvo-messenger-php`, com namespace `MVO\Messenger`.

**O envio integrado de mensagens pessoais E2EE ainda não está habilitado.** Os componentes locais e o controlador experimental existem, mas o verificador remoto ICDC permanece pendente. `metadata_connected` indica que o cliente concluiu a conexão e a sincronização inicial de metadados; `accountInfo()['e2ee_ready']` permanece `false`. Threads criptografadas são bloqueadas antes do envio, sem fallback para texto sem criptografia. Em **18/10/2026**, a autenticação com continuação humana no navegador foi confirmada pelo PHP e salva. A restauração dessa sessão atingiu `metadata_connected`, com metadados de **13 conversas** e WebSocket ativo durante **12 segundos** de observação. **Nenhuma mensagem foi enviada nesse teste**; envio/recebimento, entrega, leitura e estabilidade prolongada permanecem sem validação real.

Este pacote é distribuído na série experimental **0.1**. O SDK WhatsApp continua separado, na série pública **1.0.3**, com seu `MVO\WaClient`, pacote e armazenamento próprios. Instalar o SDK WhatsApp não instala Messenger.

**As extensões desta revisão estão na branch de desenvolvimento, ainda sem release. Messenger 0.1.1 e Facebook 0.1.0 publicados não incluem o provisionamento de dispositivo, transporte Page Lightspeed, upload e os novos serviços descritos abaixo.** Para desenvolvê-los, instale as fontes locais de ambos os pacotes via Composer `path`. Um `composer require ...:^0.1` pode resolver uma release anterior a esses contratos.

## Instalação

Use PHP **8.2+ de 64 bits**, com `curl`, `json`, `openssl`, `sodium`, `pdo` e `pdo_sqlite`, conforme [composer.json](composer.json). A extensão `gmp` é adicional para o provisionamento e componentes experimentais E2EE (certificados/Signal); login, metadados e os serviços Facebook não precisam dela. Na aplicação consumidora:

```sh
composer require "mmhospedagem/mvo-messenger-php:^0.1"
```

Carregue `vendor/autoload.php`. [Packagist](https://packagist.org/packages/mmhospedagem/mvo-messenger-php) · [Repositório de distribuição](https://github.com/mmhospedagem/mvo-messenger-php) · [Documentação Facebook](https://mmhospedagem.github.io/mvo-whats-php/facebook/). Para login e serviços Facebook pela fachada `FacebookConnection`, instale `mmhospedagem/mvo-facebook-php:^0.1`, que inclui este runtime.

### Instalação local para desenvolver no monorepo

Use PHP **8.2+ de 64 bits**, extensões `curl`, `json`, `openssl`, `sodium`, `pdo` e `pdo_sqlite`. No `composer.json` de uma aplicação consumidora:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../mvo-whats-php/packages/messenger",
      "options": {
        "symlink": true,
        "versions": {"mmhospedagem/mvo-messenger-php": "0.1.x-dev"}
      }
    }
  ],
  "require": {"mmhospedagem/mvo-messenger-php": "0.1.x-dev"}
}
```

Ajuste o caminho à localização deste checkout, execute `composer install` na aplicação e carregue o `vendor/autoload.php` dela. O repositório `path` aponta para o checkout de desenvolvimento, em vez de instalar um release.

## Sessão e primeiro exemplo

No checkout em desenvolvimento, o fluxo sem Web usa `loginAndroid()` com e-mail/senha da própria conta recebidos pela entrada privada e um perfil de aplicativo fornecido pelo operador. A autenticação usa endpoints nativos JSON; não descobre formulário HTML nem abre navegador. O SDK não inclui a configuração Meta do aplicativo.

```php
// E-mail e senha vêm da entrada privada, sem hardcode, logs ou argumentos CLI.
// Execute somente na primeira autenticação ou renovação, com a conexão parada.
/** @var \MVO\Messenger\Auth\AndroidLoginProfile $nativeProfile Configuração privada. */
$client->loginAndroid($email, $password, $nativeProfile);
$client->connect();
```

A sequência acima mostra o caso sem desafio. No406, trate a exceção e conserve o mesmo objeto/processo/lock enquanto recebe código para `confirmLogin()` ou consulta uma aprovação pessoal com `checkLoginApproval()`, conforme as capacidades de `loginChallenge()`. Não encerre o dono antes de continuar. O prazo original é de até dez minutos; expiração, reinício e `stop()` descartam a tentativa. Códigos e tokens de resultado incerto não são repetidos.

`GET /me` confirma o UID por JSON antes de salvar cookies, com token de usuário somente em RAM. Esse novo verificador tem fixtures e validação real própria pendente. O405 retorna `login_native_verification_unsupported`, com browser indisponível. O pedido experimental `requestLoginSms()` não é catálogo nem prova de entrega; veja o contrato abaixo.

A conta não muda dentro do mesmo ID. Retome uma sessão válida somente com `connect()`, mesmo ID/diretório, sem repetir senha/perfil. O transporte posterior ainda faz bootstrap Web e DGW; a autenticação nativa não converte inbox ou Fanpages e não habilita E2EE. O método separado `login()` conserva a alternativa Web explícita, sem fallback automático por `loginAndroid()`.

### Autenticação Android em desenvolvimento

`loginAndroid()` autentica somente por endpoints nativos JSON: chave dinâmica, `/auth/login`, código/aprovação 406 e `GET /me` com autorização pelo token do usuário mantido somente em RAM. Não consulta HTML nem abre ou exporta navegador durante a autenticação. O 405 retorna `login_native_verification_unsupported`, com `browser_continuation_available=false`; não existe conversão para 406. A nova verificação JSON de identidade tem fixtures e ainda precisa de validação real própria. Bootstrap, metadados, inbox e serviços de Fanpage continuam no protocolo Web/DGW, com `e2ee_ready=false`.

`loginAndroid($email, $password, AndroidLoginProfile $profile, ?AndroidLoginTransport $transport = null)` compartilha ID, lock e armazenamento. O SDK não inclui configuração Meta de aplicativo. O [guia Android](../../docs/facebook/AUTENTICACAO-ANDROID.md) contém a configuração privada, as rotas, os estados e os limites. Código recebido continua por `confirmLogin()`; aprovação feita pelo dono pode ser consultada com `checkLoginApproval()` quando `can_check=true`.

`requestLoginSms()` é um pedido nativo experimental e explícito para o 406 corrente, uma única tentativa: `can_request_sms=true` não comprova oferta de SMS pela conta; `delivery_confirmed=false` permanece. Não há catálogo nativo de meios (`methods=[]`). A verificação `GET /me` e esse pedido têm testes sintéticos; sua validação real própria ainda está pendente. Código/aprovação e restauração em uma conta foram demonstrados em 20/10/2026 pela implementação anterior, cuja verificação de identidade ainda era Web.

O método separado `login()` usa `WebLoginService` como alternativa opcional. O construtor interno `AndroidLoginService(..., allowWebCheckpoint: true)` mantém compatibilidade legada explícita; `loginAndroid()` não habilita HTTP de checkpoint nem navegador. Nenhum desses caminhos deve ser selecionado automaticamente como fallback da política nativa.

### Alternativa avançada: importar uma sessão existente

`importCookies()` permanece disponível para um arquivo privado explícito de cookies. O importador aceita uma lista de objetos com `name`, `value`, `domain`, `path`, `secure`, `expires`/`expirationDate` e `hostOnly`, ou um objeto nome/valor. Mantenha o arquivo fora da pasta pública HTTP e do Git. Não passe valores de cookies ou senha em argumentos da CLI, mensagens ou logs.

Os comandos abaixo partem da raiz do monorepo `mvo-whats-php`. No repositório de distribuição `mvo-messenger-php`, execute `composer install` e use `examples/...` no lugar de `packages/messenger/examples/...`.

O exemplo CLI abaixo restaura/importa uma sessão; o login por e-mail/senha usa o método PHP `login()`:

```sh
php packages/messenger/examples/connect.php --help
php packages/messenger/examples/connect.php --session=demo --auth-dir=/caminho/privado/mvo-auth --cookies-file=/caminho/privado/facebook-cookies.json --seconds=30
```

Para restaurar, use o mesmo `--session` e `--auth-dir` sem `--cookies-file`. O exemplo faz conexão, mantém o loop pelo intervalo escolhido e imprime projeções de diagnóstico sem conteúdo de mensagens. **Ele não envia mensagens.** `--cookies-file` recebe apenas o caminho local; nunca passe os valores dos cookies na linha de comando.

## Contrato PHP

As operações de autenticação dependem da entrada escolhida: `loginAndroid()` usa somente endpoints nativos e não oferece catálogo Web; `login()` conserva a alternativa Web opcional. As duas compartilham o dono da sessão, sem fallback automático entre elas.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use MVO\Messenger\MessengerClient;

$client = new MessengerClient(
    sessionId: 'cliente-demo',
    authDirectory: __DIR__ . '/var/private-auth',
);

// Na primeira autenticação/renovação, com entrada privada da aplicação:
// $client->login($email, $password);
// Para restaurar uma sessão válida, não repita o login.

$pending = [];
$client->on('message', static function (array $event) use (&$pending): void {
    $pending[] = $event;
});

try {
    $client->connect();
    $until = microtime(true) + 30;
    while ($client->isConnected() && microtime(true) < $until) {
        $client->poll(0.25);
        foreach ($pending as $event) {
            // Aplique ações da sua aplicação aqui, com idempotência.
            // Não execute comandos dentro do callback do listener.
        }
        $pending = [];
    }
} finally {
    $client->stop();
}
```

Mantenha `var/private-auth` fora do diretório HTTP público. Cada sessão ativa tem um dono exclusivo do lock; `poll()` mantém transporte e sincronização. `stop()` fecha a conexão local e libera o lock, preservando a sessão salva. Não há `logout()` Messenger implementado neste pacote.

| Método | Comportamento atual |
| --- | --- |
| `login(string $email, string $password): void` | Autentica pelo formulário Web atual e salva somente a sessão confirmada; não inicia DGW. |
| `loginAndroid(string $email, string $password, AndroidLoginProfile $profile, ?AndroidLoginTransport $transport = null): void` | Login nativo sem HTML/navegador; código/aprovação 406 e verificação JSON `GET /me` antes de salvar. O novo verificador tem fixtures, com validação real pendente. |
| `requestLoginSms(): array` | Pedido experimental explícito do 406 corrente, uma tentativa; não confirma entrega, disponibilidade ou autenticação. |
| `loginChallenge(): ?array` | Projeta `type`, `expires_at`, `ready`, `methods`, `selected_method`, `requires_code` e `can_check`, sem tokens; `null` quando ausente/expirado. |
| `refreshLoginMethods(): array` | Consulta as alternativas reconhecidas oferecidas na tentativa atual, sem solicitar código. |
| `requestLoginMethod(string $methodId): array` | Solicita a alternativa escolhida pelo dono, usando um ID atual; pode provocar envio de SMS/e-mail ou pedido de aprovação. |
| `checkLoginApproval(): bool` | Consulta a aprovação em outro aplicativo quando `can_check=true`; `false` pendente, `true` identidade verificada e sessão salva. Não inicia DGW. |
| `confirmLogin(string $code): void` | Confirma o código do método selecionado quando `requires_code=true` e `ready=true`; verifica a sessão antes de salvá-la. Não inicia DGW. |
| `exportPrivateLoginChallenge(): array` | Handoff privado da tentativa pendente para um browser bridge local; contém segredos e nunca pode ser resposta pública. |
| `completeBrowserLogin(CookieJar $cookies): void` | Verifica a identidade por HTTPS antes de salvar os cookies vindos do contexto isolado. Exige desafio pendente e lock; não inicia DGW. |
| `importCookies(CookieJar $cookies): void` | Importa/renova a mesma conta, com conexão parada. |
| `connect(): void` | Restaura cookies, valida identidade, abre DGW e aguarda sync inicial. |
| `poll(float $timeoutSeconds = 0.5): void` | Processa eventos e continuação dos cursores. |
| `isConnected(): bool` / `getState(): string` | Consulta o estado de metadados e transporte. |
| `accountInfo(): array` | Retorna uma projeção pública; `e2ee_ready` é `false`. |
| `threads(): array` | Consulta o inventário local sincronizado. |
| `refreshThreads(): array` | Solicita atualização de metadados pela conexão ativa. |
| `events(int $after = 0, int $limit = 100): array` | Consulta eventos persistidos, por cursor local. |
| `sendText(string $threadId, string $text): array` | Aceita apenas thread conhecida, com tipo de envio sem criptografia explicitamente suportado. |
| `outgoing(string $id): ?array` | Consulta o estado local usando o `offline_id` retornado. |
| `webSession(): WebSession` | Capacidade privada do mesmo dono/lock para o pacote Facebook. A sessão nativa oferece bootstrap de foto, multipart e grupo DGW Page separado; não serializar nem publicar seus campos. |
| `e2eeDevice(?RegistrationTransport $transport = null): DeviceManager` | Obtém o componente explícito de provisionamento, sem registrar automaticamente nem habilitar mensagens E2EE. |
| `on(string $event, callable $handler): static` | Registra listener. Comandos pertencem ao loop externo. |
| `stop(): void` | Encerra localmente e libera o dono da sessão. |

As capabilities atuais são `session.email_password`, `session.cookie_import`, `connection.persistent`, `threads.metadata`, `message.text.unencrypted` e `events.unencrypted`. Elas descrevem o contrato implementado; a validação real limitada está discriminada abaixo. `login()` confirma a autenticação da sessão; `connect()` depois prepara o transporte e o sync de metadados. Nenhum deles registra o dispositivo E2EE.

### Enviar para uma thread confirmada

Depois de conectar e **fora dos callbacks**, selecione um ID exato retornado por `threads()` ou `refreshThreads()`:

```php
// Exemplo de comando de aplicação; não é executado pelo exemplo connect.php.
$threadId = '10000000000000001'; // Fictício: substitua pelo ID confirmado do destino autorizado.
$thread = null;
foreach ($client->threads() as $candidate) {
    if ($candidate['id'] === $threadId) $thread = $candidate;
}
if ($thread !== null && ($thread['plaintext_send_supported'] ?? false) === true) {
    $result = $client->sendText($thread['id'], 'Olá pela MVO!');
    $state = $client->outgoing($result['offline_id']);
}
```

Envie apenas para um destino autorizado. IDs Facebook permanecem strings nativas; não use número telefônico ou JID WhatsApp como substituto.

`submitted` indica submissão ao transporte com resposta correlacionada. `server_ack` requer projeção do reconhecimento de mensagem retornado pelo servidor. **Nenhum desses estados comprova entrega, leitura ou renderização.** Uma falha depois de iniciar I/O resulta em `send_outcome_uncertain`; examine outbox/eventos antes de decidir reenviar. O cliente não faz replay automático de mensagens incertas.

## Provisionamento explícito de dispositivo

O componente local `MVO\Messenger\E2ee` implementa chaves independentes, inventário ICDC e um registro explícito. Ele exige a extensão adicional GMP e o mesmo dono de sessão conectado. **Provisionar não equivale a conectar o transporte criptografado nem habilita envio/recebimento E2EE.** `connect()` e `poll()` não registram dispositivo automaticamente.

```php
// Depois de connect(), no loop externo, fora de listeners:
$device = $client->e2eeDevice();
$local = $device->prepare();              // Somente gera/salva chaves localmente.
$roster = $device->registrationRoster();  // Leitura autenticada; contém snapshot_hash.
// A aplicação apresenta e revisa o inventário/fingerprints antes de decidir.
// Registro é uma ALTERAÇÃO explícita da conta, não parte deste exemplo de leitura:
// $registration = $device->register($hashRevisadoPeloOperador);
$status = $device->status();
```

`register(string $expectedRosterHash)` busca o inventário novamente e recusa mudança desde a revisão. O CAT atual fica privado, vinculado à conta/app/validade; chaves e registro ficam no `SessionStore`, sob lock. A incerteza é gravada antes do I/O. Resposta perdida preserva as mesmas chaves e bloqueia outra tentativa; não gerar nova identidade para contornar a incerteza. O inventário de identidades não comprova confiança criptográfica dos demais dispositivos.

Uma confirmação completa pode retornar `registration_confirmed=true`, mas `status()` conserva `e2ee_ready=false`, `encrypted_send_available=false`, `encrypted_receive_available=false` e `secure_history_available=false`. Noise/certificados, Signal/trust, fan-out/DSM, envelope/franking, inbox/outbox e recibos têm componentes locais testados. O controlador explícito `e2eeRuntime()` liga login/versão/CAT/prekeys ao mesmo dono, mas bloqueia texto/decodificação sem o verificador nativo ICDC; histórico seguro e validação real continuam ausentes. Threads E2EE continuam bloqueadas para texto sem criptografia. Veja o [contrato do componente](src/E2ee/README.md) e o [guia de dispositivo](https://github.com/mmhospedagem/mvo-whats-php/blob/main/docs/facebook/DISPOSITIVO-E2EE.md).

## Serviços Facebook com o mesmo dono

O pacote separado `mmhospedagem/mvo-facebook-php` organiza os serviços. Ele não é uma dependência reversa deste runtime: uma aplicação que só instala Messenger não recebe `FacebookClient`. Na integração avançada, mantenha o cliente Messenger conectado e crie a fachada com sua capacidade privada; não copie cookies ou crie outro dono concorrente:

```php
use MVO\Facebook\FacebookClient;

$facebook = new FacebookClient(
    $client->webSession(),
    __DIR__ . '/var/private-facebook',
);
try {
    $pages = $facebook->pages()->managed();
    // Consultas e comandos concretos pertencem ao loop externo da aplicação.
} finally {
    $facebook->close(); // Fecha serviços; o dono $client continua com seu ciclo de vida.
}
```

`FacebookConnection` do pacote Facebook também oferece login e serviços como entrada única, sem exigir esse segundo objeto na aplicação. As extensões da branch de desenvolvimento são:

| Serviço Facebook | Contrato delimitado |
| --- | --- |
| `pages()` | Descoberta/paginação, identificação, criação inicial de perfil/Página, cinco campos observados e solicitação de exclusão. Criação não conclui o assistente nem autoriza agir como outra pessoa. |
| `posts()` / `comments()` / `reactions()` | Timeline e interações, criação de texto, edição/exclusão de texto e ocultação de comentários com contextos/providers atuais. Ocultar para o viewer não equivale à moderação global. |
| `inbox()` | Leituras e texto experimental Page Lightspeed; contrato Mercury legado permanece explícito e separado. Sem fallback para mensagem pessoal ou thread E2EE. |
| `media()` / `albums()` | Upload de fotos/vídeo, publicação de fotos e vídeo já carregados e CRUD de álbuns. O transporte raw preserva conta/lock; asset carregado não é publicado automaticamente. Publicação básica de vídeo usa timeline `profile`, sem garantir processamento ou reprodução. |
| `administration()` | Pessoas/convites do portfólio, tarefas comerciais e o próprio acesso limitado. `updateBusinessUser()` exige observar essa pessoa no mesmo portfólio por `people()`/`nextPeople()`, no mesmo serviço, há até cinco minutos. Remover o próprio acesso exige confirmação recente de senha; replay não renova o prazo. Sem transferência de propriedade/admin arbitrário. |
| `insights()` / `contentInsights()` | Valor, série, breakdown e tabela de conteúdo paginada, com exportação local CSV/JSON. Totais podem incluir tráfego pago; células desconhecidas permanecem indisponíveis, sem relatório orgânico exaustivo. |
| `moderation()` | Banir/desbanir usuário e bloquear/desbloquear mensagens de Página, com permissão e ledger. Denúncias finais não implementadas. |
| `ads()` | Consultas Web parciais por IDs conhecidos. Campanhas/orçamentos/criativos não ganham mutations por meio desses novos serviços. |

As assinaturas e pré-condições completas ficam na [referência Facebook](https://github.com/mmhospedagem/mvo-whats-php/blob/main/docs/facebook/REFERENCIA.md); o [contexto LLM Facebook](https://github.com/mmhospedagem/mvo-whats-php/blob/main/docs/facebook/LLMS.md) é independente do WhatsApp. Nenhuma dessas classes acrescenta API REST ou ferramenta ao MCP WhatsApp.

### Contexto e texto do inbox Page

`inbox()->lightspeedContext($actorId,$pageId,$selectedItemId)` valida a seleção devolvida por `threads()` e lê o documento atual do Business Inbox. O leitor exige a mesma conta, `DGWWebConfig`, `ServerAppID` e versão `LSVersion`; se necessário, extrai o literal de um asset público atual sem cookies/execução JS. Não há app ID ou versão de fallback. O UUIDv4 é criado/persistido pelo SDK por conta, conforme o cliente BizInbox efêmero observado; não copia dados do navegador e não é um dispositivo E2EE.

```php
// Destino autorizado e IDs exatos retornados pelas consultas atuais.
$context = $facebook->inbox()->lightspeedContext($actorId, $pageId, $selectedItemId);
// A aplicação faz COMMIT durável do contexto junto da chave/texto/escopo antes de enviar.
$salvarTentativaPrivada([
    'actor_id' => $actorId, 'page_id' => $pageId, 'selection_id' => $selectedItemId,
    'text' => $text, 'idempotency_key' => $idempotencyKey, 'context' => $context,
]); // Callback da aplicação, não método do SDK.
$result = $facebook->inbox()->sendTextLightspeed(
    $actorId, $pageId, $selectedItemId, $text, $idempotencyKey, $context
);
```

O contexto contém `dgw_app_id`, `dgw_app_version`, `server_app_id`, `schema_version`, `device_id`, `origin`, `recipient_id`, `offline_threading_id`, `epoch_id`. Para a mesma tentativa, carregar os dados persistidos e reutilizá-los: não chamar `lightspeedContext()` outra vez, pois isso cria outro OTID/epoch. A chave incerta continua bloqueada e nunca é reenviada automaticamente.

A capacidade `PageMailboxWebSession` cria `PageMailboxGateway` separado com `x-dgw-app-asset_ids={"PAGE":"ID real"}`, mantendo conta, cookies e lock do dono. Não transforma um stream pessoal em Page nem usa a query de sync como falsa mutation. O resultado exige substituição otimista e mensagem com ID/OTID/autor Page/thread/texto correlacionados. `server_ack` mantém entrega/leitura não confirmadas. Leitura do bootstrap, bytes, correlação e idempotência foram testados com fixtures; **a aquisição autenticada atual e o envio real Page ainda precisam de validação**.

## Evidências e limites

| Área | Implementado | Teste local | Validação em conta real |
| --- | --- | --- | --- |
| Login por e-mail/senha e continuação humana | Formulário Web atual, chave dinâmica, senha cifrada e handoff privado opcional | Envelope decifrado por receptor sintético; login/redirects/desafios simulados | Em 18/10/2026: fluxo com verificação humana, identidade confirmada pelo PHP e sessão salva; outros desafios não comprovados |
| Cookies, identidade, lock e restauração | Sim | Fixtures, isolamento e renovação da mesma conta | Em 18/10/2026: restauração da mesma sessão confirmada; concorrência de lock coberta pelos testes locais |
| Bootstrap, identidade e versão dinâmica LS | Sim, com rejeição de dados ausentes | HTML/JS sintéticos; comparação estática com módulos públicos | Bootstrap HTTPS nativo confirmado em 18/10/2026 |
| WebSocket binário e DGW v5 | Sim | Peer TCP localhost, bytes de frames, ACKs, streams e limites | `metadata_connected` e WebSocket ativo por 12 s observados em 18/10/2026; estabilidade prolongada não medida |
| Lightspeed e projeções de metadados | Subconjunto explícito e limitado | ASTs sintéticas, rollback, cursor e deduplicação | Metadados de 13 conversas sincronizados em 18/10/2026; nenhum envio nesse teste |
| Texto em threads sem criptografia suportadas | Implementação experimental | Comandos/resultados simulados | Pendente |
| Provisionamento explícito de dispositivo | Chaves, roster e registro com confirmação/controle de incerteza; não habilita mensagens | Assinaturas, identidade, hashes, lock, resposta/rejeição e repetição incerta sintéticos | Nenhum registro real executado nesta validação |
| Capacidade de transporte Page e upload | `WebSession` privado, multipart e grupo DGW Page sob o mesmo dono | Escopo/frames/ack/correlação sintéticos; serviço no pacote Facebook | Upload e envio Page reais pendentes |
| Mensagens pessoais E2EE e histórico criptografado | **Não** | Bloqueio sem fallback | Não disponível |
| Feed, posts, anúncios, Páginas e administração Facebook | Serviços delimitados no pacote separado `mvo-facebook-php`, sem API REST | Fixtures do pacote Facebook, incluindo ledger/projeções | Leituras/escritas ampliadas ainda não validadas em conta; sem promessa de administração completa |

Execute os testes do pacote a partir da raiz, usando as dependências de desenvolvimento já instaladas:

```sh
php vendor/bin/phpunit --configuration packages/messenger/phpunit.xml
```

Os testes não acessam contas Meta. A análise dos artefatos públicos e as referências estão detalhadas em [Facebook Messenger](https://github.com/mmhospedagem/mvo-whats-php/blob/main/docs/FACEBOOK-MESSENGER.md). Veja [multicanal](https://github.com/mmhospedagem/mvo-whats-php/blob/main/docs/MULTICANAL.md) e o [adaptador opcional](https://github.com/mmhospedagem/mvo-whats-php/blob/main/packages/messenger-adapter/README.md) para integrar os contratos compartilhados.

## Código e referências

O código PHP deste pacote é original, sob MIT. A implementação de terceiros [mautrix/meta, revisão 1b3bd5d](https://github.com/mautrix/meta/tree/1b3bd5db21113e8b42cb45e7464019332653d277) foi consultada como referência de protocolo; seus arquivos AGPL não são uma dependência instalada ou código vendorizado no pacote. A observação de JavaScript público foi estática, sem executar o aplicativo dentro do SDK. Protocolo e loop usam PHP, sem runtime Go, Node.js ou ponte externa. O auxiliar desktop opcional do laboratório serve somente à verificação humana no login; a aplicação consumidora implementa sua própria interface e política de acesso.


## Controlador E2EE explícito no checkout

`e2eeRuntime()` retorna o controlador `MVO\Messenger\E2ee\Runtime` do mesmo dono. `FacebookConnection` também o delega. Obter o controlador e ler `status()` não inicia rede, registro ou upload. Seu `connect()` é uma operação explícita, exige registro/chaves confirmados, GMP/zlib, CAT atual e `302.0.SiteData.client_revision`, e pode publicar prekeys após login confirmado. O transporte filho fecha quando o dono para/falha; não é iniciado pelo connect/poll comum.

O padrão não contém verificador remoto ICDC de produção e bloqueia texto/decodificação com `messenger_icdc_verification_unavailable`. Não substitua a prova do roster por um booleano ou hash revisado. Os eventos encrypted_* encaminhados aos listeners são privados; comandos pertencem ao loop externo. A prontidão pública permanece false. Consulte o [contrato](src/E2ee/INTEGRATION.md).
