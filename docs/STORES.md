# Persistência intercambiável

`MVO\Store\KeyValueStore` define armazenamento binário por namespace e chave, paginação por ordem de bytes, transações atômicas e fechamento. O `SignalStore` usa esse contrato para ratchets, identidades, prekeys, sender keys, outbox, inbox, histórico e app-state. As credenciais de pareamento continuam no diretório protegido da sessão.

O padrão é SQLite local com WAL. `PdoStore` aceita PDO SQLite/MySQL/PostgreSQL. Os adaptadores `RedisStore` e `MongoStore` são opcionais e não alteram o padrão:

```php
use MVO\WaClient;
use MVO\Store\RedisStore;

$client = new WaClient(
    sessionId: 'default',
    backendFactory: static fn(string $session): RedisStore => new RedisStore(
        url: getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379/0',
        sessionId: $session,
        maxBytes: 16 * 1024 * 1024,
    ),
);
```

```php
use MVO\Store\MongoStore;

$client = new WaClient(
    sessionId: 'default',
    backendFactory: static fn(string $session): MongoStore => new MongoStore(
        uri: getenv('MONGODB_URI') ?: 'mongodb://127.0.0.1:27017',
        database: 'mvo',
        collection: 'sessions',
        sessionId: $session,
    ),
);
```

Redis funciona com PHP sockets nativos, sem `ext-redis`, Node.js ou servidor embutido. Aceita `redis://`/`rediss://`, ACL `usuario:senha`, seleção de database e TLS com verificação de certificado. Não faz descoberta de cluster, failover Sentinel ou redirecionamento `MOVED`; para isso use um endpoint estável ou injete um cliente Redis compatível no parâmetro `command`.

MongoDB usa a extensão oficial opcional `ext-mongodb`; não exige o pacote Composer de alto nível. Sem a extensão, construir `MongoStore` gera erro explícito. Não há instalação automática de extensão nem banco. O manager usa escrita `majority` com journal e desativa repetição automática de writes. A URI pode configurar autenticação e TLS fornecidos pelo driver.

## Renomeação para MVO e sessões existentes

O nome público e o namespace PHP agora são MVO. O diretório `.auth/default`, o arquivo `state.sqlite`, os identificadores de sessão e a codificação dos registros permanecem iguais. A migração de armazenamento copia/preserva os bytes existentes; não gera identidades, pré-chaves ou ratchets novos e não exige outro QR somente pela troca da marca.

`SqliteStore` usa a tabela `mvo_kv`. Ao encontrar a tabela do nome anterior, faz um rename transacional preservando seu schema, índices e todas as sessões. Se as duas tabelas existem, mescla registros disjuntos e aceita duplicatas somente quando sessão, namespace, chave e valor são exatamente iguais. Um conflito interrompe e reverte a migração; nenhuma das duas versões do estado criptográfico é escolhida silenciosamente. Após sucesso, a tabela antiga deixa de existir. O procedimento é idempotente.

Quando essa migração é necessária no `CredentialStore`, a biblioteca detecta primeiro o schema por metadados e adquire `connection.lock` antes do DDL. Se um worker antigo ainda usa a sessão, a abertura falha com “Session already in use”; pare esse worker antes de migrar. O lock temporário é liberado em sucesso ou falha. Consultar credenciais já migradas não adquire esse lock. Faça um backup consistente antes da primeira migração; não copie apenas o arquivo SQLite enquanto houver gravação em WAL.

`PdoStore` também usa `mvo_kv` por padrão e aplica a migração na conexão fornecida, preservando a coluna `k` de seu próprio schema. SQLite e PostgreSQL usam transação, e PostgreSQL bloqueia as tabelas durante a operação. MySQL/MariaDB usa rename atômico quando só existe a origem; a mescla de duas tabelas exige InnoDB e locks explícitos mantidos até concluir cópia, commit e remoção da origem. DDL MySQL tem commit implícito: falhas após a cópia podem deixar ambas as tabelas para reconciliação, mas não iniciam uma sessão vazia nem sobrescrevem colisões. Tabelas explicitamente configuradas pela aplicação não são renomeadas.

Redis usa o prefixo `mvo`. Se existe um snapshot no prefixo anterior, uma operação Lua compara ambos os valores e transfere o snapshot completo no mesmo hash slot. A origem recebe um marcador de migração que impede um cliente antigo de continuar alterando ratchets naquele prefixo. Conteúdos divergentes geram `RemoteStoreConflict`; destino ausente depois do marcador também falha em vez de criar estado vazio. Uma confirmação perdida gera `RemoteCommitUnknown`: reabra e reconcilie o estado. Prefixos personalizados continuam sendo responsabilidade da aplicação.

MongoDB usa o database `mvo` para sessões novas. Para uma sessão existente somente no database anterior, a biblioteca faz fallback e continua o CAS naquele database, sem copiar uma parte das chaves para outro documento. Se os dois databases contêm a sessão, mesmo com snapshots iguais, a abertura falha explicitamente até reconciliação. Esse fallback não é apresentado como migração física entre databases: a transferência exige manutenção coordenada e, quando necessária, transação suportada pelo servidor. Pare workers antigos antes da troca de versão. Um adapter `command` de dois argumentos recebe `(documento, database)` e permite essa detecção; um adapter legado de um argumento já está vinculado ao banco escolhido pela aplicação e não fornece descoberta entre bancos.

## Atomicidade e concorrência

Os stores remotos guardam um snapshot versionado de **uma sessão**. Uma transação lê o snapshot, executa a operação em memória e troca versão e dados com uma única escrita condicional. Redis usa Lua CAS e apenas um `SET`; Mongo usa `findAndModify` em um documento. Assim, uma atualização de ratchet e a ciphertext correspondente da outbox não são gravadas parcialmente em vários registros remotos.

Uma alteração concorrente gera `RemoteStoreConflict` sem gravar o snapshot do processo perdedor. O store não repete automaticamente a operação criptográfica. A aplicação deve reler o estado e decidir o próximo passo; transações Signal não podem ser reexecutadas cegamente sobre ciphertext já enviada.

Se o commit foi despachado e a confirmação se perde, o resultado é **indeterminado**, mesmo que o servidor tenha persistido tudo. `RemoteCommitUnknown` impede reutilizar aquela instância. Reabra o store, consulte outbox/status e reconcilie o envio antes de continuar. Não trate esse erro como rollback confirmado nem como mensagem entregue.

## Limites

Cada leitura fora de transação carrega o snapshot inteiro; a paginação ordena e limita as chaves devolvidas ao chamador, mas não transforma o servidor em índice por mensagem. Esse desenho privilegia a atomicidade do estado criptográfico e é apropriado para sessões com volume limitado. Para históricos grandes, prefira o backend SQL ou separe o histórico por meio de um backend especializado.

O snapshot JSON/base64 é limitado por `maxBytes`, incluindo o custo de codificar os valores binários. Padrões: Redis 16 MiB; Mongo 8 MiB, abaixo do limite BSON de documento. Exceder o limite falha antes do commit. Reduzir o limite para menos que o snapshot existente também impede a leitura; aumente-o conscientemente ou migre os dados. Os dados não são cifrados novamente pelo store; mantenha controles de acesso, TLS e persistência do servidor adequados ao ambiente.

Os nomes de sessões viram identificadores SHA-256 independentes; namespace/chaves/valores são codificados sem perda de bytes. Não compartilhe a mesma sessão com dois workers ativos. A infraestrutura Redis deve manter as chaves sem TTL e sem eviction; perda/expiração do snapshot significa perda do estado Signal, não simples limpeza de cache.

## Testes

`RemoteStoreTest` testa snapshot/CAS por adaptadores determinísticos, com rollback, conflitos, isolamento de sessão/namespace, valores binários, paginação, fechamento, limites e confirmação de commit perdida. O transporte RESP também é exercitado contra um servidor de fixture TCP em loopback, incluindo AUTH, SELECT, bulk binário e arrays. Nenhum servidor Redis/MongoDB externo é instalado ou modificado durante esses testes.

Não foram executados testes de integração com um Redis ou MongoDB de produção nesta máquina; o driver MongoDB não está instalado. Os testes de protocolo não substituem a validação da autenticação, TLS, journal e disponibilidade do banco escolhido no ambiente de implantação.

`StoreBrandMigrationTest` cobre SQLite real com rename, índices, registros binários e múltiplas sessões; mescla e rollback de conflitos; o guard de `connection.lock`; e fixtures Redis/Mongo com CAS concorrente, confirmação perdida, marcador sem destino e bases conflitantes. Os testes não abrem a sessão real. MySQL/PostgreSQL e Lua em um servidor Redis real ainda precisam de validação de integração no ambiente escolhido.
