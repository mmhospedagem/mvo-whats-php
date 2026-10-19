<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee\Noise;
use MVO\Messenger\E2ee\{Crypto,ProtoWire,XEdDsa};

/** Explicit Messenger trust profile from the pinned reference: signatures AND validity windows. */
final class NoiseCertificate
{
    public const ROOT_PUBLIC_HEX='142375574d0a587166aae71ebe516437c4a28b73e3695c6ce1f7f9545da8ee6b';
    public static function verify(string $chain,string $serverStatic,?int $now=null,?string $rootPublic=null):void
    {
        if(strlen($chain)>65536)throw new \UnexpectedValueException('Noise certificate chain exceeds limit');
        $rootPublic??=hex2bin(self::ROOT_PUBLIC_HEX);Crypto::length($rootPublic,32);Crypto::length($serverStatic,32);$now??=time();
        $fields=self::fields($chain);$intermediate=self::certificate(self::bytes($fields,2),$now);$leaf=self::certificate(self::bytes($fields,1),$now);
        if($intermediate['issuer']!==0||$leaf['issuer']!==$intermediate['serial'])throw new \UnexpectedValueException('Messenger Noise certificate issuer mismatch');
        if(!XEdDsa::verify($rootPublic,$intermediate['details'],$intermediate['signature'])||!XEdDsa::verify($intermediate['key'],$leaf['details'],$leaf['signature']))throw new \UnexpectedValueException('Messenger Noise certificate signature failed');
        if(!hash_equals($leaf['key'],$serverStatic))throw new \UnexpectedValueException('Messenger Noise static key differs from its certificate');
    }
    /** @return array<int,int|string> */
    private static function fields(string $raw):array{$out=[];foreach(ProtoWire::decode($raw)as$id=>$values){if(count($values)!==1)throw new \UnexpectedValueException('Repeated Messenger certificate field');$out[$id]=$values[0];}return $out;}
    /** @param array<int,int|string> $fields */
    private static function bytes(array $fields,int $id):string{$v=$fields[$id]??null;if(!is_string($v)||$v==='')throw new \UnexpectedValueException('Missing Messenger certificate bytes');return $v;}
    /** @return array{serial:int,issuer:int,key:string,details:string,signature:string} */
    private static function certificate(string $raw,int $now):array
    {
        $cert=self::fields($raw);$details=self::bytes($cert,1);$signature=self::bytes($cert,2);Crypto::length($signature,64);$data=self::fields($details);
        $serial=$data[1]??0;$issuer=$data[2]??0;$before=$data[4]??0;$after=$data[5]??0;
        if(!is_int($serial)||!is_int($issuer)||!is_int($before)||!is_int($after)||$after<=$before||$now<$before||$now>$after)throw new \UnexpectedValueException('Messenger Noise certificate is outside its validity window');
        $key=self::bytes($data,3);Crypto::length($key,32);return ['serial'=>$serial,'issuer'=>$issuer,'key'=>$key,'details'=>$details,'signature'=>$signature];
    }
}
