# Conta, atendimento e organização

Os serviços PHP abaixo reutilizam a sessão autenticada e o estado sincronizado. Não provisionam outro aparelho ou uma conta oficial. Rotas HTTP, tokens de clientes e Swagger pertencem à aplicação que integra a biblioteca.

## Atendentes, atribuições e notas


```php
$tools = $client->accountTools();
$agent = $tools->saveAgent('atendente-01', ['name' => 'Suporte', 'deviceId' => 1]);
$assignment = $tools->setAssignment('5511999999999', 'atendente-01');
$opened = $tools->setOpened('5511999999999', 'atendente-01', true);
$note = $tools->saveNote('nota-cliente-01', [
    'chatJid' => '5511999999999',
    'text' => 'Cliente prefere atendimento à tarde.',
]);
$notes = $tools->listNotes('5511999999999', 100);
$favorites = $tools->addFavorites(['5511999999999@s.whatsapp.net']);
$campaign = $tools->getCampaign('campanha-01');
$insights = $tools->getCampaignInsights('campanha-01');
```

Campanhas, métricas e marketing são projeções de leitura: não criam campanhas pagas, reservam cota ou inventam métricas. Campos não observados continuam ausentes. Consultas e patches usam o estado autenticado, sem endpoint para protobuf/AppState/IQ arbitrário, chaves ou arquivos locais.

Os testes locais da biblioteca cobrem índices, criptografia/MAC dos patches, confirmação e rejeição de ACK, filtros, paginação e isolamento de autoria. Isso não substitui a validação de disponibilidade visual/comercial de cada recurso numa conta real.

## Outros recursos da conta

- Preferências e recursos observados: [ACCOUNT_EXTENSIONS.md](ACCOUNT_EXTENSIONS.md).
- Perfil, username, privacidade, contatos e e-mail: [SERVICOS.md](SERVICOS.md).
- Perfil comercial, catálogo e organização Business: [BUSINESS.md](BUSINESS.md).
- Estado de conversas e confirmação de alterações: [APPSTATE.md](APPSTATE.md).

Atribuições exigem atendentes observados. Exclusões e alterações afetam a conta autenticada; o ACK do patch não comprova apresentação visual em outro cliente. Campos não observados continuam desconhecidos, sem transformar ausência de dados em confirmação do serviço.
