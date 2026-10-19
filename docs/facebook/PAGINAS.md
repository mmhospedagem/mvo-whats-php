# Descobrir e selecionar Páginas

Após conectar, `listPages()` consulta o launchpoint Web autenticado e projeta os recursos reconhecidos. O SDK confirma que o bootstrap pertence à mesma conta. Não é uma lista exaustiva de todo recurso comercial da conta.

```php
use MVO\Facebook\FacebookConnection;

function paginasDisponiveis(FacebookConnection $facebook): array
{
    $result = $facebook->listPages();
    foreach ($result['items'] as $page) {
        echo $page['id'] . ' | ' . ($page['name'] ?? '') . PHP_EOL;
    }
    return $result;
}
```

Execute a função fora dos callbacks, com uma conexão ativa conforme o [guia de sessão](CONEXAO.md). A descoberta lembra recursos conhecidos da conta; ela não escolhe o primeiro resultado automaticamente.

## Identificadores e seleção

| Campo | Uso |
| --- | --- |
| `id` | Identificador nativo do recurso descoberto. |
| `name` | Nome observado; não use como chave. |
| `type` | Tipo reconhecido, incluindo `profile_plus` quando aplicável. |
| `actor_id` | Identidade que atua; preserve o valor retornado. |
| `delegate_page_id` | Página delegada, quando fornecida; pode diferir do actor. |

`selectPage($id)` aceita um recurso já descoberto pela mesma conta e guarda uma preferência local. A aplicação recebe a escolha explícita do usuário e valida que ela pertence à lista:

```php
use MVO\Facebook\FacebookConnection;

function selecionarPagina(FacebookConnection $facebook, string $idEscolhido): array
{
    $pages = $facebook->listPages();
    foreach ($pages['items'] as $page) {
        if ($page['id'] === $idEscolhido) {
            $facebook->selectPage($idEscolhido);
            return $facebook->selectedContext();
        }
    }
    throw new InvalidArgumentException('Escolha uma Página devolvida pela descoberta.');
}
```

A seleção não troca `c_user`, não muda a conta autenticada e não concede permissões no servidor. Métodos que recebem `$actorId`/`$pageId` continuam exigindo os argumentos explícitos corretos. `selectedContext()` devolve `account_id`, `page` e `ad_account`, com `null` quando ainda não há escolha. As preferências persistem no armazenamento privado dos serviços.

## Completude e capacidades

Na projeção atual, `exhaustive=false` e o indicador de próxima página pode ser desconhecido. Lista vazia significa que o formato reconhecido não devolveu recursos naquela consulta; não prova ausência de Páginas na conta. Não invente cursor para completar a lista e não remova escolhas antigas por causa de uma resposta parcial.

O pacote não implementa criação/exclusão de Fanpage, alteração geral de perfil, administração de membros/cargos nem insights orgânicos completos. A disponibilidade de uma Página descoberta também não confirma que todos os bindings de conteúdo ou inbox funcionem nessa conta.

Consulte [publicações](PUBLICACOES.md), [inbox](INBOX.md) e [anúncios](ANUNCIOS.md). Fontes: [PagesService](../../packages/facebook/src/Service/PagesService.php), [SelectionStore](../../packages/facebook/src/Storage/SelectionStore.php) e [PagesBootstrapReader](../../packages/facebook/src/Protocol/PagesBootstrapReader.php).
