# Monitorar publicações e interações

O monitor é um componente local em SQLite. Ele recebe snapshots de itens normalizados e registra diferenças. Não autentica, consulta o Facebook, inicia polling ou envia webhooks automaticamente. A aplicação coleta com os serviços disponíveis, decide o intervalo e fornece as projeções.

## Separar os recursos observados

`ResourceScope($accountId, $identityId, $resourceType, $resourceId)` define a coleção. Conta autenticada, identidade que atua e recurso fazem parte da chave; não misture Páginas ou contas no mesmo scope. Publicações, comentários e reações podem usar scopes distintos.

`SnapshotItem($id, $fields = [], $counters = [])` conserva o ID nativo e campos permitidos. `SnapshotPage($snapshotId, $items, $cursor = null, $nextCursor = null, $complete = false)` identifica a página de uma tentativa de coleta.

## Exemplo executável sem conta

Este exemplo usa **dados sintéticos** e não se conecta ao Facebook. A primeira coleta é uma coleção de teste comprovadamente inteira; a segunda observa uma alteração parcial:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use MVO\Facebook\Monitoring\ResourceScope;
use MVO\Facebook\Monitoring\SnapshotItem;
use MVO\Facebook\Monitoring\SnapshotMonitor;
use MVO\Facebook\Monitoring\SnapshotPage;

$monitor = new SnapshotMonitor(__DIR__ . '/var/private-facebook/example-monitor.sqlite');
$scope = new ResourceScope('conta-sintetica', 'pagina-sintetica', 'page.posts', 'pagina-sintetica');

$monitor->ingest($scope, new SnapshotPage(
    snapshotId: 'exemplo-baseline-1',
    items: [new SnapshotItem('post-sintetico', ['text' => 'Texto inicial'], ['reactions' => 2])],
    complete: true,
));

$monitor->ingest($scope, new SnapshotPage(
    snapshotId: 'exemplo-observacao-2',
    items: [new SnapshotItem('post-sintetico', ['text' => 'Texto revisado'], ['reactions' => 3])],
    complete: false,
));

foreach ($monitor->events($scope, after: 0, limit: 100) as $event) {
    echo json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
```

Repita com o mesmo SQLite para observar deduplicação. O arquivo [monitor-local.php](../../packages/facebook/examples/monitor-local.php) inclui coleta paginada, retomada e consumo por cursor, sem credenciais ou rede.

## Baseline e completude

A primeira coleta **exaustiva** cria baseline silencioso: conteúdo histórico não vira novidade. Coletas iniciais parciais guardam observações, mas não geram notificações até o baseline exaustivo.

`complete=true` exige que toda a coleção do scope tenha sido observada e a paginação encerrada. HTTP 200, uma página vazia, um limite local ou um cursor desconhecido não comprovam isso. Em caso de visibilidade limitada, janela temporal, ordenação parcial ou histórico incompleto, use `complete=false`.

Uma coleta parcial posterior atualiza apenas itens observados. Ela não remove ausentes. Somente uma nova coleta exaustiva permite `item.removed`, que significa ausência naquela coleção observada, sem provar exclusão no Facebook. As listas parciais de anúncios e o inbox com `has_more=null` não fornecem completude para remoção automática.

## Páginas, retomada e idempotência

Mantenha o mesmo `snapshotId` durante uma coleta paginada. O `cursor` da próxima página deve corresponder ao `nextCursor` anterior. `state($scope)` conserva `pending_snapshot_id` e `next_cursor` para retomar após reinício.

Páginas intermediárias ficam pendentes; a última página confirma itens, eventos e recibos atomicamente. Repetir uma página idêntica é idempotente, inclusive após reinício. Conteúdo diferente com a mesma chave é rejeitado. Uma observação nova exige outro `snapshotId`; não intercale coletas do mesmo scope.

`discardPending($scope, $snapshotId)` cancela explicitamente uma tentativa interrompida sem apagar baseline e eventos confirmados.

## Eventos e dados permitidos

| Evento | Significado local |
| --- | --- |
| `item.created` | Item novo observado após o baseline. |
| `item.updated` | Campos normalizados observados mudaram. |
| `item.removed` | Ausência em coleta posterior comprovadamente exaustiva. |
| `counters.changed` | Uma medição já conhecida mudou; não identifica uma pessoa. |

Campos permitidos: `text`, `message`, `name`, `author_id`, `parent_id`, `created_time`, `updated_time`, `reaction_type`, `permalink_url`, `status` e `object_type`. Valores são string, inteiro, booleano ou `null`. Contadores são inteiros não negativos com nomes simples.

Campos ausentes são desconhecidos e preservam observações anteriores. `null` explícito limpa um campo conhecido. A primeira observação de um contador não é uma variação. Não forneça HTML, cookies, tokens ou árvore GraphQL bruta.

`events($scope, $after = 0, $limit = 100)` pagina por ID durável. O consumidor salva seu cursor após processar as ações externas de forma idempotente. `items($scope, $afterId = null, $limit = 100)` pagina pelo ID nativo. Não há promessa de entrega de webhook exatamente uma vez.

Na conexão ativa, `monitor()` abre o monitor da pasta privada da conta. Referências de monitor guardadas pela aplicação têm ciclo de vida próprio. Testes SQLite comprovam o comportamento local; não comprovam coleta ou eventos reais do Facebook. Fonte detalhada: [contrato do monitor](../../packages/facebook/src/Monitoring/README.md).
