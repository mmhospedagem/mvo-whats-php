# Bots e Meta AI

O serviço de bots oferece descoberta, perfil, prompts diretos, menções em grupos e respostas criptografadas em partes. A disponibilidade depende da conta, da região e do servidor do WhatsApp.

```php
$bots = $client->bot()->listBots();
$bot = $bots[0]['fbidJid'];

$reply = $client->bot()->sendPrompt($bot, 'Explique esta ideia em três frases.');
$client->bot()->sendPrompt($bot, 'Agora dê um exemplo.', [
    'aiThreadId' => $reply['aiThreadId'],
]);

$client->bot()->sendPrompt('123456-789@g.us', '@Meta AI ajude o grupo.', [
    'botJid' => $bot,
]);

$client->on('bot_chunk', function (array $chunk): void {
    echo $chunk['editType'].': '.($chunk['text'] ?? '').PHP_EOL;
});
```

Use o `fbidJid` retornado na descoberta para envio direto. O JID legado conhecido `13135550002@s.whatsapp.net` é convertido ao FBID quando usado como `botJid` de menção. O destino `@bot` prevalece sobre a opção `botJid` no envio direto.

Prompts diretos carregam capacidades, segredo da mensagem e thread de IA. O resultado retorna `aiThreadId`, que pode ser reutilizado para continuar a conversa. As opções incluem `personaId`, `capabilities`, `aiThreadId` e `aiThreadType`. O transporte cria uma sessão Signal com o bot, coloca o bot em `participants` e entrega cópias `DeviceSentMessage` aos outros aparelhos da conta.

Menções em grupos carregam `botInvokeMessage`, os JIDs mencionados e a configuração Bloks. O envelope omitirá metadados exclusivos de conversa direta, como persona e thread. Texto e imagem/vídeo/documento com legenda são aceitos. O grupo recebe a mensagem por sender key; o bot recebe uma cópia Signal separada em `<bot>`, contendo um segredo derivado por HKDF `Bot Message`. A gravação dessa ratchet participa da mesma transação do envio e do outbox.

As partes de resposta `msmsg` usam o segredo persistido do prompt, o ID da parte/edição, a identidade do autor e a identidade PN/LID da conta na derivação AES-GCM. A biblioteca verifica a autenticação e a associação com o bot solicitado antes de persistir uma parte e emitir `bot_chunk`. As partes `inner` e `last` usam `edit_target_id` como salt; `first` e `full` usam o ID da própria mensagem. O evento entrega `editType`, `targetMessageId`, `editTargetId`, `saltId`, `text` e o WAProto completo em `proto`.

Respostas estruturadas são extraídas de `richResponseMessage`, inclusive quando chegam em `protocolMessage.editedMessage`. O evento inclui `responseMode=replace` para esses snapshots: cada atualização substitui o texto anterior, sem concatenar a mesma resposta várias vezes. `richResponse` contém a projeção dos blocos conhecidos, seu estado de interpretação e a indicação de truncamento; dados brutos, credenciais de download e segredos não fazem parte dessa projeção. Blocos de progresso e de raciocínio não são tratados como uma resposta textual final.

```php
$conteudo = \MVO\Message\BotResponseContent::decode($chunk['proto']);
if ($conteudo !== null && is_string($conteudo['text'])) {
    $respostas[$chunk['targetMessageId']] = $conteudo['text'];
}
```

`last` ou `full` indica o término do stream. Isso, isoladamente, não garante que exista conteúdo utilizável. Não apresente uma resposta como disponível apenas porque o envio recebeu ACK ou porque chegou um marcador terminal. Na API, consulte `bot.response` usando o `message_id` do prompt; o contrato diferencia envio, progresso, conteúdo disponível e conclusão. Respostas antigas podem ser reconstruídas dos eventos autenticados retidos nesta instância, sem reenviar o prompt. Se esses eventos já não estiverem disponíveis, a API informa a indisponibilidade em vez de fabricar uma resposta vazia concluída.

Eventos são duráveis e podem reaparecer com `replayed=true` se um callback falhar ou o processo parar depois da gravação. Para deduplicação, use chat, ID da parte e participante. Um payload com tag inválida, destinatário diferente ou segredo indisponível não é importado como mensagem autenticada; `message_error` informa `bot_chunk_authentication_failed`.

A implementação foi comparada ao protocolo do projeto de referência e validada localmente com pares Signal, sender keys e vetores independentes HKDF/AES-GCM. O acesso ao serviço real de bots requer a capacidade correspondente na conta; os testes locais não demonstram que todo bot estará habilitado.
