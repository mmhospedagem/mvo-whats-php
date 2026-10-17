<?php
declare(strict_types=1);
namespace MVO\Voip;

/** Authenticated media over raw UDP or native WebRTC, with an optional codec/feedback engine. */
final class MediaSession
{
    private SrtpSession $sender;
    private SrtpSession $receiver;
    private ?RelayTransport $relay=null;
    private ?CallMediaEngine $engine=null;
    /** @var array<string,mixed> */ private array $voipSettings=[];
    private bool $voipSettingsConfigured=false;
    private readonly string $ownDevice;
    private readonly string $peerDevice;
    private string $peerKeyFingerprint;
    /** @var array<int,int> */
    private array $sequences=[];
    private bool $ready=false;
    private bool $closed=false;
    private bool $audioEnabled=true;
    private bool $videoEnabled=false;
    private bool $mediaFlowing=false;
    /**
     * @param \Closure(string,array<string,mixed>):void|null $emit */
    public function __construct(private readonly string $callId,string $callKey,string $ownDevice,string $peerDevice,private readonly ?\Closure $emit=null)
    {
        $this->ownDevice=GroupCallState::device($ownDevice);$this->peerDevice=GroupCallState::device($peerDevice);
        $ownDevice=$this->ownDevice;$peerDevice=$this->peerDevice;
        $own=CallCrypto::perDevice($callKey,$ownDevice);$peer=CallCrypto::perDevice($callKey,$peerDevice);$this->sender=new SrtpSession($own['masterKey'],$own['masterSalt']);$this->receiver=new SrtpSession($peer['masterKey'],$peer['masterSalt']);$this->peerKeyFingerprint=hash('sha256',$callKey);
    }
    /**
     * @param array<string,mixed> $descriptor */
    public function connectRawUdp(array $descriptor):void
    {
        $this->active();
        if(($descriptor['transport']??null)!=='raw-udp')throw new \InvalidArgumentException('Choose transport=raw-udp explicitly for this method');
        if($this->relay!==null)throw new \LogicException('Call media relay already configured');$relay=new UdpRelay($descriptor);$self=[];$peer=[];foreach(range(0,6) as $slot){$self[]=CallCrypto::ssrc($this->callId,$this->ownDevice,$slot);$peer[]=CallCrypto::ssrc($this->callId,$this->peerDevice,$slot);}$relay->connect($self,$peer);$relay->setMediaFlowing($this->mediaFlowing);$this->relay=$relay;
    }
    /** @param list<array<string,mixed>> $descriptors
     * @param \Closure(array<string,mixed>):RelayTransport|null $factory */
    public function connectRelays(array $descriptors,?\Closure $factory=null):void {
        $this->active();if($this->relay!==null)throw new \LogicException('Call media transport already configured');
        $pool=new RelayPool($descriptors,$factory,emit:function(string $event,array $data):void{$this->event($event,['callId'=>$this->callId,...$data]);});
        $this->connectTransport($pool);
    }
    public function connectTransport(RelayTransport $transport):void {
        $this->active();if($this->relay!==null)throw new \LogicException('Call media transport already configured');$self=[];$peer=[];
        foreach(range(0,6) as $slot){$self[]=CallCrypto::ssrc($this->callId,$this->ownDevice,$slot);$peer[]=CallCrypto::ssrc($this->callId,$this->peerDevice,$slot);}
        try{$transport->connect($self,$peer);if($transport instanceof RelayFlowControl)$transport->setMediaFlowing($this->mediaFlowing);}catch(\Throwable $e){$transport->close();throw $e;}$this->relay=$transport;
    }
    /** @param array<string,mixed> $options */
    public function mediaEngine(?OpusCodec $codec=null,array $options=[]):CallMediaEngine {
        $this->active();
        if($this->engine===null){
            $codec??=is_file(MLowCodec::defaultMLowLibrary())?new MLowCodec():new OpusCodec();
            $engine=new CallMediaEngine($codec,$this->protectPayload(...),$this->sendEncrypted(...),$this->sendRtcp(...),['emit'=>function(string $event,array $data):void{$this->event($event,['callId'=>$this->callId,...$data]);},...$options,'protectVideoPayload'=>fn(string $payload,int $timestamp,int $pt,int $slot,bool $marker,string $extension,int $profile):string=>$this->protectPayload($payload,$timestamp,$pt,$slot,$marker,$extension,$profile),'localAudioSsrc'=>CallCrypto::ssrc($this->callId,$this->ownDevice,0),'localVideoSsrc'=>CallCrypto::ssrc($this->callId,$this->ownDevice,2)]);
            try{if($this->voipSettingsConfigured)$engine->applyVoipSettings($this->voipSettings);$engine->setAudioEnabled($this->audioEnabled);$engine->setVideoEnabled($this->videoEnabled);}catch(\Throwable $error){$engine->close();throw $error;}$this->engine=$engine;
        }
        return $this->engine;
    }
    public function setAudioEnabled(bool $enabled):void { $this->active();$this->audioEnabled=$enabled;$this->engine?->setAudioEnabled($enabled); }
    public function setVideoEnabled(bool $enabled):void { $this->active();$this->videoEnabled=$enabled;$this->engine?->setVideoEnabled($enabled); }
    public function sendEncrypted(string $packet):void { $this->active();($this->relay??throw new \LogicException('Call media relay is not configured'))->send($packet); }
    public function hasTransport():bool { return $this->relay!==null; }
    public function setMediaFlowing(bool $flowing=true):void { $this->active();$this->mediaFlowing=$flowing;if($this->relay instanceof RelayFlowControl)$this->relay->setMediaFlowing($flowing); }
    /** @param array<string,mixed> $settings */
    public function setVoipSettings(array $settings):void { $this->active();$this->engine?->applyVoipSettings($settings);$this->voipSettings=$settings;$this->voipSettingsConfigured=true; }
    /** Accept may carry the answering device's independent receive key. Equal keys keep replay state. */
    public function setPeerCallKey(string $callKey):void {
        $this->active();$peer=CallCrypto::perDevice($callKey,$this->peerDevice);$fingerprint=hash('sha256',$callKey);if(hash_equals($this->peerKeyFingerprint,$fingerprint))return;
        $this->receiver=new SrtpSession($peer['masterKey'],$peer['masterSalt']);$this->peerKeyFingerprint=$fingerprint;
    }
    /** Encodes an already compressed codec frame. Timestamp is supplied by the caller's media clock. */
    public function protectPayload(string $encodedFrame,int $timestamp,int $payloadType=120,int $slot=0,bool $marker=false,string $extension='',int $extensionProfile=0xdebe):string
    {
        $this->active();
        if($payloadType===119 && $this->appDataSframeRequested())throw new \LogicException('SFrame for app-data is not supported');
        if($encodedFrame==='')throw new \InvalidArgumentException('Encoded media frame is empty');if($payloadType>=64 && $payloadType<=95)throw new \InvalidArgumentException('RTP payload types 64..95 are reserved when multiplexing RTCP');$ssrc=CallCrypto::ssrc($this->callId,$this->ownDevice,$slot);$seq=$this->sequences[$slot]??random_int(0,65535);$packet=Rtp::encode($encodedFrame,$ssrc,$seq,$timestamp,$payloadType,$marker,[],$extension,$extensionProfile);$this->sequences[$slot]=($seq+1)&65535;return $this->sender->protect($packet);
    }
    public function sendPayload(string $encodedFrame,int $timestamp,int $payloadType=120,int $slot=0,bool $marker=false):void { if($this->relay===null)throw new \LogicException('Call media relay is not configured');$this->relay->send($this->protectPayload($encodedFrame,$timestamp,$payloadType,$slot,$marker)); }
    /**
     * @return array<string,mixed>|null */
    public function handleDatagram(string $bytes):?array
    {
        $this->active();
        if(strlen($bytes)<2)throw new \UnexpectedValueException('Truncated call media datagram');$rtcp=ord($bytes[1])>=192 && ord($bytes[1])<=223;
        $plain=$rtcp?$this->receiver->unprotectRtcp($bytes):$this->receiver->unprotect($bytes);if($plain===null)return null;
        $data=$rtcp?['kind'=>'rtcp','bytes'=>$plain]:['kind'=>'rtp',...Rtp::decode($plain)];
        if(!$rtcp && $data['payloadType']===119 && $this->appDataSframeRequested()){$this->event('voip_media_error',['callId'=>$this->callId,'reason'=>'unsupported_app_data_sframe']);return null;}
        if($this->relay instanceof RelayPool)$this->relay->notePeerMedia();
        if(!$this->ready){$this->ready=true;$this->event('voip_media_ready',['callId'=>$this->callId,'peerJid'=>$this->peerDevice]);}
        $this->engine?->handlePacket($data);$this->event('voip_media_packet',['callId'=>$this->callId,...$data]);return $data;
    }
    public function poll(float $timeout=0.0):void {
        if($this->closed || $this->relay===null)return;$this->relay->tick();
        for($i=0;$i<64;$i++){$packet=$this->relay->receive($i===0?$timeout:0.0);if($packet===null)break;try{$this->handleDatagram($packet);}catch(\UnexpectedValueException){$this->event('voip_media_error',['callId'=>$this->callId,'reason'=>'invalid_media_packet']);}if($this->closed)return;}
        $this->engine?->tick();
    }
    public function sendRtcp(string $packet):void { if($this->relay===null)throw new \LogicException('Call media relay is not configured');$this->relay->send($this->sender->protectRtcp($packet)); }
    public function isReady():bool { return $this->ready; }
    /** Transfers an accepted 1:1 transport to a group session without reconnecting it. */
    public function releaseTransport():?RelayTransport {
        $this->active();$transport=$this->relay;$this->relay=null;$this->closed=true;$this->ready=false;
        $engine=$this->engine;$this->engine=null;$engine?->close();return $transport;
    }
    public function close():void { $this->relay?->close();$this->relay=null;$this->engine?->close();$this->engine=null;$this->ready=false;$this->closed=true; }
    private function active():void { if($this->closed)throw new \LogicException('Call media session is closed'); }
    private function appDataSframeRequested():bool { return ($this->voipSettings['sframe']??false)===true && ($this->voipSettings['sframeRx']??false)===true; }
    /**
     * @param array<string,mixed> $value */
    private function event(string $event,array $value):void { if($this->emit!==null)($this->emit)($event,$value); }
    public function __destruct(){ $this->close(); }
}

