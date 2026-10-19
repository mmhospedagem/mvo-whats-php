<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee;

use MVO\Messenger\Transport\HttpResponse;

/** Separate account-changing protocol, not a Facebook Graph or cookie transport. */
interface RegistrationTransport
{
    /** $operation is fetch or register. Implementations must not retry register.
     * @param array<string,string> $form Private CAT-bearing protocol fields.
     */
    public function request(string $operation, #[\SensitiveParameter] array $form): HttpResponse;
}
