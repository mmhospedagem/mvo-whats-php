# Limite comprovado da verificação ICDC remota

Revisão estática final em 19/10/2026, usando somente artefatos públicos já adquiridos em 18/10/2026. Não houve leitura de cookies/storage do navegador, registro, rede autenticada ou operação em conta real. Esta nota distingue o perfil de versão comprovado do contrato criptográfico ainda incompleto.

## Observado nos bytes first-party

| Módulo / localização do artefato | O que os bytes estabelecem | O que não estabelecem |
| --- | --- | --- |
| `MAWAppVersion`, `public-05.js`; SHA-256 do artefato `2723f035edbfb9672564da69844951ae8eec172181af86fe7e21c002b34906aa` | Versão primária 302, secundária 0, terciária `SiteData.client_revision`. | Aceitação atual do servidor ou um verificador ICDC. |
| `WAAbPropsTypes`, mesmo artefato, linha 873 | Configurações de intervalo/cooldown e limiar de alertas ICDC. | Estrutura assinada, device IDs, identidades, signatário ou comparação de sequências. |
| `WAArmadilloTransportEvent.pb`, `public-02.js`, linha 225; SHA-256 `0cc7810a00264052356fd89b6e7abd22bf7dd164c833153a78da78ed89b11702` | `IcdcAlert` contém somente o enum `type` (NONE/DETECTED/CLEARED). `DeviceChange` descreve tipo/nome/plataforma/modelo. | Uma assinatura ou binding aparelho→identidade; esses eventos não são prova de confiança. |
| `getMAWIdentityKeys`, `public-05.js`, linha 196 | A solicitação segue por `MAWBridgeSendAndReceive`, backend `waapi`, rota `getIdentityKeys`. | Implementação/resposta tipada dessa rota e política de confiança remota. |
| `MAWBridgeSendAndReceive`, `public-03.js`, linha 147; SHA-256 `4aae8275619c045bd14b8782645e09111edca5aeb29262dbd5bbb06a2d6e2da5` | Encaminhamento assíncrono pelo bridge, timeout, logging e ACK do worker. | Verificação de roster ou sucesso de login criptográfico da conta. |
| `MAWWebWorkerSingleton`, `public-05.js`, linha 494 | O backend depende do recurso dinâmico `cr:6600`, com criação de worker compartilhado v2/v4 ou dedicado. | O binding de `cr:6600` e o código do backend que implementa `waapi` não foram estabelecidos nos assets selecionados. |

Também foram verificadas ocorrências em `lightspeed-runtime-public`, `runtime-public`, `shipped`, `pages-public` e os assets públicos do novo Inbox. Ocorrências de CTA/telemetria não foram promovidas a provas criptográficas. A pesquisa textual e extração de módulos foram operações locais; não observam execução e não provam ausência em todos os recursos que o Facebook pode servir.

## Lacuna concreta

Os bytes examinados não fornecem uma cadeia verificável completa que estabeleça:

1. Como o blob ICDC de `fbid:devices` é decodificado e quais bytes exatos são assinados.
2. Qual chave/âncora autoriza o signatário e como mudanças/rollback de sequência são tratados.
3. Como cada device ID é vinculado a uma identidade exata do bundle Signal, incluindo os demais aparelhos próprios.

O material específico que falta é a implementação do backend/worker selecionado por `cr:6600` e da verificação de roster/identidades alcançada por esse backend, ou outro contrato first-party equivalente que estabeleça esses três pontos. O schema/procedimento de **registrar a própria identidade** não demonstra sozinho essa verificação remota. Um `dhash`, alerta, IQ result ou fingerprint aprovado também não supre o vínculo ausente.

Por isso não foi adicionado um verificador nativo presumido. `RosterVerifier` permanece sem implementação de produção, e o `Runtime` padrão/fachadas bloqueiam envio de texto e exposição de plaintext com `messenger_icdc_verification_unavailable`. Os testes usam uma implementação explicitamente sintética, sem alegar suporte ICDC Meta.

## Evidência REA e alcance

REA analisou estaticamente seis módulos recortados desses artefatos, com manifesto de origem/hash preservado no cache privado. Evidência pai `ev_5850e1252fbd1f3837dca0d87e4e8b069cfccd13bad2732edfa9b830442c7c13`; raiz SHA-256 `18388a72b2d699d1915f3cba6f7ce71be4a6a8692f751cfe8e9a448cdd61257d`. Foram lidos 19.882 bytes e parseados seis arquivos JS sem falhas. A cobertura semântica é desconhecida, com 939 pontos não resolvidos; o loader Meta `__d` não foi resolvido como módulo CommonJS/ESM. As localizações/conclusões acima foram conferidas diretamente nos bytes inertes. Nenhum bootstrap ou worker foi executado.
