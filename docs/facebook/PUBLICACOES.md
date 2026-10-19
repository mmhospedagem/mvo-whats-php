# Publicações e timeline

`posts()` agrupa consultas paginadas de timeline e o binding do compositor de publicações de texto. Essas operações exigem contexto Web atual; a biblioteca não descobre automaticamente os providers Relay nem prepara o composer.

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

A aplicação precisa estabelecer e validar esse contexto antes de habilitar a ação. Não há preparação automática, anexos, menções estruturadas, upload, edição, exclusão ou publicação cruzada nesse método. A escrita concreta ainda não foi validada em conta real.

## Resultado e reenvio

O retorno normalizado conserva `post_id`, `story_id`, `url`, `publishing_flow` e `scheduled_publish_time` quando reconhecidos. Isso não prova visibilidade, distribuição ou aprovação. A mutation usa ledger privado e idempotência: chave confirmada devolve a mesma projeção sem HTTP; resultado incerto não é reenviado automaticamente. Veja [erros e estados dos comandos](LIMITES.md).

Fontes: [PostsService](../../packages/facebook/src/Service/PostsService.php) e [PageDocuments](../../packages/facebook/src/Catalog/PageDocuments.php). Próximo: [comentários e reações](COMENTARIOS-REACOES.md).
