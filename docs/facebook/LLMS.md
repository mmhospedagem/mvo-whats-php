# MVO Facebook PHP — contexto para LLMs e agentes

Este documento é autocontido para o pacote `mmhospedagem/mvo-facebook-php`, namespace `MVO\Facebook`. O código está em `packages/facebook/` e depende do runtime `mmhospedagem/mvo-messenger-php`, namespace `MVO\Messenger`. Ambos são distribuídos separadamente na série experimental 0.1; o monorepo mantém suas fontes e os portais de documentação. O SDK WhatsApp `mmhospedagem/mvo-whats-php` / `MVO\WaClient` é separado: não misture suas credenciais, IDs, classes, MCP ou eventos com Facebook.

**Versão deste contexto: branch de desenvolvimento, ainda sem release das extensões descritas abaixo. Facebook 0.1.0 e Messenger 0.1.1 publicados não contêm provisionamento de dispositivo, envio Page Lightspeed, upload, criação/gestão ampliada de Páginas, edição/moderação e os novos serviços de administração/insights.** Para esses contratos, use os checkouts dos dois pacotes via Composer `path`; instalar `^0.1` pode resolver releases anteriores. Publicação no Git, implementação e testes sintéticos não comprovam operação em uma conta real.

O entregável Facebook é somente biblioteca PHP, exemplos e documentação. Não existe uma nova API REST, painel obrigatório ou servidor MCP Facebook. O laboratório loopback de validação é privado e não faz parte do repositório ou dos pacotes distribuídos. PHP executa autenticação, bootstrap, WebSocket DGW e sincronização Lightspeed; Node.js e Go não são runtimes do conector. O navegador isolado pode auxiliar uma verificação humana, mas não substitui o protocolo PHP.

## Instalação independente

Requer PHP 8.2+ de 64 bits e extensões `curl`, `json`, `openssl`, `sodium`, `pdo`, `pdo_sqlite`, conforme os `composer.json` dos dois pacotes. `gmp` é adicional para o provisionamento explícito de dispositivo Messenger; não é requisito para login, metadados ou os serviços Facebook. Na aplicação consumidora, instale a série publicada:

```sh
composer require "mmhospedagem/mvo-facebook-php:^0.1"
```

O Composer resolve Messenger como dependência, sem instalar WhatsApp. Para usar somente `MessengerClient`, a alternativa é `composer require "mmhospedagem/mvo-messenger-php:^0.1"`. As fontes de distribuição são https://github.com/mmhospedagem/mvo-facebook-php e https://github.com/mmhospedagem/mvo-messenger-php; a documentação é https://mmhospedagem.github.io/mvo-whats-php/facebook/.

O checkout Facebook com as extensões exige Messenger `^0.1.2`; a release Messenger 0.1.1 não fornece suas novas interfaces. Enquanto a publicação coordenada não ocorrer, configure **ambos** os pacotes `path` com os aliases `0.1.x-dev` abaixo. Essa resolução foi conferida por Composer dry-run; isso não valida chamadas remotas.

Para desenvolver os pacotes de um checkout do monorepo, a alternativa Composer `path` fica na aplicação consumidora, sem alterar o Composer raiz WhatsApp:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../mvo-whats-php/packages/messenger",
      "options": {"versions": {"mmhospedagem/mvo-messenger-php": "0.1.x-dev"}}
    },
    {
      "type": "path",
      "url": "../mvo-whats-php/packages/facebook",
      "options": {"versions": {"mmhospedagem/mvo-facebook-php": "0.1.x-dev"}}
    }
  ],
  "require": {"mmhospedagem/mvo-facebook-php": "0.1.x-dev"},
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

Execute `composer install` na aplicação e use seu `vendor/autoload.php`. Os caminhos são exemplos portáveis relativos ao projeto consumidor. Não é necessário instalar ou carregar WhatsApp para usar Facebook.

## Entrada principal, login e restauração

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use MVO\Facebook\FacebookConnection;

$facebook = new FacebookConnection(
    sessionId: 'facebook-demo',
    authDirectory: __DIR__ . '/var/private-auth',
    privateStorageDirectory: __DIR__ . '/var/private-facebook',
);

// No primeiro acesso ou renovação explícita:
// $email e $password devem vir de entrada privada da aplicação.
// $facebook->login($email, $password);

try {
    // Com uma sessão já confirmada, restaura sem e-mail/senha ou JSON de cookies.
    $facebook->connect();
    $pages = $facebook->listPages();

    while ($facebook->isConnected()) {
        $facebook->poll(0.5);
        // Consultas/comandos da aplicação pertencem ao loop externo.
    }
} finally {
    $facebook->stop();
}
```

`login()` autentica, verifica a identidade e salva apenas a sessão confirmada; `connect()` é a etapa seguinte. Não chame `login()` a cada reinício. Use o mesmo ID e as mesmas pastas para retomar; outro usuário exige outro ID. Um dono mantém o lock por sessão. Não inicie um `MessengerClient` concorrente para a mesma sessão usada por `FacebookConnection`.

Persistência privada: `<authDirectory>/messenger/<sessionId>/state.sqlite`. A pasta interna `messenger` reflete o runtime compartilhado, sem exigir outro cliente. Os serviços mantêm comandos, escolhas e monitor em `<privateStorageDirectory>/<accountId>/`. `stop()` fecha localmente e libera o lock; preserva sessão confirmada e dados. Não há `logout()`, `run()`, `sendText()` ou `importCookies()` público em `FacebookConnection`: não proponha esses métodos como se existissem. `poll()` mantém o trabalho.

A sessão contém cookies e dados privados. Não guardar em raiz HTTP, Git, exemplos, filas em texto claro ou logs. Senha e código do autenticador não são credenciais persistidas. Não coloque esses valores no contexto de um modelo, argumentos CLI ou código público.

## Verificações de login

Login e ciclo de vida podem lançar `MVO\Messenger\MessengerException`, com `reason` e `retryable`. Se houver desafio, `loginChallenge()` retorna somente `{type, expires_at, ready}`. O contexto real fica em memória por até dez minutos, no processo que iniciou a tentativa e conserva o lock. Reinício, expiração ou `stop()` cancelam a tentativa pendente.

Somente `type=two_factor` com `ready=true` permite receber o código privadamente e chamar `confirmLogin(string $code)`. Preserve zeros iniciais e chame `connect()` depois da confirmação. CAPTCHA/checkpoint exige ação humana; não enviar TOTP como CAPTCHA, resolver automaticamente ou repetir credenciais/códigos. `ready=false` não é um formulário de confirmação implementado. Formatos de login/desafio não reconhecidos permanecem bloqueados.

`exportPrivateLoginChallenge()` é avançado e **secreto**, com `{url, cookies, expires_at}`, apenas para um bridge privado da aplicação. Não o publicar por HTTP, logs, eventos ou ferramentas. Após a verificação humana no mesmo contexto, `completeBrowserLogin(CookieJar $cookies)` verifica a identidade por HTTPS antes de salvar. A biblioteca não abre navegador automaticamente e não lê perfis existentes para extrair sessões.

## Estado, eventos e fronteira de mensagens

- `isConnected()` verifica estado `metadata_connected` e transporte ativo. Isso não comprova dispositivo criptográfico ou permissões comerciais.
- `accountInfo()` fornece `channel`, `provider`, `session_id`, `account_id`, `display_name`, `state`, `connected`, `transport`, `lightspeed_version`, `e2ee_ready`, `has_saved_session`, `capabilities`, `login_challenge`.
- `channel=messenger`, `transport=dgw_websocket` identificam o runtime interno. `has_saved_session` indica armazenamento local, não validade no servidor. `e2ee_ready=false`: provisionar um dispositivo não implementa mensagens pessoais E2EE. Não há fallback plaintext de uma thread criptografada/migrada.
- Estados comuns: `stopped`, `session_imported`, `awaiting_two_factor`, `awaiting_captcha`, `login_challenge_expired`, `authenticating`, `connecting_transport`, `metadata_connected`, `disconnected`. Falhas podem usar seu `reason` específico como estado.
- `on(string $event, callable $handler): static` recebe callbacks; não executar consultas/escritas neles. `events(int $after=0, int $limit=100): array` consulta eventos persistidos do runtime. Eventos de monitor são outra coleção.
- O runtime Messenger possui seus próprios métodos experimentais de texto sem criptografia em threads reconhecidas. Eles não equivalem ao inbox de Página. A Página usa `inbox()->sendTextLightspeed()` com um grupo DGW vinculado ao asset; `FacebookConnection` continua sem um `sendText()` genérico.

## Serviços realmente presentes

`FacebookConnection::services()` obtém o `FacebookClient` do mesmo dono conectado. A conexão oferece os atalhos `pages()`, `posts()`, `comments()`, `reactions()`, `inbox()`, `media()`, `albums()`, `moderation()`, `administration()`, `insights()`, `contentInsights()`, `ads()`, `listPages()`, `listAdAccounts()`, `selectPage($id)`, `selectAdAccount($id)`, `selectedContext()`, `monitor()` e `command($id)`. `e2eeDevice()` delega explicitamente ao dono Messenger. A fachada preserva o objeto `administration()` durante sua vida; conserve esse objeto em sequências que dependem de preflight/reautenticação. Depois de `stop()`, obtenha serviços novos; referências antigas não autorizam I/O sob um dono parado.

| Área | Métodos implementados | Limite de uso |
| --- | --- | --- |
| Páginas | `pages()->list()` / `listPages()`, `creationContext($scale=1)`, `categories($search,$limit=10)`, `create(...)`, `managed($scale=1)`, `next($cursor,$count=20,$scale=1)`, `get($pageId,$actorId=null)`, `field(...)`, `updateField(...)`, `delete(...)`, `availability()` | Criação inicial, descoberta paginada e cinco campos observados; exclusão é solicitação, sem garantir remoção permanente. Preservar `actor_id` e `delegate_page_id`. Não há CRUD arbitrário de todos os objetos/configurações. |
| Contexto | `selectPage(string $id)`, `selectAdAccount(string $id)`, `selectedContext()` | Preferências locais entre recursos descobertos. Nenhuma seleção automática, troca de `c_user`, concessão remota ou injeção automática de argumentos. |
| Timeline | `posts()->list($profileId, $variables=[], $actorId=null)`, `next($profileId, $cursor, $variables=[], $actorId=null)` | Exige providers atuais; retorna `items` com IDs/texto/feedback/cursor e `page_info`. |
| Texto de publicação | `posts()->createText($actorId,$text,$idempotencyKey,$context,$variables=[])`, `editText($actorId,$storyId,$text,$idempotencyKey,$context,$variables)`, `delete($actorId,$storyId,$idempotencyKey,$variables)` | Composer `profile` e providers reais. Edição limitada a texto de story existente sem anexos; exclusão exige ID opaco exato. Upload é serviço independente e não publica story automaticamente. |
| Comentários | `list($feedbackId,$variables=[],$actorId=null)`, `next/previous($feedbackId,$cursor,$intentToken,$count=20,$variables=[],$actorId=null)`, `create($actorId,$feedbackId,$text,$idempotencyKey,$context,$variables=[])`, `editText(...)`, `delete(...)`, `setHidden(...)` | Preservar ordenação/cursor, feedback e IDs opacos. Contextos de criação/edição/menu e providers são próprios; ocultação do viewer não comprova moderação global. |
| Reações | `summary($feedbackId, $scale=1.0, $actorId=null)`, `list($feedbackId, $reactionId=null, $scale=1.0, $actorId=null)`, `next($feedbackId, $cursor, $reactionId=null, $count=10, $scale=1.0, $actorId=null)`, `set(...)`, `remove(...)` | Leitura distingue contadores, coleção paginada e subconjunto importante. Escrita exige actor/feedback/reação observados, chave, contexto e providers. Não inferir pessoas a partir de contador. |
| Inbox de Página | `threads($actorId,$pageId,$limit=20,$beforeTimePrecise=null,$tags=[])`, `messages($actorId,$pageId,$selectedItemId,$limit=20,$beforeTimePrecise=null)`, `lightspeedContext(...)`, `sendTextLightspeed(...)`, `sendText(...)` | Leitura e envio de texto Page experimental. `selectedItemId` é `selection_id` retornado; `has_more=null` é desconhecido. LS usa transporte Page separado; `sendText()` preserva contrato Mercury legado explícito. Não há fallback pessoal. |
| Fotos | `media()->photoContext($actorId)`, `uploadPhoto($actorId,$file,$idempotencyKey,$context)` | Multipart PHP, um arquivo local JPEG/PNG/WebP, até 25 MiB por política do SDK. Asset carregado não é story/mensagem publicada. |
| Pessoas e acesso | `administration()->people/nextPeople`, `pageAccess`, `updateBusinessUser`, `inviteBusinessPeople`, `pageRoles/nextPageRoles`, `pageRoleReauthenticationKey`, `reauthenticatePageRolesEncrypted`, `invitePageRole/changePageRole/removePageRole/cancelPageInvitation`, `pageAssetAccess/assignPageAssetTasks/removePageAssetAccess`, `permissions`, `reauthenticationKey`, `reauthenticateEncrypted`, `removeMyLimitedAccess` | Portfólio, Página e Business User são escopos separados. Escritas nativas exigem permissão atual e confirmação de senha nesse objeto; alteração/remoção/cancelamento exigem vínculo observado. `CUSTOM` é somente leitura. Sem transferência de propriedade ou paridade com todo modelo moderno de acesso. |
| Insights de Página | `insights()->metrics()`, `singleValue(...)`, `timeSeries(...)`, `breakdown(...)`, `availability()` | Métricas Web delimitadas, datas reais e caller atual. Totais podem incluir mídia paga; sem inventário/relatório orgânico completo. |
| Tabela de conteúdo | `contentInsights()->context`, `columns`, `listContent`, `next`, `report`, `availability` e `ContentReportExporter::csv/json` | Mesma conta/Página/datas/providers na continuação. Export local não comprova paginação completa, completude das métricas ou snapshot atômico. Renderers desconhecidos permanecem indisponíveis. |
| Álbuns | `albums()->get/items/create/edit/delete/addMedia/setCover/reorder` | Tokens e IDs nativos, composer/providers reais e comandos idempotentes; upload, criação do contêiner e publicação são ações separadas. |
| Moderação de pessoas | `moderation()->banUser/unbanUser/setMessagesBlocked` | Banimento da Página e bloqueio de mensagens são ações distintas. Mesma pessoa dona, actor Página explícito, ledger e confirmação do formato retornado. Sem enumeração de banidos ou envio final de denúncia. |
| Anúncios | `accounts()`, `account($id)`, `campaigns($ids)`, `adsets($ids)`, `ads($ids)`, `previews($ids)`, `insights($ids, $datePreset='today', $metrics=...)`, `insightsRange($ids, $since, $until, $metrics=...)`, `availability()` | Somente leituras Web observadas. Objetos por IDs conhecidos; não enumeram toda a conta. Disponibilidade não testa permissão. |
| Monitor | `monitor()` obtém `SnapshotMonitor` | Motor SQLite de diferenças de projeções; aplicação consulta a rede e agenda coletas. Sem webhook/polling automático. |

As assinaturas completas, projeções e defaults estão em [REFERENCIA.md](REFERENCIA.md), junto aos métodos das classes reais. Em `reactions()->set()`, os argumentos são `actorId`, `feedbackId`, `reactionId`, `idempotencyKey`, `context`, `variables=[]`; em `remove()`, omite-se `reactionId`.

`createText()` exige em `$context`: `composer_entry_point`, `composer_source_surface`, `composer_type=profile`, `logging.composer_session_id`, objeto `audience`, lista `tracking`. Comentário/reação exigem `session_id` e o enum `feedback_source` efetivo. Esses contextos são privados do fluxo Web, não chaves WhatsApp. `feedbackId` é opaco e não pode ser fabricado de `post_id`.

Alguns documentos exigem providers dinâmicos em `$variables`; defaults da assinatura não os tornam opcionais. `PageDocuments::providers()`, `PageManagementDocuments::providers()` e `ContentManagementDocuments::providers()`, no namespace `MVO\Facebook\Catalog`, listam os exigidos pelos respectivos documentos. Não há extração automática de todos os contextos/providers. Ausência reconhecida lança `facebook_provider_context_required` antes do I/O. Fixtures sintéticas e nomes/doc IDs encontrados estaticamente não autorizam fabricar valores.

Anúncios: `accounts()` usa `first=2`, com `partial=true`, `exhaustive=false`, sem inventário completo/paginação estabelecida. A ramificação de criação automática de conta é excluída. Métodos por IDs aceitam de 1 a 100 IDs nativos numéricos como strings. Insights têm agregado `first=1`, `filter_zero_delivery=true`, sem breakdown arbitrário. Métricas padrão: `SPEND`, `IMPRESSIONS`, `REACH`, `COST_PER_RESULT`, `PERFORMANCE_INDICATOR`, `ANCHOR_EVENT_ATTRIBUTION_SETTING`; aceita ainda `RESULTS`, até sete métricas. A constante PHP do default é privada. Valores monetários permanecem no formato do servidor.

Não implementar nem sugerir fallback para Marketing Graph API/`graph.facebook.com`. As consultas de anúncios ficam nos contratos Web `/api/graphql/` comprovados; os demais serviços têm seus próprios transportes Web/DGW explícitos. Criação/publicação de campanhas, conjuntos, anúncios/criativos e alterações de orçamento/status não estão implementadas. `ads()->availability()` informa `read_only=true`, `validated_in_account=false`, `account_list_exhaustive=false`.

## Inbox de Página: contexto e envio atuais

Assinaturas de `InboxService`:

```text
lightspeedContext(string $actorId, string $pageId, string $selectedItemId, ?callable $bundleLoader = null): array;
sendTextLightspeed(string $actorId, string $pageId, string $selectedItemId, string $text, string $idempotencyKey, array $context): array;
sendText(string $actorId, string $pageId, string $selectedItemId, string $text, string $idempotencyKey, array $context): array;
```

`lightspeedContext()` é somente leitura: confirma `selected_page.id`, thread, `other_user_id`, `composer_input_disabled=false` e `cannot_reply_reason=null`, depois lê `/latest/inbox/all?asset_id=...&selected_item_id=...`. Não abre socket Page nem envia mensagem. O bootstrap inerte exige a conta autenticada em `CurrentUserInitialData`, `DGWWebConfig` e `ServerAppID`. A versão vem de `LSVersion` no documento ou de seu bundle público atual; o loader anônimo não recebe cookies e não executa JavaScript. Conta, Página ou versões divergentes bloqueiam o fluxo; não fabricar app IDs, providers ou versões.

O retorno contém strings `dgw_app_id`, `dgw_app_version`, `server_app_id`, `schema_version`, `device_id`, `origin`, `recipient_id`, `offline_threading_id`, `epoch_id`. `device_id` é UUIDv4 criado pelo SDK e persistido por conta no banco privado; o cliente BizInbox efêmero observado também pode gerar UUIDv4. Não é cookie, identidade de conta ou dispositivo E2EE. O callback opcional `$bundleLoader` tem contrato `callable(list<string>):list<string>` para assets CDN públicos atuais/testes.

Exemplo de integração: `$salvarTentativa` é armazenamento durável **da aplicação**, que deve recusar substituição de uma tentativa já existente. Os IDs vêm das consultas reais; não usar IDs fictícios para executar envios.

```php
use MVO\Facebook\FacebookConnection;

function prepararEEnviarTextoPagina(
    FacebookConnection $facebook,
    string $actorId,
    string $pageId,
    string $selectedItemId,
    string $text,
    string $idempotencyKey,
    callable $salvarTentativa,
): array {
    $inbox = $facebook->inbox();
    $context = $inbox->lightspeedContext($actorId, $pageId, $selectedItemId);
    $salvarTentativa([
        'actor_id' => $actorId, 'page_id' => $pageId,
        'selection_id' => $selectedItemId, 'text' => $text,
        'idempotency_key' => $idempotencyKey, 'context' => $context,
    ]); // Precisa concluir o commit antes de continuar.
    return $inbox->sendTextLightspeed(
        $actorId, $pageId, $selectedItemId, $text, $idempotencyKey, $context
    );
}
```

Essa função prepara uma tentativa nova. Para consultar/repetir a mesma chave, carregue a tentativa persistida e passe **o mesmo** contexto/texto/escopo a `sendTextLightspeed()`; não execute a preparação novamente. Uma nova aquisição gera outro OTID/epoch. Uma tentativa incerta continua bloqueada pelo ledger, sem reenvio automático ou troca de chave.

O envio usa task 46, `source=4456448`, `sync_group=205` e grupo DGW separado com `x-dgw-app-asset_ids={"PAGE":"ID exato"}`, sob o mesmo dono/lock. Não acrescenta `sender_fbid` inventado e não reutiliza o stream pessoal como se fosse Page. O `WebSession` precisa implementar a capacidade `PageMailboxWebSession`; a sessão nativa `MessengerClient::webSession()` a fornece. O remetente repete seu preflight antes do I/O e confere a configuração do transporte. `LSBizInboxGraphQLLightspeedRequestQuery` observada inicializa/lê sync, sem ser usada como uma falsa mutation de envio.

Confirmação exige substituição otimista correlacionada **e** linha da mensagem com mesmo ID de servidor, OTID, Página autora, thread e texto. ACK de transporte, HTTP 200 ou substituição isolada não bastam. O resultado conserva `server_ack`, `delivery_confirmed=false`, `read_confirmed=false` e `transport=lightspeed_page_dgw`; acompanhar resultados não equivale à validação visual/entrega.

`sendText()` continua sendo o caminho Mercury **legado explícito**, distinto do LS. Seu contexto exige `client_thread_id`, `recipient_id`, `client`, `source`, `message_id`, `offline_threading_id`, `timestamp_ms`; opcionais `origin` e `privacy_mutation_token` somente quando observados. Não há aquisição automática do contexto Mercury. `PageInboxSender::newOfflineIdentity()` gera somente OTID/timestamp, sem inventar thread/permissões/ID de mensagem real. Não usar o retorno de `lightspeedContext()` nesse método. Veja [INBOX.md](INBOX.md).

## Páginas, administração e insights

`pages()->creationContext(float $scale=1)` consulta a criação como pessoa autenticada e exige actor `User` com mesmo account ID; uma Página/empresa não substitui essa identidade. Retorna `can_create_page` e categorias sugeridas quando presentes, sem criar recurso. `categories(string $search,int $limit=10)` busca categorias reais; limit de 1 a 100 é corte local, sem inventar paginação ou completude. `create(string $name,array $categoryIds,string $idempotencyKey,array $context,string $bio='')` cria o par inicial perfil/Página com 1 a 3 IDs distintos do seletor. Nome tem de 2 a 75 unidades UTF-16 e bio até 255. `$context` contém `creation_source` (`comet`/`www` conforme branch Web atual), `page_referrer` atual e `off_platform_creator_reachout_id` opcional. Não inventar branch/ID de categoria/referrer. Leia `creationContext()` antes para apresentar capacidade/termos reais; a autorização final permanece no servidor. Resultado preserva `profile_id`/`actor_id` e `page_id`/`delegate_page_id` distintos, `setup_complete=false`; não conclui assistente, publica conteúdo ou troca cookies automaticamente. Criação não foi executada em conta real nesta validação.

Além da descoberta, `pages()->get($pageId,$actorId=null)` devolve identificação mínima; `field($pageId,$field,$context,$actorId=null,$scale=1)` lê e `updateField($pageId,$field,$value,$key,$context,$actorId=null)` altera um campo observado: `name`, `blurb`, `email`, `phone` ou `website`. O contexto contém `end_point` e `entry_point` reais do editor; não criar enums. Alterações são independentes, sem operação multicanal/atômica. Mudança de nome pode aguardar aprovação. `delete($pageId,$key,$actorId=null)` solicita exclusão com ID correlacionado e `permanent_deletion_confirmed=false`; não inventar prazo de recuperação. A disponibilidade continua específica por contrato, sem CRUD universal de Fanpage.

Para obter o contexto da criação sem copiar flags do navegador, `pages()->creationRequestContext(): array` faz GET autenticado somente de `https://www.facebook.com/pages/creation/?ref_type=launch_point`. Lê a identidade e `gkxData[20935].result` de JSON inerte: flag verdadeira produz `creation_source=comet`, falsa produz `www`; ausência/conflito bloqueia. O referrer é o `launch_point` da própria rota solicitada, com reachout nulo. Passe o resultado em `$context` de `create()` após conferir a capacidade pessoal e escolher categorias nativas. Essa leitura não cria uma Página nem importa cookies/storage do navegador.

`create()` impõe preflight em cada tentativa nova: depois de reserva/replay do ledger, confirma actor pessoal e `can_create_page===true`. Negativa/falha bloqueia a mutation e conserva `commandId`, `execution_started=false`, `outcome=not_executed`. Replay confirmado não refaz essa leitura; comandos incertos permanecem bloqueados. A conferência não substitui a decisão explícita de criar uma Página nem conclui o assistente posterior.

Assinaturas de `PageAdministrationService`:

```text
people(string $businessId, array $providers, int $count = 20): array;
nextPeople(string $businessId, string $cursor, array $providers, int $count = 20): array;
pageAccess(string $pageId): array;
updateBusinessUser(string $businessId, string $businessUserId, array $values, string $idempotencyKey): array;
inviteBusinessPeople(string $businessId, array $emails, array $context, string $idempotencyKey): array;
permissions(string $profileId): array;
reauthenticationKey(): array;
reauthenticateEncrypted(string $encryptedPassword, string $idempotencyKey): array;
removeMyLimitedAccess(string $profileId, string $idempotencyKey, float $scale = 1): array;
```

`people()`/`nextPeople()` retornam coleção parcial paginada, `exhaustive=false`; count de 1 a 100. **Antes de `updateBusinessUser()`, observar o Business User no portfólio por `people()`/`nextPeople()` no mesmo objeto do serviço, há até cinco minutos.** Após expiração, é necessária nova leitura. O vínculo não é inferido de ID global/Page ID e não concede autorização. `$values` é completo: `firstName`, `lastName`, `email`, `business_account_task_ids` (IDs exatos), `expireTime` (`int|null`) e `clientTimezoneID` (ID numérico Facebook). Resposta confirma o mesmo Business/User; revisão pendente não vira cargo efetivo (`role_change_effective_confirmed=false`).

Convites aceitam de 1 a 100 e-mails distintos. `$context` contém `business_account_task_ids`, `assets` (lista de `{asset_id,permitted_task_ids}`), `invite_origin_surface`, `expiry_time` (`int|null`), `client_timezone_id` (nome IANA) e `auto_assign_access` (bool). Resultado parcial permanece parcial; `access_accepted_confirmed=false`. Não reenviar automaticamente endereços restantes, transformar tarefas em nomes de cargo ou contornar verificação da Meta.

`pageAccess()` devolve o indicador observado de gerenciar administradores, sem concedê-lo. `permissions()` refere-se ao próprio usuário no perfil, sem enumerar todos os administradores. Para remover **o próprio acesso limitado**, consulte `reauthenticationKey()`, cifre a senha privadamente com `MVO\Messenger\Auth\PasswordEncryption`, use `reauthenticateEncrypted()` e então `removeMyLimitedAccess()` no mesmo serviço. O envelope `#PWD_BROWSER` é segredo; nunca enviar a modelos/logs. A confirmação vale até cinco minutos e replay do ledger não renova `verified_at`. O serviço também implementa cargos nativos de Página, descritos abaixo. Não há transferência de propriedade nem equivalência com todos os modelos modernos de acesso.

`insights()->metrics()` enumera pares observados para `Views`, `Viewers`, `Reach`, `Interactions`, `Comments`, `Shares`, `Visits`, `NewFollowers`, `NetFollows`. `singleValue($pageId,$metric,$since,$until,$callerId)`, `timeSeries(...)` e `breakdown($pageId,$metric,$breakdown,$since,$until,$callerId)` recebem datas reais `YYYY-MM-DD`, intervalo crescente e caller do Business Suite atual. Séries conservam timestamps do servidor; ausências não viram zero. `PAID_ORGANIC`, quando aceito pelo servidor, conserva os labels dos buckets. Totais `distribution_scope=not_separated` podem incluir tráfego pago e não são rebatizados como orgânicos. `contentInsights()` oferece a tabela paginada e exports locais descritos abaixo; não equivale a relatório orgânico completo ou job de exportação Meta. Veja [PAGINAS.md](PAGINAS.md), [ADMINISTRACAO.md](ADMINISTRACAO.md), [INSIGHTS.md](INSIGHTS.md).

## Texto, moderação e upload

`posts()->editText($actorId,$storyId,$text,$key,$context,$variables)` edita somente texto de story existente sem anexos. `$context` contém `composer_entry_point`, `composer_source_surface`, `composer_type`, `logging.composer_session_id`, `tracking`; `$variables` conserva providers, `feedLocation`, `renderLocation`, `scale`, `ppeEnabled`. `posts()->delete($actorId,$storyId,$key,$variables)` exige o mesmo ID opaco devolvido como `deleted_story_id`.

`comments()->editText($actorId,$commentId,$text,$key,$context,$variables)` conserva `formatting_style`, `attribution_id_v2`, `tracking` e disclosure AI opcional booleano; variáveis incluem os providers/rendering atuais e `translationType`. `delete($actorId,$commentId,$key,$context,$variables)` exige `attribution_id_v2`, `tracking` e ID de exclusão correlacionado. `setHidden($actorId,$commentId,$hidden,$key,$context,$variables)` usa `feedback_source` **numérico** do menu; para ocultar, também `attribution_id_v2`. Conserva `is_hidden_by_viewer` e `is_hidden_by_content_owner` reais; não tratar ocultação individual como moderação global ou inventar anexos/menções.

`media()->photoContext($actorId): PhotoUploadContext` obtém `ReactComposerMediaConfig.photo` do bootstrap autenticado atual; o tipo pertence a `MVO\Facebook\Media`. Ausência não cria defaults anônimos. `uploadPhoto($actorId, UploadFile $file, $key, PhotoUploadContext $context)` exige mesma conta/ator, arquivo local legível JPEG/PNG/WebP de até 25 MiB por política do SDK e capacidade multipart do dono. `UploadFile($path,$filename,$mediaType)` pertence a `MVO\Messenger\Transport`; não aceita URLs/streams ou filename com caminho/controle. O ledger vincula hash/tamanho/contexto, sem guardar o caminho privado. TLS/endpoint fixo e ausência de redirects preservam o limite de transporte. Retorno `photo_id`, `uploaded=true`, `published=false`; upload não publica post, mensagem ou álbum. Vídeos/resumable não passam por este método. Veja [MIDIA.md](MIDIA.md), [PUBLICACOES.md](PUBLICACOES.md), [COMENTARIOS-REACOES.md](COMENTARIOS-REACOES.md).

## Provisionamento explícito: ainda sem mensagens E2EE

`FacebookConnection::e2eeDevice(?RegistrationTransport $transport=null): DeviceManager` e `MessengerClient::e2eeDevice()` usam a mesma conta e lock. `prepare()` gera/persiste chaves novas e independentes localmente, sem rede. `registrationRoster()` faz leitura autenticada de identidades e devolve `snapshot_hash`/fingerprints para revisão; isso não verifica confiança criptográfica dos outros dispositivos. `register(string $expectedRosterHash)` é uma alteração explícita da conta, exige hash revisado, busca roster novamente e bloqueia mudanças concorrentes. Não é chamado por `login()`, `connect()` ou `poll()`.

GMP é necessário para esse componente adicional. CAT atual permanece privado, vinculado à conta/app/validade, sem eventos/logs/LLMs. As chaves ficam no `SessionStore` privado do dono, com locks. Antes da mutação, a incerteza é persistida; uma resposta perdida preserva a identidade e bloqueia novo registro. Sucesso exige confirmação completa do servidor e correlação das chaves salvas; uma flag fornecida pelo consumidor não produz prontidão.

`status()` conserva `e2ee_ready=false`, `encrypted_send_available=false`, `encrypted_receive_available=false`, `secure_history_available=false`, mesmo com `registration_confirmed=true`. Componentes locais de Noise/certificados, Signal/trust, fan-out/DSM, envelope/franking, inbox/outbox e recibos têm testes sintéticos; sua existência não comprova login/interoperabilidade atual nem habilita o envio comum. O controlador `e2eeRuntime()` já integra dono, login/CAT, versão atual e prekeys. A fórmula first-party é `302.0.SiteData.client_revision`, sem fallback. A prontidão continua bloqueada pela ausência de verificador remoto ICDC e de validação real; renovar CAT também exige contexto atual. Histórico seguro continua ausente. Não tentar plaintext de thread migrada. Leia [DISPOSITIVO-E2EE.md](DISPOSITIVO-E2EE.md).

## Escritas e resultados incertos

Mutations dos serviços exigem chave de idempotência de 1 a 256 bytes e ledger privado. O registro é gravado `unknown` antes do I/O. Chave igual com outro conteúdo produz `facebook_idempotency_conflict`. Uma chave já confirmada retorna a projeção persistida sem repetir a requisição; uma chave sem confirmação lança `facebook_command_uncertain`, sem novo envio. Não trocar a chave para contornar incerteza.

`FacebookException` tem `reason`, `outcome`, `retryable` e `commandId`. `command($id)` retorna `id`, `operation`, `state`, `result`, `created_at`, `updated_at`, ou `null`. Estados importantes: `prepared` reserva local; `unknown` resultado incerto; `response_received` resposta parseada, mas projeção não confirmada; `confirmed` projeção validada em `result.projection`. Não persistir resposta HTTP/GraphQL bruta como resultado público. Falha na validação conserva o ID da tentativa para conciliação.

HTTP 200, documento encontrado, contador ou `confirmed` local não comprovam aparência/publicação visível, entrega, aprovação ou leitura. `retryable=true` não autoriza retry automático de escrita incerta. Erros de contexto/identidade devem ser tratados sem copiar segredos de outra conta. Exceções de autenticação são `MessengerException`; argumentos/ciclo inválidos também podem lançar `InvalidArgumentException`/`LogicException`. Não oferecer um retorno de sucesso genérico para uma operação ainda ausente.

## Monitoramento e completude

Classes `MVO\Facebook\Monitoring`:

```php
ResourceScope($accountId, $identityId, $resourceType, $resourceId);
SnapshotItem($id, $fields = [], $counters = []);
SnapshotPage($snapshotId, $items, $cursor = null, $nextCursor = null, $complete = false);
SnapshotMonitor($sqliteFile);
```

O monitor expõe `ingest($scope, $page)`, `state($scope)`, `items($scope, $afterId=null, $limit=100)`, `events($scope, $after=0, $limit=100)`, `discardPending($scope, $snapshotId)`. Use IDs novos para novas coletas e o mesmo snapshot/cursor para retomada idêntica. Conta, identidade ativa e recurso são escopos separados. Páginas pendentes e recibos sobrevivem à reabertura.

Primeira coleta **exaustiva** cria baseline silencioso; histórico inicial não vira novidade. Coletas parciais não autorizam inferir remoção. Campos ausentes são desconhecidos; `null` explícito pode limpar campo. Contadores ausentes não viram zero. Eventos locais são `item.created`, `item.updated`, `counters.changed`, e `item.removed` apenas com ausência em coleta posterior exaustiva, sem provar exclusão no servidor. O consumidor entrega suas notificações e guarda o cursor de evento.

Não marque `complete=true` só porque recebeu HTTP 200, esgotou um subconjunto contextual de anúncios ou recebeu `has_more=null`. O monitor não consulta a rede nem garante que o coletor suporte um inventário exaustivo.

## Integração avançada com Messenger existente

`new FacebookClient($messenger->webSession(), $privateStorageDirectory)` usa o dono Messenger já conectado. Não copie cookies, não serialize `WebSession` ou `privateRequestFields()` e não crie outro dono concorrente. `FacebookClient::close()` libera apenas armazenamentos dos serviços; não para o Messenger existente. `FacebookConnection::stop()` fecha seus serviços e seu dono. Monitores guardados pela aplicação têm armazenamento próprio. O inventário `services()->documentInventory()` informa documentos observados, não permissões/sucesso real.

## Exemplos e verificações locais

A partir da raiz do checkout, comandos de ajuda não conectam contas:

```sh
php packages/facebook/examples/session.php --help
php packages/facebook/examples/discover.php --help
php packages/facebook/examples/monitor-local.php /diretorio/privado/monitor.sqlite
```

`monitor-local.php` usa dados sintéticos, sem conta ou rede; repetir o mesmo SQLite demonstra deduplicação. `session.php` autentica com `--login` usando apenas ambiente privado `MVO_FACEBOOK_EMAIL` e `MVO_FACEBOOK_PASSWORD`, ou restaura sem essa opção; requer `--session`, `--auth-dir`, `--data-dir`, aceita `--seconds` (padrão 30). Ele diagnostica desafios e encerra; para continuar 2FA/bridge, a aplicação deve manter o objeto/processo vivo. `discover.php` restaura uma sessão existente e lista Páginas; `--ads` acrescenta contas parciais e `--ad-ids` consulta anúncios por IDs conhecidos. Não executa escritas.

Testes locais do checkout:

```sh
php vendor/bin/phpunit --bootstrap tools/bootstrap-packages.php packages/facebook/tests
```

Se necessário, use `php -c /caminho/php.ini ...`. O bootstrap acima pertence ao monorepo público e é infraestrutura de testes/autoload, não dependência da biblioteca consumidora. Testes simulados não demonstram execução remota.

## Evidência e fronteiras atuais

Em 18/10/2026, foram confirmados em uma conta: autenticação com continuação humana, identidade verificada pelo PHP, cookies salvos e restauração em `metadata_connected`; metadados de 13 conversas, WebSocket observado por 12 segundos. Uma captura autenticada do launchpoint de Páginas foi reconhecida pelo parser, projetando sete Páginas modernas, sem afirmar completude. Isso não comprova todos os desafios/contas, estabilidade prolongada ou permissões comerciais.

Testes locais cobrem conexão/persistência simuladas, parsing, catálogo de documentos, projeções, ledger, escolhas isoladas e monitor SQLite. Os serviços de publicação, comentários, reações, inbox comercial, anúncios e eventos reais ainda não foram validados em conta nesta etapa. Não transformar implementação, fixture ou fonte estática em promessa de sucesso real.

As extensões da branch de desenvolvimento têm contratos e testes de provisionamento explícito, envio Page LS/Mercury, upload de fotos, edição/exclusão de texto, ocultação de comentários, campos da Página, pessoas/permissões e métricas. **Não houve registro de dispositivo, envio Page, upload, publicação, convite, mudança de cargo ou exclusão em conta real nesta validação.** Aquisição automática do contexto Page também foi validada com fixtures, sem comprovar o HTML autenticado atual.

Continuam ausentes mensagens pessoais E2EE/histórico seguro, acompanhamento realtime contínuo do inbox Page, fluxos gerais de Página/reels/edição audiovisual e chamadas, transferência de propriedade e todos os modelos modernos de acesso, enumeração de banidos e denúncias finais, renderers pago/liquidez completos e exports remotos Meta, relatórios orgânicos exaustivos, grupos/Marketplace, Instagram e mutations comerciais de anúncios. Nenhuma ferramenta Facebook entrou na allowlist MCP WhatsApp. Não criar stubs com sucesso fictício ou inventar caminhos para recursos ausentes. `availability()` diferencia cobertura implementada de `validated_in_account=false`.

Consulte [REFERENCIA.md](REFERENCIA.md), [README do pacote](../../packages/facebook/README.md), `packages/facebook/src/FacebookConnection.php`, `FacebookClient.php`, `Service/`, `Monitoring/`, e o contrato de autenticação `packages/messenger/src/MessengerClient.php` antes de propor chamadas. Preserve o runtime WhatsApp e alterações de outros agentes. A distribuição pública inclui apenas SDKs, exemplos e documentação; sessões, capturas autenticadas, laboratório privado e a API consumidora não pertencem a esse contrato.


## Extensões de mídia, cargos e relatórios

No mesmo dono `FacebookConnection`/`FacebookClient`, `albums()` retorna `AlbumService`; `moderation()` retorna `ModerationService`; `contentInsights()` retorna `ContentInsightsService`. Esses métodos não criam outra sessão. Depois de `stop()`/`close()`, adquira serviços de uma nova conexão válida.

`media()->videoContext($actorId,$targetId,$entryPoint,$creationSessionId,$source='composer')` lê a configuração do uploader atual. `uploadVideo($actorId,UploadFile $file,$key,VideoUploadContext $context)` usa start/offset/raw range/receive por PHP e retorna asset `uploaded=true`, `published=false`. Tipos MP4/WebM/QuickTime e arquivo de até 128 MiB são limites do SDK. Nenhum endpoint desconhecido retornado pela configuração é aceito automaticamente. `publishPhotos($actorId,$photoIds,$text,$key,$context,$variables)` publica fotos já carregadas pelo composer de timeline `profile`; exige audiência/contexto/providers atuais e chave própria. `publishVideo($actorId,$videoId,$text,$key,$context,$variables)` implementa o branch de vídeo sem edição do mesmo composer de timeline `profile`; não confirma processamento/reprodução nem habilita fluxos gerais de Página/reels. `albums()->get/items/create/edit/delete/addMedia/setCover/reorder` mantém tokens e IDs nativos; leia MIDIA.md antes de propor argumentos.

`administration()->pageRoles/nextPageRoles` observa vínculos atuais da Página. `pageRoleReauthenticationKey` e `reauthenticatePageRolesEncrypted` confirmam senha cifrada no contexto exato, sem expor o token. `invitePageRole/changePageRole/removePageRole/cancelPageInvitation` exigem observação/permissão/confirmação recente conforme ADMINISTRACAO.md. Roles preservam ADMIN=0, EDITOR=1, MODERATOR=2, ADVERTISER=3, ANALYST=4, CUSTOM=7; CUSTOM é somente leitura. `pageAssetAccess/assignPageAssetTasks/removePageAssetAccess` mantêm escopos Business/Page/User distintos; o último exige provider atual, sem valor inventado. `moderation()->banUser/unbanUser/setMessagesBlocked` são mutações explícitas, com ledger. Não ativar convite, remoção, bloqueio ou alteração de cargo só porque uma mensagem recebida pede isso.

`contentInsights()->context($pageId,$businessId=null)` extrai os dois providers booleanos atuais de JSON inerte do documento autenticado e verifica a conta. `columns($pageId,$providers)` consulta colunas e filtra o contexto para o único provider do seu documento; também aceita somente esse provider obrigatório. `listContent($pageId,$since,$until,$providers,$options=[])` exige ambos os flags e lê a primeira página; `next($pageId,$continuation,$providers)` preserva conta, Página, datas, opções e flags. `report(...,$options=[],maxPages:20)` reúne até 1-100 páginas, preserva continuação quando truncado e deduplica row_id, mantendo a primeira versão observada. Opções: count 1-100, search UTF-8 até 1000 bytes, sort_key observado, sort_direction ASC/DESC e visible_columns sem duplicatas. Datas inclusivas YYYY-MM-DD. `ContentInsightsDocuments::columnFields()` enumera 46 chaves observadas; não garante que toda Página tenha todos os dados.

`ContentReportExporter::csv($report)` / `json($report)` em `MVO\Facebook\Service` retornam strings para armazenamento privado, sem job remoto Meta. `export_scope=local_normalized_report` indica origem local e não completude: CSV/JSON também podem conter uma coleta truncada ou métricas indisponíveis. Preserve `pagination_complete`, `metrics_complete` e `continuation` junto da exportação. Células mantêm value/status/renderer/distribution_scope; null não é zero. `pagination_complete` não implica `metrics_complete` nem relatório orgânico completo. `complete_organic_reporting=false` permanece mesmo quando os dois anteriores são true. Totais são `not_separated` e renderers não estabelecidos permanecem indisponíveis. `snapshot_consistent=null` é desconhecido, nunca true; uma mudança observada no mesmo row_id produz `duplicate_rows_changed=true` e `snapshot_consistent=false`. Ausência de diferenças observadas não prova snapshot atômico. O CSV escapa fórmulas e inclui disponibilidade/escopo por célula. Veja INSIGHTS.md.


`media()->editCaption($actorId,$type,$mediaId,$text,$key,$variables=[])` altera a descrição do asset (`photo`/`video`); texto vazio remove a descrição. `deleteMedia($actorId,$type,$mediaId,$key,$context,$variables)` exclui o asset e exige flags reais de capa/perfil; não equivale apenas a retirar do álbum. Veja MIDIA.md.

`FacebookConnection::e2eeRuntime()` e `MessengerClient::e2eeRuntime()` obtêm um controlador explícito no mesmo dono, sem registrar ou conectar automaticamente. O runtime usa o bootstrap atual, fecha em stop/falha do dono e encaminha eventos encrypted_transport_connected/encrypted_message/encrypted_message_blocked/encrypted_message_receipt aos listeners do pai. Comandos permanecem fora de callbacks. `Runtime::connect()` pode publicar prekeys após registro/login confirmados e deve ser uma decisão explícita da aplicação. A fachada não expõe injeção de trust/raiz de testes; não há verificador remoto ICDC nativo de produção. Revisar um hash não contorna `messenger_icdc_verification_unavailable`. Ler status não executa I/O, e o envio comum de Messenger nunca faz fallback plaintext para threads criptografadas.
