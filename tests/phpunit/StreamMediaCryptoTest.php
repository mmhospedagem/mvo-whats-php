<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use MVO\Media\{MediaCrypto,StreamMediaCrypto,MediaTransfer};
use MVO\Protocol\BinaryNode;

final class StreamMediaCryptoTest extends TestCase
{
    /** @return iterable<string,array{int}> */
    public static function lengths():iterable { foreach ([1,15,16,17,65520,65535,65536,65537,131056,131072] as $length) yield (string)$length=>[$length]; }
    #[DataProvider('lengths')]
    public function testStreamingCipherMatchesBufferedBytesAndSidecar(int $length):void {
        $plain=str_repeat('z',$length-1)."\xff"; $key=str_repeat('k',32); $source=StreamMediaCrypto::temporary(); fwrite($source,$plain); rewind($source);
        $streamed=StreamMediaCrypto::encrypt($source,'video',$key); $buffered=MediaCrypto::encrypt($plain,'video',$key);
        $body=stream_get_contents($streamed['stream']); self::assertSame($buffered['body'],$body);
        foreach (['fileLength','fileSha256','fileEncSha256'] as $field) self::assertSame($buffered[$field],$streamed[$field]);
        self::assertSame(MediaCrypto::streamingSidecar($body,'video',$key),StreamMediaCrypto::sidecar($streamed['stream'],'video',$key));
        $decrypted=StreamMediaCrypto::decrypt($streamed['stream'],$buffered+['mediaType'=>'video']);
        self::assertSame($plain,stream_get_contents($decrypted)); fclose($decrypted); fclose($streamed['stream']); fclose($source);
    }
    public function testCorruptMacCannotExposePlaintextStream():void {
        $encrypted=MediaCrypto::encrypt('secret','image',str_repeat('k',32)); $body=$encrypted['body']; $body[strlen($body)-1]=$body[strlen($body)-1]^"\x01";
        $stream=StreamMediaCrypto::temporary(); fwrite($stream,$body); rewind($stream);
        try { StreamMediaCrypto::decrypt($stream,['mediaType'=>'image','mediaKey'=>$encrypted['mediaKey']]); self::fail(); }
        catch (\UnexpectedValueException $e) { self::assertSame('Media MAC mismatch',$e->getMessage()); }
        finally { fclose($stream); }
    }
    public function testStreamUploadAndAuthenticatedAtomicDownload():void {
        $encrypted=null; $key=str_repeat('k',32);
        $transfer=new MediaTransfer(static fn()=>new BinaryNode('iq',['type'=>'result'],[new BinaryNode('media_conn',['auth'=>'token','ttl'=>'3600'],[new BinaryNode('host',['hostname'=>'mmg.whatsapp.net'])])]),static function(array $request) use(&$encrypted):array {
            if ($request['method']==='POST') { self::assertIsResource($request['body']); $encrypted=stream_get_contents($request['body']); return ['status'=>200,'body'=>'{"url":"https://mmg.whatsapp.net/media","direct_path":"/media"}']; }
            return ['status'=>200,'body'=>$encrypted];
        });
        $source=StreamMediaCrypto::temporary(); fwrite($source,str_repeat('abc',30000)); rewind($source);
        $descriptor=$transfer->uploadStream($source,'document',['mediaKey'=>$key]); fclose($source);
        $descriptor['mediaType']='document'; $target=tempnam(sys_get_temp_dir(),'mvo-stream-');
        try {
            self::assertSame($target,$transfer->downloadStream($descriptor,$target)); self::assertSame(str_repeat('abc',30000),file_get_contents($target));
            $encrypted[10]=$encrypted[10]^"\x01";
            try { $transfer->downloadStream($descriptor,$target); self::fail(); } catch (\RuntimeException) { self::assertSame(str_repeat('abc',30000),file_get_contents($target)); }
        } finally { unlink($target); }
    }
}
