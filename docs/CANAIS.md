# Canais do WhatsApp em PHP

`$client->channels()` oferece o gerenciamento de canais pela sessão autenticada do `WaClient`. O acesso anterior por `newsletter()` continua disponível e retorna o mesmo serviço. Um canal usa um JID como `120363000000000000@newsletter`; os IDs deste guia são ilustrativos.

Mantenha o loop `poll()`/`run()` ativo. Os exemplos abaixo pressupõem uma conexão autenticada. As permissões de proprietário, administrador e seguidor são determinadas pelo WhatsApp; a biblioteca preserva recusas e não aceita termos automaticamente.

## Criar, consultar e configurar

```php
use MVO\Service\NewsletterService;

$channels = $client->channels();
$created = $channels->create('Novidades MVO', 'Avisos e novidades');
$jid = NewsletterService::normalizeMetadata($created)['jid']
    ?? throw new RuntimeException('O servidor não retornou um JID de canal válido');

$metadata = $channels->fetch($jid);
$view = NewsletterService::normalizeMetadata($metadata);
$followed = $channels->listSubscribed();
$byInvite = $channels->fetchByInvite('https://whatsapp.com/channel/CONVITE');
$link = $channels->inviteUrl($jid); // null quando o servidor não fornece convite

$channels->setName($jid, 'Canal MVO');
$channels->setDescription($jid, 'Novidades e atendimento');
$channels->setPicture($jid, file_get_contents(__DIR__.'/canal.jpg'));
$channels->removePicture($jid);
$channels->setReactionCodes($jid, 'ALL'); // ALL, BASIC, NONE ou BLOCKLIST
$channels->mute($jid, true);             // mensagens
$channels->mute($jid, true, true);       // atividade administrativa
```

`update($jid, $patch)` combina `name`, `description`, `picture` e `reactionCodesSetting` em uma alteração parcial. String vazia remove a descrição; `picture: null` solicita remoção da foto. O SDK recebe bytes JPEG prontos; sua aplicação deve preparar e validar esses bytes antes da chamada.

`normalizeMetadata()` acrescenta acesso a nome, descrição, imagem, convite, quantidade de seguidores e dados do usuário. Campos ausentes permanecem `null` e o envelope original fica em `raw`. Contagens grandes recebidas como string são preservadas.

Para seguir, deixar de seguir ou excluir, use `follow($jid)`, `unfollow($jid)` e `delete($jid)`. Exclusão é uma operação no servidor. Um resultado local ausente não significa que o canal foi excluído.

## Diretório e administração

| Finalidade | Métodos |
| --- | --- |
| Busca e recomendações | `searchDirectory()`, `fetchRecommended()`, `fetchSimilar()` |
| Diretório, categorias e domínios | `fetchDirectoryList()`, `fetchDirectoryCategoriesPreview()`, `fetchIsDomainPreviewable()` |
| Metadados reduzidos e resolução | `fetchDehydrated()`, `resolveChannel()`, `inviteCode()`, `inviteUrl()` |
| Administradores e capacidades | `fetchAdminInfo()`, `fetchAdminCapabilities()` |
| Seguidores e métricas | `fetchFollowers()`, `fetchInsights()` |
| Convites administrativos | `createAdminInvite()`, `fetchPendingInvites()`, `acceptAdminInvite()`, `revokeAdminInvite()` |
| Propriedade e remoção de administrador | `changeOwner()`, `demoteAdmin()` |
| Restrições e denúncias | `fetchEnforcements()`, `fetchReports()` |
| Termos | `queryTosState()`, `acceptTos()` |

```php
$results = $channels->searchDirectory([
    'searchText' => 'tecnologia',
    'categories' => ['BUSINESS'],
    'limit' => 25,
]);
$directory = $channels->fetchDirectoryList([
    'view' => 'POPULAR',
    'countryCodes' => ['BR'],
    'limit' => 25,
]);

$invite = $channels->createAdminInvite($jid, '5511999999999');
$pending = $channels->fetchPendingInvites($jid);
```

O diretório aceita `RECOMMENDED`, `NEW`, `POPULAR`, `FEATURED` e `TRENDING`, países ISO de duas letras maiúsculas e as categorias nativas. `startCursor` é o cursor retornado pelo servidor. Convites administrativos resolvem o telefone para LID antes da mutação; uma identidade não resolvida não é enviada como se fosse LID.

`fetchInsights()` preserva objetos nativos em `metrics`; os campos específicos dessas métricas não foram observados na referência pública atual. Esse método avançado não oferece nomes de métricas inventados e depende do contrato autorizado para a conta.

## Publicações, enquetes e interações

```php
$post = $channels->send($jid, 'Novidades da MVO!');
$photo = $channels->send($jid, [
    'type' => 'image',
    'media' => ['path' => __DIR__.'/novidade.jpg'],
    'caption' => 'Novidades',
]);
$poll = $channels->send($jid, [
    'type' => 'poll',
    'name' => 'Qual tema você prefere?',
    'options' => ['Tecnologia', 'Hospedagem'],
    'selectableCount' => 1,
]);

$channels->editMessage($jid, $post['id'], 'Texto atualizado');
$channels->revoke($jid, $post['id']);
```

O canal usa mensagens públicas plaintext e uploads próprios para imagem, vídeo/GIF/PTV, áudio/voz, documento, sticker e pacote de stickers. Contatos e enquetes também são suportados. Um descritor cifrado de chat privado não pode ser reutilizado em um canal.

`send()`, `editMessage()` e `revoke()` retornam `server_ack` somente após o ACK correlacionado. `serverId` e `timestampSeconds` aparecem quando o servidor os fornece. Publicações próprias confirmadas ficam no histórico local; a projeção local não cria um callback de mensagem recebida. Um timeout ou erro após envio exige conferir os eventos antes de repetir a publicação.

| Ação | Método e identificador |
| --- | --- |
| Reagir / remover reação | `react($jid, $serverId, '👍')`, `revokeReaction($jid, $serverId)` |
| Votar / remover voto | `votePoll($jid, $serverId, $hashes)`, `votePoll($jid, $serverId, [])` |
| Visualização explícita | `sendViewReceipt($jid, [$serverId])` |
| Votantes / remetentes de reações | `fetchPollVoters()`, `fetchMessageReactionSenders()` |
| Fixar / desafixar | `pinMessages($jid, [$serverId])`, `unpinMessages($jid, [$serverId])` |
| Identificar parceria paga / IA | `addPaidPartnershipLabel()`, `addAiContentLabel()` |
| Resultado explícito de enquete | `sendPollResultSnapshot()` |

Essas ações usam o ID numérico de servidor, que é distinto do ID de cliente retornado como `id`. Os votos são hashes SHA256 de 32 bytes. Reações, votos e recibos retornam `sent` quando o transporte aceita a stanza; esse estado não comprova ACK, entrega ou visualização. As consultas não enviam recibos de visualização automaticamente.

## Perguntas e respostas

```php
$question = $channels->sendQuestion($jid, 'Qual assunto você quer ver aqui?');
// Use o serverId real observado para a pergunta.
$response = $channels->respondQuestion($jid, $questionServerId, 'Hospedagem PHP');
```

Perguntas e compartilhamentos usam os wrappers nativos `questionMessage` / `questionReplyMessage`, com contexto e metadados próprios. `respondQuestion()` usa `questionResponseMessage`. A resposta tem um `responseServerId` opaco, preservado como string; `questionServerId` identifica a pergunta e não transforma a resposta em outro post com o mesmo ID.

`reshareQuestionResponse()` recebe os dois IDs de servidor e os protocolos originais da pergunta/resposta observados na mesma sessão. A API recupera essas citações por referências locais, sem aceitar um protocolo arbitrário no request. O compartilhamento depende de permissão administrativa.

## Histórico persistente, mídia e eventos

```php
$events = $channels->fetchMessagesDecoded($jid, 25);
$updates = $channels->fetchMessageUpdatesDecoded($jid, 25, since: $since, after: $serverId);
$local = $channels->listLocalMessages($jid, 50, beforeTimestamp: $before);
$record = $channels->getLocalMessage($jid, $messageId);
$bytes = $channels->downloadMessageMedia($jid, $messageId);

$subscription = $channels->subscribeLiveUpdates($jid);
// Renove após durationSeconds e depois de reconectar.
```

As consultas `fetchMessages()`, `fetchMessageUpdates()` e `fetchStatuses()` preservam o retorno `BinaryNode`. As variantes `*Decoded()` retornam uma lista `{event, data}` e projetam os dados na inbox durável do cliente. Um post também gera o evento compatível `message`; filtre por `newsletter_message` ao construir uma lista para não duplicá-lo.

`getLocalMessage()` aceita o ID de cliente e, opcionalmente, um `serverId` consistente. IDs locais `server:123` e `response:ID` identificam registros sem ID original ou respostas; preserve a referência retornada. `downloadMessageMedia()` usa o descritor armazenado, valida SHA256 e tamanho e rejeita posts revogados. Não recebe uma URL arbitrária do consumidor.

Posts e Status de canais têm índices separados, mesmo quando o servidor reutiliza um número. `fetchStatusesDecoded()` consulta publicações temporárias do canal com até 100 itens; esse recurso é distinto dos Status pessoais de `status()`. Respostas a perguntas também não substituem o registro da pergunta.

```php
$client->on('newsletter_message', static function (array $event): void {
    echo json_encode([
        'event' => 'newsletter_message',
        'chat' => $event['chat'],
        'id' => $event['id'],
        'serverId' => $event['serverId'],
        'text' => $event['text'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), PHP_EOL;
});
$client->on('newsletter_update', $onPostUpdate);
$client->on('channel_update', $onChannelUpdate);
```

`newsletter_update` traz edições, revogações, reações, votos e contadores. `channel_update` traz entrada/saída, metadados, silenciamento, estado e alterações administrativas. O envelope genérico `mex_notification` continua disponível.

A projeção e a fila de callbacks usam o armazenamento da sessão. Callback interrompido volta com `replayed: true` após reiniciar. A entrega é pelo menos uma vez: uma queda entre concluir o callback e salvar seu cursor pode repeti-lo. A aplicação deve manter seus efeitos idempotentes. Tombstones impedem que um histórico antigo restaure um post revogado; clocks por campo preservam patches independentes recebidos fora de ordem.

O exemplo de consulta [examples/channels.php](../examples/channels.php) oferece `--help`, `list`, `metadata` e `messages` com saída JSON resumida. Ele usa uma sessão já vinculada e exige que outro worker dessa sessão esteja parado.

## Integração e validação

Os métodos PHP descritos nesta página reutilizam a sessão conectada. Sua aplicação define as rotas HTTP, autenticação, filas e projeções de eventos que desejar expor; esses componentes não acompanham a biblioteca.

Os testes desta implementação usam transportes locais, respostas e conteúdo sintéticos. Conferem formatos IQ/MEX, ACK e recusas, entrada antes de I/O, upload/download plaintext, hashes, isolamento de sessão, histórico, reinício e callbacks. Não foram criados, seguidos, excluídos ou publicados canais reais durante a validação.

A pesquisa examinou módulos públicos atuais do WhatsApp Web, comparou 28 operações persistidas e registrou hashes e evidências REA em [CHANNEL-PROTOCOL-EVIDENCE.json](CHANNEL-PROTOCOL-EVIDENCE.json). Isso não comprova permissão ou disponibilidade atual de cada recurso na conta. Agendamento, publicação de Status de canais, produtor de quiz e alteração do estado de respostas não têm contrato de envio confirmado nesta revisão; não recebem rotas fictícias. Métricas avançadas continuam exigindo um objeto nativo autorizado.
