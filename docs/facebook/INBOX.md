# Inbox de Página

`inbox()` oferece consultas de conversas e mensagens do inbox comercial reconhecido pelo protocolo Web. Este serviço é somente leitura. Não implementa envio como Página nem usa o envio pessoal Messenger como substituto.

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

O retorno contém `items`, `sync_sequence_id` e `has_more`. Cada conversa projeta nome, pasta, contagens, timestamp, razão de indisponibilidade de resposta e estado do composer quando fornecidos. Mesmo que `composer_input_disabled=false`, isso não cria suporte de envio na biblioteca.

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

As consultas foram verificadas com fixtures locais. Isso não comprova leitura do inbox comercial nesta conta real, todos os anexos ou mensagens E2EE. O dispositivo Messenger E2EE continua ausente, sem fallback sem criptografia. Veja [limites](LIMITES.md) e a fonte [InboxService](../../packages/facebook/src/Service/InboxService.php).
