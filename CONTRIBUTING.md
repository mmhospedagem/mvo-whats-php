# Contribuir com a MVO WHATS PHP

Obrigado por ajudar a melhorar a biblioteca. Use as [issues](https://github.com/mmhospedagem/mvo-whats-php/issues) para relatar um problema ou propor um recurso. Para dúvidas de integração, inclua uma reprodução pequena em PHP e a versão instalada.

## Preparar o ambiente

Instale PHP 8.2+ de 64 bits, as extensões do `composer.json` e o Composer. O [guia de instalação](docs/INSTALLATION.md) cobre Ubuntu e Windows.

```sh
git clone https://github.com/mmhospedagem/mvo-whats-php.git
cd mvo-whats-php
composer install
composer doctor
composer test
composer analyse
```

Os comandos usam o PHP disponível no terminal. No Windows, `tools/setup.ps1` pode configurar um ambiente isolado de desenvolvimento com Xdebug; `dev.local.json` permanece local. A configuração de VS Code em `.vscode/settings.example.json` pode ser copiada para `.vscode/settings.json` e adaptada ao interpretador instalado.

Os SDKs opcionais em `packages/` têm dependências e autoload próprios. Os testes do repositório usam um bootstrap dedicado, sem carregar um painel ou servidor:

```sh
php vendor/bin/phpunit --bootstrap tools/bootstrap-packages.php packages --no-progress
php vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --autoload-file=tools/bootstrap-packages.php --level=6 --no-progress packages/channels-core/src packages/whatsapp-adapter/src packages/messenger/src packages/messenger-adapter/src packages/facebook/src
```

Esses comandos verificam contratos locais com dados sintéticos. Não comprovam autenticação, entrega ou compatibilidade de uma conta real. A aplicação consumidora usa o autoload Composer do pacote instalado, sem depender desse bootstrap de testes. O laboratório privado de desenvolvimento não faz parte da distribuição.

## Fazer uma alteração

1. Crie uma branch para a mudança.
2. Preserve as assinaturas públicas ou explique a alteração de compatibilidade.
3. Para bugs no protocolo, inclua um teste com dados sintéticos que falhe antes da correção.
4. Execute `composer lint`, `composer test` e `composer analyse`.
5. Abra um pull request explicando o problema, a mudança e o que foi verificado.

Prefira um PR por problema. Preserve o SDK WhatsApp em `src/` e mantenha os conectores separados em `packages/`. Leia os guias do canal afetado antes de alterar autenticação, mensagens ou serviços. Testes de unidade não precisam de uma conta WhatsApp ou Facebook; testes reais são optativos e exigem uma conta e um destinatário de teste autorizados. Nunca execute exemplos de envio contra contas de outras pessoas para reproduzir um bug.

## Documentação e exemplos

Os portais são separados: [WhatsApp](docs/index.html#inicio) usa os guias em `docs/`; [Facebook](docs/facebook/index.html#inicio) usa os guias e contextos LLM em `docs/facebook/`. Preserve as âncoras existentes e atualize exemplos, assinaturas e limites somente no canal correspondente. Edite os guias Markdown e regenere os dois portais:

```sh
python -m pip install -r tools/requirements-docs.txt
python tools/build_docs.py
python -m unittest discover -s tools/tests -p test_docs_portals.py
```

Inclua os HTMLs e contextos gerados atualizados no PR: `docs/index.html`, `docs/llms.txt`, `docs/llms-full.txt`, `docs/facebook/index.html` e `docs/facebook/llms-full.txt`. Mantenha o índice fonte `docs/facebook/llms.txt` coerente com os guias. Para conferir localmente, execute `python -m http.server 8765` e acesse `/docs/` ou `/docs/facebook/`. Python participa apenas da geração da documentação; os clientes e o protocolo executam em PHP.

## Dados de reprodução

Use números e identificadores fictícios. Não publique QR Codes válidos, arquivos de sessão, bancos SQLite de contas, tokens, chaves, conteúdo de conversas ou dumps de produção. Para vulnerabilidades, siga [SECURITY.md](SECURITY.md).

Ao contribuir, você permite distribuir sua contribuição conforme a licença do projeto, preservando os avisos aplicáveis de terceiros.
