# Cobertura, erros e validação

A biblioteca Facebook é experimental. Cobertura implementada, teste com transporte simulado e resultado observado numa conta real são evidências distintas. Métodos e documentos persistidos não comprovam compatibilidade total do Facebook.

**Em desenvolvimento:** as extensões de dispositivo, administração, conteúdo, upload e inbox descritas nesta revisão estão na branch de desenvolvimento; as primeiras releases Facebook 0.1.0/Messenger 0.1.1 não as incluem. Testes locais e contratos observados não comprovam execução em conta real.

## Matriz de cobertura

| Recurso | Contrato implementado | Evidência e limite |
| --- | --- | --- |
| Login e sessão | E-mail/senha, confirmação de identidade, persistência e restauração. | Login real confirmado com continuação humana e restauração; sem garantia de todos os desafios. |
| 2FA | Desafio reconhecido em memória e confirmação de autenticador. | Testes locais; conclusão completa desse caminho em conta real não demonstrada. |
| CAPTCHA | Handoff privado opcional e confirmação independente da identidade. | Exige ação humana; pacote não abre navegador nem resolve desafio automaticamente. |
| Metadados Messenger | DGW/Lightspeed, cursores e loop PHP. | Conta real chegou a `metadata_connected`, com 13 conversas e observações curtas; não comprova estabilidade prolongada. |
| Dispositivo e componentes E2EE | Chaves próprias, ICDC/registro explícito, Noise, Signal e journal de texto persistente. | Testes locais; nenhum registro/envio real. A integração de produto ainda não está habilitada; `e2ee_ready=false`. |
| Páginas | Descoberta/paginação, criação com categorias, cinco campos, leitura e solicitação de exclusão. | Contratos públicos e testes locais; formulário de criação observado no perfil pessoal, sem criar Página real. Configurações arbitrárias ausentes. |
| Timeline, comentários e reações | Bindings de leitura/escrita e projeções reconhecidas. | Fixtures e artefatos públicos; escritas não validadas em conta real. Contextos/providers precisam ser fornecidos. |
| Inbox comercial | Leitura, aquisição de contexto e envio Lightspeed com stream vinculado à Página; Mercury legado separado. | Contrato público e testes; nenhum envio real. ACK exige correlação de OTID, mensagem, thread, autor e texto; entrega/leitura não confirmadas. |
| Mídia e álbuns | Upload de fotos/vídeo, publicação de fotos e vídeo já carregados e CRUD de álbuns. | Contratos/fixtures; vídeo usa start, offset, bytes e receive sem publicação automática. Publicação básica de vídeo usa timeline `profile`, sem garantir processamento ou reprodução. |
| Administração e moderação | Pessoas, convites/cargos de Página, tarefas/acesso a assets e bloqueio de usuários/mensagens. | Permissão e confirmação recente protegidas; propriedade, todos os modelos de acesso e denúncias finais ausentes. Nenhuma alteração real nesta revisão. |
| Métricas e relatórios | Valores, séries, breakdowns, tabela de conteúdo paginada e exportação local CSV/JSON. | Mantém ausências, escopo e completude; renderers pago/liquidez não estabelecidos e export remoto ausente. Não equivale a relatório orgânico completo. |
| Anúncios | Consultas Web parciais, objetos por IDs conhecidos e métricas agregadas. | Contratos/fixtures; queries comerciais não validadas em conta real. Sem mutations comerciais. |
| Monitor | SQLite, snapshots, cursores, deduplicação e diferenças. | Testes locais; não coleta rede ou prova eventos reais. |

As observações reais acima foram feitas em 18/10/2026. Não houve envio de mensagens, publicação, comentário, reação ou alteração publicitária nesses testes Facebook. A validação de autenticação/metadados não se estende automaticamente aos serviços comerciais.

## Recursos ainda ausentes

Continuam ausentes: conector Instagram; mensagens pessoais E2EE e histórico seguro; acompanhamento contínuo realtime do inbox da Página; configurações arbitrárias de Fanpage; transferência de propriedade e cobertura de todos os modelos de acesso; fluxos gerais de Página/reels/edição audiovisual; enumeração de banidos e envio final de denúncias; todas as células de métricas e exportação remota Meta; relatórios orgânicos completos. A administração implementa somente operações Web identificadas, não todo o painel Meta. Providers/composer continuam exigindo contexto real. A configuração de foto pode ser lida do bootstrap autenticado, quando disponível.

Anúncios não têm criação/publicação de campanha, conjunto, anúncio ou criativo, nem alterações de status/orçamento. A integração mantém a restrição de usar somente operações Web estabelecidas, sem recorrer à Graph API interna do Ads Manager para preencher lacunas.

## Erros de autenticação e conexão

Erros do runtime usam `MVO\Messenger\MessengerException`, com `reason` e `retryable`. Projete apenas o diagnóstico necessário; não exponha argumentos, objetos, HTML, cookies ou stack traces da autenticação.

| Motivo | Tratamento |
| --- | --- |
| `login_two_factor_required` | Consulte `loginChallenge()`; somente `two_factor` com `ready=true` aceita `confirmLogin()`. |
| `login_code_invalid` | Solicite decisão do operador; não repita o código automaticamente. |
| `login_captcha_required`, `login_challenge_required` | Verificação humana no mesmo contexto; sem resolução automática. |
| `login_challenge_expired` | O contexto foi descartado; outra tentativa explícita pode ser necessária. |
| `login_flow_unavailable`, `login_encryption_unavailable` | Formato/chave necessários indisponíveis; não envie senha sem criptografia. |
| `login_unconfirmed`, `login_identity_mismatch` | Cookies e identidade não foram confirmados consistentemente. |
| `login_rate_limited` | Aguarde; não faça um loop de tentativas. |
| `messenger_e2ee_not_ready` | Conversa criptografada indisponível neste runtime. |

Veja [conexão](CONEXAO.md) para o ciclo de vida e [Messenger](../FACEBOOK-MESSENGER.md) para o contrato compartilhado.

## Erros dos serviços Facebook

`MVO\Facebook\FacebookException` contém `reason`, `outcome`, `retryable` e `commandId` quando há comando registrado. Entrada local inválida pode lançar `InvalidArgumentException` antes da requisição. HTTP 200 pode conter erro GraphQL ou resposta parcial; não o trate como sucesso da ação.

```php
use MVO\Facebook\FacebookConnection;
use MVO\Facebook\FacebookException;

function diagnosticarComando(FacebookConnection $facebook, FacebookException $error): array
{
    return [
        'reason' => $error->reason,
        'outcome' => $error->outcome,
        'retryable' => $error->retryable,
        'command_id' => $error->commandId,
        'command' => $error->commandId === null ? null : $facebook->command($error->commandId),
    ];
}
```

O comando é dado privado local e pode conter conteúdo normalizado da ação; a função é para uso interno, não uma resposta pública pronta.

## Idempotência e resultado incerto

Mutations exigem chave de idempotência de até 256 bytes e ledger privado. O SDK registra a tentativa antes do I/O; uma falha depois disso pode deixar resultado incerto. Mesma chave com conteúdo diferente é conflito.

| Estado | Interpretação |
| --- | --- |
| `prepared` | Reserva local do comando. |
| `unknown` | A tentativa entrou no caminho de I/O; não há resultado confirmado. |
| `response_received` | Resposta recebida/parseada, sem projeção de resultado validada. |
| `confirmed` | O serviço reconheceu os IDs/campos esperados e guardou `result.projection`. |

`response_received` não confirma a mutation e não autoriza reenvio. Falha ao reconhecer resultado conserva `commandId`; não invente outra chave para repetir. Consulte `command($id)` e o estado real da ação antes de decidir. Mesma chave já confirmada retorna a projeção persistida sem HTTP.

`confirmed` não prova exibição, alcance, entrega, aprovação de anúncio ou visibilidade de uma publicação. A projeção explícita evita persistir uma árvore GraphQL bruta, mas continua sendo dado privado. O monitor de snapshots e o ledger de comandos têm funções diferentes: evento local não é recibo remoto.

## Manter a integração

Os doc IDs, formatos e providers Web podem mudar. Fontes e hashes do catálogo permitem revisar o binding; não use IDs aleatórios ou flags de fixtures para contornar indisponibilidade. Consulte o código e testes correspondentes antes de ampliar um recurso. Preserve sessões privadas, o dono único do lock e a separação entre WhatsApp e Facebook.

Referências: [FacebookException](../../packages/facebook/src/FacebookException.php), [WebGraphQL](../../packages/facebook/src/Protocol/WebGraphQL.php), [CommandLedger](../../packages/facebook/src/Storage/CommandLedger.php) e [catálogo público observado](../../packages/facebook/resources/README.md).

## Extensões verificadas localmente

| Área | O que o código implementa | Limite material |
| --- | --- | --- |
| Conteúdo | Edição de texto/exclusão de story e comentário, ocultar/desocultar comentário. | Conteúdo existente sem anexos; flags de ocultação do viewer e do dono são distintas. |
| Fotos | Arquivo local JPEG/PNG/WebP, contexto real e upload multipart pelo mesmo dono. | Até 25 MiB por política do SDK; foto aceita não é publicação. |
| Administração | Pessoas paginadas, acesso/tarefas e atualização de usuário do portfólio; permissões e saída de acesso limitado. | Pode exigir nova verificação da Meta; não confere permissão que a conta não possui. |
| Insights | Valores, séries diárias e buckets observados, inclusive `PAID_ORGANIC` quando aceito. | Nem toda métrica/período permite esse breakdown; total sem separação não é orgânico. |

A observação das interfaces Inbox/Insights/Administração foi somente leitura. O operador concluiu a verificação de identidade diretamente na Meta; a biblioteca não a contornou. Foram extraídas apenas URLs de scripts CDN e baixados módulos públicos sem cookies. Não houve envio, upload, edição, exclusão, mudança de tarefas/cargos ou registro de dispositivo em conta real nesta revisão.
