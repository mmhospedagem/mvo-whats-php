# Formulários nativos sem registro na Meta

Use `type => 'form'` para enviar a definição junto com a mensagem, sem `flow_id`, conta de desenvolvedor ou publicação na Meta. O envio usa a conexão PHP, Signal, fila persistente e eventos da sessão.

Um teste anterior entre duas sessões confirmou opções no WhatsApp Web e Android e o componente `InputFields` no Android 2.26.39.79. A adaptação portável de texto livre com cancelamento explícito descrita abaixo é um novo contrato do SDK, baseado no parser Web analisado; ainda precisa de validação completa em clientes reais Web e Android. A compatibilidade depende da versão do cliente. As limitações do formato Galaxy estão descritas em [FLOWS.md](FLOWS.md).

## Opções e etapas no Web e Android

O modo padrão `platform => 'portable'` aceita seleção única ou múltipla, até cinco opções por etapa e resposta personalizada opcional. O limite é de três etapas finais, incluindo os campos de texto expandidos conforme a seção seguinte. O próprio WhatsApp apresenta a navegação entre etapas.

```php
$sent = $client->sendMessage('5511999999999', [
    'type' => 'form',
    'text' => 'Responda ao atendimento MVO.',
    'cta' => 'Responder formulário',
    'steps' => [
        [
            'id' => 'pedido_42_servico',
            'label' => 'Qual serviço você procura?',
            'selection' => 'single',
            'options' => [
                ['id' => 'hospedagem', 'title' => 'Hospedagem'],
                ['id' => 'servidor', 'title' => 'Servidor'],
            ],
            'allow_custom_answer' => true,
        ],
        [
            'id' => 'pedido_42_recursos',
            'label' => 'Quais recursos são necessários?',
            'selection' => 'multiple',
            'options' => [
                ['id' => 'backup', 'title' => 'Backup'],
                ['id' => 'email', 'title' => 'E-mail'],
            ],
        ],
    ],
]);
```

`text`, `cta` e `steps` são obrigatórios. `title` e `footer` são opcionais. O CTA permite até 30 caracteres. IDs das respostas são definidos pela aplicação. `allow_custom_answer` acrescenta um campo livre junto às opções.

## Texto livre com “Cancelar consulta” no modo portável

Para solicitar CPF/CNPJ com texto livre e uma opção visível de cancelamento, use `kind => 'input'` no modo padrão e informe `cancel_option` explicitamente:

```php
$sent = $client->sendMessage('5511999999999', [
    'type' => 'form',
    'platform' => 'portable', // Padrão quando omitido.
    'text' => 'Digite o CPF/CNPJ para consulta ou selecione Cancelar consulta.',
    'cta' => 'Informar documento',
    'steps' => [[
        'id' => 'pedido_42_documento',
        'kind' => 'input',
        'label' => 'Consulta de cadastro',
        'button' => 'Enviar',
        'cancel_option' => [
            'id' => 'pedido_42_cancelar',
            'title' => 'Cancelar consulta',
        ],
        'fields' => [[
            'id' => 'pedido_42_cpf_cnpj',
            'label' => 'CPF ou CNPJ',
            'type' => 'text',
            'required' => true,
        ]],
    ]],
]);
```

Execute o envio depois de `connected`, fora dos callbacks, e continue `poll()`/`run()`. O [exemplo CLI completo](../examples/native-form.php) conecta, envia uma vez e processa a resposta no loop externo. O número acima é fictício; use um destinatário autorizado e IDs exclusivos para cada consulta.

O parser Web distribuído analisado aceita `ChoicePicker` com pelo menos uma opção e resposta personalizada; ele rejeita `InputFields` e uma lista de opções vazia. Por isso, o SDK transforma **cada campo** em um `ChoicePicker` de seleção única (`mutuallyExclusive`), com o ID original do campo, uma única opção real de cancelamento e `allow_custom_answer => true`. Os rótulos da etapa e do campo compõem a pergunta. Essa adaptação não esconde opções nem altera respostas recebidas.

Uma etapa de entrada exige de um a cinco campos no schema, mas no modo portável cada campo ocupa uma das três etapas finais. Dois campos mais uma etapa de opções totalizam três; três campos mais uma etapa de opções são rejeitados. IDs de campos e etapas não podem colidir. `type => 'text'` e `required` booleano continuam obrigatórios. A aplicação valida texto obrigatório e o documento antes de consultar seu serviço: o componente de seleção também aceita o cancelamento como resposta válida.

No modo `portable`, o valor exato `__proto__` é reservado nos IDs que se tornam chaves de resposta: o ID de uma etapa de opções (`kind => 'choice'`) e o ID de cada campo de entrada (`kind => 'input'`). A construção da resposta no Web perde esse valor; o SDK rejeita esses IDs antes do envio, sem renomeá-los. O ID de agrupamento da etapa `input` não vira chave de resposta e pode usar esse valor. Essa reserva não se aplica ao modo `android`; os demais IDs válidos continuam preservados literalmente.

`cancel_option` exige `id` e `title` não vazios e não é criado automaticamente. O texto “Cancelar consulta” é uma opção que o destinatário seleciona e envia; não é uma ação especial do modal. Em um formulário com várias etapas, ela não encerra nem pula as etapas seguintes. Sua aplicação decide cancelar a consulta quando receber a resposta completa. Para pedir somente o documento ou o cancelamento, use um único campo como no exemplo.

O modo portável rejeita `default` não vazio porque a resposta personalizada do Web não oferece esse preenchimento inicial. Omita `default` ou use `''`.

## Campos nativos `InputFields` no Android

Para manter o componente `InputFields` exato, escolha explicitamente `platform => 'android'`. Esse modo foi confirmado no Android e não é aceito pelo parser Web analisado. Não inclua `cancel_option` nesse modo.

```php
$client->sendMessage('5511999999999', [
    'type' => 'form',
    'platform' => 'android',
    'text' => 'Preencha seu cadastro.',
    'cta' => 'Abrir cadastro',
    'steps' => [[
        'id' => 'cadastro_42',
        'kind' => 'input',
        'label' => 'Cadastro MVO',
        'button' => 'Enviar',
        'fields' => [[
            'id' => 'pedido_42_nome',
            'label' => 'Nome',
            'type' => 'text',
            'required' => true,
        ]],
    ]],
]);
```

No modo Android, uma etapa aceita de um a cinco campos agrupados, exclusivamente `type => 'text'`. `required` é booleano; `default`, quando fornecido, permite até 128 unidades UTF-16 (um caractere fora do plano básico, como muitos emojis, ocupa duas). O total continua limitado a três etapas, podendo misturar campos e opções. IDs de campos e etapas não podem colidir.

## Receber os dados na aplicação

```php
$pending = [];
$client->on('message', static function (array $event) use (&$pending): void {
    $form = $event['parsedFormResponse'] ?? null;
    if ($form === null) return;
    $pending[] = $form['params']; // stdClass; preserve os IDs originais.
});

// Dentro do loop externo, após poll():
while ($params = array_shift($pending)) {
    $value = $params->pedido_42_cpf_cnpj ?? null;
    if ($value === 'pedido_42_cancelar') {
        // Cancele a consulta na sua aplicação, com idempotência.
        continue;
    }
    if (!is_string($value) || trim($value) === '') continue;
    // Valide o CPF/CNPJ antes de consultar seu serviço; não basta ser texto.
}
```

No exemplo portável, `params` contém a chave original `pedido_42_cpf_cnpj`, tanto para o texto quanto para o ID da opção de cancelamento. Os objetos abaixo ilustram esses dois resultados; `000.000.000-00` é um valor fictício inválido, não um documento para consulta:

```json
{"pedido_42_cpf_cnpj": "000.000.000-00"}
```

```json
{"pedido_42_cpf_cnpj": "pedido_42_cancelar"}
```

Não há evento sintético de cancelamento nem renomeação de chaves. Se o destinatário digitar exatamente o ID reservado ao cancelamento, o valor recebido será indistinguível da seleção dessa opção; escolha um ID próprio e trate-o como reservado. Nas etapas de opções comuns, seleção múltipla retorna uma lista de IDs.

O evento contém `parsedFormResponse` com `name => 'form_message'`, `version` e `params` como `stdClass`. O Web foi observado gerando versão 1; o Android gerou versão 3. A biblioteca conserva a versão e o JSON bruto e reconstitui os objetos após persistência e replay. Não é criado um `flow_token` artificial: use IDs exclusivos da aplicação e a referência citada quando o cliente a enviar. O Web analisado não cita obrigatoriamente a mensagem original.

Na biblioteca, envie `type: "form"` com `sendMessage()`. O evento `message` inclui `parsedFormResponse`; sua aplicação pode validar os dados, consultar serviços e enviar o próximo formulário. A publicação desses eventos em uma API HTTP ou webhook pertence à aplicação que usa a biblioteca.

## Limites

O formulário nativo contém etapas locais; não executa o `data_exchange` de Galaxy dentro do modal. Trocas com seu backend passam pelos eventos/webhooks e por um novo envio da aplicação. Os tipos Galaxy continuam separados em [FLOWS.md](FLOWS.md).

A análise estática do parser Web e a reprodução controlada de suas funções distribuídas de parser e resposta sustentam o formato escolhido. O ensaio local passou dez verificações, incluindo texto livre e seleção de cancelamento com a chave original do campo. Ele executou essas funções isoladamente, sem sessão autenticada, e não demonstra abertura e envio da nova adaptação em clientes Web/Android reais. Os testes PHP cobrem o builder e o transporte simulado; também não equivalem à validação nesses clientes. `server_ack` confirma aceitação pelo servidor, sem comprovar exibição ou preenchimento.

Mensagens já recebidas não são convertidas retroativamente pelo novo código. Para avaliar a adaptação, envie uma nova mensagem com `cancel_option`; reabrir uma mensagem antiga mantém a definição antiga.
