# Changelog

As versões da biblioteca seguem versionamento semântico. Mudanças no protocolo do WhatsApp podem exigir atualizações de compatibilidade, independentemente da versão da biblioteca.

## 1.0.2 — 2026-10-18

- Formulários portáveis com `kind: input` e `cancel_option` explícita: cada campo usa resposta de texto livre e uma opção visível de cancelamento, preservando o ID original. A adaptação segue o parser distribuído do WhatsApp Web, com limite de três etapas após expansão e rejeição de valores iniciais não suportados. O modo Android com `InputFields` permanece separado.
- Exemplos PHP e contexto para LLMs descrevem o tratamento de texto/cancelamento e os limites de validação em clientes reais.
- Em teste entre duas contas, a API recebeu o texto enviado pelo WhatsApp Web e o cancelamento enviado pelo Android com as chaves originais. Essa evidência se limita ao formulário e aos clientes usados no teste.

## 1.0.1 — 2026-10-17

Primeira distribuição no [Packagist](https://packagist.org/packages/mmhospedagem/mvo-whats-php), com o nome Composer **`mmhospedagem/mvo-whats-php`**.

- Instalação direta com `composer require "mmhospedagem/mvo-whats-php:^1.0"`, sem configurar repositório VCS.
- Guias de instalação, contexto para LLMs e referência de agentes atualizados para o nome público do pacote.
- As classes e o namespace `MVO\` permanecem iguais. Instalações iniciais por VCS com `mvo/mvo-php` devem atualizar a dependência Composer; a mudança não exige recriar as sessões nem alterar os caminhos de credenciais.

## 1.0.0 — 2026-10-17

Primeira distribuição pública da **MVO WHATS PHP**.

- Cliente PHP 8.2+ com conexão por QR Code ou código de pareamento, sessão persistente, eventos e reconexão.
- Envio e recebimento de mensagens, mídia, interativos, formulários, histórico e recibos.
- Serviços de grupos, comunidades, canais, Status, perfil e recursos comerciais.
- Armazenamento de estado, identidades PN/LID, sincronização, agendamentos e ferramentas de diagnóstico.
- Interface MCP em PHP para integração com clientes de LLM, além de documentação `llms.txt` e `llms-full.txt`.
- Tutoriais de instalação, integração, exemplos e ambiente de desenvolvimento.

Os limites de cada recurso estão nos respectivos guias. A presença de uma API ou definição Protobuf não garante que todo cliente WhatsApp, conta ou tipo de sessão aceite o recurso. Codecs e transporte nativo de chamadas são componentes opcionais.
