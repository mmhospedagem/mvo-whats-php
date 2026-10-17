<?php
declare(strict_types=1);
namespace MVO\Voip;

final class Rtp
{
    public const EXTENSION_PROFILE=0xdebe;
    /** Frame information, transport sequence and optional FastREMB, padded to RTP words. */
    public static function videoExtension(bool $keyFrame,bool $firstPacket,int $frameNumber,int $transportSequence,int $receiverEstimate=0):string
    {
        if($frameNumber<0 || $frameNumber>65535 || $transportSequence<0 || $transportSequence>65535) throw new \InvalidArgumentException('Video extension counters must be uint16');
        $extension=chr($firstPacket?0x32:0x30).chr($keyFrame?0x08:0x20);
        if($firstPacket) $extension.=pack('n',$frameNumber);
        $extension.="\x51\0\0\x61\0\0\x91".pack('n',$transportSequence);
        if($receiverEstimate>0) $extension.=Rtcp::fastRembExtension($receiverEstimate);
        return $extension.str_repeat("\0",(4-strlen($extension)%4)%4);
    }
    /**
     * @param list<int> $csrc */
    public static function encode(string $payload,int $ssrc,int $sequence,int $timestamp,int $payloadType=111,bool $marker=false,array $csrc=[],?string $extension=null,int $extensionProfile=self::EXTENSION_PROFILE):string
    {
        if($ssrc<0 || $ssrc>0xffffffff || $sequence<0 || $sequence>65535 || $timestamp<0 || $timestamp>0xffffffff || $payloadType<0 || $payloadType>127 || count($csrc)>15)throw new \InvalidArgumentException('Invalid RTP header');
        $first=0x80|count($csrc)|($extension===null?0:0x10);$header=pack('CCnNN',$first,$payloadType|($marker?0x80:0),$sequence,$timestamp,$ssrc);
        foreach($csrc as $source){if($source<0 || $source>0xffffffff)throw new \InvalidArgumentException('Invalid CSRC');$header.=pack('N',$source);}
        if($extension!==null){if(strlen($extension)%4!==0 || strlen($extension)>262140 || $extensionProfile<0 || $extensionProfile>65535)throw new \InvalidArgumentException('RTP extension must be aligned with a uint16 profile');$header.=pack('nn',$extensionProfile,intdiv(strlen($extension),4)).$extension;}
        return $header.$payload;
    }
    /**
     * @return array{header:string,payload:string,ssrc:int,sequence:int,timestamp:int,payloadType:int,marker:bool,padding:bool,csrc:list<int>,extensionProfile:?int,extensionData:?string} */
    public static function decode(string $packet,bool $stripPadding=true):array
    {
        if(strlen($packet)<12 || (ord($packet[0])&0xc0)!==0x80)throw new \UnexpectedValueException('Invalid/truncated RTP packet');
        $length=12+(ord($packet[0])&15)*4;if(strlen($packet)<$length)throw new \UnexpectedValueException('Truncated RTP CSRCs');$profile=null;$extension=null;$csrc=[];
        for($offset=12;$offset<$length;$offset+=4) $csrc[]=unpack('N',substr($packet,$offset,4))[1];
        if(ord($packet[0])&16){if(strlen($packet)<$length+4)throw new \UnexpectedValueException('Truncated RTP extension');$data=unpack('nprofile/nwords',substr($packet,$length,4));$profile=$data['profile'];$size=$data['words']*4;$extension=substr($packet,$length+4,$size);if(strlen($extension)!==$size)throw new \UnexpectedValueException('Truncated RTP extension data');$length+=4+$size;}
        $header=substr($packet,0,$length);$payload=substr($packet,$length);$padding=(ord($packet[0])&32)!==0;
        if($padding && $stripPadding){$pad=$payload===''?0:ord($payload[strlen($payload)-1]);if($pad<1 || $pad>strlen($payload))throw new \UnexpectedValueException('Invalid RTP padding');$payload=substr($payload,0,-$pad);}
        $values=unpack('nsequence/Ntimestamp/Nssrc',substr($packet,2,10));
        return ['header'=>$header,'payload'=>$payload,...$values,'payloadType'=>ord($packet[1])&127,'marker'=>(ord($packet[1])&128)!==0,'padding'=>$padding,'csrc'=>$csrc,'extensionProfile'=>$profile,'extensionData'=>$extension];
    }
}

