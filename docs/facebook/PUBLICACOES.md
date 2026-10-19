# Publicações e timeline

`posts()` agrupa consultas paginadas de timeline, criação e edição de texto e exclusão de publicações. Essas operações exigem contexto Web atual; a biblioteca não descobre automaticamente os providers Relay nem prepara o composer.

## Pré-condições do protocolo

Use IDs nativos de perfil/Página e providers do contexto atual. `PageDocuments::providers($documentName)` informa quais providers o documento exige. `PageDocuments::arguments()` valida os valores antes do I/O; providers ausentes geram `facebook_provider_context_required`.

O conjunto da consulta inicial pode diferir do conjunto de paginação. Não copie flags de fixtures, de outra sessão ou de outro documento como configuração real. O [catálogo dos documentos](../../packages/facebook/resources/README.md) conserva fontes e contratos; sua existência não comprova permissão ou sucesso em uma conta.

## Ler a timeline

Esta função recebe os valores atuais já estabelecidos pela integração. Ela consulta uma página e preserva os indicadores retornados:

```php
use MVO\Facebook\FacebookConnection;

function lerTimeline(
    FacebookConnection $facebook,
    string $profileId,
    string $actorId,
    array $providersAtuais,
): array {
    return $facebook->posts()->list($profileId, $providersAtuais, $actorId);
}
```

O retorno contém `items` e `page_info`. Cada item projeta `id`, `post_id`, `text`, `url`, `feedback_id` e `cursor`, mantendo `null` para campos desconhecidos. `id`, `post_id` e `feedback_id` têm papéis distintos; não derive um deles a partir de outro.

Para seguir, use `page_info.end_cursor` e o contrato de providers da consulta paginada:

```php
use MVO\Facebook\FacebookConnection;

function proximaPaginaTimeline(
    FacebookConnection $facebook,
    string $profileId,
    string $actorId,
    array $paginaAnterior,
    array $providersDaPaginacao,
): ?array {
    $info = $paginaAnterior['page_info'];
    if ($info['has_next_page'] !== true || !is_string($info['end_cursor'])) {
        return null;
    }
    return $facebook->posts()->next(
        $profileId,
        $info['end_cursor'],
        $providersDaPaginacao,
        $actorId,
    );
}
```

`has_next_page=null` é desconhecido, não comprova esgotamento. Um limite local ou uma janela de timeline não significa histórico completo.

## Criar uma publicação de texto

`createText($actorId, $text, $idempotencyKey, $context, $variables = [])` implementa o compositor de timeline `composer_type=profile`. A chamada exige:

| Campo de `$context` | Obrigação |
| --- | --- |
| `composer_entry_point` | Entrada real do composer atual. |
| `composer_source_surface` | Superfície real do composer. |
| `composer_type` | `profile` para este binding. |
| `logging.composer_session_id` | Sessão real desse composer. |
| `audience` | Objeto de audiência efetivamente escolhido; sem audiência presumida. |
| `tracking` | Lista de strings do contexto atual. |
| `navigation_data` | Opcional; objeto quando fornecido. |
| `is_tracking_encrypted` | Opcional; booleano quando fornecido. |

`$variables` recebe os providers atuais de `ComposerStoryCreateMutation`. O trecho é uma função de integração, sem valores fictícios que poderiam causar uma publicação com audiência incorreta:

```php
use MVO\Facebook\FacebookConnection;

function publicarTexto(
    FacebookConnection $facebook,
    string $actorId,
    string $texto,
    string $chaveIdempotencia,
    array $contextoDoComposer,
    array $providersAtuais,
): array {
    return $facebook->posts()->createText(
        $actorId,
        $texto,
        $chaveIdempotencia,
        $contextoDoComposer,
        $providersAtuais,
    );
}
```

A aplicação precisa estabelecer e validar esse contexto antes de habilitar a ação. `createText()` não adiciona anexos, menções estruturadas ou publicação cruzada. O [upload de fotos](MIDIA.md) é uma operação separada e não publica automaticamente um post. A escrita concreta ainda não foi validada em conta real.

## Editar o texto de uma publicação

`editText($actorId, $storyId, $text, $idempotencyKey, $context, $variables)` usa o **ID GraphQL exato da story**, não um `post_id` transformado. Este helper atende publicações existentes somente de texto; não reconstrói ou remove fotos, links, enquetes e anexos de uma publicação complexa.

| Campo de `$context` | Obrigação |
| --- | --- |
| `composer_entry_point` | Entrada do composer de edição atual. |
| `composer_source_surface` | Superfície real da edição. |
| `composer_type` | Tipo efetivo do composer de edição; sem copiar o tipo do composer de criação. |
| `logging.composer_session_id` | Sessão desse composer. |
| `tracking` | Lista de strings do contexto atual. |

`$variables` contém os providers de **`ContentManagementDocuments::providers('ComposerStoryEditMutation')`** e também `feedLocation`, `renderLocation`, `scale` positivo e `ppeEnabled` booleano atuais. Não derive esse último valor do nome do provider nem use um valor de fixture. As variáveis do catálogo anterior `PageDocuments` continuam valendo para criação e leitura, não substituem o novo documento de edição.

```php
use MVO\Facebook\FacebookConnection;

function editarTextoPublicacao(
    FacebookConnection $facebook,
    string $actorId,
    string $storyId,
    string $novoTexto,
    string $chaveIdempotencia,
    array $contextoDoComposerDeEdicao,
    array $variaveisAtuaisDeEdicao,
): array {
    return $facebook->posts()->editText(
        $actorId, $storyId, $novoTexto, $chaveIdempotencia,
        $contextoDoComposerDeEdicao, $variaveisAtuaisDeEdicao,
    );
}
```

O resultado projeta `story_id`, `post_id`, `text` e `url`. A confirmação exige a mesma story e o texto solicitado no resultado reconhecido; uma resposta HTTP 200 sem esses campos não vira sucesso. Isso não garante distribuição ou leitura.

## Excluir uma publicação

`delete($actorId, $storyId, $idempotencyKey, $variables)` executa a ação Comet de exclusão de uma story do feed. Não significa arquivar, ocultar para o viewer, apagar um anúncio ou excluir uma Página.

Forneça o ID GraphQL exato, os providers atuais de `ContentManagementDocuments::providers('useCometFeedStoryDeleteMutation')` e `scale` positivo. As permissões continuam sendo verificadas pelo Facebook no contexto do actor escolhido.

```php
use MVO\Facebook\FacebookConnection;

function excluirPublicacao(
    FacebookConnection $facebook,
    string $actorId,
    string $storyId,
    string $chaveIdempotencia,
    array $variaveisAtuais,
): array {
    return $facebook->posts()->delete(
        $actorId, $storyId, $chaveIdempotencia, $variaveisAtuais,
    );
}
```

A projeção `['story_id' => ..., 'deleted' => true]` só é retornada quando `deleted_story_id` corresponde ao ID solicitado. O código não infere exclusão apenas porque o post sumiu de uma listagem parcial.

## Resultado e reenvio

O retorno de criação conserva `post_id`, `story_id`, `url`, `publishing_flow` e `scheduled_publish_time` quando reconhecidos. Isso não prova visibilidade, distribuição ou aprovação. Criação, edição e exclusão usam ledger privado e idempotência: chave confirmada devolve a mesma projeção sem HTTP; resultado incerto não é reenviado automaticamente. Uma mesma chave com conteúdo diferente é conflito. Veja [erros e estados dos comandos](LIMITES.md).

Os documentos e formatos de edição/exclusão foram conferidos em artefatos públicos do Facebook Web e exercitados com transporte sintético. Nenhuma dessas escritas foi executada em conta real nesta revisão. Conteúdo com anexos, edição de enquetes, agendamento e outros fluxos continuam exigindo contratos específicos.

Fontes: [PostsService](../../packages/facebook/src/Service/PostsService.php), [PageDocuments](../../packages/facebook/src/Catalog/PageDocuments.php) e [ContentManagementDocuments](../../packages/facebook/src/Catalog/ContentManagementDocuments.php). Próximo: [comentários e reações](COMENTARIOS-REACOES.md).
