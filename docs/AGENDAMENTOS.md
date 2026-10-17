# Agendamento persistente de mensagens

O agendador PHP publica texto, enquete, imagem, vídeo, áudio, voz, documento e figurinha para contatos e grupos. Usa a sessão e a caixa de saída existentes. Ele é local à MVO: não depende da habilitação de agendamento nativo do WhatsApp.

```php
$queue = $client->messageSchedules();
$scheduled = $queue->schedule('5511999999999', [
    'type' => 'text',
    'text' => 'Olá! Seu atendimento está agendado para amanhã.',
], time() + 3600, 'atendimento-001');

$image = $queue->schedule('120363000000000000@g.us', [
    'type' => 'image',
    'media' => '/srv/mvo/campanha.jpg',
    'mimetype' => 'image/jpeg',
    'caption' => 'Conheça a MVO: https://mvo.host',
    'fileName' => 'campanha.jpg',
], time() + 7200, 'campanha-001');

$record = $queue->get($scheduled['id']);
$page = $queue->list(50); // próximo cursor: id do último registro
$queue->reschedule($scheduled['id'], time() + 10800);
$queue->cancel($scheduled['id']);
```

`sendAt` usa segundos Unix, no futuro, com limite de 30 dias. O ID opcional é único por sessão. Repetir o mesmo ID com os mesmos dados retorna o registro existente; dados diferentes geram erro. Cada registro reserva um `messageId` antes do envio.

As mídias são copiadas para o armazenamento da sessão na criação. O arquivo original pode ser removido depois. O registro público contém apenas tamanho, SHA-256 e nome; bytes, caminhos e URLs de origem não aparecem nas consultas. O limite é de 32 MiB por arquivo, 128 MiB de mídia pendente e 1.000 registros ativos por sessão. Registros concluídos/cancelados não ocupam essa cota; o arquivamento explícito remove os payloads antigos e mantém identificadores de idempotência. Não há descarte automático de resultados incertos.

O loop conectado de `WaClient` executa os itens vencidos. Reiniciar restaura a fila; se estiver desconectado no horário, ela será processada quando houver conexão. Parar o cliente interrompe a publicação; sua aplicação deve controlar quando o loop da sessão pode executar. Cancelar e reagendar só é permitido antes da execução. A confirmação transacional revalida horário e estado para respeitar alterações concorrentes.

Estados: `queued`, `publishing`, `server_ack`, `unknown`, `failed`, `canceled` e `skipped`. ACK confirma aceite pelo servidor; entrega e leitura exigem recibos reais. Falha ou reinício após possível transmissão gera `unknown`, preserva o ID e impede repetição automática. Erro comprovadamente anterior à transmissão é informado como `not_executed`.

Use `$client->on('message_schedule', ...)` para acompanhar resultados. Sua aplicação pode encaminhar esses eventos para seus consumidores. Configuração de retenção/backup deve incluir o armazenamento das sessões, que agora contém as cópias das mídias agendadas.

## Integração

O agendador é uma API PHP local. Para expô-lo por HTTP, sua aplicação define autenticação, isolamento entre sessões, validação de mídia e projeções dos resultados. Use os métodos `schedule()`, `list()`, `get()`, `reschedule()` e `cancel()` apresentados acima.

## Edição concorrente, atraso e retenção

Criações aceitam um quinto argumento de política. O padrão `send_late` mantém o comportamento de publicar ao reconectar. `skip_if_late` descarta quando o atraso ultrapassa `maxLatenessSeconds` (300 por padrão); `expiresAt` é um prazo absoluto posterior a `sendAt`. Ambos são conferidos dentro da mesma transação que reivindica o envio, imediatamente antes da publicação. Itens descartados têm estado `skipped`, falha `not_executed` e a mídia liberada.

```php
$row = $queue->schedule('5511999999999', [
    'type' => 'text', 'text' => 'Oferta válida por tempo limitado.',
], time() + 3600, 'oferta-001', [
    'misfirePolicy' => 'skip_if_late',
    'maxLatenessSeconds' => 120,
    'expiresAt' => time() + 7200,
]);

$changed = $queue->edit($row['id'], [
    'content' => [
        'type' => 'document',
        'media' => '/srv/mvo/catalogo.pdf',
        'fileName' => 'catalogo.pdf',
        'mimetype' => 'application/pdf',
        'caption' => 'Confira os planos MVO.',
    ],
    'sendAt' => time() + 5400,
    'expiresAt' => time() + 9000,
], $row['revision']);
```

`edit` exige a revisão lida e somente modifica `queued`; uma disputa retorna `PublicationRevisionConflict`. O arquivo de substituição é copiado antes da troca transacional e não substitui a cópia vigente se a revisão ficou obsoleta. O ID de mensagem permanece reservado. `reschedule` aceita revisão opcional por compatibilidade. Para remover o prazo pelo SDK, envie `expiresAt => null`.

`archiveTerminal(time() - 86400, 100)` remove até 100 payloads terminados (`server_ack`, `failed`, `canceled`, `skipped`) atualizados até o corte. `queued`, `publishing` e `unknown` nunca são arquivados. Tombstones conservam o ID, estado e hash imutável da criação; `get` continua encontrando o resumo com `archived=true`, mas a listagem normal deixa de incluí-lo. Reutilizar esse ID com uma criação diferente continua gerando conflito. Os tombstones fazem parte do backup e não devem ser apagados para liberar espaço. Um replay igual não altera uma edição posterior.

Canais oferecem os mesmos métodos `edit($channelJid, $id, $patch, $revision)` e `archiveTerminal($channelJid, $before, $limit)` para texto/enquete do mecanismo local.

Novas rotas:

| Método | Recurso | Uso |
|---|---|---|
| PATCH | `/schedules/messages/{schedule_id}/content` | Substituir conteúdo/horário/política com `expectedRevision` obrigatório |
| POST | `/schedules/messages/archive` | Arquivar resultados terminados, com `before` e `limit` |
| PATCH | `/channels/{channel_id}/schedules/{schedule_id}/content` | Editar publicação de canal |
| POST | `/channels/{channel_id}/schedules/archive` | Arquivar resultados do canal |

Na API, `clearExpiration=true` remove o prazo; não combine com `expiresAt`. Mídias recebem `url` HTTPS pública ou `base64` canônico, nunca caminhos ou chaves privadas.

## Lotes duráveis de mensagens e Status de grupo

```php
$batch = $client->publicationBatches()->create('group-status', [
    '120363000000001@g.us',
    '120363000000002@g.us',
], [
    'type' => 'image',
    'media' => '/srv/mvo/publicidade.jpg',
    'caption' => 'Sua ideia online com a MVO: https://www.mvo.host',
    'mimetype' => 'image/jpeg',
], 'campanha-mvo-001');

$client->publicationBatches()->pause($batch['id']);
$client->publicationBatches()->resume($batch['id']);
$report = $client->publicationBatches()->export($batch['id'], 'csv');
```

`mode=message` recebe os tipos do agendador. `mode=group-status` recebe os tipos de Status de grupo (texto, imagem, vídeo, áudio e voz/ptt); não recebe `fileName`, que é exclusivo de mensagens. Use de 1 a 100 destinos explícitos, sem duplicatas. A lib não expande listas nem descobre destinatários automaticamente. O worker valida associação/permissões atuais no envio de cada Status. Cada item recebe um ID próprio antes da primeira operação de rede. A cópia durável da mídia é compartilhada entre os itens, não o envio criptográfico.

No máximo 100 lotes ativos e 128 MiB de mídia de lotes pendentes por sessão; cada arquivo tem até 32 MiB. O worker processa um item por passagem e emite `publication_batch`. A primeira rejeição, resultado incerto ou `not_executed` pausa o lote. `resume` é explícito e só habilita itens nunca tentados ou comprovadamente `not_executed`; não repete itens `server_ack`, `rejected` ou `unknown`. `completed` significa que terminou de processar os itens elegíveis; consulte o estado de cada item e os recibos reais para saber o resultado.

Publicações em andamento têm lease de 300 segundos: outro processo não avança o mesmo lote enquanto esse lease está vigente. Após interrupção e expiração, o item torna-se `unknown` e o lote pausa. Cancelar interrompe itens pendentes, sem revogar mensagens já publicadas ou fingir que uma tentativa em andamento não aconteceu. Pausas/cancelamentos concorrentes são preservados quando chega o resultado de uma tentativa em andamento. A idempotência usa o ID do lote e o conteúdo/destinatários imutáveis da criação.

| Método | Recurso | Uso |
|---|---|---|
| POST | `/batches` | Criar com `batch_id`, `mode`, `recipients`, `content` |
| GET | `/batches` | Listar com paginação |
| GET | `/batches/capabilities` | Limites e modos |
| GET | `/batches/{batch_id}` | Progresso e resultados por item |
| POST | `/batches/{batch_id}/pause` | Pausar novas tentativas |
| POST | `/batches/{batch_id}/resume` | Retomar somente itens seguros |
| DELETE | `/batches/{batch_id}` | Cancelar trabalho pendente |
| GET | `/batches/{batch_id}/export?format=csv` | Relatório CSV ou JSON |

Exportações HTTP retornam `format`, `mimeType` e `content` no envelope JSON. CSV neutraliza prefixos de fórmulas de planilha. As consultas e exportações omitem bytes, origem da mídia, tokens de claim e chaves criptográficas. Isso é um fluxo local de envio, independente de campanhas pagas do WhatsApp Business.
