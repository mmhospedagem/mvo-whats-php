<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Message\MessageCodec;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Voip\{GroupCallState,GroupMediaSession,RelayTransport,VoipService};

final class VoipGroupTransportTest extends TestCase
{
    private static function info(int $epoch=1):BinaryNode { return new BinaryNode('group_info',['media'=>'video','connected_limit'=>'32','transaction-id'=>(string)$epoch,'rekey'=>'1'],[new BinaryNode('user',['jid'=>'111@lid','state'=>'connected'],[new BinaryNode('device',['jid'=>'111:1@lid','pid'=>'0'])]),new BinaryNode('user',['jid'=>'222@lid','state'=>'connected'],[new BinaryNode('device',['jid'=>'222:2@lid','pid'=>'1'])])]); }
    public function testConnectMediaBindsGroupPlanWithoutTreatingAllocationOrSpoofAsConnected():void
    {
        $descriptors=[];$legs=[];$key=null;$events=[];$fault=false;$sent=[];$now=1000.0;
        $service=new VoipService(static fn()=>['meLid'=>'111:1@lid'],static fn($node)=>new BinaryNode('ack',['class'=>'call','id'=>$node->attrs['id']],[new BinaryNode('relay',[],[new BinaryNode('key',[],str_repeat('a',32)),new BinaryNode('token',['id'=>'0'],'raw-token'),new BinaryNode('te',['token_id'=>'0'],inet_pton('127.0.0.1').pack('n',5000))])]),function($node)use(&$sent){$sent[]=$node;},static fn()=>['222:2@lid'],function($devices,$padded)use(&$key){$key=SchemaCodec::decode('Message',MessageCodec::unpad($padded))['call']['callKey'];return ['nodes'=>[new BinaryNode('to',['jid'=>$devices[0]],[new BinaryNode('enc',['type'=>'msg'],$padded)])],'deviceIdentity'=>null];},static fn($from,$type,$cipher)=>$cipher,function($name,$data)use(&$events){$events[]=['name'=>$name,'data'=>$data];},fn()=>$now,function($descriptor)use(&$descriptors,&$legs,&$fault){$descriptors[]=$descriptor;return $legs[]=new VoipGroupMemoryRelay($fault);});
        $id=$service->offerGroup('123@g.us',['222@lid'])['callId'];$service->handleNode(new BinaryNode('call',['from'=>$id.'@call','id'=>'UPDATE'],[new BinaryNode('group_update',['call-id'=>$id,'call-creator'=>'111:1@lid'],[self::info()])]));$service->drainPending();self::assertNotNull($key);$session=$service->connectMedia($id);self::assertInstanceOf(GroupMediaSession::class,$session);self::assertSame('webrtc',$descriptors[0]['transport']);self::assertSame(3480,$descriptors[0]['fallbackPort']);self::assertSame(5000,$descriptors[0]['port']);self::assertNotEmpty($descriptors[0]['streamDescriptor']);self::assertCount(10,$legs[0]->self);self::assertCount(10,$legs[0]->peers);self::assertSame('connecting',$service->getCall($id)['state']);
        $roster=new GroupCallState($id);$roster->applyRoster(self::info(),$id.'@call','222:2@lid');$peer=new GroupMediaSession($id,'222:2@lid',$roster);$peer->setVideoCoverage(true);$peer->rotateKey($key,1);$packet=$peer->protectPayload('real-opus',160);$bad=$packet;$bad[strlen($bad)-1]=chr(ord($bad[strlen($bad)-1])^1);$legs[0]->packets=[$bad,$packet];$service->tick();self::assertSame('connected',$service->getCall($id)['state']);self::assertCount(1,array_filter($events,static fn($event)=>$event['name']==='voip_media_error' && $event['data']['reason']==='invalid_media_packet'));
        // Reissue is queued by the receive handler; the next poll opens the replacement leg.
        $reissue=new BinaryNode('relay',[],[new BinaryNode('key',[],str_repeat('b',32)),new BinaryNode('token',['id'=>'0'],'replacement-token'),new BinaryNode('te',['token_id'=>'0'],inet_pton('127.0.0.1').pack('n',5001))]);$service->handleNode(new BinaryNode('call',['from'=>$id.'@call','id'=>'REISSUE'],[new BinaryNode('group_update',['call-id'=>$id,'call-creator'=>'111:1@lid'],[self::info(2),$reissue])]));self::assertCount(1,$descriptors);$service->tick();self::assertCount(2,$descriptors);self::assertSame(5001,$descriptors[1]['port']);
        $fault=true;$service->tick();$service->tick();self::assertSame('ended',$service->getCall($id)['state']);self::assertSame('transport_failed',$service->getCall($id)['endReason']);
    }
    public function testRxSubscriptionsRetainPidZeroAndDropRemovedDevices():void
    {
        $state=new GroupCallState('CALL');$state->applyRoster(self::info(),'CALL@call','222:2@lid');$session=new GroupMediaSession('CALL','222:2@lid',$state);$session->setVideoCoverage(true);$session->setVideoSubscriptions([['participantJid'=>'111:1@lid','quality'=>3]]);self::assertSame([['pid'=>0,'quality'=>3]],$session->rxSubscriptions());$state->removeDepartedParticipant('111:1@lid');$session->updateRoster();self::assertSame([],$session->rxSubscriptions());
    }
    public function testPollDropsAtMost64MalformedPacketsPerTurn():void
    {
        $state=new GroupCallState('CALL');$state->applyRoster(self::info(),'CALL@call','111:1@lid');$session=new GroupMediaSession('CALL','111:1@lid',$state);$attempts=0;$errors=0;$session=new GroupMediaSession('CALL','111:1@lid',$state,function($event)use(&$errors){if($event==='voip_media_error')++$errors;});$session->setTransport(static fn()=>null,function()use(&$attempts){++$attempts;return 'bad';});$session->poll();self::assertSame(64,$attempts);self::assertSame(64,$errors);self::assertSame([],$session->readyDevices());
    }
}

final class VoipGroupMemoryRelay implements RelayTransport
{
    public array $packets=[];public array $self=[];public array $peers=[];public bool $closed=false;
    public function __construct(private bool &$fault) {}
    public function connect(array $selfSsrcs,array $peerSsrcs=[]):void { $this->self=$selfSsrcs;$this->peers=$peerSsrcs; }
    public function send(string $packet):void {}
    public function receive(float $timeout=0.0):?string { return array_shift($this->packets); }
    public function tick():void { if($this->fault)throw new \RuntimeException('Synthetic transport failure'); }
    public function close():void { $this->closed=true; }
    public function reregister():void {}
    public function updateSubscriptions(array $selfSsrcs,array $peerSsrcs,?string $streamDescriptor=null,?string $rxSubscriptions=null):void { $this->self=$selfSsrcs;$this->peers=$peerSsrcs; }
    public function metrics():array { return ['open'=>!$this->closed,'allocated'=>true,'lastPongAt'=>0.0,'openedAt'=>1000.0,'rttMs'=>1]; }
}
