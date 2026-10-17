<?php
declare(strict_types=1);

namespace MVO\Signal;

use MVO\Crypto\Crypto;

/**
 * Internal, binary-safe Signal state types. Public keys use Signal's 0x05 prefix.
 *
 * @phpstan-type KeyPair array{private:string,public:string}
 * @phpstan-type MessageKey array{index:int,cipherKey:string,macKey:string,iv:string}
 * @phpstan-type ReceiveChain array{public:string,index:int,key:string,unused:list<MessageKey>}
 * @phpstan-type SendChain array{pair:KeyPair,index:int,key:string}
 * @phpstan-type Snapshot array{localIdentity:string,remoteIdentity:string,localRegistration:int,remoteRegistration:int,root:string,send:SendChain,receive:list<ReceiveChain>,pending:array{preKeyId:?int,signedPreKeyId:int,baseKey:string}|null,previousCounter:int,baseKey:string}
 * @phpstan-type Session array{localIdentity:string,remoteIdentity:string,localRegistration:int,remoteRegistration:int,root:string,send:SendChain,receive:list<ReceiveChain>,pending:array{preKeyId:?int,signedPreKeyId:int,baseKey:string}|null,previousCounter:int,baseKey:string,previous:list<Snapshot>}
 */
final class SignalState
{
    public static function publicKey(string $key): string
    {
        if (strlen($key) === 32) return "\x05".$key;
        if (strlen($key) === 33 && $key[0] === "\x05") return $key;
        throw new \UnexpectedValueException('Invalid Signal public key');
    }

    public static function rawPublicKey(string $key): string { return substr(self::publicKey($key), 1); }

    /** @return KeyPair */
    public static function keyPair(): array
    {
        $pair = Crypto::x25519KeyPair();
        return ['private'=>$pair['private'], 'public'=>self::publicKey($pair['public'])];
    }

    /**
     * @param KeyPair $pair
     * @return KeyPair
     */
    public static function normalizePair(array $pair): array
    {
        Crypto::length($pair['private'],32);
        $public = self::publicKey($pair['public']);
        if (!hash_equals(sodium_crypto_scalarmult_base($pair['private']),substr($public,1))) {
            throw new \UnexpectedValueException('Inconsistent Signal keypair');
        }
        return ['private'=>$pair['private'],'public'=>$public];
    }

    public static function pack(mixed $value): mixed
    {
        if (is_string($value)) return ['$bytes'=>base64_encode($value)];
        if (is_array($value)) return array_map(self::pack(...),$value);
        if (is_int($value) || is_bool($value) || $value === null) return $value;
        throw new \UnexpectedValueException('Unsupported Signal state value');
    }

    public static function unpack(mixed $value): mixed
    {
        if (is_array($value) && array_keys($value) === ['$bytes']) {
            if (!is_string($value['$bytes'])) throw new \UnexpectedValueException('Invalid Signal binary encoding');
            $decoded = base64_decode($value['$bytes'],true);
            if ($decoded === false) throw new \UnexpectedValueException('Invalid Signal base64');
            return $decoded;
        }
        if (is_array($value)) return array_map(self::unpack(...),$value);
        if (is_int($value) || is_bool($value) || $value === null) return $value;
        throw new \UnexpectedValueException('Invalid Signal state value');
    }
}
