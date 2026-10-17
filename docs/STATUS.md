# Status do WhatsApp em PHP

`$client->status()` administra as publicações temporárias do WhatsApp pela conexão já autenticada. Status são diferentes do recado do perfil, alterado por `profile()->setStatus()`.

## Publicar

Informe os destinatários explicitamente. O serviço normaliza telefones e JIDs PN/LID reais; a distribuição usa a criptografia de broadcast da sessão. A configuração de privacidade não substitui a lista de destinatários de uma publicação.

```php
$statuses = $client->status();
$audience = ['123456789@lid'];
$published = $statuses->sendText('Novidades da MVO!', $audience, [
    'font' => 0,
    'backgroundColor' => '#009BF5',
    'textColor' => '#FFFFFF',
], 'allowlist');

$image = $statuses->sendImage(['path' => __DIR__.'/imagem.jpg'], $audience, [
    'caption' => 'Conheça nossas novidades',
], 'allowlist');
$video = $statuses->sendVideo(['path' => __DIR__.'/video.mp4'], $audience, [], 'allowlist');
$voice = $statuses->sendVoice(['path' => __DIR__.'/voz.ogg'], $audience, [], 'allowlist');
```

O ACK do servidor não comprova visualização. A audiência utilizada fica salva junto do envio, na mesma base da sessão, para permitir consultas e revogação depois de reiniciar. Não há descoberta implícita da agenda nem publicação para destinatários inventados.

`statusSetting` aceita `contacts`, `allowlist`, `denylist` e `close_friends`. Para as cores, use `#RRGGBB`, `#AARRGGBB` ou um inteiro ARGB de 32 bits. O serviço valida os tipos de conteúdo e os campos antes do envio; o servidor determina disponibilidade e limites da conta.

## Publicar Status em um grupo ou lista de grupos

`sendToGroups()` publica uma mensagem de **Status de grupo**, usando o envelope `groupStatusMessageV2` e a criptografia Sender Key do grupo. Cada destino precisa ser um JID real `@g.us` e a conta conectada precisa ser membro atual. Em grupos que permitem publicação apenas por administradores, a sessão também precisa ser administradora.

```php
$publication = $client->status()->sendToGroups([
    '120363000000001@g.us',
    '120363000000002@g.us',
], [
    'type' => 'text',
    'text' => 'Olá, equipe! Confira nossas novidades.',
    'backgroundColor' => '#0000AA',
    'textColor' => '#FFFFFF',
    'font' => 0,
], [
    'listName' => 'Equipe MVO',
    'listEmoji' => '⭐',
]);

// Também aceita um grupo único e texto simples.
$simple = $client->status()->sendToGroups('120363000000001@g.us', 'Novidades MVO');

$photo = $client->status()->sendToGroups('120363000000001@g.us', [
    'type' => 'image',
    'media' => ['path' => __DIR__.'/imagem.jpg'],
    'caption' => 'Conheça nossas novidades',
    'mimetype' => 'image/jpeg',
    'accessibilityLabel' => 'Novidades da equipe MVO',
], ['listName' => 'Equipe MVO', 'listEmoji' => '📢']);

$video = $client->status()->sendToGroups('120363000000001@g.us', [
    'type' => 'video',
    'media' => ['path' => __DIR__.'/video.mp4'],
    'mimetype' => 'video/mp4',
    'caption' => 'Vídeo da equipe',
    'seconds' => 12,
    'gifPlayback' => false,
]);

$voice = $client->status()->sendToGroups('120363000000001@g.us', [
    'type' => 'voice', // audio envia áudio comum; voice/ptt envia nota de voz.
    'media' => ['path' => __DIR__.'/voz.ogg'],
    'mimetype' => 'audio/ogg; codecs=opus',
    'seconds' => 5,
    'backgroundColor' => '#0000AA',
]);
```

São aceitos até 50 grupos explícitos por chamada. JIDs repetidos são removidos, preservando a ordem. `listName` e `listEmoji` são metadados de apresentação do público; os destinatários continuam sendo os membros atuais de cada grupo. Quando omitidos, são usados o nome atual de cada grupo e 📢; se o servidor não informar um nome utilizável, a legenda será “Status do grupo”. Esses campos não criam uma lista de contatos nem substituem a privacidade do Status pessoal. O serviço não envia mensagens personalizadas separadas para cada membro.

Todos os destinos são verificados antes de processar a mídia. A mídia é enviada ao CDN uma vez e seu descritor criptografado é reutilizado; a conta e a composição de cada grupo são conferidas novamente imediatamente antes de sua publicação. Os formatos são `text`, `image`, `video`, `audio`, `voice` e `ptt`. As mesmas regras do processamento de mídia da LIB se aplicam. Não há conversão ou divisão automática de vídeos nesta operação.

O retorno contém `requestedCount`, `sentCount`, `results`, `complete`, `failure` e `skippedGroups`. Cada resultado tem seu próprio `groupJid`, `id` e `status`. `sentCount` conta confirmações do servidor e não visualizações. Um resultado `unknown`, `rejected` ou `not_executed` interrompe a sequência; os grupos restantes aparecem em `skippedGroups`. Não reenvie o lote inteiro, pois os primeiros grupos podem ter recebido a publicação. Preserve os IDs retornados para consultar o envio.

Quando o preparo criptográfico falha antes da publicação, `status` será `not_executed` e `preflight` apresentará apenas `stage` e `reason` seguros, por exemplo `device_discovery` / `device_list_empty` ou `signal_sessions` / `signal_signature_invalid`. A causa original permanece no encadeamento interno da exceção, sem telefones, participantes ou dados criptográficos na resposta. Nessa situação o ID foi reservado, mas não existe mensagem na outbox nem escrita desse Status no transporte. Uma falha na pré-validação inicial impede todas as publicações do lote; uma falha antes de um grupo posterior preserva os resultados já confirmados. Erros depois da escrita continuam como `unknown` quando não há confirmação, mesmo que uma operação aninhada tenha falhado no preparo.

```php
$group = '120363000000001@g.us';
$rows = $client->status()->listForGroup($group, ['limit' => 50]);
$item = $client->status()->getForGroup($group, 'MESSAGE_ID');
// Se houver o mesmo ID com autores diferentes, informe o autor retornado na listagem.
$received = $client->status()->getForGroup($group, 'MESSAGE_ID', '123456789@lid');
$deleted = $client->status()->deleteForGroup($group, 'OWN_ACKNOWLEDGED_MESSAGE_ID');
```

As consultas são locais, persistentes e restritas ao grupo informado. `listForGroup` permite `limit`, `beforeTimestamp`, `author` e `includeRevoked`; a consulta individual também pode devolver uma publicação revogada. Somente uma publicação própria confirmada pelo servidor pode ser removida. A remoção retorna separadamente os envios de revogação do Status e de sincronização dos aparelhos; uma falha parcial não é escondida. As consultas não inventam contadores de visualização nem prazos de expiração a partir do histórico de mensagens do grupo.

Aplicações com controle de acesso podem configurar `$client->setGroupStatusGuard($callback)`. O callback é reavaliado antes da preparação, de cada grupo e da escrita no transporte. Suspensão ou exclusão pode interromper o restante do lote, preservando os resultados dos grupos já confirmados. A API MVO configura esse controle automaticamente.

A disponibilidade e a apresentação do Status de grupo dependem das permissões e da versão/liberação do WhatsApp nos aparelhos. A confirmação de transporte não comprova a exibição do cartão no aplicativo.

## Consultar e remover

```php
$own = $statuses->listStatuses(['scope' => 'own']);
$received = $statuses->listStatuses(['scope' => 'received', 'limit' => 100]);
$item = $statuses->getStatus('MESSAGE_ID', '123456789@lid');
$viewers = $statuses->listViewers($published['id']);
$audienceSnapshot = $statuses->getAudience($published['id']);

// Reutiliza a audiência original salva; revoga somente uma publicação própria.
$revoked = $statuses->revokeStatus($published['id']);
```

As consultas leem o histórico recebido ou sincronizado por essa sessão. Não são um inventário remoto completo. Por padrão, a listagem omite publicações expiradas após 24 horas e revogadas. Use `includeExpired` e `includeRevoked` para consultar registros locais antigos. Quando houver IDs iguais de autores diferentes, informe o autor retornado na listagem.

`listViewers()` apresenta apenas recibos de leitura/reprodução autorizados e observados. Recibos de entrega não são visualizações. A ausência de recibos não permite concluir que ninguém viu a publicação. A revogação usa a audiência salva da publicação; não recalcula os destinatários com base na privacidade atual.

## Privacidade, silenciamento e interação

```php
$privacy = $statuses->getPrivacy(); // null enquanto não houver projeção sincronizada
$statuses->setPrivacy(['mode' => 'allowlist', 'userJids' => $audience]);
$statuses->setUserMuted('123456789@lid', true);
$muted = $statuses->listMuted();
$statuses->setUserMuted('123456789@lid', false);

$statuses->readStatus('MESSAGE_ID', '123456789@lid');
$statuses->replyToStatus('MESSAGE_ID', '123456789@lid', 'Gostei da novidade!');
$statuses->reactToStatus('MESSAGE_ID', '123456789@lid', '👍');
$bytes = $statuses->downloadStatus('MEDIA_MESSAGE_ID', '123456789@lid');
```

Privacidade e silenciamento usam patches app-state autenticados. Um valor desconhecido não é convertido em uma configuração padrão presumida. `readStatus()` retorna `receipt_queued`: o recibo fica persistido para envio e a marcação local acontece quando o transporte o envia. Isso não é uma confirmação de recebimento pelo servidor. Responder e reagir usam o autor e a referência de Status armazenada. O download usa os metadados reais da mídia recebida.

## Eventos e persistência

```php
$client->on('status', function (array $event): void {
    // action diferencia recebimento, publicação, revogação e visualização observada.
    echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
});
```

O evento `message` continua disponível. Os dados de Status, audiências e marcações compartilham o armazenamento persistente da sessão. A aplicação deve continuar executando o loop do cliente para receber publicações e recibos.

Os testes usam transportes locais e fixtures criptográficas. Não publicam, visualizam ou removem Status de contas reais.
