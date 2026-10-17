<?php
declare(strict_types=1);
namespace MVO\Voip;
use MVO\Crypto\Crypto;
use MVO\Protocol\Protobuf;

final class Stun
{
    public const COOKIE=0x2112a442;
    public static function attribute(int $type,string $value):string { if($type<0 || $type>65535 || strlen($value)>65535)throw new \InvalidArgumentException('Invalid STUN attribute');return pack('nn',$type,strlen($value)).$value.str_repeat("\0",(4-strlen($value)%4)%4); }
    /**
     * @param array<int,string> $attributes */
    public static function encode(int $type,string $transactionId,array $attributes=[],?string $integrityKey=null,bool $fingerprint=true):string
    {
        Crypto::length($transactionId,12);$body='';foreach($attributes as $tag=>$value)$body.=self::attribute($tag,$value);
        if($integrityKey!==null){$input=pack('nnN',$type,strlen($body)+24,self::COOKIE).$transactionId.$body;$body.=self::attribute(8,hash_hmac('sha1',$input,$integrityKey,true));}
        if($fingerprint){$input=pack('nnN',$type,strlen($body)+8,self::COOKIE).$transactionId.$body;$body.=self::attribute(0x8028,pack('N',crc32($input)^0x5354554e));}
        if(strlen($body)>65535)throw new \LengthException('STUN message too large');return pack('nnN',$type,strlen($body),self::COOKIE).$transactionId.$body;
    }
    /**
     * @return array{type:int,transactionId:string,attributes:array<int,list<string>>,success:bool,error:bool,authenticated:bool} */
    public static function decode(string $packet,?string $integrityKey=null,?string $transactionId=null):array
    {
        if(strlen($packet)<20)throw new \UnexpectedValueException('Truncated STUN');$header=unpack('ntype/nlength/Ncookie',substr($packet,0,8));$id=substr($packet,8,12);
        if($header['cookie']!==self::COOKIE || $header['length']!==strlen($packet)-20 || $header['length']%4!==0 || ($transactionId!==null && !hash_equals($id,$transactionId)))throw new \UnexpectedValueException('Invalid STUN header or transaction');
        $attrs=[];$authenticated=false;$seenIntegrity=false;$seenFingerprint=false;
        for($offset=20;$offset<strlen($packet);) {
            if($offset+4>strlen($packet))throw new \UnexpectedValueException('Truncated STUN attribute');$a=unpack('ntype/nlength',substr($packet,$offset,4));$tag=$a['type'];$len=$a['length'];$value=substr($packet,$offset+4,$len);if(strlen($value)!==$len)throw new \UnexpectedValueException('STUN attribute overrun');
            if($seenFingerprint || ($seenIntegrity && $tag!==0x8028))throw new \UnexpectedValueException('Unauthenticated trailing STUN attribute');
            if($tag===8){if($seenIntegrity || $len!==20)throw new \UnexpectedValueException('Invalid STUN integrity');$seenIntegrity=true;if($integrityKey!==null){$input=substr_replace(substr($packet,0,$offset),pack('n',$offset-20+24),2,2);if(!hash_equals(hash_hmac('sha1',$input,$integrityKey,true),$value))throw new \UnexpectedValueException('STUN integrity mismatch');$authenticated=true;}}
            if($tag===0x8028){if($len!==4 || unpack('N',$value)[1]!==((crc32(substr($packet,0,$offset))^0x5354554e)&0xffffffff))throw new \UnexpectedValueException('STUN fingerprint mismatch');$seenFingerprint=true;}
            $attrs[$tag][]=$value;$offset+=4+$len+(4-$len%4)%4;if($offset>strlen($packet))throw new \UnexpectedValueException('STUN padding overrun');
        }
        return ['type'=>$header['type'],'transactionId'=>$id,'attributes'=>$attrs,'success'=>($header['type']&0x110)===0x100,'error'=>($header['type']&0x110)===0x110,'authenticated'=>$authenticated];
    }
    public static function allocate(string $token,string $key,string $subscriptions,string $ip,int $port,string $id,?string $rxSubscriptions=null):string
    {
        $address=inet_pton($ip);if($address===false || $port<1 || $port>65535 || $token==='' || $key==='')throw new \InvalidArgumentException('Invalid raw relay credentials/address');Crypto::length($id,12);
        $mask=pack('N',self::COOKIE);if(strlen($address)===16){for($i=0;$i<12;$i++)$mask.=$id[intdiv($i,4)*4+3-($i%4)];}
        $xor="\0".(strlen($address)===16?"\x02":"\x01").pack('n',$port^(self::COOKIE>>16)).($address^$mask);
        $attrs=[0x4000=>$token];if($rxSubscriptions!==null && $rxSubscriptions!=='')$attrs[0x4021]=$rxSubscriptions;$attrs[0x4024]=$subscriptions;$attrs[0x16]=$xor;
        return self::encode(3,$id,$attrs,$key,false);
    }
    /**
     * @param list<int> $self
     * @param list<int> $peer */
    public static function subscriptions(array $self,array $peer,int $selfPid=0,int $peerPid=0):string
    {
        $out=[];foreach([[$self,$selfPid],[$peer,$peerPid]] as [$sources,$pid])foreach($sources as $ssrc){if($ssrc===0)continue;$inner=Protobuf::encode([['number'=>1,'wire'=>0,'value'=>$pid],['number'=>2,'wire'=>0,'value'=>1],['number'=>3,'wire'=>0,'value'=>$ssrc]]);$out[]=['number'=>1,'wire'=>2,'value'=>$inner];}return Protobuf::encode($out);
    }
    public static function ping(string $id):string { Crypto::length($id,12);return pack('nnN',0x0801,0,self::COOKIE).$id; }
    public static function senderSubscriptions(int $ssrc):string {
        if($ssrc<1 || $ssrc>0xffffffff)throw new \InvalidArgumentException('Invalid subscription SSRC');
        return Protobuf::encode([['number'=>1,'wire'=>2,'value'=>Protobuf::encode([['number'=>3,'wire'=>0,'value'=>$ssrc],['number'=>5,'wire'=>0,'value'=>0],['number'=>6,'wire'=>0,'value'=>0]])]]);
    }
    public static function binding(?string $username,?string $key,?string $subscriptions,bool $controlling,bool $fingerprint,string $id):string {
        $attrs=[];if($username!==null && $username!=='')$attrs[6]=$username;$attrs[0x24]=pack('N',16777215);if($controlling)$attrs[0x802a]=random_bytes(8);if($subscriptions!==null && $subscriptions!=='')$attrs[0x4000]=$subscriptions;return self::encode(1,$id,$attrs,$key,$fingerprint);
    }
    /** @param list<array{layer:int,ssrcs:list<int>}> $layers */
    public static function streamDescriptor(array $layers):string {
        $records=[];foreach($layers as $layer){if($layer['layer']<0 || $layer['layer']>255)throw new \InvalidArgumentException('Invalid stream layer');$types=$layer['layer']===5?[4]:[0,1,2];foreach($types as $index=>$type){$ssrc=$layer['ssrcs'][$index]??0;if($ssrc===0)continue;if($ssrc<0 || $ssrc>0xffffffff)throw new \InvalidArgumentException('Invalid stream SSRC');$fields=[];if($layer['layer']!==0)$fields[]=['number'=>1,'wire'=>0,'value'=>$layer['layer']];if($type!==0)$fields[]=['number'=>2,'wire'=>0,'value'=>$type];$fields[]=['number'=>3,'wire'=>0,'value'=>$ssrc];$records[]=['number'=>1,'wire'=>2,'value'=>Protobuf::encode($fields)];}}return Protobuf::encode($records);
    }
    /** @param list<array{pid:int,quality:int}> $subscriptions */
    public static function rxSubscriptions(array $subscriptions):string {
        $records=[];foreach($subscriptions as $subscription){$fields=[];foreach([1=>'pid',2=>'quality'] as $number=>$key){$value=$subscription[$key];if($value<0 || $value>0xffffffff)throw new \InvalidArgumentException('Invalid RX subscription');if($value!==0)$fields[]=['number'=>$number,'wire'=>0,'value'=>$value];}$records[]=['number'=>2,'wire'=>2,'value'=>Protobuf::encode($fields)];}return Protobuf::encode($records);
    }
}

