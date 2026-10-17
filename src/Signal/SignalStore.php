<?php
declare(strict_types=1);

namespace MVO\Signal;

use MVO\Crypto\Crypto;
use MVO\Store\{KeyValueStore,SqliteStore};

/**
 * Durable per-device sessions, trusted identities and one-time prekeys.
 * Authentication and all ratchet changes commit in one SQLite transaction.
 * @phpstan-import-type Session from SignalState
 * @phpstan-import-type KeyPair from SignalState
 */
final class SignalStore
{
    private ?KeyValueStore $db;
    private int $transactionDepth = 0;
    private bool $rollbackOnly = false;

    public function __construct(string $path, string $sessionId = 'default', ?KeyValueStore $backend = null)
    {
        $this->db = $backend ?? new SqliteStore($path,$sessionId);
    }

    public function close(): void
    {
        if ($this->transactionDepth > 0) throw new \LogicException('Cannot close Signal store during a transaction');
        $this->db?->close();
        $this->db = null;
    }

    private function database(): KeyValueStore { return $this->db ?? throw new \LogicException('Signal store is closed'); }

    /** Binary-safe application metadata, isolated from cryptographic records. */
    public function get(string $namespace,string $key): ?string { return $this->database()->get('application.'.$namespace,$key); }
    public function set(string $namespace,string $key,string $value): void { $this->database()->set('application.'.$namespace,$key,$value); }
    public function delete(string $namespace,string $key): void { $this->database()->delete('application.'.$namespace,$key); }
    /** @return array<string,string> */
    public function entries(string $namespace,int $limit=1000,string $after=''):array { return $this->database()->entries('application.'.$namespace,$limit,$after); }

    /**
     * @template T
     * @param callable(self):T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed
    {
        if ($this->transactionDepth > 0) {
            ++$this->transactionDepth;
            try { return $operation($this); }
            catch (\Throwable $error) { $this->rollbackOnly = true; throw $error; }
            finally { --$this->transactionDepth; }
        }
        $this->transactionDepth = 1;
        $this->rollbackOnly = false;
        try {
            return $this->database()->transaction(function() use ($operation) {
                $result = $operation($this);
                if ($this->rollbackOnly) throw new \RuntimeException('Signal transaction aborted by a nested failure');
                return $result;
            });
        } finally { $this->transactionDepth = 0; $this->rollbackOnly = false; }
    }

    /** @return Session|null */
    public function getSession(string $jid): ?array
    {
        $value = $this->database()->get('signal.sessions',$jid);
        if ($value === null) return null;
        $record = SignalState::unpack(json_decode($value,true,128,JSON_THROW_ON_ERROR));
        if (!is_array($record) || !isset($record['root'],$record['send'],$record['previous'])) {
            throw new \UnexpectedValueException('Invalid stored Signal session');
        }
        /** @var Session $record */
        return $record;
    }

    /** @param Session $session */
    public function putSession(string $jid,array $session): void
    {
        $this->database()->set('signal.sessions',$jid,json_encode(SignalState::pack($session),JSON_THROW_ON_ERROR));
    }

    public function getIdentity(string $jid): ?string { return $this->database()->get('signal.identities',$jid); }

    /** Trusted per-device identities used to reject conflicting linkage, never to copy ratchets.
     * @return array<int,string> */
    public function peerIdentities(string $jid):array
    {
        $peer=\MVO\Protocol\Jid::parse($jid)->canonical()->bare();$result=[];
        foreach(['signal.identities','signal.sessions']as$namespace){
            $after='';do{
                $page=$this->database()->entries($namespace,1000,$after);
                foreach($page as$key=>$value){
                    $after=(string)$key;$address=\MVO\Protocol\Jid::parse((string)$key)->canonical();if($address->bare()!==$peer)continue;
                    if($namespace==='signal.identities')$result[$address->device]=$value;
                    elseif(!isset($result[$address->device])){$session=$this->getSession((string)$key);if($session!==null)$result[$address->device]=$session['remoteIdentity'];}
                }
            }while(count($page)===1000);
        }
        return $result;
    }

    /** Trust on first authenticated use; silently replacing a contact's identity is forbidden. */
    public function trustIdentity(string $jid,string $identity): void
    {
        $identity = SignalState::publicKey($identity);
        $trusted = $this->getIdentity($jid);
        if ($trusted !== null && !hash_equals($trusted,$identity)) {
            throw new \UnexpectedValueException('Signal identity changed for '.$jid);
        }
        $this->database()->set('signal.identities',$jid,$identity);
    }

    /** @return KeyPair|null */
    public function getPreKey(int $id): ?array
    {
        self::validatePreKeyId($id);
        $value = $this->database()->get('signal.prekeys',(string)$id);
        if ($value === null) return null;
        $record = SignalState::unpack(json_decode($value,true,16,JSON_THROW_ON_ERROR));
        if (!is_array($record) || !is_string($record['private'] ?? null) || !is_string($record['public'] ?? null)) {
            throw new \UnexpectedValueException('Invalid stored Signal prekey');
        }
        return SignalState::normalizePair(['private'=>$record['private'],'public'=>$record['public']]);
    }

    /** @param KeyPair $pair */
    public function putPreKey(int $id,array $pair): void
    {
        self::validatePreKeyId($id);
        $pair = SignalState::normalizePair($pair);
        $this->transaction(function() use ($id,$pair): void {
            $existing = $this->getPreKey($id);
            if ($existing !== null && $existing !== $pair) throw new \LogicException('Cannot overwrite an allocated Signal prekey');
            $this->database()->set('signal.prekeys',(string)$id,json_encode(SignalState::pack($pair),JSON_THROW_ON_ERROR));
            $next = (int)($this->database()->get('signal.meta','nextPreKeyId') ?? '1');
            $this->database()->set('signal.meta','nextPreKeyId',(string)max($next,$id+1));
            $pending = $this->pendingIds();
            if (!in_array($id,$pending,true)) { $pending[] = $id; $this->writePendingIds($pending); }
        });
    }

    /**
     * Generates durable keys before exposing them for network upload.
     * @return list<array{id:int,private:string,public:string}>
     */
    public function generatePreKeys(int $count): array
    {
        if ($count < 1 || $count > 1000) throw new \InvalidArgumentException('Prekey count must be between 1 and 1000');
        return $this->transaction(function() use ($count): array {
            $next = (int)($this->database()->get('signal.meta','nextPreKeyId') ?? '1');
            if ($next > 0xffffff-$count+1) throw new \OverflowException('Signal prekey IDs exhausted');
            $result = [];
            for ($id=$next;$id<$next+$count;$id++) {
                $pair = Crypto::x25519KeyPair();
                $this->putPreKey($id,$pair);
                $result[] = ['id'=>$id,...$pair];
            }
            return $result;
        });
    }

    /** @return list<array{id:int,private:string,public:string}> */
    public function pendingPreKeys(): array
    {
        $result = [];
        foreach ($this->pendingIds() as $id) {
            $pair = $this->getPreKey($id);
            if ($pair !== null) $result[] = ['id'=>$id,'private'=>$pair['private'],'public'=>SignalState::rawPublicKey($pair['public'])];
        }
        return $result;
    }

    /**
     * Mark uploaded only after an acknowledged upload.
     * @param list<int> $ids
     */
    public function markPreKeysUploaded(array $ids): void
    {
        foreach ($ids as $id) self::validatePreKeyId($id);
        $this->transaction(fn()=> $this->writePendingIds(array_values(array_diff($this->pendingIds(),$ids))));
    }

    public function consumePreKey(int $id): void
    {
        self::validatePreKeyId($id);
        $this->transaction(function() use ($id): void {
            $this->database()->delete('signal.prekeys',(string)$id);
            $this->writePendingIds(array_values(array_diff($this->pendingIds(),[$id])));
        });
    }

    /** @return list<int> */
    private function pendingIds(): array
    {
        $value = $this->database()->get('signal.meta','pendingPreKeys');
        if ($value === null) return [];
        $ids = json_decode($value,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($ids) || !array_is_list($ids)) throw new \UnexpectedValueException('Invalid stored Signal prekey list');
        foreach ($ids as $id) {
            if (!is_int($id)) throw new \UnexpectedValueException('Invalid stored Signal prekey ID');
            self::validatePreKeyId($id);
        }
        return $ids;
    }

    /** @param list<int> $ids */
    private function writePendingIds(array $ids): void { $this->database()->set('signal.meta','pendingPreKeys',json_encode($ids,JSON_THROW_ON_ERROR)); }
    private static function validatePreKeyId(int $id): void
    {
        if ($id < 1 || $id > 0xffffff) throw new \InvalidArgumentException('Invalid Signal prekey ID');
    }
}
