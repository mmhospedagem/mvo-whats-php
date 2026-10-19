<?php
declare(strict_types=1);

namespace MVO\Messenger\Transport;

// Facebook owns this synthetic dependency-boundary fixture. The guard permits
// jointly running Messenger tests that already installed the equivalent seam.
if (!class_exists(CurlTransportCapture::class, false)) {
    /** Unit boundary capture: outside an explicit active fixture, native cURL is used. */
    final class CurlTransportCapture
    {
        public static bool $active = false;
        /** @var array<int,mixed> */ public static array $options = [];
        /** @var list<string> */ public static array $headers = [];
        public static string $body = '';
        public static int $status = 200;
        public static string $uploadedBody = '';
    }

    function curl_init(?string $url=null):\CurlHandle|false
    {
        if(CurlTransportCapture::$active)CurlTransportCapture::$options=[];
        return \curl_init($url);
    }

    /** @param array<int,mixed> $options */
    function curl_setopt_array(\CurlHandle $handle, array $options): bool
    {
        if (!CurlTransportCapture::$active) return \curl_setopt_array($handle, $options);
        CurlTransportCapture::$options = array_replace(CurlTransportCapture::$options,$options);
        return true;
    }
    function curl_setopt(\CurlHandle $handle, int $option, mixed $value): bool
    {
        if (!CurlTransportCapture::$active) return \curl_setopt($handle, $option, $value);
        CurlTransportCapture::$options[$option] = $value;
        return true;
    }
    function curl_exec(\CurlHandle $handle): string|bool
    {
        if (!CurlTransportCapture::$active) return \curl_exec($handle);
        if((CurlTransportCapture::$options[CURLOPT_UPLOAD]??false)===true){
            CurlTransportCapture::$uploadedBody='';
            do{
                $chunk=(CurlTransportCapture::$options[CURLOPT_READFUNCTION])($handle,null,4);
                CurlTransportCapture::$uploadedBody.=$chunk;
            }while($chunk!=='');
        }
        foreach (CurlTransportCapture::$headers as $line) {
            if ((CurlTransportCapture::$options[CURLOPT_HEADERFUNCTION])($handle, $line) !== strlen($line)) return false;
        }
        return (CurlTransportCapture::$options[CURLOPT_WRITEFUNCTION])($handle, CurlTransportCapture::$body) === strlen(CurlTransportCapture::$body);
    }
    function curl_getinfo(\CurlHandle $handle, ?int $option = null): mixed
    {
        if (!CurlTransportCapture::$active) return $option === null ? \curl_getinfo($handle) : \curl_getinfo($handle, $option);
        return $option === CURLINFO_RESPONSE_CODE ? CurlTransportCapture::$status : null;
    }

}
