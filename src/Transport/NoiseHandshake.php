<?php
declare(strict_types=1);
namespace MVO\Transport;
use MVO\Crypto\Crypto;
/** Noise symmetric state. Wire sequencing and certificate trust live in NoiseSession. */
final class NoiseHandshake
{
    public const XX = "Noise_XX_25519_AESGCM_SHA256\0\0\0\0";
    public const IK = "Noise_IK_25519_AESGCM_SHA256\0\0\0\0";
    public const XX_FALLBACK = 'Noise_XXfallback_25519_AESGCM_SHA256';
    private string $hash;
    private string $chain;
    private ?string $key;
    private int $nonce = 0;
    public function __construct(string $name = self::XX, string $prologue = FrameCodec::HEADER) {
        $this->hash = strlen($name) === 32 ? $name : Crypto::sha256($name);
        $this->chain = $this->hash; $this->key = $this->hash; $this->authenticate($prologue);
    }
    private function active(): void { if ($this->key === null) throw new \LogicException('Handshake finished or invalidated'); }
    public function authenticate(string $data): void { $this->active(); $this->hash = Crypto::sha256($this->hash.$data); }
    public function mixKey(string $material): void {
        $this->active(); $keys = Crypto::hkdf($material,64,'',$this->chain);
        $this->chain = substr($keys,0,32); $this->key = substr($keys,32); $this->nonce = 0;
    }
    public function encrypt(string $plain): string {
        $this->active(); $cipher = Crypto::encryptGcm($this->key,Crypto::nonce($this->nonce++),$plain,$this->hash);
        $this->authenticate($cipher); return $cipher;
    }
    public function decrypt(string $cipher): string {
        $this->active();
        try { $plain = Crypto::decryptGcm($this->key,Crypto::nonce($this->nonce++),$cipher,$this->hash); }
        catch (\Throwable $e) { $this->key = null; throw $e; }
        $this->authenticate($cipher); return $plain;
    }
    public function finish(bool $initiator = true): NoiseChannel {
        $this->active(); $keys = Crypto::hkdf('',64,'',$this->chain);
        $this->key = null; $this->hash = $this->chain = '';
        $a = substr($keys,0,32); $b = substr($keys,32);
        return new NoiseChannel($initiator ? $a : $b,$initiator ? $b : $a);
    }
}
