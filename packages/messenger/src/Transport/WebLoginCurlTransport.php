<?php
declare(strict_types=1);

namespace MVO\Messenger\Transport;

use MVO\Messenger\MessengerException;

/** Native cURL with an in-memory-only cookie jar. Redirects are decided by the login service. */
final class WebLoginCurlTransport implements WebLoginTransport
{
    private ?\CurlHandle $handle = null;

    public function __construct(private readonly float $timeoutSeconds = 20.0, private readonly int $maxBytes = 16777216)
    {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $timeoutSeconds > 120 || $maxBytes < 1) throw new \InvalidArgumentException('Invalid login HTTP limits');
    }

    /** @param array<string,string> $form */
    public function request(string $method, #[\SensitiveParameter] string $url, #[\SensitiveParameter] array $form = []): WebLoginResponse
    {
        self::validateUrl($url);
        if (!in_array($method, ['GET', 'POST'], true) || ($method === 'GET' && $form !== [])) throw new \InvalidArgumentException('Invalid login HTTP method');
        foreach ($form as $name => $value) if (!is_string($name) || !is_string($value)) throw new \InvalidArgumentException('Invalid login form');
        if ($this->handle === null) {
            $handle = curl_init();
            if ($handle === false) throw new MessengerException('login_transport_failed', 'Não foi possível iniciar o transporte de login.');
            $this->handle = $handle;
        }
        $handle = $this->handle;
        curl_reset($handle); // cURL preserves its cookie engine while resetting request options.
        $body = ''; $headers = []; $headerBytes = 0; $tooLarge = false;
        $origin = 'https://' . parse_url($url, PHP_URL_HOST);
        // The public login page is a document navigation. Sending the API-like
        // Accept/Origin profile returns an error interstitial, without a form.
        $requestHeaders = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
            'Upgrade-Insecure-Requests: 1',
            'Sec-Fetch-Dest: document', 'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: ' . ($method === 'POST' ? 'same-origin' : 'none'),
            'Sec-Fetch-User: ?1',
        ];
        if ($method === 'POST') {
            $requestHeaders[] = 'Origin: ' . $origin;
            $requestHeaders[] = 'Referer: ' . $origin . '/';
        }
        curl_setopt_array($handle, [
            CURLOPT_URL => $url, CURLOPT_COOKIEFILE => '',
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_RETURNTRANSFER => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($this->timeoutSeconds * 1000),
            CURLOPT_TIMEOUT_MS => (int) ceil($this->timeoutSeconds * 1000),
            CURLOPT_ENCODING => '', CURLOPT_USERAGENT => CurlHttpClient::USER_AGENT,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > $this->maxBytes) { $tooLarge = true; return 0; }
                $body .= $chunk; return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers, &$headerBytes, &$tooLarge): int {
                $headerBytes += strlen($line);
                if ($headerBytes > 65536) { $tooLarge = true; return 0; }
                if (str_starts_with($line, 'HTTP/')) $headers = [];
                elseif (str_contains($line, ':')) { [$name, $value] = explode(':', $line, 2); $headers[strtolower(trim($name))][] = trim($value); }
                return strlen($line);
            },
        ]);
        if ($method === 'POST') curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($form, '', '&', PHP_QUERY_RFC3986));
        else curl_setopt($handle, CURLOPT_HTTPGET, true);
        if (curl_exec($handle) === false) throw new MessengerException($tooLarge ? 'login_response_too_large' : 'login_transport_failed', $tooLarge ? 'A resposta de login excedeu o limite local.' : 'A requisição HTTPS de login não foi concluída.');
        return new WebLoginResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body, $headers);
    }

    public static function validateUrl(#[\SensitiveParameter] string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' ||
            !in_array($parts['host'] ?? '', ['www.messenger.com', 'messenger.com', 'www.facebook.com', 'facebook.com'], true) ||
            (isset($parts['port']) && $parts['port'] !== 443) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) ||
            preg_match('/[\x00-\x20\x7f]/', $url) || strlen($url) > 8192) {
            throw new MessengerException('login_unsafe_redirect', 'O login tentou sair dos destinos HTTPS Facebook/Messenger permitidos.');
        }
    }

    /** @return list<array<string,mixed>> */
    public function cookies(): array
    {
        if ($this->handle === null) return [];
        $rows = curl_getinfo($this->handle, CURLINFO_COOKIELIST);
        if (!is_array($rows)) return [];
        $cookies = [];
        foreach ($rows as $row) {
            if (!is_string($row)) continue;
            $httpOnly=str_starts_with($row, '#HttpOnly_');
            if ($httpOnly) $row = substr($row, 10);
            if ($row === '' || $row[0] === '#') continue;
            $fields = explode("\t", $row);
            if (count($fields) !== 7) continue;
            [$domain, $subdomains, $path, $secure, $expiry, $name, $value] = $fields;
            $cookies[] = ['name' => $name, 'value' => $value, 'domain' => $domain, 'path' => $path,
                'secure' => $secure === 'TRUE', 'hostOnly' => $subdomains !== 'TRUE', 'httpOnly'=>$httpOnly,'expires' => ctype_digit($expiry) && $expiry !== '0' ? (int) $expiry : null];
        }
        return $cookies;
    }

    public function clear(): void { $this->handle = null; }
    public function __destruct() { $this->clear(); }
    public function __debugInfo(): array { return ['cookieJar' => '[PRIVATE, MEMORY ONLY]']; }
}
