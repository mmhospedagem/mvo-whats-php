# Referência PHP do Facebook

Esta referência descreve o código de `packages/facebook`, namespace `MVO\Facebook`. Facebook e Messenger são distribuídos separadamente na série experimental 0.1. As assinaturas abaixo pertencem à biblioteca PHP; não são rotas REST nem ferramentas MCP. O SDK WhatsApp, `MVO\WaClient`, tem documentação e armazenamento próprios.

Use a [introdução Facebook](INTRODUCAO.md), o [guia do pacote](../../packages/facebook/README.md) e o [contexto para LLMs](LLMS.md). Fontes dos métodos: [FacebookConnection](../../packages/facebook/src/FacebookConnection.php), [FacebookClient](../../packages/facebook/src/FacebookClient.php), [serviços](../../packages/facebook/src/Service/) e [monitor](../../packages/facebook/src/Monitoring/README.md).

## Convenções e pré-condições

- IDs, cursores, tokens de ordenação e timestamps precisos conservam as strings retornadas pelo Facebook. `accountId`, `actorId`, `pageId`, `profileId`, `feedbackId` e `selection_id` têm funções distintas. Um nome, URL ou ID de publicação não substitui um `feedbackId` GraphQL.
- Um único dono mantém o lock da sessão. Chame `connect()` e mantenha `poll()` no processo da aplicação. Callbacks recebem eventos; consultas e comandos ficam no loop externo, depois dos callbacks.
- `selectPage()` e `selectAdAccount()` persistem preferências locais. Os serviços continuam recebendo os IDs explícitos de suas assinaturas; a seleção não injeta argumentos nem troca a identidade autenticada.
- `$variables = []` não dispensa providers/contexto atuais. Os catálogos descrevem os providers de cada documento. A aquisição universal não existe; criação, contexto de foto/vídeo, inbox e tabela de conteúdo têm leitores próprios descritos nos guias. Não copie valores de fixtures para uma conta real.
- `null` em um campo desconhecido não significa zero, ausência definitiva ou fim da paginação. Uma consulta reconhecida pode ser parcial.
- Resultados de criação/interação são projeções validadas da resposta. Não comprovam exibição no aplicativo, entrega, aprovação de anúncio ou leitura por uma pessoa.

## Conexão e sessão persistente

```php
use MVO\Facebook\FacebookConnection;

$facebook = new FacebookConnection(
    sessionId: 'facebook-demo',
    authDirectory: __DIR__ . '/var/private-auth',
    privateStorageDirectory: __DIR__ . '/var/private-facebook',
);
```

O construtor recebe `string $sessionId`, `string $authDirectory`, `string $privateStorageDirectory`. As dependências opcionais são `?HttpClient $httpClient = null`, `?callable $socketFactory = null`, `?callable $bundleLoader = null` e `float $timeoutSeconds = 20.0`, usadas também por testes isolados. Timeout deve ser finito, positivo e de até 120 segundos. Diretórios são locais e privados; o ID da sessão tem de 1 a 64 caracteres, começa com letra/número e aceita letras, números, `_` e `-`.

| Método de `FacebookConnection` | Retorno e comportamento |
| --- | --- |
| `login(string $email, string $password, ?WebLoginService $service = null): void` | Primeiro login ou renovação explícita. Descobre o formulário atual, cifra a senha e salva somente a sessão confirmada. Pode exigir verificação. Não inicia o transporte; chame `connect()` após confirmar o login. O terceiro argumento é uma dependência opcional para autenticação/testes. |
| `loginChallenge(): ?array` | `null` ou `{type, expires_at, ready}`. Projeção segura do desafio pendente. Expiração é um timestamp; o contexto privado fica em memória no mesmo processo por até dez minutos. |
| `confirmLogin(string $code): void` | Continua um desafio de autenticador reconhecido, com `type=two_factor` e `ready=true`. Preserve zeros iniciais do código. Depois da confirmação, chame `connect()`. CAPTCHA não aceita TOTP. |
| `connect(): void` | Restaura a sessão salva e inicia bootstrap, transporte e sincronização de metadados. Não repete e-mail/senha. Requer verificação pendente resolvida e lock livre. |
| `poll(float $timeoutSeconds = 0.5): void` | Processa transporte e sincronização. Timeout deve ser finito e não negativo. Sem conexão ativa, não cria outra sessão nem autentica automaticamente. |
| `on(string $event, callable $handler): static` | Registra callback que recebe um array de payload. Não execute consultas/escritas dentro dele. |
| `events(int $after = 0, int $limit = 100): array` | Consulta eventos persistidos do runtime da conta. É diferente dos eventos de diferenças de snapshots de `SnapshotMonitor`. |
| `isConnected(): bool` | Verifica estado `metadata_connected` e transporte ativo. Não comprova E2EE ou permissões comerciais. |
| `getState(): string` | Estado atual do runtime compartilhado; em falhas pode conter o `reason` específico. |
| `accountInfo(): array` | Projeção da conta, descrita abaixo. Não contém cookies nem senha. |
| `stop(): void` | Fecha serviços e transporte, cancela verificação pendente e libera o lock. Preserva a sessão confirmada e os dados persistidos; não faz logout remoto. |
| `services(): FacebookClient` | Obtém a fachada de serviços sob o mesmo dono conectado. Exige conexão utilizável e chamada fora dos callbacks. |

`accountInfo()` fornece `channel`, `provider`, `session_id`, `account_id`, `display_name`, `state`, `connected`, `transport`, `lightspeed_version`, `e2ee_ready`, `has_saved_session`, `capabilities` e `login_challenge`. `channel=messenger` e `transport=dgw_websocket` identificam o runtime interno compartilhado; não exigem outro cliente da aplicação. `has_saved_session=true` descreve armazenamento local e não validade remota. `e2ee_ready` permanece `false`. As capabilities desse runtime não tornam todos os métodos Messenger disponíveis na fachada Facebook.

| Estado comum | Significado |
| --- | --- |
| `stopped` | Dono parado localmente. |
| `session_imported` | Sessão salva após login confirmado; transporte ainda não conectado. |
| `awaiting_two_factor` / `awaiting_captcha` | Verificação pendente no processo que iniciou o login. Consulte `loginChallenge()`. |
| `login_challenge_expired` | Tentativa pendente expirou; não foi convertida em sessão salva. |
| `authenticating` | Bootstrap/autenticação da sessão em andamento. |
| `connecting_transport` | Transporte sendo estabelecido. |
| `metadata_connected` | Sincronização inicial de metadados confirmada; não comprova login/prontidão E2EE; os componentes experimentais são separados. |
| `disconnected` | Transporte caiu durante o processamento; examine a falha antes de reabrir. |

Para retomar, crie outro `FacebookConnection` com o mesmo ID e pastas e chame apenas `connect()`. O SQLite da conta fica em `<authDirectory>/messenger/<sessionId>/state.sqlite`; os serviços usam `<privateStorageDirectory>/<accountId>/`. Senha e código não são credenciais persistidas. Outra conta exige outro ID de sessão.

### Continuação humana avançada

`exportPrivateLoginChallenge(): array` fornece `{url, cookies, expires_at}` para um bridge privado da aplicação. Esse retorno contém segredos: não o envie por HTTP público, logs, eventos ou ferramentas de LLM. `completeBrowserLogin(CookieJar $cookies): void` recebe a continuação humana do mesmo contexto e verifica a identidade por HTTPS antes de salvar. O pacote não abre navegador automaticamente nem resolve CAPTCHA. Reinício e `stop()` descartam a tentativa pendente. Consulte o [contrato Messenger](../FACEBOOK-MESSENGER.md) para pré-condições e formatos ainda não suportados.

## Fachada de serviços e seleção

`FacebookConnection` oferece diretamente `pages()`, `posts()`, `comments()`, `reactions()`, `inbox()`, `media()`, `albums()`, `administration()`, `moderation()`, `insights()`, `contentInsights()`, `ads()`, `listPages()`, `listAdAccounts()`, `selectPage()`, `selectAdAccount()`, `selectedContext()`, `monitor()` e `command()`, delegando ao mesmo `FacebookClient` conectado.

| Método de `FacebookClient` | Retorno e efeito |
| --- | --- |
| `__construct(WebSession $session, string $privateStorageDirectory, ?DocumentRegistry $documents = null)` | Integração avançada com `MessengerClient::webSession()` já conectado. Usa o dono existente; não autentica nem copia cookies. O registry padrão combina os documentos Web observados de Páginas e anúncios. |
| `accountId(): string` | ID nativo da conta autenticada. |
| `pages(): PagesService`, `posts(): PostsService`, `comments(): CommentsService`, `reactions(): ReactionsService`, `inbox(): InboxService`, `ads(): AdsService` | Obtêm os serviços correspondentes. Não abrem outra conta. |
| `listPages(): array` | Delega a `pages()->list()` e guarda os recursos descobertos para seleção local. |
| `listAdAccounts(): array` | Delega a `ads()->accounts()` e guarda os recursos descobertos para seleção local. |
| `selectPage(string $id): void`, `selectAdAccount(string $id): void` | Persistem um recurso previamente descoberto para a mesma conta. Não escolhem o primeiro item automaticamente e não concedem permissão remota. |
| `selectedContext(): array` | `{account_id, page, ad_account}`; escolhas podem ser `null`. Recursos conhecidos conservam seus campos normalizados e `observed_at`. |
| `monitor(): SnapshotMonitor` | Abre o monitor local de `<privateStorageDirectory>/<accountId>/monitor.sqlite`. Não inicia consultas ou processos. |
| `command(string $id): ?array` | Consulta um comando local por seu ID. Retorna `null` se não existe; veja os estados de escrita abaixo. |
| `documentInventory(): array` | Lista `{name, kind, source_sha256}` dos documentos observados. Inventário de protocolo, não catálogo de permissões ou operações validadas em conta. Disponível na fachada retornada por `services()`. |
| `close(): void` | Fecha somente os armazenamentos dessa fachada. O dono Messenger continua separado. Com `FacebookConnection`, use `stop()` para encerrar também o dono. |

Após `close()`, não reutilize os serviços dessa fachada. Um monitor anteriormente guardado pela aplicação tem seu próprio armazenamento; o ciclo de vida dele não é controlado por `FacebookClient::close()`.

## Páginas

`PagesService::list(): array` faz a descoberta autenticada do launchpoint Web, verificando a identidade da conta e lendo somente coleções reconhecidas. Retorna:

```json
{
  "items": [
    {
      "id": "100000000001001",
      "name": "Página de exemplo",
      "type": "profile_plus",
      "actor_id": "100000000001001",
      "delegate_page_id": "100000000002001"
    }
  ],
  "page_info": {"end_cursor": null, "has_next_page": null},
  "exhaustive": false
}
```

IDs acima são fictícios. `type` reconhecido é `classic` ou `profile_plus`; `delegate_page_id` pode ser `null`. Preserve actor e Página delegada separadamente. A descoberta HTML acima permanece parcial; os métodos adicionais do checkout em desenvolvimento estão descritos abaixo e nos guias Páginas/Administração.

## Publicações

| Método de `PostsService` | Entrada e retorno |
| --- | --- |
| `list(string $profileId, array $variables = [], ?string $actorId = null): array` | Timeline do perfil informado, sob actor opcional. Exige providers atuais do documento inicial. Retorna `{items, page_info}`. |
| `next(string $profileId, string $cursor, array $variables = [], ?string $actorId = null): array` | Próxima página com `end_cursor` recebido. Exige o conjunto de variáveis/providers do documento paginado. Retorno igual ao inicial. |
| `createText(string $actorId, string $text, string $idempotencyKey, array $context, array $variables = []): array` | Cria texto pelo composer de timeline `profile`, com contexto real e idempotência. Retorna `{post_id, story_id, url, publishing_flow, scheduled_publish_time}`; campos não informados ficam `null`. |

Itens de leitura contêm `id`, `post_id`, `text`, `url`, `feedback_id`, `cursor`. `page_info` contém `end_cursor` e `has_next_page`; ambos podem ser desconhecidos. `feedback_id` deve vir desse retorno ou de outra resposta nativa reconhecida.

Para `createText()`, `$context` exige `composer_entry_point`, `composer_source_surface`, `composer_type=profile`, `logging.composer_session_id`, objeto `audience` e lista `tracking`. Aceita ainda `navigation_data` e `is_tracking_encrypted`. `$variables` contém os providers atuais do documento de criação. A biblioteca não descobre automaticamente esses valores nem presume audiência. Não há anexos, upload, menções, edição, exclusão ou publicação cruzada neste método.

## Comentários e respostas

| Método de `CommentsService` | Entrada e retorno |
| --- | --- |
| `list(string $feedbackId, array $variables = [], ?string $actorId = null): array` | Primeira página na ordenação escolhida pelo servidor. `$variables` exige `feedLocation` real e os providers atuais. |
| `next(string $feedbackId, string $cursor, string $intentToken, int $count = 20, array $variables = [], ?string $actorId = null): array` | Pagina com `end_cursor`, mesmo `intent_token` e `feedLocation`. `count`: 1 a 100. |
| `previous(string $feedbackId, string $cursor, string $intentToken, int $count = 20, array $variables = [], ?string $actorId = null): array` | Pagina com `start_cursor` e o mesmo token de ordenação. `count`: 1 a 100. |
| `create(string $actorId, string $feedbackId, string $text, string $idempotencyKey, array $context, array $variables = []): array` | Comenta ou responde, com IDs exatos e contexto. Retorna a projeção do comentário criado. |

Leituras retornam `feedback_id`, `items`, `intent_token`, `count`, `total_count` e `page_info`. `page_info` contém `end_cursor`, `start_cursor`, `has_next_page`, `has_previous_page`. Contagens e indicadores podem ser `null`. Itens contêm `id`, `legacy_fbid`, `text`, `author: {id, name}`, `created_time`, `feedback_id`, `parent_id` e `cursor`. A criação retorna esses campos de comentário, sem o cursor da coleção.

O contexto de criação exige `session_id` e `feedback_source`, o nome do enum do contexto efetivo. Campos opcionais aceitos: `attribution_id_v2`, `tracking`, `reply_comment_parent_fbid`, `reply_target_clicked`, `formatting_style`, `is_self_disclosed_as_ai_generated`. Para responder, use o ID nativo do pai em `reply_comment_parent_fbid`. O documento também exige providers/renderização atuais em `$variables`. Os documentos de leitura inicial e paginação têm conjuntos próprios de providers. Não invente `intentToken` para mudar ordenação. O checkout em desenvolvimento acrescenta edição/exclusão de texto e ocultação contextualizadas; veja as assinaturas abaixo.

## Reações e curtidas

| Método de `ReactionsService` | Entrada e retorno |
| --- | --- |
| `summary(string $feedbackId, float $scale = 1.0, ?string $actorId = null): array` | Consulta tipos e contadores, sem inferir autores a partir deles. |
| `list(string $feedbackId, ?string $reactionId = null, float $scale = 1.0, ?string $actorId = null): array` | Coleção de pessoas/perfis que reagiram; `null` consulta todas as reações reconhecidas. |
| `next(string $feedbackId, string $cursor, ?string $reactionId = null, int $count = 10, float $scale = 1.0, ?string $actorId = null): array` | Próxima página, com mesmo filtro de reação. `count`: 1 a 100. |
| `set(string $actorId, string $feedbackId, string $reactionId, string $idempotencyKey, array $context, array $variables = []): array` | Define uma reação cujo ID foi observado no contexto atual. Exige contexto e idempotência. |
| `remove(string $actorId, string $feedbackId, string $idempotencyKey, array $context, array $variables = []): array` | Remove a reação do actor pelo valor de remoção estabelecido pelo próprio documento. |

`scale` deve ser finito, maior que zero e de até 4. Não invente uma tabela de emojis para IDs; use IDs observados, inclusive em `supported_reaction_infos` quando fornecido.

`summary()` retorna `feedback_id`, `reactions: [{reaction_id, name, count, count_label}]`, `reaction_types_count`, `facebook_reaction_count`, `instagram_reaction_count`, `aggregated_reaction_count`, `display_strategy`. Número de tipos não é total de curtidas. As três contagens de plataformas/agrupamento são medidas distintas, possivelmente `null`; sua presença não implementa um conector Instagram.

Leituras paginadas retornam `feedback_id`, `reaction_filter`, `items`, `important_items`, `page_info`. Itens contêm `id`, `type`, `name`, `profile_picture_url`, `url`, `reaction_id`, `cursor`. `important_items` é um subconjunto separado, não uma segunda página. Preserve `reaction_filter` em `next()` e leia `page_info.end_cursor` / `has_next_page` sem transformar `null` em lista esgotada.

Escritas exigem `$context.session_id`, `$context.feedback_source` e os providers atuais em `$variables`. Aceitam também `attribution_id_v2`, `tracking`, `feedback_referrer`. Retornam `feedback_id`, `viewer_reaction_known`, `viewer_reaction_id`, `reaction_count` e `supported_reaction_infos: [{id}]`. `viewer_reaction_known=false` distingue campo ausente de uma reação conhecida removida.

## Inbox de Página

| Método de `InboxService` | Entrada e retorno |
| --- | --- |
| `threads(string $actorId, string $pageId, int $limit = 20, ?string $beforeTimePrecise = null, array $tags = []): array` | Consulta conversas do inbox comercial. Limite: 1 a 100. Tags: até 20 strings exatas observadas. Retorna `{items, sync_sequence_id, has_more}`. |
| `messages(string $actorId, string $pageId, string $selectedItemId, int $limit = 20, ?string $beforeTimePrecise = null): array` | Consulta mensagens da seleção retornada em `threads()`. Retorna `{thread, items, has_more}`. |

O cursor temporal é a string numérica observada de 1 a 24 dígitos; não fabrique uma data ou converta unidades. Conversas projetadas contêm `id`, `selection_id`, `thread_key: {thread_fbid, other_user_id}`, `name`, `folder`, `unread_count`, `messages_count`, `updated_time_precise`, `cannot_reply_reason`, `composer_input_disabled`. Passe `selection_id` reconhecido para `messages()`, preservando o `id` GraphQL original separadamente.

Mensagens projetadas contêm `id`, `message_id`, `offline_threading_id`, `sender_id`, `text`, `snippet`, `timestamp_precise`, `unread`. `has_more` permanece `null`: não há evidência confiável de esgotamento nesse contrato. As leituras não alteram estado/atribuição nem garantem histórico completo. Há um sender Mercury experimental, descrito abaixo, sem comprovação de aceitação atual. Ele não usa conversas pessoais Messenger como fallback de Página.

## Anúncios

| Método de `AdsService` | Entrada e retorno |
| --- | --- |
| `accounts(): array` | Opções do contexto atual, no máximo duas. Retorna `{items, partial: true, exhaustive: false, maximum_items: 2, source}`. Não cria conta automaticamente. |
| `account(string $id): ?array` | Metadados de uma conta por ID legado exato, ou `null` se a resposta reconhecida não contiver a conta. |
| `campaigns(array $ids): array` | Metadados de entrega/status/estrutura de campanhas cujos IDs já são conhecidos. |
| `adsets(array $ids): array` | Metadados brutos reconhecidos de conjuntos de anúncios por IDs conhecidos. |
| `ads(array $ids): array` | Metadados de anúncios existentes por IDs conhecidos. |
| `previews(array $ids): array` | Consulta prévia/link existente. Não cria nem publica um criativo. |
| `insights(array $ids, string $datePreset = 'today', array $metrics = DEFAULT_METRICS): array` | Métricas agregadas pelo preset aceito pelo servidor. |
| `insightsRange(array $ids, string $since, string $until, array $metrics = DEFAULT_METRICS): array` | Datas `YYYY-MM-DD`, intervalo inclusivo de entrada, com `since <= until`. |
| `availability(): array` | Descreve contratos e limites implementados; `read_only=true`, `validated_in_account=false`, `account_list_exhaustive=false`. Não testa permissões remotamente. |

Itens de conta contêm `id`, `name`, `currency`, `status`; campos podem ser `null`. Métodos por IDs recebem listas de 1 a 100 IDs numéricos exatos como strings. Retornam listas de nós dos tipos esperados; preservam os campos nativos retornados. Eles não enumeram campanhas/anúncios de toda a conta e não produzem um schema comercial completo normalizado.

`DEFAULT_METRICS` é a descrição do default da assinatura: `SPEND`, `IMPRESSIONS`, `REACH`, `COST_PER_RESULT`, `PERFORMANCE_INDICATOR`, `ANCHOR_EVENT_ATTRIBUTION_SETTING`. O argumento aceita até sete métricas dessa lista ou `RESULTS`. A constante é privada: para definir outro subconjunto, passe strings explícitas. Insights usam o agregado observado `first=1` e `filter_zero_delivery=true`; não oferecem breakdowns arbitrários ou relatório completo da conta. Valores monetários e métricas preservam o formato nativo sem conversão presumida.

Não há enumeração completa de contas, criação de campanhas/conjuntos/anúncios/criativos, alteração de orçamento/status ou publicação. A Graph API interna do Ads Manager não é usada como alternativa. Chamadas Web `/api/graphql/` reconhecidas e Marketing Graph API são caminhos distintos; o segundo foi excluído desta integração.

## Escritas, ledger e erros

`FacebookException` estende `RuntimeException` e expõe `reason`, `outcome`, `retryable`, `commandId`. Uma escrita que alcança o ledger conserva `commandId` também quando falha. O retorno de `command($id)` contém `id`, `operation`, `state`, `result`, `created_at`, `updated_at`.

| Estado do comando | Interpretação |
| --- | --- |
| `prepared` | Reserva local criada antes do envio. Não é sucesso remoto. |
| `unknown` | Incerteza gravada antes do I/O, inclusive quando a resposta não foi confirmada. Não repetir automaticamente. |
| `response_received` | Resposta recebida e parseada; projeção do serviço ainda não confirmada. Não autoriza reenvio. |
| `confirmed` | Projeção do resultado validada e salva em `result.projection`. Mesma chave/conteúdo retorna essa projeção sem outra requisição. |

O ledger também admite estados de finalização `not_confirmed` e `rejected` para sua infraestrutura; os métodos atuais não devem ser tratados como produtores de todas essas transições. Uma nova chave não resolve a incerteza do comando anterior. Repetir chave com outro conteúdo é conflito, não atualização.

| Diagnóstico relevante | Ação da aplicação |
| --- | --- |
| `facebook_provider_context_required` | Fornecer providers/contexto atual do documento; não preencher com valores arbitrários. |
| `facebook_resource_not_discovered` | Descobrir o recurso para a mesma conta antes de persistir a seleção. |
| `facebook_authentication_required`, `facebook_csrf_unavailable`, `facebook_identity_unconfirmed` | Conferir autenticação, verificação e identidade da sessão. Não copiar tokens de outra conta. |
| `facebook_pages_unavailable`, `facebook_ads_invalid_response`, `facebook_inbox_selection_mismatch` | Resposta não corresponde ao contrato esperado. Preserve IDs/estado e examine o binding; não tratar como lista vazia/sucesso. |
| `facebook_idempotency_conflict` | A chave pertence a outro conteúdo. Não trocar silenciosamente a chave e reenviar. |
| `facebook_command_uncertain`, `facebook_command_unconfirmed` | Consultar `commandId` e conciliar com o estado real. Não há retry automático de escrita. |
| `facebook_transport_failed`, `facebook_rate_limited`, `facebook_http_failed` | Respeitar limitação/falha e o grau de incerteza. `retryable` não autoriza repetir uma mutation incerta. |

Erros de autenticação/ciclo de vida vêm de `MVO\Messenger\MessengerException`, com `reason` e `retryable`, por exemplo `session_required`, `session_expired`, `login_verification_pending`, `login_challenge_expired`, `login_identity_mismatch`. Argumentos inválidos podem lançar `InvalidArgumentException`; uso de fachada fechada, callback indevido e conflito de ciclo de vida podem lançar `LogicException`. A tabela é uma orientação, não uma enumeração fechada de todos os diagnósticos do protocolo.

## Monitoramento local

O namespace é `MVO\Facebook\Monitoring`. A aplicação faz a coleta, projeta dados normalizados, define a completude verdadeira e chama o monitor. Não há consulta de rede, scheduler, webhook ou alteração no Facebook feita por esses objetos.

| Classe/método | Contrato |
| --- | --- |
| `ResourceScope(string $accountId, string $identityId, string $resourceType, string $resourceId)` | Escopo exato de conta, identidade ativa e recurso. `key(): string` gera a chave local; `toArray(): array` conserva os quatro descritores. |
| `SnapshotItem(string $id, array $fields = [], array $counters = [])` | Projeção normalizada. `toArray(): array` devolve `{id, fields, counters}`. Campos ausentes são desconhecidos; `null` explícito limpa um campo conhecido. Contadores são inteiros não negativos. |
| `SnapshotPage(string $snapshotId, array $items, ?string $cursor = null, ?string $nextCursor = null, bool $complete = false)` | Página ordenada de até 5.000 `SnapshotItem`. `complete=true` só quando toda a coleção foi observada e a paginação terminou. `toArray(): array` projeta a página. |
| `SnapshotMonitor(string $sqliteFile)` | Armazenamento local privado, ou `:memory:` para execução efêmera. |
| `ingest(ResourceScope $scope, SnapshotPage $page): array` | Persiste uma página. Retorna `{duplicate, committed, baseline_created, events_created}`. Só uma página terminal confirma seus itens pendentes. |
| `state(ResourceScope $scope): array` | `{baseline_ready, last_snapshot_id, last_complete, pending_snapshot_id, next_cursor, pending_pages, known_items}`. Permite retomar a coleta. |
| `items(ResourceScope $scope, ?string $afterId = null, int $limit = 100): array` | Itens confirmados, paginação local estável pelo ID exato. |
| `events(ResourceScope $scope, int $after = 0, int $limit = 100): array` | Eventos locais após o ID informado, com `{id, type, item_id, payload, created_at}`. O consumidor salva seu próprio cursor/ack. |
| `discardPending(ResourceScope $scope, string $snapshotId): void` | Descarta explicitamente uma coleta pendente sem apagar itens/eventos confirmados. |

Os limites de paginação local de `items()` e `events()` são de 1 a 500; o cursor de evento deve ser não negativo. Campos normalizados de `SnapshotItem`: `text`, `message`, `name`, `author_id`, `parent_id`, `created_time`, `updated_time`, `reaction_type`, `permalink_url`, `status`, `object_type`; valores são string, inteiro, booleano ou `null`. Campos fora dessa lista, HTML bruto e credenciais não são aceitos. Nomes de contadores seguem o padrão simples do componente. Consulte o [guia do monitor](../../packages/facebook/src/Monitoring/README.md) para invariantes e exemplo completo.

A primeira coleta exaustiva cria baseline silencioso. Antes dela, coletas parciais não notificam histórico como novidade. Coletas posteriores geram `item.created`, `item.updated`, `counters.changed`; somente uma coleta exaustiva posterior pode produzir `item.removed`, significando ausência na coleção observada, sem provar exclusão no Facebook. Cursor incorreto, página repetida com conteúdo diferente ou nova coleta sobre outra pendente são rejeitados.

## Evidência e recursos ainda ausentes

Em 18/10/2026, o runtime compartilhado confirmou autenticação com continuação humana, persistência/restauração e `metadata_connected`, com metadados de 13 conversas e observação do WebSocket por 12 segundos. A leitura autenticada do launchpoint de Páginas também forneceu uma captura reconhecida pelo parser, com sete Páginas modernas e `exhaustive=false`. Esse alcance não comprova escrita, todas as Páginas/permissões, outras contas, todos os desafios ou estabilidade prolongada.

Os serviços usam documentos observados e testes locais de formato, projeção, isolamento e idempotência. Publicação, comentários, reações, consultas comerciais de inbox/anúncios e eventos reais desses serviços não foram validados em conta nesta etapa. Continuam ausentes prontidão integrada de mensagens E2EE pessoais, transferência de propriedade/todos os modelos de acesso, fluxos gerais de Página/reels/edição audiovisual, denúncias finais, relatórios orgânicos completos, chamadas, grupos, Marketplace e Instagram. As extensões locais abaixo incluem álbuns, upload de vídeo, moderação de usuários e relatórios paginados, com alcance próprio. Não há API REST Facebook nem ferramentas Facebook no MCP WhatsApp.

## Extensões no checkout em desenvolvimento

As primeiras releases Facebook 0.1.0/Messenger 0.1.1 não incluem estas extensões. `FacebookConnection` e `FacebookClient` agora expõem `media()`, `administration()` e `insights()`. `FacebookConnection::e2eeDevice(?RegistrationTransport $transport=null): DeviceManager` delega ao mesmo dono Messenger. O objeto de administração é preservado durante a vida dessa fachada; guarde-o para a sequência de reautenticação/ação. `stop()` invalida os serviços do dono anterior.

### Páginas, pessoas e métricas

| Método | Contrato |
| --- | --- |
| `pages()->creationContext(float $scale=1): array` | Consulta capacidade atual da pessoa autenticada e categorias sugeridas. Rejeita actor Business/Página como substituto da pessoa; não cria nada. |
| `pages()->creationRequestContext(): array` | GET autenticado da rota `pages/creation/?ref_type=launch_point`, com o mesmo dono. Lê identidade e gate 20935 em JSON inerte para obter `creation_source`; ausência não recebe fallback. Retorna contexto utilizável por `create()`, sem copiar storage do navegador. |
| `pages()->categories(string $search, int $limit=10): array` | Busca categorias por nome e conserva seus IDs nativos. Limite local, sem inventar paginação/exaustividade. |
| `pages()->create(string $name, array $categoryIds, string $idempotencyKey, array $context, string $bio=''): array` | Criação inicial com 1 a 3 categorias, contexto Web atual e pessoa autenticada. Retorna perfil/Página delegada distintos, `setup_complete=false`; não completa o assistente posterior. |
| `pages()->managed(float $scale=1): array` | Recursos gerenciados modernos, com `items` e `page_info`; preserva perfil/Página delegada. |
| `pages()->next(string $cursor, int $count=20, float $scale=1): array` | Próxima página com cursor recebido; count de 1 a 100. |
| `pages()->get(string $pageId, ?string $actorId=null): ?array` | Identificação mínima `{id,name}` observada. |
| `pages()->field(string $pageId, string $field, array $context, ?string $actorId=null, float $scale=1): array` | Valor atual de `name`, `blurb`, `email`, `phone` ou `website`; contexto exige enums reais `end_point`/`entry_point`. |
| `pages()->updateField(string $pageId, string $field, string $value, string $idempotencyKey, array $context, ?string $actorId=null): array` | Uma alteração independente; nome pode exigir aprovação posterior. |
| `pages()->delete(string $pageId, string $idempotencyKey, ?string $actorId=null): array` | Solicita exclusão; não comprova exclusão permanente nem inventa prazo de recuperação. |
| `administration()->people(string $businessId, array $providers, int $count=20): array` | Pessoas/convites do portfólio e tarefas observadas, sem confundir Business ID com Page ID. |
| `administration()->nextPeople(string $businessId, string $cursor, array $providers, int $count=20): array` | Paginação com mesmo portfólio e providers atuais. |
| `administration()->pageAccess(string $pageId): array` | Indicador devolvido para gerenciar administradores; não concede esse acesso. |
| `administration()->updateBusinessUser(string $businessId, string $businessUserId, array $values, string $idempotencyKey): array` | Requer a pessoa observada por `people/nextPeople` no mesmo serviço/portfólio nos últimos cinco minutos. Valores completos atuais: `firstName`, `lastName`, `email`, `business_account_task_ids`, `expireTime`, `clientTimezoneID`; pode retornar revisão pendente. |
| `administration()->inviteBusinessPeople(string $businessId, array $emails, array $context, string $idempotencyKey): array` | Convites explícitos ao portfólio com tarefas/assets atuais; convite emitido não comprova aceitação ou propriedade. |
| `administration()->permissions(string $profileId): array` | Permissões do usuário autenticado no perfil; não é lista completa de todos os administradores. |
| `administration()->reauthenticationKey(): array` | Chave pública atual para o envelope de senha da confirmação. |
| `administration()->reauthenticateEncrypted(string $encryptedPassword, string $idempotencyKey): array` | Envelope privado `#PWD_BROWSER`; o timestamp real `verified_at` não é renovado por replay do ledger. |
| `administration()->removeMyLimitedAccess(string $profileId, string $idempotencyKey, float $scale=1): array` | Apenas a saída do próprio acesso limitado após confirmação recente; não remove administradores arbitrários. |
| `insights()->metrics(): array` | Pares observados: Views, Viewers, Reach, Interactions, Comments, Shares, Visits, NewFollowers, NetFollows. |
| `insights()->singleValue(string $pageId, string $metric, string $since, string $until, string $callerId): array` | Valor/delta; datas reais YYYY-MM-DD e caller atual do Business Suite. |
| `insights()->timeSeries(string $pageId, string $metric, string $since, string $until, string $callerId): array` | Pontos diários, sem preencher datas ou valores ausentes com zero. |
| `insights()->breakdown(string $pageId, string $metric, string $breakdown, string $since, string $until, string $callerId): array` | Buckets devolvidos; `PAID_ORGANIC` somente quando suportado pelo servidor. |

`availability()` em Páginas/Administração/Insights informa a cobertura do contrato, não testa acesso remoto. [Páginas](PAGINAS.md), [administração](ADMINISTRACAO.md) e [insights](INSIGHTS.md) detalham projeções e contextos. Não existe CRUD arbitrário de todos os objetos do painel.

A criação exige perfil pessoal, como o formulário Web. Consulte `creationContext()`, obtenha categorias nativas, use `creationRequestContext()` e apresente a ação/termos na aplicação antes de chamar `create()`. Nome aceita 2 a 75 unidades UTF-16; bio, até 255; categorias, de 1 a 3 IDs distintos como strings. O retorno confirma o par `profile_id`/`page_id`, sem inferir que o assistente posterior foi concluído. Nenhuma Página foi criada durante esta análise.

Em tentativas novas, `create()` verifica novamente o actor pessoal e `can_create_page===true` antes da mutation, após reserva/replay do ledger. Falha/negativa do preflight conserva `commandId`, `execution_started=false` e `outcome=not_executed`. Replay confirmado não faz outra consulta; uma criação incerta continua bloqueada.

### Conteúdo e fotos

| Método | Contrato |
| --- | --- |
| `posts()->editText(string $actorId, string $storyId, string $text, string $idempotencyKey, array $context, array $variables): array` | Edição de texto de story existente sem anexos; IDs e retorno correlacionados. |
| `posts()->delete(string $actorId, string $storyId, string $idempotencyKey, array $variables): array` | Exclusão de story pelo ID opaco exato e providers atuais. |
| `comments()->editText(string $actorId, string $commentId, string $text, string $idempotencyKey, array $context, array $variables): array` | Texto e contexto atual de formatação; não modifica anexos. |
| `comments()->delete(string $actorId, string $commentId, string $idempotencyKey, array $context, array $variables): array` | Correlação estrita de `deleted_comment_id`. |
| `comments()->setHidden(string $actorId, string $commentId, bool $hidden, string $idempotencyKey, array $context, array $variables): array` | Retorna flags reais de viewer/dono; ocultação pessoal não é moderação global. |
| `media()->photoContext(string $actorId): PhotoUploadContext` | Lê `ReactComposerMediaConfig.photo` do bootstrap autenticado allowlisted; ausência não cria defaults anônimos. |
| `media()->uploadPhoto(string $actorId, UploadFile $file, string $idempotencyKey, PhotoUploadContext $context): array` | JPEG/PNG/WebP, até 25 MiB por política do SDK. Retorna `photo_id`, `uploaded=true`, `published=false` e dimensões quando presentes. |

`UploadFile(string $path, string $filename, string $mediaType)` pertence a `MVO\Messenger\Transport`. Aceita arquivo local legível; recusa URL/stream e nome com caminho/controle. O ledger vincula hash, tamanho e contexto, sem persistir o caminho privado. Upload multipart usa a mesma conta/lock, TLS verificado e endpoint fixo, sem redirecionamento. Veja [fotos](MIDIA.md), [publicações](PUBLICACOES.md) e [comentários](COMENTARIOS-REACOES.md).

### Inbox e dispositivo

`inbox()->lightspeedContext(string $actorId, string $pageId, string $selectedItemId, ?callable $bundleLoader=null): array` adquire contexto atual via consulta de preflight e GET autenticado do inbox. Extrai app IDs/schema reais, mantém UUIDv4 próprio por conta e retorna OTID/epoch novos. Persista esse resultado com a chave antes de enviar; não gere outro contexto para repetir a mesma tentativa.

`inbox()->sendTextLightspeed(string $actorId, string $pageId, string $selectedItemId, string $text, string $idempotencyKey, array $context): array` usa task 46 e stream DGW próprio vinculado ao asset da Página, com conta/lock do dono atual. Exige `dgw_app_id`, `dgw_app_version`, `server_app_id`, `schema_version`, `device_id`, `recipient_id`, `offline_threading_id`, `epoch_id` e aceita `origin`. O gateway fecha no final. Confirma OTID, mensagem, thread, autor Página e texto; `server_ack` não comprova entrega/leitura. Configuração ausente/divergente não usa fallback.

`inbox()->sendText(string $actorId, string $pageId, string $selectedItemId, string $text, string $idempotencyKey, array $context): array` implementa o contrato Mercury legado observado. Exige contexto real e preflight da Página/thread, preserva conta/ator/Página/destinatário e correlaciona a única action do servidor. OTID/timestamp devem ser persistidos e reutilizados com a chave. Não é fallback automático do Lightspeed. Consulte [Inbox](INBOX.md).

`e2eeDevice()` oferece `prepare(): array`, `registrationRoster(): array`, `register(string $expectedRosterHash): array` e `status(): array`. Preparação é local; inventário é leitura autenticada; registro é mutação explícita. GMP adicional é necessário. O CAT precisa ser atual e da mesma conta. Resultado incerto conserva as mesmas chaves e bloqueia repetição; `registered` não altera `e2ee_ready=false`. Noise, certificados/trust, Signal/fan-out, envelopes, recibos e histórico continuam sem implementação completa. Consulte [dispositivo](DISPOSITIVO-E2EE.md).


## Álbuns, cargos, moderação e relatórios no checkout

| Entrada | Guia e contrato |
| --- | --- |
| `albums(): AlbumService` | [Mídia](MIDIA.md): leitura/paginação, criação, edição, exclusão, anexação de IDs já carregados, capa e ordem. |
| `media(): MediaService` | [Mídia](MIDIA.md): configuração/upload de vídeo e publicação de fotos e vídeo carregados, além do upload de fotos. Upload não publica. |
| `administration(): PageAdministrationService` | [Administração](ADMINISTRACAO.md): convites/cargos de Página e acesso/tarefas de assets, com preflight e confirmação recente. |
| `moderation(): ModerationService` | [Administração](ADMINISTRACAO.md): `banUser($pageId,$userId,$key)`, `unbanUser(...)`, `setMessagesBlocked($pageId,$userId,$blocked,$key)`. |
| `contentInsights(): ContentInsightsService` | [Insights](INSIGHTS.md): `context`, `columns`, `listContent`, `next`, `report` e `availability`. |
| `ContentReportExporter::csv(array $report): string` / `json(array $report): string` | Exportação local do relatório normalizado, sem executar job Meta. |

Todos os serviços mantêm a sessão/dono existente; a fachada valida seu estado antes de disponibilizá-los. Os contratos ampliados ainda não foram validados por alterações em contas reais nesta revisão. Consulte os guias para assinaturas, limites e contexto obrigatório antes de enviar comandos.


### Métodos de mídia e controlador criptografado

| Método | Contrato |
| --- | --- |
| `media()->publishPhotos(string $actorId,array $photoIds,string $text,string $key,array $context,array $variables): array` | Publica fotos já carregadas com chave própria e o composer atual de timeline `profile`. |
| `media()->publishVideo(string $actorId,string $videoId,string $text,string $key,array $context,array $variables): array` | Branch de vídeo sem edição do mesmo composer. Não confirma processamento/reprodução/reels. |
| `media()->editCaption(string $actorId,string $type,string $mediaId,string $text,string $key,array $variables=[]): array` | Legenda de `photo`/`video`; string vazia remove a descrição. Fotos exigem scale atual. |
| `media()->deleteMedia(string $actorId,string $type,string $mediaId,string $key,array $context,array $variables): array` | Exclui o asset, não somente o vínculo com álbum. Exige flags reais de capa/perfil do contexto. |
| `FacebookConnection::e2eeRuntime(): MVO\Messenger\E2ee\Runtime` | Controlador explícito no mesmo dono, sem registro ou conexão automáticos. `connect()` pode publicar prekeys após registro/login confirmados. |

`MessengerClient::e2eeRuntime()` oferece o mesmo controlador, preservado enquanto a aplicação mantiver sua referência. `stop()`/falha do dono fecha o transporte filho antes de liberar o lock. Eventos privados `encrypted_transport_connected`, `encrypted_message`, `encrypted_message_blocked` e `encrypted_message_receipt` usam os listeners do dono; execute comandos no loop externo. A fachada não fornece bypass do verificador ICDC nem da raiz de certificados. O padrão mantém `e2ee_ready=false` e bloqueia texto/decodificação com `messenger_icdc_verification_unavailable`. Consulte [Dispositivo E2EE](DISPOSITIVO-E2EE.md).
