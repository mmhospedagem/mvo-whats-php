# Monitor de projeções normalizadas

Este componente é local: não autentica, consulta nem altera o Facebook. O coletor PHP do cliente fornece somente projeções reconhecidas pelo protocolo. Rede, intervalo de consulta e entrega de notificações pertencem ao loop externo.

O [exemplo PHP executável](../../examples/monitor-local.php) usa somente dados sintéticos e SQLite. A partir da raiz do repositório:

```sh
php packages/facebook/examples/monitor-local.php /caminho/privado/monitor-example.sqlite
```

Execute duas vezes com o mesmo arquivo para observar a deduplicação após reabertura. A coleta inicial tem duas páginas, a coleta seguinte é parcial e os eventos locais são paginados. O script não usa painel, API REST nem conta Facebook.

```php
use MVO\Facebook\Monitoring\{ResourceScope, SnapshotItem, SnapshotPage, SnapshotMonitor};

$monitor = new SnapshotMonitor(__DIR__ . '/var/private/facebook-monitor.sqlite');
$scope = new ResourceScope('conta-nativa', 'pagina-ativa', 'page.posts', 'pagina-nativa');

// Um novo ID de coleta; mantenha-o e o cursor ao retomar depois de uma falha.
$result = $monitor->ingest($scope, new SnapshotPage(
    snapshotId: 'coleta-2026-10-18-001',
    items: [new SnapshotItem('id-nativo-do-post', ['text' => 'Publicação'], ['comments' => 2, 'reactions' => 5])],
    cursor: null,
    nextCursor: null,
    complete: true,
));

$state = $monitor->state($scope);
$events = $monitor->events($scope, after: 0, limit: 100);
```

IDs são strings nativas opacas. A chave inclui conta autenticada, identidade que atua, tipo e ID do recurso. Publicações, comentários, reações e contadores podem usar scopes distintos; isso não declara que qualquer operação Web correspondente já está disponível no cliente.

`complete=true` significa **coleção inteira comprovadamente observada**, com paginação encerrada. Uma resposta HTTP bem-sucedida, uma página vazia ou o fim de um limite local não comprovam isso. Se houver restrições de visibilidade, ordenação parcial, janela temporal, limite, falha ou coleção cuja completude não foi demonstrada, use `complete=false`. Se o coletor trabalha com uma janela, use outro scope que represente exatamente essa coleção e não o histórico inteiro.

- A primeira coleta exaustiva cria o baseline sem notificar conteúdo histórico. Coletas iniciais incompletas registram o que foi observado e continuam sem notificações até um baseline exaustivo.
- Páginas com `nextCursor` ficam pendentes, sem alterar itens/eventos confirmados. `state()` conserva `pending_snapshot_id` e `next_cursor` para retomada. A última página confirma a projeção inteira atomicamente.
- Uma coleta posterior parcial atualiza somente itens observados; não remove ausentes. Somente uma coleta exaustiva posterior pode produzir `item.removed`. A remoção significa ausência na coleção observada, não comprovação de exclusão no Facebook.
- Eventos são `item.created`, `item.updated`, `item.removed` e `counters.changed`. Curtidas/reações são itens de seu próprio recurso, ou contadores normalizados conforme a informação efetivamente obtida.
- Campos ou contadores ausentes são desconhecidos e preservam os valores anteriores. A primeira observação de uma medição não é tratada como variação. `null` explícito limpa um campo conhecido. Contadores variam em qualquer direção; a mudança não identifica quem reagiu.
- Cada ID de coleta identifica um conjunto ordenado de páginas. Repetir exatamente uma página é idempotente, inclusive após reinício. Conteúdo diferente com a mesma chave de página é rejeitado. Use novo `snapshotId` para uma nova observação, mesmo se o cursor for igual.
- `discardPending($scope, $snapshotId)` cancela explicitamente uma coleta interrompida; não altera baseline, itens ou eventos confirmados. Coletas de um mesmo scope não se intercalam.
- `events()` pagina por ID durável e `items()` pelo ID nativo exato. O consumidor salva seu último ID de evento após processar as ações externas de forma idempotente; o monitor não promete entrega de webhook exatamente uma vez.

Campos normalizados permitidos: `text`, `message`, `name`, `author_id`, `parent_id`, `created_time`, `updated_time`, `reaction_type`, `permalink_url`, `status`, `object_type`. Valores são string, inteiro, booleano ou null. Contadores são nomes simples e inteiros não negativos. Não forneça HTML, nós GraphQL, credenciais, cookies ou tokens. O banco conserva conteúdo normalizado observado e cursores privados; mantenha-o fora do diretório público. Depuração de objetos omite textos e cursores.

Os testes usam somente SQLite temporário e projeções sintéticas, incluindo falha de escrita injetada, retomada, deduplicação e isolamento. Isso não comprova endpoints, permissões ou eventos em uma conta Facebook real.
