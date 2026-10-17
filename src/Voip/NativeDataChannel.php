<?php
declare(strict_types=1);
namespace MVO\Voip;

/** libdatachannel runs native threads; the C bridge queues callbacks for the PHP poller. */
final class NativeDataChannel implements DataChannel
{
    private \FFI $api;
    /** Keep native libraries loaded while libdatachannel worker threads shut down.
     * @var array<string,\FFI> */
    private static array $libraries=[];
    private ?\FFI\CData $handle=null;
    private \FFI\CData $buffer;
    private bool $offered=false;
    private const API=<<<'C'
    int mvo_rtc_abi(void);
    void *mvo_rtc_create(const char *bind_address,int min_port,int max_port);
    int mvo_rtc_offer(void *handle);
    int mvo_rtc_remote(void *handle,const char *sdp,const char *type);
    int mvo_rtc_description(void *handle,char *buffer,int size);
    int mvo_rtc_state(void *handle);
    int mvo_rtc_gathered(void *handle);
    int mvo_rtc_open(void *handle);
    int mvo_rtc_send(void *handle,const char *bytes,int size);
    int mvo_rtc_receive(void *handle,char *buffer,int capacity);
    void mvo_rtc_close(void *handle);
    C;
    public static function defaultLibrary():string {
        $configured=getenv('MVO_RTC_LIBRARY');
        if(is_string($configured) && $configured!=='')return $configured;
        return dirname(__DIR__,2).'/.dev/native/bin/'.(PHP_OS_FAMILY==='Windows'?'mvo_rtc.dll':(PHP_OS_FAMILY==='Darwin'?'libmvo_rtc.dylib':'libmvo_rtc.so'));
    }
    public function __construct(?string $library=null,?string $bindAddress=null,int $minPort=0,int $maxPort=0)
    {
        if(!extension_loaded('FFI'))throw new \RuntimeException('Native WebRTC requires ext-ffi and the compiled native/rtc_bridge.c library');
        if($minPort<0 || $maxPort>65535 || $minPort>$maxPort || ($minPort===0 && $maxPort!==0))throw new \InvalidArgumentException('Invalid ICE port range');
        if($bindAddress!==null && filter_var($bindAddress,FILTER_VALIDATE_IP)===false)throw new \InvalidArgumentException('ICE bindAddress must be an IP address');
        $path=realpath($library??self::defaultLibrary());
        if($path===false)throw new \RuntimeException('Native WebRTC library is missing; run tools/setup-voip.ps1 or configure MVO_RTC_LIBRARY');
        if(PHP_OS_FAMILY==='Windows'){
            $dependency=dirname($path).'/libdatachannel.dll';
            if(is_file($dependency))self::$libraries[$dependency]??=\FFI::cdef('',$dependency);
        }
        $this->api=self::$libraries[$path]??=\FFI::cdef(self::API,$path);
        if($this->nativeInt('mvo_rtc_abi')!==1)throw new \RuntimeException('Unsupported native WebRTC bridge ABI');
        $handle=$this->nativePointer('mvo_rtc_create',$bindAddress,$minPort,$maxPort);
        $this->handle=$handle;$this->buffer=$this->api->new('char[65536]');
        if($this->state()==='failed'){$this->close();throw new \RuntimeException('Native ICE peer creation failed');}
    }
    public function createOffer():void {
        $handle=$this->active();if($this->offered)throw new \LogicException('WebRTC offer already created');
        self::check($this->nativeInt('mvo_rtc_offer',$handle),'create offer');$this->offered=true;
    }
    public function localDescription(bool $waitForCandidates=false,float $timeout=5.0):string {
        if(!is_finite($timeout) || $timeout<0 || $timeout>30)throw new \InvalidArgumentException('Invalid SDP timeout');
        $handle=$this->active();$deadline=microtime(true)+$timeout;
        do {
            $size=$this->nativeInt('mvo_rtc_description',$handle,$this->buffer,65536);
            if($size>0 && (!$waitForCandidates || $this->nativeInt('mvo_rtc_gathered',$handle)===1))return \FFI::string($this->buffer);
            if($size<0 && !in_array($size,[-3,-4],true))self::check($size,'read SDP');
            if(microtime(true)>=$deadline)break;usleep(1000);
        }while(true);
        throw new \RuntimeException('Native WebRTC SDP gathering timed out');
    }
    public function setRemoteDescription(string $sdp,string $type='answer'):void {
        if(!in_array($type,['offer','answer'],true) || strlen($sdp)>65535 || !str_starts_with($sdp,'v=0') || str_contains($sdp,"\0"))throw new \InvalidArgumentException('Invalid WebRTC remote description');
        self::check($this->nativeInt('mvo_rtc_remote',$this->active(),$sdp,$type),'set remote description');
    }
    public function state():string { return $this->handle===null?'closed':match($this->nativeInt('mvo_rtc_state',$this->handle)){0=>'new',1=>'connecting',2=>'connected',3=>'disconnected',4=>'failed',default=>'closed'}; }
    public function isOpen():bool { return $this->handle!==null && $this->nativeInt('mvo_rtc_open',$this->handle)===1; }
    public function send(string $bytes):void {
        if($bytes==='' || strlen($bytes)>65535)throw new \LengthException('SCTP datagram must contain 1..65535 bytes');
        self::check($this->nativeInt('mvo_rtc_send',$this->active(),$bytes,strlen($bytes)),'send datagram');
    }
    public function receive(float $timeout=0.0):?string {
        if(!is_finite($timeout) || $timeout<0 || $timeout>30)throw new \InvalidArgumentException('Invalid SCTP receive timeout');
        $handle=$this->active();$deadline=microtime(true)+$timeout;
        do {$size=$this->nativeInt('mvo_rtc_receive',$handle,$this->buffer,65536);self::check($size,'receive datagram');if($size>0)return \FFI::string($this->buffer,$size);if(microtime(true)>=$deadline)return null;usleep(1000);}while(true);
    }
    public function close():void { if($this->handle!==null){$handle=$this->handle;$this->handle=null;$this->nativeVoid('mvo_rtc_close',$handle);} }
    private function nativePointer(string $function,mixed ...$arguments):\FFI\CData {
        $result=$this->api->{$function}(...$arguments);
        if(!$result instanceof \FFI\CData || \FFI::isNull($result))throw new \RuntimeException('Native WebRTC allocation failed');
        return $result;
    }
    private function nativeVoid(string $function,mixed ...$arguments):void { $this->api->{$function}(...$arguments); }
    private function nativeInt(string $function,mixed ...$arguments):int {
        $result=$this->api->{$function}(...$arguments);
        if(!is_int($result))throw new \UnexpectedValueException('Native WebRTC ABI returned an invalid integer');
        return $result;
    }
    private function active():\FFI\CData { return $this->handle??throw new \LogicException('Native WebRTC peer is closed'); }
    private static function check(int $result,string $operation):void { if($result<0)throw new \RuntimeException('Native WebRTC '.$operation.' failed ('.$result.')'); }
    public function __destruct(){ $this->close(); }
}
