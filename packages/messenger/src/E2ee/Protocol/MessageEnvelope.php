<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee\Protocol;
use MVO\Messenger\E2ee\ProtoWire as W;

/** Explicit minimal consumer-text profile. Unknown versions/oneofs never become empty messages. */
final class MessageEnvelope
{
    /** @return array{application:string,franking_tag:string} Private application bytes go only to Signal. */
    public static function text(string $text,?string $frankingKey=null):array
    {
        if($text===''||strlen($text)>60000||preg_match('//u',$text)!==1)throw new \InvalidArgumentException('Use nonempty UTF-8 Messenger text within 60000 bytes');
        $frankingKey??=random_bytes(32);if(strlen($frankingKey)!==32)throw new \InvalidArgumentException('Franking key must be 32 bytes');
        $consumer=W::bytes(1,W::bytes(1,W::bytes(1,W::bytes(1,$text))));
        $sub=W::integer(1,0).W::bytes(2,W::bytes(1,$consumer).W::integer(2,1));
        $application=W::bytes(1,W::bytes(4,$sub)).W::bytes(2,W::bytes(8,$frankingKey).W::integer(9,0));
        return ['application'=>$application,'franking_tag'=>hash_hmac('sha256',$application,$frankingKey,true)];
    }
    public static function transport(#[\SensitiveParameter]string $application,?string $ownDeviceDestination=null):string
    {
        $integral=W::bytes(1,"\x01");
        if($ownDeviceDestination!==null){$destination=Address::bare($ownDeviceDestination);if($destination!==$ownDeviceDestination)throw new \InvalidArgumentException('DSM requires a bare Messenger destination');$integral.=W::bytes(2,W::bytes(1,$destination));}
        return W::bytes(1,W::bytes(1,W::bytes(1,$application).W::integer(2,2)).W::integer(3,0)).W::bytes(2,W::bytes(1,$integral));
    }
    /** @return array{text:string,destination:?string,franking_verified:bool} */
    public static function read(#[\SensitiveParameter]string $transport,?string $frankingTag=null):array
    {
        if($frankingTag!==null&&strlen($frankingTag)!==32)throw new \UnexpectedValueException('Invalid Messenger franking tag');
        $t=self::fields($transport);$payload=self::fields(self::bytes($t,1));$sub=self::fields(self::bytes($payload,1));
        if(($sub[2]??0)!==2||($payload[3]??0)!==0)throw new \UnexpectedValueException('Unsupported Messenger transport profile');
        $application=self::bytes($sub,1);$app=self::fields($application);$metadata=self::fields(self::bytes($app,2));$key=self::bytes($metadata,8);
        if(strlen($key)!==32||($metadata[9]??0)!==0||$frankingTag!==null&&!hash_equals(hash_hmac('sha256',$application,$key,true),$frankingTag))throw new \UnexpectedValueException('Messenger franking verification failed');
        $body=self::fields(self::bytes($app,1));self::onlyContent($body,4);$consumer=self::fields(self::bytes($body,4));
        if(($consumer[1]??0)!==0)throw new \UnexpectedValueException('Unsupported consumer future-proof profile');self::onlyContent($consumer,2,[1]);
        $nested=self::fields(self::bytes($consumer,2));if(($nested[2]??0)!==1)throw new \UnexpectedValueException('Unsupported Messenger consumer version');
        $ca=self::fields(self::bytes($nested,1));$p=self::fields(self::bytes($ca,1));self::onlyContent($p,1);$content=self::fields(self::bytes($p,1));self::onlyContent($content,1);
        $textFields=self::fields(self::bytes($content,1));$text=self::bytes($textFields,1);
        if(strlen($text)>60000||preg_match('//u',$text)!==1)throw new \UnexpectedValueException('Invalid Messenger text');
        $destination=null;
        if(isset($t[2])){$protocol=self::fields(self::bytes($t,2));if(isset($protocol[1])){$integral=self::fields(self::bytes($protocol,1));if(isset($integral[2])){$dsm=self::fields(self::bytes($integral,2));$destination=self::bytes($dsm,1);if(Address::bare($destination)!==$destination)throw new \UnexpectedValueException('Invalid DSM destination');}}}
        return ['text'=>$text,'destination'=>$destination,'franking_verified'=>$frankingTag!==null];
    }
    /** @return array<int,int|string> */
    private static function fields(string $raw):array{$out=[];foreach(W::decode($raw)as$id=>$v){if(count($v)!==1)throw new \UnexpectedValueException('Repeated singular Messenger envelope field');$out[$id]=$v[0];}return $out;}
    /** @param array<int,int|string> $fields */
    private static function bytes(array $fields,int $id):string{$v=$fields[$id]??null;if(!is_string($v)||$v==='')throw new \UnexpectedValueException('Missing Messenger envelope bytes');return $v;}
    /** @param array<int,int|string> $fields
     * @param list<int> $metadata
     */
    private static function onlyContent(array $fields,int $expected,array $metadata=[]):void{foreach($fields as$id=>$value)if($id!==$expected&&!in_array($id,$metadata,true))throw new \UnexpectedValueException('Unsupported Messenger oneof content');}
}
