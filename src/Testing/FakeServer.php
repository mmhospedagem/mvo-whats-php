<?php
declare(strict_types=1);
namespace MVO\Testing;
use MVO\Crypto\Crypto;
use MVO\Protocol\BinaryNode;

/** Foreground development server. Native WebSocket + authenticated Noise, explicitly loopback only. */
final class FakeServer
{
    /** @var resource|null */
    private $listener=null;
    /** @var array{private:string,public:string} */
    private array $root;
    /** @var array{private:string,public:string} */
    private array $server;
    /** @var array<string,\Closure(BinaryNode):list<BinaryNode>> */
    private array $routes=[];
    private string $url='';
    private string $activeDevice='';
    public function __construct(private readonly int $port=0,private readonly bool $sendSuccess=true,private readonly bool $corruptCertificate=false,private ?FakeProtocol $protocol=null)
    {
        if($port<0 || $port>65535)throw new \InvalidArgumentException('Invalid fake port');$this->root=Crypto::x25519KeyPair();$this->server=Crypto::x25519KeyPair();
    }
    /** Handler response nodes are encrypted with the real negotiated Noise transport.
     * @param callable(BinaryNode):list<BinaryNode> $handler */
    public function route(string $tag,string $namespace,callable $handler): void { if($tag==='')throw new \InvalidArgumentException('Fake route tag required');$this->routes[$tag.'\0'.$namespace]=\Closure::fromCallable($handler); }
    public function listen(): void
    {
        if(is_resource($this->listener))return;$socket=@stream_socket_server('tcp://127.0.0.1:'.$this->port,$code,$error);if($socket===false)throw new \RuntimeException('Cannot bind fake server loopback port');$this->listener=$socket;$address=stream_socket_get_name($socket,false);if($address===false)throw new \RuntimeException('Fake server address unavailable');$this->url='ws://'.$address.'/ws/chat';
    }
    public function url(): string { if($this->url==='')throw new \LogicException('Call listen first');return $this->url; }
    public function rootPublicKey(): string { return $this->root['public']; }
    public function useProtocol(FakeProtocol $protocol):void {$this->protocol=$protocol;}
    public function protocol():?FakeProtocol {return $this->protocol;}
    public function authenticatedDevice():?string {return $this->activeDevice==='' ? null : $this->activeDevice;}
    /** Accept one connection and serve a bounded number of nodes; useful for deterministic tests. */
    public function serveOnce(float $acceptTimeout=30.0,int $maxNodes=1000,?callable $tick=null): bool
    {
        $this->listen();if($maxNodes<1 || $maxNodes>100000 || !is_finite($acceptTimeout) || $acceptTimeout<0)throw new \InvalidArgumentException('Invalid fake node/timeout limit');$socket=@stream_socket_accept($this->listener,$acceptTimeout);if($socket===false)return false;$ws=new ServerWebSocket($socket);$noise=new NoiseResponder($this->root,$this->server,$this->corruptCertificate);$seen=0;
        try {
            $ws->upgrade();while($seen<$maxNodes) {
                if($tick!==null&&$tick()===false)break;
                try{$bytes=$ws->receive($this->protocol===null ? 10 : 0.02);}catch(\RuntimeException $e){if(is_resource($socket)&&feof($socket))break;throw $e;}
                if($bytes===null){if($ws->isClosed()||$this->protocol===null)break;if($this->activeDevice!=='')foreach($this->protocol->drainOutbound($this->activeDevice) as $out)$ws->send($noise->encode($out));continue;}
                $result=$noise->push($bytes);foreach($result['out'] as $wire)$ws->send($wire);
                if($result['authenticated']){
                    $this->activeDevice=$this->protocol?->authenticate($noise->clientPayload(),$noise->clientStaticKey()) ?? '';
                    if($this->sendSuccess&&($this->protocol===null||$this->protocol->isRegistered($this->activeDevice)))$ws->send($noise->encode(new BinaryNode('success',['t'=>(string)time()])));
                }
                foreach($result['nodes'] as $node) { $seen++;foreach($this->dispatch($node) as $response)$ws->send($noise->encode($response)); }
                if($this->protocol!==null&&$this->activeDevice!=='')foreach($this->protocol->drainOutbound($this->activeDevice) as $out)$ws->send($noise->encode($out));
            }return true;
        } finally { $this->activeDevice='';$ws->close(); }
    }
    /** @return list<BinaryNode> */
    public function dispatch(BinaryNode $node): array
    {
        $route=$this->routes[$node->tag.'\0'.($node->attrs['xmlns'] ?? '')] ?? null;if($route!==null)return $route($node);
        if($this->protocol!==null && $this->activeDevice!=='')return $this->protocol->dispatch($node,$this->activeDevice);
        $id=$node->attrs['id'] ?? '';
        if($node->tag==='iq') {
            $ns=$node->attrs['xmlns'] ?? '';if(in_array($ns,['w:p','urn:xmpp:ping'],true))return [new BinaryNode('iq',['type'=>'result','id'=>$id,'from'=>'s.whatsapp.net'])];
            if($ns==='test:echo')return [new BinaryNode('iq',['type'=>'result','id'=>$id,'from'=>'s.whatsapp.net'],$node->content)];
            return [new BinaryNode('iq',['type'=>'error','id'=>$id,'from'=>'s.whatsapp.net'],[new BinaryNode('error',['code'=>'501','text'=>'fake-route-not-implemented'])])];
        }
        if($node->tag==='message' && $id!=='')return [new BinaryNode('ack',['class'=>'message','id'=>$id,'from'=>$node->attrs['to'] ?? 's.whatsapp.net','t'=>(string)time()])];
        return [];
    }
    public function close(): void { if(is_resource($this->listener))fclose($this->listener);$this->listener=null;$this->url=''; }
}
