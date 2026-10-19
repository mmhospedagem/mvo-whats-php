# Facebook PHP

A biblioteca `MVO\Facebook` conecta uma conta Facebook por e-mail e senha, guarda a sessão confirmada e a restaura nas próximas execuções. Os serviços da conta usam o protocolo Web em PHP; não exigem aplicativo Meta, OAuth ou a API Graph oficial.

O pacote é `mmhospedagem/mvo-facebook-php`. Ele tem instalação, namespace e armazenamento separados do [SDK WhatsApp](../CONEXAO.md). A classe principal é `FacebookConnection`; o runtime Messenger é uma dependência interna, sem exigir outro cliente na aplicação.

**Em desenvolvimento:** as extensões de dispositivo, administração, conteúdo, upload e inbox descritas nesta revisão estão na branch de desenvolvimento; as primeiras releases Facebook 0.1.0/Messenger 0.1.1 não as incluem. Testes locais e contratos observados não comprovam execução em conta real.

## Caminho recomendado

1. [Instale o pacote](INSTALACAO.md) e prepare diretórios privados.
2. [Conecte e guarde a sessão](CONEXAO.md); futuros processos usam apenas `connect()`.
3. [Descubra as Páginas e escolha a identidade](PAGINAS.md).
4. Consulte os serviços de que sua aplicação precisa e mantenha `poll()` no loop externo.

## Recursos por área

| Área | Implementação atual | Guia |
| --- | --- | --- |
| Autenticação | Login, persistência, restauração e continuação de desafios reconhecidos. | [Conexão e sessão](CONEXAO.md) |
| Páginas | Descoberta/paginação, criação com categorias no perfil pessoal, cinco campos editáveis e solicitação de exclusão. | [Páginas](PAGINAS.md) |
| Publicações | Timeline, criação de texto, edição de texto e exclusão, com contexto atual obrigatório. | [Publicações](PUBLICACOES.md) |
| Comentários e reações | Leitura, criação, edição/exclusão e ocultação de comentários; reações e contadores separados. | [Comentários e reações](COMENTARIOS-REACOES.md) |
| Inbox de Página | Leitura, contexto atual e envio experimental Lightspeed em stream vinculado à Página; Mercury legado separado. | [Inbox](INBOX.md) |
| Mídia e álbuns | Upload de fotos/vídeo, publicação de fotos e vídeo carregados, legendas/exclusão de assets e gestão de álbuns. | [Mídia](MIDIA.md) |
| Administração e moderação | Pessoas, convites/cargos de Página, tarefas/acesso a assets e bloqueios de usuários/mensagens. | [Administração](ADMINISTRACAO.md) |
| Insights | Métricas agregadas, tabela de conteúdo paginada e exportação local CSV/JSON com limites explícitos. | [Insights](INSIGHTS.md) |
| Dispositivo e E2EE | Provisionamento explícito e componentes criptográficos locais; prontidão integrada ainda bloqueada. | [Dispositivo E2EE](DISPOSITIVO-E2EE.md) |
| Anúncios | Consultas Web parciais por IDs conhecidos; sem alterações comerciais. | [Anúncios](ANUNCIOS.md) |
| Monitoramento | Snapshots, diferenças e eventos locais em SQLite. | [Monitoramento](MONITORAMENTO.md) |
| Integração PHP | Exemplos executáveis e composição com um Messenger existente. | [Exemplos](EXEMPLOS.md) |

## O que significa estar conectado

`login()` salva somente uma sessão cuja identidade foi confirmada. `connect()` restaura essa sessão e inicia o transporte compartilhado. `metadata_connected` confirma metadados utilizáveis; não comprova acesso a todas as Páginas nem a todas as conversas. Dispositivo e runtime Messenger E2EE são componentes explícitos separados desse login. As mensagens criptografadas continuam bloqueadas pela ausência do verificador remoto ICDC de produção e de validação real: `e2ee_ready=false`, sem fallback plaintext.

As operações comerciais têm cobertura parcial. Métodos que precisam de providers Relay, contexto do composer ou IDs opacos exigem os valores reais da sessão; não existe aquisição universal desses valores. Criação, foto/vídeo, inbox e tabela de conteúdo têm leitores próprios; outros documentos exigem contexto fornecido pela aplicação. A presença de um binding e de testes locais não comprova uma publicação ou alteração real. Consulte a [matriz de limites e evidências](LIMITES.md) antes de escolher um recurso.

## Separação entre canais

Use `MVO\WaClient` para WhatsApp e `MVO\Facebook\FacebookConnection` para Facebook. Não reutilize QR, JIDs, tokens ou credenciais de WhatsApp no Facebook. Uma conta Facebook tem seu próprio ID local e dono de lock; cada conta adicional exige outra sessão. Nenhuma API REST, painel ou ferramenta MCP Facebook faz parte deste pacote.

Fontes do contrato: [FacebookConnection](../../packages/facebook/src/FacebookConnection.php), [README do pacote](../../packages/facebook/README.md) e [autenticação compartilhada Messenger](../FACEBOOK-MESSENGER.md).

## Extensões da biblioteca

- [Mídia e álbuns](MIDIA.md): upload nativo, publicação explícita de fotos/vídeo, legendas, exclusão de assets e gestão de álbuns; transferir não publica automaticamente.
- [Administração](ADMINISTRACAO.md): pessoas, acesso e tarefas observadas, com pré-condições de identidade.
- [Insights](INSIGHTS.md): valor, série diária e breakdowns; totais não são renomeados como orgânicos.
- [Dispositivo E2EE](DISPOSITIVO-E2EE.md): preparação local e registro explícito, sem habilitar mensagens criptografadas.
