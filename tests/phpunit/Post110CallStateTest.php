<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Message\MessageCodec;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Voip\{GroupCallState,GroupMediaSession,MediaSession,RelayFlowControl,RelayTransport,Rtp,VoipService};

/** Offline regressions of the call lifecycle, independent of a server accepting our stanzas. */
final class Post110CallStateTest extends TestCase
{
    private const A='111:1@lid';private const B='222:2@lid';private const C='333:3@lid';
    private float $now=1000.0;
    private array $sent=[];private array $queries=[];private array $events=[];private array $fanouts=[];private array $keys=[];
    private int $resolved=0;private bool $autoAck=true;
    private function service(string $own=self::B,?\Closure $duringQuery=null,?\Closure $resolver=null,array $credentials=[]):VoipService
    {
        $service=null;
        return $service=new VoipService(static fn()=>['meLid'=>$own,...$credentials],function(BinaryNode $node)use($duringQuery,&$service):BinaryNode{$this->queries[]=$node;if($duringQuery!==null)$duringQuery($service,$node);return new BinaryNode('ack',['class'=>'call','id'=>$node->attrs['id']]);},function(BinaryNode $node)use(&$service):void{$this->sent[]=$node;if($node->tag==='call' && $this->autoAck)$service->handleNode(new BinaryNode('ack',['class'=>'call','id'=>$node->attrs['id'],'type'=>$node->content[0]->tag]));},function(string $peer)use($resolver):array{++$this->resolved;return $resolver===null?[GroupCallState::device($peer)]:$resolver($peer);},function(array $devices,string $plain):array{$this->fanouts[]=$devices;$this->keys[]=SchemaCodec::decode('Message',MessageCodec::unpad($plain))['call']['callKey'];return ['nodes'=>array_map(static fn($device)=>new BinaryNode('to',['jid'=>$device],[new BinaryNode('enc',['type'=>'msg'],$plain)]),$devices),'deviceIdentity'=>null];},static fn($sender,$type,$bytes)=>$bytes,function(string $event,array $data):void{$this->events[]=['event'=>$event,'data'=>$data];},fn()=>$this->now);
    }
    private static function stanza(string $tag,string $id='CALL',string $from=self::A,array $attrs=[],array $children=[]):BinaryNode { return new BinaryNode('call',['from'=>$from,'id'=>'NODE-'.$tag],[new BinaryNode($tag,['call-id'=>$id,'call-creator'=>self::A,...$attrs],$children)]); }
    private static function enc(string $key):BinaryNode { return new BinaryNode('enc',['type'=>'msg'],MessageCodec::pad(SchemaCodec::encode('Message',['call'=>['callKey'=>$key]]))); }
    private static function roster(int $epoch=1,array $devices=[self::A,self::B,self::C],bool $rekey=false,string $media='audio'):BinaryNode { $users=[];foreach($devices as $pid=>$device)$users[]=new BinaryNode('user',['jid'=>GroupCallState::account($device),'state'=>'connected'],[new BinaryNode('device',['jid'=>$device,'pid'=>(string)$pid])]);return new BinaryNode('group_info',['media'=>$media,'connected_limit'=>'32','transaction-id'=>(string)$epoch,...($rekey?['rekey'=>'1']:[])],$users); }
    private function incoming(VoipService $service,string $id='CALL',bool $video=false,array $attrs=[],array $children=[]):void { $service->handleNode(self::stanza('offer',$id,attrs:$attrs,children:[self::enc(str_repeat('k',32)),...($video?[new BinaryNode('video',['enc'=>'h.264'])]:[]),...$children])); }
    private function group(VoipService $service,string $id='GROUP',bool $joinable=true,array $children=[]):void { $service->handleNode(self::stanza('offer',$id,$id.'@call',['group-jid'=>'123@g.us','joinable'=>$joinable?'1':'0'],[self::roster(),...$children])); }
    private function tagged(string $tag):array { return array_values(array_filter($this->sent,static fn($node)=>$node->child($tag)!==null)); }
    private static function videoAllowed(MediaSession $session):bool { return (new \ReflectionProperty(MediaSession::class,'videoEnabled'))->getValue($session); }

    public function testExplicitCompanionStaysAloneAndDiscoveryFailureStillRingsPrimary():void
    {
        $service=$this->service(self::A);$service->offer('222:7@lid');self::assertSame(0,$this->resolved);self::assertSame([['222:7@lid']],$this->fanouts);
        $service=$this->service(self::A,resolver:static function():never{throw new \RuntimeException('Discovery unavailable');});$call=$service->offer('444@lid');self::assertSame('offered',$call['state']);self::assertSame(['444:0@lid'],$this->fanouts[1]);
    }
    public function testAcceptedElsewhereFromUnselectedCompanionDoesNotKillSelectedMedia():void
    {
        $service=$this->service(self::A,resolver:static fn()=>['222:2@lid','222:7@lid']);$id=$service->offer('222@lid')['callId'];$service->handleNode(self::stanza('accept',$id,self::B));$service->tick(false);$elsewhere=$this->tagged('terminate');self::assertCount(1,$elsewhere);self::assertSame('222:7@lid',$elsewhere[0]->attrs['to']);self::assertSame('accepted_elsewhere',$elsewhere[0]->child('terminate')->attrs['reason']);
        $service->handleNode(self::stanza('terminate',$id,'222:7@lid',['reason'=>'accepted_elsewhere']));self::assertSame('connecting',$service->getCall($id)['state']);$service->handleNode(self::stanza('terminate',$id,self::B,['reason'=>'accepted_elsewhere']));self::assertSame('ended',$service->getCall($id)['state']);
    }
    public function testBornVideoGuardIsArmedBeforeQueryAndIgnoresOwnAnnouncements():void
    {
        $service=$this->service(duringQuery:function(VoipService $service,BinaryNode $node):void{if($node->child('accept')!==null){self::assertSame('connecting',$service->getCall('CALL')['state']);self::assertTrue($service->getCall('CALL')['videoSendHeld']);$service->handleNode(self::stanza('mute_v2',from:'222:8@lid',attrs:['mute-state'=>'0']));}});
        $this->incoming($service,video:true);$service->accept('CALL');$service->setVideoEnabled('CALL',true);$media=$service->media('CALL');self::assertFalse(self::videoAllowed($media));$this->now+=0.2;$service->tick(false);self::assertTrue($service->getCall('CALL')['videoSendHeld']);
        // Any non-request mute announcement is readiness, even without a readable mute-state.
        $service->handleNode(self::stanza('mute_v2'));$this->now+=0.149;$service->tick(false);self::assertFalse(self::videoAllowed($media));$this->now+=0.002;$service->tick(false);self::assertTrue(self::videoAllowed($media));self::assertFalse($service->getCall('CALL')['videoSendHeld']);self::assertSame('connecting',$service->getCall('CALL')['state']);
    }
    public function testBornVideoGuardTimesOutWithoutTurningOnCameraTheApplicationDisabled():void
    {
        $service=$this->service();$this->incoming($service,video:true);$service->accept('CALL');$media=$service->media('CALL');$this->now+=2.01;$service->tick(false);self::assertFalse($service->getCall('CALL')['videoSendHeld']);self::assertFalse(self::videoAllowed($media));$releases=array_values(array_filter($this->events,static fn($entry)=>$entry['event']==='voip_video_send_released'));self::assertSame('timeout',$releases[0]['data']['reason']);
    }
    public function testUpgradeAnswerInsideRequestQueryIsNotLostAndUsesOwnTransactionCounter():void
    {
        $service=$this->service(duringQuery:function(VoipService $service,BinaryNode $node):void{if(($node->child('video')?->attrs['state']??null)==='11')$service->handleNode(self::stanza('video',attrs:['state'=>'4','transaction-id'=>'99']));});$this->incoming($service);$service->accept('CALL');$service->requestVideoUpgrade('CALL');self::assertSame('accepted',$service->getCall('CALL')['videoUpgrade']['state']);self::assertTrue($service->getCall('CALL')['videoEnabled']);$service->tick(false);$enabled=$this->tagged('video');self::assertCount(1,$enabled);self::assertSame('1',$enabled[0]->child('video')->attrs['state']);self::assertSame('2',$enabled[0]->child('video')->attrs['transaction-id']);self::assertTrue(self::videoAllowed($service->media('CALL')));
    }
    public function testAcceptUpgradeHoldsFramesUntilPeerEnabledAndCancelCodesDoNotRejectOurRequest():void
    {
        $service=$this->service();$this->incoming($service);$service->accept('CALL');$service->handleNode(self::stanza('video',attrs:['state'=>'11','transaction-id'=>'1']));$service->acceptVideoUpgrade('CALL');$media=$service->media('CALL');self::assertFalse(self::videoAllowed($media));$service->handleNode(self::stanza('video',attrs:['state'=>'1','transaction-id'=>'2']));$this->now+=0.299;$service->tick(false);self::assertFalse(self::videoAllowed($media));$this->now+=0.002;$service->tick(false);self::assertTrue(self::videoAllowed($media));
        $other=$this->service();$this->incoming($other,'OTHER');$other->accept('OTHER');$other->requestVideoUpgrade('OTHER');$other->handleNode(self::stanza('video','OTHER',attrs:['state'=>'8','transaction-id'=>'3']));self::assertSame('pending',$other->getCall('OTHER')['videoUpgrade']['state']);$other->cancelVideoUpgrade('OTHER');self::assertSame('cancelled',$other->getCall('OTHER')['videoUpgrade']['state']);self::assertSame('8',end($this->queries)->child('video')->attrs['state']);
    }
    public function testUpgradeTimeoutWithdrawsWithDisabledOutsideTheReceiveHandler():void
    {
        $service=$this->service();$this->incoming($service);$service->accept('CALL');$service->requestVideoUpgrade('CALL');$this->now+=5.01;$queryCount=count($this->queries);$service->tick(false);self::assertCount($queryCount,$this->queries);self::assertSame('timeout',$service->getCall('CALL')['videoUpgrade']['state']);self::assertSame('0',$this->tagged('video')[0]->child('video')->attrs['state']);self::assertFalse(self::videoAllowed($service->media('CALL')));
    }
    public function testEarlyTerminateDropsOfferButReringAndExpiredTerminateCanRing():void
    {
        $service=$this->service();$service->handleNode(self::stanza('terminate'));$this->incoming($service);self::assertSame([],$service->getCalls());$service->handleNode(self::stanza('terminate','RERING'));$this->incoming($service,'RERING',attrs:['re-ring'=>'1']);self::assertSame('ringing',$service->getCall('RERING')['state']);$service->reject('RERING');$this->incoming($service,'RERING',attrs:['re-ring'=>'1']);self::assertSame('ringing',$service->getCall('RERING')['state']);$service->handleNode(self::stanza('terminate','LATE'));$this->now+=30.01;$this->incoming($service,'LATE');self::assertSame('ringing',$service->getCall('LATE')['state']);
    }
    public function testJoinableFlagAndServerInvisibleEndOnlyLocalLegAndFollowRostersWhileOffCall():void
    {
        $service=$this->service();$this->group($service);$service->accept('GROUP');$service->handleNode(self::stanza('terminate','GROUP','GROUP@call',['reason'=>'user_invisible']));self::assertSame('invisible',$service->getCall('GROUP')['endReason']);self::assertTrue($service->getCall('GROUP')['joinable']);$service->handleNode(self::stanza('group_update','GROUP','GROUP@call',children:[self::roster(2,[self::A,self::B])]));self::assertCount(2,$service->participants('GROUP'));$service->join('GROUP');self::assertSame('connecting',$service->getCall('GROUP')['state']);
        $this->group($service,'NOJOIN',false);$service->reject('NOJOIN');self::assertFalse($service->getCall('NOJOIN')['joinable']);$this->expectException(\LogicException::class);$service->join('NOJOIN');
    }
    public function testSettingsDecodeBase64AndHeartbeatAndVideoTransactionPoliciesAreApplied():void
    {
        $settings=new BinaryNode('voip_settings',['uncompressed'=>'1'],base64_encode('{"options":{"heartbeat_interval_s":"3","video_state_txn_id_recv_enforce":"1"},"encode":{"frame_ms":"60"},"rc":{"maxfpp":"2"}}'));$service=$this->service();$this->group($service,children:[$settings]);self::assertSame(3000,$service->getCall('GROUP')['voipSettings']['heartbeatIntervalMs']);self::assertSame(60,$service->getCall('GROUP')['voipSettings']['audioFrameMs']);$service->accept('GROUP');$service->tick(false);$this->now+=2.99;$service->tick(false);self::assertCount(1,$this->tagged('heartbeat'));$this->now+=0.02;$service->tick(false);self::assertCount(2,$this->tagged('heartbeat'));
        $service->handleNode(self::stanza('video','GROUP',attrs:['state'=>'1','transaction-id'=>'2']));$service->handleNode(self::stanza('video','GROUP',attrs:['state'=>'6','transaction-id'=>'1']));self::assertSame(1,$service->getCall('GROUP')['participantVideoState'][self::A]);
        $other=$this->service();$this->incoming($other);$other->accept('CALL');$other->handleNode(self::stanza('video',attrs:['state'=>'1','transaction-id'=>'2']));$other->handleNode(self::stanza('video',attrs:['state'=>'6','transaction-id'=>'1']));self::assertSame(6,$other->getCall('CALL')['participantVideoState'][self::A]);
    }
    public function testGroupLivenessRequiresAuthenticatedOtherTrafficAndNeverTreatsWholePathLossAsPeerInvisible():void
    {
        $service=$this->service();$this->group($service);$service->accept('GROUP');$service->handleNode(self::stanza('enc_rekey','GROUP',attrs:['transaction-id'=>'1'],children:[self::enc(str_repeat('g',32))]));$state=new GroupCallState('GROUP');$state->applyRoster(self::roster(),'GROUP@call',self::C);$peer=new GroupMediaSession('GROUP',self::C,$state);$peer->rotateKey(str_repeat('g',32),1);$media=$service->groupMedia('GROUP');$packet=$peer->protectPayload('audio',160);$spoof=$packet;$spoof[strlen($spoof)-1]=chr(ord($spoof[strlen($spoof)-1])^1);try{$media->handleDatagram($spoof);self::fail();}catch(\UnexpectedValueException){}$service->tick(false);$this->now+=25;$service->tick(false);self::assertSame([],$this->tagged('peer_state'));
        $media->handleDatagram($packet);$service->tick(false);$this->now+=5;$service->tick(false);$this->now+=20;$media->handleDatagram($peer->protectPayload('audio',320));$service->tick(false);$reports=$this->tagged('peer_state');self::assertCount(1,$reports);self::assertSame('111@lid',$reports[0]->child('peer_state')->child('user')->attrs['jid']);self::assertSame('invisible',$reports[0]->child('peer_state')->child('user')->attrs['state']);self::assertCount(3,$service->participants('GROUP'),'A visibility report is not a local kick');$this->now+=5;$service->tick(false);self::assertCount(1,$this->tagged('peer_state'));
    }
    public function testRekeyRetriesExactCiphertextAndCancelsOldEpochAfterMembershipChange():void
    {
        $this->autoAck=false;$service=$this->service();$this->group($service);$service->accept('GROUP');$service->handleNode(self::stanza('group_update','GROUP','GROUP@call',children:[self::roster(2,rekey:true)]));$service->tick(true);$first=$this->tagged('enc_rekey');self::assertCount(2,$first);$fanouts=count($this->fanouts);$this->now+=3;$service->tick(false);$this->now+=0.5;$service->tick(false);$second=$this->tagged('enc_rekey');self::assertCount(4,$second);self::assertSame($first[0]->attrs['id'],$second[2]->attrs['id']);self::assertSame($first[0]->child('enc_rekey')->child('enc')->content,$second[2]->child('enc_rekey')->child('enc')->content);self::assertSame($fanouts,count($this->fanouts));$service->handleNode(self::stanza('group_update','GROUP','GROUP@call',children:[self::roster(3,[self::A,self::B])]));$this->now+=10;$service->tick(false);self::assertCount(4,$this->tagged('enc_rekey'));
    }
    public function testGroupSubscriptionsCoalesceVideoChangesAndRemovedDeviceLosesOldVideoState():void
    {
        $state=new GroupCallState('GROUP');$state->applyRoster(self::roster(media:'video'),'GROUP@call',self::B);$media=new GroupMediaSession('GROUP',self::B,$state,clock:fn()=>$this->now);$media->setVideoCoverage(true);$media->setParticipantVideoState(self::A,true);$relay=new Post110MemoryRelay();$media->setMediaFlowing();$media->connectTransport($relay);self::assertTrue($relay->flowing);$media->poll();self::assertCount(1,$relay->subscriptions);$media->setVideoSubscriptions([['participantJid'=>self::A,'quality'=>3]]);$this->now+=0.2;$media->poll();self::assertCount(1,$relay->subscriptions);$this->now+=1.81;$media->poll();self::assertCount(2,$relay->subscriptions);
        $state->applyRoster(self::roster(2,[self::B,self::C],media:'video'),'GROUP@call',self::B);$media->updateRoster();$media->poll();self::assertCount(3,$relay->subscriptions,'Membership removal must not wait for the video throttle');$state->applyRoster(self::roster(3,media:'video'),'GROUP@call',self::B);$media->updateRoster();$media->setVideoSubscriptions(null);self::assertSame([],$media->rxSubscriptions(),'A rejoined device needs a fresh camera announcement');
    }
    public function testGroupSecondaryStreamKeepsItsArgumentAndAuthenticatesRtpExtension():void
    {
        $state=new GroupCallState('GROUP');$state->applyRoster(self::roster(media:'video'),'GROUP@call',self::A);$a=new GroupMediaSession('GROUP',self::A,$state);$b=new GroupMediaSession('GROUP',self::B,$state);foreach([$a,$b] as $media){$media->setVideoCoverage(true);$media->rotateKey(str_repeat('g',32),1);}$extension=Rtp::videoExtension(true,true,4,9,300000);$packet=$a->protectPayload('h264',90000,97,2,true,true,$extension);$decoded=$b->handleDatagram($packet);self::assertSame($extension,$decoded['extensionData']);self::assertSame(Rtp::EXTENSION_PROFILE,$decoded['extensionProfile']);self::assertSame('h264',$decoded['payload']);self::assertNull($b->handleDatagram($packet));
    }
    public function testAcceptAuthenticatesDistinctPeerSendingKeyBeforeSelectingTheAnsweringDevice():void
    {
        $service=$this->service(self::A,resolver:static fn()=>[self::B]);$id=$service->offer('222@lid')['callId'];$service->handleNode(self::stanza('accept',$id,self::B,children:[new BinaryNode('enc',['type'=>'msg'],'invalid')]));self::assertSame('offered',$service->getCall($id)['state']);$peerKey=str_repeat('p',32);$service->handleNode(self::stanza('accept',$id,self::B,children:[self::enc($peerKey)]));$media=$service->media($id);$peer=new MediaSession($id,$peerKey,self::B,self::A);$packet=$peer->protectPayload('from-peer',160);self::assertSame('from-peer',$media->handleDatagram($packet)['payload']);self::assertNull($media->handleDatagram($packet));self::assertSame('connected',$service->getCall($id)['state']);self::assertArrayNotHasKey('peerCallKey',$service->getCall($id));
    }
    public function testRosterWithoutGroupJidIsGroupButJoinableWithoutRosterRemainsIndividual():void
    {
        $service=$this->service();$service->handleNode(self::stanza('offer','ROSTER','ROSTER@call',['joinable'=>'1'],[self::roster()]));self::assertTrue($service->getCall('ROSTER')['group']);self::assertNull($service->getCall('ROSTER')['groupJid']);$service->accept('ROSTER');self::assertSame('ROSTER@call',end($this->queries)->attrs['to']);$this->incoming($service,'INDIVIDUAL',attrs:['joinable'=>'1']);self::assertFalse($service->getCall('INDIVIDUAL')['group']);self::assertTrue($service->getCall('INDIVIDUAL')['joinable']);$service->handleNode(self::stanza('terminate','INDIVIDUAL'));self::assertSame('ended',$service->getCall('INDIVIDUAL')['state']);
    }
    public function testPromotionPreservesAcceptedTransportWithoutRedialAndRequiresFreshGroupKey():void
    {
        $service=$this->service();$this->incoming($service,attrs:['joinable'=>'1']);$service->accept('CALL');$old=$service->media('CALL');$relay=new Post110MemoryRelay();$old->connectTransport($relay);$individual=new MediaSession('CALL',str_repeat('k',32),self::A,self::B);$old->handleDatagram($individual->protectPayload('individual',160));self::assertSame('connected',$service->getCall('CALL')['state']);self::assertSame(1,$relay->connections);
        $service->handleNode(self::stanza('group_update',from:'CALL@call',children:[self::roster()]));self::assertTrue($service->getCall('CALL')['group']);self::assertSame('connecting',$service->getCall('CALL')['state']);$group=$service->groupMedia('CALL');self::assertTrue($group->hasTransport());self::assertSame(1,$relay->connections);self::assertFalse($relay->closed);$old->close();self::assertFalse($relay->closed);self::assertNull($group->keyTransaction());
        $service->handleNode(self::stanza('enc_rekey',attrs:['transaction-id'=>'1'],children:[self::enc(str_repeat('g',32))]));$state=new GroupCallState('CALL');$state->applyRoster(self::roster(),'CALL@call',self::A);$peer=new GroupMediaSession('CALL',self::A,$state);$peer->rotateKey(str_repeat('g',32),1);$group->handleDatagram($peer->protectPayload('group',320));self::assertSame('connected',$service->getCall('CALL')['state']);$service->terminate('CALL');self::assertTrue($relay->closed);
    }
    public function testMalformedSettingsVariantIsIgnoredAndScalarSectionsHaveSafeDefaults():void
    {
        $service=$this->service();$this->incoming($service,children:[new BinaryNode('voip_settings',['uncompressed'=>'0'],'{"options":{"heartbeat_interval_s":1}}')]);self::assertArrayNotHasKey('voipSettings',$service->getCall('CALL'));$service->accept('CALL');$service->handleNode(self::stanza('mute_v2',children:[new BinaryNode('voip_settings',[],'{"options":"scalar","encode":null,"rc":[],"vid_rc":1}')]));self::assertNull($service->getCall('CALL')['voipSettings']['heartbeatIntervalMs']);self::assertFalse($service->getCall('CALL')['voipSettings']['disableRtcpRemb']);
    }
    public function testTerminatedUpgradeNeverEmitsTimeoutOrReopensVideoOnLaterTick():void
    {
        $service=$this->service();$this->incoming($service);$service->accept('CALL');$service->requestVideoUpgrade('CALL');$service->terminate('CALL');self::assertSame('cancelled',$service->getCall('CALL')['videoUpgrade']['state']);$events=count($this->events);$this->now+=10;$service->tick(false);self::assertCount($events,$this->events);self::assertSame([],$this->tagged('video'));
    }
    public function testOwnPhoneAliasAnnouncementsAreIgnoredAndAcceptInsideOfferQueryIsPreserved():void
    {
        $service=$this->service(credentials:['meJid'=>'555:1@s.whatsapp.net']);$this->incoming($service);$service->accept('CALL');$service->handleNode(self::stanza('mute_v2',from:'555:8@s.whatsapp.net',attrs:['mute-state'=>'1']));self::assertArrayNotHasKey('peerAudioMuted',$service->getCall('CALL'));
        $caller=$this->service(self::A,duringQuery:static function(VoipService $service,BinaryNode $node):void{if($node->child('offer')!==null)$service->handleNode(self::stanza('accept',$node->child('offer')->attrs['call-id'],self::B));});$call=$caller->offer('222@lid');self::assertSame('connecting',$call['state']);self::assertSame(self::B,$call['peerJid']);
    }
    public function testGroupBornVideoGuardUsesCurrentParticipantsAndRosterPrunesVideoAnnouncements():void
    {
        $service=$this->service();$service->handleNode(self::stanza('offer','GROUP','GROUP@call',['joinable'=>'1'],[self::roster(media:'video'),new BinaryNode('video',['enc'=>'h.264'])]));$service->accept('GROUP');$service->setVideoEnabled('GROUP',true);$media=$service->groupMedia('GROUP');$enabled=new \ReflectionProperty(GroupMediaSession::class,'videoSendEnabled');self::assertFalse($enabled->getValue($media));$service->handleNode(self::stanza('mute_v2','GROUP',attrs:['mute-state'=>'0']));$this->now+=0.151;$service->tick(false);self::assertTrue($enabled->getValue($media));$service->handleNode(self::stanza('video','GROUP',attrs:['state'=>'1','transaction-id'=>'1']));$service->handleNode(self::stanza('group_update','GROUP','GROUP@call',children:[self::roster(2,[self::B,self::C],media:'video')]));self::assertArrayNotHasKey(self::A,$service->getCall('GROUP')['participantVideoState']);
    }
    public function testManualCameraStopCancelsUnacknowledgedUpgradeEnabledSnapshot():void
    {
        $this->autoAck=false;$service=$this->service();$this->incoming($service);$service->accept('CALL');$service->handleNode(self::stanza('video',attrs:['state'=>'11']));$service->acceptVideoUpgrade('CALL');$service->tick(false);$enabled=$this->tagged('video')[0];self::assertSame('1',$enabled->child('video')->attrs['state']);$service->setVideoEnabled('CALL',false);$this->now+=4;$service->tick(false);$this->now+=1;$service->tick(false);self::assertCount(1,$this->tagged('video'));self::assertFalse($service->handleNode(new BinaryNode('ack',['class'=>'call','id'=>$enabled->attrs['id']])));self::assertFalse(self::videoAllowed($service->media('CALL')));
    }
    public function testSframeFlagsGateOnlyAppDataWhenBothAreOnAndNeverClaimItWasDecoded():void
    {
        foreach([[false,false],[true,false],[false,true],[true,true]] as [$tx,$rx]){$service=$this->service();$settings=new BinaryNode('voip_settings',[],json_encode(['sframe'=>['enable_sframe'=>$tx,'enable_sframe_rx'=>$rx]],JSON_THROW_ON_ERROR));$this->group($service,children:[$settings]);$service->accept('GROUP');$service->handleNode(self::stanza('enc_rekey','GROUP',attrs:['transaction-id'=>'1'],children:[self::enc(str_repeat('g',32))]));$state=new GroupCallState('GROUP');$state->applyRoster(self::roster(),'GROUP@call',self::A);$peer=new GroupMediaSession('GROUP',self::A,$state);$peer->rotateKey(str_repeat('g',32),1);$media=$service->groupMedia('GROUP');
            $packet=$peer->protectPayload('opaque-app-data',160,119,6);if($tx && $rx){self::assertNull($media->handleDatagram($packet));self::assertSame('connecting',$service->getCall('GROUP')['state']);self::assertSame([],$media->readyDevices());try{$media->protectPayload('unsafe',160,119,6);self::fail('App-data must not omit the requested layer');}catch(\LogicException $error){self::assertStringContainsString('SFrame',$error->getMessage());}try{$service->sendReaction('GROUP','ok');self::fail();}catch(\LogicException){}self::assertSame([],(new \ReflectionProperty(VoipService::class,'reactions'))->getValue($service));}
            else{self::assertIsString($media->protectPayload('allowed',160,119,6));}
            self::assertSame('audio',$media->handleDatagram($peer->protectPayload('audio',320))['payload']);self::assertSame('connected',$service->getCall('GROUP')['state']);
        }
    }
}

final class Post110MemoryRelay implements RelayTransport,RelayFlowControl
{
    public bool $flowing=false;public bool $closed=false;public int $connections=0;public array $subscriptions=[];
    public function connect(array $selfSsrcs,array $peerSsrcs=[]):void { ++$this->connections; }
    public function setMediaFlowing(bool $flowing=true):void { $this->flowing=$flowing; }
    public function send(string $packet):void {}
    public function receive(float $timeout=0.0):?string { return null; }
    public function tick():void {}
    public function close():void { $this->closed=true; }
    public function reregister():void {}
    public function updateSubscriptions(array $selfSsrcs,array $peerSsrcs,?string $streamDescriptor=null,?string $rxSubscriptions=null):void { $this->subscriptions[]=['self'=>$selfSsrcs,'peers'=>$peerSsrcs,'rx'=>$rxSubscriptions]; }
    public function metrics():array { return ['open'=>true,'allocated'=>false,'lastPongAt'=>0.0,'openedAt'=>1000.0,'rttMs'=>null]; }
}
