# Facebook Messenger em PHP — contrato experimental

A entrada principal para usar Facebook está na [documentação Facebook](facebook/index.html#inicio). Este guia detalha o runtime compartilhado Messenger e seus limites; use o [guia de conexão](facebook/CONEXAO.md) para login e persistência por `FacebookConnection`.

O conector implementa uma parte do **protocolo Web de conversas do Facebook Messenger**, com código PHP próprio. Ele não usa a API oficial da Meta, não exige app Graph/OAuth e não depende de Node.js, Go ou de uma ponte de mensagens externa.

**O envio Messenger E2EE integrado ainda não está habilitado.** O [componente experimental](facebook/DISPOSITIVO-E2EE.md) oferece provisionamento explícito, Noise, Signal e journal de texto no mesmo dono, com testes locais. Esses componentes não comprovam autenticação/interoperabilidade atual da conta. O cliente pode chegar a `metadata_connected` após sincronizar metadados; `e2ee_ready` continua `false`. O envio comum aceita apenas threads conhecidas cujo tipo sem criptografia esteja explicitamente suportado. Conversas criptografadas falham com `messenger_e2ee_not_ready`, sem fallback.

O fluxo principal de autenticação recebe **e-mail e senha**, obtém a sessão pelo formulário Web e preserva somente os cookies resultantes. Em 18/10/2026, a autenticação real foi confirmada com verificação humana no navegador isolado e confirmação independente da identidade por HTTPS. O PHP depois abriu DGW, sincronizou 13 conversas e manteve `metadata_connected` durante uma observação de 12 segundos; uma segunda execução restaurou os próprios cursores persistidos e repetiu essa observação sem novo login. Nenhuma mensagem foi enviada. Essa validação de metadados não demonstra troca de mensagens, E2EE ou estabilidade prolongada.

## Pacotes e isolamento

O pacote `packages/messenger` tem nome Composer `mmhospedagem/mvo-messenger-php` e namespace `MVO\Messenger`. Instale a série experimental 0.1 com `composer require "mmhospedagem/mvo-messenger-php:^0.1"`. O [README do pacote](../packages/messenger/README.md) explica também a alternativa Composer `path` para desenvolvimento e os métodos reais de `MessengerClient`.

O [adaptador Messenger](../packages/messenger-adapter/README.md) acrescenta os contratos compartilhados de [MVO Channels](MULTICANAL.md), sem tornar Messenger dependência do SDK WhatsApp. A biblioteca WhatsApp raiz continua na série pública 1.0.3 com `MVO\WaClient` e os mesmos caminhos de código.

Este pacote continua focado nas conversas Messenger. A biblioteca separada `packages/facebook` oferece `MVO\Facebook\FacebookConnection` como entrada direta para login e-mail/senha, persistência e restauração da sessão Facebook, sem exigir outro cliente na aplicação. Ela reutiliza internamente o mesmo runtime e acrescenta serviços Web parciais de Páginas, publicações, comentários, reações, inbox, consultas de anúncios e monitoramento SQLite. Uma aplicação que já tem um Messenger ativo pode compartilhar `MessengerClient::webSession()` com `FacebookClient`; não abra outro dono no mesmo ID/pasta. Consulte [Facebook PHP](FACEBOOK.md) para exemplos e limites; isso não representa suporte completo a Marketplace, grupos ou administração geral de Páginas. Também não há conector Instagram nesta alteração.

## Autenticação atual

1. O operador informa e-mail e senha de sua conta Facebook em uma entrada privada da aplicação.
2. `MessengerClient::login()` usa `WebLoginService` para carregar o formulário atual de `www.messenger.com` por HTTPS, com cookie jar anônimo somente em memória.
3. O parser obtém o action, os campos e a chave pública dinâmica fornecidos pelo servidor. A senha é cifrada em `#PWD_BROWSER:5` com AES-256-GCM e chave protegida por Sodium sealed-box.
4. A sessão só é aceita depois de `c_user`/`xs` válidos e uma página autenticada confirmar a mesma identidade. Os cookies finais mantêm domínio, caminho e expiração reais.
5. O cliente persiste a sessão confirmada, sem persistir a senha. `connect()` depois escolhe o domínio coberto pelos cookies, descobre a versão/configuração Lightspeed, abre DGW e aguarda o sync inicial.

```php
use MVO\Messenger\MessengerClient;

$client = new MessengerClient('cliente-demo', __DIR__ . '/var/private-auth');
// $email e $password vêm de uma entrada privada, fora dos logs/argumentos CLI.
$client->login($email, $password);
$client->connect();
// Mantenha poll() no loop da aplicação e encerre com stop().
```

O método `login()` exige conexão parada e não registra dispositivo E2EE. Para restaurar uma sessão válida, use o mesmo ID/diretório e chame somente `connect()`. Se expirar, execute um novo login da **mesma conta**. Outra conta exige outro ID.

O formulário Messenger observado usa `pass` com o valor já criptografado. O formulário Facebook tradicional, quando realmente fornecido pelo servidor com sua configuração, usa `encpass`. O código não envia a senha como plaintext se a chave estiver ausente. O fluxo CAA/Relay sem formulário ainda não foi implementado: retorna `login_flow_unavailable`, em vez de inventar endpoint/chave.

Não há leitura do perfil habitual do navegador, API oficial, OAuth, QR ou código de pareamento WhatsApp. A autenticação em duas etapas exige o código real do dono da conta; não é contornada. CAPTCHA, checkpoints e consentimento continuam sujeitos às verificações do Facebook.

### Verificação humana no navegador local

Uma verificação concluída em outro navegador não necessariamente libera a tentativa do PHP: o desafio pertence ao contexto e aos cookies daquela tentativa. A aplicação pode implementar uma continuação humana opcional em um navegador isolado, usando a interface privada de handoff. O operador conclui a verificação **na página oficial do Facebook**; não importa ou exporta JSON e não fornece credenciais ao chat.

O bridge PHP recebe `exportPrivateLoginChallenge()` diretamente do cliente que mantém o lock. Esse método retorna URL, cookies e expiração **privados**, exclusivamente para a integração local: nunca devolva o objeto por HTTP, ferramentas, eventos ou logs. O bridge semeia um contexto de navegador próprio, preservando escopo e atributos dos cookies. Não lê cookies de perfis existentes nem resolve CAPTCHA.

Depois da ação humana, somente os cookies Facebook/Messenger daquele contexto são candidatos a `completeBrowserLogin(CookieJar $cookies)`. O SDK faz uma nova requisição HTTPS e exige `CurrentUserInitialData.USER_ID` positivo, igual ao `c_user`, antes de salvar a sessão atomicamente. CAPTCHA concluído, mudança de URL ou cookies aparentes não bastam. A conta salva no mesmo ID continua imutável. Erros de verificação preservam o desafio enquanto estiver válido; `stop()` ou expiração o cancelam.

Esse auxiliar de autenticação é opcional e exige integração privada da aplicação; não é distribuído como painel. O protocolo e o loop Messenger continuam em PHP; o pacote não depende de navegador para restaurar sessões ou manter DGW. Em 18/10/2026, o operador concluiu a verificação na janela isolada e o PHP confirmou a identidade por HTTPS, salvou a sessão e carregou o bootstrap autenticado. Isso comprova o handoff nessa conta e tentativa, sem demonstrar compatibilidade de todos os desafios. O laboratório utilizado nessa validação é privado e não faz parte da biblioteca distribuída.

Autenticação e conexão são resultados separados. Se a sessão foi salva, mas o sync seguinte falhar, o laboratório retorna `partial`, com `authenticated=true`, `imported=true`, `connected=false` e um `connection_error` seguro. O operador pode restaurar com “Conectar sessão salva”; não precisa repetir e-mail/senha apenas por uma falha posterior de protocolo.

### Continuação por aplicativo autenticador

Quando o Facebook retorna `login_two_factor_required`, o cliente conserva o cookie jar, a página e o contexto do desafio **somente em memória**, por até dez minutos, com o lock exclusivo da sessão. A senha já foi descartada. `loginChallenge()` e `accountInfo()['login_challenge']` expõem somente `type`, `expires_at` e `ready`; não expõem contexto, HTML ou tokens.

`ready=true` indica que os campos e a operação de confirmação necessários foram reconhecidos no desafio atual. O operador informa o código no formulário privado e a aplicação chama `confirmLogin($code)`, fora dos callbacks. O código deve permanecer uma string para preservar zeros iniciais; não deve entrar em logs, argumentos CLI ou filas em texto claro. `ready=false` bloqueia o envio do código para um formato ainda desconhecido.

Uma resposta indicando código válido não confirma a conexão. O conector precisa concluir a continuação permitida e verificar novamente cookies e identidade antes de salvar a sessão. Só depois a aplicação chama `connect()` para abrir DGW e sincronizar metadados. `stop()` cancela o desafio; expiração e reinício do processo descartam esse contexto e exigem nova tentativa de login. Não há repetição automática de códigos.

A compatibilidade desse caminho com a conta real continua pendente de confirmação completa; não confunda testes locais do formato com autenticação finalizada.

| Motivo | Ação/limite |
| --- | --- |
| `login_rejected` | O servidor não confirmou credenciais/sessão; não há repetição automática. |
| `login_two_factor_required` | Consulte `loginChallenge()`; com `ready=true`, informe o código do autenticador no formulário privado. |
| `login_code_invalid` | Confira o código do autenticador; nenhuma repetição automática. |
| `login_challenge_expired` | Inicie outra tentativa de login; o contexto anterior foi descartado. |
| `login_challenge_unavailable` | O desafio atual não forneceu um formato de confirmação reconhecido. |
| `login_verification_pending` | Conclua ou cancele a verificação antes de restaurar/importar uma sessão. |
| `login_captcha_required` / `login_challenge_required` | No laboratório, continue a tentativa pendente em “Concluir verificação no Facebook”; uma verificação em outro contexto pode não liberá-la. |
| `login_consent_required` | Resolva a decisão de consentimento solicitada pelo Facebook. |
| `login_rate_limited` | Aguarde; não existe loop automático de tentativas. |
| `login_flow_unavailable` / `login_encryption_unavailable` | O formato/chave necessário não foi fornecido; a biblioteca não tenta senha sem criptografia. |
| `login_unconfirmed` / `login_identity_mismatch` | Cookies e identidade não foram confirmados de forma consistente. |

As mensagens públicas de erro não incluem senha, código, cookies, corpo HTTP ou chave privada. Não registre objetos/payloads/stack traces brutos da autenticação; use uma projeção explícita de `reason` e mensagem. O contexto temporário é descartado depois de sucesso, falha terminal, cancelamento ou expiração; a etapa pendente de 2FA é preservada somente pelo processo que a iniciou.

### Importação avançada e exemplo CLI

A importação de arquivo continua opcional para uma sessão existente. O arquivo JSON e o diretório de autenticação são privados, fora do diretório HTTP público. Passe apenas **o caminho do arquivo** ao exemplo CLI; não passe valores de cookies ou senha em argumentos, variáveis de ambiente compartilhadas, tickets ou logs. Domínios/caminhos dos cookies são respeitados; redirecionamentos não encaminham a sessão para hosts arbitrários.

```sh
php packages/messenger/examples/connect.php --help
php packages/messenger/examples/connect.php --session=demo --auth-dir=/caminho/privado/mvo-auth --cookies-file=/caminho/privado/facebook-cookies.json --seconds=30
```

Para restaurar, remova `--cookies-file` e preserve o mesmo ID/diretório. Esse exemplo CLI é de restauração/importação: o fluxo e-mail/senha é oferecido pelo método PHP `login()`. O exemplo não envia mensagens e não imprime os valores dos cookies.

## Ciclo de vida e eventos

`connect()` inicia/restaura a sessão e aguarda metadados utilizáveis. `poll()` mantém os streams, recebe eventos e continua cursores. Cada sessão ativa tem um dono exclusivo de lock. `stop()` encerra localmente e preserva a sessão salva; não revoga a conta.

Os estados intermediários incluem `awaiting_two_factor`, `login_challenge_expired`, `session_imported`, `authenticating` e `connecting_transport`. Depois do sync inicial, a projeção `accountInfo()` pode conter:

```json
{
  "channel": "messenger",
  "provider": "mvo_protocol",
  "session_id": "demo",
  "account_id": "100000000001",
  "display_name": "Conta de exemplo",
  "state": "metadata_connected",
  "connected": true,
  "transport": "dgw_websocket",
  "lightspeed_version": "12345678900000000",
  "e2ee_ready": false,
  "has_saved_session": true,
  "capabilities": [
    "session.email_password",
    "session.cookie_import",
    "connection.persistent",
    "threads.metadata",
    "message.text.unencrypted",
    "events.unencrypted"
  ]
}
```

Esse JSON é **ilustrativo**, com IDs/versão fictícios; não é resultado de uma conexão real. A implementação extrai os valores atuais da sessão e não usa esse número de versão como fallback.

Listeners recebem projeções de `connection`, `message`, `thread`, `message_ack`, `message_failed` ou `protocol_notice`, conforme o conteúdo realmente processado. Execute comandos no loop externo depois dos callbacks. O método `events($after, $limit)` consulta o histórico local persistido; não consulta o servidor por conta própria.

Projeções, deduplicação do batch e avanço do cursor são gravados na mesma transação SQLite. Uma falha de interpretação/projeção preserva o cursor anterior. Procedimentos desconhecidos que foram decodificados ficam em batches privados para investigação; isso não significa que já tenham sido projetados em eventos públicos. Opcodes Lightspeed fora do subconjunto implementado geram erro explícito.

O decoder preserva a diferença entre cursor inicial `undefined` e `null`, valida o epoch solicitado e só confirma uma transação com seu finalizador correspondente. O booleano do finalizador controla a limpeza do epoch de rede; não significa abortar a transação. Ao restaurar um cache, o cliente usa os próprios cursores em novas solicitações DGW e não reaplica preloaders HTML destinados a um cache Web vazio.

Um cursor salvo não dispensa os parâmetros de snapshot. A restauração preserva os parâmetros do bootstrap para cada grupo: `1=mailbox`, `2=contact`, `95=e2ee` e `104=communityExclusive`. Nas continuações, respeita o flag `sendSyncParams` confirmado pela transação. Uma resposta `LSHandleSyncFailure` produz `messenger_sync_failed`, sem expor a mensagem remota bruta, apagar credenciais/cursores ou repetir o login automaticamente.

## Envio e resultados incertos

Selecione um ID nativo confirmado por `threads()`/`refreshThreads()`, mantido como string. O cliente exige thread conhecida e `plaintext_send_supported=true`, além de rejeitar a marcação de criptografia. Não aceita número telefônico ou JID WhatsApp no lugar desse ID.

`sendText($threadId, $text)` cria um ID local de correlação e salva a outbox antes do I/O. `outgoing($offlineId)` consulta o estado. Uma falha depois de iniciar o envio pode produzir `send_outcome_uncertain`; o cliente não reenvia automaticamente.

| Estado/evidência | Significado |
| --- | --- |
| WebSocket conectado | Handshake do transporte concluído. |
| DGW stream estabelecido e ACK binário | Confirmação do stream/dados no transporte. |
| `submitted` | Houve resposta correlacionada à solicitação, ainda sem confirmação de mensagem projetada. |
| `server_ack` | O servidor retornou um reconhecimento da mensagem correlacionado ao ID local. |
| Entrega, leitura ou aparência no aplicativo | Não inferidas desses estados; precisam de evidência específica. |

## Por que E2EE é uma etapa separada

Os cookies da sessão Web e a task de texto Lightspeed não substituem o dispositivo criptográfico. O caminho Messenger observado nas referências usa autenticação criptográfica CAT, registro de identidade/dispositivo ICDC, chaves Signal, payload de cliente Messenger e transporte criptografado separado. O histórico seguro acrescenta o protocolo Labyrinth e recuperação autorizada.

A Meta descreve E2EE por padrão para conversas pessoais, com diferenças de múltiplos aparelhos em relação ao WhatsApp, em [Building end-to-end security for Messenger](https://engineering.fb.com/2023/12/06/security/building-end-to-end-security-for-messenger/). Essa fonte foi publicada em dezembro de 2023; é contexto técnico da Meta, não evidência de um envio PHP em 2026.

Os tipos de thread criptografados identificados pelo cliente são recusados. `metadata_connected` e `connected=true` não alteram `e2ee_ready=false` e não autorizam um envio plaintext alternativo. Nenhum registro de aparelho Messenger foi realizado durante os testes locais.

O vínculo nativo `LSCreateOpenToE2EEThreadLink` também bloqueia a conversa antiga mesmo se seu tipo ainda indicar plaintext. Ele é persistido separadamente, antes ou depois da chegada da conversa, e sobrevive a atualizações de metadados. A pasta de migração E2EE igualmente bloqueia o envio. `showOpenMessageHistory` permite exibir histórico; não é permissão para enviar. Na restauração, vínculos antes retidos em batches privados são projetados uma única vez, sem repetir mensagens, ACKs ou avanço de cursores. O cliente não redireciona silenciosamente o envio para outro ID.

## Evidência de engenharia reversa

Em **18/10/2026**, foram examinados HTML e módulos JavaScript públicos das páginas anônimas [Facebook Web](https://www.facebook.com/) e [Messenger](https://www.messenger.com/), sem login ou coleta de cookies de conta. Os bundles CDN foram tratados como texto/artefatos estáticos. Código alvo não é executado pelo SDK PHP.

| Conjunto | Evidência REA estática | Limite |
| --- | --- | --- |
| DGW público | `ev_1d53a67d4f57a3298d54401863644927ad8e658297016eaa915e79d99251be88` | 34 arquivos JS, sem falhas de parsing; cobertura semântica desconhecida, com resíduos. |
| Bootstrap e módulos de sessão | `ev_d2a4dba499dd4fe255a5ae1c063ec3f873f84404076aca9c5a0cf314b32e891f` | 33 arquivos JS, sem falhas de parsing; relações estáticas não demonstram execução autenticada. |

As raízes de artefato desses conjuntos têm SHA-256 `877f1e682e1f94a73acb3d22eed71fb70548244dd5994140b33508cde93cd794` e `cba27eebcc2e9bfc43e557b7d007b91e1b2b01ae024fde4bf40b2d802419d892`, respectivamente. Os IDs identificam os registros da análise local; não são endpoints públicos de autenticação ou atestados de compatibilidade.

O login foi examinado separadamente na evidência estática REA `ev_6d766185fc1f4d1f1ba2b7e313421d2b551c78c81bf67b09bb6ccd7f0fd1ca70`, raiz SHA-256 `0cb4dcec38c27fbe255d7ba55cb89c5322a07a4a1d0a2ece0a26a98b1ca67254`. O conjunto tem 15 arquivos JavaScript parseados sem falha e 2132 resíduos semânticos. Os módulos públicos `EnvelopeEncryption`, `FBBrowserPasswordEncryption`, `MWLoginEncryptPasswordOnSubmit` e `LoginFormController` estabelecem o envelope criptográfico e a escolha de campo. O parser PHP foi aplicado também ao HTML anônimo capturado do Messenger, recuperando action/chave/configuração. Isso não executou login.

Os módulos públicos corroboram DGW versão5, composição dinâmica de URL/configuração, identidade de usuário, tokens do bootstrap e assinaturas específicas de procedimentos LS. A presença de módulos MQTT no mesmo bundle não demonstra que o Messenger pessoal use o fluxo antigo `/ls_req`. As páginas anônimas não forneceram uma sessão LS autenticada, CAT ou tráfego de mensagens criptografadas.

A implementação atual [mautrix/meta na revisão 1b3bd5db21113e8b42cb45e7464019332653d277](https://github.com/mautrix/meta/tree/1b3bd5db21113e8b42cb45e7464019332653d277), de 18/10/2026, foi usada como **referência de implementação** para detalhes de DGW/LS e a separação E2EE. Os frames estendidos16/17 e determinados formatos de tasks estão nessa referência e não foram todos demonstrados pelo bundle anônimo analisado. São necessários testes autenticados para confirmar sua compatibilidade com uma conta.

Os manifests privados de pesquisa registram URL, revisão, tamanho e SHA-256 dos arquivos consultados. Permanecem no cache local de análise, junto aos relatórios REA; o pacote não distribui esses bundles, credenciais ou capturas de conta. A referência AGPL não é dependência instalada ou código vendorizado: o código PHP novo é original e usa MIT.

## Validação disponível e pendências

| Recurso | Implementação | Teste local executável | Cliente/conta Facebook real |
| --- | --- | --- | --- |
| E-mail/senha | Formulário atual e criptografia com chave dinâmica | Receptor criptográfico independente e fixtures de cookies, identidade, redirects e desafios | Confirmado com continuação humana no navegador; TOTP inteiramente PHP não demonstrado |
| Cookies, lock e mesma identidade | Sim | Importação sintética, isolamento, expiração/renovação | Handoff e identidade confirmados por HTTPS; sessão persistida |
| Bootstrap e versão LS | Sim, sem valores de fallback inventados | HTML/JS sintéticos e comparação estática | Bootstrap autenticado e versão dinâmica obtidos |
| WebSocket binário | Sim | Peer nativo localhost, máscara, fragmentação, ping/pong e rejeições | Conexão nativa e observação de 12 segundos, repetidas na restauração |
| DGW v5 e streams | Sim | Fixtures de bytes, ACKs, correlação, encerramento e limites | Sync inicial e incremental confirmados nessa conta |
| Decodificação LS e cursores | Subconjunto explícito | AST sintética, atomicidade, guardas e deduplicação | Payloads reais projetados; restauração dos próprios cursores confirmada |
| Listagem de metadados | Experimental | Simulação de requests/respostas | 13 conversas projetadas; nenhum dispositivo E2EE registrado |
| Texto sem criptografia | Experimental, somente rotas conhecidas | Envio/ACK simulados e recusa de rotas E2EE/migradas | Nenhum envio realizado |
| E2EE pessoal, histórico seguro, chamadas e mídia | Não implementado neste conector | Rejeição de envio criptografado | Não disponível |

Os testes e o [exemplo CLI](../packages/messenger/examples/connect.php) permitem validar o contrato local. Testes sintéticos não demonstram entrega ou exibição. A tentativa autenticada de 18/10/2026 inicialmente encontrou instruções Lightspeed não suportadas. A comparação com os módulos públicos corrigiu as operações de tuplas, vetores, dicionários e guardas de sync; o PHP então confirmou `metadata_connected` e restaurou a sessão em outra execução. Nenhuma mensagem Facebook foi enviada durante essa validação.

Depois da correção dos parâmetros de restauração, o daemon do laboratório também retomou a sessão salva e manteve `metadata_connected` em sete amostras durante 31 segundos, com 13 conversas projetadas. Isso confirma a integração local HTTP/fila/cliente PHP naquele intervalo; não é ensaio de estabilidade prolongada ou validação de entrega de mensagens.

**Preflight anônimo desta máquina em 18/10/2026:** o perfil anterior de cabeçalhos retornou HTTP200 com um interstitial de erro de 875 bytes. Remover apenas `Origin` não resolveu. Com cabeçalhos de navegação de documento, o `WebLoginCurlTransport` retornou HTTP200 com 111.157 bytes; o parser reconheceu `/login/password/`, o campo `pass`, os campos ocultos e a chave pública dinâmica. Esse GET público comprovou a obtenção do formulário nativo antes da autenticação real descrita acima; não foi, por si, evidência de login ou mensagens.

## Provisionamento do dispositivo no checkout em desenvolvimento

`MessengerClient::e2eeDevice(?RegistrationTransport $transport=null): DeviceManager` oferece `prepare()` local, `registrationRoster()` de leitura, `register($expectedRosterHash)` explícito e `status()`. CAT, identidade e validade vêm do bootstrap real; GMP é obrigatório somente nesse componente. Nenhum registro ocorreu nos testes reais desta revisão. A API está disponível no checkout em desenvolvimento e não nas primeiras releases Messenger 0.1.1/Facebook 0.1.0.

`registered` não torna `e2ee_ready` verdadeiro. Os componentes locais de Noise, trust/ratchets Signal, fan-out, franking e recibos têm testes sintéticos. `e2eeRuntime()` fornece o ciclo explícito no mesmo dono; faltam verificador remoto ICDC, histórico seguro e validação real para liberar a prontidão integrada. Confira [o contrato](../packages/messenger/src/E2ee/README.md) antes de chamar qualquer operação. Não apague estado/chaves para contornar `registration_uncertain`.
