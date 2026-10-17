<?php
declare(strict_types=1);
namespace MVO\Voip;

/** Elects responsive relay legs; only authenticated peer media proves a new generation. */
final class RelayPool implements RelayTransport,RelayFlowControl
{
    /** @var array<string,array{descriptor:array<string,mixed>,relay:RelayTransport,generation:int,created:float,media:?float,fallback:bool}> */
    private array $legs=[];
    /** @var list<int> */ private array $self=[];
    /** @var list<int> */ private array $peer=[];
    /** @var list<array<string,mixed>> */ private array $descriptors;
    private ?string $selected=null;
    private ?string $receivedSource=null;
    private int $generation=0;
    private int $proved=0;
    private bool $started=false;
    private bool $closed=false;
    private ?float $sendingSince=null;
    private ?float $newestSendingSince=null;
    private bool $mediaFlowing=false;
    private bool $subscriptionsConfigured=false;
    private ?string $streamDescriptor=null;
    private ?string $rxSubscriptions=null;
    private bool $reallocated=false;
    private int $redials=0;
    /** @param list<array<string,mixed>> $descriptors
     * @param \Closure(array<string,mixed>):RelayTransport|null $factory
     * @param \Closure():float|null $clock
     * @param \Closure(string,array<string,mixed>):void|null $emit */
    public function __construct(array $descriptors,private readonly ?\Closure $factory=null,private readonly ?\Closure $clock=null,private readonly ?\Closure $emit=null){$this->descriptors=$descriptors;}
    /** @param list<int> $selfSsrcs
     * @param list<int> $peerSsrcs */
    public function connect(array $selfSsrcs,array $peerSsrcs=[]):void {
        if($this->started || $this->closed)throw new \LogicException('Relay pool is already configured or closed');
        $this->started=true;$this->self=$selfSsrcs;$this->peer=$peerSsrcs;$this->configure($this->descriptors);
    }
    /** New credentials start a generation; old legs remain until peer media proves the replacement.
     * @param list<array<string,mixed>> $descriptors */
    public function configure(array $descriptors,?int $generation=null):void {
        if(!$this->started || $this->closed)throw new \LogicException('Relay pool is not active');
        if($generation!==null && $generation<0)throw new \InvalidArgumentException('Invalid relay generation');
        if($generation!==null && $generation<$this->generation)return;
        if($descriptors===[] || count($descriptors)>16)throw new \InvalidArgumentException('A relay generation must contain 1..16 endpoints');
        $named=[];foreach($descriptors as $descriptor){self::endpoint($descriptor);$named[self::id($descriptor)]=true;$fallback=$this->fallbackDescriptor($descriptor,false);if($fallback!==null)$named[self::id($fallback)]=true;}
        $nextGeneration=$generation??($this->generation+1);
        if($nextGeneration>$this->generation)$this->newestSendingSince=null;
        $this->generation=$nextGeneration;$this->descriptors=$descriptors;$seen=[];
        foreach($descriptors as $descriptor){
            if($this->subscriptionsConfigured){$descriptor['streamDescriptor']=$this->streamDescriptor;$descriptor['rxSubscriptions']=$this->rxSubscriptions;}
            self::endpoint($descriptor);$id=self::id($descriptor);if(isset($seen[$id]))continue;$seen[$id]=true;
            if(isset($this->legs[$id])){$this->legs[$id]['generation']=$this->generation;continue;}
            $fallback=$this->fallbackDescriptor($descriptor,false);$fallbackId=$fallback===null?null:self::id($fallback);
            if($fallbackId!==null && isset($this->legs[$fallbackId])){$this->legs[$fallbackId]['generation']=$this->generation;continue;}
            $rebound=false;
            foreach($this->legs as $heldId=>$held){
                $updater=$held['relay'];$previous=$held['descriptor'];
                if(!$updater instanceof RelayCredentialUpdater || isset($named[$heldId]) || $held['generation']>=$this->generation || $previous['ip']!==$descriptor['ip'])continue;
                if($previous['port']!==$descriptor['port'] && $previous['port']!==($descriptor['fallbackPort']??null))continue;
                $raw=($descriptor['transport']??'webrtc')==='raw-udp';
                if(($previous['transport']??'webrtc')!==($descriptor['transport']??'webrtc') || (!$raw && (($previous['key']??null)!==($descriptor['key']??null) || ($previous['authTokenId']??null)!==($descriptor['authTokenId']??null))))continue;
                $descriptor['port']=$previous['port'];$descriptor['fallbackPort']=$previous['fallbackPort']??null;
                $updater->updateCredentials($descriptor);unset($this->legs[$heldId]);$id=self::id($descriptor);
                $this->legs[$id]=[...$held,'descriptor'=>$descriptor,'generation'=>$this->generation];
                if($this->selected===$heldId)$this->selected=$id;if($this->receivedSource===$heldId)$this->receivedSource=$id;
                $rebound=true;break;
            }
            if($rebound)continue;
            $this->dial($descriptor,false);
        }
        $this->retire();
        if($this->legs===[])throw new \RuntimeException('No call relay could be opened');
    }
    /** @param array<string,mixed> $descriptor */
    private function dial(array $descriptor,bool $fallback):void {
        if(count($this->legs)>=48)throw new \LengthException('Call relay pool reached its leg limit');
        $id=self::id($descriptor);$relay=null;
        try{$relay=$this->factory===null?(($descriptor['transport']??'webrtc')==='raw-udp'?new UdpRelay($descriptor,$this->clock):new WebRtcRelay($descriptor,clock:$this->clock)):($this->factory)($descriptor);$relay->connect($this->self,$this->peer);if($relay instanceof RelayFlowControl)$relay->setMediaFlowing($this->mediaFlowing);if($this->subscriptionsConfigured)$relay->updateSubscriptions($this->self,$this->peer,$this->streamDescriptor,$this->rxSubscriptions);}
        catch(\Throwable){$relay?->close();$next=$this->fallbackDescriptor($descriptor,$fallback);if($next!==null){$this->dial($next,true);return;}$this->event('voip_relay_failed',['endpoint'=>$descriptor['ip'].':'.$descriptor['port']]);return;}
        $this->legs[$id]=['descriptor'=>$descriptor,'relay'=>$relay,'generation'=>$this->generation,'created'=>$this->now(),'media'=>null,'fallback'=>$fallback];
    }
    public function send(string $packet):void {
        $id=$this->elect();if($id===null)throw new \RuntimeException('No responsive call relay is ready');
        try{$this->legs[$id]['relay']->send($packet);}
        catch(\Throwable $e){$this->fail($id);throw $e;}
        if(isset($this->legs[$id]) && $this->legs[$id]['generation']===$this->generation && $this->proved<$this->generation)$this->newestSendingSince??=$this->now();if($this->mediaFlowing && !$this->closed)$this->watchDownlink();
    }
    public function receive(float $timeout=0.0):?string {
        if(!is_finite($timeout) || $timeout<0 || $timeout>30)throw new \InvalidArgumentException('Invalid relay pool timeout');
        if($this->closed)throw new \LogicException('Relay pool is closed');$deadline=$this->now()+$timeout;
        do{
            foreach(array_keys($this->legs) as $id){if(!isset($this->legs[$id]))continue;for($dropped=0;$dropped<64;$dropped++){try{$packet=$this->legs[$id]['relay']->receive(0);}catch(\UnexpectedValueException){continue;}catch(\Throwable){$this->fail($id);break;}if($packet===null)break;$this->receivedSource=$id;return $packet;}}
            if($timeout===0.0 || $this->now()>=$deadline)return null;usleep(500);
        }while(true);
    }
    /** Must be called only after SRTP/SRTCP authentication, never from a STUN response. */
    public function notePeerMedia():void {
        $id=$this->receivedSource;if($id===null || !isset($this->legs[$id]))return;$now=$this->now();
        $current=$this->selected===null?null:($this->legs[$this->selected]??null);
        $this->legs[$id]['media']=$now;$this->sendingSince=null;$this->reallocated=false;
        $newest=$this->legs[$id]['generation']===$this->generation;
        $leavesOlder=$newest && $current!==null && $current['generation']<$this->generation;
        if($newest)$this->proved=$this->generation;
        $canFollowOlder=$this->proved<$this->generation && $this->newestSendingSince!==null && $now-$this->newestSendingSince>=0.4;
        $hasNewest=false;foreach($this->legs as $leg)if($leg['generation']===$this->generation && $leg['relay']->metrics()['open']){$hasNewest=true;break;}
        if(($newest || !$hasNewest || $canFollowOlder) && ($leavesOlder || $current===null || $current['media']===null || $now-$current['media']>=0.4))$this->selected=$id;
        $this->retire();
    }
    public function tick():void {
        if($this->closed)return;$now=$this->now();
        foreach(array_keys($this->legs) as $id){if(!isset($this->legs[$id]))continue;
            try{$leg=$this->legs[$id];$leg['relay']->tick();$metrics=$leg['relay']->metrics();
                $unanswered=$metrics['open'] && !$metrics['allocated'] && $metrics['lastPongAt']===0.0 && $now-$metrics['openedAt']>=4;
                $unopened=!$metrics['open'] && $now-$leg['created']>=5;
                if(($unanswered || $unopened) && !$leg['fallback'] && isset($leg['descriptor']['fallbackPort']))$this->fail($id);
            }catch(\Throwable){$this->fail($id);}
        }
        $this->elect();$this->retire();
    }
    private function watchDownlink():void {
        $now=$this->now();if($this->sendingSince===null){$this->sendingSince=$now;return;}$quiet=$now-$this->sendingSince;
        if(!$this->reallocated){if($quiet<5)return;$this->reallocated=true;foreach($this->legs as $id=>$leg)if(($leg['descriptor']['transport']??'webrtc')!=='raw-udp' && $leg['relay']->metrics()['open'])try{$leg['relay']->reregister();}catch(\Throwable){$this->fail($id);}return;}
        if($this->redials>=3 || $quiet<5+5*2**$this->redials)return;
        $redialled=false;foreach(array_keys($this->legs) as $id){if(!isset($this->legs[$id]))continue;$held=$this->legs[$id];if($held['generation']!==$this->generation || ($held['descriptor']['transport']??'webrtc')==='raw-udp')continue;$held['relay']->close();unset($this->legs[$id]);if($this->selected===$id)$this->selected=null;$this->dial($held['descriptor'],$held['fallback']);$redialled=true;}
        if($redialled)++$this->redials;$this->sendingSince=$now;$this->reallocated=false;
        if($redialled && $this->legs===[])$this->event('voip_transport_lost',['reason'=>'all_relays_failed']);
    }
    private function fail(string $id):void {
        if(!isset($this->legs[$id]))return;$leg=$this->legs[$id];$leg['relay']->close();unset($this->legs[$id]);if($this->selected===$id)$this->selected=null;
        $next=$leg['generation']===$this->generation?$this->fallbackDescriptor($leg['descriptor'],$leg['fallback']):null;if($next!==null)$this->dial($next,true);
        if($this->legs===[])$this->event('voip_transport_lost',['reason'=>'all_relays_failed']);
    }
    /** @param array<string,mixed> $descriptor
     * @return array<string,mixed>|null */
    private function fallbackDescriptor(array $descriptor,bool $used):?array {
        $port=$descriptor['fallbackPort']??null;if($used || !is_int($port) || $port<1 || $port>65535 || $port===$descriptor['port'])return null;
        $descriptor['port']=$port;unset($descriptor['fallbackPort']);return $descriptor;
    }
    private function elect():?string {
        $now=$this->now();$candidates=[];
        foreach($this->legs as $id=>$leg){if($this->proved>=$this->generation && $leg['generation']<$this->generation)continue;$m=$leg['relay']->metrics();if(!$m['open'] || (!$m['allocated'] && $m['lastPongAt']===0.0) || ($m['lastPongAt']>0 && $now-$m['lastPongAt']>4))continue;$candidates[$id]=$m['rttMs']??PHP_INT_MAX;}
        $newest=array_filter($candidates,fn($rtt,$id)=>$this->legs[$id]['generation']===$this->generation,ARRAY_FILTER_USE_BOTH);
        if($newest!==[]){
            $held=$this->selected===null?null:($this->legs[$this->selected]??null);
            $followOlder=$held!==null && isset($candidates[$this->selected]) && $held['generation']<$this->generation && $held['media']!==null && $now-$held['media']<0.4 && $this->proved<$this->generation && $this->newestSendingSince!==null && $now-$this->newestSendingSince>=0.4;
            if($followOlder)return $this->selected;
            $candidates=$newest;
        }
        if($this->selected!==null && isset($candidates[$this->selected]))return $this->selected;
        asort($candidates,SORT_NUMERIC);$this->selected=array_key_first($candidates);return $this->selected;
    }
    private function retire():void {
        if($this->proved<$this->generation)return;$now=$this->now();
        foreach($this->legs as $id=>$leg)if($leg['generation']<$this->generation && ($leg['media']===null || $now-$leg['media']>=5)){$leg['relay']->close();unset($this->legs[$id]);if($this->selected===$id)$this->selected=null;}
    }
    public function reregister():void { foreach($this->legs as $id=>$leg)try{$leg['relay']->reregister();}catch(\Throwable){$this->fail($id);} }
    /** @param list<int> $selfSsrcs
     * @param list<int> $peerSsrcs */
    public function updateSubscriptions(array $selfSsrcs,array $peerSsrcs,?string $streamDescriptor=null,?string $rxSubscriptions=null):void {
        if($this->subscriptionsConfigured && $this->self===$selfSsrcs && $this->peer===$peerSsrcs && $this->streamDescriptor===$streamDescriptor && $this->rxSubscriptions===$rxSubscriptions)return;
        $this->self=$selfSsrcs;$this->peer=$peerSsrcs;$this->streamDescriptor=$streamDescriptor;$this->rxSubscriptions=$rxSubscriptions;$this->subscriptionsConfigured=true;
        foreach($this->legs as $id=>$leg)try{$leg['relay']->updateSubscriptions($selfSsrcs,$peerSsrcs,$streamDescriptor,$rxSubscriptions);$this->legs[$id]['descriptor']['streamDescriptor']=$streamDescriptor;$this->legs[$id]['descriptor']['rxSubscriptions']=$rxSubscriptions;}catch(\Throwable){$this->fail($id);}
    }
    public function setMediaFlowing(bool $flowing=true):void { $this->mediaFlowing=$flowing;if(!$flowing){$this->sendingSince=null;$this->reallocated=false;$this->redials=0;}foreach($this->legs as $leg)if($leg['relay'] instanceof RelayFlowControl)$leg['relay']->setMediaFlowing($flowing); }
    public function metrics():array { $id=$this->elect();return $id===null?['open'=>false,'allocated'=>false,'lastPongAt'=>0.0,'openedAt'=>0.0,'rttMs'=>null]:$this->legs[$id]['relay']->metrics(); }
    /** Only legs that really answered and have a measured RTT can be advertised; no credentials escape.
     * @return list<array{relayName:string,latencyMs:int,addressBytes:string}> */
    public function measuredRelays():array
    {
        $byName=[];foreach($this->legs as $leg){$descriptor=$leg['descriptor'];$name=$descriptor['relayName']??null;$address=$descriptor['addressBytes']??null;if(!is_string($name) || $name==='' || !is_string($address) || $address==='')continue;try{$metrics=$leg['relay']->metrics();}catch(\Throwable){continue;}$rtt=$metrics['rttMs'];if(!$metrics['open'] || (!$metrics['allocated'] && $metrics['lastPongAt']<=0) || !is_int($rtt) || $rtt<0 || $rtt>0xffffff)continue;if(!isset($byName[$name]) || $rtt<$byName[$name]['latencyMs'])$byName[$name]=['relayName'=>$name,'latencyMs'=>$rtt,'addressBytes'=>$address];}return array_values($byName);
    }
    /** @return list<array<string,mixed>> */
    public function stats():array { $out=[];foreach($this->legs as $id=>$leg)$out[]=['endpoint'=>$leg['descriptor']['ip'].':'.$leg['descriptor']['port'],'generation'=>$leg['generation'],'selected'=>$this->selected===$id,'lastAuthenticatedMediaAt'=>$leg['media'],...$leg['relay']->metrics()];return $out; }
    public function close():void { foreach($this->legs as $leg)$leg['relay']->close();$this->legs=[];$this->closed=true;$this->selected=null; }
    /** @param array<string,mixed> $descriptor */
    private static function endpoint(array $descriptor):void { if(!is_string($descriptor['ip']??null) || filter_var($descriptor['ip'],FILTER_VALIDATE_IP)===false || !is_int($descriptor['port']??null) || $descriptor['port']<1 || $descriptor['port']>65535)throw new \InvalidArgumentException('Invalid relay endpoint'); }
    /** @param array<string,mixed> $descriptor */
    private static function id(array $descriptor):string { return hash('sha256',serialize([$descriptor['ip'],$descriptor['port'],$descriptor['key']??'',$descriptor['rawToken']??'',$descriptor['token']??'',$descriptor['authTokenId']??'',$descriptor['authToken']??'',$descriptor['rawAuthToken']??'',$descriptor['selfPid']??0,$descriptor['peerPid']??0,$descriptor['transport']??'webrtc'])); }
    /** @param array<string,mixed> $data */
    private function event(string $event,array $data):void { if($this->emit!==null)($this->emit)($event,$data); }
    private function now():float { return $this->clock===null?microtime(true):($this->clock)(); }
    public function __destruct(){ $this->close(); }
}
