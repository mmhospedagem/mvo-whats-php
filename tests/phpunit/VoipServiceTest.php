<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Crypto\Crypto;
use MVO\Message\MessageCodec;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Voip\VoipService;

final class VoipServiceTest extends TestCase
{
    private string $signalKey;
    private array $queries=[];
    private array $sent=[];
    private array $events=[];
    private float $clock=1000.0;
    protected function setUp():void { $this->signalKey=random_bytes(32); }
    private function service(string $own,string $peer,?\Closure $query=null):VoipService
    {
        return new VoipService(static fn()=>['meJid'=>$own,'meLid'=>$own],$query??function(BinaryNode $node,float $timeout)use($own){self::assertSame(30.0,$timeout);$this->queries[$own][]=$node;return new BinaryNode('ack',['id'=>$node->attrs['id'],'class'=>'call']);},function($node)use($own){$this->sent[$own][]=$node;},static fn()=>[$peer],function($devices,$padded){$decoded=SchemaCodec::decode('Message',MessageCodec::unpad($padded));self::assertSame(32,strlen($decoded['call']['callKey']));$nodes=[];foreach($devices as $device){$nonce=random_bytes(12);$nodes[]=new BinaryNode('to',['jid'=>$device],[new BinaryNode('enc',['v'=>'2','type'=>'pkmsg','count'=>'0'],$nonce.Crypto::encryptGcm($this->signalKey,$nonce,$padded))]);}return ['nodes'=>$nodes,'deviceIdentity'=>'signed-identity'];},fn($sender,$type,$cipher)=>Crypto::decryptGcm($this->signalKey,substr($cipher,0,12),substr($cipher,12)),function($name,$data)use($own){$this->events[$own][]=['name'=>$name,'data'=>$data];},fn()=>$this->clock);
    }
    private static function incoming(BinaryNode $node,string $from):BinaryNode { return new BinaryNode($node->tag,['from'=>$from,'id'=>$node->attrs['id']],$node->content); }
    public function testOfferAcceptAndAuthenticatedMediaDriveActualSessionStates():void
    {
        $a=$this->service('111:1@lid','222:2@lid');$b=$this->service('222:2@lid','111:1@lid');$offered=$a->offer('222@lid');$id=$offered['callId'];self::assertSame('offered',$offered['state']);
        $offer=$this->queries['111:1@lid'][0];$inner=$offer->child('offer');self::assertSame('2',$inner->child('encopt')->attrs['keygen']);self::assertSame('222:2@lid',$inner->child('destination')->content[0]->attrs['jid']);self::assertSame('signed-identity',$inner->child('device-identity')->content);
        $b->handleNode(self::incoming($offer,'111:1@lid'));self::assertSame('ringing',$b->getCall($id)['state']);self::assertArrayNotHasKey('222:2@lid',$this->queries); // Never autoaccepted.
        $b->accept($id);$accept=$this->queries['222:2@lid'][0];self::assertNull($accept->child('accept')->child('enc'));self::assertNull($accept->child('accept')->child('device-identity'));self::assertSame('111:1@lid',$accept->attrs['to']);
        $a->handleNode(self::incoming($accept,'222:2@lid'));self::assertSame('connecting',$a->getCall($id)['state']);self::assertSame('connecting',$b->getCall($id)['state']);
        $mediaA=$a->media($id);$mediaB=$b->media($id);$mediaA->handleDatagram($mediaB->protectPayload("\xf8\xff\xfe",16000));self::assertSame('connected',$a->getCall($id)['state']);$mediaB->handleDatagram($mediaA->protectPayload("\xf8\xff\xfe",16000));self::assertSame('connected',$b->getCall($id)['state']);
        self::assertArrayNotHasKey('callKey',$a->getCall($id));$a->terminate($id);self::assertSame('ended',$a->getCall($id)['state']);
    }
    public function testUnauthenticatedOffersAreAcknowledgedWithoutRingingOrAcceptance():void
    {
        $b=$this->service('222:2@lid','111:1@lid');$node=new BinaryNode('call',['from'=>'111:1@lid','id'=>'MSG'],[new BinaryNode('offer',['call-id'=>'CALL','call-creator'=>'111:1@lid'],[new BinaryNode('enc',['type'=>'msg'],'invalid')])]);
        self::assertTrue($b->handleNode($node));self::assertSame([],$b->getCalls());self::assertSame('ack',$this->sent['222:2@lid'][0]->tag);self::assertArrayNotHasKey('222:2@lid',$this->queries);self::assertSame('voip_error',$this->events['222:2@lid'][0]['name']);
    }
    public function testMediaCannotStartBeforeAcceptAndTimeoutDoesNotClaimConnected():void
    {
        $a=$this->service('111:1@lid','222:2@lid');$call=$a->offer('222@lid');try{$a->media($call['callId']);self::fail();}catch(\LogicException){}
        $this->clock+=121;$a->tick();self::assertSame('ended',$a->getCall($call['callId'])['state']);self::assertSame('timeout',$a->getCall($call['callId'])['endReason']);
    }
    public function testAnotherPeerCannotTerminateAnExistingCall():void
    {
        $a=$this->service('111:1@lid','222:2@lid');$call=$a->offer('222@lid');$node=new BinaryNode('call',['from'=>'999:1@lid','id'=>'MSG'],[new BinaryNode('terminate',['call-id'=>$call['callId'],'call-creator'=>'111:1@lid'])]);
        try{$a->handleNode($node);self::fail();}catch(\UnexpectedValueException){}self::assertSame('offered',$a->getCall($call['callId'])['state']);
    }
    public function testGroupOfferCanBeRejectedButCannotPretendToNegotiateGroupMedia():void
    {
        $b=$this->service('222:2@lid','111:1@lid');$offer=new BinaryNode('call',['from'=>'111:1@lid','id'=>'MSG'],[new BinaryNode('offer',['call-id'=>'GROUPCALL','call-creator'=>'111:1@lid','group-jid'=>'123@g.us'])]);$b->handleNode($offer);self::assertTrue($b->getCall('GROUPCALL')['group']);
        try{$b->accept('GROUPCALL');self::fail();}catch(\LogicException $e){self::assertStringContainsString('Group call',$e->getMessage());}
        $b->reject('GROUPCALL');$reject=$this->queries['222:2@lid'][0];self::assertSame('GROUPCALL@call',$reject->attrs['to']);self::assertSame('0',$reject->child('reject')->attrs['count']);self::assertSame('ended',$b->getCall('GROUPCALL')['state']);
    }
    public function testSignalingFailureEndsLocalStateWithoutReportingOfferAsAccepted():void
    {
        $a=$this->service('111:1@lid','222:2@lid',static fn($node)=>new BinaryNode('ack',['id'=>$node->attrs['id'],'class'=>'call','error'=>'500']));
        try{$a->offer('222@lid');self::fail();}catch(\RuntimeException){}self::assertSame('ended',$a->getCalls()[0]['state']);self::assertSame('setup_failed',$a->getCalls()[0]['endReason']);
    }
    public function testMuteStopsLocalAudioEvenWhenItsSignalingAckFailsAndVideoFollowsControls():void
    {
        if(!extension_loaded('FFI') || !is_file(\MVO\Voip\OpusCodec::defaultLibrary()))self::markTestSkipped('Optional native Opus is not installed');
        $rejectMute=false;$a=$this->service('111:1@lid','222:2@lid',function(BinaryNode $node)use(&$rejectMute):BinaryNode{$this->queries['111:1@lid'][]=$node;return new BinaryNode('ack',['id'=>$node->attrs['id'],'class'=>'call',...($rejectMute?['error'=>'500']:[])]);});$b=$this->service('222:2@lid','111:1@lid');
        $id=$a->offer('222@lid',true)['callId'];$b->handleNode(self::incoming($this->queries['111:1@lid'][0],'111:1@lid'));$b->accept($id);$a->handleNode(self::incoming($this->queries['222:2@lid'][0],'222:2@lid'));
        $media=$a->media($id);$leg=new class implements \MVO\Voip\RelayTransport {
            public array $sent=[];
            public function connect(array $selfSsrcs,array $peerSsrcs=[]):void{}
            public function send(string $packet):void{$this->sent[]=$packet;}
            public function receive(float $timeout=0.0):?string{return null;}
            public function tick():void{}
            public function close():void{}
            public function reregister():void{}
            public function updateSubscriptions(array $selfSsrcs,array $peerSsrcs,?string $streamDescriptor=null,?string $rxSubscriptions=null):void{}
            public function metrics():array{return ['open'=>true,'allocated'=>true,'lastPongAt'=>0.0,'openedAt'=>0.0,'rttMs'=>null];}
        };$media->connectTransport($leg);$engine=$media->mediaEngine(new \MVO\Voip\OpusCodec(),['now'=>static fn()=>0.0]);$pcm=array_fill(0,960,0.1);
        self::assertCount(1,$engine->sendAudioPcm($pcm));self::assertSame([],$engine->sendVideoAnnexB("\0\0\0\1\x65test",90000));$a->setVideoEnabled($id,true);self::assertCount(1,$engine->sendVideoAnnexB("\0\0\0\1\x65test",90000));
        $rejectMute=true;try{$a->setMuted($id,true);self::fail('Failed signaling must throw');}catch(\RuntimeException){}
        $before=count($leg->sent);self::assertSame([],$engine->sendAudioPcm($pcm));self::assertSame($before,count($leg->sent));self::assertTrue($a->getCall($id)['audioMuted']);
        $rejectMute=false;$a->setMuted($id,false);self::assertCount(1,$engine->sendAudioPcm($pcm,60));
        $a->setVideoEnabled($id,false);self::assertSame([],$engine->sendVideoAnnexB("\0\0\0\1\x65test",180000));
        $a->setScreenSharing($id,true);self::assertCount(1,$engine->sendVideoAnnexB("\0\0\0\1\x65screen",270000));
        $a->setScreenSharing($id,false);self::assertSame([],$engine->sendVideoAnnexB("\0\0\0\1\x65screen",360000));
        $rejectMute=true;try{$a->terminate($id);self::fail('Failed hang-up signaling must throw');}catch(\RuntimeException){}self::assertSame('ended',$a->getCall($id)['state']);self::assertSame('user_ended',$a->getCall($id)['endReason']);
        try{$engine->sendAudioPcm($pcm);self::fail('Hang-up must close local media despite failed signaling');}catch(\LogicException){}$a->close();$b->close();
    }
}
