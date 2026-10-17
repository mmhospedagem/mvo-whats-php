<?php
declare(strict_types=1);
namespace MVO\Message;

/** Native photo-poll hashes bind the option name to the uploaded plaintext image hash. */
final class PollOptionHash
{
    public static function image(string $name,string $fileSha256):string
    {
        if(trim($name)===''||preg_match('//u',$name)!==1)throw new \InvalidArgumentException('Poll option name must be non-empty UTF-8');
        if(strlen($fileSha256)!==32)throw new \InvalidArgumentException('Photo poll image SHA256 must be 32 binary bytes');
        return hash('sha256',hash('sha256',$name).base64_encode($fileSha256));
    }

    /** @param array<string,mixed> $option */
    public static function vote(array $option):string
    {
        $name=$option['optionName']??null;
        if(!is_string($name)||trim($name)==='')throw new \InvalidArgumentException('Poll option requires its original name');
        // optionHash associates a photo with an option. It is not the vote token.
        return hash('sha256',$name,true);
    }
}
