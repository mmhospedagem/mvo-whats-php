<?php
declare(strict_types=1);
namespace MVO\Tests\Fixtures;

use MVO\Crypto\Crypto;
use MVO\Crypto\XEdDsa;
use MVO\Protocol\BinaryCodec;
use MVO\Protocol\BinaryNode;
use MVO\Protocol\Protobuf;
use MVO\Transport\FrameCodec;
use MVO\Transport\NoiseChannel;
use MVO\Transport\NoiseHandshake;
use MVO\Transport\WebSocket;

/** Real local Noise responder; replaces only network I/O, never cryptographic verification. */
final class NoisePeer extends WebSocket
{
    /** @var array{private:string,public:string} */
    public array $root;
    /** @var array{private:string,public:string} */
    public array $intermediate;
    /** @var array{private:string,public:string} */
    public array $server;
    public string $chain;
    public string $clientPayload = '';
    public string $clientStatic = '';
    public bool $closed = false;
    public string $routingInfo = '';
    /** @var list<BinaryNode> */
    public array $received = [];
    /** @var list<string> */
    private array $queue = [];
    private NoiseHandshake $handshake;
    private FrameCodec $frames;
    private ?NoiseChannel $channel = null;
    private string $ephemeralPrivate = '';
    private int $phase = 0;

    public function __construct(public bool $corruptCertificate = false, private readonly bool $resume = false, private readonly bool $fallback = false)
    {
        parent::__construct();
        $this->root = Crypto::x25519KeyPair();
        $this->intermediate = Crypto::x25519KeyPair();
        $this->server = Crypto::x25519KeyPair();
        $this->chain = self::message([
            1 => self::certificate(11, 7, $this->server['public'], $this->intermediate['private']),
            2 => self::certificate(7, 0, $this->intermediate['public'], $this->root['private']),
        ]);
        $this->handshake = new NoiseHandshake($resume ? NoiseHandshake::IK : NoiseHandshake::XX);
        $this->frames = new FrameCodec();
    }

    /** @param array<int,int|string> $values */
    public static function message(array $values): string
    {
        $fields = [];
        foreach ($values as $number => $value) $fields[] = ['number' => $number, 'wire' => is_int($value) ? 0 : 2, 'value' => $value];
        return Protobuf::encode($fields);
    }

    public static function certificate(int $serial, int $issuer, string $key, string $issuerPrivate): string
    {
        $details = self::message([1 => $serial, 2 => $issuer, 3 => $key]);
        return self::message([1 => $details, 2 => XEdDsa::sign($issuerPrivate, $details)]);
    }

    /** @return array<int,int|string> */
    public static function fields(string $bytes): array
    {
        $values = [];
        foreach (Protobuf::decode($bytes) as $field) $values[$field['number']] = $field['value'];
        return $values;
    }

    public function send(string $payload): void
    {
        if ($this->phase === 0) {
            if (str_starts_with($payload,"ED\x00\x01")) {
                $length=unpack('N',"\0".substr($payload,4,3))[1];
                $this->routingInfo=substr($payload,7,$length); $payload=substr($payload,7+$length);
            }
            if (!str_starts_with($payload, FrameCodec::HEADER)) throw new \RuntimeException('Missing WhatsApp intro');
            $payload = substr($payload, 4);
        }
        foreach ($this->frames->push($payload) as $frame) {
            if ($this->phase === 0) {
                $hello = self::fields(self::fields($frame)[2]);
                $clientEphemeral = $hello[1];
                $ephemeral = Crypto::x25519KeyPair();
                $this->ephemeralPrivate = $ephemeral['private'];
                if ($this->resume && !$this->fallback) {
                    $this->handshake->authenticate($this->server['public']);
                    $this->handshake->authenticate($clientEphemeral);
                    $this->handshake->mixKey(Crypto::x25519($this->server['private'],$clientEphemeral));
                    $this->clientStatic=$this->handshake->decrypt($hello[2]);
                    $this->handshake->mixKey(Crypto::x25519($this->server['private'],$this->clientStatic));
                    $this->clientPayload=$this->handshake->decrypt($hello[3]);
                    $this->handshake->authenticate($ephemeral['public']);
                    $this->handshake->mixKey(Crypto::x25519($ephemeral['private'],$clientEphemeral));
                    $this->handshake->mixKey(Crypto::x25519($ephemeral['private'],$this->clientStatic));
                    $certificate=$this->handshake->encrypt($this->chain);
                    $this->queue[]=$this->frames->encode(self::message([3=>self::message([1=>$ephemeral['public'],3=>$certificate])]));
                    $this->channel=$this->handshake->finish(false); $this->phase=2;
                    $this->enqueue(new BinaryNode('success',['t'=>'123']));
                    continue;
                }
                if ($this->resume) $this->handshake=new NoiseHandshake(NoiseHandshake::XX_FALLBACK);
                $this->handshake->authenticate($clientEphemeral);
                $this->handshake->authenticate($ephemeral['public']);
                $this->handshake->mixKey(Crypto::x25519($ephemeral['private'], $clientEphemeral));
                $static = $this->handshake->encrypt($this->server['public']);
                $this->handshake->mixKey(Crypto::x25519($this->server['private'], $clientEphemeral));
                $chain = $this->chain;
                if ($this->corruptCertificate) $chain[strlen($chain) - 1] = $chain[strlen($chain) - 1] ^ "\x01";
                $certificate = $this->handshake->encrypt($chain);
                $wire = $this->frames->encode(self::message([3 => self::message([1 => $ephemeral['public'], 2 => $static, 3 => $certificate])]));
                // An arbitrary WebSocket boundary must not become a Noise-frame boundary.
                $this->queue[] = substr($wire, 0, 7);
                $this->queue[] = substr($wire, 7);
                $this->phase = 1;
            } elseif ($this->phase === 1) {
                $finish = self::fields(self::fields($frame)[4]);
                $this->clientStatic = $this->handshake->decrypt($finish[1]);
                $this->handshake->mixKey(Crypto::x25519($this->ephemeralPrivate, $this->clientStatic));
                $this->clientPayload = $this->handshake->decrypt($finish[2]);
                $this->channel = $this->handshake->finish(false);
                $this->phase = 2;
                $this->enqueue(new BinaryNode('success', ['t' => '123']));
            } else {
                $this->received[] = (new BinaryCodec())->decode($this->channel->decrypt($frame), true);
            }
        }
    }

    public function enqueue(BinaryNode $node): void
    {
        $this->queue[] = $this->frames->encode($this->channel->encrypt((new BinaryCodec())->encode($node, true)));
    }

    public function tamperNext(): void
    {
        $last = count($this->queue) - 1;
        $position = strlen($this->queue[$last]) - 1;
        $this->queue[$last][$position] = $this->queue[$last][$position] ^ "\x01";
    }

    public function receive(float $timeoutSeconds = 20.0): ?string { return array_shift($this->queue); }
    public function close(): void { $this->closed = true; }
}
