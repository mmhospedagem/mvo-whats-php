<?php
declare(strict_types=1);
namespace MVO\Voip;

final class RelaySdp
{
    public const FINGERPRINT='sha-256 F9:CA:0C:98:A3:CC:71:D6:42:CE:5A:E2:53:D2:15:20:D3:1B:BA:D8:57:A4:F0:AF:BE:0B:FB:F3:6B:0C:A0:68';
    /** @param array<string,mixed> $relay */
    public static function answer(string $offer,array $relay):string {
        $ip=$relay['ip']??null;$port=$relay['port']??null;$token=($relay['authToken']??'')!==''?$relay['authToken']:($relay['token']??null);$key=$relay['key']??null;
        if(!is_string($ip) || filter_var($ip,FILTER_VALIDATE_IP)===false || !is_int($port) || $port<1 || $port>65535)throw new \InvalidArgumentException('Invalid WebRTC relay endpoint');
        foreach([$token,$key] as $credential)if(!is_string($credential) || !preg_match('/^[!-~]{4,256}$/D',$credential))throw new \InvalidArgumentException('Invalid relay ICE credentials');
        if(strlen($key)<22)throw new \InvalidArgumentException('Relay ICE password is shorter than 22 bytes');
        if(strlen($offer)>65535 || !str_starts_with($offer,'v=0') || str_contains($offer,"\0"))throw new \InvalidArgumentException('Invalid relay SDP offer');
        $lines=preg_split('/\r?\n/',$offer);$out=[];$seen=[];
        foreach($lines as $line){
            if($line==='' || str_starts_with($line,'a=candidate:') || str_starts_with($line,'a=end-of-candidates') || str_starts_with($line,'a=ice-options:'))continue;
            foreach(['a=ice-ufrag:'=>$token,'a=ice-pwd:'=>$key,'a=fingerprint:'=>self::FINGERPRINT,'a=max-message-size:'=>'1500','a=setup:'=>'passive'] as $prefix=>$value){if(str_starts_with($line,$prefix)){$line=$prefix.$value;$seen[$prefix]=true;break;}}
            $out[]=$line;
        }
        foreach(['a=ice-ufrag:','a=ice-pwd:','a=fingerprint:','a=setup:'] as $field)if(!isset($seen[$field]))throw new \UnexpectedValueException('Relay SDP is missing a required authentication field');
        $out[]='a=candidate:2 1 udp 2122262783 '.$ip.' '.$port.' typ host generation 0 network-cost 5';$out[]='a=end-of-candidates';
        return implode("\r\n",$out)."\r\n";
    }
}
