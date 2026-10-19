<?php
declare(strict_types=1);
namespace MVO\Messenger\Transport;

use MVO\Messenger\Protocol\BootstrapParser;

/** Loads inert public bundles only, without account cookies or executing their JavaScript. */
final class PublicBundleLoader
{
    /** @param list<string> $urls
     * @return list<string>
     */
    public function loadVersionBundles(array $urls): array
    {
        return $this->load($urls,static fn(string $source):bool=>BootstrapParser::extractVersion($source)!==null);
    }
    /** @param list<string> $urls
     * @return list<string>
     */
    public function loadTwoFactorBundles(array $urls):array
    {
        return $this->load($urls,static fn(string $source):bool=>str_contains($source,'useTwoFactorLoginValidateCodeMutation_facebookRelayOperation'));
    }
    /** @param list<string> $urls
     * @param callable(string):bool $containsTarget
     * @return list<string>
     */
    private function load(array $urls,callable $containsTarget):array
    {
        $scripts=[];$total=0;$deadline=microtime(true)+20;
        foreach(array_slice($urls,0,32) as $url){
            $remaining=$deadline-microtime(true);if($remaining<=0)break;
            $parts=parse_url($url);$host=strtolower($parts['host']??'');
            if (($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||
                (isset($parts['port'])&&$parts['port']!==443)||!($host==='static.xx.fbcdn.net'||str_ends_with($host,'.fbcdn.net'))||!str_starts_with($parts['path']??'','/rsrc.php/')) continue;
            $curl=curl_init($url);if($curl===false)break;$body='';
            curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT_MS=>(int)ceil(min(5,$remaining)*1000),CURLOPT_TIMEOUT_MS=>(int)ceil(min(10,$remaining)*1000),
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_USERAGENT=>CurlHttpClient::USER_AGENT,CURLOPT_ENCODING=>'',
                CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body,&$total):int{
                    if(strlen($body)+strlen($chunk)>8388608||$total+strlen($chunk)>33554432)return 0;
                    $body.=$chunk;$total+=strlen($chunk);return strlen($chunk);
                }]);
            try{$ok=curl_exec($curl)!==false&&(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE)===200;}finally{unset($curl);}
            if($ok){$scripts[]=$body;if($containsTarget($body))break;}
            if($total>=33554432)break;
        }
        return $scripts;
    }
}
