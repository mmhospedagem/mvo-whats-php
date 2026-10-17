<?php
declare(strict_types=1);
namespace MVO\Voip;

/** AES_CM_128_HMAC_SHA1, WhatsApp's four-byte RTP tag and ten-byte RTCP tag. */
final class SrtpSession
{
    /** @var array<string,string> */
    private array $rtpKeys;
    /** @var array<string,string> */
    private array $rtcpKeys;
    /** @var array<int,array{roc:int,last:int,highest:int,seen:array<int,true>}> */
    private array $rx=[];
    /** @var array<int,array{roc:int,last:int,highest:int,seen:array<int,true>}> */
    private array $tx=[];
    /** @var array<int,array{highest:int,seen:array<int,true>}> */
    private array $rtcpRx=[];
    /** @var array<int,int> */
    private array $rtcpTx=[];
    public function __construct(string $masterKey,string $masterSalt,private readonly int $rtpTagLength=4,private readonly int $maxContexts=32)
    {
        if(!in_array($rtpTagLength,[4,10],true) || $maxContexts<1 || $maxContexts>1024)throw new \InvalidArgumentException('Invalid SRTP session limits');
        $this->rtpKeys=self::keys($masterKey,$masterSalt,0);$this->rtcpKeys=self::keys($masterKey,$masterSalt,3);
    }
    public function protect(string $packet):string
    {
        $p=Rtp::decode($packet,false);$ssrc=$p['ssrc'];$ctx=$this->tx[$ssrc]??['roc'=>0,'last'=>$p['sequence'],'highest'=>-1,'seen'=>[]];$roc=self::estimate($ctx,$p['sequence']);$index=$roc*65536+$p['sequence'];
        if(self::seen($ctx,$index))throw new \LogicException('Reusing an SRTP packet index is forbidden');
        $encrypted=$p['header'].CallCrypto::ctr($this->rtpKeys['cipher'],self::iv($this->rtpKeys['salt'],$ssrc,$roc,$p['sequence']),$p['payload']);
        $tag=substr(hash_hmac('sha1',$encrypted.pack('N',$roc),$this->rtpKeys['auth'],true),0,$this->rtpTagLength);
        if(!isset($this->tx[$ssrc]) && count($this->tx)>=$this->maxContexts)throw new \LengthException('SRTP send context limit reached');self::record($ctx,$index);$ctx['roc']=$roc;$ctx['last']=$p['sequence'];$this->tx[$ssrc]=$ctx;return $encrypted.$tag;
    }
    public function unprotect(string $packet):?string
    {
        if(strlen($packet)<12+$this->rtpTagLength)throw new \UnexpectedValueException('Truncated SRTP');
        $encrypted=substr($packet,0,-$this->rtpTagLength);$p=Rtp::decode($encrypted,false);$ssrc=$p['ssrc'];$ctx=$this->rx[$ssrc]??['roc'=>0,'last'=>$p['sequence'],'highest'=>-1,'seen'=>[]];$roc=self::estimate($ctx,$p['sequence']);$index=$roc*65536+$p['sequence'];
        if(self::seen($ctx,$index))return null;
        $expected=substr(hash_hmac('sha1',$encrypted.pack('N',$roc),$this->rtpKeys['auth'],true),0,$this->rtpTagLength);
        if(!hash_equals($expected,substr($packet,-$this->rtpTagLength)))throw new \UnexpectedValueException('SRTP authentication failed');
        $plain=$p['header'].CallCrypto::ctr($this->rtpKeys['cipher'],self::iv($this->rtpKeys['salt'],$ssrc,$roc,$p['sequence']),$p['payload']);Rtp::decode($plain);
        if(!isset($this->rx[$ssrc]) && count($this->rx)>=$this->maxContexts)throw new \LengthException('SRTP receive context limit reached');if($index>$ctx['highest']){$ctx['roc']=$roc;$ctx['last']=$p['sequence'];}self::record($ctx,$index);$this->rx[$ssrc]=$ctx;return $plain;
    }
    public function protectRtcp(string $packet):string
    {
        if(strlen($packet)<8 || (ord($packet[0])&0xc0)!==0x80)throw new \InvalidArgumentException('Invalid RTCP');$ssrc=unpack('N',substr($packet,4,4))[1];$index=$this->rtcpTx[$ssrc]??0;if($index>=0x7fffffff)throw new \OverflowException('SRTCP index exhausted');
        $iv=$this->rtcpKeys['salt']^(str_repeat("\0",4).pack('N',$ssrc)."\0\0".pack('N',$index)."\0\0");
        $cipher=substr($packet,0,8).CallCrypto::ctr($this->rtcpKeys['cipher'],$iv,substr($packet,8)).pack('N',$index|0x80000000);$this->rtcpTx[$ssrc]=$index+1;
        return $cipher.substr(hash_hmac('sha1',$cipher,$this->rtcpKeys['auth'],true),0,10);
    }
    public function unprotectRtcp(string $packet):?string
    {
        if(strlen($packet)<22)throw new \UnexpectedValueException('Truncated SRTCP');$cipher=substr($packet,0,-10);
        if(!hash_equals(substr(hash_hmac('sha1',$cipher,$this->rtcpKeys['auth'],true),0,10),substr($packet,-10)))throw new \UnexpectedValueException('SRTCP authentication failed');
        $word=unpack('N',substr($cipher,-4))[1];$index=$word&0x7fffffff;$ssrc=unpack('N',substr($cipher,4,4))[1];$ctx=$this->rtcpRx[$ssrc]??['highest'=>-1,'seen'=>[]];if(self::seen($ctx,$index))return null;
        $plain=substr($cipher,0,-4);
        if($word&0x80000000){$iv=$this->rtcpKeys['salt']^(str_repeat("\0",4).pack('N',$ssrc)."\0\0".pack('N',$index)."\0\0");$plain=substr($cipher,0,8).CallCrypto::ctr($this->rtcpKeys['cipher'],$iv,substr($cipher,8,-4));}
        if(!isset($this->rtcpRx[$ssrc]) && count($this->rtcpRx)>=$this->maxContexts)throw new \LengthException('SRTCP receive context limit reached');self::record($ctx,$index);$this->rtcpRx[$ssrc]=$ctx;return $plain;
    }
    /**
     * @return array{cipher:string,auth:string,salt:string} */
    private static function keys(string $key,string $salt,int $base):array { return ['cipher'=>CallCrypto::sessionKey($key,$salt,$base,16),'auth'=>CallCrypto::sessionKey($key,$salt,$base+1,20),'salt'=>CallCrypto::sessionKey($key,$salt,$base+2,14)."\0\0"]; }
    private static function iv(string $salt,int $ssrc,int $roc,int $seq):string { return $salt^(str_repeat("\0",4).pack('NNn',$ssrc,$roc,$seq)."\0\0"); }
    /**
     * @param array<string,mixed> $ctx */
    private static function estimate(array $ctx,int $seq):int { $roc=$ctx['roc'];if($ctx['highest']<0)return $roc;if($ctx['last']<32768 && $seq-$ctx['last']>32768)$roc--;elseif($ctx['last']>=32768 && $ctx['last']-$seq>32768)$roc++;if($roc<0 || $roc>0xffffffff)throw new \UnexpectedValueException('SRTP rollover index invalid');return $roc; }
    /**
     * @param array<string,mixed> $ctx */
    private static function seen(array $ctx,int $index):bool { return $index<=$ctx['highest']-64 || isset($ctx['seen'][$index]); }
    /**
     * @param array<string,mixed> $ctx */
    private static function record(array &$ctx,int $index):void { $ctx['highest']=max($ctx['highest'],$index);$ctx['seen'][$index]=true;foreach($ctx['seen'] as $old=>$yes)if($old<=$ctx['highest']-64)unset($ctx['seen'][$old]); }
}

