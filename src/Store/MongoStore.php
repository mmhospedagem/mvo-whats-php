<?php
declare(strict_types=1);
namespace MVO\Store;

/** One BSON document per session; findAndModify CAS atomically replaces ratchets plus outbox. */
final class MongoStore extends RemoteSnapshotStore
{
    private const LEGACY_DATABASE='zapo';
    /** @var \Closure(array<string,mixed>,string):array<string,mixed> */
    private \Closure $command;
    private ?MongoDriverConnection $connection=null;
    private string $documentId;
    private readonly string $database;
    private ?string $legacyDatabase=null;
    private ?string $selectedDatabase=null;
    /** @param callable(array<string,mixed>,string=):array<string,mixed>|null $command An optional second database argument enables cross-database detection. One-argument adapters are already database-bound. */
    public function __construct(string $uri='mongodb://127.0.0.1:27017',string $database='mvo',string $collection='sessions',string $sessionId='default',int $maxBytes=8388608,?callable $command=null)
    {
        parent::__construct($sessionId,$maxBytes);if($maxBytes>15000000 || !preg_match('/^[A-Za-z0-9_-]+$/D',$database) || !preg_match('/^[A-Za-z0-9_.-]+$/D',$collection)) throw new \InvalidArgumentException('Invalid MongoDB namespace/snapshot limit');$this->documentId=hash('sha256',$sessionId);$this->collection=$collection;$this->database=$database;
        if($command===null) { $this->connection=new MongoDriverConnection($uri,$database);$this->command=$this->connection->command(...);$databaseAware=true; }else {$this->command=\Closure::fromCallable($command);$reflection=new \ReflectionFunction($this->command);$databaseAware=$reflection->getNumberOfParameters()>=2 || $reflection->isVariadic();}
        if($database==='mvo' && $databaseAware)$this->legacyDatabase=self::LEGACY_DATABASE;
    }
    private string $collection;
    /** @return array{version:string,data:array<string,string>} */
    protected function readSnapshot(): array
    {
        $doc=$this->readDocument($this->database);$legacy=$this->legacyDatabase===null?null:$this->readDocument($this->legacyDatabase);
        if($doc!==null && $legacy!==null)throw new RemoteStoreConflict('Legacy and MVO MongoDB databases both contain this session; reconcile explicitly before continuing');
        $this->selectedDatabase=$legacy!==null?$this->legacyDatabase:$this->database;$doc??=$legacy;if($doc===null)return ['version'=>'','data'=>[]];return ['version'=>$doc['version'],'data'=>$this->decode($doc['payload'])];
    }
    /** @return array{version:string,payload:string}|null */
    private function readDocument(string $database):?array
    {
        $r=($this->command)(['find'=>$this->collection,'filter'=>['_id'=>$this->documentId],'limit'=>1,'singleBatch'=>true,'readConcern'=>['level'=>'majority']],$database);self::assertOk($r);$rows=$r['cursor']['firstBatch']??null;if(!is_array($rows) || count($rows)>1)throw new \UnexpectedValueException('Invalid MongoDB snapshot cursor');if($rows===[])return null;$doc=$rows[0];if(!is_array($doc) || !is_string($doc['version']??null) || !preg_match('/^[a-f0-9]{32}$/D',$doc['version']) || !is_string($doc['payload']??null))throw new \UnexpectedValueException('Invalid MongoDB session document');return ['version'=>$doc['version'],'payload'=>$doc['payload']];
    }
    protected function commitSnapshot(string $expectedVersion,string $nextVersion,string $payload): bool
    {
        $database=$this->selectedDatabase??$this->database;if($this->legacyDatabase!==null && $this->readDocument($database===$this->database?$this->legacyDatabase:$this->database)!==null)throw new RemoteStoreConflict('Another MongoDB database contains this session; refusing a split state');
        $request=['findAndModify'=>$this->collection,'query'=>['_id'=>$this->documentId,'version'=>$expectedVersion],'update'=>['$set'=>['version'=>$nextVersion,'payload'=>$payload]],'upsert'=>$expectedVersion==='','new'=>true,'writeConcern'=>['w'=>'majority','j'=>true,'wtimeout'=>10000]];
        try { $r=($this->command)($request,$database); }
        catch(\Throwable $e) { if($e->getCode()===11000) return false;throw new RemoteCommitUnknown('MongoDB commit acknowledgement unavailable; inspect persisted outbox before retrying',0,$e); }
        if(isset($r['writeConcernError'])) throw new RemoteCommitUnknown('MongoDB majority commit was not acknowledged');
        if(($r['ok'] ?? 0)!=1) { if(($r['code'] ?? null)===11000) return false;throw new RemoteCommitUnknown('MongoDB commit response failed'); }
        if(($r['value'] ?? null)===null) return false;
        if(!is_array($r['value']) || ($r['value']['version'] ?? null)!==$nextVersion || ($r['value']['payload'] ?? null)!==$payload) throw new RemoteCommitUnknown('MongoDB returned an unexpected committed document');return true;
    }
    /** @param array<string,mixed> $response */
    private static function assertOk(array $response): void { if(($response['ok'] ?? 0)!=1) throw new \RuntimeException('MongoDB read command failed'); }
    protected function disconnect(): void { $this->connection?->close(); }
}
