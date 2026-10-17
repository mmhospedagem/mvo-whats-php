<?php
declare(strict_types=1);
namespace MVO\Media;
use MVO\Crypto\Crypto;
final class MediaCrypto
{
    /** @return array{iv:string,encKey:string,macKey:string,refKey:string} */
    public static function deriveKeys(string $type, string $key): array {
        Crypto::length($key,32);
        $info = match($type) {
            'image','sticker','xma-image'=>'WhatsApp Image Keys','video','gif','ptv'=>'WhatsApp Video Keys',
            'audio','ptt'=>'WhatsApp Audio Keys','document'=>'WhatsApp Document Keys',
            'history','md-msg-hist'=>'WhatsApp History Keys','md-app-state'=>'WhatsApp App State Keys',
            'group-history'=>'Group History','sticker-pack'=>'WhatsApp Sticker Pack Keys',
            'thumbnail-sticker-pack'=>'WhatsApp Sticker Pack Thumbnail Keys','thumbnail-link'=>'WhatsApp Link Thumbnail Keys',
            default=>throw new \InvalidArgumentException('Unsupported media type')
        };
        $expanded = Crypto::hkdf($key,112,$info);
        return ['iv'=>substr($expanded,0,16),'encKey'=>substr($expanded,16,32),'macKey'=>substr($expanded,48,32),'refKey'=>substr($expanded,80,32)];
    }
    /** @return array{mediaKey:string,body:string,fileSha256:string,fileEncSha256:string,fileLength:int} */
    public static function encrypt(string $plain, string $type, ?string $key = null): array {
        $key ??= random_bytes(32); $k = self::deriveKeys($type,$key);
        $cipher = openssl_encrypt($plain,'aes-256-cbc',$k['encKey'],OPENSSL_RAW_DATA,$k['iv']);
        if ($cipher === false) throw new \RuntimeException('Media encryption failed');
        $body = $cipher.substr(Crypto::hmac($k['macKey'],$k['iv'].$cipher),0,10);
        return ['mediaKey'=>$key,'body'=>$body,'fileSha256'=>Crypto::sha256($plain),'fileEncSha256'=>Crypto::sha256($body),'fileLength'=>strlen($plain)];
    }
    public static function decrypt(string $body, string $type, string $key, ?string $fileSha256 = null, ?string $fileEncSha256 = null): string {
        $k = self::deriveKeys($type,$key); $length = strlen($body)-10;
        if ($length < 16 || $length % 16 !== 0) throw new \UnexpectedValueException('Invalid media length');
        if ($fileEncSha256 !== null && !hash_equals($fileEncSha256,Crypto::sha256($body))) throw new \UnexpectedValueException('Encrypted hash mismatch');
        $cipher = substr($body,0,-10); $mac = substr($body,-10);
        if (!hash_equals(substr(Crypto::hmac($k['macKey'],$k['iv'].$cipher),0,10),$mac)) throw new \UnexpectedValueException('Media MAC mismatch');
        $plain = openssl_decrypt($cipher,'aes-256-cbc',$k['encKey'],OPENSSL_RAW_DATA,$k['iv']);
        if ($plain === false) throw new \UnexpectedValueException('Invalid media padding');
        if ($fileSha256 !== null && !hash_equals($fileSha256,Crypto::sha256($plain))) throw new \UnexpectedValueException('Plaintext hash mismatch');
        return $plain;
    }

    /** HMACs of overlapping 64 KiB windows, including the IV and final MAC. */
    public static function streamingSidecar(string $body, string $type, string $key): string {
        $keys = self::deriveKeys($type,$key);
        $data = $keys['iv'].$body;
        $result = '';
        for ($offset = 0, $length = strlen($data); $offset < $length; $offset += 65536) {
            $result .= substr(Crypto::hmac($keys['macKey'],substr($data,$offset,65552)),0,10);
        }
        return $result;
    }

    public static function firstFrameSidecar(string $body, string $type, string $key, int $firstFrameLength): string {
        if ($firstFrameLength <= 0 || $firstFrameLength > strlen($body)-10) throw new \InvalidArgumentException('Invalid firstFrameLength');
        $keys = self::deriveKeys($type,$key);
        $aligned = (int) (ceil($firstFrameLength/16)*16);
        return substr(Crypto::hmac($keys['macKey'],$keys['iv'].substr($body,0,$aligned)),0,10);
    }

    /** MP4/MOV can seek progressively only when moov occurs before mdat. */
    public static function isStreamable(string $plain): bool {
        if (strlen($plain) < 8 || substr($plain,4,4) !== 'ftyp') return true;
        $offset = 0;
        while ($offset+8 <= strlen($plain) && $offset <= 1024) {
            $type = substr($plain,$offset+4,4);
            if ($type === 'moov') return true;
            if ($type === 'mdat') return false;
            $size = unpack('Nsize',substr($plain,$offset,4))['size'];
            if ($size === 1) {
                if ($offset+16 > strlen($plain)) return true;
                $large = unpack('Nhigh/Nlow',substr($plain,$offset+8,8));
                if ($large['high'] !== 0) return true;
                $size = $large['low'];
                if ($size < 16) return true;
            } elseif ($size < 8) return true;
            $offset += $size;
        }
        return true;
    }
}
