<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Protocol\BinaryData;
use MVO\Protocol\BinaryNode;
use MVO\Service\{ChannelPublicationScheduler,ServiceException,Services,ScheduledPublicationNotExecuted};
use MVO\Signal\SignalStore;
use MVO\Transport\NodeSession;
use MVO\WaClient;
use PHPUnit\Framework\TestCase;

final class ChannelPublicationSchedulerTest extends TestCase
{
    private string $path;
    private SignalStore $store;
    private int $now=1800000000;
    /** @var list<array<string,mixed>> */
    private array $published=[];
    protected function setUp():void {$this->path=tempnam(sys_get_temp_dir(),'mvo-schedule-');$this->store=new SignalStore($this->path);}
    protected function tearDown():void {$this->store->close();foreach([$this->path,$this->path.'-wal',$this->path.'-shm']as$file)if(is_file($file))unlink($file);}
    private function scheduler(?\Closure $publish=null):ChannelPublicationScheduler
    {
        return new ChannelPublicationScheduler($this->store,$publish??function(string $jid,array $content,array $options):array{
            $this->published[]=['jid'=>$jid,'content'=>$content,'id'=>$options['id']];
            return ['id'=>$options['id'],'status'=>'server_ack','serverId'=>19,'ack'=>'PRIVATE','mediaKey'=>'PRIVATE'];
        },fn():int=>$this->now);
    }

    public function testRestartKeepsExactMessageIdAndPublishesOnlyWhenDueOnce():void
    {
        $row=$this->scheduler()->schedule('1@newsletter',['type'=>'text','text'=>'Agendada'],$this->now+60,'first');
        self::assertSame('mvo_local',$row['engine']);self::assertSame('queued',$row['state']);self::assertSame([],$this->scheduler()->drain());
        $this->store->close();$this->store=new SignalStore($this->path);$this->now+=60;
        $rows=$this->scheduler()->drain();self::assertCount(1,$rows);self::assertSame('server_ack',$rows[0]['state']);
        self::assertSame($row['messageId'],$this->published[0]['id']);self::assertSame($this->now,$rows[0]['sentAt']);
        self::assertArrayNotHasKey('ack',$rows[0]['result']);self::assertArrayNotHasKey('mediaKey',$rows[0]['result']);
        self::assertSame([],$this->scheduler()->drain());self::assertCount(1,$this->published);
    }

    public function testCancellationAndRescheduleAreDurableAndChannelScoped():void
    {
        $scheduler=$this->scheduler();$scheduler->schedule('1@newsletter',['type'=>'text','text'=>'A'],$this->now+60,'a');
        $scheduler->schedule('1@newsletter',['type'=>'text','text'=>'B'],$this->now+60,'b');
        $scheduler->schedule('2@newsletter',['type'=>'text','text'=>'C'],$this->now+60,'a');
        self::assertSame('canceled',$scheduler->cancel('1@newsletter','a')['state']);
        self::assertSame('canceled',$scheduler->cancel('1@newsletter','a')['state']);
        $scheduler->reschedule('1@newsletter','b',$this->now+120);
        self::assertSame(['a','b'],array_column($scheduler->list('1@newsletter'),'id'));
        self::assertSame(['b'],array_column($scheduler->list('1@newsletter',50,'a'),'id'));
        self::assertNull($scheduler->get('2@newsletter','b'));
        $this->now+=60;self::assertSame(['2@newsletter'],array_column($scheduler->drain(),'channelJid'));
        $this->now+=60;self::assertSame(['b'],array_column($scheduler->drain(),'id'));
    }
    public function testConcurrentRescheduleAfterDueScanCannotPublishBeforeTheNewTime():void
    {
        $checks=0;$scheduler=new ChannelPublicationScheduler($this->store,function(string $jid,array $content,array $options):array{$this->published[]=$options;return ['id'=>$options['id'],'status'=>'server_ack'];},fn():int=>$this->now,function()use(&$checks):void{
            if(++$checks!==2)return;
            $otherStore=new SignalStore($this->path);
            try{(new ChannelPublicationScheduler($otherStore,static fn():never=>throw new \LogicException('Read-only queue publisher'),fn():int=>$this->now))->reschedule('1@newsletter','race',$this->now+600);}
            finally{$otherStore->close();}
        });
        $original=$scheduler->schedule('1@newsletter',['type'=>'text','text'=>'Publish later'],$this->now+1,'race');$this->now++;
        self::assertSame([],$scheduler->drain());self::assertSame([],$this->published);$queued=$scheduler->get('1@newsletter','race');self::assertSame('queued',$queued['state']);self::assertSame(0,$queued['attempts']);self::assertSame($this->now+600,$queued['sendAt']);self::assertSame($original['messageId'],$queued['messageId']);
        $this->store->close();$this->store=new SignalStore($this->path);$this->now+=600;
        self::assertSame($original['messageId'],$this->scheduler()->drain()[0]['messageId']);self::assertCount(1,$this->published);self::assertSame([],$this->scheduler()->drain());
    }
    public function testCancellationBetweenDueScanAndClaimIsSkippedWithoutIncrementingAttempts():void
    {
        $checks=0;$scheduler=new ChannelPublicationScheduler($this->store,static fn():never=>throw new \LogicException('Canceled publication must not write'),fn():int=>$this->now,function()use(&$checks):void{if(++$checks===2)$this->scheduler()->cancel('1@newsletter','race');});
        $scheduler->schedule('1@newsletter',['type'=>'text','text'=>'Canceled'],$this->now+1,'race');$this->now++;
        self::assertSame([],$scheduler->drain());self::assertSame('canceled',$scheduler->get('1@newsletter','race')['state']);self::assertSame(0,$scheduler->get('1@newsletter','race')['attempts']);
    }

    public function testTimeoutRemainsUnknownAndCannotBeRescheduledOrAutomaticallyRepeated():void
    {
        $scheduler=$this->scheduler(function():array{throw new \RuntimeException('timeout with token=PRIVATE');});
        $row=$scheduler->schedule('1@newsletter',['type'=>'text','text'=>'A'],$this->now+1,'a');$this->now++;
        $result=$scheduler->drain()[0];self::assertSame('unknown',$result['state']);self::assertSame($row['messageId'],$result['messageId']);
        self::assertStringNotContainsString('PRIVATE',json_encode($result));self::assertSame([],$scheduler->drain());
        $this->expectException(\LogicException::class);$scheduler->reschedule('1@newsletter','a',$this->now+60);
    }

    public function testInterruptedPublicationIsRecoveredWithoutPublishingAgain():void
    {
        $scheduler=$this->scheduler();$row=$scheduler->schedule('1@newsletter',['type'=>'text','text'=>'A'],$this->now+1,'a');
        $row['state']='publishing';$row['attempts']=1;$this->store->set('channel-publication-schedules','1@newsletter:a',BinaryData::encode($row));
        $this->store->close();$this->store=new SignalStore($this->path);$this->now++;
        $result=$this->scheduler()->drain()[0];self::assertSame('unknown',$result['state']);
        self::assertSame('publication_interrupted',$result['failure']['code']);self::assertSame([],$this->published);
    }

    public function testReentrantDrainDoesNotPublishTwice():void
    {
        $scheduler=$this->scheduler(function(string $jid,array $content,array $options):array{
            self::assertSame([],$this->scheduler()->drain());$this->published[]=$options;
            return ['id'=>$options['id'],'status'=>'server_ack'];
        });
        $scheduler->schedule('1@newsletter',['type'=>'text','text'=>'A'],$this->now+1);$this->now++;
        self::assertSame('server_ack',$scheduler->drain()[0]['state']);self::assertCount(1,$this->published);
    }

    public function testServerRejectionIsRecordedWithoutInventingDelivery():void
    {
        $scheduler=$this->scheduler(function():array{throw new ServiceException('message.publish','479','not allowed');});
        $scheduler->schedule('1@newsletter',['type'=>'text','text'=>'A'],$this->now+1);$this->now++;
        $row=$scheduler->drain()[0];self::assertSame('failed',$row['state']);self::assertSame('479',$row['failure']['serverCode']);self::assertNull($row['sentAt']);
    }

    public function testWrongAcknowledgementDoesNotBecomeSuccess():void
    {
        $scheduler=$this->scheduler(fn():array=>['id'=>'OTHER','status'=>'server_ack']);
        $scheduler->schedule('1@newsletter',['type'=>'text','text'=>'A'],$this->now+1);$this->now++;
        self::assertSame('unknown',$scheduler->drain()[0]['state']);
    }

    public function testIdempotentScheduleDoesNotCreateAnotherMessageId():void
    {
        $scheduler=$this->scheduler();$content=['type'=>'poll','name'=>'Preferência','options'=>['A','B'],'selectableCount'=>1];
        $row=$scheduler->schedule('1@newsletter',$content,$this->now+60,'same');
        self::assertSame($row,$scheduler->schedule('1@newsletter',$content,$this->now+60,'same'));
        $this->expectException(\InvalidArgumentException::class);$scheduler->schedule('1@newsletter',['type'=>'text','text'=>'Outro'],$this->now+60,'same');
    }

    public function testBadInputsFailBeforeAnyPublicationOrPersistence():void
    {
        $invalid=[['1@g.us',['type'=>'text','text'=>'A'],$this->now+1],
            ['1@newsletter',['type'=>'text','text'=>'A'],$this->now],
            ['1@newsletter',['type'=>'text','text'=>'A'],$this->now+2592001],
            ['1@newsletter',['type'=>'image','media'=>'PRIVATE'],$this->now+1],
            ['1@newsletter',['type'=>'text','text'=>'A','contextInfo'=>['messageSecret'=>'PRIVATE']],$this->now+1],
            ['1@newsletter',['type'=>'poll','name'=>'A','options'=>['same','same']],$this->now+1],
            ['1@newsletter',['type'=>'poll','name'=>'A','options'=>['A','B'],'endTime'=>$this->now+1],$this->now+1]];
        foreach($invalid as[$jid,$content,$at]){
            try{$this->scheduler()->schedule($jid,$content,$at);self::fail('Invalid schedule accepted');}catch(\InvalidArgumentException){}
        }
        self::assertSame([],$this->scheduler()->list('1@newsletter'));self::assertSame([],$this->published);
        self::assertFalse(ChannelPublicationScheduler::capabilities()['nativeCreationSupported']);
    }
    public function testAuthorizationDeniedBeforeClaimLeavesQueuedAndDoesNotIncrementAttempts():void
    {
        $checks=0;$scheduler=new ChannelPublicationScheduler($this->store,function():array{self::fail('Publication must not start');},fn():int=>$this->now,function()use(&$checks):void{if(++$checks===2)throw new \RuntimeException('Suspended');});
        $row=$scheduler->schedule('1@newsletter',['type'=>'text','text'=>'A'],$this->now+1,'guard');$this->now++;
        try{$scheduler->drain();self::fail();}catch(\RuntimeException $error){self::assertSame('Suspended',$error->getMessage());}
        self::assertSame('queued',$scheduler->get('1@newsletter','guard')['state']);self::assertSame(0,$scheduler->get('1@newsletter','guard')['attempts']);
        $done=$this->scheduler()->drain();self::assertSame($row['messageId'],$done[0]['messageId']);self::assertSame(1,$done[0]['attempts']);
    }
    public function testAuthorizationDeniedAfterClaimRestoresQueuedAndExactId():void
    {
        $checks=0;$scheduler=new ChannelPublicationScheduler($this->store,function():array{self::fail('Publication must not start');},fn():int=>$this->now,function()use(&$checks):void{if(++$checks===3)throw new \RuntimeException('Deleted');});
        $row=$scheduler->schedule('1@newsletter',['type'=>'text','text'=>'A'],$this->now+1,'guard');$this->now++;
        try{$scheduler->drain();self::fail();}catch(\RuntimeException $error){self::assertSame('Deleted',$error->getMessage());}
        $restored=$scheduler->get('1@newsletter','guard');self::assertSame('queued',$restored['state']);self::assertSame(0,$restored['attempts']);self::assertSame($row['messageId'],$restored['messageId']);
        self::assertSame('server_ack',$this->scheduler()->drain()[0]['state']);
    }
    /** Real WaClient/Services/Newsletter serialization with a deterministic local transport. */
    private function guardedClient(object $control,bool $useTestWallClock=true):WaClient
    {
        $client=new WaClient(authDirectory:dirname($this->path).'/mvo-schedule-client-'.bin2hex(random_bytes(5)),clock:fn():float=>(float)$this->now,automaticMaintenance:false,wallClock:$useTestWallClock?fn():float=>(float)$this->now:null);
        $session=new class($control)implements NodeSession{
            private array $incoming=[];
            public function __construct(private object $control){}
            public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string{return '';}
            public function close():void{}
            public function sendNode(BinaryNode $node):void{
                $this->control->writes[]=$node;
                $this->incoming[]=$node->tag==='message'?new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]):new BinaryNode('iq',['type'=>'result','id'=>$node->attrs['id']]);
            }
            public function receiveNode(float $timeoutSeconds=1.0):?BinaryNode{
                if($this->control->suspendOnRead){$this->control->active=false;$this->control->suspendOnRead=false;}
                return array_shift($this->incoming);
            }
        };
        foreach(['stateStore'=>$this->store,'state'=>'connected','running'=>true,'session'=>$session,'lastPing'=>(float)$this->now]as$field=>$value)(new \ReflectionProperty(WaClient::class,$field))->setValue($client,$value);
        $client->setChannelScheduleGuard(function()use($control):void{if(!$control->active)throw new \RuntimeException('Instance authorization revoked');});
        $services=new Services(function(BinaryNode $node,float $timeout)use($client,$control):BinaryNode{
            if($node->tag==='message'&&$control->suspendAfterPreparation){
                $control->suspendAfterPreparation=false;
                $client->query(new BinaryNode('iq',['type'=>'get','xmlns'=>'test:preparation']),$timeout);
                $control->active=false;
            }
            return $client->query($node,$timeout);
        },$client->sendNode(...),static fn():array=>[]);
        (new \ReflectionProperty(WaClient::class,'services'))->setValue($client,$services);
        return $client;
    }
    private static function control():object{return (object)['active'=>true,'writes'=>[],'suspendOnRead'=>false,'suspendAfterPreparation'=>false];}
    private function closeGuardedClient(WaClient $client):void
    {
        $directory=$client->sessionDirectory();(new \ReflectionProperty(WaClient::class,'stateStore'))->setValue($client,null);$client->stop();unset($client);
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST)as$file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($directory);$parent=dirname($directory);if(is_dir($parent))rmdir($parent);
    }
    public function testWorkerPollReadRevocationPreventsAutomaticScheduledPublication():void
    {
        $control=self::control();$client=$this->guardedClient($control);
        try{
            $client->channelSchedules()->schedule('1@newsletter',['type'=>'text','text'=>'A'],$this->now+1,'poll');$this->now++;$control->suspendOnRead=true;
            $client->poll(0);self::assertSame([],$control->writes);$row=$client->channelSchedules()->get('1@newsletter','poll');self::assertSame('queued',$row['state']);self::assertSame(0,$row['attempts']);
        }finally{$this->closeGuardedClient($client);}
    }
    public function testDefaultClientScheduleUsesUnixWallTimeIndependentlyOfMonotonicClock():void
    {
        $this->now=1000;$client=$this->guardedClient(self::control(),false);
        try{$row=$client->channelSchedules()->schedule('1@newsletter',['type'=>'text','text'=>'A'],time()+60,'wall');self::assertSame('queued',$row['state']);self::assertGreaterThan(1700000000,$row['createdAt']);}
        finally{$this->closeGuardedClient($client);}
    }
    public function testRevocationDuringPreparationCannotWritePublicationAndCanResumeSameId():void
    {
        $control=self::control();$client=$this->guardedClient($control);
        try{
            $row=$client->channelSchedules()->schedule('1@newsletter',['type'=>'text','text'=>'A'],$this->now+1,'prepare');$this->now++;$control->suspendAfterPreparation=true;
            try{$client->channelSchedules()->drain();self::fail();}catch(ScheduledPublicationNotExecuted){}
            self::assertSame(['iq'],array_map(static fn(BinaryNode $n):string=>$n->tag,$control->writes));$restored=$client->channelSchedules()->get('1@newsletter','prepare');self::assertSame('queued',$restored['state']);self::assertSame(0,$restored['attempts']);
            $control->active=true;$done=$client->channelSchedules()->drain()[0];self::assertSame('server_ack',$done['state']);self::assertSame($row['messageId'],$done['messageId']);self::assertSame(['iq','message'],array_map(static fn(BinaryNode $n):string=>$n->tag,$control->writes));
        }finally{$this->closeGuardedClient($client);}
    }
    public function testRevocationAfterPublicationWriteNeverRestoresQueuedOrResends():void
    {
        $control=self::control();$client=$this->guardedClient($control);
        try{
            $client->on('node',function(array $event)use($client,$control):void{
                if($event['node']->tag==='ack'){$control->active=false;$client->sendNode(new BinaryNode('receipt',['id'=>'would-be-receipt']));}
            });
            $row=$client->channelSchedules()->schedule('1@newsletter',['type'=>'text','text'=>'A'],$this->now+1,'written');$this->now++;
            $done=$client->channelSchedules()->drain()[0];self::assertSame('unknown',$done['state']);self::assertSame(1,$done['attempts']);self::assertSame($row['messageId'],$done['messageId']);self::assertSame(['message'],array_map(static fn(BinaryNode $n):string=>$n->tag,$control->writes));
            $control->active=true;self::assertSame([],$client->channelSchedules()->drain());self::assertCount(1,$control->writes);
        }finally{$this->closeGuardedClient($client);}
    }
    public function testNestedPrewriteDenialIsNotProofThatAlreadyWrittenParentWasUnexecuted():void
    {
        $control=self::control();$client=$this->guardedClient($control);
        try{
            $client->on('node',function(array $event):void{if($event['node']->tag==='ack')throw new ScheduledPublicationNotExecuted(new \RuntimeException('Nested publication denied before its write'));});
            $client->channelSchedules()->schedule('1@newsletter',['type'=>'text','text'=>'A'],$this->now+1,'parent');$this->now++;
            self::assertSame('unknown',$client->channelSchedules()->drain()[0]['state']);self::assertSame([],$client->channelSchedules()->drain());self::assertCount(1,$control->writes);
        }finally{$this->closeGuardedClient($client);}
    }
    public function testWorkerPollRevocationKeepsDurableBatchPendingWithoutAnyMessageWrite():void
    {
        $control=self::control();$client=$this->guardedClient($control);
        try{
            $row=$client->publicationBatches()->create('message',['1@s.whatsapp.net'],['type'=>'text','text'=>'Never publish after suspension'],'batch-guard');$control->suspendOnRead=true;
            $client->poll(0);self::assertSame([],$control->writes);$fresh=$client->publicationBatches()->get('batch-guard');self::assertSame('running',$fresh['state']);self::assertSame('pending',$fresh['items'][0]['state']);self::assertSame(0,$fresh['items'][0]['attempts']);self::assertSame($row['items'][0]['messageId'],$fresh['items'][0]['messageId']);
        }finally{$this->closeGuardedClient($client);}
    }

}
