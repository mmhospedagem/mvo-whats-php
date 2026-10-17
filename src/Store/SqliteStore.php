<?php
declare(strict_types=1);
namespace MVO\Store;
/** Binary key/value persistence, isolated by session and namespace. */
final class SqliteStore implements KeyValueStore
{
    private \PDO $db;
    private bool $closed = false;
    /** @param callable():void|null $beforeMigration Optional exclusive lock guard for an existing legacy database. */
    public function __construct(string $path, private readonly string $sessionId = 'default', ?callable $beforeMigration=null) {
        if ($sessionId === '') throw new \InvalidArgumentException('Empty session ID');
        $this->db = new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout=5000');
        try {
            SqlStoreMigration::initialize($this->db,'sqlite','mvo_kv','key','CREATE TABLE IF NOT EXISTS mvo_kv (session TEXT NOT NULL, namespace TEXT NOT NULL, key TEXT NOT NULL, value BLOB NOT NULL, PRIMARY KEY(session,namespace,key))',$beforeMigration);
            $this->db->exec('PRAGMA journal_mode=WAL');
        }catch(\Throwable $error){unset($this->db);$this->closed=true;throw $error;}
    }
    public function get(string $namespace, string $key): ?string {
        $q = $this->db->prepare('SELECT value FROM mvo_kv WHERE session=? AND namespace=? AND key=?');
        $q->execute([$this->sessionId,$namespace,$key]); $value = $q->fetchColumn();
        return $value === false ? null : $value;
    }
    public function set(string $namespace, string $key, string $value): void {
        $q = $this->db->prepare('INSERT INTO mvo_kv(session,namespace,key,value) VALUES(?,?,?,?) ON CONFLICT(session,namespace,key) DO UPDATE SET value=excluded.value');
        $q->bindValue(1,$this->sessionId); $q->bindValue(2,$namespace); $q->bindValue(3,$key); $q->bindValue(4,$value,\PDO::PARAM_LOB); $q->execute();
    }
    public function delete(string $namespace, string $key): void {
        $q = $this->db->prepare('DELETE FROM mvo_kv WHERE session=? AND namespace=? AND key=?'); $q->execute([$this->sessionId,$namespace,$key]);
    }
    /** @return array<string,string> */
    public function entries(string $namespace, int $limit = 1000, string $after = ''): array {
        if ($limit < 1 || $limit > 10000) throw new \InvalidArgumentException('Store page limit must be between 1 and 10000');
        $q = $this->db->prepare('SELECT key,value FROM mvo_kv WHERE session=? AND namespace=? AND key>? ORDER BY key COLLATE BINARY LIMIT ?');
        $q->bindValue(1,$this->sessionId); $q->bindValue(2,$namespace); $q->bindValue(3,$after); $q->bindValue(4,$limit,\PDO::PARAM_INT); $q->execute();
        return $q->fetchAll(\PDO::FETCH_KEY_PAIR);
    }
    /**
     * @template T
     * @param callable(self):T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed {
        if ($this->db->inTransaction()) throw new \LogicException('Nested transaction');
        $this->db->beginTransaction();
        try { $result = $operation($this); $this->db->commit(); return $result; }
        catch (\Throwable $e) { $this->rollbackIfActive(); throw $e; }
    }
    private function rollbackIfActive(): void {
        if ($this->db->inTransaction()) $this->db->rollBack();
    }
    /** Release the PDO handle even when exception traces retain this object. A closed store cannot be reused. */
    public function close(): void {
        if ($this->closed) return;
        if ($this->db->inTransaction()) throw new \LogicException('Cannot close SQLite store during a transaction');
        unset($this->db);
        $this->closed = true;
    }
}
