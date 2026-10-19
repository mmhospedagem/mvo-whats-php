<?php
declare(strict_types=1);
namespace MVO\Messenger\Auth;

use MVO\Messenger\Transport\{HttpResponse,UploadFile};

/** Optional same-owner boundary for the observed Web resumable video protocol. */
interface RawUploadWebSession extends WebSession
{
    /** @param array<string,string> $form
     * @param array<string,string> $headers */
    public function requestWithHeaders(string $method,string $url,#[\SensitiveParameter] array $form=[],array $headers=[]):HttpResponse;
    /** @param array<string,string> $headers */
    public function uploadRange(string $url,UploadFile $file,int $offset,int $length,array $headers):HttpResponse;
}
