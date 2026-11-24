<?php
declare(strict_types=1);
namespace MVO\Messenger\Tests;

use MVO\Messenger\Auth\SessionStore;
use MVO\Messenger\MessengerException;
use MVO\Messenger\Protocol\EventProjector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventProjectorTest extends TestCase
{
    private const RECEIPT='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private string $root;
    private ?SessionStore $store=null;

    protected function setUp():void
    {
        $this->root=sys_get_temp_dir().'/mvo-messenger-projector-'.bin2hex(random_bytes(8));
        $this->store=new SessionStore($this->root,'test');$this->store->acquire();
    }
    protected function tearDown():void
    {
        $this->store?->release();$this->store=null;gc_collect_cycles();
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $entry){if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($this->root);
    }
    /** @return list<array{name:string,args:list<mixed>}> */
    private function calls(string $epoch='42',?string $current=null,string $next='cursor-next',bool $clearEpoch=false,?string $finalEpoch=null):array
    {
        $row=array_fill(0,24,null);$row[0]='Synthetic transactional message';$row[3]='10001';$row[5]=1792356000000;
        $row[8]='mid.transactional';$row[10]='222';$row[12]=false;$row[17]=false;
        return [
            ['name'=>'LSExecuteFirstBlockForSyncTransactionV4','args'=>[1,$epoch,$current,$next,0,1,false,0,false,1]],
            ['name'=>'LSDeleteThenInsertThread','args'=>[1792356000000,0,'','Synthetic transaction',null,false,0,'10001',0,1]],
            ['name'=>'LSUpsertMessage','args'=>$row],
            ['name'=>'LSReplaceOptimsiticMessage','args'=>['500','mid.transactional']],
            ['name'=>'LSExecuteFinallyBlockForSyncTransaction','args'=>[$clearEpoch,1,$finalEpoch??$epoch]],
        ];
    }
    private function prepare():SessionStore
    {
        $store=$this->store??throw new \LogicException('Missing test store');
        $store->set('cursor.1','cursor-original');$store->enqueue('500','10001','Synthetic pending text',1);
        return $store;
    }
    private function assertNoPartialProjection(SessionStore $store):void
    {
        self::assertSame('cursor-original',$store->get('cursor.1'));self::assertSame([],$store->threads());self::assertSame([],$store->events());
        self::assertSame('prepared',$store->outgoing('500')['state']);self::assertNull($store->outgoing('500')['server_id']);
        self::assertNull($store->get('receipt.'.self::RECEIPT));
        $database=new \PDO('sqlite:'.$this->root.'/messenger/test/state.sqlite');
        self::assertSame(0,(int)$database->query('SELECT COUNT(*) FROM batches')->fetchColumn());unset($database);
    }
    /** @return array<string,array{string}> */
    public static function messageProcedures():array
    {
        return ['insert'=>['LSInsertMessage'],'upsert'=>['LSUpsertMessage']];
    }
    #[DataProvider('messageProcedures')]
    public function testNullTextCommitsMetadataCursorAndAckWithoutInventingContent(string $procedure):void
    {
        $store=$this->prepare();$projector=new EventProjector($store,'123');$calls=$this->calls(current:'cursor-original');
        $calls[2]['name']=$procedure;$calls[2]['args'][0]=null;
        // A sticker slot alone must not make the nullable body a text or guessed media message.
        $calls[2]['args'][11]='70007';
        $result=$projector->apply($calls,self::RECEIPT);
        self::assertSame([1],$result['completed']);self::assertSame([1=>'cursor-next'],$result['cursors']);
        self::assertSame('cursor-next',$store->get('cursor.1'));self::assertSame('committed',$store->get('receipt.'.self::RECEIPT));
        self::assertSame(['thread','message','message_ack'],array_column($result['events'],'type'));
        $message=$result['events'][1]['payload'];
        self::assertSame('unknown',$message['type']);self::assertNull($message['text']);
        self::assertSame('mid.transactional',$message['id']);self::assertSame('10001',$message['thread_id']);self::assertSame('222',$message['sender_id']);
        self::assertSame(1792356000000,$message['timestamp_ms']);self::assertFalse($message['from_me']);self::assertFalse($message['encrypted']);
        self::assertArrayNotHasKey('attachments',$message);self::assertArrayNotHasKey('sticker',$message);
        self::assertSame('server_ack',$store->outgoing('500')['state']);
        self::assertSame(['events'=>[],'cursors'=>[],'completed'=>[],'unknown'=>[]],$projector->apply($calls,str_repeat('b',64)));
        self::assertCount(3,$store->events());
        // Different procedure names can repeat the same message, without repeating the event.
        $row=$calls[2];$row['name']=$procedure==='LSInsertMessage'?'LSUpsertMessage':'LSInsertMessage';
        self::assertSame([],$projector->apply([$row])['events']);self::assertCount(3,$store->events());
    }
    #[DataProvider('messageProcedures')]
    public function testNullTextAndEmptyTextHaveDistinctMessageEvents(string $procedure):void
    {
        $store=$this->store??throw new \LogicException('Missing test store');$projector=new EventProjector($store,'123');
        $row=$this->calls()[2];$row['name']=$procedure;$row['args'][0]=null;
        $unknown=$projector->apply([$row]);self::assertNull($unknown['events'][0]['payload']['text']);
        $row['args'][0]='';$text=$projector->apply([$row]);
        self::assertCount(1,$text['events']);self::assertSame('text',$text['events'][0]['payload']['type']);self::assertSame('',$text['events'][0]['payload']['text']);
        self::assertSame([],$projector->apply([$row])['events']);self::assertCount(2,$store->events());
    }
    #[DataProvider('messageProcedures')]
    public function testNullTextDoesNotWeakenTransactionValidation(string $procedure):void
    {
        $store=$this->prepare();$calls=$this->calls(current:'cursor-original',finalEpoch:'43');$calls[2]['name']=$procedure;$calls[2]['args'][0]=null;
        try{(new EventProjector($store,'123'))->apply($calls,self::RECEIPT);self::fail('Expected epoch mismatch');}
        catch(MessengerException $error){self::assertSame('invalid_sync_transaction',$error->reason);}
        $this->assertNoPartialProjection($store);
    }
    /** @return iterable<string,array{string,int,mixed,string}> */
    public static function malformedMessageRows():iterable
    {
        foreach(['LSInsertMessage','LSUpsertMessage']as $procedure){
            foreach(['boolean'=>false,'integer'=>0,'float'=>1.5,'array'=>[]]as $kind=>$text)yield $procedure.' text '.$kind=>[$procedure,0,$text,'invalid_message_row'];
            yield $procedure.' empty message ID'=>[$procedure,8,'','invalid_message_row'];
            yield $procedure.' oversized message ID'=>[$procedure,8,str_repeat('m',513),'invalid_message_row'];
            yield $procedure.' numeric message ID'=>[$procedure,8,123,'invalid_message_row'];
            yield $procedure.' missing thread ID'=>[$procedure,3,null,'invalid_native_id'];
            yield $procedure.' missing sender ID'=>[$procedure,10,null,'invalid_native_id'];
            yield $procedure.' missing timestamp'=>[$procedure,5,null,'invalid_native_value'];
            yield $procedure.' invalid timestamp'=>[$procedure,5,'not-a-time','invalid_native_value'];
        }
    }
    #[DataProvider('malformedMessageRows')]
    public function testMalformedRowsRollBackEarlierMessageAckThreadCursorAndReceipt(string $procedure,int $offset,mixed $value,string $reason):void
    {
        $store=$this->prepare();$calls=$this->calls(current:'cursor-original');$bad=$calls[2];$bad['name']=$procedure;$bad['args'][0]=null;$bad['args'][$offset]=$value;
        array_splice($calls,4,0,[$bad]);
        try{(new EventProjector($store,'123'))->apply($calls,self::RECEIPT);self::fail('Expected malformed message row');}
        catch(MessengerException $error){self::assertSame($reason,$error->reason);}
        $this->assertNoPartialProjection($store);
    }
    public function testFinalizerEpochMismatchRollsBackMessageThreadAckCursorAndDedupBatch():void
    {
        $store=$this->prepare();$projector=new EventProjector($store,'123');
        try{$projector->apply($this->calls(current:'cursor-original',finalEpoch:'43'),self::RECEIPT);self::fail('Expected epoch mismatch');}
        catch(MessengerException $error){self::assertSame('invalid_sync_transaction',$error->reason);}
        $this->assertNoPartialProjection($store);
    }
    public function testCursorMismatchRollsBackAllEarlierProjectionEffects():void
    {
        $store=$this->prepare();
        try{(new EventProjector($store,'123'))->apply($this->calls(current:'cursor-unrelated'),self::RECEIPT);self::fail('Expected cursor mismatch');}
        catch(MessengerException $error){self::assertSame('sync_cursor_mismatch',$error->reason);}
        $this->assertNoPartialProjection($store);
    }
    public function testClearEpochFlagCommitsTheAlreadyValidatedTransaction():void
    {
        $store=$this->prepare();
        $result=(new EventProjector($store,'123'))->apply($this->calls(current:'cursor-original',clearEpoch:true),self::RECEIPT);
        self::assertSame([1],$result['completed']);self::assertSame('cursor-next',$store->get('cursor.1'));
        self::assertSame('server_ack',$store->outgoing('500')['state']);self::assertSame('committed',$store->get('receipt.'.self::RECEIPT));
    }
    public function testMalformedFinalizerFlagRollsBackAllEarlierProjectionEffects():void
    {
        $store=$this->prepare();$calls=$this->calls(current:'cursor-original');$calls[4]['args'][0]='unrecognized flag';
        try{(new EventProjector($store,'123'))->apply($calls,self::RECEIPT);self::fail('Expected invalid finalizer');}
        catch(MessengerException $error){self::assertSame('invalid_sync_transaction',$error->reason);}
        $this->assertNoPartialProjection($store);
    }
    public function testEpochZeroCommitsAtomicallyAndReplayDoesNotDuplicateEffects():void
    {
        $store=$this->store??throw new \LogicException('Missing test store');$store->enqueue('500','10001','Synthetic pending text',1);
        $projector=new EventProjector($store,'123');$calls=$this->calls(epoch:'0');$result=$projector->apply($calls,self::RECEIPT);
        self::assertSame([1],$result['completed']);self::assertSame([1=>'cursor-next'],$result['cursors']);
        self::assertSame('cursor-next',$store->get('cursor.1'));self::assertSame('Synthetic transaction',$store->threads()[0]['name']);
        self::assertSame(['thread','message','message_ack'],array_column($result['events'],'type'));
        self::assertSame('server_ack',$store->outgoing('500')['state']);self::assertSame('mid.transactional',$store->outgoing('500')['server_id']);
        self::assertSame('committed',$store->get('receipt.'.self::RECEIPT));
        $secondReceipt=str_repeat('b',64);
        self::assertSame(['events'=>[],'cursors'=>[],'completed'=>[],'unknown'=>[]],$projector->apply($calls,$secondReceipt));
        self::assertSame('committed',$store->get('receipt.'.$secondReceipt));
        self::assertCount(3,$store->events());
    }
    public function testMissingFinalizerNeverLeavesProjectionOrCursorBehind():void
    {
        $store=$this->prepare();$calls=$this->calls(current:'cursor-original');array_pop($calls);
        try{(new EventProjector($store,'123'))->apply($calls,self::RECEIPT);self::fail('Expected incomplete sync transaction');}
        catch(MessengerException $error){self::assertSame('incomplete_sync_transaction',$error->reason);}
        $this->assertNoPartialProjection($store);
    }
    public function testCutoverLinkBeforeThreadSurvivesRefreshAndNativeDeleteShim():void
    {
        $store=$this->store??throw new \LogicException('Missing test store');$projector=new EventProjector($store,'123');
        $projector->apply([['name'=>'LSCreateOpenToE2EEThreadLink','args'=>['10001','20002',true,'1792356000000']]]);
        self::assertNull($store->thread('10001'));
        $thread=$this->calls()[1];$projector->apply([$thread]);
        self::assertTrue($store->thread('10001')['encrypted']);self::assertFalse($store->thread('10001')['plaintext_send_supported']);
        self::assertSame('20002',$store->threads()[0]['encrypted_thread_id']);self::assertTrue($store->threads()[0]['show_open_message_history']);
        $thread['args'][3]='Refreshed synthetic name';
        $projector->apply([['name'=>'LSHybridThreadDelete','args'=>['10001']],$thread]);
        self::assertSame('Refreshed synthetic name',$store->thread('10001')['name']);self::assertFalse($store->thread('10001')['plaintext_send_supported']);
        self::assertSame('20002',$store->thread('10001')['encrypted_thread_id']);
    }
    public function testCutoverFolderOnlyUpdatesAnExistingThreadAndBlocksRefresh():void
    {
        $store=$this->store??throw new \LogicException('Missing test store');$projector=new EventProjector($store,'123');
        $move=['name'=>'LSMoveThreadToE2EECutoverFolder','args'=>['10001']];
        $projector->apply([$move]);self::assertSame([],$store->threads());
        $thread=$this->calls()[1];$thread['args'][3]='Before cutover';$projector->apply([$thread]);
        $projector->apply([$move,['name'=>'LSHybridThreadDelete','args'=>['10001']]]);
        $thread['args'][3]='After cutover';$projector->apply([$thread]);
        self::assertTrue($store->thread('10001')['e2ee_cutover']);self::assertFalse($store->thread('10001')['plaintext_send_supported']);
    }
    public function testCutoverRoutingRollsBackWithAnInvalidTransaction():void
    {
        $store=$this->prepare();$calls=$this->calls(current:'cursor-original',finalEpoch:'43');
        array_splice($calls,2,0,[['name'=>'LSCreateOpenToE2EEThreadLink','args'=>['10001','20002',false,'1792356000000']]]);
        try{(new EventProjector($store,'123'))->apply($calls);self::fail('Expected epoch mismatch');}
        catch(MessengerException $error){self::assertSame('invalid_sync_transaction',$error->reason);}
        (new EventProjector($store,'123'))->apply([$this->calls()[1]]);
        self::assertTrue($store->thread('10001')['plaintext_send_supported']);self::assertArrayNotHasKey('encrypted_thread_id',$store->thread('10001'));
    }
    public function testRetainedRoutingUpgradeDoesNotReplayMessagesAcksOrCursors():void
    {
        $store=$this->prepare();$projector=new EventProjector($store,'123');$projector->apply([$this->calls()[1]]);
        $calls=[['name'=>'LSCreateOpenToE2EEThreadLink','args'=>['10001','20002',true,'1792356000000']],...$this->calls(current:'stale-cursor')];
        $store->transaction(fn()=>$store->saveBatch(hash('sha256',json_encode($calls,JSON_THROW_ON_ERROR)),$calls));
        $before=$store->events();$projector->restoreRouting();
        self::assertSame('20002',$store->thread('10001')['encrypted_thread_id']);self::assertFalse($store->thread('10001')['plaintext_send_supported']);
        self::assertSame('cursor-original',$store->get('cursor.1'));self::assertSame('prepared',$store->outgoing('500')['state']);
        self::assertSame($before,$store->events());self::assertSame('complete',$store->get('projection.cutover.v1'));
        $projector->restoreRouting();self::assertSame($before,$store->events());
    }
}
