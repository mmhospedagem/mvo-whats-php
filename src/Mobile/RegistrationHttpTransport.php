<?php
declare(strict_types=1);
namespace MVO\Mobile;

/** Fixed-origin verified TLS registration transport; no automatic request replay. */
final class RegistrationHttpTransport
{
    private const ORIGIN='https://v.whatsapp.net';
    /** @param \Closure(string):array{signature:string,certificateChain:string}|null $attestation Genuine external Android keystore provider; no fabricated proof is generated.
     * @param \Closure(string,list<string>,string):RegistrationResponse|null $exchange Isolated HTTP fixture injection. The default always uses the fixed verified TLS origin. */
    public function __construct(private readonly ?\Closure $attestation=null,private readonly ?\Closure $exchange=null) {}
    public function send(RegistrationRequest $request):RegistrationResponse
    {
        return ($this->prepare($request))();
    }
    /** Builds and validates the entire body before the caller commits an in-flight attempt.
     * @return \Closure():RegistrationResponse */
    public function prepare(RegistrationRequest $request):\Closure
    {
        $encoded=RegistrationWire::seal($request->parameters);$signature='';$headers=['Content-Type: application/x-www-form-urlencoded','Accept: application/json','User-Agent: '.$this->userAgent($request->deviceInfo)];
        if($this->attestation!==null){
            $proof=($this->attestation)($encoded);
            if(!is_array($proof) || !is_string($proof['signature']??null) || !is_string($proof['certificateChain']??null) || $proof['signature']==='' || strlen($proof['signature'])>16384 || $proof['certificateChain']==='' || strlen($proof['certificateChain'])>65536)throw new \UnexpectedValueException('The genuine Android attestation provider returned an invalid proof');
            $signature=RegistrationWire::base64url($proof['signature']);$headers[]='Authorization: '.base64_encode($proof['certificateChain']);
        }
        $body='ENC='.rawurlencode($encoded).'&H='.rawurlencode($signature);$path=match($request->operation){'code'=>'/v2/code','register'=>'/v2/register','security'=>'/v2/security',default=>throw new \InvalidArgumentException('Unknown registration operation')};
        return fn():RegistrationResponse=>$this->exchange===null?$this->http(self::ORIGIN.$path,$headers,$body):($this->exchange)(self::ORIGIN.$path,$headers,$body);
    }
    /** @param array<string,mixed> $info */
    private function userAgent(array $info):string
    {
        if(($info['os']??'android')!=='android')throw new \InvalidArgumentException('The mobile registration transport currently supports Android');
        $parts=[];foreach(['appVersion','osVersion','manufacturer','device'] as$key){$value=$info[$key]??null;if(!is_string($value) || $value==='' || strlen($value)>256 || preg_match('/[\x00-\x1f\x7f]/',$value))throw new \InvalidArgumentException('Invalid Android registration user agent');$parts[$key]=$value;}
        foreach(['osVersion','manufacturer','device'] as$key)$parts[$key]=preg_replace('/[^,.A-Za-z0-9_()\-]/','_',$parts[$key]);
        return 'WhatsApp/'.str_replace(' ','_',$parts['appVersion']).' Android/'.$parts['osVersion'].' Device/'.$parts['manufacturer'].'-'.$parts['device'];
    }
    /** @param list<string> $headers */
    private function http(string $url,array $headers,string $body):RegistrationResponse
    {
        $curl=curl_init($url);if($curl===false)throw new \RuntimeException('Cannot initialize registration HTTPS transport');$received='';$headerBytes=0;$retryAfter=0;
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_CONNECTTIMEOUT_MS=>10000,CURLOPT_TIMEOUT_MS=>30000,CURLOPT_PROXY=>'',CURLOPT_ENCODING=>'',
            CURLOPT_HEADERFUNCTION=>static function(\CurlHandle $handle,string $line)use(&$headerBytes,&$retryAfter):int{
                $headerBytes+=strlen($line);if($headerBytes>98304)return 0;if(preg_match('/^Retry-After:\s*([0-9]{1,8})\s*\r\n$/Di',$line,$match))$retryAfter=min(31536000,(int)$match[1]);return strlen($line);
            },CURLOPT_WRITEFUNCTION=>static function(\CurlHandle $handle,string $chunk)use(&$received):int{if(strlen($received)+strlen($chunk)>262144)return 0;$received.=$chunk;return strlen($chunk);}]);
        try{
            if(curl_exec($curl)===false)throw new \RuntimeException('Registration HTTPS transfer did not produce a confirmed result');$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            $decoded=json_decode($received,true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);if(!is_array($decoded) || array_is_list($decoded))throw new \UnexpectedValueException('Registration HTTPS response is not a JSON object');
            return new RegistrationResponse($decoded,$status,$retryAfter);
        }finally{curl_close($curl);}
    }
}
