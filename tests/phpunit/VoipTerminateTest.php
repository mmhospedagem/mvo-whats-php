<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Message\MessageCodec;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Voip\{MediaSession,VoipService};
use PHPUnit\Framework\TestCase;

/** Termination is a single signaling write, never a blocking IQ/ACK transaction. */
final class VoipTerminateTest extends TestCase
{
    private VoipService $service;
    private float $now=10;
    /** @var list<BinaryNode> */private array $queries=[];
    /** @var list<BinaryNode> */private array $writes=[];
    private ?\Closure $onWrite=null;

    protected function setUp():void
    {
        $plain=MessageCodec::pad(SchemaCodec::encode('Message',['call'=>['callKey'=>str_repeat('k',32)]]));
        $this->service=new VoipService(static fn():array=>['meJid'=>'222:2@lid','meLid'=>'222:2@lid'],
            function(BinaryNode $node,float $timeout):BinaryNode{$this->queries[]=$node;return new BinaryNode('ack',['class'=>'call','id'=>$node->attrs['id']]);},
            function(BinaryNode $node):void{$this->writes[]=$node;($this->onWrite)?->__invoke($node);},
            static fn():array=>['111:1@lid'],static fn():array=>throw new \LogicException('No key distribution in this fixture'),
            static fn():string=>$plain,static function(string $event,array $data):void{},fn():float=>$this->now);
        $this->service->handleNode(new BinaryNode('call',['from'=>'111:1@lid','id'=>'fixture-offer'],[
            new BinaryNode('offer',['call-id'=>'TERMINATE-CALL','call-creator'=>'111:1@lid'],[
                new BinaryNode('audio',['enc'=>'opus','rate'=>'16000']),new BinaryNode('enc',['type'=>'msg'],'fixture-key')])]));
        $this->service->accept('TERMINATE-CALL');$this->queries=[];$this->writes=[];
    }
    protected function tearDown():void{$this->onWrite=null;$this->service->close();}

    public function testLocalEndAndMediaClosePrecedeTheOnlyWriteWithoutQuery():void
    {
        $media=$this->service->media('TERMINATE-CALL');
        $this->onWrite=function(BinaryNode $node)use($media):void{
            self::assertNotNull($node->child('terminate'));self::assertSame('ended',$this->service->getCall('TERMINATE-CALL')['state']);
            try{$media->protectPayload('fixture',0);self::fail('Local media must close before signaling');}catch(\LogicException){}
        };
        $this->service->terminate('TERMINATE-CALL');self::assertSame([],$this->queries);self::assertCount(1,$this->writes);
        $node=$this->writes[0];self::assertSame('111:1@lid',$node->attrs['to']);
        self::assertSame(['call-id'=>'TERMINATE-CALL','call-creator'=>'111:1@lid'],$node->child('terminate')->attrs);
        self::assertSame('user_ended',$this->service->getCall('TERMINATE-CALL')['endReason']);
    }

    public function testOnlyConnectedCallsSendDurationsAndExplicitRemoteReason():void
    {
        $peer=new MediaSession('TERMINATE-CALL',str_repeat('k',32),'111:1@lid','222:2@lid');
        try{$this->service->media('TERMINATE-CALL')->handleDatagram($peer->protectPayload('fixture-audio',16000));}finally{$peer->close();}
        self::assertSame('connected',$this->service->getCall('TERMINATE-CALL')['state']);$this->now=12.5;
        $this->service->terminate('TERMINATE-CALL','timeout');self::assertSame([],$this->queries);self::assertCount(1,$this->writes);
        self::assertSame(['call-id'=>'TERMINATE-CALL','call-creator'=>'111:1@lid','duration'=>'2500','audio_duration'=>'2500','reason'=>'timeout'],$this->writes[0]->child('terminate')->attrs);
    }

    public function testWriteFailurePropagatesAfterLocalEndWithoutReplay():void
    {
        $cause=new \RuntimeException('Synthetic failure after termination write');
        $this->onWrite=static function()use($cause):void{throw $cause;};$failure=null;
        try{$this->service->terminate('TERMINATE-CALL');}catch(\Throwable $error){$failure=$error;}
        self::assertSame($cause,$failure);self::assertSame('ended',$this->service->getCall('TERMINATE-CALL')['state']);
        self::assertSame([],$this->queries);self::assertCount(1,$this->writes);
        $this->now=20;$this->service->terminate('TERMINATE-CALL');$this->service->tick();
        self::assertSame([],$this->queries);self::assertCount(1,$this->writes);
    }

    public function testRepeatedTerminationIsIdempotentAndPreservesFirstEndReason():void
    {
        $this->service->terminate('TERMINATE-CALL');$ended=$this->service->getCall('TERMINATE-CALL');
        $this->now=20;$this->service->terminate('TERMINATE-CALL','timeout');self::assertSame($ended,$this->service->getCall('TERMINATE-CALL'));
        self::assertSame([],$this->queries);self::assertCount(1,$this->writes);
    }
}
