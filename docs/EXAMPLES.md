# Exemplos — MVO WHATS PHP

[Início](../README.md) · [Instalação](INSTALLATION.md) · [Primeira conexão](GETTING_STARTED.md)

Os trechos de envio e consulta pressupõem um `$client` autenticado, com `isConnected() === true`. Execute-os no loop externo do worker, fora de callbacks. Telefones, JIDs e URLs deste guia são fictícios: substitua por destinatários, recursos e arquivos reais.

## Texto e destinatários

```php
$to = '5511999999999';

$sent = $client->sendText($to, 'Olá pela MVO WHATS PHP!');
echo $sent['id'].': '.$sent['status'].PHP_EOL;

$sent = $client->sendMessage($to, [
    'type' => 'text',
    'text' => 'Mensagem estruturada',
]);
```

O destino pode ser um número com DDI/DDD, JID PN, JID LID ou um JID de grupo. Não crie um LID a partir do telefone; use as identidades reais retornadas pelo WhatsApp. Consulte [Identidades](IDENTIDADES.md).

```php
$client->sendText('5511999999999@s.whatsapp.net', 'Mensagem pelo JID PN');
$client->sendText('120363000000000001@g.us', 'Olá, grupo!');
```

Quando a conta e o serviço suportarem usernames, o SDK resolve o destinatário na conexão atual:

```php
$client->sendText('@contato.exemplo', 'Olá pelo username!');
$jid = $client->resolveRecipient('@contato.exemplo');
```

Se for exigida uma chave de contato, use `contato.exemplo:1234` com a chave verdadeira. A consulta pode retornar ausência, necessidade de chave ou recusa; não há conversão inventada de username para telefone.

## Imagens, vídeos, documentos e voz

```php
$client->sendImage($to, __DIR__.'/media/foto.jpg', [
    'caption' => 'Confira esta imagem',
]);

$client->sendVideo($to, __DIR__.'/media/video.mp4', [
    'caption' => 'Vídeo de apresentação',
]);

$client->sendDocument($to, __DIR__.'/media/catalogo.pdf', [
    'fileName' => 'Catalogo.pdf',
    'caption' => 'Catálogo em anexo',
]);

$client->sendAudio($to, __DIR__.'/media/audio.mp3');
$client->sendVoice($to, __DIR__.'/media/voz.ogg', [
    'mimetype' => 'audio/ogg; codecs=opus',
]);
$client->sendSticker($to, __DIR__.'/media/figurinha.webp');
```

Os arquivos precisam existir e estar acessíveis ao worker. Prepare voz em Ogg/Opus, figurinhas em WebP e GIFs no formato de vídeo MP4; `sendGif()` recebe MP4. O Composer não instala FFmpeg nem converte automaticamente qualquer arquivo para o formato aceito. Veja [Mídia](MIDIA.md).

O formato comum de `sendMessage()` também aceita mídia:

```php
$client->sendMessage($to, [
    'type' => 'image',
    'media' => ['path' => __DIR__.'/media/foto.jpg'],
    'caption' => 'Imagem com visualização única',
    'viewOnce' => true,
]);
```

## Localização e enquete

```php
$client->sendMessage($to, [
    'type' => 'location',
    'latitude' => -23.5505,
    'longitude' => -46.6333,
    'name' => 'Local de atendimento',
    'address' => 'São Paulo, SP',
]);

$client->sendMessage($to, [
    'type' => 'poll',
    'name' => 'Qual horário prefere?',
    'options' => ['Manhã', 'Tarde'],
    'selectableCount' => 1,
]);
```

## Botões e listas

```php
$client->sendMessage($to, [
    'type' => 'buttons',
    'data' => [
        'contentText' => 'Como podemos ajudar?',
        'footerText' => 'Atendimento MVO',
        'headerType' => 'EMPTY',
        'buttons' => [
            ['buttonId' => 'suporte', 'buttonText' => ['displayText' => 'Suporte'], 'type' => 'RESPONSE'],
            ['buttonId' => 'vendas', 'buttonText' => ['displayText' => 'Vendas'], 'type' => 'RESPONSE'],
        ],
    ],
]);

$client->sendMessage($to, [
    'type' => 'list',
    'data' => [
        'title' => 'Atendimento',
        'description' => 'Selecione uma opção',
        'buttonText' => 'Abrir menu',
        'listType' => 'SINGLE_SELECT',
        'sections' => [[
            'title' => 'Opções',
            'rows' => [
                ['rowId' => 'suporte', 'title' => 'Suporte'],
                ['rowId' => 'vendas', 'title' => 'Vendas'],
            ],
        ]],
    ],
]);
```

As permissões, a aceitação e a renderização de interativos dependem do WhatsApp e do receptor. `type=buttons` usa o formato interativo implementado pelo SDK; o formato antigo continua separado como `legacy-buttons`. Consulte [Tipos de mensagens](TIPOS-MENSAGENS.md) e [Compatibilidade de botões](COMPATIBILIDADE-BOTOES.md).

Para formulários, leia [Formulários nativos](FORMULARIOS.md). Os tipos `flow`, `flow-navigate` e `flow-exchange` têm requisitos próprios e limitações diferentes: veja [Flows](FLOWS.md) antes de selecionar o formato.

## Reagir, editar e remover uma mensagem

```php
$sent = $client->sendText($to, 'Texto inicial');
$target = ['id' => $sent['id'], 'fromMe' => true];

$client->sendMessage($to, [
    'type' => 'reaction', 'target' => $target, 'emoji' => '👍',
]);
$client->sendMessage($to, [
    'type' => 'edit', 'target' => $target, 'text' => 'Texto corrigido',
]);
$client->sendMessage($to, [
    'type' => 'revoke', 'target' => $target,
]);
```

Execute somente a ação desejada. O WhatsApp aplica prazos, propriedade e permissões. Em mensagens de outros participantes de grupos, preserve a chave completa, incluindo `participant` quando informado.

## Receber e responder sem consultas no callback

Acrescente a fila local abaixo ao worker do [guia inicial](GETTING_STARTED.md). O callback apenas seleciona a mensagem; o envio acontece depois de `poll()` retornar:

```php
$pending = [];
$client->on('message', static function (array $event) use (&$pending): void {
    $chat = $event['chat'] ?? null;
    if (($event['fromMe'] ?? false) === true || !is_string($chat)
        || !preg_match('/@(s\.whatsapp\.net|lid)$/D', $chat)
        || trim((string) ($event['text'] ?? '')) !== '/ajuda') {
        return;
    }
    if (count($pending) < 100) {
        $pending[] = ['to' => $chat, 'text' => 'Olá! Informe como podemos ajudar.'];
    }
});

// No loop do worker, depois de $client->poll(0.25):
if ($client->isConnected() && $pending !== []) {
    $command = array_shift($pending);
    $result = $client->sendText($command['to'], $command['text']);
}
```

A fila em memória serve para demonstrar a ordem de execução. Em uma integração que precisa sobreviver a quedas, grave eventos e comandos em uma fila durável, aplique deduplicação por conversa/ID e registre o resultado de cada tentativa. Não marque entrega antes de observar os recibos, nem reenvie automaticamente uma operação com resultado incerto.

## Eventos para uma API

O SDK entrega **arrays PHP** aos listeners. O envelope JSON é definido pela sua integração. Este exemplo seleciona campos públicos, acrescenta o ID da sessão e gera uma linha JSON por mensagem:

```php
$sessionId = 'cliente-demo';
$client->on('message', static function (array $event) use ($sessionId): void {
    $payload = [
        'session_id' => $sessionId,
        'event' => 'message',
        'data' => [
            'id' => $event['id'] ?? null,
            'chat' => $event['chat'] ?? null,
            'from' => $event['from'] ?? null,
            'fromMe' => $event['fromMe'] ?? false,
            'type' => $event['type'] ?? null,
            'text' => $event['text'] ?? null,
            'timestamp' => $event['timestamp'] ?? null,
        ],
    ];
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL;
});
```

Saída fictícia desse adaptador:

```json
{
  "session_id": "cliente-demo",
  "event": "message",
  "data": {
    "id": "MESSAGE_ID_DE_EXEMPLO",
    "chat": "5511999999999@s.whatsapp.net",
    "from": "5511999999999@s.whatsapp.net",
    "fromMe": false,
    "type": "text",
    "text": "Olá, preciso de atendimento.",
    "timestamp": 1700000000
  }
}
```

O evento original contém outros campos, como `key`, `data`, contexto e descritores de mídia, dependendo do conteúdo. Alguns carregam bytes binários. Não serialize integralmente eventos de autenticação ou protocolos para o cliente. Caso precise preservar binários internamente em JSON, use `MVO\Protocol\BinaryData::encode()` e `decode()`, conforme [Tipos de mensagens](TIPOS-MENSAGENS.md).

Para webhooks, enfileire o envelope no callback e entregue-o por outro componente com autenticação, assinatura e tentativas controladas. Esse exemplo não cria um servidor HTTP nem um contrato de webhook obrigatório da biblioteca.

## Baixar mídia recebida

Preserve o descritor do evento em uma fila privada. Depois de sair do callback, com o cliente autenticado:

```php
// $received é o evento original de uma mensagem de mídia.
$destination = __DIR__.'/storage/downloads/arquivo-recebido.jpg';
$path = $client->downloadMedia($received, $destination);
```

Crie a pasta de destino e mantenha o evento original apenas no armazenamento autorizado. O SDK verifica os dados criptográficos da mídia; não trate uma legenda ou nome recebido como caminho local confiável. Para arquivos maiores e uso de streams, veja [Mídia](MIDIA.md) e [Recuperação de mídia](RECUPERACAO-MIDIA.md).

## Recibos, leitura e presença

```php
$status = $client->getMessageStatus($sent['id']);

// Faça isso somente se sua aplicação decidiu marcar esta mensagem como lida.
$client->sendReceipt([$received['key']], 'read');

$client->presence()->sendChatstate($to, 'composing');
$client->presence()->sendChatstate($to, 'paused');
```

Para anunciar disponibilidade após o login, configure o cliente com `markOnlineOnConnect: true`. O padrão mantém a presença sob controle da aplicação. Leia [Presença](PRESENCE.md).

## Consultar a própria conta

```php
$account = $client->accountInfo();

echo $account['phoneE164'] ?? 'Telefone ainda indisponível';
echo $account['displayName'] ?? 'Nome ainda indisponível';
```

`accountInfo()` é uma leitura local e não consulta o WhatsApp. Retorna `jid`, `deviceJid`, `lid`, `phoneNumber`, `phoneE164`, `deviceId`, `displayName`, `username`, `platform`, `webVersion`, `registered` e `connected`. O telefone vem da identidade confirmada; LID e telefone de entrada para pareamento não são usados para inventar um número. Campos não recebidos ou sincronizados podem ser `null`.

Foto, recado e perfil comercial precisam de consultas explícitas fora dos callbacks:

```php
$jid = $client->accountJid();
if ($jid !== null) {
    $picture = $client->profile(5.0)->getProfilePicture($jid, 'image');
    $about = $client->profile(5.0)->getStatus($jid);
    $business = $client->business(5.0)->getBusinessProfile([$jid]);
}
```

Os cinco segundos limitam as consultas dessas facades. O servidor pode omitir dados ou recusar o acesso; ausência não comprova tipo de conta. Não classifique uma conta como pessoal apenas porque uma consulta Business falhou.

## Histórico, grupos e comunidades

```php
$chats = $client->history()->listChats();
$messages = $client->history()->listMessages($to, 50);

$groupJid = '120363000000000001@g.us';
$metadata = $client->group()->queryGroupMetadata($groupJid);
$communities = $client->community()->queryAllCommunities();
```

O histórico local contém apenas o que foi recebido/importado pela sessão. Operações de grupos e comunidades exigem as permissões da conta. Sincronizações de histórico e app-state são assíncronas. Veja [Histórico](HISTORICO.md), [App-state](APPSTATE.md), [Serviços](SERVICOS.md) e [Comunidades](COMUNIDADES.md).

## Business, Status e canais

```php
// Catálogo da conta Business, quando autorizado.
$catalog = $client->catalog()->getCatalog(limit: 25);

// Status para uma audiência explícita.
$status = $client->status()->sendText(
    'Novidades da MVO!',
    ['5511888888888@s.whatsapp.net'],
    ['backgroundColor' => '#009BF5', 'textColor' => '#FFFFFF'],
    'allowlist',
);

// Publicação em um canal que a conta está autorizada a administrar.
$channelJid = '120363000000000000@newsletter';
$publication = $client->channels()->send($channelJid, 'Novidades do canal!');
```

Essas operações publicam conteúdo real quando executadas com dados válidos. Use apenas a que pretende realizar. Consulte os guias de [Business](BUSINESS.md), [Status](STATUS.md) e [Canais](CANAIS.md) para paginação, administração, audiência, mídia, recusas e limites.

## Executar exemplos do repositório

Na cópia clonada do repositório:

```sh
# Não inicia conexão com o WhatsApp.
php examples/protocol.php
php examples/session-events.php --help

# Inicia uma sessão e mostra o QR no terminal.
php examples/connect.php --session=demo --mode=qr
```

Com o worker `examples/connect.php` aberto, outro terminal pode enviar pela fila local:

```sh
php examples/send.php --session=demo --to=5511999999999 --text="Olá pelo PHP"
php examples/send.php --session=demo --to=5511999999999 --payload-file=examples/payloads/poll.json
```

`examples/send.php` usa a conexão existente quando encontra o worker; caso a sessão esteja livre, abre uma conexão temporária e a encerra preservando o vínculo. Essa fila pertence ao exemplo `connect.php`: um worker próprio precisa implementar ou consumir sua própria fila de comandos.

Para testes sem uma conta real, siga [Ambiente simulado](FAKE-WHATSAPP.md). Para os demais formatos, consulte [Tipos de mensagens](TIPOS-MENSAGENS.md), [Extensões de mensagens](MESSAGE_EXTENSIONS.md) e [Agendamentos](AGENDAMENTOS.md).
