# Sessão persistente e mensagens

O cliente mantém credenciais, identidades, pré-chaves, sessões Signal e ratchets em `.auth/<sessao>/state.sqlite`. Parar o processo encerra o socket, mas mantém o vínculo e o estado criptográfico. Ao iniciar novamente com o mesmo nome de sessão, o cliente faz login sem QR enquanto o WhatsApp continuar reconhecendo esse aparelho.

## Manter o processo ativo

```powershell
composer worker -- --session=default
```

O worker continua ativo até Ctrl+C ou uma falha definitiva. O antigo limite padrão de 180 segundos foi removido. `--timeout=60` continua disponível para uma execução temporária; `--timeout=0` significa contínuo.

Depois de Ctrl+C, execute o mesmo comando para retomar. O cliente usa keepalive após o login e reconecta falhas transitórias com espera exponencial e jitter, limitada a 60 segundos entre tentativas. O lock da sessão permanece reservado durante a reconexão. A biblioteca não executa com a aplicação fechada; ela restaura o vínculo quando o processo volta a iniciar.

Para consultar a sessão sem interromper o worker:

```powershell
composer session:status -- --session=default
```

O diagnóstico informa vínculo, uso do lock e upload das pré-chaves, sem mostrar chaves privadas.

## Enviar pelo terminal

Com o worker aberto, use outro terminal:

```powershell
composer message:send -- --session=default --to=5511999999999 --text="Olá pelo PHP"
```

Substitua o destino pelo número desejado, com DDI/DDD. Se o worker está usando a sessão, o comando é entregue a ele por uma fila local privada: não é aberta uma segunda conexão concorrente. Se não há worker, o comando abre uma conexão temporária, envia e encerra mantendo as credenciais.

Para texto com múltiplas linhas, use arquivo UTF-8:

```powershell
composer message:send -- --session=default --to=5511999999999 --text-file=mensagem.txt
```

O resultado `server_ack` confirma que o servidor aceitou aquele ID. Não significa que o destinatário recebeu ou leu; esses estados chegam pelo evento `message_receipt`. Não há retransmissão automática de envios incertos para evitar duplicatas. O código persistido na outbox permite `retryMessage($id)`, explicitamente, reutilizando o mesmo ID e ciphertext.

Os recibos enviados pelo próprio protocolo usam uma fila separada: o nó exato e
seu prazo de retry são persistidos antes do transporte. Uma escrita concluída
remove o recibo da fila, sem aguardar um ACK de recibo que pode não chegar.
Falhas de escrita preservam o registro para nova tentativa; um ACK correspondente
também pode encerrar um registro ainda pendente. O dono da sessão reconstrói índices locais por páginas ao
retomar, encontra confirmações além da primeira página e consulta novamente o
registro antes de retransmitir. Enquanto os prazos não vencem, cada `poll()` evita
reler e decodificar toda essa fila. Os retries têm orçamento por ciclo; recibos
novos continuam sendo enviados imediatamente. Essa fila não autoriza reenvio de
mensagens de usuário nem confirma entrega ou leitura dessas mensagens. Completar
a escrita de um recibo não muda o significado de `server_ack` nem dos recibos
recebidos do contato.

Uma cópia autenticada de mensagem enviada por outro aparelho da própria conta
usa recibo `sender`. Em conversas individuais, o recibo conserva também
`recipient`, com a conversa de destino: sincronizar uma cópia entre seus
aparelhos não é uma entrega ao contato. Mensagens de outras contas continuam
usando o recibo de entrega comum; mensagens de protocolo `peer` usam `peer_msg`.

## Enviar para username

Com a sessão conectada, `sendText()` e `sendMessage()` também aceitam usernames do WhatsApp:

```php
$result = $client->sendText('cliente.exemplo', 'Olá pela MVO PHP!');
// Também aceita @cliente.exemplo e, quando exigido, cliente.exemplo:1234.
```

`$client->resolveRecipient('cliente.exemplo')` consulta o WhatsApp pela sessão atual e retorna o JID real do destinatário. O envio faz essa consulta automaticamente; não concatene `@lid` ou `@s.whatsapp.net` ao username. A consulta é renovada em cada envio, sem um cache permanente que possa apontar para um proprietário antigo do nome.

A mesma entrada funciona em `composer message:send -- --session=default --to=cliente.exemplo --text="Olá"`. Se o contato exigir uma chave de quatro dígitos, informe o username seguido de `:1234`, usando a chave real. `RecipientResolutionException::status` distingue `not-found`, `key-required` e `invalid-result`; nenhuma dessas falhas inicia envio. `ProfileService::parseUsername()` e `RecipientResolver::normalize()` validam a entrada sem consultar a rede. O envio direto por `MessageService` depende do resolver configurado pelo `WaClient`.

Se um comando ao worker atingir timeout, consulte o ID local mostrado no início antes de repetir:

```powershell
composer message:send -- --session=default --status=ID_DO_COMANDO_LOCAL
```

Comandos ainda não iniciados expiram no prazo solicitado. Um comando encontrado em processamento após uma queda permanece com resultado desconhecido e não é enviado novamente automaticamente. O ID local do comando é diferente do ID da mensagem WhatsApp.

## Receber no terminal

O worker mostra o tipo e o resumo do conteúdo recebido depois da validação criptográfica, com remetente e horário. O evento `message` preserva `data`, `proto`, contexto e o descritor de mídia; `message_protocol` informa ações de protocolo. Falhas de descriptografia geram `message_error` e pedido limitado de retry. Veja [TIPOS-MENSAGENS.md](TIPOS-MENSAGENS.md) e [MIDIA.md](MIDIA.md) para os formatos e exemplos.

As atualizações do ratchet e o evento de entrada pendente são gravados na mesma transação. Se a aplicação cair antes de concluir o callback, o evento pendente pode ser reemitido com `replayed=true` após o reinício. Trate ações externas de forma idempotente pelo par `key.remoteJid`/`id`. Depois do callback concluído, o texto temporário do evento é descartado e fica um marcador de deduplicação; isso não é um arquivo completo do histórico de conversas.

## API PHP

```php
<?php
require 'vendor/autoload.php';

use MVO\WaClient;

$client = new WaClient('default');
$client->on('message', function (array $event) {
    echo "{$event['from']}: {$event['text']}\n";
    // Use o ID do evento para evitar repetir ações externas em caso de replay.
});
$client->on('message_receipt', function (array $event) {
    echo "{$event['id']}: {$event['type']}\n";
});
$client->on('reconnect', function (array $event) {
    echo "Reconectando em {$event['delay']}s\n";
});
$client->on('logged_out', function (array $event) {
    echo "Vinculo recusado: {$event['reason']}\n";
});

try {
    $client->connect();
    while ($client->isRunning()) {
        $client->poll(0.5);
        // Quando isConnected() retornar true, sua aplicação pode chamar:
        // $result = $client->sendText('5511999999999', 'Olá');
        // $status = $client->getMessageStatus($result['id']);
    }
} finally {
    $client->disconnect();
}
```

`run()` é uma alternativa ao loop manual e mantém a conexão por tempo indeterminado. `disconnect()` e `stop()` encerram deliberadamente o processo lógico (`stopped`), liberando lock e SQLite. Não desvinculam a conta no celular. Falhas transitórias deixam o cliente em `reconnecting`; `401` e remoção do dispositivo deixam `logged_out`, preservando dados e impedindo loops de autenticação rejeitada.

## Aparelho removido no celular

Nenhuma biblioteca consegue restaurar um vínculo que o WhatsApp removeu. A sessão `default` anterior foi recusada com `401`, e o usuário confirmou que o aparelho não aparecia mais no celular. Ela foi arquivada em `.auth-backups/`; o novo vínculo foi criado e o usuário confirmou a retomada sem QR.

Para uma futura sessão marcada como revogada:

```powershell
composer session:relink -- --session=default
composer worker -- --session=default
```

O primeiro comando arquiva toda a pasta antiga e não sobrescreve vínculos válidos. Depois é necessário escanear o novo QR uma vez. Os backups contêm credenciais privadas e ficam ignorados pelo Git, junto com `.auth`.

## Cobertura atual

- `sendMessage` com texto, mídia, contatos, localização, reações, edição/exclusão, fixação/manutenção, enquetes/votos, eventos/respostas e conteúdo genérico WAProto.
- Conversas individuais PN/LID e grupos, descoberta de aparelhos e fanout para outros aparelhos da própria conta.
- Signal v3 com X3DH, Double Ratchet, `pkmsg`/`msg`, assinaturas, MACs, chaves fora de ordem e proteção contra replay/troca silenciosa de identidade.
- Upload de pré-chaves persistidas antes da publicação, reposição quando o servidor informa estoque baixo, ACKs e recibos.
- Outbox atômica com ciphertext/ID e inbox pendente recuperável.
- Sender Keys de grupos, distribuição autenticada, rotação após alteração de membros/aparelhos e persistência.
- Upload/download integrado de mídia com verificação de MAC/hashes, sidecars, pacotes de figurinhas e limites de tamanho.
- Schema WAProto completo para serializar/decodificar formatos estruturados, incluindo listas, botões, produtos e interativos via `raw`/`data`.

Histórico, app-state, status, listas de transmissão, canais e retry solicitado pelo destinatário possuem módulos próprios, com pré-condições e limites descritos em [PARIDADE.md](PARIDADE.md). Chamadas e mídia nativa são opcionais e ainda não tiveram validação em uma chamada WhatsApp real. O envio de mídia em memória tem limite padrão de 32 MiB; as APIs de stream usam arquivos temporários e memória limitada. Conversão de codecs exige configurar o `MediaProcessor` e os executáveis FFmpeg/ffprobe. Não há API HTTP pública de gerenciamento de sessões; o endpoint MCP opcional tem escopo próprio.

A validação local inclui comunicação Signal/Sender Keys entre partes, reinício do banco, mensagens fora de ordem, replay, adulteração, fanout e ACK, recuperação de callback, reconexão, transferência HTTP simulada e vetores criptográficos independentes. O vínculo e a retomada sem QR foram confirmados pelo usuário, que também relatou funcionamento de mensagens. Em testes posteriores autorizados, foram enviados botões, lista e imagem ao destino informado; os botões de resposta rápida foram confirmados no celular e no navegador após os ajustes de metadados, contexto e payload. Veja [COMPATIBILIDADE-BOTOES.md](COMPATIBILIDADE-BOTOES.md) para os formatos validados e as verificações restantes.
