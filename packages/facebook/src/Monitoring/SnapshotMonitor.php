<?php
declare(strict_types=1);

namespace MVO\Facebook\Monitoring;

/** Local durable diff engine. Network collection and scheduling belong to the caller. */
final class SnapshotMonitor
{
    private \PDO $database;

    public function __construct(string $sqliteFile)
    {
        if ($sqliteFile !== ':memory:') {
            $directory = dirname($sqliteFile);
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Unable to create private monitor directory');
        }
        $this->database = new \PDO('sqlite:' . $sqliteFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        if ($sqliteFile !== ':memory:') @chmod($sqliteFile, 0600);
        $this->database->exec('PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON;');
        $this->database->exec('CREATE TABLE IF NOT EXISTS monitor_scopes(scope_key TEXT PRIMARY KEY,descriptor TEXT NOT NULL,baseline INTEGER NOT NULL DEFAULT 0,last_snapshot TEXT,last_complete INTEGER NOT NULL DEFAULT 0,active_snapshot TEXT,next_cursor TEXT,page_count INTEGER NOT NULL DEFAULT 0,updated_at INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS monitor_runs(scope_key TEXT NOT NULL,snapshot_id TEXT NOT NULL,state TEXT NOT NULL,PRIMARY KEY(scope_key,snapshot_id));
            CREATE TABLE IF NOT EXISTS monitor_pending(scope_key TEXT NOT NULL,snapshot_id TEXT NOT NULL,item_id TEXT NOT NULL,payload TEXT NOT NULL,PRIMARY KEY(scope_key,snapshot_id,item_id));
            CREATE TABLE IF NOT EXISTS monitor_receipts(scope_key TEXT NOT NULL,snapshot_id TEXT NOT NULL,cursor_key TEXT NOT NULL,digest TEXT NOT NULL,result TEXT NOT NULL,PRIMARY KEY(scope_key,snapshot_id,cursor_key));
            CREATE TABLE IF NOT EXISTS monitor_items(scope_key TEXT NOT NULL,item_id TEXT NOT NULL,payload TEXT NOT NULL,PRIMARY KEY(scope_key,item_id));
            CREATE TABLE IF NOT EXISTS monitor_events(id INTEGER PRIMARY KEY AUTOINCREMENT,scope_key TEXT NOT NULL,event_key TEXT NOT NULL UNIQUE,type TEXT NOT NULL,item_id TEXT NOT NULL,payload TEXT NOT NULL,created_at INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS monitor_event_scope ON monitor_events(scope_key,id);');
    }

    /** A page is durable on return. Only a terminal page commits its staged items.
     * Reuse a snapshotId for retries/resumption; use a new ID for each new collection.
     * @return array{duplicate:bool,committed:bool,baseline_created:bool,events_created:int}
     */
    public function ingest(ResourceScope $scope, SnapshotPage $page): array
    {
        return $this->transaction(function () use ($scope, $page): array {
            $key = $scope->key();
            $cursorKey = self::cursorKey($page->cursor);
            $digest = hash('sha256', self::json($page->toArray()));
            $receipt = $this->one('SELECT digest,result FROM monitor_receipts WHERE scope_key=? AND snapshot_id=? AND cursor_key=?', [$key, $page->snapshotId, $cursorKey]);
            if ($receipt !== null) {
                if (!hash_equals($receipt['digest'], $digest)) throw new \LogicException('A monitor page retry changed its normalized content');
                $result = self::decode($receipt['result']);
                return ['duplicate' => true, 'committed' => $result['committed'], 'baseline_created' => $result['baseline_created'], 'events_created' => 0];
            }
            $this->execute('INSERT INTO monitor_scopes(scope_key,descriptor,updated_at) VALUES(?,?,?) ON CONFLICT(scope_key) DO NOTHING', [$key, self::json($scope->toArray()), time()]);
            $state = $this->one('SELECT * FROM monitor_scopes WHERE scope_key=?', [$key]);
            if ($state === null) throw new \LogicException('Monitor scope was not initialized');
            $run = $this->one('SELECT state FROM monitor_runs WHERE scope_key=? AND snapshot_id=?', [$key, $page->snapshotId]);
            if ($run !== null && $run['state'] !== 'active') throw new \LogicException('The monitor snapshot is already closed');
            if ($state['active_snapshot'] === null) {
                if ($page->cursor !== null || $run !== null) throw new \LogicException('A new monitor snapshot must start without a cursor');
                $this->execute('INSERT INTO monitor_runs(scope_key,snapshot_id,state) VALUES(?,?,?)', [$key, $page->snapshotId, 'active']);
                $this->execute('UPDATE monitor_scopes SET active_snapshot=?,next_cursor=NULL,page_count=0 WHERE scope_key=?', [$page->snapshotId, $key]);
            } elseif ($state['active_snapshot'] !== $page->snapshotId) {
                throw new \LogicException('Finish or explicitly discard the pending monitor snapshot');
            }
            if ($state['next_cursor'] !== $page->cursor) throw new \LogicException('Monitor page does not match the persisted next cursor');
            if ($page->nextCursor !== null && $this->one('SELECT 1 FROM monitor_receipts WHERE scope_key=? AND snapshot_id=? AND cursor_key=?', [$key, $page->snapshotId, self::cursorKey($page->nextCursor)]) !== null) {
                throw new \LogicException('Monitor pagination returned a previously consumed cursor');
            }
            foreach ($page->items as $item) {
                $pending = $this->one('SELECT payload FROM monitor_pending WHERE scope_key=? AND snapshot_id=? AND item_id=?', [$key, $page->snapshotId, $item->id]);
                $projection = $item->toArray();
                if ($pending !== null) {
                    $previous = self::decode($pending['payload']);
                    $projection = ['id' => $item->id, 'fields' => [...$previous['fields'], ...$item->fields], 'counters' => [...$previous['counters'], ...$item->counters]];
                }
                $this->execute('INSERT INTO monitor_pending(scope_key,snapshot_id,item_id,payload) VALUES(?,?,?,?) ON CONFLICT(scope_key,snapshot_id,item_id) DO UPDATE SET payload=excluded.payload', [$key, $page->snapshotId, $item->id, self::json($projection)]);
            }
            $result = ['duplicate' => false, 'committed' => $page->nextCursor === null, 'baseline_created' => false, 'events_created' => 0];
            if ($page->nextCursor === null) {
                $result['events_created'] = $this->commitItems($scope, $page, (bool) $state['baseline']);
                $result['baseline_created'] = !(bool) $state['baseline'] && $page->complete;
                $this->execute('UPDATE monitor_scopes SET baseline=?,last_snapshot=?,last_complete=?,active_snapshot=NULL,next_cursor=NULL,page_count=0,updated_at=? WHERE scope_key=?', [(int) ((bool) $state['baseline'] || $page->complete), $page->snapshotId, (int) $page->complete, time(), $key]);
                $this->execute('UPDATE monitor_runs SET state=? WHERE scope_key=? AND snapshot_id=?', ['committed', $key, $page->snapshotId]);
                $this->execute('DELETE FROM monitor_pending WHERE scope_key=? AND snapshot_id=?', [$key, $page->snapshotId]);
            } else {
                $this->execute('UPDATE monitor_scopes SET next_cursor=?,page_count=page_count+1,updated_at=? WHERE scope_key=?', [$page->nextCursor, time(), $key]);
            }
            $this->execute('INSERT INTO monitor_receipts(scope_key,snapshot_id,cursor_key,digest,result) VALUES(?,?,?,?,?)', [$key, $page->snapshotId, $cursorKey, $digest, self::json($result)]);
            return $result;
        });
    }

    /** Abandon a failed collection explicitly; committed items/events are untouched. */
    public function discardPending(ResourceScope $scope, string $snapshotId): void
    {
        $this->transaction(function () use ($scope, $snapshotId): void {
            $key = $scope->key();
            $row = $this->one('SELECT active_snapshot FROM monitor_scopes WHERE scope_key=?', [$key]);
            if ($row === null || $row['active_snapshot'] !== $snapshotId) throw new \LogicException('The requested monitor snapshot is not pending');
            $this->execute('UPDATE monitor_runs SET state=? WHERE scope_key=? AND snapshot_id=?', ['discarded', $key, $snapshotId]);
            $this->execute('DELETE FROM monitor_pending WHERE scope_key=? AND snapshot_id=?', [$key, $snapshotId]);
            $this->execute('UPDATE monitor_scopes SET active_snapshot=NULL,next_cursor=NULL,page_count=0,updated_at=? WHERE scope_key=?', [time(), $key]);
        });
    }

    /** Pagination cursors are private collector state, not authentication credentials.
     * @return array{baseline_ready:bool,last_snapshot_id:?string,last_complete:bool,pending_snapshot_id:?string,next_cursor:?string,pending_pages:int,known_items:int}
     * @phpstan-impure
     */
    public function state(ResourceScope $scope): array
    {
        $row = $this->one('SELECT * FROM monitor_scopes WHERE scope_key=?', [$scope->key()]);
        $count = $this->one('SELECT COUNT(*) AS count FROM monitor_items WHERE scope_key=?', [$scope->key()]);
        return ['baseline_ready' => (bool) ($row['baseline'] ?? false), 'last_snapshot_id' => $row['last_snapshot'] ?? null, 'last_complete' => (bool) ($row['last_complete'] ?? false), 'pending_snapshot_id' => $row['active_snapshot'] ?? null, 'next_cursor' => $row['next_cursor'] ?? null, 'pending_pages' => (int) ($row['page_count'] ?? 0), 'known_items' => (int) ($count['count'] ?? 0)];
    }

    /** Stable local pagination by exact opaque item ID, not a converted numeric ID.
     * @return list<array{id:string,fields:array<string,string|int|bool|null>,counters:array<string,int>}>
     * @phpstan-impure
     */
    public function items(ResourceScope $scope, ?string $afterId = null, int $limit = 100): array
    {
        self::limit($limit);
        $statement = $this->database->prepare('SELECT payload FROM monitor_items WHERE scope_key=? AND (? IS NULL OR item_id>?) ORDER BY item_id LIMIT ?');
        $statement->bindValue(1, $scope->key()); $statement->bindValue(2, $afterId); $statement->bindValue(3, $afterId); $statement->bindValue(4, $limit, \PDO::PARAM_INT); $statement->execute();
        return array_map(self::decode(...), $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Consumer cursors are durable event IDs; acknowledge them in the consumer.
     * @return list<array{id:int,type:string,item_id:string,payload:array<string,mixed>,created_at:int}>
     * @phpstan-impure
     */
    public function events(ResourceScope $scope, int $after = 0, int $limit = 100): array
    {
        self::limit($limit);
        if ($after < 0) throw new \InvalidArgumentException('Invalid monitor event cursor');
        $statement = $this->database->prepare('SELECT id,type,item_id,payload,created_at FROM monitor_events WHERE scope_key=? AND id>? ORDER BY id LIMIT ?');
        $statement->bindValue(1, $scope->key()); $statement->bindValue(2, $after, \PDO::PARAM_INT); $statement->bindValue(3, $limit, \PDO::PARAM_INT); $statement->execute();
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'type' => $row['type'], 'item_id' => $row['item_id'], 'payload' => self::decode($row['payload']), 'created_at' => (int) $row['created_at']], $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function commitItems(ResourceScope $scope, SnapshotPage $page, bool $notify): int
    {
        $key = $scope->key(); $events = 0;
        $statement = $this->database->prepare('SELECT item_id,payload FROM monitor_pending WHERE scope_key=? AND snapshot_id=? ORDER BY item_id');
        $statement->execute([$key, $page->snapshotId]);
        while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
            $incoming = self::decode($row['payload']);
            $old = $this->one('SELECT payload FROM monitor_items WHERE scope_key=? AND item_id=?', [$key, $row['item_id']]);
            if ($old === null) {
                $merged = $incoming;
                if ($notify) $events += $this->event($scope, $page, 'item.created', $row['item_id'], $merged);
            } else {
                $previous = self::decode($old['payload']);
                $changes = []; $counterChanges = [];
                foreach ($incoming['fields'] as $name => $value) {
                    if (array_key_exists($name, $previous['fields']) && $previous['fields'][$name] !== $value) $changes[$name] = ['before' => $previous['fields'][$name], 'after' => $value];
                }
                foreach ($incoming['counters'] as $name => $value) {
                    if (array_key_exists($name, $previous['counters']) && $previous['counters'][$name] !== $value) $counterChanges[$name] = ['before' => $previous['counters'][$name], 'after' => $value, 'delta' => $value - $previous['counters'][$name]];
                }
                $merged = ['id' => $incoming['id'], 'fields' => [...$previous['fields'], ...$incoming['fields']], 'counters' => [...$previous['counters'], ...$incoming['counters']]];
                if ($notify && $changes !== []) $events += $this->event($scope, $page, 'item.updated', $row['item_id'], ['id' => $incoming['id'], 'changes' => $changes]);
                if ($notify && $counterChanges !== []) $events += $this->event($scope, $page, 'counters.changed', $row['item_id'], ['id' => $incoming['id'], 'changes' => $counterChanges]);
            }
            $this->execute('INSERT INTO monitor_items(scope_key,item_id,payload) VALUES(?,?,?) ON CONFLICT(scope_key,item_id) DO UPDATE SET payload=excluded.payload', [$key, $row['item_id'], self::json($merged)]);
        }
        if ($page->complete) {
            $missing = $this->database->prepare('SELECT item_id FROM monitor_items WHERE scope_key=? AND NOT EXISTS(SELECT 1 FROM monitor_pending p WHERE p.scope_key=monitor_items.scope_key AND p.snapshot_id=? AND p.item_id=monitor_items.item_id)');
            $missing->execute([$key, $page->snapshotId]);
            foreach ($missing->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                if ($notify) $events += $this->event($scope, $page, 'item.removed', $id, ['id' => $id]);
                $this->execute('DELETE FROM monitor_items WHERE scope_key=? AND item_id=?', [$key, $id]);
            }
        }
        return $events;
    }

    /** @param array<string,mixed> $payload */
    private function event(ResourceScope $scope, SnapshotPage $page, string $type, string $itemId, array $payload): int
    {
        $eventKey = hash('sha256', self::json([$scope->key(), $page->snapshotId, $type, $itemId]));
        return $this->execute('INSERT INTO monitor_events(scope_key,event_key,type,item_id,payload,created_at) VALUES(?,?,?,?,?,?) ON CONFLICT(event_key) DO NOTHING', [$scope->key(), $eventKey, $type, $itemId, self::json(['scope' => $scope->toArray(), 'snapshot_id' => $page->snapshotId, ...$payload]), time()]);
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function transaction(callable $operation): mixed
    {
        // Serialize competing writers before reading the expected page cursor.
        $this->database->exec('BEGIN IMMEDIATE');
        try { $result = $operation(); $this->database->exec('COMMIT'); return $result; }
        catch (\Throwable $error) { try { $this->database->exec('ROLLBACK'); } catch (\PDOException) { } throw $error; }
    }
    /**
     * @param list<mixed> $arguments
     * @return array<string,mixed>|null
     */
    private function one(string $sql, array $arguments): ?array
    {
        $statement = $this->database->prepare($sql); $statement->execute($arguments); $row = $statement->fetch(\PDO::FETCH_ASSOC); return $row === false ? null : $row;
    }
    /** @param list<mixed> $arguments */
    private function execute(string $sql, array $arguments): int
    {
        $statement = $this->database->prepare($sql); $statement->execute($arguments); return $statement->rowCount();
    }
    private static function cursorKey(?string $cursor): string { return hash('sha256', self::json($cursor)); }
    private static function json(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
    /** @return array<string,mixed> */
    private static function decode(string $value): array { return json_decode($value, true, 64, JSON_THROW_ON_ERROR); }
    private static function limit(int $limit): void { if ($limit < 1 || $limit > 500) throw new \InvalidArgumentException('Monitor pagination limit must be between 1 and 500'); }
    public function __debugInfo(): array { return ['storage' => 'private_sqlite', 'network' => false]; }
}
