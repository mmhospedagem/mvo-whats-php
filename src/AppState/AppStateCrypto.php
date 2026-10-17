<?php
declare(strict_types=1);
namespace MVO\AppState;

use MVO\Crypto\Crypto;
use MVO\Protocol\SchemaCodec;

/** Authenticated WhatsApp app-state records and the 128-byte LT-hash accumulator. */
final class AppStateCrypto
{
    /**
     * @return array{index:string,encryption:string,value:string,snapshot:string,patch:string} */
    public static function deriveKeys(string $key): array
    {
        Crypto::length($key,32);
        $derived=Crypto::hkdf($key,160,'WhatsApp Mutation Keys');
        return ['index'=>substr($derived,0,32),'encryption'=>substr($derived,32,32),'value'=>substr($derived,64,32),'snapshot'=>substr($derived,96,32),'patch'=>substr($derived,128,32)];
    }

    /**
     * @param array<string,mixed>|null $value
     * @return array{indexMac:string,valueBlob:string,valueMac:string} */
    public static function encryptMutation(int $operation,string $keyId,string $keyData,string $index,?array $value,int $version,?string $iv=null): array
    {
        self::operation($operation,$keyId);
        if ($version<0) throw new \InvalidArgumentException('Negative app-state action version');
        $keys=self::deriveKeys($keyData);
        $iv??=random_bytes(16); Crypto::length($iv,16);
        $data=['index'=>$index,'padding'=>'','version'=>$version];
        if ($value!==null) $data['value']=$value;
        $cipher=openssl_encrypt(SchemaCodec::encode('SyncActionData',$data),'aes-256-cbc',$keys['encryption'],OPENSSL_RAW_DATA,$iv);
        if ($cipher===false) throw new \RuntimeException('App-state encryption failed');
        $mac=self::valueMac($keys['value'],$operation,$keyId,$iv.$cipher);
        return ['indexMac'=>Crypto::hmac($keys['index'],$index),'valueBlob'=>$iv.$cipher.$mac,'valueMac'=>$mac];
    }

    /**
     * @return array{index:string,value:?array<string,mixed>,version:int,indexMac:string,valueMac:string} */
    public static function decryptMutation(int $operation,string $keyId,string $keyData,string $indexMac,string $blob): array
    {
        self::operation($operation,$keyId); Crypto::length($indexMac,32);
        if (strlen($blob)<64 || (strlen($blob)-48)%16!==0) throw new \UnexpectedValueException('Invalid app-state value blob');
        $keys=self::deriveKeys($keyData); $mac=substr($blob,-32); $encrypted=substr($blob,0,-32);
        if (!hash_equals(self::valueMac($keys['value'],$operation,$keyId,$encrypted),$mac)) throw new \UnexpectedValueException('App-state value MAC mismatch');
        $plain=openssl_decrypt(substr($encrypted,16),'aes-256-cbc',$keys['encryption'],OPENSSL_RAW_DATA,substr($encrypted,0,16));
        if ($plain===false) throw new \UnexpectedValueException('App-state AES padding invalid');
        $data=SchemaCodec::decode('SyncActionData',$plain);
        if (!is_string($data['index']??null) || !is_int($data['version']??null)) throw new \UnexpectedValueException('Missing app-state index/version');
        if (!hash_equals(Crypto::hmac($keys['index'],$data['index']),$indexMac)) throw new \UnexpectedValueException('App-state index MAC mismatch');
        return ['index'=>$data['index'],'value'=>$data['value']??null,'version'=>$data['version'],'indexMac'=>$indexMac,'valueMac'=>$mac];
    }

    public static function snapshotMac(string $key,string $hash,int $version,string $collection): string
    {
        Crypto::length($hash,128);
        return Crypto::hmac(self::deriveKeys($key)['snapshot'],$hash.self::versionBytes($version).$collection);
    }

    /**
     * @param list<string> $valueMacs */
    public static function patchMac(string $key,string $snapshotMac,array $valueMacs,int $version,string $collection): string
    {
        Crypto::length($snapshotMac,32);
        foreach ($valueMacs as $mac) Crypto::length($mac,32);
        return Crypto::hmac(self::deriveKeys($key)['patch'],$snapshotMac.implode('',$valueMacs).self::versionBytes($version).$collection);
    }

    /** Each point is a little-endian unsigned 16-bit integer with modular arithmetic.
     * @param list<string> $add
     * @param list<string> $subtract */
    public static function ltHash(string $base,array $add=[],array $subtract=[]): string
    {
        Crypto::length($base,128);
        $points=array_values(unpack('v*',$base));
        foreach ([$subtract,$add] as $kind=>$values) foreach ($values as $value) {
            Crypto::length($value,32);
            $expanded=array_values(unpack('v*',Crypto::hkdf($value,128,'WhatsApp Patch Integrity')));
            foreach ($points as $i=>$point) $points[$i]=($point+($kind===0?-$expanded[$i]:$expanded[$i]))&0xffff;
        }
        return pack('v*',...$points);
    }

    private static function operation(int $operation,string $keyId): void
    {
        if (!in_array($operation,[0,1],true)) throw new \InvalidArgumentException('Invalid app-state operation');
        if ($keyId==='' || strlen($keyId)>254) throw new \InvalidArgumentException('Invalid app-state key ID');
    }
    private static function valueMac(string $key,int $operation,string $keyId,string $encrypted): string
    {
        $associated=chr($operation+1).$keyId;
        return substr(hash_hmac('sha512',$associated.$encrypted.str_repeat("\0",7).chr(strlen($associated)),$key,true),0,32);
    }
    private static function versionBytes(int $version): string
    {
        if ($version<0) throw new \InvalidArgumentException('Negative app-state collection version');
        return pack('N2',intdiv($version,4294967296),$version&0xffffffff);
    }
}

