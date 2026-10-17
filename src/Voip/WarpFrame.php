<?php
declare(strict_types=1);
namespace MVO\Voip;

final class WarpFrame
{
    public static function unwrap(string $bytes):?string {
        if(strlen($bytes)<4 || ord($bytes[0])!==9)return $bytes;
        $length=4+(ord($bytes[1])&127)*2;
        if($length>strlen($bytes))throw new \UnexpectedValueException('WARP header overruns datagram');
        $body=substr($bytes,$length);
        return strlen($body)>=12 && (ord($body[0])&0xc0)===0x80?$body:null;
    }
}
