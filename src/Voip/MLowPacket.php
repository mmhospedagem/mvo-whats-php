<?php
declare(strict_types=1);
namespace MVO\Voip;

/** SMPL multiframe and WhatsApp's compact in-band RED, on a 16 kHz timeline. */
final class MLowPacket
{
    public const MAX_PACKET_BYTES=4000;
    public const MAX_SUBFRAMES=18;
    public const MAX_RED_BLOCKS=8;
    public const TOC_VAD=0x40;
    /** @return list<array{packet:string,samples:int}> Empty means malformed or not MLow. */
    public static function frames(string $packet):array
    {
        if($packet==='' || strlen($packet)>self::MAX_PACKET_BYTES) return [];
        $toc=ord($packet[0]); if($toc>=0xc0) return [];
        if(($toc&0x82)!==0x82) return [['packet'=>$packet,'samples'=>self::span($toc)]];
        $length=strlen($packet); if($length<3) return [];
        $count=ord($packet[1])&63; if($count<2 || $count>self::MAX_SUBFRAMES) return [];
        $offset=2; $sizes=[]; $total=0;
        for($i=0;$i<$count-1;$i++) {
            if($offset>=$length) return [];
            $size=ord($packet[$offset++]);
            if($size>=252) { if($offset>=$length) return []; $size+=ord($packet[$offset++])*4; }
            if($size===0) return [];
            $sizes[]=$size; $total+=$size;
        }
        if($offset+$total>=$length) return [];
        $sizes[]=$length-$offset-$total; $frames=[];
        foreach($sizes as $size) { $frame=substr($packet,$offset,$size); $frameToc=ord($frame[0]); if($frameToc>=0xc0 || ($frameToc&0x82)===0x82) return []; $frames[]=['packet'=>$frame,'samples'=>self::span($frameToc)]; $offset+=$size; }
        return $frames;
    }
    public static function samples(string $packet):int { $samples=0; foreach(self::frames($packet) as $frame) $samples+=$frame['samples']; return $samples; }
    /** @return array{primary:string,copies:list<array{packet:string,delta:int}>}|null */
    public static function readRed(string $packet):?array
    {
        $offset=0; $headers=[]; $total=0; $length=strlen($packet); if($length>self::MAX_PACKET_BYTES) return null;
        while($offset<$length && (ord($packet[$offset])&128)!==0 && (ord($packet[$offset])&127)!==0) {
            if($offset+1>=$length || count($headers)>=8) return null;
            $size=ord($packet[$offset+1]); if($size===0) return null;
            $headers[]=['size'=>$size,'delta'=>(ord($packet[$offset])&127)*160]; $total+=$size; $offset+=2;
        }
        if($offset>=$length || ord($packet[$offset])!==0 || ++$offset+$total>=$length) return null;
        $copies=[]; foreach($headers as $header) { $copy=substr($packet,$offset,$header['size']); if(self::samples($copy)===0) return null; $copies[]=['packet'=>$copy,'delta'=>$header['delta']]; $offset+=$header['size']; }
        $primary=substr($packet,$offset); if(self::samples($primary)===0) return null;
        return ['primary'=>$primary,'copies'=>$copies];
    }
    /** @param list<array{packet:string,timestamp:int}> $copies */
    public static function writeRed(string $primary,int $timestamp,array $copies=[]):string
    {
        if(self::samples($primary)===0 || !array_is_list($copies) || count($copies)>8 || $timestamp<0 || $timestamp>0xffffffff) throw new \InvalidArgumentException('Invalid MLow RED primary or copies');
        $headers=''; $body='';
        foreach($copies as $copy) {
            if(!is_string($copy['packet']??null) || !is_int($copy['timestamp']??null) || $copy['timestamp']<0 || $copy['timestamp']>0xffffffff) throw new \InvalidArgumentException('Invalid MLow RED copy');
            $delta=($timestamp-$copy['timestamp'])&0xffffffff; $length=strlen($copy['packet']);
            if($length===0 || $length>255 || $delta<160 || $delta>20320 || $delta%160!==0) continue;
            if(self::samples($copy['packet'])===0) throw new \InvalidArgumentException('RED copy is not MLow');
            $headers.=chr(128|intdiv($delta,160)).chr($length); $body.=$copy['packet'];
        }
        $packet=$headers."\0".$body.$primary; if(strlen($packet)>self::MAX_PACKET_BYTES) throw new \LengthException('MLow RED packet exceeds 4000 bytes'); return $packet;
    }
    /** @param list<string> $frames */
    public static function pack(array $frames):string
    {
        if(!array_is_list($frames) || count($frames)<2 || count($frames)>18) throw new \InvalidArgumentException('MLow multiframe requires 2..18 frames');
        $headers=chr(0x82|(ord($frames[0][0]??"\xff")&0x39)).chr(count($frames)); $body='';
        foreach($frames as $i=>$frame) {
            $length=strlen($frame);
            if($length===0 || $length>1275 || count(self::frames($frame))!==1) throw new \InvalidArgumentException('Invalid MLow subframe');
            if($i<count($frames)-1) $headers.=$length<252?chr($length):chr(252+($length&3)).chr(intdiv($length-252,4));
            $body.=$frame;
        }
        $packet=$headers.$body; if(strlen($packet)>self::MAX_PACKET_BYTES) throw new \LengthException('MLow multiframe exceeds 4000 bytes'); return $packet;
    }
    private static function span(int $toc):int { return [160,320,960,1920][($toc>>3)&3]; }
}
