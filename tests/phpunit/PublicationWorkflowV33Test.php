<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Protocol\BinaryData;
use MVO\Service\{ChannelPublicationScheduler,DurablePublicationBatchService,MessagePublicationScheduler,MessageScheduleConflict,PublicationRevisionConflict};
use MVO\Signal\SignalStore;
use PHPUnit\Framework\TestCase;

final class PublicationWorkflowV33Test extends TestCase
{
    private string $path;
    private SignalStore $store;
    private int $now=1800000000;
    private array $sent=[];
    protected function setUp():void{$this->path=tempnam(sys_get_temp_dir(),'mvo-pub-v33-');$this->store=new SignalStore($this->path);}
    protected function tearDown():void{$this->store->close();foreach([$this->path,$this->path.'-wal',$this->path.'-shm']as$file)if(is_file($file))unlink($file);}
    private function messages():MessagePublicationScheduler{return new MessagePublicationScheduler($this->store,function(string $to,array $content,array $options):array{$this->sent[]=$content;return ['id'=>$options['id'],'to'=>$to,'status'=>'server_ack'];},fn():int=>$this->now);}
    private function channels():ChannelPublicationScheduler{return new ChannelPublicationScheduler($this->store,function(string $to,array $content,array $options):array{$this->sent[]=$content;return ['id'=>$options['id'],'status'=>'server_ack'];},fn():int=>$this->now);}
    private function batches(?\Closure $publish=null,?\Closure $guard=null):DurablePublicationBatchService{return new DurablePublicationBatchService($this->store,$publish??function(string $mode,string $to,array $content,array $options):array{$this->sent[]=['to'=>$to,'content'=>$content,'id'=>$options['id']];return ['id'=>$options['id'],'to'=>$to,'status'=>'server_ack','mediaKey'=>'PRIVATE'];},fn():int=>$this->now,$guard);}
    public function testArchiveRemovesPayloadButRetainsOriginalImmutableIdempotencyAfterAnEdit():void
    {
        $queue=$this->messages();$content=['type'=>'text','text'=>'Original private payload'];$original=$queue->schedule('1@s.whatsapp.net',$content,$this->now+10,'stable');
        $edited=$queue->edit('stable',['content'=>['type'=>'text','text'=>'Changed private payload']],1);self::assertSame(2,$edited['revision']);self::assertSame($original['messageId'],$edited['messageId']);
        $this->now+=10;$queue->drain();$report=$queue->archiveTerminal($this->now);self::assertSame(['stable'],$report['archivedIds']);self::assertSame([],$queue->list());
        $tombstone=$queue->get('stable');self::assertTrue($tombstone['archived']);self::assertNull($tombstone['content']);self::assertStringNotContainsString('private payload',$this->store->get('message-publication-schedule-tombstones','stable'));
        self::assertSame($original['messageId'],$queue->schedule('1@s.whatsapp.net',$content,$original['sendAt'],'stable')['messageId']);self::assertSame([],$queue->drain());
        $this->expectException(MessageScheduleConflict::class);$queue->schedule('1@s.whatsapp.net',['type'=>'text','text'=>'Different'],$this->now+10,'stable');
    }
    public function testTerminalRowsNoLongerExhaustTheActiveQueueLimitAcrossPages():void
    {
        $queue=$this->messages();$base=$queue->schedule('1@s.whatsapp.net',['type'=>'text','text'=>'Old'],$this->now+10,'seed');$queue->cancel('seed');
        $this->store->transaction(function()use($base):void{for($i=0;$i<1005;$i++){$row=$base;$row['id']='old-'.sprintf('%05d',$i);$row['state']='canceled';$this->store->set('message-publication-schedules',$row['id'],BinaryData::encode($row));}});
        self::assertSame('queued',$queue->schedule('1@s.whatsapp.net',['type'=>'text','text'=>'New'],$this->now+10,'new')['state']);self::assertSame(1000,$queue->archiveTerminal($this->now,1000)['archivedCount']);self::assertSame(6,$queue->archiveTerminal($this->now,1000)['archivedCount']);self::assertSame(['new'],array_column($queue->list(),'id'));
    }
    public function testArchiveNeverTouchesAnUnknownAttemptOrAQueuedSnapshot():void
    {
        $queue=$this->messages();$queued=$queue->schedule('1@s.whatsapp.net',['type'=>'document','media'=>['bytes'=>'KEEP']],$this->now+10,'queued');$unknown=$queued;$unknown['id']='unknown';$unknown['state']='unknown';$this->store->set('message-publication-schedules','unknown',BinaryData::encode($unknown));
        self::assertSame(0,$queue->archiveTerminal($this->now)['archivedCount']);self::assertSame('KEEP',$this->store->get('message-publication-schedule-assets','queued'));self::assertSame('unknown',$queue->get('unknown')['state']);
    }
    public function testContentAndMediaReplacementAreAtomicUnderRevisionConflict():void
    {
        $queue=$this->messages();$original=$queue->schedule('1@s.whatsapp.net',['type'=>'document','media'=>['bytes'=>'OLD'],'fileName'=>'one.txt'],$this->now+10,'edit');
        $changed=$queue->edit('edit',['content'=>['type'=>'document','media'=>['bytes'=>'NEW'],'fileName'=>'two.txt']],1);self::assertSame('NEW',$this->store->get('message-publication-schedule-assets','edit'));
        try{$this->messages()->edit('edit',['content'=>['type'=>'document','media'=>['bytes'=>'STALE']]],1);self::fail('Stale revision accepted');}catch(PublicationRevisionConflict){self::assertSame($changed,$queue->get('edit'));self::assertSame('NEW',$this->store->get('message-publication-schedule-assets','edit'));}
        $this->now+=10;$queue->drain();self::assertSame('NEW',$this->sent[0]['media']['bytes']);self::assertSame($original['messageId'],$queue->get('edit')['messageId']);
    }
    public function testExpiredAndLateMessagesReleaseAssetsWithoutAnyPublication():void
    {
        $queue=$this->messages();$queue->schedule('1@s.whatsapp.net',['type'=>'document','media'=>['bytes'=>'EXPIRED']],$this->now+10,'expired',['expiresAt'=>$this->now+20]);$queue->schedule('1@s.whatsapp.net',['type'=>'text','text'=>'Late'],$this->now+10,'late',['misfirePolicy'=>'skip_if_late','maxLatenessSeconds'=>5]);
        $this->now+=21;$rows=$queue->drain();self::assertSame(['skipped','skipped'],array_column($rows,'state'));self::assertSame(['publication_expired','publication_too_late'],array_column(array_column($rows,'failure'),'code'));self::assertSame([],$this->sent);self::assertNull($this->store->get('message-publication-schedule-assets','expired'));
    }
    public function testDefaultPolicyKeepsBackwardCompatibleLateSendAndExpirationCanBeCleared():void
    {
        $queue=$this->messages();$queue->schedule('1@s.whatsapp.net',['type'=>'text','text'=>'Late allowed'],$this->now+10,'late',['expiresAt'=>$this->now+20]);$queue->edit('late',['expiresAt'=>null],1);$this->now+=500;self::assertSame('server_ack',$queue->drain()[0]['state']);self::assertCount(1,$this->sent);
    }
    public function testChannelRevisionPoliciesAndArchiveRetainReservedId():void
    {
        $q=$this->channels();$jid='123@newsletter';$original=$q->schedule($jid,['type'=>'text','text'=>'Original'],$this->now+10,'same');$q->edit($jid,'same',['content'=>['type'=>'text','text'=>'Edited'],'misfirePolicy'=>'skip_if_late','maxLatenessSeconds'=>0],1);
        try{$q->edit($jid,'same',['content'=>['type'=>'text','text'=>'Stale']],1);self::fail('Stale revision accepted');}catch(PublicationRevisionConflict){}
        $this->now+=11;self::assertSame('skipped',$q->drain()[0]['state']);self::assertSame(1,$q->archiveTerminal($jid,$this->now)['archivedCount']);self::assertSame($original['messageId'],$q->schedule($jid,['type'=>'text','text'=>'Original'],$original['sendAt'],'same')['messageId']);self::assertSame([],$this->sent);
    }
    public function testBatchRestartPublishesEachReservedIdOnceAndSharesDurableMedia():void
    {
        $source=tempnam(sys_get_temp_dir(),'mvo-batch-');file_put_contents($source,'ORIGINAL');try{$created=$this->batches()->create('message',['1@s.whatsapp.net','2@s.whatsapp.net'],['type'=>'image','media'=>['path'=>$source],'mimetype'=>'image/jpeg'],'campaign');}finally{unlink($source);}
        $this->store->close();$this->store=new SignalStore($this->path);$service=$this->batches();$service->drain();$service->drain();self::assertSame('completed',$service->get('campaign')['state']);self::assertSame(array_column($created['items'],'messageId'),array_column($this->sent,'id'));self::assertSame('ORIGINAL',$this->sent[0]['content']['media']['bytes']);self::assertSame('ORIGINAL',$this->sent[1]['content']['media']['bytes']);self::assertNull($this->store->get('publication-batch-assets','campaign'));self::assertSame([],$service->drain());self::assertStringNotContainsString('PRIVATE',$service->export('campaign'));
    }
    public function testExplicitResumeSkipsUnknownAndAcknowledgedItemsAndKeepsIds():void
    {
        $calls=0;$service=$this->batches(function(string $mode,string $to,array $content,array $options)use(&$calls):array{$this->sent[]=$options['id'];if(++$calls===1)throw new \RuntimeException('PRIVATE');return ['id'=>$options['id'],'status'=>'server_ack'];});
        $created=$service->create('message',['1@s.whatsapp.net','2@s.whatsapp.net'],['type'=>'text','text'=>'A'],'unknown');$service->drain();self::assertSame('paused',$service->get('unknown')['state']);self::assertSame([],$service->drain());$service->resume('unknown');$service->drain();self::assertSame(['unknown','server_ack'],array_column($service->get('unknown')['items'],'state'));self::assertSame(array_column($created['items'],'messageId'),$this->sent);self::assertStringNotContainsString('PRIVATE',$service->export('unknown'));
    }
    public function testOnlyDefinitiveNotExecutedItemCanResumeWithTheSameReservedId():void
    {
        $calls=0;$service=$this->batches(function(string $mode,string $to,array $content,array $options)use(&$calls):array{$this->sent[]=$options['id'];return ['id'=>$options['id'],'status'=>++$calls===1?'not_executed':'server_ack'];});$service->create('message',['1@s.whatsapp.net'],['type'=>'text','text'=>'A'],'safe');$service->drain();self::assertSame('not_executed',$service->get('safe')['items'][0]['state']);$service->resume('safe');$service->drain();self::assertSame($this->sent[0],$this->sent[1]);self::assertSame('completed',$service->get('safe')['state']);
    }
    public function testConcurrentPauseAndCancelNeverLoseTheirStateWhenAnInflightAckReturns():void
    {
        $service=null;$service=$this->batches(function(string $mode,string $to,array $content,array $options)use(&$service):array{$service->cancel('race');return ['id'=>$options['id'],'status'=>'server_ack'];});$service->create('message',['1@s.whatsapp.net','2@s.whatsapp.net'],['type'=>'text','text'=>'A'],'race');$service->drain();$row=$service->get('race');self::assertSame('canceled',$row['state']);self::assertSame(['server_ack','canceled'],array_column($row['items'],'state'));self::assertSame([],$service->drain());
    }
    public function testActiveLeasePreventsASecondWorkerFromPublishingTheNextItem():void
    {
        $service=null;$service=$this->batches(function(string $mode,string $to,array $content,array $options):array{$secondStore=new SignalStore($this->path);try{$second=new DurablePublicationBatchService($secondStore,static function():array{throw new \RuntimeException('Must not publish');},fn():int=>$this->now);self::assertSame([],$second->drain());}finally{$secondStore->close();}return ['id'=>$options['id'],'status'=>'server_ack'];});$service->create('message',['1@s.whatsapp.net','2@s.whatsapp.net'],['type'=>'text','text'=>'A'],'leased');$service->drain();self::assertSame(['server_ack','pending'],array_column($service->get('leased')['items'],'state'));
    }
    public function testCrashRecoveryAfterLeaseExpirationIsUnknownAndNeverAutomaticallyRetried():void
    {
        $service=$this->batches();$row=$service->create('message',['1@s.whatsapp.net'],['type'=>'text','text'=>'A'],'crash');$row['items'][0]=array_replace($row['items'][0],['state'=>'publishing','attempts'=>1,'claimToken'=>'old','leaseUntil'=>$this->now+300]);$this->store->set('publication-batches','crash',BinaryData::encode($row));self::assertSame([],$service->drain());$this->now+=301;$service->drain();self::assertSame('unknown',$service->get('crash')['items'][0]['state']);self::assertSame('paused',$service->get('crash')['state']);self::assertSame([],$service->drain());self::assertSame([],$this->sent);
    }
    public function testBatchScopeConflictsAndGroupStatusValidationOccurBeforePersistence():void
    {
        $service=$this->batches();$created=$service->create('group-status',['120363123@g.us'],['type'=>'text','text'=>'Status','backgroundColor'=>'#003D66'],'group');self::assertSame($created,$service->create('group-status',['120363123@g.us'],['type'=>'text','text'=>'Status','backgroundColor'=>'#003D66'],'group'));$service->drain();self::assertSame('Status',$this->sent[0]['content']['text']);
        foreach([['group-status',['1@s.whatsapp.net']],['message',['1@s.whatsapp.net','1@s.whatsapp.net']]]as[$mode,$targets]){try{$service->create($mode,$targets,['type'=>'text','text'=>'A'],'invalid');self::fail('Invalid destination accepted');}catch(\InvalidArgumentException){self::assertNull($service->get('invalid'));}}
        $this->expectException(MessageScheduleConflict::class);$service->create('group-status',['120363123@g.us'],['type'=>'text','text'=>'Different'],'group');
    }
    public function testBatchExportNeutralizesFormulaPrefixAndOmitsClaimTokensAndSecrets():void
    {
        $service=$this->batches();$row=$service->create('message',['1@s.whatsapp.net'],['type'=>'text','text'=>'A'],'-formula');$row['items'][0]['claimToken']='PRIVATE';$this->store->set('publication-batches','-formula',BinaryData::encode($row));$csv=$service->export('-formula','csv');self::assertStringContainsString("'-formula",$csv);$json=$service->export('-formula');self::assertStringNotContainsString('PRIVATE',$json);self::assertStringNotContainsString('requestFingerprint',$json);
    }
    public function testGuardDenialBeforePublishCannotExecuteOrResetAnyUnknownItem():void
    {
        $calls=0;$service=$this->batches(guard:static function()use(&$calls):void{if(++$calls===2)throw new \LogicException('Suspended');});$service->create('message',['1@s.whatsapp.net'],['type'=>'text','text'=>'A'],'guard');try{$service->drain();self::fail('Guard bypass');}catch(\LogicException){self::assertSame('not_executed',$service->get('guard')['items'][0]['state']);self::assertSame('paused',$service->get('guard')['state']);self::assertSame([],$this->sent);}
    }
    public function testRunnableIndexMigratesOnceAndNeverScansTerminalHistoryOnWorkerPump():void
    {
        $service=$this->batches();$terminal=$service->create('message',['1@s.whatsapp.net'],['type'=>'text','text'=>'Historical'],'old');$terminal['state']='completed';$terminal['items'][0]['state']='server_ack';
        $this->store->transaction(function()use($terminal):void{for($i=0;$i<1205;$i++){$row=$terminal;$row['id']='history-'.sprintf('%05d',$i);$this->store->set('publication-batches',$row['id'],BinaryData::encode($row));}$this->store->set('publication-batches','old',BinaryData::encode($terminal));$this->store->delete('publication-batch-runnable','old');});
        $runnable=$service->create('message',['2@s.whatsapp.net'],['type'=>'text','text'=>'Runnable'],'run');$service->pause('run');$this->store->delete('publication-batch-index','version');$this->store->close();
        $counting=new class($this->path)implements \MVO\Store\KeyValueStore{
            private \MVO\Store\SqliteStore $inner;
            public array $scans=[];
            public function __construct(string $path){$this->inner=new \MVO\Store\SqliteStore($path);}
            public function get(string $namespace,string $key):?string{return $this->inner->get($namespace,$key);}
            public function set(string $namespace,string $key,string $value):void{$this->inner->set($namespace,$key,$value);}
            public function delete(string $namespace,string $key):void{$this->inner->delete($namespace,$key);}
            public function entries(string $namespace,int $limit=1000,string $after=''):array{$this->scans[$namespace]=($this->scans[$namespace]??0)+1;return $this->inner->entries($namespace,$limit,$after);}
            public function transaction(callable $operation):mixed{return $this->inner->transaction(fn():mixed=>$operation($this));}
            public function close():void{$this->inner->close();}
        };
        $this->store=new SignalStore($this->path,backend:$counting);$service=$this->batches();self::assertSame([],$service->drain());self::assertGreaterThanOrEqual(2,$counting->scans['application.publication-batches']);self::assertSame('1',$this->store->get('publication-batch-index','version'));
        $counting->scans=[];for($i=0;$i<10;$i++)self::assertSame([],$this->batches()->drain());self::assertArrayNotHasKey('application.publication-batches',$counting->scans);self::assertSame(10,$counting->scans['application.publication-batch-runnable']);
        $service->resume('run');$counting->scans=[];$service->drain();self::assertSame($runnable['items'][0]['messageId'],$this->sent[0]['id']);self::assertNull($this->store->get('publication-batch-runnable','run'));self::assertArrayNotHasKey('application.publication-batches',$counting->scans);self::assertSame([],$service->drain());
    }

}
