# Botões no celular e no navegador

**Correção confirmada no navegador em 14/10/2026.** Após ajustar os metadados, preservar o contexto dos aparelhos da conta e remover a versão forçada do payload, o usuário confirmou que os botões apareceram no Web. O funcionamento no celular já havia sido confirmado. O ACK do servidor continua significando aceitação do envio; a exibição foi validada pelo usuário.

Foram testados, com autorização para o mesmo destinatário:

| Formato | Resultado informado no navegador |
|---|---|
| `buttonsMessage` legado | Aviso para acessar pelo celular |
| `interactiveMessage.nativeFlowMessage` com `quick_reply` | Mesmo aviso |
| Native flow dentro de `documentWithCaptionMessage` | Mesmo aviso |
| Envelope anterior com a marcação `bot/biz_bot` | Mesmo aviso |
| Metadados de compatibilidade com o payload anterior forçando `messageVersion=3` | Mesmo aviso |
| Metadados de compatibilidade, contexto preservado e payload sem versão forçada | Botões exibidos; confirmado pelo usuário |

A validação real cobre as respostas rápidas “Confirmar / Ajuda” na conta e no navegador testados. Pagamentos, carrosséis, fluxos especiais e respostas estruturadas têm testes locais das estruturas portadas; não foram todos enviados a contas reais. A confirmação obtida não é uma garantia universal para qualquer versão de cliente ou modalidade comercial.

## Metadados e envelopes

O envio combina os metadados do stanza com o contexto destinado aos outros aparelhos da conta:

- Mensagens `interactiveMessage` recebem `device_fanout=false` no stanza.
- Botões comuns, legados e carrosséis recebem `biz` com `actual_actors=2`, `host_storage=2` e `privacy_mode_ts` em segundos, além do native flow `mixed` versão 9.
- Pagamentos recebem `biz.native_flow_name`; a ação `send_location` usa um único nó `biz`, com `interactive` versão 1 e `native_flow` de nome `location_request_message`, sem versão forçada e sem `quality_control`. O Android aceita esse alias da mensagem para o botão `send_location`; o teste real recebeu recibos de entrega após essa troca. Os demais fluxos especiais mantêm sua estrutura própria.
- Listas mantêm `type=media` e `enc.mediatype=list` também em conversas individuais; respostas de botões/listas recebem os companions correspondentes.
- O contexto `MessageContextInfo` é preservado dentro e fora do envelope `deviceSentMessage` destinado aos outros aparelhos da própria conta. Mensagens interativas recebem um segredo de 32 bytes, persistido com a outbox.
- O helper de respostas rápidas usa um payload mínimo, sem forçar `messageVersion=3` nem `messageParamsJson` vazio. A API `raw` permite campos adicionais quando necessários.

Os metadados, timestamp, ID e ciphertext são gravados juntos antes do envio. `retryMessage($id)` repete o nó original, sem recalcular os metadados nem avançar o ratchet novamente.

## API de botões

`type=buttons` converte o corpo de botões para `interactiveMessage/nativeFlowMessage`, mantendo os IDs, rótulos, texto, rodapé, contexto e cabeçalho preparado. Os botões de resposta usam `quick_reply` e JSON com `display_text`/`id`. O envio usa os metadados descritos acima.

```php
$content = [
    'type'=>'buttons',
    'data'=>[
        'contentText'=>'Escolha uma opção:',
        'footerText'=>'Atendimento',
        'buttons'=>[
            ['buttonId'=>'confirmar', 'buttonText'=>['displayText'=>'Confirmar']],
            ['buttonId'=>'ajuda', 'buttonText'=>['displayText'=>'Ajuda']],
        ],
    ],
];
$client->sendMessage($to, $content);
```

Se precisar enviar exatamente `buttonsMessage`, use `type=legacy-buttons` com o mesmo corpo ou `type=raw` com `message.buttonsMessage`. Os campos Protobuf legados permanecem disponíveis.

## Menu em texto

Para uma conversa que precise ser legível sem renderização de botões, selecione explicitamente `buttonMode=text`:

```php
$client->sendMessage($to, [...$content, 'buttonMode'=>'text']);
```

Isso envia uma mensagem de texto comum:

```text
Escolha uma opção:

1. Confirmar
2. Ajuda

Responda com o número da opção.

Atendimento
```

As respostas serão texto (`1` ou `2`), não cliques nem IDs de botão; a aplicação deve associar o número à opção. A biblioteca não envia uma segunda mensagem automaticamente nem tenta identificar a plataforma de cada aparelho. Cabeçalhos de mídia e ações como abrir URL são rejeitados nesse modo para evitar perder sua função silenciosamente. Citações e menções continuam preservadas.

Teste pelo terminal, substituindo o destino:

```powershell
composer message:send -- --session=default --to=5511999999999 --payload-file=examples/payloads/test-buttons-text.json
```

O exemplo que foi validado no navegador está em `examples/payloads/test-buttons-web.json`. Reinicie um worker já aberto para carregar o código novo; a sessão continua válida.

## Validação e avisos

A confirmação de exibição cobre a combinação final descrita acima. Os envelopes anteriores da tabela não resolveram o caso observado e não são apresentados como alternativas validadas para Web. As atribuições e licenças dos componentes estão em [THIRD_PARTY_NOTICES.md](../THIRD_PARTY_NOTICES.md). O runtime permanece exclusivamente PHP.
