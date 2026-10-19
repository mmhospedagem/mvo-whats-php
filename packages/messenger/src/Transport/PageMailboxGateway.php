<?php
declare(strict_types=1);
namespace MVO\Messenger\Transport;

use MVO\Messenger\Auth\SessionStore;
use MVO\Messenger\MessengerException;
use MVO\Messenger\Protocol\PageMailboxConfig;

/** Native PHP DGW stream group with PAGE asset IDs and the existing account lock. */
final class PageMailboxGateway implements PageMailboxTransport
{
    private ?DgwConnection $connection = null;
    private ?\Closure $factory;
    private ?\Closure $ownerGuard;
    /** @param callable(string,string,array<string,string>):Socket|null $socketFactory
     * @param callable():void|null $ownerGuard Parent runtime callback/lifecycle guard.
     */
    public function __construct(private readonly SessionStore $owner, private readonly PageMailboxConfig $configuration,
        ?callable $socketFactory = null, ?callable $ownerGuard = null, private readonly float $timeoutSeconds = 20.0)
    {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $timeoutSeconds > 120) throw new \InvalidArgumentException('Invalid Page mailbox timeout');
        $this->factory = $socketFactory === null ? null : \Closure::fromCallable($socketFactory);
        $this->ownerGuard = $ownerGuard === null ? null : \Closure::fromCallable($ownerGuard);
    }
    public function config(): PageMailboxConfig { return $this->configuration; }
    /** @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function request(array $request): array
    {
        ($this->ownerGuard)?->__invoke();
        if (!$this->owner->ownsLock() || $this->owner->get('account_id') !== $this->configuration->accountId) throw new MessengerException('page_mailbox_owner_required', 'The Page mailbox requires the same active Messenger account owner');
        if (($request['app_id'] ?? null) !== $this->configuration->serverAppId || ($request['type'] ?? null) !== 3) throw new \InvalidArgumentException('The Page mailbox requires its current application and a task request');
        if ($this->connection === null) {
            $cookies = $this->owner->loadCookies() ?? throw new MessengerException('session_required', 'Restore the account before opening its Page mailbox');
            $cookie = $cookies->headerFor($this->configuration->origin . '/');
            if ($cookies->accountId() !== $this->configuration->accountId || !preg_match('/(?:^|; )c_user=([^;]+)/', $cookie, $user)
                || $user[1] !== $this->configuration->accountId || !preg_match('/(?:^|; )xs=[^;]+/', $cookie)) throw new MessengerException('session_domain_mismatch', 'The saved authenticated session does not cover the selected Page inbox origin');
            $headers = ['Cookie' => $cookie, 'User-Agent' => CurlHttpClient::USER_AGENT];
            $socket = $this->factory === null ? new WebSocket($this->configuration->gatewayUrl(), $this->configuration->origin, $headers, timeoutSeconds: $this->timeoutSeconds)
                : ($this->factory)($this->configuration->gatewayUrl(), $this->configuration->origin, $headers);
            if (!$socket instanceof Socket) throw new \UnexpectedValueException('The Page mailbox factory did not return a socket');
            $this->connection = new DgwConnection($socket); $this->connection->connect();
        }
        return $this->connection->request($request, $this->timeoutSeconds);
    }
    public function close(): void { $this->connection?->close(); $this->connection = null; }
    public function __destruct() { $this->close(); }
    /** @return array<string,mixed> */
    public function __debugInfo(): array { return ['page_id' => $this->configuration->pageId, 'transport' => 'page_asset_bound_dgw', 'session' => '[PRIVATE]']; }
}
