<?php
declare(strict_types=1);
namespace MVO\Voip;

/** WhatsApp STUN registration and encrypted SRTP ride inside a real DTLS/SCTP datachannel. */
final class WebRtcRelay implements RelayTransport,RelayCredentialUpdater,RelayFlowControl
{
    private ?DataChannel $channel=null;
    private string $transactionId;
    private string $localUfrag='';
    /** @var list<int> */ private array $self=[];
    /** @var list<int> */ private array $peer=[];
    private float $openedAt=0;
    private float $registrationAt=0;
    private float $createdAt=0;
    private float $lastPing=0;
    private float $lastPong=0;
    private int $replayStep=0;
    private bool $registered=false;
    private bool $allocated=false;
    private ?int $rttMs=null;
    private bool $mediaFlowing=false;
    private ?string $allocateCache=null;
    /** @param array<string,mixed> $descriptor @param \Closure():DataChannel|null $factory @param \Closure():float|null $clock */
    public function __construct(private array $descriptor,private readonly ?\Closure $factory=null,private readonly ?\Closure $clock=null){$this->transactionId=random_bytes(12);}
    /** @param list<int> $selfSsrcs
     * @param list<int> $peerSsrcs */
    public function connect(array $selfSsrcs,array $peerSsrcs=[]):void {
        if($this->channel!==null)throw new \LogicException('WebRTC relay already configured');
        $this->self=$selfSsrcs;$this->peer=$peerSsrcs;$channel=$this->factory===null?new NativeDataChannel():($this->factory)();
        try{$channel->createOffer();$offer=$channel->localDescription();if(!preg_match('/a=ice-ufrag:([^\r\n]+)/',$offer,$m))throw new \UnexpectedValueException('Local ICE credentials missing');$this->localUfrag=$m[1];$channel->setRemoteDescription(RelaySdp::answer($offer,$this->descriptor));}
        catch(\Throwable $e){$channel->close();throw $e;}
        $this->channel=$channel;$this->createdAt=$this->now();
    }
    public function send(string $packet):void { ($this->channel??throw new \LogicException('WebRTC relay is closed'))->send($packet); }
    public function receive(float $timeout=0.0):?string {
        $packet=($this->channel??throw new \LogicException('WebRTC relay is closed'))->receive($timeout);if($packet===null)return null;
        if(strlen($packet)>=20 && substr($packet,0,2)==="\x08\x02"){
            if(!hash_equals(substr($packet,8,12),$this->transactionId))throw new \UnexpectedValueException('Relay pong transaction mismatch');$this->lastPong=$this->now();return null;
        }
        if(strlen($packet)>=20 && substr($packet,4,4)===pack('N',Stun::COOKIE)){
            $control=Stun::decode($packet,$this->descriptor['key'],$this->transactionId);
            // Binding variants may be refused while the following ALLOCATE succeeds.
            if($control['error'] && $control['type']!==0x0111)throw new \RuntimeException('WebRTC relay refused registration');
            // This channel has already authenticated the pinned relay certificate over DTLS.
            // An inner MESSAGE-INTEGRITY, when present, is still verified by Stun::decode.
            if(in_array($control['type'],[0x0101,0x0103],true)){$this->allocated=$this->allocated || $control['type']===0x0103;$this->lastPong=$this->now();$this->rttMs??=(int)round(($this->now()-$this->openedAt)*1000);}
            return null;
        }
        return WarpFrame::unwrap($packet);
    }
    public function tick():void {
        if($this->channel===null)return;$now=$this->now();
        if(in_array($this->channel->state(),['failed','closed'],true))throw new \RuntimeException('WebRTC relay connection failed');
        if(!$this->channel->isOpen()){if($now-$this->createdAt>=20)throw new \RuntimeException('WebRTC relay connection timed out');return;}
        if(!$this->registered){$this->registrationAt=$this->openedAt=$this->lastPing=$now;$this->registered=true;$this->reregister();}
        $delays=[0.05,0.15,0.5,3.0];while(isset($delays[$this->replayStep]) && $now-$this->registrationAt>=$delays[$this->replayStep]){$this->reregister();++$this->replayStep;}
        if($now-$this->lastPing>=($this->mediaFlowing?1:5)){$this->send(Stun::ping($this->transactionId));$this->lastPing=$now;}
    }
    public function reregister():void {
        if($this->channel===null || !$this->channel->isOpen())return;
        $remote=($this->descriptor['authToken']??'')!==''?$this->descriptor['authToken']:$this->descriptor['token'];$key=$this->descriptor['key'];$ssrc=$this->peer[0]??$this->self[0]??0;
        if($ssrc===0)throw new \LogicException('Relay registration requires media SSRCs');
        $subs=Stun::senderSubscriptions($ssrc);$this->send(Stun::binding($remote.':'.$this->localUfrag,$key,$subs,true,true,$this->transactionId));
        if(($this->descriptor['token']??'')!=='' && $this->descriptor['token']!==$remote)$this->send(Stun::binding($this->descriptor['token'].':'.$this->localUfrag,$key,$subs,true,true,$this->transactionId));
        $this->send(Stun::binding(null,null,$subs,false,false,$this->transactionId));
        if(is_string($this->descriptor['rawToken']??null) && $this->descriptor['rawToken']!==''){
            $list=$this->descriptor['streamDescriptor']??Stun::subscriptions($this->self,$this->peer,$this->descriptor['selfPid']??0,$this->descriptor['peerPid']??0);
            $this->allocateCache??=Stun::allocate($this->descriptor['rawToken'],$key,$list,$this->descriptor['ip'],$this->descriptor['port'],$this->transactionId,$this->descriptor['rxSubscriptions']??null);$this->send($this->allocateCache);
        }
    }
    public function metrics():array { return ['open'=>$this->channel?->isOpen()??false,'allocated'=>$this->allocated,'lastPongAt'=>$this->lastPong,'openedAt'=>$this->registered?$this->openedAt:$this->createdAt,'rttMs'=>$this->rttMs]; }
    /** @param list<int> $selfSsrcs
     * @param list<int> $peerSsrcs */
    public function updateSubscriptions(array $selfSsrcs,array $peerSsrcs,?string $streamDescriptor=null,?string $rxSubscriptions=null):void {
        if($this->self===$selfSsrcs && $this->peer===$peerSsrcs && ($this->descriptor['streamDescriptor']??null)===$streamDescriptor && ($this->descriptor['rxSubscriptions']??null)===$rxSubscriptions)return;
        $this->self=$selfSsrcs;$this->peer=$peerSsrcs;$this->descriptor['streamDescriptor']=$streamDescriptor;$this->descriptor['rxSubscriptions']=$rxSubscriptions;$this->allocateCache=null;$this->reregister();
    }
    /** @param array<string,mixed> $descriptor */
    public function updateCredentials(array $descriptor):void {
        if(($descriptor['ip']??null)!==$this->descriptor['ip'] || ($descriptor['port']??null)!==$this->descriptor['port'] || ($descriptor['key']??null)!==$this->descriptor['key'] || ($descriptor['authTokenId']??null)!==($this->descriptor['authTokenId']??null) || ($descriptor['transport']??'webrtc')!=='webrtc')throw new \InvalidArgumentException('New ICE identity requires a new relay channel');
        // Validate credentials before touching a live channel. The existing DTLS association stays pinned.
        RelaySdp::answer("v=0\r\na=ice-ufrag:held\r\na=ice-pwd:held\r\na=fingerprint:held\r\na=setup:actpass\r\n",$descriptor);
        $this->descriptor=$descriptor;$this->allocateCache=null;$this->replayStep=0;$this->registrationAt=$this->now();$this->reregister();
    }
    public function setMediaFlowing(bool $flowing=true):void { $this->mediaFlowing=$flowing; }
    public function close():void { $this->channel?->close();$this->channel=null;$this->allocated=false; }
    private function now():float { return $this->clock===null?microtime(true):($this->clock)(); }
    public function __destruct(){ $this->close(); }
}
