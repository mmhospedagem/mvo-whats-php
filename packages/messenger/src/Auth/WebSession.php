<?php
declare(strict_types=1);
namespace MVO\Messenger\Auth;

use MVO\Messenger\Transport\HttpResponse;

/** Account-bound application integration. Responses may contain private data. */
interface WebSession
{
    public function accountId():string;
    /** Current CSRF/session fields; never expose these through an API or log.
     * @return array<string,string>
     */
    public function privateRequestFields():array;
    /** @param array<string,string> $form */
    public function request(string $method,string $url,#[\SensitiveParameter] array $form=[]):HttpResponse;
}
