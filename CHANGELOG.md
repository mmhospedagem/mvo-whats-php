# Changelog

As versões da biblioteca seguem versionamento semântico. Mudanças no protocolo do WhatsApp podem exigir atualizações de compatibilidade, independentemente da versão da biblioteca.

## Em desenvolvimento — WhatsApp

- Isolamento por geração de conexão: nós, respostas de consultas e falhas antigas não autenticam nem encerram uma sessão substituta após callbacks de ciclo de vida. Consultas interrompidas preservam o resultado incerto sem replay e não removem o estado de consultas da nova conexão.

- `messageContextInfo` disponível em envios tipados, com precedência dos campos gerados pelo builder, contexto de citação/menções separado e preservação do formato raw.
- Agrupadores de álbuns usam `enc.mediatype=collection`, inclusive quando o conteúdo está envolvido por wrappers de mensagem.
- Portadores de distribuição de chave acompanhados somente de metadados internos não geram mensagens vazias. Conteúdo de grupo autenticado por uma chave já estabelecida pode ser recebido mesmo quando um portador pairwise opcional é inválido, sem confirmar ratchets parciais; falhas do conteúdo `skmsg` continuam exigindo retry limitado.
- Envelope Business Galaxy específico do papel da sessão: companion usa atributo direto, primário mantém metadados nativos aninhados. Contexto vem das credenciais, sem opção de mensagem para alterar o papel.

- Corpo Business Galaxy alinhado à captura nativa com `messageVersion=3`, parâmetros de mensagem vazios e fanout inicial sem override legado; replay mantém o nó exato.

- Envelope nativo para Business Galaxy v4 com nome real e metadados de extensões, preservando o JSON cifrado e o caminho dos Flows legados.

- Publicação de formulários aplica o padrão nativo `V700` somente quando a versão estiver ausente, preservando versões explícitas e a incerteza das tentativas anteriores.

- Recusas GraphQL de formulários conservam um identificador de diagnóstico somente quando reconhecido exatamente, sem expor a mensagem remota nem inferir causa por código numérico.

- Parser do IQ 118 corrigido para o atributo `result.status` documentado no cliente Web, sem aceitar nós de texto legados ou status conflitantes. Diagnóstico seguro da autenticação de formulários: fase nativa, falha de formato/transporte/timeout distinta de recusa IQ e presença estrutural dos campos esperados, sem payloads privados ou mudança de protocolo.

- Guard opcional `setBusinessFormsGuard()` para revalidar autorização entre fases remotas e antes do transporte de mensagens de formulários, preservando snapshots e impedindo replay implícito após retirada de acesso.
- Gerenciamento de modelos Business por `WaClient::forms()`: catálogo local persistente, edição, duplicação, modelos iniciais e importação/exportação do schema Android. Não sincroniza o catálogo privado do aplicativo.
- Autenticação SMB por nonce, publicação HTTP explícita e mensagem `business-form` Galaxy v4 com ID remoto e `wellVersion` retornados pelo servidor. Geração assistida devolve a saída ou recusa para revisão, sem publicar automaticamente.
- Publicações e tentativas de envio conservam snapshots, IDs e resultados incertos; uma repetição da mesma requisição não republica nem reenvia implicitamente. Respostas CSV são solicitadas ao primário e verificadas como documento gzip.
- Novos contratos derivados do Web e APK Business, cobertos por testes locais de autenticação, HTTP, Signal, persistência e falhas. A aceitação pelo servidor e a interface em clientes reais ainda precisam de validação; não há garantia de compatibilidade Web/Android por análise estática.
- ACK de protocolo para nós `<status>` de contatos e canais, preservando os atributos de roteamento. A confirmação não decodifica conteúdo nem marca visualização.
- Reconexão automática para o erro de stream sem código explícito nem conflito que contém `<ack class="status">`, usando a sessão existente e sem repetir comandos interrompidos. Revogação e os demais códigos conservam suas regras.
- Fila de recibos com índices reconstruídos por páginas, retries limitados por ciclo e remoção após escrita concluída. Falhas preservam o nó exato para nova tentativa, sem exigir um ACK que pode não chegar nem autorizar reenvio de mensagens de usuário.
- Recibos de cópias autenticadas enviadas por outros aparelhos da própria conta usam `sender` e conservam o destinatário da conversa, inclusive nas redeliveries, sem confundir sincronização entre aparelhos com entrega ao contato.

## Em desenvolvimento — Facebook e Messenger

- Serviços de Páginas, pessoas/tarefas comerciais, métricas, edição/exclusão de conteúdo, ocultação de comentários e upload multipart de fotos, com contratos Web observados, contexto explícito e ledger privado.
- Álbuns, upload resumable de vídeo pelo transporte PHP, publicação de fotos e vídeo já carregados, cargos/convites de Página, acesso a assets e bloqueio de usuários/mensagens.
- Tabela de conteúdo com providers atuais, paginação delimitada e exportação local CSV/JSON, preservando células indisponíveis e a distinção de totais/pago/orgânico.
- Inbox de Página com caminho Lightspeed em stream DGW vinculado ao asset da Página e contrato Mercury legado separado. Preflight de destinatário/composer, OTID persistido e confirmação correlacionada; ACK não comprova entrega ou leitura.
- Provisionamento experimental explícito de dispositivo E2EE, separado de mensagens criptografadas. Não registra durante conexão nem habilita `e2ee_ready`.
- Componentes locais E2EE de Noise/certificados, Signal, trust, fanout/DSM, franking e inbox/outbox/recibos, no mesmo armazenamento privado Messenger. Testes sintéticos não comprovam login/interoperabilidade atual; o envio comum permanece bloqueado para threads criptografadas.
- Novos guias, referência e contexto para LLMs. Estas extensões locais não estão nas releases Facebook 0.1.0/Messenger 0.1.1. Testes sintéticos e fontes públicas não comprovam escritas em conta real.
- Runtime WhatsApp preservado. Nenhuma mudança na API consumidora ou publicação de pacote nesta etapa.

## 1.0.3 — 2026-10-18

- Documentação separada para WhatsApp e Facebook, com navegação e busca próprias, referência PHP, exemplos e contextos para LLMs. Os links existentes dos 49 guias WhatsApp foram preservados.
- Código dos pacotes opcionais Facebook/Messenger e contratos multicanal organizado sob `packages/`. As distribuições Composer Facebook e Messenger têm nomes próprios; o pacote WhatsApp não passa a depender delas.
- Facebook inclui login PHP, sessão confirmada persistente e restauração, recursos Web parciais de Páginas/publicações/interações, leitura de inbox e anúncios, e monitor de snapshots em SQLite. E2EE pessoal, envio pelo inbox comercial e escritas de anúncios dependentes da Graph API permanecem indisponíveis.
- Compatibilidade do runtime Messenger com PHP 8.5: chamadas obsoletas de SplObjectStorage/cURL e uma chave de fixture ajustadas, mantendo as verificações estritas.
- Testes dos pacotes opcionais e dos dois portais incluídos na CI. O laboratório privado e arquivos de sessão ficam fora das distribuições públicas.
- Os arquivos do runtime WhatsApp não foram alterados nesta atualização. A validação Facebook desta etapa usa transportes sintéticos; ela não comprova publicação, entrega ou compatibilidade completa em contas reais.

## 1.0.2 — 2026-10-18

- Formulários portáveis com `kind: input` e `cancel_option` explícita: cada campo usa resposta de texto livre e uma opção visível de cancelamento, preservando o ID original. A adaptação segue o parser distribuído do WhatsApp Web, com limite de três etapas após expansão e rejeição de valores iniciais não suportados. O modo Android com `InputFields` permanece separado.
- Exemplos PHP e contexto para LLMs descrevem o tratamento de texto/cancelamento e os limites de validação em clientes reais.
- Em teste entre duas contas, a API recebeu o texto enviado pelo WhatsApp Web e o cancelamento enviado pelo Android com as chaves originais. Essa evidência se limita ao formulário e aos clientes usados no teste.

## 1.0.1 — 2026-10-17

Primeira distribuição no [Packagist](https://packagist.org/packages/mmhospedagem/mvo-whats-php), com o nome Composer **`mmhospedagem/mvo-whats-php`**.

- Instalação direta com `composer require "mmhospedagem/mvo-whats-php:^1.0"`, sem configurar repositório VCS.
- Guias de instalação, contexto para LLMs e referência de agentes atualizados para o nome público do pacote.
- As classes e o namespace `MVO\` permanecem iguais. Instalações iniciais por VCS com `mvo/mvo-php` devem atualizar a dependência Composer; a mudança não exige recriar as sessões nem alterar os caminhos de credenciais.

## 1.0.0 — 2026-10-17

Primeira distribuição pública da **MVO WHATS PHP**.

- Cliente PHP 8.2+ com conexão por QR Code ou código de pareamento, sessão persistente, eventos e reconexão.
- Envio e recebimento de mensagens, mídia, interativos, formulários, histórico e recibos.
- Serviços de grupos, comunidades, canais, Status, perfil e recursos comerciais.
- Armazenamento de estado, identidades PN/LID, sincronização, agendamentos e ferramentas de diagnóstico.
- Interface MCP em PHP para integração com clientes de LLM, além de documentação `llms.txt` e `llms-full.txt`.
- Tutoriais de instalação, integração, exemplos e ambiente de desenvolvimento.

Os limites de cada recurso estão nos respectivos guias. A presença de uma API ou definição Protobuf não garante que todo cliente WhatsApp, conta ou tipo de sessão aceite o recurso. Codecs e transporte nativo de chamadas são componentes opcionais.
