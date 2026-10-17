<?php
declare(strict_types=1);
namespace MVO\Mobile;

use MVO\Auth\PairingCodeCrypto;
use MVO\Crypto\{Crypto,XEdDsa};
use MVO\Protocol\SchemaCodec;

/** Primary-side QR and code pairing. Ephemeral secrets never enter the durable companion inventory. */
final class CompanionCrypto
{
    /** @return array{ref:string,noisePublicKey:string,identityPublicKey:string,advSecretKey:string,platform:string} */
    public static function parseQr(string $qr):array
    {
        $parts=explode(',',$qr);if(count($parts)<5)throw new \InvalidArgumentException('Companion QR needs five comma-separated fields');
        $platform=array_pop($parts);$adv=array_pop($parts);$identity=array_pop($parts);$noise=array_pop($parts);$ref=implode(',',$parts);
        if($ref==='' || $platform==='' || strlen($qr)>16384)throw new \InvalidArgumentException('Invalid companion QR ref/platform');
        return ['ref'=>$ref,'noisePublicKey'=>self::base64($noise),'identityPublicKey'=>self::base64($identity),'advSecretKey'=>self::base64($adv),'platform'=>$platform];
    }
    /** @param array{private:string,public:string} $identity
     * @param list<int> $validIndexes */
    public static function signedKeyIndexList(array $identity,int $rawId,int $currentIndex,int $timestamp,array $validIndexes):string
    {
        self::pair($identity);self::uint32($rawId);self::uint32($currentIndex);if($timestamp<0)throw new \InvalidArgumentException('Invalid key-list timestamp');
        foreach($validIndexes as $index)self::uint32($index);
        $validIndexes=array_values(array_unique($validIndexes));sort($validIndexes);
        if(!in_array(0,$validIndexes,true) || !in_array($currentIndex,$validIndexes,true))throw new \InvalidArgumentException('Key-index list must contain the primary and current index');
        $details=SchemaCodec::encode('ADVKeyIndexList',['rawId'=>$rawId,'timestamp'=>$timestamp,'currentIndex'=>$currentIndex,'validIndexes'=>$validIndexes]);
        return SchemaCodec::encode('ADVSignedKeyIndexList',['details'=>$details,'accountSignature'=>XEdDsa::sign($identity['private'],"\x06\x02".$details)]);
    }
    /** @param array{private:string,public:string} $identity
     * @param list<int> $validIndexes
     * @return array{deviceIdentityBytes:string,keyIndexListBytes:string} */
    public static function signedCompanion(array $identity,string $companionPublic,string $advSecret,int $rawId,int $index,int $timestamp,array $validIndexes):array
    {
        self::pair($identity);Crypto::length($companionPublic,32);Crypto::length($advSecret,32);self::uint32($rawId);self::uint32($index);
        $details=SchemaCodec::encode('ADVDeviceIdentity',['rawId'=>$rawId,'timestamp'=>$timestamp,'keyIndex'=>$index]);
        $signed=SchemaCodec::encode('ADVSignedDeviceIdentity',['details'=>$details,'accountSignatureKey'=>$identity['public'],'accountSignature'=>XEdDsa::sign($identity['private'],"\x06\x00".$details.$companionPublic)]);
        return ['deviceIdentityBytes'=>SchemaCodec::encode('ADVSignedDeviceIdentityHMAC',['details'=>$signed,'hmac'=>Crypto::hmac($advSecret,$signed)]),
            'keyIndexListBytes'=>self::signedKeyIndexList($identity,$rawId,$index,$timestamp,$validIndexes)];
    }
    public static function pemKey(string $advSecret,string $noisePublic):string { Crypto::length($advSecret,32);Crypto::length($noisePublic,32);return Crypto::hkdf($advSecret,32,'Canonical Ent Companion Nonce Encrypt',$noisePublic); }
    /** @return array{primaryEphemeralKeyPair:array{private:string,public:string},wrappedPrimaryEphemeralPub:string,sharedEphemeral:string} */
    public static function preparePrimaryHello(string $code,string $wrapped):array
    {
        $code=PairingCodeCrypto::normalizeCode(strtoupper(preg_replace('/[^a-zA-Z0-9]/','',$code)));
        Crypto::length($wrapped,80);$key=hash_pbkdf2('sha256',$code,substr($wrapped,0,32),PairingCodeCrypto::PBKDF2_ITERATIONS,32,true);
        $companion=self::ctr($key,substr($wrapped,32,16),substr($wrapped,48));
        $primary=Crypto::x25519KeyPair();$salt=random_bytes(32);$counter=random_bytes(16);
        $primaryKey=hash_pbkdf2('sha256',$code,$salt,PairingCodeCrypto::PBKDF2_ITERATIONS,32,true);
        return ['primaryEphemeralKeyPair'=>$primary,'wrappedPrimaryEphemeralPub'=>$salt.$counter.self::ctr($primaryKey,$counter,$primary['public']),'sharedEphemeral'=>Crypto::x25519($primary['private'],$companion)];
    }
    /** @param array{private:string,public:string} $primaryIdentity */
    public static function completePrimary(string $sharedEphemeral,string $wrappedBundle,string $companionPublic,array $primaryIdentity):string
    {
        self::pair($primaryIdentity);Crypto::length($sharedEphemeral,32);Crypto::length($companionPublic,32);Crypto::length($wrappedBundle,156);
        $key=Crypto::hkdf($sharedEphemeral,32,'link_code_pairing_key_bundle_encryption_key',substr($wrappedBundle,0,32));
        $plain=Crypto::decryptGcm($key,substr($wrappedBundle,32,12),substr($wrappedBundle,44));Crypto::length($plain,96);
        if(!hash_equals(substr($plain,0,32),$companionPublic) || !hash_equals(substr($plain,32,32),$primaryIdentity['public']))throw new \UnexpectedValueException('Companion link-code bundle identity mismatch');
        $sharedIdentity=Crypto::x25519($primaryIdentity['private'],$companionPublic);
        return Crypto::hkdf($sharedEphemeral.$sharedIdentity.substr($plain,64),32,'adv_secret');
    }
    private static function base64(string $value):string
    {
        $value=strtr($value,'-_','+/');$decoded=base64_decode($value,true);
        if($decoded===false || strlen($decoded)!==32)throw new \InvalidArgumentException('Companion QR keys must decode to 32 bytes');return $decoded;
    }
    /** @param array{private:string,public:string} $pair */
    private static function pair(array $pair):void { Crypto::length($pair['private'],32);Crypto::length($pair['public'],32);if(!hash_equals(sodium_crypto_scalarmult_base($pair['private']),$pair['public']))throw new \InvalidArgumentException('Inconsistent primary identity keypair'); }
    private static function uint32(int $n):void { if($n<0 || $n>0xffffffff)throw new \InvalidArgumentException('Invalid mobile uint32'); }
    private static function ctr(string $key,string $iv,string $plain):string { $result=openssl_encrypt($plain,'aes-256-ctr',$key,OPENSSL_RAW_DATA,$iv);if($result===false)throw new \RuntimeException('Mobile link-code AES-CTR failed');return $result; }
}
