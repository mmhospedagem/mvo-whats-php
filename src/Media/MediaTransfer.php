<?php
declare(strict_types=1);

namespace MVO\Media;

use MVO\Protocol\BinaryNode;
use MVO\Protocol\SchemaCodec;

/** Native PHP encrypted media transfer; all network requests are bounded and HTTPS. */
final class MediaTransfer
{
    /** @var \Closure(BinaryNode):BinaryNode */
    private \Closure $query;
    /** @var \Closure(array<string,mixed>):array{status:int,body:string}|null */
    private ?\Closure $http;
    /** @var array{auth:string,expiresAt:int,hosts:list<string>}|null */
    private ?array $connection = null;
    /** @var list<string> */
    private array $allowedHosts;

    /**
     * $http is injectable for tests/proxies; it must return HTTP status and binary body.
     * @param callable(BinaryNode):BinaryNode $query
     * @param callable(array<string,mixed>):array{status:int,body:string}|null $http
     * @param list<string> $allowedHosts Additional explicitly trusted HTTPS hosts.
     */
    public function __construct(callable $query, ?callable $http = null, private readonly int $maxBytes = 33554432, array $allowedHosts = [], private readonly ?string $proxy=null, private readonly ?MediaProcessor $processor=null)
    {
        if ($maxBytes <= 0 || $maxBytes > PHP_INT_MAX - 1024) throw new \InvalidArgumentException('Media maxBytes must be positive');
        $this->query = \Closure::fromCallable($query);
        $this->http = $http === null ? null : \Closure::fromCallable($http);
        $this->allowedHosts = array_map('strtolower', $allowedHosts);
    }

    public function maxBytes(): int { return $this->maxBytes; }
    public function processor():?MediaProcessor { return $this->processor; }
    public function clearConnection(): void { $this->connection = null; }

    /** Encrypts and uploads with bounded RAM; only the encrypted file is spooled to disk.
     * @param resource|string $source Readable stream (current offset), or local path.
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function uploadStream($source,string $type,array $options=[]):array {
        $path=match($type) {
            'image','video','audio','document','sticker','gif','ptt','sticker-pack','thumbnail-sticker-pack','thumbnail-link','group-history','md-app-state','md-msg-hist'=>'/mms/'.$type,
            'ptv'=>'/mms/video', default=>throw new \InvalidArgumentException('Unsupported media upload type'),
        };
        if (isset($options['mediaKey']) && !is_string($options['mediaKey'])) throw new \InvalidArgumentException('mediaKey must be binary bytes');
        if (isset($options['firstFrameLength'])) throw new \InvalidArgumentException('firstFrameLength requires buffered upload');
        $owned=false;
        if (is_string($source)) {
            if (str_contains($source,"\0") || preg_match('~^[a-z][a-z0-9+.-]*://~i',$source)) throw new \InvalidArgumentException('Expected local media path');
            $source=fopen($source,'rb'); $owned=true;
            if ($source===false) throw new \RuntimeException('Cannot open media source');
        }
        try { $encrypted=StreamMediaCrypto::encrypt($source,$type,$options['mediaKey'] ?? null,$this->maxBytes); }
        finally { if ($owned && is_resource($source)) fclose($source); }
        $stream=$encrypted['stream']; $length=$encrypted['encryptedLength'];
        $token=rtrim(strtr(base64_encode($encrypted['fileEncSha256']),'+/','-_'),'='); $last=null;
        try {
            foreach ([false,true] as $refreshed) {
                $conn=$this->mediaConnection();
                foreach ($conn['hosts'] as $host) {
                    try {
                        rewind($stream);
                        $reply=$this->streamRequest('POST','https://'.$host.$path.'/'.$token.'?auth='.rawurlencode($conn['auth']).'&token='.$token,$stream,$length,null,1048576);
                        if (in_array($reply['status'],[401,403],true) && !$refreshed) { $this->clearConnection(); continue 2; }
                        if ($reply['status']<200 || $reply['status']>=300) throw new \RuntimeException('Media upload failed with HTTP '.$reply['status']);
                        $parsed=json_decode($reply['body'],true,32,JSON_THROW_ON_ERROR);
                        if (!is_array($parsed) || !is_string($parsed['url'] ?? null) || !is_string($parsed['direct_path'] ?? null)) throw new \UnexpectedValueException('Invalid media upload response');
                        $this->validateUrl($parsed['url']); $this->validateDirectPath($parsed['direct_path']);
                        unset($encrypted['stream'],$encrypted['encryptedLength']);
                        $result=$encrypted+['url'=>$parsed['url'],'directPath'=>$parsed['direct_path'],'mediaKeyTimestamp'=>time()];
                        if ($options['sidecar'] ?? false) $result['streamingSidecar']=StreamMediaCrypto::sidecar($stream,$type,$result['mediaKey']);
                        return $result;
                    } catch (\Throwable $e) { $last=$e; }
                }
                break;
            }
            throw new \RuntimeException('Media upload failed: '.($last?->getMessage() ?? 'authentication refused'),0,$last);
        } finally { fclose($stream); }
    }

    /** Downloads, authenticates and atomically publishes a local file with bounded RAM.
     * @param array<string,mixed> $descriptor */
    public function downloadStream(array $descriptor,string $destination):string {
        if (!isset($descriptor['mediaType'])) $descriptor=self::resolvePayload($descriptor) ?? throw new \InvalidArgumentException('Message has no downloadable media');
        if (str_contains($destination,"\0") || preg_match('~^[a-z][a-z0-9+.-]*://~i',$destination) || !is_dir(dirname($destination))) throw new \InvalidArgumentException('Expected local media destination');
        if (!is_string($descriptor['mediaKey'] ?? null) || !is_string($descriptor['mediaType'] ?? null)) throw new \InvalidArgumentException('Missing media key/type');
        MediaCrypto::deriveKeys($descriptor['mediaType'],$descriptor['mediaKey']);
        $urls=[];
        if (is_string($descriptor['url'] ?? null)) { $this->validateUrl($descriptor['url']); $urls[]=$descriptor['url']; }
        if (is_string($descriptor['directPath'] ?? null)) {
            $direct=$descriptor['directPath']; $this->validateDirectPath($direct);
            if (str_starts_with($direct,'https://')) $urls[]=$direct;
            else foreach ($this->connection['hosts'] ?? ['mmg.whatsapp.net','mmg-fallback.whatsapp.net'] as $host) $urls[]='https://'.$host.$direct;
        }
        if ($urls===[]) throw new \InvalidArgumentException('Missing media URL/directPath');
        $plain=null; $last=null;
        foreach (array_unique($urls) as $url) {
            $cipher=StreamMediaCrypto::temporary();
            try {
                $reply=$this->streamRequest('GET',$url,null,0,$cipher,$this->maxBytes+26);
                if (in_array($reply['status'],[404,410],true)) throw new MediaExpiredException($reply['status']);
                if ($reply['status']<200 || $reply['status']>=300) throw new \RuntimeException('Media download failed with HTTP '.$reply['status']);
                rewind($cipher); $plain=StreamMediaCrypto::decrypt($cipher,$descriptor,$this->maxBytes); break;
            } catch (\Throwable $e) { $last=$e; }
            finally { fclose($cipher); }
        }
        if ($plain===null) {
            if ($last instanceof MediaExpiredException) throw $last;
            throw new \RuntimeException('Media download failed: '.$last->getMessage(),0,$last);
        }
        $temp=tempnam(dirname($destination),'.mvo-media-');
        if ($temp===false) { fclose($plain); throw new \RuntimeException('Cannot create media destination'); }
        try {
            $target=fopen($temp,'wb'); if ($target===false) throw new \RuntimeException('Cannot write media destination');
            try { if (stream_copy_to_stream($plain,$target)===false || !fflush($target)) throw new \RuntimeException('Cannot copy media destination'); } finally { fclose($target); }
            if (!rename($temp,$destination)) throw new \RuntimeException('Cannot publish media destination');
            return $destination;
        } finally { fclose($plain); if (is_file($temp)) unlink($temp); }
    }

    /** @param resource|null $body
     * @param resource|null $destination
     * @return array{status:int,body:string} */
    private function streamRequest(string $method,string $url,$body,int $bodyLength,$destination,int $limit):array {
        $this->validateUrl($url);
        if ($this->http!==null) {
            $reply=($this->http)(['method'=>$method,'url'=>$url,'body'=>$body,'bodyLength'=>$bodyLength,'destination'=>$destination,'maxBytes'=>$limit,'timeoutSeconds'=>120]);
            if (strlen($reply['body'])>$limit) throw new \LengthException('Media response exceeds maxBytes');
            if ($destination!==null) { StreamMediaCrypto::write($destination,$reply['body']); return ['status'=>$reply['status'],'body'=>'']; }
            return $reply;
        }
        $curl=curl_init($url); $received=''; $count=0;
        $headers=['Origin: https://web.whatsapp.com','User-Agent: Mozilla/5.0','Accept: */*'];
        if ($body!==null) $headers=[...$headers,'Content-Type: application/octet-stream','Content-Length: '.$bodyLength,'Expect:'];
        curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>120,CURLOPT_WRITEFUNCTION=>static function(\CurlHandle $handle,string $chunk) use(&$count,&$received,$limit,$destination):int {
            $count+=strlen($chunk); if ($count>$limit) return 0;
            if ($destination===null) $received.=$chunk; else StreamMediaCrypto::write($destination,$chunk); return strlen($chunk);
        }]);
        if ($body!==null) curl_setopt_array($curl,[CURLOPT_UPLOAD=>true,CURLOPT_INFILE=>$body,CURLOPT_INFILESIZE=>$bodyLength,CURLOPT_CUSTOMREQUEST=>$method]);
        if ($this->proxy!==null) curl_setopt($curl,CURLOPT_PROXY,$this->proxy);
        try {
            if (curl_exec($curl)===false) throw new \RuntimeException($count>$limit ? 'Media response exceeds maxBytes' : 'Media HTTP transfer failed: '.curl_error($curl));
            return ['status'=>curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$received];
        } finally { curl_close($curl); }
    }

    /**
     * @param array<string,mixed> $content
     * @return array<string,mixed> WAProto Message
     */
    public function prepare(array $content): array
    {
        return (new MediaMessageBuilder($this))->build($content);
    }
    /** @param array<string,mixed> $content
     * @return array<string,mixed> */
    public function prepareNewsletter(array $content):array { return (new MediaMessageBuilder($this,newsletter:true))->build($content); }
    /** Catalogue images and Business covers use a plaintext SHA256 upload, not message-media encryption.
     * @return array<string,mixed> */
    public function uploadBusiness(string $plain,string $type):array
    {
        $path=match($type){'product-catalog-image'=>'/product/image','biz-cover-photo'=>'/pps/biz-cover-photo',default=>throw new \InvalidArgumentException('Unsupported Business media type')};
        if($plain===''||strlen($plain)>$this->maxBytes)throw new \LengthException('Empty/oversized Business image');
        $image=@getimagesizefromstring($plain);
        if(!is_array($image)||!in_array($image['mime'],['image/jpeg','image/png','image/webp','image/gif'],true))throw new \InvalidArgumentException('Business media must be a supported raster image');
        $hash=hash('sha256',$plain,true);$token=rtrim(strtr(base64_encode($hash),'+/','-_'),'=');$last=null;
        foreach([false,true]as$refreshed){
            $connection=$this->mediaConnection();
            foreach($connection['hosts']as$host){
                try{
                    $response=$this->request('POST','https://'.$host.$path.'/'.$token.'?auth='.rawurlencode($connection['auth']).'&token='.$token,$plain,1048576);
                    if(in_array($response['status'],[401,403],true)&&!$refreshed){$this->clearConnection();continue 2;}
                    if($response['status']<200||$response['status']>=300)throw new \RuntimeException('Business media upload failed with HTTP '.$response['status']);
                    $parsed=json_decode($response['body'],true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
                    if(!is_array($parsed))throw new \UnexpectedValueException('Invalid Business upload response');
                    $url=$parsed['url']??null;$direct=$parsed['direct_path']??null;
                    if(is_string($url)&&$url!=='')$this->validateUrl($url);
                    if(is_string($direct)&&$direct!=='')$this->validateDirectPath($direct);
                    if((!is_string($url)||$url==='')&&is_string($direct)&&$direct!==''){
                        $url=str_starts_with($direct,'https://')?$direct:'https://mmg.whatsapp.net'.$direct;
                        $this->validateUrl($url);
                    }
                    if($type==='product-catalog-image'&&(!is_string($url)||$url===''))throw new \UnexpectedValueException('Business upload response missing media location');
                    $result=['fileSha256'=>$hash,'fileLength'=>strlen($plain)];
                    if(is_string($url)&&$url!=='')$result['url']=$url;
                    if(is_string($direct)&&$direct!=='')$result['directPath']=$direct;
                    if($type==='biz-cover-photo'){
                        foreach(['fbid','ts']as$field)if((!is_string($parsed[$field]??null)&&!is_int($parsed[$field]??null))||!preg_match('/^[0-9]{1,24}$/D',(string)$parsed[$field]))throw new \UnexpectedValueException('Business cover upload missing '.$field);
                        if(!is_string($parsed['meta_hmac']??null)||$parsed['meta_hmac']===''||strlen($parsed['meta_hmac'])>4096||preg_match('/[\x00-\x20\x7f]/',$parsed['meta_hmac']))throw new \UnexpectedValueException('Business cover upload missing metadata token');
                        $result+=['id'=>(string)$parsed['fbid'],'timestamp'=>(string)$parsed['ts'],'token'=>$parsed['meta_hmac']];
                    }
                    return $result;
                }catch(\Throwable $error){$last=$error;}
            }
            break;
        }
        throw new \RuntimeException('Business media upload failed: '.($last?->getMessage()??'authentication refused'),0,$last);
    }
    /** Public-channel media uses the newsletter CDN endpoint and plaintext file hash.
     * @return array<string,mixed> */
    public function uploadNewsletter(string $plain,string $type):array {
        if ($plain==='' || strlen($plain)>$this->maxBytes) throw new \LengthException('Empty/oversized newsletter media');
        if (!in_array($type,['image','video','audio','document','sticker','gif','ptt','ptv','sticker-pack','thumbnail-link'],true)) throw new \InvalidArgumentException('Unsupported newsletter media type');
        $hash=hash('sha256',$plain,true); $token=rtrim(strtr(base64_encode($hash),'+/','-_'),'='); $last=null;
        foreach ([false,true] as $refreshed) {
            $connection=$this->mediaConnection();
            foreach ($connection['hosts'] as $host) {
                try {
                    $reply=$this->request('POST','https://'.$host.'/newsletter/newsletter-'.$type.'/'.$token.'?auth='.rawurlencode($connection['auth']).'&token='.$token,$plain,1048576);
                    if (in_array($reply['status'],[401,403],true) && !$refreshed) { $this->clearConnection(); continue 2; }
                    if ($reply['status']<200 || $reply['status']>=300) throw new \RuntimeException('Newsletter upload failed with HTTP '.$reply['status']);
                    $parsed=json_decode($reply['body'],true,32,JSON_THROW_ON_ERROR);
                    if (!is_array($parsed) || !is_string($parsed['url'] ?? null) || !is_string($parsed['direct_path'] ?? null)) throw new \UnexpectedValueException('Invalid newsletter upload response');
                    $this->validateUrl($parsed['url']); $this->validateDirectPath($parsed['direct_path']);
                    return ['url'=>$parsed['url'],'directPath'=>$parsed['direct_path'],'fileSha256'=>$hash,'fileLength'=>strlen($plain)];
                } catch (\Throwable $e) { $last=$e; }
            }
            break;
        }
        throw new \RuntimeException('Newsletter upload failed: '.($last?->getMessage() ?? 'authentication refused'),0,$last);
    }
    /** Downloads public channel bytes and verifies SHA256 before returning them.
     * @param array<string,mixed> $descriptor */
    public function downloadNewsletter(array $descriptor,?string $destination=null):string {
        if (isset($descriptor['proto'])) $descriptor=$descriptor['proto'];
        if (!isset($descriptor['url']) && !isset($descriptor['directPath'])) {
            foreach (['imageMessage','videoMessage','ptvMessage','audioMessage','documentMessage','stickerMessage','stickerPackMessage'] as $field) if (isset($descriptor[$field])) { $descriptor=$descriptor[$field]; break; }
        }
        if (!empty($descriptor['mediaKey'])) throw new \InvalidArgumentException('Encrypted media requires downloadMedia');
        if (!is_string($descriptor['fileSha256'] ?? null) || strlen($descriptor['fileSha256'])!==32) throw new \InvalidArgumentException('Newsletter media requires a SHA256 hash');
        $url=$descriptor['url'] ?? null;
        if (!is_string($url) && is_string($descriptor['directPath'] ?? null)) { $this->validateDirectPath($descriptor['directPath']); $url=str_starts_with($descriptor['directPath'],'https://') ? $descriptor['directPath'] : 'https://mmg.whatsapp.net'.$descriptor['directPath']; }
        if (!is_string($url)) throw new \InvalidArgumentException('Missing newsletter media URL');
        $reply=$this->request('GET',$url,'',$this->maxBytes);
        if ($reply['status']<200 || $reply['status']>=300) throw new \RuntimeException('Newsletter download failed with HTTP '.$reply['status']);
        $plain=$reply['body']; if (!hash_equals($descriptor['fileSha256'],hash('sha256',$plain,true))) throw new \UnexpectedValueException('Newsletter media hash mismatch');
        if (isset($descriptor['fileLength']) && $descriptor['fileLength']!==strlen($plain)) throw new \UnexpectedValueException('Newsletter media length mismatch');
        if ($destination===null) return $plain;
        if (str_contains($destination,"\0") || preg_match('~^[a-z][a-z0-9+.-]*://~i',$destination) || !is_dir(dirname($destination))) throw new \InvalidArgumentException('Expected local newsletter destination');
        $temp=tempnam(dirname($destination),'.mvo-channel-'); if ($temp===false) throw new \RuntimeException('Cannot create newsletter destination');
        try { if (file_put_contents($temp,$plain)!==strlen($plain) || !rename($temp,$destination)) throw new \RuntimeException('Cannot publish newsletter media'); }
        finally { if (is_file($temp)) unlink($temp); } return $destination;
    }

    /** Public channel descriptors have hashes and CDN paths, without a Signal media key.
     * @param array<string,mixed> $message
     * @return array<string,mixed>|null */
    public static function resolveNewsletterPayload(array $message):?array
    {
        if(is_array($message['proto']??null))$message=$message['proto'];
        $message=\MVO\Message\ContentDecoder::unwrap($message)['message'];
        foreach(['imageMessage'=>'image','videoMessage'=>'video','ptvMessage'=>'ptv','audioMessage'=>'audio','documentMessage'=>'document','stickerMessage'=>'sticker','stickerPackMessage'=>'sticker-pack']as$field=>$type){
            $payload=$message[$field]??null;
            if(!is_array($payload)||!empty($payload['mediaKey']))continue;
            return [...$payload,'type'=>$type,'plaintext'=>true];
        }
        return null;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed> Descriptor with raw binary key/hash fields.
     */
    public function upload(string $plain, string $type, array $options = []): array
    {
        if ($plain === '') throw new \InvalidArgumentException('Cannot upload empty media');
        if (strlen($plain) > $this->maxBytes) throw new \LengthException('Media exceeds maxBytes');
        $path = match ($type) {
            'image','video','audio','document','sticker','gif','ptt','sticker-pack','thumbnail-sticker-pack','thumbnail-link',
            'group-history','md-app-state','md-msg-hist' => '/mms/'.$type,
            'ptv' => '/mms/video',
            default => throw new \InvalidArgumentException('Unsupported media upload type: '.$type),
        };
        $key = $options['mediaKey'] ?? null;
        if ($key !== null && !is_string($key)) throw new \InvalidArgumentException('mediaKey must be binary bytes');
        if (isset($options['firstFrameLength']) && (!is_int($options['firstFrameLength']) || $options['firstFrameLength'] <= 0 || $options['firstFrameLength'] > strlen($plain))) throw new \InvalidArgumentException('Invalid firstFrameLength');
        $encrypted = MediaCrypto::encrypt($plain, $type, $key);
        $token = rtrim(strtr(base64_encode($encrypted['fileEncSha256']), '+/', '-_'), '=');
        $last = null;
        // Retry once after stale authentication; preserve the same ciphertext/hash token.
        foreach ([false,true] as $refreshed) {
            $conn = $this->mediaConnection();
            foreach ($conn['hosts'] as $host) {
                $url = 'https://'.$host.$path.'/'.$token.'?auth='.rawurlencode($conn['auth']).'&token='.$token;
                try {
                    $response = $this->request('POST', $url, $encrypted['body'], 1048576);
                    if (($response['status'] === 401 || $response['status'] === 403) && !$refreshed) {
                        $this->clearConnection();
                        continue 2;
                    }
                    if ($response['status'] < 200 || $response['status'] >= 300) {
                        throw new \RuntimeException('Media upload failed with HTTP '.$response['status']);
                    }
                    $parsed = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
                    if (!is_array($parsed) || !isset($parsed['url'], $parsed['direct_path']) || !is_string($parsed['url']) || !is_string($parsed['direct_path']) || $parsed['direct_path'] === '') {
                        throw new \UnexpectedValueException('Media upload response missing url/direct_path');
                    }
                    $this->validateUrl($parsed['url']);
                    $this->validateDirectPath($parsed['direct_path']);
                    $result = $encrypted;
                    unset($result['body']);
                    $result['url'] = $parsed['url'];
                    $result['directPath'] = $parsed['direct_path'];
                    $result['mediaKeyTimestamp'] = time();
                    if (isset($parsed['metadata_url']) && is_string($parsed['metadata_url'])) $result['metadataUrl'] = $parsed['metadata_url'];
                    if (($options['sidecar'] ?? false) && MediaCrypto::isStreamable($plain)) {
                        $result['streamingSidecar'] = MediaCrypto::streamingSidecar($encrypted['body'], $type, $encrypted['mediaKey']);
                    }
                    if (isset($options['firstFrameLength'])) {
                        $result['firstFrameLength'] = $options['firstFrameLength'];
                        $result['firstFrameSidecar'] = MediaCrypto::firstFrameSidecar($encrypted['body'], $type, $encrypted['mediaKey'], $options['firstFrameLength']);
                    }
                    return $result;
                } catch (\Throwable $error) {
                    $last = $error;
                }
            }
            break;
        }
        throw new \RuntimeException('Media upload failed: '.($last?->getMessage() ?? 'authentication refused'), 0, $last);
    }

    /**
     * Returns authenticated plaintext bytes, or the destination path when provided.
     * Nothing is written until the MAC, encrypted hash, plaintext hash and length pass.
     * @param array<string,mixed> $descriptor
     */
    public function download(array $descriptor, ?string $destination = null): string
    {
        if (!isset($descriptor['mediaType'])) $descriptor = self::resolvePayload($descriptor) ?? throw new \InvalidArgumentException('Message has no downloadable media');
        $type = $descriptor['mediaType'];
        $key = $descriptor['mediaKey'] ?? null;
        if (!is_string($type) || !is_string($key) || strlen($key) !== 32) throw new \InvalidArgumentException('Invalid media type/key');
        MediaCrypto::deriveKeys($type,$key);
        $length = $descriptor['fileLength'] ?? null;
        if ($length !== null && (!is_int($length) || $length < 0)) throw new \InvalidArgumentException('Invalid media fileLength');
        if ($length !== null && $length > $this->maxBytes) throw new \LengthException('Media exceeds maxBytes');
        foreach (['fileSha256','fileEncSha256'] as $field) {
            if (isset($descriptor[$field]) && (!is_string($descriptor[$field]) || strlen($descriptor[$field]) !== 32)) throw new \InvalidArgumentException('Invalid media '.$field);
        }
        $urls = [];
        if (isset($descriptor['url']) && is_string($descriptor['url']) && $descriptor['url'] !== '') {
            $this->validateUrl($descriptor['url']);
            $urls[] = $descriptor['url'];
        }
        $direct = $descriptor['directPath'] ?? null;
        if (is_string($direct) && $direct !== '') {
            $this->validateDirectPath($direct);
            if (str_starts_with($direct, 'https://')) $urls[] = $direct;
            else {
                foreach ($this->connection['hosts'] ?? ['mmg.whatsapp.net','mmg-fallback.whatsapp.net'] as $host) $urls[] = 'https://'.$host.$direct;
            }
        }
        if ($urls === []) throw new \InvalidArgumentException('Media requires directPath or url');
        $plain = null;
        $last = null;
        foreach (array_unique($urls) as $url) {
            try {
                $response = $this->request('GET', $url, '', $this->maxBytes + 32);
                if (in_array($response['status'],[404,410],true)) throw new MediaExpiredException($response['status']);
                if ($response['status'] < 200 || $response['status'] >= 300) throw new \RuntimeException('Media download failed with HTTP '.$response['status']);
                $plain = MediaCrypto::decrypt($response['body'], $type, $key, $descriptor['fileSha256'] ?? null, $descriptor['fileEncSha256'] ?? null);
                if (strlen($plain) > $this->maxBytes) throw new \LengthException('Media exceeds maxBytes');
                if ($length !== null && strlen($plain) !== $length) throw new \UnexpectedValueException('Media plaintext length mismatch');
                break;
            } catch (\Throwable $error) {
                $plain = null;
                $last = $error;
            }
        }
        if ($plain === null) {
            if ($last instanceof MediaExpiredException) throw $last;
            throw new \RuntimeException('Media download failed: '.$last->getMessage(), 0, $last);
        }
        if ($destination === null) return $plain;
        if (str_contains($destination, "\0") || preg_match('~^[a-z][a-z0-9+.-]*://~i', $destination) || !is_dir(dirname($destination))) throw new \InvalidArgumentException('Destination must be a local file in an existing directory');
        $temp = tempnam(dirname($destination), '.mvo-media-');
        if ($temp === false) throw new \RuntimeException('Cannot create media destination');
        try {
            if (file_put_contents($temp, $plain, LOCK_EX) !== strlen($plain) || !rename($temp, $destination)) throw new \RuntimeException('Cannot write media destination');
        } finally {
            if (is_file($temp)) unlink($temp);
        }
        return $destination;
    }

    /**
     * @param array<string,mixed> $message
     * @return array<string,mixed>|null
     */
    public static function resolvePayload(array $message): ?array
    {
        $wrappers = [];
        foreach (SchemaCodec::fields('Message') as $field => $definition) {
            if (in_array($definition['type'],['Message.FutureProofMessage','Message.DeviceSentMessage'],true)) $wrappers[] = $field;
        }
        for ($depth = 0; $depth < 16; $depth++) {
            $unwrapped = false;
            foreach ($wrappers as $wrapper) {
                if (isset($message[$wrapper]['message']) && is_array($message[$wrapper]['message'])) {
                    $message = $message[$wrapper]['message'];
                    $unwrapped = true;
                    break;
                }
            }
            if (!$unwrapped) break;
        }
        foreach (['imageMessage'=>'image','instantImageMessage'=>'image','videoMessage'=>'video','audioMessage'=>'audio','ptvMessage'=>'ptv','documentMessage'=>'document','stickerMessage'=>'sticker','stickerPackMessage'=>'sticker-pack','messageHistoryBundle'=>'group-history'] as $field => $type) {
            $fields = $message[$field] ?? null;
            if (!is_array($fields)) continue;
            if ($field === 'videoMessage' && ($fields['gifPlayback'] ?? false)) $type = 'gif';
            if ($field === 'audioMessage' && ($fields['ptt'] ?? false)) $type = 'ptt';
            if (!isset($fields['mediaKey']) || !is_string($fields['mediaKey']) || (!isset($fields['directPath']) && !isset($fields['url']))) return null;
            $result = ['mediaType'=>$type];
            if($field==='instantImageMessage')$result['variant']='instant-image';
            foreach (['directPath','url','mediaKey','fileSha256','fileEncSha256','fileLength','mimetype','fileName'] as $key) {
                if (isset($fields[$key])) $result[$key] = $fields[$key];
            }
            return $result;
        }
        return null;
    }

    /** @return array{auth:string,expiresAt:int,hosts:list<string>} */
    private function mediaConnection(): array
    {
        if ($this->connection !== null && $this->connection['expiresAt'] > time() + 30) return $this->connection;
        $response = ($this->query)(new BinaryNode('iq', ['type'=>'set','to'=>'s.whatsapp.net','xmlns'=>'w:m'], [new BinaryNode('media_conn')]));
        if (($response->attrs['type'] ?? '') !== 'result') throw new \RuntimeException('media_conn query failed');
        $conn = $response->child('media_conn');
        $auth = $conn?->attrs['auth'] ?? '';
        $ttl = filter_var($conn?->attrs['ttl'] ?? '', FILTER_VALIDATE_INT);
        if ($conn === null || $auth === '' || $ttl === false || $ttl <= 0) throw new \UnexpectedValueException('Invalid media_conn auth/ttl');
        $hosts = $fallback = [];
        if (is_array($conn->content)) foreach ($conn->content as $node) {
            if ($node->tag !== 'host' || !isset($node->attrs['hostname'])) continue;
            $hostname = $node->attrs['hostname'];
            $this->validateUrl('https://'.$hostname.'/');
            if (($node->attrs['type'] ?? '') === 'fallback') $fallback[] = $hostname;
            else $hosts[] = $hostname;
        }
        $hosts = array_values(array_unique([...$hosts, ...$fallback]));
        if ($hosts === []) throw new \UnexpectedValueException('media_conn contains no hosts');
        return $this->connection = ['auth'=>$auth,'expiresAt'=>$ttl >= 1000000000 ? $ttl : time() + $ttl,'hosts'=>$hosts];
    }

    private function validateDirectPath(string $path): void
    {
        if (str_starts_with($path, 'https://')) { $this->validateUrl($path); return; }
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x20\x7f]/', $path)) throw new \InvalidArgumentException('Invalid media directPath');
    }

    private function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) throw new \InvalidArgumentException('Media URL must use trusted HTTPS');
        $host = strtolower($parts['host']);
        foreach (['whatsapp.net','whatsapp.com','fbcdn.net'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) return;
        }
        if (in_array($host, $this->allowedHosts, true)) return;
        throw new \InvalidArgumentException('Untrusted media host: '.$host);
    }

    /** @return array{status:int,body:string} */
    private function request(string $method, string $url, string $body, int $limit): array
    {
        $this->validateUrl($url);
        $headers = ['Origin'=>'https://web.whatsapp.com','User-Agent'=>'Mozilla/5.0','Accept'=>'*/*'];
        if ($method === 'POST') {
            $headers['Content-Type'] = 'application/octet-stream';
            $headers['Content-Length'] = (string) strlen($body);
        }
        if ($this->http !== null) {
            $response = ($this->http)(['method'=>$method,'url'=>$url,'headers'=>$headers,'body'=>$body,'maxBytes'=>$limit,'timeoutSeconds'=>60]);
            if (strlen($response['body']) > $limit) throw new \LengthException('Media response exceeds maxBytes');
            return $response;
        }
        if (!extension_loaded('curl')) throw new \RuntimeException('Media transfer requires ext-curl');
        $handle = curl_init($url);
        if ($handle === false) throw new \RuntimeException('Cannot initialize media HTTP transfer');
        $received = '';
        $overflow = false;
        $headerLines = [];
        foreach ($headers as $name => $value) $headerLines[] = $name.': '.$value;
        try {
            curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headerLines,
                CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>15, CURLOPT_TIMEOUT=>60,
                CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$received, &$overflow, $limit): int {
                    if (strlen($received) + strlen($chunk) > $limit) { $overflow = true; return 0; }
                    $received .= $chunk;
                    return strlen($chunk);
                }]);
            if ($method === 'POST') curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
            if ($this->proxy!==null) curl_setopt($handle,CURLOPT_PROXY,$this->proxy);
            $ok = curl_exec($handle);
            if ($overflow) throw new \LengthException('Media response exceeds maxBytes');
            if ($ok === false) throw new \RuntimeException('Media HTTP transfer error: '.curl_error($handle));
            return ['status'=>(int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body'=>$received];
        } finally {
            curl_close($handle);
        }
    }
}
