<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Store\PdoStore;
use MVO\Signal\SignalStore;

final class PdoStoreTest extends TestCase
{
    public function testBinaryValuesOrderedPagesAndSessionIsolation():void {
        $db=new \PDO('sqlite::memory:'); $a=new PdoStore($db,'a'); $b=new PdoStore($db,'b');
        foreach (['b','a','c'] as $key) $a->set('keys',$key,"\0\xff".$key);
        $b->set('keys','a','other');
        self::assertSame(['a'=>"\0\xffa",'b'=>"\0\xffb"],$a->entries('keys',2));
        self::assertSame(['c'=>"\0\xffc"],$a->entries('keys',2,'b'));
        self::assertSame('other',$b->get('keys','a'));
        $a->delete('keys','a'); self::assertNull($a->get('keys','a'));
        self::assertSame('other',$b->get('keys','a'));
    }
    public function testSignalNestedRollbackRemainsAtomicWithInjectedBackend():void {
        $backend=new PdoStore(new \PDO('sqlite::memory:'));
        $signal=new SignalStore(':memory:',backend:$backend);
        try {
            $signal->transaction(function() use($signal):void {
                $signal->set('outbox','one','ciphertext');
                try { $signal->transaction(function() use($signal):void { $signal->set('ratchet','two','state'); throw new \RuntimeException('fault'); }); } catch (\RuntimeException) {}
            });
            self::fail('Swallowed nested error committed');
        } catch (\RuntimeException $e) { self::assertStringContainsString('nested failure',$e->getMessage()); }
        self::assertNull($signal->get('outbox','one')); self::assertNull($signal->get('ratchet','two'));
        $signal->transaction(fn()=>$signal->set('outbox','ok','saved'));
        self::assertSame(['ok'=>'saved'],$signal->entries('outbox'));
    }
    public function testUnsafeTableCannotBeInterpolated():void {
        $this->expectException(\InvalidArgumentException::class); new PdoStore(new \PDO('sqlite::memory:'),table:'t; DROP TABLE x');
    }
}
