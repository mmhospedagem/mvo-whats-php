<?php
declare(strict_types=1);
namespace MVO\Testing;

use MVO\Media\MediaCrypto;
use MVO\Protocol\BinaryNode;

/** Explicit test-only HTTPS encrypted blob service. Never changes global certificate trust. */
final class FakeMediaServer
{
    /** @var resource|null */
    private $listener=null;
    private string $address='';
    private readonly string $directory;
    private readonly string $auth;
    /** @var array<string,string> URL path to encrypted bytes */
    private array $blobs=[];
    private int $storedBytes=0;

    public function __construct(?string $directory=null,private readonly int $maxBytes=33554432,private readonly int $maxStoredBytes=134217728,private readonly ?string $advertisedOrigin=null)
    {
        if($maxBytes<1||$maxStoredBytes<$maxBytes)throw new \InvalidArgumentException('Invalid fake media bounds');
        if($advertisedOrigin!==null&&!in_array($advertisedOrigin,['https://localhost','https://127.0.0.1'],true))throw new \InvalidArgumentException('Fake advertised HTTPS origin must be loopback on port 443');
        $this->directory=$directory ?? sys_get_temp_dir().'/mvo-fake-media-'.bin2hex(random_bytes(8));
        if(!is_dir($this->directory)&&!mkdir($this->directory,0700,true))throw new \RuntimeException('Cannot create fake media certificate directory');
        $this->auth=bin2hex(random_bytes(24));$this->createCertificate();
    }
    public function listen():void
    {
        if(is_resource($this->listener))return;
        $context=stream_context_create(['ssl'=>['local_cert'=>$this->caFile(),'local_pk'=>$this->directory.'/key.pem','verify_peer'=>false,'verify_peer_name'=>false,'crypto_method'=>STREAM_CRYPTO_METHOD_TLSv1_2_SERVER|STREAM_CRYPTO_METHOD_TLSv1_3_SERVER]]);
        $socket=@stream_socket_server('tcp://127.0.0.1:0',$code,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$context);
        if($socket===false)throw new \RuntimeException('Cannot bind fake media HTTPS service');
        $address=stream_socket_get_name($socket,false);if($address===false){fclose($socket);throw new \RuntimeException('Cannot determine fake media address');}
        $this->listener=$socket;$this->address=$address;
    }
    public function caFile():string {return $this->directory.'/certificate.pem';}
    public function address():string {$this->listen();return $this->address;}
    public function url():string {$this->listen();return $this->advertisedOrigin ?? 'https://'.$this->address;}
    public function connectionNode():BinaryNode {$this->listen();return new BinaryNode('media_conn',['auth'=>$this->auth,'ttl'=>'3600'],[new BinaryNode('host',['hostname'=>substr($this->url(),8),'type'=>'primary'])]);}
    /** @return array<string,mixed> Real encrypted media descriptor, for test fixture messages. */
    public function publish(string $plain,string $type):array
    {
        if(strlen($plain)>$this->maxBytes)throw new \LengthException('Fake media plaintext exceeds maxBytes');
        $encrypted=MediaCrypto::encrypt($plain,$type);$path='/mms/'.$type.'/'.self::token($encrypted['body']);$this->put($path,$encrypted['body']);unset($encrypted['body']);
        return $encrypted+['url'=>$this->url().$path,'directPath'=>$this->url().$path,'mediaType'=>$type];
    }
    public function expire(string $path):void {$path=parse_url($path,PHP_URL_PATH);if(is_string($path)&&isset($this->blobs[$path])){$this->storedBytes-=strlen($this->blobs[$path]);unset($this->blobs[$path]);}}
    /** Serves at most one bounded request. Call from the foreground fake server tick. */
    public function poll(float $timeout=0):bool
    {
        if(!is_finite($timeout)||$timeout<0||$timeout>30)throw new \InvalidArgumentException('Invalid fake HTTPS poll timeout');$this->listen();$read=[$this->listener];$write=$except=[];$seconds=(int)$timeout;
        if(stream_select($read,$write,$except,$seconds,(int)(($timeout-$seconds)*1000000))!==1)return false;
        $socket=@stream_socket_accept($this->listener,0);if($socket===false)return false;stream_set_timeout($socket,3);
        try {
            if(@stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_SERVER)!==true)return true;
            $header='';while(!str_contains($header,"\r\n\r\n")){if(strlen($header)>=16384){$this->response($socket,431,'');return true;}$chunk=fread($socket,1);if($chunk===false||$chunk==='')return true;$header.=$chunk;}
            $lines=explode("\r\n",substr($header,0,-4));$first=array_shift($lines);if(!is_string($first)||!preg_match('~^(GET|POST) (/[^ ]*) HTTP/1\.[01]$~D',$first,$match)){$this->response($socket,400,'');return true;}
            $headers=[];foreach($lines as $line){$colon=strpos($line,':');if($colon===false){$this->response($socket,400,'');return true;}$name=strtolower(substr($line,0,$colon));if(isset($headers[$name])){$this->response($socket,400,'');return true;}$headers[$name]=trim(substr($line,$colon+1));}
            if(isset($headers['transfer-encoding'])){$this->response($socket,400,'');return true;}
            $rawLength=$headers['content-length'] ?? '0';if(!ctype_digit($rawLength)||strlen($rawLength)>9){$this->response($socket,400,'');return true;}$length=(int)$rawLength;
            if($length>$this->maxBytes+26){$this->response($socket,413,'');return true;}
            $target=parse_url($match[2]);$path=$target['path'] ?? '';if(!preg_match('~^/mms/[a-z-]+/[A-Za-z0-9_-]{43}$~D',$path)){$this->response($socket,404,'');return true;}
            if($match[1]==='GET'){$this->response($socket,isset($this->blobs[$path])?200:404,$this->blobs[$path] ?? '');return true;}
            parse_str($target['query'] ?? '',$query);if(!is_string($query['auth'] ?? null)||!hash_equals($this->auth,$query['auth'])){$this->response($socket,401,'');return true;}
            if(strtolower($headers['expect'] ?? '')==='100-continue')fwrite($socket,"HTTP/1.1 100 Continue\r\n\r\n");
            $body='';while(strlen($body)<$length){$chunk=fread($socket,min(65536,$length-strlen($body)));if($chunk===false||$chunk===''){$this->response($socket,400,'');return true;}$body.=$chunk;}
            $token=basename($path);if($length<26||!hash_equals($token,self::token($body))||($query['token'] ?? null)!==$token){$this->response($socket,400,'');return true;}
            try{$this->put($path,$body);}catch(\LengthException){$this->response($socket,507,'');return true;}
            $this->response($socket,200,json_encode(['url'=>$this->url().$path,'direct_path'=>$this->url().$path],JSON_THROW_ON_ERROR),'application/json');return true;
        } finally {fclose($socket);}
    }
    private function put(string $path,string $body):void {$new=$this->storedBytes-strlen($this->blobs[$path] ?? '')+strlen($body);if($new>$this->maxStoredBytes)throw new \LengthException('Fake encrypted blob storage limit');$this->blobs[$path]=$body;$this->storedBytes=$new;}
    private static function token(string $body):string {return rtrim(strtr(base64_encode(hash('sha256',$body,true)),'+/','-_'),'=');}
    /** @param resource $socket */
    private function response($socket,int $status,string $body,string $type='application/octet-stream'):void
    {
        $wire='HTTP/1.1 '.$status." Fake\r\nContent-Type: ".$type."\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body;
        for($offset=0;$offset<strlen($wire);){$written=fwrite($socket,substr($wire,$offset));if($written===false||$written===0)throw new \RuntimeException('Fake HTTPS response failed');$offset+=$written;}fflush($socket);
    }
    private function createCertificate():void
    {
        if(is_file($this->caFile())&&is_file($this->directory.'/key.pem')){
            $certificateBytes=file_get_contents($this->caFile());$keyBytes=file_get_contents($this->directory.'/key.pem');$certificate=$certificateBytes===false ? false : @openssl_x509_read($certificateBytes);$privateKey=$keyBytes===false ? false : @openssl_pkey_get_private($keyBytes);$parsed=$certificate===false ? false : openssl_x509_parse($certificate);
            if(is_array($parsed)&&($parsed['validFrom_time_t'] ?? PHP_INT_MAX)<=time()&&($parsed['validTo_time_t'] ?? 0)>time()+3600&&($parsed['subject']['CN'] ?? '')==='MVO offline fake localhost'&&str_contains($parsed['extensions']['subjectAltName'] ?? '','DNS:localhost')&&str_contains($parsed['extensions']['subjectAltName'] ?? '','IP Address:127.0.0.1')&&$privateKey!==false&&openssl_x509_check_private_key($certificate,$privateKey))return;
        }
        $config=$this->directory.'/openssl.cnf';$text="[req]\ndistinguished_name=dn\nx509_extensions=ext\nprompt=no\n[dn]\nCN=MVO offline fake localhost\n[ext]\nsubjectAltName=DNS:localhost,IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n";
        if(file_put_contents($config,$text)!==strlen($text))throw new \RuntimeException('Cannot write fake certificate configuration');
        $options=['config'=>$config,'digest_alg'=>'sha256','private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048];$key=openssl_pkey_new($options);if($key===false)throw new \RuntimeException('Cannot create fake TLS key');
        $csr=openssl_csr_new(['commonName'=>'MVO offline fake localhost'],$key,$options);if($csr===false)throw new \RuntimeException('Cannot create fake TLS request');
        $cert=openssl_csr_sign($csr,null,$key,2,$options);if($cert===false||!openssl_x509_export($cert,$pem)||!openssl_pkey_export($key,$private,null,$options))throw new \RuntimeException('Cannot sign fake TLS certificate');
        foreach(['certificate.pem'=>$pem,'key.pem'=>$private] as $name=>$bytes){$path=$this->directory.'/'.$name;if(file_put_contents($path,$bytes)!==strlen($bytes))throw new \RuntimeException('Cannot save fake TLS certificate');chmod($path,0600);}
    }
    public function close():void {if(is_resource($this->listener))fclose($this->listener);$this->listener=null;$this->address='';}
}
