<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee\Noise;
use MVO\Messenger\E2ee\Crypto;
final class NoiseChannel
{
    private int $writeCounter = 0;
    private int $readCounter = 0;
    private bool $failed = false;
    public function __construct(private readonly string $writeKey, private readonly string $readKey) { Crypto::length($writeKey,32); Crypto::length($readKey,32); }
    public function encrypt(string $plain, string $aad = ''): string {
        if ($this->failed) throw new \LogicException('Channel invalidated');
        return Crypto::encryptGcm($this->writeKey,Crypto::nonce($this->writeCounter++),$plain,$aad);
    }
    public function decrypt(string $cipher, string $aad = ''): string {
        if ($this->failed) throw new \LogicException('Channel invalidated');
        try { return Crypto::decryptGcm($this->readKey,Crypto::nonce($this->readCounter++),$cipher,$aad); }
        catch (\Throwable $e) { $this->failed = true; throw $e; }
    }
    /** @return array<string,string> */
    public function __debugInfo():array{return ['messenger_noise_channel'=>'[PRIVATE]'];}
    /** @return array<never,never> */
    public function __serialize():array{throw new \LogicException('Do not serialize private Noise traffic keys');}
}
