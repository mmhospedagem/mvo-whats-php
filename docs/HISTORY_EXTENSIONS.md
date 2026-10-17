# Histórico, interações e respostas enriquecidas

Os recursos abaixo interpretam conteúdo autenticado recebido pela sessão. O codec Protobuf completo permanece separado dos serviços: ter um campo no schema não concede acesso a histórico remoto, contas Business, catálogos ou recursos ainda não habilitados pelo WhatsApp.

## Sincronização e recuperação

```php
$features = $client->historyFeatures();
$jobs = $features->listSyncs(100);
$job = $features->getSync($jobs[0]['id']);
$client->on('history_sync_chunk', function (array $chunk): void {
    // syncId, state=imported, progress e contagens do chunk importado.
});
$client->on('history_sync_error', function (array $failure): void {
    // syncId, attempt e código seguro; não contém chaves ou respostas privadas.
});
```

Chunks `FULL` agora percorrem a mesma verificação de mídia, expansão limitada e importação transacional dos outros históricos. `NO_HISTORY` e `MESSAGE_ACCESS_STATUS` registram o estado sem tentar baixar um arquivo inexistente. A fila e o registro de progresso persistem após reinício. Uma notificação repetida não reinicia a contagem de tentativas nem importa novamente um chunk já concluído. Erros de expansão, integridade e transferência são expostos por códigos, sem o texto privado da exceção.

`imported` confirma a importação **deste chunk**, não a existência de todo o histórico da conta. Uma requisição usa `prepared`, `server_ack`, `unknown`, `primary_responded` ou `received`. `server_ack` confirma somente o pedido; `primary_responded` preserva o código do celular; `received` exige a chegada e importação de conteúdo correlacionado. Não significa que todos os chunks de uma sessão foram recebidos.

```php
// Somente para um chunk autenticado que falhou e contém os dados necessários.
$retry = $features->retryChunk($failedSyncId, regenerate: true);

// Somente quando syncId contém contexto Business/Coex realmente observado.
$request = $features->requestFullHistory([
    'syncId' => $observedBusinessSyncId,
    'historyDurationDays' => 30,
    'historyFromTimestamp' => strtotime('2026-01-01 UTC'),
]);
```

Esses pedidos são explícitos, feitos por uma sessão companheira ao principal. Há no máximo duas solicitações de regeneração por chunk; uma tentativa anterior sem resultado conhecido bloqueia a próxima. A resposta precisa vir de um dispositivo principal autenticado da própria conta e corresponder ao ID reservado. IDs arbitrários fornecidos pelo cliente HTTP não viram comandos de recuperação.

O pedido completo reaproveita contexto Coex autenticado de até 24 horas; a API não aceita inventar `providerId`, `product` ou `opaqueClientData`. O WhatsApp pode recusar conta não SMB, aparelho hospedado ausente, configuração de vários provedores ou prazo expirado. Nenhum desses métodos recupera automaticamente mensagens apagadas. A confirmação da solicitação não é confirmação de dados importados.

## Chamadas e figurinhas observadas

```php
$calls = $features->listCallLogs(['limit' => 100]);
$favorites = $features->listStickers(['collection' => 'favorite', 'limit' => 100]);
```

O importador preserva `HistorySync.callLogRecords`, `recentStickers` e `favoriteStickers`. Chamadas incluem ID, horário, direção, duração, voz/vídeo, resultado e participantes observados; não são gravações. Figurinhas são identificadas por SHA-256 e coleção, com tamanho, formato e indicadores disponíveis. Consultas não retornam chaves de mídia, caminhos de download nem fazem downloads automáticos. A ausência de um registro local não demonstra que ele não existe no telefone. As coleções têm retenção limitada por sessão: até 5.000 chamadas e 2.000 figurinhas.

## Respostas de Status e lembretes de bots

```php
$answers = $features->listStatusInteractions([
    'messageId' => $statusId,
    'author' => $statusAuthor,
    'limit' => 100,
]);
$reminders = $features->listBotReminders(['state' => 'active', 'limit' => 100]);
```

O índice de interações liga pergunta/resposta, citação, recompartilhamento e sticker de reação à chave da publicação original. Preserva a conversa e o respondente autenticado real, inclusive quando a resposta chega por mensagem individual. Referências de mensagens não comprovam visualização ou acesso à publicação original. A consulta pode ser filtrada por autor, respondente e conversa. São retidas até 2.000 interações.

Lembretes são observações `CREATE`, `UPDATE`, `DELETE` e `NOTIFY` recebidas de bots. Preservam o pedido original, nome, frequência e horário fornecido pelo protocolo, com até 1.000 registros. Uma mensagem comum contendo esses metadados não altera o inventário de bots. Edições mais antigas não substituem o estado mais recente. A biblioteca não cria lembretes nativos da Meta nem interpreta esses registros como uma fila de envios locais.

## Respostas enriquecidas

`richResponse.blocks` inclui imagens, grades de imagens, mapas com anotações, conteúdo dinâmico e listas de reels do formato Protobuf. Cards do formato unificado têm projeções deliberadas de campos públicos conhecidos; novos formatos continuam como `unsupported`, sem extrair strings arbitrárias. `richResponse.sources` e `botSources` trazem fontes e citações disponíveis.

Somente URLs HTTPS sem credenciais são projetadas. URLs locais ou com parâmetros explícitos de autenticação são omitidas. A biblioteca não busca essas URLs nem executa ações de cards ou solicitações de credenciais. Os blocos e fontes possuem orçamento agregado de 2 MiB em JSON UTF-8 compacto, com até 512 blocos e texto limitado a 1 MiB; `truncated` informa corte. Interfaces visuais, fontes remotas e mídia continuam sujeitas à disponibilidade do serviço e dos clientes Android/Web/iOS.

## Integração e limites

Use os serviços PHP de histórico e recuperação apresentados neste guia. Se a aplicação publicar consultas por HTTP, ela define autenticação, paginação e projeção dos dados.

Os testes locais verificam importação FULL, atomicidade, correlação, remetentes indevidos, resultado incerto, limites e referências. Eles não substituem testes reais de compatibilidade do serviço de recuperação ou apresentação de mídia no WhatsApp.

## Cobranças e rateios observados

```php
$payments = $client->historyFeatures()->listPayments([
    'chat' => '120363000000000000@g.us',
    'type' => 'split-payment',
    'limit' => 100,
]);
$payment = $client->historyFeatures()->getPayment($payments[0]['id']);
```

`paymentReminderMessage`, `splitPaymentMessage` e `splitPaymentUpdateMessage` possuem projeção tipada no campo `payment` das mensagens e um índice de até 2.000 registros por sessão. A API oferece somente `GET /payments/observed` e `GET /payments/observed/{payment_id}`. Não cria cobranças ou lembretes nativos e não executa pagamentos.

Valores mantêm `value` como string decimal exata de inteiro de 64 bits, `offset` original e `currencyCode`. A biblioteca não converte esses números para float nem inventa a unidade de `offset`. Valores inválidos são omitidos. Endereços bancários de pagamento e conteúdo de contexto privado não entram na projeção. `reportedStatus=paid` é uma declaração da mensagem; `paymentVerified=false` permanece explícito.

O criador declarado de um rateio precisa corresponder ao remetente autenticado, incluindo vínculos PN/LID já confiados. A criação observada é imutável. Uma atualização exige original único na mesma conversa, participante previsto e remetente correspondente àquele participante. Atualizações desconhecidas, ambíguas ou de terceiros não alteram o registro. `SplitPaymentUpdateMessage` não contém uma confirmação de quitação: sua existência incrementa somente os metadados de observação, sem marcar o participante como pago. Mudanças de um lembrete são limitadas às partes originalmente observadas; parar um lembrete exige a identidade do pagador.

`historyFeatures()->capabilities()['experimental']` distingue schema conhecido, decodificação autenticada, consulta observada e publicação nativa para StatusPoll, Add Yours, agendamento nativo, pagamentos, lembretes de bots e instantImage. Todas essas publicações nativas continuam `false`. O agendador local da MVO é uma funcionalidade separada.

Na análise estática dos artefatos públicos armazenados, o conjunto `status-focus` foi registrado pela REA com Evidence `ev_72fa60e65af80d63eab66ef9424092ad9a3a50ef9eab1ece04a5e3db90599a31`, SHA-256 `a0521cf3f7a5ecf0d3d44fc836fa5aadd9c11d30c9f50d40890a36b778c8afa6`. A pesquisa limitada em 17 bundles armazenados não estabeleceu um produtor publicável de StatusPoll, convite Add Yours ou rateio. Isso não demonstra ausência desses recursos em todas as versões, módulos carregados sob demanda ou contas.

O módulo público `WAWebNativeFlowValidation`, no bundle oficial armazenado de índice 06, exporta `isValidNativeFlowMessage` e rejeita explicitamente `PAYMENT_REMINDER`. Existem parser e ação de interface em `WAWebPaymentReminder`/`WAWebGetInteractiveCtaActions`, mas isso não comprova publicação compatível. Esse caminho interativo `payment_reminder` também é diferente do novo campo Protobuf `paymentReminderMessage`. `WAWebCoexV2SupportedMsgTypes` classifica reminder/split/update como formatos não suportados por aquele fluxo Coex. São limites do código examinado, não resultados de envio real. Nenhum cache bruto desses artefatos é necessário no pacote distribuído.
