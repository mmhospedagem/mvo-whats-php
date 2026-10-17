<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Store\{KeyValueStore,RedisStore,MongoStore,RemoteStoreConflict,RemoteCommitUnknown,RedisConnection};

final class RemoteStoreTest extends TestCase
{
    private array $redis=[];
    private array $mongo=[];
    private array $commands=[];
    private bool $loseAck=false;
    private bool $corruptRead=false;
    private function redisCommand(array $a): mixed
    {
        $this->commands[]=$a;
        if($a[0]==='GET') return $this->corruptRead ? 'broken' : ($this->redis[$a[1]] ?? null);
        if($a[0]==='EVAL') { self::assertSame('1',$a[2]);self::assertSame(1,substr_count($a[1],"redis.call('SET'"));$old=$this->redis[$a[3]] ?? '';$version=$old==='' ? '' : explode("\n",$old,2)[0];if($version!==$a[4]) return 0;$this->redis[$a[3]]=$a[5]."\n".$a[6];if($this->loseAck) throw new \RuntimeException('lost ack');return 1; }
        throw new \LogicException('Unexpected Redis command');
    }
    private function mongoCommand(array $a): array
    {
        $this->commands[]=$a;
        if(isset($a['find'])) { $doc=$this->mongo[$a['filter']['_id']] ?? null;return ['ok'=>1,'cursor'=>['firstBatch'=>$doc===null ? [] : [$doc]]]; }
        $key=$a['query']['_id'];$old=$this->mongo[$key] ?? null;$version=$old['version'] ?? '';if($version!==$a['query']['version']) return $a['upsert'] ? ['ok'=>0,'code'=>11000] : ['ok'=>1,'value'=>null];
        $this->mongo[$key]=['_id'=>$key]+$a['update']['$set'];if($this->loseAck) throw new \RuntimeException('lost ack');self::assertSame(['w'=>'majority','j'=>true,'wtimeout'=>10000],$a['writeConcern']);return ['ok'=>1,'value'=>$this->mongo[$key]];
    }
    private function store(string $type,string $session='default',int $limit=1048576): KeyValueStore
    {
        return $type==='redis' ? new RedisStore(sessionId:$session,maxBytes:$limit,command:$this->redisCommand(...)) : new MongoStore(sessionId:$session,maxBytes:$limit,command:$this->mongoCommand(...));
    }
    public static function backends(): iterable { yield ['redis'];yield ['mongo']; }
    #[DataProvider('backends')]
    public function testBinaryStatePersistsAndNamespacesSessionsAreIsolated(string $type): void
    {
        $a=$this->store($type);$a->transaction(function(KeyValueStore $s):void { $s->set('ratchet',"key\0", "\xff\0\x01");$s->set('outbox','MSG1','cipher'); });$b=$this->store($type);self::assertSame("\xff\0\x01",$b->get('ratchet',"key\0"));self::assertSame('cipher',$b->get('outbox','MSG1'));self::assertNull($b->get('other',"key\0"));self::assertNull($this->store($type,'other')->get('ratchet',"key\0"));$a->close();$a->close();self::assertSame('cipher',$b->get('outbox','MSG1'));
    }
    #[DataProvider('backends')]
    public function testRollbackDoesNotCommitPartialCryptoState(string $type): void
    {
        $s=$this->store($type);$s->set('ratchet','key','before');try { $s->transaction(function(KeyValueStore $s):void { $s->set('ratchet','key','after');$s->set('outbox','MSG1','cipher');throw new \RuntimeException('abort'); });self::fail('Expected abort'); }catch(\RuntimeException $e) { self::assertSame('abort',$e->getMessage()); }self::assertSame('before',$s->get('ratchet','key'));self::assertNull($s->get('outbox','MSG1'));
    }
    #[DataProvider('backends')]
    public function testConcurrentSnapshotRejectsEntireWriteWithoutRetry(string $type): void
    {
        $s=$this->store($type);$other=$this->store($type);$s->set('ratchet','key','before');try { $s->transaction(function(KeyValueStore $s)use($other):void { $s->set('ratchet','key','unsafe');$s->set('outbox','MSG1','cipher');$other->set('ratchet','key','concurrent'); });self::fail('Expected conflict'); }catch(RemoteStoreConflict $e) { self::assertStringContainsString('Concurrent',$e->getMessage()); }self::assertSame('concurrent',$s->get('ratchet','key'));self::assertNull($s->get('outbox','MSG1'));
    }
    #[DataProvider('backends')]
    public function testLostCommitAckPoisonsInstanceAndReopenSeesAtomicOutcome(string $type): void
    {
        $s=$this->store($type);$this->loseAck=true;try { $s->transaction(function(KeyValueStore $s):void { $s->set('ratchet','key','next');$s->set('outbox','MSG1','cipher'); });self::fail('Expected unknown'); }catch(RemoteCommitUnknown $e) { self::assertStringContainsString('acknowledgement',$e->getMessage()); }$this->loseAck=false;
        try { $s->set('ratchet','key','unsafe');self::fail('Poisoned store reused'); }catch(RemoteCommitUnknown $e) { self::assertStringContainsString('reopen',$e->getMessage()); }
        $new=$this->store($type);self::assertSame('next',$new->get('ratchet','key'));self::assertSame('cipher',$new->get('outbox','MSG1'));
    }
    #[DataProvider('backends')]
    public function testPageOrderUsesBinaryKeysAndResumeCursor(string $type): void
    {
        $s=$this->store($type);$s->transaction(function(KeyValueStore $s):void { foreach(['b','A','a',"a\0",'10','2',"\xff"] as $k) $s->set('n',$k,$k); });$first=$s->entries('n',3);self::assertSame([10,2,'A'],array_keys($first));$second=$s->entries('n',3,'A');self::assertSame(['a',"a\0",'b'],array_keys($second));self::assertSame(["\xff"=>"\xff"],$s->entries('n',3,'b'));$s->delete('n','A');self::assertNull($s->get('n','A'));
    }
    #[DataProvider('backends')]
    public function testSnapshotLimitFailsBeforeRemoteCommit(string $type): void
    {
        $s=$this->store($type,limit:1024);$s->set('n','small','ok');try { $s->transaction(function(KeyValueStore $s):void { $s->set('n','big',str_repeat('x',900)); });self::fail('Expected bound'); }catch(\LengthException $e) { self::assertStringContainsString('snapshot',$e->getMessage()); }self::assertNull($s->get('n','big'));self::assertSame('ok',$s->get('n','small'));
    }
    #[DataProvider('backends')]
    public function testCloseAndNestedTransactionsAreRejected(string $type): void
    {
        $s=$this->store($type);try { $s->transaction(function(KeyValueStore $s):void {$s->transaction(static fn():null=>null);});self::fail('Expected nested rejection'); }catch(\LogicException $e) { self::assertStringContainsString('Nested',$e->getMessage()); }$s->close();$this->expectException(\LogicException::class);$s->get('n','k');
    }
    public function testRedisMalformedReadIsRejected(): void { $this->corruptRead=true;$this->expectException(\UnexpectedValueException::class);$this->store('redis')->get('n','k'); }
    public function testMongoMissingExtensionIsExplicit(): void { if(extension_loaded('mongodb')) self::markTestSkipped('MongoDB installed');$this->expectException(\RuntimeException::class);$this->expectExceptionMessage('ext-mongodb');new MongoStore(); }
    public function testRedisRespSocketHandlesBinaryBulkAndAuth(): void
    {
        $process=proc_open([PHP_BINARY,__DIR__.'/RemoteStoreServer.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,null,['bypass_shell'=>true,'create_new_console'=>false]);self::assertIsResource($process);$port=trim(fgets($pipes[1]));self::assertMatchesRegularExpression('/^[0-9]+$/D',$port);
        try { $conn=new RedisConnection('redis://user:secret@127.0.0.1:'.$port.'/2');$bytes="\0\xff\r\nvalue";self::assertSame($bytes,$conn->command(['ECHO',$bytes]));self::assertSame(['hello',null,42],$conn->command(['ARRAY']));$conn->close(); }
        finally { foreach($pipes as $pipe) fclose($pipe);proc_close($process); }
    }
}
