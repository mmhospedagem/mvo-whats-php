# WhatsApp Business com MVO PHP

Use a mesma instância autenticada de `WaClient` para administrar o perfil, catálogo e ferramentas comerciais. `business()` cuida do perfil e da capa; `catalog()` administra produtos, coleções e checkout; `businessTools()` sincroniza etiquetas, respostas rápidas e listas de transmissão. Os exemplos pressupõem `$client` conectado, conforme [Conectar WhatsApp](CONEXAO.md).

O contrato de formulários Business desta revisão usa o serviço separado `forms()`: catálogo local, snapshots de publicação, envio tipado `business-form`, metadados e acompanhamento de exportação de respostas. A fachada já integra autenticação Business automática e HTTPS para geração/publicação, sem token manual; `authenticationStatus()` oferece uma projeção segura. Consulte [Gerenciar formulários Business](FORMULARIOS-GERENCIAMENTO.md) para as assinaturas, os prazos e as pré-condições. O catálogo não sincroniza a tela do aplicativo; autorização remota, entrega de nonce no modo companion e compatibilidade visual ainda não estão homologadas em conta real por este guia.

As alterações remotas aguardam a resposta do servidor. Uma conta sem autorização recebe erro; a biblioteca não converte uma recusa em sucesso. Etiquetas, respostas rápidas e listas dependem das chaves de [app-state](APPSTATE.md) compartilhadas pelo aparelho principal.

As fachadas de serviços pertencem à conexão em que foram obtidas. Após parar ou reconectar, obtenha novamente `business()`, `catalog()` ou o serviço desejado. Se um callback de HTTP/upload encerrar ou substituir a conexão, a operação antiga lança `MVO\Transport\SessionRestartRequired` e não vincula a capa, o produto ou a mídia usando a nova sessão. O upload HTTP já iniciado pode ter ocorrido; confira o resultado antes de repetir, sem presumir cancelamento remoto nem repetir automaticamente.

## Perfil, horários e capa

```php
$profiles = $client->business()->getBusinessProfile([$client->accountJid()]);

$client->business()->editBusinessProfile([
    'description' => 'Atendimento MVO de segunda a sexta',
    'address' => 'Endereço comercial',
    'email' => 'atendimento@example.com',
    'websites' => ['https://www.mvo.host'],
    'businessHours' => [
        'timezone' => 'America/Sao_Paulo',
        'config' => [
            ['dayOfWeek' => 'mon', 'mode' => 'specific_hours', 'openTime' => 540, 'closeTime' => 1080],
            ['dayOfWeek' => 'tue', 'mode' => 'open_24h'],
            ['dayOfWeek' => 'wed', 'mode' => 'appointment_only'],
        ],
    ],
]);

$coverId = $client->business()->updateCoverPhoto(['path' => __DIR__ . '/capa.png']);
// Para remover posteriormente: $client->business()->deleteCoverPhoto($coverId);
```

`openTime` e `closeTime` são minutos desde meia-noite, entre `0` e `1440`. Dias fechados são omitidos. Os dias aceitos são `mon`, `tue`, `wed`, `thu`, `fri`, `sat` e `sun`. `websites` aceita até duas URLs; `[]` limpa os sites. `categories` aceita IDs ou objetos `['id' => '...']`, e `latitude`/`longitude` aceitam coordenadas válidas. Apenas os campos informados são alterados.

A capa aceita caminho local, `['bytes' => $imageBytes]` ou stream aberto. O upload PPS envia a imagem original com SHA-256, valida os metadados retornados e só então vincula a capa ao perfil. `bindCoverPhoto($id, $timestamp, $token)` continua disponível quando o upload já foi feito pelo chamador. JPEG, PNG, WebP e GIF são aceitos pelo uploader; não há recorte automático.

## Consultar e criar produtos

```php
$catalog = $client->catalog();
$page = $catalog->getCatalog(limit: 25);
$products = $page['products'];
$nextCursor = $page['nextPageCursor'];

if ($nextCursor !== null) {
    $next = $catalog->getCatalog(limit: 25, cursor: $nextCursor);
}

$created = $catalog->createProduct([
    'name' => 'Plano MVO',
    'description' => 'Hospedagem com suporte',
    'price' => '29990',
    'currency' => 'BRL',
    'retailerId' => 'PLANO-MVO',
    'url' => 'https://www.mvo.host',
    'images' => [['path' => __DIR__ . '/produto.png']],
    'originCountryCode' => 'BR',
    'isHidden' => false,
]);

$productId = $created['id']; // ID recebido do WhatsApp.
$product = $catalog->getProduct($productId);
$selected = $catalog->getProducts([$productId]); // ['products' => [...]]
```

**Os preços do SDK são inteiros em milésimos da moeda.** `price => '29990'` representa R$ 29,99. Use string de inteiro ou `int`; valores fracionários de ponto flutuante são recusados. `priceAmount1000` e `currencyCode` são aliases de `price` e `currency`. `salePrice` usa a mesma unidade. A biblioteca preserva os valores grandes sem arredondamento por ponto flutuante.

Na criação, `name`, `description`, `price`, `currency` e `images` são obrigatórios. `currency` usa três letras maiúsculas, como `BRL`. `originCountryCode` usa duas letras; `null` declara a isenção de país de origem suportada pelo protocolo. O servidor determina os requisitos comerciais aplicáveis à conta.

As imagens devem conter de uma a dez entradas quando o campo é informado. No SDK, use caminhos locais ou `['bytes' => $imageBytes]`. Uma imagem já carregada pode ser reutilizada por `['url' => 'https://mmg.whatsapp.net/...']`; somente HTTPS em domínio real `*.whatsapp.net` é aceito para esse reaproveitamento. A biblioteca não baixa URLs externas. Se sua aplicação aceita URLs ou base64, prepare e valide os bytes antes de chamar a biblioteca.

O upload de imagem de produto usa o endpoint de catálogo com o SHA-256 dos bytes originais. Ele é diferente da mídia cifrada de mensagens: não reutilize um descritor com `mediaKey` de uma conversa como imagem do catálogo.

## Alterar preço, visibilidade e excluir

```php
$updated = $catalog->updateProduct($productId, [
    'price' => '39990',
    'salePrice' => '29990',
    'isHidden' => false,
]);

$catalog->updateProduct($productId, ['isHidden' => true]);

// Exclusão explícita, quando desejada:
// $deleted = $catalog->deleteProducts([$productId]);
// echo $deleted['deleted']; // Contagem confirmada pelo servidor.
```

Uma atualização é parcial: omitir `images` mantém as imagens atuais. `images => []` é recusado, pois não representa uma remoção de imagens comprovada pelo protocolo. `productCreate`, `productUpdate` e `productDelete` são aliases dos métodos acima. O serviço também está acessível por `$client->business()->catalog()`.

As consultas retornam o ID, dados do produto, imagens, visibilidade e os campos que o servidor disponibiliza. As consultas MEX de produto preservam informações de variantes, vídeos, conformidade e moderação quando presentes. A ausência de informação de estoque não é transformada em “disponível”. IDs de operações persistidas e permissões podem mudar no servidor.

## Coleções e associação de produtos

```php
$collections = $catalog->getCollections(limit: 25);
$collection = $catalog->createCollection('Planos MVO');
$collectionId = $collection['id'];

$details = $catalog->getCollection($collectionId, limit: 25);

$catalog->updateCollection($collectionId, [
    'name' => 'Planos em destaque',
    'productsToAdd' => [$productId],
    'productsToRemove' => [],
]);

// Exclusão explícita:
// $deleted = $catalog->deleteCollections([$collectionId]);
```

As consultas retornam `nextPageCursor`; passe esse valor no argumento `cursor` para continuar. `getCollections()` devolve `collections`; `getCollection()` devolve a coleção diretamente, com `id`, `name` e `products`. A criação também permite uma coleção vazia.

`productsToAdd` e `productsToRemove` modificam a associação sem excluir os produtos. Um ID não pode aparecer nos dois campos da mesma atualização. `catalogSessionId` é opcional nas mutações; a biblioteca gera um identificador quando não fornecido. `collectionCreate`, `collectionUpdate` e `collectionDelete` são aliases.

`deleteCollections()` faz uma requisição por coleção, sem transação de lote. Confira `deleted`, `ids` e `results`: uma recusa individual em um lote preserva as exclusões já confirmadas. Erros de transporte ou respostas inválidas continuam sendo exceções; não repita automaticamente um lote cujo resultado ficou desconhecido.

```php
// Recurso de moderação, sujeito à permissão e ao estado da coleção:
// $catalog->appealCollection($collectionId, 'Justificativa da revisão');

// Índices começam em zero e devem refletir a ordem atual conhecida:
// $catalog->reorderCollections([
//     ['id' => $collectionId, 'fromIndex' => 2, 'toIndex' => 0],
// ]);
```

Criação/edição retornam o ID e os campos de moderação recebidos. Exclusão, recurso e reordenação exigem o marcador de sucesso da resposta; uma IQ sem esse marcador é recusada pelo parser.

## Carrinho e categorias

```php
$settings = $catalog->getCartSettings();
$enabled = $settings['cartEnabled'];

$confirmed = $catalog->setCartEnabled(true);
$categories = $catalog->getBusinessCategories('Hospedagem', 'pt_BR');

// Usa um ID devolvido pela taxonomia do servidor:
// $client->business()->editBusinessProfile(['categories' => [$categoryId]]);
```

`setCartEnabled()` altera a opção comercial remota e retorna `cartEnabled` conforme a resposta recebida. `updateCartEnabled()` é um alias. `getCartSettings($jid)` consulta o perfil comercial indicado; sem JID, usa a própria conta. A consulta pode incluir `commerceExperience`.

`getBusinessCategories($query, $locale, $v2)` retorna a taxonomia localizada do servidor em `categories`, com `id`, `display_name` e possíveis subcategorias. O idioma usa `idioma_PAIS`, como `pt_BR`; `$v2` é `true` por padrão. `createCatalog()` envia a criação explícita do catálogo e exige a resposta correspondente.

## Pedidos e checkout

```php
$merchant = '5584999999999@s.whatsapp.net';

// Cria um pedido contra o catálogo do comerciante informado:
// $order = $catalog->createOrder($merchant, [
//     ['id' => $productId, 'quantity' => 1],
// ]);
// $details = $catalog->getOrder($order['order_id'], $order['token'], $merchant);
```

`createOrder()` retorna os dados de pedido recebidos, incluindo `order_id`; `getOrder()` preserva os campos de checkout e envia o token no campo sensível previsto pelo protocolo. Trate o token como dado privado. O método legado `getOrderDetails($orderId, $tokenBase64)` consulta o formato IQ e mantém o token em base64, sem decodificá-lo antes do envio.

`refreshCart(array $request)` expõe o registro extensível de checkout da especificação e retorna o `cart` do servidor. É uma operação avançada: o chamador fornece a estrutura de carrinho aceita pelo serviço remoto. A biblioteca valida os valores JSON, profundidade e tamanho; isso não substitui a validação comercial do servidor. Atualizar um carrinho de checkout e habilitar a opção comercial de carrinho são operações separadas.

## Etiquetas em conversas e mensagens

```php
$tools = $client->businessTools();
$label = $tools->createLabel('Cliente VIP', 3);
$labelId = $label['id'];

$tools->updateLabel($labelId, ['name' => 'Cliente ativo', 'color' => 5]);
$tools->setChatLabel('5584999999999@s.whatsapp.net', $labelId, true);
$tools->setMessageLabel('5584999999999@s.whatsapp.net', $messageId, $labelId, true);

$labels = $tools->listLabels();
$chatLabels = $tools->getChatLabels('5584999999999@s.whatsapp.net');
$messageLabels = $tools->getMessageLabels('5584999999999@s.whatsapp.net', $messageId);

// Desassociar: repita setChatLabel/setMessageLabel com false.
// Excluir: $tools->deleteLabel($labelId);
```

Os IDs são strings de inteiros positivos de 32 bits. Sem ID explícito, `createLabel()` usa o maior ID local/pendente mais um. As cores são inteiros de `0` a `19`. `getLabel($id)` retorna `null` quando não está na projeção sincronizada. A exclusão remove as associações locais das conversas e de todas as mensagens armazenadas.

## Respostas rápidas e listas comerciais

```php
$replyId = (string) time(); // Defina um ID estável por resposta; evite colisões.
$reply = $tools->saveQuickReply($replyId, [
    'shortcut' => '/planos',
    'message' => 'Confira nossos planos em https://www.mvo.host',
    'keywords' => ['planos', 'hospedagem'],
    'count' => 0,
]);

$tools->saveQuickReply($replyId, ['message' => 'Atendimento MVO disponível.']);
$replies = $tools->listQuickReplies(limit: 100);
// $tools->deleteQuickReply($replyId);

$list = $tools->saveBroadcastList([
    'id' => '1720000001@broadcast',
    'listName' => 'Clientes MVO',
    'participants' => [
        ['lidJid' => '123456789@lid', 'pnJid' => '5584999999999@s.whatsapp.net'],
    ],
    'labelIds' => [$labelId],
]);
$lists = $tools->listBroadcastLists();
// $tools->deleteBroadcastList($list['id']);
```

`saveQuickReply()` cria ou atualiza pelo ID numérico estável. O atalho aceita até 64 bytes, a mensagem até 4096 bytes, e `keywords` até 20 strings de 64 bytes. O contador é inteiro não negativo. `getQuickReply($id)` retorna `null` para uma resposta removida. As consultas de etiquetas, respostas e listas aceitam `limit` de `1` a `1000` e `after` com o último ID retornado; a ordenação dos IDs é lexicográfica.

As listas aceitam de 1 a 256 participantes, cada um com `lidJid` obrigatório e `pnJid` opcional. A biblioteca não inventa o LID a partir do telefone. Use os dados sincronizados da conta ou a resolução de identidade antes de salvar. Salvar a definição não envia uma mensagem; o envio explícito usa `broadcastList()->send($listJid, $content, $recipients)` com os destinatários desejados.

## Confirmação, leitura local e limites

As consultas de `businessTools()` leem a projeção local autenticada; não representam uma consulta instantânea ao celular. Aguarde a sincronização inicial antes de interpretar uma lista vazia como ausência de dados. As escritas usam patches cifrados de app-state e só retornam após confirmação. Quando o ACK falha, a ação permanece na fila durável e a projeção não anuncia a alteração como concluída. Use o loop normal de sincronização para reconciliar esse estado, sem apagar as chaves da sessão.

Erros IQ/MEX são propagados como `MVO\Service\ServiceException`, com `operation` e `serverCode`. Entradas inválidas geram `InvalidArgumentException`; respostas ausentes ou inconsistentes geram erro de parser. Nas ferramentas locais, uma atualização de registro ainda não sincronizado pode gerar `OutOfBoundsException`.

Os testes verificam produtos, coleções, carrinho e perfil com fixtures de requisições/respostas, incluindo recusas e respostas incompletas. Para as ferramentas, um segundo peer local autentica os patches e seus MACs, incluindo falha de ACK e mais de mil registros. Os protocolos de coleções/carrinho também foram conferidos em artefatos públicos do WhatsApp Web 2.2412.54. Isso comprova a implementação e os contratos testados; não comprova homologação comercial de cada recurso no servidor atual. Não foram realizadas mutações em uma conta WhatsApp Business real durante esta implementação.

O [relatório de evidências de protocolo](BUSINESS-PROTOCOL-EVIDENCE.json) registra URLs públicas, hashes SHA-256, módulos, IDs de análise estática, arquivos PHP e limites da verificação. Ele contém somente metadados, sem bundles nem dados de conta. Os artefatos de coleções/carrinho representam um protocolo histórico de 2024; a inspeção dos artefatos públicos observados em 14/10/2026 foi parcial.

Na parte atual inspecionada, as mutações de gerenciamento de produtos usam Facebook GraphQL com outro contexto de autenticação. Esses IDs não foram tratados como consultas WhatsApp MEX na biblioteca. Essa observação não demonstra que todo cliente usa o mesmo caminho nem confirma a disponibilidade ou remoção dos endpoints IQ anteriores. A compatibilidade comercial atual precisa ser homologada com a conta e o servidor que executarão a aplicação.
