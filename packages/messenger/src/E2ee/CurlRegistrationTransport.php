<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee;

use MVO\Messenger\MessengerException;
use MVO\Messenger\Transport\{CurlHttpClient,HttpResponse};

/** Fixed HTTPS endpoints; no cookies, redirects, unverified TLS or automatic retry. */
final class CurlRegistrationTransport implements RegistrationTransport
{
    public function __construct(private readonly string $origin, private readonly float $timeout = 20.0)
    {
        if (!in_array($origin, ['https://www.facebook.com', 'https://www.messenger.com'], true) || !is_finite($timeout) || $timeout <= 0 || $timeout > 120) throw new \InvalidArgumentException('Invalid Messenger device transport');
    }
    public function request(string $operation, #[\SensitiveParameter] array $form): HttpResponse
    {
        $path = match ($operation) { 'fetch' => '/v2/fb_icdc_fetch', 'register' => '/v2/fb_register_v2', default => throw new \InvalidArgumentException('Unknown Messenger device operation') };
        $handle = curl_init('https://reg-e2ee.facebook.com' . $path);
        if ($handle === false) throw new MessengerException('messenger_device_transport_failed', 'Could not initialize Messenger registration HTTPS');
        $body = ''; $tooLarge = false;
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_RETURNTRANSFER => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT_MS => (int)ceil($this->timeout * 1000), CURLOPT_TIMEOUT_MS => (int)ceil($this->timeout * 1000),
            CURLOPT_USERAGENT => CurlHttpClient::USER_AGENT, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                'Origin: ' . $this->origin, 'Referer: ' . $this->origin . '/messages/', 'Sec-Fetch-Dest: empty', 'Sec-Fetch-Mode: cors', 'Sec-Fetch-Site: cross-site'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > 1048576) { $tooLarge = true; return 0; } $body .= $chunk; return strlen($chunk);
            }]);
        try {
            if (curl_exec($handle) === false) throw new MessengerException($tooLarge ? 'messenger_device_response_too_large' : 'messenger_device_transport_failed', 'Messenger registration HTTPS did not complete');
            return new HttpResponse((int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body, []);
        } finally { unset($handle); }
    }
}
