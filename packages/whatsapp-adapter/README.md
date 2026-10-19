# Adaptador WhatsApp para MVO Channels — desenvolvimento local

Pacote Composer opcional PHP 8.2+ que implementa o contrato comum `MVO\Channels\PersistentClient` usando um `MVO\WaClient` já existente. O namespace é `MVO\Channels\WhatsApp`, e o cliente é `WhatsAppClient`.

O SDK raiz continua instalado e utilizado normalmente como `mmhospedagem/mvo-whats-php`, sem precisar deste adaptador ou de `channels-core`. Nenhum arquivo em `src/` foi movido e nenhuma assinatura pública do `WaClient` foi substituída.

## Instalação local

Este trabalho ainda não está publicado. No `composer.json` da aplicação consumidora, configure os pacotes locais:

```json
{
  "repositories": [
    {"type": "path", "url": "../MVO Whatsapp LIB/packages/channels-core", "options": {"symlink": true}},
    {"type": "path", "url": "../MVO Whatsapp LIB/packages/whatsapp-adapter", "options": {"symlink": true}},
    {"type": "path", "url": "../MVO Whatsapp LIB", "options": {"symlink": true}}
  ],
  "require": {"mmhospedagem/mvo-whatsapp-adapter": "*@dev"}
}
```

Execute `composer install` na aplicação e adapte os caminhos à sua instalação. O terceiro caminho usa a biblioteca WhatsApp local; a exigência `^1.0` também é compatível com a biblioteca já publicada. Para um clone local sem tag reconhecida, a aplicação pode informar a versão do repositório de caminho com `options.versions` do Composer.

## Encapsular um cliente existente

```php
<?php
declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use MVO\Channels\WhatsApp\WhatsAppClient;
use MVO\WaClient;

$native = new WaClient(
    sessionId: 'cliente-demo',
    authDirectory: __DIR__.'/var/whatsapp-auth',
);
$client = new WhatsAppClient($native);
$pending = [];

$client->on('message', static function (array $event) use (&$pending): void {
    // Preserve as chaves nativas; processe as ações depois do callback.
    $pending[] = $event;
});

try {
    $client->connect();
    while ($native->isRunning()) {
        $client->poll(0.25);
        if (!$client->isConnected()) {
            continue;
        }
        foreach ($pending as $event) {
            // Enfileire os eventos para sua aplicação, com idempotência por conexão/chat/ID.
        }
        $pending = [];
        // Execute comandos autorizados aqui, por exemplo:
        // $result = $client->sendText('5511999999999', 'Olá pela MVO!');
    }
} finally {
    $client->stop();
}
```

`var/whatsapp-auth` precisa ficar fora do diretório HTTP público. Use a mesma pasta e sessão para restaurar um vínculo válido e mantenha um único dono do lock. O adaptador não cria outro `WaClient`, não abre outra sessão nem altera as credenciais do objeto recebido. `connect()` pode iniciar pareamento: cadastre antes os listeners de autenticação apropriados ao seu fluxo.

## Registro na aplicação

```php
use MVO\Channels\ChannelRegistry;
use MVO\Channels\ConnectionConfig;
use MVO\Channels\WhatsApp\WhatsAppClient;
use MVO\WaClient;

$privateAuthDirectory = __DIR__.'/var/whatsapp-auth';
$registry = new ChannelRegistry();
$registry->register('whatsapp', 'mvo_protocol', static function (ConnectionConfig $config) use ($privateAuthDirectory): WhatsAppClient {
    if (!in_array($config->authMode, ['qr', 'pairing_code', 'restore'], true)) {
        throw new InvalidArgumentException('Modalidade de autenticação não configurada nesta factory');
    }
    return new WhatsAppClient(new WaClient(sessionId: $config->id, authDirectory: $privateAuthDirectory));
});
$client = $registry->create(new ConnectionConfig('cliente-demo', 'whatsapp', 'mvo_protocol', 'qr'));
```

A modalidade descreve o fluxo da aplicação. Para `pairing_code`, solicite o código pelo cliente nativo após `connect()` e fora dos callbacks; o descritor não solicita códigos automaticamente. Um ID aceito pelo descritor ainda pode ser rejeitado pelas restrições próprias de `WaClient`; a factory deve usar IDs válidos para essa sessão. Mobile exige configuração privada/identidade primária adequada e não é habilitado por trocar apenas `authMode`.

## Semântica preservada

- `channel()` retorna `whatsapp`; `provider()` retorna `mvo_protocol`.
- `capabilities()` declara `message.text`, `events.native`, `connection.persistent` e `native_client`. Isso não declara paridade com recursos de outros canais.
- `sendText($to, $text)` retorna o resultado original de `WaClient`. `server_ack` confirma aceitação no servidor; entrega/leitura dependem de recibos.
- `accountInfo()` retorna o snapshot público original. PN, LID e `deviceJid` continuam distintos, e campos desconhecidos permanecem `null`.
- `on()` preserva os payloads e a ordem dos callbacks nativos. **`auth_paired` continua contendo credenciais privadas**; o adaptador não é um filtro de segredos. Publique somente uma projeção explícita.
- `nativeClient()` dá acesso aos serviços específicos como mídia, grupos e Status, com suas pré-condições originais. O contrato comum não inventa equivalentes desses recursos em outros provedores.
- `stop()` encerra localmente e preserva a sessão. `nativeClient()->logout()` é uma ação explícita diferente, que desvincula o aparelho.

## Verificação local

Na raiz do SDK:

```sh
php vendor/bin/phpunit --configuration packages/whatsapp-adapter/phpunit.xml
```

As fixtures usam um transporte de nós em memória para verificar callbacks exatos, isolamento entre sessões, PN/LID, início versus login confirmado, `poll`, parada e propagação da falha de envio desconectado. Não há conexão com conta real nem comprovação de entrega em WhatsApp neste conjunto.
