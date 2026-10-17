# Extensões do protocolo na LIB MVO

Esta implementação utiliza o schema integrado `2.3000.1050588369`. Os métodos usam a sessão existente. A API aplica autenticação por instância, validação antes da fila e os mesmos contratos de jobs e erros das operações anteriores.

## CRM e sincronização

```php
$features = $client->stateFeatures();
$anuncios = $features->listCtwaPromotions(null, 100);
$privacidade = $features->getAcp2('5511999999999@s.whatsapp.net');
$capacidade = $features->contactRefreshCapabilities();
if (!$client->isPrimary() && $capacidade['refreshSupported'] === true) {
    $pedido = $features->requestContactRefresh();
    $estado = $features->getContactRefresh($pedido['requestId']);
}
$inventario = $features->omittedConversations();
```

CTWA reúne dados de origem e promoção presentes em mensagens autenticadas recebidas. Não atribui campanhas a conversas sem essa evidência. O histórico de promoções é separado por sessão e conversa. Dados comerciais do cliente, sublistas, aniversário, visibilidade no gerenciador e configuração sincronizada do histórico de grupos possuem operações próprias em `businessTools()`.

A atualização de contatos é solicitada por uma sessão companheira ao celular principal, somente quando ele anuncia a capacidade. O resultado inicialmente pode ser `server_ack` ou `outcome_unknown`; `completed` depende de uma resposta autenticada do aparelho principal, correlacionada ao ID. A consulta expira tentativas sem resposta após 24 horas. Um ACK não prova que a agenda foi atualizada.

O inventário de conversas omitidas preserva o indicador recebido na sincronização. `recoverConversation($inventoryId, $count)` solicita histórico de uma conversa desse inventário; a confirmação da requisição não equivale a receber as mensagens. O estado muda para `received` somente quando a conversa é efetivamente importada. Não recupera automaticamente conteúdo apagado.

ACP2 informa o estado observado de uma conversa restrita. O cliente público analisado apresenta a alteração somente no celular principal, com o controle Web desabilitado. A projeção informa `configurationSupported=false`; não há um setter que simula sucesso. O estado pode restringir aparelhos vinculados. A observação dessa configuração não comprova que seu efeito foi testado nesta conta.

## Enquetes com imagens em canais

```php
$resultado = $client->newsletter()->sendImagePoll('123456789@newsletter', [
    'name' => 'Qual imagem você prefere?',
    'selectableCount' => 1,
    'options' => [
        ['name' => 'Primeira', 'media' => ['path' => '/app/primeira.jpg']],
        ['name' => 'Segunda', 'media' => ['path' => '/app/segunda.jpg']],
    ],
]);
```

O produtor público encontrado é específico de canais e depende de habilitação no cliente WhatsApp. Todas as imagens são preparadas antes de publicar a enquete. Depois são enviadas como mensagens associadas ao ID real da enquete. `optionHash` vincula cada imagem à alternativa; o token de voto continua sendo o hash do nome da alternativa.

O resultado contém `parent`, `parentKey`, `messages`, `complete` e `sentCount`. `sentCount` conta somente as imagens associadas com ACK; `complete` indica que o pai e todas as imagens receberam confirmação do servidor, sem comprovar apresentação ou voto no aplicativo. Em falha parcial, preserve `failure.messageId` e os IDs anteriores. Nunca reenvie a enquete inteira automaticamente: o pai ou alguns filhos podem ter sido aceitos.

## Eventos com capa e convites de chamadas

```php
$evento = $client->sendMessage($chat, [
    'type' => 'event',
    'name' => 'Apresentação MVO',
    'description' => 'Conheça a integração.',
    'startTime' => time() + 3600,
    'endTime' => time() + 7200,
    'cover' => ['media' => ['path' => '/app/capa.jpg'], 'mimetype' => 'image/jpeg'],
]);
$convite = $client->sendMessage($chat, [
    'type' => 'scheduled-call',
    'title' => 'Reunião MVO',
    'scheduledTimestampMs' => (time() + 3600) * 1000,
    'callType' => 'video',
]);
```

A capa é uma imagem criptografada associada ao evento por `EVENT_COVER_IMAGE`, seguindo o produtor Android observado. O upload é preparado antes de publicar o evento. O resultado composto preserva os IDs do evento e da capa e informa falha parcial; não repita o lote inteiro automaticamente.

O convite de chamada usa o horário em milissegundos Unix e aceita `callType=voice` ou `video`. Ele envia um convite; não inicia uma chamada nem agenda automaticamente uma conexão de áudio/vídeo. O cancelamento usa `type=scheduled-call-cancel` e `target.id` e exige um convite próprio previamente observado na mesma conversa e sessão. O transporte e as cópias para os aparelhos da conta são testados com Signal; isso não substitui a validação visual no WhatsApp real.

## Agendamento local de publicações em canais

```php
$agendador = $client->channelSchedules();
$agendamento = $agendador->schedule('123456789@newsletter', [
    'type' => 'text',
    'text' => 'Publicação agendada pela MVO.',
], time() + 3600);
$consulta = $agendador->get('123456789@newsletter', $agendamento['id']);
// Antes de iniciar a publicação:
$agendador->reschedule('123456789@newsletter', $agendamento['id'], time() + 7200);
$agendador->cancel('123456789@newsletter', $agendamento['id']);
```

O mecanismo é explicitamente `mvo_local`: o worker PHP precisa estar conectado para publicar. A fila persiste após reinício, aceita texto e enquetes com alternativas textuais, e permite horários futuros em segundos Unix até 30 dias. Sem conexão, a publicação aguarda o worker. São retidos até mil registros por canal, incluindo resultados e cancelamentos.

Os estados são `queued`, `publishing`, `server_ack`, `unknown`, `failed` e `canceled`. `server_ack` indica somente aceite pelo servidor. Uma interrupção durante a publicação mantém o ID reservado e vira `unknown` na recuperação; não há reenvio automático. Somente registros ainda `queued` podem ser reagendados ou cancelados. A aplicação recebe o evento `channel_schedule` com o resultado persistido da tentativa.

A API verifica acesso, geração e estado da instância novamente no momento da publicação, incluindo durante sua preparação. Suspensão ou exclusão bloqueiam novos envios agendados. Na integração direta com a LIB, configure `setChannelScheduleGuard($guard)` com uma função que lance uma exceção quando sua aplicação não autorizar a publicação. Uma recusa comprovadamente anterior à escrita mantém o registro `queued`; depois de uma possível escrita, o resultado permanece `unknown` e exige conferência. Os horários persistidos usam o relógio Unix, separado dos prazos monotônicos de conexão.

Esse mecanismo não ativa o agendamento nativo do WhatsApp. O Web público analisado exclui canais do seu agendamento nativo de mensagens. `newsletterScheduledMessage` e `ScheduledMessageMetadata` são preservados para observação; a existência desses campos não demonstra um comando de criação disponível. `newsletter()->listObservedSchedules($jid)` retorna metadados observados, sem chaves de revelação e sem interpretar a unidade de `scheduledTime` como uma data de execução garantida.

## Status interativos recebidos

```php
$capacidades = \MVO\Service\StatusService::interactiveCapabilities();
$enquetes = $client->status()->listInteractiveStatuses([
    'type' => 'poll',
    'limit' => 100,
    'includeExpired' => false,
    'includeRevoked' => false,
]);
$statusDoGrupo = $client->status()->listInteractiveStatuses([
    'type' => 'group-status',
    'groupJid' => '120363000000000000@g.us',
    'limit' => 100,
]);
```

A consulta utiliza somente conteúdo recebido e autenticado no histórico desta sessão. Aceita `type=all|poll|add-yours|group-status`, `author`, `limit`, `beforeTimestamp`, `includeExpired` e `includeRevoked`. Status de grupos exigem `groupJid` explícito e preservam a conversa e o autor reais; não recebem artificialmente a validade ou o estado de visualização de `status@broadcast`.

Os eventos normalizam `statusInteraction`, incluindo referências à publicação e à resposta quando presentes. A notificação nativa `STATUS_ADD_YOURS` identifica uma resposta, e não a criação de um convite. As capacidades mantêm `publish=false` para enquetes de Status e convites Add Yours. Status de grupos possuem um caminho próprio de publicação: `status()->sendToGroups()`, com envio direto ao JID do grupo, envelope `groupStatusMessageV2` e metadado `is_group_status`. A biblioteca verifica participação e restrições do grupo e usa seu transporte criptografado de Sender Keys. Um nome e emoji de público são metadados de apresentação; os destinatários continuam sendo os participantes do grupo. Os métodos de publicação já existentes para texto, mídia e outros Status mantêm seus próprios contratos. Consulte o [registro das evidências e limites](ADVANCED-MESSAGES-PROTOCOL-EVIDENCE.json).

## Publicar Status em grupos

```php
$resultado = $client->status()->sendToGroups(
    ['120363000000000000@g.us', '120363000000000001@g.us'],
    [
        'type' => 'text',
        'text' => 'Atualização para a equipe MVO',
        'backgroundColor' => '#0000AA',
        'textColor' => '#FFFFFF',
        'font' => 0,
    ],
    ['listName' => 'Equipe MVO', 'listEmoji' => '📢'],
);
```

Cada grupo recebe uma publicação independente e um ID próprio. Confira os resultados individuais antes de repetir: uma falha no segundo grupo não desfaz o primeiro envio. `server_ack` confirma aceite pelo servidor; não confirma visualização. A implementação não depende do relay externo de personalização por participante do exemplo de referência e não converte silenciosamente o Status em mensagem comum para outros clientes.

## Validação e integração

Prepare a mídia nos formatos aceitos pela biblioteca, descritos em [MIDIA.md](MIDIA.md). Uma integração HTTP deve validar suas próprias entradas antes de chamar os métodos PHP e manter segredos de sessão fora das respostas públicas.

Testes locais exercitam serialização, transporte simulado, persistência, reinício, falhas parciais, correlação e contratos. A apresentação visual e disponibilidade de recursos condicionais precisam de validação nos clientes WhatsApp e nas contas que os utilizarão. Nenhum enum do protobuf é tratado, por si só, como confirmação de entrega ou de recurso habilitado.
