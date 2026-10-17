<?php
declare(strict_types=1);

namespace MVO\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Media\MediaCrypto;
use MVO\Media\MediaTransfer;
use MVO\Protocol\BinaryNode;

final class MediaTransferTest extends TestCase
{
    private int $queries = 0;
    /** @var list<array<string,mixed>> */
    private array $requests = [];
    /** @var array<string,string> */
    private array $uploaded = [];

    public function testUploadUsesEncryptedHashRoutingAndCachesConnection(): void
    {
        $media = $this->fixture();
        $descriptor = $media->upload('secret bytes','image',['mediaKey'=>str_repeat('K',32)]);
        $encrypted = MediaCrypto::encrypt('secret bytes','image',str_repeat('K',32));
        $token = rtrim(strtr(base64_encode($encrypted['fileEncSha256']),'+/','-_'),'=');
        self::assertSame($encrypted['body'],$this->requests[0]['body']);
        self::assertStringStartsWith('https://mmg.whatsapp.net/mms/image/'.$token.'?auth=auth%2B%2F%20one&token='.$token,$this->requests[0]['url']);
        self::assertSame('application/octet-stream',$this->requests[0]['headers']['Content-Type']);
        self::assertSame(strlen($encrypted['body']),(int)$this->requests[0]['headers']['Content-Length']);
        self::assertSame(hash('sha256','secret bytes',true),$descriptor['fileSha256']);
        self::assertArrayNotHasKey('body',$descriptor);
        $media->upload('another file','document');
        self::assertSame(1,$this->queries);
        $media->clearConnection();
        $media->upload('another file','document');
        self::assertSame(2,$this->queries);
    }

    public function testDownloadValidatesAndWritesOnlyAuthenticatedPlaintext(): void
    {
        $media = $this->fixture();
        $plain = "plain\0media\xff";
        $descriptor = $media->upload($plain,'document') + ['mediaType'=>'document'];
        self::assertSame($plain,$media->download($descriptor));
        $path = sys_get_temp_dir().'/mvo-verified-'.bin2hex(random_bytes(6));
        try {
            self::assertSame($path,$media->download($descriptor,$path));
            self::assertSame($plain,file_get_contents($path));
            foreach ($this->uploaded as &$body) $body[0] = chr(ord($body[0])^1);
            unset($body);
            try {
                $media->download($descriptor,$path);
                self::fail('Corruption should fail');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Encrypted hash mismatch',$error->getMessage());
            }
            self::assertSame($plain,file_get_contents($path));
        } finally {
            if (is_file($path)) unlink($path);
        }
    }

    #[DataProvider('integrityFailures')]
    public function testDownloadRejectsIntegrityFailure(string $failure, string $expected): void
    {
        $media = $this->fixture();
        $descriptor = $media->upload('plaintext media','audio') + ['mediaType'=>'audio'];
        if ($failure === 'mac') {
            foreach ($this->uploaded as &$body) $body[strlen($body)-1] = chr(ord($body[strlen($body)-1])^1);
            unset($body,$descriptor['fileEncSha256']);
        } elseif ($failure === 'plainHash') $descriptor['fileSha256'] = str_repeat('X',32);
        elseif ($failure === 'length') $descriptor['fileLength']++;
        elseif ($failure === 'key') $descriptor['mediaKey'] = str_repeat('X',32);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($expected);
        $media->download($descriptor);
    }

    public static function integrityFailures(): iterable
    {
        yield 'MAC'=>['mac','Media MAC mismatch'];
        yield 'plaintext hash'=>['plainHash','Plaintext hash mismatch'];
        yield 'plaintext length'=>['length','length mismatch'];
        yield 'wrong key'=>['key','Media MAC mismatch'];
    }

    public function testUploadRefreshesExpiredAuthenticationOnlyOnce(): void
    {
        $calls = 0;
        $media = new MediaTransfer($this->query(...),static function(array $request) use (&$calls):array {
            $calls++;
            return $calls===1 ? ['status'=>403,'body'=>'expired'] : ['status'=>200,'body'=>'{"url":"https://mmg.whatsapp.net/media","direct_path":"/media"}'];
        });
        $media->upload('hello','image');
        self::assertSame(2,$calls);
        self::assertSame(2,$this->queries);
    }

    public function testUploadFailsOverToFallbackHost(): void
    {
        $calls = [];
        $media = new MediaTransfer($this->query(...),static function(array $request) use (&$calls):array {
            $calls[] = $request['url'];
            if (count($calls)===1) throw new \RuntimeException('network failed');
            return ['status'=>200,'body'=>'{"url":"https://mmg-fallback.whatsapp.net/media","direct_path":"/media"}'];
        });
        $media->upload('hello','image');
        self::assertCount(2,$calls);
        self::assertStringStartsWith('https://mmg-fallback.whatsapp.net/',$calls[1]);
    }

    public function testAbsoluteExpiredConnectionIsRefreshed(): void
    {
        $media = new MediaTransfer(function(BinaryNode $node):BinaryNode {
            $response = $this->query($node);
            $connection = $response->child('media_conn');
            return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('media_conn',['auth'=>'one','ttl'=>(string)(time()+5)],$connection->content)]);
        }, static fn(array $request):array=>['status'=>200,'body'=>'{"url":"https://mmg.whatsapp.net/media","direct_path":"/media"}']);
        $media->upload('hello','image');
        $media->upload('second','image');
        self::assertSame(2,$this->queries);
    }

    #[DataProvider('invalidConnections')]
    public function testRejectsInvalidMediaConnection(BinaryNode $connection): void
    {
        $media = new MediaTransfer(static fn(BinaryNode $node):BinaryNode=>$connection,static function(array $request):array { self::fail('HTTP must not be reached'); });
        $this->expectException(\Throwable::class);
        $media->upload('hello','image');
    }

    public static function invalidConnections(): iterable
    {
        yield 'IQ error'=>[new BinaryNode('iq',['type'=>'error'])];
        yield 'missing node'=>[new BinaryNode('iq',['type'=>'result'])];
        yield 'missing auth'=>[new BinaryNode('iq',['type'=>'result'],[new BinaryNode('media_conn',['ttl'=>'100'])])];
        yield 'invalid ttl'=>[new BinaryNode('iq',['type'=>'result'],[new BinaryNode('media_conn',['auth'=>'A','ttl'=>'0'])])];
        yield 'no hosts'=>[new BinaryNode('iq',['type'=>'result'],[new BinaryNode('media_conn',['auth'=>'A','ttl'=>'100'])])];
        yield 'untrusted host'=>[new BinaryNode('iq',['type'=>'result'],[new BinaryNode('media_conn',['auth'=>'A','ttl'=>'100'],[new BinaryNode('host',['hostname'=>'127.0.0.1'])])])];
    }

    #[DataProvider('unsafeUrls')]
    public function testDownloadRejectsUnsafeUrlsWithoutHttp(string $url): void
    {
        $media = $this->fixture();
        $this->expectException(\InvalidArgumentException::class);
        try { $media->download(['mediaType'=>'image','mediaKey'=>str_repeat('K',32),'url'=>$url]); }
        finally { self::assertSame([],$this->requests); }
    }

    public static function unsafeUrls(): iterable
    {
        foreach (['http://mmg.whatsapp.net/x','https://localhost/x','https://mmg.whatsapp.net.evil.test/x','https://user@mmg.whatsapp.net/x','https://mmg.whatsapp.net:8443/x','https://mmg.whatsapp.net/x#fragment',"https://mmg.whatsapp.net/x\r\ny",'file:///secret'] as $url) yield [$url];
    }

    public function testUploadAndDownloadRespectConfiguredSizeLimit(): void
    {
        $media = $this->fixture(4);
        try { $media->upload('12345','document'); self::fail('Expected size failure'); }
        catch (\LengthException) { self::assertSame([],$this->requests); }
        $this->expectException(\LengthException::class);
        $media->download(['mediaType'=>'document','mediaKey'=>str_repeat('K',32),'directPath'=>'/media','fileLength'=>5]);
    }

    public function testOversizedDownloadResponseIsRejectedBeforeDecrypt(): void
    {
        $media = new MediaTransfer($this->query(...),static fn(array $request):array=>['status'=>200,'body'=>str_repeat('x',100)],8);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('response exceeds maxBytes');
        $media->download(['mediaType'=>'document','mediaKey'=>str_repeat('K',32),'directPath'=>'/media']);
    }

    #[DataProvider('invalidUploadReplies')]
    public function testInvalidUploadResponsesAreNotSuccessful(int $status,string $body): void
    {
        $media = new MediaTransfer($this->query(...),static fn(array $request):array=>['status'=>$status,'body'=>$body]);
        $this->expectException(\RuntimeException::class);
        $media->upload('plain','image');
    }

    public static function invalidUploadReplies(): iterable
    {
        yield 'server error'=>[503,'unavailable'];
        yield 'invalid JSON'=>[200,'invalid'];
        yield 'missing paths'=>[200,'{}'];
        yield 'unsafe returned URL'=>[200,'{"url":"https://evil.test/x","direct_path":"/media"}'];
        yield 'unsafe returned path'=>[200,'{"url":"https://mmg.whatsapp.net/x","direct_path":"//evil.test"}'];
    }

    public function testPayloadUnwrapsViewOnceAndIdentifiesVoiceNote(): void
    {
        $descriptor = ['mediaKey'=>str_repeat('K',32),'directPath'=>'/media','ptt'=>true,'fileLength'=>12];
        $message = ['ephemeralMessage'=>['message'=>['viewOnceMessageV2'=>['message'=>['audioMessage'=>$descriptor]]]]];
        self::assertSame($descriptor['mediaKey'],MediaTransfer::resolvePayload($message)['mediaKey']);
        self::assertSame('ptt',MediaTransfer::resolvePayload($message)['mediaType']);
        self::assertSame('ptt',MediaTransfer::resolvePayload(['botInvokeMessage'=>['message'=>['audioMessage'=>$descriptor]]])['mediaType']);
        self::assertNull(MediaTransfer::resolvePayload(['conversation'=>'hello']));
    }

    public function testSidecarsFollowIndependentOverlappingHmacComputation(): void
    {
        $media = $this->fixture(200000);
        $descriptor = $media->upload(str_repeat('X',140000),'video',['sidecar'=>true,'firstFrameLength'=>43]);
        $body = array_values($this->uploaded)[0];
        $keys = MediaCrypto::deriveKeys('video',$descriptor['mediaKey']);
        $data = $keys['iv'].$body;
        $expected = '';
        for ($offset=0;$offset<strlen($data);$offset+=65536) $expected .= substr(hash_hmac('sha256',substr($data,$offset,65552),$keys['macKey'],true),0,10);
        self::assertSame($expected,$descriptor['streamingSidecar']);
        self::assertSame(substr(hash_hmac('sha256',$keys['iv'].substr($body,0,48),$keys['macKey'],true),0,10),$descriptor['firstFrameSidecar']);
        $mp4 = pack('N',16).'ftypisom'.pack('N',0).pack('N',8).'mdat';
        self::assertFalse(MediaCrypto::isStreamable($mp4));
        $descriptor = $media->upload($mp4,'video',['sidecar'=>true]);
        self::assertArrayNotHasKey('streamingSidecar',$descriptor);
    }

    private function fixture(int $limit = 33554432): MediaTransfer
    {
        return new MediaTransfer($this->query(...),function(array $request):array {
            $this->requests[] = $request;
            $path = parse_url($request['url'],PHP_URL_PATH);
            if ($request['method']==='POST') {
                $token = basename($path);
                $direct = '/media/'.$token;
                $this->uploaded[$direct] = $request['body'];
                return ['status'=>200,'body'=>json_encode(['url'=>'https://mmg.whatsapp.net'.$direct,'direct_path'=>$direct],JSON_THROW_ON_ERROR)];
            }
            return ['status'=>200,'body'=>$this->uploaded[$path] ?? ''];
        },$limit);
    }

    private function query(BinaryNode $node): BinaryNode
    {
        $this->queries++;
        self::assertSame('set',$node->attrs['type']);
        self::assertSame('w:m',$node->attrs['xmlns']);
        self::assertNotNull($node->child('media_conn'));
        return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('media_conn',['auth'=>'auth+/ one','ttl'=>'3600'],[
            new BinaryNode('host',['hostname'=>'mmg-fallback.whatsapp.net','type'=>'fallback']),
            new BinaryNode('host',['hostname'=>'mmg.whatsapp.net']),
        ])]);
    }
}
