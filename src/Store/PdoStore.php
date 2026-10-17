<?php
declare(strict_types=1);
namespace MVO\Store;

/** SQLite, MySQL/MariaDB and PostgreSQL persistence with atomic database transactions. */
final class PdoStore implements KeyValueStore
{
    private ?\PDO $db;
    private readonly string $driver;
    public function __construct(\PDO $connection, private readonly string $sessionId='default', private readonly string $table='mvo_kv')
    {
        if ($sessionId === '' || strlen($sessionId)>191 || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/D',$table)) throw new \InvalidArgumentException('Invalid store session or table');
        $this->db=$connection;
        $connection->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
        $this->driver=(string)$connection->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $ddl=match($this->driver) {
            'mysql'=>"CREATE TABLE IF NOT EXISTS $table (session VARBINARY(191) NOT NULL, namespace VARBINARY(191) NOT NULL, k VARBINARY(512) NOT NULL, value LONGBLOB NOT NULL, PRIMARY KEY(session,namespace,k)) ENGINE=InnoDB",
            'pgsql'=>"CREATE TABLE IF NOT EXISTS $table (session TEXT NOT NULL, namespace TEXT NOT NULL, k TEXT COLLATE \"C\" NOT NULL, value BYTEA NOT NULL, PRIMARY KEY(session,namespace,k))",
            'sqlite'=>"CREATE TABLE IF NOT EXISTS $table (session TEXT NOT NULL, namespace TEXT NOT NULL, k TEXT COLLATE BINARY NOT NULL, value BLOB NOT NULL, PRIMARY KEY(session,namespace,k))",
            default=>throw new \InvalidArgumentException('Supported PDO drivers: sqlite, mysql, pgsql'),
        };
        try {SqlStoreMigration::initialize($connection,$this->driver,$table,'k',$ddl);}
        catch(\Throwable $error){$this->db=null;throw $error;}
    }
    private function db():\PDO { return $this->db ?? throw new \LogicException('Store is closed'); }
    private function validate(string $namespace,string $key):void {
        if (strlen($namespace)>191 || strlen($key)>512 || str_contains($namespace,"\0") || str_contains($key,"\0")) throw new \InvalidArgumentException('Store namespace/key too long or contains NUL');
    }
    public function get(string $namespace,string $key):?string {
        $this->validate($namespace,$key);
        $q=$this->db()->prepare("SELECT value FROM {$this->table} WHERE session=? AND namespace=? AND k=?");
        $q->execute([$this->sessionId,$namespace,$key]); $value=$q->fetchColumn();
        return $value===false ? null : self::bytes($value);
    }
    public function set(string $namespace,string $key,string $value):void {
        $this->validate($namespace,$key);
        $suffix=$this->driver==='mysql' ? ' ON DUPLICATE KEY UPDATE value=VALUES(value)' : ' ON CONFLICT(session,namespace,k) DO UPDATE SET value=excluded.value';
        $q=$this->db()->prepare("INSERT INTO {$this->table}(session,namespace,k,value) VALUES(?,?,?,?)".$suffix);
        $q->bindValue(1,$this->sessionId); $q->bindValue(2,$namespace); $q->bindValue(3,$key); $q->bindValue(4,$value,\PDO::PARAM_LOB); $q->execute();
    }
    public function delete(string $namespace,string $key):void {
        $this->validate($namespace,$key);
        $q=$this->db()->prepare("DELETE FROM {$this->table} WHERE session=? AND namespace=? AND k=?"); $q->execute([$this->sessionId,$namespace,$key]);
    }
    /** @return array<string,string> */
    public function entries(string $namespace,int $limit=1000,string $after=''):array {
        $this->validate($namespace,$after);
        if ($limit<1 || $limit>10000) throw new \InvalidArgumentException('Store page limit must be between 1 and 10000');
        $q=$this->db()->prepare("SELECT k,value FROM {$this->table} WHERE session=? AND namespace=? AND k>? ORDER BY k LIMIT ?");
        $q->bindValue(1,$this->sessionId); $q->bindValue(2,$namespace); $q->bindValue(3,$after); $q->bindValue(4,$limit,\PDO::PARAM_INT); $q->execute();
        $result=[];
        while ($row=$q->fetch(\PDO::FETCH_NUM)) $result[(string)$row[0]]=self::bytes($row[1]);
        return $result;
    }
    /** @template T
     * @param callable(self):T $operation
     * @return T */
    public function transaction(callable $operation):mixed {
        $db=$this->db();
        if ($db->inTransaction()) throw new \LogicException('Nested transaction');
        $db->beginTransaction();
        try { $result=$operation($this); $db->commit(); return $result; }
        catch (\Throwable $e) { $this->rollbackIfActive(); throw $e; }
    }
    private function rollbackIfActive():void { if ($this->db()->inTransaction()) $this->db()->rollBack(); }
    private static function bytes(mixed $value):string {
        if (is_resource($value)) { $bytes=stream_get_contents($value); if ($bytes===false) throw new \RuntimeException('Cannot read binary database value'); return $bytes; }
        if (!is_string($value)) throw new \UnexpectedValueException('Expected binary database value');
        return $value;
    }
    public function close():void {
        if ($this->db?->inTransaction()) throw new \LogicException('Cannot close store during a transaction');
        $this->db=null;
    }
}
