<?php
declare(strict_types=1);

namespace MVO\Messenger\Transport;

use MVO\Messenger\MessengerException;

/** Fixed HTTPS login destinations. Application authorization is supplied by the caller. */
final class AndroidLoginCurlTransport implements AndroidLoginTransport
{
    private const USER_AGENT = 'MVO-Messenger-PHP/0.1';
    private const URL_METHODS = [
        'https://graph.facebook.com/pwd_key_fetch' => 'POST',
        'https://b-graph.facebook.com/pwd_key_fetch' => 'POST',
        'https://graph.facebook.com/auth/login' => 'POST',
        'https://b-graph.facebook.com/auth/login' => 'POST',
        'https://graph.facebook.com/check_approved_machine' => 'GET',
        'https://b-graph.facebook.com/check_approved_machine' => 'GET',
        'https://graph.facebook.com/me' => 'GET',
        'https://b-graph.facebook.com/me' => 'GET',
    ];
    private const SMS_URL_PATTERN = '~\Ahttps://(?:b-)?graph\.facebook\.com/[1-9][0-9]{0,24}/twofacsms\z~';
    private const HEADER_NAMES = [
        'authorization' => 'Authorization',
        'user-agent' => 'User-Agent',
        'accept-language' => 'Accept-Language',
        'x-fb-friendly-name' => 'X-FB-Friendly-Name',
    ];

    public function __construct(
        private readonly float $timeoutSeconds = 20.0,
        private readonly int $maxBytes = 2097152,
        private readonly int $maxHeaderBytes = 65536,
    ) {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $timeoutSeconds > 120 ||
            $maxBytes < 1 || $maxBytes > 2097152 || $maxHeaderBytes < 1 || $maxHeaderBytes > 65536) {
            throw new \InvalidArgumentException('Invalid Android login HTTP limits.');
        }
    }

    /**
     * @param array<string,string> $form
     * @param array<string,string> $headers
     */
    public function request(
        string $method,
        #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] array $form = [],
        #[\SensitiveParameter] array $headers = [],
    ): HttpResponse {
        self::validateUrl($url);
        // The only admitted variable route is the native SMS request, a POST.
        $sms = !isset(self::URL_METHODS[$url]);
        if ($method !== (self::URL_METHODS[$url] ?? 'POST')) {
            throw new \InvalidArgumentException('Invalid Android login HTTP method.');
        }
        if (str_ends_with($url, '/me') && $form !== []) {
            throw new \InvalidArgumentException('Native identity does not accept query fields.');
        }
        if ($method === 'GET' || $sms) foreach ($form as $name => $value) {
            if (is_string($name) && strtolower($name) === 'method') throw new \InvalidArgumentException('This native request does not accept a method override.');
        }
        $encoded = self::encodeForm($form);
        $requestHeaders = self::requestHeaders($headers);
        // Only an already admitted base URL may gain an internally encoded
        // query. A caller-provided query is never accepted by validateUrl().
        $requestUrl = $method === 'GET' && $encoded !== '' ? $url . '?' . $encoded : $url;

        $handle = curl_init();
        if ($handle === false) throw self::transportFailure();
        $body = ''; $responseHeaders = []; $headerBytes = 0; $tooLarge = false;
        $maxBytes = $this->maxBytes; $maxHeaderBytes = $this->maxHeaderBytes;
        try {
            $configured = curl_setopt_array($handle, [
                CURLOPT_URL => $requestUrl,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($this->timeoutSeconds * 1000),
                CURLOPT_TIMEOUT_MS => (int) ceil($this->timeoutSeconds * 1000),
                CURLOPT_NOSIGNAL => true, CURLOPT_VERBOSE => false,
                CURLOPT_RETURNTRANSFER => false, CURLOPT_HEADER => false,
                CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => $requestHeaders,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                    if (strlen($chunk) > $maxBytes - strlen($body)) { $tooLarge = true; return 0; }
                    $body .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders, &$headerBytes, &$tooLarge, $maxHeaderBytes): int {
                    $headerBytes += strlen($line);
                    if ($headerBytes > $maxHeaderBytes) { $tooLarge = true; return 0; }
                    if (str_starts_with($line, 'HTTP/')) $responseHeaders = [];
                    elseif (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $responseHeaders[strtolower(trim($name))][] = trim($value);
                    }
                    return strlen($line);
                },
            ]);
            if (!$configured) throw self::transportFailure();
            // Key fetch retains its observed POST + logical method=GET form.
            // Approval is a real GET and must never carry a POSTFIELDS body.
            $configured = $method === 'GET'
                ? curl_setopt($handle, CURLOPT_HTTPGET, true)
                : curl_setopt($handle, CURLOPT_POSTFIELDS, $encoded);
            if (!$configured) throw self::transportFailure();
            if (curl_exec($handle) === false) {
                if ($tooLarge) throw new MessengerException('login_response_too_large', 'A resposta de login excedeu o limite local.');
                throw self::transportFailure();
            }
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($status < 100 || $status > 599) throw self::transportFailure();
            return new HttpResponse($status, $body, $responseHeaders);
        } finally {
            // A new handle for every request keeps cookie state and credentials out of reuse.
            unset($handle);
        }
    }

    /** Only the observed native routes on their canonical, query-free HTTPS bases are admitted. */
    public static function validateUrl(#[\SensitiveParameter] string $url): void
    {
        if (!isset(self::URL_METHODS[$url]) && preg_match(self::SMS_URL_PATTERN, $url) !== 1) {
            throw new MessengerException('login_unsafe_redirect', 'O login tentou sair dos destinos HTTPS e rotas permitidos.');
        }
    }

    /** @param array<string,string> $form */
    private static function encodeForm(#[\SensitiveParameter] array $form): string
    {
        if (count($form) > 128) throw new \InvalidArgumentException('Invalid Android login form.');
        $bytes = 0;
        foreach ($form as $name => $value) {
            if (!is_string($name) || $name === '' || strlen($name) > 128 || preg_match('/[\x00-\x20\x7f]/', $name) ||
                !is_string($value) || strlen($value) > 1048576) {
                throw new \InvalidArgumentException('Invalid Android login form.');
            }
            $bytes += strlen($name) + strlen($value);
            if ($bytes > 2097152) throw new \InvalidArgumentException('Invalid Android login form.');
        }
        $encoded = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        if (strlen($encoded) > 2097152) throw new \InvalidArgumentException('Invalid Android login form.');
        return $encoded;
    }

    /**
     * @param array<string,string> $headers
     * @return list<string>
     */
    private static function requestHeaders(#[\SensitiveParameter] array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value)) throw new \InvalidArgumentException('Invalid Android login headers.');
            $key = strtolower($name);
            if (!isset(self::HEADER_NAMES[$key]) || isset($normalized[$key]) || $value === '' || strlen($value) > 8192 ||
                preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new \InvalidArgumentException('Invalid Android login headers.');
            }
            $normalized[$key] = $value;
        }
        $normalized['user-agent'] ??= self::USER_AGENT;
        $lines = ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'];
        foreach ($normalized as $name => $value) $lines[] = self::HEADER_NAMES[$name] . ': ' . $value;
        return $lines;
    }

    private static function transportFailure(): MessengerException
    {
        return new MessengerException('login_transport_failed', 'A requisição HTTPS de login não foi concluída.');
    }

    /** @return array<string,int|float> */
    public function __debugInfo(): array
    {
        return ['timeoutSeconds' => $this->timeoutSeconds, 'maxBytes' => $this->maxBytes, 'maxHeaderBytes' => $this->maxHeaderBytes];
    }
}
