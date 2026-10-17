<?php
declare(strict_types=1);
namespace MVO\Transport;
use MVO\Crypto\Crypto;
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
}
