<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\Protocol\Jid;

/** Pure preflight for the state features; these checks never contact WhatsApp. */
final class StateFeatureInputs
{
    public static function chat(string $jid):string
    {
        $value=Jid::recipient($jid)->canonical();
        if(!in_array($value->server,['s.whatsapp.net','lid','g.us'],true))throw new \InvalidArgumentException('A user or group chat is required');
        return $value->bare();
    }
    public static function contactManager(string $jid):string
    {
        $value=Jid::parse($jid)->canonical();
        if($value->server!=='lid'||$value->device!==0)throw new \InvalidArgumentException('Contact manager metadata requires a bare LID JID');
        return $value->bare();
    }
    public static function groupHistory(string $jid):string
    {
        $value=Jid::parse($jid)->canonical();
        if($value->server!=='g.us'||$value->device!==0)throw new \InvalidArgumentException('Group history sharing requires a group JID');
        return $value->bare();
    }
    public static function predefinedId(int $id):int
    {
        if($id<0||$id>2147483647)throw new \InvalidArgumentException('Predefined label ID must be a nonnegative int32');
        return $id;
    }
    public static function labelSublist(int $id):int
    {
        if($id<0||$id>2147483647)throw new \InvalidArgumentException('Label sublist ID must be a nonnegative int32');
        return $id;
    }
    /** ISO calendar dates are the explicit SDK input convention for the string birthday field. */
    public static function contactBirthday(string $birthday):string
    {
        if(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$birthday,$m)!==1||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))throw new \InvalidArgumentException('Birthday must be a valid YYYY-MM-DD calendar date');
        return $birthday;
    }
    public static function refreshRequestId(string $id):string
    {
        if(preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$id)!==1)throw new \InvalidArgumentException('Invalid contact refresh request ID');
        return $id;
    }
    public static function recovery(string $id,int $count):string
    {
        if(preg_match('/^[a-f0-9]{64}$/D',$id)!==1||$count<1||$count>10000)throw new \InvalidArgumentException('Recovery requires an observed inventory ID and count 1..10000');
        return $id;
    }
    public static function action(string $action):string
    {
        if($action===''||strlen($action)>256||preg_match('//u',$action)!==1||preg_match('/[\x00-\x1f\x7f]/',$action))throw new \InvalidArgumentException('Invalid pending customer action');
        return $action;
    }
    public static function page(int $limit,string $after=''):void
    {
        if($limit<1||$limit>1000||strlen($after)>512||str_contains($after,"\0"))throw new \InvalidArgumentException('Invalid state pagination');
    }
    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public static function customerData(string $lid,array $input):array
    {
        self::contactManager($lid);
        $allowed=['contactType','email','altPhoneNumbers','birthday','address','acquisitionSource','leadStage','lastOrder'];
        if($input===[]||array_diff(array_keys($input),$allowed)!==[])throw new \InvalidArgumentException('Unknown or empty customer data update');
        foreach(['contactType','acquisitionSource','leadStage']as$field)if(array_key_exists($field,$input)&&(!is_int($input[$field])||$input[$field]<0||$input[$field]>2147483647))throw new \InvalidArgumentException($field.' must be a nonnegative int32');
        if(array_key_exists('lastOrder',$input)&&(!is_int($input['lastOrder'])||$input['lastOrder']<0))throw new \InvalidArgumentException('lastOrder must be Unix seconds');
        foreach(['email','altPhoneNumbers','address']as$field)if(array_key_exists($field,$input)&&(!is_string($input[$field])||strlen($input[$field])>4096||preg_match('//u',$input[$field])!==1||str_contains($input[$field],"\0")))throw new \InvalidArgumentException('Invalid customer '.$field);
        if(isset($input['email'])&&$input['email']!==''&&filter_var($input['email'],FILTER_VALIDATE_EMAIL)===false)throw new \InvalidArgumentException('Invalid customer email');
        if(array_key_exists('birthday',$input)){
            if(!is_string($input['birthday']))throw new \InvalidArgumentException('Customer birthday must be an ISO calendar date');
            $date=self::contactBirthday($input['birthday']);$input['birthday']=(new \DateTimeImmutable($date.'T00:00:00+00:00'))->getTimestamp();
        }
        return $input;
    }
}
