<?php
declare(strict_types=1);
namespace MVO\Messenger\Transport;

use MVO\Messenger\Protocol\PageMailboxConfig;

/** A Page-bound stream group. Never substitute the personal Messenger connection. */
interface PageMailboxTransport
{
    public function config(): PageMailboxConfig;
    /** @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function request(array $request): array;
    public function close(): void;
}
