<?php
declare(strict_types=1);
namespace MVO\Service;

/** Shared local queue policy; these fields do not enable native WhatsApp scheduling. */
final class PublicationPolicy
{
    /**
     * @param array<string,mixed> $options
     * @return array{misfirePolicy:string,maxLatenessSeconds:int,expiresAt:?int} */
    public static function normalize(array $options,int $sendAt):array
    {
        if(array_diff(array_keys($options),['misfirePolicy','maxLatenessSeconds','expiresAt'])!==[])throw new \InvalidArgumentException('Unknown publication policy field');
        $policy=$options['misfirePolicy']??'send_late';$lateness=$options['maxLatenessSeconds']??300;$expires=$options['expiresAt']??null;
        if(!in_array($policy,['send_late','skip_if_late'],true)||!is_int($lateness)||$lateness<0||$lateness>2592000||($expires!==null&&(!is_int($expires)||$expires<=$sendAt)))throw new \InvalidArgumentException('Invalid publication lateness policy or expiration');
        return ['misfirePolicy'=>$policy,'maxLatenessSeconds'=>$lateness,'expiresAt'=>$expires];
    }
    /**
     * @param array<string,mixed> $record */
    public static function skipReason(array $record,int $now):?string
    {
        if(isset($record['expiresAt'])&&$now>=$record['expiresAt'])return 'publication_expired';
        if(($record['misfirePolicy']??'send_late')==='skip_if_late'&&$now-$record['sendAt']>($record['maxLatenessSeconds']??300))return 'publication_too_late';
        return null;
    }
    /** Stable semantic hash, independent of JSON object key order.
     * @param array<string,mixed> $value */
    public static function fingerprint(array $value):string
    {
        return hash('sha256',\MVO\Protocol\BinaryData::encode(self::ordered($value)));
    }
    private static function ordered(mixed $value):mixed
    {
        if(!is_array($value))return $value;
        if(!array_is_list($value))ksort($value,SORT_STRING);
        foreach($value as$key=>$child)$value[$key]=self::ordered($child);
        return $value;
    }
    public static function terminal(string $state):bool{return in_array($state,['server_ack','failed','canceled','skipped'],true);}
}
