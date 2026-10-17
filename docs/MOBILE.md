# Sessão principal mobile e aparelhos vinculados

O módulo `MVO\Mobile` implementa o transporte TCP do protocolo mobile, o payload de login Android/iOS e o lado principal do vínculo de aparelhos. Ele permite cadastrar uma identidade principal por **SMS ou ligação**, confirmar o código e restaurar as mesmas chaves após reiniciar. Também aceita a importação explícita de uma identidade principal já registrada. Uma sessão Web vinculada, como `default`, continua sendo companion: definir informações de Android não muda seu papel na conta.

## Cadastrar por SMS ou ligação

Use uma sessão exclusiva para a identidade principal. O código recebido por SMS/ligação tem seis dígitos; ele é diferente do código de oito caracteres que vincula um aparelho à conta existente. O registro como principal pode encerrar o uso desse número no aplicativo principal anterior.

```php
use MVO\WaClient;

$client = new WaClient(sessionId: 'mobile-cliente');
$registration = $client->mobileRegistration();

// Execute uma vez. method='voice' solicita uma ligação em vez de SMS.
$result = $registration->requestCode([
    'cc' => '55',
    'phoneNumber' => '11999990000', // Nacional, com DDD; sem repetir 55.
    'method' => 'sms',
]);

// Em outra requisição/processo, o mesmo sessionId restaura o cadastro pendente.
$result = (new WaClient(sessionId: 'mobile-cliente'))
    ->mobileRegistration()->verifyCode(['code' => $codigoRecebido]);

// Se challenge.type for two_factor, confirme separadamente o PIN existente.
$result = (new WaClient(sessionId: 'mobile-cliente'))
    ->mobileRegistration()->verifyCode(['twoFactorPin' => $pinDaConta]);

// Depois de registered=true, a configuração mobile também é restaurada.
$client = new WaClient(sessionId: 'mobile-cliente');
$client->connect();
$client->run();
```

Para cadastro Android com `cc=55`, informe DDD + celular de nove dígitos: 11 dígitos nacionais, com `9` imediatamente após o DDD. O cadastro usa o número real da linha que recebe SMS/ligação; não reutilize um JID legado do WhatsApp que tenha omitido o nono dígito. A biblioteca rejeita formatos brasileiros antigos e números fixos antes de acessar o estado ou a rede; não insere dígitos por conta própria. Para `cc=1`, por exemplo, `phoneNumber=2025550123` é nacional e `+12025550123` é a forma internacional equivalente.

`no_routes` e `blocked` são motivos de recusa retornados pelo WhatsApp. Não identificam sozinhos a causa nem comprovam banimento permanente. `retryAfter=86400` significa aguardar 24 horas; trocar a sessão ou apagar o estado não garante que o servidor libere nova tentativa. A aplicação deve preservar o prazo e não reenviar SMS automaticamente. `challenge=null` informa que a biblioteca não classificou um desafio; não é prova de que o servidor não exige etapas adicionais.

As chamadas de confirmação acima representam etapas condicionais: envie `twoFactorPin` somente quando o WhatsApp exigir a segunda etapa. Não envie código SMS e PIN juntos. `state()` consulta `status`, `registered`, `method`, `phoneNumber`, `retryAfter`, `challenge`, `reason` e `accountJid`, sem expor chaves ou códigos.

A biblioteca reserva e persiste as chaves Noise/Signal, identificadores de cadastro e informações do dispositivo antes do envio. Reutiliza esses dados na confirmação e nos reinícios. Os códigos e PINs não são persistidos. Uma sessão companion já registrada é recusada e preservada; use outro identificador de sessão.

`retryAfter` aplica o prazo retornado pelo servidor. Se a conexão HTTP perder a resposta, o resultado passa a `request_unknown` ou `verification_unknown`; isso não comprova que o código deixou de ser enviado. Para solicitar novamente após resultado desconhecido, confira a situação e informe `confirmResend=true` explicitamente, respeitando o prazo. Não há reenvio automático de SMS ou ligação.

O transporte PHP usa HTTPS com validação TLS, origem fixa, X25519 efêmero e AES-GCM. O perfil público de cadastro Android suportado é `2.26.39.79`; o token da versão foi conferido no APK assinado. O perfil não é prova de integridade de hardware. O servidor pode exigir confirmação no aparelho, CAPTCHA, email ou atestação. Esses desafios são retornados em `challenge`; a biblioteca não os declara concluídos nem tenta contorná-los. O transporte aceita um provedor externo de atestação Android real pela aplicação que disponha dele. Cadastro iOS e cadastro com o APK Business precisam de perfis próprios e são recusados antes do envio.

Integre as etapas com `mobileRegistration()->requestCode()`, `verifyCode()` e a consulta de estado descritas neste guia. Inicie o transporte principal após a confirmação do registro; a aplicação define sua própria interface de entrada para o código recebido.

## Conectar uma identidade principal existente

Use uma sessão separada e credenciais reais de um ambiente que você controla. `PrimaryCredentials::validate()` exige `registered=true`, JID numérico da conta no dispositivo zero, pares Noise/Signal consistentes, signed-prekey autenticada, registration ID e segredo ADV. `PrimaryCredentials::fromReference()` converte o objeto **binário já decodificado** do projeto de referência (`registrationInfo`, `privKey`, `pubKey`, `keyId`) para o formato PHP; ele não tenta adivinhar formatos base64.

```php
use MVO\Mobile\PrimaryCredentials;
use MVO\WaClient;

$client = new WaClient(
    sessionId: 'primary',
    mobileDeviceInfo: [
        'os' => 'android',
        'manufacturer' => 'motorola',
        'device' => 'moto_g52',
        'osVersion' => '12',
        'osBuildNumber' => 'S1SRS32.38-132-8',
        'appVersion' => '2.26.15.11', // Exemplo de formato; informe a versão da identidade importada.
        'localeLanguageIso6391' => 'pt',
        'localeCountryIso31661Alpha2' => 'BR',
    ],
);

// Faça a importação uma vez, antes de conectar; depois a sessão persiste no diretório de autenticação.
$client->importPrimaryCredentials(PrimaryCredentials::fromReference($decodedPrimaryCredentials));
$client->connect();
$client->run();
```

O login usa `tcp://g.whatsapp.net:443` por padrão. `TcpSocket` envia bytes brutos e adapta a interface usada por `NoiseSession`. O mesmo handshake Noise autenticado verifica os certificados do servidor e cifra a conexão. Não há upgrade HTTP/WebSocket na conexão mobile. O adaptador aceita proxy HTTP CONNECT com autenticação; rejeita esquemas de proxy incompatíveis.

O payload Android anuncia `ANDROID` ou `SMB_ANDROID`, MCC/MNC e os dados do aparelho. iOS anuncia `IOS` ou `SMB_IOS`, o modelo de máquina e o canal de distribuição, omitindo MCC/MNC e deviceBoard. A versão mobile tem quatro componentes numéricos. Os valores devem corresponder à identidade fornecida; a confirmação do registro no servidor é obrigatória para chaves novas.

## Vincular e administrar companions

```php
$mobile = $client->mobile();
$linked = $mobile->linkCompanion($qrObtidoNoOutroAparelho);

// O outro aparelho precisa ter solicitado um código para a conta principal primeiro.
$linked = $mobile->linkCompanionByCode('1234-5678');

$devices = $mobile->listCompanions();
$mobile->publishKeyIndexList();
$removed = $mobile->reconcileCompanions();
$mobile->revokeCompanion($linked['deviceJid']);
```

`revokeAllCompanions($reason, $excludeHostedCompanion)` envia a solicitação de remoção de todos os aparelhos, opcionalmente preservando aparelhos hospedados. `listCompanions()` lista os companions conhecidos por este módulo; `reconcileCompanions()` consulta a lista autenticada do servidor e remove registros que já não aparecem. Notificações `account_sync` atualizam imediatamente os índices e removem aparelhos desvinculados, sem executar uma consulta dentro do handler.

O fluxo QR valida as três chaves do QR, assina `ADVDeviceIdentity` e `ADVKeyIndexList` com XEdDSA da identidade principal e aplica o HMAC com o ADV do companion. O upload `pair-device` declara os indicadores de migração LID quando a conta usa LID. A resposta precisa identificar um aparelho pertencente à própria conta.

O fluxo por código usa PBKDF2-SHA256, AES-CTR e X25519 no `primary_hello`; autentica e abre o bundle AES-GCM do `companion_finish`, verifica as duas identidades e deriva o mesmo ADV que o companion. O estado efêmero expira e fica apenas em memória. Aplicações com um loop próprio podem usar `beginCompanionByCode()`, encaminhar as notificações a `handleNode()` e concluir com `finishCompanionByCode()`.

## Estado, chaves e bootstrap

A época ADV, o conjunto conhecido de aparelhos e o maior índice emitido persistem no `SignalStore`. O índice é reservado **antes** do upload, inclusive quando a resposta se perde; ele nunca é reutilizado após revogação ou reinício. O segredo ADV do companion não é guardado no inventário persistente.

Após conectar, a identidade principal inicializa sua própria chave app-state e envia `SettingPushName` na coleção crítica, usando `data_namespace=3`. Uma importação de conta com estado app-state anterior também precisa conservar suas chaves e coleções; o módulo não ignora conflitos ou MACs inválidos para aparentar uma sincronização bem-sucedida.

O vínculo agenda o provisionamento durável para o loop externo. O módulo envia `INITIAL_BOOTSTRAP` e `APP_STATE_SYNC_KEY_SHARE` por mensagens peer protegidas por Signal quando o novo companion já tiver disponibilizado suas prekeys. Falhas são retentadas até oito vezes, e os eventos informam o erro. O envio de histórico concluído fica registrado para que uma falha posterior no compartilhamento de chave não repita o histórico.

`sendHistorySyncBootstrap($jid, $options)` aceita conversas, pushnames, pares PN/LID e companionMetaNonce reais. O protobuf `HistorySync` é comprimido com zlib. Até 32 KiB comprimidos seguem inline; acima disso é necessário o uploader de mídia `md-msg-hist`, que devolve a referência cifrada. `shareAppStateSyncKeys($jid)` envia a chave ativa com fingerprint e timestamp. As duas operações rejeitam destinos que não estejam no inventário desta identidade principal.

Eventos: `companion_host_code_requested`, `companion_host_linked`, `companion_host_provisioned`, `companion_host_revoked` e `companion_host_error`.

## Validação e limites

Os testes locais verificam TCP real em loopback, payloads Android/iOS, recusa de credenciais companion, conversão de credenciais, assinaturas ADV/HMAC, reciprocidade do código com o lado companion PHP, adulteração de bundles, compressão/histórico externo, persistência, reservas de índice após resposta perdida, revogação, reconciliação e provisionamento. O cadastro foi validado com respostas HTTP isoladas, vetor criptográfico independente, persistência e proteção contra concorrência. Nenhum SMS ou ligação foi disparado por esses testes; a aceitação de um cadastro real pelo servidor ainda precisa ser validada com o número do usuário.

O parâmetro opcional `includePem` do serviço reproduz o campo experimental do código de referência e fica desativado por padrão. O suporte não inclui migração automática de bases de um aplicativo mobile. A identidade e o estado importados, a versão do aplicativo e as exigências do servidor determinam a aceitação da sessão.

Projeto de referência: `caeace8d03cd22f24f224c4063edc537522ef936`, `WaMobileCoordinator.ts`, `companion-host.ts`, `WaMobileClientPayload.ts`, `WaMobileTcpSocket.ts` e builders mobile.
