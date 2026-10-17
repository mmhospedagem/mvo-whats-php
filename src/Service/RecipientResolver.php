<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\Protocol\Jid;

/** Resolves usernames through authenticated USync; username mappings are never guessed or cached. */
final class RecipientResolver
{
    public function __construct(private readonly ProfileService $profile) {}

    /** Pure validation for application/API request boundaries; does not contact WhatsApp. */
    public static function normalize(string $input):string
    {
        $input=trim($input);
        if (!str_starts_with($input,'@') && (str_contains($input,'@') || preg_match('/^\+?[0-9 ()-]+$/D',$input))) {
            $jid=Jid::recipient($input)->canonical();
            if (!in_array($jid->server,['s.whatsapp.net','lid','g.us','bot','newsletter','broadcast'],true)) throw new \InvalidArgumentException('Invalid WhatsApp recipient server');
            return (string)$jid;
        }
        $parsed=ProfileService::parseUsername($input);
        return $parsed['username'].($parsed['key']===null ? '' : ':'.$parsed['key']);
    }

    /** Validates the input before classification, so malformed values cannot bypass validation. */
    public static function isUsername(string $input):bool
    {
        return !str_contains(self::normalize($input),'@');
    }

    public function resolve(string $input):string
    {
        $normalized=self::normalize($input);
        if (str_contains($normalized,'@')) return $normalized;
        $result=$this->profile->resolveUsername($normalized);
        $status=$result['status'] ?? 'invalid-result';
        if ($status!=='found') throw new RecipientResolutionException(in_array($status,['not-found','key-required'],true) ? $status : 'invalid-result');
        if (!is_string($result['jid'] ?? null)) throw new RecipientResolutionException('invalid-result');
        try { $jid=Jid::parse($result['jid'])->canonical(); } catch (\InvalidArgumentException) { throw new RecipientResolutionException('invalid-result'); }
        if (!in_array($jid->server,['s.whatsapp.net','lid'],true) || $jid->device!==0 || !ctype_digit($jid->user)) throw new RecipientResolutionException('invalid-result');
        return (string)$jid;
    }
}
