# Recuperar mídia expirada

Downloads de eventos com ID, chat e chave de mídia tentam uma recuperação automática quando todos os endereços do CDN falham e o último erro é HTTP 404/410. A biblioteca envia um recibo `server-error` selado ao aparelho principal, aguarda a notificação `mediaretry` e tenta baixar uma vez pelo novo `directPath`. Funciona tanto em `downloadMedia` como em `downloadMediaStream`.

```php
$bytes = $client->downloadMedia($event);
$client->downloadMediaStream($event, __DIR__.'/arquivo.pdf');
```

A recuperação precisa do evento ou de metadados equivalentes. Um descritor de mídia isolado não identifica qual mensagem deve ser recuperada. Nesse caso, faça o pedido explicitamente:

```php
$result = $client->requestMediaReupload([
    'messageId' => 'ID_ORIGINAL',
    'chatJid' => '558400000000@s.whatsapp.net',
    'mediaKey' => $descriptor['mediaKey'],
    'fromMe' => false,
]);

if ($result['result'] === 'success') {
    $descriptor['directPath'] = $result['directPath'];
    unset($descriptor['url']);
    $bytes = $client->downloadMedia($descriptor);
}
```

Em grupos, informe também `participant`. O timeout padrão é de 30 segundos e pode ser passado como segundo argumento. Os resultados são `success`, `not_found`, `decryption_error` e `general_error`. Erros de autenticação ou timeout lançam exceção. Pedidos repetidos em andamento compartilham o mesmo registro; uma chave diferente para o mesmo ID é rejeitada.

O recibo e a resposta usam HKDF-SHA256 com o contexto `WhatsApp Media Retry Notification`, AES-256-GCM e o ID original como dados autenticados. A resposta precisa vir da própria conta, abrir com a chave original e repetir o mesmo ID selado. O manipulador da notificação não faz consultas nem downloads; só resolve o pedido pendente.

O novo caminho não troca a chave nem os hashes originais. Cada novo download continua verificando MAC, hash do arquivo criptografado, hash do conteúdo e tamanho. Se o aparelho reencriptou a mídia com outra chave, o download falhará; a biblioteca não contorna essa verificação nem entra em um ciclo de reuploads. Mensagens de canais públicos não têm esse protocolo de recuperação por chave individual.

Para integrações de baixo nível que controlam o próprio loop, `MessageService` também oferece `beginMediaReupload` e `getMediaReuploadResult`. Sua variante síncrona precisa receber o callback `pump` do cliente. O `WaClient` configura esse callback automaticamente.
