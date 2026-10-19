# Inbox de Página

`inbox()` oferece consultas de conversas/mensagens, aquisição de contexto e envio experimental Lightspeed pelo mailbox da Página. Um contrato Mercury legado permanece separado. Ambos exigem escopo explícito, preflight e idempotência; não usam o envio pessoal Messenger como substituto.

## Actor, Página e conversa

Preserve os identificadores distintos:

| Identificador | Papel |
| --- | --- |
| Conta autenticada | Usuário dono dos cookies e do lock. |
| `$actorId` | Perfil que atua na consulta; pode ser diferente da Página delegada. |
| `$pageId` | Página efetiva no inbox. |
| `id` | ID GraphQL original da conversa. |
| `selection_id` | Seleção devolvida pelo serviço para consultar as mensagens. |
| `thread_key` | Identificadores de thread/participante, preservados separadamente. |

Não derive IDs a partir do nome da Página nem trate esses valores como JIDs WhatsApp. Use a [descoberta de Páginas](PAGINAS.md) e o contexto efetivo da conta.

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

O retorno contém `items`, `sync_sequence_id` e `has_more`. Cada conversa projeta nome, pasta, contagens, timestamp, razão de indisponibilidade de resposta e estado do composer quando fornecidos. `composer_input_disabled=false` é apenas uma das pré-condições verificadas pelo sender; não comprova autorização nem entrega.

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

As consultas foram verificadas com fixtures locais. Isso não comprova leitura do inbox comercial nesta conta real, todos os anexos ou mensagens E2EE. O [provisionamento do dispositivo](DISPOSITIVO-E2EE.md) é separado de mensagens E2EE, que permanecem indisponíveis, sem fallback plaintext. Veja [limites](LIMITES.md) e a fonte [InboxService](../../packages/facebook/src/Service/InboxService.php).

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
