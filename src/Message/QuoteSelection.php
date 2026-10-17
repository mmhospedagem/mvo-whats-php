<?php
declare(strict_types=1);
namespace MVO\Message;

/** A selected quotation is an exact, bounded substring, never a caller-supplied substitute. */
final class QuoteSelection
{
    /** @param array<string,mixed> $proto */
    public static function validate(array $proto,mixed $selectedText):string
    {
        if(!is_string($selectedText)||trim($selectedText)===''||strlen($selectedText)>8192||!preg_match('//u',$selectedText))throw new \InvalidArgumentException('selectedText must be non-empty UTF-8, up to 8192 bytes');
        $message=ContentDecoder::unwrap($proto)['message'];
        $text=$message['conversation']??$message['extendedTextMessage']['text']??$message['imageMessage']['caption']??$message['instantImageMessage']['caption']??$message['videoMessage']['caption']??$message['documentMessage']['caption']??null;
        if(!is_string($text)||!str_contains($text,$selectedText))throw new \InvalidArgumentException('selectedText is not present in the original message');
        return $selectedText;
    }
}
