# Reações, revisões e observações autenticadas

A biblioteca mantém projeções locais dos dados efetivamente recebidos pela sessão. Não consulta um total remoto autoritativo nem transforma a presença de um campo protobuf em suporte de publicação nativa.

```php
$features = $client->historyFeatures();
$chat = '120363000000000000@g.us';
$messageId = 'MVO_ORIGINAL_001';

// Última reação por participante. Substituições e remoções corrigem a contagem.
$reactions = $features->messageReactions($chat, $messageId, 100, '');

// Original, texto atual e revisões autenticadas, em ordem cronológica.
$revisions = $features->messageRevisions($chat, $messageId, 100, '');
$nextPage = $features->messageRevisions($chat, $messageId, 100, $revisions['nextCursor'] ?? '');

// O histórico comum agora apresenta a versão atual; a original permanece local.
$current = $client->history()->getMessage($chat, $messageId);
$original = $client->history()->getStoredMessage($chat, $messageId);

$formerMembers = $features->listMessageObservations('past-participants', [
    'chat' => $chat, 'participant' => '5511999999999@s.whatsapp.net',
    'limit' => 100, 'after' => '',
]);
$memberLabels = $features->listMessageObservations('member-labels', [
    'chat' => $chat, 'limit' => 100, 'after' => '',
]);
$comments = $features->listMessageObservations('comments', [
    'chat' => $chat, 'targetMessageId' => $messageId, 'limit' => 100, 'after' => '',
]);
$locations = $features->listMessageObservations('live-locations', [
    'chat' => $chat, 'limit' => 100, 'after' => '',
]);
$policies = $features->listMessageObservations('after-read', [
    'chat' => $chat, 'limit' => 100, 'after' => '',
]);
$botUpdates = $features->listMessageObservations('bot-observations', [
    'author' => '867051314767696@bot', 'limit' => 100, 'after' => '',
]);
```

## Regras da projeção

- Reações exigem a mensagem original na mesma conversa. Os timestamps e IDs determinam o resultado de substituições, remoções e repetições. Aliases PN/LID conhecidos são consolidados por participante.
- Edições e revogações exigem o mesmo autor autenticado da mensagem original. Uma revogação não é revertida por uma edição posterior. Exclusão local permanece uma operação distinta.
- O conteúdo original é imutável na projeção. `getMessage()` e `listMessages()` apresentam o conteúdo corrente com `editedAtMs`, `revoked`, `revokedAtMs` e `revisionCount`, quando aplicável. `getStoredMessage()` é acesso interno ao conteúdo original recebido, incluindo descritores privados; não o serialize diretamente em HTTP ou webhook.
- Adições autenticadas que chegam antes do original são guardadas para reconciliação, com limite de 1.000 registros pendentes por sessão e 64 KiB por registro. Dados ainda cifrados ou com falha de autenticação não entram nessas projeções.
- Revisões usam um índice cronológico por mensagem e cursor opaco. As consultas gerais usam o ID do último registro como `after`.
- Ex-participantes são importados de `HistorySync.pastParticipants`. Eles registram uma saída/remoção observada; não são a lista atual de membros.
- A identificação nativa do membro vem de `GroupParticipant.memberLabel` ou de atualização autenticada do próprio participante. Não é uma etiqueta CRM e não oferece setter nativo.
- `botProgress`, `botSuggestions`, `botMemory` e `botCapabilities` são normalizados com limites de tamanho e campos explícitos. Notificações de memória apenas informam o que o bot declarou adicionar/remover; não habilitam escrita de memória pela biblioteca.

## Recursos nativos ainda não homologados

As capacidades `experimental.comments`, `experimental.liveLocation` e `experimental.afterRead` mantêm `nativePublish=false`.

Comentários recebidos e descriptografados podem ser consultados quando o original é conhecido. Localizações preservam coordenadas, precisão, sequência e deslocamento declarados; a biblioteca não ativa o GPS do destinatário nem implementa duração/encerramento nativos. A política após leitura preserva `reportedDuration`, com `expiryKnown=false`: não inventa unidade, relógio inicial ou horário de expiração.

As novas projeções começam com os dados recebidos/importados após a atualização. A importação de histórico disponibilizado pelo celular pode preencher observações anteriores; isso não promete recuperar dados apagados ou indisponíveis.
