<?php
declare(strict_types=1);
namespace MVO\Messenger\Tests;
use MVO\Messenger\E2ee\{Crypto,ProtoWire as W,XEdDsa};
use MVO\Messenger\E2ee\Noise\{NoiseHandshake,NoiseChannel,FrameCodec};
use MVO\Messenger\E2ee\Protocol\{BinaryCodec,BinaryNode};
use MVO\Messenger\Transport\Socket;
/** Synthetic certificate issuer and Noise responder; no socket, account, CAT request or Meta I/O. */
final class MessengerNoisePeer implements Socket
{
    /** @var array{private:string,public:string} */public array $root;
    /** @var array{private:string,public:string} */public array $server;
    public string $clientPayload='';public bool $closed=false;public ?\Closure $onNode=null;public ?BinaryNode $loginResult=null;public bool $omitLogin=false;private bool $connected=false;private int $phase=0;
    private ?NoiseHandshake $state=null;private ?NoiseChannel $channel=null;private BinaryCodec $codec;
    /** @var array{private:string,public:string} */private array $ephemeral;
    /** @var list<string> */private array $queue=[];
    /** @var list<BinaryNode> */public array $received=[];
    public function __construct(private readonly ?string $failure=null){$this->root=Crypto::x25519KeyPair();$this->server=Crypto::x25519KeyPair();$this->ephemeral=Crypto::x25519KeyPair();$this->codec=new BinaryCodec();}
    public function connect():void{$this->connected=true;}
    public function isConnected():bool{return $this->connected;}
    public function close():void{$this->connected=false;$this->closed=true;}
    public function receive(float $timeoutSeconds=0.5):?string{return array_shift($this->queue);}
    public function send(string $raw):void
    {
        if($this->phase===0){if(!str_starts_with($raw,FrameCodec::HEADER))throw new \UnexpectedValueException('Wrong Messenger Noise prologue');$raw=substr($raw,4);}
        $length=unpack('N',"\0".substr($raw,0,3))[1];$frame=substr($raw,3);if(strlen($frame)!==$length)throw new \UnexpectedValueException('Wrong frame length');
        if($this->phase===0){$hello=self::bytes(W::decode($frame),2);$remote=self::bytes(W::decode($hello),1);$this->state=new NoiseHandshake();$this->state->authenticate($remote);$this->state->authenticate($this->ephemeral['public']);$this->state->mixKey(Crypto::x25519($this->ephemeral['private'],$remote));$static=$this->state->encrypt($this->server['public']);$this->state->mixKey(Crypto::x25519($this->server['private'],$remote));$payload=$this->state->encrypt($this->certificate());$reply=W::bytes(3,W::bytes(1,$this->ephemeral['public']).W::bytes(2,$static).W::bytes(3,$payload));$this->queue[]=substr(pack('N',strlen($reply)),1).$reply;++$this->phase;return;}
        if($this->phase===1){$finish=W::decode(self::bytes(W::decode($frame),4));$static=$this->state->decrypt(self::bytes($finish,1));$this->state->mixKey(Crypto::x25519($this->ephemeral['private'],$static));$this->clientPayload=$this->state->decrypt(self::bytes($finish,2));$this->channel=$this->state->finish(false);++$this->phase;if(!$this->omitLogin)$this->enqueue($this->loginResult??new BinaryNode('success'));return;}
        $node=$this->codec->decode($this->channel->decrypt($frame),true);$this->received[]=$node;($this->onNode)?->__invoke($node,$this);
    }
    private function certificate():string
    {
        $intermediate=Crypto::x25519KeyPair();$now=time();$before=$this->failure==='future'?$now+60:$now-60;$after=$this->failure==='expired'?$now-1:$now+600;
        if($this->failure==='expired')$before=$now-120;
        $middle=W::integer(1,1).W::integer(2,0).W::bytes(3,$intermediate['public']).W::integer(4,$now-120).W::integer(5,$now+600);
        $leaf=W::integer(1,2).W::integer(2,1).W::bytes(3,$this->failure==='static'?Crypto::x25519KeyPair()['public']:$this->server['public']).W::integer(4,$before).W::integer(5,$after);
        $signature=$this->failure==='signature'?str_repeat('x',64):XEdDsa::sign($intermediate['private'],$leaf);
        return W::bytes(1,W::bytes(1,$leaf).W::bytes(2,$signature)).W::bytes(2,W::bytes(1,$middle).W::bytes(2,XEdDsa::sign($this->root['private'],$middle)));
    }
    public function enqueue(BinaryNode $node):void{$cipher=$this->channel->encrypt($this->codec->encode($node,true));$framed=substr(pack('N',strlen($cipher)),1).$cipher;$this->queue[]=substr($framed,0,2);$this->queue[]=substr($framed,2);}
    /** @param array<int,list<int|string>> $fields */
    private static function bytes(array $fields,int $id):string{$v=$fields[$id][0]??null;if(!is_string($v))throw new \UnexpectedValueException('Wrong synthetic Noise field');return $v;}
}
