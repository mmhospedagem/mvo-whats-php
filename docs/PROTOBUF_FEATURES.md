# Novos recursos de mensagens

As extensões de CRM, contatos, histórico, enquetes com imagens e agendamento local de canais estão descritas em [Extensões do protocolo](PROTOBUF_EXTENSIONS.md).

Os métodos abaixo usam o schema `2.3000.1050588369`, o transporte criptografado da sessão e os mesmos recibos das mensagens existentes. `server_ack` confirma aceite pelo servidor; entrega, leitura e reprodução dependem dos recibos e do cliente que recebeu a mensagem.

## Aviso MVO

```php
$client->sendProviderNotice($chat);
$client->sendProviderNotice($chat, ['privacyUrl'=>'https://www.mvo.host/privacidade']);
$client->sendProviderNotice($chat, [
    'format'=>'native',
    'text'=>'Esta empresa utiliza a plataforma MVO para gerenciar esta conversa.',
]);
// O mesmo envio pela interface genérica:
$client->sendMessage($chat, ['type'=>'provider-notice']);
```

O formato padrão `text` envia “Esta empresa utiliza a plataforma MVO para gerenciar esta conversa.”. Quando informada, `privacyUrl` acrescenta uma linha `Saiba mais: URL`. A URL deve ser HTTPS, sem credenciais, fragmento ou caracteres de controle; a biblioteca não acessa o link nem gera preview.

O formato explícito `native` acompanha uma mensagem comum de texto com a declaração de atendimento por terceiros (`BSP`), armazenamento próprio (`OnPremise`) e o horário do envio. Exige `text` visível em UTF-8, de até 65.536 bytes, e não aceita `privacyUrl`. A faixa é genérica e seu texto é definido pelo aplicativo WhatsApp: não é possível substituir esse texto por “MVO”. O conteúdo de `text` também aparece na conversa; não é um transportador invisível. A biblioteca não declara hospedagem pela Meta, não cria certificado ou selo e não modifica a configuração global da sessão. A exibição da faixa depende do estado observado e do cliente WhatsApp.

Em um teste controlado entre duas contas, o destinatário confirmou o aviso no Android. Capturas posteriores fornecidas pelo usuário também mostraram a faixa nativa no WhatsApp Web e o indicador com modal de detalhes no Android, ambos sobre atendimento por outras empresas. O WhatsApp define esses textos; não há um campo para inserir “MVO” na faixa ou no modal.

Ambos os formatos só são enviados quando o método é chamado. A mensagem permanece sujeita aos mesmos ACKs e recibos dos demais textos; um ACK não confirma a exibição da faixa.

## Álbuns de imagens e vídeos

```php
$album = $client->sendAlbum('5511999999999', [
    ['type'=>'image', 'media'=>['path'=>'/app/fotos/primeira.jpg']],
    ['type'=>'video', 'media'=>['path'=>'/app/videos/apresentacao.mp4']],
], ['caption'=>'Novidades']);

if (!$album['complete']) {
    // Preserve parentKey, messages e failure.messageId.
    // Não reenvie o álbum inteiro: itens anteriores podem ter sido aceitos.
    error_log(json_encode($album['failure']));
}
```

O envio prepara todas as mídias antes de publicar a mensagem pai. Os filhos têm a associação `MEDIA_ALBUM` e índice de ordem. Um ACK incerto interrompe os próximos itens e preserva os identificadores das tentativas persistidas. Consulte o outbox antes de decidir por um retry.

O evento `message` continua emitido para cada mensagem. O novo evento `album` reúne as referências dos itens quando o conjunto completo foi observado:

```php
$client->on('album', function (array $album): void {
    // parentKey, items, expectedImageCount, expectedVideoCount, complete.
    // Os itens referenciam o histórico; não duplicam arquivos nem chaves.
    salvarAlbum($album);
});
```

O agrupamento persiste após reinício, aceita mensagens fora de ordem e valida autor, conversa, índices e contagens. Retém até mil álbuns por sessão durante 48 horas. Callbacks podem ser repetidos após recuperação; deduplique usando a chave do pai.

## Enquetes e quizzes

```php
$quiz = $client->sendQuiz('5511999999999', [
    'name'=>'Qual linguagem usamos?',
    'options'=>['PHP', 'JavaScript'],
    'correctAnswer'=>'PHP',
    'endTime'=>time()+3600,
]);

$poll = $client->sendMessage('5511999999999', [
    'type'=>'poll', 'name'=>'Qual cidade?', 'options'=>['Natal', 'Recife'],
    'allowAddOption'=>true,
]);
$client->sendMessage($poll['to'], [
    'type'=>'poll-add-option', 'poll'=>['id'=>$poll['id']], 'optionName'=>'Fortaleza',
]);
$client->sendMessage($poll['to'], [
    'type'=>'poll-vote', 'poll'=>['id'=>$poll['id']], 'selectedOptionNames'=>['Fortaleza'],
]);
$results = $client->interactions()->pollResults($poll['to'], $poll['id']);
```

Segredos e autoria são recuperados do armazenamento da sessão. Um voto vazio retira a seleção anterior. A API valida nomes, duplicatas e o número de seleções permitido. A edição `poll-edit` permite mudar o título; não altera alternativas nem regras da enquete original.

Os resultados usam somente votos autenticados observados por esta sessão. Guardam a seleção mais recente por participante, preservam o histórico original e não atribuem nomes inventados a hashes desconhecidos. Enquetes anônimas omitem a identidade dos votantes. Opções adicionadas e edições autenticadas atualizam a projeção das consultas.

## Eventos e respostas

```php
$client->sendMessage($chat, [
    'type'=>'event-response', 'event'=>['id'=>$eventId],
    'response'=>'going', 'extraGuestCount'=>0,
]);
$responses = $client->interactions()->eventResponses($chat, $eventId);
```

As respostas são `going`, `not_going` e `maybe`. O tipo `event-edit` recupera o conteúdo observado e aceita alterações somente do autor original. O tipo `event-invite` representa um convite compartilhável com ID existente, título, horário, local e miniatura; não cria um registro remoto de evento por inventar um ID ou um handle de capa.

## Música, áudio e localização

```php
$client->sendMusic($chat, [
    // Referências reais do catálogo, obtidas de uma sessão com acesso à faixa.
    'embeddedMusic'=>['songId'=>$songId, 'musicContentMediaId'=>$musicContentMediaId, 'title'=>$title, 'author'=>$author],
    'songUri'=>$songUri,
    'style'=>'VINYL',
]);
$client->sendAudioSticker($chat, ['path'=>'/app/sticker.webp'], ['path'=>'/app/efeito.ogg']);
$request = $client->requestLocation($chat, ['text'=>'Compartilhe sua localização.']);
// Instrução manual, como texto comum, para clientes Web.
$client->requestLocation($chat, ['format'=>'text', 'text'=>'Abra esta conversa no celular e use Anexar > Localização.']);
// Campo novo do protocolo: experimental, com interface ainda não confirmada no destinatário.
$experimental = $client->requestLocation($chat, ['format'=>'protocol']);
$client->cancelLocationRequest($experimental['to'], ['id'=>$experimental['id'], 'fromMe'=>true]);
```

O cartão musical nativo é experimental e exige `musicContentMediaId`, `songId`, `title`, `author` e `songUri`. Esses dados devem identificar uma faixa real do catálogo; o método não pesquisa músicas, converte um MP3 nem concede acesso à faixa. `songUri` e o opcional `artworkUri` devem ser URLs HTTPS de subdomínios de `fbcdn.net` ou `whatsapp.net`. A validação local confere o formato, sem confirmar existência, validade da assinatura ou disponibilidade no catálogo. Dados incompletos e marcadores como `TRACK_ID` são recusados antes da construção da mensagem.

O Web analisado possui parser e interface condicionais: renderiza `VINYL` quando a referência de áudio é aceita e o recurso de recebimento está habilitado para a conta. O Android 2.26.40.76 também possui parser e interface, com condições de habilitação próprias. Quando o recurso ou os dados não são aceitos, o cliente pode mostrar uma mensagem incompatível ou o aviso de reprodução apenas no telefone. O envio por `sendMusic` aceita somente `VINYL`/1 e adota esse estilo por padrão; os demais valores continuam preservados no codec Protobuf para recebimento e estudo. Um ACK, recibo de entrega ou leitura não comprova reprodução. Ainda não foi confirmado um cartão válido reproduzido nas contas de teste. Para enviar um arquivo musical comum, use `sendAudio`; essa operação não produz um cartão do catálogo.

A figurinha com áudio segue o produtor observado no Android: `stickerMessage.audioMessage`, campo 26. O recebimento aceita também o wrapper declarado no schema. A reprodução depende das capacidades habilitadas no aparelho.

A solicitação de localização usa por padrão `format=interactive`, com o botão nativo `send_location` existente no Android. O envelope contém um único `biz`, `interactive` versão 1 e `native_flow` com nome `location_request_message`, sem versão forçada nesse último nó. O Android aceita esse par de nome de mensagem e ação; no teste entre duas contas, trocar apenas o nome externo superou a rejeição 405 e produziu recibos de entrega. Em 16/10/2026, o usuário confirmou no Android 2.26.40.76 da conta destinatária que o botão apareceu e abriu a tela de localização. Essa validação cobre o cliente e as contas testadas. A opção `text` envia somente instruções manuais, como texto comum; não abre GPS nem consulta localização automaticamente. O Web atual não implementa a ação nativa de localização.

A opção explícita `format=protocol` preserva `requestLocationMessage`, campo 136, envelope externo `type=media` e `enc.mediatype=request_location`. O envio corrigido recebeu dois recibos de entrega em produção. O Android 2.26.40.76 exibiu aviso de mensagem incompatível na cópia da conta remetente; a exibição na conta destinatária não foi verificada. Esse campo permanece experimental: sua presença no Protobuf não comprova suporte visual. `server_ack` comprova somente aceite pelo servidor e `delivered` somente o recibo de entrega, sem confirmar exibição ou interação.

O cancelamento mantém `requestLocationUpdateMessage`, campo 138, declarado no Protobuf, e se refere ao formato `protocol`. Não remove o botão interativo nem a instrução de texto. Esse campo não foi observado no classificador do APK Android analisado; a interação de cancelamento no aparelho ainda depende de validação específica.

## Status com links, música e perguntas

```php
$audience = ['5511999999999'];
$client->status()->sendLink('Conheça a MVO', 'https://www.mvo.host', $audience, [
    'title'=>'MVO', 'style'=>'FULL',
]);
$client->status()->sendMusic([
    'type'=>'text', 'text'=>'Ouvindo agora',
    'musicMetadata'=>['songId'=>$songId, 'title'=>'Faixa', 'author'=>'Artista'],
], $audience);
$client->status()->sendQuestion('Qual novidade você prefere?', $audience);
```

Previews aceitam `AUTO`, `COMPACT`, `FULL` e `IMMERSIVE`. Em imagens/vídeos, use `musicAnnotation` com `embeddedMusic`. A decoração ocorre depois do upload e conserva os hashes e chaves da mídia. O público é sempre explícito. Respostas e compartilhamentos usam publicações observadas e preservam os vínculos com o status original.

## Temas da conversa

```php
$client->themes()->set($chat, ['wallpaper'=>[
    'type'=>'solid-color', 'backgroundLightArgb'=>0xffffffff,
    'backgroundDarkArgb'=>0xff101010,
]]);
$client->themes()->reset($chat);
```

Os tipos são `default`, `solid-color`, `stock-image`, `custom-image` e `animated`. Cores usam inteiros ARGB. Temas se aplicam a conversas individuais. Imagens de catálogo e papéis animados exigem IDs existentes; não há catálogo implícito neste método.

## Integração e recebimento

Use os métodos PHP descritos acima para votos e consultas. A biblioteca entrega os eventos da sessão; sua aplicação define contratos HTTP e webhooks quando necessário. O evento `album` disponibiliza o conjunto agrupado por referências ao histórico.

Testes de codec, criptografia, transporte simulado, persistência e contratos não substituem a validação visual em Android, iOS e Web. As limitações acima distinguem o formato confirmado do que depende de serviços, catálogo ou recursos habilitados pelo WhatsApp.
