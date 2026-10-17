# Sincronização do estado da conta em PHP

`MVO\AppState\AppStateService` implementa o protocolo `w:sync:app:state` das cinco coleções `critical_unblock_low`, `critical_block`, `regular_low`, `regular` e `regular_high`. As chaves e o estado das coleções ficam no `SignalStore` da sessão. Reiniciar o processo conserva as versões, o LT-hash, os MACs dos índices e as ações ainda pendentes.

As chaves chegam do aparelho principal em mensagens `appStateSyncKeyShare`. O consumidor deve encaminhar somente mensagens já autenticadas pelo Signal e identificadas como pertencentes à própria conta. `handleProtocol()` exige esse contexto e pode validar novamente o remetente com o callback `ownDevice`. O handler importa as chaves e sinaliza a necessidade de sincronização; ele não executa requisições enquanto processa uma mensagem recebida.

`WaClient` já conecta esse fluxo ao loop: solicita as chaves ausentes dos próprios aparelhos, importa respostas autenticadas e sincroniza sem relink. A validação real da sessão `default` autenticou as cinco coleções, preservando a identidade existente. Essa validação confirma o bootstrap; alterações individuais, como excluir ou arquivar conversas, não foram executadas na conta durante o desenvolvimento.

## Operações

```php
$state->sync();                         // Consulta as cinco coleções.
$state->setChatArchive($jid, true);
$state->setChatMute($jid, true, (int) (microtime(true) * 1000) + 86400000);
$state->setChatPin($jid, true);
$state->setChatRead($jid, true);         // Estado de leitura; recibo é uma API separada.
$state->setMessageStar(['chatJid' => $jid, 'id' => $id, 'fromMe' => false], true);
$state->deleteMessageForMe(['chatJid' => $jid, 'id' => $id, 'fromMe' => false]);
$state->setChatLabel($jid, '12', true);
$state->setMessageLabel($jid, $id, '12', true);
$state->editLabel('12', ['name' => 'Clientes', 'color' => 3]);
$state->setContact($jid, ['fullName' => 'Cliente', 'firstName' => 'Cliente']);
```

Também estão disponíveis `setChatLock`, `clearChat`, `deleteChat`, `setPushName`, `setStatusPrivacy`, `setUserStatusMute`, `setQuickReply`, `setBroadcastList` e `removeBroadcastList`. Fixar uma conversa remove seu arquivamento; arquivá-la remove sua fixação. Bloqueá-la remove os dois estados. Excluir uma mensagem para si não a revoga para os outros participantes. Para ações sobre mensagens recebidas em grupos que usam um identificador com participante, informe `participantJid`.

`AppStateSchema::all()` lista 67 ações: as 66 especificações do snapshot de referência e a extensão verificada `LabelMessage`. Essa extensão usa `labelAssociationAction`, coleção `regular`, versão `3` e índice `["label_message", labelId, chatJid, messageId, "0", "0"]`. `mutate()` separa os argumentos do índice dos dados da ação. A serialização protobuf valida os campos e aceita nomes dos enums quando o campo correspondente é um enum.

`$client->businessTools()` oferece uma interface validada para etiquetas, respostas rápidas e listas comerciais, com consultas à projeção local. A exclusão de etiqueta remove associações em conversas e mensagens; as respostas rápidas são consultadas por ID, percorrendo todos os registros autenticados, inclusive em armazenamentos antigos. Veja [WhatsApp Business](BUSINESS.md).

```php
// Agrupa ações sem iniciar uma requisição para cada uma.
$state->mutate('LabelEdit', ['id' => '12'], ['name' => 'Clientes', 'color' => 3], false, false);
$state->mutate('LabelJid', ['labelId' => '12', 'chatJid' => $jid], ['labeled' => true], false, false);
$state->flushMutations();

// Remove um registro de uma coleção, preservando o protocolo autenticado.
$state->mutate('LabelJid', ['labelId' => '12', 'chatJid' => $jid], null, true);
```

## Persistência e erros

Cada ação entra na fila durável antes da comunicação. A fila compacta ações repetidas sobre o mesmo índice, mantendo a última. A sincronização primeiro consulta o servidor e depois envia o patch na versão correta. Se a resposta se perder, a ação permanece pendente; o próximo ciclo consulta os patches autenticados do servidor e reconhece a ação já aceita antes de tentar outra alteração. O estado local avança apenas após validar a resposta.

`flushMutations()` lança uma exceção se restarem ações pendentes. `sync()` devolve, por coleção, `success`, `success_has_more`, `conflict`, `blocked` ou `error_retry`, a versão e as mutações aplicadas. Um conflito de versão provoca uma consulta e nova preparação do patch com o estado atualizado. Não limpe as chaves ou as coleções para esconder um erro.

O callback de eventos recebe `appstate_keys`, `appstate_mutation`, `appstate_missing_keys`, `appstate_key_share_requested` e `appstate_error`. Pedidos de chaves e respostas aos próprios aparelhos são sinalizados para envio posterior, por mensagem Signal, fora do handler de leitura. `needsSync()` e `requestSync()` permitem coordenar esse trabalho no loop da aplicação.

## Criptografia e integração

O PHP deriva cinco chaves com HKDF-SHA256 (`WhatsApp Mutation Keys`), cifra `SyncActionData` com AES-256-CBC e verifica o MAC SHA-512 truncado de cada valor, o HMAC do índice, o MAC do patch e o MAC do snapshot. O LT-hash usa 64 pontos little-endian de 16 bits e HKDF com `WhatsApp Patch Integrity`. Ao contrário dos caminhos tolerantes presentes no código de referência, esta implementação rejeita um MAC de snapshot ou patch inválido e mantém a última coleção autenticada.

Snapshots e patches externos usam o callback de download com `mediaType=md-app-state`; o `MediaTransfer` da biblioteca já deriva `WhatsApp App State Keys` e autentica as mídias. O callback de intervalo de mensagens fornece `SyncActionMessageRange` para arquivar, marcar leitura, limpar ou excluir conversas. Quando a aplicação não fornece esse callback, o intervalo fica vazio, como em um armazenamento sem histórico; para modificar conversas com histórico, integre o armazenamento de mensagens.

Construtor:

```php
$state = new AppStateService(
    $signalStore,
    fn (BinaryNode $node, float $timeout) => $client->query($node, $timeout),
    fn (string $event, array $data) => $events->emit($event, $data),
    fn (array $descriptor) => $mediaTransfer->download($descriptor),
    fn (string $sender) => $isOwnAuthenticatedDevice($sender),
    fn (string $chat) => $messageStore->messageRange($chat),
);
```

Não há geração artificial de chaves para uma sessão companion: o aparelho principal precisa compartilhar suas chaves reais. As verificações locais usam vetor independente produzido com Python `cryptography`, testes de adulteração, troca de chaves, snapshots, substituição/remoção de registros, paginação e recuperação de resposta perdida. Os testes não substituem a validação de cada ação contra a conta conectada.

Projeto de referência: `caeace8d03cd22f24f224c4063edc537522ef936`, `src/appstate`, `src/client/coordinators/WaAppStateMutationCoordinator.ts` e `spec/appstate/index.js`.
