# Cobertura, erros e validação

A biblioteca Facebook é experimental. Cobertura implementada, teste com transporte simulado e resultado observado numa conta real são evidências distintas. Métodos e documentos persistidos não comprovam compatibilidade total do Facebook.

## Matriz de cobertura

| Recurso | Contrato implementado | Evidência e limite |
| --- | --- | --- |
| Login e sessão | E-mail/senha, confirmação de identidade, persistência e restauração. | Login real confirmado com continuação humana e restauração; sem garantia de todos os desafios. |
| 2FA | Desafio reconhecido em memória e confirmação de autenticador. | Testes locais; conclusão completa desse caminho em conta real não demonstrada. |
| CAPTCHA | Handoff privado opcional e confirmação independente da identidade. | Exige ação humana; pacote não abre navegador nem resolve desafio automaticamente. |
| Metadados Messenger | DGW/Lightspeed, cursores e loop PHP. | Conta real chegou a `metadata_connected`, com 13 conversas e observações curtas; não comprova estabilidade prolongada. |
| Messenger E2EE | Não implementado. | `e2ee_ready=false`; sem fallback plaintext. |
| Páginas | Descoberta parcial e escolhas locais. | Projeção de sete recursos `profile_plus` num bootstrap autenticado real; não prova inventário completo. |
| Timeline, comentários e reações | Bindings de leitura/escrita e projeções reconhecidas. | Fixtures e artefatos públicos; escritas não validadas em conta real. Contextos/providers precisam ser fornecidos. |
| Inbox comercial | Leitura de threads/mensagens. | Fixtures locais; envio como Página não implementado. |
| Anúncios | Consultas Web parciais, objetos por IDs conhecidos e métricas agregadas. | Contratos/fixtures; queries comerciais não validadas em conta real. Sem mutations comerciais. |
| Monitor | SQLite, snapshots, cursores, deduplicação e diferenças. | Testes locais; não coleta rede ou prova eventos reais. |

As observações reais acima foram feitas em 18/10/2026. Não houve envio de mensagens, publicação, comentário, reação ou alteração publicitária nesses testes Facebook. A validação de autenticação/metadados não se estende automaticamente aos serviços comerciais.

## Recursos ainda ausentes

Não há conector Instagram neste pacote, dispositivo E2EE, envio pelo inbox de Página, CRUD geral de Fanpage, cargos/administração geral, upload de mídia, edição/exclusão/moderação de posts/comentários ou insights orgânicos completos. Preparação automática de composer/providers Relay também não está implementada.

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
