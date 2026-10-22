<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\Protocol\Jid;

/** Read-only public application catalog query. Never uses the account's private SMB token. */
final class BusinessFormCatalogHttpTransport
{
    public const ENDPOINT = 'https://graph.whatsapp.com/graphql/catalog';
    public const DOCUMENT_ID = '24989855014035777';
    public const MAX_BYTES = 2097152;
    private \Closure $tokenProvider;
    private ?\Closure $authorizationGuard;

    /** @param callable():string $accessTokenProvider Application catalog configuration, supplied in memory.
     * @param (\Closure(string,list<string>,string,float):array{status:int,body:string})|null $exchange
     * @param callable():void|null $authorizationGuard Fresh local authorization, including after HTTP. */
    public function __construct(callable $accessTokenProvider, private readonly string $language = 'pt_BR', private readonly ?string $proxy = null, private readonly ?\Closure $exchange = null, ?callable $authorizationGuard = null)
    {
        if (PHP_INT_SIZE < 8) throw new \LogicException('Galaxy catalog requires 64-bit PHP');
        if (preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/D', $language) !== 1) throw new \InvalidArgumentException('Invalid Galaxy catalog language');
        if ($proxy !== null) {
            $parts = parse_url($proxy);
            if ($proxy === '' || preg_match('/[\x00-\x20\x7f]/', $proxy) || !is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https', 'socks5', 'socks5h'], true) || !is_string($parts['host'] ?? null) || $parts['host'] === '' || isset($parts['query']) || isset($parts['fragment'])) throw new \InvalidArgumentException('Invalid explicit Galaxy catalog proxy');
        }
        $this->tokenProvider = \Closure::fromCallable($accessTokenProvider);
        $this->authorizationGuard = $authorizationGuard === null ? null : \Closure::fromCallable($authorizationGuard);
    }

    /** The fixed metadata query preserves original data/null; signatures are not verified here.
     * @return array<string,mixed>|null */
    public function getFlowData(string $businessJid, string $flowId, float $timeout = 32.0): ?array
    {
        $jid = Jid::parse($businessJid);
        if ($jid->device !== 0 || !in_array($jid->server, ['s.whatsapp.net', 'lid'], true) || !ctype_digit($jid->user)) throw new \InvalidArgumentException('Galaxy metadata requires a bare business PN or LID JID');
        if ($flowId === '' || !ctype_digit($flowId) || strlen($flowId) > 64) throw new \InvalidArgumentException('flow_id must be a numeric string of at most 64 digits');
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 32.0) throw new \InvalidArgumentException('Galaxy catalog timeout must be greater than zero and at most 32 seconds');
        ($this->authorizationGuard)?->__invoke();
        try { $token = ($this->tokenProvider)(); }
        catch (\Throwable) {
            ($this->authorizationGuard)?->__invoke();
            throw new \LogicException('Business form catalog token provider is unavailable or not configured');
        }
        ($this->authorizationGuard)?->__invoke();
        if (!is_string($token) || $token === '' || strlen($token) > 65536 || preg_match('/[\x00-\x20\x7f]/', $token) || preg_match('//u', $token) !== 1) throw new \LogicException('A valid application catalog access token must be supplied explicitly');
        $body = json_encode([
            'access_token' => $token,
            'doc_id' => self::DOCUMENT_ID,
            'variables' => ['request' => ['extensions' => ['biz_jid' => $businessJid, 'flow_id' => $flowId]]],
            'lang' => $this->language,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, 128);
        unset($token);
        if (strlen($body) > self::MAX_BYTES) throw new \LengthException('Galaxy catalog request exceeds the SDK 2 MiB limit');
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'Accept-Encoding: gzip'];
        ($this->authorizationGuard)?->__invoke();
        try { $reply = $this->exchange === null ? $this->curl($headers, $body, $timeout) : ($this->exchange)(self::ENDPOINT, $headers, $body, $timeout); }
        catch (\Throwable) {
            ($this->authorizationGuard)?->__invoke();
            throw new \RuntimeException('Galaxy catalog transfer has an unconfirmed outcome');
        }
        finally { unset($body); }
        ($this->authorizationGuard)?->__invoke();
        if (!is_int($reply['status'] ?? null) || $reply['status'] < 100 || $reply['status'] > 599 || !is_string($reply['body'] ?? null) || strlen($reply['body']) > self::MAX_BYTES) throw new \RuntimeException('Invalid or oversized Galaxy catalog response');
        $status = $reply['status'];
        if ($status >= 500) throw new \RuntimeException('Galaxy catalog server failure has an unconfirmed outcome');
        try { $envelope = json_decode($reply['body'], false, 128, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
        catch (\JsonException) {
            if ($status >= 300) throw new ServiceException('galaxy.getFlowData', (string) $status, 'Galaxy catalog metadata request was refused');
            throw new \RuntimeException('Galaxy catalog response has an unconfirmed format');
        }
        if (!$envelope instanceof \stdClass) {
            if ($status >= 300) throw new ServiceException('galaxy.getFlowData', (string) $status, 'Galaxy catalog metadata request was refused');
            throw new \RuntimeException('Galaxy catalog response must be an object');
        }
        $hasData = property_exists($envelope, 'data') && $envelope->data !== null;
        $hasError = (property_exists($envelope, 'error') && $envelope->error !== null) || (property_exists($envelope, 'errors') && $envelope->errors !== null && $envelope->errors !== []);
        if ($hasData && $hasError) throw new \RuntimeException('Galaxy catalog returned a partial result; metadata is not confirmed');
        if ($hasError || $status >= 300) throw new ServiceException('galaxy.getFlowData', self::errorCode($envelope, $status), 'Galaxy catalog metadata request was refused');
        if ($status !== 200 || !property_exists($envelope, 'data') || ($envelope->data !== null && !$envelope->data instanceof \stdClass)) throw new \RuntimeException('Galaxy catalog did not return confirmed data');
        return $envelope->data === null ? null : self::object($envelope->data);
    }

    /** @param list<string> $headers
     * @return array{status:int,body:string} */
    private function curl(array $headers, string $body, float $timeout): array
    {
        $curl = curl_init(self::ENDPOINT);
        if ($curl === false) throw new \RuntimeException('Cannot initialize Galaxy catalog transport');
        $received = ''; $headerBytes = 0;
        try {
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT_MS => (int) min(10000, max(1, ceil($timeout * 1000))),
                CURLOPT_TIMEOUT_MS => (int) max(1, ceil($timeout * 1000)), CURLOPT_PROXY => $this->proxy ?? '', CURLOPT_ENCODING => 'gzip',
                CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$headerBytes): int { $headerBytes += strlen($line); return $headerBytes > 65536 ? 0 : strlen($line); },
                CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use (&$received): int { if (strlen($received) + strlen($chunk) > self::MAX_BYTES) return 0; $received .= $chunk; return strlen($chunk); },
            ]);
            if (curl_exec($curl) === false) throw new \RuntimeException('Galaxy catalog HTTPS result is unconfirmed');
            return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $received];
        } finally { curl_close($curl); }
    }

    private static function errorCode(\stdClass $envelope, int $status): ?string
    {
        $error = $envelope->error ?? (is_array($envelope->errors ?? null) ? ($envelope->errors[0] ?? null) : null); $code = null;
        if ($error instanceof \stdClass) { $extensions = $error->extensions ?? null; $code = $error->code ?? ($extensions instanceof \stdClass ? ($extensions->error_code ?? $extensions->code ?? null) : null); }
        if ((is_int($code) || is_string($code)) && preg_match('/^-?[0-9]{1,10}$/D', (string) $code) === 1) return (string) $code;
        return $status >= 300 && $status < 500 ? (string) $status : null;
    }
    /** @return array<string,mixed> */
    private static function object(\stdClass $object): array
    {
        $out = []; foreach (get_object_vars($object) as $key => $value) $out[$key] = self::value($value); return $out;
    }
    private static function value(mixed $value): mixed
    {
        if ($value instanceof \stdClass) return self::object($value);
        return is_array($value) ? array_map(self::value(...), $value) : $value;
    }
}
