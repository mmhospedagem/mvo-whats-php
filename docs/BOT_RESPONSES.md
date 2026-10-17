# Respostas de bots

`bot()->sendPrompt()` devolve a confirmação do envio da pergunta. A resposta chega depois pelo evento `bot_chunk`, que pode conter `first`, `inner` e `last`, ou um único `full`.

```php
$client->on('bot_chunk', function (array $chunk): void {
    $promptId = $chunk['targetMessageId'];
    $text = $chunk['text'];
    $mode = $chunk['responseMode']; // replace ou append
    $finished = in_array($chunk['editType'], ['last', 'full'], true);
    // Grave por sessão + promptId + bot; deduplique pelo ID do chunk.
    // replace contém uma versão inteira da resposta, não um trecho para concatenar.
});
```

Respostas ricas e edições de respostas ricas são normalizadas com `text`, `type=rich-response`, `responseMode=replace` e `richResponse`. O formato unificado do WhatsApp contém JSON dentro de `richResponseMessage.unifiedResponse.data`; a LIB interpreta somente layouts e campos de texto conhecidos. O formato anterior usa `submessages`. Quando ambos existem, o texto não é duplicado.

`richResponse` contém `format`, `status`, `blocks` e `truncated`. Os blocos conhecidos incluem texto, código, tabela e LaTeX. Componentes futuros aparecem como `unsupported`, sem expor JSON bruto, chaves de mídia ou credenciais. Um indicador de progresso permanece no bloco `progress` e não se transforma em texto de resposta.

`last` e `full` indicam o fim do fluxo; sozinhos não comprovam que exista uma resposta legível. Preserve a última resposta válida se a finalização não trouxer conteúdo. Use `revisionTimestampMs`, quando presente, para não substituir uma edição recente por outra antiga. A revisão e o ID da primeira resposta também identificam o fluxo a que a edição pertence.

Os chunks passam pela autenticação do segredo da pergunta, correspondência do bot esperado e verificação de que a pergunta pertence à conta conectada. O histórico guarda o conteúdo autenticado junto de `botChunk.targetMessageId`, tipo de edição e modo de atualização, permitindo consulta após reinício. O ID do evento continua sendo o ID do chunk recebido.

Para migrar eventos autenticados que já estavam armazenados antes desta correção, `MVO\Message\BotResponseContent::decode($proto)` devolve a projeção segura de uma resposta rica direta ou de uma edição `protocolMessage.editedMessage`. Esse método interpreta conteúdo; não autentica um pacote recebido nem substitui a validação do remetente e da sessão.
