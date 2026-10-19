# Páginas: descoberta, informações e edição

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

`pages()->managed()` usa a coleção Web `additional_profiles_with_biz_tools` e devolve `page_info.end_cursor` e `has_next_page`. A continuação ocorre com `pages()->next($cursor)`. Cada resposta mantém `exhaustive=false`: chegar ao fim dessa coleção não comprova o inventário de todos os ativos comerciais da conta. `listPages()` conserva o caminho anterior de bootstrap.

## Criar uma Página pelo perfil pessoal

O fluxo atual é `AdditionalProfilePlusCreation`, disponível em `https://www.facebook.com/pages/creation/` quando a interface está no **perfil pessoal**. Abrir essa rota atuando como uma Página/empresa pode mostrar conteúdo indisponível. Essa diferença de identidade não significa que a criação deixou de existir.

`creationContext()` consulta a capacidade `can_create_page`, confirma que o actor é a pessoa autenticada e projeta categorias sugeridas, quando disponíveis. O método não cria nada, não importa a lista de amigos devolvida pelo documento e não troca o perfil automaticamente.

`create()` também impõe essa conferência automaticamente em toda tentativa nova, depois de reservar o comando e antes da mutation. Capacidade negativa, actor de Página/Business ou falha da consulta bloqueiam a criação com `outcome=not_executed` e `commandId`. Um replay já confirmado não refaz a consulta; resultado incerto não é reenviado. O estado de preflight bloqueado registra `execution_started=false`, distinguindo-o de uma criação que pode ter alcançado o servidor.

`categories($search, $limit = 10)` consulta o seletor nativo. O limite é aplicado localmente: não existe um parâmetro de paginação ou contagem inventado no documento. Preserve os IDs retornados; a pesquisa não é um catálogo exaustivo de todas as categorias.

`create($name, $categoryIds, $idempotencyKey, $context, $bio = '')` executa a primeira criação. Requer de uma a três categorias distintas, nome de 2 a 75 unidades UTF-16 e bio opcional de até 255 unidades UTF-16, seguindo os limites observados na interface. As demais regras de nome e elegibilidade são decididas pelo Facebook.

```php
use MVO\Facebook\Service\PagesService;

function criarPaginaEscolhidaPeloUsuario(
    PagesService $pages,
    string $nome,
    array $categoriasEscolhidas,
    string $idempotencyKey,
): array {
    $capacidade = $pages->creationContext();
    if (!$capacidade['can_create_page']) {
        throw new RuntimeException('O Facebook não permite criar a Página neste contexto.');
    }

    // A aplicação apresenta os termos atuais e chama esta função após a ação explícita do usuário.
    return $pages->create(
        $nome,
        $categoriasEscolhidas,
        $idempotencyKey,
        $pages->creationRequestContext(),
        'Descrição da nova Página.',
    );
}
```

`creationRequestContext()` obtém esse contexto em PHP usando a própria sessão: faz um GET autenticado da rota observada `https://www.facebook.com/pages/creation/?ref_type=launch_point`, confirma o `CurrentUserInitialData.USER_ID` e lê o gate `20935` dos dados inertes `HasteSupportData`/`ServerJS`. Nenhum JavaScript é executado e nenhum cookie é importado do navegador.

O resultado contém `creation_source` (`comet` se o gate for verdadeiro, `www` se falso), `page_referrer=launch_point`, derivado do link nativo solicitado, e `off_platform_creator_reachout_id=null`, pois essa rota não inclui esse parâmetro. Gate ausente, formato desconhecido, conta divergente e valores conflitantes são erros; não há default de flag inventado. Se a aplicação já tem outro contexto Web efetivo, pode fornecer os três campos diretamente, preservando os valores da rota e branch correspondentes.

A resposta válida precisa trazer **ambos** os IDs: `profile_id`/`actor_id` do novo perfil e `page_id`/`delegate_page_id` da Página. Não são intercambiáveis. `setup_complete=false` registra que os passos posteriores de contato, fotos, WhatsApp, audiência e preferências não foram concluídos automaticamente. Nenhum recurso criado é selecionado automaticamente.

`name_error`, `error_message`, resposta incompleta e timeout não são criação bem-sucedida. Conserve o comando e a chave de idempotência; uma operação incerta não é reenviada automaticamente. A abertura do formulário foi observada no perfil pessoal, mas nenhuma Página real foi criada nesta validação.

## Consultar e editar informações

`get($pageId)` retorna apenas o nome e ID observados pelo documento Web. O editor por campo oferece `name`, `blurb`, `email`, `phone` e `website`. `field()` consulta o valor atual; `updateField()` solicita uma alteração independente e idempotente. O contexto `end_point`/`entry_point` deve corresponder ao editor Web atual; o catálogo valida os enums conhecidos.

```php
use MVO\Facebook\Service\PagesService;

function atualizarDescricao(
    PagesService $pages,
    string $pageId,
    string $idempotencyKey,
    array $contextoAtualDoEditor,
): array {
    $antes = $pages->field($pageId, 'blurb', $contextoAtualDoEditor);
    $resultado = $pages->updateField(
        $pageId,
        'blurb',
        'Hospedagem de sites, cloud e e-mail profissional.',
        $idempotencyKey,
        $contextoAtualDoEditor,
    );
    return ['antes' => $antes, 'alteracao' => $resultado];
}
```

O actor padrão é a Página; o único actor alternativo aceito é a pessoa autenticada. Não use o ID de outra Página como actor. O SDK não concede permissões por definir `av`. Alterar o nome pode abrir uma solicitação de análise: `name_approval_confirmed=false` permanece explícito.

## Solicitar exclusão

`pages()->delete($pageId, $idempotencyKey)` usa a mutation Web de exclusão. O resultado reconhecido informa `status=deletion_requested` e `permanent_deletion_confirmed=false`. O Facebook decide o prazo de restauração e a exclusão definitiva. Não chame esse método para simplesmente desconectar uma sessão.

Não há atualização atômica de vários campos: cada campo usa uma chave de comando própria. Se houver timeout, erro GraphQL ou formato desconhecido, conserve o `commandId`, consulte o ledger e não reenfileire automaticamente a escrita.

## Cobertura demonstrada e limites

Há testes sintéticos de formatos, escopo, paginação, rejeição, replay e resultados incertos. Eles não comprovam uma edição ou exclusão de Página real. `pages()->availability()` descreve a cobertura de contrato.

A criação usa o contrato atual observado no perfil pessoal. O pacote ainda não oferece edição arbitrária de todos os campos/configurações nem conclusão integral do assistente pós-criação. Testes sintéticos do contrato não substituem uma criação real autorizada em conta de teste.

Administração comercial, convites e limites de cargos estão no [guia de administração](ADMINISTRACAO.md). Métricas e separação entre totais e tráfego orgânico ficam em [insights](INSIGHTS.md).

Consulte [publicações](PUBLICACOES.md), [inbox](INBOX.md) e [anúncios](ANUNCIOS.md). Fontes: [PagesService](../../packages/facebook/src/Service/PagesService.php), [SelectionStore](../../packages/facebook/src/Storage/SelectionStore.php) e [PagesBootstrapReader](../../packages/facebook/src/Protocol/PagesBootstrapReader.php).
