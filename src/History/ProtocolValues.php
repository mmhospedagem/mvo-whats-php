<?php
declare(strict_types=1);
namespace MVO\History;

/** Bounded public projections: never fetch URLs or expose protocol key material. */
final class ProtocolValues
{
    public static function text(mixed $value,int $max=8192):?string
    {
        if(!is_string($value)||preg_match('//u',$value)!==1)return null;
        $value=preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u','',$value)??'';
        if(strlen($value)>$max){$value=substr($value,0,$max);while($value!==''&&preg_match('//u',$value)!==1)$value=substr($value,0,-1);}
        return $value;
    }
    public static function url(mixed $value):?string
    {
        if(!is_string($value)||strlen($value)>8192||preg_match('//u',$value)!==1||preg_match('/[\x00-\x20\x7f]/',$value))return null;
        $parts=parse_url($value);
        if(!is_array($parts)||strtolower($parts['scheme']??'')!=='https'||!isset($parts['host'])||isset($parts['user'])||isset($parts['pass']))return null;
        $host=strtolower($parts['host']);
        if($host==='localhost'||str_ends_with($host,'.localhost')||str_ends_with($host,'.local'))return null;
        if(filter_var(trim($host,'[]'),FILTER_VALIDATE_IP)!==false&&filter_var(trim($host,'[]'),FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)return null;
        if(isset($parts['query'])&&preg_match('/(?:^|[&;])(?:access_token|token|auth|authorization|key|secret|signature|sig)=/i',$parts['query']))return null;
        return explode('#',$value,2)[0];
    }
    /**
     * @return array<string,mixed>|null */
    public static function key(mixed $value):?array
    {
        if(!is_array($value)||!is_string($value['id']??null)||$value['id']===''||strlen($value['id'])>256||preg_match('/[\x00-\x20\x7f]/',$value['id']))return null;
        $result=['id'=>$value['id']];
        foreach(['remoteJid','participant']as$field)if(isset($value[$field])){
            if(!is_string($value[$field]))return null;
            try{$result[$field]=\MVO\Protocol\Jid::parse($value[$field])->canonical()->bare();}catch(\InvalidArgumentException){return null;}
        }
        if(is_bool($value['fromMe']??null))$result['fromMe']=$value['fromMe'];
        return $result;
    }
    public static function page(int $limit,string $after=''):void
    {
        if($limit<1||$limit>1000||strlen($after)>128||preg_match('/[^A-Za-z0-9_-]/',$after))throw new \InvalidArgumentException('Invalid protocol page');
    }
}
