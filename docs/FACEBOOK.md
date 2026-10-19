# Facebook PHP — biblioteca experimental

Os guias agora estão organizados na [documentação Facebook](facebook/index.html#inicio), separada do WhatsApp: instalação, conexão e sessão, Páginas, publicações, interações, inbox, anúncios, monitoramento, exemplos, [referência PHP](facebook/REFERENCIA.md) e [limites](facebook/LIMITES.md). Esta página conserva a visão geral do contrato.

`packages/facebook/` é o pacote PHP separado `mmhospedagem/mvo-facebook-php`, namespace `MVO\Facebook`. Organiza recursos Web de Páginas, publicações, comentários, reações, inbox, anúncios parciais e acompanhamento local. Instale a série experimental 0.1 com `composer require "mmhospedagem/mvo-facebook-php:^0.1"`; o Composer resolve o runtime Messenger. O [README do pacote](../packages/facebook/README.md) também descreve a alternativa `path` para desenvolvimento no monorepo.

A entrega é uma biblioteca. Não acrescenta rotas REST, painel, daemon de produto ou ferramentas ao MCP WhatsApp. `MVO\WaClient` e seus caminhos de autenticação permanecem separados. Instagram e Messenger E2EE não são habilitados por este pacote.

## Conectar, salvar e restaurar

`FacebookConnection` é a entrada principal da biblioteca: recebe e-mail/senha no primeiro login, salva a sessão confirmada e depois restaura com `connect()`. A aplicação não precisa instanciar um Messenger separado nem importar JSON de cookies.

```php
use MVO\Facebook\FacebookConnection;

$facebook = new FacebookConnection(
    sessionId: 'facebook-demo',
    authDirectory: __DIR__ . '/var/private-auth',
    privateStorageDirectory: __DIR__ . '/var/private-facebook',
);

// Primeiro acesso: valores recebidos por uma entrada privada da aplicação.
$facebook->login($email, $password);

try {
    $facebook->connect();
    $pages = $facebook->listPages();
    // Para uma aplicação persistente, continue poll() no loop externo.
} finally {
    $facebook->stop(); // Preserva a sessão confirmada para a próxima execução.
}
```

Em outra execução, construa `FacebookConnection` com **o mesmo ID e as mesmas pastas** e chame apenas `connect()`, sem repetir `login()`, e-mail ou senha. `poll($timeoutSeconds = 0.5)` mantém o transporte e seus cursores. `on($event, $handler)` recebe eventos; consultas/comandos pertencem ao loop externo depois dos callbacks. `events($after = 0, $limit = 100)` consulta os eventos persistidos.

`isConnected()`, `getState()` e `accountInfo()` permitem consultar o estado. `accountInfo()['has_saved_session']` significa sessão presente no armazenamento local, não que os cookies ainda sejam aceitos no servidor. Expiração/revogação exige novo login da mesma conta; outra conta precisa de outro ID. `metadata_connected` não significa E2EE pronto nem comprova todas as permissões de Páginas/anúncios.

O runtime PHP compartilhado mantém somente um dono do lock por sessão. Não abra simultaneamente outro `FacebookConnection` ou `MessengerClient` com o mesmo ID e diretório. `stop()` fecha os armazenamentos dos serviços, encerra o transporte e libera o lock, preservando cookies, escolhas e comandos persistidos. Monitores guardados separadamente continuam sob controle da aplicação.

Cookies confirmados e estado ficam em `<authDirectory>/messenger/<sessionId>/state.sqlite`; o nome interno corresponde ao runtime compartilhado. Dados dos serviços usam `<privateStorageDirectory>/<accountId>/`. Mantenha ambos os diretórios fora da raiz HTTP e do Git. A biblioteca persiste a sessão confirmada, não a senha ou o código do autenticador.

### Autenticação em duas etapas e verificação humana

`login()` pode lançar `MVO\Messenger\MessengerException` e deixar uma verificação pendente no mesmo processo por até dez minutos. `loginChallenge()` retorna somente `type`, `expires_at` e `ready`. Com `type=two_factor` e `ready=true`, receba o código real como string por entrada privada e chame `confirmLogin($code)`; depois, `connect()`. Preserve zeros iniciais. Não use TOTP em `type=captcha`, não envie códigos quando `ready=false` e não repita login/códigos automaticamente.

`stop()`, expiração e reinício cancelam o desafio ainda pendente. CAPTCHA/checkpoints continuam exigindo a ação humana no Facebook. O método `exportPrivateLoginChallenge()` fornece URL/cookies/expiração **secretos** somente para um bridge privado opcional da aplicação; nunca o exponha por HTTP, eventos, logs ou ferramentas. Após a verificação no mesmo contexto isolado, `completeBrowserLogin(CookieJar $cookies)` confirma a identidade por HTTPS antes de salvar. A biblioteca não abre o navegador nem resolve o desafio automaticamente. O [contrato compartilhado de autenticação](FACEBOOK-MESSENGER.md) descreve os formatos atualmente reconhecidos e seus limites.

Senha e código pertencem à entrada privada da aplicação. O exemplo [session.php](../packages/facebook/examples/session.php) aceita `--login` com as variáveis privadas `MVO_FACEBOOK_EMAIL`/`MVO_FACEBOOK_PASSWORD` ou restaura sem `--login`; use `--help`. Ele diagnostica desafios, cuja continuação pertence aos métodos PHP descritos acima. Não forneça senhas/códigos em argumentos CLI ou logs. O exemplo [discover.php](../packages/facebook/examples/discover.php) restaura a sessão e lista recursos somente para leitura.

### Compartilhar um cliente Messenger existente

Uma aplicação que já mantém `MessengerClient` conectado pode continuar usando `new FacebookClient($messenger->webSession(), $privateStorageDirectory)`. Essa entrada avançada compartilha conta, cookies e campos atuais sob o mesmo dono; não é necessária no fluxo de `FacebookConnection`.

`FacebookClient::close()` libera somente seus armazenamentos de comandos/preferências, sem parar o Messenger, revogar a sessão ou apagar arquivos. Depois de `close()`, construa outra fachada; consultas e aquisição de serviços na fachada fechada falham antes do I/O. `FacebookConnection::services()` fornece essa fachada para sua própria conexão. Não mantenha referências antigas a serviços depois de `stop()`; obtenha-os novamente ao restaurar.

`WebSession::privateRequestFields()` e respostas Web de baixo nível podem conter segredos. Não os inclua em HTTP, LLMs, exemplos, logs, eventos ou payloads de suporte.

## Descobrir e escolher recursos

`listPages()` consulta os formatos reconhecidos do launchpoint de Páginas. O resultado contém `items`, `page_info` e completude conforme a evidência do parser. Um launchpoint que não exponha toda a paginação não pode ser anunciado como todas as Páginas da conta.

`listAdAccounts()` consulta contas de anúncios somente quando houver binding Web estabelecido. Recursos indisponíveis permanecem explícitos; não criar contas, fabricar IDs nem usar a Graph API interna do Ads Manager para preencher a lista.

`selectPage($id)`, `selectAdAccount($id)` e `selectedContext()` são preferências locais vinculadas à conta. A seleção exige um ID previamente descoberto naquela conta. A aplicação apresenta nomes/IDs ao usuário e solicita a escolha; não utiliza automaticamente o primeiro item. Escolher uma Página não troca `c_user` nem altera a identidade global da conta. A operação concreta conserva os argumentos de actor/Página/conta que sua assinatura exigir.

## Organização dos serviços

| Acesso | Área | Condição de uso |
| --- | --- | --- |
| `pages()` | Páginas e descoberta | Sessão autenticada e bootstrap reconhecido. |
| `posts()` | Publicações | Documento, variáveis, providers e resultado conhecidos para cada método. |
| `comments()` | Comentários e respostas | IDs nativos exatos; observar a ordenação/paginação retornada. |
| `reactions()` | Reações | Não derivar autores de contadores ou inventar reaction IDs. |
| `inbox()` | Inbox comercial | Somente formatos comprovados; não constitui transporte E2EE. |
| `ads()` | Consultas Web de anúncios | Suporte parcial, por binding; escritas Graph dependentes indisponíveis. |
| `monitor()` | Diferenças locais | Snapshots normalizados fornecidos pelo coletor. |
| `command($id)` | Ledger local de comandos | Inspeção de estado; não repete automaticamente resultados incertos. |
| `stop()` | Conexão `FacebookConnection` | Encerra localmente e preserva a sessão salva. |
| `FacebookClient::close()` | Armazenamentos da fachada avançada | Não encerra o dono Messenger. |

Leia a assinatura em `packages/facebook/src/Service/` antes de gerar uma chamada. A fachada e o catálogo não representam administração completa do Facebook. Não use métodos, doc IDs ou schemas que não existam na revisão local.

Publicações expõem `list()`, `next()` e `createText()`. Comentários expõem `list($feedbackId, $variables = [], $actorId = null)`, `next($feedbackId, $cursor, $intentToken, $count = 20, $variables = [], $actorId = null)`, `previous()` com os mesmos argumentos, e `create()` para escrita. As leituras exigem providers atuais e `feedLocation`; copie `intent_token` da consulta inicial e o `end_cursor`/`start_cursor` correspondente ao paginar. Não invente token de ordenação nem derive feedback ID de post ID.

Reações expõem `summary($feedbackId, $scale = 1.0, $actorId = null)`, `list($feedbackId, $reactionId = null, $scale = 1.0, $actorId = null)`, `next($feedbackId, $cursor, $reactionId = null, $count = 10, $scale = 1.0, $actorId = null)`, `set()` e `remove()`. Preserve o filtro de reação ao seguir o cursor. `important_items` é um subconjunto separado da coleção paginada `items`; `reaction_types_count` conta tipos, não pessoas ou curtidas. Contadores Facebook, Instagram e agregados permanecem distintos, e desconhecidos continuam `null`.

As escritas exigem actor explícito, chave de idempotência e contexto real do composer/feedback, além dos providers quando requeridos. O pacote não preenche esses contextos com valores fictícios. O [README](../packages/facebook/README.md) e o [catálogo de Páginas](../packages/facebook/resources/README.md) listam assinaturas e pré-condições. Upload de mídia, edição/exclusão/moderação, insights orgânicos de Página e envio no inbox ainda precisam de contratos próprios.

Inbox é somente leitura, por `threads()` e `messages()`. Preserve separadamente o GraphQL ID e o `selection_id` devolvido; não substitua pela thread pessoal do Messenger. Actor e Página podem ser diferentes em uma Página delegada. O resultado `has_more=null` mantém a completude desconhecida.

Anúncios expõem `accounts()`, `account()`, `campaigns()`, `adsets()`, `ads()`, `previews()`, `insights()`, `insightsRange()` e `availability()`. `accounts()` usa um documento Web com limite fixo de duas opções, sem paginação estabelecida: `partial=true`, `exhaustive=false`. Não é inventário completo de contas e não executa o caminho que criaria uma conta automaticamente. Campanhas/conjuntos/anúncios exigem IDs já conhecidos; insights são agregados, limitados pelo documento e não fornecem breakdowns arbitrários. Prévia consulta um link existente, sem publicar criativo. `availability()` informa `validated_in_account=false`.

## Binding Web, providers e anúncios

O transporte PHP utiliza documentos persistidos Web de `/api/graphql/`. O catálogo registra a origem e o hash do artefato observado. Descobrir um documento não comprova que variáveis dinâmicas, providers, permissões, upload e resposta estejam estabelecidos para uma operação inteira.

Providers Relay são valores do contexto atual. Quando exigidos pelo catálogo, são validados antes do I/O. Não presumir valores de feature flags, copiar providers de outra conta ou injetar `null` para tornar uma query executável.

O usuário restringiu anúncios ao protocolo Web comprovado. A biblioteca não usa a Graph API interna do Ads Manager como fallback. Criar/publicar anúncios e alterar orçamento/status permanecem indisponíveis quando o fluxo encontrado depende dessa API. O suporte a consultas não implica suporte a mutations comerciais.

## Resultado e incerteza

`FacebookException` informa `reason`, `outcome`, `retryable` e `commandId` quando houver comando registrado. HTTP 200 não é confirmação suficiente: GraphQL pode retornar erro ou resultado parcial. Uma lista vazia com formato desconhecido também não é evidência de ausência de recursos.

Mutations de baixo nível usam ledger SQLite e chave de idempotência. A incerteza é registrada antes do I/O. Mesma chave com conteúdo diferente é conflito; uma ação sem resultado confirmado não é reenviada automaticamente. A aplicação precisa conservar o contexto e verificar `command($id)` antes de decidir uma nova tentativa. Isso protege chamadas implementadas; não torna uma escrita ainda desconhecida disponível.

`response_received` registra apenas resposta recebida/parseada, sem confirmar a operação ou permitir reenvio. `confirmed` exige a validação/projeção do serviço; o ledger guarda somente `result.projection` normalizado. Replay confirmado retorna essa mesma projeção sem HTTP. Se o resultado ou seus IDs não forem reconhecidos, a exceção conserva `commandId` e o comando permanece não confirmado, sem guardar a árvore GraphQL bruta.

## Monitor de publicações e interações

O monitor não abre conexões nem inicia polling. Ele recebe `SnapshotPage` com `SnapshotItem` normalizados de um coletor, dentro de um `ResourceScope(accountId, identityId, resourceType, resourceId)`. Conta, identidade ativa e recurso fazem parte da chave, evitando misturar Páginas, posts, comentários ou reações de contextos diferentes.

```php
use MVO\Facebook\Monitoring\ResourceScope;
use MVO\Facebook\Monitoring\SnapshotItem;
use MVO\Facebook\Monitoring\SnapshotPage;

$scope = new ResourceScope($accountId, $pageId, 'page.posts', $pageId);

// A aplicação só marca complete=true quando observou toda essa coleção.
$page = new SnapshotPage(
    snapshotId: $collectionId,
    items: [new SnapshotItem($postId, ['text' => $text], ['comments' => $commentCount])],
    cursor: $requestCursor,
    nextCursor: $nextCursor,
    complete: $collectionIsExhaustive,
);

$facebook->monitor()->ingest($scope, $page);
$events = $facebook->monitor()->events($scope, after: $lastProcessedEventId, limit: 100);
```

Os valores acima vêm de projeções normalizadas realmente obtidas; o exemplo não define um endpoint ou coletor fictício. `complete=true` não significa apenas sucesso HTTP nem última página de um limite escolhido pela aplicação.

- A primeira coleta exaustiva é baseline silencioso. Coletas iniciais parciais não geram falsos eventos de novidades históricas.
- Páginas ficam pendentes até uma página terminal. Recibos, projeção, cursores e eventos são persistidos atomicamente; reabrir o banco permite retomar a coleta.
- `item.created`, `item.updated` e `counters.changed` descrevem mudanças observadas após o baseline. Um novo campo/contador antes desconhecido não é uma variação comprovada.
- Coletas posteriores parciais não removem itens ausentes. Somente outra coleta exaustiva pode produzir `item.removed`: ausência na coleção, não prova de exclusão no servidor.
- A mesma página com o mesmo conteúdo é idempotente após reinício. Conteúdo diferente na mesma chave de página é rejeitado. Uma nova coleta precisa de novo `snapshotId`.
- A aplicação conserva seu cursor de consumo dos eventos e trata ações externas de forma idempotente. O monitor não promete entrega de webhook exatamente uma vez.

Consulte [contrato e exemplo do monitor](../packages/facebook/src/Monitoring/README.md) para cancelamento explícito de coleta pendente, campos permitidos, paginação local e execução com dados sintéticos.

## Evidência desta implementação

Os testes locais cobrem persistência/rollback, idempotência, isolamento de conta e identidade, paginação, normalização e formatos representados nas fixtures. Artefatos públicos fornecem evidência de documentos e procedimentos específicos. Nenhuma dessas verificações comprova execução de uma publicação, comentário, reação ou alteração de anúncios em uma conta real.

A autenticação e a sincronização de metadados Messenger já observadas são outra evidência, com alcance descrito no seu guia. Não as extrapole para gestão de Páginas, inbox comercial, anúncios ou monitoramento de eventos reais. A distribuição pública contém somente SDKs, exemplos e documentação; sessões, capturas autenticadas, a API consumidora e o laboratório privado não são distribuídos.
