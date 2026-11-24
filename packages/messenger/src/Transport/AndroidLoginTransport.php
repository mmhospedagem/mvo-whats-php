<?php
declare(strict_types=1);

namespace MVO\Messenger\Transport;

/** Private native-login HTTP boundary; no session, cookie jar or automatic retry. */
interface AndroidLoginTransport
{
    /**
     * @param array<string,string> $form
     * @param array<string,string> $headers
     */
    public function request(
        string $method,
        #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] array $form = [],
        #[\SensitiveParameter] array $headers = [],
    ): HttpResponse;
}
