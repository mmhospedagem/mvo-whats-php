# Preferências, conversas de IA e conveniências de mensagens

`MVO\Service\AccountExtensionsService` usa os índices AppState verificados e aguarda o ACK autenticado antes de retornar uma alteração. É construído com os serviços da sessão:

```php
$account = new MVO\Service\AccountExtensionsService(
    $client->chat(), $client->appStateData(), $client->history()
);
$account->setPreference('disableLinkPreviews', true);
$account->setPreference('relayAllCalls', true);
$account->setPreference('locale', 'pt_BR');
$account->setPreference('twentyFourHourFormat', true);
$account->setPreference('unarchiveChats', false);
$preferences = $account->preferences();
```

Valores ausentes são `observed=false, value=null`, preservando a diferença entre desconhecido e desativado. Essas opções nativas são distintas dos ajustes de automação locais da aplicação. A resposta também contém `globalSettings`, observado em HistorySync, com preferências de download por rede, notificações, qualidade de mídia e outros campos conhecidos. `avatarUserSettings` e `chatLockSettings` são excluídos integralmente antes de persistir essa projeção: senha do avatar e código de bloqueio não entram no DTO nem no namespace de preferências.

## Preferências por plataforma

```php
$account->setPlatformPreference(
    'WEB', 'IS_MESSAGES_NOTIFICATION_ENABLED',
    '5511999999999@s.whatsapp.net', true
);
$page = $account->platformPreferences(100, '');
```

O índice SettingsSync exige plataforma, chave e conversa explícitas. As plataformas aceitas são WEB, HYBRID, WINDOWS e MAC. O tipo de valor é validado contra o campo protobuf correspondente. Não existe fallback para um índice global inventado. Preferências de outros aparelhos/conversas são preservadas.

## Conversas de IA

```php
$threads = $account->aiThreads();
$thread = $threads[0]; // selecione um registro real e não excluído
$account->mutateAiThread($thread['chatJid'], $thread['threadId'], 'rename', 'Planejamento MVO');
$account->mutateAiThread($thread['chatJid'], $thread['threadId'], 'pin', true);
// Exclusão explícita: mutateAiThread($bot, $threadId, 'delete').
```

A consulta combina AI_THREAD recebido/enviado no histórico autenticado e ações AppState sincronizadas. A alteração exige thread observada para o bot solicitado e não modifica outras threads. O ACK confirma o patch; ainda exige homologação no aplicativo da conta para comprovar a apresentação visual.

## Figurinhas e diagnóstico

Use o `id` SHA-256 de uma figurinha observada em `historyFeatures()->listStickers()`:

```php
$account->setFavoriteSticker($observedStickerId, true);
$account->removeRecentSticker($observedStickerId);
$diagnostics = $account->nativeDiagnostics();
```

Favoritar resolve internamente um descritor completo observado em mensagem ou AppState; a ausência desse descritor impede o envio. Remover dos recentes exige o timestamp observado. Chaves de mídia nunca fazem parte do DTO público. A confirmação do patch não apaga a evidência histórica original da figurinha.

O diagnóstico é somente leitura: flags, capacidades por aparelho, versões e assinaturas observadas. Valores desconhecidos não são convertidos em `false`. `canEnablePaidFeatures=false` e `serverEligibilityConfirmed=false`: a consulta não compra, habilita ou certifica nenhum recurso do servidor.

## Solicitar número voluntariamente

```php
$client->sendMessage($individualJid, MVO\Message\PhoneNumberRequests::content());
$requests = MVO\Message\PhoneNumberRequests::list($client->history(), $individualJid);
```

Use somente conversa individual. A solicitação envia `requestPhoneNumberMessage`; depende da elegibilidade e versão do cliente para aparecer. A correlação aceita somente `contactMessage.isSelfContact=true`, citando a solicitação na mesma conversa e direção oposta. Um cartão de terceiros ou resposta sem referência não conta como compartilhamento. Não existe descoberta de número oculto; o registro retorna os IDs correlacionados e não extrai números do vCard.

### Compartilhamento nativo recebido

O botão nativo também pode responder com `protocolMessage.type=11` (`SHARE_PHONE_NUMBER`), sem ID da solicitação ou cartão de contato. Consulte essas observações separadamente:

```php
$shares = $client->phoneNumberShares($individualJid, limit: 100);
// Consulta apenas os registros da conversa e seus aliases PN/LID confirmados.
if (count($shares) === 100) {
    $nextPage = $client->phoneNumberShares($individualJid, limit: 100, after: $shares[99]['cursor']);
}

$client->on('message_protocol', function (array $event) use ($client): void {
    $share = $client->history()->decodePhoneNumberShare($event);
    if ($share !== null && !$share['fromMe']) {
        // Persistir por messageId + conversa evita processar novamente um replay.
        echo json_encode($share, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
});
```

Cada observação contém `messageId`, `chat`, `fromMe`, `timestamp`, `phoneNumber`, `phoneJid`, `lid`, `numberSource`, `requestId`, `correlation`, `source` e `native`. A listagem acrescenta um `cursor` opaco. `numberSource` informa `authenticated_stanza`, `authenticated_identity_mapping` ou `unavailable`; número e identidade ausentes permanecem `null`. `phoneNumber` contém somente dígitos. O mapeamento de identidade só complementa o número **depois** de observar um protocolo nativo autenticado; um número conhecido sozinho não comprova compartilhamento.

O protocolo não identifica o pedido original: `requestId=null`, `correlation=conversation`. Por isso um pedido pode continuar em `awaiting_response` enquanto a lista de compartilhamentos já comprova a ação na conversa. `fromMe=true` representa o compartilhamento enviado pela própria conta ou sincronizado por um aparelho autenticado dela; não confirma o recebimento remoto. Importações sem remetente autenticado, grupos e números declarados em campos arbitrários não produzem essa observação.

## Prévia automática optativa de links

```php
$builder = new MVO\Message\LinkPreviewBuilder(
    uploadThumbnail: function (string $jpeg) use ($client): array {
        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, $jpeg); rewind($stream);
            return $client->uploadMediaStream($stream, 'thumbnail-link');
        } finally { fclose($stream); }
    }
);
$content = $builder->build(
    'Conheça a MVO: https://www.mvo.host/', 'https://www.mvo.host/', true
);
$client->sendMessage($individualJid, $content);
```

O fetcher padrão usa HTTPS público, DNS conferido e fixado por requisição, certificado TLS válido, nenhum proxy herdado e no máximo três redirecionamentos. HTML limitado a 1 MiB; imagem a 2 MiB e 16 milhões de pixels. Metadados OpenGraph/Twitter/title são sanitizados. A miniatura exige ext-gd, vira JPEG de até 640 pixels de largura e pode ser enviada como `thumbnail-link` criptografada. `includeThumbnail=false` evita buscar a imagem. Uma falha de preparação ocorre antes do envio da mensagem; não há fallback silencioso ou promessa de exibição em todos os clientes. Fetchers injetados devem impor sua própria política de rede pública.
