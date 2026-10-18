# Tipos de mensagem em PHP

`WaClient::sendMessage($destino, $conteudo)` é a entrada comum para texto, mídia, ações e mensagens estruturadas. Usa a mesma sessão persistente, Signal, inbox/outbox e fila do worker. Aceita telefone com DDI/DDD, JID PN/LID e JID de grupo `...@g.us`. O runtime permanece inteiramente PHP.

## Formatos disponíveis

| `type` | Conteúdo |
|---|---|
| `text` ou string | Texto, resposta citada, menções, encaminhamento e prévia de link fornecida |
| `image`, `video`, `gif`, `ptv` | Foto, vídeo, GIF em MP4 e vídeo circular |
| `audio`, `ptt` | Áudio e mensagem de voz |
| `document`, `sticker`, `sticker-pack` | Documento, figurinha e pacote de figurinhas |
| `contact`, `contacts` | Um contato vCard ou vários contatos |
| `location`, `live-location` | Localização e atualização de localização ao vivo |
| `reaction` | Reagir; emoji vazio remove a reação |
| `edit`, `revoke` | Editar e excluir para todos, conforme permissões do WhatsApp |
| `pin`, `unpin`, `keep`, `unkeep` | Fixar/desafixar e manter/desfazer manutenção |
| `poll`, `poll-vote` | Criar enquete e votar; seleção vazia retira o voto |
| `event`, `event-response` | Criar evento e responder presença |
| `list`, `buttons`, `interactive` | Listas, botões e fluxos interativos com `data` no formato WAProto |
| `flow`, `flow-navigate`, `flow-exchange` | WhatsApp Flows por ID, navegação ou troca de dados; veja [FLOWS.md](FLOWS.md) |
| `form` | Formulários nativos sem registro na Meta: opções, texto livre portável com `cancel_option` explícito e componente `InputFields` no modo Android; limites e validação em [FORMULARIOS.md](FORMULARIOS.md) |
| `legacy-buttons` | Envelope antigo `buttonsMessage`, disponível explicitamente |
| `list-response`, `buttons-response`, `interactive-response` | Respostas estruturadas |
| `product`, `order`, `template`, `group-invite` e demais campos WAProto | Corpo estruturado em `data` ou envelope `raw` |
| `raw` | Qualquer campo de `WAProto.Message` do schema importado, preservando campos desconhecidos no recebimento |

O codec usa `resources/WAProto.schema.json`, atualizado e conferido com os módulos públicos do WhatsApp Web `2.3000.1050588369`: 771 tipos de mensagem e 226 enums, incluindo submensagens e definições históricas preservadas. Os 39 grupos `oneof` respeitam suas alternativas na escrita e leitura. Veja [versão, novidades e compatibilidade do Protobuf](PROTOBUF.md).

Valores `uint64` que excedem `PHP_INT_MAX` usam strings decimais; bytes usam strings binárias PHP. A serialização do schema não garante que todos os formatos sejam aceitos: cada um depende dos requisitos de conteúdo, conta e versão do servidor. Chamadas de voz/vídeo usam outro protocolo.

## Usar no cliente conectado

Os exemplos seguintes pressupõem `$client->isConnected() === true`. Veja [MENSAGENS.md](MENSAGENS.md) para o loop de conexão.

```php
$to = '5511999999999';
$sent = $client->sendMessage($to, ['type'=>'text', 'text'=>'Olá pelo PHP']);
$target = ['id'=>$sent['id'], 'fromMe'=>true];

$client->sendMessage($to, ['type'=>'reaction', 'target'=>$target, 'emoji'=>'👍']);
$client->sendMessage($to, ['type'=>'edit', 'target'=>$target, 'text'=>'Texto corrigido']);
$client->sendMessage($to, ['type'=>'pin', 'target'=>$target, 'durationSecs'=>86400]);
$client->sendMessage($to, ['type'=>'unpin', 'target'=>$target]);
$client->sendMessage($to, ['type'=>'keep', 'target'=>$target]);
$client->sendMessage($to, ['type'=>'unkeep', 'target'=>$target]);
$client->sendMessage($to, ['type'=>'revoke', 'target'=>$target]);
```

Para agir sobre mensagem de outra pessoa em grupo, inclua `participant` na chave de destino. O WhatsApp valida administração, propriedade e prazos das ações.

```php
$client->sendMessage($to, [
    'type'=>'contact', 'displayName'=>'Contato',
    'vcard'=>"BEGIN:VCARD\nVERSION:3.0\nFN:Contato\nTEL;type=CELL;waid=5511999999999:+5511999999999\nEND:VCARD",
]);
$client->sendMessage($to, [
    'type'=>'location', 'latitude'=>-23.5505, 'longitude'=>-46.6333,
    'name'=>'São Paulo', 'address'=>'São Paulo, SP',
]);
```

`live-location` codifica uma atualização com coordenadas, `sequenceNumber` e `timeOffset`. A aplicação precisa fornecer as atualizações; a biblioteca não acompanha GPS do aparelho nem agenda transmissões automaticamente.

## Responder, mencionar e encaminhar

```php
$client->sendMessage($to, [
    'type'=>'text', 'text'=>'Resposta',
    'quote'=>['key'=>$received['key'], 'proto'=>$received['proto']],
    'mentions'=>['5511999999999@s.whatsapp.net'],
]);
$client->sendMessage($to, ['type'=>'raw', 'message'=>$received['proto'], 'forward'=>true]);
$client->sendMessage($to, [
    'type'=>'text', 'text'=>'https://example.com',
    'linkPreview'=>['matchedText'=>'https://example.com', 'title'=>'Exemplo', 'description'=>'Descrição'],
]);
```

Não há coleta automática da prévia. `contextInfo` aceita os campos do schema e aliases como `quotedMessageId`, `quotedParticipant` e `mentionedJids`. `ephemeralExpiration` coloca a expiração no contexto da mensagem; não altera sozinho a configuração global da conversa.

## Enquetes e eventos

```php
$poll = $client->sendMessage($to, [
    'type'=>'poll', 'name'=>'Qual dia?', 'options'=>['Hoje', 'Amanhã'], 'selectableCount'=>1,
]);
$client->sendMessage($to, [
    'type'=>'poll-vote', 'poll'=>['id'=>$poll['id']], 'selectedOptionNames'=>['Hoje'],
]);

$event = $client->sendMessage($to, [
    'type'=>'event', 'name'=>'Reunião', 'startTime'=>time()+3600, 'endTime'=>time()+7200,
    'description'=>'Planejamento',
]);
$client->sendMessage($to, [
    'type'=>'event-response', 'event'=>['id'=>$event['id']], 'response'=>'going',
]);
```

Respostas de evento: `going`, `not_going`, `maybe`, com `extraGuestCount` opcional. A biblioteca guarda o segredo e o autor da mensagem original por conversa/ID, inclusive após reinício. Para enquete/evento recebido, use o ID recebido. Também é possível fornecer `authorJid` e `messageSecret` binário dentro de `poll`/`event` ao importar estado externo.

Votos, respostas e addons criptografados são autenticados antes de expor os dados. Sem o segredo original, o evento mantém o conteúdo cifrado, `encrypted=true` e `decryptError`; a biblioteca não inventa uma resposta. `selectedOptions` recebido contém hashes SHA-256 dos nomes das opções, que a aplicação pode comparar aos nomes da enquete.

## Listas, botões e conteúdo genérico

```php
$client->sendMessage($to, ['type'=>'list', 'data'=>[
    'title'=>'Escolha uma opção', 'description'=>'Menu', 'buttonText'=>'Abrir',
    'listType'=>'SINGLE_SELECT',
    'sections'=>[['title'=>'Opções', 'rows'=>[
        ['rowId'=>'suporte', 'title'=>'Suporte', 'description'=>'Falar com atendimento'],
    ]]],
]]);

$client->sendMessage($to, ['type'=>'buttons', 'data'=>[
    'contentText'=>'Continuar?', 'footerText'=>'Atendimento', 'headerType'=>'EMPTY',
    'buttons'=>[['buttonId'=>'sim', 'buttonText'=>['displayText'=>'Sim'], 'type'=>'RESPONSE']],
]]);

$client->sendMessage($to, ['type'=>'raw', 'message'=>[
    'interactiveMessage'=>['body'=>['text'=>'Menu'], 'nativeFlowMessage'=>[
        'buttons'=>[['name'=>'quick_reply', 'buttonParamsJson'=>'{"display_text":"Ajuda","id":"ajuda"}']],
    ]],
]]);
```

`type=buttons` converte o corpo acima para native flow com `quick_reply`; `legacy-buttons` preserva `buttonsMessage`. Os campos e enums são validados contra o schema; erros de nome/tipo são rejeitados. Alguns formatos comerciais/interativos podem ser recusados ou renderizados de forma diferente conforme a conta e o aplicativo receptor.

Após ajustar os metadados interativos, o contexto dos aparelhos da conta e a versão do payload, o usuário confirmou os botões no navegador; o funcionamento no celular também foi confirmado. Para um menu em texto comum, use `buttonMode=text` junto de `type=buttons`. Veja [COMPATIBILIDADE-BOTOES.md](COMPATIBILIDADE-BOTOES.md) para a correção, os testes reais e a cobertura dos outros fluxos.

## Terminal e payload JSON

```powershell
composer message:send -- --session=default --to=5511999999999 --type=image --file="C:\fotos\foto.jpg" --caption="Minha foto"
composer message:send -- --session=default --to=5511999999999 --payload-file=examples/payloads/poll.json
composer message:send -- --session=default --to=123456789@g.us --payload-file=examples/payloads/location.json
```

`--payload-file` aceita o mesmo objeto de `sendMessage`. Bytes em JSON usam `{"$base64":"..."}`. Caminhos dentro do JSON devem ser absolutos para funcionar no worker; `--file` é convertido automaticamente em caminho absoluto. A fila suporta payloads de até 512 KiB; arquivos de mídia são referenciados por caminho. O worker precisa ser reiniciado para carregar esta versão, mantendo a sessão existente.

## Recebimento e grupos

```php
$client->on('message', function(array $event) use($client) {
    echo $event['chat'].' ['.$event['type'].'] '.($event['text'] ?? '').PHP_EOL;
    // $event['data']: corpo decodificado; $event['proto']: envelope completo.
    // $event['context']: citação, menções e demais contexto.
    // $event['media']: descrição para $client->downloadMedia($event).
    // Em grupos: $event['participant'] e $event['key']['participant'].
});
$client->on('message_protocol', function(array $event) {
    // $event['data']['type']: REVOKE=0, MESSAGE_EDIT=14, outros protocolos.
});
```

Campos novos/desconhecidos são preservados em `proto['_unknown']`. A inbox mantém bytes e coordenadas sem conversão destrutiva para JSON. Se salvar eventos fora da biblioteca, use `MVO\Protocol\BinaryData::encode()`/`decode()`.

Grupos usam Sender Keys Signal com assinatura, rotação quando muda a lista de membros/aparelhos, distribuição por sessão individual e persistência atômica junto da outbox. A lista é consultada novamente no envio; a distribuição só é marcada como aceita após ACK. Há testes entre instâncias PHP, com reinício, replay, adulteração e retry exato. Não há sincronização completa de histórico/app-state, envio a Status/broadcast/newsletters ou protocolo de chamadas nesta etapa. A validação dos novos tipos contra contas reais ainda está pendente.

Veja [MIDIA.md](MIDIA.md) para todos os formatos de arquivos, miniaturas, pacotes de figurinhas, limites e download verificado.
