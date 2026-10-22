<?php
declare(strict_types=1);
namespace MVO\Service;

/** Native Business JSON GraphQL transport. Tokens are supplied explicitly and never persisted here. */
final class BusinessFormHttpTransport
{
    public const ENDPOINT='https://graph.facebook.com/graphql';
    public const MAX_BYTES=2097152;
    private \Closure $tokenProvider;
    private ?\Closure $authorizationGuard;
    /** @param callable():string $accessTokenProvider
     * @param (\Closure(string,list<string>,string,float):array{status:int,body:string})|null $exchange
     * @param callable():void|null $authorizationGuard Fresh local authorization before auth and HTTP; throws on withdrawal. */
    public function __construct(callable $accessTokenProvider,private readonly string $language='pt_BR',private readonly ?string $proxy=null,private readonly ?\Closure $exchange=null,private readonly float $timeout=30.0,?callable $authorizationGuard=null)
    {
        if(PHP_INT_SIZE<8)throw new \LogicException('Business GraphQL requires 64-bit PHP');
        if(preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/D',$language)!==1)throw new \InvalidArgumentException('Invalid Business GraphQL language');
        if(!is_finite($timeout)||$timeout<=0||$timeout>300)throw new \InvalidArgumentException('Business GraphQL timeout must be greater than zero and at most 300 seconds');
        if($proxy!==null){$parts=parse_url($proxy);if($proxy===''||preg_match('/[\x00-\x20\x7f]/',$proxy)||!is_array($parts)||!in_array(strtolower($parts['scheme']??''),['http','https','socks5','socks5h'],true)||!is_string($parts['host']??null)||$parts['host']===''||isset($parts['query'])||isset($parts['fragment']))throw new \InvalidArgumentException('Invalid explicit Business GraphQL proxy');}
        $this->tokenProvider=\Closure::fromCallable($accessTokenProvider);
        $this->authorizationGuard=$authorizationGuard===null?null:\Closure::fromCallable($authorizationGuard);
    }

    /** Returns GraphQL data; errors, partial outcomes and HTTP refusals never become success.
     * @param array<string,mixed> $variables
     * @return array<string,mixed> */
    public function request(string $docId,array $variables):array
    {
        if(!in_array($docId,[BusinessFormGraphqlService::PUBLISH_DOC_ID,BusinessFormGraphqlService::GENERATE_DOC_ID],true))throw new \InvalidArgumentException('Unsupported Business form GraphQL document');
        if($variables!==[]&&array_is_list($variables))throw new \InvalidArgumentException('GraphQL variables must be an object');
        foreach(array_keys($variables)as$key)if(!is_string($key)||preg_match('/^[_A-Za-z][_0-9A-Za-z]*$/D',$key)!==1)throw new \InvalidArgumentException('Invalid GraphQL variable name');
        try{$encodedVariables=json_encode($variables===[]?new \stdClass():$variables,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES,128);}catch(\JsonException $error){throw new \InvalidArgumentException('Invalid Business GraphQL variables',0,$error);}
        if(strlen($encodedVariables)>self::MAX_BYTES)throw new \LengthException('Business GraphQL request exceeds the SDK 2 MiB limit');
        ($this->authorizationGuard)?->__invoke();
        try{$token=($this->tokenProvider)();}
        catch(ServiceException $error){($this->authorizationGuard)?->__invoke();throw new ServiceException('business.forms.authentication',self::safeCode($error->serverCode),'Business form authentication was refused');}
        catch(\Throwable){($this->authorizationGuard)?->__invoke();throw new \RuntimeException('Business GraphQL access token is unavailable');}
        ($this->authorizationGuard)?->__invoke();
        if(!is_string($token)||$token===''||strlen($token)>65536||preg_match('/[\x00-\x20\x7f]/',$token)||preg_match('//u',$token)!==1)throw new \LogicException('A real Business GraphQL access token must be supplied explicitly');
        $body=json_encode(['variables'=>$variables===[]?new \stdClass():$variables,'access_token'=>$token,'doc_id'=>(int)$docId,'lang'=>$this->language,'Content-Type'=>'application/json'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES,128);unset($token);
        if(strlen($body)>self::MAX_BYTES)throw new \LengthException('Business GraphQL request exceeds the SDK 2 MiB limit');
        $headers=['Content-Type: application/json','Accept: application/json','Accept-Encoding: gzip'];
        ($this->authorizationGuard)?->__invoke();
        try{$reply=$this->exchange===null?$this->curl($headers,$body):($this->exchange)(self::ENDPOINT,$headers,$body,$this->timeout);}catch(\Throwable){throw new \RuntimeException('Business GraphQL transfer has an unconfirmed outcome');}finally{unset($body);}
        if(!is_int($reply['status']??null)||$reply['status']<100||$reply['status']>599||!is_string($reply['body']??null)||strlen($reply['body'])>self::MAX_BYTES)throw new \RuntimeException('Invalid or oversized Business GraphQL response');
        $status=$reply['status'];
        if($status>=500)throw new \RuntimeException('Business GraphQL server failure has an unconfirmed outcome');
        try{$envelope=json_decode($reply['body'],false,128,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException){
            if($status>=400)throw new ServiceException('business.forms.graphql',(string)$status,'Business GraphQL request was refused');
            throw new \RuntimeException('Business GraphQL response has an unconfirmed format');
        }
        if(!$envelope instanceof \stdClass){if($status>=400)throw new ServiceException('business.forms.graphql',(string)$status,'Business GraphQL request was refused');throw new \RuntimeException('Business GraphQL response must be an object');}
        $hasData=property_exists($envelope,'data')&&$envelope->data!==null;
        $hasError=(property_exists($envelope,'error')&&$envelope->error!==null)||(property_exists($envelope,'errors')&&$envelope->errors!==null&&$envelope->errors!==[]);
        if($hasData&&$hasError)throw new \RuntimeException('Business GraphQL returned a partial result; operation outcome requires reconciliation');
        if($hasError||$status>=400)throw new ServiceException('business.forms.graphql',self::errorCode($envelope,$status),'Business GraphQL request was refused',self::errorKind($envelope));
        if($status!==200||!$hasData||!$envelope->data instanceof \stdClass)throw new \RuntimeException('Business GraphQL did not return confirmed data');
        return self::object($envelope->data);
    }

    /** @param list<string> $headers
     * @return array{status:int,body:string} */
    private function curl(array $headers,string $body):array
    {
        $curl=curl_init(self::ENDPOINT);if($curl===false)throw new \RuntimeException('Cannot initialize Business GraphQL transport');$received='';$headerBytes=0;
        try{
            curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT_MS=>(int)min(10000,max(1,ceil($this->timeout*1000))),CURLOPT_TIMEOUT_MS=>(int)max(1,ceil($this->timeout*1000)),CURLOPT_PROXY=>$this->proxy??'',CURLOPT_ENCODING=>'gzip',
                CURLOPT_HEADERFUNCTION=>static function(\CurlHandle $handle,string $line)use(&$headerBytes):int{$headerBytes+=strlen($line);return $headerBytes>65536?0:strlen($line);},
                CURLOPT_WRITEFUNCTION=>static function(\CurlHandle $handle,string $chunk)use(&$received):int{if(strlen($received)+strlen($chunk)>self::MAX_BYTES)return 0;$received.=$chunk;return strlen($chunk);},
            ]);
            if(curl_exec($curl)===false)throw new \RuntimeException('Business GraphQL HTTPS result is unconfirmed');return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$received];
        }finally{curl_close($curl);}
    }
    private static function errorCode(\stdClass $envelope,int $status):?string
    {
        $error=$envelope->error??(is_array($envelope->errors??null)?($envelope->errors[0]??null):null);$code=null;
        if($error instanceof \stdClass){$extensions=$error->extensions??null;$code=$error->code??($extensions instanceof \stdClass?($extensions->error_code??$extensions->code??null):null);}
        if((is_int($code)||is_string($code))&&preg_match('/^-?[0-9]{1,10}$/D',(string)$code)===1)return (string)$code;return $status>=400&&$status<500?(string)$status:null;
    }
    private static function safeCode(?string $code):?string{return $code!==null&&preg_match('/^-?[0-9]{1,10}$/D',$code)===1?$code:null;}
    /** Recognize only an exact fixed slug. Numeric codes or arbitrary surrounding text prove no variable name. */
    private static function errorKind(\stdClass $envelope):?string
    {
        $error=$envelope->error??(is_array($envelope->errors??null)?($envelope->errors[0]??null):null);
        return $error instanceof \stdClass&&in_array($error->message??null,[
            'missing_required_variable_value',
            'A server error missing_required_variable_value occured. Check server logs for details.',
        ],true)?'missing_required_variable_value':null;
    }
    /** @return array<string,mixed> */
    private static function object(\stdClass $object):array
    {
        $out=[];foreach(get_object_vars($object)as$key=>$value)$out[$key]=self::value($value);return $out;
    }
    private static function value(mixed $value):mixed
    {
        if($value instanceof \stdClass)return self::object($value);if(is_array($value))return array_map(self::value(...),$value);return $value;
    }
}
