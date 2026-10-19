<?php
declare(strict_types=1);
namespace MVO\Messenger\Auth;
use MVO\Messenger\Transport\{HttpResponse,UploadFile};

/** Optional upload capability; credentials remain in the account owner. */
interface MultipartWebSession extends WebSession
{
    /** @param array<string,string> $form
     * @param array<string,UploadFile> $files */
    public function upload(string $url, #[\SensitiveParameter] array $form, array $files): HttpResponse;
}
