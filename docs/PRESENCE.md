# Presença e indicadores de conversa

Use `$client->presence()` na sessão conectada para anunciar disponibilidade, enviar indicadores de digitação/gravação e assinar eventos de contatos.

## Disponibilidade da própria conta

```php
$client->presence()->send('available');
$client->presence()->send('unavailable');
```

Esses métodos enviam a indicação pela conexão atual. O retorno `void` não representa confirmação de entrega nem altera as configurações de privacidade do WhatsApp.

Para anunciar `available` automaticamente depois de cada login autenticado, incluindo reconexões:

```php
use MVO\WaClient;

$client = new WaClient('minha-sessao', markOnlineOnConnect: true);
```

A biblioteca envia a presença após a ativação da conexão ser aceita pelo servidor e antes de emitir `connection` com `state=connected`. Não envia presença durante QR Code, código de pareamento ou autenticação pendente.

O padrão é `false`: a biblioteca mantém o controle manual e não envia `available` nem um `unavailable` inferido durante o login. Outros aparelhos e as regras de privacidade da conta continuam participando da disponibilidade observada pelos contatos.

Você pode mudar a política do objeto existente:

```php
$client->setMarkOnlineOnConnect(true);
$enabled = $client->isMarkOnlineOnConnect();
```

O setter apenas altera a política aplicada aos próximos logins. Não envia presença imediatamente, não reinicia a sessão e não grava essa opção nas credenciais. Para mudar a disponibilidade atual, use `presence()->send(...)`. Ao recriar o cliente, forneça novamente a política desejada pela configuração da sua aplicação.

## Digitando, gravando e pausado

```php
$jid = '5511999999999@s.whatsapp.net';

$client->presence()->sendChatstate($jid, 'composing');
$client->presence()->sendChatstate($jid, 'recording');
$client->presence()->sendChatstate($jid, 'paused');
```

`recording` é um alias público para a forma nativa de gravação de áudio. O formato anterior continua válido:

```php
$client->presence()->sendChatstate($jid, 'composing', 'audio');
```

A assinatura completa é `sendChatstate(string $jid, string $state='composing', ?string $media=null): void`. O alias aceita `media=null` ou `audio`; `recording` com outra mídia, `paused` com mídia e estados desconhecidos são rejeitados antes do envio.

Envie `paused` quando terminar o indicador. A biblioteca não agenda uma pausa automática. Use JIDs reais, como `@s.whatsapp.net` e `@lid`; presença não está disponível para broadcasts nem canais `@newsletter`.

## Assinaturas e eventos recebidos

```php
$client->presence()->subscribe($jid);

$client->on('presence', function (array $event): void {
    $jid = $event['chatJid'];
    $state = $event['type']; // available ou unavailable
    $lastSeen = $event['lastSeen'] ?? null;
});

$client->on('chatstate', function (array $event): void {
    $jid = $event['chatJid'];
    $participant = $event['participantJid'] ?? null;
    $state = $event['state']; // composing ou paused, conforme o protocolo
    $recording = $state === 'composing' && ($event['media'] ?? null) === 'audio';
});
```

A assinatura completa é `subscribe(string $jid, ?string $name=null, ?string $context=null, ?BinaryNode $privacyToken=null): void`. A biblioteca anexa o token de contato conhecido quando necessário e disponível; aplicações comuns usam apenas o JID.

`lastSeen` pode distinguir um horário (`kind=timestamp`, `unixSeconds`), uma restrição de privacidade (`privacy_denied`), ausência de acesso anterior (`never_online`) e informação desconhecida (`unknown`). Sua ausência não permite deduzir a última visualização. Grupos também podem produzir `groupOnlineCount` quando informado pelo servidor.

As assinaturas valem para a conexão atual. Elas não são persistidas nem repetidas automaticamente. Registre o interesse novamente quando chegar `connection=connected`:

```php
use MVO\WaClient;

$client = new WaClient('minha-sessao', markOnlineOnConnect: true);
$contacts = ['5511999999999@s.whatsapp.net'];

$client->on('connection', function (array $event) use ($client, $contacts): void {
    if ($event['state'] !== 'connected') return;
    foreach ($contacts as $jid) $client->presence()->subscribe($jid);
});

$client->on('presence', function (array $event): void {
    echo $event['chatJid'].': '.$event['type'].PHP_EOL;
});

try {
    $client->connect();
    $client->run();
} finally {
    $client->disconnect();
}
```

Mantenha `run()` ou `poll()` ativo para receber eventos. A presença recebida depende das permissões e das informações divulgadas pelo WhatsApp; a assinatura não contorna a privacidade do contato.

Publicações temporárias de texto, imagem, vídeo e voz pertencem ao serviço separado `$client->status()`. Veja [Status](STATUS.md) para audiência, privacidade, silenciamento, revogação e recibos observados.
