# Ambiente de desenvolvimento PHP

O ambiente local está instalado e validado em Windows, com PHP **8.4.25 TS x64**, Composer, PHPUnit **11.5.57**, PHPStan **2.3.1** e Xdebug **3.5.3**. O runtime da biblioteca continua compatível com PHP 8.2+; o bootstrap de depuração Windows está fixado em PHP 8.4 TS x64 VS17.

Após o bootstrap Windows, o projeto usa `.dev/php.ini`, com erros habilitados, timezone `America/Sao_Paulo` e Xdebug em loopback. O `php.ini` global do XAMPP não é alterado. Não precisa iniciar Apache, MySQL, Docker ou Node.js.

## Uso imediato

No terminal, dentro da pasta do projeto:

```powershell
composer check
composer test
composer test -- --filter CryptoTest
composer test:coverage
composer example
```

| Comando | Função |
|---|---|
| `composer doctor` | Diagnóstico de PHP, extensões, dependências, Xdebug e diretórios |
| `composer test` | Suíte PHPUnit com seleção por classe/método |
| `composer test:legacy` | Suíte original de 15 grupos |
| `composer test:coverage` | HTML em `build/coverage/index.html` e Clover em `build/clover.xml` |
| `composer analyse` | PHPStan nível 6, alvo PHP 8.2, sem baseline/ignores |
| `composer lint` | Verificação de sintaxe dos arquivos PHP do projeto |
| `composer check` | Diagnóstico, sintaxe, ambas as suítes e PHPStan; valide o debugger separadamente com `composer debug:verify` |
| `composer example` | Exemplo local de protocolo/mídia |
| `composer debug` | Executa exemplo de depuração; requer IDE escutando a porta 9003 |
| `composer debug:verify` | Valida conexão, breakpoint, inspeção e retomada sem precisar da IDE |
| `composer connect:qr` | Exibe e atualiza o QR no terminal, com validade; também salva SVG privado por sessão |
| `composer worker -- --session=default` | Processo contínuo com retomada, reconexão e recebimento de mensagens |
| `composer message:send -- --to=NUMERO --text="Olá"` | Envia pelo worker existente ou conexão temporária; também aceita `--file`/`--payload-file` |
| `composer session:status` | Diagnóstico seguro do vínculo e lock |
| `composer session:relink` | Arquiva uma sessão revogada para novo vínculo explícito |
| `composer test:resume` | Teste de login/restauração em sessão vinculada e livre |
| `composer connect:code -- --phone=5511999999999` | Solicita o código de vínculo para o número informado |
| `composer test:live` | Teste explícito de conexão/QR no servidor real, sem confirmar vínculo |

`composer test -- --filter NomeDoMetodo` e `composer test -- --list-tests` também funcionam. Com `dev.local.json`, os comandos usam o executável e o INI isolados do bootstrap; sem esse arquivo, usam o PHP CLI e o INI selecionados pelo chamador. Os testes normais desativam o Xdebug; o comando de cobertura ativa somente `coverage`.

No ambiente Windows validado, executar PHPUnit diretamente com Xdebug em `develop,debug` reteve referências PDO após exceções capturadas e causou avisos na remoção dos bancos SQLite temporários. Para validar a suíte, use `composer test` ou desligue o Xdebug na execução direta:

```powershell
php -d xdebug.mode=off vendor/bin/phpunit
```

A depuração de testes individuais continua disponível no VS Code. Execute a validação automatizada com Xdebug desligado depois de encerrar a depuração.

## Breakpoints no VS Code

1. Abra `mvo-php.code-workspace` pelo VS Code, ou execute `code mvo-php.code-workspace`.
2. Abra `examples/debug.php` e coloque um breakpoint na linha de `$encoded = ...`.
3. Na aba **Executar e Depurar**, escolha **Depurar exemplo PHP** e pressione **F5**.
4. O debugger pausa na entrada. Continue com **F5** até o breakpoint; inspecione `$recipient`, `$node`, `$encoded` e `$decoded`. Avance com **F10** ou entre em um método com **F11**.

Para depurar testes, escolha **Depurar teste PHPUnit**; informe a classe ou o método quando solicitado. Para iniciar pelo terminal, escolha **Escutar Xdebug (composer debug)** e depois execute `composer debug`.

As extensões **PHP Debug** e **Intelephense** já foram instaladas. Há tarefas de testes, análise e cobertura em **Terminal → Executar Tarefa**. O caminho do executável PHP fica em `.vscode/settings.json`; o bootstrap atualiza esse caminho conforme o PHP selecionado.

Não execute dois listeners na porta 9003 ao mesmo tempo. A verificação automática `composer debug:verify` usa uma porta dinâmica exclusiva, sem conflitar com o VS Code.

## Recriar ou atualizar as dependências

Com PHP 8.4 TS x64 VS17 e Composer disponíveis:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools/setup.ps1 -InstallEditorExtensions
composer check
composer test:coverage
```

O `ExecutionPolicy Bypass` vale somente para esse processo. O setup usa o lock do Composer, baixa o Xdebug do site oficial, confere o SHA256 fixado em `dev/runtime.lock.json`, gera a configuração local e executa o diagnóstico. É idempotente: preserva a DLL quando o hash confere e não apaga dados de sessão.

Para selecionar outro executável compatível:

```powershell
powershell -ExecutionPolicy Bypass -File tools/setup.ps1 -PhpExecutable 'C:\caminho\php.exe'
```

Use `-SkipComposer` para regenerar apenas a configuração depois de mudar a pasta. Para alterar versões de dependências intencionalmente, use `composer update` e revise `composer.lock`; para reproduzir o ambiente, use sempre `composer install`.

## Arquivos e dados locais

| Caminho | Conteúdo |
|---|---|
| `dev/runtime.lock.json` | Versão, URL oficial e SHA256 do Xdebug Windows |
| `dev/php.ini.template` | Modelo de configuração PHP |
| `dev.local.json` | Executável e caminhos absolutos desta máquina; ignorado pelo Git |
| `.dev/ext/php_xdebug.dll` | Extensão local; ignorada pelo Git |
| `.dev/php.ini` | Configuração gerada; ignorada pelo Git |
| `.dev/logs/` | Logs PHP/Xdebug; ignorados pelo Git |
| `.cache/` | Cache PHPUnit/PHPStan |
| `build/coverage/index.html` | Relatório HTML de cobertura |
| `tests/phpunit/` | Testes PHPUnit por componente |
| `phpunit.xml.dist` | Configuração da suíte e fontes para cobertura |
| `phpstan.neon.dist` | Configuração da análise estática |

As ferramentas de desenvolvimento ficam em `require-dev`. O runtime de autenticação exige também GMP e o pacote PHP BaconQrCode para renderizar o QR. O bootstrap Windows já habilita GMP no `php.ini` isolado. Os testes locais SQLite criam bancos temporários; não usam credenciais reais.

## Linux/macOS ou outro PHP

O bootstrap Windows rejeita combinações incompatíveis para não carregar uma DLL errada. Em outro sistema, instale PHP 8.2+ de 64 bits, as extensões exigidas pelo Composer e Xdebug apropriado. Para rodar as ferramentas diretamente, sem o launcher local Windows:

```sh
composer install
php tests/run.php
php vendor/bin/phpunit
php vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress
XDEBUG_MODE=coverage php vendor/bin/phpunit --coverage-html build/coverage
```

## Validação realizada

- A suíte PHPUnit ampliada cobre histórico, app-state, serviços, backends, passkeys, mobile e chamadas, além dos 15 grupos legados. O total atualizado aparece em `composer check`.
- PHPStan nível 6 e verificação de sintaxe passaram.
- Cobertura calculada com Xdebug: 238/254 instruções cobertas, aproximadamente 93,7% na revisão inicial deste ambiente. Esse número muda conforme a biblioteca evolui.
- O teste DBGp iniciou um processo PHP, instalou um breakpoint de linha, leu `$probe` e retomou até a saída esperada.

O usuário confirmou vínculo, retomada sem QR e botões funcionando no celular e Web. A validação real desta etapa retomou a sessão `default` e autenticou as cinco coleções app-state; os outros recursos novos usam testes locais. Consulte `PARIDADE.md`, `MENSAGENS.md` e `CONEXAO.md`; escolha **Depurar conexão WhatsApp (QR)** no VS Code para inspecionar o processo.

Referências das ferramentas: [Xdebug para Windows](https://xdebug.org/download), [PHPUnit 11](https://docs.phpunit.de/en/11.5/installation.html) e [PHPStan](https://phpstan.org/user-guide/getting-started).

Para depurar sem conta real, execute a tarefa **MVO: servidor WhatsApp local** e escolha **Depurar cliente WhatsApp local (QR)** no painel de depuração. Coloque breakpoints em `WaClient`, `MessageService` ou `FakePeer`. O [guia local](FAKE-WHATSAPP.md) descreve os dados isolados e cenários; reiniciar o cliente reaproveita suas credenciais de teste. **Depurar testes VoIP locais** exercita os transportes/codecs com sinais sintéticos, sem chamadas ou dispositivos reais. A tarefa **MVO: diagnostico VoIP** confere as dependências opcionais instaladas.

## Pacote para distribuição

`composer package` recria `mvo-php.zip` com código, schemas, testes, exemplos, documentação e configuração de desenvolvimento. O empacotador usa entradas explícitas e verifica o ZIP; credenciais, bancos de sessão, backups, caches, dependências instaladas e checkouts de referência ficam fora dele. No destino, execute `composer install` e prepare as extensões PHP.
