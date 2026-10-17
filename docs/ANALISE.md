# Diagnóstico histórico da implementação MVO PHP

Análise técnica realizada em 14/10/2026 sobre o snapshot cujo núcleo informa versão 1.10.0, revisão `caeace8d03cd22f24f224c4063edc537522ef936`. Esse snapshot é posterior à tag oficial da versão; a revisão das atualizações posteriores está em [PARIDADE.md](PARIDADE.md). As atribuições e licenças dos componentes estão em [THIRD_PARTY_NOTICES.md](../THIRD_PARTY_NOTICES.md).

**Diagnóstico histórico:** a tabela abaixo registra a primeira revisão. A implementação foi ampliada para histórico, app-state, serviços, mobile, stores, plugins e ferramentas opcionais. Consulte [PARIDADE.md](PARIDADE.md) para a cobertura atual e as diferenças que permanecem.

**Atualização de implementação:** WebSocket/TLS, Noise XX com certificados, XEdDSA, payloads de login/registro, QR/código, sessão persistente, reconexão, Signal v3/Double Ratchet, mensagens estruturadas, transferência de mídia, addons e Sender Keys de grupos foram adicionados depois deste diagnóstico inicial. `SchemaCodec` cobre todo o WAProto importado, incluindo inteiros de 64 bits. O usuário confirmou novo vínculo e retomada sem QR. Veja [CONEXAO.md](CONEXAO.md), [MENSAGENS.md](MENSAGENS.md), [TIPOS-MENSAGENS.md](TIPOS-MENSAGENS.md) e [MIDIA.md](MIDIA.md) para a cobertura atual. A tabela e a lista abaixo registram o estado inicial da análise e não substituem essa atualização.

## Escopo efetivamente analisado

Foi obtido um checkout completo dos arquivos da revisão, com histórico superficial. O inventário estrutural percorreu 1.026 arquivos de código, schemas, exemplos e documentação em `src`, `packages`, `spec` e `examples`. O núcleo contém 467 arquivos TypeScript, incluindo testes e barrels. Esses números não representam 1.026 arquivos revisados manualmente linha por linha nem paridade funcional.

A leitura de implementação concentrou-se em JIDs, constantes, WAP encoder/decoder e tabelas, framing, estado Noise, mídia, HKDF, dependências de autenticação, composição do cliente, contratos do store e estrutura de Signal/app-state. O mapa de arquivos e símbolos exportados está em `resources/source-map.json`. O inventário permite localizar o restante, mas não substitui uma revisão semântica de cada recurso.

## Arquitetura identificada

O caminho de saída começa nos coordenadores do `WaClient`, passa pela codificação da mensagem Protobuf, resolução de dispositivos e sessões Signal, construção de stanzas WAP, criptografia Noise e WebSocket. A entrada percorre o caminho inverso, com roteamento de nós, ACKs, receipts, retries e persistência. Autenticação coordena credenciais, identidade de dispositivo, QR/código/passkey e estabelecimento da sessão. App-state possui chaves e integridade próprias.

Portar somente as assinaturas de `WaClient` não reproduziria esse comportamento. O requisito de execução exclusivamente PHP implica portar todas essas camadas ou usar dependências PHP compatíveis; uma ponte para o pacote JavaScript não atende ao pedido.

## Módulos do núcleo

Contagens incluem arquivos de teste. Arquivos isolados na raiz de `src` não aparecem nesta tabela.

| Módulo | Arquivos TS | Responsabilidade | Cobertura PHP atual |
|---|---:|---|---|
| `appstate` | 13 | Derivação de chaves, patches, snapshots, MACs, LT hash e sincronização | Pendente |
| `auth` | 16 | Credenciais e pareamento QR/código/Shortcake | Pendente |
| `client` | 112 | Fachada, conexão, coordenadores, eventos, envio e persistência | Pendente |
| `crypto` | 24 | Primitivas, curvas, XEdDSA e matemática | SHA/HMAC/HKDF/AES-GCM/X25519; XEdDSA e matemática pendentes |
| `infra` | 11 | Log, filas, locks e concorrência | Pendente |
| `media` | 15 | Criptografia, transferência, sidecars e contêineres | Criptografia em memória e hashes; transferência/streaming pendentes |
| `message` | 40 | Mensagens, encoders, tipos, addons e ACKs | Apenas wire codec genérico Protobuf; APIs de mensagem pendentes |
| `protocol` | 28 | Constantes, JIDs, namespaces e limites | JIDs e tokens WAP; catálogo completo pendente |
| `retry` | 10 | Estado de retry e replay de mensagens | Pendente |
| `signal` | 38 | Sessões, ratchet, prekeys, identidade e grupos | Pendente; X25519 isolado não equivale a Signal |
| `store` | 63 | Contratos, providers, cache e memória | SQLite key/value próprio; contratos tipados pendentes |
| `transport` | 75 | WebSocket, Noise, WAP, builders, stream e keepalive | Codec WAP, framing e estado simétrico Noise; transporte de rede e sessão pendentes |
| `util` | 14 | Bytes, coleções, protobuf, XML, streams e outras funções auxiliares | Helpers internos mínimos |
| `__tests__` | 2 | Verificação estrutural | Substituída por testes PHP dos componentes portados |

## Pacotes opcionais

O upstream também contém `store-sqlite`, `store-mysql`, `store-postgres`, `store-redis`, `store-mongo`, `media-utils`, `native`, `fake-server`, `mcp-server`, `voip`, `voip-media` e `wam`. Eles não foram portados. O SQLite desta biblioteca é uma implementação PHP independente com escopo menor. O runtime não depende de nenhum pacote npm.

Os diretórios `spec/proto`, `spec/abprops`, `spec/appstate`, `spec/mex` e `spec/version` contêm especificações auxiliares. Somente `WAProto.proto` foi copiado para a distribuição PHP; ainda não há classes geradas nem catálogo PHP completo das outras especificações.

## Decisões implementadas

1. Strings PHP representam bytes; não aplicar conversão UTF-8 em material criptográfico.
2. HKDF implementado com HMAC permite IKM vazio, necessário ao split Noise. A função nativa PHP `hash_hkdf` rejeita IKM vazio.
3. Nonces seguem a revisão analisada: oito bytes zero e contador uint32 big-endian. Não substituir pela codificação de outra implementação Noise sem validar compatibilidade.
4. Tabelas WAP extraídas do arquivo upstream, sem reconstruir valores manualmente. A indexação dos tokens simples começa em 1; dicionários começam em 0.
5. Mídia usa contexto HKDF específico por tipo, IV de 16 bytes, AES-256-CBC, HMAC-SHA256 truncado em 10 bytes sobre IV+ciphertext e SHA256 dos dados claros/cifrados.
6. Falhas de autenticação do canal Noise invalidam o objeto. O decoder WAP limita tamanho e profundidade, verifica truncamento e rejeita atributos duplicados.
7. Não foram criadas APIs que anunciem conexão ou entrega sem implementação real.

O normalizador de destinatários é deliberadamente mais estrito que o upstream: rejeita texto arbitrário com números; telefones formatados com hífens continuam sendo telefones, exceto o padrão numérico explícito de grupo `numero-numero`. Isso deve ser considerado ao migrar chamadas existentes.

## Trabalho necessário para um cliente completo

1. Gerar classes PHP de `WAProto.proto` e implementar serialização semanticamente equivalente, incluindo int64/uint64 sem perda.
2. Implementar e validar XEdDSA. Ed25519 comum não pode ser usado como substituição automática.
3. Portar certificados Noise, payloads de login/registro, sequência XX/IK/fallback, transporte WebSocket, timeouts, ping/pong e reconexão.
4. Portar contratos de credenciais, geração de identidades/prekeys, QR, código de pareamento e persistência atômica da sessão.
5. Portar estabelecimento de sessões Signal, Double Ratchet, prekey messages, MACs, identidade, chaves de mensagens atrasadas, sender keys e rotação.
6. Integrar dispositivos, fanout, envio e recebimento de texto, receipts, ACKs e retries; testar duas contas reais e reinício da sessão.
7. Portar app-state e histórico com verificação de integridade, retomada offline e persistência.
8. Portar upload/download de mídia, sidecars, grupos, contatos, presença, privacidade, status, business, newsletters e os demais coordenadores.
9. Validar isolamento de sessões, concorrência e recuperação de falhas antes de anunciar suporte a escala.

O `AGENTS.md` do upstream informa que os bundles `wa-web/` e `wa-mob/` não são distribuídos. Eles não estavam disponíveis neste checkout. Esta implementação reutiliza decisões já codificadas no upstream; nenhuma funcionalidade nova foi validada contra esses bundles.

## Evidência e limites dos testes

`php tests/run.php` executa 15 grupos de testes. Há vetores conhecidos RFC 5869/RFC 7748/NIST, vetor de mídia calculado independentemente com Python cryptography, enumeração de todos os tokens, binários nos limites de mudança de formato, listas de 256 itens, rejeição de truncamento, compressão, limites de profundidade, fragmentação de frames, falhas de autenticação e persistência SQLite com rollback.

Os testes de Noise exercitam apenas estados simétricos locais. Não comprovam handshake de rede, certificados de servidor ou autenticação WhatsApp. Os testes WAP comprovam os formatos exercitados, não todas as particularidades dos builders. Não houve pareamento, envio real, validação contra servidor ou teste de carga.

**Conclusão de cobertura: biblioteca PHP parcial de componentes, com testes locais; o pedido de port completo do projeto de referência ainda não está concluído.**
