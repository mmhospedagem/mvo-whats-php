<?php
declare(strict_types=1);
namespace MVO\Media;

/** Bounded-memory CBC/HMAC media processing. Returned temporary streams belong to the caller. */
final class StreamMediaCrypto
{
    /** @param resource $source
     * @return array{stream:resource,mediaKey:string,fileSha256:string,fileEncSha256:string,fileLength:int,encryptedLength:int} */
    public static function encrypt($source,string $type,?string $key=null,int $maxBytes=1073741824):array {
        if (!is_resource($source) || get_resource_type($source)!=='stream' || $maxBytes<1) throw new \InvalidArgumentException('Expected readable media stream');
        $key ??= random_bytes(32); $keys=MediaCrypto::deriveKeys($type,$key);
        $target=self::temporary(); $plainHash=hash_init('sha256'); $encHash=hash_init('sha256'); $mac=hash_init('sha256',HASH_HMAC,$keys['macKey']);
        hash_update($mac,$keys['iv']); $iv=$keys['iv']; $buffer=''; $length=0; $encryptedLength=0;
        try {
            while (!feof($source)) {
                $chunk=fread($source,65536);
                if ($chunk===false || ($chunk==='' && !feof($source))) throw new \RuntimeException('Cannot read media stream');
                $length+=strlen($chunk);
                if ($length>$maxBytes) throw new \LengthException('Media exceeds maxBytes');
                hash_update($plainHash,$chunk); $buffer.=$chunk;
                $blocks=intdiv(strlen($buffer),16)*16;
                if ($blocks===0) continue;
                $cipher=openssl_encrypt(substr($buffer,0,$blocks),'aes-256-cbc',$keys['encKey'],OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING,$iv);
                if ($cipher===false) throw new \RuntimeException('Media encryption failed');
                $buffer=substr($buffer,$blocks); $iv=substr($cipher,-16);
                self::write($target,$cipher); hash_update($mac,$cipher); hash_update($encHash,$cipher); $encryptedLength+=strlen($cipher);
            }
            if ($length===0) throw new \InvalidArgumentException('Cannot upload empty media');
            $padding=16-strlen($buffer);
            $cipher=openssl_encrypt($buffer.str_repeat(chr($padding),$padding),'aes-256-cbc',$keys['encKey'],OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING,$iv);
            if ($cipher===false) throw new \RuntimeException('Media encryption failed');
            self::write($target,$cipher); hash_update($mac,$cipher); hash_update($encHash,$cipher); $encryptedLength+=strlen($cipher);
            $tag=substr(hash_final($mac,true),0,10); self::write($target,$tag); hash_update($encHash,$tag); $encryptedLength+=10;
            rewind($target);
            return ['stream'=>$target,'mediaKey'=>$key,'fileSha256'=>hash_final($plainHash,true),'fileEncSha256'=>hash_final($encHash,true),'fileLength'=>$length,'encryptedLength'=>$encryptedLength];
        } catch (\Throwable $e) { fclose($target); throw $e; }
    }
    /** Requires a seekable encrypted stream. Authenticates the entire file before decrypting.
     * @param resource $source
     * @param array<string,mixed> $descriptor
     * @return resource Verified plaintext temporary stream at offset zero. */
    public static function decrypt($source,array $descriptor,int $maxBytes=1073741824) {
        if (!is_resource($source) || get_resource_type($source)!=='stream' || !stream_get_meta_data($source)['seekable']) throw new \InvalidArgumentException('Encrypted media stream must be seekable');
        $type=$descriptor['mediaType'] ?? null; $key=$descriptor['mediaKey'] ?? null;
        if (!is_string($type) || !is_string($key)) throw new \InvalidArgumentException('Missing media key/type');
        $keys=MediaCrypto::deriveKeys($type,$key);
        foreach (['fileSha256','fileEncSha256'] as $field) if (isset($descriptor[$field]) && (!is_string($descriptor[$field]) || strlen($descriptor[$field])!==32)) throw new \InvalidArgumentException('Invalid media '.$field);
        if (isset($descriptor['fileLength']) && (!is_int($descriptor['fileLength']) || $descriptor['fileLength']<0 || $descriptor['fileLength']>$maxBytes)) throw new \LengthException('Invalid media length');
        if (fseek($source,0,SEEK_END)!==0) throw new \RuntimeException('Cannot seek media stream');
        $total=ftell($source);
        if ($total===false || $total<26 || ($total-10)%16!==0 || $total>$maxBytes+26) throw new \UnexpectedValueException('Invalid encrypted media length');
        $cipherLength=$total-10; rewind($source); $encHash=hash_init('sha256'); $mac=hash_init('sha256',HASH_HMAC,$keys['macKey']); hash_update($mac,$keys['iv']);
        $remaining=$cipherLength;
        while ($remaining>0) { $chunk=self::read($source,min(65536,$remaining)); $remaining-=strlen($chunk); hash_update($mac,$chunk); hash_update($encHash,$chunk); }
        $tag=self::read($source,10); hash_update($encHash,$tag);
        if (isset($descriptor['fileEncSha256']) && !hash_equals($descriptor['fileEncSha256'],hash_final($encHash,true))) throw new \UnexpectedValueException('Encrypted hash mismatch');
        if (!hash_equals(substr(hash_final($mac,true),0,10),$tag)) throw new \UnexpectedValueException('Media MAC mismatch');
        rewind($source); $target=self::temporary(); $plainHash=hash_init('sha256'); $remaining=$cipherLength; $iv=$keys['iv']; $length=0;
        try {
            while ($remaining>0) {
                $chunk=self::read($source,min(65536,$remaining)); $remaining-=strlen($chunk);
                $plain=openssl_decrypt($chunk,'aes-256-cbc',$keys['encKey'],OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING,$iv);
                if ($plain===false) throw new \UnexpectedValueException('Media decryption failed'); $iv=substr($chunk,-16);
                if ($remaining===0) {
                    $pad=ord($plain[strlen($plain)-1]);
                    if ($pad<1 || $pad>16 || !hash_equals(str_repeat(chr($pad),$pad),substr($plain,-$pad))) throw new \UnexpectedValueException('Invalid media padding');
                    $plain=substr($plain,0,-$pad);
                }
                $length+=strlen($plain); if ($length>$maxBytes) throw new \LengthException('Media exceeds maxBytes');
                self::write($target,$plain); hash_update($plainHash,$plain);
            }
            if (isset($descriptor['fileSha256']) && !hash_equals($descriptor['fileSha256'],hash_final($plainHash,true))) throw new \UnexpectedValueException('Plaintext hash mismatch');
            if (isset($descriptor['fileLength']) && $length!==$descriptor['fileLength']) throw new \UnexpectedValueException('Media plaintext length mismatch');
            rewind($target); return $target;
        } catch (\Throwable $e) { fclose($target); throw $e; }
    }
    /** @param resource $stream
     * @return string Small sidecar, 10 bytes per 64 KiB. */
    public static function sidecar($stream,string $type,string $key):string {
        $keys=MediaCrypto::deriveKeys($type,$key); $stat=fstat($stream);
        if ($stat===false || !stream_get_meta_data($stream)['seekable']) throw new \InvalidArgumentException('Seekable media stream required');
        $length=$stat['size']+16; $result='';
        for ($offset=0;$offset<$length;$offset+=65536) {
            if ($offset===0) { rewind($stream); $chunk=$keys['iv'].self::read($stream,min(65536,$stat['size'])); }
            else { fseek($stream,$offset-16); $chunk=self::read($stream,min(65552,$length-$offset)); }
            $result.=substr(hash_hmac('sha256',$chunk,$keys['macKey'],true),0,10);
        }
        rewind($stream); return $result;
    }
    /** @return resource */
    public static function temporary() { $stream=tmpfile(); if ($stream===false) throw new \RuntimeException('Cannot create media temporary file'); return $stream; }
    /** @param resource $stream */
    public static function write($stream,string $bytes):void {
        for ($offset=0,$length=strlen($bytes);$offset<$length;) { $written=fwrite($stream,substr($bytes,$offset)); if ($written===false || $written===0) throw new \RuntimeException('Cannot write media stream'); $offset+=$written; }
    }
    /** @param resource $stream */
    private static function read($stream,int $length):string {
        $result='';
        while (strlen($result)<$length) { $chunk=fread($stream,$length-strlen($result)); if ($chunk===false || $chunk==='') throw new \UnexpectedValueException('Truncated media stream'); $result.=$chunk; }
        return $result;
    }
}
