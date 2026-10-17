# Carrosséis, convites de canal, citações parciais e novos formatos

As operações abaixo usam a mesma sessão persistente, criptografia Signal, caixa de saída e confirmação de envio das demais mensagens. Não repetem automaticamente uma mensagem com resultado incerto. `server_ack` significa confirmação do servidor; não comprova exibição, entrega ou leitura. Os testes locais verificam o protocolo e a descriptografia por outro dispositivo simulado. A renderização dos novos formatos ainda depende da versão e dos recursos habilitados em cada aplicativo WhatsApp.

## Carrossel de imagens e vídeos

```php
$result = $client->sendCarousel('5511999999999', [
    [
        'type' => 'image',
        'media' => '/srv/mvo/catalogo/cloud.jpg',
        'mimetype' => 'image/jpeg',
        'title' => 'Cloud MVO',
        'subtitle' => 'Hospedagem para empresas',
        'caption' => 'Conheça nosso plano Cloud.',
        'footer' => 'Atendimento comercial',
        'accessibilityLabel' => 'Plano Cloud MVO',
        'buttons' => [
            ['type' => 'url', 'label' => 'Conhecer', 'url' => 'https://mvo.host'],
            ['type' => 'reply', 'label' => 'Tenho interesse', 'id' => 'cloud'],
        ],
    ],
    [
        'type' => 'video',
        'media' => '/srv/mvo/catalogo/datacenter.mp4',
        'mimetype' => 'video/mp4',
        'title' => 'Nossa infraestrutura',
        'caption' => 'Veja o datacenter MVO.',
        'width' => 1280,
        'height' => 720,
        'seconds' => 30,
        'buttons' => [
            ['type' => 'call', 'label' => 'Ligar', 'phoneNumber' => '+5511999999999'],
            ['type' => 'copy', 'label' => 'Copiar cupom', 'copyCode' => 'MVO2026'],
        ],
    ],
], ['text' => 'Escolha a sua solução.', 'footer' => 'MVO • mvo.host']);
```

O helper também está disponível em `$client->message()->sendCarousel(...)`. São permitidos de 2 a 10 cartões e de 1 a 3 botões por cartão. O contrato da biblioteca limita a soma das mídias a 32 MiB. Todas as fontes locais são lidas antes do primeiro upload; todas as mídias são preparadas antes de escrever uma única mensagem de carrossel. Falha em preparar um cartão impede a publicação. Pode haver uploads já concluídos no CDN, mas não uma publicação parcial de cartões.

Cada cartão aceita `title`, `subtitle`, `caption`, `footer`, `mimetype`, `width`, `height`, `seconds`, `jpegThumbnail` binário e `accessibilityLabel`, além de `type`, `media` e `buttons`. A SDK recebe arquivos locais, streams ou `['bytes' => $binary]`; não baixa URLs arbitrárias. O endpoint HTTP aceita HTTPS público ou Base64 e usa downloads limitados com proteção contra acesso a redes privadas.

O codec e os metadados interativos de carrossel já existiam. Esta extensão acrescenta autoria tipada, preparação de múltiplas mídias, validação e integração HTTP. Não é uma promessa de renderização em todo cliente.

## Cartão para seguir um canal

```php
$result = $client->sendChannelInvite('5511999999999', '120363000000000000@newsletter', [
    'caption' => 'Acompanhe as novidades oficiais da MVO.',
    // 'jpegThumbnail' => file_get_contents('/srv/mvo/canal-miniatura.jpg'),
]);
```

Para compor diretamente, use `$client->newsletter()->followerInviteContent($channelJid, $options)` e envie o conteúdo retornado por `sendMessage()`. O helper consulta o nome e o JID reais do canal e exige que a resposta corresponda ao canal solicitado. O campo transmitido é `newsletterFollowerInviteMessageV2`. Não altera inscrições nem concede cargo de administrador. Nome/JID substitutos não são parâmetros de autoria. A miniatura é opcional, limitada a 1 MiB, e não é baixada automaticamente.

## Responder citando somente um trecho

```php
$result = $client->sendPartialQuote(
    '120363000000000000@g.us',
    '3EB01234567890ABCDEF12',
    'plano Cloud',
    ['type' => 'text', 'text' => 'Sobre o plano Cloud: podemos ajudar.'],
    '5511888888888@s.whatsapp.net', // autor opcional quando o ID é unívoco
);
```

A mensagem original deve estar no histórico autenticado da própria sessão e conversa. O trecho deve ser uma sequência literal do texto ou da legenda original, com até 8192 bytes UTF-8. Não há posições inventadas: o protobuf contém somente `partiallySelectedContent.text`. IDs ambíguos exigem autor. Mensagem revogada, referência a outra conversa e trecho inexistente são rejeitados antes da preparação de mídia ou escrita. A SDK substitui qualquer conteúdo original fornecido pelo chamador pelo registro verdadeiro do histórico.

No nível de autoria, também é possível usar `quote: ['id' => $id, 'selectedText' => $text, 'author' => $author]` em `sendMessage()`. O evento recebido contém `quote.id`, `quote.participant`, `quote.remoteJid`, `quote.selectedText` e `quote.quotedType`; o contexto original permanece disponível.

## Spoiler visual

```php
$result = $client->sendSpoiler('5511999999999', 'Uma novidade para revelar.');
// Também aceita mídia:
$result = $client->sendSpoiler('5511999999999', [
    'type' => 'image', 'media' => '/srv/mvo/novidade.jpg', 'caption' => 'Toque para revelar.',
]);
```

Em `sendMessage()` pode-se informar `spoiler: true` em texto/mídia, ou `type: spoiler` com `content`. O produtor usa `spoilerMessage` e `contextInfo.isSpoiler`. O recebimento normaliza `spoiler` sem eliminar os campos originais. O recurso é experimental: ocultar visualmente não protege o conteúdo nem impede que outro cliente o apresente.

## Figurinha Lottie nativa, sem conversão

```php
$result = $client->sendLottieSticker('5511999999999', '/srv/mvo/figurinha-nativa.was', [
    'mimetype' => 'application/was',
    'width' => 512, 'height' => 512,
    'accessibilityLabel' => 'Figurinha animada MVO',
]);
```

O pacote nativo fornecido pelo usuário é transmitido intacto, cifrado como sticker, com MIME `application/was` e `isLottie: true`. Não passa pelo conversor WebP. O helper não converte JSON, Lottie ou TGS para `.was`, não valida o contêiner `.was` e não garante exibição. MIME e tamanho são validados; o usuário precisa fornecer um pacote nativo válido. `pngThumbnail` binário opcional é limitado a 1 MiB.

## Imagem instantânea recebida

O decoder reconhece `instantImageMessage`, retorna `kind: instant-image` e normaliza `instantImage` com legenda, MIME e dimensões. `media.mediaType` permanece `image`, pois o download autenticado usa as chaves de imagem; `media.variant` identifica `instant-image`. Downloads preservam a verificação de MAC e hashes. Não foi criado um produtor de envio sem evidência suficiente do envelope de origem.

## Operações HTTP

| Método e caminho relativo à instância | Operação |
| --- | --- |
| `POST /messages/carousels` | `message.carousel` |
| `POST /messages/channel-invitations` | `message.channel-invite` |
| `POST /messages/:message_id/partial-quotes` | `message.partial-quote` |
| `POST /messages/spoilers` | `message.spoiler` |
| `POST /messages/lottie-stickers` | `message.lottie-sticker` |

Os contratos completos e exemplos públicos ficam em `message-extension-schemas.json` no projeto API. Fontes de mídia são exclusivamente `url` HTTPS pública ou `base64`, nunca `mediaKey`, protobuf bruto ou caminho do servidor. Campos desconhecidos e fontes conflitantes são rejeitados antes do processamento. Os arquivos temporários HTTP são privados e removidos ao concluir ou falhar a operação.
