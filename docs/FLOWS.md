# WhatsApp Flows em PHP

Para formulários sem registro na Meta, use o tipo nativo `form`, descrito em [FORMULARIOS.md](FORMULARIOS.md). Opções foram confirmadas no Web e Android; o componente `InputFields` foi confirmado no Android. O modo padrão `portable` agora adapta campos de texto para opções com resposta personalizada e `cancel_option` explícito, preservando o ID de cada campo. Essa adaptação ainda depende de validação completa em clientes Web e Android reais. Os tipos Galaxy abaixo continuam tendo requisitos e compatibilidade próprios.

Use `sendMessage()` com `flow`, `flow-navigate` ou `flow-exchange`. Os três tipos usam a conexão e a fila persistente da sessão. `navigate` aceita a definição inline abaixo, sem informar um `flow_id`; também aceita o ID de um Flow registrado. `data_exchange` usa um Flow registrado com endpoint configurado.

## Formulário inline

O formulário abaixo foi enviado entre duas sessões de teste, aberto no WhatsApp Web e concluído. A aplicação recebeu a resposta autenticada e o token correspondente. **O teste no Android ainda mostrou “Ocorreu um erro”; a compatibilidade mobile deste formato continua em investigação.**

```php
$client->sendMessage('5511999999999', [
    'type' => 'flow-navigate',
    'text' => 'Preencha seu cadastro.',
    'flow_token' => 'cadastro-cliente-42',
    'flow_cta' => 'Abrir cadastro',
    'flow_json' => [
        'version' => '7.3',
        'screens' => [[
            'id' => 'INICIO',
            'title' => 'Cadastro MVO',
            'terminal' => true,
            'success' => true,
            'data' => (object)[],
            'layout' => [
                'type' => 'SingleColumnLayout',
                'children' => [[
                    'type' => 'Form',
                    'name' => 'form',
                    'children' => [
                        ['type' => 'TextInput', 'name' => 'name', 'label' => 'Nome', 'required' => true],
                        ['type' => 'Footer', 'label' => 'Enviar', 'on-click-action' => [
                            'name' => 'complete', 'payload' => ['name' => '${form.name}'],
                        ]],
                    ],
                ]],
            ],
        ]],
    ],
]);
```

`flow_json` aceita array associativo PHP, objeto ou string JSON de um objeto. A biblioteca preserva os objetos JSON, infere a primeira tela quando `screen` é omitido e gera um ID local determinístico de 16 dígitos. Esse ID identifica a definição inline; **não registra nem publica um Flow nos servidores do WhatsApp**. Os dois identificadores de inicialização exigidos pelo motor Web também são gerados internamente.

O limite da definição é 256 KiB e 100 telas. IDs de telas devem ser únicos, inclusive sem diferenciar maiúsculas e minúsculas. A definição inline não aceita ações `data_exchange` nem configuração de endpoint.

Analisamos o APK oficial Android 2.26.40.77 e um APK da versão inicialmente informada, 2.26.39.79, com assinaturas verificadas contra o oficial. A fase USB posterior identificou 2.26.40.76 no aparelho e analisou seu APK instalado. O carregador consulta cache e baixa a definição por `/json/{flow_id}`; o ID local da definição inline não corresponde a um registro remoto. Essa observação explica uma diferença entre os carregadores, sem comprovar o comportamento de todas as versões Android. Trocar versões de mensagem ou gerar outro número local não publica a definição.

O formato distinto `form_message` / `a2ui_form` envia a definição local e resolveu os testes nativos anteriores. O Android seleciona seu parser pelo nome do envelope externo. O envelope `mixed` chegou ao Web, mas não apareceu no Android; `form_message` com atributos herdados foi recusado com código 473. O formato público exato, sem esses atributos, abriu e enviou respostas no Android. A implementação tipada está em [FORMULARIOS.md](FORMULARIOS.md), incluindo opções, a nova adaptação portável de texto livre com cancelamento explícito e `InputFields` específico do modo Android. A evidência dos testes anteriores não homologa a nova adaptação. Envie uma nova mensagem para usá-la; o código não altera definições já recebidas nem configura um endpoint `data_exchange` registrado.

## Navigate

`navigate` abre uma tela do Flow com dados iniciais opcionais. Informe `screen` e `data`, ou o objeto `flow_action_payload`, sem misturar os dois formatos.

```php
$common = [
    'text' => 'Preencha seu cadastro.',
    'flow_id' => '123456789012345',
    'flow_token' => 'cadastro-cliente-42',
    'flow_cta' => 'Abrir cadastro',
];

$sent = $client->sendMessage('5511999999999', [
    ...$common,
    'type' => 'flow-navigate',
    'screen' => 'CADASTRO',
    'data' => ['cliente_id' => 42],
]);
```

O tipo genérico `flow` permite escolher a ação explicitamente. Sem ação, usa `navigate`:

```php
$client->sendMessage('5511999999999', [
    ...$common,
    'type' => 'flow',
    'flow_action' => 'navigate',
    'flow_action_payload' => [
        'screen' => 'CADASTRO',
        'data' => (object)[],
    ],
]);
```

`data` deve ser um objeto JSON. Um array PHP vazio nesse campo é convertido em `{}`; uma lista não vazia é rejeitada. Objetos e listas dentro dos dados mantêm seus tipos.

## Data exchange

`data_exchange` inicia a troca com o endpoint configurado no Flow. A mensagem não leva `screen`, `data` nem `flow_action_payload`. O envio dessa mensagem não cria ou configura o endpoint.

```php
$client->sendMessage('5511999999999', [
    ...$common,
    'type' => 'flow-exchange',
]);
```

`flow_action => 'data_exchange'` com `type => 'flow'` produz a mesma ação. Combinações contraditórias, como `flow-exchange` com `flow_action => 'navigate'`, são rejeitadas antes do envio.

A biblioteca inclui internamente `flow_metadata: {"data_api_version":"3.0"}` em `data_exchange`. A presença dessa versão faz o cliente consultar os metadados remotos e verificar a chave e a assinatura do endpoint, em vez de pular essa consulta em uma mensagem recente. Para `navigate` com endpoint registrado, é possível optar por essa consulta com `'data_api_version' => '3.0'`. Esse é o único valor aceito; a opção é rejeitada com `flow_json`. Metadados brutos, URLs de endpoint e chaves não são parâmetros de autoria da mensagem.

O valor `3.0` consta do exemplo `Get Flow` da [coleção oficial de WhatsApp Cloud API](https://raw.githubusercontent.com/WhatsApp/WhatsApp-Flows-Tools/main/postman/v1/whatsapp-cloud-api.postman_collection.json). A correção foi validada no conteúdo criptografado e no retry persistente; a execução de um endpoint real continua sem homologação.

## Campos e respostas

Informe `flow_json` ou `flow_id` (string numérica). `flow_token`, `flow_cta` e `text` são obrigatórios. O CTA permite até 30 caracteres. A versão de parâmetros suportada é `3`. `mode`, quando informado, aceita `published` ou `draft`. `title` e `footer` são opcionais. Os aliases `cta` e `action` continuam disponíveis.

```php
$client->on('message', function (array $event): void {
    $flow = $event['parsedFlowResponse'] ?? null;
    if ($flow === null) return;

    // params é um objeto JSON; os campos brutos continuam em proto/data.
    $token = $flow['flowToken'] ?? null;
    echo json_encode($flow['params'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
});
```

Respostas nativas `galaxy_message` e o alias legado `flow` são reconhecidos. O parser mantém o token, a versão e `wa_flow_response_params`, quando presentes. JSON inválido ou uma lista no lugar do objeto não interrompe a recepção: o conteúdo bruto permanece disponível.

## Compatibilidade conferida

A implementação foi comparada com módulos públicos do WhatsApp Web e com seu motor de formulários executado em um ensaio controlado. O botão criptografado usa `galaxy_message` e `messageVersion = 1`. O envio usa o envelope de negócio `native_flow(name=mixed,v=9)`, aceito no teste real; a alternativa `name=galaxy_message` foi recusada pelo servidor com código 405. Esta página publica os resultados e limites da verificação; capturas do ambiente de desenvolvimento e registros de aparelhos ficam fora da distribuição pública.

Os testes locais cobrem criptografia Signal, cópia para aparelhos próprios, reinício, retry com o mesmo ciphertext e respostas autenticadas. O teste real confirmou aceitação, recepção nativa, abertura e conclusão do formulário inline no Web. No Android, o modal abriu e seu conteúdo exibiu um erro nativo; a abertura de um Flow publicado e a execução de seu endpoint ainda não foram homologadas nesta biblioteca.

Uma recusa explícita do WhatsApp é registrada como `rejected` com `serverCode`. Timeout sem confirmação permanece `unknown`: não é prova de recusa ou entrega. Consulte o evento/job antes de reenviar para evitar duplicidade.

## O envelope `mixed` explica o erro Android?

Para `form_message`, o Android seleciona o parser pelo nome do envelope externo; a correção usa `form_message` nesse envelope. Galaxy tem uma regra diferente: nos APKs 2.26.39.79 e 2.26.40.77, o validador permite explicitamente o botão `galaxy_message` dentro de um envelope `mixed`.

Repetimos o teste com o Flow inline atual, mantendo o builder e mudando somente a construção do envelope para o formato público `biz {}` → `interactive {type:native_flow,v:1}` → `native_flow {name:galaxy_message}`, sem atributos herdados nem versão no último nó. O servidor recusou esse envio com ACK 405 antes de chegar ao destinatário. A variante pública plana `biz {native_flow_name:galaxy_message}` também foi recusada, com ACK 479. Não atribuímos um significado específico a esses códigos. A implementação anterior foi restaurada byte a byte, preservando as sessões.

Isso não estabelece a causa exata do erro interno do Galaxy no aparelho. O teste anterior com `mixed` já abria o modal no Android e falhava depois; a análise aponta para o carregamento e a inicialização do conteúdo como etapa seguinte a investigar. O registro auditável distingue essa inferência dos resultados reais.

## Diagnóstico do APK Android

Em 15/10/2026, aprofundamos a engenharia reversa do APK 2.26.39.79, versão inicialmente informada pelo usuário. A identidade do pacote e suas assinaturas foram verificadas. O ponto de abertura também foi comparado com o APK oficial 2.26.40.77. Esta fase offline combinou decompilação com conferência das instruções DEX, sem acessar o celular. A observação USB posterior está documentada abaixo.

O caminho normal do botão Galaxy apresenta esta diferença em relação ao Web:

| Etapa | Comportamento encontrado |
| --- | --- |
| Abrir o botão | `Bda.A01` lê ID, token, versão, ação, CTA, modo e dados da ação. Não encaminha `flow_json`, os IDs de tracking ou `flow_metadata` do botão para o modal. |
| Inicializar | `FlowsWebViewDataRepository.A08` prepara os dados da ponte. Os identificadores de tracking são calculados no cliente; enviar outros identificadores no botão não altera esse caminho. |
| Resolver a definição | Na navegação normal, `A01` consulta metadados em cache ou na rede e verifica o ID. No caminho controlado pela configuração 8552, `A07` consulta o cache do JSON e busca `/json/{id}` quando necessário. |
| Tratar falhas | Um resultado `Dpf` segue para o estado de erro da interface. Falhas de metadados e de JSON podem convergir nessa interface; o texto genérico exibido pelo aparelho não revela qual falhou. |
| APK oficial mais novo | A seleção de campos de `E97.A01`, equivalente a `Bda.A01`, continua sem encaminhar a definição inline. A comparação não demonstra uma solução por atualização de versão. |

`navigate` não elimina a resolução dos metadados nesse caminho Android. `draft` evita reutilizar o cache de metadados e consulta a rede. A visualização de uma resposta já preenchida tem um caminho separado: não transforma o JSON inline de um novo botão em definição local.

Uma revisão independente dos 12 DEX encontrou somente três métodos com referência direta ao literal `flow_json`: a leitura do JSON já carregado em `A05`, o carregador `A07` e o descritor do serializador `FOR`. A revisão das chamadas explícitas do cache e de `FlowDataResponse` não encontrou outro consumidor de mensagem que armazene a definição enviada diretamente. Essa busca é limitada a literais e referências explícitas desse APK.

Colocar a definição em `flow_action_payload.data.flow_json` também não é uma correção sustentada pelo motor público examinado. O motor constrói o template a partir do `flow_json` de inicialização antes de aplicar a ação de navegação; os dados dessa ação continuam sendo dados da tela.

### Ensaio controlado do motor

Geramos um formulário Nome com o `FlowBuilder` PHP real, sem conexão ou credenciais, e executamos o motor público inalterado em um host Android simulado:

| Entrada da ponte nativa simulada | Resultado observado |
| --- | --- |
| `flow_json` como string, payload em JSON/base64 e tracking presentes | Renderizou Nome e emitiu `WAExtensionsComplete` com o token controlado. |
| Mesmo conteúdo, sem `flow_json` | Formulário ausente. |
| Mesmo conteúdo, sem tracking | Carregamento sem campo. |
| `flow_json` como objeto na ponte | Exibiu erro. |
| Payload da ação como objeto bruto na ponte | Exibiu erro. |

Essas representações são da ponte entre o aplicativo e o motor, não novos formatos para `buttonParamsJson`: o carregador Web converte o objeto da mensagem antes de entregá-lo ao motor. O ensaio usa o bootstrap público `flows-v2/wa-web` com host Android simulado; não equivale ao WebView do aparelho. O botão de conclusão foi acionado pelo ensaio, mas seu rótulo ficou vazio nesse bootstrap, uma limitação registrada.

O APK seleciona uma URL externa por configuração. A busca nos 313 assets do pacote não identificou um motor Flow embarcado; o bootstrap móvel efetivo e os valores das configurações da conta continuam desconhecidos. Por isso, o ensaio confirma que a definição pode ser processada pelo motor comparativo, sem comprovar a abertura pelo APK instalado.

### Resultado e limite da correção

Não encontramos um campo aceito pelo caminho de abertura analisado que encaminhe a definição Galaxy inline ao Android. O ID local gerado em PHP não fornece os metadados e o JSON remoto que esse caminho procura. A investigação ainda não produziu uma correção PHP verificada para o Galaxy inline no Android, nem uma implementação de `data_exchange` sem registro remoto.

O requisito de não registrar Flows na Meta foi preservado. A biblioteca mantém o comportamento já confirmado no Web e o tipo distinto `form`; nenhum formulário nativo é apresentado como correção do Galaxy. A observação USB confirmou a interface de erro, mas ainda faltam o código interno, os valores de configuração e as respostas de carregamento para atribuir a falha exata a uma dessas etapas.

Esta análise estática não enviou mensagens nem reiniciou sessões. Seus resultados descrevem os caminhos encontrados no cliente analisado; não confirmam a execução de um Flow inline no Android.

### Debug por USB no aparelho

Em 15/10/2026, observamos o Samsung Z Fold6 (`SM-F956B`), Android 16, com WhatsApp **2.26.40.76** instalado. O usuário abriu a mensagem Galaxy já recebida e manteve o erro visível. A captura de tela e a hierarquia nativa confirmaram o texto **“Ocorreu um erro.”** no componente `com.whatsapp:id/error`, dentro de `Conversation`.

Extraímos por ADB os quatro APKs já instalados, preservando seus hashes. A análise desse `base.apk` confirmou a seleção dos mesmos oito campos na ação `Bhh.A01`, sem encaminhar `flow_json` do botão. O repositório prepara a ponte em `A09` e, no caminho analisado, resolve metadados e JSON pelo ID; o carregador é `A08` / `EpQ`. Os nomes ofuscados mudaram em relação aos APKs anteriores.

| Evidência nesta fase | Resultado e limite |
| --- | --- |
| Interface real | Erro nativo observado diretamente. A hierarquia não expôs um nó WebView; isso também pode ocorrer com uma sobreposição de erro ou uma WebView oculta. |
| Logcat do processo principal | Capturas limitadas a 1.000 linhas. Não expuseram o código Galaxy de erro de metadados/JSON; logs de outros processos podem ficar fora desse recorte. |
| Avisos da WebView | O stack de `clearHistory` corresponde a `FlowsWebViewFragment.A21` → `Fxr.A00` → limpeza. Esse helper carrega `about:blank` antes de limpar e destruir a WebView. Os avisos de URI `about` e de instância já destruída não identificam a falha inicial. |
| Depuração do renderer | A busca no inventário acessível não encontrou o socket de depuração da WebView do processo principal. Nenhuma sessão CDP ou debugger Java foi anexada. |

Esta fase foi uma observação passiva do aplicativo original. Não houve instalação ou modificação de APK, extração do armazenamento privado do aplicativo, interceptação TLS, novos envios ou reinícios de sessões. Os logs brutos e a captura de tela permanecem privados e fora dos pacotes; o relatório exporta somente metadados selecionados, constantes e hashes.

**Resultado:** o erro Android foi observado no aparelho e o caminho de carregamento foi conferido no APK instalado. A causa inicial exata e uma correção PHP para o Galaxy inline continuam sem confirmação. A observação no aparelho é distinta da análise offline e do ensaio com host simulado; os registros privados de depuração não fazem parte desta distribuição.
