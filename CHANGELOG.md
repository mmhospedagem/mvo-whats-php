# Changelog

As versões da biblioteca seguem versionamento semântico. Mudanças no protocolo do WhatsApp podem exigir atualizações de compatibilidade, independentemente da versão da biblioteca.

## 1.0.0 — 2026-10-17

Primeira distribuição pública da **MVO WHATS PHP**.

- Cliente PHP 8.2+ com conexão por QR Code ou código de pareamento, sessão persistente, eventos e reconexão.
- Envio e recebimento de mensagens, mídia, interativos, formulários, histórico e recibos.
- Serviços de grupos, comunidades, canais, Status, perfil e recursos comerciais.
- Armazenamento de estado, identidades PN/LID, sincronização, agendamentos e ferramentas de diagnóstico.
- Interface MCP em PHP para integração com clientes de LLM, além de documentação `llms.txt` e `llms-full.txt`.
- Tutoriais de instalação, integração, exemplos e ambiente de desenvolvimento.

Os limites de cada recurso estão nos respectivos guias. A presença de uma API ou definição Protobuf não garante que todo cliente WhatsApp, conta ou tipo de sessão aceite o recurso. Codecs e transporte nativo de chamadas são componentes opcionais.
