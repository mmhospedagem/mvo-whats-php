# Administração, pessoas e permissões

`PageAdministrationService` usa documentos Web do Facebook e do Business Suite com a mesma sessão privada da conexão. Não usa a Marketing Graph API. Pessoas de um portfólio, permissões de Página, papéis de acesso limitado e propriedade são conceitos distintos.

## Cargos nativos da Página

`pageRoles($pageId)` consulta a coleção nativa `page_roles` da Página. O resultado distingue `user_id`, `role_value`, `role_name` e `is_pending`, além do cursor. `nextPageRoles($pageId, $cursor, $count = 10)` continua a mesma coleção; o limite local aceito é de 1 a 100. O actor dessas duas consultas é a própria Página, com a pessoa dona da sessão preservada em `__user`. O resultado inicial confere ambos os IDs retornados pelo servidor.

| `role_name` | `role_value` | Escrita nesse fluxo |
| --- | --- | --- |
| `ADMIN` | `0` | Convite, alteração, remoção ou cancelamento pendente. |
| `EDITOR` | `1` | Convite, alteração, remoção ou cancelamento pendente. |
| `MODERATOR` | `2` | Convite, alteração, remoção ou cancelamento pendente. |
| `ADVERTISER` | `3` | Convite, alteração, remoção ou cancelamento pendente. |
| `ANALYST` | `4` | Convite, alteração, remoção ou cancelamento pendente. |
| `CUSTOM` | `7` | Somente leitura; a interface observada desabilita edição e remoção. |

Esses valores são os do seletor Web observado, disponíveis também em `PageAdministrationDocuments::roles()`. Não correspondem a `business_account_task_ids` ou `permitted_task_ids`. A coleção conserva `exhaustive=false` e `all_administrators=false`: percorrê-la não demonstra todos os modelos modernos de acesso, parceiros ou propriedade.

### Confirmar a senha para cargos

O fluxo nativo de cargos usa **outra confirmação**, diferente da confirmação de remoção do próprio acesso limitado descrita adiante. Chame `pageRoleReauthenticationKey($pageId)`, cifre a senha com a chave atual e envie o envelope para `reauthenticatePageRolesEncrypted($pageId, $encryptedPassword, $idempotencyKey)`. Receba a senha somente por uma entrada privada da aplicação. Não use valores fictícios do exemplo de teste.

```php
use MVO\Facebook\Service\PageAdministrationService;
use MVO\Messenger\Auth\PasswordEncryption;

function confirmarSenhaParaCargos(
    PageAdministrationService $admin,
    string $pageId,
    #[\SensitiveParameter] string $senhaRecebidaPrivadamente,
    string $idempotencyKey,
): array {
    $chave = $admin->pageRoleReauthenticationKey($pageId);
    $envelope = PasswordEncryption::encrypt(
        $chave['key_id'],
        $chave['public_key'],
        $senhaRecebidaPrivadamente,
    );
    return $admin->reauthenticatePageRolesEncrypted(
        $pageId,
        $envelope,
        $idempotencyKey,
    );
}
```

O `auth_token` retornado pela Meta fica somente na memória desse objeto, vinculado à Página. Ele não aparece na projeção pública, no ledger ou em `var_dump()`. A janela de cinco minutos é uma política conservadora do SDK, não uma garantia de validade do token no servidor. O replay preserva o timestamp real e não recria um token depois de reiniciar o processo. Nesse caso, consulte o comando salvo para conhecer o resultado; uma nova ação exige uma nova confirmação explícita. Preserve privadamente o mesmo envelope ao repetir a chave de um comando de confirmação; não gere outro envelope para a mesma chave.

### Convidar, alterar, remover e cancelar

| Método | Pré-condição |
| --- | --- |
| `invitePageRole($pageId, $userId, $role, $idempotencyKey)` | Pessoa exata selecionada pela aplicação, cargo editável e confirmação de senha nesse objeto. |
| `changePageRole($pageId, $userId, $role, $idempotencyKey)` | Pessoa observada há até cinco minutos nessa Página, cargo aceito e editável. |
| `removePageRole($pageId, $userId, $currentRole, $idempotencyKey, $deleteScheduledPosts = false)` | Mesmo vínculo observado e o `role_value` atual exato. Excluir publicações agendadas exige a escolha explícita `true`. |
| `cancelPageInvitation($pageId, $userId, $currentRole, $idempotencyKey)` | Convite observado nessa Página com `is_pending=true`; preserve seu cargo atual. |

Em tentativas novas, o SDK reserva a chave no ledger e verifica a confirmação em memória e a permissão atual `if_viewer_can_manage_admins` **antes da mutation**. Alterar, remover ou cancelar também exige um registro recente obtido por `pageRoles()`/`nextPageRoles()` no mesmo serviço. Um convite pendente deve ser cancelado; não é tratado como acesso já aceito. Uma escrita confirmada invalida a observação local da pessoa: consulte novamente antes de outra alteração.

```php
use MVO\Facebook\Service\PageAdministrationService;

function convidarModeradorEscolhido(
    PageAdministrationService $admin,
    string $pageId,
    string $userIdEscolhido,
    string $idempotencyKey,
): array {
    // A aplicação já confirmou a senha para esta Página nesse mesmo objeto.
    return $admin->invitePageRole($pageId, $userIdEscolhido, 2, $idempotencyKey);
}
```

`invitation_pending` distingue uma resposta que ainda depende de aceitação. O servidor precisa retornar a pessoa exata, o cargo esperado e o estado do convite. A resposta preserva `role_change_effective_confirmed=false`: ela não comprova propagação do acesso em todos os produtos. Na remoção, `scheduled_posts_deletion_confirmed=false` permanece porque o documento não devolve os posts excluídos. Uma rejeição embutida ou um resultado incompleto não se transforma em sucesso.

Um replay confirmado retorna a projeção salva sem reexecutar o preflight ou a mutation. A operação precisa do token original ainda presente nesse objeto para construir o mesmo comando. Depois de uma nova confirmação que substitua o token, ou depois de reiniciar o processo, use a consulta ao ledger para inspecionar um comando anterior; não crie outra chave apenas para contornar incerteza.

## Consultar pessoas do portfólio

`people($businessId, $providers, $count = 20)` devolve pessoas/convites reconhecidos, cursores e os IDs de tarefas comerciais enviados pelo servidor. `nextPeople()` recebe o cursor devolvido e mantém o mesmo Business ID. O limite por chamada é de 1 a 100.

```php
use MVO\Facebook\Catalog\PageManagementDocuments;
use MVO\Facebook\Service\PageAdministrationService;

function pessoasDoPortfolio(
    PageAdministrationService $admin,
    string $businessId,
    array $providersAtuais,
): array {
    $requeridos = PageManagementDocuments::providers(
        'BusinessCometBizSuiteSettingsBusinessUsersViewContainerQuery',
    );
    // Os nomes acima indicam quais valores precisam vir do contexto atual.
    return $admin->people($businessId, $providersAtuais, 20);
}
```

Providers Relay não são permissões nem constantes universais. O SDK não os obtém automaticamente; a ausência de um provider bloqueia a operação antes do I/O. Não copie o valor `false` de uma fixture de teste para uma conta real. Cada página de resultado é parcial e conserva `exhaustive=false`.

`pageAccess($pageId)` informa se o documento retornou `if_viewer_can_manage_admins` para a Página exata. `permissions($profileId)` consulta as permissões da pessoa conectada sobre um perfil com acesso limitado; não lista todos os administradores.

## Alterar informações e tarefas comerciais

`updateBusinessUser($businessId, $businessUserId, $values, $idempotencyKey)` recebe os **valores atuais completos** de `firstName`, `lastName`, `email`, `business_account_task_ids`, `expireTime` e `clientTimezoneID`. Não é um PATCH implícito que completa campos ausentes com vazio. O timezone dessa mutation é o ID numérico Facebook retornado pelo contexto, e não um offset UTC.

`business_account_task_ids` conserva os IDs exatos do seletor atual. Não transforme nomes como “Administrador” em tarefas inventadas. A projeção valida o Business ID e o Business User ID da resposta. `pending_integrity_review` distingue uma revisão pendente; `role_change_effective_confirmed=false` evita afirmar que a troca de acesso já entrou em vigor.

Antes da escrita, consulte `people()`/`nextPeople()` pelo mesmo objeto do serviço até observar o Business User nesse portfólio. Essa confirmação local de escopo vale por até cinco minutos. Um ID global sem vínculo observado com o Business ID é rejeitado antes da mutation. A seleção não substitui a autorização final do servidor.

## Convidar pessoas e atribuir ativos

`inviteBusinessPeople($businessId, $emails, $context, $idempotencyKey)` recebe de 1 a 100 endereços explícitos. O contexto contém:

| Campo | Conteúdo |
| --- | --- |
| `business_account_task_ids` | Tarefas comerciais exatas escolhidas no contexto atual. |
| `assets` | Lista de `{asset_id, permitted_task_ids}`; pode ser vazia se não houver atribuição. |
| `invite_origin_surface` | Surface atual do convite Web. |
| `expiry_time` | Timestamp atual escolhido ou `null`. |
| `client_timezone_id` | Nome IANA do navegador, por exemplo `America/Sao_Paulo`. |
| `auto_assign_access` | Escolha booleana explícita do fluxo de atribuição automática. |

```php
use MVO\Facebook\Service\PageAdministrationService;

function convidarPessoa(
    PageAdministrationService $admin,
    string $businessId,
    array $contextoAtualDoConvite,
    string $idempotencyKey,
): array {
    return $admin->inviteBusinessPeople(
        $businessId,
        ['pessoa@example.test'],
        $contextoAtualDoConvite,
        $idempotencyKey,
    );
}
```

O resultado conserva os IDs de solicitações de acesso. Quantidade menor que a solicitada gera `partial_invitation_response`; não é autorização para reenviar automaticamente os demais endereços. `access_accepted_confirmed=false` permanece até existir evidência da aceitação. A Meta pode exigir uma nova confirmação de identidade para a mutation. Esse desafio não é contornado pelo SDK e um resultado incerto não é repetido.

## Acesso à Página como ativo do portfólio

`pageAssetAccess($businessId, $pageId, $userId)` consulta o vínculo exato no gerenciador de permissões. Diferencia `BusinessUser`, `BusinessRoleRequest` e `NonBusinessScopedPermittedUser`, com `is_direct_admin`. Ela não devolve uma lista de tarefas que esse documento não seleciona e não comprova propriedade da Página.

`assignPageAssetTasks($businessId, $pageId, $businessUserId, $taskIds, $idempotencyKey)` envia os IDs exatos selecionados para o ativo `PAGE`. Antes de uma tentativa nova, a pessoa precisa ter sido observada em `people()`/`nextPeople()` nesse portfólio há até cinco minutos e a consulta de administração da Página precisa permitir o gerenciamento. Isso conserva o isolamento entre o Business User do portfólio e um ID global de pessoa. A resposta confere o ID da Página e o usuário empresarial; `tasks_effective_confirmed=false` não presume ativação imediata nem descreve essa operação como transferência de propriedade.

`removePageAssetAccess($businessId, $pageId, $userId, $isDirectAdmin, $providers, $idempotencyKey)` usa a ramificação real devolvida pelo contexto. A tentativa nova consulta novamente o vínculo e a permissão antes da mutation. Os providers exigidos estão em `PageAdministrationDocuments::providers('BizKitRemoveUserAssetConnectionMutation')`; seus valores booleanos devem vir do contexto Web atual. A biblioteca não inventa um valor padrão para `BillingHubContextFieldCutover`.

O resultado `page_asset_removal_response_received` preserva `access_removed_confirmed=false`; ele não declara que todos os caminhos indiretos de acesso desapareceram. Se a resposta da ramificação de administrador direto ainda contém o alvo, a operação fica sem confirmação. Desafios adicionais do Business Suite exigem a ação apropriada da pessoa e não provocam repetição automática.

## Banir da Página e bloquear mensagens

`ModerationService` mantém as ações separadas:

| Método | Efeito solicitado |
| --- | --- |
| `banUser($pageId, $userId, $idempotencyKey)` | Banimento pelo contrato `user_ban_biz_inbox`, com a origem Web observada. |
| `unbanUser($pageId, $userId, $idempotencyKey)` | Desfazer esse banimento pelo contrato `user_unblock`. |
| `setMessagesBlocked($pageId, $userId, $blocked, $idempotencyKey)` | Definir explicitamente o bloqueio de mensagens; `true` bloqueia e `false` desbloqueia. |

As escritas usam o actor Página e a mesma pessoa dona da sessão. Não transformam um bloqueio de mensagens em banimento da Página e não alternam a partir de um booleano local possivelmente antigo. A resposta de mensagens precisa devolver a pessoa exata e `is_message_blocked_by_viewer` igual ao solicitado. Os contratos de banimento devolvem somente o ID, por isso conservam `current_state_confirmed=false` e o status `ban_response_received`/`unban_response_received`.

O módulo público de denúncia abre uma tela CIX/FRX com conteúdo e ações escolhidos pelo servidor. O contrato do envio final e sua confirmação ainda não foram estabelecidos. Não há método fictício que apenas abra a tela e declare a denúncia enviada; `availability()` mantém `submit_report=false`. A enumeração dos usuários banidos também continua indisponível.

## Remover o próprio acesso limitado

O fluxo observado exige confirmação de senha. `reauthenticationKey()` consulta a chave pública atual da pessoa autenticada; `reauthenticateEncrypted()` envia um envelope `#PWD_BROWSER` já cifrado. A classe `MVO\Messenger\Auth\PasswordEncryption` produz esse envelope usando os valores retornados. Credenciais devem permanecer fora de logs, exemplos compartilhados e contexto de modelos.

O mesmo objeto do serviço conserva uma confirmação com validade local de até cinco minutos. O timestamp salvo por uma resposta confirmada é preservado no replay; uma chamada antiga no ledger não renova essa janela. Depois, `removeMyLimitedAccess($profileId, $idempotencyKey)` remove somente o acesso limitado da própria conta. Ele não recebe um administrador arbitrário como alvo.

## Cobertura e limites

Os contratos têm testes sintéticos de isolamento, projeção, idempotência e frescor da confirmação. Não houve convite, remoção ou alteração de cargo em conta real nesta validação. `availability()` diferencia os métodos disponíveis das lacunas.

Ainda não há transferência de propriedade, edição de `CUSTOM`, cobertura de todos os modelos modernos de acesso, envio final de denúncias ou enumeração de pessoas banidas. O enum público contém nomes relacionados a propriedade, mas um nome de enum não estabelece quem deve ser o alvo nem quais pré-condições e confirmações são necessárias; essas operações não foram ativadas. Uma lista de pessoas no Business Suite não equivale a cargos nativos. Consulte [Páginas](PAGINAS.md) e [insights](INSIGHTS.md).
