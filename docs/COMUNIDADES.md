# Comunidades em PHP

`$client->community()` administra comunidades pela conexão autenticada do próprio `WaClient`: criação, metadados, configurações, foto, membros, administradores, convites, subgrupos, solicitações, propriedade e recursos de suspensão. O serviço reutiliza os transportes de grupos, perfil e MEX; não cria outra sessão.

Os exemplos pressupõem um cliente conectado com o loop de `poll()`/`run()` em execução. Os JIDs ilustrativos precisam ser substituídos pelos retornados pelo WhatsApp.

## Criar e consultar comunidades

```php
$communities = $client->community();
$community = $communities->createCommunity('Clientes MVO', [
    'description' => 'Avisos e atendimento',
    'membershipApprovalMode' => 'request_required', // ou open
    'allowNonAdminSubGroupCreation' => false,
    'createGeneralChat' => true,
]);
$jid = $community['jid'];

$all = $communities->queryAllCommunities();
$metadata = $communities->queryCommunityMetadata($jid);
$members = $metadata['participants'];
$admins = array_values(array_filter(
    $members,
    static fn(array $member): bool => ($member['isAdmin'] ?? false) === true,
));
```

`queryAllCommunities()` filtra os grupos com `isParentGroup=true`. `queryCommunityMetadata()` exige que o ID consultado seja uma comunidade. Grupos de anúncios e demais grupos vinculados são subgrupos, com IDs próprios; não são confundidos com o pai.

O nome aceita até 100 caracteres UTF-8 e a descrição até 2.048. As opções de criação são explícitas: `description`, `membershipApprovalMode`, `allowNonAdminSubGroupCreation` e `createGeneralChat`. Opções desconhecidas são rejeitadas.

## Nome, descrição, foto e configurações

```php
$communities->setSubject($jid, 'Comunidade MVO');
$communities->setDescription($jid, 'Avisos e suporte');
$communities->setDescription($jid, null); // remover

$photo = $communities->getProfilePicture($jid, 'image'); // ou preview
$photoId = $communities->setProfilePicture($jid, file_get_contents(__DIR__.'/comunidade.jpg'));
$communities->removeProfilePicture($jid);

$communities->setSetting($jid, 'allow_non_admin_sub_group_creation', false);
$communities->setMemberAddMode($jid, 'admin_add');
$communities->setMemberLinkMode($jid, 'admin_link');
$communities->setMemberShareGroupHistoryMode($jid, 'admin_share');
$communities->setEphemeralDuration($jid, 86400);
```

Ao omitir `previousId` em `setDescription($jid, $description, $previousId)`, o serviço consulta o ID atual da descrição antes de escrever. Se já tiver o `descId`, forneça-o no terceiro argumento. String vazia ou `null` remove a descrição.

`setProfilePicture()` recebe bytes JPEG válidos de até 8 MiB. O SDK verifica o formato e não converte nem recorta o arquivo; faça a conversão antes de chamar.

`setSetting()` reutiliza as configurações de `GroupService`: `announcement`, `restrict`, `ephemeral`, `membership_approval_mode`, `allow_non_admin_sub_group_creation`, `group_history`, `allow_admin_reports` e `no_frequently_forwarded`. Prefira `setEphemeralDuration()` para definir a duração: `0` desativa e os valores usuais são `86400`, `604800` e `7776000` segundos. O servidor pode recusar configurações incompatíveis com aquele grupo.

Os modos aceitos são `admin_add`/`all_member_add`, `admin_link`/`all_member_link` e `admin_share`/`all_member_share`, respectivamente. Permissões continuam sendo determinadas pelo WhatsApp.

## Membros, administradores e convites

```php
$results = $communities->addParticipants($jid, ['123456789@lid']);
foreach ($results as $result) {
    if ($result['status'] !== 'ok') {
        // Trate a recusa individual usando code e jid.
    }
}
$communities->promoteParticipants($jid, ['123456789@lid']);
$communities->demoteParticipants($jid, ['123456789@lid']);
$communities->removeParticipants($jid, ['123456789@lid']);

$code = $communities->queryInviteCode($jid);
$renewed = $communities->revokeInvite($jid);
$info = $communities->queryCommunityInviteInfo($code);
$join = $communities->joinCommunityViaInvite($code);
if (($join['status'] ?? null) === 'pending') {
    // O servidor recebeu um pedido de entrada; a aprovação ainda não ocorreu.
}
```

Participantes aceitam telefone com DDI ou JID individual PN/LID, sem componente de dispositivo. Use LIDs reais; não gere um LID a partir do telefone. Usernames não são aceitos nessas listas administrativas. Resultados de alterações preservam `status`, `code`, o identificador e os dados originais; a IQ pode ter sucesso com falhas individuais.

`queryCommunityInviteInfo()` recusa um convite que seja de um grupo comum. O código de convite e o JID da comunidade são identificadores diferentes. O resultado de entrada distingue `joined` de `pending`; não trate solicitação de aprovação como entrada concluída.

## Subgrupos vinculados

```php
$subgroup = $communities->createSubGroup($jid, 'Suporte MVO', [
    '123456789@lid',
], ['description' => 'Atendimento aos clientes']);

$groups = $communities->querySubGroups($jid); // IQ w:g2: sub_groups
$rawMex = $communities->fetchSubGroups($jid); // envelope MEX ou null
$participants = $communities->queryLinkedGroupsParticipants($jid);
$suggestions = $communities->fetchSubgroupSuggestions($jid);

$linked = $communities->linkSubGroups($jid, ['120363000000000001@g.us']);
$hidden = $communities->linkSubGroups($jid, [
    ['jid' => '120363000000000002@g.us', 'hidden' => true],
]);
$communities->joinLinkedGroup($jid, '120363000000000001@g.us');
$unlinked = $communities->unlinkSubGroups(
    $jid,
    ['120363000000000001@g.us'],
    removeOrphanedMembers: false,
);
```

A criação envia `linked_parent` junto do pedido de criação, usando o pai informado no primeiro argumento. `options` aceita somente `description`; não permite sobrescrever o pai. Participantes são opcionais na criação de subgrupo. Vincular a comunidade a si mesma é rejeitado.

`linkSubGroups()` aceita JIDs em string ou objetos com `jid` e `hidden` booleano opcional. `hidden: true` envia `hidden_group` para solicitar o vínculo oculto. `unlinkSubGroups()` recebe somente JIDs em string. Ambos preservam as listas de sucesso e `failed` do servidor; confira todos os resultados antes de repetir. `querySubGroups()` oferece a consulta IQ; `fetchSubGroups()` e `fetchSubgroupSuggestions($jid, $hint)` preservam o formato MEX, incluindo `null` e campos adicionais do servidor. `hint`, quando fornecido, é um JID de grupo.

## Solicitações, propriedade e encerramento

```php
$requests = $communities->queryMembershipApprovalRequests($jid);
$approved = $communities->approveMembershipRequests($jid, ['123456789@lid']);
$rejected = $communities->rejectMembershipRequests($jid, ['987654321@lid']);
$cancelled = $communities->cancelMembershipRequests($jid, ['987654321@lid']);

$internal = $communities->isInternalGroup('120363000000000001@g.us');
$appeal = $communities->submitGroupSuspensionAppeal(
    $jid,
    'Solicito a revisão da suspensão.',
    '{"source":"customer-support"}',
);

// Execute somente quando essa for a ação pretendida pela aplicação:
$communities->transferCommunityOwnership($jid, '123456789@lid');
$communities->leaveCommunity($jid);
$communities->deactivateCommunity($jid);
```

Leia as falhas individuais também em aprovação, rejeição e cancelamento. `isInternalGroup()` consulta o JID do grupo/subgrupo indicado, não presume o pai. Propriedade, recurso e desativação usam comandos reais autenticados; nenhuma operação é simulada localmente quando a conta não tem permissão.

## Integração e verificação

Use `community()` na sessão autenticada para executar os métodos descritos neste guia. Caso sua aplicação ofereça uma API HTTP, ela deve definir autenticação, isolamento entre clientes, filas e contratos de entrada.

Os métodos IQ aguardam confirmação do servidor e propagam `MVO\Service\ServiceException` com `operation` e `serverCode`. MEX propaga erros GraphQL, mantém os dados originais e pode retornar `null`; IDs persistidos podem mudar com o serviço. Falhas não apagam credenciais nem autorizam uma nova conexão.

Os testes locais verificam filtros, validação, comandos autenticados, vínculo atômico de subgrupos, solicitações pendentes e falhas parciais. Nenhuma comunidade real foi criada ou alterada nesta entrega. A autorização da conta e a disponibilidade das operações no serviço atual ainda precisam ser homologadas com os dados e permissões da aplicação.
