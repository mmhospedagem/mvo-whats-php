<?php
declare(strict_types=1);

namespace MVO\Facebook\Protocol;

/** Native request context from one authenticated Business Suite document.
 * Request fields contain CSRF values and must remain inside the session owner.
 */
final class PageInboxContext
{
    /** @param array<string,string> $requestFields
     * @param array<string,mixed> $queryVariables
     */
    public function __construct(
        public readonly string $origin,
        public readonly string $actorId,
        public readonly string $pageId,
        #[\SensitiveParameter] private readonly array $requestFields,
        public readonly array $queryVariables,
        public readonly ?string $profileActorId = null,
    ) {}

    /** Private session-bound CSRF/request fields; never return through HTTP, events or logs.
     * @return array<string,string>
     */
    public function privateRequestFields(): array { return $this->requestFields; }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return ['origin'=>$this->origin,'actorId'=>$this->actorId,'pageId'=>$this->pageId,'profileActorId'=>$this->profileActorId,
            'queryVariables'=>$this->queryVariables,'privateRequestFields'=>'[redacted]'];
    }

    /** Request context is deliberately ephemeral and contains session-bound secrets. */
    public function __serialize(): array { throw new \LogicException('Do not serialize a private Page inbox context'); }
}
