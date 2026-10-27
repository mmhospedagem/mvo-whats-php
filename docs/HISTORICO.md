# Histórico, confirmações e recuperação de mensagens

A biblioteca grava conversas, contatos e mensagens na mesma persistência da sessão. Uma mensagem recebida só entra no histórico depois da autenticação Signal; a gravação participa da transação da ratchet e da caixa de entrada. O envio grava o conteúdo, os destinatários elegíveis e o envelope criptografado antes de usar a rede.

## Histórico inicial no pareamento

Para pedir o histórico completo ao vincular um aparelho novo, configure a opção
antes de `connect()`:

```php
$client = new MVO\WaClient(
    sessionId: 'cliente-demo',
    authDirectory: __DIR__.'/var/whatsapp-auth',
    historyRequireFullSync: true,
);
```

O padrão é `false`. A opção é serializada em `DeviceProps.requireFullSync` no
registro do companion. Ela não altera uma sessão já vinculada, não solicita
novamente o pareamento e não garante que o telefone fornecerá todo o histórico.
Os arquivos recebidos continuam passando pela importação autenticada e pelos
limites descritos abaixo. Para buscar mensagens anteriores em uma sessão
existente, use `requestHistorySync()` com uma referência válida da conversa.

O exemplo de conexão aceita a mesma configuração por `--full-history`:

```sh
php examples/connect.php --mode=qr --session=cliente-demo --full-history
```

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

São importados mensagens, metadados de conversas, push names, contatos, vínculos PN/LID e segredos de mensagens necessários para respostas criptografadas a enquetes/eventos. Blocos já processados não são baixados novamente. A expansão tem limite padrão de 64 MiB e cada registro protobuf, 16 MiB; o download usa o limite do `MediaTransfer`. Sem um sink, a expansão é limitada e bufferizada em PHP. A leitura do histórico local mantém apenas os melhores resultados da página em memória, embora precise percorrer o índice do chat.

## Consumir registros com `history.sink`

Configure `historySink` no construtor antes de conectar. O contrato PHP é
`MVO\History\HistorySyncSink`; o adaptador `CallbackHistorySyncSink` aceita
`item`, `commit` e `abort`. Os dois últimos callbacks são opcionais no adaptador.

```php
use MVO\History\CallbackHistorySyncSink;
use MVO\WaClient;

$sink = new CallbackHistorySyncSink(
    item: function (array $item, array $chunk) use ($repository): void {
        $repository->stage($chunk['id'], $item);
    },
    commit: function (array $chunk) use ($repository): void {
        $repository->publishOnce($chunk['id'], $chunk);
    },
    abort: function (array $chunk, Throwable $error) use ($repository): void {
        $repository->discardStaged($chunk['id']);
    },
);

$client = new WaClient(
    sessionId: 'cliente-demo',
    authDirectory: __DIR__.'/var/whatsapp-auth',
    historySink: $sink,
    historyPersist: false,
);
```

`$repository` representa a persistência da sua aplicação. O exemplo executável
[history-sink.php](../examples/history-sink.php) implementa staging, confirmação
idempotente e retomada usando outro banco SQLite, sem consultas ao SDK dentro dos
callbacks.

O padrão `historyPersist: true` mantém as projeções locais e também chama o sink.
Com `false`, somente os blocos `HistorySync` deixam de alimentar as tabelas locais
de mensagens, conversas, contatos e observações. Essa opção exige um sink.
Vínculos PN/LID, segredos necessários às respostas criptografadas, tokens de
privacidade e configurações continuam na sessão. Ela não desliga a inbox/outbox,
o histórico de mensagens ao vivo, app-state nem pacotes `GroupHistory` enviados
por participantes de grupos. As consultas locais não passam a consultar o banco
externo automaticamente.

Cada item tem `kind` e `value`. Campos repetidos produzem um item por entrada;
campos escalares conhecidos também são entregues. `syncType` fica no descritor
do bloco e campos protobuf desconhecidos da raiz não são itens separados.

```php
// Uma mensagem: o HistorySyncMsg completo conserva stubs e msgOrderId.
[
    'kind' => 'conversations.messages',
    'conversationId' => '558400000000@s.whatsapp.net',
    'value' => [
        'message' => [
            'key' => ['id' => 'HISTORY_ID', 'fromMe' => false],
            'message' => ['conversation' => 'Olá'],
        ],
        'msgOrderId' => 10,
    ],
];
// Depois das mensagens dessa conversa: metadados sem a lista de mensagens.
['kind' => 'conversations', 'value' => ['id' => '558400000000@s.whatsapp.net']];
// Outros exemplos: pushnames, inlineContacts, statusV3Messages, globalSettings.
```

O segundo argumento contém `id`, `syncType`, `chunkOrder` e `progress` quando
disponíveis. `id` é o ID do portador autenticado, estável nas tentativas do mesmo
bloco. Importe somente dados confiáveis ao chamar diretamente
`HistoryService::ingest()`/`ingestCompressed()`; nesses métodos, `notification.id`
pode fornecer o identificador ou será usado SHA-256 do protobuf descompactado.
Separe os dados por conta. O mesmo conteúdo pode aparecer em blocos diferentes;
deduplique mensagens também por sua chave exata, incluindo chat, participante e
`fromMe`, preservando PN/LID.

O download usa streams e arquivos temporários privados, valida MAC/hashes da mídia
e verifica completamente a compressão antes de chamar `item`. A leitura protobuf
não acumula a lista de mensagens da conversa: uma conversa pode ultrapassar
16 MiB no total, respeitando os limites do bloco, de cada registro e do cabeçalho.
Há limite global de um milhão de campos estruturais. O consumo de RAM depende do
maior registro/cabeçalho e do próprio consumidor; disco temporário e o tamanho
do bloco continuam necessários. Esse caminho faz mais de uma passagem pelo
spool para validar metadados e aplicar as projeções.

Os callbacks são síncronos: o próximo item só é lido depois que `item` retorna.
Isso aplica controle de fluxo, mas um callback lento também pausa o loop da
sessão. Não envie mensagens, faça consultas ou reentre na importação a partir
desses callbacks. Encaminhe comandos para o loop externo e mantenha o tempo de
cada callback limitado.

Os itens são provisórios até `commit`. Falhas de parsing, persistência ou do
consumidor chamam `abort`, preservam a tarefa e impedem o recibo de confirmação.
Uma exceção em `abort` não substitui a causa original. A aplicação deve descartar
o staging incompleto. `commit` recebe também as contagens do bloco, roda depois
da transação das projeções locais e precisa retornar com sucesso antes do
checkpoint e do recibo `hist_sync`. Falha no recibo após esse checkpoint não
reentrega os itens ao sink.

O banco do SDK e o banco externo não compartilham uma transação: pode haver
repetição após falha ou queda entre o commit externo e o checkpoint local.
`item` e `commit` precisam tolerar replay. Uma falha de commit externo pode
deixar projeções locais já gravadas; elas serão reaplicadas de forma idempotente.
Ao parar/reconectar, o importador antigo não pode confirmar o bloco ou enviar
recibos pela conexão substituta. Se `commit` retornou antes do encerramento,
o SDK não chama `abort` para desfazer dados externos já publicados.

O sink é configuração privada do processo. Registros podem incluir texto,
identificadores e material criptográfico: proteja o armazenamento e não os
publique em logs, webhooks ou ferramentas MCP. Não houve expansão automática
da allowlist MCP. Os testes locais cobrem integridade, limites, falhas, replay e
cancelamento; não demonstram recebimento de histórico de um aparelho real.

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
