# MVO Facebook PHP — contexto para LLMs e agentes

Este documento é autocontido para o pacote `mmhospedagem/mvo-facebook-php`, namespace `MVO\Facebook`. O código está em `packages/facebook/` e depende do runtime `mmhospedagem/mvo-messenger-php`, namespace `MVO\Messenger`. Ambos são distribuídos separadamente na série experimental 0.1; o monorepo mantém suas fontes e os portais de documentação. O SDK WhatsApp `mmhospedagem/mvo-whats-php` / `MVO\WaClient` é separado: não misture suas credenciais, IDs, classes, MCP ou eventos com Facebook.

O entregável Facebook é somente biblioteca PHP, exemplos e documentação. Não existe uma nova API REST, painel obrigatório ou servidor MCP Facebook. O laboratório loopback de validação é privado e não faz parte do repositório ou dos pacotes distribuídos. PHP executa autenticação, bootstrap, WebSocket DGW e sincronização Lightspeed; Node.js e Go não são runtimes do conector. O navegador isolado pode auxiliar uma verificação humana, mas não substitui o protocolo PHP.

## Instalação independente

Requer PHP 8.2+ de 64 bits e extensões `curl`, `json`, `openssl`, `sodium`, `pdo`, `pdo_sqlite`, conforme os `composer.json` dos dois pacotes. Na aplicação consumidora, instale:

```sh
composer require "mmhospedagem/mvo-facebook-php:^0.1"
```

O Composer resolve Messenger como dependência, sem instalar WhatsApp. Para usar somente `MessengerClient`, a alternativa é `composer require "mmhospedagem/mvo-messenger-php:^0.1"`. As fontes de distribuição são https://github.com/mmhospedagem/mvo-facebook-php e https://github.com/mmhospedagem/mvo-messenger-php; a documentação é https://mmhospedagem.github.io/mvo-whats-php/facebook/.

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
- `channel=messenger`, `transport=dgw_websocket` identificam o runtime interno. `has_saved_session` indica armazenamento local, não validade no servidor. `e2ee_ready=false`: o caminho pessoal E2EE não está implementado e não há fallback plaintext de uma thread criptografada/migrada.
- Estados comuns: `stopped`, `session_imported`, `awaiting_two_factor`, `awaiting_captcha`, `login_challenge_expired`, `authenticating`, `connecting_transport`, `metadata_connected`, `disconnected`. Falhas podem usar seu `reason` específico como estado.
- `on(string $event, callable $handler): static` recebe callbacks; não executar consultas/escritas neles. `events(int $after=0, int $limit=100): array` consulta eventos persistidos do runtime. Eventos de monitor são outra coleção.
- O runtime Messenger possui seus próprios métodos experimentais de texto sem criptografia em threads reconhecidas. Eles não equivalem a envio em todas as conversas e não foram adicionados como método de `FacebookConnection` ou como envio do inbox de Página.

## Serviços realmente presentes

`FacebookConnection::services()` obtém o `FacebookClient` do mesmo dono conectado. A conexão oferece os atalhos `pages()`, `posts()`, `comments()`, `reactions()`, `inbox()`, `ads()`, `listPages()`, `listAdAccounts()`, `selectPage($id)`, `selectAdAccount($id)`, `selectedContext()`, `monitor()` e `command($id)`.

| Área | Métodos implementados | Limite de uso |
| --- | --- | --- |
| Páginas | `pages()->list()` / `listPages()` | Descoberta parcial autenticada; retorna `items`, `page_info`, `exhaustive=false`. Perfil moderno conserva `actor_id` e `delegate_page_id` separados. Sem CRUD de Fanpages. |
| Contexto | `selectPage(string $id)`, `selectAdAccount(string $id)`, `selectedContext()` | Preferências locais entre recursos descobertos. Nenhuma seleção automática, troca de `c_user`, concessão remota ou injeção automática de argumentos. |
| Timeline | `posts()->list($profileId, $variables=[], $actorId=null)`, `next($profileId, $cursor, $variables=[], $actorId=null)` | Exige providers atuais; retorna `items` com IDs/texto/feedback/cursor e `page_info`. |
| Texto de publicação | `posts()->createText($actorId, $text, $idempotencyKey, $context, $variables=[])` | Composer `profile`; exige audiência, tracking, sessão e providers reais. Não prepara contexto, não faz upload, edição/exclusão ou menções. |
| Comentários | `comments()->list($feedbackId, $variables=[], $actorId=null)`, `next/previous($feedbackId, $cursor, $intentToken, $count=20, $variables=[], $actorId=null)`, `create($actorId, $feedbackId, $text, $idempotencyKey, $context, $variables=[])` | Leituras exigem `feedLocation` e providers. Preservar token de ordenação/cursor. Criação exige `session_id`, enum `feedback_source`; resposta usa `reply_comment_parent_fbid` exato. Sem edição/moderação/exclusão. |
| Reações | `summary($feedbackId, $scale=1.0, $actorId=null)`, `list($feedbackId, $reactionId=null, $scale=1.0, $actorId=null)`, `next($feedbackId, $cursor, $reactionId=null, $count=10, $scale=1.0, $actorId=null)`, `set(...)`, `remove(...)` | Leitura distingue contadores, coleção paginada e subconjunto importante. Escrita exige actor/feedback/reação observados, chave, contexto e providers. Não inferir pessoas a partir de contador. |
| Inbox de Página | `threads($actorId, $pageId, $limit=20, $beforeTimePrecise=null, $tags=[])`, `messages($actorId, $pageId, $selectedItemId, $limit=20, $beforeTimePrecise=null)` | Somente leitura; `messages()` recebe `selection_id` retornado. `has_more=null` é desconhecido. Sem envio ou fallback para Messenger pessoal. |
| Anúncios | `accounts()`, `account($id)`, `campaigns($ids)`, `adsets($ids)`, `ads($ids)`, `previews($ids)`, `insights($ids, $datePreset='today', $metrics=...)`, `insightsRange($ids, $since, $until, $metrics=...)`, `availability()` | Somente leituras Web observadas. Objetos por IDs conhecidos; não enumeram toda a conta. Disponibilidade não testa permissão. |
| Monitor | `monitor()` obtém `SnapshotMonitor` | Motor SQLite de diferenças de projeções; aplicação consulta a rede e agenda coletas. Sem webhook/polling automático. |

As assinaturas completas, projeções e defaults estão em [REFERENCIA.md](REFERENCIA.md), junto aos métodos das classes reais. Em `reactions()->set()`, os argumentos são `actorId`, `feedbackId`, `reactionId`, `idempotencyKey`, `context`, `variables=[]`; em `remove()`, omite-se `reactionId`.

`createText()` exige em `$context`: `composer_entry_point`, `composer_source_surface`, `composer_type=profile`, `logging.composer_session_id`, objeto `audience`, lista `tracking`. Comentário/reação exigem `session_id` e o enum `feedback_source` efetivo. Esses contextos são privados do fluxo Web, não chaves WhatsApp. `feedbackId` é opaco e não pode ser fabricado de `post_id`.

Alguns documentos exigem providers dinâmicos em `$variables`; defaults da assinatura não os tornam opcionais. `MVO\Facebook\Catalog\PageDocuments::providers($documentName)` lista os exigidos. Não há extração automática de todos os contexts/providers nesta revisão. Ausência reconhecida lança `facebook_provider_context_required` antes do I/O. Fixtures sintéticas e nomes/doc IDs encontrados estaticamente não autorizam fabricar valores.

Anúncios: `accounts()` usa `first=2`, com `partial=true`, `exhaustive=false`, sem inventário completo/paginação estabelecida. A ramificação de criação automática de conta é excluída. Métodos por IDs aceitam de 1 a 100 IDs nativos numéricos como strings. Insights têm agregado `first=1`, `filter_zero_delivery=true`, sem breakdown arbitrário. Métricas padrão: `SPEND`, `IMPRESSIONS`, `REACH`, `COST_PER_RESULT`, `PERFORMANCE_INDICATOR`, `ANCHOR_EVENT_ATTRIBUTION_SETTING`; aceita ainda `RESULTS`, até sete métricas. A constante PHP do default é privada. Valores monetários permanecem no formato do servidor.

Não implementar nem sugerir fallback para Marketing Graph API/`graph.facebook.com`. O usuário autorizou somente contratos Web `/api/graphql/` comprovados. Criação/publicação de campanhas, conjuntos, anúncios/criativos e alterações de orçamento/status não estão implementadas. `availability()` informa `read_only=true`, `validated_in_account=false`, `account_list_exhaustive=false`.

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

Ausentes: dispositivo Messenger E2EE/histórico seguro, envio pelo inbox de Página, upload/mídia/chamadas, CRUD/administração completa de Fanpages, moderação/edição/exclusão, administração de grupos/Marketplace, conector Instagram e mutations comerciais de anúncios. Nenhuma ferramenta Facebook entrou na allowlist MCP WhatsApp. Não criar stubs com sucesso fictício ou inventar caminhos para recursos ausentes.

Consulte [REFERENCIA.md](REFERENCIA.md), [README do pacote](../../packages/facebook/README.md), `packages/facebook/src/FacebookConnection.php`, `FacebookClient.php`, `Service/`, `Monitoring/`, e o contrato de autenticação `packages/messenger/src/MessengerClient.php` antes de propor chamadas. Preserve o runtime WhatsApp e alterações de outros agentes. A distribuição pública inclui apenas SDKs, exemplos e documentação; sessões, capturas autenticadas, laboratório privado e a API consumidora não pertencem a esse contrato.
