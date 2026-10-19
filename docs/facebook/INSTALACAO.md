# Instalar a biblioteca Facebook

O pacote `mmhospedagem/mvo-facebook-php` é distribuído na série experimental **0.1**. Seu runtime Messenger é uma dependência transitiva. Instalar o SDK WhatsApp não instala Facebook automaticamente; os pacotes e os diretórios privados são separados.

## Requisitos

Use PHP 8.2+ de 64 bits, com `curl`, `json`, `openssl`, `sodium`, `pdo` e `pdo_sqlite`. As exigências efetivas estão nos arquivos [Composer Facebook](../../packages/facebook/composer.json) e [Composer Messenger](../../packages/messenger/composer.json). O protocolo e o loop executam em PHP; Node.js e Go não são runtimes deste pacote.

O [dispositivo e os componentes experimentais E2EE](DISPOSITIVO-E2EE.md) do checkout também exigem `gmp`; stanzas comprimidas exigem `zlib`. São adicionais ao login, metadados e serviços Web. Preparar/registrar o dispositivo não habilita mensagens criptografadas. Facebook 0.1.0/Messenger 0.1.1 ainda não incluem essas extensões; para avaliá-las localmente use os repositórios `path` abaixo.

O Composer deste checkout exige Messenger `^0.1.2`, para não aceitar um runtime publicado anterior às novas interfaces. Até a publicação coordenada dos pacotes, o alias local `0.1.x-dev` do exemplo satisfaz essa dependência; os dois caminhos locais são necessários.

## Composer na aplicação consumidora

Na pasta da sua aplicação:

```sh
composer require "mmhospedagem/mvo-facebook-php:^0.1"
```

Use o `vendor/autoload.php` dessa aplicação. Não é preciso cadastrar repositório VCS ou instalar o SDK WhatsApp. [Pacote Facebook no Packagist](https://packagist.org/packages/mmhospedagem/mvo-facebook-php) · [Fontes de distribuição](https://github.com/mmhospedagem/mvo-facebook-php).

Se quiser apenas `MessengerClient`, sem os serviços Facebook:

```sh
composer require "mmhospedagem/mvo-messenger-php:^0.1"
```

## Alternativa: desenvolver no monorepo

Com um clone de `mvo-whats-php` ao lado da aplicação, configure os pacotes locais no `composer.json` consumidor:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../mvo-whats-php/packages/messenger",
      "options": {
        "versions": {"mmhospedagem/mvo-messenger-php": "0.1.x-dev"}
      }
    },
    {
      "type": "path",
      "url": "../mvo-whats-php/packages/facebook",
      "options": {
        "versions": {"mmhospedagem/mvo-facebook-php": "0.1.x-dev"}
      }
    }
  ],
  "require": {"mmhospedagem/mvo-facebook-php": "0.1.x-dev"},
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

Ajuste os dois caminhos ao seu checkout e execute na aplicação:

```sh
composer install
php -r "require 'vendor/autoload.php'; echo class_exists('MVO\\Facebook\\FacebookConnection') ? 'Facebook disponível' : 'Autoload ausente';"
```

Não copie `src/` Facebook para o SDK WhatsApp nem altere o autoload raiz para misturar namespaces. O Composer resolve o runtime Messenger como dependência do pacote Facebook.

## Pastas privadas

Uma aplicação pode manter a seguinte organização:

```text
minha-aplicacao/
  composer.json
  vendor/
  facebook.php
  var/
    private-auth/
    private-facebook/
```

As pastas `var/private-*` precisam ficar fora da raiz HTTP e do Git, com acesso restrito ao processo PHP. Elas guardam cookies, estado, conteúdo observado e bancos SQLite. Faça backup consistente desses arquivos quando precisar preservar a sessão; não os envie para ferramentas de IA ou suporte público.

O armazenamento de autenticação é `<authDirectory>/messenger/<sessionId>/state.sqlite`. O nome interno `messenger` identifica o runtime compartilhado. Os serviços guardam seus dados em `<privateStorageDirectory>/<accountId>/`. Um único processo deve manter o lock da sessão ativa.

Próximo passo: [primeiro login e restauração](CONEXAO.md).
