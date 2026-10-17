<?php
declare(strict_types=1);
namespace MVO\Voip;
use MVO\Crypto\Crypto;

final class CallCrypto
{
    /**
     * @return array{masterKey:string,masterSalt:string} */
    public static function perDevice(string $callKey,string $deviceJid):array { Crypto::length($callKey,32);if($deviceJid==='')throw new \InvalidArgumentException('Missing call device JID');$out=Crypto::hkdf($callKey,46,$deviceJid);return ['masterKey'=>substr($out,0,16),'masterSalt'=>substr($out,16,14)]; }
    public static function ssrc(string $callId,string $deviceJid,int $slot=0):int { if($callId==='' || $deviceJid==='' || $slot<0 || $slot>6)throw new \InvalidArgumentException('Invalid call SSRC context');return unpack('V',Crypto::hkdf($callId,4,$deviceJid,pack('V',$slot)))[1]; }
    public static function sessionKey(string $masterKey,string $masterSalt,int $label,int $length):string { Crypto::length($masterKey,16);Crypto::length($masterSalt,14);if($label<0 || $label>255 || $length<1 || $length>32)throw new \InvalidArgumentException('Invalid SRTP KDF');$iv=$masterSalt."\0\0";$iv[7]=chr(ord($iv[7])^$label);return self::ctr($masterKey,$iv,str_repeat("\0",$length)); }
    public static function ctr(string $key,string $iv,string $bytes):string { $result=openssl_encrypt($bytes,'aes-128-ctr',$key,OPENSSL_RAW_DATA,$iv);if($result===false)throw new \RuntimeException('SRTP AES-CTR failed');return $result; }
}

