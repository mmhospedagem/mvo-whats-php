<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Message\ReceiptQueue;
use MVO\Protocol\{BinaryCodec,BinaryData,BinaryNode};
use MVO\Signal\SignalStore;
use MVO\Store\{KeyValueStore,SqliteStore};
use PHPUnit\Framework\TestCase;

final class ReceiptQueueTest extends TestCase
{
    private ReceiptQueueCountingStore $backend;
    private SignalStore $store;

    protected function setUp():void
    {
        $this->backend=new ReceiptQueueCountingStore();
        $this->store=new SignalStore(':memory:','test',$this->backend);
    }

    public function testFutureBacklogIsLoadedInPagesOnceAndAckBeyondFiveThousandIsRemoved():void
    {
        $this->seed(5070,time()+3600);
        $sends=0;$queue=new ReceiptQueue($this->store,static function()use(&$sends):void{++$sends;});
        $this->backend->reset();$queue->drainPending();
        self::assertSame(20,$this->backend->pages);
        self::assertSame(5070,$this->backend->rows);
        $this->backend->reset();
        for($i=0;$i<1000;++$i)$queue->drainPending();
        self::assertSame([0,0,0,0],$this->backend->counts());
        self::assertSame(0,$sends);
        self::assertTrue($queue->handleAck($this->ack(5069)));
        self::assertSame([1,0,1,0],$this->backend->counts());
        self::assertNull($this->store->get('receipt-queue',self::key(5069)));
        self::assertNotNull($this->store->get('receipt-queue',self::key(0)));
    }

    public function testAckKeepsExactToTypeAndParticipantWithoutNormalizingIdentities():void
    {
        $base=['id'=>'same','type'=>'read','to'=>'123@lid','participant'=>'456:2@lid'];
        foreach([$base,array_replace($base,['to'=>'123@s.whatsapp.net']),array_replace($base,['type'=>'read-self']),
            array_replace($base,['participant'=>'456:3@lid'])]as$i=>$attrs)$this->put((string)$i,new BinaryNode('receipt',$attrs),time()+3600);
        $queue=new ReceiptQueue($this->store,static function():void{});
        $queue->handleAck(new BinaryNode('ack',['class'=>'receipt','id'=>'same','type'=>'read','from'=>'123@lid','participant'=>'456:2@lid']));
        self::assertNull($this->store->get('receipt-queue','0'));
        foreach(['1','2','3']as$key)self::assertNotNull($this->store->get('receipt-queue',$key));
        $queue->handleAck(new BinaryNode('ack',['class'=>'receipt','id'=>'same','type'=>'read','participant'=>'456:2@lid']));
        self::assertNull($this->store->get('receipt-queue','1'));
        self::assertNotNull($this->store->get('receipt-queue','2'));
        self::assertNotNull($this->store->get('receipt-queue','3'));
    }

    public function testErrorAndUnrelatedAcksDoNotRemoveOrReadTheQueue():void
    {
        $this->seed(1,0);$queue=new ReceiptQueue($this->store,static function():void{});$this->backend->reset();
        self::assertFalse($queue->handleAck(new BinaryNode('ack',['class'=>'message'])));
        self::assertTrue($queue->handleAck(new BinaryNode('ack',array_replace($this->ack(0)->attrs,['error'=>'500']))));
        self::assertTrue($queue->handleAck(new BinaryNode('ack',$this->ack(0)->attrs,[new BinaryNode('error')])));
        self::assertSame([0,0,0,0],$this->backend->counts());
        self::assertNotNull($this->store->get('receipt-queue',self::key(0)));
    }

    public function testSenderAckRemovesOnlyTheReceiptForItsExactRecipientAfterRestart():void
    {
        $base=['id'=>'own-echo','to'=>'5511000000000@s.whatsapp.net','type'=>'sender'];
        $this->put('first',new BinaryNode('receipt',[...$base,'recipient'=>'77770000000@lid']),time()+3600);
        $this->put('other',new BinaryNode('receipt',[...$base,'recipient'=>'88880000000@lid']),time()+3600);
        $this->put('unrouted',new BinaryNode('receipt',$base),time()+3600);
        $queue=new ReceiptQueue($this->store,static function():void{self::fail('Future receipt was sent');});
        $queue->drainPending();unset($queue);
        $queue=new ReceiptQueue($this->store,static function():void{self::fail('An ACK must not send a receipt');});
        self::assertTrue($queue->handleAck(new BinaryNode('ack',['class'=>'receipt','id'=>'own-echo',
            'from'=>$base['to'],'type'=>'sender','recipient'=>'77770000000@lid'])));
        self::assertNull($this->store->get('receipt-queue','first'));
        self::assertNotNull($this->store->get('receipt-queue','other'));
        self::assertNotNull($this->store->get('receipt-queue','unrouted'));
    }

    public function testSenderAckWithoutRecipientCannotRemoveARoutedReceipt():void
    {
        $base=['id'=>'own-echo','to'=>'5511000000000@s.whatsapp.net','type'=>'sender'];
        $this->put('routed',new BinaryNode('receipt',[...$base,'recipient'=>'77770000000@lid']),time()+3600);
        $queue=new ReceiptQueue($this->store,static function():void{self::fail('An ACK must not send a receipt');});
        $ack=new BinaryNode('ack',['class'=>'receipt','id'=>'own-echo','from'=>$base['to'],'type'=>'sender']);
        self::assertTrue($queue->handleAck($ack));
        self::assertNotNull($this->store->get('receipt-queue','routed'));
        $queue->handleAck(new BinaryNode('ack',[...$ack->attrs,'recipient'=>'77770000000@s.whatsapp.net']));
        self::assertNotNull($this->store->get('receipt-queue','routed'),'PN and LID recipients remain distinct');
        $queue->handleAck(new BinaryNode('ack',[...$ack->attrs,'recipient'=>'77770000000@lid']));
        self::assertNull($this->store->get('receipt-queue','routed'));
    }

    public function testRetryBudgetAndDueOrderLetEveryBacklogEntryProgress():void
    {
        $this->seed(100,0);$ids=[];
        $queue=new ReceiptQueue($this->store,static function(BinaryNode $node)use(&$ids):void{$ids[]=$node->attrs['id'];});
        foreach([32,64,96,100]as$count){$queue->drainPending();self::assertCount($count,$ids);}
        self::assertCount(100,array_unique($ids));
        self::assertSame('receipt-0',$ids[0]);self::assertSame('receipt-99',$ids[99]);
        self::assertSame([],$this->store->entries('receipt-queue'));
        $this->backend->reset();$queue->drainPending();self::assertSame([0,0,0,0],$this->backend->counts());
    }

    public function testNewReceiptIsImmediateAndSynchronousAckDoesNotResurrectIt():void
    {
        $queue=null;$sends=0;
        $queue=new ReceiptQueue($this->store,function(BinaryNode $node)use(&$queue,&$sends):void {
            ++$sends;$rows=$this->store->entries('receipt-queue');self::assertCount(1,$rows);
            $record=BinaryData::decode(reset($rows));self::assertSame(1,$record['attempt']);self::assertGreaterThan(time(),$record['nextAttempt']);
            $queue->handleAck(new BinaryNode('ack',array_merge($node->attrs,['class'=>'receipt','from'=>$node->attrs['to']])));
        });
        $queue->enqueue($this->node(1));self::assertSame(1,$sends);
        self::assertSame([],$this->store->entries('receipt-queue'));
        $this->backend->reset();$queue->drainPending();self::assertSame([0,0,0,0],$this->backend->counts());
    }

    public function testDirectGroupAndSenderReceiptsCompleteAfterWritingWithoutAnAck():void
    {
        $nodes=[new BinaryNode('receipt',['id'=>'direct','to'=>'5522000000000@s.whatsapp.net']),
            new BinaryNode('receipt',['id'=>'group','to'=>'100-200@g.us','participant'=>'456:2@lid','type'=>'read'],
                [new BinaryNode('list',[],[new BinaryNode('item',['id'=>'other'])])]),
            new BinaryNode('receipt',['id'=>'own','to'=>'5511000000000@s.whatsapp.net','type'=>'sender','recipient'=>'77770000000@lid'])];
        foreach($nodes as$node){
            $observed=[];$queue=new ReceiptQueue($this->store,function(BinaryNode $sent)use(&$observed):void{
                $rows=$this->store->entries('receipt-queue');
                $observed[]=['sent'=>(new BinaryCodec())->encode($sent),'rows'=>count($rows),
                    'record'=>BinaryData::decode(reset($rows))];
            });
            $queue->enqueue($node);
            self::assertCount(1,$observed);self::assertSame(1,$observed[0]['rows']);
            self::assertSame((new BinaryCodec())->encode($node),$observed[0]['sent']);
            self::assertSame($observed[0]['sent'],$observed[0]['record']['node']);
            self::assertSame(1,$observed[0]['record']['attempt']);
            self::assertGreaterThan(time(),$observed[0]['record']['nextAttempt']);
            self::assertSame([],$this->store->entries('receipt-queue'));
            $this->backend->reset();$queue->drainPending();self::assertSame([0,0,0,0],$this->backend->counts());
        }
    }

    public function testSuccessfulOuterWriteDoesNotDeleteAReentrantFailedWriteOfTheSameNode():void
    {
        $queue=null;$calls=0;
        $queue=new ReceiptQueue($this->store,function(BinaryNode $node)use(&$queue,&$calls):void{
            if(++$calls===1){$queue->enqueue($node);return;}
            throw new \RuntimeException('Offline on the reentrant write');
        });
        $queue->enqueue($this->node(1));$rows=$this->store->entries('receipt-queue');
        self::assertSame(2,$calls);self::assertCount(1,$rows);
        self::assertSame(2,BinaryData::decode(reset($rows))['attempt']);
    }

    public function testAckAndReentrantNewReceiptAreNotDeletedByTheOlderWriteCompletion():void
    {
        $queue=null;$calls=0;
        $queue=new ReceiptQueue($this->store,function(BinaryNode $node)use(&$queue,&$calls):void{
            if(++$calls===1){
                $queue->handleAck(new BinaryNode('ack',[...$node->attrs,'class'=>'receipt','from'=>$node->attrs['to']]));
                $queue->enqueue($node);return;
            }
            throw new \RuntimeException('New receipt write failed');
        });
        $queue->enqueue($this->node(1));$rows=$this->store->entries('receipt-queue');
        self::assertSame(2,$calls);self::assertCount(1,$rows);
        self::assertSame(1,BinaryData::decode(reset($rows))['attempt'],'The new record is a separate generation');
    }

    public function testWriteCompletionRevalidatesDurableBytesBeforeDeletingAndRefreshesDueTime():void
    {
        $calls=0;$changed=null;
        $queue=new ReceiptQueue($this->store,function()use(&$calls,&$changed):void{
            ++$calls;$rows=$this->store->entries('receipt-queue');$key=(string)array_key_first($rows);
            $record=BinaryData::decode(reset($rows));$record['attempt']=7;$record['nextAttempt']=time()+3600;
            $changed=BinaryData::encode($record);$this->store->set('receipt-queue',$key,$changed);
        });
        $queue->enqueue($this->node(1));$rows=$this->store->entries('receipt-queue');
        self::assertCount(1,$rows);self::assertSame($changed,reset($rows));
        $this->backend->reset();$queue->drainPending();self::assertSame(1,$calls);
        self::assertSame([0,0,0,0],$this->backend->counts());
    }

    public function testRetryRevalidatesRemovalAndPostponementWithoutRecreatingOrSending():void
    {
        $this->seed(2,0);$sends=0;$queue=new ReceiptQueue($this->store,static function()use(&$sends):void{++$sends;});
        $queue->handleAck(new BinaryNode('ack',['class'=>'receipt','id'=>'not-present']));
        $this->store->delete('receipt-queue',self::key(0));
        $record=BinaryData::decode($this->store->get('receipt-queue',self::key(1)));$record['nextAttempt']=time()+3600;
        $this->store->set('receipt-queue',self::key(1),BinaryData::encode($record));
        $queue->drainPending();self::assertSame(0,$sends);self::assertNull($this->store->get('receipt-queue',self::key(0)));
        $this->backend->reset();for($i=0;$i<100;++$i)$queue->drainPending();self::assertSame([0,0,0,0],$this->backend->counts());
    }

    public function testRetrySynchronousAckDoesNotRestoreRecordAfterTransportReturns():void
    {
        $this->seed(1,0);$queue=null;$calls=0;
        $queue=new ReceiptQueue($this->store,function(BinaryNode $node)use(&$queue,&$calls):void {
            ++$calls;$queue->handleAck(new BinaryNode('ack',array_merge($node->attrs,['class'=>'receipt','from'=>$node->attrs['to']])));
        });
        $queue->drainPending();self::assertSame(1,$calls);self::assertSame([],$this->store->entries('receipt-queue'));
        $queue->drainPending();self::assertSame(1,$calls);
    }

    public function testRestartRebuildsIndexAndRetriesExactPersistedNodeAfterFailure():void
    {
        $node=new BinaryNode('receipt',['id'=>'batch','to'=>'100-200@g.us','participant'=>'456:2@lid','type'=>'read'],
            [new BinaryNode('list',[],[new BinaryNode('item',['id'=>'other'])])]);
        $queue=new ReceiptQueue($this->store,static function():void{throw new \RuntimeException('Offline');});$queue->enqueue($node);
        $rows=$this->store->entries('receipt-queue');$key=(string)array_key_first($rows);$record=BinaryData::decode(reset($rows));
        $record['nextAttempt']=0;$this->store->set('receipt-queue',$key,BinaryData::encode($record));
        $sent=[];$during=null;$queue=new ReceiptQueue($this->store,function(BinaryNode $node)use(&$sent,&$during,$key):void{
            $sent[]=(new BinaryCodec())->encode($node);$during=BinaryData::decode($this->store->get('receipt-queue',$key));
        });
        $queue->drainPending();self::assertSame([(new BinaryCodec())->encode($node)],$sent);
        self::assertSame(2,$during['attempt']);self::assertSame($record['createdAt'],$during['createdAt']);
        self::assertNull($this->store->get('receipt-queue',$key));
        $queue->handleAck(new BinaryNode('ack',['class'=>'receipt','id'=>'batch','from'=>'100-200@g.us','participant'=>'456:2@lid','type'=>'read','error'=>'500']));
        self::assertSame([],$this->store->entries('receipt-queue'),'A later error ACK cannot resurrect a completed write');
    }

    public function testFailureAfterWritingButBeforeRemovingRemainsRecoverableAfterRestart():void
    {
        $calls=0;$queue=new ReceiptQueue($this->store,function()use(&$calls):void{++$calls;$this->backend->failNextDelete=true;});
        try{$queue->enqueue($this->node(1));self::fail('Expected completion persistence failure');}
        catch(\RuntimeException $error){self::assertSame('Delete failed',$error->getMessage());}
        self::assertSame(1,$calls);$rows=$this->store->entries('receipt-queue');self::assertCount(1,$rows);
        $key=(string)array_key_first($rows);$record=BinaryData::decode(reset($rows));$record['nextAttempt']=0;
        $this->store->set('receipt-queue',$key,BinaryData::encode($record));
        $queue=new ReceiptQueue($this->store,static function()use(&$calls):void{++$calls;});$queue->drainPending();
        self::assertSame(2,$calls);self::assertSame([],$this->store->entries('receipt-queue'));
    }

    public function testErrorAckDuringFailedTransportDoesNotRemoveThePendingWrite():void
    {
        $queue=null;$calls=0;
        $queue=new ReceiptQueue($this->store,function(BinaryNode $node)use(&$queue,&$calls):void{
            ++$calls;$queue->handleAck(new BinaryNode('ack',[...$node->attrs,'class'=>'receipt','from'=>$node->attrs['to'],'error'=>'500']));
            throw new \RuntimeException('Socket closed after an uncertain write');
        });
        $queue->enqueue($this->node(1));self::assertSame(1,$calls);
        self::assertCount(1,$this->store->entries('receipt-queue'));
        $this->backend->reset();$queue->drainPending();self::assertSame(1,$calls);
        self::assertSame([0,0,0,0],$this->backend->counts());
    }

    public function testFailedDurableUpdateCanBeRetriedWithoutLosingHeapEntry():void
    {
        $this->seed(1,0);$sends=0;$queue=new ReceiptQueue($this->store,static function()use(&$sends):void{++$sends;});
        $this->backend->failNextWrite=true;
        try{$queue->drainPending();self::fail('Expected persistence failure');}catch(\RuntimeException $error){self::assertSame('Write failed',$error->getMessage());}
        self::assertSame(0,$sends);$queue->drainPending();self::assertSame(1,$sends);
    }

    public function testFailedPagedLoadIsRebuiltBeforeAnyRetries():void
    {
        $this->seed(600,time()+3600);$queue=new ReceiptQueue($this->store,static function():void{self::fail('Future receipt was sent');});
        $this->backend->reset();$this->backend->failPage=2;
        try{$queue->drainPending();self::fail('Expected read failure');}catch(\RuntimeException $error){self::assertSame('Read failed',$error->getMessage());}
        $this->backend->failPage=null;$queue->drainPending();$queue->handleAck($this->ack(599));
        self::assertNull($this->store->get('receipt-queue',self::key(599)));self::assertNotNull($this->store->get('receipt-queue',self::key(0)));
    }

    public function testDuplicateEnqueuesKeepDerivedHeapBounded():void
    {
        $queue=new ReceiptQueue($this->store,static function():void{throw new \RuntimeException('Offline');});
        for($i=0;$i<600;++$i)$queue->enqueue($this->node(1));
        $heap=(new \ReflectionProperty($queue,'due'))->getValue($queue);
        self::assertLessThanOrEqual(256,$heap->count());self::assertCount(1,$this->store->entries('receipt-queue'));
        $queue->handleAck($this->ack(1));self::assertSame([],$this->store->entries('receipt-queue'));
    }

    private static function key(int $i):string{return sprintf('%08d',$i);}
    private function node(int $i):BinaryNode{return new BinaryNode('receipt',['id'=>'receipt-'.$i,'to'=>'12345@g.us','participant'=>'456:2@lid','type'=>'read']);}
    private function ack(int $i):BinaryNode{return new BinaryNode('ack',['class'=>'receipt','id'=>'receipt-'.$i,'from'=>'12345@g.us','participant'=>'456:2@lid','type'=>'read']);}
    private function seed(int $count,int $due):void
    {
        $this->store->transaction(function()use($count,$due):void{for($i=0;$i<$count;++$i)$this->put(self::key($i),$this->node($i),$due);});
    }
    private function put(string $key,BinaryNode $node,int $due):void
    {
        $this->store->set('receipt-queue',$key,BinaryData::encode(['node'=>(new BinaryCodec())->encode($node),'attrs'=>$node->attrs,'attempt'=>0,'nextAttempt'=>$due,'createdAt'=>time()]));
    }
}

/** Counts actual SQLite operations, rather than timing assertions sensitive to CI load. */
final class ReceiptQueueCountingStore implements KeyValueStore
{
    private SqliteStore $inner;
    public int $reads=0;
    public int $writes=0;
    public int $deletes=0;
    public int $pages=0;
    public int $rows=0;
    public bool $failNextWrite=false;
    public bool $failNextDelete=false;
    public ?int $failPage=null;
    public function __construct(){$this->inner=new SqliteStore(':memory:','test');}
    public function get(string $namespace,string $key):?string{++$this->reads;return $this->inner->get($namespace,$key);}
    public function set(string $namespace,string $key,string $value):void{++$this->writes;if($this->failNextWrite){$this->failNextWrite=false;throw new \RuntimeException('Write failed');}$this->inner->set($namespace,$key,$value);}
    public function delete(string $namespace,string $key):void{++$this->deletes;if($this->failNextDelete){$this->failNextDelete=false;throw new \RuntimeException('Delete failed');}$this->inner->delete($namespace,$key);}
    public function entries(string $namespace,int $limit=1000,string $after=''):array{++$this->pages;if($this->pages===$this->failPage)throw new \RuntimeException('Read failed');$rows=$this->inner->entries($namespace,$limit,$after);$this->rows+=count($rows);return $rows;}
    public function transaction(callable $operation):mixed{return $this->inner->transaction(fn()=>$operation($this));}
    public function close():void{$this->inner->close();}
    public function reset():void{$this->reads=$this->writes=$this->deletes=$this->pages=$this->rows=0;}
    /** @return array{int,int,int,int} */
    public function counts():array{return [$this->reads,$this->writes,$this->deletes,$this->pages];}
}
