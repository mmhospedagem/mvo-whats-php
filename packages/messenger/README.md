# MVO Messenger PHP — experimental

Cliente PHP independente para o protocolo Web do **Facebook Messenger**. A implementação usa HTTPS, WebSocket DGW e Lightspeed, sem a API oficial da Meta e sem runtime Node.js ou Go. O pacote é `mmhospedagem/mvo-messenger-php`, com namespace `MVO\Messenger`.

**O caminho de mensagens pessoais com criptografia de ponta a ponta ainda não foi implementado.** `metadata_connected` indica que o cliente concluiu a conexão e a sincronização inicial de metadados; `accountInfo()['e2ee_ready']` permanece `false`. Threads criptografadas são bloqueadas antes do envio, sem fallback para texto sem criptografia. Em **18/10/2026**, a autenticação com continuação humana no navegador foi confirmada pelo PHP e salva. A restauração dessa sessão atingiu `metadata_connected`, com metadados de **13 conversas** e WebSocket ativo durante **12 segundos** de observação. **Nenhuma mensagem foi enviada nesse teste**; envio/recebimento, entrega, leitura e estabilidade prolongada permanecem sem validação real.

Este pacote é distribuído na série experimental **0.1**. O SDK WhatsApp continua separado, na série pública **1.0.3**, com seu `MVO\WaClient`, pacote e armazenamento próprios. Instalar o SDK WhatsApp não instala Messenger.

## Instalação

Use PHP **8.2+ de 64 bits**, com `curl`, `json`, `openssl`, `sodium`, `pdo` e `pdo_sqlite`, conforme [composer.json](composer.json). Na aplicação consumidora:

```sh
composer require "mmhospedagem/mvo-messenger-php:^0.1"
```

Carregue `vendor/autoload.php`. [Packagist](https://packagist.org/packages/mmhospedagem/mvo-messenger-php) · [Repositório de distribuição](https://github.com/mmhospedagem/mvo-messenger-php) · [Documentação Facebook](https://mmhospedagem.github.io/mvo-whats-php/facebook/). Para login e serviços Facebook pela fachada `FacebookConnection`, instale `mmhospedagem/mvo-facebook-php:^0.1`, que inclui este runtime.

### Instalação local para desenvolver no monorepo

Use PHP **8.2+ de 64 bits**, extensões `curl`, `json`, `openssl`, `sodium`, `pdo` e `pdo_sqlite`. No `composer.json` de uma aplicação consumidora:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../mvo-whats-php/packages/messenger",
      "options": {
        "symlink": true,
        "versions": {"mmhospedagem/mvo-messenger-php": "0.1.x-dev"}
      }
    }
  ],
  "require": {"mmhospedagem/mvo-messenger-php": "0.1.x-dev"}
}
```

Ajuste o caminho à localização deste checkout, execute `composer install` na aplicação e carregue o `vendor/autoload.php` dela. O repositório `path` aponta para o checkout de desenvolvimento, em vez de instalar um release.

## Sessão e primeiro exemplo

O fluxo principal recebe **e-mail e senha da própria conta Facebook** por uma entrada privada da aplicação. `MessengerClient::login()` obtém a página atual de `www.messenger.com`, extrai o formulário e sua chave pública, cifra a senha no formato `#PWD_BROWSER` e só salva a sessão depois de confirmar cookies e identidade. A senha não é persistida pelo cliente. Os cookies mantêm seus domínios reais; não são copiados artificialmente entre Facebook e Messenger.

```php
// E-mail e senha devem vir de uma entrada privada da aplicação, sem hardcode.
// Execute somente na primeira autenticação ou renovação, com a conexão parada.
$client->login($email, $password);
$client->connect();
```

A aplicação fornece sua própria entrada privada de credenciais. A biblioteca não exige painel ou importação de JSON para esse fluxo. O laboratório utilizado na validação é privado e não integra a distribuição pública.

Quando há autenticação em duas etapas por aplicativo autenticador, `login()` retorna `login_two_factor_required` e preserva o desafio em memória por até dez minutos. Consulte `loginChallenge()`; com `ready=true`, receba o código em uma entrada privada e chame `confirmLogin($code)`, mantendo os zeros iniciais. O código não deve ir para logs ou argumentos CLI. `ready=false` bloqueia um formato desconhecido. A sessão só é salva depois de confirmar cookies e identidade, e `connect()` continua sendo uma etapa separada.

`stop()` cancela o desafio; expiração ou reinício do processo exigem novo login. CAPTCHA, checkpoint e consentimento retornam motivos específicos para o operador verificar no aplicativo/site. A biblioteca não contorna desafios e não inventa formulário, chave ou endpoint quando a página muda. A entrada de credenciais por CAA/Relay sem formulário ainda não está implementada. O fluxo real com continuação humana foi validado em 18/10/2026; isso não garante login sem desafio nem suporte a todos os formatos de verificação.

A aplicação pode integrar uma **continuação humana opcional no navegador isolado**, usando o contexto da tentativa pendente em uma janela isolada. `exportPrivateLoginChallenge()` entrega URL/cookies/expiração somente ao bridge privado da aplicação; nunca serialize esse resultado em HTTP, eventos, ferramentas ou logs. Após a verificação oficial, `completeBrowserLogin(CookieJar $cookies)` confirma por HTTPS que o `USER_ID` positivo corresponde ao `c_user`, antes de persistir. A conta de uma sessão existente continua imutável. Esse auxiliar desktop não lê perfis pessoais existentes nem é dependência do protocolo Messenger; o operador conclui os desafios oficiais, e o PHP verifica a sessão. A transferência seguida da restauração nativa foi confirmada em 18/10/2026, sem comprovar outros desafios ou contas.

A conta não pode mudar dentro do mesmo ID de sessão. Para retomar uma sessão válida, chame apenas `connect()` com o mesmo ID/diretório, sem fornecer a senha de novo. Para outra conta, crie outro ID.

### Alternativa avançada: importar uma sessão existente

`importCookies()` permanece disponível para um arquivo privado explícito de cookies. O importador aceita uma lista de objetos com `name`, `value`, `domain`, `path`, `secure`, `expires`/`expirationDate` e `hostOnly`, ou um objeto nome/valor. Mantenha o arquivo fora da pasta pública HTTP e do Git. Não passe valores de cookies ou senha em argumentos da CLI, mensagens ou logs.

Os comandos abaixo partem da raiz do monorepo `mvo-whats-php`. No repositório de distribuição `mvo-messenger-php`, execute `composer install` e use `examples/...` no lugar de `packages/messenger/examples/...`.

O exemplo CLI abaixo restaura/importa uma sessão; o login por e-mail/senha usa o método PHP `login()`:

```sh
php packages/messenger/examples/connect.php --help
php packages/messenger/examples/connect.php --session=demo --auth-dir=/caminho/privado/mvo-auth --cookies-file=/caminho/privado/facebook-cookies.json --seconds=30
```

Para restaurar, use o mesmo `--session` e `--auth-dir` sem `--cookies-file`. O exemplo faz conexão, mantém o loop pelo intervalo escolhido e imprime projeções de diagnóstico sem conteúdo de mensagens. **Ele não envia mensagens.** `--cookies-file` recebe apenas o caminho local; nunca passe os valores dos cookies na linha de comando.

## Contrato PHP

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use MVO\Messenger\MessengerClient;

$client = new MessengerClient(
    sessionId: 'cliente-demo',
    authDirectory: __DIR__ . '/var/private-auth',
);

// Na primeira autenticação/renovação, com entrada privada da aplicação:
// $client->login($email, $password);
// Para restaurar uma sessão válida, não repita o login.

$pending = [];
$client->on('message', static function (array $event) use (&$pending): void {
    $pending[] = $event;
});

try {
    $client->connect();
    $until = microtime(true) + 30;
    while ($client->isConnected() && microtime(true) < $until) {
        $client->poll(0.25);
        foreach ($pending as $event) {
            // Aplique ações da sua aplicação aqui, com idempotência.
            // Não execute comandos dentro do callback do listener.
        }
        $pending = [];
    }
} finally {
    $client->stop();
}
```

Mantenha `var/private-auth` fora do diretório HTTP público. Cada sessão ativa tem um dono exclusivo do lock; `poll()` mantém transporte e sincronização. `stop()` fecha a conexão local e libera o lock, preservando a sessão salva. Não há `logout()` Messenger implementado neste pacote.

| Método | Comportamento atual |
| --- | --- |
| `login(string $email, string $password): void` | Autentica pelo formulário Web atual e salva somente a sessão confirmada; não inicia DGW. |
| `loginChallenge(): ?array` | Projeta `type`, `expires_at` e `ready` do desafio pendente, sem tokens; `null` quando ausente/expirado. |
| `confirmLogin(string $code): void` | Continua a confirmação pelo código real do autenticador; verifica a sessão antes de salvá-la. Não inicia DGW. |
| `exportPrivateLoginChallenge(): array` | Handoff privado da tentativa pendente para um browser bridge local; contém segredos e nunca pode ser resposta pública. |
| `completeBrowserLogin(CookieJar $cookies): void` | Verifica a identidade por HTTPS antes de salvar os cookies vindos do contexto isolado. Exige desafio pendente e lock; não inicia DGW. |
| `importCookies(CookieJar $cookies): void` | Importa/renova a mesma conta, com conexão parada. |
| `connect(): void` | Restaura cookies, valida identidade, abre DGW e aguarda sync inicial. |
| `poll(float $timeoutSeconds = 0.5): void` | Processa eventos e continuação dos cursores. |
| `isConnected(): bool` / `getState(): string` | Consulta o estado de metadados e transporte. |
| `accountInfo(): array` | Retorna uma projeção pública; `e2ee_ready` é `false`. |
| `threads(): array` | Consulta o inventário local sincronizado. |
| `refreshThreads(): array` | Solicita atualização de metadados pela conexão ativa. |
| `events(int $after = 0, int $limit = 100): array` | Consulta eventos persistidos, por cursor local. |
| `sendText(string $threadId, string $text): array` | Aceita apenas thread conhecida, com tipo de envio sem criptografia explicitamente suportado. |
| `outgoing(string $id): ?array` | Consulta o estado local usando o `offline_id` retornado. |
| `on(string $event, callable $handler): static` | Registra listener. Comandos pertencem ao loop externo. |
| `stop(): void` | Encerra localmente e libera o dono da sessão. |

As capabilities atuais são `session.email_password`, `session.cookie_import`, `connection.persistent`, `threads.metadata`, `message.text.unencrypted` e `events.unencrypted`. Elas descrevem o contrato implementado; a validação real limitada está discriminada abaixo. `login()` confirma a autenticação da sessão; `connect()` depois prepara o transporte e o sync de metadados. Nenhum deles registra o dispositivo E2EE.

### Enviar para uma thread confirmada

Depois de conectar e **fora dos callbacks**, selecione um ID exato retornado por `threads()` ou `refreshThreads()`:

```php
// Exemplo de comando de aplicação; não é executado pelo exemplo connect.php.
$threadId = '10000000000000001'; // Fictício: substitua pelo ID confirmado do destino autorizado.
$thread = null;
foreach ($client->threads() as $candidate) {
    if ($candidate['id'] === $threadId) $thread = $candidate;
}
if ($thread !== null && ($thread['plaintext_send_supported'] ?? false) === true) {
    $result = $client->sendText($thread['id'], 'Olá pela MVO!');
    $state = $client->outgoing($result['offline_id']);
}
```

Envie apenas para um destino autorizado. IDs Facebook permanecem strings nativas; não use número telefônico ou JID WhatsApp como substituto.

`submitted` indica submissão ao transporte com resposta correlacionada. `server_ack` requer projeção do reconhecimento de mensagem retornado pelo servidor. **Nenhum desses estados comprova entrega, leitura ou renderização.** Uma falha depois de iniciar I/O resulta em `send_outcome_uncertain`; examine outbox/eventos antes de decidir reenviar. O cliente não faz replay automático de mensagens incertas.

## Evidências e limites

| Área | Implementado | Teste local | Validação em conta real |
| --- | --- | --- | --- |
| Login por e-mail/senha e continuação humana | Formulário Web atual, chave dinâmica, senha cifrada e handoff privado opcional | Envelope decifrado por receptor sintético; login/redirects/desafios simulados | Em 18/10/2026: fluxo com verificação humana, identidade confirmada pelo PHP e sessão salva; outros desafios não comprovados |
| Cookies, identidade, lock e restauração | Sim | Fixtures, isolamento e renovação da mesma conta | Em 18/10/2026: restauração da mesma sessão confirmada; concorrência de lock coberta pelos testes locais |
| Bootstrap, identidade e versão dinâmica LS | Sim, com rejeição de dados ausentes | HTML/JS sintéticos; comparação estática com módulos públicos | Bootstrap HTTPS nativo confirmado em 18/10/2026 |
| WebSocket binário e DGW v5 | Sim | Peer TCP localhost, bytes de frames, ACKs, streams e limites | `metadata_connected` e WebSocket ativo por 12 s observados em 18/10/2026; estabilidade prolongada não medida |
| Lightspeed e projeções de metadados | Subconjunto explícito e limitado | ASTs sintéticas, rollback, cursor e deduplicação | Metadados de 13 conversas sincronizados em 18/10/2026; nenhum envio nesse teste |
| Texto em threads sem criptografia suportadas | Implementação experimental | Comandos/resultados simulados | Pendente |
| Mensagens pessoais E2EE e histórico criptografado | **Não** | Bloqueio sem fallback | Não disponível |
| Feed, posts, anúncios e administração geral Facebook | **Não** | Fora do contrato | Não disponível |

Execute os testes do pacote a partir da raiz, usando as dependências de desenvolvimento já instaladas:

```sh
php vendor/bin/phpunit --configuration packages/messenger/phpunit.xml
```

Os testes não acessam contas Meta. A análise dos artefatos públicos e as referências estão detalhadas em [Facebook Messenger](https://github.com/mmhospedagem/mvo-whats-php/blob/main/docs/FACEBOOK-MESSENGER.md). Veja [multicanal](https://github.com/mmhospedagem/mvo-whats-php/blob/main/docs/MULTICANAL.md) e o [adaptador opcional](https://github.com/mmhospedagem/mvo-whats-php/blob/main/packages/messenger-adapter/README.md) para integrar os contratos compartilhados.

## Código e referências

O código PHP deste pacote é original, sob MIT. A implementação de terceiros [mautrix/meta, revisão 1b3bd5d](https://github.com/mautrix/meta/tree/1b3bd5db21113e8b42cb45e7464019332653d277) foi consultada como referência de protocolo; seus arquivos AGPL não são uma dependência instalada ou código vendorizado no pacote. A observação de JavaScript público foi estática, sem executar o aplicativo dentro do SDK. Protocolo e loop usam PHP, sem runtime Go, Node.js ou ponte externa. O auxiliar desktop opcional do laboratório serve somente à verificação humana no login; a aplicação consumidora implementa sua própria interface e política de acesso.
