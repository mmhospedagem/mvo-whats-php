# Integração dos componentes E2EE pelo dono Messenger

Estas fontes pertencem ao checkout local em desenvolvimento. Não são uma afirmação de suporte E2EE já publicado ou de interoperabilidade atual validada com uma conta Meta. Nenhum registro, upload de pré-chave ou envio real foi executado nesta implementação.

## Orquestrador do dono

`Runtime` amarra os componentes abaixo. O construtor recebe o **mesmo** `SessionStore`, `accountId`, `callable():BootstrapConfig` atual, browser user agent, `ownerGuard`, `socketFactory` opcional com a assinatura do cliente, `RosterVerifier` opcional, handler `(string $evento, array $dados)`, timeout e raiz sintética opcional para testes. A raiz de produção é fixada; não existe opção para desligar certificados.

O seam já está ligado a `MessengerClient::e2eeRuntime()` e `FacebookConnection::e2eeRuntime()`. Prefira essas fachadas: retornam o componente do dono conectado, mantido por cache `WeakReference` enquanto houver uma referência externa. Elas obtêm bootstrap/versão/CAT e encaminham eventos ao pai, sem expor `RosterVerifier` ou `testRootPublic`. Não iniciam E2EE no login comum. O construtor abaixo é a fronteira **interna** de integração/testes; aplicações não devem abrir outro dono para usá-lo.

```php
// Integração interna do dono existente; não abra outro SessionStore/lock.
$runtime = new Runtime(
    $store, $accountId, $bootstrapAtual, $browserUserAgent,
    ownerGuard: $validarDonoConectado,
    socketFactory: $socketFactory,
    eventHandler: $eventosPrivados,
);
$runtime->connect(); // Operação explícita: pode publicar pré-chaves públicas salvas.
```

O runtime nunca registra um dispositivo. Restaura somente chaves que correspondem a um registro confirmado (conta, UUID, fingerprint e device ID); valida CAT, origem e versão antes de abrir o socket. GMP/zlib ausentes bloqueiam antes do I/O. `connect(int $initialPrekeys=50)` executa XX, aguarda `success` E2EE, recusa conta/aparelho divergentes, salva pré-chaves antes de publicá-las e só as marca após o IQ `result` correspondente. A incerteza é persistida antes do upload e bloqueia republicação/regeneração automática. Um canal Noise ou metadados LS não confirmam login.

**Versão comprovada:** o módulo público `MAWAppVersion` observado em 18/10/2026 calcula `302.0.SiteData.client_revision`. `BlueWebVersion::fromBootstrap()` aplica essa fórmula à revisão do bootstrap atual; não usa AnalyticsCoreData, LS version, `301.0.2` ou revisão salva de um exemplo. Artefato first-party SHA-256 `2723f035edbfb9672564da69844951ae8eec172181af86fe7e21c002b34906aa`, inventariado em `implementation-provenance.json`. Isso fixa o perfil observado, sem provar sua aceitação futura pelo servidor.

**Bloqueio concreto restante:** não foi obtido um contrato completo comprovado que ligue o blob ICDC remoto aos device IDs, identidade signatária, sequência confiável e identidades dos bundles. Não há um `RosterVerifier` de produção. O padrão permite login/prekeys/leitura da descoberta, mas bloqueia texto de saída e plaintext de entrada com `messenger_icdc_verification_unavailable`. Implementações injetadas desse contrato são uma fronteira interna de protocolo; não exponha booleanos de permissão ou um callback que simplesmente retorna. A política sintética dos testes não implementa ICDC Meta.

A [revisão dos bytes first-party](ICDC-EVIDENCE.md) registra módulos, hashes, ponte `waapi`, recurso dinâmico do worker e os campos/âncoras que ainda faltam, com limites explícitos da análise REA.

Quando houver um verificador nativo comprovado:

- `reviewPeer(string $recipientBare): array` busca aparelhos do destinatário **e da própria conta**, exige presença do aparelho ativo e projeta snapshot/fingerprints. Não aprova identidades.
- `approvePeer(string $snapshotHash): array` exige o snapshot atual já verificado; aprova fingerprints e verifica assinaturas/sessões atomicamente. Identidades existentes não são substituídas. Um hash revisado não contorna o bloqueio ICDC.
- `sendText(string $recipient, string $text, string $idempotencyKey, ?string $messageId=null): array` refaz descoberta/bundles e exige exatamente o roster aprovado para uma nova operação. Replay confirmado não consulta nem envia novamente; submitted/unknown bloqueia sem I/O adicional.
- `poll(float $timeout=0.5): ?array` trata mensagens/ACKs/recibos diretos. Ciphertext entra numa fila privada durável antes do ACK ao servidor; decriptação exige confiança e commit atômico. Não envia leitura automaticamente.
- `retryPendingIncoming(int $after=0,int $limit=100): array` revisita até 500 sequências por página; mantém corpos bloqueados. `flushReceipts(int $after=0,int $limit=100): int` envia recibos de entrega pendentes fora da transação.
- `messages($after,$limit)`/`outgoing($id)` são projeções locais privadas; `close()` exige o dono. `ownerStopped()` é o cleanup idempotente do pai mesmo após liberar o lock.

O pai chama `ownerStopped()` ao parar, deslogar ou perder a conexão, antes de liberar o lock. O destrutor também fecha o socket quando a última referência do runtime é liberada, inclusive transportes injetados ainda retidos pelo factory; esse cleanup não executa ownerGuard. O guard fecha o socket quando o dono/conta deixa de valer ou o CAT expira. Callbacks não podem emitir comandos. Eventos: `encrypted_transport_connected`, `encrypted_message`, `encrypted_message_blocked`, `encrypted_message_receipt`, encaminhados aos listeners do pai; o evento de mensagem contém dado privado da aplicação. `status()` mantém `e2ee_ready=false` e distingue confirmação de transporte, prekeys, verificador configurado e limites de interoperabilidade. O runtime não é iniciado automaticamente pelo login LS.

## Fronteiras para o loop

O mesmo dono `SessionStore`, conta e lock precisam permanecer ativos. Não crie um segundo dono para os componentes. O runtime/fachada deve obter as chaves salvas e **registro confirmado**, validando o vínculo entre conta, UUID, fingerprint e ID de aparelho; nunca montar uma identidade de WhatsApp ou usar flags fornecidas pelo consumidor como confirmação.

1. `Noise\NoiseConnection(Socket $socket, float $timeoutSeconds=20, ?string $testRootPublic=null)` mantém enquadramento, XX e criptografia de tráfego. Endpoint do perfil de referência: `NoiseConnection::URL`. Origin deve ser a origem Messenger/Facebook autenticada, com o browser user agent atual; não copie cookies de outro domínio. A injeção de raiz existe para peers sintéticos. Produção usa a raiz fixada na classe, sem desativar verificação. Não há IK/resume Messenger presumido.
2. `Protocol\ClientPayload::login(DeviceKeys $keys, int $registeredDeviceId, CryptoAuthToken $token, array $appVersion, string $browserAgent): string` gera o payload privado. A versão BLUE_WEB precisa vir de evidência atual; não há fallback `301.0.2`. CAT inválido, expirado ou de outra conta bloqueia antes do handshake. `DeviceKeys::noiseKeyPair()` e `signalCredentials()` são fronteiras internas **secretas**; não são resultados de API, eventos, jobs ou logs.
3. `NoiseConnection::start($keys->noiseKeyPair(), $payload)` confirma somente Noise/certificado. Ainda é preciso processar o `success` do protocolo de login e recusar `failure`, `stream:error`, identidade divergente, fechamento ou timeout. `isEstablished()` não equivale a `e2ee_ready`.
4. `Signal\SignalStore(SessionStore $owner, string $accountId)` e `Signal\SignalProtocol($store, $keys->signalCredentials())` mantêm sessões, identidades e pré-chaves no **mesmo banco/lock/transação**. Os registros usam namespaces exclusivos Messenger. `approveIdentity($deviceJid,$rawIdentity,$reviewedSha256)` é decisão explícita inicial; identidades ausentes/trocadas bloqueiam. Assinatura da pré-chave ainda precisa verificar. Não existe auto-trust/retry apagando identidades.
5. `Protocol\DeviceDirectory` cria/projeta IQs de descoberta e bundles. O loop precisa correlacionar cada resposta ao ID/tipo/escopo solicitados. A descoberta inclui destinatário e a própria conta; o resultado **não verifica sozinho ICDC/trust remoto**. A referência consultada também deixava essa verificação em aberto. Não anunciar inventário autenticado criptograficamente apenas por receber `dhash`.
6. Salve chaves com `SignalStore::generatePreKeys()` **antes** de montar `DeviceDirectory::uploadPrekeys()`. Use `pendingPreKeys()` para a parte pública. Somente o IQ `result` correlacionado autoriza `markPreKeysUploaded($idsExatos)`. Falha/timeout conserva os privados e o estado pendente. O builder não envia nem confirma o upload.
7. `MessageEngine($store,$signal,$ownDeviceJid)` é o journal de texto. `prepareText($messageId,$idempotencyKey,$recipientBareJid,$text,$targetDevices)` consome sessões já aprovadas para todos os aparelhos atuais do destinatário e demais aparelhos próprios. Não aceita fan-out parcial. A própria cópia recebe DSM com destino original; o aparelho ativo é excluído. Ratchets e ciphertext do outbox são commitados juntos.
8. `submit($messageId, callable(BinaryNode):void $send)` grava incerteza **antes** da chamada de rede. Passe `NoiseConnection::sendNode(...)`. Uma resposta perdida nunca regenera envelopes/ratchets ou retransmite automaticamente. `outgoing()` devolve apenas projeção segura. Replay confirmado não envia; tentativa submetida/incerta continua bloqueada para conciliação.
9. `acknowledge(BinaryNode $node)` aceita ACK de mensagem ou recibo direto correlacionado. `server_ack` é aceite; recibos do destinatário podem avançar para `delivered/read`, sem regressão. Origem/ID/tipo divergentes não confirmam entrega. Retry, recibos em lote e recuperação automática de entrega precisam de política/integrador próprios; não converter tipos desconhecidos em sucesso.
10. `receive(BinaryNode $node)` exige um corpo direto `enc v=3`/`msg|pkmsg`. Signal, envelope, deduplicação, plaintext do inbox e recibo pendente commitam na mesma transação. Adulteração falha sem consumir o ratchet/pré-chave. `messages($after,$limit)` é histórico local, não Labyrinth. `pendingReceipts()`/`receiptSent()` mantêm a fila local; a aplicação faz o envio fora da transação. Não enviar recibo de leitura automaticamente como se houvesse interação humana.

O consumidor não deve passar árvores arbitrárias de um webhook como se tivessem vindo do Noise autenticado. O codec só suporta endereços nativos `FacebookID:device@msgr`, inclusive o token binário FB_JID 246. Interop, domínios WhatsApp, grupos e formatos de conteúdo fora do perfil texto são recusados. IDs não são telefones, LIDs ou usernames.

## Envelope e franking

`Protocol\MessageEnvelope` usa ConsumerApplication versão 1, MessageApplication versão 2 e transporte `enc v=3`. A aplicação carrega texto e chave aleatória de franking de 32 bytes. O HMAC autentica os bytes exatos da aplicação. Um tag encaminhado pelo servidor é verificado; quando ausente, o texto continua sujeito ao MAC Signal e a projeção conserva `franking_verified=false`, sem fabricar um tag validado. Versões/oneofs desconhecidos não geram texto vazio de sucesso.

## Prova local e limite de prontidão

Os testes executam pares PHP independentes, verificação de certificados válidos/expirados/futuros/raiz/estática/assinatura divergentes, HKDF RFC 5869, X3DH/Double Ratchet, pré-chave consumida uma vez, mensagens fora de ordem, restauração SQLite, isolamento/trust, fan-out/DSM, franking, rollback, journal e incerteza/recibos. O peer de Noise é sintético: não contata a Meta. Isso demonstra contratos/estado locais, não aceitação do servidor atual.

Ainda exigem validação pelo dono: renovação CAT observada, verificador ICDC remoto com vínculo de dispositivos/identidades e sequência confiável, manutenção/rotação, retries e recibos em lote, outros conteúdos e histórico seguro/Labyrinth. Login/consultas/uploads já têm orquestração e integração às fachadas com testes de confirmação, sem execução real. `DeviceManager::status()` continua sem declarar prontidão; não ligue `e2ee_ready` apenas porque chaves, componentes ou socket existem.

## Origem do código

`implementation-provenance.json` fixa hashes das primitivas MIT do próprio SDK MVO que foram isoladas/adaptadas para o pacote. Não existe dependência do autoload/estado WhatsApp. Nenhuma implementação AGPL foi copiada ou traduzida. Campos do perfil e schemas públicos são referenciados no inventário da investigação fixado em `mautrix/meta@1b3bd5d` e `whatsmeow@f16f9ca`; não equivalem a evidência first-party atual do worker criptográfico. A aritmética GMP de assinatura não garante tempo constante. Os settings são privados, sem promessa de criptografia adicional em repouso.
