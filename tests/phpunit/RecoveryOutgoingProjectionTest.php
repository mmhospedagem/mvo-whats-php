<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Message\{ContentBuilder,MessageService};
use MVO\Protocol\{BinaryData,BinaryNode};
use MVO\Signal\{SignalProtocol,SignalStore};
use PHPUnit\Framework\TestCase;

/** ACK recovery repairs only local projections; it never retransmits a message. */
final class RecoveryOutgoingProjectionTest extends TestCase
{
    private const CHAT='222@s.whatsapp.net';
    private const AUTHOR='111@s.whatsapp.net';
    private const PENDING='message-outgoing-projection-pending-v1';
    private const DONE='message-outgoing-projection-done-v1';
    private string $path;
    private SignalStore $store;
    private MessageService $service;
    private array $credentials;
    private array $events=[];
    private int $queries=0;

    protected function setUp():void
    {
        $path=tempnam(sys_get_temp_dir(),'mvo-projection-');
        if($path===false)throw new \RuntimeException('Cannot create fixture');
        $this->path=$path;$this->credentials=CredentialStore::fresh();
        $this->credentials['registered']=true;$this->credentials['meJid']='111:5@s.whatsapp.net';$this->credentials['serverHasPreKeys']=true;
        $this->restart();
    }
    protected function tearDown():void
    {
        $this->store->close();unset($this->service,$this->store);gc_collect_cycles();
        foreach([$this->path,$this->path.'-wal',$this->path.'-shm']as$file)if(is_file($file))unlink($file);
    }
    private function restart(?\Closure $emit=null,?\Closure $query=null):void
    {
        if(isset($this->store)){$this->store->close();unset($this->service,$this->store);}
        $this->store=new SignalStore($this->path);
        $this->service=new MessageService(new SignalProtocol($this->store,$this->credentials),$this->store,
            fn():array=>$this->credentials,static function():void{},
            $query??function():BinaryNode{++$this->queries;throw new \LogicException('Recovery must not query or retransmit');},
            static function():void{},$emit??function(string $name,array $event):void{$this->events[]=[$name,$event];});
    }
    /** @return array<string,mixed> */
    private function persist(string $id,array $content):array
    {
        $content=(new \ReflectionMethod(MessageService::class,'hydrateAddon'))->invoke($this->service,$content,self::CHAT,$this->credentials);
        $built=ContentBuilder::build($content,self::CHAT,['outgoingId'=>$id,'meJid'=>self::AUTHOR,'nowMs'=>time()*1000]);
        $metadata=['id'=>$id,'to'=>self::CHAT,'status'=>'pending','timestamp'=>time(),'type'=>$built['attrs']['type']];
        (new \ReflectionMethod(MessageService::class,'persistOutgoing'))->invoke($this->service,new BinaryNode('message',['id'=>$id,'to'=>self::CHAT,'type'=>$built['attrs']['type']],[]),$metadata,$built,self::AUTHOR,[]);
        return $metadata;
    }
    private function ack(array $metadata):array
    {
        $metadata['status']='server_ack';$metadata['acknowledgedAt']=time();
        (new \ReflectionMethod(MessageService::class,'updateOutbox'))->invoke($this->service,$metadata);
        return $metadata;
    }
    private function poll():void
    {
        $metadata=$this->persist('PARENT',['type'=>'poll','name'=>'Choose','options'=>['A','B']]);$this->ack($metadata);
    }

    public function testPersistedAckQueueRecoversVoteAndAlbumAfterRecreatingServiceWithoutNetwork():void
    {
        $this->poll();
        $vote=$this->persist('VOTE',['type'=>'poll-vote','poll'=>['id'=>'PARENT'],'selectedOptionNames'=>['A']]);$this->ack($vote);
        $album=$this->persist('ALBUM',['type'=>'raw','message'=>['albumMessage'=>['expectedImageCount'=>1,'expectedVideoCount'=>1]]]);$this->ack($album);
        foreach([['IMAGE','imageMessage',0],['VIDEO','videoMessage',1]]as[$id,$field,$index]){
            $item=$this->persist($id,['type'=>'raw','message'=>[$field=>['caption'=>$id],'messageContextInfo'=>['messageAssociation'=>['associationType'=>'MEDIA_ALBUM','parentMessageKey'=>['id'=>'ALBUM','remoteJid'=>self::CHAT,'fromMe'=>true],'messageIndex'=>$index]]]]);$this->ack($item);
        }
        self::assertCount(5,$this->store->entries(self::PENDING));
        self::assertSame(0,$this->service->interactions()->pollResults(self::CHAT,'PARENT')['totalVoters']);
        $this->restart();$this->service->drainPending();
        self::assertSame(0,$this->queries);self::assertSame(1,$this->service->interactions()->pollResults(self::CHAT,'PARENT')['totalVoters']);
        self::assertSame([1,0],array_column($this->service->interactions()->pollResults(self::CHAT,'PARENT')['options'],'votes'));
        $albums=array_values(array_filter($this->events,static fn(array $event):bool=>$event[0]==='album'));
        self::assertCount(1,$albums);self::assertSame('ALBUM',$albums[0][1]['parentKey']['id']);self::assertSame(['IMAGE','VIDEO'],array_column($albums[0][1]['items'],'messageId'));
        self::assertSame([],$this->store->entries(self::PENDING));self::assertCount(5,$this->store->entries(self::DONE));
        $this->service->retryMessage('VOTE');$this->service->retryMessage('VIDEO');$this->service->drainPending();
        self::assertSame(0,$this->queries);self::assertSame(1,$this->service->interactions()->pollResults(self::CHAT,'PARENT')['totalVoters']);
        self::assertCount(1,array_filter($this->events,static fn(array $event):bool=>$event[0]==='album'));
        self::assertNull($this->store->getSession(self::CHAT),'Local recovery cannot create or change a Signal ratchet');
    }

    public function testThrowingAckCallbackCannotPreventCommittedVoteProjection():void
    {
        $this->poll();$vote=$this->persist('VOTE',['type'=>'poll-vote','poll'=>['id'=>'PARENT'],'selectedOptionNames'=>['B']]);
        $this->restart(static function(string $event):void{if($event==='message_ack')throw new \RuntimeException('Application callback failed');},function(BinaryNode $node):BinaryNode{++$this->queries;return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);});
        try{(new \ReflectionMethod(MessageService::class,'submit'))->invoke($this->service,new BinaryNode('message',['id'=>'VOTE','to'=>self::CHAT],[]),$vote);self::fail('Callback did not throw');}
        catch(\RuntimeException $error){self::assertSame('Application callback failed',$error->getMessage());}
        self::assertSame('server_ack',$this->service->getMessageStatus('VOTE')['status']);
        self::assertSame([0,1],array_column($this->service->interactions()->pollResults(self::CHAT,'PARENT')['options'],'votes'));
        self::assertNotNull($this->store->get(self::DONE,'VOTE'));
        $this->restart();$this->service->retryMessage('VOTE');self::assertSame(1,$this->queries,'Retry of a committed ACK is local only');
    }

    public function testAlbumCallbackRetryUsesDurablePayloadWithoutReaggregatingChildren():void
    {
        foreach([['ALBUM',['albumMessage'=>['expectedImageCount'=>1,'expectedVideoCount'=>0]]],['IMAGE',['imageMessage'=>['caption'=>'One'],'messageContextInfo'=>['messageAssociation'=>['associationType'=>'MEDIA_ALBUM','parentMessageKey'=>['id'=>'ALBUM','remoteJid'=>self::CHAT,'fromMe'=>true],'messageIndex'=>0]]]]]as[$id,$proto])$this->ack($this->persist($id,['type'=>'raw','message'=>$proto]));
        $this->restart(static function(string $event):void{if($event==='album')throw new \RuntimeException('Album callback failed');});
        $this->service->drainPending();
        self::assertNotNull($this->store->get(self::PENDING,'IMAGE'));$marker=BinaryData::decode($this->store->get(self::DONE,'IMAGE'));
        self::assertSame('ALBUM',$marker['albumEvent']['parentKey']['id']);
        $this->restart();$this->service->drainPending();$this->service->drainPending();
        $albums=array_values(array_filter($this->events,static fn(array $event):bool=>$event[0]==='album'));
        self::assertCount(1,$albums);self::assertSame(1,$albums[0][1]['expectedCount']);self::assertSame([],$this->store->entries(self::PENDING));self::assertSame(0,$this->queries);
    }

    public function testPendingProjectionBatchIsBoundedAndUnknownSendsAreNotReplayed():void
    {
        for($i=0;$i<20;$i++)$this->ack($this->persist(sprintf('ACK%02d',$i),['type'=>'text','text'=>'Local']));
        $unknown=$this->persist('UNKNOWN',['type'=>'text','text'=>'Unconfirmed']);$unknown['status']='unknown';
        (new \ReflectionMethod(MessageService::class,'updateOutbox'))->invoke($this->service,$unknown);
        self::assertNull($this->store->get(self::PENDING,'UNKNOWN'));
        $this->restart();$this->service->drainPending();self::assertCount(16,$this->store->entries(self::DONE));self::assertCount(4,$this->store->entries(self::PENDING));
        $this->service->drainPending();self::assertCount(20,$this->store->entries(self::DONE));self::assertSame([],$this->store->entries(self::PENDING));self::assertSame(0,$this->queries);
        self::assertSame('unknown',$this->service->getMessageStatus('UNKNOWN')['status']);
    }

    public function testAlbumCallbackCanRetryItsOwnAcknowledgedChildWithoutReentrantEmission():void
    {
        foreach([['ALBUM',['albumMessage'=>['expectedImageCount'=>1,'expectedVideoCount'=>0]]],['IMAGE',['imageMessage'=>['caption'=>'One'],'messageContextInfo'=>['messageAssociation'=>['associationType'=>'MEDIA_ALBUM','parentMessageKey'=>['id'=>'ALBUM','remoteJid'=>self::CHAT,'fromMe'=>true],'messageIndex'=>0]]]]]as[$id,$proto])$this->ack($this->persist($id,['type'=>'raw','message'=>$proto]));
        $callbacks=0;
        $this->restart(function(string $event)use(&$callbacks):void{
            if($event!=='album')return;
            ++$callbacks;
            // The bound keeps this regression test finite if the guard is removed.
            if($callbacks<3)$this->service->retryMessage('IMAGE');
        });
        $this->service->drainPending();
        self::assertSame(1,$callbacks);self::assertSame(0,$this->queries);self::assertSame([],$this->store->entries(self::PENDING));
        $this->service->retryMessage('IMAGE');$this->service->drainPending();self::assertSame(1,$callbacks);
    }
    public function testAcknowledgedOutgoingTextReactionsEditsAndRevocationsReachObservedHistory():void
    {
        $this->ack($this->persist('ORIGINAL',['type'=>'text','text'=>'Original outgoing']));$this->restart();$this->service->drainPending();
        $history=$this->service->history()->store;self::assertSame('Original outgoing',$history->getMessage(self::CHAT,'ORIGINAL')['text']);
        $target=['id'=>'ORIGINAL','remoteJid'=>self::CHAT,'fromMe'=>true];
        $this->ack($this->persist('REACTION',['type'=>'reaction','target'=>$target,'emoji'=>'👍']));$this->restart();$this->service->drainPending();$history=$this->service->history()->store;
        self::assertSame(1,$history->messageObservations()->reactions(self::CHAT,'ORIGINAL')['totalReactions']);
        $this->ack($this->persist('EDIT',['type'=>'edit','target'=>$target,'text'=>'Edited outgoing']));$this->restart();$this->service->drainPending();$history=$this->service->history()->store;
        self::assertSame('Edited outgoing',$history->getMessage(self::CHAT,'ORIGINAL')['text']);self::assertSame(1,$history->messageObservations()->revisions(self::CHAT,'ORIGINAL')['totalRevisions']);
        $this->ack($this->persist('REVOKE',['type'=>'revoke','target'=>$target]));$this->restart();$this->service->drainPending();$history=$this->service->history()->store;
        self::assertTrue($history->getMessage(self::CHAT,'ORIGINAL')['revoked']);self::assertSame(2,$history->messageObservations()->revisions(self::CHAT,'ORIGINAL')['totalRevisions']);
        self::assertSame(0,$this->queries,'ACK recovery must remain a local projection without transmission');
    }
}
