<?php
declare(strict_types=1);
namespace MVO\Messenger\Auth;

use MVO\Messenger\Protocol\PageMailboxConfig;
use MVO\Messenger\Transport\PageMailboxTransport;

/** The original runtime creates the Page stream without exposing cookies or another lock owner. */
interface PageMailboxWebSession extends WebSession
{
    public function pageMailbox(PageMailboxConfig $config): PageMailboxTransport;
}
