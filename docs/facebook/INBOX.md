# Inbox de Página

`inbox()` oferece consultas de conversas/mensagens, aquisição de contexto e envio experimental Lightspeed pelo mailbox da Página. Um contrato Mercury legado permanece separado. Ambos exigem escopo explícito, preflight e idempotência; não usam o envio pessoal Messenger como substituto.

## Actor, Página e conversa

Preserve os identificadores distintos:

| Identificador | Papel |
| --- | --- |
| Conta autenticada | Usuário dono dos cookies e do lock. |
| `$actorId` | Perfil que atua na consulta; pode ser diferente da Página delegada. |
| `$pageId` | Página efetiva no inbox. |
| `id` | ID original da conversa: GraphQL nas consultas unificadas, thread LS no snapshot. |
| `selection_id` | Seleção devolvida pelo serviço para consultar as mensagens. |
| `thread_key` | Identificadores de thread/participante, preservados separadamente. |

Não derive IDs a partir do nome da Página nem trate esses valores como JIDs WhatsApp. Use a [descoberta de Páginas](PAGINAS.md) e o contexto efetivo da conta.

## Leitura do inbox atual

`snapshot(string $actorId, string $pageId, ?string $threadId = null): array` lê o documento autenticado atual do Meta Business Suite com a mesma sessão PHP. O SDK confirma conta, Página, perfil delegado e preloaders nativos antes de decodificar o lote Lightspeed completo. Não importa cookies do navegador, envia texto, marca conversa como lida ou registra dispositivo E2EE.

```php
// Os dois IDs vieram da mesma Página descoberta; não são intercambiáveis.
$snapshot = $facebook->inbox()->snapshot($actorId, $delegatePageId);
foreach ($snapshot['threads'] as $thread) {
    // A aplicação apresenta as conversas e recebe uma escolha explícita.
}
// $selectionId é selection_id do snapshot da mesma Página.
$selected = $facebook->inbox()->snapshot($actorId, $delegatePageId, $selectionId);
```

Esse método retorna apenas as conversas e mensagens carregadas pelo bootstrap nativo naquela leitura. `has_more=null` significa completude desconhecida; não há paginação inventada, histórico completo ou acompanhamento realtime contínuo. A interface consumidora precisa atualizar explicitamente a leitura e descartar o cache ao trocar conta/Página. O [exemplo executável](../../packages/facebook/examples/page-inbox-snapshot.php) mostra o uso da preferência salva.

No snapshot, `selection_id` preserva o `thread_key.thread_fbid` observado e identifica somente um **filtro local** desses dados, com `selection_source=snapshot_thread_key`. O terceiro argumento de `snapshot()` filtra por esse ID depois de confirmar o documento. Ele não é enviado como `selected_item_id` da rota, e não deve ser passado aos métodos unificados/Mercury como se fosse uma seleção deles. `comm_item_id` permanece separado.

O parser mantém o snapshot isolado do Messenger pessoal e exige a Página como participante das conversas projetadas. Lote incompleto, opcode desconhecido, epoch divergente ou escopo não confirmado aborta a projeção inteira.

O contexto do documento também é separado do bootstrap de workers incorporados. Um recurso `AsyncData.resolve` identificado pelos inicializadores nativos de worker não fornece tokens, identidade, gates ou raízes à página. Outros payloads AsyncData continuam sendo lidos, incluindo a configuração do inbox. Definições conflitantes dentro do próprio documento permanecem um erro; não é aplicada uma regra de escolher o primeiro ou o último token.

Em 20/10/2026, após login Android com autenticador e restauração da sessão em outro processo, a leitura real de uma Página retornou oito conversas, sete nomes e oito URLs de fotos. Uma consulta filtrada retornou uma mensagem com remetente/foto e anexo `file` projetados. O arquivo não foi baixado e nenhuma mensagem foi enviada; isso não valida histórico completo ou recebimento contínuo.

### Contatos e anexos do snapshot

O snapshot projeta os contatos recebidos no mesmo lote da Página. Cada conversa pode trazer `name` e `photo_url`; cada mensagem pode trazer `sender_name` e `sender_photo_url`. Quando falta um nome explícito na conversa individual, o SDK usa o contato do outro participante confirmado. Nomes/fotos explícitos de grupos são preservados; a foto de um integrante não substitui uma imagem de grupo ausente. Não há consulta ao armazenamento pessoal nem URL Graph fabricada a partir de um ID.

As mensagens também podem conter `attachments`, associados aos IDs reais da mensagem e da thread. Os tipos de apresentação são `image`, `video`, `audio`, `sticker` e `file`; formatos desconhecidos ou recursos sem URL utilizável precisam de indicação explícita na aplicação. Os campos de apresentação incluem `id`, `type`, `url`, `preview_url`, `mime_type`, `filename`, dimensões e duração/tamanho quando fornecidos pelo formato reconhecido. Metadados desconhecidos permanecem ausentes ou nulos, sem inferir conteúdo a partir do texto.

`availability` distingue `available`, `preview_only` e `unavailable`; `unavailable_reason` descreve a ausência reconhecida sem fabricar links. Prévias XMA preservam `native_type=xma` e título/descrição quando presentes. URLs de ação, story ou CTA não são convertidas em imagem/vídeo. Uma prévia sem arquivo de mídia continua identificável como conteúdo compartilhado, com indicação de indisponibilidade. `attachment_coverage` informa se há procedimentos de anexo ainda não reconhecidos no snapshot; esse aviso é global, sem atribuição a uma mensagem arbitrária.

URLs de mídia/avatar devem passar pela política HTTPS dos CDNs Meta reconhecidos (`fbcdn.net` e `fbsbx.com`, incluindo subdomínios). Credenciais em URL, destinos arbitrários e esquemas locais não são fontes de mídia. URLs assinadas podem expirar; uma nova leitura pode fornecer links atuais. Não publique essas URLs em exemplos/logs nem encaminhe cookies da conta ao carregar mídia. Na interface consumidora, use texto literal, imagens com referência desativada, controles nativos para vídeo/áudio e sem reprodução automática. Confira também a política `img-src`/`media-src` da aplicação.

Essa projeção continua limitada às mensagens carregadas pelo documento. Suporte ao formato não prova que um vídeo, imagem ou sticker específico esteja presente no snapshot, e não transforma uma prévia em histórico completo.

Na validação de 19/10/2026, os contatos de 8 conversas e os remetentes de 8 mensagens foram identificados no lote real da Página. Uma URL de avatar respondeu HTTP 200 com MIME de imagem, sem cookies da sessão. Havia uma prévia XMA real corretamente correlacionada, cujo registro não fornecia URL/miniatura de mídia. Imagens, vídeos, áudio e stickers de mensagem foram verificados com fixtures e apresentação DOM sintética; sua reprodução real ainda não foi demonstrada nessa conta. Avatares não foram contabilizados como anexos de mensagem.

## Consultar conversas

```php
use MVO\Facebook\FacebookConnection;

function conversasDaPagina(
    FacebookConnection $facebook,
    string $actorId,
    string $pageId,
): array {
    return $facebook->inbox()->threads($actorId, $pageId, limit: 20);
}
```

A assinatura completa é `threads($actorId, $pageId, $limit = 20, $beforeTimePrecise = null, $tags = [])`. O limite vai de 1 a 100. Tags são strings exatas observadas no inbox; lista vazia não aplica filtro de tags.

`threads()` e `messages()` preservam o contrato do documento unificado catalogado, separado de `snapshot()`. A versão atual do Business Suite pode rejeitar essa consulta; o SDK não transforma a recusa em coleção vazia nem em acesso ao Messenger pessoal.

O retorno contém `items`, `sync_sequence_id` e `has_more`. Cada conversa projeta nome, pasta, contagens, timestamp, razão de indisponibilidade de resposta e estado do composer quando fornecidos. `composer_input_disabled=false` é apenas uma das pré-condições verificadas pelo sender; não comprova autorização nem entrega.

Quando a resposta inclui `selected_page`, o SDK exige que seu ID corresponda ao `$pageId` consultado antes de devolver conversas ou mensagens. Página divergente ou identificação fornecida sem ID bloqueia o resultado. Formatos antigos podem omitir esse campo; essa omissão não comprova autorização da Página.

## Consultar mensagens

Receba a escolha de conversa e passe o `selection_id` retornado pela própria consulta:

```php
use MVO\Facebook\FacebookConnection;

function mensagensDaConversa(
    FacebookConnection $facebook,
    string $actorId,
    string $pageId,
    array $conversa,
): array {
    $selectionId = $conversa['selection_id'] ?? null;
    if (!is_string($selectionId) || $selectionId === '') {
        throw new RuntimeException('A conversa não forneceu uma seleção reconhecida.');
    }
    return $facebook->inbox()->messages($actorId, $pageId, $selectionId, limit: 20);
}
```

Assinatura: `messages($actorId, $pageId, $selectedItemId, $limit = 20, $beforeTimePrecise = null)`. O retorno tem `thread`, `items` e `has_more`. Mensagens preservam `id`, `message_id`, `offline_threading_id`, `sender_id`, `text`, `snippet`, `timestamp_precise` e `unread` quando presentes. IDs e timestamps permanecem strings exatas.

## Paginação e limites

`beforeTimePrecise` aceita apenas o timestamp numérico observado como string. Não faça cálculos de cursor ou conversões para float; não substitua ID GraphQL por `thread_fbid` ao selecionar a conversa.

O contrato estabelecido não fornece um indicador confiável de esgotamento; `has_more=null` permanece desconhecido. Uma página vazia ou um limite de 20 não prova histórico completo. Não marque uma coleção de inbox como exaustiva no [monitor](MONITORAMENTO.md) sem evidência própria de completude.

As consultas unificadas `threads()`/`messages()` têm testes locais; a tentativa atual foi recusada pelo servidor, sem ser convertida em lista vazia. Separadamente, em 19/10/2026, `snapshot()` foi validado por leitura autenticada em uma Página autorizada: 8 conversas e 8 mensagens carregadas, além de 1 mensagem corretamente filtrada por thread. Isso não comprova histórico completo, todos os anexos, envio comercial ou mensagens E2EE. O [provisionamento do dispositivo](DISPOSITIVO-E2EE.md) é separado de mensagens E2EE, que permanecem indisponíveis. Veja [limites](LIMITES.md) e a fonte [InboxService](../../packages/facebook/src/Service/InboxService.php).

## Envio atual por Lightspeed

`lightspeedContext(string $actorId, string $pageId, string $selectedItemId, ?callable $bundleLoader=null): array` consulta a Página/thread e lê o bootstrap autenticado do inbox. Extrai configuração DGW, aplicação e schema atuais de JSON inerte ou bundles públicos vinculados. Ausência/divergência bloqueia o fluxo; não há IDs ou versões de fallback. O callback opcional é um seam de transporte/teste para bundles públicos, sem cookies.

O resultado contém `dgw_app_id`, `dgw_app_version`, `server_app_id`, `schema_version`, `device_id`, `origin`, `recipient_id`, `offline_threading_id` e `epoch_id`. O device ID é um UUIDv4 criado pelo SDK e persistido por conta, no formato do cliente Web observado; não é o dispositivo E2EE nem uma cópia do storage do navegador. Guarde esse resultado **com a futura chave de idempotência antes do envio**. Não chame `lightspeedContext()` novamente para reenviar o mesmo comando: isso gera outra identidade de mensagem.

```php
// $actorId, $pageId e $selectionId vieram da descoberta/inbox da mesma conta.
$context = $facebook->inbox()->lightspeedContext($actorId, $pageId, $selectionId);
// Grave $context e $key no armazenamento privado da aplicação antes de prosseguir.
// O envio é uma ação externa explícita:
$result = $facebook->inbox()->sendTextLightspeed(
    $actorId, $pageId, $selectionId, $text, $key, $context,
);
```

`sendTextLightspeed(string $actorId, string $pageId, string $selectedItemId, string $text, string $idempotencyKey, array $context): array` repete o preflight e usa task 46 em um stream DGW próprio, com o asset da Página. A conta pessoal autentica o transporte, mas o stream fica vinculado à Página; o socket pessoal não é reutilizado como se fosse esse mailbox. O dono/lock e a sessão privada são os mesmos. O transporte fecha no final, inclusive em falha.

A confirmação exige resposta LS correlacionada com OTID, ID do servidor, thread, autor Página e texto. Retorna `status=server_ack`, `delivery_confirmed=false`, `read_confirmed=false`. A ausência dessa confirmação conserva a incerteza e impede retry automático. A implementação foi testada com transporte simulado; aquisição do HTML atual e aceitação de envio real ainda não foram validadas nesta etapa.

## Contrato Mercury legado separado

`sendText(string $actorId, string $pageId, string $selectedItemId, string $text, string $idempotencyKey, array $context): array` exige o contexto Mercury real. O SDK consulta o thread e confirma Página, destinatário, seleção e composer antes do POST. Preserva `__user` do dono e `request_user_id` da Página.

O contexto exige `client_thread_id`, `recipient_id`, `client`, `source`, `message_id`, `offline_threading_id` e `timestamp_ms`; aceita `origin` e `privacy_mutation_token` quando fornecidos pelo protocolo. `PageInboxSender::newOfflineIdentity()` gera OTID/timestamp locais; a aplicação deve guardá-los com o ID local real/contexto antes de enviar e reutilizá-los com a mesma chave. Esses valores não são flags de permissão, e sua aquisição completa não é automática.

Confirmação requer uma única action correlacionada por OTID, ID de servidor, autor da Página, thread e texto. O resultado `server_ack` mantém `delivery_confirmed=false` e `read_confirmed=false`. Timeout/retorno incompleto bloqueia repetição automática. A aceitação do caminho Mercury não foi testada na conta real.

Contrato completo, campos e evidências: [PageInboxSender](../../packages/facebook/src/Protocol/PAGE-INBOX-SEND.md). O caminho legado não substitui o método Lightspeed acima nem é usado como fallback automático.
