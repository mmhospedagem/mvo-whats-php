# Upload de fotos Facebook Web

`MediaService::uploadPhoto()` transfere **um arquivo** JPEG, PNG ou WebP pelo transporte multipart do mesmo dono de sessão Messenger. O retorno contém `photo_id`, `uploaded=true` e `published=false`: esse resultado não é uma publicação nem uma mensagem no inbox. O limite local de 25 MiB é uma política do SDK, não uma afirmação sobre o limite do Facebook.

O serviço recebe `PhotoUploadContext($accountId, $actorId, $photoConfig)`. `$photoConfig` é **`ReactComposerMediaConfig.photo` do contexto autenticado atual**. Conta e actor são bindings explícitos e não podem ser trocados depois. O serviço conserva `uploadData.profile_id` recebido; não o deduz do actor e não substitui pelo `0` observado no bootstrap anônimo usado na investigação. Não aceita outros endpoints ou campos desconhecidos.

```php
use MVO\Facebook\Media\PhotoUploadContext;
use MVO\Messenger\Transport\UploadFile;

// O contexto autenticado é fornecido pela integração que conserva o dono da sessão.
// Não use a configuração de outra conta, valores de fixtures ou cookies em exemplos públicos.
$context = new PhotoUploadContext($accountId, $pageActorId, $currentPhotoConfig);
$file = new UploadFile(__DIR__.'/assets/foto.png', 'foto.png', 'image/png');

$asset = $facebook->media()->uploadPhoto(
    $pageActorId,
    $file,
    'upload-foto-pedido-42',
    $context,
);
```

`UploadFile` aceita um arquivo local escolhido explicitamente, sem buscar URLs remotas. O caminho privado não integra o payload HTTP, o resultado, o ledger ou a representação de depuração. O digest SHA-256, tamanho, nome de transferência e MIME vinculam o conteúdo à chave de idempotência.

O dono da sessão precisa oferecer `MVO\Messenger\Auth\MultipartWebSession`; integrações antigas que implementam somente `WebSession` continuam válidas para suas operações anteriores. O transporte novo preserva o mesmo CookieJar, lock, conta e limite de ciclo de vida.

Cada upload tem um comando no ledger. Um resultado confirmado é reproduzido sem I/O; conteúdo diferente com a mesma chave é conflito. Timeout, HTTP inesperado, resposta recusada ou payload sem `photoID` não habilitam repetição automática. A aplicação consulta o comando antes de decidir outra ação. O `upload_id` decimal é correlação do cliente, sem promessa de idempotência no servidor.

O contrato observado usa `POST https://upload.facebook.com/ajax/react_composer/attachments/photo/upload?av=<actor>`, campo multipart **`farr`** e JSON de resposta `payload.photoID`. Origem e hashes estão em [`resources/content-management-documents.json`](../../resources/content-management-documents.json). O código público de `ComposerMediaFileUploader`, `XComposerPhotoUploader` e `AsyncUploadBase` forneceu esse formato. A investigação e os testes locais não executaram upload em conta real.

Vídeos usam `VideoUploadContext` obtido por `MediaService::videoContext()` e `uploadVideo()` com o limite opcional `RawUploadWebSession`. O fluxo Web inicia um asset, consulta o offset de rupload, transmite o sufixo do arquivo original e finaliza o recebimento; ele não publica um post. Nenhum token de API é usado. Um resultado incerto não habilita reinício automático ou repetição de etapas.

`MediaService::publishPhotos()` publica assets de fotos já carregados usando o compositor de timeline de perfil observado, com outra chave e o contexto atual completo. `publishVideo()` usa o branch próprio de vídeo sem edição, colaboração, música ou crop. `editCaption()` e `deleteMedia()` atuam no asset; exclusão não significa apenas desvincular um item de um álbum.

`AlbumService` oferece consulta, paginação, criação, edição, exclusão, adição de assets, capa e ordenação. Criação do contêiner e adição de mídia são comandos separados; falha da segunda etapa não deve repetir a primeira. Fontes, IDs, variáveis e hashes adicionais estão em [`resources/media-documents.json`](../../resources/media-documents.json), e assinaturas/pré-condições em [MIDIA.md](../../../../docs/facebook/MIDIA.md).

Agendamento, anexos no inbox, fluxos gerais de Página/reels, transformação completa de mídia e deslocamento de fotos entre álbuns não são implicitamente habilitados por esses helpers. A evidência é estática e de testes sintéticos: nenhum upload, publicação ou alteração de álbum foi executado em conta real.
