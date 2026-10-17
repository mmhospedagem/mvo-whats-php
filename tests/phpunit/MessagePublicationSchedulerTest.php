<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Protocol\BinaryData;
use MVO\Service\{MessagePublicationScheduler,ScheduledPublicationNotExecuted,ScheduledMessagePreparationFailed};
use MVO\Signal\SignalStore;
use PHPUnit\Framework\TestCase;

final class MessagePublicationSchedulerTest extends TestCase
{
    private string $path;
    private SignalStore $store;
    private int $now=1800000000;
    /** @var list<array<string,mixed>> */
    private array $published=[];
    protected function setUp():void{$this->path=tempnam(sys_get_temp_dir(),'mvo-msg-schedule-');$this->store=new SignalStore($this->path);}
    protected function tearDown():void{$this->store->close();foreach([$this->path,$this->path.'-wal',$this->path.'-shm']as$file)if(is_file($file))unlink($file);}
    private function scheduler(?\Closure $publish=null,?\Closure $guard=null):MessagePublicationScheduler
    {
        return new MessagePublicationScheduler($this->store,$publish??function(string $jid,array $content,array $options):array{$this->published[]=['to'=>$jid,'content'=>$content,'options'=>$options];return ['id'=>$options['id'],'to'=>$jid,'status'=>'server_ack','timestamp'=>$this->now,'mediaKey'=>'PRIVATE'];},fn():int=>$this->now,$guard);
    }
    public function testRestartKeepsExactIdAndPublishesOnceOnlyAfterDueTime():void
    {
        $row=$this->scheduler()->schedule('+55 11 99999-9999',['type'=>'text','text'=>'Lembrete'],$this->now+60,'first');
        self::assertSame('5511999999999@s.whatsapp.net',$row['chatJid']);self::assertSame([],$this->scheduler()->drain());
        $this->store->close();$this->store=new SignalStore($this->path);$this->now+=60;$sent=$this->scheduler()->drain();
        self::assertSame('server_ack',$sent[0]['state']);self::assertSame($row['messageId'],$this->published[0]['options']['id']);self::assertArrayNotHasKey('mediaKey',$sent[0]['result']);
        self::assertSame([],$this->scheduler()->drain());self::assertCount(1,$this->published);
    }
    public function testMediaSnapshotSurvivesTemporarySourceRemovalAndRestartWithoutLeakingBytes():void
    {
        $source=tempnam(sys_get_temp_dir(),'mvo-source-');$bytes="PNG\0PRIVATE-MEDIA";file_put_contents($source,$bytes);
        try{$row=$this->scheduler()->schedule('120363111@g.us',['type'=>'image','media'=>['path'=>$source],'fileName'=>'foto.png','mimetype'=>'image/png','caption'=>'Legenda'],$this->now+1,'image');}
        finally{unlink($source);}
        self::assertSame(strlen($bytes),$row['media']['size']);self::assertSame(hash('sha256',$bytes),$row['media']['sha256']);self::assertStringNotContainsString('PRIVATE-MEDIA',json_encode($row));self::assertStringNotContainsString($source,json_encode($row));
        $this->store->close();$this->store=new SignalStore($this->path);$this->now++;
        $sent=$this->scheduler()->drain();self::assertSame('server_ack',$sent[0]['state']);self::assertSame($bytes,$this->published[0]['content']['media']['bytes']);self::assertNull($this->store->get('message-publication-schedule-assets','image'));
    }
    public function testIdempotentScheduleRejectsChangedRecipientContentTimeOrMedia():void
    {
        $queue=$this->scheduler();$content=['type'=>'document','media'=>['bytes'=>'one'],'fileName'=>'one.txt'];$row=$queue->schedule('1@s.whatsapp.net',$content,$this->now+60,'stable');
        self::assertSame($row,$queue->schedule('1@s.whatsapp.net',$content,$this->now+60,'stable'));
        foreach([['2@s.whatsapp.net',$content,$this->now+60],['1@s.whatsapp.net',$content,$this->now+61],['1@s.whatsapp.net',array_replace($content,['media'=>['bytes'=>'two']]),$this->now+60]]as[$to,$body,$when]){
            try{$queue->schedule($to,$body,$when,'stable');self::fail('Changed schedule accepted');}catch(\InvalidArgumentException){self::assertSame($row,$queue->get('stable'));}
        }
    }
    public function testCancelAndRescheduleKeepScopeAndDiscardCanceledAssets():void
    {
        $queue=$this->scheduler();$queue->schedule('1@s.whatsapp.net',['type'=>'text','text'=>'A'],$this->now+60,'a');$queue->schedule('2@s.whatsapp.net',['type'=>'document','media'=>['bytes'=>'private']],$this->now+60,'b');
        self::assertSame(['a'],array_column($queue->list(50,'','1@s.whatsapp.net'),'id'));
        self::assertSame($this->now+120,$queue->reschedule('a',$this->now+120)['sendAt']);self::assertSame('canceled',$queue->cancel('b')['state']);self::assertNull($this->store->get('message-publication-schedule-assets','b'));
        $this->now+=60;self::assertSame([],$queue->drain());$this->now+=60;self::assertSame(['a'],array_column($queue->drain(),'id'));
    }
    public function testUnknownResultOrExceptionIsNeverAutomaticallyResent():void
    {
        $count=0;$queue=$this->scheduler(function()use(&$count):array{++$count;throw new \RuntimeException('token=PRIVATE');});$row=$queue->schedule('1@s.whatsapp.net',['type'=>'text','text'=>'A'],$this->now+1,'a');$this->now++;
        $result=$queue->drain()[0];self::assertSame('unknown',$result['state']);self::assertSame($row['messageId'],$result['messageId']);self::assertStringNotContainsString('PRIVATE',json_encode($result));self::assertSame([],$queue->drain());self::assertSame(1,$count);
        $this->expectException(\LogicException::class);$queue->reschedule('a',$this->now+60);
    }
    public function testInterruptedPublicationRecoveryPreservesIdWithoutInvokingPublisher():void
    {
        $queue=$this->scheduler();$row=$queue->schedule('1@s.whatsapp.net',['type'=>'text','text'=>'A'],$this->now+1,'a');$row['state']='publishing';$row['attempts']=1;$this->store->set('message-publication-schedules','a',BinaryData::encode($row));$this->now++;
        $recovered=$queue->drain()[0];self::assertSame('unknown',$recovered['state']);self::assertSame('publication_interrupted',$recovered['failure']['code']);self::assertSame($row['messageId'],$recovered['messageId']);self::assertSame([],$this->published);
    }
    public function testMissingOrCorruptSnapshotCannotReachPublisher():void
    {
        $queue=$this->scheduler();$queue->schedule('1@s.whatsapp.net',['type'=>'document','media'=>['bytes'=>'expected']],$this->now+1,'a');$this->store->set('message-publication-schedule-assets','a','corrupt');$this->now++;
        $result=$queue->drain()[0];self::assertSame('failed',$result['state']);self::assertSame('scheduled_asset_unavailable',$result['failure']['code']);self::assertSame([],$this->published);
    }
    public function testFreshGuardDenialLeavesQueuedAndDoesNotAttemptPublication():void
    {
        $queue=$this->scheduler(guard:static function():void{throw new \LogicException('suspended');});$row=$queue->schedule('1@s.whatsapp.net',['type'=>'text','text'=>'A'],$this->now+1,'a');$this->now++;
        try{$queue->drain();self::fail('Guard bypass');}catch(\LogicException){self::assertSame('queued',$queue->get('a')['state']);self::assertSame(0,$queue->get('a')['attempts']);self::assertSame($row['messageId'],$queue->get('a')['messageId']);self::assertSame([],$this->published);}
    }
    public function testDenialInsidePreparationRestoresOnlyProvenNoWriteAttempt():void
    {
        $queue=$this->scheduler(static function():array{throw new ScheduledPublicationNotExecuted(new \LogicException('suspended'));});$queue->schedule('1@s.whatsapp.net',['type'=>'text','text'=>'A'],$this->now+1,'a');$this->now++;
        try{$queue->drain();self::fail('Guard bypass');}catch(ScheduledPublicationNotExecuted){self::assertSame('queued',$queue->get('a')['state']);self::assertSame(0,$queue->get('a')['attempts']);}
    }
    public function testProvenPreparationFailureIsTerminalNotExecutedWithoutRawExceptionText():void
    {
        $queue=$this->scheduler(static function():array{throw new ScheduledMessagePreparationFailed(new \InvalidArgumentException('PRIVATE'));});$queue->schedule('1@s.whatsapp.net',['type'=>'text','text'=>'A'],$this->now+1,'a');$this->now++;
        $result=$queue->drain()[0];self::assertSame('failed',$result['state']);self::assertSame('not_executed',$result['failure']['outcome']);self::assertStringNotContainsString('PRIVATE',json_encode($result));self::assertSame([],$queue->drain());
    }
    public function testReentrantDrainCannotPublishSameClaimTwice():void
    {
        $queue=null;$calls=0;$queue=$this->scheduler(function(string $to,array $content,array $options)use(&$queue,&$calls):array{++$calls;self::assertSame([],$queue->drain());return ['id'=>$options['id'],'status'=>'server_ack'];});
        $queue->schedule('1@s.whatsapp.net',['type'=>'text','text'=>'A'],$this->now+1,'a');$this->now++;self::assertSame('server_ack',$queue->drain()[0]['state']);self::assertSame(1,$calls);
    }
    public function testInvalidOrUnsupportedRequestsDoNotCreateRecords():void
    {
        $bad=[['status@broadcast',['type'=>'text','text'=>'A'],$this->now+1],['1@newsletter',['type'=>'text','text'=>'A'],$this->now+1],['1@s.whatsapp.net',['type'=>'raw','message'=>[]],$this->now+1],['1@s.whatsapp.net',['type'=>'text','text'=>'A'],$this->now],['1@s.whatsapp.net',['type'=>'text','text'=>'A'],$this->now+2592001],['1@s.whatsapp.net',['type'=>'poll','name'=>'A','options'=>['yes','no'],'endTime'=>$this->now+1],$this->now+2]];
        foreach($bad as[$to,$content,$when]){try{$this->scheduler()->schedule($to,$content,$when);self::fail('Invalid schedule accepted');}catch(\InvalidArgumentException){self::assertSame([],$this->scheduler()->list());}}
    }
    public function testMediaMimeMustMatchTheScheduledTypeBeforeAnySnapshotIsPersisted():void
    {
        foreach(['image'=>'video/mp4','video'=>'image/jpeg','audio'=>'video/mp4','ptt'=>'application/pdf','sticker'=>'image/png']as$type=>$mime){
            try{$this->scheduler()->schedule('1@s.whatsapp.net',['type'=>$type,'media'=>['bytes'=>'fixture'],'mimetype'=>$mime],$this->now+60,$type);self::fail('Incompatible scheduled MIME accepted');}
            catch(\InvalidArgumentException){self::assertSame([],$this->scheduler()->list());self::assertNull($this->store->get('message-publication-schedule-assets',$type));}
        }
    }
    public function testCompatibleMediaMimeAndArbitraryValidDocumentMimeRemainSupported():void
    {
        foreach(['image'=>'image/png','video'=>'video/mp4','audio'=>'audio/mpeg','ptt'=>'audio/ogg','sticker'=>'image/webp','document'=>'application/vnd.mvo+json']as$type=>$mime){$row=$this->scheduler()->schedule('1@s.whatsapp.net',['type'=>$type,'media'=>['bytes'=>'fixture'],'mimetype'=>$mime],$this->now+60,$type);self::assertSame('queued',$row['state']);self::assertSame($mime,$row['content']['mimetype']);}
        self::assertCount(6,$this->scheduler()->list());
    }
    public function testConcurrentRescheduleAfterDueReadPreventsEarlyPublication():void
    {
        $calls=0;$queue=null;$queue=$this->scheduler(guard:function()use(&$calls,&$queue):void{if(++$calls===2)$queue->reschedule('race',$this->now+600);});
        $queue->schedule('1@s.whatsapp.net',['type'=>'text','text'=>'A'],$this->now+1,'race');$this->now++;
        self::assertSame([],$queue->drain());self::assertSame([],$this->published);self::assertSame('queued',$queue->get('race')['state']);self::assertSame(0,$queue->get('race')['attempts']);
        $this->now+=600;self::assertSame('server_ack',$queue->drain()[0]['state']);self::assertCount(1,$this->published);
    }
    public function testConcurrentCancellationAfterDueReadIsNeverOverwritten():void
    {
        $calls=0;$queue=null;$queue=$this->scheduler(guard:function()use(&$calls,&$queue):void{if(++$calls===2)$queue->cancel('race');});
        $queue->schedule('1@s.whatsapp.net',['type'=>'text','text'=>'A'],$this->now+1,'race');$this->now++;
        self::assertSame([],$queue->drain());self::assertSame([],$this->published);self::assertSame('canceled',$queue->get('race')['state']);self::assertSame(0,$queue->get('race')['attempts']);
    }
}
