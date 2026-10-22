# Gerenciar formulários Business e enviar Galaxy

Este guia descreve o contrato **desta revisão de desenvolvimento**. Não presuma que uma versão estável já instalada pelo Composer contém esses métodos: confira `WaClient::forms()` e o código da versão escolhida.

A entrada é `$client->forms()`, no mesmo processo que mantém a sessão WhatsApp. O catálogo salvo pelo SDK é local e isolado por sessão. Publicar o formulário e enviar sua mensagem são operações remotas separadas. A abertura do conteúdo pelo WhatsApp Web ou Android depende dos clientes e da conta; ter o contrato implementado não confirma compatibilidade visual.

## O que cada operação altera

| Operação | Alcance |
| --- | --- |
| Criar, listar, consultar, editar, renomear, duplicar e excluir modelos | Catálogo persistente desta sessão SDK. Não escreve no armazenamento privado do celular. |
| Importar/exportar modelos | JSON com os campos observados no catálogo Android. Não é sincronização com o aplicativo. |
| `publish()` | Solicitação explícita de publicação remota; exige resultado confirmado com `flowId` e `wellVersion`. |
| `send()` | Publica e envia uma mensagem, com estado persistente por `requestId`. |
| `sendPublication()` | Envia **uma nova mensagem** usando uma publicação já confirmada e seu snapshot. |
| `generate()` | Autentica pela sessão e devolve a saída ou recusa do gerador remoto para revisão. Não salva nem publica automaticamente. |
| `authenticationStatus()` | Consulta somente uma projeção segura da autenticação Business, sem iniciar uma tentativa ou expor segredos. |
| Exportar respostas | Solicita ao aparelho primário um CSV, aguarda a resposta autenticada e baixa o documento. |

O catálogo usa o schema salvo pelo Android, mas **não sincroniza a tela “Meus formulários” do celular**. Excluir ou renomear um modelo local não exclui nem modifica um Flow remoto já publicado. Uma publicação conserva o snapshot utilizado, inclusive se o modelo local mudar depois.

## Sessão e ciclo de execução

Cadastre listeners antes de conectar. Continue `poll()` ou `run()` e aguarde `connected`. Faça consultas, publicação e envio no loop externo, depois dos callbacks. Cada sessão ativa tem um dono do lock; encaminhe os comandos ao worker que já está conectado.

```php
use MVO\WaClient;

$client = new WaClient(
    sessionId: 'cliente-demo',
    authDirectory: __DIR__.'/var/whatsapp-auth',
);

$client->connect();
try {
    while ($client->isRunning()) {
        $client->poll(0.25);
        if (!$client->isConnected()) continue;

        // Consuma aqui os comandos recebidos por sua aplicação.
        // $forms = $client->forms();
    }
} finally {
    $client->disconnect();
}
```

`disconnect()` preserva as credenciais. `logout()` tem outra finalidade: desvincula o aparelho. O serviço usa a sessão escolhida; não aceita credenciais de uma API consumidora como substituição do login WhatsApp.

Obtenha `$forms = $client->forms()` depois de cada conexão utilizável. A fachada pertence à conexão que a criou: depois de desconectar ou reconectar, descarte a referência anterior e obtenha outra. O catálogo e os registros persistidos continuam na pasta da sessão; a autenticação Business tem outro ciclo de vida.

## Autenticação Business automática

`publish()` e `generate()` usam a autenticação nativa da própria sessão e o transporte HTTPS implementado no SDK. Não exigem fornecer app key, access token ou cookies manualmente. Consultar e editar o catálogo local não inicia essa autenticação.

Na primeira operação remota, o SDK solicita autorização por IQ 118, aguarda a notificação Business `wa_ad_account_nonce` e troca esse nonce por token no IQ 104. As requisições são enviadas a `s.whatsapp.net`; publicação e geração seguem por HTTPS para `https://graph.facebook.com/graphql`. Esse acesso é específico da conta WhatsApp Business e não reutiliza credenciais dos pacotes Facebook/Messenger.

Cada fase nativa tem prazo de até 32 segundos: primeiro IQ, espera do nonce e segundo IQ. A primeira operação pode consumir até 96 segundos nessa autenticação, seguida de uma requisição HTTP com prazo padrão de 30 segundos. Isso é um orçamento de espera dessas etapas, não uma garantia de conclusão nem o prazo total de envio da mensagem. Execute a operação no worker dono da sessão, fora dos listeners, e dimensione o timeout da sua aplicação consumidora.

```php
$forms = $client->forms(); // Reobtenha depois de uma reconexão.
$authentication = $forms->authenticationStatus();
// Apenas estado: esta consulta não solicita autorização nem retorna o token.
```

Na fachada de `WaClient`, a projeção contém `state`, `authenticated`, `pending`, `recoveryRequired`, `serverCode`, `retryAfterSeconds` e `supportVerified`. `authenticated=true` significa que o SDK recebeu um token nessa conexão; não confirma publicação, entrega ou abertura. `supportVerified` permanece `false` enquanto esta integração não tiver homologação real. Estados como `requesting_nonce`, `waiting_nonce`, `exchanging_token`, `authenticated`, `recovery_required`, `cooldown`, `timed_out` e `failed` descrevem a etapa, não a conclusão do formulário.

O token fica somente em RAM e é limpo ao encerrar a conexão, mudar a identidade ou invalidar a autenticação. O nonce é temporário e só é aceito de uma notificação Business do transporte enquanto existe uma tentativa pendente e dentro do prazo; não é aceito de uma mensagem comum ou peer. Nonce, access token e session cookies são removidos das projeções públicas dos nós de autenticação/eventos. Não registre capturas de transporte nem exponha payloads privados na sua aplicação.

`RecoveryRequired` exige concluir a recuperação no aplicativo oficial; uma recusa 403 não é contornada. Erros 429/431 aplicam o cooldown local observado no app: 600 segundos na primeira ocorrência e 86.400 segundos na seguinte. Consulte `retryAfterSeconds`, respeite também as restrições do servidor e não repita a operação automaticamente. O cooldown é mantido em RAM por conta e sobrevive a reconexões do mesmo objeto `WaClient`; não é salvo em disco e reiniciar o processo não remove a política do servidor.

A autenticação, o serializador e os transportes estão implementados e cobertos por testes locais. A autorização da conta e a entrega real do nonce na conexão **companion Web** ainda não foram homologadas. O servidor pode recusar esse modo, não emitir o nonce ou exigir recursos do aplicativo oficial. A implementação não transforma essas situações em sucesso.

## Criar e editar um modelo local

Os exemplos seguintes pressupõem `$forms = $client->forms()` e uma sessão conectada. Cada comando deve ser executado em resposta a uma ação de aplicação, sem repeti-lo em cada passagem do loop.

```php
$forms = $client->forms();

$saved = $forms->create([
    'form_title' => 'Dados do cliente',
    'form_description' => '',
    'form_type' => 'CUSTOMER_INFO',
    'message_text' => 'Preencha seus dados para continuar o atendimento.',
    'selected_field_list' => ['NAME', 'PHONE_NUMBER', 'EMAIL', 'CUSTOM', 'CUSTOM'],
    'custom_field_names' => ['Assunto', 'Detalhes'],
    'custom_field_supporting_texts' => ['Selecione o departamento.', 'Explique como podemos ajudar.'],
    'custom_field_input_types' => ['DROPDOWN', 'TEXT_AREA'],
    'custom_field_options' => [['Suporte', 'Comercial', 'Financeiro'], []],
    'edited_field_keys' => ['NAME'],
    'edited_field_names' => ['Nome da pessoa responsável'],
    'is_ai_form' => false,
]);

$id = $saved['id']; // Identidade LOCAL; não é o flowId remoto.
$rows = $forms->list(limit: 50);
$same = $forms->get($id);
$renamed = $forms->rename($id, 'Cadastro para atendimento');
$updated = $forms->update($id, ['message_text' => 'Confira e envie seus dados.']);
$copy = $forms->duplicate($id, 'Cadastro para outro atendimento');
```

O SDK atribui `date_time` em milissegundos e expõe `id` como seu alias decimal em string. A entrada de `create()` não deve fornecer esses campos. Os títulos locais recebem um sufixo para evitar colisões. `update()` modifica somente os campos informados, preservando a identidade local.

As quatro listas `custom_field_*` são paralelas: cada ocorrência de `CUSTOM` em `selected_field_list` tem um nome, texto auxiliar, tipo e lista de opções na mesma posição. Os tipos customizados de envio comprovados no construtor Android são `TEXT_INPUT`, `TEXT_AREA` e `DROPDOWN`. Não confunda essa lista com outros enums de campos existentes no APK.

O catálogo consegue conservar as chaves Android conhecidas. O envio tipado valida um subconjunto concreto: `NAME`, `PHONE_NUMBER`, `EMAIL`, `CPF_CNPJ`, `DELIVERY_ADDRESS`, `CITIZENSHIP_CARD` e `CUSTOM`. Uma chave armazenável não garante que o builder de envio já implemente seu comportamento.

Campos predefinidos não podem repetir. `CUSTOM` pode repetir, desde que as listas paralelas correspondam. Opções do dropdown devem ser não vazias e únicas; campos de texto não têm opções. Alterar o rótulo de um campo predefinido usa `edited_field_keys`/`edited_field_names` e sua representação customizada no envio.

O catálogo aplica limites de recursos do SDK, incluindo 20 campos, 20 opções por dropdown e 1.000 modelos por sessão. Esses limites não são uma declaração dos limites do servidor WhatsApp.

### Modelos iniciais, importação e exclusão

```php
$startingPoints = $forms->templates(); // customer_info e offer_signup; definições locais.
$json = $forms->exportTemplates();

// Outra aplicação/sessão pode importar esse JSON, após revisar seus dados.
$imported = $forms->importTemplates($json);

// replace:true substitui somente o catálogo LOCAL desta sessão.
// $forms->importTemplates($jsonRevisado, replace: true);

$deleted = $forms->delete($copy['id']);
```

Não importe o JSON exportado na mesma sessão sem tratar os IDs existentes. O SDK recusa colisões de identidade; importar não envia mensagens, não publica Flows e não injeta os modelos no aplicativo Android.

## Publicar um formulário remoto

```php
$requestId = 'cadastro-publicacao-001'; // Gere e persista na sua aplicação.
$publication = $forms->publish($id, 'BR', $requestId);

if ($publication['state'] === 'published') {
    $remoteId = $publication['publication']['flowId'];
    $wellVersion = $publication['publication']['wellVersion'];
}

$status = $forms->publicationStatus($requestId);
$last = $forms->lastPublication($id);
```

O código do país tem duas letras maiúsculas, como `BR`. A definição é validada antes da tentativa remota. Não substitua o ID remoto por `id`, `date_time`, hash local ou um número inventado.

`requestId` identifica a mesma definição e país. Reutilizá-lo para outro conteúdo produz conflito; reutilizá-lo com o mesmo conteúdo consulta o resultado persistido, sem nova publicação implícita. Edite o modelo e escolha uma nova identidade de requisição para uma publicação nova.

| Estado de publicação | Interpretação |
| --- | --- |
| `prepared` | Tentativa reservada; ainda não há confirmação persistida do resultado. |
| `published` | Resposta com `flowId` e `wellVersion` validada pelo SDK. |
| `rejected` | Recusa explícita preservada, com código quando disponível. |
| `unknown` | Resultado não confirmado. Uma exceção ou timeout não prova que o servidor não criou o recurso. |
| `abandoned` | A aplicação reconheceu localmente uma tentativa incerta; isso não cancela nem exclui o recurso remoto. |

Não repita automaticamente `unknown` com um novo `requestId`. Consulte os estados e reconcilie o resultado. Se decidir assumir explicitamente a incerteza, use `$forms->abandonPublication($requestId)`. Essa ação é somente local e não significa que o servidor cancelou a publicação.

## Enviar e acompanhar a tentativa

```php
$sendRequestId = 'cadastro-envio-001';
$result = $forms->send(
    $id,
    '5511999999999', // Destino fictício; use um destinatário autorizado.
    'BR',
    [
        'cta' => 'Preencher formulário',
        'text' => 'Para continuar, preencha este cadastro.',
        'title' => 'Atendimento',
        'footer' => 'MVO',
    ],
    $sendRequestId,
);

$attempt = $forms->sendStatus($sendRequestId);
$publication = $forms->publicationStatus($sendRequestId);
```

`send()` preserva a publicação mesmo se o envio da mensagem falhar. Uma tentativa com resultado incerto não é reenviada silenciosamente. O estado do envio tem `messageId` para conciliar com outbox, recibos e eventos. `server_ack` confirma aceitação da mensagem pelo servidor, não entrega, abertura do formulário, conclusão ou leitura.

Para enviar deliberadamente uma nova mensagem usando um recurso já publicado:

```php
$newMessage = $forms->sendPublication(
    'cadastro-publicacao-001',
    '5511999999999',
    ['cta' => 'Preencher formulário', 'text' => 'Segue o cadastro solicitado.'],
);
```

Essa chamada usa o snapshot da publicação, mesmo que o modelo local tenha sido editado ou excluído. **Cada chamada solicita uma nova mensagem**; não a use como repetição automática de um envio incerto. O resultado anterior de `send()` é consultado por `sendStatus()`; a nova mensagem de `sendPublication()` deve ser acompanhada pelo ID retornado e pelo pipeline normal de mensagens.

## Tipo `business-form`, Galaxy e compatibilidade

O envio usa `interactiveMessage.nativeFlowMessage`, botão `galaxy_message`, `messageVersion = 1`, parâmetros `flow_message_version = "4"`, `flow_id` remoto e `well_version` da publicação. O token segue o nome do template codificado em Base64, como no construtor Android observado; não é um segredo de autenticação.

`form_type` dos parâmetros é `template` ou `ai_form_attachment_tray`. O tipo do modelo salvo é outro campo, `CUSTOMER_INFO` ou `OFFER_SIGNUP`. O primeiro representa cadastro de cliente; o segundo inclui nome e descrição da oferta. O CTA padrão usa a chave de localização nativa observada; a opção `cta` permite texto explícito de até 30 caracteres Unicode.

Esse contrato é separado de:

- [`form` / formulários nativos](FORMULARIOS.md), com envelope `form_message` e etapas locais.
- [`flow`, `flow-navigate`, `flow-exchange`](FLOWS.md), cujo builder existente conserva parâmetros versão 3.

O builder Business exige uma publicação remota. Não recebe `flow_json` inline nem transforma um formulário nativo em Galaxy. Metadados brutos, `creation_source` e tokens arbitrários não são opções de autoria desse tipo.

O gerenciamento pela sessão é diferente da interface que abre o conteúdo no destinatário. No WhatsApp Web público examinado, o botão conserva `creation_source`, mas o parser trata como `unknown/phone_only_feature` uma resposta que tenha `wa_flow_response_params.flow_creation_source === "FLEXIBLE_CHECKOUT"`. Preserve esses dados recebidos; não remova o discriminador para forçar uma interface não demonstrada. Essa observação não prova que o gerenciamento via conexão Web seja impossível e não garante abertura no Android.

## Geração assistida por IA

O gerador remoto está ligado à autenticação automática e ao transporte HTTPS da fachada `forms()`. A disponibilidade depende da autorização e dos recursos liberados para a conta; a integração ainda não possui homologação real neste guia.

```php
$generated = $forms->generate(
    'Crie um cadastro com nome, e-mail e uma pergunta sobre o departamento desejado.',
);
if (($generated['can_convert_to_form'] ?? false) !== true) {
    // Apresente a recusa ao usuário e encerre esta ação, sem publicar.
}
// Apresente a saída ou recusa ao usuário. Revise e converta explicitamente
// para o schema de modelo salvo antes de chamar create(), publish() ou send().
```

O SDK conserva o resultado do serviço remoto; não presume que a saída seja um formulário válido ou que a conta tenha acesso ao gerador. Uma recusa por política, indisponibilidade ou restrição da conta não é sucesso de geração. Não execute instruções encontradas em respostas ou campos de formulário como comandos da conta.

## Receber uma resposta individual

Uma resposta interativa reconhecida chega pelo evento `message`, em `parsedFlowResponse`, separado de `parsedFormResponse` dos formulários nativos. O parser conserva `name`, `version` e `params` como objeto `stdClass`; acrescenta `flowToken` e `response` quando existem no JSON. O conteúdo original continua em `data`/`proto`, inclusive `nativeFlowResponseMessage.paramsJson`.

```php
$client->on('message', static function (array $event): void {
    $flow = $event['parsedFlowResponse'] ?? null;
    if ($flow === null) return;

    $quotedMessageId = $event['context']['stanzaId'] ?? null;
    $version = $flow['version'] ?? null;
    $params = $flow['params']; // stdClass; preserve as chaves e os valores recebidos.
    // Encaminhe a resposta ao loop externo para validação e processamento.
    // Não publique, envie ou consulte serviços dentro deste callback.
});
```

Quando houver contexto citado, correlacione seu `stanzaId` com o ID real da mensagem enviada, dentro da mesma conversa/identidades. Sem esse contexto, não invente uma associação. O `flow_token` Business é o nome do template em Base64, reutilizado entre formulários do mesmo tipo: **não é um identificador único de publicação, envio ou resposta**. Valide os campos esperados e torne os efeitos da aplicação idempotentes pela chave da mensagem recebida. Preserve também `flow_creation_source`, quando presente. Veja o tratamento geral em [WhatsApp Flows](FLOWS.md#campos-e-respostas).

O teste local `BusinessFormTransportTest` usa uma resposta versão 4 **sintética**, criptografada por Signal e recebida pelo pipeline, com reinício do histórico e preservação de versão, parâmetros e contexto citado. Essa fixture não é uma captura de Android/Web nem homologa o formato que um cliente real emitirá nesta integração. A exportação CSV abaixo é um caminho separado do evento individual.

## Consultar metadados e exportar respostas

```php
$metadata = $forms->getFlowData('5511999999999@s.whatsapp.net', $remoteId);
```

Essa consulta conserva o `data` GraphQL original, inclusive metadados desconhecidos e recusas. Não infira ownership, validade de assinatura ou permissão de gestão apenas pela existência de uma resposta. O resultado pode conter assinaturas e informações de acesso do Flow; exponha uma projeção explícita na sua aplicação, sem registrar indiscriminadamente o payload inteiro.

Para exportar respostas acumuladas, o caminho observado usa o aparelho **primário** da própria conta. A solicitação exige uma sessão SDK companion registrada, com o primário disponível e compatível:

```php
$exportId = 'respostas-cadastro-001';
$export = $forms->requestResponses($remoteId, $exportId);

// Continue poll() no loop externo. A resposta autenticada do primário
// precisa chegar antes do download; o ACK da solicitação não contém o CSV.
$exportStatus = $forms->responseStatus($exportId);
if (($exportStatus['csvReady'] ?? false) === true) {
    $csv = $forms->downloadResponses($exportId); // Bytes CSV UTF-8, não o descriptor privado.
    // Ou grave em um caminho local de uma pasta já existente:
    // $forms->downloadResponses($exportId, __DIR__.'/var/respostas.csv');
}
```

A projeção de estado não expõe chaves de mídia. O documento recebido é verificado/descriptografado como mídia de documento e descomprimido de gzip. O SDK limita o arquivo comprimido a 8 MiB e o CSV expandido a 16 MiB; esses são limites locais. A interface Web examinada descreve exportação acumulada dos últimos 30 dias. Isso não é promessa de retenção, disponibilidade ou completude para todas as contas.

`responseStatus()` pode indicar solicitação em andamento, resultado incerto, CSV disponível, falha de download ou expiração. Reutilize o `requestId` para consultar o mesmo pedido; não solicite repetidamente o mesmo Flow enquanto houver uma tentativa pendente. Uma sessão primária no SDK não finge executar o mesmo pedido companion para si própria.

## Exemplo CLI e validação

O exemplo [`business-forms.php`](../examples/business-forms.php) oferece comandos explícitos para catálogo, publicação, envio e consulta de estado, incluindo `--action=authentication-status` para a projeção segura. `--help` e a execução sem argumentos encerram antes de abrir armazenamento ou sessão:

```sh
php examples/business-forms.php --help
php examples/business-forms.php --action=create --session=cliente-demo --form-file=./formulario.json
php examples/business-forms.php --action=list --session=cliente-demo
php examples/business-forms.php --action=authentication-status --session=cliente-demo
```

Salve o objeto do exemplo de criação em `formulario.json`, sem `$forms->create(...)`, `id` ou `date_time`. Use a mesma sessão e pasta nos próximos comandos. O exemplo conecta e encerra ao concluir um comando; em uma API consumidora, mantenha o worker persistente e encaminhe os comandos ao dono do lock.

### Evidência e alcance

A investigação de 22/10/2026 comparou o código público Web revision **1051302048** com o WhatsApp Business Android **2.26.42.70**. O APK analisado tem SHA-256 `6f6d817386c089c4b9fa3c538707ceb29ae12c413ed731257366e3edb6234b64`. O aplicativo não foi modificado nem executado para estas provas estáticas.

| Contrato observado | Ponto de referência nessa versão |
| --- | --- |
| Catálogo e composição Galaxy Business versão 4 | `CreateFormActivity`, construtores `HnL.invoke`/`Hnh.invoke` e codificação do token de template |
| Publicação remota e serialização HTTP | `G5b` / `HYf.Cgz`, documento `26463307113337151` |
| Geração assistida por IA | `GDy` e transporte `Fxv`, documento `27378498295139173` |
| Autenticação por nonce e token | `HSx.A01`, construtores `Dul`, `C17J.A09` / `Gml.A00`, `HNJ.CKX` e `HZN.CYr` |
| Metadados e exportação de respostas | `WAWebGalaxyFlowsDrawerGetFlowDataQuery`, `WAWebGalaxyFlowsDrawerUtils` e caminho de download CSV |
| Limite da interface Web | `WAWebGalaxyFlowsUtils.isFlexibleCheckoutForm` e `WAWebParseInteractiveResponseMessageProto` |

Esses nomes e documentos identificam o artefato estudado; não são uma promessa de estabilidade do protocolo. O SDK implementa os contratos e os verifica com testes locais. Isso não equivale a publicação aceita, nonce recebido em companion, mensagem entregue ou formulário concluído em aparelho real. A homologação remota e a compatibilidade visual dos clientes continuam pendentes, separadamente.
