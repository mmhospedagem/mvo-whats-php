<?php
declare(strict_types=1);

namespace MVO\Messenger\Transport;

/** Per-login anonymous cookie engine; implementations must never persist a password. */
interface WebLoginTransport
{
    /** @param array<string,string> $form */
    public function request(string $method, #[\SensitiveParameter] string $url, #[\SensitiveParameter] array $form = []): WebLoginResponse;
    /** @return list<array<string,mixed>> Actual response-cookie scopes, not copied across domains. */
    public function cookies(): array;
    public function clear(): void;
}
