<?php
declare(strict_types=1);
namespace MVO\Voip;

final class H264Depacketizer
{
    private ?int $timestamp=null;
    /** @var list<string> */
    private array $nals=[];
    private string $fu='';
    private int $fuType=-1;
    private int $fuSequence=-1;
    private int $bytes=0;
    public function __construct(private readonly int $maxBufferedBytes=8388608)
    {
        if($maxBufferedBytes<4 || $maxBufferedBytes>67108864) throw new \InvalidArgumentException('Invalid H264 reassembly bound');
    }
    /** @return list<array{timestamp:int,data:string,keyFrame:bool}> */
    public function push(string $payload,int $timestamp,bool $marker,int $sequence):array
    {
        if($timestamp<0 || $timestamp>0xffffffff || $sequence<0 || $sequence>65535) throw new \InvalidArgumentException('Invalid H264 RTP header');
        if($payload==='') return [];
        $frames=[];
        if($this->timestamp!==null && $timestamp!==$this->timestamp) { $previous=$this->flush(); if($previous!==null) $frames[]=$previous; $this->reset(); }
        $this->timestamp=$timestamp;
        if($this->bytes+strlen($payload)+4>$this->maxBufferedBytes) { $this->reset(); return $frames; }
        $type=ord($payload[0])&31;
        if($type>=1 && $type<=23) $this->appendNal($payload);
        elseif($type===24) $this->appendStap($payload);
        elseif($type===28) $this->appendFu($payload,$sequence);
        else return $frames;
        if($marker && $this->fu==='') { $current=$this->flush(); if($current!==null) $frames[]=$current; }
        return $frames;
    }
    public function reset():void { $this->timestamp=null; $this->nals=[]; $this->fu=''; $this->fuType=-1; $this->fuSequence=-1; $this->bytes=0; }
    private function appendNal(string $nal):void
    {
        if($this->bytes+strlen($nal)+4>$this->maxBufferedBytes) { $this->reset(); return; }
        $this->nals[]=$nal; $this->bytes+=strlen($nal)+4;
    }
    private function appendStap(string $payload):void
    {
        $parsed=[]; $offset=1; $length=strlen($payload);
        while($offset+2<=$length) {
            $size=(ord($payload[$offset])<<8)|ord($payload[$offset+1]); $offset+=2;
            if($size===0 || $offset+$size>$length) return;
            $nal=substr($payload,$offset,$size); $type=ord($nal[0])&31;
            if($type<1 || $type>23) return;
            $parsed[]=$nal; $offset+=$size;
        }
        if($offset!==$length) return;
        $additional=0; foreach($parsed as $nal) $additional+=strlen($nal)+4;
        if($this->bytes+$additional>$this->maxBufferedBytes) { $this->reset(); return; }
        foreach($parsed as $nal) $this->appendNal($nal);
    }
    private function appendFu(string $payload,int $sequence):void
    {
        if(strlen($payload)<3) return;
        $header=ord($payload[1]); $type=$header&31;
        if(($header&32)!==0 || $type<1 || $type>23) return;
        if(($header&128)!==0) {
            $this->bytes-=strlen($this->fu); $this->fu=chr((ord($payload[0])&0xe0)|$type).substr($payload,2); $this->bytes+=strlen($this->fu); $this->fuType=$type; $this->fuSequence=$sequence;
        } elseif($this->fuType!==$type) return;
        elseif($sequence===(($this->fuSequence+1)&65535)) { $fragment=substr($payload,2); $this->fu.=$fragment; $this->bytes+=strlen($fragment); $this->fuSequence=$sequence; }
        else { $this->bytes-=strlen($this->fu); $this->fu=''; $this->fuType=-1; $this->fuSequence=-1; return; }
        if(($header&64)!==0) { $nal=$this->fu; $this->bytes-=strlen($nal); $this->fu=''; $this->fuType=-1; $this->fuSequence=-1; $this->appendNal($nal); }
    }
    /** @return array{timestamp:int,data:string,keyFrame:bool}|null */
    private function flush():?array
    {
        if($this->nals===[] || $this->timestamp===null) return null;
        $data=H264::START_CODE.implode(H264::START_CODE,$this->nals);
        $result=['timestamp'=>$this->timestamp,'data'=>$data,'keyFrame'=>H264::isKeyFrame($data)];
        $this->reset(); return $result;
    }
}
