# MCP e telemetria WAM opcionais

As duas integrações são PHP nativo e não iniciam conexão, enviam mensagens ou ativam telemetria automaticamente ao carregar a biblioteca.

## Servidor MCP por stdio

```powershell
C:\xampp\php\php.exe -c .dev/php.ini examples/mcp.php
```

Configure o cliente MCP para iniciar esse comando com o diretório do projeto como `cwd`. Exemplo de configuração, adaptando os caminhos ao ambiente:

```json
{
  "mcpServers": {
    "mvo-php": {
      "command": "C:/xampp/php/php.exe",
      "args": [
        "-c", "C:/projects/mvo-php/.dev/php.ini",
        "C:/projects/mvo-php/examples/mcp.php"
      ],
      "env": {
        "MCP_SESSION_ID": "default",
        "MCP_AUTH_DIRECTORY": "C:/projects/mvo-php/.auth"
      }
    }
  }
}
```

O servidor negocia MCP `2025-06-18`, usa JSON-RPC UTF-8 delimitado por newline e mantém stdout exclusivo para mensagens MCP. Logs e erros do processo vão para stderr. Suporta `initialize`, `notifications/initialized`, `ping`, `tools/list`, `tools/call`, `resources/list` e `resources/read`.

Os tools são gerados de uma lista explícita de métodos públicos: conexão/estado/envio/recibos, consultas de histórico, grupos/comunidades, presença, perfil/privacidade, Business, canais, status, listas, bots e ações de conversa. Os parâmetros têm os mesmos nomes dos métodos PHP e incluem `session` opcional. Exemplos:

```json
{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"client_connect","arguments":{"session":"default"}}}
{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"client_send_message","arguments":{"to":"123456789@lid","content":"Olá"}}}
{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"history_list_messages","arguments":{"chat":"123456789@lid","limit":20}}}
{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"events_list","arguments":{"after":0,"limit":30}}}
```

Esses exemplos de chamada pressupõem o handshake MCP concluído. A conexão começa apenas quando o cliente MCP invoca `client_connect`; os tools de envio executam ações reais quando invocados. Use uma sessão disponível: o processo MCP não abre a mesma sessão simultaneamente com outro worker que detenha seu lock. Sessões criadas são reutilizadas até o fim do processo, com máximo padrão de quatro. O loop faz poll dos clientes ativos enquanto aguarda entrada.

Resources disponíveis:

- `mvo://sessions`: estado das sessões mantidas pelo processo.
- `mvo://schema/message`: tipos e campos WAProto do snapshot PHP.
- `mvo://events`: até 200 eventos recentes, sanitizados e com cursor.

Não existe tool genérico de avaliação de código, execução de shell, leitura arbitrária de arquivo, consulta IQ livre, travessia de propriedades ou acesso a credenciais. Chaves privadas, secrets de mensagens e media keys são removidos da saída. Valores binários usam `{"$base64":"..."}`. O limite de frame é 2 MiB; objetos/arrays/profundidade também são limitados. O ring guarda até 200 eventos e até 1 MiB no total; payloads acima de 64 KiB são omitidos sem interromper o recebimento WhatsApp. O ring de eventos não substitui o histórico persistente.

`ToolRegistry` e `StdioServer` podem ser usados sem WhatsApp para expor handlers locais explícitos. `ClientRuntime` recebe uma factory `callable(string):WaClient`; isso permite definir auth/backend/proxy da aplicação sem expor esses detalhes ao cliente MCP.

O servidor stdio foi testado como subprocesso PHP em Windows, incluindo initialize e tools/list, sem conexão real ao WhatsApp.

## Streamable HTTP

```powershell
C:\xampp\php\php.exe -c .dev/php.ini examples/mcp-http.php
```

O endpoint padrão é `http://127.0.0.1:8788/mcp`. `HttpServer` reutiliza `StdioServer` para JSON-RPC, tools e resources; `ClientRuntime` permanece compartilhado. Cada sessão HTTP recebe `Mcp-Session-Id`, mantém o estado de inicialização e expira após uma hora de inatividade. POST retorna JSON ou 202 para notificações; GET entrega SSE curto; DELETE encerra a sessão de protocolo. As contas WhatsApp compartilhadas permanecem sob controle dos tools `client_stop` e do encerramento do processo.

```php
$http = new MVO\Mcp\HttpClient('http://127.0.0.1:8788/mcp');
$http->initialize();
$tools = $http->call('tools/list');
$http->disconnect();
```

Configure `MCP_HTTP_HOST`, `MCP_HTTP_PORT`, `MCP_HTTP_PATH`, `MCP_HTTP_ORIGINS`, `MCP_HTTP_HOSTS` e `MCP_BEARER_TOKEN` conforme a aplicação. Host e Origin são validados; bind externo exige bearer explícito. O transporte nativo oferece HTTP; TLS deve ser fornecido pela infraestrutura quando usado fora de loopback.

O limite padrão é 2 MiB por mensagem, 16 KiB por header e 32 sockets simultâneos. Há até 16 sessões MCP de protocolo; o limite de contas em `ClientRuntime` continua separado. `publish()` enfileira notificações sanitizadas e GET oferece retomada com `Last-Event-ID` somente no stream original, com retenção limitada. Eventos WhatsApp continuam acessíveis por `events_list` e resources. Não existe eval ou chamada arbitrária. A referência é a [especificação MCP 2025-06-18](https://modelcontextprotocol.io/specification/2025-06-18/basic/transports).

Os testes usam `HttpClient`/cURL reais contra um subprocesso TCP loopback e cobrem sessão, Origin/Host/bearer, limites, SSE/replay, cleanup e sockets incompletos. Nenhuma conta WhatsApp foi conectada por esses testes.

## WAM

```php
use MVO\Telemetry\WamService;

$wam = new WamService(
    query: fn($node, $timeout) => $client->query($node, $timeout),
    enabled: false, // padrão
);

// Opt-in explícito da aplicação:
$wam->setEnabled(true);
$wam->commit('UiAction', ['uiActionType' => 'CHAT_OPEN', 'uiActionT' => 142]);
$uploaded = $wam->flush();
```

`commit` grava um evento real informado pela aplicação. Não existem observadores automáticos, timer de upload, simulação de interação humana ou geração de eventos de UI. `flush` é a única operação de rede; telemetria desabilitada não coleta nem envia e desabilitar descarta o buffer em memória.

`WamRegistry` contém 426 eventos, 46 globals e 862 enums da versão exata `@vinikjkkj/wa-wam@2.3000.1041713829-1ec0d3b`, dependência fixada no plugin WAM do projeto de referência. O arquivo distribuído é JSON; não é necessário npm ou runtime JavaScript. Nomes desconhecidos, enums inválidos e tipos de campo incorretos falham antes de modificar o batch.

`WamEncoder` implementa WAM v5 TLV: IDs curtos/estendidos, null, inteiro 0/1, int8/16/32/64, float64, strings UTF-8 curtas/médias/longas, markers de atributo/evento/field e sinalizador de último field. Inteiros e doubles usam big-endian. `WamBatch` escreve header/canal/stream/sequence, globals com delta e commitTime por evento. Há vetores byte a byte compartilhados com o teste upstream.

O upload faz IQ real `xmlns=w:stats`, filho `<add t="unixSeconds">`, com o batch binário. Aguarda `type=result`; recusas geram `ServiceException` e preservam o batch para decisão explícita da aplicação. Não há retry silencioso ou declaração de sucesso sem ACK. O buffer limita cada canal a 64000 bytes. `commitById` permite campos já resolvidos para integrações que mantêm seu próprio registro de IDs.

O peso declarado no registro é gravado no header. O serviço não simula amostragem aleatória: a aplicação decide quais eventos reais deseja registrar. Globals opcionais são explicitamente fornecidos pelo chamador; dados de dispositivo, conta e consentimento não são inventados.

Os testes locais cobrem codec, registry, validação, sequência, limite de buffer, IQ de upload, recusa do servidor e garantia de nenhuma transmissão com WAM desabilitado. Não foram enviados batches WAM reais durante o desenvolvimento.
