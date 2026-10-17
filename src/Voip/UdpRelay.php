<?php
declare(strict_types=1);
namespace MVO\Voip;

/** Explicit raw-UDP relay leg for primary/mobile calls. It does not implement WebRTC DTLS/SCTP. */
final class UdpRelay implements RelayTransport,RelayCredentialUpdater,RelayFlowControl
{
    /** @var resource|null */
    private $socket=null;
    private string $transactionId;
    private string $allocation='';
    private float $openedAt=0;
    private float $registrationAt=0;
    private float $lastPing=0;
    private int $replayStep=0;
    public bool $allocated=false;
    private float $lastPong=0;
    private ?int $rttMs=null;
    /** @var list<int> */ private array $self=[];
    /** @var list<int> */ private array $peer=[];
    private bool $mediaFlowing=false;
    /**
     * @param array<string,mixed> $descriptor
     * @param \Closure():float|null $clock Seconds on the same time scale as the owning relay pool.
     */
    public function __construct(private array $descriptor,private readonly ?\Closure $clock=null)
    {
        if(!is_string($descriptor['ip']??null) || filter_var($descriptor['ip'],FILTER_VALIDATE_IP)===false || !is_int($descriptor['port']??null) || $descriptor['port']<1 || $descriptor['port']>65535 || !is_string($descriptor['rawToken']??null) || $descriptor['rawToken']==='' || !is_string($descriptor['key']??null) || $descriptor['key']==='')throw new \InvalidArgumentException('Raw relay requires explicit IP, port, binary token and key');$this->transactionId=random_bytes(12);
    }
    /**
     * @param list<int> $selfSsrcs
     * @param list<int> $peerSsrcs */
    public function connect(array $selfSsrcs,array $peerSsrcs=[]):void
    {
        if($this->socket!==null)throw new \LogicException('Raw relay already opened');$ip=$this->descriptor['ip'];$endpoint='udp://'.(str_contains($ip,':')?'['.$ip.']':$ip).':'.$this->descriptor['port'];$socket=@stream_socket_client($endpoint,$errno,$error,1,STREAM_CLIENT_CONNECT);
        if($socket===false)throw new \RuntimeException('Raw relay socket failed: '.$error,$errno);$this->socket=$socket;stream_set_blocking($socket,false);
        $this->registrationAt=$this->openedAt=$this->now();$this->lastPing=$this->openedAt;
        $this->updateSubscriptions($selfSsrcs,$peerSsrcs,$this->descriptor['streamDescriptor']??null,$this->descriptor['rxSubscriptions']??null);
    }
    public function send(string $packet):void
    {
        if(!is_resource($this->socket))throw new \LogicException('Raw relay is not open');if($packet==='' || strlen($packet)>65507)throw new \LengthException('Invalid UDP datagram length');$written=@fwrite($this->socket,$packet);if($written!==strlen($packet))throw new \RuntimeException('Raw relay datagram send failed');
    }
    /** Returns a raw SRTP/SRTCP datagram; STUN is consumed internally. */
    public function receive(float $timeout=0.0):?string
    {
        if(!is_resource($this->socket))throw new \LogicException('Raw relay is not open');if($timeout<0 || !is_finite($timeout))throw new \InvalidArgumentException('Invalid UDP receive timeout');$read=[$this->socket];$write=[];$except=[];$seconds=(int)$timeout;$ready=stream_select($read,$write,$except,$seconds,(int)(($timeout-$seconds)*1000000));if($ready===false)throw new \RuntimeException('Raw relay select failed');if($ready===0)return null;
        $bytes=fread($this->socket,65535);if($bytes===false)throw new \RuntimeException('Raw relay receive failed');if($bytes==='')return null;
        if(strlen($bytes)>=20 && substr($bytes,0,2)==="\x08\x02"){if(!hash_equals(substr($bytes,8,12),$this->transactionId))throw new \UnexpectedValueException('Relay pong transaction mismatch');$this->lastPong=$this->now();return null;}
        if(strlen($bytes)>=20 && substr($bytes,4,4)===pack('N',Stun::COOKIE)){$control=Stun::decode($bytes,$this->descriptor['key'],$this->transactionId);if($control['error'])throw new \RuntimeException('Relay rejected allocation');if($control['type']===0x0103){if(!$control['authenticated'])throw new \UnexpectedValueException('Relay allocation success is not authenticated');$this->allocated=true;$this->lastPong=$this->now();$this->rttMs??=(int)round(($this->lastPong-$this->openedAt)*1000);}return null;}
        return WarpFrame::unwrap($bytes);
    }
    public function tick():void
    {
        if(!is_resource($this->socket))return;$now=$this->now();$delays=[0.05,0.15,0.5,3.0];while(isset($delays[$this->replayStep]) && $now-$this->registrationAt>=$delays[$this->replayStep]){$this->send($this->allocation);++$this->replayStep;}if($now-$this->lastPing>=($this->mediaFlowing?1:5)){$this->send(Stun::ping($this->transactionId));$this->lastPing=$now;}
    }
    public function close():void { if(is_resource($this->socket))fclose($this->socket);$this->socket=null;$this->allocated=false; }
    public function reregister():void { if(is_resource($this->socket))$this->send($this->allocation); }
    /** @param list<int> $selfSsrcs
     * @param list<int> $peerSsrcs */
    public function updateSubscriptions(array $selfSsrcs,array $peerSsrcs,?string $streamDescriptor=null,?string $rxSubscriptions=null):void {
        $subscriptions=$streamDescriptor??Stun::subscriptions($selfSsrcs,$peerSsrcs,$this->descriptor['selfPid']??0,$this->descriptor['peerPid']??0);
        $allocation=Stun::allocate($this->descriptor['rawToken'],$this->descriptor['key'],$subscriptions,$this->descriptor['ip'],$this->descriptor['port'],$this->transactionId,$rxSubscriptions);
        $this->self=$selfSsrcs;$this->peer=$peerSsrcs;$this->descriptor['streamDescriptor']=$streamDescriptor;$this->descriptor['rxSubscriptions']=$rxSubscriptions;
        if($allocation===$this->allocation)return;$this->allocation=$allocation;$this->reregister();
    }
    /** @param array<string,mixed> $descriptor */
    public function updateCredentials(array $descriptor):void {
        if(($descriptor['ip']??null)!==$this->descriptor['ip'] || ($descriptor['port']??null)!==$this->descriptor['port'] || ($descriptor['transport']??'raw-udp')!=='raw-udp' || !is_string($descriptor['rawToken']??null) || $descriptor['rawToken']==='' || !is_string($descriptor['key']??null) || $descriptor['key']==='')throw new \InvalidArgumentException('Raw relay reissue requires the same endpoint and valid credentials');
        $this->descriptor=$descriptor;$this->allocation='';$this->replayStep=0;$this->registrationAt=$this->now();$this->updateSubscriptions($this->self,$this->peer,$descriptor['streamDescriptor']??null,$descriptor['rxSubscriptions']??null);
    }
    public function setMediaFlowing(bool $flowing=true):void { $this->mediaFlowing=$flowing; }
    public function metrics():array { return ['open'=>is_resource($this->socket),'allocated'=>$this->allocated,'lastPongAt'=>$this->lastPong,'openedAt'=>$this->openedAt,'rttMs'=>$this->rttMs]; }
    private function now():float { return $this->clock===null?microtime(true):($this->clock)(); }
    public function __destruct(){ $this->close(); }
}

