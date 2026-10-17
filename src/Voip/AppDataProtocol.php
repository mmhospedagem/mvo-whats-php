<?php
declare(strict_types=1);
namespace MVO\Voip;
use MVO\Protocol\Protobuf;

/** wa.voip appDataPayloads/appDataMessage wire protocol, carried on RTP PT119/SSRC slot6. */
final class AppDataProtocol
{
    public static function reaction(int $transaction,string $emoji):string { return self::info(1,$transaction,$emoji); }
    public static function arEffect(int $transaction,string $effect):string { return self::info(3,$transaction,$effect); }
    private static function info(int $kind,int $transaction,string $text):string
    {
        if($transaction<1 || $text==='' || strlen($text)>4096 || preg_match('//u',$text)!==1)throw new \InvalidArgumentException('Invalid call app-data transaction/text');$info=Protobuf::encode([['number'=>1,'wire'=>0,'value'=>$transaction],['number'=>2,'wire'=>2,'value'=>$text]]);return self::bytes(1,self::bytes($kind,$info));
    }
    /** @return list<array{kind:string,transactionId:int,text:string}> */
    public static function decode(string $payload):array
    {
        if(strlen($payload)>65536)throw new \LengthException('Call app-data exceeds 64 KiB');$items=[];
        try{foreach(Protobuf::decode($payload,65536,256) as $field)if($field['wire']===2 && $field['number']===1 && is_string($field['value'])){foreach(self::message($field['value'],false) as $item)$items[]=$item;if(count($items)>=32)return array_slice($items,0,32);}}catch(\Throwable){$items=[];}
        if($items!==[])return $items;
        try{return self::message($payload,true);}catch(\Throwable){return [];}
    }
    /** @return list<array{kind:string,transactionId:int,text:string}> */
    private static function message(string $bytes,bool $strict):array
    {
        $items=[];foreach(Protobuf::decode($bytes,65536,64) as $field){if($field['wire']!==2 || !in_array($field['number'],[1,3],true) || !is_string($field['value']))continue;$transaction=null;$text=null;foreach(Protobuf::decode($field['value'],65536,16) as $info){if($info['number']===1 && $info['wire']===0 && is_int($info['value']))$transaction=$info['value'];elseif($info['number']===2 && $info['wire']===2 && is_string($info['value']))$text=$info['value'];}if($text!==null && preg_match('//u',$text)===1 && (!$strict || $transaction!==null))$items[]=['kind'=>$field['number']===1?'reaction':'ar_effect','transactionId'=>$transaction??0,'text'=>$text];}return $items;
    }
    private static function bytes(int $number,string $value):string { return Protobuf::encode([['number'=>$number,'wire'=>2,'value'=>$value]]); }
}
