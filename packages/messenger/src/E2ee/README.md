# Provisionamento experimental do dispositivo Messenger

Este componente implementa geração/persistência de identidade, pré-chave assinada e chave Noise independentes, codificação ICDC, leitura do inventário de identidades e uma operação explícita de registro. O checkout também contém componentes de Noise, Signal, envelope e journal descritos em [INTEGRATION.md](INTEGRATION.md), ainda sujeitos à integração e validação do login/protocolo completo. **A presença desses componentes não habilita por si só o envio/recebimento E2EE na fachada.** `registration_confirmed` e `e2ee_ready` são estados distintos: o último permanece `false` no status de provisionamento.

`MessengerClient::connect()` e `MessengerClient::poll()` não registram aparelhos nem enviam pré-chaves E2EE. O usuário desta biblioteca decide quando provisionar um dispositivo na própria conta. O `connect()` do runtime E2EE explícito tem outro contrato e pode publicar pré-chaves salvas após registro/login confirmados; consulte [INTEGRATION.md](INTEGRATION.md). Os testes desta implementação não executaram registro nem envio em conta real.

## Uso pelo dono da sessão

Depois de `MessengerClient::connect()` e do estado `metadata_connected`, fora dos callbacks:

```php
// Usa o mesmo lock e armazenamento privado da sessão Messenger ativa.
$device = $client->e2eeDevice();
$status = $device->prepare(); // Somente local: gera ou restaura as mesmas chaves.
$roster = $device->registrationRoster(); // Leitura autenticada, sem registrar.
```

`registrationRoster()` apresenta sequência, contagem, fingerprints SHA-256 das identidades e `snapshot_hash`. Não expõe CAT, cookies nem chaves privadas. O inventário obtido por HTTPS é uma fotografia da conta; não substitui a verificação criptográfica de confiança nos aparelhos remotos.

Uma integração que recebeu autorização explícita para adicionar um aparelho pode chamar:

```php
// Mutação real na conta: faça somente após a decisão explícita do operador.
$status = $device->register($roster['snapshot_hash']);
```

O método busca novamente o inventário e recusa mudanças desde a revisão. A lista preserva a ordem dos aparelhos e acrescenta a identidade local somente se estiver ausente. O `ihash` usa a lista ordenada de bytes, separadamente. As assinaturas autenticam os bytes exatos da pré-chave tipada e da lista protobuf.

Um registro só vira `registered` após HTTP 200 e resposta reconhecida com `status=200`, `product=msgr`, `wa_device_id` válido e nenhuma indicação negativa de `icdc_success`. Esse reconhecimento não comprova conexão Noise, sessões Signal, entrega ou leitura. O componente não adota identidades ADV fictícias nem reaproveita credenciais WhatsApp.

## Requisitos e armazenamento

- PHP 8.2+ de 64 bits, Sodium, SQLite e cURL conforme o pacote; **GMP é adicional e obrigatório para o provisionamento**. O restante do Messenger não passa a depender de GMP.
- As chaves ficam nos settings privados do `SessionStore`, no mesmo banco protegido e sob o mesmo dono do lock. O diretório precisa ficar fora da raiz HTTP pública e acessível apenas ao processo. Não há uma promessa adicional de criptografia em repouso: proteja o armazenamento/backup no sistema operacional.
- CAT vem exclusivamente de `MessengerWebInitData.cryptoAuthToken.encryptedSerializedCat`, com `expirationTimeInSeconds`, e usa o `appId` desse módulo. Não há fallback para o app DGW nem renovação CAT com documento antigo/inventado. Token expirado impede I/O.
- `DeviceKeys::exportPrivateJson()` é uma fronteira privada para persistência, não um retorno de API. JSON de diagnóstico e `__debugInfo()` omitem segredos. Serialização PHP dos objetos de chaves/CAT é recusada.
- A primitiva XEdDSA foi isolada do código MIT MVO já existente. A aritmética de assinatura GMP não oferece garantia de tempo constante. O pacote não depende de `MVO\WaClient`, do autoload WhatsApp ou de código Go/JavaScript em runtime.

## Falha e incerteza

Antes do POST de registro, o banco salva `registration_uncertain`. Timeout, formato incompleto, erro HTTP ou falha posterior à escrita conservam esse estado e as mesmas chaves. Uma segunda chamada é bloqueada: não ocorre reenvio automático ou criação de nova identidade. `registration_rejected` exige rejeição explícita no status interno da resposta. Não apague a pasta para resolver um timeout: isso descartaria a identidade cuja inclusão pode ter ocorrido.

Ainda não há reconciliação automática de uma tentativa incerta com o ID de aparelho remoto. É necessário observar a confirmação do protocolo na conta antes de decidir outra tentativa. Também não há rotação/revogação automática de chaves ou recuperação do histórico seguro.

## Evidência e trabalho restante

Os campos ICDC e endpoints `/v2/fb_icdc_fetch` e `/v2/fb_register_v2` foram reconstruídos a partir do inventário de protocolo da referência pública `mautrix/meta` fixada no commit `1b3bd5db21113e8b42cb45e7464019332653d277`; o schema ICDC está na referência `whatsmeow` `f16f9ca30d6ae7ff35de7a02d94c93d3ae959098`. Código AGPL dessa referência não foi incorporado ou traduzido. Esse material é referência de protocolo, sem demonstração de aceitação atual do servidor.

Os módulos públicos `MAWMICInternal`, `MAWMICSchema` e `MAWCurrentUser`, analisados estaticamente, distinguem conclusão Lightspeed, inicialização MAW, fila offline e mapeamento de threads. Evidência REA `ev_34edd0fbe5d94710393361b56c33738352a3d90e7e146d7c114e17bfc9145ff2`, raiz SHA-256 `c87d5c4660e6c24ffd9e22eb7e3f852002b21f862c16dfd7cbea2d22943566e5`: três arquivos parseados, sem execução; cobertura semântica desconhecida com 410 pontos não resolvidos. Eles não estabelecem o protocolo completo do worker criptográfico.

Os componentes novos incluem XX com certificados e validade temporal, endereços/token FB_JID, payload Messenger sem versão de fallback, IQs de descoberta/bundles/upload, trust explícito e ratchets Signal persistentes, envelope texto v3/franking e journal atômico de inbox/outbox/recibos. Primitivas MIT do próprio SDK MVO foram isoladas; hashes de origem estão em `implementation-provenance.json`. O pacote continua independente do autoload/estado WhatsApp e não incorpora implementação AGPL.

`Runtime` já orquestra restauração do registro confirmado, CAT, versão `302.0.SiteData.client_revision` observada no módulo first-party `MAWAppVersion`, handshake/login, IQs e publicação de pré-chaves confirmada, persistência/certeza, fila privada de entrada e receipts diretos. Não registra dispositivos. GMP/zlib ausentes bloqueiam antes de I/O. `MessengerClient::e2eeRuntime()` e `FacebookConnection::e2eeRuntime()` fornecem o componente do mesmo dono, com bootstrap/guard/socket/eventos e cleanup em stop/perda da conexão. Não expõem verifier/raiz de teste. Liberar a última referência também fecha o transporte.

O bloqueio concreto de texto permanece: não há verificador remoto ICDC de produção que estabeleça signer/seq e vincule cada device ID às identidades dos bundles. O contrato `RosterVerifier` é uma fronteira interna, sem implementação nativa comprovada. Na configuração padrão, login/prekeys/leitura de roster não habilitam envio de texto nem plaintext de entrada; `messenger_icdc_verification_unavailable` é explícito. Um fingerprint aprovado ou hash revisado não contorna essa verificação. Os testes injetam apenas uma política sintética, sem alegar verificação ICDC Meta.

Renovação CAT, manutenção/retry/recibos em lote e validação do servidor atual ainda são necessários. História segura/Labyrinth e outros conteúdos permanecem etapas próprias. Não substitua essas etapas por flags de prontidão; `e2ee_ready` continua falso.

Os testes locais verificam vetor de assinatura Signal, HKDF RFC 5869, peers Noise sintéticos e certificados inválidos/expirados/futuros, X3DH/ratchets, protobuf/ICDC, fan-out/DSM, franking, restauração SQLite, isolamento, lock e incerteza/journal/recibos. Não são evidência de dispositivo registrado ou mensagem recebida em cliente real. Consulte [INTEGRATION.md](INTEGRATION.md) para a ordem e os limites de cada API.
