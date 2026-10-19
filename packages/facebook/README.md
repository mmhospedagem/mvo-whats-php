# MVO Facebook PHP

Biblioteca PHP experimental para recursos do Facebook Web, separada do SDK WhatsApp. Pacote `mmhospedagem/mvo-facebook-php`, namespace `MVO\Facebook`, série experimental **0.1**.

**Checkout em desenvolvimento:** as extensões de gerenciamento, fotos, inbox Lightspeed e provisionamento do dispositivo abaixo ainda não fazem parte das releases Facebook 0.1.0/Messenger 0.1.1. Para avaliá-las use a instalação local `path`. Contratos e testes sintéticos não comprovam execução em conta real.

**Documentação Facebook:** [portal por assunto](https://mmhospedagem.github.io/mvo-whats-php/facebook/), [referência PHP](https://github.com/mmhospedagem/mvo-whats-php/blob/main/docs/facebook/REFERENCIA.md) e [guia para LLMs](https://github.com/mmhospedagem/mvo-whats-php/blob/main/docs/facebook/LLMS.md). O portal WhatsApp tem sua própria navegação e busca.

`FacebookConnection` conecta a conta com e-mail/senha, guarda a sessão confirmada e permite restaurá-la depois sem repetir as credenciais. Também organiza os serviços PHP de Páginas, publicações, comentários, reações, inbox e consultas de anúncios. Internamente reutiliza o runtime PHP de autenticação/transporte Messenger; a aplicação não precisa instanciar outro cliente nem importar JSON de cookies. A existência de um serviço ou de um documento Relay não comprova que todos os seus fluxos estejam disponíveis em uma conta real.

Este pacote não inclui painel, servidor REST, worker de produto ou implantação. Não altera `MVO\WaClient`, suas credenciais ou a allowlist MCP WhatsApp. O runtime é PHP 8.2+ de 64 bits; não exige Node.js ou Go.

## Instalação

Use PHP 8.2+ de 64 bits, com as extensões declaradas no [composer.json](composer.json) e nas dependências Messenger. Na aplicação consumidora:

```sh
composer require "mmhospedagem/mvo-facebook-php:^0.1"
```

Carregue `vendor/autoload.php`. O Composer instala o runtime Messenger como dependência; o SDK WhatsApp não é necessário. [Packagist](https://packagist.org/packages/mmhospedagem/mvo-facebook-php) · [Repositório de distribuição](https://github.com/mmhospedagem/mvo-facebook-php).

### Instalação local para desenvolver no monorepo

Configure os pacotes `messenger` e `facebook` como repositórios `path` no Composer **da aplicação consumidora**. Por exemplo, com o checkout ao lado da aplicação:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../mvo-whats-php/packages/messenger",
      "options": {"versions": {"mmhospedagem/mvo-messenger-php": "0.1.x-dev"}}
    },
    {
      "type": "path",
      "url": "../mvo-whats-php/packages/facebook",
      "options": {"versions": {"mmhospedagem/mvo-facebook-php": "0.1.x-dev"}}
    }
  ],
  "require": {"mmhospedagem/mvo-facebook-php": "0.1.x-dev"},
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

Execute `composer install` nessa aplicação. Ajuste somente os caminhos ao seu checkout. Essa alternativa usa os arquivos do checkout, em vez de um release publicado. O `composer.json` do pacote e as dependências transitivas do Messenger são a fonte dos requisitos de extensões.

As extensões deste checkout exigem Messenger `^0.1.2`. Até a publicação coordenada, os dois aliases `0.1.x-dev` acima resolvem essa dependência local; Messenger 0.1.1 não contém as novas interfaces. Não use somente o caminho Facebook junto de um Messenger publicado anterior.

## Conectar e guardar a sessão

Use `FacebookConnection` como entrada principal. Escolha um ID por conta e duas pastas privadas: autenticação e dados dos serviços. No primeiro acesso, `login()` confirma a identidade e salva somente a sessão; `connect()` inicia/restaura o transporte. Se o Facebook solicitar uma verificação, conclua o fluxo descrito abaixo antes de conectar.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use MVO\Facebook\FacebookConnection;

$facebook = new FacebookConnection(
    sessionId: 'facebook-demo',
    authDirectory: __DIR__ . '/var/private-auth',
    privateStorageDirectory: __DIR__ . '/var/private-facebook',
);

// $email e $password vêm da entrada privada da sua aplicação.
// Não use credenciais em código, logs ou argumentos da linha de comando.
$facebook->login($email, $password);

try {
    $facebook->connect();

    $pages = $facebook->listPages();
    foreach ($pages['items'] as $page) {
        echo $page['id'] . ' | ' . $page['name'] . PHP_EOL;
    }

    // Em um processo persistente, faça consultas e comandos no loop externo.
    // Continue $facebook->poll() enquanto precisar manter a conexão ativa.
} finally {
    $facebook->stop(); // Encerra localmente e preserva a sessão salva.
}
```

### Reabrir sem e-mail e senha

Em outro processo, use **o mesmo ID e as mesmas pastas**, chamando somente `connect()`:

```php
use MVO\Facebook\FacebookConnection;

$facebook = new FacebookConnection(
    'facebook-demo',
    __DIR__ . '/var/private-auth',
    __DIR__ . '/var/private-facebook',
);

try {
    $facebook->connect();
    $info = $facebook->accountInfo();
    // $info['has_saved_session'] informa persistência local, não validade no servidor.
    while ($facebook->isConnected()) {
        $facebook->poll(0.5);
        // Consultas/comandos da aplicação ficam aqui, fora dos callbacks.
    }
} finally {
    $facebook->stop();
}
```

Não chame `login()` a cada inicialização. Se a sessão expirar ou for revogada, faça novo login da mesma conta. Outra conta exige outro ID. Apenas um processo pode manter o lock de cada sessão. `stop()` libera o lock e fecha os armazenamentos dos serviços; não revoga a conta nem apaga a sessão, escolhas e comandos persistidos.

Os cookies confirmados e o estado ficam no SQLite privado em `<authDirectory>/messenger/<sessionId>/state.sqlite`. O nome interno da pasta corresponde ao runtime compartilhado; não exige um segundo cliente. Dados dos serviços ficam em `<privateStorageDirectory>/<accountId>/`. Não coloque essas pastas na raiz HTTP, no Git ou em logs. Senha e código do autenticador não são persistidos como credenciais de sessão.

### Autenticador, CAPTCHA e confirmação

`login()` pode lançar `MVO\Messenger\MessengerException` com uma verificação pendente. Consulte `loginChallenge()`, que retorna somente `type`, `expires_at` e `ready`. O contexto fica no mesmo processo, em memória, por até dez minutos, conservando seu lock.

```php
// Execute no processo que iniciou login(), depois de tratar a exceção de desafio.
$challenge = $facebook->loginChallenge();
if ($challenge !== null && $challenge['type'] === 'two_factor' && $challenge['ready']) {
    // $code é uma string recebida por entrada privada; preserve zeros iniciais.
    $facebook->confirmLogin($code);
    $facebook->connect();
}
```

Um CAPTCHA não aceita código TOTP. `ready=false` impede a confirmação de um formato ainda desconhecido; não repita códigos ou login automaticamente. Expiração, reinício ou `stop()` descartam o desafio pendente. O aplicativo precisa manter esse processo durante a verificação.

CAPTCHA/checkpoints exigem a ação humana no Facebook. Para um bridge privado opcional da aplicação, `exportPrivateLoginChallenge()` entrega URL/cookies/expiração **secretos**; não os devolva por HTTP, eventos, ferramentas ou logs. Após a verificação no mesmo contexto isolado, `completeBrowserLogin(CookieJar $cookies)` verifica a identidade por HTTPS antes de salvar. O pacote não abre um navegador automaticamente nem resolve CAPTCHA. Consulte o [contrato de autenticação compartilhado](https://github.com/mmhospedagem/mvo-whats-php/blob/main/docs/FACEBOOK-MESSENGER.md).

### Métodos de conexão

| Método de `FacebookConnection` | Comportamento |
| --- | --- |
| `login($email, $password)` | Autentica e salva somente a sessão confirmada; não conecta automaticamente. |
| `loginChallenge()`, `confirmLogin($code)` | Consulta o desafio seguro e continua um autenticador reconhecido. |
| `connect()`, `poll($timeoutSeconds = 0.5)` | Restaura a sessão e mantém o transporte/cursores. |
| `on($event, $handler)`, `events($after = 0, $limit = 100)` | Recebe callbacks e consulta eventos persistidos; comandos ficam no loop externo. |
| `isConnected()`, `getState()`, `accountInfo()` | Consulta transporte, estado e projeção da conta; sessão salva não comprova validade remota. |
| `stop()` | Encerra localmente, libera o lock e preserva a sessão confirmada. |
| `services()` | Acessa o `FacebookClient` dos serviços sob esse mesmo dono conectado. |

`metadata_connected` confirma a etapa de metadados descrita no guia correspondente. Não comprova acesso a todas as Páginas, permissões comerciais, completude de listas ou prontidão E2EE.

### Integração com um Messenger já existente

Para aplicações que já mantêm um `MessengerClient` conectado, a entrada avançada `new FacebookClient($messenger->webSession(), $privateStorageDirectory)` permanece disponível. Não mantenha outro `FacebookConnection` no mesmo ID/pasta ao mesmo tempo. O `WebSession` e seu `privateRequestFields()` são privados e não devem ser serializados para HTTP, eventos ou ferramentas.

`FacebookClient::close()` libera somente os armazenamentos dessa fachada avançada e não para o dono Messenger. Já `FacebookConnection::stop()` encerra o seu dono e os armazenamentos dos serviços. A aplicação controla separadamente monitores que tenha guardado.

### Exemplos locais

Os comandos abaixo partem da raiz do monorepo `mvo-whats-php`. No repositório de distribuição `mvo-facebook-php`, execute `composer install` e use `examples/...` no lugar de `packages/facebook/examples/...`.

O exemplo [session.php](examples/session.php) autentica com `--login` ou restaura sem essa opção; recebe credenciais somente pelas variáveis privadas `MVO_FACEBOOK_EMAIL` e `MVO_FACEBOOK_PASSWORD`, não por argumentos CLI. Consulte a ajuda para os diretórios e tempo de observação. Ele diagnostica desafios, mas a continuação do autenticador/bridge pertence à integração PHP acima.

O exemplo [discover.php](examples/discover.php) restaura uma sessão salva e lista Páginas sem publicar nem enviar mensagens:

```sh
php packages/facebook/examples/session.php --help
php packages/facebook/examples/discover.php --help
php packages/facebook/examples/discover.php --session=demo --auth-dir=/diretorio/privado/auth --data-dir=/diretorio/privado/facebook
```

Execute a partir do checkout, sem outro processo usando o lock dessa sessão. `--ads` acrescenta a descoberta parcial de contas publicitárias; `--ad-ids=10001,10002`, junto de `--ads`, consulta anúncios existentes por IDs conhecidos. Esses IDs são fictícios. A ajuda não conecta nem abre armazenamento. Uma fachada `FacebookClient` fechada rejeita novas consultas e aquisição de serviços antes de iniciar requisições.

## Descoberta e contexto explícito

Use `listPages()` e `listAdAccounts()` para descobrir os recursos acessíveis. A segunda consulta depende de um binding Web de anúncios realmente estabelecido; indisponibilidade não autoriza fabricar IDs ou criar uma conta de anúncios.

`selectPage($pageId)` e `selectAdAccount($adAccountId)` guardam escolhas locais entre recursos previamente descobertos para a mesma conta. `selectedContext()` consulta essas preferências. Nenhum primeiro resultado deve ser escolhido automaticamente. A seleção não troca o cookie `c_user`, não altera o usuário autenticado e não muda uma identidade global do Facebook. Não é uma concessão de permissão no servidor.

Nas operações que recebem actor, Página, publicação, comentário ou conta de anúncios, preserve os identificadores nativos exatos e o contexto exigido pelo método. Não converta IDs Facebook em JIDs WhatsApp. IDs retornados na descoberta podem ser identificadores opacos ou codificados; não derive outro ID a partir de nome, URL ou posição na lista.

## Serviços e disponibilidade

| Entrada PHP | Responsabilidade | Limite atual |
| --- | --- | --- |
| `pages()`, `listPages()` | Descobrir Páginas no launchpoint autenticado. | Parser de projeções reconhecidas; lista parcial não é inventário exaustivo. |
| `posts()` | Consultar ou alterar publicações conforme o binding do método. | Documentos e providers atuais precisam ser estabelecidos; não há promessa de CRUD completo. |
| `comments()` | Comentários e respostas nos formatos reconhecidos. | Identificadores, ordenação e paginação vêm do protocolo; escrita depende do caminho comprovado. |
| `reactions()` | Consultas e ações de reação reconhecidas. | Contador agregado não identifica autores; tipos/IDs não são inventados. |
| `inbox()` | Leitura e envio experimental de texto como Página. | Contexto atual, preflight e stream Lightspeed vinculado à Página; ACK não comprova entrega. |
| `media()` / `albums()` | Upload de fotos/vídeo, publicação de fotos e vídeo carregados e CRUD de álbuns. | Upload é separado da publicação; publicação básica de vídeo usa timeline `profile`, sem garantir processamento ou reprodução. |
| `administration()` | Pessoas, convites/cargos de Página, tarefas comerciais e acesso a assets. | Cobertura parcial, com permissão e confirmação recente; transferência de propriedade e todos os modelos modernos de acesso ausentes. |
| `insights()` / `contentInsights()` | Valores, séries, breakdowns, tabela paginada e export local CSV/JSON. | Células desconhecidas não viram zero; totais não viram dados exclusivamente orgânicos. |
| `moderation()` | Banir/desbanir usuário e bloquear/desbloquear mensagens na Página. | Permissões do Facebook se aplicam; denúncias finais ausentes. |
| `e2eeDevice()` | Preparação e registro explícito do dispositivo. | Exige GMP e confirmação do inventário; não habilita mensagens E2EE. |
| `ads()`, `listAdAccounts()` | Consultas de anúncios quando houver documentos Web comprovados. | Criação, publicação e alterações dependentes da Graph API interna do Ads Manager permanecem indisponíveis. |
| `monitor()` | SQLite local para snapshots, diferenças, cursores e eventos. | Não consulta o Facebook nem inicia polling sozinho. |
| `command($id)` | Consultar um comando registrado localmente. | Resultado local não prova exibição, entrega, aprovação ou execução de uma operação indisponível. |
| `stop()` | Encerrar a conexão `FacebookConnection`. | Preserva a sessão confirmada e os dados dos serviços. |
| `FacebookClient::close()` | Liberar os armazenamentos da fachada avançada. | Não encerra nem desconecta o Messenger. |

Consulte as assinaturas em `src/Service/` antes de chamar um método. A fachada organiza os serviços; ela não transforma um procedimento ainda desconhecido em uma operação funcional.

Métodos específicos presentes nesta revisão:

| Serviço | Métodos | Contexto obrigatório/limite |
| --- | --- | --- |
| Publicações | `list($profileId, $variables, $actorId)`, `next($profileId, $cursor, $variables, $actorId)`, `createText($actorId, $text, $idempotencyKey, $context, $variables)` | Timeline com providers atuais. Criação exige contexto real do composer, audiência, tracking e sessão; não há preparação automática desse contexto. |
| Comentários | `list($feedbackId, $variables = [], $actorId = null)`; `next($feedbackId, $cursor, $intentToken, $count = 20, $variables = [], $actorId = null)`; `previous($feedbackId, $cursor, $intentToken, $count = 20, $variables = [], $actorId = null)`; `create($actorId, $feedbackId, $text, $idempotencyKey, $context, $variables = [])` | Leituras exigem providers e `feedLocation` reais. Escrita exige `session_id` e enum `feedback_source`; não derive feedback ID de post ID. |
| Reações | `summary($feedbackId, $scale = 1.0, $actorId = null)`; `list($feedbackId, $reactionId = null, $scale = 1.0, $actorId = null)`; `next($feedbackId, $cursor, $reactionId = null, $count = 10, $scale = 1.0, $actorId = null)`; `set($actorId, $feedbackId, $reactionId, $idempotencyKey, $context, $variables = [])`; `remove($actorId, $feedbackId, $idempotencyKey, $context, $variables = [])` | Reação/feedback exatos, filtro preservado na paginação; escrita exige contexto e providers. `remove()` usa o valor de remoção estabelecido pelo documento. |
| Inbox | `threads(...)`, `messages(...)`, `lightspeedContext(...)`, `sendTextLightspeed(...)`, `sendText(...)` | Actor e Página separados; `selection_id` observado. Lightspeed usa gateway próprio; `sendText` é o contrato Mercury legado separado. `has_more=null` não significa lista esgotada. |
| Anúncios | `accounts()`, `account($id)`, `campaigns($ids)`, `adsets($ids)`, `ads($ids)`, `previews($ids)`, `insights($ids, $datePreset, $metrics)`, `insightsRange($ids, $since, $until, $metrics)`, `availability()` | Leituras Web parciais; objetos por IDs já conhecidos. Não há enumeração completa ou mutations comerciais. |

Na descoberta de anúncios, o documento observado fixa `first=2`: `accounts()` retorna no máximo duas opções do contexto, com `partial=true` e `exhaustive=false`, sem paginação estabelecida. A consulta não usa a ramificação que criaria uma conta automaticamente. `campaigns()`, `adsets()` e `ads()` recebem IDs conhecidos, não listam toda uma conta. Insights são agregados com `first=1` e `filter_zero_delivery=true`; não representam relatórios completos nem breakdowns arbitrários. Valores monetários/métricas conservam o formato retornado, sem conversões presumidas.

`availability()` descreve contratos de anúncios implementados, com `validated_in_account=false`; não é comprovação de permissão ou sucesso real. Prévia consulta um link existente, sem criar ou publicar um criativo.

Nas leituras de comentários, a consulta inicial retorna `intent_token` da ordenação escolhida pelo servidor. Use `end_cursor` em `next()` ou `start_cursor` em `previous()`, com o mesmo token e `feedLocation`; não fabrique um token para alterar a ordenação. Documentos inicial e paginado exigem conjuntos distintos de providers/variáveis.

Nas leituras de reações, `items` é a coleção paginada e `important_items` é um subconjunto separado. Preserve `reaction_filter` em `next()`. `summary()` retorna tipos e contadores; `reaction_types_count` conta tipos, não curtidas. `facebook_reaction_count`, `instagram_reaction_count` e `aggregated_reaction_count` são medidas distintas; valores desconhecidos permanecem `null`. Nenhum contador é convertido em identidade de pessoa.

O [catálogo de Páginas](resources/README.md) detalha métodos, fontes e contextos observados. As extensões incluem `posts()->editText/delete`, `comments()->editText/delete/setHidden`, `pages()->creationContext/creationRequestContext/categories/create/managed/next/get/field/updateField/delete`, `media()->photoContext/uploadPhoto/videoContext/uploadVideo/publishPhotos/publishVideo/editCaption/deleteMedia`, `albums()`, `administration()`, `moderation()`, `insights()` e `contentInsights()`. A criação usa o perfil pessoal e conserva o par perfil/Página delegada; `creationRequestContext()` adquire o contexto pela própria sessão PHP e o assistente posterior não é concluído automaticamente. Cada operação conserva seu contexto e limite específico; consulte a [referência completa](https://github.com/mmhospedagem/mvo-whats-php/blob/main/docs/facebook/REFERENCIA.md). Upload não publica, ocultação do viewer não é moderação global e métricas agregadas não são relatórios orgânicos completos.

O envio atual de texto como Página usa `inbox()->lightspeedContext($actorId, $pageId, $selectionId)` seguido de `sendTextLightspeed($actorId, $pageId, $selectionId, $text, $key, $context)`. Salve o contexto retornado junto da chave **antes de enviar** e reutilize-o exatamente. O SDK adquire configuração atual via GET autenticado e gera identidade UUIDv4 própria estável; não copia storage do navegador. Consulte [o contrato do inbox](src/Protocol/PAGE-INBOX-SEND.md). Nenhum envio real foi executado nesta etapa.

## Protocolo Web e limites

O transporte usa a sessão Web atual e documentos persistidos de `/api/graphql/` observados em artefatos fornecidos pelo próprio Facebook. `DocumentRegistry` mantém o contrato do documento e seus campos; `PageDocuments` conserva a proveniência dos bindings incluídos. Não preencha doc IDs ou variáveis com valores de outro projeto para contornar um binding ausente.

Alguns documentos exigem providers Relay cujos valores dependem do contexto atual. `PageDocuments::arguments($name, $values)` valida os providers conhecidos antes do I/O. Valores arbitrários, `null` inventado, flags copiadas de outra sessão e defaults presumidos não comprovam compatibilidade. Um documento de query ou mutation identificado estaticamente, sozinho, não estabelece o fluxo completo de execução, upload ou parsing.

**Anúncios:** este trabalho usa somente caminhos Web comprovados. Não utiliza a Graph API interna do Ads Manager como alternativa para criar/publicar anúncios, mudar status ou alterar orçamento. Encontrar uma referência em JavaScript a uma operação Graph não habilita esse recurso na biblioteca.

Erros tipados usam `FacebookException`, com `reason`, `outcome`, `retryable` e, quando disponível, `commandId`. Não trate HTTP 200 como confirmação de uma ação: respostas GraphQL podem ser parciais ou conter erros. Identificadores e cursores são strings exatas e os resultados conservam o grau de completude realmente conhecido.

## Escritas e idempotência

O transporte de mutations exige ledger privado e chave de idempotência. O registro fica incerto antes do I/O; repetir uma chave com outro conteúdo é conflito. Um comando sem resultado confirmado não é executado novamente automaticamente. Consulte `command($id)` e o estado real antes de decidir uma nova ação.

`response_received` significa somente que a resposta foi recebida/parseada; não confirma a mutation e não autoriza reenvio. `confirmed` é gravado somente depois de a projeção do serviço validar o resultado. `command($id)['result']['projection']` contém essa projeção explícita, sem o nó GraphQL bruto. Repetir a mesma chave já confirmada devolve a projeção persistida, sem outra requisição. Falha no reconhecimento dos IDs/resultados conserva o `commandId` na exceção, com comando não confirmado e sem resposta bruta persistida.

Esses controles não comprovam que uma mutation concreta já esteja pronta. Não use o transporte de baixo nível como uma promessa de publicação, edição ou exclusão. Implemente somente chamadas cujo documento, variáveis, permissões, resultado e contexto tenham sido estabelecidos para o método correspondente.

## Acompanhamento local

O [monitor](src/Monitoring/README.md) recebe somente projeções normalizadas. `ResourceScope` separa conta, identidade ativa e recurso. `SnapshotPage` conserva a ordem da paginação; `SnapshotMonitor` confirma itens, eventos e recibos na mesma transação SQLite.

A primeira coleta **exaustiva** cria baseline sem notificar conteúdo histórico. Antes dela, coletas incompletas não geram novidades. Páginas pendentes sobrevivem ao reinício. Uma coleta parcial posterior atualiza somente o que observou; ausência não implica exclusão. Somente outra coleta exaustiva permite `item.removed`, que significa ausência da coleção observada, não prova de exclusão no Facebook.

Eventos locais: `item.created`, `item.updated`, `item.removed`, `counters.changed`. Dados ausentes são desconhecidos; não zeram contadores. A aplicação agenda as consultas, fornece os snapshots, consome os eventos e persiste seu próprio cursor de processamento. O monitor não entrega webhooks nem cria threads/processos de polling.

```sh
php packages/facebook/examples/monitor-local.php /caminho/privado/monitor.sqlite
```

Esse exemplo, executado a partir do checkout, usa dados sintéticos e nenhuma conta. Repeti-lo com o mesmo SQLite demonstra deduplicação, não eventos reais do Facebook.

## Evidência e validação

Os testes deste pacote exercitam projeções sintéticas, parsing de bootstrap, contratos de documentos, transporte falso, seleção isolada por conta, ledger e monitor SQLite. Análise de artefatos públicos comprova formatos específicos observados; não substitui uma chamada autenticada bem-sucedida.

A validação real de autenticação/metadados Messenger está documentada separadamente. Ela não comprova publicação de posts, comentários, reações, inbox comercial ou administração de anúncios por este pacote. Não declare compatibilidade total ou resultado real com base em fixture, doc ID, classe existente ou contador local.

Guia completo: [Facebook PHP](https://github.com/mmhospedagem/mvo-whats-php/blob/main/docs/FACEBOOK.md). Estado de instalação e extensões: [composer.json](composer.json). Os recursos ainda experimentais e as operações não implementadas permanecem identificados no guia de limites.
