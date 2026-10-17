<?php
declare(strict_types=1);

namespace MVO\Transport;

use MVO\Crypto\Crypto;
use MVO\Protocol\BinaryCodec;
use MVO\Protocol\BinaryNode;
use MVO\Protocol\Protobuf;

/** Authenticated Noise XX, persisted-static IK resume and XXfallback transport. */
final class NoiseSession implements NodeSession
{
    private FrameCodec $frames;
    private BinaryCodec $binary;
    private ?NoiseChannel $channel = null;
    /** @var list<string> */
    private array $inbox = [];
    private bool $started = false;

    public function __construct(
        private readonly WebSocket $socket,
        private readonly float $handshakeTimeoutSeconds = 20.0,
        private readonly ?string $rootPublicKey = null,
        private readonly int $rootSerial = 0,
        private readonly ?string $serverStaticKey = null,
        private readonly ?string $routingInfo = null,
    ) {
        if (!is_finite($handshakeTimeoutSeconds) || $handshakeTimeoutSeconds <= 0) throw new \InvalidArgumentException('Invalid Noise handshake timeout');
        if ($serverStaticKey !== null) Crypto::length($serverStaticKey,32);
        if ($routingInfo !== null && strlen($routingInfo)>65535) throw new \LengthException('Routing info too large');
        $routing = $routingInfo === null || $routingInfo === '' ? '' : "ED\x00\x01".substr(pack('N',strlen($routingInfo)),1).$routingInfo;
        $this->frames = new FrameCodec($routing.FrameCodec::HEADER);
        $this->binary = new BinaryCodec();
    }

    /** Returns the authenticated raw 32-byte server static public key. */
    public function start(string $staticPrivate, string $staticPublic, string $clientPayload): string
    {
        if ($this->started) throw new \LogicException('Noise session has already started');
        $this->started = true;
        try {
            Crypto::length($staticPrivate, 32);
            Crypto::length($staticPublic, 32);
            if (!hash_equals(sodium_crypto_scalarmult_base($staticPrivate), $staticPublic)) throw new \InvalidArgumentException('Noise static key pair does not match');
            $ephemeral = Crypto::x25519KeyPair();
            $handshake = new NoiseHandshake($this->serverStaticKey === null ? NoiseHandshake::XX : NoiseHandshake::IK);
            if ($this->serverStaticKey !== null) $handshake->authenticate($this->serverStaticKey);
            $handshake->authenticate($ephemeral['public']);
            $helloFields = [1 => $ephemeral['public']];
            if ($this->serverStaticKey !== null) {
                $handshake->mixKey(Crypto::x25519($ephemeral['private'],$this->serverStaticKey));
                $helloFields[2] = $handshake->encrypt($staticPublic);
                $handshake->mixKey(Crypto::x25519($staticPrivate,$this->serverStaticKey));
                $helloFields[3] = $handshake->encrypt($clientPayload);
            }
            $hello = self::message($helloFields);
            $this->socket->send($this->frames->encode(self::message([2 => $hello])));
            $frame = $this->nextFrame($this->handshakeTimeoutSeconds);
            if ($frame === null) throw new \RuntimeException('Noise server hello timed out');
            $server = self::fields(self::required(self::fields($frame), 3));
            $serverEphemeral = self::required($server, 1);
            Crypto::length($serverEphemeral, 32);
            if ($this->serverStaticKey !== null && !isset($server[2])) {
                $handshake->authenticate($serverEphemeral);
                $handshake->mixKey(Crypto::x25519($ephemeral['private'],$serverEphemeral));
                $handshake->mixKey(Crypto::x25519($staticPrivate,$serverEphemeral));
                // The persisted static key was authenticated by the previous XX
                // certificate chain. Its DH possession authenticates this resume.
                $handshake->decrypt(self::required($server,3));
                $this->channel = $handshake->finish();
                return $this->serverStaticKey;
            }
            if ($this->serverStaticKey !== null) {
                $handshake = new NoiseHandshake(NoiseHandshake::XX_FALLBACK);
                $handshake->authenticate($ephemeral['public']);
            }
            $handshake->authenticate($serverEphemeral);
            $handshake->mixKey(Crypto::x25519($ephemeral['private'], $serverEphemeral));
            $serverStatic = $handshake->decrypt(self::required($server, 2));
            Crypto::length($serverStatic, 32);
            $handshake->mixKey(Crypto::x25519($ephemeral['private'], $serverStatic));
            $certificate = $handshake->decrypt(self::required($server, 3));
            NoiseCertificate::verify($certificate, $serverStatic, $this->rootPublicKey, $this->rootSerial);
            $encryptedStatic = $handshake->encrypt($staticPublic);
            $handshake->mixKey(Crypto::x25519($staticPrivate, $serverEphemeral));
            $encryptedPayload = $handshake->encrypt($clientPayload);
            $finish = self::message([1 => $encryptedStatic, 2 => $encryptedPayload]);
            $this->socket->send($this->frames->encode(self::message([4 => $finish])));
            $this->channel = $handshake->finish();
            return $serverStatic;
        } catch (\Throwable $error) { $this->close(); throw $error; }
    }

    public function sendNode(BinaryNode $node): void
    {
        if ($this->channel === null) throw new \LogicException('Noise session is not established');
        try { $this->socket->send($this->frames->encode($this->channel->encrypt($this->binary->encode($node, true)))); }
        catch (\Throwable $error) { $this->close(); throw $error; }
    }

    public function receiveNode(float $timeoutSeconds = 1.0): ?BinaryNode
    {
        if ($this->channel === null) throw new \LogicException('Noise session is not established');
        try {
            $frame = $this->nextFrame($timeoutSeconds);
            return $frame === null ? null : $this->binary->decode($this->channel->decrypt($frame), true);
        } catch (\Throwable $error) { $this->close(); throw $error; }
    }

    public function close(): void
    {
        $this->channel = null;
        $this->inbox = [];
        $this->socket->close();
    }

    private function nextFrame(float $timeoutSeconds): ?string
    {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds < 0) throw new \InvalidArgumentException('Invalid Noise receive timeout');
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            if ($this->inbox !== []) return array_shift($this->inbox);
            $chunk = $this->socket->receive(max(0.0, $deadline - microtime(true)));
            if ($chunk === null) return null;
            foreach ($this->frames->push($chunk) as $frame) $this->inbox[] = $frame;
        } while ($this->inbox !== [] || microtime(true) < $deadline);
        return null;
    }

    /** @param array<int,string> $fields */
    private static function message(array $fields): string
    {
        $out = [];
        foreach ($fields as $number => $value) $out[] = ['number' => $number, 'wire' => 2, 'value' => $value];
        return Protobuf::encode($out);
    }

    /** @return array<int,string> */
    private static function fields(string $message): array
    {
        $out = [];
        foreach (Protobuf::decode($message, 1048576, 64) as $field) {
            if ($field['wire'] !== 2) continue;
            if (isset($out[$field['number']])) throw new \UnexpectedValueException('Duplicate Noise handshake field');
            if (!is_string($field['value'])) throw new \UnexpectedValueException('Invalid Noise handshake bytes');
            $out[$field['number']] = $field['value'];
        }
        return $out;
    }

    /** @param array<int,string> $fields */
    private static function required(array $fields, int $number): string
    {
        return $fields[$number] ?? throw new \UnexpectedValueException('Missing Noise handshake field ' . $number);
    }
}
