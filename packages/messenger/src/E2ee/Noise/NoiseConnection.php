<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee\Noise;
use MVO\Messenger\E2ee\{Crypto,ProtoWire};
use MVO\Messenger\E2ee\Protocol\{BinaryNode,BinaryCodec};
use MVO\Messenger\Transport\Socket;

/** Fresh XX only. Never reuses a WhatsApp static or an unproven Messenger IK resume profile. */
final class NoiseConnection
{
    public const URL='wss://web-chat-e2ee.facebook.com/ws/chat';
    private FrameCodec $frames;private BinaryCodec $codec;private ?NoiseChannel $channel=null;
    /** @var list<string> */private array $pending=[];private bool $started=false;
    public function __construct(private readonly Socket $socket,private readonly float $timeoutSeconds=20,private readonly ?string $testRootPublic=null)
    {
        if(!is_finite($timeoutSeconds)||$timeoutSeconds<=0)throw new \InvalidArgumentException('Invalid encrypted handshake timeout');
        $this->frames=new FrameCodec(FrameCodec::HEADER);$this->codec=new BinaryCodec();
    }
    /** Returns the certificate-authenticated static, NOT a successful Messenger login.
     * @param array{private:string,public:string} $localNoise
     */
    public function start(#[\SensitiveParameter]array $localNoise,#[\SensitiveParameter]string $clientPayload):string
    {
        if($this->started)throw new \LogicException('Noise connection is single-use');$this->started=true;
        try{
            Crypto::length($localNoise['private'],32);Crypto::length($localNoise['public'],32);
            if(!hash_equals(sodium_crypto_scalarmult_base($localNoise['private']),$localNoise['public']))throw new \InvalidArgumentException('Inconsistent Messenger Noise keypair');
            if(strlen($clientPayload)>1048576)throw new \LengthException('Messenger client payload exceeds limit');
            $ephemeral=Crypto::x25519KeyPair();$state=new NoiseHandshake();$state->authenticate($ephemeral['public']);$this->socket->connect();
            $this->socket->send($this->frames->encode(ProtoWire::bytes(2,ProtoWire::bytes(1,$ephemeral['public']))));
            $frame=$this->nextFrame($this->timeoutSeconds);if($frame===null)throw new \RuntimeException('Messenger Noise hello timed out');
            $server=self::fields(self::bytes(self::fields($frame),3));$remoteEphemeral=self::bytes($server,1);Crypto::length($remoteEphemeral,32);
            $state->authenticate($remoteEphemeral);$state->mixKey(Crypto::x25519($ephemeral['private'],$remoteEphemeral));
            $serverStatic=$state->decrypt(self::bytes($server,2));Crypto::length($serverStatic,32);$state->mixKey(Crypto::x25519($ephemeral['private'],$serverStatic));
            $chain=$state->decrypt(self::bytes($server,3));NoiseCertificate::verify($chain,$serverStatic,rootPublic:$this->testRootPublic);
            $encryptedStatic=$state->encrypt($localNoise['public']);$state->mixKey(Crypto::x25519($localNoise['private'],$remoteEphemeral));$encryptedPayload=$state->encrypt($clientPayload);
            $this->socket->send($this->frames->encode(ProtoWire::bytes(4,ProtoWire::bytes(1,$encryptedStatic).ProtoWire::bytes(2,$encryptedPayload))));
            $this->channel=$state->finish();return $serverStatic;
        }catch(\Throwable $error){$this->close();throw $error;}
    }
    public function sendNode(BinaryNode $node):void
    {
        if($this->channel===null)throw new \LogicException('Messenger Noise is not established');
        try{$this->socket->send($this->frames->encode($this->channel->encrypt($this->codec->encode($node,true))));}catch(\Throwable $e){$this->close();throw $e;}
    }
    public function receiveNode(float $timeoutSeconds=0.5):?BinaryNode
    {
        if($this->channel===null)throw new \LogicException('Messenger Noise is not established');
        try{$raw=$this->nextFrame($timeoutSeconds);return $raw===null?null:$this->codec->decode($this->channel->decrypt($raw),true);}catch(\Throwable $e){$this->close();throw $e;}
    }
    public function isEstablished():bool{return $this->channel!==null&&$this->socket->isConnected();}
    public function close():void{$this->channel=null;$this->pending=[];$this->socket->close();}
    private function nextFrame(float $timeout):?string
    {
        if(!is_finite($timeout)||$timeout<0)throw new \InvalidArgumentException('Invalid Messenger receive timeout');$deadline=microtime(true)+$timeout;
        do{if($this->pending!==[])return array_shift($this->pending);$chunk=$this->socket->receive(max(0,$deadline-microtime(true)));if($chunk===null)return null;foreach($this->frames->push($chunk)as$frame)$this->pending[]=$frame;}while($this->pending!==[]||microtime(true)<$deadline);return null;
    }
    /** @return array<int,string> */
    private static function fields(string $raw):array{$out=[];foreach(ProtoWire::decode($raw)as$id=>$values){if(count($values)!==1||!is_string($values[0]))throw new \UnexpectedValueException('Invalid Messenger Noise field');$out[$id]=$values[0];}return $out;}
    /** @param array<int,string> $fields */
    private static function bytes(array $fields,int $id):string{return $fields[$id]??throw new \UnexpectedValueException('Missing Messenger Noise field');}
    public function __debugInfo():array{return ['noise_established'=>$this->isEstablished()];}
    public function __serialize():array{throw new \LogicException('Do not serialize an encrypted connection');}
}
