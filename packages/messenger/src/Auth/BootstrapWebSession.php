<?php
declare(strict_types=1);
namespace MVO\Messenger\Auth;

/** Private, allowlisted configuration from this owner's authenticated bootstrap. */
interface BootstrapWebSession extends WebSession
{
    public function privateModule(string $name): mixed;
}
