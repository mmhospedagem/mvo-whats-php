# Formulários nativos sem registro na Meta

Use `type => 'form'` para enviar a definição junto com a mensagem, sem `flow_id`, conta de desenvolvedor ou publicação na Meta. O envio usa a conexão PHP, Signal, fila persistente e eventos da sessão.

Um teste entre duas sessões confirmou opções no WhatsApp Web e Android e campo de texto no Android 2.26.39.79. A compatibilidade depende do tipo de campo e da versão do cliente; as limitações do formato Galaxy estão descritas em [FLOWS.md](FLOWS.md).

## Opções e etapas no Web e Android

O modo padrão `platform => 'portable'` aceita de uma a três etapas, seleção única ou múltipla, até cinco opções por etapa e resposta personalizada opcional. O próprio WhatsApp apresenta a navegação entre etapas.

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

## Campos de texto no Android

O componente nativo `InputFields` foi confirmado no Android. O parser Web analisado aceita somente etapas de opções; por isso, campos de texto exigem `platform => 'android'`.

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

Uma etapa aceita de um a cinco campos, exclusivamente `type => 'text'`. `required` é booleano; `default`, quando fornecido, permite até 128 unidades UTF-16 (um caractere fora do plano básico, como muitos emojis, ocupa duas). O total continua limitado a três etapas, podendo misturar campos e opções no modo Android. IDs de campos e etapas não podem colidir.

## Receber os dados na aplicação

```php
$client->on('message', function (array $event): void {
    $form = $event['parsedFormResponse'] ?? null;
    if ($form === null) return;
    echo json_encode($form['params'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
});
```

Exemplo de resposta de campo de texto:

```json
{
  "name": "form_message",
  "version": 3,
  "params": {"pedido_42_nome": "Maria"}
}
```

O Web foi observado gerando versão 1; o Android gerou versão 3. A biblioteca conserva a versão e o JSON bruto e reconstitui os objetos após persistência e replay. Seleção múltipla retorna uma lista de IDs. Não é criado um `flow_token` artificial: use IDs exclusivos da aplicação e a referência citada quando o cliente a enviar. O Web analisado não cita obrigatoriamente a mensagem original.

Na biblioteca, envie `type: "form"` com `sendMessage()`. O evento `message` inclui `parsedFormResponse`; sua aplicação pode validar os dados, consultar serviços e enviar o próximo formulário. A publicação desses eventos em uma API HTTP ou webhook pertence à aplicação que usa a biblioteca.

## Limites

O formulário nativo contém etapas locais; não executa o `data_exchange` de Galaxy dentro do modal. Trocas com seu backend passam pelos eventos/webhooks e por um novo envio da aplicação. Os tipos Galaxy continuam separados em [FLOWS.md](FLOWS.md). Mensagens já recebidas não são convertidas retroativamente pelo novo código.
