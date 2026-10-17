<?php
declare(strict_types=1);
namespace MVO\Voip;

/** RTCP wire formats. Call only with authenticated plaintext from SRTCP. */
final class Rtcp
{
    /** @param list<array<string,int>> $reports */
    public static function receiverReport(int $senderSsrc,array $reports=[]):string
    {
        if(count($reports)>31) throw new \InvalidArgumentException('RTCP supports at most 31 report blocks');
        $body=self::word($senderSsrc);
        foreach($reports as $report) $body.=self::reportBlock($report);
        return self::packet(201,count($reports),$body);
    }

    /** WhatsApp's measured 32-byte report blocks, optional four-word trailer, and CNAME.
     * @param list<array<string,int>> $reports
     */
    public static function senderReportWithSdes(int $ssrc,int $packetCount,int $octetCount,int $timestamp,?string $cnameSeed=null,array $reports=[],?int $blockCounter=null,bool $withTrailer=true,bool $videoProfile=false,?float $unixMs=null):string
    {
        $max=$videoProfile?15:31;
        if(count($reports)>$max) throw new \InvalidArgumentException('Too many WhatsApp report blocks');
        if($reports===[]) $reports=[['ssrc'=>0]];
        $seed=$cnameSeed??random_bytes(18);
        if(strlen($seed)!==18) throw new \InvalidArgumentException('RTCP CNAME seed requires 18 bytes');
        $now=$unixMs??microtime(true)*1000;
        if(!is_finite($now) || $now<0) throw new \InvalidArgumentException('Invalid RTCP wall clock');
        $seconds=(int)floor($now/1000); $fraction=(int)floor(($now-$seconds*1000)/1000*4294967296);
        $body=self::word($ssrc).pack('NN',($seconds+2208988800)&0xffffffff,$fraction&0xffffffff).self::word($timestamp).self::word($packetCount).self::word($octetCount);
        $counter=$blockCounter??(($packetCount*161)&0xffffffff);
        foreach($reports as $report) $body.=self::reportBlock($report).self::word($counter).pack('N',0x24800);
        if($withTrailer) $body.=pack('NNNN',0,0,0,0x5000);
        $hex=substr(bin2hex(substr($seed,6)),0,11); $cname=substr($hex,0,5).'@pj'.substr($hex,5,6).'.org';
        $sdes=self::packet(202,1,self::word($ssrc)."\x01\x12".$cname.str_repeat("\0",4));
        return self::packet(200,count($reports)|($videoProfile?16:0),$body).$sdes;
    }

    public static function pli(int $senderSsrc,int $mediaSsrc,bool $videoProfile=false):string
    {
        return self::packet(206,$videoProfile?17:1,self::word($senderSsrc).self::word($mediaSsrc));
    }

    public static function fir(int $senderSsrc,int $mediaSsrc,int $sequence):string
    {
        if($sequence<0 || $sequence>255) throw new \InvalidArgumentException('FIR sequence must be uint8');
        return self::packet(206,4,self::word($senderSsrc).pack('N',0).self::word($mediaSsrc).pack('Cxxx',$sequence));
    }

    /** RFC 4585 Generic NACK; PID and BLP sequence arithmetic wraps at 65536.
     * @param list<int> $sequences
     */
    public static function nack(int $senderSsrc,int $mediaSsrc,array $sequences):string
    {
        if($sequences===[] || count($sequences)>65536) throw new \InvalidArgumentException('NACK requires 1..65536 sequence numbers');
        $missing=[];
        foreach($sequences as $sequence) { if($sequence<0 || $sequence>65535) throw new \InvalidArgumentException('NACK sequence must be uint16'); $missing[$sequence]=true; }
        $fci='';
        while($missing!==[]) {
            $pid=array_key_first($missing); unset($missing[$pid]); $mask=0;
            for($bit=0;$bit<16;$bit++) { $sequence=($pid+$bit+1)&65535; if(isset($missing[$sequence])) { $mask|=1<<$bit; unset($missing[$sequence]); } }
            $fci.=pack('nn',$pid,$mask);
        }
        return self::packet(205,1,self::word($senderSsrc).self::word($mediaSsrc).$fci);
    }

    /** @param int|list<int> $mediaSsrcs */
    public static function remb(int $senderSsrc,int|array $mediaSsrcs,int $bitsPerSecond):string
    {
        $ssrcs=is_array($mediaSsrcs)?$mediaSsrcs:[$mediaSsrcs];
        if($ssrcs===[] || count($ssrcs)>255 || $bitsPerSecond<0) throw new \InvalidArgumentException('Invalid REMB sources or bitrate');
        $mantissa=$bitsPerSecond; $exponent=0;
        while($mantissa>0x3ffff && $exponent<63) { $mantissa=intdiv($mantissa,2); ++$exponent; }
        $body=self::word($senderSsrc).pack('N',0).'REMB'.pack('N',(count($ssrcs)<<24)|($exponent<<18)|min($mantissa,0x3ffff));
        foreach($ssrcs as $ssrc) $body.=self::word($ssrc);
        return self::packet(206,15,$body);
    }

    /** One-byte RTP extension element id 13; caller pads the extension area to four bytes. */
    public static function fastRembExtension(int $bitsPerSecond):string
    {
        $value=max(0,min(0xffffff,$bitsPerSecond));
        return "\xd3\x01".substr(pack('N',$value),1);
    }

    public static function nextVideoBitrate(int $previous,int $octets,float $elapsedMs,float $lossPercent):int
    {
        if($previous<=0) return 300000;
        $measured=$elapsedMs>0 && $octets>0?$octets*8000/$elapsedMs:0;
        $next=$lossPercent>=10?min($previous,$measured>0?$measured:$previous)*0.85:($lossPercent>=2?$previous:max($previous,$measured)*1.5);
        return (int)max(64000,min(2000000,floor($next)));
    }

    public static function nextAudioBitrate(int $previous,float $lossPercent):int
    {
        if($previous<=0) return 24000;
        return (int)max(16000,min(30000,floor($lossPercent>=10?$previous*0.85:($lossPercent<2?$previous*1.1:$previous))));
    }

    /** @return list<array<string,mixed>> */
    public static function parse(string $compound):array
    {
        if($compound==='') throw new \UnexpectedValueException('Empty RTCP compound packet');
        $result=[]; $offset=0; $total=strlen($compound);
        while($offset<$total) {
            if($total-$offset<4) throw new \UnexpectedValueException('Truncated RTCP header');
            $first=ord($compound[$offset]); $type=ord($compound[$offset+1]);
            if(($first&0xc0)!==0x80 || $type<192 || $type>223) throw new \UnexpectedValueException('Invalid RTCP header');
            $size=(self::u16($compound,$offset+2)+1)*4;
            if($size<4 || $offset+$size>$total) throw new \UnexpectedValueException('Truncated RTCP packet');
            $raw=substr($compound,$offset,$size); $payload=$raw;
            if(($first&32)!==0) {
                $padding=ord($raw[$size-1]);
                if($offset+$size!==$total || $padding===0 || $padding>$size-4) throw new \UnexpectedValueException('Invalid RTCP padding');
                $payload=substr($raw,0,-$padding);
            }
            $count=$first&31; $entry=['type'=>$type,'format'=>$count,'raw'=>$raw];
            if(in_array($type,[200,201,205,206],true)) {
                $minimum=$type===200?28:($type===201?8:12);
                if(strlen($payload)<$minimum) throw new \UnexpectedValueException('Truncated RTCP body');
                $entry['senderSsrc']=self::u32($payload,4);
            }
            if($type===200 || $type===201) {
                $start=$type===200?28:8; $stride=24; $profile=false; $tail=0; $sizeWithoutPadding=strlen($payload);
                if($type===200 && $sizeWithoutPadding!==$start+$count*24) {
                    if(in_array($sizeWithoutPadding-$start-$count*32,[0,16],true)) $stride=32;
                    elseif(($count&16)!==0 && in_array($sizeWithoutPadding-$start-($count&15)*32,[0,16],true)) { $stride=32; $count&=15; $profile=true; }
                    else throw new \UnexpectedValueException('Invalid RTCP sender report length');
                    $tail=$sizeWithoutPadding-$start-$count*$stride;
                }
                if($sizeWithoutPadding!==$start+$count*$stride+$tail) throw new \UnexpectedValueException('Invalid RTCP receiver report length');
                $entry['reports']=[]; $entry['whatsappVideoProfile']=$profile;
                if($type===200) $entry+=['ntpSeconds'=>self::u32($payload,8),'ntpFraction'=>self::u32($payload,12),'rtpTimestamp'=>self::u32($payload,16),'packetCount'=>self::u32($payload,20),'octetCount'=>self::u32($payload,24)];
                for($i=0;$i<$count;$i++) $entry['reports'][]=self::parseReportBlock($payload,$start+$i*$stride,$stride);
                if($tail!==0) $entry['trailer']=substr($payload,-$tail);
            } elseif($type===205 && $count===1) {
                if((strlen($payload)-12)%4!==0 || strlen($payload)===12) throw new \UnexpectedValueException('Invalid RTCP NACK length');
                $entry['kind']='nack'; $entry['mediaSsrc']=self::u32($payload,8); $missing=[];
                for($at=12;$at<strlen($payload);$at+=4) { $pid=self::u16($payload,$at); $mask=self::u16($payload,$at+2); $missing[$pid]=true; for($bit=0;$bit<16;$bit++) if(($mask&(1<<$bit))!==0) $missing[($pid+$bit+1)&65535]=true; }
                $entry['sequences']=array_keys($missing);
            } elseif($type===206) {
                $entry['mediaSsrc']=self::u32($payload,8);
                if(in_array($count,[1,17],true)) { if(strlen($payload)!==12) throw new \UnexpectedValueException('Invalid PLI length'); $entry['kind']='pli'; $entry['whatsappVideoProfile']=$count===17; }
                elseif($count===4) {
                    if(strlen($payload)<20 || (strlen($payload)-12)%8!==0) throw new \UnexpectedValueException('Invalid FIR length');
                    $entry['kind']='fir'; $entry['requests']=[];
                    for($at=12;$at<strlen($payload);$at+=8) $entry['requests'][]=['ssrc'=>self::u32($payload,$at),'sequence'=>ord($payload[$at+4])];
                } elseif($count===15 && substr($payload,12,4)==='REMB') {
                    if(strlen($payload)<20) throw new \UnexpectedValueException('Truncated REMB');
                    $sources=ord($payload[16]); $band=self::u32($payload,16)&0xffffff;
                    if($sources===0 || strlen($payload)!==20+$sources*4) throw new \UnexpectedValueException('Invalid REMB source list');
                    $entry['kind']='remb'; $entry['bitsPerSecond']=($band&0x3ffff)*2**($band>>18); $entry['ssrcs']=[];
                    for($at=20;$at<strlen($payload);$at+=4) $entry['ssrcs'][]=self::u32($payload,$at);
                }
            }
            $result[]=$entry; $offset+=$size;
        }
        return $result;
    }

    /** @param array<string,int> $report */
    private static function reportBlock(array $report):string
    {
        $lost=max(-0x800000,min(0x7fffff,$report['packetsLost']??0));
        $fraction=max(0,min(255,$report['fractionLost']??0));
        $highest=((($report['cycles']??0)&65535)<<16)|(($report['highestSequence']??0)&65535);
        return self::word($report['ssrc']??0).pack('NNNNN',($fraction<<24)|($lost&0xffffff),$highest,($report['jitter']??0)&0xffffffff,($report['lastSenderReport']??0)&0xffffffff,($report['delaySinceLastSenderReport']??0)&0xffffffff);
    }

    /** @return array<string,int> */
    private static function parseReportBlock(string $bytes,int $offset,int $stride):array
    {
        $loss=self::u32($bytes,$offset+4); $lost=$loss&0xffffff; if(($lost&0x800000)!==0) $lost-=0x1000000;
        $highest=self::u32($bytes,$offset+8);
        $result=['ssrc'=>self::u32($bytes,$offset),'fractionLost'=>$loss>>24,'packetsLost'=>$lost,'highestSequence'=>$highest&65535,'cycles'=>$highest>>16,'jitter'=>self::u32($bytes,$offset+12),'lastSenderReport'=>self::u32($bytes,$offset+16),'delaySinceLastSenderReport'=>self::u32($bytes,$offset+20)];
        if($stride===32) $result+=['blockCounter'=>self::u32($bytes,$offset+24),'converged'=>self::u32($bytes,$offset+28)];
        return $result;
    }

    private static function packet(int $type,int $count,string $body):string
    {
        $size=strlen($body)+4;
        if($size%4!==0 || $size>262144) throw new \LengthException('RTCP packet length exceeds wire format');
        return pack('CCn',0x80|$count,$type,intdiv($size,4)-1).$body;
    }
    private static function word(int $value):string { if($value<0 || $value>0xffffffff) throw new \InvalidArgumentException('RTCP word must be uint32'); return pack('N',$value); }
    private static function u16(string $bytes,int $at):int { return (ord($bytes[$at])<<8)|ord($bytes[$at+1]); }
    private static function u32(string $bytes,int $at):int { return (ord($bytes[$at])<<24)|(ord($bytes[$at+1])<<16)|(ord($bytes[$at+2])<<8)|ord($bytes[$at+3]); }
}
