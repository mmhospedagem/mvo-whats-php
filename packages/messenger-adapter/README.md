# Adaptador Messenger para MVO Channels — desenvolvimento local

Adaptador PHP opcional entre `MVO\Messenger\MessengerClient` e o contrato comum `MVO\Channels\PersistentClient`. O pacote local é `mmhospedagem/mvo-messenger-adapter`; a classe é `MVO\Channels\Messenger\MessengerChannelAdapter`.

O protocolo e as credenciais continuam no pacote Messenger. O adaptador apenas delega chamadas a **um cliente já existente**, sem iniciar conexão ou criar outra sessão no construtor. O SDK WhatsApp continua independente e não exige nenhum desses pacotes.

## Instalação por caminhos locais

Core e adaptador continuam opcionais de desenvolvimento no monorepo. O runtime Messenger tem distribuição própria no Packagist. Para desenvolver os três em conjunto, configure o `composer.json` da aplicação consumidora e ajuste os caminhos:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../MVO Whatsapp LIB/packages/channels-core",
      "options": {"symlink": true, "versions": {"mmhospedagem/mvo-channels-core": "dev-main"}}
    },
    {
      "type": "path",
      "url": "../MVO Whatsapp LIB/packages/messenger",
      "options": {"symlink": true, "versions": {"mmhospedagem/mvo-messenger-php": "dev-main"}}
    },
    {
      "type": "path",
      "url": "../MVO Whatsapp LIB/packages/messenger-adapter",
      "options": {"symlink": true, "versions": {"mmhospedagem/mvo-messenger-adapter": "dev-main"}}
    }
  ],
  "require": {
    "mmhospedagem/mvo-channels-core": "dev-main",
    "mmhospedagem/mvo-messenger-php": "dev-main",
    "mmhospedagem/mvo-messenger-adapter": "dev-main"
  }
}
```

Execute `composer install` na aplicação e carregue o `vendor/autoload.php` dela. As versões `dev-main` são metadados explícitos do repositório `path`, não um lançamento no Packagist.

## Encapsular e registrar

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use MVO\Channels\ChannelRegistry;
use MVO\Channels\ConnectionConfig;
use MVO\Channels\Messenger\MessengerChannelAdapter;
use MVO\Messenger\MessengerClient;

$privateDirectory = __DIR__ . '/var/private-auth';
$registry = new ChannelRegistry();
$registry->register('messenger', 'mvo_protocol', static function (ConnectionConfig $config) use ($privateDirectory): MessengerChannelAdapter {
    if (!in_array($config->authMode, ['email_password', 'cookie_import', 'restore'], true)) {
        throw new InvalidArgumentException('Modalidade Messenger não configurada nesta factory');
    }
    return new MessengerChannelAdapter(new MessengerClient(
        sessionId: $config->id,
        authDirectory: $privateDirectory,
    ));
});

$client = $registry->create(new ConnectionConfig(
    id: 'cliente-messenger',
    channel: 'messenger',
    provider: 'mvo_protocol',
    authMode: 'restore',
));
```

Criar o cliente não conecta nem solicita autenticação. A modalidade seleciona o fluxo da aplicação; a autenticação acontece por uma ação explícita. `email_password` usa o método nativo `MessengerClient::login()`; não é OAuth nem API oficial.

Mantenha o armazenamento privado fora da pasta HTTP pública. Para a primeira autenticação, use o [login nativo](../messenger/README.md#sessão-e-primeiro-exemplo), com **o mesmo ID e diretório**, antes de conectar. Receba e-mail/senha por uma entrada privada da aplicação. `nativeClient()` dá acesso ao `MessengerClient` encapsulado para `login()`, `loginChallenge()`, `confirmLogin()`, `importCookies()`, `threads()`, `refreshThreads()`, `events()` e `outgoing()`.

O registro devolve o tipo comum `ChannelClient`. Guarde o adaptador concreto quando sua aplicação precisar dos métodos específicos, ou valide seu tipo antes de chamar `nativeClient()`.

```php
// Entrada privada e ação explícita, antes de connect(), fora dos callbacks:
if ($client instanceof MessengerChannelAdapter) {
    $client->nativeClient()->login($email, $password);
}
```

Não grave a senha na configuração serializável, em argumentos CLI ou em logs. O método salva somente a sessão validada; CAPTCHA/2FA/checkpoint exigem a confirmação do operador e não são contornados. Esse fluxo foi testado com fixtures; autenticação real permanece pendente.

## Loop e capabilities

```php
use MVO\Channels\PersistentClient;

if (!$client instanceof PersistentClient) {
    throw new LogicException('Este fluxo exige uma conexão persistente');
}

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
            // Projete e processe eventos aqui; deduplique por conexão/thread/ID.
        }
        $pending = [];
    }
} finally {
    $client->stop();
}
```

Capabilities Messenger incluem `message.text.unencrypted`; **não** há `message.text` genérico que prometa envio em qualquer thread. Consulte `supports()` com o nome exato e mantenha a verificação por thread no cliente nativo. Threads criptografadas geram `messenger_e2ee_not_ready`, sem fallback.

`accountInfo()` mantém `state=metadata_connected` após o sync e `e2ee_ready=false`. O adaptador não muda esses estados para sugerir prontidão E2EE. ACK de transporte, reconhecimento de servidor, entrega e leitura continuam distintos.

| Evidência | Alcance |
| --- | --- |
| Contratos, registry e delegação | Exercitáveis localmente com clientes/fixtures isolados. |
| Protocolo DGW/LS | Implementação experimental PHP, com testes locais do pacote Messenger. |
| Login e-mail/senha | Implementação do formulário Web atual com testes criptográficos/fixtures; conta real pendente. |
| Conexão e mensagens Facebook reais | Pendentes de validação com conta própria. |
| Dispositivo Messenger E2EE | Não implementado. |

Leia [o guia multicanal](../../docs/MULTICANAL.md), [o contrato Messenger](../messenger/README.md) e [a evidência de engenharia reversa](../../docs/FACEBOOK-MESSENGER.md). Nenhuma API REST privada ou credencial de produção faz parte deste pacote.
