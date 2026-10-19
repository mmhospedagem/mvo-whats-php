<?php
declare(strict_types=1);
namespace MVO\Messenger\Transport;

use MVO\Messenger\Auth\CookieJar;
use MVO\Messenger\MessengerException;

/** Native PHP HTTP transport. Redirects never forward a session automatically. */
final class CurlHttpClient implements HttpClient
{
    public const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
    public function __construct(private readonly float $timeout = 20.0, private readonly int $maxBytes = 33554432)
    {
        if (!is_finite($timeout) || $timeout <= 0 || $maxBytes < 1) throw new \InvalidArgumentException('Invalid HTTP limits');
    }
    public function request(string $method, string $url, #[\SensitiveParameter] CookieJar $cookies, #[\SensitiveParameter] array $form = []): HttpResponse
    {
        if (!in_array($method,['GET','POST'],true)) throw new \InvalidArgumentException('Unsupported Messenger HTTP method');
        $cookie = $cookies->headerFor($url);
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || (isset($parts['port']) && $parts['port'] !== 443)) throw new \InvalidArgumentException('Expected a verified HTTPS Facebook endpoint');
        if ($method === 'GET' && $form !== []) throw new \InvalidArgumentException('GET does not take a form body');
        // Bootstrap and post-login identity checks are document navigations.
        // The API-like header profile produces an interstitial/error on these
        // same public endpoints, before an authenticated page can be parsed.
        $requestHeaders=['Cookie: '.$cookie];
        if($method==='GET'){
            array_push($requestHeaders,
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8','Upgrade-Insecure-Requests: 1',
                'Sec-Fetch-Dest: document','Sec-Fetch-Mode: navigate','Sec-Fetch-Site: none','Sec-Fetch-User: ?1');
        }else{
            array_push($requestHeaders,'Accept: text/html,application/json;q=0.9,*/*;q=0.8','Origin: https://'.$parts['host'],'Referer: https://'.$parts['host'].'/');
        }
        $handle = curl_init($url);
        if ($handle === false) throw new \RuntimeException('Unable to initialize HTTP');
        $body = ''; $headers = []; $tooLarge = false; $headerBytes = 0;
        curl_setopt_array($handle,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_CONNECTTIMEOUT_MS=>(int)ceil($this->timeout*1000),CURLOPT_TIMEOUT_MS=>(int)ceil($this->timeout*1000),
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>self::USER_AGENT,
            CURLOPT_HTTPHEADER=>$requestHeaders,
            CURLOPT_WRITEFUNCTION=>function ($handle,string $chunk) use (&$body,&$tooLarge):int {
                if (strlen($body)+strlen($chunk)>$this->maxBytes) { $tooLarge=true; return 0; }
                $body.=$chunk; return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION=>static function ($handle,string $line) use (&$headers,&$headerBytes,&$tooLarge):int {
                $headerBytes += strlen($line);
                if ($headerBytes > 65536) { $tooLarge = true; return 0; }
                if (str_starts_with($line,'HTTP/')) $headers=[];
                elseif (str_contains($line,':')) { [$name,$value]=explode(':',$line,2); $headers[strtolower(trim($name))][]=trim($value); }
                return strlen($line);
            }]);
        if ($method==='POST') curl_setopt($handle,CURLOPT_POSTFIELDS,http_build_query($form,'','&',PHP_QUERY_RFC3986));
        try {
            if (curl_exec($handle)===false) throw new MessengerException($tooLarge?'response_too_large':'http_transport_failed',$tooLarge?'Messenger bootstrap exceeds the configured size limit':'Facebook HTTPS request failed',!$tooLarge);
            return new HttpResponse((int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE),$body,$headers);
        } finally { unset($handle); }
    }
}
