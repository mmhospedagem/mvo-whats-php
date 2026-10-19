# Facebook PHP

A biblioteca `MVO\Facebook` conecta uma conta Facebook por e-mail e senha, guarda a sessão confirmada e a restaura nas próximas execuções. Os serviços da conta usam o protocolo Web em PHP; não exigem aplicativo Meta, OAuth ou a API Graph oficial.

O pacote é `mmhospedagem/mvo-facebook-php`. Ele tem instalação, namespace e armazenamento separados do [SDK WhatsApp](../CONEXAO.md). A classe principal é `FacebookConnection`; o runtime Messenger é uma dependência interna, sem exigir outro cliente na aplicação.

## Caminho recomendado

1. [Instale o pacote](INSTALACAO.md) e prepare diretórios privados.
2. [Conecte e guarde a sessão](CONEXAO.md); futuros processos usam apenas `connect()`.
3. [Descubra as Páginas e escolha a identidade](PAGINAS.md).
4. Consulte os serviços de que sua aplicação precisa e mantenha `poll()` no loop externo.

## Recursos por área

| Área | Implementação atual | Guia |
| --- | --- | --- |
| Autenticação | Login, persistência, restauração e continuação de desafios reconhecidos. | [Conexão e sessão](CONEXAO.md) |
| Páginas | Descoberta parcial e seleção local explícita. | [Páginas](PAGINAS.md) |
| Publicações | Timeline paginada e binding de criação de texto, com contexto atual obrigatório. | [Publicações](PUBLICACOES.md) |
| Comentários e reações | Leituras paginadas, contadores e bindings de escrita contextualizados. | [Comentários e reações](COMENTARIOS-REACOES.md) |
| Inbox de Página | Leitura de conversas e mensagens; envio não implementado. | [Inbox](INBOX.md) |
| Anúncios | Consultas Web parciais por IDs conhecidos; sem alterações comerciais. | [Anúncios](ANUNCIOS.md) |
| Monitoramento | Snapshots, diferenças e eventos locais em SQLite. | [Monitoramento](MONITORAMENTO.md) |
| Integração PHP | Exemplos executáveis e composição com um Messenger existente. | [Exemplos](EXEMPLOS.md) |

## O que significa estar conectado

`login()` salva somente uma sessão cuja identidade foi confirmada. `connect()` restaura essa sessão e inicia o transporte compartilhado. `metadata_connected` confirma metadados utilizáveis; não comprova acesso a todas as Páginas nem a todas as conversas. O dispositivo Messenger E2EE ainda não está implementado: `e2ee_ready=false`, sem fallback para texto sem criptografia.

As operações comerciais têm cobertura parcial. Métodos que precisam de providers Relay, contexto do composer ou IDs opacos exigem os valores reais da sessão; esses valores não são preparados automaticamente. A presença de um binding e de testes locais não comprova uma publicação ou alteração real. Consulte a [matriz de limites e evidências](LIMITES.md) antes de escolher um recurso.

## Separação entre canais

Use `MVO\WaClient` para WhatsApp e `MVO\Facebook\FacebookConnection` para Facebook. Não reutilize QR, JIDs, tokens ou credenciais de WhatsApp no Facebook. Uma conta Facebook tem seu próprio ID local e dono de lock; cada conta adicional exige outra sessão. Nenhuma API REST, painel ou ferramenta MCP Facebook faz parte deste pacote.

Fontes do contrato: [FacebookConnection](../../packages/facebook/src/FacebookConnection.php), [README do pacote](../../packages/facebook/README.md) e [autenticação compartilhada Messenger](../FACEBOOK-MESSENGER.md).
