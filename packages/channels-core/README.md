# MVO Channels Core — desenvolvimento local

Contratos PHP 8.2+ para organizar integrações independentes por canal e provedor. Este diretório é um pacote Composer local, ainda sem publicação. Não implementa protocolos, autenticação ou uma API REST e não é uma dependência obrigatória do SDK WhatsApp existente.

## Contratos

`MVO\Channels\ChannelClient` expõe somente:

```php
channel(): string;
provider(): string;
capabilities(): MVO\Channels\Capabilities;
accountInfo(): array;
on(string $event, callable $handler): static;
sendText(string $to, string $text): array;
```

Os destinos, IDs, payloads e resultados conservam o formato do provedor. Um resultado de envio não comprova entrega, leitura ou exibição. A aplicação precisa incluir a conexão e o canal nas chaves de deduplicação; um ID numérico do Facebook não se transforma em JID WhatsApp. Eventos nativos podem ter dados privados e valores binários: use projeções explícitas antes de publicá-los.

`MVO\Channels\PersistentClient` acrescenta `connect(): void`, `poll(float $timeoutSeconds = 0.5): void`, `stop(): void` e `isConnected(): bool`. Esse contrato é opcional: um provedor baseado em requisições/webhooks não precisa implementar um loop artificial. `connect()` inicia/restaura, enquanto o estado/evento do provedor confirma quando a conexão pode ser utilizada. `stop()` mantém credenciais válidas; revogação/logout é uma operação específica do provedor.

`Capabilities` aceita uma lista explícita de nomes. `supports('message.text')` consulta o suporte oferecido pelo contrato do adaptador; ausência não deve ser interpretada como suporte implícito.

## Configuração pública e seleção do provedor

```php
use MVO\Channels\ChannelRegistry;
use MVO\Channels\ConnectionConfig;

$config = new ConnectionConfig(
    id: 'cliente-42',
    channel: 'whatsapp',
    provider: 'mvo_protocol',
    authMode: 'qr',
);

$registry = new ChannelRegistry();
// A aplicação registra sua factory de cada pacote instalado.
// $registry->register('whatsapp', 'mvo_protocol', $whatsappFactory);
// $client = $registry->create($config);

echo json_encode($config, JSON_THROW_ON_ERROR);
// {"id":"cliente-42","channel":"whatsapp","provider":"mvo_protocol","auth_mode":"qr"}
```

O descritor é imutável e contém exclusivamente `id`, `channel`, `provider` e `authMode`. Não aceita cookies, tokens, senhas, caminhos de credenciais nem opções arbitrárias. Esses dados são fornecidos em configurações privadas específicas de cada pacote, capturadas pela factory na aplicação. O descritor pode ser serializado; isso não autoriza serializar o cliente, seus eventos ou seus objetos de autenticação.

A factory recebe `ConnectionConfig` e retorna `ChannelClient`, sem conectar implicitamente. Ela valida a modalidade de autenticação suportada e decide como resolver o armazenamento privado da conexão. O registro usa o par exato canal/provedor, rejeita cadastros duplicados e resultados de outro canal/provedor, e não faz fallback. Não armazena instâncias nem compartilha sessões automaticamente: a aplicação continua sendo responsável por isolamento entre clientes e dono exclusivo de cada sessão ativa.

## Usar via Composer local

No `composer.json` da aplicação consumidora, configure o caminho absoluto deste pacote ou um caminho relativo à aplicação:

```json
{
  "repositories": [
    {"type": "path", "url": "../MVO Whatsapp LIB/packages/channels-core", "options": {"symlink": true}}
  ],
  "require": {"mmhospedagem/mvo-channels-core": "*@dev"}
}
```

Execute `composer install` na aplicação e carregue seu próprio `vendor/autoload.php`. Esse procedimento não altera o `composer.json` do SDK WhatsApp. Os caminhos do exemplo precisam corresponder ao seu ambiente; não há pacote publicado correspondente a este trabalho local.

## Verificação sem conta

Na raiz do SDK, usando o PHPUnit de desenvolvimento existente:

```sh
php vendor/bin/phpunit --configuration packages/channels-core/phpunit.xml
```

Os testes exercitam seleção exata de factories, isolamento de instâncias, IDs preservados como strings, modalidades de autenticação, rejeição de fallback e descritores imutáveis. Usam fixtures locais e não validam nenhum servidor Facebook ou WhatsApp.
