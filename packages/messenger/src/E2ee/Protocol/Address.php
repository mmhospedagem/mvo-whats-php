<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee\Protocol;

/** Messenger account/device IDs stay native; no phone, LID, interop or WhatsApp aliases. */
final class Address
{
    /** @return array{user:string,device:int} */
    public static function parse(string $jid):array
    {
        if(!preg_match('/^([1-9][0-9]{0,24})(?::(0|[1-9][0-9]{0,4}))?@msgr$/D',$jid,$match))throw new \InvalidArgumentException('Use a native Messenger user/device address');
        $device=isset($match[2])?(int)$match[2]:0;
        if($device>65535)throw new \InvalidArgumentException('Messenger device ID exceeds uint16');
        return ['user'=>$match[1],'device'=>$device];
    }
    public static function device(string $jid):string{$p=self::parse($jid);return $p['user'].':'.$p['device'].'@msgr';}
    public static function bare(string $jid):string{return self::parse($jid)['user'].'@msgr';}
}
