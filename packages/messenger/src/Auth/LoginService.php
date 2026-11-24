<?php
declare(strict_types=1);

namespace MVO\Messenger\Auth;

/** One private authentication attempt, sharing the Messenger owner's lock.
 * Implementations return only independently confirmed, scoped account cookies.
 * Challenges and secrets stay in memory; unsupported continuations must fail
 * explicitly, never fall back to another authentication protocol on their own.
 */
interface LoginService
{
    public function login(#[\SensitiveParameter] string $email, #[\SensitiveParameter] string $password): CookieJar;

    /** Safe metadata only.
     * @return array<string,mixed>|null
     */
    public function challenge(): ?array;

    /** @return array<string,mixed> */
    public function refreshMethods(): array;

    /** Explicit user selection; never automatically sends a verification code.
     * @return array<string,mixed>
     */
    public function requestMethod(string $methodId): array;

    public function checkApproval(): ?CookieJar;
    public function confirm(#[\SensitiveParameter] string $code): CookieJar;
    public function cancel(): void;

    /** Secret application handoff, never an HTTP/event/job response.
     * @return array{url:string,cookies:list<array<string,mixed>>,expires_at:int}
     */
    public function exportPrivateChallenge(): array;
}
