<?php
declare(strict_types=1);
namespace MVO\Testing;
use MVO\Crypto\{Crypto,XEdDsa};
use MVO\Protocol\{BinaryCodec,BinaryNode,Protobuf};
use MVO\Transport\{FrameCodec,NoiseHandshake,NoiseChannel};

/** Test CA with real Noise XX/IK/XXfallback. No production trust anchors are modified. */
final class NoiseResponder
{
    private NoiseHandshake $handshake;
    private FrameCodec $frames;
    private BinaryCodec $binary;
    private ?NoiseChannel $channel=null;
    private int $phase=0;
    private string $intro='';
    private bool $introReceived=false;
    private string $ephemeralPrivate='';
    private string $chain;
    private string $clientPayload='';
    private string $clientStatic='';
    private string $mode='';
    private string $routing='';
    /** @var array{private:string,public:string} */
    private array $server;
    /** @param array{private:string,public:string} $root
     * @param array{private:string,public:string}|null $server */
    public function __construct(array $root,?array $server=null,private readonly bool $corruptCertificate=false,private readonly bool $forceFallback=false)
    {
        $intermediate=Crypto::x25519KeyPair();$this->server=$server ?? Crypto::x25519KeyPair();$this->chain=self::message([1=>self::certificate(11,7,$this->server['public'],$intermediate['private']),2=>self::certificate(7,0,$intermediate['public'],$root['private'])]);$this->handshake=new NoiseHandshake();$this->frames=new FrameCodec(maxLength:1048576);$this->binary=new BinaryCodec();
    }
    public function isAuthenticated(): bool { return $this->channel!==null; }
    public function clientPayload():string { return $this->clientPayload; }
    public function clientStaticKey():string { return $this->clientStatic; }
    public function mode():string { return $this->mode; }
    public function routingInfo():string { return $this->routing; }
    /** @return array{out:list<string>,nodes:list<BinaryNode>,authenticated:bool} */
    public function push(string $bytes): array
    {
        $out=[];$nodes=[];$justAuthenticated=false;
        if(!$this->introReceived) { $this->intro.=$bytes;if(strlen($this->intro)<4)return ['out'=>[],'nodes'=>[],'authenticated'=>false];$offset=0;if(substr($this->intro,0,4)==="ED\x00\x01"){if(strlen($this->intro)<7)return ['out'=>[],'nodes'=>[],'authenticated'=>false];$length=unpack('N',"\0".substr($this->intro,4,3))[1];if($length>65535)throw new \LengthException('Fake Noise routing info exceeds 64 KiB');$offset=7+$length;if(strlen($this->intro)<$offset+4)return ['out'=>[],'nodes'=>[],'authenticated'=>false];$this->routing=substr($this->intro,7,$length);}if(strlen($this->intro)<$offset+4)return ['out'=>[],'nodes'=>[],'authenticated'=>false];if(substr($this->intro,$offset,4)!==FrameCodec::HEADER)throw new \UnexpectedValueException('Fake server requires WhatsApp Noise intro');$bytes=substr($this->intro,$offset+4);$this->intro='';$this->introReceived=true; }
        foreach($this->frames->push($bytes) as $frame) {
            if($this->phase===0) {
                $hello=self::fields(self::field(self::fields($frame),2));if(isset($hello[2])!==isset($hello[3]))throw new \UnexpectedValueException('Incomplete fake Noise IK hello');$client=self::field($hello,1);Crypto::length($client,32);$ephemeral=Crypto::x25519KeyPair();$this->ephemeralPrivate=$ephemeral['private'];$ik=isset($hello[2]);
                if($ik && !$this->forceFallback){try{$resume=new NoiseHandshake(NoiseHandshake::IK);$resume->authenticate($this->server['public']);$resume->authenticate($client);$resume->mixKey(Crypto::x25519($this->server['private'],$client));$clientStatic=$resume->decrypt(self::field($hello,2));Crypto::length($clientStatic,32);$resume->mixKey(Crypto::x25519($this->server['private'],$clientStatic));$clientPayload=$resume->decrypt(self::field($hello,3));}catch(\UnexpectedValueException){$resume=null;}
                    if($resume!==null){$this->handshake=$resume;$this->clientStatic=$clientStatic;$this->clientPayload=$clientPayload;$this->mode='IK';$resume->authenticate($ephemeral['public']);$resume->mixKey(Crypto::x25519($ephemeral['private'],$client));$resume->mixKey(Crypto::x25519($ephemeral['private'],$clientStatic));$certificate=$resume->encrypt($this->chain);$out[]=$this->frames->encode(self::message([3=>self::message([1=>$ephemeral['public'],3=>$certificate])]));$this->channel=$resume->finish(false);$this->phase=2;$this->ephemeralPrivate='';$justAuthenticated=true;continue;}
                }
                $this->mode=$ik?'XXfallback':'XX';$this->handshake=new NoiseHandshake($ik?NoiseHandshake::XX_FALLBACK:NoiseHandshake::XX);$this->handshake->authenticate($client);$this->handshake->authenticate($ephemeral['public']);$this->handshake->mixKey(Crypto::x25519($ephemeral['private'],$client));$static=$this->handshake->encrypt($this->server['public']);$this->handshake->mixKey(Crypto::x25519($this->server['private'],$client));$chain=$this->chain;if($this->corruptCertificate)$chain[strlen($chain)-1]=$chain[strlen($chain)-1]^"\x01";$cert=$this->handshake->encrypt($chain);$out[]=$this->frames->encode(self::message([3=>self::message([1=>$ephemeral['public'],2=>$static,3=>$cert])]));$this->phase=1;
            } elseif($this->phase===1) {
                $finish=self::fields(self::field(self::fields($frame),4));$client=$this->handshake->decrypt(self::field($finish,1));Crypto::length($client,32);$this->handshake->mixKey(Crypto::x25519($this->ephemeralPrivate,$client));$this->clientPayload=$this->handshake->decrypt(self::field($finish,2));$this->clientStatic=$client;$this->channel=$this->handshake->finish(false);$this->ephemeralPrivate='';$this->phase=2;$justAuthenticated=true;
            } else $nodes[]=$this->binary->decode(($this->channel ?? throw new \LogicException('Noise not established'))->decrypt($frame),true);
        }return ['out'=>$out,'nodes'=>$nodes,'authenticated'=>$justAuthenticated];
    }
    public function encode(BinaryNode $node): string { return $this->frames->encode(($this->channel ?? throw new \LogicException('Noise not authenticated'))->encrypt($this->binary->encode($node,true))); }
    /** @param array<int,int|string> $fields */
    private static function message(array $fields): string { $out=[];foreach($fields as $id=>$value)$out[]=['number'=>$id,'wire'=>is_int($value)?0:2,'value'=>$value];return Protobuf::encode($out); }
    /** @return array<int,int|string> */
    private static function fields(string $bytes): array { $out=[];foreach(Protobuf::decode($bytes,1048576,32) as $f) { if(isset($out[$f['number']]))throw new \UnexpectedValueException('Duplicate fake Noise field');$out[$f['number']]=$f['value']; }return $out; }
    /** @param array<int,int|string> $fields */
    private static function field(array $fields,int $id): string { $v=$fields[$id] ?? null;if(!is_string($v))throw new \UnexpectedValueException('Missing fake Noise field');return $v; }
    private static function certificate(int $serial,int $issuer,string $public,string $issuerPrivate): string { $details=self::message([1=>$serial,2=>$issuer,3=>$public]);return self::message([1=>$details,2=>XEdDsa::sign($issuerPrivate,$details)]); }
}
