# Comentários, curtidas e reações

Comentários e reações usam o `feedback_id` GraphQL exato devolvido pelo Facebook. Não construa esse ID a partir de `post_id`, URL ou nome. As consultas retornam projeções normalizadas; itens, contadores e autores têm significados separados.

## Consultar comentários

`comments()->list($feedbackId, $variables = [], $actorId = null)` exige `feedLocation` real e os providers atuais do documento `CommentListComponentsRootQuery`. A integração fornece esses valores; o SDK não os extrai automaticamente.

```php
use MVO\Facebook\FacebookConnection;

function lerComentarios(
    FacebookConnection $facebook,
    string $feedbackId,
    string $actorId,
    array $variaveisAtuais,
): array {
    return $facebook->comments()->list($feedbackId, $variaveisAtuais, $actorId);
}
```

O retorno contém `feedback_id`, `items`, `intent_token`, `count`, `total_count` e `page_info`. Os itens incluem IDs exatos, texto, autor, tempo, feedback, comentário pai e cursor, com `null` para informações desconhecidas. `count` e `total_count` não devem ser presumidos iguais.

## Paginação e ordenação

O servidor escolhe a ordenação inicial e devolve `intent_token`. Preserve esse token, `feedLocation` e o feedback ao paginar:

| Método | Cursor | Limite |
| --- | --- | --- |
| `next($feedbackId, $cursor, $intentToken, $count, $variables, $actorId)` | `page_info.end_cursor` | 1 a 100; padrão 20. |
| `previous($feedbackId, $cursor, $intentToken, $count, $variables, $actorId)` | `page_info.start_cursor` | 1 a 100; padrão 20. |

Os providers da paginação pertencem a `CommentsListComponentsPaginationQuery`, que pode exigir variáveis diferentes da consulta inicial. Não invente `intent_token` para mudar ordenação. `has_next_page`/`has_previous_page=null` não confirmam o fim da coleção.

```php
use MVO\Facebook\FacebookConnection;

function maisComentarios(
    FacebookConnection $facebook,
    string $actorId,
    array $pagina,
    array $variaveisDaPaginacao,
): ?array {
    $info = $pagina['page_info'];
    if ($info['has_next_page'] !== true
        || !is_string($info['end_cursor'])
        || !is_string($pagina['intent_token'])) {
        return null;
    }
    return $facebook->comments()->next(
        $pagina['feedback_id'], $info['end_cursor'], $pagina['intent_token'],
        20, $variaveisDaPaginacao, $actorId,
    );
}
```

## Criar comentário ou resposta

`create($actorId, $feedbackId, $text, $idempotencyKey, $context, $variables = [])` exige `session_id` real e `feedback_source` como nome do enum conhecido, além dos providers atuais. Para responder, passe em `$context['reply_comment_parent_fbid']` o ID exato do comentário pai. O contexto admite também `attribution_id_v2`, `tracking`, `reply_target_clicked`, `formatting_style` e `is_self_disclosed_as_ai_generated`, respeitando os tipos do método.

Esse helper cria texto ou resposta, sem upload/anexo. Edição, exclusão e ocultação têm os contratos próprios descritos abaixo. A preparação automática do contexto e a escrita em conta real ainda não foram validadas. A chave de idempotência e a [consulta de comandos](LIMITES.md) são obrigatórias para tratar resultado incerto.

## Editar um comentário de texto

`editText($actorId, $commentId, $text, $idempotencyKey, $context, $variables)` recebe o ID GraphQL exato do comentário. Atende comentários existentes somente de texto; não reconstrói anexos, menções ou formatação complexa.

| Campo de `$context` | Obrigação |
| --- | --- |
| `formatting_style` | Estilo obtido do comentário/composer atual; não presumir `PLAIN_TEXT`. |
| `attribution_id_v2` | Cadeia de navegação atual da edição. |
| `tracking` | Lista atual de strings de tracking. |
| `is_self_disclosed_as_ai_generated` | Opcional; booleano quando fornecido. |

As variáveis incluem `feedLocation`, `translationType`, `useDefaultActor` booleano e `scale` positivo atuais, além dos providers de `ContentManagementDocuments::providers('useCometUFIEditCommentMutation')`.

```php
use MVO\Facebook\FacebookConnection;

function editarComentario(
    FacebookConnection $facebook,
    string $actorId,
    string $commentId,
    string $novoTexto,
    string $chaveIdempotencia,
    array $contextoDeEdicao,
    array $variaveisAtuais,
): array {
    return $facebook->comments()->editText(
        $actorId, $commentId, $novoTexto, $chaveIdempotencia,
        $contextoDeEdicao, $variaveisAtuais,
    );
}
```

O resultado reconhecido contém `id` e `text`. A biblioteca confere o ID e o texto devolvidos antes de confirmar o comando. Uma resposta de outro comentário ou um corpo diferente mantém o resultado não confirmado.

## Excluir um comentário

`delete($actorId, $commentId, $idempotencyKey, $context, $variables)` executa a ação do menu Comet. `$context` exige `attribution_id_v2` e a lista `tracking` atuais. `$variables` recebe `scale` positivo e os providers de `ContentManagementDocuments::providers('useCometUFIDeleteCommentMutation')`. Campos opcionais de renderização/grupo precisam corresponder ao mesmo menu que originou a operação.

```php
use MVO\Facebook\FacebookConnection;

function excluirComentario(
    FacebookConnection $facebook,
    string $actorId,
    string $commentId,
    string $chaveIdempotencia,
    array $contextoDoMenu,
    array $variaveisAtuais,
): array {
    return $facebook->comments()->delete(
        $actorId, $commentId, $chaveIdempotencia,
        $contextoDoMenu, $variaveisAtuais,
    );
}
```

O retorno `['id' => ..., 'deleted' => true]` exige `deleted_comment_id` igual ao comentário solicitado. A exclusão e seus efeitos sobre respostas permanecem sujeitos às regras e permissões do Facebook.

## Ocultar ou voltar a mostrar um comentário

`setHidden($actorId, $commentId, $hidden, $idempotencyKey, $context, $variables)` escolhe as ações Web de ocultar (`true`) ou reexibir (`false`). Ele conserva os dois estados devolvidos pelo servidor:

| Resultado | Significado |
| --- | --- |
| `is_hidden_by_viewer` | Estado de ocultação para o viewer atual. |
| `is_hidden_by_content_owner` | Estado de ocultação pelo dono do conteúdo informado pelo servidor. |
| `spam_display_mode` | Modo devolvido, ou `null` se desconhecido. |

Uma ocultação para o viewer **não comprova moderação global ou ocultação para todo o público**. Não apresente o método como banimento de pessoas, filtro de spam, denúncia ou aprovação de comentários; esses fluxos não são implementados aqui.

O contexto exige `feedback_source` como **inteiro numérico do menu atual**, diferentemente do nome de enum usado em `create()`. Para ocultar, exige também `attribution_id_v2`; para reexibir, somente `feedback_source`. As variáveis exigem `feedLocation`, `scale` positivo, `commentActionLinksRewriteEnabled` booleano atual e os providers do documento correspondente:

- `CometUFIHideCommentMutation` para ocultar.
- `CometUFIUnhideCommentMutation` para reexibir.

```php
use MVO\Facebook\FacebookConnection;

function mudarVisibilidadeComentario(
    FacebookConnection $facebook,
    string $actorId,
    string $commentId,
    bool $oculto,
    string $chaveIdempotencia,
    array $contextoDoMenu,
    array $variaveisAtuais,
): array {
    return $facebook->comments()->setHidden(
        $actorId, $commentId, $oculto, $chaveIdempotencia,
        $contextoDoMenu, $variaveisAtuais,
    );
}
```

Os documentos de gerenciamento pertencem ao novo catálogo `ContentManagementDocuments`, separado do catálogo de leitura/criação `PageDocuments`. Providers ausentes e campos desconhecidos são rejeitados antes de I/O. Toda escrita usa idempotência no ledger: confirmação reproduz a projeção sem HTTP, conteúdo diferente na mesma chave gera conflito e resultado incerto não é reenviado automaticamente.

Edição, exclusão e ocultação foram conferidas em código público e exercitadas com transporte sintético. Não houve escrita ou moderação em uma conta real nesta revisão. Isso também não demonstra que uma conta delegada tenha permissões para cada ação.

## Resumo das reações

`reactions()->summary($feedbackId, $scale = 1.0, $actorId = null)` retorna tipos e contadores. `scale` deve ser maior que zero e no máximo 4.

```php
use MVO\Facebook\FacebookConnection;

function resumoDeReacoes(FacebookConnection $facebook, string $feedbackId): array
{
    return $facebook->reactions()->summary($feedbackId);
}
```

| Campo | Interpretação |
| --- | --- |
| `reactions` | Tipos/IDs observados e suas contagens. |
| `reaction_types_count` | Quantidade de tipos, não total de curtidas. |
| `facebook_reaction_count` | Contagem Facebook quando todos os componentes são conhecidos. |
| `instagram_reaction_count` | Contagem Instagram informada separadamente. |
| `aggregated_reaction_count` | Contagem agregada informada pelo servidor. |

Valores desconhecidos permanecem `null`; não transforme ausência em zero. Nenhum contador identifica quem curtiu.

## Listar pessoas que reagiram

`list($feedbackId, $reactionId = null, $scale = 1.0, $actorId = null)` retorna `items`, `important_items`, `reaction_filter` e `page_info`. `reactionId=null` representa todos os tipos. Para filtrar, use um ID de reação observado, sem derivá-lo de emoji ou nome.

`important_items` é um subconjunto separado escolhido pelo servidor; não o concatene aos itens paginados como se fosse outra página. Siga com `next($feedbackId, $cursor, $reactionId, $count = 10, $scale = 1.0, $actorId = null)`, preservando `reaction_filter` e usando `end_cursor`. O limite vai de 1 a 100.

## Definir ou remover uma reação

`set($actorId, $feedbackId, $reactionId, $idempotencyKey, $context, $variables = [])` exige contexto real com `session_id`, `feedback_source` e providers atuais. IDs permitidos vêm do resultado real, como `supported_reaction_infos`; não escolha números por tentativa. O contexto opcional inclui `attribution_id_v2`, `tracking` e `feedback_referrer`.

`remove($actorId, $feedbackId, $idempotencyKey, $context, $variables = [])` usa internamente o valor de remoção estabelecido pelo documento. O retorno contém `viewer_reaction_known`, `viewer_reaction_id`, `reaction_count` e `supported_reaction_infos`. `viewer_reaction_known=false` não confirma remoção. Essas escritas têm binding e testes locais; não há validação real de alteração nesta revisão.

Fontes: [CommentsService](../../packages/facebook/src/Service/CommentsService.php), [ReactionsService](../../packages/facebook/src/Service/ReactionsService.php) e [catálogo de contextos](../../packages/facebook/resources/README.md).
