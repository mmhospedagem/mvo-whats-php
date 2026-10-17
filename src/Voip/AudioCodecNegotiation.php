<?php
declare(strict_types=1);
namespace MVO\Voip;

use MVO\Protocol\BinaryNode;

/** Select only locally available codecs; negotiated RTP clock and native sample rate stay explicit. */
final class AudioCodecNegotiation
{
    /** @param array<string,list<int>> $supported Codec wire names and supported sample rates.
     * @return array{enc:string,rate:int,channels:int,payloadType:int,implementation:string}
     */
    public static function select(BinaryNode $offer,array $supported=['opus'=>[16000,8000,48000]],bool $mlowForOpus=false):array
    {
        $offered=[];
        foreach(is_array($offer->content)?$offer->content:[] as $node) {
            if($node->tag!=='audio') continue;
            $encoding=strtolower($node->attrs['enc']??''); $rate=filter_var($node->attrs['rate']??'',FILTER_VALIDATE_INT); $channels=filter_var($node->attrs['channels']??'1',FILTER_VALIDATE_INT);
            if(!is_int($rate) || $channels!==1 || !isset($supported[$encoding]) || !in_array($rate,$supported[$encoding],true)) continue;
            $offered[$encoding][$rate]=true;
        }
        foreach($supported as $encoding=>$rates) foreach($rates as $rate) if(isset($offered[$encoding][$rate])) {
            $implementation=$encoding==='opus' && $mlowForOpus?'mlow':$encoding;
            if($implementation==='mlow' && $rate!==16000) continue;
            $pt=match($encoding) { 'opus'=>120,'mlow','mlow-1'=>122,'mlow-red-1'=>121,default=>throw new \InvalidArgumentException('Unsupported codec wire name') };
            return ['enc'=>$encoding,'rate'=>$rate,'channels'=>1,'payloadType'=>$pt,'implementation'=>$implementation];
        }
        throw new \UnexpectedValueException('No compatible audio codec in call offer');
    }
}
