<?php
declare(strict_types=1);
namespace MVO\Messenger\Transport;

use MVO\Messenger\Auth\CookieJar;

interface RawUploadHttpClient extends HttpClient
{
    /** @param array<string,string> $form
     * @param array<string,string> $headers */
    public function requestWithHeaders(string $method,string $url,#[\SensitiveParameter] CookieJar $cookies,#[\SensitiveParameter] array $form=[],array $headers=[]):HttpResponse;
    /** @param array<string,string> $headers */
    public function uploadRange(string $url,#[\SensitiveParameter] CookieJar $cookies,UploadFile $file,int $offset,int $length,array $headers):HttpResponse;
}
