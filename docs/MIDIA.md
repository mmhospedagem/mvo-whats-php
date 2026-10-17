# Mensagens de mídia em PHP

A biblioteca envia e recebe imagens, vídeos, GIFs em MP4, vídeos circulares (PTV), áudios, mensagens de voz (PTT), documentos, figurinhas e pacotes de figurinhas. Upload, download, criptografia AES/HMAC e construção do protocolo rodam em PHP, com as extensões cURL, OpenSSL e fileinfo. Não é necessário Node.js.

O cliente precisa estar conectado à sessão antes de enviar. Nos exemplos abaixo, `$client` é uma instância conectada de `MVO\WaClient`, e `$to` é o número com DDI/DDD ou um JID válido de conversa individual/grupo. O retorno confirma o ACK do servidor; entrega e leitura chegam pelos eventos de recibo.

## Envio pelo terminal

Mantenha o worker ativo:

```powershell
composer worker -- --session=default
```

Em outro terminal, envie um arquivo local:

```powershell
composer message:send -- --to=5511999999999 --type=image --file="C:\arquivos\foto.jpg" --caption="Minha foto"
composer message:send -- --to=5511999999999 --type=video --file="C:\arquivos\video.mp4"
composer message:send -- --to=5511999999999 --type=gif --file="C:\arquivos\animacao.mp4"
composer message:send -- --to=5511999999999 --type=ptv --file="C:\arquivos\video-circular.mp4"
composer message:send -- --to=5511999999999 --type=audio --file="C:\arquivos\musica.mp3"
composer message:send -- --to=5511999999999 --type=ptt --file="C:\arquivos\voz.ogg"
composer message:send -- --to=5511999999999 --type=document --file="C:\arquivos\relatorio.pdf" --file-name="Relatorio.pdf"
composer message:send -- --to=5511999999999 --type=sticker --file="C:\arquivos\figurinha.webp"
```

Substitua o destino e os arquivos. `--mimetype` permite informar o MIME explicitamente; normalmente a biblioteca o detecta. As opções adicionais e os pacotes de figurinhas podem ser enviados com `--payload-file="C:\arquivos\mensagem.json"`. Nesse JSON, use caminhos absolutos, por exemplo:

```json
{
  "type": "image",
  "media": {"path": "C:\\arquivos\\foto.jpg"},
  "caption": "Minha foto",
  "viewOnce": true
}
```

## API PHP

As conveniências abaixo recebem o caminho de um arquivo local. Os campos opcionais seguem os nomes do protocolo.

```php
$client->sendImage($to, __DIR__.'/foto.jpg', [
    'caption' => 'Minha foto',
]);

$client->sendVideo($to, __DIR__.'/video.mp4', [
    'caption' => 'Meu vídeo',
    'seconds' => 12,
]);

$client->sendGif($to, __DIR__.'/animacao.mp4');
$client->sendPtv($to, __DIR__.'/video-circular.mp4', [
    'seconds' => 8,
    'width' => 480,
    'height' => 480,
]);

$client->sendAudio($to, __DIR__.'/musica.mp3');
$client->sendVoice($to, __DIR__.'/voz.ogg', [
    'mimetype' => 'audio/ogg; codecs=opus',
    'seconds' => 4,
]);

$client->sendDocument($to, __DIR__.'/relatorio.pdf', [
    'fileName' => 'Relatorio.pdf',
    'title' => 'Relatório mensal',
    'caption' => 'Documento em anexo',
]);

$client->sendSticker($to, __DIR__.'/figurinha.webp');
```

As mesmas mensagens podem ser enviadas por `sendMessage()`, com `type` igual a `image`, `video`, `gif`, `ptv`, `audio`, `ptt`, `document` ou `sticker`:

```php
$client->sendMessage($to, [
    'type' => 'image',
    'media' => ['path' => __DIR__.'/foto.jpg'],
    'caption' => 'Foto com visualização única',
    'viewOnce' => true,
]);
```

`gif` também pode ser representado por `type=video` com `gifPlayback=true`; `ptt`, por `type=audio` com `ptt=true`.

## Formatos e metadados

GIFs no WhatsApp são vídeos MP4 com o indicador de repetição. A biblioteca rejeita um arquivo GIF bruto no envio como `gif`; converta-o para MP4 antes. Para PTV, prepare um MP4 quadrado. Figurinhas devem estar em WebP; para mensagens de voz, prefira Ogg com codec Opus. A biblioteca não converte contêineres, codecs, imagens ou proporções automaticamente.

São detectados nativamente:

- MIME pelo conteúdo, com nomes/extensões como alternativa;
- largura e altura de imagens reconhecidas pelo PHP;
- duração e dimensões de MP4/MOV com os campos de contêiner correspondentes;
- duração de WAV e Ogg/Opus com metadados reconhecidos;
- primeiro quadro de WebP animado, para gerar sua autenticação específica.

Os campos informados pelo chamador têm prioridade sobre os metadados detectados. Quando a detecção não encontra um campo, ele fica ausente; informe `seconds`, `width` e `height` quando necessário. A biblioteca gera os sidecars criptográficos de reprodução para áudio/vídeo que permitem acesso progressivo; MP4 com o índice `moov` depois de `mdat` recebe o fluxo sem esse sidecar.

Miniaturas e waveform são opcionais e devem ser fornecidas prontas. Use bytes JPEG para `jpegThumbnail`, bytes PNG para `pngThumbnail` de figurinhas e uma string binária de amostras para `waveform`, com um byte por amostra. Não passe uma lista PHP de números como se fosse um campo binário.

```php
$client->sendImage($to, __DIR__.'/foto.jpg', [
    'jpegThumbnail' => file_get_contents(__DIR__.'/miniatura.jpg'),
]);

$client->sendVoice($to, __DIR__.'/voz.ogg', [
    'waveform' => pack('C*', 0, 8, 20, 48, 80, 100, 75, 30, 5, 0),
]);
```

## Arquivos, bytes e streams

O campo `media` aceita três formas. Uma string isolada significa sempre caminho local:

```php
// Caminho local.
['type' => 'document', 'media' => ['path' => __DIR__.'/arquivo.pdf']];

// Bytes explícitos; use fileName para definir o nome exibido de documentos.
['type' => 'document', 'media' => ['bytes' => $bytes], 'fileName' => 'arquivo.pdf'];

// Stream aberto: a leitura começa na posição atual.
$stream = fopen(__DIR__.'/arquivo.pdf', 'rb');
try {
    $client->sendMessage($to, [
        'type' => 'document',
        'media' => $stream,
        'fileName' => 'arquivo.pdf',
    ]);
} finally {
    fclose($stream);
}
```

A biblioteca não fecha um stream fornecido pelo chamador. URLs HTTP, `file://`, `data://` e outros wrappers não são aceitos como origem de arquivo; baixe ou gere os bytes na aplicação antes de enviar. Streams abertos podem ser usados diretamente na API PHP; a fila entre processos e o JSON da CLI usam arquivos ou bytes explícitos.

Para binários em um payload JSON, utilize o formato `{"$base64":"..."}` disponibilizado por `BinaryData::fromJsonInput()`, dentro de `media.bytes`, `jpegThumbnail`, `waveform` e outros campos binários. O JSON/fila local tem limite de 512 KiB; para mídias maiores, informe um caminho local.

## Pacotes de figurinhas

Um pacote exige ao menos uma figurinha, um ícone de bandeja separado e uma miniatura de capa JPEG. Cada item do ZIP precisa de um nome de arquivo único; diretórios, caminhos relativos e colisões de nomes são rejeitados.

```php
$client->sendMessage($to, [
    'type' => 'sticker-pack',
    'stickerPackId' => 'meu-pacote-001', // Opcional: gerado quando ausente.
    'name' => 'Minhas figurinhas',
    'publisher' => 'Minha aplicação',
    'packDescription' => 'Pacote criado em PHP',
    'stickers' => [
        [
            'fileName' => 'ola.webp',
            'media' => ['path' => __DIR__.'/ola.webp'],
            'emojis' => ['👋'],
            'isAnimated' => false,
        ],
        [
            'fileName' => 'feliz.webp',
            'media' => ['path' => __DIR__.'/feliz.webp'],
            'emojis' => ['😀'],
        ],
    ],
    'trayIcon' => [
        'fileName' => 'icone.png',
        'media' => ['path' => __DIR__.'/icone.png'],
    ],
    'coverThumbnail' => ['path' => __DIR__.'/capa.jpg'],
    'thumbnailWidth' => 252,
    'thumbnailHeight' => 252,
]);
```

O ZIP é criado em PHP, com entrada para o ícone de bandeja. Pacote e capa são enviados separadamente com seus domínios criptográficos correspondentes. O descritor retornado pelo recebimento de um pacote permite baixar o ZIP completo.

## Recebimento e download verificado

O evento `message` preserva o conteúdo completo em `proto` e apresenta um descritor em `media` quando há mídia baixável. O arquivo não é baixado automaticamente.

```php
$client->on('message', function (array $event) use ($client): void {
    if (!isset($event['media'])) {
        return;
    }

    // Retorna bytes somente depois da validação criptográfica.
    $bytes = $client->downloadMedia($event);

    // Ou escolha explicitamente um arquivo de destino.
    // O diretório já precisa existir.
    $path = __DIR__.'/recebidos/'.bin2hex(random_bytes(12)).'.bin';
    $client->downloadMedia($event, $path);
});
```

Escolha apenas uma das duas formas no código real, evitando baixar o mesmo arquivo duas vezes. Também é possível passar diretamente `$event['media']` ou `$event['proto']` para `downloadMedia()`.

O download verifica o MAC, os hashes SHA-256 do arquivo criptografado e do conteúdo quando presentes no descritor, e o tamanho declarado quando informado. O arquivo de destino só é gravado após essas verificações. A biblioteca aceita HTTPS em hosts de mídia do WhatsApp/Meta autorizados e tenta os hosts alternativos disponíveis; redirecionamentos arbitrários não são seguidos.

## Limites e transferência direta

O limite padrão é **32 MiB por mídia**; o pacote de figurinhas deve caber nesse limite depois da montagem do ZIP. A leitura, criptografia e transferência são síncronas e mantêm o conteúdo em memória. A presença de uma API de stream de entrada não representa transferência com memória constante.

Para transferências diretas com outro limite, instancie `MediaTransfer`:

```php
use MVO\Media\MediaTransfer;
use MVO\Protocol\BinaryNode;

$media = new MediaTransfer(
    query: fn (BinaryNode $node): BinaryNode => $client->query($node),
    maxBytes: 64 * 1024 * 1024,
);

// Faz upload e devolve um envelope WAProto pronto para envio.
$message = $media->prepare([
    'type' => 'document',
    'media' => ['path' => __DIR__.'/arquivo.pdf'],
]);
$client->sendMessage($to, ['type' => 'raw', 'message' => $message]);

// Esta instância também aplica seu limite ao download.
$bytes = $media->download($event['media']);
```

Essa instância independente não altera o limite padrão do cliente. Credenciais de CDN ficam em cache pelo TTL recebido, com renovação antecipada; uma recusa de autenticação no upload força uma renovação antes da última tentativa. Os campos `mediaKey`, hashes e sidecars são strings binárias PHP, e não strings base64 dentro da API.

Os testes locais cobrem o protocolo e a transferência com respostas HTTP simuladas, incluindo corrupção, limites e falhas de autenticação/host. A aceitação de formatos específicos e a exibição no aplicativo ainda devem ser validadas com os arquivos e destinatários desejados.

## Streams e processamento

`WaClient(mediaMaxBytes: 128 * 1024 * 1024)` configura o limite do cliente. `uploadMediaStream($pathOrResource, $type)` cifra em blocos e mantém um arquivo temporário reproduzível para os hosts de fallback. O retorno é um descritor WAProto; envie-o com `type=raw` no campo de mídia correspondente. `downloadMediaStream($event, $destination)` baixa para um temporário, verifica MAC/hash/length, decifra em blocos e só então substitui o destino. Os testes comparam os bytes cifrados e sidecars com o algoritmo buffered em todas as fronteiras de blocos.

O cliente gera miniaturas de imagem/figurinha quando GD está disponível (já habilitado no PHP de desenvolvimento). Para vídeo, áudio comprimido, waveform e normalização Opus, configure explicitamente os binários nativos:

```php
$processor = new MVO\Media\MediaProcessor(
    ffmpeg: 'C:/ffmpeg/bin/ffmpeg.exe',
    ffprobe: 'C:/ffmpeg/bin/ffprobe.exe',
);
$client = new MVO\WaClient(mediaProcessor: $processor);
// Após conectar:
$client->sendVoice($to, __DIR__.'/entrada.wav', ['normalizeVoice'=>true]);
```

FFmpeg/ffprobe são opcionais e precisam existir no caminho configurado. O Composer não instala esses executáveis. Chamadas usam argumentos separados, sem shell, com timeout e limite de saída; nenhum JavaScript é executado. `capabilities()` informa as operações configuradas. Sem encoder, solicitar `normalizeVoice` falha explicitamente.

## Mídia pública de canais

`newsletter()->send($jid, ['type'=>'image', 'media'=>['path'=>$path]])` faz upload plaintext no endpoint próprio de canais. PTV e packs de figurinhas também usam esses endpoints; packs contêm ZIP/tray icon e não herdam a chave/thumbnail cifrada de conversas. `downloadNewsletterMedia($message, $destination)` verifica o SHA-256 do arquivo público antes de gravar. Mídia cifrada de chats não pode ser reutilizada como descritor de canal.

Para arquivos diretos expirados, veja [RECUPERACAO-MIDIA.md](RECUPERACAO-MIDIA.md): eventos com ID/chat permitem solicitar novo directPath ao primário e repetir o download uma vez, preservando a verificação dos hashes originais.
