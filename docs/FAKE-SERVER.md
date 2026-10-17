# Servidor de desenvolvimento PHP offline

Este guia apresenta o harness básico de transporte. Para pareamento QR/código, mensagens Signal/grupos, mídia HTTPS, histórico, app-state e retomada de `WaClient`, use o [ambiente WhatsApp falso com estado](FAKE-WHATSAPP.md), ativado explicitamente por `FakeServer(protocol: $protocol)`.

```powershell
C:\xampp\php\php.exe -c .dev/php.ini examples/fake-server.php 8787
```

O comando permanece no terminal em primeiro plano. A primeira linha contém a URL `ws://127.0.0.1:8787/ws/chat` e a chave pública da CA de teste em base64. Cada execução gera uma CA e identidade de servidor novas. Chaves privadas, payloads de autenticação e mensagens não são registrados. O servidor só aceita bind em `127.0.0.1`.

O harness executa rede TCP, upgrade RFC6455, frames binários mascarados do cliente, controle ping/pong/close, fragmentação, framing WhatsApp, handshake Noise XX/IK/XXfallback e roteamento ED, certificados XEdDSA com cadeia root/intermediário/leaf e frames AES-GCM nas duas direções. O cliente PHP verifica essa cadeia normalmente usando a CA de teste fornecida explicitamente. A raiz oficial e a configuração de produção continuam intactas.

Exemplo mínimo de cliente de transporte:

```php
use MVO\Crypto\Crypto;
use MVO\Protocol\BinaryNode;
use MVO\Transport\WebSocket;
use MVO\Transport\NoiseSession;

$socket = new WebSocket('ws://127.0.0.1:8787/ws/chat');
$socket->connect();
$root = base64_decode($publicRootFromServerOutput, true);
if ($root === false || strlen($root) !== 32) {
    throw new InvalidArgumentException('CA de teste inválida');
}
$noise = new NoiseSession($socket, rootPublicKey: $root);
$keys = Crypto::x25519KeyPair();
$noise->start($keys['private'], $keys['public'], 'debug local');
$success = $noise->receiveNode();
$noise->sendNode(new BinaryNode('iq', [
    'id' => 'DEBUG1', 'type' => 'get', 'xmlns' => 'test:echo',
], [new BinaryNode('data', [], 'teste')]));
$result = $noise->receiveNode();
$noise->close();
```

Para uma aplicação que usa `WaClient`, construa a mesma `NoiseSession` por meio do argumento `sessionFactory`, usando **sessão e authDirectory exclusivos de teste**. O harness padrão verifica transporte e criptografia; o modo `FakeProtocol` adiciona registro público de fixtures, pareamento QR/código simulado e serviços descritos no guia complementar. Não autentica uma conta nos servidores do WhatsApp. Nunca use as credenciais da sessão real `default` nesse servidor de teste.

## Rotas padrão

| Stanza | Resposta |
| --- | --- |
| Noise XX autenticado | `<success t>` de teste |
| IQ `xmlns=w:p` ou `urn:xmpp:ping` | IQ result com o mesmo ID |
| IQ `xmlns=test:echo` | IQ result com o mesmo conteúdo e ID |
| IQ desconhecida | IQ error 501 `fake-route-not-implemented` |
| `<message id>` | ACK de transporte com o mesmo ID |
| Presença, recibos e outras stanzas | Nenhuma resposta padrão |

ACK de mensagem aqui confirma somente que o servidor de teste recebeu um frame; não declara entrega, descriptografia Signal ou leitura por outro aparelho. Os payloads de mensagem podem ser fornecidos pelas fixtures da aplicação.

## Rotas customizadas

```php
use MVO\Testing\FakeServer;
use MVO\Protocol\BinaryNode;

$server = new FakeServer(port: 8787);
$server->route('iq', 'w:g2', static function (BinaryNode $iq): array {
    return [new BinaryNode('iq', ['id' => $iq->attrs['id'], 'type' => 'result'], [
        new BinaryNode('group', ['id' => '12345', 'subject' => 'Grupo local']),
    ])];
});
$server->listen();
while (true) {
    $server->serveOnce();
}
```

O handler recebe uma `BinaryNode` já descriptografada pelo transporte Noise e devolve uma lista de nodes. As respostas passam pela criptografia Noise negociada. `serveOnce` limita a conexão a 1000 nodes por padrão; esse limite é configurável. O servidor atende uma conexão por vez, apropriado para debug e fixtures determinísticas.

O harness básico não inclui TLS/wss do próprio WebSocket, serviços de negócio ou uma implementação completa do servidor WhatsApp. O modo `FakeProtocol` oferece mídia HTTPS, app-state e pareamento simulado conforme a cobertura documentada em [FAKE-WHATSAPP.md](FAKE-WHATSAPP.md); não vincula um celular real. Rotas ausentes retornam erro explícito, sem inventar comportamento do servidor real.

## Testes

`FakeServerTest` inicia um subprocesso PHP com TCP loopback, conecta o `WebSocket`/`NoiseSession` de produção, valida a CA de teste, envia/recebe conteúdo binário cifrado e verifica ACK. Outros testes provam que a raiz oficial rejeita a CA do harness e que uma cadeia adulterada é recusada mesmo quando a CA de teste foi explicitamente selecionada. Nenhuma conexão externa, conta real ou chave de produção é usada.
