# Upload de fotos

`media()` oferece upload de fotos e vídeos, publicação de fotos ou de um vídeo no compositor de perfil, edição de legendas e exclusão de mídia. `albums()` administra os álbuns e seus itens. As operações usam a mesma conta, CookieJar, lock e ciclo de vida da conexão Facebook. Um upload confirmado produz um **asset**; a publicação é uma operação explícita e usa outra chave de idempotência.

## Obter o contexto autenticado

Após a conexão utilizável, chame `photoContext($actorId)` fora dos callbacks. O método lê somente `ReactComposerMediaConfig` já recebido pelo dono da sessão e seleciona o bloco `photo`. Não faz uma requisição extra, não troca a identidade ativa e não cria configuração anônima como fallback.

```php
use MVO\Facebook\FacebookConnection;
use MVO\Facebook\Media\PhotoUploadContext;

function contextoDeUpload(
    FacebookConnection $facebook,
    string $actorId,
): PhotoUploadContext {
    return $facebook->media()->photoContext($actorId);
}
```

O contexto fica vinculado ao `accountId` da sessão e ao `actorId` explícito. Essa vinculação não concede permissão de publicar ou administrar uma Página. O campo `uploadData.profile_id` conserva o valor do bootstrap atual; não é inferido do actor nem preenchido com o `0` observado em um bootstrap anônimo da investigação.

Se o módulo não estiver disponível, seu formato não for reconhecido ou o dono não fornecer a interface opcional `BootstrapWebSession`, a chamada retorna `facebook_upload_context_unavailable` com `outcome=not_executed`. Ela não tenta outra conta, endpoint ou contexto. A presença de autenticação Messenger, por si só, não demonstra que esse módulo tenha sido recebido.

## Escolher o arquivo e fazer upload

`uploadPhoto($actorId, UploadFile $file, $idempotencyKey, PhotoUploadContext $context)` aceita um arquivo local escolhido explicitamente. O helper não busca URLs remotas.

```php
use MVO\Facebook\FacebookConnection;
use MVO\Messenger\Transport\UploadFile;

function carregarFoto(
    FacebookConnection $facebook,
    string $actorId,
    string $arquivoLocal,
    string $nomeDoArquivo,
    string $mimeReal,
    string $chaveIdempotencia,
): array {
    $contexto = $facebook->media()->photoContext($actorId);
    $arquivo = new UploadFile($arquivoLocal, $nomeDoArquivo, $mimeReal);

    return $facebook->media()->uploadPhoto(
        $actorId,
        $arquivo,
        $chaveIdempotencia,
        $contexto,
    );
}
```

Use `image/jpeg`, `image/png` ou `image/webp` conforme o arquivo real. O nome de transferência deve ser um nome simples, sem diretórios ou caracteres de controle. O arquivo precisa existir, ser legível e conter entre 1 byte e 25 MiB para este helper. O teto de 25 MiB é uma política local do SDK, não uma afirmação sobre o limite do Facebook.

`UploadFile` conserva o caminho somente no transporte privado. O caminho não entra no nome de transferência, no resultado, na representação de depuração ou no ledger. A aplicação ainda deve selecionar e validar o conteúdo que pretende carregar; declarar um MIME não equivale a converter ou analisar a imagem.

## Interpretar o resultado

| Campo | Interpretação |
| --- | --- |
| `photo_id` | ID do asset reconhecido em `payload.photoID`. |
| `uploaded` | `true` somente depois de reconhecer o retorno de upload. |
| `published` | `false`: o helper não publica nem envia esse asset. |
| `width`, `height` | Dimensões inteiras positivas informadas, ou `null`. |
| `image_url` | URL HTTPS informada no payload, ou `null`. Não presumir permanência ou acesso público irrestrito. |
| `media_taken_time` | Valor inteiro recebido, ou `null`; o SDK não presume unidade diferente. |

HTTP 200 isolado não basta. O SDK rejeita erros explícitos, payload desconhecido e resposta sem um `photoID` numérico válido. Só a projeção acima é persistida; a árvore de resposta, tokens de sessão e outros campos não são copiados para o comando.

O transporte do mesmo dono precisa oferecer `MultipartWebSession`. Integrações antigas que implementam apenas `WebSession` continuam válidas para as operações anteriores, mas não passam a suportar upload automaticamente.

## Idempotência e falhas

A chave de idempotência vincula conta, actor, configuração de upload, digest SHA-256, tamanho, nome e MIME do arquivo. Ela não usa o caminho local como identidade do conteúdo.

- A chave confirmada reproduz a mesma projeção sem repetir o upload.
- Conteúdo ou contexto diferente na mesma chave gera conflito.
- Timeout, HTTP inesperado, recusa do servidor ou resposta não reconhecida conservam o comando sem confirmação e impedem repetição automática.
- O `upload_id` decimal é correlação do cliente. Ele não estabelece uma garantia de idempotência no servidor.

Consulte [o estado do comando](LIMITES.md) antes de decidir uma nova tentativa. Não gere outra chave automaticamente apenas porque não recebeu o retorno esperado.

## Contrato e alcance da validação

O formato foi recuperado de `ComposerMediaFileUploader`, `XComposerPhotoUploader`, `AsyncUploadBase`, `ActorURI` e do bloco `ReactComposerMediaConfig.photo` em artefatos públicos do Facebook Web. Ele usa o endpoint Web de fotos observado, campo multipart `farr` e resposta `payload.photoID`; não usa Graph API oficial ou a API Marketing Graph interna.

Os testes locais cobrem a projeção, vinculação ao mesmo dono/actor, bootstrap ausente, endpoint desconhecido, idempotência, digest do arquivo e resultado incerto. Não houve upload de foto em conta real nesta revisão. Obter o contexto autenticado e a aceitação de um upload concreto precisam ser validados na conta e no cliente de destino.

## Vídeos: contexto, bytes e finalização

`videoContext($actorId, $targetId, $entryPoint, $creationSessionId, $source = 'composer')` consulta `useComposerVideoUploaderConfigQuery` para a identidade e o destino informados. O resultado é um `VideoUploadContext` vinculado à conta e ao actor atuais. `creationSessionId` é o UUID da criação atual; `entryPoint` e `source` são os valores do compositor em uso, não uma identidade nova. As fontes implementadas são `composer`, `newsfeed_composer`, `biz_composer` e `album_uploader`.

```php
use MVO\Facebook\FacebookConnection;
use MVO\Messenger\Transport\UploadFile;

function carregarVideo(
    FacebookConnection $facebook,
    string $actorId,
    string $targetId,
    string $entryPoint,
    string $uuidDaCriacao,
    string $source,
    string $arquivoLocal,
    string $nomeDoArquivo,
    string $mimeReal,
    string $chaveIdempotencia,
): array {
    $media = $facebook->media();
    $contexto = $media->videoContext(
        $actorId, $targetId, $entryPoint, $uuidDaCriacao, $source,
    );

    return $media->uploadVideo(
        $actorId,
        new UploadFile($arquivoLocal, $nomeDoArquivo, $mimeReal),
        $chaveIdempotencia,
        $contexto,
    );
}
```

O helper aceita `video/mp4`, `video/webm` ou `video/quicktime`, com extensão `mp4`, `webm` ou `mov`. `UploadFile` mantém o limite local de 128 MiB e verifica novamente o SHA-256 antes de transferir os bytes. Esses limites e formatos são o recorte implementado pelo SDK, não todos os formatos ou tamanhos aceitos pelo Facebook.

O fluxo usa o início Web em `vupload-edge.facebook.com`, consulta o deslocamento em `rupload.facebook.com/fb_video/...`, envia o trecho restante e confirma o recebimento no endpoint Web de finalização. Os bytes são transmitidos em PHP com a interface opcional `RawUploadWebSession`; não são encaminhados como foto ou como arquivo multipart. Configurações que indiquem hosts ou rotas diferentes dos contratos observados são recusadas.

| Campo do resultado | Interpretação |
| --- | --- |
| `video_id` | ID exato do asset retornado pelo início do upload. |
| `uploaded` | Transferência e finalização confirmadas pelo formato implementado. |
| `published` | Sempre `false`; não foi criado um post. |
| `bytes` | Tamanho do arquivo selecionado. |
| `resumed_from_offset` | Deslocamento informado pelo servidor antes da transferência. |
| `upload_skipped` | O início informou explicitamente que os bytes já estavam disponíveis. |
| `processing_ready` | `null`; este método não confirma o processamento ou a reprodução do vídeo. |

Um deslocamento não zero faz o transporte enviar somente o restante do arquivo. Mesmo quando todos os bytes já estão presentes, é necessário obter o handle opaco de conclusão. Esse handle não é publicado no resultado nem persistido no ledger. A consulta de deslocamento não constitui retomada automática de um processo interrompido: depois de timeout ou resultado incerto, a mesma chave não repete o início, a transferência ou a finalização.

O helper implementa o pipeline `MediaUploadFBUploadServiceRequest`: seu uploader recebe o arquivo original inteiro e `ResumableUploadServiceComet` envia o sufixo a partir do `Offset` consultado. `start_offset` e `end_offset` do início continuam no identificador e nos headers da transferência; não são tratados como o limite de um blob previamente recortado. O fluxo antigo de `VideoUploadReceiveRequestManager`, que recebe chunks já recortados, não é combinado com este contrato.

## Publicar fotos já carregadas

`publishPhotos($actorId, $photoIds, $text, $idempotencyKey, $context, $variables)` usa o `ComposerStoryCreateMutation` existente e o formato nativo `attachments: [{photo: {id: ...}}]`. A chamada aceita de uma a 50 fotos distintas. A legenda do post pode ser vazia.

```php
use MVO\Facebook\FacebookConnection;

function publicarFotosCarregadas(
    FacebookConnection $facebook,
    string $actorId,
    array $idsRetornadosPelosUploads,
    string $legenda,
    string $chaveDaPublicacao,
    array $contextoAtualDoCompositor,
    array $variaveisRelayAtuais,
): array {
    return $facebook->media()->publishPhotos(
        $actorId,
        $idsRetornadosPelosUploads,
        $legenda,
        $chaveDaPublicacao,
        $contextoAtualDoCompositor,
        $variaveisRelayAtuais,
    );
}
```

O contexto é o mesmo contrato de [criação de publicação](PUBLICACOES.md): `composer_entry_point`, `composer_source_surface`, `composer_type=profile`, `logging.composer_session_id`, `audience` e `tracking`; `navigation_data` e `is_tracking_encrypted` são opcionais. Os providers Relay atuais pertencem a `$variables`. Não preencha providers desconhecidos com `false` nem invente um público. Este helper cobre o compositor de timeline de perfil observado; não promete todos os fluxos de publicação de Página, reels ou anúncios.

O resultado contém `story_id`, `post_id`, `url`, `published`, `attachment_ids_submitted` e `client_rendering_validated=false`. A confirmação do ID da publicação não demonstra a renderização das fotos em um cliente real. A chave da publicação deve ser diferente das chaves dos uploads; a aplicação não deve recriar uma publicação automaticamente depois de uma resposta incerta.

## Publicar um vídeo já carregado

`publishVideo($actorId, $videoId, $text, $idempotencyKey, $context, $variables)` implementa o branch `VIDEO` do transformador Web. Ele usa o mesmo contexto atual de timeline de perfil descrito acima e o ID retornado pelo upload de vídeo. Sua chave é independente da transferência.

```php
use MVO\Facebook\FacebookConnection;

function publicarVideoCarregado(
    FacebookConnection $facebook,
    string $actorId,
    string $videoId,
    string $legenda,
    string $chaveDaPublicacao,
    array $contextoAtualDoCompositor,
    array $variaveisRelayAtuais,
): array {
    return $facebook->media()->publishVideo(
        $actorId, $videoId, $legenda, $chaveDaPublicacao,
        $contextoAtualDoCompositor, $variaveisRelayAtuais,
    );
}
```

O anexo de vídeo conserva o formato próprio do Web: `audio_descriptions=null`, `additional_video_metadata.translatedAudioMetadata=[]`, `notify_when_processed=true`, `transcriptions=null` e `was_created_via_unified_video_flow=null`, além do ID. Essa é a variante sem edição, colaboração, playlist, tradução de áudio, trilha musical ou crop. Os helpers públicos retornam `null` para descrições/transcrições ausentes; `buildComposerVideoGenerationParams` não cria parâmetros de geração quando não há crop, trim ou mixagem. O SDK não inventa esses parâmetros nem envia um objeto de foto com outro nome.

A publicação não confirma que o processamento de vídeo terminou, que o conteúdo foi convertido em reel ou que um cliente conseguiu reproduzi-lo. O resultado segue a projeção da publicação de fotos, com `client_rendering_validated=false`. Fluxos gerais de Página, reels e edição audiovisual exigem contratos separados.

## Criar e administrar álbuns

`albums()` usa documentos próprios, listados em [media-documents.json](../../packages/facebook/resources/media-documents.json). Cada mutação exige uma chave de idempotência e os providers atuais de seu documento. `MediaDocuments::providers($documentName)` lista as variáveis necessárias; a função não obtém nem fabrica seus valores.

| Método | Parâmetros principais e comportamento |
| --- | --- |
| `get(actorId, referenceToken, variables)` | Consulta o álbum pelo token opaco recebido do Facebook; retorna metadados e a primeira página de itens. Não transforme um ID numérico em token inventado. |
| `items(actorId, albumId, cursor=null, count=8, scale=1.0)` | Lista fotos e vídeos com seus IDs exatos e `page_info`; aceita de 1 a 50 itens por consulta. |
| `create(actorId, title, description, privacy, creationSessionId, key, variables, contributors=[])` | Cria o contêiner do álbum; retorna `album_id`, `url`, `created=true` e `media_added=false`. |
| `edit(actorId, albumId, changes, key, variables)` | Altera os campos explicitamente enviados. `changes` aceita `title`, `description`, `privacy`, `contributors_to_add` e `contributors_to_remove`. |
| `delete(actorId, albumId, key, variables)` | Exclui o álbum conforme o fluxo nativo. A exclusão também pode remover seu conteúdo; não é uma operação local de organização. |
| `addMedia(actorId, albumId, attachments, creationSessionId, key, variables)` | Adiciona assets já carregados, com legenda opcional por item. |
| `setCover(actorId, albumId, photoId, key, variables)` | Atualiza a capa e exige que o retorno confirme o mesmo álbum e foto. |
| `reorder(actorId, albumId, mediaIds, key, variables)` | Define a ordem de uma lista de IDs; só confirma quando o retorno exibe todos na ordem solicitada. |

`variables` precisa do `scale` numérico positivo do contexto Web e dos providers do documento correspondente. A privacidade de criação/edição usa o objeto atual do compositor com `allow`, `base_state`, `deny` e `tag_expansion_state`. Não associe automaticamente o álbum a um público maior. As listas de colaboradores conservam os IDs selecionados pela aplicação.

```php
use MVO\Facebook\FacebookConnection;

function criarAlbumSemAdicionarArquivos(
    FacebookConnection $facebook,
    string $actorId,
    string $titulo,
    string $descricao,
    array $privacidadeAtual,
    string $uuidDaCriacao,
    string $chave,
    array $variaveisRelayAtuais,
): array {
    return $facebook->albums()->create(
        $actorId, $titulo, $descricao, $privacidadeAtual,
        $uuidDaCriacao, $chave, $variaveisRelayAtuais,
    );
}
```

Em `addMedia`, cada item é um objeto como `['type' => 'photo', 'id' => $photoId, 'caption' => $legenda]` ou `['type' => 'video', 'id' => $videoId]`. São aceitos de um a 50 IDs distintos. O SDK verifica no resultado o ID do álbum e a presença dos assets solicitados; um payload sem essa confirmação não se torna sucesso.

Criar o álbum e adicionar os arquivos são dois comandos distintos. O próprio fluxo Web admite que a criação tenha sucesso e a adição falhe. Guarde o `album_id` criado e inspecione o comando da adição; não repita a criação para tentar corrigir o segundo passo.

## Editar legenda e excluir um item

`media()->editCaption($actorId, $type, $mediaId, $text, $key, $variables=[])` altera a descrição da foto ou vídeo, inclusive quando exibido em um álbum. Use `photo` ou `video`; uma string vazia remove a descrição. Fotos exigem `scale` atual nas variáveis. O retorno precisa confirmar o ID e o texto solicitado.

`media()->deleteMedia($actorId, $type, $mediaId, $key, $context, $variables)` **apaga o asset**, não apenas um vínculo no álbum. O resultado conserva `scope=asset`. Informe os flags reais atuais em `context`: `isCoverPhoto` e `isProfilePic` para foto; `isProfileVideo` para vídeo. As variáveis contêm `scale`, providers e, quando disponível, `renderLocation` do visualizador. O helper não presume que os flags sejam falsos.

Mover um item entre álbuns, cortar/girar a imagem, editar marcações e fazer edição completa de vídeo não estão incluídos nesses métodos. Acesso, permissões, processamento e disponibilidade de cada operação continuam determinados pelo Facebook.

## Evidência de vídeo, publicação e álbuns

O protocolo foi recuperado de módulos públicos Web: `useComposerVideoUploaderConfig`, `MediaUploadFBDefaultServerConfigurationRetriever`, `MediaUploadFBStartRequest`, `MediaUploadFBUploadServiceRequest`, `ResumableUploadServiceComet`, `MediaUploadFBReceiveRequest`, `mediaAttachmentAreaCreationDataTransform`, `mediaAttachmentAreaTransformUtil`, `buildComposerVideoGenerationParams`, `getVideoAudioDescriptionData`, `getVideoTextTranscriptData`, `useCometPhotoAlbumCreate`, `useCometComposerAlbumEditSubmit`, `composerAlbumMediaAttachmentTransform` e os handlers nativos de legenda/exclusão. O [catálogo de mídia](../../packages/facebook/resources/media-documents.json) conserva IDs, variáveis, defaults observados, fontes e SHA-256. Esses documentos Web não são a API oficial nem a Marketing Graph interna.

Os testes locais verificam endpoints, actor/dono, deslocamento parcial, transmissão do trecho selecionado, handle ausente, finalização incompleta, asset diferente, idempotência, ausência de caminho/tokens no resultado persistido, CRUD e confirmação das mídias. Eles usam transportes e respostas sintéticos. **Nenhum vídeo/foto foi carregado ou publicado e nenhum álbum foi alterado em conta real nesta implementação.** A compatibilidade em uma conta e a exibição no cliente exigem validação real separada.

Fontes: [MediaService](../../packages/facebook/src/Service/MediaService.php), [AlbumService](../../packages/facebook/src/Service/AlbumService.php), [PhotoUploadContext](../../packages/facebook/src/Media/PhotoUploadContext.php), [VideoUploadContext](../../packages/facebook/src/Media/VideoUploadContext.php) e [MediaDocuments](../../packages/facebook/src/Catalog/MediaDocuments.php).
