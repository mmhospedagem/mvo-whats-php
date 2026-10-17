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

## Fazer uma alteração

1. Crie uma branch para a mudança.
2. Preserve as assinaturas públicas ou explique a alteração de compatibilidade.
3. Para bugs no protocolo, inclua um teste com dados sintéticos que falhe antes da correção.
4. Execute `composer lint`, `composer test` e `composer analyse`.
5. Abra um pull request explicando o problema, a mudança e o que foi verificado.

Prefira um PR por problema. Testes de unidade não precisam de uma conta WhatsApp; testes reais são optativos e exigem uma conta e um destinatário de teste autorizados. Nunca execute exemplos de envio contra números de outras pessoas para reproduzir um bug.

## Documentação e exemplos

Edite os guias Markdown e regenere o site:

```sh
python -m pip install -r tools/requirements-docs.txt
python tools/build_docs.py
```

Inclua `docs/index.html` atualizado no PR. Para conferir localmente, abra esse arquivo no navegador ou execute `python -m http.server 8765` e acesse `/docs/`. Python participa apenas da geração da documentação; o cliente WhatsApp executa em PHP.

## Dados de reprodução

Use números e identificadores fictícios. Não publique QR Codes válidos, arquivos de sessão, bancos SQLite de contas, tokens, chaves, conteúdo de conversas ou dumps de produção. Para vulnerabilidades, siga [SECURITY.md](SECURITY.md).

Ao contribuir, você permite distribuir sua contribuição conforme a licença do projeto, preservando os avisos aplicáveis de terceiros.
