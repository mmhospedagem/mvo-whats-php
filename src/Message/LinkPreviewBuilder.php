<?php
declare(strict_types=1);
namespace MVO\Message;

/** Opt-in public HTTPS previews. DNS is checked and pinned on every request, including redirects. */
final class LinkPreviewBuilder
{
    private ?\Closure $fetch;
    private ?\Closure $upload;
    /** The injected fetcher must enforce its own public-network policy; maxBytes is checked again here.
     * @param callable(string,int):string|null $fetch
     * @param callable(string):array<string,mixed>|null $uploadThumbnail */
    public function __construct(?callable $fetch=null,?callable $uploadThumbnail=null)
    {$this->fetch=$fetch===null?null:\Closure::fromCallable($fetch);$this->upload=$uploadThumbnail===null?null:\Closure::fromCallable($uploadThumbnail);}
    /** @return array<string,mixed> */
    public function build(string $text,string $url,bool $includeThumbnail=true):array
    {
        MessageCodec::text($text);self::url($url);if(!str_contains($text,$url))throw new \InvalidArgumentException('The preview URL must occur in the message text');
        $html=$this->get($url,1048576);if(str_contains(substr($html,0,1024),"\0"))throw new \InvalidArgumentException('Preview document is not HTML');
        $meta=[];preg_match_all('~<meta\b[^>]{0,8192}>~i',$html,$tags);foreach(array_slice($tags[0],0,2048)as$tag){$attrs=self::attributes($tag);$name=strtolower($attrs['property']??$attrs['name']??'');if($name!==''&&isset($attrs['content'])&&!isset($meta[$name]))$meta[$name]=$attrs['content'];}
        preg_match('~<title\b[^>]*>(.{0,8192}?)</title\s*>~is',$html,$title);
        $preview=['matchedText'=>$url,'title'=>self::text($meta['og:title']??$meta['twitter:title']??$title[1]??'',512),'description'=>self::text($meta['og:description']??$meta['twitter:description']??$meta['description']??'',2048),'previewType'=>'NONE'];
        $image=$meta['og:image:secure_url']??$meta['og:image']??$meta['twitter:image']??null;
        if($includeThumbnail&&is_string($image)&&$image!==''){
            $image=self::relative($url,$image);self::url($image);$bytes=$this->get($image,2097152);$info=@getimagesizefromstring($bytes);
            if($info===false||$info[0]<1||$info[1]<1||$info[0]>8192||$info[1]>8192||$info[0]*$info[1]>16777216||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true))throw new \InvalidArgumentException('Preview thumbnail must be a bounded JPEG, PNG or WebP image');
            if(!function_exists('imagecreatefromstring')||!function_exists('imagejpeg'))throw new \LogicException('ext-gd is required to prepare preview thumbnails');
            $source=@imagecreatefromstring($bytes);if($source===false)throw new \InvalidArgumentException('Invalid preview image');
            $width=min(640,$info[0]);$height=max(1,(int)round($info[1]*$width/$info[0]));$canvas=imagecreatetruecolor($width,$height);if($canvas===false)throw new \RuntimeException('Cannot allocate preview thumbnail');
            try{imagefill($canvas,0,0,imagecolorallocate($canvas,255,255,255));imagecopyresampled($canvas,$source,0,0,0,0,$width,$height,$info[0],$info[1]);ob_start();try{if(!imagejpeg($canvas,null,80))throw new \RuntimeException('Cannot encode preview thumbnail');$jpeg=ob_get_contents();}finally{ob_end_clean();}}finally{unset($canvas,$source);}
            if(!is_string($jpeg)||strlen($jpeg)>524288)throw new \LengthException('Preview thumbnail exceeds limit');$preview['jpegThumbnail']=$jpeg;$preview['thumbnailWidth']=$width;$preview['thumbnailHeight']=$height;
            if($this->upload!==null){$uploaded=($this->upload)($jpeg);foreach(['directPath'=>'thumbnailDirectPath','fileSha256'=>'thumbnailSha256','fileEncSha256'=>'thumbnailEncSha256','mediaKey'=>'mediaKey','mediaKeyTimestamp'=>'mediaKeyTimestamp']as$from=>$to)if(isset($uploaded[$from]))$preview[$to]=$uploaded[$from];}
        }
        $content=['type'=>'text','text'=>$text,'linkPreview'=>$preview];ContentBuilder::build($content,'preview@s.whatsapp.net');return $content;
    }
    private function get(string $url,int $max):string
    {
        if($this->fetch!==null){$bytes=($this->fetch)($url,$max);if(!is_string($bytes)||$bytes===''||strlen($bytes)>$max)throw new \LengthException('Invalid or oversized preview response');return $bytes;}
        for($redirect=0;$redirect<=3;++$redirect){$host=self::url($url);$ips=[];if(filter_var($host,FILTER_VALIDATE_IP))$ips[]=$host;else foreach(@dns_get_record($host,DNS_A|DNS_AAAA)?:[]as$r){if(isset($r['ip']))$ips[]=$r['ip'];if(isset($r['ipv6']))$ips[]=$r['ipv6'];}
            if($ips===[])throw new \RuntimeException('Preview host did not resolve');foreach($ips as$ip)if(!self::publicIp($ip))throw new \InvalidArgumentException('Preview host resolves to a private or reserved address');
            $curl=curl_init($url);if($curl===false)throw new \RuntimeException('Cannot open preview URL');$body='';$location=null;$headers=0;$exceeded=false;$pin=str_contains($ips[0],':')?'['.$ips[0].']':$ips[0];
            curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':443:'.$pin],CURLOPT_USERAGENT=>'MVO-PHP-LinkPreview/1.0',CURLOPT_HTTPHEADER=>['Accept: text/html,image/*','Accept-Encoding: identity'],CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$headers,&$location):int{$headers+=strlen($line);if($headers>32768)return 0;if(str_starts_with($line,'HTTP/'))$location=null;if(str_starts_with(strtolower($line),'location:'))$location=trim(substr($line,9));return strlen($line);},CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body,&$exceeded,$max):int{if(strlen($body)+strlen($chunk)>$max){$exceeded=true;return 0;}$body.=$chunk;return strlen($chunk);}]);
            try{if(curl_exec($curl)===false){if($exceeded)throw new \LengthException('Preview response exceeds byte limit');throw new \RuntimeException('Preview HTTPS request failed');}$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);}finally{unset($curl);}
            if(in_array($status,[301,302,303,307,308],true)){if($redirect===3||!is_string($location))throw new \RuntimeException('Preview redirect limit or missing destination');$url=self::relative($url,$location);continue;}
            if($status<200||$status>=300||$body==='')throw new \RuntimeException('Preview HTTPS returned an unsuccessful response');return $body;
        }throw new \RuntimeException('Preview fetch aborted');
    }
    public static function url(string $url):string
    {
        $p=parse_url($url);if(strlen($url)>8192||preg_match('/[\x00-\x20\x7f\\\\]/',$url)||$p===false||($p['scheme']??null)!=='https'||!is_string($p['host']??null)||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||isset($p['port'])&&$p['port']!==443)throw new \InvalidArgumentException('Preview requires public HTTPS on port 443 without credentials or fragments');
        $host=strtolower(trim($p['host'],'[]'));if(filter_var($host,FILTER_VALIDATE_IP)){if(!self::publicIp($host))throw new \InvalidArgumentException('Private preview address');}
        elseif(preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$host)!==1||preg_match('/\.(local|localhost|internal|test)$/D',$host))throw new \InvalidArgumentException('Invalid public preview host');return $host;
    }
    private static function publicIp(string $ip):bool
    {
        if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return false;if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){$n=ip2long($ip);foreach([['100.64.0.0',10],['192.0.0.0',24],['192.0.2.0',24],['192.88.99.0',24],['198.18.0.0',15],['198.51.100.0',24],['203.0.113.0',24],['224.0.0.0',4]]as[$net,$bits])if(($n&(-1<<(32-$bits)))===(ip2long($net)&(-1<<(32-$bits))))return false;return true;}$packed=inet_pton($ip);return is_string($packed)&&(ord($packed[0])&0xe0)===0x20&&!str_starts_with($packed,"\x20\x01\x0d\xb8");
    }
    /** @return array<string,string> */
    private static function attributes(string $tag):array
    {
        preg_match_all('~([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+))~s',$tag,$matches,PREG_SET_ORDER);$out=[];foreach($matches as$m){$m=array_pad($m,5,'');$out[strtolower($m[1])]=html_entity_decode($m[2]!==''?$m[2]:($m[3]!==''?$m[3]:$m[4]),ENT_QUOTES|ENT_HTML5,'UTF-8');}return $out;
    }
    private static function text(string $value,int $max):string{$value=trim(html_entity_decode(strip_tags($value),ENT_QUOTES|ENT_HTML5,'UTF-8'));$value=preg_replace('/[\x00-\x1f\x7f]+/',' ',$value)??'';if(preg_match('//u',$value)!==1)return '';$value=substr($value,0,$max);while($value!==''&&preg_match('//u',$value)!==1)$value=substr($value,0,-1);return $value;}
    private static function relative(string $base,string $location):string
    {
        if(str_starts_with($location,'https://'))return $location;$p=parse_url($base);$host=$p['host']??throw new \InvalidArgumentException('Invalid base URL');if(str_starts_with($location,'//'))return 'https:'.$location;if(preg_match('/^[a-z][a-z0-9+.-]*:/i',$location))throw new \InvalidArgumentException('Preview redirect or image must use HTTPS');return 'https://'.$host.(str_starts_with($location,'/')?$location:rtrim(str_replace('\\','/',dirname($p['path']??'/')),'/').'/'.$location);
    }
}
