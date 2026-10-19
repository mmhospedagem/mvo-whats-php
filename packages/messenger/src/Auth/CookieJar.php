<?php
declare(strict_types=1);

namespace MVO\Messenger\Auth;

/** Server-issued cookies with their original scopes; private persistence boundary. */
final class CookieJar
{
    /** @var list<array{name:string,value:string,domain:string,path:string,secure:bool,expires:?int,hostOnly:bool}> */
    private array $cookies;
    private readonly string $account;

    /** @param list<array<string,mixed>> $cookies */
    public function __construct(#[\SensitiveParameter] array $cookies)
    {
        if ($cookies === [] || count($cookies) > 200) throw new \InvalidArgumentException('Import between 1 and 200 Facebook cookies');
        $this->cookies = [];
        $seen = [];
        foreach ($cookies as $cookie) {
            if (!is_array($cookie)) throw new \InvalidArgumentException('Expected Facebook cookie objects');
            $name = $cookie['name'] ?? $cookie['key'] ?? null;
            $value = $cookie['value'] ?? null;
            $domain = $cookie['domain'] ?? '.facebook.com';
            if (!is_string($domain)) throw new \InvalidArgumentException('Invalid Facebook cookie domain');
            $domain = strtolower(str_starts_with($domain, '.') ? substr($domain, 1) : $domain);
            $path = $cookie['path'] ?? '/';
            if (!is_string($name) || !preg_match('/^[!#$%&\'*+.^_`|~0-9a-zA-Z-]+$/D', $name) ||
                !is_string($value) || strlen($value) > 16384 || preg_match('/[\x00-\x20\x7f-\xff]/', $value) || strpbrk($value, "\"\\;,") !== false ||
                !self::allowedDomain($domain) || !is_string($path) || !str_starts_with($path, '/') || preg_match('/[\x00-\x20\x7f]/', $path)) {
                throw new \InvalidArgumentException('Invalid Facebook session cookie');
            }
            $expires = $cookie['expirationDate'] ?? $cookie['expires'] ?? null;
            if ($expires !== null && (!is_numeric($expires) || !is_finite((float) $expires))) throw new \InvalidArgumentException('Invalid cookie expiration');
            $expires = $expires === null || (float) $expires <= 0 ? null : (int) $expires;
            $key = $domain . "\0" . $path . "\0" . $name;
            if (isset($seen[$key])) throw new \InvalidArgumentException('Duplicate Facebook session cookie');
            $seen[$key] = true;
            $this->cookies[] = ['name'=>$name, 'value'=>$value, 'domain'=>$domain, 'path'=>$path,
                'secure'=>($cookie['secure'] ?? true) !== false, 'expires'=>$expires, 'hostOnly'=>($cookie['hostOnly'] ?? false) === true];
        }
        $user = $this->value('c_user');
        if ($user === null || !preg_match('/^[1-9][0-9]{0,24}$/D', $user) || $this->value('xs', false) === null) {
            throw new \InvalidArgumentException('Facebook session requires c_user and xs cookies');
        }
        $this->account = $user;
    }

    public static function fromJson(#[\SensitiveParameter] string $json): self
    {
        if (strlen($json) > 1048576) throw new \LengthException('Session import is too large');
        $cookies = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($cookies)) throw new \InvalidArgumentException('Expected a cookie array or object');
        if (!array_is_list($cookies)) {
            $cookies = array_map(static fn (string $name, mixed $value): array => ['name'=>$name, 'value'=>$value, 'domain'=>'.facebook.com'], array_keys($cookies), array_values($cookies));
        }
        foreach ($cookies as $cookie) if (!is_array($cookie)) throw new \InvalidArgumentException('Expected cookie objects');
        return new self($cookies);
    }

    public function accountId(): string { return $this->account; }

    /** Values are selected only for the exact request domain/path, never arbitrary redirects. */
    public function headerFor(string $url, ?int $now = null): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['https','wss'], true) ||
            isset($parts['user']) || isset($parts['pass']) || !self::allowedDomain(strtolower($parts['host']))) {
            throw new \InvalidArgumentException('Session cookies may only be sent to HTTPS Facebook/Messenger endpoints');
        }
        $host = strtolower($parts['host']); $path = $parts['path'] ?? '/'; $now ??= time();
        $selected = [];
        foreach ($this->cookies as $cookie) {
            $domainMatch = $host === $cookie['domain'] || (!$cookie['hostOnly'] && str_ends_with($host, '.' . $cookie['domain']));
            $cookiePath = $cookie['path'];
            $pathMatch = $path === $cookiePath || (str_starts_with($path, $cookiePath) && (str_ends_with($cookiePath, '/') || substr($path, strlen($cookiePath), 1) === '/'));
            if ($domainMatch && $pathMatch && ($cookie['expires'] === null || $cookie['expires'] > $now)) $selected[] = $cookie;
        }
        usort($selected, static fn (array $a, array $b): int => strlen($b['path']) <=> strlen($a['path']));
        return implode('; ', array_map(static fn (array $cookie): string => $cookie['name'] . '=' . $cookie['value'], $selected));
    }

    /** A deliberately explicit private-storage boundary. Never return this through an API. */
    public function exportPrivate(): string { return json_encode($this->cookies, JSON_THROW_ON_ERROR); }
    public function __debugInfo(): array { return ['accountId'=>$this->accountId(), 'cookies'=>'[REDACTED]']; }

    private function value(string $name, bool $requireUnique = true): ?string
    {
        $values = [];
        foreach ($this->cookies as $cookie) if ($cookie['name'] === $name && $cookie['value'] !== '' && ($cookie['expires'] === null || $cookie['expires'] > time())) $values[$cookie['value']] = true;
        // SSO may issue distinct xs tokens to Facebook and Messenger. Account
        // identity must be unique; authentication tokens remain domain scoped.
        if ($requireUnique && count($values) > 1) throw new \InvalidArgumentException('Ambiguous Facebook account cookies');
        return $values === [] ? null : (string) array_key_first($values);
    }
    private static function allowedDomain(string $domain): bool
    {
        if (strlen($domain) > 253 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $domain) !== 1) return false;
        return in_array($domain, ['facebook.com','messenger.com'], true) || str_ends_with($domain, '.facebook.com') || str_ends_with($domain, '.messenger.com');
    }
}
