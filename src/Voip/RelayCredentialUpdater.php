<?php
declare(strict_types=1);
namespace MVO\Voip;

/** Optional capability; existing custom RelayTransport implementations stay compatible. */
interface RelayCredentialUpdater
{
    /** Re-registers the same endpoint without replacing its socket or DTLS channel.
     * @param array<string,mixed> $descriptor */
    public function updateCredentials(array $descriptor):void;
}
