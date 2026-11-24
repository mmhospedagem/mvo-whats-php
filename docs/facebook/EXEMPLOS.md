# Exemplos PHP de Facebook

Os exemplos do pacote são programas PHP locais. Ajuda não conecta nem abre armazenamento. Nenhum deles publica posts, envia mensagens ou altera anúncios.

## Primeiro login e restauração

Para autenticação nativa do checkout em desenvolvimento, use a configuração privada descrita no [guia Android](AUTENTICACAO-ANDROID.md):

```php
/** @var \MVO\Facebook\FacebookConnection $facebook Mesmo dono da aplicação. */
/** @var \MVO\Messenger\Auth\AndroidLoginProfile $nativeProfile Configuração privada. */
$facebook->loginAndroid($email, $password, $nativeProfile);
// Após eventual confirmLogin($code) ou checkLoginApproval() e sessão confirmada:
$facebook->connect();
```

No 406, mantenha o objeto/processo vivo durante a entrada privada. O código acima mostra a sequência sem desafio; trate a exceção antes de encerrar. `GET /me` confirma o UID por JSON com token de usuário em RAM; esse novo verificador tem fixtures e validação real pendente. 405 é recusado, sem HTML ou navegador. O inbox/DGW e os serviços de Fanpage continuam Web; `e2ee_ready=false`.

**Exemplos CLI Web opcionais:** os comandos abaixo usam o método separado `login()`, não implementam o fluxo Android e não devem ser selecionados como fallback automático:

```sh
php packages/facebook/examples/session.php --help
php packages/facebook/examples/session.php --session=demo --auth-dir=/diretorio/privado/auth --data-dir=/diretorio/privado/facebook --login
php packages/facebook/examples/session.php --session=demo --auth-dir=/diretorio/privado/auth --data-dir=/diretorio/privado/facebook --seconds=30
```

No primeiro comando de login, as variáveis `MVO_FACEBOOK_EMAIL` e `MVO_FACEBOOK_PASSWORD` precisam ser fornecidas pelo ambiente privado do processo. Não coloque credenciais na linha de comando ou no histórico do terminal. A execução seguinte restaura a mesma sessão sem `--login`.

O exemplo `session.php` diagnostica desafios e encerra a tentativa; não mantém um formulário de 2FA nem implementa um bridge de navegador. Para continuar um desafio no mesmo objeto/processo, siga [conexão e sessão](CONEXAO.md). Não inicie outro processo com o mesmo lock enquanto o primeiro permanece ativo.

### Escolher a verificação em duas etapas

No fluxo Android, `methods=[]`: não chame o exemplo de seleção Web para fabricar alternativas. Código já recebido usa `confirmLogin()` com `ready/requires_code`; aprovação pessoal usa `checkLoginApproval()` com `can_check`. `requestLoginSms()` é um pedido experimental explícito, uma tentativa do 406 corrente, com `delivery_confirmed=false`; `can_request_sms` não comprova disponibilidade. Veja [conexão](CONEXAO.md).

**Alternativa Web isolada:** O [exemplo `login-methods.php`](../../packages/facebook/examples/login-methods.php) mostra como ligar entradas privadas da aplicação ao login e à escolha de método, mantendo o mesmo objeto vivo. Consulte a ajuda sem conectar:

```sh
php packages/facebook/examples/login-methods.php --help
```

A aplicação fornece callbacks para apresentar somente os métodos oferecidos, receber a escolha do dono e obter código ou decisão de conferir uma aprovação. O exemplo não extrai cookies de perfis existentes, não fabrica opções de SMS/e-mail/aplicativo nem repete pedidos automaticamente. CAPTCHA exige continuação humana separada. A entrada privada pertence à aplicação; códigos e credenciais não vão para argumentos, logs ou exemplos públicos. Essa integração da branch de desenvolvimento tem testes locais, sem prova de envio de SMS/e-mail ou aprovação real.

## Descobrir recursos sem alterações

```sh
php packages/facebook/examples/discover.php --help
php packages/facebook/examples/discover.php --session=demo --auth-dir=/diretorio/privado/auth --data-dir=/diretorio/privado/facebook
php packages/facebook/examples/discover.php --session=demo --auth-dir=/diretorio/privado/auth --data-dir=/diretorio/privado/facebook --ads
```

`discover.php` restaura uma sessão salva e consulta Páginas. `--ads` acrescenta a descoberta **parcial** de contas publicitárias. `--ad-ids`, junto de `--ads`, consulta anúncios por IDs já conhecidos; confira a ajuda. Os programas não selecionam automaticamente uma Página/conta ou assumem que as listas são exaustivas.

## Consultar o inbox da Fanpage selecionada

```sh
php packages/facebook/examples/page-inbox-snapshot.php --help
```

Inclua esse helper na aplicação que já possui `FacebookConnection` conectado. Depois de descobrir e selecionar uma Página, chame `consultarInboxPaginaSelecionada($facebook)`. Para uma conversa escolhida no retorno, passe seu `selection_id` como segundo argumento. O helper mantém actor e Página delegada separados e consulta apenas os dados carregados pelo documento nativo atual, sem enviar mensagens. Veja [inbox](INBOX.md) para completude e limites.

## Monitor sintético

```sh
php packages/facebook/examples/monitor-local.php /diretorio/privado/monitor-example.sqlite
```

Execute duas vezes com o mesmo arquivo para observar deduplicação e reabertura. O exemplo só usa dados sintéticos: não autentica e não acessa o Facebook. A aplicação real precisa coletar projeções dos serviços e informar a completude correta; veja [monitoramento](MONITORAMENTO.md).

## Compartilhar um Messenger existente

Quando a aplicação já possui um `MessengerClient` ativo, use `FacebookClient` com o `WebSession` privado daquele mesmo dono. Não crie outro `FacebookConnection` para o mesmo ID/pasta. Esta função recebe o dono já conectado:

```php
use MVO\Facebook\FacebookClient;
use MVO\Messenger\MessengerClient;

function descobrirComMessengerExistente(MessengerClient $messenger, string $diretorioPrivado): array
{
    $facebook = new FacebookClient($messenger->webSession(), $diretorioPrivado);
    try {
        return $facebook->listPages();
    } finally {
        $facebook->close();
    }
}
```

`FacebookClient::close()` fecha seus bancos de comandos/preferências, sem parar o dono Messenger. A aplicação continua responsável por `poll()` e `MessengerClient::stop()`. `FacebookConnection::stop()` encerra o dono criado por ela própria. Referências de serviços de uma conexão encerrada não representam outra sessão ativa; obtenha-as novamente ao restaurar.

`WebSession`, `privateRequestFields()` e o handoff de login contêm contexto privado. Não os serialize para HTTP, eventos, ferramentas ou logs.

## Onde consultar cada contrato

| Tarefa | Guia |
| --- | --- |
| Instalar separadamente do WhatsApp | [Instalação](INSTALACAO.md) |
| Login, 2FA, CAPTCHA, restauração e lock | [Conexão](CONEXAO.md) |
| Escolher Fanpage explicitamente | [Páginas](PAGINAS.md) |
| Consultar timeline/criar texto contextualizado | [Publicações](PUBLICACOES.md) |
| Comentários, curtidas e autores | [Comentários e reações](COMENTARIOS-REACOES.md) |
| Conversas/mensagens comerciais | [Inbox](INBOX.md) |
| Metadados e métricas publicitárias | [Anúncios](ANUNCIOS.md) |
| Eventos locais de snapshots | [Monitoramento](MONITORAMENTO.md) |
| Resultado incerto e evidência | [Limites](LIMITES.md) |

Fontes executáveis: [session.php](../../packages/facebook/examples/session.php), [discover.php](../../packages/facebook/examples/discover.php) e [monitor-local.php](../../packages/facebook/examples/monitor-local.php).
