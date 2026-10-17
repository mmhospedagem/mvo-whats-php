<?php
declare(strict_types=1);
namespace MVO\Service;

/** Input checks shared by channel operations, before any transport or upload. */
final class NewsletterInput
{
    public static function jid(string $jid): string
    {
        if (!preg_match('/^[0-9]{1,128}@newsletter$/D', $jid)) throw new \InvalidArgumentException('Expected a channel JID');
        return $jid;
    }
    public static function text(mixed $value,string $field,int $maximum=65536,bool $empty=true): string
    {
        if (!is_string($value) || (!$empty && trim($value)==='') || strlen($value)>$maximum || preg_match('//u',$value)!==1 || str_contains($value,"\0")) throw new \InvalidArgumentException('Invalid channel '.$field);
        return $value;
    }
    /** @param array<string,mixed> $input
     * @param list<string> $allowed
     * @param list<string> $nullable */
    public static function keys(array $input,array $allowed,array $nullable=[]): void
    {
        foreach ($input as $key=>$value) {
            if (!in_array($key,$allowed,true)) throw new \InvalidArgumentException('Unknown channel field: '.$key);
            if ($value===null && !in_array($key,$nullable,true)) throw new \InvalidArgumentException('Channel field cannot be null: '.$key);
        }
    }
    public static function integer(mixed $value,string $field,int $minimum=1,int $maximum=1000): int
    {
        if (!is_int($value) || $value<$minimum || $value>$maximum) throw new \InvalidArgumentException('Invalid channel '.$field);
        return $value;
    }
    public static function boolean(mixed $value,string $field): bool
    {
        if (!is_bool($value)) throw new \InvalidArgumentException('Invalid channel '.$field);
        return $value;
    }
    /** @param list<string> $allowed */
    public static function enum(mixed $value,string $field,array $allowed): string
    {
        if (!is_string($value) || !in_array($value,$allowed,true)) throw new \InvalidArgumentException('Invalid channel '.$field);
        return $value;
    }
    public static function role(mixed $value): string { return self::enum($value,'viewRole',['OWNER','ADMIN','SUBSCRIBER','GUEST']); }
    /** @return list<string> */
    public static function strings(mixed $values,string $field,int $maximum=1000,bool $allowEmpty=true): array
    {
        if (!is_array($values) || !array_is_list($values) || count($values)>$maximum || (!$allowEmpty && $values===[])) throw new \InvalidArgumentException('Invalid channel '.$field);
        foreach ($values as $value) self::text($value,$field,256,false);
        return $values;
    }
    /** @return list<string> */
    public static function countries(mixed $values): array
    {
        $values=self::strings($values,'countryCodes',250);
        foreach ($values as $value) if (!preg_match('/^[A-Z]{2}$/D',$value)) throw new \InvalidArgumentException('Country codes must contain two uppercase letters');
        return $values;
    }
    public static function cursor(mixed $value): string { return self::text($value,'startCursor',4096,false); }
    public static function picture(mixed $bytes): string
    {
        if (!is_string($bytes) || strlen($bytes)<4 || strlen($bytes)>16777216 || !str_starts_with($bytes,"\xff\xd8\xff")) throw new \InvalidArgumentException('Channel picture must contain JPEG bytes');
        return $bytes;
    }
    public static function messageId(string $id): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$id)) throw new \InvalidArgumentException('Invalid channel message id');
        return $id;
    }
    public static function invite(string $invite): string
    {
        if (str_starts_with($invite,'https://')) {
            $url=parse_url($invite);
            if ($url===false || !in_array(strtolower($url['host'] ?? ''),['whatsapp.com','www.whatsapp.com'],true) || isset($url['port']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || !preg_match('~^/channel/([A-Za-z0-9_-]{1,256})/?$~D',$url['path'] ?? '',$match)) throw new \InvalidArgumentException('Invalid channel invite URL');
            return $match[1];
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,256}$/D',$invite)) throw new \InvalidArgumentException('Invalid channel invite code');
        return $invite;
    }
}
