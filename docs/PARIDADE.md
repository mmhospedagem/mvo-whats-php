# Paridade atual com o projeto de referência

A conversão partiu do snapshot `caeace8d03cd22f24f224c4063edc537522ef936`, cujo núcleo informa versão 1.10.0. A tag oficial `v1.10.0` aponta para uma revisão anterior, `c4c1c57d4c4ad820e06d6c6d45419194e4469662`. Em 14/10/2026, o histórico foi conferido até `653042df263be650b7729c3649d735936125f65e`: nove commits após a tag, sete já incluídos no snapshot de referência e dois posteriores a ele. Não houve mudança no núcleo `src/` nem em `spec/` nesse intervalo; as mudanças funcionais se concentram nos módulos opcionais de chamadas e mídia. O cliente executa em PHP.

Estar presente na referência não comprova que um comportamento foi portado. A revisão das implementações PHP, das diferenças encontradas e das regressões está no [registro de atualização por commit](updates/2026-10-14/commit-audit.json).

## Revisão dos commits de 26/10/2026

O [registro desta revisão](updates/2026-10-26/commit-audit.json) relaciona os cinco
commits publicados em 26/10 na janela UTC/Brasil, os arquivos PHP e os testes
correspondentes. Quatro mudanças exigiram implementação: histórico completo nos
`DeviceProps` do pareamento, resultado PN/LID por número, registro do código
pendente depois de uma reconexão e espera pelo reinício do servidor após ADV.
O isolamento de socket/Noise já estava coberto pelas correções de geração da
conexão da MVO.

A validação desta revisão usa testes locais e fixtures de protocolo. Ela não
comprova novo pareamento em contas reais nem disponibilidade de histórico no
telefone. O contrato PHP mantém `exists=null` para uma consulta sem veredito de
contatos, inclusive quando há LID; não transforma essa ausência em número fora
do WhatsApp. Consulte [Conexão](CONEXAO.md), [Histórico](HISTORICO.md) e
[Identidades](IDENTIDADES.md) para os contratos públicos.

| Área | Implementação PHP | Evidência / limite |
|---|---|---|
| Login e retomada | QR, código, lock, persistência, keepalive, reconexão, XX/IK/XXfallback e routing-info | Retomada real da sessão `default` sem QR após estas mudanças; identidades preservadas |
| Mensagens | Tipos estruturados, raw WAProto, Signal, grupos, own-device fanout, addons, botões corrigidos | Botões rápidos Web/celular confirmados anteriormente; demais formatos com testes locais |
| Confiabilidade | Inbox/outbox, receipts duráveis, delivered/read/played, retry autorizado por dispositivo, recuperação Signal | Retries antigos sem plaintext/audience persistidos exigem tratamento explícito |
| WhatsApp Flows | Tipos `flow`, `flow-navigate` e `flow-exchange`, formulário inline sem ID manual, dados como objeto JSON e respostas com token | Envio, abertura e conclusão inline comprovados no Web entre contas reais; Android ainda apresentou erro. Endpoint exchange e Flow publicado pendentes. Veja [FLOWS.md](FLOWS.md) |
| Formulários nativos | Tipo `form`, definição local, seleção única/múltipla, texto livre portável com `cancel_option` explícito, até três etapas finais e `InputFields` no modo Android | Adaptação portável verificada em duas mensagens reais: texto livre no Web (resposta v1) e cancelamento no Android (resposta v3), com interface confirmada pelo usuário e respostas recebidas/decodificadas pela sessão remetente. Evidência anterior: opções Web/Android e `InputFields` Android. Testes PHP/parser Web adicionais; versões dos aplicativos e demais combinações não homologadas por esse ensaio. Consulte [FORMULARIOS.md](FORMULARIOS.md) |
| Histórico | Chats/contatos/mensagens, sync comprimido, sink incremental opcional com commit/abort e persistência externa, pedido de páginas, compartilhamento restrito de histórico de grupos | Sink coberto localmente por integridade, replay, cancelamento e conversa de 32 MiB; depende dos dados enviados pelo primário, limites configurados e idempotência externa. Veja [Histórico](HISTORICO.md) |
| App-state | Chaves, MACs, LT hash, snapshots/patches, conflitos, filas, projeção atômica e 67 ações: 66 do snapshot e extensão `LabelMessage` | Archive/mute/read/pin/star/labels/contacts/delete-for-me e ações genéricas; exige chaves da conta |
| Grupos/comunidades | Serviço dedicado `community()`: criar/listar/editar, foto, participantes/admins, convites, solicitações, vínculos/criação de subgrupos, propriedade e consultas IQ/MEX | Validação e protocolo em testes locais; permissões e disponibilidade no serviço atual dependem da conta. Veja [COMUNIDADES.md](COMUNIDADES.md) |
| Conta | Presença/chatstate, perfil/foto/status/username, privacidade, bloqueios, Business e email | Algumas operações são exclusivas de Business ou primário mobile |
| Business comercial | Perfil/capa PPS, catálogo/produtos CRUD, coleções CRUD/associações/recurso/reordenação, carrinho, categorias, pedidos; etiquetas/respostas rápidas/listas por app-state | IQ/MEX e uploads com fixtures; patches autenticados em peer local; disponibilidade e autorização comercial precisam de homologação na conta real. Veja [BUSINESS.md](BUSINESS.md) |
| Manutenção do núcleo | Tokens trusted-contact/cstoken, configuração A/B tipada, digest/recuperação de pré-chaves, rotação durável de signed prekey e retomada offline em lotes | Configuração A/B e digest de 812 pré-chaves validados na sessão real; tokens, recuperação e rotação com testes locais |
| Status e listas | Texto, imagem, vídeo/voz com sender-key, audiência salva, consultas com expiração, revogação, privacidade, mute, recibos observados, leitura/respostas/reações e download; API com 19 operações | Consultas limitadas ao histórico sincronizado; visualizações somente por recibos observados. Veja [STATUS.md](STATUS.md). Listas comerciais dependem da conta Business |
| Canais | Serviço `channels()`, CRUD/foto/configurações, diretório, administradores/convites/propriedade, posts/mídia plaintext, perguntas/respostas, enquetes, reações/votos/views, pin/unpin/rótulos, histórico/eventos duráveis; API com 62 operações | Protocolos e reinício testados localmente; nenhuma mutação em canal real. Métricas avançadas e recursos sem contrato confirmado têm limites explícitos. Veja [CANAIS.md](CANAIS.md) |
| Bots | Descoberta/perfil, prompts diretos/menções, Signal sidecar, chunks autenticados | Depende de bots habilitados para a conta |
| Mídia | CBC/HMAC/hash, streams, reupload 404/410, GD, processor FFmpeg/ffprobe opcional | FFmpeg/ffprobe não estão instalados pelo Composer; conversão exige executáveis configurados |
| Passkeys | Shortcake, assinatura WebAuthn externa, handoff/ECDH, confirmação/expiração | Não fornece um autenticador de hardware; validado localmente |
| Mobile primário | TCP/Noise, login Android/iOS, importação explícita, link QR/código, índices, keyshare e bootstrap | Exige identidade primária já registrada; não cadastra número por SMS |
| Stores | SQLite, PDO MySQL/PostgreSQL, Redis RESP nativo, MongoDB opcional, CAS/rollback/confirmação perdida | Credenciais ficam no diretório protegido; dados não são migrados automaticamente; Mongo requer extensão |
| Extensões | Plugins PHP, once/off, filtros, handlers e dispose | Nenhum plugin JavaScript é carregado |
| Ferramentas opcionais | WAM opt-in, MCP stdio/Streamable HTTP e [servidor local](FAKE-WHATSAPP.md) com QR/código, IK/XXfallback, Signal/grupos, HTTPS, histórico e app-state | MCP com allowlist, Host/Origin/bearer e sem eval; fake com rotas explícitas e uma conexão WebSocket ativa, sem equivalência ao servidor WhatsApp completo |
| Chamadas | Individual/grupo, Signal, roster/rekey/joinable, vídeo/app-data, SRTP/SRTCP, UDP/WebRTC ICE/DTLS/SCTP nativo e RelayPool | Transporte/criptografia em loopback e tons PCM em chamada real entre dois workers; outros clientes, hardware e vídeo ainda exigem validação |
| Áudio/vídeo de chamadas | Opus/MLow C por FFI, RED, PLC/FEC, RTCP/NACK/PLI/FIR/REMB, H.264, jitter, clock, captura/render/gravação FFmpeg explícitos e pós-filtro Companion opcional | Dependências nativas opcionais; Companion exige modelo externo; qualidade neural e hardware não avaliados; paridade RS descartada como referência |

## Atualização após a tag 1.10.0

| Commit | Comportamento conferido ou acrescentado em PHP |
|---|---|
| `64767d9` | Formato H.264 SPS/STAP-A/FU-A já compatível; novos vetores e extensão RTP de vídeo autenticada |
| `78cdf14` | Publicação de versões dos pacotes; nenhum comportamento adicional |
| `04073f8` | RX independente de TX, guardas de vídeo, RED copy-first/PT120, agrupamento MLow, pacing SR e REMB; backend Companion opcional |
| `961bdaf` | Silenciar reprodução, substituir microfone e escolher saída por backend nativo |
| `3c59491` | Publicação de versões dos pacotes; nenhum comportamento adicional |
| `1ebadd9` | Grupos, roster/rekey, atividade de remetentes e promoção individual → grupo com o transporte existente |
| `caeace8` | Reutilização de buffers FFI, índices de participantes/SSRC e redução de trabalho por pacote |
| `bec579b` | Correções de seleção de aparelhos, transações e inscrições; gerações de relay, reemissão de credenciais e recuperação limitada |
| `653042d` | Publicação das versões 1.4.0/0.3.0 dos pacotes de chamadas/mídia; versão MVO independente |

A aplicação mantém suas APIs PHP; a divisão interna dos arquivos TypeScript não foi reproduzida como um requisito de compatibilidade. O [registro por commit](updates/2026-10-14/commit-audit.json) contém as revisões completas, fontes, arquivos PHP, testes e limites. As medições da aba de desempenho são anteriores a esta atualização: seus dados e hashes originais foram preservados.

## Chamadas e diferenças restantes

Os módulos de chamadas estão documentados em [VOIP.md](VOIP.md), [VOIP-GRUPOS.md](VOIP-GRUPOS.md) e [VOIP-MIDIA.md](VOIP-MIDIA.md). Há WebRTC nativo, relays múltiplos, grupos com rekey/roster, MLow e captura/reprodução explícitas. O backend Companion é opcional e exige pesos externos; o ciclo nativo foi testado com tensores sintéticos, sem comprovar qualidade neural. Em 26/10/2026, tons foram medidos nos dois sentidos de chamadas reais entre dois workers de teste. Isso não homologa áudio em hardware, clientes Android/Web ou vídeo. Não há recuperação da paridade Reed-Solomon proprietária que a referência também descarta. App-data transporta a atribuição de efeitos, sem renderizador gráfico. Quando ambos os flags SFrame estão ativos, somente app-data é recusado explicitamente; áudio/vídeo seguem SRTP. A fonte consultada também não aplica essa camada extra de app-data. Uma chamada só se torna conectada após mídia autenticada; dispositivos não abrem automaticamente.

Não há promessa de equivalência de todos os métodos, providers internos, flags experimentais ou comportamentos de pacotes opcionais. WAM não produz atividade sintética/automática; o fake é um harness de desenvolvimento com rotas/fixtures documentadas, não a infraestrutura do WhatsApp. MCP oferece stdio e Streamable HTTP com ferramentas permitidas explicitamente; não oferece eval, shell ou chamadas IQ arbitrárias. Bibliotecas C/FFmpeg são opcionais para mídia/chamadas e não introduzem Node.js. Recursos sem implementação não recebem respostas de sucesso simuladas.

## Como verificar

`composer check` executa diagnóstico, sintaxe, suíte legada, PHPUnit, PHPStan nível 6 e verificação real do Xdebug. Os testes novos usam criptografia entre partes, vetores independentes, recusas, replay, rollback, reinício, loopback de rede e subprocessos Windows. Eles não executam alterações em grupos, privacidade, canais, passkeys ou chamadas da conta real.

`composer voip:doctor` verifica FFI, WebRTC/codecs e executáveis AV sem abrir hardware ou conectar WhatsApp. Os testes nativos incluem PCM → Opus → SRTP → SCTP/DTLS local → PCM. Dependências ausentes são reportadas explicitamente.

A validação real anterior retomou `default` sem QR, processou mensagens recebidas, importou chaves do aparelho principal e autenticou as cinco coleções de app-state. O armazenamento passou a conter 53 conversas e 400 contatos. Nenhuma mensagem de teste foi enviada. A disponibilidade de cada recurso novo e as permissões da conta ainda precisam ser verificadas usando a aplicação. O relatório histórico inicial permanece em [ANALISE.md](ANALISE.md), identificado como diagnóstico da primeira revisão.

A consulta real posterior confirmou novamente a retomada sem QR e as mesmas identidades, sincronizou configuração A/B e validou o digest remoto (`reason=ok`, 812 pré-chaves), sem erros de serviço ou mensagens de teste enviadas. Não houve rotação forçada, chamada real ou ativação de dispositivos; veja [COORDENADORES.md](COORDENADORES.md).
