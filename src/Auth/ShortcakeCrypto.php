<?php
declare(strict_types=1);
namespace MVO\Auth;

use MVO\Crypto\Crypto;
use MVO\Protocol\SchemaCodec;

/** Native commit/reveal, X25519 and AES-GCM for WhatsApp passkey companion linking. */
final class ShortcakeCrypto
{
    /** Ephemeral private keys and nonce must stay in memory only.
     * @param array{private:string,public:string}|null $keyPair
     * @return array{keyPair:array{private:string,public:string},companionNonce:string,companionEphemeralIdentityBytes:string,commitmentHash:string,prologuePayloadBytes:string} */
    public static function companion(string $ref,int $deviceType=1,?array $keyPair=null,?string $nonce=null): array
    {
        if($ref==='' || strlen($ref)>4096 || $deviceType<0)throw new \InvalidArgumentException('Invalid Shortcake ref/device type');
        $keyPair??=Crypto::x25519KeyPair();$nonce??=random_bytes(32);
        Crypto::length($keyPair['private'],32);Crypto::length($keyPair['public'],32);Crypto::length($nonce,32);
        if(!hash_equals(sodium_crypto_scalarmult_base($keyPair['private']),$keyPair['public']))throw new \InvalidArgumentException('Inconsistent Shortcake ephemeral key pair');
        $identity=SchemaCodec::encode('CompanionEphemeralIdentity',['publicKey'=>$keyPair['public'],'deviceType'=>$deviceType,'ref'=>$ref]);
        $commitment=Crypto::sha256($identity.$nonce);
        return ['keyPair'=>$keyPair,'companionNonce'=>$nonce,'companionEphemeralIdentityBytes'=>$identity,'commitmentHash'=>$commitment,
            'prologuePayloadBytes'=>SchemaCodec::encode('ProloguePayload',['companionEphemeralIdentity'=>$identity,'commitment'=>['hash'=>$commitment]])];
    }
    /** @return array{publicKey:string,nonce:string} */
    public static function decodePrimary(string $bytes): array
    {
        $decoded=SchemaCodec::decode('PrimaryEphemeralIdentity',$bytes);
        foreach(['publicKey','nonce'] as $field)if(!is_string($decoded[$field]??null) || strlen($decoded[$field])!==32)throw new \UnexpectedValueException('Shortcake primary '.$field.' must be 32 bytes');
        return ['publicKey'=>$decoded['publicKey'],'nonce'=>$decoded['nonce']];
    }
    /** @param array{publicKey:string,nonce:string} $primary */
    public static function verificationCode(string $companionNonce,array $primary): string
    {
        Crypto::length($companionNonce,32);Crypto::length($primary['publicKey'],32);Crypto::length($primary['nonce'],32);
        $bytes=substr($primary['nonce'],0,5)^substr(Crypto::sha256($companionNonce.$primary['publicKey']),0,5);
        return PairingCodeCrypto::encodeCrockford($bytes);
    }
    public static function encryptionKey(string $companionPrivate,string $primaryPublic,int $deviceType,string $ref): string
    {
        if($ref==='' || $deviceType<0)throw new \InvalidArgumentException('Invalid Shortcake key context');
        return Crypto::hkdf(Crypto::x25519($companionPrivate,$primaryPublic),32,'Pairing Information Encryption Key','Companion Pairing '.$deviceType.' with ref '.$ref);
    }
    public static function encryptPairingRequest(string $key,string $plain,?string $iv=null): string
    {
        $iv??=random_bytes(12);Crypto::length($key,32);Crypto::length($iv,12);
        return SchemaCodec::encode('EncryptedPairingRequest',['encryptedPayload'=>Crypto::encryptGcm($key,$iv,$plain),'iv'=>$iv]);
    }
    public static function handoffKey(string $advSecret): string { Crypto::length($advSecret,32);return Crypto::hkdf($advSecret,32,'shortcake-passkey-handoff-v1'); }
}
