<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Auth\CredentialStore;
use MVO\Mcp\ClientRuntime;
use MVO\Store\{SqliteStore,PdoStore,RedisStore,MongoStore,RemoteStoreConflict,RemoteCommitUnknown};

final class StoreBrandMigrationTest extends TestCase
{
    private array $paths=[];private array $directories=[];private array $redis=[];private array $mongo=[];private bool $loseMigrationAck=false;private bool $raceMigration=false;private int $migrations=0;private array $mongoWrites=[];
    protected function tearDown():void
    {
        gc_collect_cycles();foreach($this->paths as $path)foreach([$path,$path.'-wal',$path.'-shm'] as $file)if(is_file($file))unlink($file);
        foreach($this->directories as $directory){foreach(glob($directory.'/*/*')?:[] as $file)unlink($file);foreach(glob($directory.'/*')?:[] as $child)rmdir($child);rmdir($directory);}
    }
    private function path():string {$path=tempnam(sys_get_temp_dir(),'mvo-migration-');$this->paths[]=$path;return $path;}
    private static function table(\PDO $db,string $table,string $column):void {$db->exec('CREATE TABLE '.$table.' (session TEXT NOT NULL,namespace TEXT NOT NULL,'.$column.' TEXT NOT NULL,value BLOB NOT NULL,PRIMARY KEY(session,namespace,'.$column.'))');}
    private static function row(\PDO $db,string $table,string $column,array $row):void {$q=$db->prepare('INSERT INTO '.$table.'(session,namespace,'.$column.',value) VALUES(?,?,?,?)');foreach($row as $i=>$value)$q->bindValue($i+1,$value,$i===3?\PDO::PARAM_LOB:\PDO::PARAM_STR);$q->execute();}
    private static function rows(\PDO $db,string $table,string $column):array {return $db->query('SELECT session,namespace,'.$column.',value FROM '.$table.' ORDER BY session,namespace,'.$column)->fetchAll(\PDO::FETCH_NUM);}
    private static function tables(\PDO $db):array {return $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);}
    public static function sqlStores():iterable {yield 'credential/sqlite'=>['key'];yield 'injected PDO'=>['k'];}
    #[DataProvider('sqlStores')]
    public function testLegacySqlRenamePreservesEverySessionAndBinaryRecordAndIsIdempotent(string $column):void
    {
        $path=$this->path();$db=new \PDO('sqlite:'.$path);self::table($db,'zapo_kv',$column);$records=[['default','auth','credentials',"{\"binary\":\"AA==\"}\0\xff"],['default','signal.sessions','222:1@lid',"ratchet\0\xff\xfe"],['other','application.outbox','MESSAGE',random_bytes(300)],['default',"namespace\0tail",'key',"\0\0\xff"]];foreach($records as $row)self::row($db,'zapo_kv',$column,$row);$before=self::rows($db,'zapo_kv',$column);$db->exec('CREATE INDEX legacy_namespace_idx ON zapo_kv(namespace)');
        $store=$column==='key'?new SqliteStore($path):new PdoStore($db);self::assertSame($before,self::rows($db,'mvo_kv',$column));self::assertSame(['mvo_kv'],self::tables($db));self::assertSame($records[1][3],$store->get('signal.sessions','222:1@lid'));$store->close();$other=$column==='key'?new SqliteStore($path,'other'):new PdoStore($db,'other');self::assertSame($records[2][3],$other->get('application.outbox','MESSAGE'));$other->close();$again=$column==='key'?new SqliteStore($path):new PdoStore($db);self::assertSame($before,self::rows($db,'mvo_kv',$column));$again->close();self::assertSame(1,(int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='legacy_namespace_idx'")->fetchColumn());unset($db);
    }
    #[DataProvider('sqlStores')]
    public function testBothSqlTablesMergeIdenticalRowsAndDisjointSessions(string $column):void
    {
        $path=$this->path();$db=new \PDO('sqlite:'.$path);self::table($db,'zapo_kv',$column);self::table($db,'mvo_kv',$column);$same=['default','signal.identities','peer',random_bytes(32)];foreach(['zapo_kv','mvo_kv'] as $table)self::row($db,$table,$column,$same);self::row($db,'zapo_kv',$column,['old','keys','one',"\0\xff"]);self::row($db,'mvo_kv',$column,['new','keys','two',"\xff\0"]);$store=$column==='key'?new SqliteStore($path):new PdoStore($db);self::assertCount(3,self::rows($db,'mvo_kv',$column));self::assertSame(['mvo_kv'],self::tables($db));self::assertSame($same[3],$store->get('signal.identities','peer'));$store->close();unset($db);
    }
    #[DataProvider('sqlStores')]
    public function testSqlConflictRollsBackEarlierCopiesAndNeverChoosesOneRatchet(string $column):void
    {
        $path=$this->path();$db=new \PDO('sqlite:'.$path);foreach(['zapo_kv','mvo_kv'] as $table)self::table($db,$table,$column);self::row($db,'zapo_kv',$column,['default','a','copy-first','durable']);self::row($db,'zapo_kv',$column,['default','signal.sessions','peer','old-ratchet']);self::row($db,'mvo_kv',$column,['default','signal.sessions','peer','new-ratchet']);$old=self::rows($db,'zapo_kv',$column);$new=self::rows($db,'mvo_kv',$column);
        try {
            try{$column==='key'?new SqliteStore($path):new PdoStore($db);self::fail('Conflicting cryptographic state was silently chosen');}
            catch(RemoteStoreConflict $error){$message=$error->getMessage();unset($error);self::assertStringContainsString('conflicting',$message);}
            self::assertSame($old,self::rows($db,'zapo_kv',$column));self::assertSame($new,self::rows($db,'mvo_kv',$column));self::assertFalse($db->inTransaction());
        } finally { unset($db);gc_collect_cycles(); }
    }
    public function testCredentialMigrationRequiresExclusiveLockButMvoStatusReadsDoNot():void
    {
        $directory=sys_get_temp_dir().'/mvo-credential-migrate-'.bin2hex(random_bytes(8));mkdir($directory);$this->directories[]=$directory;$credentials=CredentialStore::fresh();$credentials['testBytes']="\0\xff\xfe";$writer=new CredentialStore($directory);$writer->save($credentials);$writer->close();$path=$directory.'/default/state.sqlite';$db=new \PDO('sqlite:'.$path);$db->exec('ALTER TABLE mvo_kv RENAME TO zapo_kv');$before=self::rows($db,'zapo_kv','key');unset($db);
        $owner=new CredentialStore($directory);$reader=new CredentialStore($directory);$status=null;
        try {
            $owner->acquire();try{$reader->load();self::fail('Migrated underneath an active legacy worker');}catch(\RuntimeException $error){$message=$error->getMessage();unset($error);self::assertStringContainsString('already in use',$message);}
            self::assertFalse($reader->ownsLock());$db=new \PDO('sqlite:'.$path);self::assertSame(['zapo_kv'],self::tables($db));self::assertSame($before,self::rows($db,'zapo_kv','key'));unset($db);$owner->release();self::assertSame($credentials,$reader->load());self::assertFalse($reader->ownsLock());$owner->acquire();$status=new CredentialStore($directory);self::assertSame($credentials,$status->load());self::assertFalse($status->ownsLock());
        } finally { $owner->close();$reader->close();$status?->close();unset($db,$writer,$owner,$reader,$status);gc_collect_cycles(); }
    }
    public function testCredentialMigrationFailureReleasesTemporaryLock():void
    {
        $directory=sys_get_temp_dir().'/mvo-credential-conflict-'.bin2hex(random_bytes(8));mkdir($directory);mkdir($directory.'/default');$this->directories[]=$directory;$db=new \PDO('sqlite:'.$directory.'/default/state.sqlite');foreach(['zapo_kv','mvo_kv'] as $table)self::table($db,$table,'key');self::row($db,'zapo_kv','key',['default','auth','credentials','old']);self::row($db,'mvo_kv','key',['default','auth','credentials','new']);unset($db);$reader=new CredentialStore($directory);$owner=null;
        try {
            try{$reader->load();self::fail();}catch(RemoteStoreConflict $error){unset($error);}
            self::assertFalse($reader->ownsLock());$owner=new CredentialStore($directory);$owner->acquire();self::assertTrue($owner->ownsLock());
        } finally { $owner?->close();$reader->close();unset($owner,$reader);gc_collect_cycles(); }
    }
    private static function payload(string $value):string {return json_encode([base64_encode('signal.sessions').':'.base64_encode('peer')=>base64_encode($value)],JSON_THROW_ON_ERROR);}
    private static function redisKey(string $prefix):string {return $prefix.':{'.hash('sha256','default').'}:snapshot';}
    private function redisCommand(array $args):mixed
    {
        if($args[0]==='GET')return $this->redis[$args[1]]??null;if($args[0]!=='EVAL')throw new \LogicException('Unknown test Redis command');
        if($args[2]==='2'){
            self::assertStringContainsString("redis.call('RENAME'",$args[1]);++$this->migrations;if($this->raceMigration){$this->raceMigration=false;$this->redis[$args[3]]=str_repeat('b',32)."\n".self::payload('concurrent-ratchet');return 0;}
            $old=$this->redis[$args[3]]??null;$current=$this->redis[$args[4]]??null;if($old!==$args[5] || ($current??'')!==$args[6])return 0;if($current!==null && $current!==$old)return -1;$this->redis[$args[4]]=$old;$this->redis[$args[3]]=$args[7];if($this->loseMigrationAck){$this->loseMigrationAck=false;throw new \RuntimeException('Lost migration reply');}return 1;
        }
        $current=$this->redis[$args[3]]??'';$version=$current===''?'':explode("\n",$current,2)[0];if($version!==$args[4])return 0;$this->redis[$args[3]]=$args[5]."\n".$args[6];return 1;
    }
    public function testRedisMigratesSnapshotAndBlocksOldWritersWithATombstone():void
    {
        $bytes="ratchet\0\xff";$legacy=str_repeat('a',32)."\n".self::payload($bytes);$this->redis[self::redisKey('zapo')]=$legacy;$store=new RedisStore(command:$this->redisCommand(...));self::assertSame($bytes,$store->get('signal.sessions','peer'));self::assertSame($legacy,$this->redis[self::redisKey('mvo')]);self::assertStringStartsWith('mvo:storage:migrated:',$this->redis[self::redisKey('zapo')]);self::assertSame(1,$this->migrations);$store->set('outbox','MESSAGE',"cipher\0");self::assertSame($bytes,(new RedisStore(command:$this->redisCommand(...)))->get('signal.sessions','peer'));self::assertSame("cipher\0",$store->get('outbox','MESSAGE'));try{(new RedisStore(command:$this->redisCommand(...),prefix:'zapo'))->get('signal.sessions','peer');self::fail('Old prefix remained writable');}catch(\UnexpectedValueException){}$store->close();
    }
    public function testRedisConflictsAndMissingMigrationDestinationNeverBecomeEmptySessions():void
    {
        $this->redis[self::redisKey('zapo')]=str_repeat('a',32)."\n".self::payload('old');$this->redis[self::redisKey('mvo')]=str_repeat('b',32)."\n".self::payload('new');$before=$this->redis;try{(new RedisStore(command:$this->redisCommand(...)))->get('signal.sessions','peer');self::fail();}catch(RemoteStoreConflict){}self::assertSame($before,$this->redis);self::assertSame(0,$this->migrations);$this->redis=[self::redisKey('zapo')=>'mvo:storage:migrated:'.self::redisKey('mvo')];$this->expectException(RemoteStoreConflict::class);(new RedisStore(command:$this->redisCommand(...)))->get('signal.sessions','peer');
    }
    public function testRedisMigrationReconcilesLostAckAndConcurrentLegacyCas():void
    {
        $legacy=str_repeat('a',32)."\n".self::payload('old');$this->redis[self::redisKey('zapo')]=$legacy;$this->loseMigrationAck=true;try{(new RedisStore(command:$this->redisCommand(...)))->get('signal.sessions','peer');self::fail();}catch(RemoteCommitUnknown){}self::assertSame('old',(new RedisStore(command:$this->redisCommand(...)))->get('signal.sessions','peer'));$this->redis=[self::redisKey('zapo')=>$legacy];$this->raceMigration=true;self::assertSame('concurrent-ratchet',(new RedisStore(command:$this->redisCommand(...)))->get('signal.sessions','peer'));self::assertSame(3,$this->migrations);
    }
    private function mongoCommand(array $command,string $database):array
    {
        if(isset($command['find'])){$doc=$this->mongo[$database][$command['filter']['_id']]??null;return ['ok'=>1,'cursor'=>['firstBatch'=>$doc===null?[]:[$doc]]];}
        $this->mongoWrites[]=$database;$key=$command['query']['_id'];$old=$this->mongo[$database][$key]??null;if(($old['version']??'')!==$command['query']['version'])return ['ok'=>0,'code'=>11000];$doc=['_id'=>$key]+$command['update']['$set'];$this->mongo[$database][$key]=$doc;return ['ok'=>1,'value'=>$doc];
    }
    public function testMongoDefaultsToMvoAndFallsBackToExistingLegacyDatabaseWithoutSplittingRatchets():void
    {
        $key=hash('sha256','default');$this->mongo['zapo'][$key]=['_id'=>$key,'version'=>str_repeat('a',32),'payload'=>self::payload("legacy\0\xff")];$store=new MongoStore(command:$this->mongoCommand(...));self::assertSame("legacy\0\xff",$store->get('signal.sessions','peer'));$store->set('outbox','MESSAGE','cipher');self::assertSame(['zapo'],$this->mongoWrites);self::assertArrayNotHasKey('mvo',$this->mongo);self::assertSame('cipher',(new MongoStore(command:$this->mongoCommand(...)))->get('outbox','MESSAGE'));$fresh=new MongoStore(sessionId:'new',command:$this->mongoCommand(...));$fresh->set('auth','credentials','new-session');self::assertSame('mvo',$this->mongoWrites[1]);self::assertSame('new-session',$fresh->get('auth','credentials'));
    }
    public function testMongoConflictingDatabasesFailBeforeAnyWrite():void
    {
        $key=hash('sha256','default');foreach(['mvo','zapo'] as $database)$this->mongo[$database][$key]=['_id'=>$key,'version'=>str_repeat('a',32),'payload'=>self::payload('same')];$before=$this->mongo;try{(new MongoStore(command:$this->mongoCommand(...)))->set('signal.sessions','peer','unsafe');self::fail('Guessed which database was authoritative');}catch(RemoteStoreConflict){}self::assertSame($before,$this->mongo);self::assertSame([],$this->mongoWrites);
    }
    public function testMcpAdvertisesOnlyMvoResourcesButCanReadLegacyBookmarks():void
    {
        $runtime=new ClientRuntime(static fn()=>throw new \LogicException('No live client'));self::assertSame(['mvo://sessions','mvo://schema/message','mvo://events'],array_keys($runtime->resources()));self::assertSame($runtime->readResource('mvo://sessions'),$runtime->readResource('zapo://sessions'));self::assertSame($runtime->readResource('mvo://events'),$runtime->readResource('zapo://events'));$response=$runtime->server()->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"fixture","version":"1"}}}');self::assertSame('mvo-php',$response['result']['serverInfo']['name']);
    }
}
