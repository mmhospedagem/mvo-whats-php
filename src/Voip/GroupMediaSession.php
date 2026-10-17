<?php
declare(strict_types=1);
namespace MVO\Voip;

/** One authenticated receive/replay context per current sender, independently of relay transport. */
final class GroupMediaSession
{
    private ?SrtpSession $sender=null;
    /** @var array<string,SrtpSession> */
    private array $receivers=[];
    /** @var array<int,string> */
    private array $ssrcDevices=[];
    /** Media sender identifiers are derived once when SSRC maps change.
     * @var array<string,string> */
    private array $senderIds=[];
    /** @var array<int,int> */
    private array $sequences=[];
    /** @var array<string,true> */
    private array $ready=[];
    private ?string $key=null;
    private ?int $keyTransaction=null;
    private int $minimumTransaction=0;
    /** @var array<string,true> */
    private array $revokedKeys=[];
    private bool $closed=false;
    private ?\Closure $send=null;
    private ?\Closure $receive=null;
    private ?\Closure $transportTick=null;
    private ?\Closure $transportClose=null;
    private ?RelayTransport $relay=null;
    private ?CallMediaEngine $engine=null;
    private bool $coversVideo=false;
    private bool $audioEnabled=true;
    private bool $videoSendEnabled=true;
    private bool $mediaFlowing=false;
    private bool $subscriptionsDirty=false;
    /** @var array<string,bool> */
    private array $videoEnabled=[];
    /** @var list<array{participantJid:string,quality:int}>|null */
    private ?array $videoSubscriptions=null;
    /** @var array<string,mixed> */
    private array $voipSettings=[];
    private bool $voipSettingsConfigured=false;
    private ?float $subscriptionsChangedAt=null;
    private ?string $subscriptionTopology=null;
    /** @var list<array{pid:int,quality:int}> */
    private array $publishedRx=[];
    /** @param \Closure(string,array<string,mixed>):void|null $emit
     * @param \Closure():float|null $clock */
    public function __construct(private readonly string $callId,private readonly string $ownDevice,private readonly GroupCallState $roster,private readonly ?\Closure $emit=null,private readonly ?\Closure $clock=null) {}
    /** @param callable(string):void $send
     * @param callable(float):?string $receive
     * @param callable():void|null $tick
     * @param callable():void|null $close */
    public function setTransport(callable $send,callable $receive,?callable $tick=null,?callable $close=null):void { $this->active();if($this->send!==null)throw new \LogicException('Group media transport already configured');$this->send=\Closure::fromCallable($send);$this->receive=\Closure::fromCallable($receive);$this->transportTick=$tick===null?null:\Closure::fromCallable($tick);$this->transportClose=$close===null?null:\Closure::fromCallable($close); }
    /** @param list<array<string,mixed>> $descriptors
     * @param \Closure(array<string,mixed>):RelayTransport|null $factory */
    public function connectRelays(array $descriptors,?\Closure $factory=null):void
    {
        $plan=$this->streamPlan();foreach($descriptors as &$descriptor){$descriptor['streamDescriptor']=Stun::streamDescriptor($plan['selfLayers']);$descriptor['rxSubscriptions']=Stun::rxSubscriptions($this->rxSubscriptions());}unset($descriptor);
        $this->connectTransport(new RelayPool($descriptors,$factory,emit:function(string $event,array $data):void{$this->event($event,['callId'=>$this->callId,...$data]);}));
    }
    public function connectTransport(RelayTransport $transport):void
    {
        $this->active();if($this->send!==null)throw new \LogicException('Group media transport already configured');$plan=$this->streamPlan();$self=[];$peer=[];foreach($plan['selfLayers'] as $layer)$self=[...$self,...$layer['ssrcs']];foreach($plan['senders'] as $sender)$peer=[...$peer,...$sender['ssrcs']];
        try{$transport->connect($self,$peer);if($transport instanceof RelayFlowControl)$transport->setMediaFlowing($this->mediaFlowing);}catch(\Throwable $error){$transport->close();throw $error;}$this->relay=$transport;$this->setTransport($transport->send(...),$transport->receive(...),$transport->tick(...),$transport->close(...));$this->subscriptionsDirty=true;
    }
    /** Takes ownership of an already connected transport when a 1:1 grows into a group. */
    public function adoptTransport(RelayTransport $transport):void
    {
        $this->active();if($this->send!==null)throw new \LogicException('Group media transport already configured');$plan=$this->streamPlan();$self=[];$peer=[];foreach($plan['selfLayers'] as $layer)$self=[...$self,...$layer['ssrcs']];foreach($plan['senders'] as $sender)$peer=[...$peer,...$sender['ssrcs']];$transport->updateSubscriptions($self,$peer,Stun::streamDescriptor($plan['selfLayers']),Stun::rxSubscriptions($this->rxSubscriptions()));if($transport instanceof RelayFlowControl)$transport->setMediaFlowing($this->mediaFlowing);$this->relay=$transport;$this->setTransport($transport->send(...),$transport->receive(...),$transport->tick(...),$transport->close(...));$this->subscriptionsDirty=true;
    }
    public function hasTransport():bool { return $this->send!==null; }
    public function setMediaFlowing(bool $flowing=true):void { $this->active();$this->mediaFlowing=$flowing;if($this->relay instanceof RelayFlowControl)$this->relay->setMediaFlowing($flowing); }
    /** @param array<string,mixed> $settings */
    public function setVoipSettings(array $settings):void { $this->active();$this->engine?->applyVoipSettings($settings);$this->voipSettings=$settings;$this->voipSettingsConfigured=true; }
    public function sendEncrypted(string $packet):void { $this->active();if($this->send===null)throw new \LogicException('Group media transport is not configured');($this->send)($packet); }
    /** @param array<string,mixed> $options */
    public function mediaEngine(?OpusCodec $codec=null,array $options=[]):CallMediaEngine
    {
        $this->active();if($this->engine===null){$own=GroupCallState::device($this->ownDevice);$codec??=is_file(MLowCodec::defaultMLowLibrary())?new MLowCodec():new OpusCodec();$engine=new CallMediaEngine($codec,$this->protectPayload(...),$this->sendEncrypted(...),$this->sendRtcp(...),['emit'=>function(string $event,array $data):void{$this->event($event,['callId'=>$this->callId,...$data]);},...$options,'protectVideoPayload'=>fn(string $payload,int $timestamp,int $pt,int $slot,bool $marker,string $extension,int $profile):string=>$this->protectPayload($payload,$timestamp,$pt,$slot,$marker,false,$extension,$profile),'localAudioSsrc'=>CallCrypto::ssrc($this->callId,$own,0),'localVideoSsrc'=>CallCrypto::ssrc($this->callId,$own,2)]);try{if($this->voipSettingsConfigured)$engine->applyVoipSettings($this->voipSettings);}catch(\Throwable $error){$engine->close();throw $error;}$this->engine=$engine;$this->syncEngine();$this->engine->setAudioEnabled($this->audioEnabled);$this->engine->setVideoEnabled($this->coversVideo && $this->videoSendEnabled);}return $this->engine;
    }
    public function setAudioEnabled(bool $enabled):void { $this->active();$this->audioEnabled=$enabled;$this->engine?->setAudioEnabled($enabled); }
    public function setVideoEnabled(bool $enabled):void { $this->active();$this->videoSendEnabled=$enabled;$this->engine?->setVideoEnabled($enabled && $this->coversVideo); }
    public function setVideoCoverage(bool $video):void { $this->active();if($this->coversVideo===$video)return;$this->coversVideo=$video;$this->engine?->setVideoEnabled($video && $this->videoSendEnabled);$this->updateRoster(); }
    public function setParticipantVideoState(string $device,bool $enabled):void { if(!$this->roster->hasDevice($device))return;$this->videoEnabled[GroupCallState::device($device)]=$enabled;$this->subscriptionsDirty=true; }
    /** null restores the upstream policy: up to four enabled cameras when more than two members joined.
     * @param list<array{participantJid:string,quality?:int}>|null $subscriptions */
    public function setVideoSubscriptions(?array $subscriptions):void
    {
        $this->active();$next=null;if($subscriptions!==null){$next=[];if(count($subscriptions)>32)throw new \LengthException('At most 32 video subscriptions');foreach($subscriptions as $input){$device=GroupCallState::device($input['participantJid']);$quality=$input['quality']??0;if($quality<0 || $quality>4)throw new \InvalidArgumentException('Video quality must be 0..4');if($this->roster->hasDevice($device))$next[]=['participantJid'=>$device,'quality'=>$quality];}}$this->videoSubscriptions=$next;$this->subscriptionsDirty=true;
    }
    /** @return list<array{pid:int,quality:int}> */
    public function rxSubscriptions():array
    {
        if(!$this->coversVideo)return [];$wanted=$this->videoSubscriptions;if($wanted===null){$wanted=[];$connected=count(array_filter($this->roster->participants(),static fn($record)=>$record['state']==='connected'));if($connected>2)foreach($this->streamPlan()['senders'] as $sender)if(($this->videoEnabled[$sender['jid']]??false) && count($wanted)<4)$wanted[]=['participantJid'=>$sender['jid'],'quality'=>0];}
        $pids=[];foreach($this->streamPlan()['senders'] as $sender)if($sender['pid']!==null)$pids[$sender['jid']]=$sender['pid'];$out=[];foreach($wanted as $subscription)if(isset($pids[$subscription['participantJid']]))$out[]=['pid'=>$pids[$subscription['participantJid']],'quality'=>$subscription['quality']];return $out;
    }
    /** Reissued credentials are applied by the outer poller, after the receive handler returns.
     * @param list<array<string,mixed>> $descriptors */
    public function configureRelays(array $descriptors,?int $generation=null):void { $this->active();if(!$this->relay instanceof RelayPool)throw new \LogicException('A relay pool is required for reissue');$plan=$this->streamPlan();foreach($descriptors as &$descriptor){$descriptor['streamDescriptor']=Stun::streamDescriptor($plan['selfLayers']);$descriptor['rxSubscriptions']=Stun::rxSubscriptions($this->rxSubscriptions());}unset($descriptor);$this->relay->configure($descriptors,$generation); }
    public function rotateKey(string $key,int $transaction):bool
    {
        $this->active();if(strlen($key)!==32 || $transaction<0)throw new \InvalidArgumentException('Invalid group call key');
        if($transaction<$this->minimumTransaction || ($this->keyTransaction!==null && $transaction<$this->keyTransaction))return false;
        if(isset($this->revokedKeys[hash('sha256',$key)]))throw new \UnexpectedValueException('Group rekey reused revoked key material');
        if($this->keyTransaction===$transaction){if($this->key!==null && hash_equals($this->key,$key))return false;throw new \UnexpectedValueException('Conflicting group keys for one roster transaction');}
        // A changed key is scoped to a new epoch; resetting replay state is safe only when the key changes.
        if($this->key!==null && hash_equals($this->key,$key)){$this->keyTransaction=$transaction;return false;}
        $this->engine?->resetReceiveState();$this->key=$key;$this->keyTransaction=$transaction;$own=CallCrypto::perDevice($key,GroupCallState::device($this->ownDevice));$this->sender=new SrtpSession($own['masterKey'],$own['masterSalt']);$this->receivers=[];$this->ready=[];$this->updateRoster();return true;
    }
    /** An excluded member knows the former shared key; do not accept any sender under it. */
    public function invalidateForMembershipChange(int $transaction):void { $this->active();$this->minimumTransaction=max($this->minimumTransaction,$transaction);if($this->key!==null)$this->revokedKeys[hash('sha256',$this->key)]=true;$this->key=null;$this->sender=null;$this->receivers=[];$this->ready=[];$this->ssrcDevices=[];foreach(array_keys($this->videoEnabled) as $device)if(!$this->roster->hasDevice($device))unset($this->videoEnabled[$device]);$this->subscriptionsDirty=true;$this->engine?->resetReceiveState();$this->syncEngine(); }
    /** Relay declaration metadata, with no SRTP keys or relay credentials.
     * @return array{transactionId:?int,selfLayers:list<array{layer:int,ssrcs:list<int>}>,senders:list<array{jid:string,senderId:string,pid:?int,ssrcs:list<int>}>} */
    public function streamPlan():array
    {
        $own=GroupCallState::device($this->ownDevice);$layers=[];foreach([[0,[0,1,4],$own],[1,[2,3,5],$own],[2,[2,3,5],$own.'_1'],[5,[6],$own]] as [$layer,$slots,$device]){$ssrcs=[];foreach($slots as $slot)$ssrcs[]=CallCrypto::ssrc($this->callId,$device,$slot);$layers[]=['layer'=>$layer,'ssrcs'=>$ssrcs];}
        $senders=[];foreach($this->roster->participants() as $record){if(GroupCallState::account($record['jid'])===GroupCallState::account($own))continue;foreach($record['devices'] as $device){$ssrcs=[];foreach($this->coversVideo?[0,1,4,6,2,3,5]:[0,1,4,6] as $slot)$ssrcs[]=CallCrypto::ssrc($this->callId,$device,$slot);if($this->coversVideo)foreach([2,3,5] as $slot)$ssrcs[]=CallCrypto::ssrc($this->callId,$device.'_1',$slot);$senders[]=['jid'=>$device,'senderId'=>sprintf('%08x',$ssrcs[0]),'pid'=>$record['devicePids'][$device]??null,'ssrcs'=>$ssrcs];}}
        return ['transactionId'=>$this->roster->transactionId(),'selfLayers'=>$layers,'senders'=>$senders];
    }
    public function updateRoster():void
    {
        $this->active();$devices=$this->roster->devices();$own=GroupCallState::device($this->ownDevice);$map=[];$senderIds=[];
        foreach(array_keys($this->videoEnabled) as $device)if(!in_array($device,$devices,true))unset($this->videoEnabled[$device]);
        foreach($this->receivers as $device=>$session)if(!in_array($device,$devices,true)){unset($this->receivers[$device],$this->ready[$device]);}
        foreach($devices as $device){if(GroupCallState::account($device)===GroupCallState::account($own))continue;$senderIds[$device]=sprintf('%08x',CallCrypto::ssrc($this->callId,$device));if($this->key!==null && !isset($this->receivers[$device])){$key=CallCrypto::perDevice($this->key,$device);$this->receivers[$device]=new SrtpSession($key['masterKey'],$key['masterSalt']);}
            foreach($this->coversVideo?[0,1,2,3,4,5,6]:[0,1,4,6] as $slot){$ssrc=CallCrypto::ssrc($this->callId,$device,$slot);if(isset($map[$ssrc]) && $map[$ssrc]!==$device)throw new \UnexpectedValueException('Group SSRC collision');$map[$ssrc]=$device;}
            if($this->coversVideo)foreach([2,3,5] as $slot){$ssrc=CallCrypto::ssrc($this->callId,$device.'_1',$slot);if(isset($map[$ssrc]) && $map[$ssrc]!==$device)throw new \UnexpectedValueException('Group secondary SSRC collision');$map[$ssrc]=$device;}
        }
        $this->ssrcDevices=$map;$this->senderIds=$senderIds;$this->subscriptionsDirty=true;$this->syncEngine();
    }
    public function protectPayload(string $payload,int $timestamp,int $payloadType=120,int $slot=0,bool $marker=false,bool $secondary=false,?string $extension=null,int $extensionProfile=Rtp::EXTENSION_PROFILE):string
    {
        $this->active();if($payloadType===119 && $this->appDataSframe())throw new \LogicException('SFrame app-data is not implemented');if($payload==='')throw new \InvalidArgumentException('Encoded media frame is empty');if($this->sender===null || !$this->roster->hasDevice($this->ownDevice))throw new \LogicException('Current group membership and key are required');if($payloadType>=64 && $payloadType<=95)throw new \InvalidArgumentException('Reserved RTP/RTCP mux payload type');
        if($secondary && !in_array($slot,[2,3,5],true))throw new \InvalidArgumentException('Secondary stream is video only');$device=GroupCallState::device($this->ownDevice).($secondary?'_1':'');$ssrc=CallCrypto::ssrc($this->callId,$device,$slot);$seq=$this->sequences[$ssrc]??random_int(0,65535);$this->sequences[$ssrc]=($seq+1)&65535;return $this->sender->protect(Rtp::encode($payload,$ssrc,$seq,$timestamp,$payloadType,$marker,[],$extension,$extensionProfile));
    }
    public function sendPayload(string $payload,int $timestamp,int $payloadType=120,int $slot=0,bool $marker=false,bool $secondary=false):void { if($this->send===null)throw new \LogicException('Group media transport is not configured');($this->send)($this->protectPayload($payload,$timestamp,$payloadType,$slot,$marker,$secondary)); }
    public function protectRtcp(string $packet):string { $this->active();if($this->sender===null || !$this->roster->hasDevice($this->ownDevice))throw new \LogicException('Current group membership and key are required');return $this->sender->protectRtcp($packet); }
    public function sendRtcp(string $packet):void { if($this->send===null)throw new \LogicException('Group media transport is not configured');($this->send)($this->protectRtcp($packet)); }
    /** @return array<string,mixed>|null */
    public function handleDatagram(string $bytes):?array
    {
        $this->active();if(strlen($bytes)<12)throw new \UnexpectedValueException('Truncated group media');$rtcp=ord($bytes[1])>=192 && ord($bytes[1])<=223;$ssrc=unpack('N',substr($bytes,$rtcp?4:8,4))[1];$device=$this->ssrcDevices[$ssrc]??null;
        if($device===null || !isset($this->receivers[$device]) || !$this->roster->hasDevice($device))throw new \UnexpectedValueException('Media sender is outside the current group roster');
        $plain=$rtcp?$this->receivers[$device]->unprotectRtcp($bytes):$this->receivers[$device]->unprotect($bytes);if($plain===null)return null;
        $packet=$rtcp?null:Rtp::decode($plain);if($packet!==null && $packet['payloadType']===119 && $this->appDataSframe()){$this->event('voip_media_error',['callId'=>$this->callId,'reason'=>'unsupported_app_data_sframe']);return null;}
        if($this->relay instanceof RelayPool)$this->relay->notePeerMedia();
        $data=['callId'=>$this->callId,'senderJid'=>$device,'senderId'=>$this->senderIds[$device],...($packet===null?['kind'=>'rtcp','bytes'=>$plain]:['kind'=>'rtp',...$packet])];
        if(!isset($this->ready[$device])){$this->ready[$device]=true;$this->event('voip_group_media_ready',['callId'=>$this->callId,'senderJid'=>$device]);}$this->engine?->handlePacket($data);$this->event('voip_group_media_packet',$data);return $data;
    }
    /** @return list<string> */
    public function readyDevices():array { return array_keys($this->ready); }
    public function keyTransaction():?int { return $this->keyTransaction; }
    public function poll(float $timeout=0.0):void
    {
        if($this->closed || $this->receive===null || !$this->prepareTransport())return;for($i=0;$i<64;$i++){$bytes=($this->receive)($i===0?$timeout:0.0);if($bytes===null)break;try{$this->handleDatagram($bytes);}catch(\UnexpectedValueException){$this->event('voip_media_error',['callId'=>$this->callId,'reason'=>'invalid_media_packet']);}if($this->closed)return;}$this->engine?->tick();
    }
    /** A relay callback can close this session synchronously through the owning call service. */
    private function prepareTransport():bool
    {
        if($this->subscriptionsDirty && $this->relay!==null){$plan=$this->streamPlan();$self=[];$peer=[];foreach($plan['selfLayers'] as $layer)$self=[...$self,...$layer['ssrcs']];foreach($plan['senders'] as $sender)$peer=[...$peer,...$sender['ssrcs']];$topology=hash('sha256',implode(',',array_merge($self,$peer)));$next=$this->rxSubscriptions();$now=$this->clock===null?microtime(true):($this->clock)();
            if($topology!==$this->subscriptionTopology || $next===$this->publishedRx || $this->subscriptionsChangedAt===null || $now-$this->subscriptionsChangedAt>=2.0){if($topology!==$this->subscriptionTopology || $next!==$this->publishedRx){$this->relay->updateSubscriptions($self,$peer,Stun::streamDescriptor($plan['selfLayers']),Stun::rxSubscriptions($next));$this->subscriptionTopology=$topology;$this->publishedRx=$next;$this->subscriptionsChangedAt=$now;}$this->subscriptionsDirty=false;}}
        if($this->closed)return false;return $this->transportTick===null || $this->tickTransport();
    }
    private function tickTransport():bool { ($this->transportTick)();return !$this->closed; }
    public function close():void { if($this->closed)return;if($this->transportClose!==null)($this->transportClose)();$this->engine?->close();$this->engine=null;$this->relay=null;$this->closed=true;$this->sender=null;$this->receivers=[];$this->key=null;$this->ready=[];$this->ssrcDevices=[]; }
    private function syncEngine():void { if($this->engine===null)return;$senders=[];foreach($this->streamPlan()['senders'] as $sender)$senders[]=['id'=>$sender['senderId'],'audio'=>$sender['ssrcs'][0],'streams'=>$sender['ssrcs']];$this->engine->configureSenders($senders); }
    private function appDataSframe():bool { return ($this->voipSettings['sframe']??false)===true && ($this->voipSettings['sframeRx']??false)===true; }
    private function active():void { if($this->closed)throw new \LogicException('Group media session is closed'); }
    /** @param array<string,mixed> $data */
    private function event(string $event,array $data):void { if($this->emit!==null)($this->emit)($event,$data); }
}
