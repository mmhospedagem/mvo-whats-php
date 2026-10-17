<p align="center">
  <img src="docs/assets/mvo-logo.png" width="460" alt="MVO WHATS PHP">
</p>

<h1 align="center">MVO WHATS PHP</h1>

<p align="center"><strong>Conecte. Receba. Envie. Tudo no seu projeto PHP.</strong></p>
<p align="center">Biblioteca nativa para integrar o protocolo WhatsApp a workers, aplicações e APIs PHP.</p>

<p align="center">
  <a href="https://packagist.org/packages/mmhospedagem/mvo-whats-php"><img src="https://img.shields.io/packagist/v/mmhospedagem/mvo-whats-php" alt="Versão no Packagist"></a>
  <a href="https://github.com/mmhospedagem/mvo-whats-php/actions/workflows/ci.yml"><img src="https://github.com/mmhospedagem/mvo-whats-php/actions/workflows/ci.yml/badge.svg" alt="PHP CI"></a>
  <a href="composer.json"><img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4" alt="PHP 8.2 ou superior"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-009ee3" alt="Licença MIT"></a>
</p>

<p align="center">
  <a href="docs/INSTALLATION.md">Instalação</a> ·
  <a href="docs/GETTING_STARTED.md">Primeira conexão</a> ·
  <a href="docs/EXAMPLES.md">Exemplos</a> ·
  <a href="https://mmhospedagem.github.io/mvo-whats-php/">Documentação web</a> ·
  <a href="docs/LLMS.md">LLMs e MCP</a> ·
  <a href="llms.txt">llms.txt</a>
</p>

---

**PHP 8.2+ · Linux e Windows · Composer · Sessões persistentes · Eventos**

Use uma instalação da biblioteca para atender várias contas, cada uma com seu próprio identificador, credenciais e processo de conexão. O transporte, a criptografia e os serviços da biblioteca executam em PHP, sem exigir Node.js ou uma API intermediária.

## Comece pelo terminal

Com PHP e as [extensões necessárias](docs/INSTALLATION.md#requisitos) instalados:

```sh
git clone https://github.com/mmhospedagem/mvo-whats-php.git
cd mvo-whats-php
composer install --no-dev --prefer-dist
php examples/connect.php --session=demo --mode=qr
```

Escaneie o QR em **WhatsApp → Aparelhos conectados → Conectar um aparelho**. O exemplo atualiza o QR enquanto ele muda e mantém o processo conectado. Execute novamente com a mesma sessão para restaurar um vínculo válido.

Para usar no seu próprio projeto, instale o pacote [**`mmhospedagem/mvo-whats-php`**](https://packagist.org/packages/mmhospedagem/mvo-whats-php) pelo Composer:

```sh
composer require "mmhospedagem/mvo-whats-php:^1.0"
```

O Composer encontra o pacote no Packagist, sem configurar repositório VCS. Veja os requisitos e a configuração completa no [guia de instalação](docs/INSTALLATION.md#instalar-no-seu-projeto-com-composer).

## Integre diretamente no PHP

Os comandos de terminal são exemplos. Sua aplicação controla o cliente pelas próprias classes da biblioteca:

```php
<?php
declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use MVO\Auth\QrCode;
use MVO\WaClient;

$client = new WaClient(
    sessionId: 'cliente-demo',
    authDirectory: __DIR__.'/storage/whatsapp',
);

$client->on('auth_qr', static function (array $event): void {
    // Encaminhe este SVG somente ao usuário autorizado a vincular a conta.
    file_put_contents(__DIR__.'/storage/qr-demo.svg', QrCode::svg($event['qr']));
});

$client->on('message', static function (array $event): void {
    echo ($event['from'] ?? '').': '.($event['text'] ?? '[mídia]').PHP_EOL;
});

try {
    $client->connect();
    while ($client->isRunning()) {
        $client->poll(0.25);
        // Consuma sua fila e chame sendText()/sendMessage() quando conectado.
    }
} finally {
    $client->disconnect();
}
```

Salve como `worker.php` e execute `php worker.php`. Mantenha `storage` fora da raiz pública da aplicação. Veja [como enviar mensagens no loop](docs/GETTING_STARTED.md#enviar-e-receber-na-mesma-conexão), [conectar por código](docs/GETTING_STARTED.md#conectar-por-código) e [integrar eventos à sua API](docs/GETTING_STARTED.md#integrar-com-uma-api).

## O que você pode construir

| Área | Recursos | Guia |
| --- | --- | --- |
| Conexão | QR rotativo, código, eventos, restauração e reconexão | [Conexão](docs/CONEXAO.md) |
| Mensagens | Texto, mídia, localização, contatos, reações, enquetes e interativos | [Tipos de mensagens](docs/TIPOS-MENSAGENS.md) |
| Mídia | Upload, download verificado, arquivos e streams | [Mídia](docs/MIDIA.md) |
| Conversas | Histórico, contatos, recibos, presença e sincronização de estado | [Histórico](docs/HISTORICO.md) · [Presença](docs/PRESENCE.md) |
| Grupos e comunidades | Administração, participantes, convites e configurações | [Serviços](docs/SERVICOS.md) · [Comunidades](docs/COMUNIDADES.md) |
| Business | Perfil comercial, catálogo, produtos e ferramentas de negócio | [Serviços](docs/SERVICOS.md) |
| Status e canais | Publicações, consultas, interações e gerenciamento | [Status](docs/STATUS.md) · [Canais](docs/CANAIS.md) |
| Persistência | SQLite e backends configuráveis para estado e histórico | [Armazenamento](docs/STORES.md) |
| Extensões | Formulários, agendamentos, plugins, MCP e chamadas opcionais | [Formulários](docs/FORMULARIOS.md) · [Agendamentos](docs/AGENDAMENTOS.md) · [MCP](docs/MCP-WAM.md) · [VoIP](docs/VOIP.md) |

## Uma biblioteca, várias sessões

```php
$client = new MVO\WaClient(
    sessionId: 'cliente-001',
    authDirectory: '/var/lib/minha-aplicacao/whatsapp',
);
```

Para 60 clientes, use uma instalação do código e 60 sessões isoladas. A arquitetura recomendada é um worker por sessão, supervisionado pela sua aplicação. Não abra dois processos para o mesmo identificador e diretório: a biblioteca usa um lock para impedir conexões concorrentes. [Veja a organização completa](docs/GETTING_STARTED.md#várias-contas-na-mesma-instalação).

## Guias para avançar

- [Instalar no Ubuntu 24.04 ou Windows](docs/INSTALLATION.md).
- [Conectar, persistir a sessão e trocar mensagens](docs/GETTING_STARTED.md).
- [Copiar exemplos de mensagens, eventos e serviços](docs/EXAMPLES.md).
- [Consultar identidade da conta e PN/LID](docs/IDENTIDADES.md).
- [Explorar histórico e app-state](docs/HISTORICO.md) · [App-state](docs/APPSTATE.md).
- [Consultar formatos de Flow e suas limitações](docs/FLOWS.md).
- [Executar o simulador local](docs/FAKE-WHATSAPP.md) e [configurar o desenvolvimento](docs/DESENVOLVIMENTO.md).
- [Ler medições e metodologia de desempenho](docs/DESEMPENHO.md).
- [Integrar LLMs e agentes com MCP](docs/LLMS.md), ou fornecer [llms-full.txt](llms-full.txt) como contexto completo ao modelo.

## Participe

Encontrou um problema ou quer contribuir? Veja [como contribuir](CONTRIBUTING.md), os [canais de relato](SECURITY.md) e o [changelog](CHANGELOG.md). A documentação também está disponível offline em [docs/index.html](docs/index.html).

## Compatibilidade e operação

A biblioteca implementa o protocolo WhatsApp e não é um produto oficial da Meta. A disponibilidade de um recurso e sua renderização dependem da conta, do servidor e do aplicativo receptor. Consulte a [matriz de recursos](docs/PARIDADE.md) e os guias específicos; testes locais não comprovam suporte universal em contas reais.

O processo PHP precisa continuar executando para receber eventos e manter a conexão. Credenciais e estado persistem no disco; `disconnect()` encerra o transporte preservando o vínculo, enquanto `logout()` solicita a remoção do aparelho. Uma sessão revogada no celular exige novo pareamento.

Mensagens funcionam com as extensões PHP descritas na instalação. Alguns recursos de mídia e chamadas utilizam FFmpeg ou bibliotecas nativas opcionais instaladas separadamente. No Linux, essas dependências usam os binários da plataforma; DLLs de Windows não são usadas no Ubuntu.

## Licença

Distribuído sob a [licença do projeto](LICENSE). Consulte também os [avisos e atribuições de terceiros](THIRD_PARTY_NOTICES.md).
