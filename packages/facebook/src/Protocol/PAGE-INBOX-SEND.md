# Envio experimental pelo Inbox da Página

`InboxService::sendText($actorId, $pageId, $selectedItemId, $text, $idempotencyKey, $context)` oferece o caminho **Mercury legado** observado nos bundles públicos do Inbox. Não é Graph API, não usa uma mutation GraphQL inventada, não envia como a conta pessoal e não usa a mutation WhatsApp `WECRichMessage`.

O serviço precisa receber o mesmo `WebSession` e o `CommandLedger` privado usados pelo `WebGraphQL`. O construtor continua compatível com leitura: `new InboxService($graph, $session, $commands)`. Uma instância construída somente com `$graph` bloqueia envio antes do I/O.

## Contexto necessário

O transporte legado exige valores do contexto Mercury real da conversa. O SDK não transforma números, PSIDs, IDs GraphQL ou IDs de outro canal em identidades inventadas. O array `$context` aceita somente:

| Campo | Origem/uso |
| --- | --- |
| `recipient_id` | ID numérico exato do usuário retornado em `thread_key.other_user_id`. |
| `client_thread_id` | ID Mercury real `user:<recipient_id>` ou `thread:<thread_fbid>`; precisa corresponder ao thread retornado. |
| `client` | Identificador atual do cliente Mercury, preservado literalmente. |
| `source` | Source Mercury real do composer, com prefixo `source:`. Não é permissão. |
| `message_id` | ID local de mensagem do composer Mercury. Não use um exemplo fictício como ID real. |
| `offline_threading_id` | OTID decimal original, de até 63 bits. |
| `timestamp_ms` | Timestamp decimal original do OTID, em milissegundos. |
| `origin` | Opcional: `https://business.facebook.com` (padrão) ou `https://www.facebook.com`, conforme o Inbox observado. |
| `privacy_mutation_token` | Opcional, somente quando fornecido pelo contexto atual. |

`PageInboxSender::newOfflineIdentity()` produz somente `offline_threading_id` e `timestamp_ms` com o formato público `timestamp << 22 | random22`. A aplicação guarda esses valores, o ID local de mensagem real e o contexto completo **antes do envio**. Em uma repetição da mesma chave, deve reutilizar o mesmo contexto; gerar outro OTID produz conflito de idempotência.

Não há aquisição automática desse contexto pelo runtime atual: o método exige que a integração já o possua. Não passe `can_send`, `is_admin` ou flags de autorização inventados; esses campos são rejeitados.

## Escopo e resultado

Antes do POST, o serviço consulta `BizInboxUnifiedThreadsQuery` com Página, ator e seleção exatos. Exige `selected_page.id` correspondente, thread/usuário consistentes, `composer_input_disabled=false` e `cannot_reply_reason=null`. Esta primeira versão é limitada a uma conversa individual com `other_user_id`; grupos ou contexto desconhecido são recusados. Uma consulta bem-sucedida continua sujeita à decisão de autorização do servidor no envio.

Os cookies e `__user` continuam pertencendo ao dono autenticado; `av` preserva o ator informado; `request_user_id` é a Página. O POST vai somente para `/messaging/send/` no origin permitido. `signature_id` é MD5 do OTID conforme o serializador público — não uma assinatura criptográfica de confiança.

O ledger inclui todo o conteúdo e contexto no hash antes do I/O. O OTID/timestamp também são preservados na projeção de incerteza antes do POST. Uma resposta perdida não causa reenvio; chamadas repetidas sem confirmação são bloqueadas. Uma falha da consulta de contexto é registrada com `execution_started=false`, sem afirmar que houve envio.

Confirmação requer exatamente uma action com o OTID original, um ID de mensagem de servidor distinto do ID local, thread correto, autor `fbid:<Page ID>` e corpo exato. Um `other_user_fbid` retornado também precisa corresponder. A associação de destinatário já foi validada pela consulta do thread; não é extraída de um ID numérico arbitrário. Corpo desconhecido, action ambígua, HTTP 200 vazio ou resultado sem correlação não produzem sucesso. O retorno `server_ack` mantém `delivery_confirmed=false` e `read_confirmed=false`.

## Evidência e limites

`resources/inbox-protocol-contracts.json` fixa URLs CDN, SHA-256 dos bundles e dos módulos. `MercuryServerSendMessageQueue`, `MercuryServerDispatcher`, `XMessagingSendController`, `MercuryIDs` e `MercuryLocalIDs` estabelecem o contrato legado. As respostas dos testes são sintéticas; não houve envio a uma Página ou destinatário real e não foi demonstrada aceitação do caminho legado na conta atual.

A evidência estática REA `ev_143e6fffdb53ca4a0de5f980dcd4aa05165462e975a96746e30ff59158f43173` tem raiz SHA-256 `b4f214afb469e2996ba346c0016384f4c1038507876bf757abd55ea042099539`: sete módulos parseados sem erro, sem execução, cobertura semântica desconhecida e 1838 pontos não resolvidos. Isso fundamenta os contratos observados, sem atestar funcionamento autenticado ou disponibilidade atual do endpoint legado.

## Caminho atual Lightspeed

`InboxService::sendTextLightspeed()` implementa separadamente o caminho observado `useBizInboxLSMessengerMessageActions` → `useBizInboxLSSendMessage` → task Lightspeed 46. O payload usa `UNIFIED_BUSINESS_INBOX=4456448`, `sync_group=205`, `send_type=1` e `mark_thread_read=true`, conforme o composer e seu serializador. Não acrescenta um `sender_fbid` inventado.

`LSBizInboxClientConfig` utiliza a identidade autenticada pessoal em `RawDGWClient`, mas cria o transporte com os assets da Página. `LSWeaveInboxDGWTransport` transforma `PAGE => Page ID` em `streamGroupAppHeaders.asset_ids`; `DGWUtils.constructConnectUrl` acrescenta o prefixo `x-dgw-app-`. O novo `PageMailboxConfig::gatewayUrl()` preserva `x-dgw-uuid` do dono e inclui explicitamente `x-dgw-app-asset_ids={"PAGE":"ID real"}`. Um stream pessoal sem essa vinculação não é equivalente.

O runtime original deve fornecer a capacidade `PageMailboxWebSession::pageMailbox(PageMailboxConfig): PageMailboxTransport`, criando `PageMailboxGateway` com o mesmo `SessionStore`/lock e sua verificação de ciclo de vida. A classe nativa abre um grupo DGW separado, usa cookies somente no handshake autorizado, espera estabelecimento/ACK e resposta com o mesmo request ID, e fecha seu socket sem liberar o lock principal. Um `WebSession` sem essa capacidade bloqueia o método antes de qualquer requisição de envio.

O contexto desse método contém `dgw_app_id`, `dgw_app_version`, `server_app_id`, `schema_version`, `device_id`, `recipient_id`, `offline_threading_id`, `epoch_id` e opcional `origin`. Os valores de gateway vêm de `DGWWebConfig`; `server_app_id` é o `ServerAppID.app_id` atual; versão vem de `LSVersion`. Não há fallback entre esses aplicativos nem número de versão hardcoded. OTID e epoch precisam ser preservados com a chave de idempotência antes de enviar. Esses dados não são cookies, CAT ou permissões.

### Aquisição do contexto sem copiar dados do navegador

`InboxService::lightspeedContext($actorId, $pageId, $selectedItemId)` faz a consulta de seleção da Página/thread e depois somente o GET autenticado de `https://business.facebook.com/latest/inbox/all?asset_id=...&selected_item_id=...`. Não abre DGW, não registra dispositivo E2EE e não envia mensagem. O leitor `PageMailboxBootstrapReader` processa JSON inerte e exige `CurrentUserInitialData`, `DGWWebConfig` e `ServerAppID` consistentes com o dono. Quando o HTML também fornece `selected_page.id`, esse ID precisa coincidir. A consulta de seleção anterior continua obrigatória; parâmetros da URL não provam autorização.

Se `LSVersion` estiver somente em um bundle público, o método lê os URLs CDN presentes no documento atual e usa um loader anônimo, com limites de tamanho/tempo e sem cookies, para extrair exclusivamente o export literal da versão. Código JavaScript não é executado. Configurações ausentes, identidade divergente ou versões conflitantes são erros; o SDK não substitui esses dados por constantes conhecidas.

O cliente BizInbox observado usa ReStore efêmero. Sua cadeia `LSPlatformDeviceId` → `ChannelClientID` permite UUIDv4 criado pelo próprio cliente. O SDK mantém um UUIDv4 próprio por conta no banco privado `CommandLedger`, inclusive após reiniciar. Isso identifica o cliente Lightspeed do SDK; não copia `MqttWebDeviceID`, storage/cookie do navegador ou identidade criptográfica. Não equivale ao cadastro de um dispositivo E2EE.

```php
// Os IDs vêm das consultas da conta; selectedItemId é selection_id de threads().
$context = $facebook->inbox()->lightspeedContext($actorId, $pageId, $selectedItemId);
// A aplicação persiste $context junto de sua chave ANTES do envio.
$result = $facebook->inbox()->sendTextLightspeed(
    $actorId, $pageId, $selectedItemId, 'Olá! Como podemos ajudar?', $idempotencyKey, $context
);
```

Em uma repetição da mesma chave, reutilize exatamente o contexto persistido; chamar `lightspeedContext()` novamente gera um novo OTID/epoch e representa uma tentativa diferente. O remetente repete sua própria consulta de seleção imediatamente antes do envio; o contexto não é uma autorização permanente. O parâmetro opcional `$bundleLoader` existe para transporte de assets públicos e testes; seu contrato é `callable(list<string>): list<string>`.

`PageMailboxSender` repete a verificação da Página/thread/usuário via leitura Web antes de criar o gateway. Exige que o transporte devolvido preserve exatamente a configuração solicitada. Persiste incerteza antes de conectar/enviar; processa a resposta somente como AST Lightspeed inerte, sem baixar/executar JavaScript. Confirmação exige um único `LSReplaceOptimisticMessage`/`LSReplaceOptimsiticMessage` correlacionado e uma linha `LSUpsertMessage`/`LSInsertMessage` com o mesmo servidor ID, OTID, Página remetente, thread e texto. Um ACK binário, substituição otimista isolada, formato desconhecido ou linha divergente não prova envio.

Existe também `LSBizInboxGraphQLLightspeedRequestQuery` (`24374347955599176`) com `pageAssetId`, dispositivo e request payload. A cadeia observada usa essa query para sincronização inicial/leitura. O envio task 46 passa por `LSBizInboxTaskSystemPipeline` → `LSPlatformTaskSystemTransport` → transporte realtime. **Não foi assumido que essa query possa enviar tarefas tipo 3.** O caminho implementado usa DGW com assets, sem transformar uma query de sincronização em mutation de envio.

`resources/inbox-lightspeed-contracts.json` fixa as origens e hashes da cadeia atual, inclusive origem do UUID e rota do Inbox. Testes locais validam bytes, vinculação de conta/Página, idempotência, protocolo DGW sintético, correlação, leitura de bootstrap e persistência do UUID. A aquisição automática está implementada com fixtures sintéticas; ainda depende de validação de uma resposta autenticada atual. Não foi aberto um gateway Page real nem enviada mensagem de produção nesta validação. O acompanhamento realtime contínuo do inbox ainda não está integrado.
