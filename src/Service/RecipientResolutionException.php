<?php
declare(strict_types=1);
namespace MVO\Service;

/** An authenticated username lookup did not produce a usable account JID. */
final class RecipientResolutionException extends \RuntimeException
{
    public function __construct(public readonly string $status)
    {
        parent::__construct(match ($status) {
            'not-found'=>'WhatsApp username was not found',
            'key-required'=>'WhatsApp username requires a four-digit contact key',
            default=>'WhatsApp returned an invalid username lookup result',
        });
    }
}
