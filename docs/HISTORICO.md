# Histórico, confirmações e recuperação de mensagens

A biblioteca grava conversas, contatos e mensagens na mesma persistência da sessão. Uma mensagem recebida só entra no histórico depois da autenticação Signal; a gravação participa da transação da ratchet e da caixa de entrada. O envio grava o conteúdo, os destinatários elegíveis e o envelope criptografado antes de usar a rede.

```php
$client->on('history_sync_chunk', function (array $chunk): void {
    echo "Importadas {$chunk['messagesCount']} mensagens\n";
});

$chats = $client->history()->listChats(limit: 100);
$contacts = $client->history()->listContacts(limit: 100);
$messages = $client->history()->listMessages('558400000000@s.whatsapp.net', limit: 50);
$message = $client->history()->getMessage('558400000000@s.whatsapp.net', 'ID');
```

`listChats` e `listContacts` ordenam por JID e recebem `after`, usando o último JID da página anterior. `listMessages` retorna as mensagens mais recentes e aceita `beforeTimestamp` exclusivo, em segundos. Mensagens de grupo com o mesmo ID e participantes diferentes permanecem separadas; `getMessage` recebe o participante como terceiro argumento quando necessário. As consultas são locais e não buscam automaticamente conteúdo ausente no telefone.

Para percorrer todo o inventário local em uma API ou exportação, use os iteradores. Eles leem páginas limitadas, sem truncar contatos ou mensagens com o mesmo timestamp:

```php
foreach ($client->history()->iterateContacts() as $contact) {
    // Processar um contato por vez.
}
foreach ($client->history()->iterateMessages('558400000000@s.whatsapp.net') as $message) {
    // Processar cada mensagem armazenada nesta conversa.
}
```

`iterateChats()` e `iterateContacts()` seguem a ordem do JID. `iterateMessages()` sem argumento percorre todas as conversas, em ordem de JID e chave de armazenamento; a ordem dentro da conversa não é cronológica. Para uma tela com as mensagens mais recentes, continue usando `listMessages()`.

O telefone pode enviar notificações `HISTORY_SYNC_NOTIFICATION`. A biblioteca aceita esse protocolo somente de um dispositivo autenticado da própria conta, coloca o trabalho em uma fila persistente e baixa o arquivo fora do despachante de nós. A transferência verifica MAC e hashes; depois descompacta gzip/zlib, lê os registros WAProto e aplica cada bloco em uma transação. O recibo `hist_sync` só é enfileirado após a importação. Falhas mantêm a tarefa para nova tentativa com intervalo crescente. O evento `history_sync_error` informa a falha sem descartar as credenciais.

São importados mensagens, metadados de conversas, push names, contatos, vínculos PN/LID e segredos de mensagens necessários para respostas criptografadas a enquetes/eventos. Blocos já processados não são baixados novamente. A expansão tem limite padrão de 64 MiB e cada registro protobuf, 16 MiB; o download usa o limite do `MediaTransfer`. A expansão é limitada e bufferizada em PHP. A leitura do histórico local mantém apenas os melhores resultados da página em memória, embora precise percorrer o índice do chat.

Para solicitar mensagens anteriores, informe a mensagem mais antiga que já está na tela:

```php
$client->requestHistorySync([
    'chatJid' => '558400000000@s.whatsapp.net',
    'oldestMsgId' => $oldest['id'],
    'oldestMsgFromMe' => $oldest['fromMe'],
    'oldestMsgTimestampMs' => $oldest['timestamp'] * 1000,
    'count' => 50,
]);
```

Esse método envia um protocolo criptografado ao telefone principal, com prioridade `high_force`; retorna depois do ACK desse pedido. O histórico chega depois pelo fluxo de sincronização, sujeito à disponibilidade do telefone e às permissões da conta.

```php
$client->on('message', function (array $message) use ($client): void {
    $client->sendReceipt([$message], 'read');
});
// Depois de reproduzir um áudio:
$client->sendReceipt([$message], 'played');
```

Os recibos são agrupados por chat/participante, gravados antes do transporte e reenviados após reconexão até o ACK correspondente. Os tipos disponíveis são `delivery`, `read`, `played`, `read-self`, `played-self`, `hist_sync` e `peer_msg`. A biblioteca também persiste recibos recebidos e expõe `server_ack`, `delivered`, `read` e `played` em `getMessageStatus`; `receipts` mantém o estado por dispositivo. Em grupos, o estado agregado representa o maior estágio observado, não a leitura por todos os participantes.

Uma recusa explícita de envio fica como `rejected`, com `serverCode` numérico do WhatsApp, e é propagada como `ServiceException`. Timeout sem ACK fica como `unknown`: consulte os eventos e recibos antes de reenviar. Uma recusa tardia não rebaixa uma confirmação de entrega ou leitura já observada. Nenhum desses erros dispara um novo envio automático.

Quando um destinatário pede um retry, a biblioteca valida se aquele dispositivo recebeu o envio original, verifica o limite de tentativas e refaz a criptografia somente para ele. Um pacote de chaves fornecido no pedido precisa ter assinatura válida e manter a identidade já confiada. Mudanças de identidade não são aceitas silenciosamente. O trabalho fica persistido e é executado fora do despachante; a nova ratchet e o envelope de retry são gravados na mesma transação. Se o transporte falhar, a retomada reutiliza exatamente esse envelope. Pedidos repetidos, dispositivos não autorizados e mensagens com mais de sete dias são ignorados. O evento `message_retry` informa o ACK do reenvio.

`retryMessage($id)` continua sendo a recuperação manual de envio sem confirmação: reutiliza o envelope original. As mensagens antigas enviadas antes de existir o registro de conteúdo/destinatários continuam podendo usar a recuperação manual, mas não atendem ao retry automático de destinatário.

Status e listas de transmissão usam o transporte real de sender keys, distribuição Signal por dispositivo, rotação quando a audiência muda e ACK persistido. `sendBroadcast` recebe a audiência explicitamente; um status precisa do LID da conta e aceita `statusSetting` com `contacts`, `allowlist`, `denylist` ou `close_friends`. Recursos de conta e regras do servidor continuam determinando a entrega.

Para compartilhar o histórico recente com um novo participante do grupo:

```php
$client->shareGroupHistory('123456-789@g.us', [
    'toJids' => ['558400000000@s.whatsapp.net'],
    'count' => 100,
]);
```

O método consulta os membros atuais e normaliza os destinatários conforme o modo PN/LID do grupo. Aceita `messages` com uma lista explícita de `WebMessageInfo`, `sinceMs` e `outOfWindowPinnedMessages`; na ausência de `messages`, usa o histórico local. O arquivo `GroupHistory` é comprimido, criptografado e enviado ao CDN. Seu envelope vai somente aos destinatários escolhidos e aos aparelhos da própria conta, usando criptografia Signal individual, seguido de um aviso por sender key ao grupo. Um membro removido ou um JID externo não entra no envio. O servidor pode recusar esse recurso quando a conta não tem a capacidade habilitada.

No recebimento, a biblioteca só baixa o pacote se a própria conta estiver em `historyReceivers`. Aplica os limites padrão do protocolo: pacote de até 14 dias, mensagens comuns de até 28 dias e descarte de mensagens temporárias vencidas. Pins fora da janela podem ser importados; registros de outro chat são descartados. O evento `group_history_bundle` informa contagens, destinatários e ID do pacote. Esse fluxo possui a mesma verificação de mídia e transação de importação do histórico do telefone.

As mutações `deleteChat`, `deleteMessage`, `upsertChat` e `updateMessage` do repositório alteram somente os dados locais. Para sincronizar alterações com outros aparelhos, use o serviço de app-state/chat.
