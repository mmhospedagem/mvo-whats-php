# Serviços nativos PHP

Os serviços reutilizam a conexão autenticada do `WaClient`. Não criam uma segunda sessão nem executam Node.js. Os métodos que fazem IQ retornam apenas após resposta `type=result`; recusas geram `MVO\Service\ServiceException`, com `operation` e `serverCode`. A API não transforma falta de permissão de administrador em sucesso.

```php
$client->presence()->send('available');
$client->presence()->subscribe('5584999999999@s.whatsapp.net');
$client->presence()->sendChatstate('5584999999999@s.whatsapp.net', 'composing');
$client->presence()->sendChatstate('5584999999999@s.whatsapp.net', 'composing', 'audio');
$client->presence()->sendChatstate('5584999999999@s.whatsapp.net', 'paused');

$groups = $client->group()->queryAllGroups();
$group = $client->group()->queryGroupMetadata('123456789@g.us');
$results = $client->group()->addParticipants($group['jid'], ['123456789@lid']);
foreach ($results as $result) {
    if ($result['status'] !== 'ok') {
        // A IQ pode ter sucesso com falhas individuais, por exemplo código 403.
    }
}
```

## Grupos e comunidades

O serviço dedicado `$client->community()` reúne o gerenciamento de comunidades, membros/admins, foto, configurações, convites, subgrupos, solicitações, propriedade e recursos de suspensão. Veja o [guia de comunidades](COMUNIDADES.md) para exemplos, respostas parciais e limites. Ele reutiliza a conexão existente e conserva a compatibilidade de `group()`.

`group()` oferece `queryGroupMetadata`, `queryAllGroups`, `queryGroupInviteInfo`, `createGroup`, `setSubject`, `setDescription`, `setSetting`, `setMemberAddMode`, `setMemberLinkMode`, `setMemberShareGroupHistoryMode`, `setEphemeralDuration`, `addParticipants`, `removeParticipants`, `promoteParticipants`, `demoteParticipants`, `leaveGroup`, `queryInviteCode`, `revokeInvite` e `joinGroupViaInvite`.

Comunidades: `createCommunity`, `deactivateCommunity`, `linkSubGroups`, `unlinkSubGroups`, `queryLinkedGroupsParticipants`, `queryMembershipApprovalRequests`, `approveMembershipRequests`, `rejectMembershipRequests`, `cancelMembershipRequests` e `joinLinkedGroup`. As consultas MEX são `fetchSubGroups`, `isInternalGroup`, `fetchSubgroupSuggestions`, `submitGroupSuspensionAppeal` e `transferCommunityOwnership`.

```php
$community = $client->group()->createCommunity('Comunidade', [
    'description' => 'Descrição',
    'membershipApprovalMode' => 'request_required', // ou open
    'createGeneralChat' => true,
]);
$client->group()->setSetting($group['jid'], 'announcement', true);
$client->group()->setEphemeralDuration($group['jid'], 86400);
$client->group()->setDescription($group['jid'], null, $group['descId']);
```

`setSetting` aceita `announcement`, `restrict`, `ephemeral`, `membership_approval_mode`, `allow_non_admin_sub_group_creation`, `group_history`, `allow_admin_reports` e `no_frequently_forwarded`. JIDs de grupos devem terminar em `@g.us`. Não confundir fixar mensagem com fixar conversa; as mudanças de conversa são app-state.

## Perfil, privacidade e Business

`profile()` oferece foto (get/set/delete), About legado (get/set), perfis em lote, modo temporário padrão, status de texto moderno, usernames (consulta própria, disponibilidade, definir, excluir, chave de recuperação, resolução para LID) e resolução de telefones para LID. `setPushName` sincroniza a alteração por app-state e anuncia presença com o novo nome.

Fotos são bytes JPEG prontos; o serviço não recorta nem reencoda a imagem. `getProfilePicture`/`getStatus` aceitam opcionalmente o `<tctoken>` de privacidade conhecido pelo chamador. Sem um token válido o servidor pode ocultar dados do contato.

```php
$client->profile()->setStatus('Atendimento de segunda a sexta');
$client->profile()->setTextStatus('Disponível', '💬');
$resolved = $client->profile()->resolveUsername('@usuario:1234');
$client->privacy()->setPrivacySetting('lastSeen', 'contacts');
$client->privacy()->setDisallowedList('lastSeen', add: ['123456789@lid']);
$client->privacy()->blockUser('123456789@lid');
$client->privacy()->unblockUser('123456789@lid');
```

Privacidade: `getPrivacySettings`, `setPrivacySetting`, `getDisallowedList`, `setDisallowedList`, `getBlocklist`, `blockUser`, `unblockUser`. A escrita de deny-list busca o `dhash` atual e repete uma vez após conflito 409. Telefones são resolvidos para LID antes de bloquear ou alterar listas. Não são usados identificadores PN incorretos quando o servidor informa migração.

`business()` oferece `getBusinessProfile`, `editBusinessProfile`, `getVerifiedName(s)`, `updateCoverPhoto`, `bindCoverPhoto` e `deleteCoverPhoto`. `updateCoverPhoto()` carrega a imagem local ao PPS, valida os metadados e vincula a capa ao perfil; `bindCoverPhoto(id, timestamp, token)` permite vincular um upload já realizado. Horários aceitam `open_24h`, `specific_hours` e `appointment_only`; dias fechados devem ser omitidos. O servidor determina a autorização da conta Business.

`catalog()` oferece consulta e CRUD de produtos, coleções e associação de produtos, recurso/reordenação de coleções, opções de carrinho, categorias e checkout/pedidos. `businessTools()` administra etiquetas, associações a conversas/mensagens, respostas rápidas e listas comerciais por app-state autenticado. As consultas dessas ferramentas leem a projeção local sincronizada. Veja os exemplos e as unidades de preço no [guia WhatsApp Business](BUSINESS.md).

## Status e listas de transmissão

```php
$published = $client->status()->send('Novidades do dia', [
    '123456789@lid',
], 'allowlist');
$client->status()->setUserMuted('123456789@lid', true);
$client->status()->setPrivacy(['mode' => 'allowlist', 'userJids' => ['123456789@lid']]);

$client->broadcastList()->setList([
    'id' => '123456789@broadcast',
    'listName' => 'Clientes',
    'participants' => [['lidJid' => '123456789@lid', 'pnJid' => '5584999999999@s.whatsapp.net']],
]);
$client->broadcastList()->send('123456789@broadcast', 'Mensagem', ['123456789@lid']);
```

`status()` publica pela criptografia de broadcast com destinatários explícitos, preserva a audiência e oferece consultas, revogação, visualizações observadas, leitura, respostas, reações e mídia. Configurações de distribuição/mute são patches app-state autenticados. Consulte o [guia de Status](STATUS.md). `broadcastList()` oferece `setList`, `removeList` e `send`. Listas comerciais dependem de conta Business. Os destinatários não são descobertos implicitamente da agenda.

## Canais / newsletters

O serviço `$client->channels()` mantém a compatibilidade de `newsletter()` e reúne administração, descoberta, posts/mídia, enquetes, perguntas, reações, fixação e histórico persistente. Veja o [guia de canais](CANAIS.md), incluindo eventos, IDs de resposta, Status de canais e os contratos HTTP da API.

`newsletter()` oferece busca por JID/convite, inscrições, diretório/recomendações/similares/categorias, metadados leves, domínio com preview, criação/edição/exclusão, capacidades/admins/convites/seguidores/insights/relatórios, follow/unfollow/mute e consulta/aceitação de TOS. As operações MEX preservam os envelopes GraphQL e seus campos futuros; confira o formato retornado antes de usá-lo como DTO de apresentação.

```php
$channel = $client->newsletter()->fetch('123456789@newsletter');
$post = $client->newsletter()->send('123456789@newsletter', 'Novidade!');
$client->newsletter()->editMessage('123456789@newsletter', $post['id'], 'Texto corrigido');
$client->newsletter()->react('123456789@newsletter', 42, '👍');
$client->newsletter()->sendViewReceipt('123456789@newsletter', [42]);
$client->newsletter()->subscribeLiveUpdates('123456789@newsletter');
```

As publicações são protobuf plaintext, conforme o protocolo público de canais. Texto, enquetes e descritores de mídia já carregada em plaintext são aceitos. Mídia cifrada de conversa direta é recusada; reaproveitar sua URL e `mediaKey` geraria uma publicação inválida. Envio/edição/exclusão aguarda ACK; reação/voto/view retorna `status=sent`, sem declarar entrega ao destinatário.

`mex()->operations()` lista as 129 operações persistidas do snapshot do projeto de referência. `mex()->run($operation, $variables)` faz IQ MEX real, valida a resposta e propaga erros GraphQL. JSON e Argo self-describing são decodificados nativamente, incluindo strings/backreferences, valores binários, inteiros/floats, arrays/objetos e erros GraphQL com paths. Formatos desconhecidos ou payloads truncados geram erro explícito. Os IDs do snapshot podem expirar com atualizações do servidor.

## Bots, email e eventos

`bot()` oferece `listBots`, `getBotProfile`, `sendPrompt` pelo publisher dedicado e `BotService::decryptChunk` com HKDF/AES-GCM autenticado. Os chunks precisam do segredo da mensagem pai e dos JIDs usados no envelope; a autenticação falha se esses dados estiverem incorretos.

`email()` oferece `getStatus`, `setEmail`, `requestVerificationCode`, `verifyCode` e `confirm` com IQs reais. Essa superfície do projeto de referência é mobile/primária; uma sessão Web/companion normalmente recebe recusa do servidor. A existência dos builders não converte a sessão companion em aparelho primário.

`IncomingEvents::parse` normaliza presença, digitação/gravação, notificações de grupos, fotos, username próprio, account-sync, Business, MEX e mensagens/updates de canais. Mantém `rawNode` e os campos desconhecidos. O `WaClient` conserva a responsabilidade de ACK, persistência e roteamento; o parser não envia nada.

`OfflineResumeService` solicita lotes de até 200 stanzas após `offline_preview`, limita a frequência a 100 ms e repete falha de transporte até três vezes. `tick()` deve ser chamado no loop de poll; a conclusão normal exige `offline` do servidor. Após 60 s sem stanza, emite conclusão com `forced=true` e contador restante, sem declarar que todo histórico foi sincronizado.

## Verificação e limites

Os testes locais usam fixtures de IQ/stanza do projeto de referência, incluem recusas, conflitos de versão, falhas individuais, envelopes plaintext, ACK incorreto, vetores criptográficos, validação e contadores offline. Isso verifica serialização e estado sem executar alterações na conta do usuário. Testes de autorização/permissões e disponibilidade do produto dependem da conta e do servidor real.

Mobile primary/registro de número, companion hosting, Shortcake/passkeys e chamadas VoIP/RTP não são implementados por estes serviços. Não são substituídos por sucesso simulado.
