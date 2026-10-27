# Conectar WhatsApp em PHP

A biblioteca implementa WebSocket/TLS, handshake Noise XX com validação obrigatória da cadeia de certificados, registro/login, pareamento QR, pareamento por código e persistência binária das credenciais em SQLite. O código executa em PHP, com OpenSSL, Sodium, GMP e o renderizador PHP BaconQrCode.

**O Composer é somente um atalho de execução.** `composer connect:qr` chama `examples/connect.php`; geração, rotação do QR, autenticação, persistência e restauração são feitas pela biblioteca. Sua API usa `WaClient` diretamente e carrega as dependências com `vendor/autoload.php`, sem executar comandos Composer para conectar.

## Gatilhos públicos para integrar uma API

| Método ou evento | Uso |
| --- | --- |
| `new WaClient(sessionId: ..., authDirectory: ...)` | Define a identidade da sessão e sua pasta persistente. |
| `connect()` | Carrega as credenciais e tenta restaurar o vínculo; em uma sessão nova inicia o pareamento. |
| `on('auth_qr', $handler)` | Entrega `{qr, expiresIn}` em cada geração/rotação. `QrCode::svg($event['qr'])` gera a imagem para o frontend. |
| `requestPairingCode($phone)` | Após `connect()`, solicita o código se `isRegistered()` for falso; retorna o código e emite `auth_code`. |
| `on('connection', $handler)` | Entrega `{state, sessionId}`; `connected` confirma login e inicialização. |
| `accountInfo()` | Snapshot público local da própria conta: número confirmado, PN/LID, nome, username, plataforma, versão ativa e conexão. Não executa consultas de rede nem retorna chaves de autenticação. |
| `on('auth_paired', $handler)` | Confirma vínculo verificado e salvo. O payload contém credenciais privadas: publique somente o resultado público. |
| `auth_qr_expired`, `auth_code_expired`, `auth_code_refresh` | Permitem invalidar a apresentação ou orientar a nova solicitação no frontend. |
| `poll($timeout)` / `run()` | Mantêm a conexão, recebem mensagens e processam rotação/reconexão. |
| `disconnect()` / `stop()` | Encerram a conexão local mantendo a sessão para restaurar depois. |
| `logout()` | Desvincula o aparelho; não usar para simplesmente reiniciar a aplicação. |

`examples/session-events.php` é um worker PHP direto que publica JSON Lines com estado, QR em SVG e código, filtrando as credenciais privadas. Substitua o callback `$publish` pelo publicador de eventos/estado da sua API:

```powershell
php -c .dev/php.ini examples/session-events.php --session=cliente-123
php -c .dev/php.ini examples/session-events.php --session=cliente-123 --mode=code --phone=5511999999999
```

Os comandos são alternativas. Reiniciar com o mesmo `sessionId` e `authDirectory` restaura automaticamente o vínculo válido; não é preciso enviar arquivos de sessão ao navegador ou chamar um método `restore()` separado. Cadastre os listeners antes de `connect()` para receber as primeiras mudanças de estado. `auth_paired` confirma o vínculo, enquanto `connection.state=connected` confirma a conexão utilizável.

Na futura API, mantenha um worker PHP por sessão ativa. As rotas HTTP pedem ações ao worker e consultam seu estado; o frontend recebe cada novo `auth_qr` por SSE/WebSocket ou consulta o último QR ainda válido. O processo do worker continua ativo depois de devolver o QR. O cliente usa I/O bloqueante: criar `WaClient` em uma rota PHP-FPM e encerrar o request logo após obter o QR fecha a conexão e interrompe o pareamento. O lock impede abrir duas conexões para a mesma sessão. Comandos com consultas devem ser executados no loop externo, depois de os callbacks retornarem.

Cada tentativa de conexão mantém uma geração interna. Se um callback encerrar ou substituir a conexão, nós, respostas de consultas e falhas da tentativa anterior não podem autenticar, alterar credenciais nem derrubar a nova sessão. Uma consulta interrompida sinaliza `SessionRestartRequired`, sem repetir automaticamente a operação. Continue executando comandos e consultas no loop externo; essa proteção não transforma callbacks em um segundo loop de aplicação.

Após encerrar uma sessão ativa, chamadas repetidas de `stop()`/`disconnect()`, inclusive durante o descarte de plugins, conservam o estado `stopped` e liberam o lock. Se um callback abrir de fato uma conexão substituta, o encerramento anterior não a sobrescreve.

Cada inscrição criada por `PluginContext::on()` ou `once()` pertence ao seu contexto: o descarte remove somente essa inscrição, mesmo quando outro contexto usa a mesma callable. Um erro do descarte anterior não emite `plugin_error` na conexão substituta. Essas garantias têm testes locais de reconexão e cleanup, sem comprovar entrega em clientes reais.

Composer continua sendo usado normalmente para instalar/autocarregar dependências; a execução do cliente não depende dos scripts de desenvolvimento em `tools/dev.php`.

## Informações da própria conta

Consulte o snapshot após conectar e depois das sincronizações de histórico/app-state:

```php
$client->on('connection', function (array $event) use ($client): void {
    if ($event['state'] === 'connected') {
        $account = $client->accountInfo();
        echo json_encode($account, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), PHP_EOL;
    }
});
```

`phoneNumber` contém somente os dígitos do número confirmado e `phoneE164` inclui `+`. `jid` é canônico, sem aparelho; `deviceJid` preserva o identificador completo e `deviceId` separa o aparelho. O LID permanece em `lid` e nunca é convertido em telefone. `displayName`, `username` e `platform` podem ser `null` até serem informados ou sincronizados. `webVersion` mostra a versão realmente utilizada pelo cliente, inclusive a versão restaurada ou atualizada após erro 405.

`accountInfo()` lê apenas os dados já disponíveis. Consultas de foto, recado ou Business devem ocorrer no loop externo do worker, após os callbacks; sua aplicação pode manter os resultados em cache. Um nome definido para a instância não é usado como nome do perfil WhatsApp.

Para leituras opcionais, `profile(5.0)` e `business(5.0)` criam facades com limite de cinco segundos por consulta IQ. Um timeout ou erro retornado por essa consulta permanece local à operação; falhas reais do transporte continuam acionando a recuperação. As chamadas usuais `profile()` e `business()` mantêm seus limites e comportamento anteriores.

## QR Code

```powershell
composer connect:qr
```

O QR aparece **diretamente no terminal**, com contagem regressiva e estado da conexão. A cada rotação, o anterior é substituído automaticamente. No celular, entre em **WhatsApp → Aparelhos conectados → Conectar um aparelho** e escaneie a tela.

Amplie o painel do terminal do VS Code para visualizar o QR inteiro, sem cortes ou quebra de linhas. O renderizador usa blocos Unicode compactos com fundo branco e quatro módulos de margem, independentemente do tema.

O arquivo `.auth/default/qr.svg` continua sendo atualizado como alternativa. Para usar apenas o arquivo, sem desenhar no terminal:

```powershell
composer connect:qr -- --no-terminal
```

O processo precisa continuar aberto. Por padrão ele roda continuamente, inclusive depois do pareamento. Ctrl+C encerra mantendo as credenciais e restaura o terminal no Windows. Para outra sessão ou uma execução limitada:

```powershell
composer connect:qr -- --session=minha-sessao --timeout=600
```

O cliente reconecta quedas transitórias com espera exponencial/jitter, limitada a 60 segundos por tentativa. Falhas de certificado e rejeições definitivas não entram nesse retry. Uma sessão já vinculada volta por login, sem QR. `--timeout=0` significa contínuo.

O TTL vem do fluxo portado: 60 segundos para a primeira referência de um conjunto completo, depois 20 segundos. Expiradas todas as referências, execute novamente para iniciar uma nova conexão. Ao vincular ou desconectar, o cliente remove o QR temporário. O arquivo contém material de autorização temporário e permanece dentro da pasta privada da sessão.

## Código com número de telefone

```powershell
composer connect:code -- --phone=5511999999999 --session=minha-sessao
```

Substitua o número pelo seu, incluindo DDI e DDD. O comando solicita o pareamento ao servidor e mostra um código de oito caracteres no formato `XXXX-XXXX` depois da confirmação do pedido. No celular, escolha **Aparelhos conectados → Conectar um aparelho → Conectar com número de telefone** e informe esse código.

Esse código é usado no fluxo de vinculação de aparelho. Não é o SMS de registro da conta. A troca com o celular usa PBKDF2, AES-CTR/AES-GCM, X25519, HKDF e validação de identidade ADV. O pedido pode ser rejeitado pelo servidor; nesse caso a biblioteca informa a falha sem emitir sucesso.

O código expira em até 180 segundos; o fluxo rejeita referências divergentes e mais de três tentativas de `primary_hello`. Execute novamente para solicitar outro código. Uma sessão já vinculada usa as credenciais salvas e não solicita novo código.

Se o transporte cair durante um pareamento por código e a conexão for retomada
pelo mesmo cliente, a biblioteca registra novamente o código pendente quando
recebe o novo `pair-device`. O código permanece em RAM e conserva o prazo
original; o registro usa outra troca efêmera e não repete a notificação push.
Se o registro falhar, `auth_code_refresh` informa `forceManual=true` e
`reason=code_reregistration_failed`. Solicite um novo código pelo loop externo
da aplicação, após o retorno dos callbacks. `stop()`/`disconnect()` descartam
essa tentativa; ela não é restaurada depois de encerrar o processo.

## Reutilizar a sessão

Execute novamente o comando com o mesmo `--session`. As chaves e a identidade vinculada são carregadas de `.auth/<sessao>/state.sqlite` e o cliente tenta login autenticado. Existe um lock por sessão; um segundo processo concorrente é rejeitado sem remover o QR do primeiro.

`disconnect()` encerra o transporte e libera os recursos locais, mantendo as credenciais e o estado Signal. Ele não desvincula o aparelho da conta. Para remover um vínculo, use **Aparelhos conectados** no próprio WhatsApp. Se o servidor recusar com `401`/dispositivo removido, o cliente preserva os dados e informa `logged_out`; `composer session:relink` arquiva essa sessão revogada para novo pareamento explícito. Não há endpoint HTTP público nem servidor web expondo `.auth`.

## Usar a biblioteca

```php
<?php
require 'vendor/autoload.php';

use MVO\WaClient;
use MVO\Auth\QrCode;

$client = new WaClient('minha-sessao');
$client->on('auth_qr', function (array $event) use ($client) {
    file_put_contents($client->sessionDirectory() . '/qr.svg', QrCode::svg($event['qr']));
    echo "QR atualizado; validade: {$event['expiresIn']} segundos\n";
});
$client->on('auth_code', function (array $event) {
    echo "Codigo: {$event['code']}\n";
});
$client->on('connection', function (array $event) {
    echo "Estado: {$event['state']}\n";
});
$client->on('auth_paired', function (array $event) {
    echo "Identidade vinculada: {$event['credentials']['meJid']}\n";
});

try {
    $client->connect();
    // Para codigo, descomente e informe seu numero:
    // if (!$client->isRegistered()) $client->requestPairingCode('5511999999999');
    $client->run();
} finally {
    $client->disconnect();
}
```

`connect()` estabelece o transporte autenticado. `run()` mantém o loop PHP ativo; `poll(0.5)` permite integrá-lo ao seu próprio loop. O cliente usa I/O bloqueante com timeouts. Execute como processo CLI; não mantenha um request PHP-FPM/Apache esperando o usuário escanear.

Estados: `connecting`, `pairing` ou `authenticating`, `reconnecting`, `connected` e `disconnected`. `auth_paired` significa que HMAC e assinatura da identidade do vínculo foram verificados e persistidos. `connected` somente é emitido após `success` do servidor e resposta ao IQ `active`.

Depois de confirmar a identidade ADV, a biblioteca mantém a conexão de
pareamento aberta para o servidor reiniciá-la: `515` ou fechamento transitório
inicia a retomada com a identidade salva. Se esse sinal não chegar, o loop usa
um prazo de 15 segundos para iniciar a retomada. `stop()` cancela a espera;
callbacks e consultas antigos não substituem uma conexão mais nova nem são
repetidos nela. Continue executando `poll()`/`run()` durante essa etapa.

Para pedir histórico completo durante um novo pareamento, configure
`historyRequireFullSync: true` no construtor; veja [Histórico](HISTORICO.md).
Essa configuração não altera uma sessão já vinculada.

Outros eventos: `auth_qr_expired`, `auth_code_expired`, `auth_code_refresh`, `error` e `node`. `node` contém a stanza bruta e pode incluir dados sensíveis; o exemplo `--verbose` registra somente tag, tipo e código.

O transporte confirma nós `<status>` recebidos diretamente do servidor com
`ack class="status"`, preservando o ID, o remetente e os atributos de roteamento.
Essa confirmação não marca uma publicação como visualizada nem cria um evento
de mensagem a partir de conteúdo ainda não decodificado. O caminho de mensagens
de Status em `status@broadcast` continua independente.

Se o servidor encerrar o stream com um filho `<ack class="status">`, sem código
explícito nem conflito, `autoReconnect=true` agenda a retomada usando a mesma
sessão, com a espera progressiva já usada para falhas transitórias. Isso não
revoga credenciais nem repete consultas ou envios interrompidos. Códigos
explícitos, conflitos, revogação, `autoReconnect=false` e `stop()` continuam
seguindo suas próprias regras; esse tratamento não torna recuperável todo erro
de stream sem código.

## Testar e depurar

```powershell
composer check
composer test -- --filter PairingFlowTest
composer test -- --filter WaClientTest
composer test:live
composer test:resume -- --session=default
```

`composer check` usa testes locais e não contata o WhatsApp. `composer test:live` é opt-in: conecta ao servidor real, valida TLS/Noise/certificados, recebe uma referência QR e verifica a renderização SVG. O teste não usa telefone, não escaneia e não vincula uma conta.

`composer test:resume` valida uma sessão já vinculada e livre: login sem QR, inicialização e preservação das identidades. Não execute com o worker ocupando o mesmo lock. Veja [MENSAGENS.md](MENSAGENS.md) para o worker contínuo e [TIPOS-MENSAGENS.md](TIPOS-MENSAGENS.md) para os formatos de envio/recebimento.

No VS Code, escolha **Depurar conexão WhatsApp (QR)** e pressione F5. Evite pausas prolongadas: os timeouts e TTLs do servidor continuam correndo enquanto o debugger está parado.

## Validação e cobertura atual

Foi obtido um QR real do servidor WhatsApp após validação TLS, WebSocket e certificado Noise. Os testes locais verificam também assinaturas contra vetor independente Signal, protocolo WebSocket com um peer TCP local, responder Noise completo com DH/AEAD/certificados, falhas de certificado/MAC, QR/TTL, troca de código vista pelo lado do celular, ADV, persistência, lock e reconexão.

**O usuário confirmou o novo vínculo por QR e a retomada sem QR.** A sessão anterior, removida no celular e recusada com `401`, foi preservada em backup. Não foi usado número real para solicitar código durante a implementação, nem enviado texto de teste sem destino autorizado.

A renomeação da biblioteca para MVO mantém `.auth/default/state.sqlite` e as credenciais existentes. A tabela interna anterior é migrada para `mvo_kv` sem regenerar identidades ou ratchets. Se um worker antigo estiver ativo, a migração exige que ele seja encerrado primeiro; essa proteção evita alterar seu armazenamento durante a conexão. Consulte [STORES.md](STORES.md) para backup, conflito de registros e compatibilidade dos backends remotos.

O escopo inclui sessão persistente, pré-chaves, Signal/Double Ratchet, mensagens PN/LID, Sender Keys, histórico e app-state autenticados. O evento `message` contém `type`, `data` e `proto` após autenticação/descriptografia; `node` continua sendo uma stanza bruta. A reconexão cobre quedas transitórias WebSocket/TCP mobile, keepalive e falhas temporárias do servidor, sem repetir cegamente envios incertos. Consulte [PARIDADE.md](PARIDADE.md) para os módulos opcionais e seus limites.

A versão Web inicial está em `WaClient::VERSION`; `--version=2.3000.NUMERO` permite configurar outra. Com `autoReconnect` habilitado (padrão), uma recusa de conexão `405` fecha o transporte recusado, consulta `https://web.whatsapp.com/sw.js`, valida os componentes numéricos e persiste a nova versão. O próprio `run()` ou o próximo `poll()` reabre a conexão com a mesma identidade, chaves e banco de mensagens. A biblioteca mantém o lock da sessão durante a recuperação; uma sessão vinculada válida retoma sem outro QR. As demais sessões continuam independentes e o processo PHP permanece ativo.

`version_updated` informa a versão salva, `reconnect` informa a retomada agendada e `connection` acompanha `reconnecting` → `authenticating` → `connected`. Os exemplos CLI e JSON Lines publicam esses eventos. Mantenha o loop em execução:

```php
$client = new \MVO\WaClient(sessionId: 'cliente-123');
$client->on('version_updated', function (array $event): void {
    echo 'Nova versão Web: '.$event['version'].PHP_EOL;
});
$client->connect();
$client->run(); // Recuperação automática acontece neste mesmo processo.
```

Há uma única consulta de versão por ciclo de falha. Se a versão oficial for igual à recusada, for inválida, não puder ser consultada, ou o servidor recusar novamente antes de estabilizar, a recuperação termina com erro explícito e preserva as credenciais. Após 60 segundos conectado e um keepalive confirmado, uma futura recusa `405` pode iniciar nova atualização. Isso permite workers de longa duração e limita ciclos de reinício. `stop()` em um callback cancela a retomada. `autoReconnect=false`, identidades mobile e sessões revogadas não usam essa recuperação Web; `401` exige novo vínculo explícito.

O resolver pode ser injetado para testes. A versão do catálogo A/B em `resources/abprops.json` descreve o snapshot dos dados e não é alterada por esse fluxo. A retomada usa IK com a chave estática anteriormente autenticada e XXfallback com certificados se necessário. Não há opção de produção para desativar TLS ou certificados.

## Passkeys / Shortcake

```php
$client = new MVO\WaClient(
    sessionId: 'passkey',
    signPasskeyAssertion: $signerWebAuthn,
    autoConfirmPasskey: true,
);
$client->on('auth_verification_code', function (array $event): void {
    echo $event['code'], PHP_EOL;
});
$client->connect();
$client->requestPasskeyPairing();
$client->run();
```

`$signerWebAuthn` é um callable da aplicação: recebe as opções WebAuthn binárias enviadas pelo servidor e devolve `['credentialId'=>bytes, 'webauthnAssertion'=>bytes]` obtidos de um autenticador real. A biblioteca implementa o protocolo commit/reveal, X25519/HKDF, handoff, rotação ADV, código de verificação e PairingRequest cifrado; não fabrica a assinatura do autenticador. O padrão de confirmação automática acompanha o projeto de referência; com `autoConfirmPasskey=false`, a aplicação chama `confirmPasskeyVerificationCode()` após conferir o código.

Uma notificação obrigatória sem signer emite `auth_passkey_required`, interrompe a apresentação do QR antigo e aguarda `setPasskeySigner()`. O handler ACKa e agenda o trabalho; assinatura e IQs são executados fora do despacho de nós. Sessão, handoff e trabalhos expiram em cinco minutos. Shortcake foi testado com primário local e vetor criptográfico independente; não houve pareamento real por passkey nesta conta.
