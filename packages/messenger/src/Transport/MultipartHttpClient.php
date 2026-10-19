<?php
declare(strict_types=1);
namespace MVO\Messenger\Transport;
use MVO\Messenger\Auth\CookieJar;

interface MultipartHttpClient extends HttpClient
{
    /** @param array<string,string> $form
     * @param array<string,UploadFile> $files */
    public function upload(string $url, #[\SensitiveParameter] CookieJar $cookies, #[\SensitiveParameter] array $form, array $files): HttpResponse;
}
