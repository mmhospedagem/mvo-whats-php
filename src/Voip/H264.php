<?php
declare(strict_types=1);
namespace MVO\Voip;

/** Annex-B and RFC 6184 payloads, including WhatsApp's SPS-typed keyframe FU-A. */
final class H264
{
    public const START_CODE="\0\0\0\1";
    public const CONSTRAINED_BASELINE_CODEC='avc1.42E01F';
    /** @return array{payloads:list<string>,keyFrame:bool} */
    public static function packetize(string $data,int $maxPayload=1100):array
    {
        if($maxPayload<3 || $maxPayload>65507) throw new \InvalidArgumentException('H264 payload size must be 3..65507');
        $nals=self::nals($data); $key=false; $firstSps=-1; $keySps=-1;
        foreach($nals as $i=>$nal) {
            $type=ord($nal['data'][0])&31;
            if(!$key) { if($type===5) { $key=true; $keySps=$firstSps; } elseif($type===7 && $firstSps<0) $firstSps=$i; }
        }
        if($keySps>=0) {
            $aggregate=''; $header=24; $size=1;
            foreach($nals as $nal) { $length=strlen($nal['data']); $size+=2+$length; if($length>65535 || $size>$maxPayload) { $aggregate=''; break; } $header|=ord($nal['data'][0])&0x80; $header=($header&0x9f)|max($header&0x60,ord($nal['data'][0])&0x60); $aggregate.=pack('n',$length).$nal['data']; }
            if($aggregate!=='') return ['payloads'=>[chr($header).$aggregate],'keyFrame'=>$key];
        }
        $payloads=[]; $separate=$keySps<0?count($nals):$keySps;
        for($i=0;$i<$separate;$i++) {
            if(strlen($nals[$i]['data'])<=$maxPayload) $payloads[]=$nals[$i]['data'];
            else $payloads=[...$payloads,...self::fragment($nals[$i]['data'],$maxPayload)];
        }
        if($keySps>=0) $payloads=[...$payloads,...self::fragment(substr($data,$nals[$keySps]['offset']),$maxPayload)];
        return ['payloads'=>$payloads,'keyFrame'=>$key];
    }
    public static function isKeyFrame(string $data):bool
    {
        $at=self::nextStartCode($data,0);
        if($at<0) return $data!=='' && (ord($data[0])&31)===5;
        while($at>=0) { $header=$at+(ord($data[$at+2])===1?3:4); if($header<strlen($data) && (ord($data[$header])&31)===5) return true; $at=self::nextStartCode($data,$header); }
        return false;
    }
    /** Codec/profile bytes of the first complete Annex-B SPS, for a host decoder. */
    public static function readCodec(string $data):?string
    {
        for($at=self::nextStartCode($data,0);$at>=0;) {
            $header=$at+(ord($data[$at+2])===1?3:4);
            if($header+3<strlen($data) && (ord($data[$header])&31)===7) return 'avc1.'.strtoupper(bin2hex(substr($data,$header+1,3)));
            $at=self::nextStartCode($data,$header);
        }
        return null;
    }
    /** @return list<array{data:string,offset:int}> */
    public static function nals(string $data):array
    {
        $at=self::nextStartCode($data,0); $nals=[];
        if($at<0) return $data===''?[]:[['data'=>$data,'offset'=>0]];
        while($at>=0) { $from=$at+(ord($data[$at+2])===1?3:4); $at=self::nextStartCode($data,$from); $to=$at<0?strlen($data):$at; if($to>$from) $nals[]=['data'=>substr($data,$from,$to-$from),'offset'=>$from]; }
        return $nals;
    }
    private static function nextStartCode(string $data,int $from):int
    {
        $at=strpos($data,"\0\0\1",$from); if($at===false) return -1;
        $start=$at>$from && $data[$at-1]==="\0"?$at-1:$at;
        return $start+3<strlen($data)?$start:-1;
    }
    /** @return list<string> */
    private static function fragment(string $nal,int $max):array
    {
        $payloads=[]; $indicator=(ord($nal[0])&0xe0)|28; $type=ord($nal[0])&31; $length=strlen($nal); $chunk=$max-2;
        for($offset=1;$offset<$length;$offset+=$chunk) { $end=min($length,$offset+$chunk); $payloads[]=chr($indicator).chr($type|($offset===1?128:0)|($end===$length?64:0)).substr($nal,$offset,$end-$offset); }
        return $payloads;
    }
}
