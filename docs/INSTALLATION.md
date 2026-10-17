# Instalação — MVO WHATS PHP

[Início](../README.md) · [Primeira conexão](GETTING_STARTED.md) · [Exemplos](EXAMPLES.md)

## Requisitos

| Item | Necessário para |
| --- | --- |
| PHP **8.2 ou superior**, CLI, **64 bits** | Executar a biblioteca e o worker persistente |
| Composer 2 | Instalar e autocarregar as dependências |
| `openssl`, `sodium`, `gmp` | Criptografia e autenticação |
| `PDO`, `pdo_sqlite` | Credenciais e estado persistente padrão |
| `json`, `zlib`, `curl`, `fileinfo` | Serialização, compressão e transferência de mídia |
| `iconv` | Dependência do renderizador QR instalado pelo Composer |
| `libxml`, `xmlwriter` | Gerar QR em SVG com `QrCode::svg()` |
| Saída de rede com DNS e TLS | Conectar ao WhatsApp e transferir mídias |
| Pasta privada persistente e gravável | Armazenar cada sessão e suas chaves |

O protocolo executa em PHP. Não é necessário instalar Node.js, npm, Apache ou um banco SQL externo para conectar e trocar mensagens. O backend padrão usa SQLite.

Recursos opcionais: `gd` para miniaturas; `sockets` e `ffi` para componentes de chamadas; FFmpeg/ffprobe para conversões e captura de mídia; drivers adicionais para outros backends. Consulte [Mídia](MIDIA.md), [Armazenamento](STORES.md) e [VoIP](VOIP.md) antes de habilitá-los. Bibliotecas nativas são específicas do sistema operacional: uma DLL de Windows não é executada no Linux.

## Ubuntu 24.04

Instale os pacotes com o gerenciador do sistema:

```sh
sudo apt update
sudo apt install -y php-cli php-common php-curl php-gmp php-sqlite3 php-xml php-mbstring php-zip ca-certificates git unzip composer
```

O Ubuntu 24.04 disponibiliza PHP 8.3 nos pacotes padrão, atendendo ao requisito PHP 8.2+. Se já houver outra instalação, confirme qual executável e configuração serão usados com `php -v` e `php --ini`. Os nomes e dependências dos pacotes podem ser consultados no [catálogo oficial do Ubuntu](https://packages.ubuntu.com/noble/php-cli).

Confira o ambiente antes de instalar a biblioteca:

```sh
php -v
php --ini
php -m
composer --version
```

As extensões OpenSSL, Sodium, JSON, zlib e fileinfo costumam fazer parte dos pacotes PHP da distribuição; a verificação acima confirma a instalação real. Não instale um segundo PHP apenas para corrigir uma extensão ausente: use os pacotes correspondentes à versão selecionada.

Opcionalmente, instale miniaturas e processamento de mídia:

```sh
sudo apt install -y php-gd ffmpeg
```

O Composer e os comandos da aplicação devem ser executados pelo usuário que mantém o projeto; `sudo` é necessário apenas para a instalação dos pacotes do sistema.

## Windows

1. Instale uma distribuição PHP 8.2+ x64 e os requisitos de runtime indicados no [manual oficial do PHP para Windows](https://www.php.net/manual/en/install.windows.php). Para um worker CLI, use um build compatível com as extensões que pretende carregar.
2. Adicione a pasta do executável PHP ao `PATH`, abra um novo terminal e confira `php -v`.
3. Instale o Composer pelo [instalador oficial](https://getcomposer.org/download/), selecionando esse mesmo `php.exe`.
4. Use `php --ini` para encontrar o arquivo de configuração realmente carregado. Se necessário, crie `php.ini` a partir do modelo fornecido na distribuição.
5. Habilite as extensões disponíveis na pasta `ext`, removendo o `;` das linhas correspondentes. Não duplique uma extensão que já esteja ativa.

Exemplo para uma instalação em `C:\php`:

```ini
extension_dir = "C:/php/ext"
extension=openssl
extension=sodium
extension=gmp
extension=pdo_sqlite
extension=curl
extension=fileinfo
extension=mbstring
extension=zip
```

`PDO`, JSON, zlib, iconv e XMLWriter podem já estar habilitados no build. Confirme com `php -m`; não adicione linhas de DLLs inexistentes. A [documentação de extensões no Windows](https://www.php.net/manual/en/install.pecl.windows.php) explica compatibilidade entre versão, arquitetura e TS/NTS. O PHP distribui suporte Sodium para Windows, habilitado pelo `php.ini`; veja o [manual Sodium](https://www.php.net/manual/en/sodium.installation.php).

```powershell
php -v
php --ini
php -m
composer --version
```

Se houver falha de verificação TLS, configure um arquivo de autoridades certificadoras válido em `curl.cainfo` e `openssl.cafile`. Preserve a verificação dos certificados.

Para desenvolvimento com Xdebug, o repositório também oferece `tools/setup.ps1`. Esse bootstrap tem requisitos próprios de build PHP e gera configuração local; consulte [Desenvolvimento](DESENVOLVIMENTO.md). Ele não é obrigatório para consumir a biblioteca.

## Instalar no seu projeto com Composer

O nome do pacote é **`mmhospedagem/mvo-whats-php`** e o namespace é **`MVO\`**. O pacote está disponível no [Packagist](https://packagist.org/packages/mmhospedagem/mvo-whats-php), repositório padrão do Composer. A instalação não exige adicionar `repositories` ao `composer.json` da aplicação.

No diretório de um projeto que já possui `composer.json`:

```sh
composer require "mmhospedagem/mvo-whats-php:^1.0"
composer check-platform-reqs
```

O requisito `^1.0` seleciona versões estáveis compatíveis da série 1.x. A primeira versão publicada com esse nome no Packagist é `1.0.1`. Para acompanhar explicitamente a branch `main`, use `composer require "mmhospedagem/mvo-whats-php:dev-main"`. A branch de desenvolvimento pode mudar; mantenha o `composer.lock` da aplicação versionado para reproduzir a revisão instalada.

Configuração equivalente para uma aplicação nova:

```json
{
  "name": "minha-empresa/whatsapp-app",
  "type": "project",
  "require": {
    "php": "^8.2",
    "mmhospedagem/mvo-whats-php": "^1.0"
  }
}
```

Depois de salvar, execute `composer install`. O projeto consumidor carrega as classes com:

```php
require __DIR__.'/vendor/autoload.php';

$client = new MVO\WaClient(
    sessionId: 'cliente-demo',
    authDirectory: __DIR__.'/storage/whatsapp',
);
```

Use `mmhospedagem/mvo-whats-php` na dependência Composer e `MVO\WaClient` no código PHP. O nome do pacote e o namespace têm funções diferentes.

### Migrar a instalação inicial por VCS

Se você instalou a distribuição inicial como `mvo/mvo-php`, substitua essa dependência por `mmhospedagem/mvo-whats-php` no `composer.json` da aplicação e remova a entrada VCS desta biblioteca em `repositories`, caso exista. Atualize o lock com `composer update` e confira as alterações antes do deployment. As classes `MVO\`, os IDs de sessão e os caminhos de credenciais permanecem os mesmos; preserve o armazenamento da aplicação. Veja a nota da versão [1.0.1 no changelog](../CHANGELOG.md).

## Clonar para executar os exemplos

```sh
git clone https://github.com/mmhospedagem/mvo-whats-php.git
cd mvo-whats-php
composer install --no-dev --prefer-dist
composer check-platform-reqs --no-dev
php examples/protocol.php
php examples/session-events.php --help
```

Esses dois últimos comandos não conectam uma conta real. Para iniciar o vínculo:

```sh
php examples/connect.php --session=demo --mode=qr
```

O QR aparece no terminal; no diretório padrão, o SVG fica em `.auth/demo/qr.svg`. O processo precisa continuar aberto. O [guia de primeira conexão](GETTING_STARTED.md) mostra como definir uma pasta de credenciais própria e integrar os eventos ao seu sistema.

Os comandos Composer definidos **neste repositório**, como `composer worker`, são atalhos dos exemplos. Eles não são adicionados automaticamente ao `composer.json` da aplicação que instala o pacote como dependência.

## Preparar testes e desenvolvimento

Para instalar também PHPUnit e PHPStan, use **PHP 8.2+**, com as extensões de desenvolvimento solicitadas pelo Composer. A suíte usa PHPUnit 11.5 e mantém o mesmo requisito mínimo do runtime.

```sh
composer install --prefer-dist
php -d zend.assertions=1 vendor/bin/phpunit --configuration phpunit.xml.dist
php vendor/bin/phpstan analyse --configuration phpstan.neon.dist --no-progress
```

No Windows, invoque os mesmos arquivos com `php`; não é necessário um script `.sh`. A suíte pode exigir extensões adicionais informadas pelo Composer. Os testes de integração reais são separados: leia o cenário antes de executá-lo contra uma conta.

Para testar transporte e mensagens sem uma conta real, use o [simulador PHP local](FAKE-WHATSAPP.md). Para depurar no editor, consulte [Desenvolvimento](DESENVOLVIMENTO.md).

## Persistência e permissões

- Armazene `authDirectory` fora da raiz HTTP pública e em um volume que sobreviva aos reinícios e deployments.
- O usuário do worker precisa poder ler e escrever na pasta. No Linux, use permissões restritas; no Windows, aplique ACLs para a conta do serviço.
- As credenciais contêm chaves da conta e não são criptografadas automaticamente em repouso. Não publique a pasta, seus backups ou QR/códigos de pareamento.
- Use um worker por par `authDirectory`/`sessionId`. Não remova locks manualmente enquanto o processo estiver ativo.
- Execute o worker com um supervisor de processos para retomar após uma falha ou reinicialização do sistema. O HTTP da sua aplicação deve comunicar-se com esse worker.

## Solução de problemas

| Sintoma | Verificação |
| --- | --- |
| Composer informa `ext-*` ausente | Confira `php --ini`, `php -m` e se o Composer usa o mesmo PHP do terminal. |
| QR SVG falha por `XMLWriter` | Instale/habilite XMLWriter e libxml; no Ubuntu, o pacote é `php-xml`. |
| `Class MVO\WaClient not found` | Execute o Composer na aplicação e carregue `vendor/autoload.php` do diretório correto. |
| `Session already in use` | Encerre ou utilize o worker que já é dono daquela sessão. |
| `Cannot create session directory` | Verifique o caminho e a permissão do usuário do processo. |
| Falha TLS/CA | Revise relógio, autoridades certificadoras, proxy e acesso de saída. |
| QR conecta e encerra imediatamente | Mantenha `poll()`/`run()` ativos em um processo CLI persistente. |
| `logged_out` ou vínculo revogado | Faça novo pareamento explícito; reiniciar o processo não restaura um vínculo removido no celular. |

Próximo passo: [conectar, enviar e receber](GETTING_STARTED.md).
