<?php
declare(strict_types=1);
namespace MVO\Messenger\Transport;

use MVO\Messenger\Auth\CookieJar;

interface HttpClient
{
    /** @param array<string,string> $form */
    public function request(string $method, string $url, CookieJar $cookies, array $form = []): HttpResponse;
}
