<?php
declare(strict_types=1);
namespace MVO\Messenger\Transport;

use MVO\Messenger\Auth\CookieJar;
use MVO\Messenger\MessengerException;

/** Native PHP HTTP transport. Redirects never forward a session automatically. */
final class CurlHttpClient implements MultipartHttpClient,RawUploadHttpClient
{
    public const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
    public function __construct(private readonly float $timeout = 20.0, private readonly int $maxBytes = 33554432)
    {
        if (!is_finite($timeout) || $timeout <= 0 || $maxBytes < 1) throw new \InvalidArgumentException('Invalid HTTP limits');
    }
    public function request(string $method, string $url, #[\SensitiveParameter] CookieJar $cookies, #[\SensitiveParameter] array $form = []): HttpResponse
    {
        return $this->perform($method, $url, $cookies, $form);
    }
    /** @param array<string,string> $form
     * @param array<string,string> $headers */
    public function requestWithHeaders(string $method,string $url,#[\SensitiveParameter] CookieJar $cookies,#[\SensitiveParameter] array $form=[],array $headers=[]):HttpResponse
    {
        VideoUploadPolicy::validate($method,$url,$form,$headers);
        return $this->perform($method,$url,$cookies,$form,[],$headers);
    }
    public function uploadRange(string $url,#[\SensitiveParameter] CookieJar $cookies,UploadFile $file,int $offset,int $length,array $headers):HttpResponse
    {
        VideoUploadPolicy::validate('POST',$url,[],$headers,true);
        VideoUploadPolicy::validateRange($file,$offset,$length,$headers);
        $stream=$file->openRange($offset,$length);
        try{return $this->perform('POST',$url,$cookies,[],[],$headers,$stream,$length);}
        finally{fclose($stream);}
    }
    /** @param array<string,UploadFile> $files */
    public function upload(string $url, #[\SensitiveParameter] CookieJar $cookies, #[\SensitiveParameter] array $form, array $files): HttpResponse
    {
        if ($files === [] || count($files) > 10 || array_intersect_key($form, $files) !== []) throw new \InvalidArgumentException('Use distinct multipart fields and one to ten selected files');
        $total = 0;
        foreach ($files as $field => $file) {
            if (!is_string($field) || !preg_match('/^[A-Za-z][A-Za-z0-9_\[\]-]{0,127}$/D', $field) || !$file instanceof UploadFile) throw new \InvalidArgumentException('Invalid multipart file field');
            $file->sha256();
            $total += $file->size;
        }
        if ($total > 268435456) throw new \LengthException('The multipart upload exceeds 256 MiB');
        return $this->perform('POST', $url, $cookies, $form, $files);
    }
    /** @param array<string,string> $form
     * @param array<string,UploadFile> $files
     * @param array<string,string> $extraHeaders
     * @param resource|null $rawStream */
    private function perform(string $method, string $url, #[\SensitiveParameter] CookieJar $cookies, #[\SensitiveParameter] array $form, array $files = [],array $extraHeaders=[], $rawStream=null,int $rawLength=0): HttpResponse
    {
        if (!in_array($method,['GET','POST'],true)) throw new \InvalidArgumentException('Unsupported Messenger HTTP method');
        foreach ($form as $name => $value) if (!is_string($name) || !is_string($value)) throw new \InvalidArgumentException('HTTP form fields must be strings');
        $cookie = $cookies->headerFor($url);
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || (isset($parts['port']) && $parts['port'] !== 443)) throw new \InvalidArgumentException('Expected a verified HTTPS Facebook endpoint');
        if ($method === 'GET' && $form !== []) throw new \InvalidArgumentException('GET does not take a form body');
        // Bootstrap and post-login identity checks are document navigations.
        // The API-like header profile produces an interstitial/error on these
        // same public endpoints, before an authenticated page can be parsed.
        $requestHeaders=['Cookie: '.$cookie];
        $videoOrigin=in_array($parts['host']??'', ['vupload-edge.facebook.com','rupload.facebook.com'],true);
        if($method==='GET'&&$extraHeaders===[]&&!$videoOrigin){
            array_push($requestHeaders,
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8','Upgrade-Insecure-Requests: 1',
                'Sec-Fetch-Dest: document','Sec-Fetch-Mode: navigate','Sec-Fetch-Site: none','Sec-Fetch-User: ?1');
        }else{
            $pageOrigin=$files!==[]||$extraHeaders!==[]||$rawStream!==null||$videoOrigin?'https://www.facebook.com':'https://'.$parts['host'];
            array_push($requestHeaders,'Accept: text/html,application/json;q=0.9,*/*;q=0.8','Origin: '.$pageOrigin,'Referer: '.$pageOrigin.'/');
        }
        foreach($extraHeaders as $name=>$value)$requestHeaders[]=$name.': '.$value;
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
        if($rawStream!==null){
            $remaining=$rawLength;
            curl_setopt_array($handle,[CURLOPT_UPLOAD=>true,CURLOPT_CUSTOMREQUEST=>'POST',CURLOPT_INFILESIZE=>$rawLength,
                CURLOPT_READFUNCTION=>static function($handle,$unused,int $length)use($rawStream,&$remaining):string{
                    if($remaining===0)return '';
                    $chunk=fread($rawStream,min($length,$remaining));
                    if($chunk===false)throw new \RuntimeException('Unable to read the selected upload range');
                    $remaining-=strlen($chunk);return $chunk;
                }]);
        }elseif ($method==='POST') {
            $bodyFields = $form;
            foreach ($files as $field => $file) $bodyFields[$field] = $file->curlFile();
            curl_setopt($handle,CURLOPT_POSTFIELDS,$files === [] ? http_build_query($form,'','&',PHP_QUERY_RFC3986) : $bodyFields);
        }
        try {
            if (curl_exec($handle)===false) throw new MessengerException($tooLarge?'response_too_large':'http_transport_failed',$tooLarge?'Messenger bootstrap exceeds the configured size limit':'Facebook HTTPS request failed',!$tooLarge);
            return new HttpResponse((int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE),$body,$headers);
        } finally { unset($handle); }
    }
}
