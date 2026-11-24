# Organização dos SDKs por canal

A organização separa **contratos compartilhados**, **protocolos por canal** e **adaptadores opcionais**. O SDK WhatsApp existente permanece no pacote raiz `mmhospedagem/mvo-whats-php`, namespace `MVO`, série pública 1.0.3. Seus arquivos `src/`, assinaturas de `WaClient` e instalação por Composer continuam no lugar.

Facebook e Messenger são pacotes Composer independentes na série experimental 0.1. O consumidor pode instalar Messenger sem o SDK WhatsApp ou manter somente WhatsApp sem carregar o código Messenger. Core e adaptadores continuam disponíveis como pacotes de desenvolvimento no monorepo.

| Diretório | Pacote | Responsabilidade |
| --- | --- | --- |
| `packages/channels-core` | `mmhospedagem/mvo-channels-core` | Descritor público, capabilities, contratos e registro de factories. |
| `packages/whatsapp-adapter` | `mmhospedagem/mvo-whatsapp-adapter` | Encapsular um `MVO\WaClient` existente no contrato comum. |
| `packages/messenger` | `mmhospedagem/mvo-messenger-php` | Autenticação Android nativa opcional, sessão privada, bootstrap Web, DGW/Lightspeed e estado próprio. |
| `packages/messenger-adapter` | `mmhospedagem/mvo-messenger-adapter` | Encapsular um `MessengerClient` no contrato comum. |
| `packages/facebook` | `mmhospedagem/mvo-facebook-php` | Conexão Facebook, login e persistência com o runtime compartilhado; serviços Web parciais e monitor SQLite. |

Messenger continua sendo o conector de conversas. A biblioteca separada `packages/facebook` acrescenta recursos Web parciais de Páginas, publicações, comentários, reações, inbox e consultas de anúncios, descritos no [guia Facebook PHP](FACEBOOK.md). Isso não representa administração completa do Facebook, Marketplace ou grupos. Instagram ainda não tem conector implementado. O pacote Facebook atual entrega somente biblioteca PHP, sem novo painel/API.

## Selecionar conexão e código

```php
use MVO\Channels\ConnectionConfig;

$whatsapp = new ConnectionConfig('whatsapp-demo', 'whatsapp', 'mvo_protocol', 'restore');
$messenger = new ConnectionConfig('messenger-demo', 'messenger', 'mvo_protocol', 'email_password');
```

| Campo | Significado |
| --- | --- |
| `id` | Identifica a conexão na aplicação; não substitui a identidade nativa da conta. |
| `channel` | Seleciona o canal, por exemplo `whatsapp` ou `messenger`. |
| `provider` | Seleciona a implementação registrada, atualmente `mvo_protocol`. |
| `authMode` | Descreve o fluxo configurado pela factory; não inicia autenticação sozinho. |

O descritor não aceita credenciais nem opções privadas arbitrárias. Pastas, cookies, identidade Mobile e detalhes de pareamento ficam na configuração privada do provedor. A factory valida modalidades suportadas e cria o cliente concreto. O [ChannelRegistry](../packages/channels-core/README.md) seleciona o par exato canal/provedor, rejeita duplicações e não escolhe outro provedor como fallback.

O contrato `ChannelClient` compartilha identificação, capabilities, `accountInfo()`, listeners e `sendText()`. `PersistentClient` acrescenta `connect()`, `poll()`, `stop()` e `isConnected()`. Destinos, payloads e resultados conservam as regras do provedor. Recursos como Status WhatsApp ou registro de dispositivo Messenger não se tornam recursos genéricos por existir um método comum.

## Dependências e instalação

Instale na aplicação consumidora somente o SDK necessário:

```sh
# FacebookConnection e seus serviços, com runtime Messenger transitivo:
composer require "mmhospedagem/mvo-facebook-php:^0.1"

# Alternativa: apenas o runtime Messenger:
composer require "mmhospedagem/mvo-messenger-php:^0.1"
```

Para desenvolver os pacotes do monorepo, configure repositórios Composer `path` na aplicação consumidora. Os exemplos completos estão nos READMEs:

- [Somente Messenger](../packages/messenger/README.md#instalação-local-para-desenvolver-no-monorepo).
- [Facebook Web com login e sessão próprios na fachada PHP](../packages/facebook/README.md#instalação-local-para-desenvolver-no-monorepo).
- [Core + adaptador Messenger](../packages/messenger-adapter/README.md#instalação-por-caminhos-locais).
- [Core + adaptador WhatsApp](../packages/whatsapp-adapter/README.md#instalação-local).

As configurações `path` atribuem versões de desenvolvimento aos checkouts e são alternativas à instalação de releases. Não é necessário modificar o `composer.json` raiz WhatsApp para usar os contratos na aplicação. O runtime dos conectores é PHP; Node.js, Go e serviços de ponte externos não são dependências Messenger.

## Isolamento operacional

Uma conexão ativa conserva seu dono de lock e seu loop. O adaptador WhatsApp utiliza o `WaClient` que recebeu. O Messenger usa armazenamento próprio sob `messenger/<sessionId>` dentro do diretório privado escolhido. Credenciais Facebook não entram no transporte ou nos bancos WhatsApp.

`FacebookConnection($sessionId, $authDirectory, $privateStorageDirectory)` é a entrada direta para a biblioteca Facebook. No checkout, `loginAndroid($email, $password, $nativeProfile)` permite autenticação nativa e salva a sessão confirmada e `connect()` inicia o transporte. Futuras execuções usam somente `connect()` com o mesmo ID/pastas; `stop()` encerra localmente e preserva a sessão. O runtime Messenger é interno, com um único dono de lock, sem exigir outro cliente ou JSON de cookies na aplicação. A alternativa `FacebookClient($messenger->webSession(), $privateStorageDirectory)` compartilha um Messenger já conectado; seu `close()` libera apenas os armazenamentos dos serviços, sem parar esse dono existente. Páginas/contas de anúncios são seleções locais explícitas entre recursos descobertos, sem mudar `c_user`. Não expor o WebSession ou seus campos privados em HTTP/logs/ferramentas. Serviços e monitor não são um novo adaptador genérico de `sendText()`.

`loginAndroid()` usa somente endpoints nativos JSON durante a autenticação: chave dinâmica, `/auth/login`, código/aprovação 406 e `GET /me` com token de usuário somente em RAM, sem HTML ou navegador. O 405 retorna `login_native_verification_unsupported` e `browser_continuation_available=false`; não há conversão para 406. O schema JSON de identidade exigido pelo novo verificador tem fixtures e ainda precisa de validação real própria. Bootstrap, metadados, inbox e serviços de Fanpage continuam no protocolo Web/DGW; `e2ee_ready=false`.

Para autenticar sem Web, use `MessengerClient::loginAndroid()` ou `FacebookConnection::loginAndroid()` com perfil privado do aplicativo. A chave e a senha cifrada usam os endpoints nativos; o 406 conserva código/aprovação sob o mesmo dono e prazo. `requestLoginSms()` é uma ação experimental separada, uma tentativa do 406 e sem comprovar disponibilidade ou entrega. `methods=[]` não autoriza fabricar alternativas. O token do usuário só existe em RAM e autoriza a verificação nativa `/me`; cookies confirmados mantêm o armazenamento da mesma sessão. A nova validação JSON de identidade não foi demonstrada em conta real nesta revisão.

`login()`/`WebLoginService` permanece uma alternativa Web explícita da biblioteca. A opção de baixo nível `allowWebCheckpoint: true` conserva compatibilidade legada/testes; as fachadas `loginAndroid()` não a ativam nem abrem navegador ou HTML para contornar 405. Senhas/códigos, configuração privada, tokens e cookies não pertencem ao `ConnectionConfig`, registry, argumentos CLI, logs ou contextos LLM. Expiração, reinício ou `stop()` descartam a verificação pendente.

Callbacks apenas recebem eventos. Execute comandos depois dos callbacks, no loop externo. A aplicação que oferece múltiplos clientes continua responsável por autenticação dos consumidores, associação entre cliente e conexão, permissões, filas e webhooks. Estes pacotes não acrescentam rotas REST ao SDK público.

Inclua **conexão + canal + identificador nativo de conversa/mensagem** em suas chaves de idempotência. IDs Facebook são preservados como strings; PN/LID e chaves WhatsApp permanecem no formato WhatsApp. Nunca converta um identificador para o namespace do outro canal.

## Capabilities e prontidão

| Integração | Estado/contrato | Limite relevante |
| --- | --- | --- |
| WhatsApp atual | Contrato e estado nativos de `WaClient` preservados | Resultados variam por recurso e evidência existente no guia correspondente. |
| Messenger | `metadata_connected` observado em conta real; `e2ee_ready=false` | Metadados sincronizados; envio sem criptografia permanece experimental e sem validação real. |
| Messenger pessoal E2EE | Componentes experimentais de dispositivo, Noise, Signal e journal; mensagens ainda bloqueadas | Falta verificador remoto ICDC de produção e validação real. `e2ee_ready=false`; histórico seguro ausente. |
| Biblioteca Facebook Web | Serviços parciais e monitor local, com fixtures e bindings observados | Sem validação de operações em conta real; providers atuais exigidos quando necessários e anúncios somente por leituras Web estabelecidas, sem fallback Graph. |
| Instagram | Não implementado | Não há adaptador funcional nesta alteração. |

`message.text.unencrypted` é uma capability Messenger específica. Ela não promete enviar a qualquer conversa. O cliente recusa threads criptografadas antes do I/O e não tenta um caminho plaintext alternativo. Um `server_ack` não comprova entrega, leitura ou aparência no aplicativo.

## O que foi demonstrado

Em **18/10/2026**, a autenticação com continuação humana no navegador isolado foi confirmada pelo PHP e salva. A restauração dessa sessão concluiu o bootstrap nativo e atingiu `metadata_connected`, com metadados de **13 conversas** e WebSocket ativo durante **12 segundos** de observação. Isso confirma esse fluxo e esse intervalo; não garante estabilidade prolongada, outros desafios ou todas as contas. **Nenhuma mensagem foi enviada nesse teste**; envio, recebimento, entrega e leitura continuam sem validação real. `e2ee_ready` permaneceu `false`.

| Área | Implementado | Evidência local | Validação real desta alteração |
| --- | --- | --- | --- |
| Registry, descritores e adaptadores | Sim | Seleção explícita e fixtures isoladas | Sem acesso a contas para esses testes. |
| Messenger bootstrap/DGW/LS | Subconjunto explícito | Testes PHP, peer localhost e análise estática de JavaScript público | Em 18/10/2026: bootstrap nativo, `metadata_connected`, 13 conversas e WebSocket observado por 12 s. |
| Messenger e-mail/senha e continuação humana | Formulário Web com senha cifrada; handoff privado opcional | Envelope criptográfico e fluxo simulado de cookies/identidade/desafios | Em 18/10/2026: fluxo com verificação humana, identidade confirmada pelo PHP e sessão salva; não comprova todos os desafios. |
| Restauração, lock e cursores Messenger | Sim | Bancos temporários, rollback e replay deduplicado | Restauração real e sincronização inicial confirmadas em 18/10/2026; concorrência de lock e rollback cobertos pelos testes locais. |
| Troca real de mensagens Messenger | Experimental, somente threads sem criptografia suportadas | Simulação de requests/resultados | Pendente. |
| E2EE Messenger | Dispositivo e runtime explícitos no mesmo dono; mensagens ainda bloqueadas | Peers Noise/Signal sintéticos, persistência, isolamento e incerteza; sem verificador remoto ICDC de produção | Nenhum registro, upload de pré-chaves ou envio real desta revisão; `e2ee_ready=false`. |
| Serviços Facebook Web e monitor | Subconjunto explícito, em pacote separado | Parsing/HTTP falso, seleção por conta, projeção de mutations, ledger e SQLite | Publicações, interações, inbox comercial, anúncios e eventos reais não validados nesta etapa. |

Leia [Facebook Messenger](FACEBOOK-MESSENGER.md) para fontes, evidências e o escopo exato. Nenhuma validação estática ou simulada constitui prova de conexão ou entrega em conta real.
