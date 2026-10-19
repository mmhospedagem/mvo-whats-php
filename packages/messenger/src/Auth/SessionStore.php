<?php
declare(strict_types=1);

namespace MVO\Messenger\Auth;

/** Session isolation is independent of WhatsApp credential storage and locks. */
final class SessionStore
{
    private \PDO $database;
    /** @var resource|null */ private $lock = null;
    private string $directory;

    public function __construct(string $root, public readonly string $sessionId)
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $sessionId)) throw new \InvalidArgumentException('Invalid Messenger session ID');
        $this->directory = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'messenger' . DIRECTORY_SEPARATOR . $sessionId;
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) throw new \RuntimeException('Unable to create Messenger session directory');
        $file = $this->directory . '/state.sqlite';
        $this->database = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        @chmod($file, 0600);
        $this->database->exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL; CREATE TABLE IF NOT EXISTS settings(name TEXT PRIMARY KEY,value TEXT NOT NULL); CREATE TABLE IF NOT EXISTS events(id INTEGER PRIMARY KEY AUTOINCREMENT,type TEXT NOT NULL,event_key TEXT UNIQUE,payload TEXT NOT NULL,created_at INTEGER NOT NULL); CREATE TABLE IF NOT EXISTS threads(id TEXT PRIMARY KEY,payload TEXT NOT NULL); CREATE TABLE IF NOT EXISTS outbox(id TEXT PRIMARY KEY,thread_id TEXT NOT NULL,text TEXT NOT NULL,state TEXT NOT NULL,request_id INTEGER,server_id TEXT,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL);');
        $this->database->exec('CREATE TABLE IF NOT EXISTS batches(digest TEXT PRIMARY KEY,payload TEXT NOT NULL,created_at INTEGER NOT NULL)');
        $this->database->exec('CREATE TABLE IF NOT EXISTS cutover_threads(open_id TEXT PRIMARY KEY,encrypted_id TEXT NOT NULL,history_visible INTEGER NOT NULL,timestamp_ms INTEGER NOT NULL)');
    }

    public function acquire(): void
    {
        if (is_resource($this->lock)) return;
        $lock = fopen($this->directory . '/owner.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            throw new \RuntimeException('Messenger session already has an active owner');
        }
        $this->lock = $lock;
    }
    public function release(): void
    {
        if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); }
        $this->lock = null;
    }
    public function ownsLock(): bool { return is_resource($this->lock); }

    /**
     * Commit LS projections, batch deduplication and cursor movement together.
     * @template T
     * @param callable():T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed
    {
        $this->requireOwner();
        if ($this->database->inTransaction()) throw new \LogicException('Nested Messenger store transactions are not supported');
        $this->database->beginTransaction();
        try {
            $result = $operation();
            $this->database->commit();
            return $result;
        } catch (\Throwable $error) {
            // A driver may already have aborted a failed commit. Preserve the
            // original projection error even when rollback is no longer possible.
            try { $this->database->rollBack(); } catch (\PDOException) { }
            throw $error;
        }
    }

    /** Private replay material; batches are never included in events().
     * @param list<array<string,mixed>> $calls
     */
    public function saveBatch(string $digest, #[\SensitiveParameter] array $calls): bool
    {
        $this->requireOwner();
        if (!$this->database->inTransaction()) throw new \LogicException('Save Messenger batches inside a projection transaction');
        $payload = json_encode($calls, JSON_THROW_ON_ERROR);
        if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1 || !hash_equals(hash('sha256', $payload), $digest)) {
            throw new \InvalidArgumentException('Messenger batch digest does not match its payload');
        }
        $statement = $this->database->prepare('INSERT INTO batches(digest,payload,created_at) VALUES(?,?,?) ON CONFLICT(digest) DO NOTHING');
        $statement->execute([$digest,$payload,time()]);
        return $statement->rowCount() > 0;
    }
    public function saveCookies(#[\SensitiveParameter] CookieJar $cookies): void
    {
        $this->requireOwner();
        if ($this->database->inTransaction()) throw new \LogicException('Nested Messenger store transactions are not supported');
        $this->database->beginTransaction();
        try {
            $previous = $this->get('account_id');
            if ($previous !== null && $previous !== $cookies->accountId()) throw new \InvalidArgumentException('Use a new Messenger session ID for another Facebook account');
            // The immutable identity and its credentials become durable together.
            $this->set('cookies', $cookies->exportPrivate());
            $this->set('account_id', $cookies->accountId());
            $this->database->commit();
        } catch (\Throwable $error) {
            try { $this->database->rollBack(); } catch (\PDOException) { }
            throw $error;
        }
    }
    /** Internal projection migration; private payloads must never be logged or exposed.
     * @param callable(list<array{name:string,args:array<mixed>}>):void $operation
     */
    public function replayPrivateBatches(callable $operation):void
    {
        $this->requireOwner();
        if(!$this->database->inTransaction())throw new \LogicException('Replay Messenger batches inside a projection transaction');
        $statement=$this->database->query('SELECT digest,payload FROM batches ORDER BY rowid');
        while($row=$statement->fetch(\PDO::FETCH_ASSOC)){
            if(!hash_equals($row['digest'],hash('sha256',$row['payload'])))throw new \UnexpectedValueException('Invalid retained Messenger batch');
            $calls=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
            if(!is_array($calls)||!array_is_list($calls))throw new \UnexpectedValueException('Invalid retained Messenger batch');
            $operation($calls);
        }
    }
    public function putCutoverLink(string $openId,string $encryptedId,bool $historyVisible,int $timestampMs):void
    {
        $this->requireOwner();
        foreach([$openId,$encryptedId] as $id)if(!preg_match('/^[1-9][0-9]{0,24}$/D',$id))throw new \InvalidArgumentException('Invalid Messenger cutover ID');
        $statement=$this->database->prepare('INSERT INTO cutover_threads(open_id,encrypted_id,history_visible,timestamp_ms) VALUES(?,?,?,?) ON CONFLICT(open_id) DO UPDATE SET encrypted_id=excluded.encrypted_id,history_visible=excluded.history_visible,timestamp_ms=excluded.timestamp_ms');
        $statement->execute([$openId,$encryptedId,(int)$historyVisible,$timestampMs]);
    }
    public function loadCookies(): ?CookieJar
    {
        $value = $this->get('cookies');
        return $value === null ? null : CookieJar::fromJson($value);
    }
    public function set(string $name, #[\SensitiveParameter] string $value): void
    {
        $this->requireOwner();
        $statement = $this->database->prepare('INSERT INTO settings(name,value) VALUES(?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value');
        $statement->execute([$name,$value]);
    }
    public function get(string $name): ?string
    {
        $statement = $this->database->prepare('SELECT value FROM settings WHERE name=?'); $statement->execute([$name]);
        $value = $statement->fetchColumn(); return $value === false ? null : $value;
    }
    /** @param array<string,mixed> $payload */
    public function event(string $type, #[\SensitiveParameter] array $payload, ?string $key = null): bool
    {
        $this->requireOwner();
        $statement = $this->database->prepare('INSERT INTO events(type,event_key,payload,created_at) VALUES(?,?,?,?) ON CONFLICT(event_key) DO NOTHING');
        $statement->execute([$type,$key,json_encode($payload, JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),time()]);
        return $statement->rowCount() > 0;
    }
    /** @return list<array<string,mixed>> */
    public function events(int $after = 0, int $limit = 100): array
    {
        if ($after < 0 || $limit < 1 || $limit > 500) throw new \InvalidArgumentException('Invalid event pagination');
        $statement = $this->database->prepare('SELECT id,type,payload,created_at FROM events WHERE id>? ORDER BY id LIMIT ?');
        $statement->bindValue(1,$after,\PDO::PARAM_INT); $statement->bindValue(2,$limit,\PDO::PARAM_INT); $statement->execute();
        return array_map(static function (array $row): array { $row['id']=(int)$row['id']; $row['created_at']=(int)$row['created_at']; $row['payload']=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR); return $row; }, $statement->fetchAll(\PDO::FETCH_ASSOC));
    }
    /** @param array<string,mixed> $thread */
    public function putThread(string $id, array $thread): void
    {
        $this->requireOwner();
        if (!preg_match('/^[1-9][0-9]{0,24}$/D', $id)) throw new \InvalidArgumentException('Expected the exact Facebook thread ID');
        $statement=$this->database->prepare('INSERT INTO threads(id,payload) VALUES(?,?) ON CONFLICT(id) DO UPDATE SET payload=excluded.payload');
        $statement->execute([$id,json_encode($thread,JSON_THROW_ON_ERROR)]);
    }
    /** @return array<string,mixed>|null */
    public function thread(string $id): ?array
    {
        $statement=$this->database->prepare('SELECT payload FROM threads WHERE id=?'); $statement->execute([$id]); $value=$statement->fetchColumn();
        return $value===false?null:$this->withCutover(json_decode($value,true,64,JSON_THROW_ON_ERROR));
    }
    /** @return list<array<string,mixed>> */
    public function threads(): array
    {
        return array_map(fn(string $value):array=>$this->withCutover(json_decode($value,true,64,JSON_THROW_ON_ERROR)),$this->database->query('SELECT payload FROM threads ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
    }
    /** @param array<string,mixed> $thread
     * @return array<string,mixed>
     */
    private function withCutover(array $thread):array
    {
        $statement=$this->database->prepare('SELECT encrypted_id,history_visible,timestamp_ms FROM cutover_threads WHERE open_id=?');$statement->execute([$thread['id']]);
        $link=$statement->fetch(\PDO::FETCH_ASSOC);
        return $link===false?$thread:[...$thread,'encrypted'=>true,'plaintext_send_supported'=>false,'encrypted_thread_id'=>$link['encrypted_id'],
            'show_open_message_history'=>(bool)$link['history_visible'],'cutover_timestamp_ms'=>(int)$link['timestamp_ms']];
    }
    public function enqueue(string $id,string $threadId,#[\SensitiveParameter] string $text,int $requestId): void
    {
        $this->requireOwner();
        $statement=$this->database->prepare('INSERT INTO outbox(id,thread_id,text,state,request_id,created_at,updated_at) VALUES(?,?,?,\'prepared\',?,?,?)');
        $statement->execute([$id,$threadId,$text,$requestId,time(),time()]);
    }
    public function markSent(string $id,string $state,?string $serverId=null): void
    {
        $this->requireOwner();
        if (!in_array($state,['prepared','submitted','server_ack','failed','uncertain'],true)) throw new \InvalidArgumentException('Invalid send state');
        $statement=$this->database->prepare('UPDATE outbox SET state=?,server_id=COALESCE(?,server_id),updated_at=? WHERE id=?');
        $statement->execute([$state,$serverId,time(),$id]);
    }
    /** @return array<string,mixed>|null */
    public function outgoing(string $id): ?array
    {
        $statement=$this->database->prepare('SELECT id,thread_id,state,request_id,server_id,created_at,updated_at FROM outbox WHERE id=?'); $statement->execute([$id]);
        $row=$statement->fetch(\PDO::FETCH_ASSOC); return $row===false?null:$row;
    }
    private function requireOwner(): void { if (!$this->ownsLock()) throw new \LogicException('Acquire the Messenger session lock before writing'); }
    public function __destruct() { $this->release(); }
    public function __debugInfo(): array { return ['sessionId'=>$this->sessionId,'ownsLock'=>$this->ownsLock()]; }
}
