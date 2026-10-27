# WhatsApp falso para desenvolvimento, em PHP

O ambiente local executa o `WaClient` de produção contra um servidor PHP e aparelhos virtuais PHP. Os aparelhos usam Signal, ratchet e SenderKey reais da biblioteca. O transporte usa WebSocket RFC6455 e Noise autenticado com uma raiz exclusiva de teste. Não é necessário conectar uma conta, escanear o QR no celular ou enviar mensagens pelo WhatsApp real.

Este é um simulador com cobertura explícita, não uma implementação completa dos servidores do WhatsApp. Uma IQ desconhecida recebe erro `501`, sem confirmar uma operação não implementada.

## Executar o exemplo

Na raiz do projeto, abra dois terminais com PHP 8.2+ e as extensões do ambiente de desenvolvimento. No Windows deste projeto, use `C:\xampp\php\php.exe` no lugar de `php` se o PHP não estiver no PATH.

Terminal 1, servidor em primeiro plano:

```powershell
php -c .dev/php.ini examples/fake-whatsapp.php server
```

Terminal 2, cliente com pareamento QR simulado:

```powershell
php -c .dev/php.ini examples/fake-whatsapp.php client
```

O cliente produz um QR do protocolo e o primário falso verifica esse QR, assina a identidade ADV e verifica a assinatura do companion. A aprovação usa uma rota de controle criada explicitamente pelo exemplo; nenhum celular deve escanear esse QR. Depois do reinício solicitado pelo protocolo, o cliente envia texto, mensagem em grupo e documento. O remoto devolve as mensagens com criptografia real.

Execute o mesmo comando do cliente novamente. Ele abre as credenciais e o estado Signal persistidos, retoma a conexão sem novo QR e continua as mensagens. Para testar código desde o início, use outra pasta isolada em ambos os terminais:

```powershell
# Terminal 1
php -c .dev/php.ini examples/fake-whatsapp.php server .cache/fake-code
# Terminal 2
php -c .dev/php.ini examples/fake-whatsapp.php client-code .cache/fake-code
```

`client-code` gera um código pelo fluxo de produção; o primário falso realiza a negociação e a prova criptográfica. Se já houver uma sessão nessa pasta, ela será retomada. Use uma pasta nova para repetir o pareamento.

`Ctrl+C` encerra o servidor. O exemplo não instala serviços nem inicia processos em segundo plano. Os dados ficam em `.cache/fake-whatsapp` ou na pasta fornecida: `client-auth`, bancos dos peers, registro público, app-state, certificado HTTPS de teste e `connection.json`. Essa configuração contém o token local das rotas de controle. Não use uma pasta de credenciais de uma conta real.

## Cobertura implementada e verificada

| Área | Comportamento exercitado |
| --- | --- |
| Transporte | Upgrade, máscaras, limites, fragmentação e controles RFC6455; Noise XX com certificado e raiz de teste, IK, XXfallback e ED de roteamento. Raiz incorreta é recusada. |
| Pareamento | Registro Web, QR, código, ADV/account/device signatures, reinício `515`, `CredentialStore` e nova instância de `WaClient` sem QR. O servidor recebe apenas chaves públicas do companion. |
| Devices e Signal | `usync` de contato/devices e alias LID registrado; consumo de prekeys, upload, digest SHA1 e rotação de signed prekey com assinatura verificada. Chaves e sessões independentes e duráveis por peer. |
| Mensagens diretas | X3DH e ratchet reais, fanout para múltiplos devices, respostas, replay deduplicado e continuação após reinício. O servidor não descriptografa mensagens destinadas ao cliente. |
| Grupos | Metadata, participantes, subject e grupos participantes; distribuição Signal de SenderKeys e conteúdo autenticado. Fanout para terceiros fora do grupo é rejeitado. Cenários completos testados usam endereço PN; o helper não configura um peer com identidade LID própria. |
| Conteúdo e receipts | Conteúdo do `ContentBuilder`/WAProto, texto e mídia; helpers de edição, revogação e reação. Delivery após o peer receber/descriptografar. Read/played somente por `FakePeer::sendReceipt()`, sem presumir leitura. |
| Retry | Receipt com bundle/prekey real, reenvio pelo outbox de produção e deduplicação por ID/ciphertext. O teste simula perda do delivery antes do retry. |
| Histórico e keys | HistorySync protobuf comprimido dentro de Signal autenticado do primário da própria conta; keyshare app-state pelo mesmo caminho. O cliente aplica as verificações normais de origem. |
| Mídia | HTTPS de blobs criptografados, hash do corpo, autenticação do upload, CBC/HMAC/SHA256 de produção e expiração explícita. O teste envia/recebe documento e imagem pelo `WaClient` e exercita as APIs de stream. |
| App-state | Cinco coleções, patches/snapshots com MAC/LT-hash reais, keys, versão, upload, remoção de records, conflito `409` e snapshot externo criptografado por HTTPS. MAC inválido é recusado. |
| IQs básicas | Ping, active/passive, abprops com catálogo vazio de teste e registro de pedidos de privacy token para destinatários existentes. Não emite tokens supostamente assinados pelo WhatsApp. |

Receber um protobuf no peer falso não prova que o WhatsApp Web exibirá botões daquele conteúdo. O simulador não implementa chamadas/relays, MEX/business/newsletter, todas as operações administrativas de grupo, registro de número móvel, Shortcake ou passkeys. Essas operações precisam de rotas e verificações adicionais.

As fixtures de pareamento também exercitam a espera por `515`/fechamento após
ADV, o prazo alternativo de 15 segundos, o cancelamento por `stop()` e o
registro do código pendente no transporte substituto. A suíte confere o prazo
original do código, a ausência de push repetido e a rejeição de material da
conexão antiga. Esses ensaios são locais; não representam aprovação de um
vínculo por um celular real.

## APIs para cenários

```php
$protocol = new MVO\Testing\FakeProtocol();
$primary = $protocol->createPeer('5511000000000@s.whatsapp.net');
$remote = $protocol->createPeer('5522000000000@s.whatsapp.net');
$desktop = $protocol->createPeer('5522000000000:2@s.whatsapp.net');
$protocol->configurePairing($primary->primaryPairing());
$protocol->createGroup('120363000000000001@g.us', 'Teste', [
    $primary->jid, $remote->jid,
]);
$remote->onMessage(function ($peer, $message) {
    if (is_string($message['text'] ?? null)) {
        $peer->sendConversation($message['chat'], 'Eco: '.$message['text']);
    }
});
$server = new MVO\Testing\FakeServer(protocol: $protocol);
$server->listen();
```

Crie o primário virtual antes de seus companions: `createPeer()` exige essa autoridade para gerar assinaturas ADV/account/device válidas. Para login pré-registrado, `registerClient($credentials)` descarta material privado e conserva o registro público; o login exige a chave Noise correspondente. `route($namespace, $callback)` acrescenta uma IQ explicitamente implementada. `captured()` inspeciona stanzas por uma API opt-in, sem log automático de credenciais.

`FakeServer::serveOnce()` atende uma conexão por vez. Os demais participantes são `FakePeer`s internos, com Signal real. Reutilize o servidor em um loop para reconexões sequenciais. O callback `tick` pode chamar `FakeMediaServer::poll()`, como no exemplo. Não há vários clientes WebSocket externos simultâneos.

## Confiança de teste e limites

`sessionFactory` injeta o `NoiseSession` com `FakeServer::rootPublicKey()` apenas nesse cliente. A verificação de certificados de produção não muda. As chaves Noise/raiz do servidor duram enquanto a instância existir; reiniciar o processo servidor cria outra raiz. O exemplo atualiza `connection.json` e o cliente lê a nova raiz explicitamente, permitindo XXfallback. Registro público, peers e app-state podem persistir por `SignalStore`.

`mediaTransferFactory` é uma opção final do construtor de `WaClient`: recebe o callback de IQ e retorna um `MediaTransfer`. O exemplo fornece `FakeMediaClient` com a CA de teste, permite somente `https://localhost` e usa `CURLOPT_CONNECT_TO` para a porta efêmera em `127.0.0.1`. TLS continua verificando certificado e hostname. Nenhuma CA é instalada globalmente, e as regras do transporte padrão não são ampliadas.

O adaptador HTTP de teste limita cada upload a 32 MiB mais overhead criptográfico e lê o body de stream em RAM dentro desse limite. As APIs de stream de produção são exercitadas, mas esse adaptador não promete HTTP com memória constante de ponta a ponta. `FakeMediaServer` guarda até 128 MiB de blobs criptografados em memória. Blobs e fila de entrega não sobrevivem ao reinício do servidor. O certificado de teste é reaproveitado somente se ainda tiver mais de uma hora de validade, os nomes esperados e a chave correspondente; caso contrário é regenerado ao iniciar o servidor, no mesmo caminho local de CA.

Limites padrão: 1.000 stanzas pendentes por device; captura de 10.000 stanzas e 16 MiB de bytes codificados, com descarte FIFO informado em `captureInfo()`; até 1.000 mensagens por consulta do peer; 1.000 patches por coleção; mensagem WebSocket de 1 MiB. O servidor atende somente loopback e não deve ser publicado como gateway.

## Testes

```powershell
php -c .dev/php.ini vendor/bin/phpunit --filter "FakeWhatsApp|FakeWaClientPairing|FakePairing|FakeServer"
```

Os testes de processo abrem TCP/WebSocket/HTTPS reais em loopback e encerram subprocessos e arquivos temporários próprios. Não acessam `.auth/default`, números reais nem infraestrutura do WhatsApp. Verificam criptografia, parsing, ACK/receipts, erros, limites, grupo, retry, histórico e app-state; não substituem testes autorizados com o serviço real.
