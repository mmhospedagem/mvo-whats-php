# Consultar contas de anúncios

`ads()` implementa leituras parciais do protocolo Web observado. Não usa a Graph API interna do Ads Manager como alternativa para criar campanhas, publicar, mudar orçamento ou alterar status. Essas operações permanecem indisponíveis nesta revisão.

## Descoberta e escolha local

`listAdAccounts()` descobre opções do contexto e as lembra no armazenamento privado. `selectAdAccount($id)` guarda a escolha explícita entre recursos descobertos da mesma conta. `selectedContext()['ad_account']` consulta essa preferência, sem trocar a identidade autenticada ou conceder permissões.

```php
use MVO\Facebook\FacebookConnection;

function contasPublicitarias(FacebookConnection $facebook): array
{
    $result = $facebook->listAdAccounts();
    foreach ($result['items'] as $account) {
        echo $account['id'] . ' | ' . ($account['name'] ?? '') . PHP_EOL;
    }
    return $result;
}
```

O documento atual fixa `first=2`. O retorno contém no máximo duas opções, com `partial=true`, `exhaustive=false` e `maximum_items=2`. Não há paginação completa de todas as contas. A consulta bloqueia a ramificação que criaria uma conta automaticamente; lista vazia não autoriza criação nem invenção de IDs.

## Objetos por IDs conhecidos

| Método | Consulta | Limite |
| --- | --- | --- |
| `accounts()` | Opções de conta no contexto atual. | Lista parcial; no máximo duas opções. |
| `account($id)` | Nome/moeda/status de uma conta nativa exata. | Pode retornar `null`; exige acesso. |
| `campaigns($ids)` | Estrutura e metadados de status/entrega de campanhas. | IDs previamente conhecidos. |
| `adsets($ids)` | Metadados de conjuntos de anúncios. | IDs previamente conhecidos; campos do servidor. |
| `ads($ids)` | Metadados de anúncios existentes. | IDs previamente conhecidos. |
| `previews($ids)` | Link de prévia já existente. | Não cria criativo nem publica. |

As listas `$ids` têm de 1 a 100 strings numéricas nativas. Esses métodos não enumeram toda uma conta publicitária. `campaigns()`, `adsets()` e `ads()` retornam nós reconhecidos do servidor; não são projeções completas do editor Ads Manager ou de seus resolvers Relay.

```php
use MVO\Facebook\FacebookConnection;

function consultarAnunciosConhecidos(FacebookConnection $facebook, array $ids): array
{
    return $facebook->ads()->ads($ids);
}
```

Receba IDs de recursos realmente conhecidos da aplicação/conta; não tente números sequenciais ou derivados de nomes. A seleção local de uma conta não substitui os IDs exigidos por esses métodos.

## Métricas agregadas

`insights($ids, $datePreset = 'today', $metrics = ...)` e `insightsRange($ids, $since, $until, $metrics = ...)` consultam o contrato agregado estabelecido. Datas explícitas usam `YYYY-MM-DD`; o intervalo fornecido inclui o dia final. A biblioteca converte para os limites de horário esperados pelo documento.

Métricas admitidas: `SPEND`, `IMPRESSIONS`, `REACH`, `COST_PER_RESULT`, `PERFORMANCE_INDICATOR`, `ANCHOR_EVENT_ATTRIBUTION_SETTING` e `RESULTS`, em uma lista de até sete itens. O conjunto padrão contém as seis primeiras. Não há breakdown arbitrário nem relatório completo de conta.

```php
use MVO\Facebook\FacebookConnection;

function metricasDoPeriodo(
    FacebookConnection $facebook,
    array $idsConhecidos,
    string $inicio,
    string $fim,
): array {
    return $facebook->ads()->insightsRange(
        $idsConhecidos, $inicio, $fim, ['SPEND', 'IMPRESSIONS', 'REACH'],
    );
}
```

O binding usa `first=1` e `filter_zero_delivery=true`; ausência de linha não significa ausência de objeto ou gasto zero. Preserve os valores monetários, moeda e métricas no formato do servidor. Não presuma divisão por cem, conversão cambial ou escala numérica comum a todos os campos.

## Disponibilidade e evidência

`ads()->availability()` descreve os contratos implementados, com `read_only=true`, `validated_in_account=false` e `account_list_exhaustive=false`. Isso não é teste de permissões, confirmação de conta conectada ao Ads Manager nem sucesso real das consultas.

Não há criação de campanha/conjunto/anúncio/criativo, edição de orçamento/status ou publicação. Os testes usam contratos e respostas sintéticas; a navegação autenticada no Ads Manager não valida essas queries comerciais. Fonte: [AdsService](../../packages/facebook/src/Service/AdsService.php). Veja [limites](LIMITES.md).
