<?php
declare(strict_types=1);
namespace MVO\Messenger\Auth;

use MVO\Messenger\Transport\HttpResponse;

/** Closures retain the original client's owner/lifecycle guard on every call. */
final class BoundWebSession implements WebSession,\JsonSerializable
{
    /** @param \Closure():array<string,string> $fields
     * @param \Closure(string,string,array<string,string>):HttpResponse $sender
     */
    public function __construct(private readonly string $account,private readonly \Closure $fields,private readonly \Closure $sender){}
    public function accountId():string{return $this->account;}
    public function privateRequestFields():array{return ($this->fields)();}
    public function request(string $method,string $url,#[\SensitiveParameter] array $form=[]):HttpResponse{return ($this->sender)($method,$url,$form);}
    public function jsonSerialize():never{throw new \LogicException('A private Facebook Web session cannot be serialized');}
    public function __debugInfo():array{return ['accountId'=>$this->account,'session'=>'[PRIVATE]'];}
}
