<?php
declare(strict_types=1);
namespace MVO\Testing;

/** Bounded real cURL transport with an explicit test CA and loopback connection mapping. */
final class FakeMediaClient
{
    public function __construct(private readonly string $caFile,private readonly string $address)
    {
        if(!is_file($caFile)||!is_readable($caFile))throw new \InvalidArgumentException('Fake media CA file is not readable');
        if(!preg_match('/^127\.0\.0\.1:([1-9][0-9]{0,4})$/D',$address,$match)||(int)$match[1]>65535)throw new \InvalidArgumentException('Fake media connection must map to loopback');
    }
    /** @param array<string,mixed> $request
     * @return array{status:int,body:string} */
    public function __invoke(array $request):array
    {
        $url=$request['url'] ?? null;$parts=is_string($url)?parse_url($url):false;
        if($parts===false||($parts['scheme'] ?? '')!=='https'||($parts['host'] ?? '')!=='localhost'||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])||isset($parts['fragment']))throw new \InvalidArgumentException('Fake media client accepts only its explicit localhost HTTPS origin');
        $method=$request['method'] ?? '';if(!in_array($method,['GET','POST'],true))throw new \InvalidArgumentException('Invalid fake media request method');
        $limit=$request['maxBytes'] ?? null;if(!is_int($limit)||$limit<1||$limit>268435456)throw new \InvalidArgumentException('Invalid fake media response bound');
        $body=$request['body'] ?? '';if(is_resource($body)){$body=stream_get_contents($body,33554459);if($body===false)throw new \RuntimeException('Cannot read fake media upload');}
        if(!is_string($body)||strlen($body)>33554458)throw new \LengthException('Fake media upload bound');
        $response='';$curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CAINFO=>$this->caFile,CURLOPT_CONNECT_TO=>['localhost:443:'.$this->address],CURLOPT_PROXY=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10,CURLOPT_WRITEFUNCTION=>static function(\CurlHandle $handle,string $chunk)use(&$response,$limit):int {if(strlen($response)+strlen($chunk)>$limit)return 0;$response.=$chunk;return strlen($chunk);}]);
        if($method==='POST')curl_setopt_array($curl,[CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/octet-stream','Expect:']]);
        try {if(curl_exec($curl)===false)throw new \RuntimeException('Fake media HTTPS transfer failed: '.curl_error($curl));return ['status'=>curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$response];}
        finally {curl_close($curl);}
    }
}
