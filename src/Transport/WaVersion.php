<?php
declare(strict_types=1);
namespace MVO\Transport;

final class WaVersion
{
    public static function validate(string $version):void {
        if(!preg_match('/^[0-9]+(?:\.[0-9]+){2,4}$/D',$version))throw new \InvalidArgumentException('Invalid WhatsApp Web version');
        foreach(explode('.',$version) as $component)if(filter_var($component,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>0xffffffff]])===false)throw new \InvalidArgumentException('Invalid WhatsApp Web version component');
    }
    public static function fromServiceWorker(string $source):string {
        $source=str_replace('\\"','"',$source);
        if (!preg_match('/"client_revision"\s*:\s*([1-9][0-9]{0,14})(?![0-9])/',$source,$match)) throw new \UnexpectedValueException('client_revision not found in WhatsApp sw.js');
        $version='2.3000.'.$match[1];self::validate($version);return $version;
    }
    public static function fetch(?string $proxy=null):string {
        $curl=curl_init('https://web.whatsapp.com/sw.js'); $body='';
        curl_setopt_array($curl,[CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'Mozilla/5.0',CURLOPT_WRITEFUNCTION=>static function(\CurlHandle $handle,string $chunk) use (&$body):int { if (strlen($body)+strlen($chunk)>8*1024*1024) return 0; $body.=$chunk; return strlen($chunk); }]);
        if ($proxy!==null) curl_setopt($curl,CURLOPT_PROXY,$proxy);
        try {
            if (curl_exec($curl)===false) throw new \RuntimeException('Cannot fetch WhatsApp version: '.curl_error($curl));
            if (curl_getinfo($curl,CURLINFO_RESPONSE_CODE)!==200) throw new \RuntimeException('WhatsApp version source refused request');
            return self::fromServiceWorker($body);
        } finally { curl_close($curl); }
    }
}
