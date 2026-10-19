<?php
declare(strict_types=1);
namespace MVO\Messenger\Auth;

use MVO\Messenger\Transport\HttpResponse;

/** Closures retain the original client's owner/lifecycle guard on every call. */
final class BoundWebSession implements MultipartWebSession,BootstrapWebSession,PageMailboxWebSession,RawUploadWebSession,\JsonSerializable
{
    /** @param \Closure():array<string,string> $fields
     * @param \Closure(string,string,array<string,string>):HttpResponse $sender
     * @param \Closure(string,array<string,string>,array<string,\MVO\Messenger\Transport\UploadFile>):HttpResponse|null $uploader
     * @param \Closure(string):mixed|null $moduleProvider
     * @param \Closure(\MVO\Messenger\Protocol\PageMailboxConfig):\MVO\Messenger\Transport\PageMailboxTransport|null $mailboxFactory
     * @param \Closure(string,string,array<string,string>,array<string,string>):HttpResponse|null $headerSender
     * @param \Closure(string,\MVO\Messenger\Transport\UploadFile,int,int,array<string,string>):HttpResponse|null $rangeUploader
     */
    public function __construct(private readonly string $account,private readonly \Closure $fields,private readonly \Closure $sender,private readonly ?\Closure $uploader=null,private readonly ?\Closure $moduleProvider=null,private readonly ?\Closure $mailboxFactory=null,private readonly ?\Closure $headerSender=null,private readonly ?\Closure $rangeUploader=null){}
    public function accountId():string{return $this->account;}
    public function privateRequestFields():array{return ($this->fields)();}
    public function request(string $method,string $url,#[\SensitiveParameter] array $form=[]):HttpResponse{return ($this->sender)($method,$url,$form);}
    /** @param array<string,\MVO\Messenger\Transport\UploadFile> $files */
    public function upload(string $url, #[\SensitiveParameter] array $form, array $files): HttpResponse
    {
        if ($this->uploader === null) throw new \LogicException('This Web session does not provide multipart upload');
        return ($this->uploader)($url, $form, $files);
    }
    public function privateModule(string $name): mixed
    {
        if ($name !== 'ReactComposerMediaConfig') throw new \InvalidArgumentException('This Web session only exposes the photo upload configuration');
        return $this->moduleProvider === null ? null : ($this->moduleProvider)($name);
    }
    /** @param array<string,string> $form
     * @param array<string,string> $headers */
    public function requestWithHeaders(string $method,string $url,#[\SensitiveParameter] array $form=[],array $headers=[]):HttpResponse
    {
        if($this->headerSender===null)throw new \LogicException('This Web session does not provide resumable upload requests');
        return ($this->headerSender)($method,$url,$form,$headers);
    }
    public function uploadRange(string $url,\MVO\Messenger\Transport\UploadFile $file,int $offset,int $length,array $headers):HttpResponse
    {
        if($this->rangeUploader===null)throw new \LogicException('This Web session does not provide raw range uploads');
        return ($this->rangeUploader)($url,$file,$offset,$length,$headers);
    }
    public function pageMailbox(\MVO\Messenger\Protocol\PageMailboxConfig $config): \MVO\Messenger\Transport\PageMailboxTransport
    {
        if ($config->accountId !== $this->account) throw new \InvalidArgumentException('The Page mailbox must use the original account owner');
        if ($this->mailboxFactory === null) throw new \LogicException('This Web session does not provide a Page mailbox transport');
        return ($this->mailboxFactory)($config);
    }
    public function jsonSerialize():never{throw new \LogicException('A private Facebook Web session cannot be serialized');}
    public function __debugInfo():array{return ['accountId'=>$this->account,'session'=>'[PRIVATE]'];}
}
