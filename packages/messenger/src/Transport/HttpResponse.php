<?php
declare(strict_types=1);
namespace MVO\Messenger\Transport;

final class HttpResponse
{
    /** @param array<string,list<string>> $headers */
    public function __construct(public readonly int $status, #[\SensitiveParameter] public readonly string $body, private readonly array $headers = []) {}
    public function header(string $name): ?string { return $this->headers[strtolower($name)][0] ?? null; }
    public function __debugInfo(): array { return ['status'=>$this->status,'body'=>'[PRIVATE]']; }
}
