# Insights Web e métricas

`InsightsService` reproduz consultas Web do Business Suite para valores agregados, séries diárias e breakdowns. Não usa a Marketing Graph API. Os documentos e pares de evento/métrica têm origem em artefatos públicos do Web; a resposta de uma conta real ainda não foi validada nesta entrega.

## Métricas disponíveis

`metrics()` descreve os pares observados. Os nomes são sensíveis a maiúsculas:

| Nome | Evento / métrica |
| --- | --- |
| `Views` | `VIEW` / `COUNT` |
| `Viewers` | `VIEW` / `UNIQUE_USERS` |
| `Reach` | `IMPRESSION` / `UNIQUE_USERS` |
| `Interactions` | `INTERACTION` / `COUNT` |
| `Comments` | `COMMENT` / `COUNT` |
| `Shares` | `SHARE` / `COUNT` |
| `Visits` | `VISIT` / `COUNT` |
| `NewFollowers` | `FOLLOW` / `NEW_USERS` |
| `NetFollows` | `NET_FOLLOW` / `UNIQUE_USERS` |

O Facebook decide a disponibilidade de cada combinação de Página, período e breakdown. Um erro de permissão, data ou disponibilidade gera `facebook_insight_unavailable`; não se converte em zero ou em relatório vazio bem-sucedido.

## Valor e série diária

```php
use MVO\Facebook\Service\InsightsService;

function alcanceDoPeriodo(
    InsightsService $insights,
    string $pageId,
    string $callerIdAtualDoBusinessSuite,
): array {
    return [
        'total' => $insights->singleValue(
            $pageId, 'Reach', '2026-10-01', '2026-10-17',
            $callerIdAtualDoBusinessSuite,
        ),
        'diario' => $insights->timeSeries(
            $pageId, 'Reach', '2026-10-01', '2026-10-17',
            $callerIdAtualDoBusinessSuite,
        ),
    ];
}
```

O `callerId` pertence ao contexto atual da interface. O SDK não escolhe uma string inventada para autorizar consultas. As datas são inclusivas, em `YYYY-MM-DD`, e seguem o calendário do documento Web. Timestamps de pontos retornados são conservados; datas ausentes e valores `null` não são preenchidos com zero. O padrão é `distribution_scope=not_separated`.

## Distinguir tráfego pago e orgânico

`breakdown($pageId, $metric, 'PAID_ORGANIC', $since, $until, $callerId)` consulta os buckets de distribuição quando essa métrica permitir. O retorno conserva os nomes e valores enviados pelo Facebook e informa `distribution_scope=server_paid_organic_buckets`. O SDK não relabela um total como orgânico.

Outros enums observados são `COUNTRY`, `AGE_BUCKET_AND_GENDER`, `GENDER`, `CITY`, `CONTENT_TYPE`, `FOLLOWER_NON_FOLLOWER`, `MEDIA_PRODUCT_TYPE` e `PUBLISHER_PLATFORM`. A presença do enum no catálogo não garante suporte em toda métrica. Não some buckets de usuários únicos para obter um total inventado: públicos podem se sobrepor e as regras são do servidor.

## Tabela de conteúdo e relatório paginado

`contentInsights()` é separado das métricas agregadas. `context($pageId, $businessId = null)` lê os providers atuais no documento autenticado de Insights, verificando o dono da conta. O parser lê JSON inerte: não executa scripts nem inventa flags ausentes.

```php
use MVO\Facebook\FacebookConnection;
use MVO\Facebook\Service\ContentReportExporter;

function relatorioDeConteudo(FacebookConnection $facebook, string $pageId): array
{
    // Recebe a conexão já autenticada e conectada, no loop externo da aplicação.
    $insights = $facebook->contentInsights();
    $providers = $insights->context($pageId);
    $columns = $insights->columns($pageId, $providers);
    $report = $insights->report(
        $pageId, '2026-10-01', '2026-10-17', $providers,
        ['count' => 50, 'visible_columns' => ['DATE', 'REACH', 'VIEWS']],
        maxPages: 20,
    );
    return [
        'columns' => $columns,
        'report' => $report,
        'local_export' => [
            'scope' => $report['export_scope'],
            'pagination_complete' => $report['pagination_complete'],
            'metrics_complete' => $report['metrics_complete'],
            'complete_organic_reporting' => $report['complete_organic_reporting'],
            'snapshot_consistent' => $report['snapshot_consistent'],
            'duplicate_rows_changed' => $report['duplicate_rows_changed'],
            'csv' => ContentReportExporter::csv($report),
            'json' => ContentReportExporter::json($report),
        ],
    ];
}
```

Execute fora dos callbacks. `listContent($pageId,$since,$until,$providers,$options=[])` lê uma página; `next($pageId,$continuation,$providers)` continua com o handle retornado. Preserve conta, Página, datas, opções e providers. Contexto diferente exige nova coleta. Cursores repetidos e respostas desconhecidas geram erro.

`context()` devolve os dois flags booleanos atuais exigidos pela consulta da tabela. `columns()` aceita esse contexto completo e envia somente o provider previsto no seu próprio documento Relay; também aceita apenas esse provider obrigatório. Não transforme os nomes dos flags em permissões nem invente valores quando estiverem ausentes.

Opções: `count` (1 a 100), `search` (UTF-8, até 1.000 bytes), `sort_key` (coluna observada), `sort_direction` (`ASC`/`DESC`) e `visible_columns` (lista sem duplicatas). Os limites são políticas do SDK. `ContentInsightsDocuments::columnFields()` enumera as 46 chaves observadas; o servidor decide sua disponibilidade. O caller observado é `BIZWEB_INSIGHTS_ORGANIC_CONTENT`; as datas são inclusivas e cada coleta usa uma Página.

`report()` reúne até `maxPages` páginas (1 a 100, padrão 20), deduplica `row_id` e preserva a continuação se houver truncamento. A primeira versão observada de uma linha é mantida. `pagination_complete` descreve somente o esgotamento da coleção e dos filtros consultados; `metrics_complete` descreve as células reconhecidas da projeção e não todas as métricas da Página.

`snapshot_consistent=null` significa **desconhecido**; nunca deve ser convertido em `true`. Se outra cópia do mesmo `row_id` tiver valores diferentes, `duplicate_rows_changed=true` e `snapshot_consistent=false`. `duplicate_rows_changed=false` significa apenas que essa diferença não foi observada nas linhas repetidas, sem comprovar um snapshot transacional.

Cada célula conserva `value`, `status`, `renderer` e `distribution_scope`. Ausências, erros e renderers desconhecidos não viram zero. Datas do servidor não ganham unidade/fuso presumidos. O KPI conhecido fornece total `not_separated`; não se converte em valor exclusivamente orgânico.

`ContentReportExporter` retorna CSV/JSON como strings para armazenamento privado pela aplicação. É uma exportação **local** do relatório normalizado, sem job remoto da Meta. O CSV mantém disponibilidade/escopo por célula, escapa fórmulas em strings e diferencia vazio de zero confirmado. Relatórios podem conter dados privados; não publique em Git ou logs.

`export_scope=local_normalized_report` identifica a origem da exportação, não sua completude. Gerar CSV/JSON continua possível quando `pagination_complete=false` ou `metrics_complete=false`; preserve esses metadados e a continuação para decidir se precisa coletar mais páginas. `complete_organic_reporting` permanece `false` mesmo quando os outros dois campos forem `true`.

## Alcance desta entrega

Os testes locais usam payloads sintéticos para verificar formato, escopo de Página, datas, ausência de zeros fabricados e erros. `availability()` mantém `validated_in_account=false` e `complete_organic_reporting=false`.

A tabela paginada, providers, colunas e exportação local estão implementados. Faltam os contratos completos das células `SingleValueMetricWithPaidBreakdown` e `WithAdsLiquidity` e a exportação remota da Meta. Células não estabelecidas ficam indisponíveis; `complete_organic_reporting` continua `false`. Não há promessa de todos os breakdowns, períodos históricos ou paridade integral com a interface.

Veja [administração](ADMINISTRACAO.md), [Páginas](PAGINAS.md) e [monitoramento local](MONITORAMENTO.md).
