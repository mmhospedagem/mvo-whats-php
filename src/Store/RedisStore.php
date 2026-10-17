<?php
declare(strict_types=1);
namespace MVO\Store;

final class RedisStore extends RemoteSnapshotStore
{
    private const LEGACY_PREFIX='zapo';
    private const MIGRATION_MARKER='mvo:storage:migrated:';
    /** Compare both snapshots and leave a tombstone so old clients cannot resume writing old ratchets. */
    private const MIGRATE="local old=redis.call('GET',KEYS[1]);local current=redis.call('GET',KEYS[2]);if old~=ARGV[1] or (current or '')~=ARGV[2] then return 0 end;if current and current~=old then return -1 end;if not current then redis.call('RENAME',KEYS[1],KEYS[2]) end;redis.call('SET',KEYS[1],ARGV[3]);return 1";
    /** One SET replaces both version and data; Lua execution itself does not roll back earlier writes. */
    private const CAS="local s=redis.call('GET',KEYS[1]);local v='';if s then local p=string.find(s,'\\n',1,true);if not p then return redis.error_reply('invalid snapshot') end;v=string.sub(s,1,p-1) end;if v~=ARGV[1] then return 0 end;redis.call('SET',KEYS[1],ARGV[2]..'\\n'..ARGV[3]);return 1";
    private string $dataKey;
    private ?string $legacyKey=null;
    /** @var \Closure(list<string>):mixed */
    private \Closure $command;
    private ?RedisConnection $connection=null;
    /** @param callable(list<string>):mixed|null $command Inject a RESP transport for testing or an existing Redis client. */
    public function __construct(string $url='redis://127.0.0.1:6379',string $sessionId='default',int $maxBytes=16777216,?callable $command=null,string $prefix='mvo')
    {
        parent::__construct($sessionId,$maxBytes);if($prefix==='' || preg_match('/[{}\s]/',$prefix)) throw new \InvalidArgumentException('Invalid Redis prefix');$suffix=':{'.hash('sha256',$sessionId).'}:snapshot';$this->dataKey=$prefix.$suffix;if($prefix==='mvo')$this->legacyKey=self::LEGACY_PREFIX.$suffix;
        if($command===null) { $this->connection=new RedisConnection($url,maxResponseBytes:$maxBytes+4096);$this->command=$this->connection->command(...); } else $this->command=\Closure::fromCallable($command);
    }
    /** @return array{version:string,data:array<string,string>} */
    protected function readSnapshot(): array
    {
        for($attempt=0;$attempt<3;$attempt++){
            $r=($this->command)(['GET',$this->dataKey]);$legacy=$this->legacyKey===null?null:($this->command)(['GET',$this->legacyKey]);
            if($legacy===null)return $this->snapshot($r);
            if($legacy===self::MIGRATION_MARKER.$this->dataKey){if($r===null)throw new RemoteStoreConflict('Migrated Redis destination is missing; refusing an empty session');return $this->snapshot($r);}
            $this->snapshot($legacy);if($r!==null){$this->snapshot($r);if($r!==$legacy)throw new RemoteStoreConflict('Legacy and MVO Redis storage contain conflicting state; no values were overwritten');}
            try{$migrated=($this->command)(['EVAL',self::MIGRATE,'2',$this->legacyKey,$this->dataKey,$legacy,$r??'',self::MIGRATION_MARKER.$this->dataKey]);}
            catch(\Throwable $error){throw new RemoteCommitUnknown('Redis migration acknowledgement unavailable; reopen and inspect both snapshots',0,$error);}
            if($migrated===1)return $this->snapshot($legacy);if($migrated===-1)throw new RemoteStoreConflict('Conflicting Redis storage migration');if($migrated!==0)throw new RemoteCommitUnknown('Invalid Redis migration acknowledgement');
        }
        throw new RemoteStoreConflict('Concurrent Redis storage migration; reopen before continuing');
    }
    /** @return array{version:string,data:array<string,string>} */
    private function snapshot(mixed $value):array
    {
        if($value===null)return ['version'=>'','data'=>[]];if(!is_string($value) || !str_contains($value,"\n"))throw new \UnexpectedValueException('Invalid Redis session snapshot response');[$version,$payload]=explode("\n",$value,2);if(!preg_match('/^[a-f0-9]{32}$/D',$version))throw new \UnexpectedValueException('Invalid Redis snapshot version');return ['version'=>$version,'data'=>$this->decode($payload)];
    }
    protected function commitSnapshot(string $expectedVersion,string $nextVersion,string $payload): bool
    {
        try { $r=($this->command)(['EVAL',self::CAS,'1',$this->dataKey,$expectedVersion,$nextVersion,$payload]); }
        catch(\Throwable $e) { throw new RemoteCommitUnknown('Redis commit acknowledgement unavailable; inspect persisted outbox before retrying',0,$e); }
        if($r!==0 && $r!==1) throw new RemoteCommitUnknown('Invalid Redis commit acknowledgement');return $r===1;
    }
    protected function disconnect(): void { $this->connection?->close(); }
}
