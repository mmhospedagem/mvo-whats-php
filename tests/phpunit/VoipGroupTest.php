<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Crypto\Crypto;
use MVO\Message\MessageCodec;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Voip\{AppDataProtocol,GroupCallState,GroupMediaSession,VoipService};

final class VoipGroupTest extends TestCase
{
    private const A='111:1@lid';private const B='222:2@lid';private const C='333:0@lid';
    private array $sent=[];private array $queries=[];private array $events=[];private float $clock=1000.0;private string $signalKey;
    protected function setUp():void { $this->signalKey=random_bytes(32); }
    private function service(string $own):VoipService
    {
        return new VoipService(static fn()=>['meLid'=>$own],function(BinaryNode $node)use($own){$this->queries[$own][]=$node;return new BinaryNode('ack',['id'=>$node->attrs['id'],'class'=>'call']);},function(BinaryNode $node)use($own){$this->sent[$own][]=$node;},static fn($jid)=>match($jid){'222@lid'=>[self::B],'333@lid'=>[self::C],default=>[self::A]},function($devices,$padded){$nodes=[];foreach($devices as $device){$nonce=random_bytes(12);$nodes[]=new BinaryNode('to',['jid'=>$device],[new BinaryNode('enc',['v'=>'2','type'=>'msg'],$nonce.Crypto::encryptGcm($this->signalKey,$nonce,$padded))]);}return ['nodes'=>$nodes,'deviceIdentity'=>null];},fn($from,$type,$cipher)=>Crypto::decryptGcm($this->signalKey,substr($cipher,0,12),substr($cipher,12)),function($name,$data)use($own){$this->events[$own][]=['name'=>$name,'data'=>$data];},fn()=>$this->clock);
    }
    private static function info(int $transaction,array $devices=[self::A,self::B,self::C],bool $rekey=false):BinaryNode
    {
        $users=[];foreach($devices as $i=>$device)$users[]=new BinaryNode('user',['jid'=>GroupCallState::account($device),'state'=>'connected'],[new BinaryNode('device',['jid'=>$device,'pid'=>(string)$i])]);return new BinaryNode('group_info',['media'=>'audio','connected_limit'=>'32','transaction-id'=>(string)$transaction,...($rekey?['rekey'=>'1']:[])],$users);
    }
    private static function update(string $id,int $transaction,array $devices=[self::A,self::B,self::C],bool $rekey=false):BinaryNode { return new BinaryNode('call',['from'=>$id.'@call','id'=>'ROSTER'.$transaction],[new BinaryNode('group_update',['call-id'=>$id,'call-creator'=>self::A],[self::info($transaction,$devices,$rekey)])]); }
    private static function incoming(BinaryNode $node,string $from):BinaryNode { return new BinaryNode('call',['from'=>$from,'id'=>$node->attrs['id']],$node->content); }
    private function offerTo(VoipService $receiver,string $id,int $transaction=1):void { $receiver->handleNode(new BinaryNode('call',['from'=>$id.'@call','id'=>'OFFER'],[new BinaryNode('offer',['call-id'=>$id,'call-creator'=>self::A,'group-jid'=>'123@g.us','joinable'=>'1'],[self::info($transaction)])])); }
    private function rekeys(string $own):array { return array_values(array_filter($this->sent[$own]??[],static fn($node)=>$node->child('enc_rekey')!==null)); }
    private function encryptedRekey(string $id,string $key,int $transaction,string $sender=self::A):BinaryNode { $padded=MessageCodec::pad(SchemaCodec::encode('Message',['call'=>['callKey'=>$key]]));$nonce=random_bytes(12);return new BinaryNode('call',['from'=>$sender,'id'=>'REKEY'.bin2hex(random_bytes(4))],[new BinaryNode('enc_rekey',['call-id'=>$id,'call-creator'=>self::A,'transaction-id'=>(string)$transaction],[new BinaryNode('enc',['type'=>'msg'],$nonce.Crypto::encryptGcm($this->signalKey,$nonce,$padded))])]); }

    public function testThreeParticipantOfferRekeyAndAuthenticatedMediaThenRemoval():void
    {
        $a=$this->service(self::A);$b=$this->service(self::B);$c=$this->service(self::C);$call=$a->offerGroup('123@g.us',['222@lid','333@lid']);$id=$call['callId'];$offer=$this->queries[self::A][0];self::assertSame($id.'@call',$offer->attrs['to']);self::assertNull($offer->child('offer')->child('encopt'));self::assertNull($offer->child('offer')->child('destination'));self::assertSame([], $offer->child('offer')->child('group_info')->attrs);
        $this->offerTo($b,$id);$this->offerTo($c,$id);$b->accept($id);$c->accept($id);$accept=$this->queries[self::B][0];self::assertSame($id.'@call',$accept->attrs['to']);self::assertSame('2',$accept->child('accept')->child('encopt')->attrs['keygen']);self::assertNull($accept->child('accept')->child('enc'));
        $a->handleNode(self::update($id,1,rekey:true));self::assertSame('connecting',$a->getCall($id)['state']);self::assertSame([],$this->rekeys(self::A));$a->drainPending();$keys=$this->rekeys(self::A);self::assertCount(2,$keys);self::assertSame('1',$keys[0]->child('enc_rekey')->attrs['transaction-id']);self::assertNotNull($keys[0]->child('enc_rekey')->child('enc'));self::assertNull($keys[0]->child('enc_rekey')->child('destination'));
        foreach($keys as $node)($node->attrs['to']===self::B?$b:$c)->handleNode(self::incoming($node,self::A));$ma=$a->groupMedia($id);$mb=$b->groupMedia($id);$mc=$c->groupMedia($id);$packet=$mb->protectPayload('opus-b',160);self::assertSame('opus-b',$ma->handleDatagram($packet)['payload']);self::assertSame('connected',$a->getCall($id)['state']);self::assertNull($ma->handleDatagram($packet));$a->drainPending();self::assertCount(2,$this->rekeys(self::A));
        $plan=$ma->streamPlan();self::assertSame([0,1,2,5],array_column($plan['selfLayers'],'layer'));self::assertSame(0,$mb->streamPlan()['senders'][0]['pid']);self::assertSame(self::C,$plan['senders'][1]['jid']);
        // Repeating an authenticated key cannot reset the RTP replay window.
        $b->handleNode(self::incoming($keys[0],self::A));$ownPacket=$ma->protectPayload('opus-a',320);self::assertSame('opus-a',$mb->handleDatagram($ownPacket)['payload']);$b->handleNode(self::incoming($keys[0],self::A));self::assertNull($mb->handleDatagram($ownPacket));
        $removedPacket=$mc->protectPayload('old-c',480);foreach([$a,$b,$c] as $index=>$service)$service->handleNode(self::update($id,2,[self::A,self::B],$index===0));self::assertSame('ended',$c->getCall($id)['state']);self::assertSame('membership_removed',$c->getCall($id)['endReason']);self::assertSame('connecting',$a->getCall($id)['state']);
        try{$ma->handleDatagram($removedPacket);self::fail('Excluded participant media was accepted');}catch(\UnexpectedValueException){}
        $a->drainPending();$new=$this->rekeys(self::A)[2];self::assertSame(self::B,$new->attrs['to']);$b->handleNode(self::incoming($new,self::A));self::assertSame(2,$ma->keyTransaction());self::assertSame('new-b',$ma->handleDatagram($mb->protectPayload('new-b',640))['payload']);
        // An old encrypted key, even authentically resent by a member, cannot roll back the epoch.
        $b->handleNode(self::incoming($keys[0],self::A));self::assertSame(2,$mb->keyTransaction());self::assertSame('connected',$a->getCall($id)['state']);
    }
    public function testKeyBeforeOfferIsHeldUntilAcceptanceAndMembershipAuthentication():void
    {
        $b=$this->service(self::B);$key=random_bytes(32);$b->handleNode($this->encryptedRekey('EARLY',$key,1));self::assertSame([],$b->getCalls());self::assertSame('receipt',$this->sent[self::B][1]->tag);$this->offerTo($b,'EARLY');$b->accept('EARLY');self::assertSame(1,$b->groupMedia('EARLY')->keyTransaction());self::assertSame('connecting',$b->getCall('EARLY')['state']);
        $b->handleNode($this->encryptedRekey('EARLY',random_bytes(32),1,'999:9@lid'));self::assertSame(1,$b->groupMedia('EARLY')->keyTransaction());
    }
    public function testRosterRejectsPeerRewriteStaleEpochAndInvalidDevices():void
    {
        $roster=new GroupCallState('CALL');try{$roster->applyRoster(self::info(1),self::A,self::B);self::fail();}catch(\UnexpectedValueException){}self::assertNull($roster->transactionId());self::assertTrue($roster->applyRoster(self::info(2),'CALL@call',self::B)['applied']);self::assertFalse($roster->applyRoster(self::info(1,[self::A,self::B]),'CALL@call',self::B)['applied']);self::assertCount(3,$roster->participants());
        $invalid=self::info(3);$invalid=new BinaryNode('group_info',$invalid->attrs,[new BinaryNode('user',['jid'=>'111@lid'],[new BinaryNode('device',['jid'=>self::B])])]);try{$roster->applyRoster($invalid,'CALL@call',self::B);self::fail();}catch(\UnexpectedValueException){}self::assertSame(2,$roster->transactionId());
        self::assertSame('333:0@lid',GroupCallState::device('333@lid'));$oversize=[];for($i=0;$i<6;$i++)$oversize[]=new BinaryNode('device',['jid'=>'111:'.$i.'@lid']);$this->expectException(\UnexpectedValueException::class);GroupCallState::readRoster(new BinaryNode('group_info',['media'=>'audio','connected_limit'=>'32'],[new BinaryNode('user',['jid'=>'111@lid'],$oversize)]));
    }
    public function testOneParticipantLeavingDoesNotEndOthersAndOnlyServerEndsGlobally():void
    {
        $b=$this->service(self::B);$this->offerTo($b,'JOIN');$b->accept('JOIN');$b->handleNode(new BinaryNode('call',['from'=>self::C,'id'=>'LEFT'],[new BinaryNode('terminate',['call-id'=>'JOIN','call-creator'=>self::A,'reason'=>'user_ended'])]));self::assertSame('connecting',$b->getCall('JOIN')['state']);self::assertCount(2,$b->participants('JOIN'));$b->leave('JOIN');self::assertTrue($b->getCall('JOIN')['joinable']);$b->join('JOIN');self::assertSame('connecting',$b->getCall('JOIN')['state']);$last=end($this->queries[self::B]);self::assertNotNull($last->child('accept'));self::assertNull($last->child('offer'));
        $b->handleNode(new BinaryNode('call',['from'=>'JOIN@call','id'=>'END'],[new BinaryNode('terminate',['call-id'=>'JOIN','call-creator'=>self::A,'reason'=>'group_call_ended'])]));self::assertFalse($b->getCall('JOIN')['joinable']);$this->expectException(\LogicException::class);$b->join('JOIN');
    }
    public function testVideoScreenShareHandsAndMuteUseSourceWireForms():void
    {
        $b=$this->service(self::B);$this->offerTo($b,'CTRL');$b->accept('CTRL');$b->requestVideoUpgrade('CTRL');$video=end($this->queries[self::B])->child('video');self::assertSame('3',$video->attrs['state']);self::assertSame('video',$video->attrs['voip_settings']);$b->handleNode(new BinaryNode('call',['from'=>self::A,'id'=>'VIDEO'],[new BinaryNode('video',['call-id'=>'CTRL','call-creator'=>self::A,'state'=>'4','transaction-id'=>'1'])]));self::assertFalse($b->getCall('CTRL')['video']);$b->handleNode(new BinaryNode('call',['from'=>'CTRL@call','id'=>'UPGRADE'],[new BinaryNode('group_update',['call-id'=>'CTRL','call-creator'=>self::A],[new BinaryNode('av_upgrade',['av-upgrader'=>self::A])])]));self::assertTrue($b->getCall('CTRL')['video']);$b->setScreenSharing('CTRL',true);$screen=end($this->queries[self::B])->child('screen_share');self::assertSame(['call-id'=>'CTRL','call-creator'=>self::A,'screenshare_state'=>'1','version'=>'2','screen_share_id'=>'1'],$screen->attrs);$b->setScreenSharing('CTRL',false);self::assertSame('2',end($this->queries[self::B])->child('screen_share')->attrs['screen_share_id']);
        $b->setHandRaised('CTRL',true);$hand=end($this->queries[self::B])->child('user_action');self::assertSame('1',$hand->attrs['broadcast']);self::assertSame('1',$hand->child('raise_hand')->attrs['raise-hand-state']);$b->requestParticipantMute('CTRL',self::C);$mute=end($this->queries[self::B]);self::assertSame(self::C,$mute->attrs['to']);self::assertSame('1',$mute->child('mute_v2')->attrs['request-state']);self::assertArrayNotHasKey('participantAudioMuted',$b->getCall('CTRL'));
        $this->offerTo($b,'CTRL2');$b->accept('CTRL2');$b->requestVideoUpgrade('CTRL2');$this->clock+=5.1;$b->tick();self::assertSame('timeout',$b->getCall('CTRL2')['videoUpgrade']['state']);
    }
    public function testReactionsRetransmitWithFreshRtpSequenceAndDeduplicateOnReceipt():void
    {
        $a=$this->service(self::A);$b=$this->service(self::B);$id=$a->offerGroup('123@g.us',['222@lid'])['callId'];$this->offerTo($b,$id);$b->accept($id);$a->handleNode(self::update($id,1,[self::A,self::B],true));$a->drainPending();$b->handleNode(self::incoming($this->rekeys(self::A)[0],self::A));$packets=[];$a->groupMedia($id)->setTransport(function($packet)use(&$packets){$packets[]=$packet;},static fn()=>null);$a->sendReaction($id,'👍');$this->clock+=0.061;$a->tick();self::assertCount(2,$packets);self::assertNotSame($packets[0],$packets[1]);$first=$b->groupMedia($id)->handleDatagram($packets[0]);$second=$b->groupMedia($id)->handleDatagram($packets[1]);self::assertSame(119,$first['payloadType']);self::assertSame($first['timestamp'],$second['timestamp']);self::assertSame($first['payload'],$second['payload']);self::assertSame([['kind'=>'reaction','transactionId'=>1,'text'=>'👍']],AppDataProtocol::decode($first['payload']));self::assertCount(1,array_filter($this->events[self::B],static fn($event)=>$event['name']==='voip_app_data'));$this->clock+=0.6;$a->tick();self::assertCount(2,$packets);
    }
}
