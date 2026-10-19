# Dispositivo Messenger E2EE

O componente experimental implementa **provisionamento do dispositivo**, separado do transporte de mensagens criptografadas. Ele gera identidade, chave Noise e pré-chave assinada próprias, preserva as chaves no armazenamento privado da conta e oferece leitura/registro ICDC explícitos. `e2ee_ready` continua `false`: registro não comprova handshake, sessões Signal ou envio/recebimento.

Estas extensões pertencem ao checkout em desenvolvimento. Confira a versão instalada antes de usar; as primeiras releases Facebook 0.1.0/Messenger 0.1.1 não contêm esta API.

## Runtime explícito da conexão

`FacebookConnection::e2eeRuntime()` e `MessengerClient::e2eeRuntime()` retornam o componente do mesmo dono conectado, fora dos callbacks. A fachada mantém cache fraco e reutiliza o runtime enquanto a aplicação conserva sua referência; não abre outro dono, banco ou lock. O bootstrap, a versão, o CAT, o guard e os eventos vêm desse dono. A fachada não expõe injeção de `RosterVerifier` ou raiz de certificado de teste.

```php
$runtime = $facebook->e2eeRuntime();
$status = $runtime->status(); // Somente estado local; não registra nem conecta E2EE.

// Operação explícita posterior ao provisionamento confirmado:
// pode publicar as pré-chaves públicas salvas na conta.
// $runtime->connect();
```

O login comum não inicia esse componente. Stop, logout ou perda da conexão encerram seu socket pelo cleanup do pai; liberar a última referência também fecha o transporte, sem depender de um guard ainda ativo. Eventos `encrypted_transport_connected`, `encrypted_message`, `encrypted_message_blocked` e `encrypted_message_receipt` são encaminhados aos listeners do dono. Conteúdo de mensagens é dado privado da aplicação. Comandos permanecem no loop externo, após o callback.

## Preparar as chaves

Com `FacebookConnection` já conectado, fora dos callbacks:

```php
use MVO\Facebook\FacebookConnection;

function prepararDispositivo(FacebookConnection $facebook): array
{
    $device = $facebook->e2eeDevice();
    return $device->prepare(); // Somente local; não registra na conta.
}
```

O dono mantém o mesmo lock. Repetir `prepare()` restaura as chaves existentes; não cria uma identidade nova a cada início. Além das extensões do pacote, esse componente exige GMP. As sessões WhatsApp não são acessadas ou reutilizadas.

## Consultar e registrar

`registrationRoster()` faz uma leitura autenticada e projeta `sequence`, `devices`, fingerprints SHA-256, `snapshot_hash` e `remote_device_trust_verified=false`. Não retorna CAT, cookies ou chaves privadas. O inventário obtido por HTTPS não substitui a verificação criptográfica de confiança nos aparelhos remotos.

`register(string $expectedRosterHash)` altera a conta e deve ser chamado somente após a decisão explícita do operador de adicionar um dispositivo. Busca novamente o inventário e recusa mudanças desde o snapshot revisado. O método não é executado por `connect()` ou `poll()`.

```php
// Integração que já recebeu a decisão explícita de adicionar o dispositivo:
// $device = $facebook->e2eeDevice();
// $reviewedRoster = $device->registrationRoster();
// $status = $device->register($reviewedRoster['snapshot_hash']);
```

O registro só é confirmado por resposta reconhecida com HTTP/status 200, produto `msgr` e ID de aparelho válido. Essa confirmação continua independente de `e2ee_ready`.

## Incerteza e persistência

Antes da escrita remota, salva `registration_uncertain`. Timeout ou resposta incompleta conservam as mesmas chaves e bloqueiam repetição automática. Não apague a pasta nem gere outra identidade para contornar uma tentativa incerta. A reconciliação de registro incerto ainda não é automática.

O CAT vem do bootstrap autenticado, com identidade e validade verificadas. Não há fallback para token/app inventado. Chaves e CAT não devem ser serializados, logados ou enviados ao contexto de uma LLM. O armazenamento é privado, com permissão restrita; não há promessa de criptografia adicional em repouso. A primitiva XEdDSA GMP não oferece garantia de tempo constante.

## Componentes de mensagens no checkout

O pacote agora contém componentes PHP de transporte Noise XX com cadeia de certificados e janela de validade, endereços/codec Messenger próprios, sessões Signal X3DH/Double Ratchet persistentes, construtores/projeções de IQs de aparelhos/pré-chaves, envelope texto v3/franking e journal transacional de inbox/outbox/recibos. São componentes internos para o dono do loop; a sua presença não significa que a conexão de produto esteja pronta ou validada no servidor atual.

| Componente | Contrato local |
| --- | --- |
| `E2ee\Noise\NoiseConnection` | Socket injetado, enquadramento de 24 bits, XX novo, certificados/estática/validade e tráfego autenticado. `isEstablished()` confirma só Noise. Não há resume IK presumido. |
| `E2ee\Protocol\ClientPayload` | Conta, aparelho registrado, CAT e user agent; exige appVersion BLUE_WEB atual explícita, sem versão de fallback. |
| `E2ee\Signal\SignalStore` / `SignalProtocol` | Mesmo dono/banco/lock; aprovação explícita inicial do fingerprint, pré-chave assinada verificada, ratchets e pré-chaves atômicos. Identidade desconhecida/trocada bloqueia. |
| `E2ee\Protocol\DeviceDirectory` | IQs de descoberta, busca e upload de pré-chaves; respostas precisam de ID/tipo/escopo correlacionados. Hash de lista não verifica confiança ICDC. |
| `E2ee\Protocol\MessageEnvelope` | Consumer 1/Application 2, `enc v=3`, texto e HMAC de franking. Sem tag recebido, mantém `franking_verified=false`. |
| `E2ee\MessageEngine` | Fan-out para destinatário e outros aparelhos próprios com DSM, outbox/ratchets commitados juntos, incerteza antes do envio e recepção/deduplicação/recibo atômicos. |
| `E2ee\Runtime` | Orquestra registro já confirmado, bootstrap/CAT/versão, Noise, `success`, IQs/prekeys e journal. Fecha com perda do dono/conta ou CAT expirado. Não registra aparelhos nem inicia automaticamente com LS. |

O runtime interno usa o módulo público observado `MAWAppVersion`: `302.0.SiteData.client_revision`, obtendo a revisão do bootstrap atual. Sem essa revisão ou GMP/zlib, falha antes de abrir o socket. Não reaproveita uma versão de Analytics/LS ou um exemplo antigo. O artefato first-party e seu hash estão no contrato de integração.

`Runtime::connect()` pode publicar pré-chaves públicas salvas, após login confirmado, e só conclui após o IQ correspondente. Uma resposta perdida preserva as chaves e bloqueia republicação automática. `reviewPeer()` consulta os aparelhos do destinatário e os próprios; `approvePeer()` e `sendText()` exigem verificação nativa do roster e aprovação explícita dos fingerprints. **Não há verificador ICDC remoto de produção neste checkout:** sem ele, retorna `messenger_icdc_verification_unavailable` e não envia texto nem expõe plaintext recebido. Um snapshot revisado não substitui a assinatura/binding ICDC. A injeção sintética usada nos testes não comprova esse protocolo.

A [revisão estática first-party](../../packages/messenger/src/E2ee/ICDC-EVIDENCE.md) localizou alertas/configuração ICDC e a ponte `waapi/getIdentityKeys`, mas não o backend do worker que vincula blob assinado, aparelhos, identidades, signatário e sequência. Essa lacuna concreta mantém o bloqueio; ela não foi deduzida somente de referências de terceiros.

`poll()` persiste ciphertext pendente antes do ACK ao servidor; após confiança válida, decripta e grava a mensagem atomicamente. `retryPendingIncoming()` retoma essa fila privada, `flushReceipts()` envia recibos de entrega e `ownerStopped()` encerra junto com o dono. ACK continua separado de entrega/leitura. O runtime não marca `e2ee_ready`.

`MessageEngine::prepareText()` exige sessões aprovadas para os aparelhos retornados pela descoberta autenticada atual; não descarta silenciosamente um aparelho que falhou. `submit()` recebe o sender Noise e nunca retransmite uma tentativa submetida/incerta automaticamente. ACK não é entrega; `acknowledge()` exige a origem correta dos recibos para avançar a projeção. `messages()` consulta somente o histórico local. Recepção válida gera recibo pendente, cujo envio ocorre fora da transação; não presume leitura humana.

O [contrato de integração](../../packages/messenger/src/E2ee/INTEGRATION.md) descreve assinaturas, ordem, armazenamento e fronteiras privadas. `DeviceKeys::signalCredentials()`/`noiseKeyPair()` são acessos internos secretos para essa integração, nunca retornos de API/LLM, eventos ou jobs. Não abra uma segunda sessão/dono nem importe credenciais WhatsApp.

## O que ainda limita a prontidão

- Validar interoperabilidade no protocolo atual. O runtime já está vinculado às fachadas e ao cleanup do dono; testes sintéticos cobrem `success`, IQs correlacionados e uploads, sem execução remota.
- Renovação CAT observada. A versão usa a fórmula first-party observada com a revisão atual do bootstrap, sem fallback.
- Verificador ICDC remoto de produção: falta comprovar o vínculo entre blob assinado, aparelhos, identidades dos bundles, signatário e sequência confiável. Esse bloqueio é explícito, independente da aprovação de fingerprints.
- Manutenção/rotação, retries, recibos em lote e conteúdos além de texto.
- Histórico seguro/Labyrinth e compatibilidade real entre clientes permanecem etapas próprias.

Threads criptografadas continuam bloqueadas para fallback plaintext. Os testes locais agora incluem HKDF RFC 5869, peers Noise sintéticos, validade/assinatura dos certificados, pares Signal, mensagens fora de ordem, restauração SQLite, trust/isolamento, envelope/franking, fan-out/DSM e journal/recibos/rollback. Isso não comprova interoperabilidade atual: nenhum dispositivo foi registrado, pré-chave enviada ou mensagem real transmitida nesta etapa. `e2ee_ready` não é habilitado pelos componentes isolados.

Fontes: [DeviceManager](../../packages/messenger/src/E2ee/DeviceManager.php), [contrato e evidências](../../packages/messenger/src/E2ee/README.md) e [limites](LIMITES.md).
