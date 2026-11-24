<?php
declare(strict_types=1);

namespace MVO\Messenger\Auth;

use MVO\Messenger\MessengerException;

/** Native approval status only; it contains no authenticated session or cookies. */
final class AndroidApprovalResult
{
    private function __construct() {}

    /** Accept one unambiguous status in the APK's data/approved response shape. */
    public static function fromJson(#[\SensitiveParameter] string $json):bool
    {
        if(strlen($json)>2097152)throw self::invalid();
        try{$root=json_decode($json,false,8,JSON_THROW_ON_ERROR);}
        catch(\JsonException){throw self::invalid();}
        // Preserve JSON object/array types: {"0":{...}} is not a status list.
        if(!$root instanceof \stdClass||array_keys(get_object_vars($root))!==['data']
            ||!is_array($root->data)||count($root->data)!==1)throw self::invalid();
        $entry=$root->data[0];
        if(!$entry instanceof \stdClass||array_keys(get_object_vars($entry))!==['approved']
            ||!is_bool($entry->approved))throw self::invalid();
        return $entry->approved;
    }

    private static function invalid():MessengerException
    {
        return new MessengerException('login_native_response_invalid','Facebook returned an unsupported native approval response');
    }
}
