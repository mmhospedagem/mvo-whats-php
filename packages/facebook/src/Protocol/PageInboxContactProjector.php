<?php
declare(strict_types=1);

namespace MVO\Facebook\Protocol;

use MVO\Facebook\FacebookException;

/** Inert contact projection for a fully decoded, Page-bound bootstrap batch.
 * Does not consult the personal Messenger store or perform contact hydration I/O.
 */
final class PageInboxContactProjector
{
    /** Table 7 offsets are established by the shipped LSVerifyContactRowExists
     * and LSDeleteThenInsertContact procedures. Authority behavior is deliberate:
     * verify inserts only missing rows; delete/insert cannot replace higher authority.
     * @param list<array{name:string,args:list<mixed>}> $calls
     * @return array<string,array{id:string,name:?string,photo_url:?string}>
     */
    public function project(array $calls):array
    {
        $rows=[];$authority=[];
        foreach($calls as $call){
            $verify=$call['name']==='LSVerifyContactRowExists';
            if(!$verify&&$call['name']!=='LSDeleteThenInsertContact')continue;
            $args=$call['args'];$id=self::id($args[0]??null);
            $level=self::decimal($args[$verify?14:21]??null);
            if($id===null||$level===null)throw new FacebookException('facebook_inbox_snapshot_unconfirmed','The native Page contacts contain an invalid identity or authority');
            if(isset($rows[$id])&&($verify||self::compare($authority[$id],$level)>0))continue;
            $rows[$id]=['id'=>$id,'name'=>self::name($args[$verify?3:9]??null),
                'photo_url'=>self::photoUrl($args[2]??null,$args[$verify?5:3]??null)];
            $authority[$id]=$level;
        }
        return $rows;
    }

    /** Avatar URLs remain literal server URLs; no graph URL or contact ID is invented. */
    public static function photoUrl(mixed $primary,mixed $fallback=null):?string
    {
        foreach([$primary,$fallback]as $url){
            if(!is_string($url)||$url===''||strlen($url)>16384||str_contains($url,'\\')
                ||preg_match('/[\x00-\x20\x7f]/',$url)||preg_match('/%(?![0-9a-f]{2})/i',$url))continue;
            $parts=parse_url($url);
            if(!is_array($parts)||($parts['scheme']??null)!=='https'||!is_string($parts['host']??null)
                ||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||isset($parts['port']))continue;
            $host=strtolower($parts['host']);
            if(preg_match('/^[a-z0-9.-]+$/D',$host)!==1)continue;
            foreach(['fbcdn.net','fbsbx.com']as $domain)if($host===$domain||str_ends_with($host,'.'.$domain))return $url;
        }
        return null;
    }

    private static function name(mixed $value):?string
    {
        return is_string($value)&&trim($value)!==''&&strlen($value)<=4096&&preg_match('//u',$value)===1
            &&preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)!==1?$value:null;
    }
    private static function id(mixed $value):?string { $id=self::decimal($value);return $id!==null&&$id!=='0'?$id:null; }
    private static function decimal(mixed $value):?string
    {
        if(is_int($value)&&$value>=0)$value=(string)$value;
        return is_string($value)&&preg_match('/^(?:0|[1-9][0-9]{0,18})$/D',$value)===1&&self::compare($value,'9223372036854775807')<=0?$value:null;
    }
    private static function compare(string $a,string $b):int { return strlen($a)<=>strlen($b)?:strcmp($a,$b); }
}
