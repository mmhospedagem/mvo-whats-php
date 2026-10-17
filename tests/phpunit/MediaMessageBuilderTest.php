<?php
declare(strict_types=1);

namespace MVO\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Media\MediaCrypto;
use MVO\Media\MediaMetadata;
use MVO\Media\MediaSource;
use MVO\Media\MediaTransfer;
use MVO\Media\StickerPackZip;
use MVO\Message\ContentBuilder;
use MVO\Message\ContentDecoder;
use MVO\Protocol\BinaryNode;

final class MediaMessageBuilderTest extends TestCase
{
    /** @var list<array<string,mixed>> */
    private array $requests = [];

    #[DataProvider('messageKinds')]
    public function testPreparesAllMediaKindsWithCorrectEnvelopeAndCryptoDomain(string $type,string $field,string $mime,string $path): void
    {
        $media = $this->fixture();
        $plain = "media binary\0";
        $message = $media->prepare(['type'=>$type,'media'=>['bytes'=>$plain],'mimetype'=>$mime,'caption'=>'Caption','seconds'=>5,'width'=>100,'height'=>80,'fileName'=>'file.pdf','jpegThumbnail'=>'jpeg','waveform'=>'wave']);
        self::assertArrayHasKey($field,$message);
        $fields = $message[$field];
        self::assertSame($mime,$fields['mimetype']);
        self::assertSame(strlen($plain),$fields['fileLength']);
        self::assertStringContainsString($path,$this->requests[0]['url']);
        self::assertSame($plain,MediaCrypto::decrypt($this->requests[0]['body'],$type,$fields['mediaKey'],$fields['fileSha256'],$fields['fileEncSha256']));
        self::assertSame($type,MediaTransfer::resolvePayload($message)['mediaType']);
        $built = ContentBuilder::build(['type'=>'raw','message'=>$message],'5511999999999@s.whatsapp.net');
        $decoded = ContentDecoder::decode($built['bytes']);
        self::assertSame($type,$decoded['kind']);
        self::assertSame($fields['mediaKey'],$decoded['data']['mediaKey']);
        self::assertSame($type,$built['encAttrs']['mediatype']);
        if ($type==='gif') self::assertTrue($fields['gifPlayback']);
        if ($type==='ptt') self::assertTrue($fields['ptt']);
        if ($type==='document') self::assertSame('file.pdf',$fields['fileName']);
        if ($type==='sticker') self::assertArrayHasKey('stickerSentTs',$fields);
    }

    public static function messageKinds(): iterable
    {
        yield ['image','imageMessage','image/jpeg','/mms/image/'];
        yield ['video','videoMessage','video/mp4','/mms/video/'];
        yield ['gif','videoMessage','video/mp4','/mms/gif/'];
        yield ['ptv','ptvMessage','video/mp4','/mms/video/'];
        yield ['audio','audioMessage','audio/mpeg','/mms/audio/'];
        yield ['ptt','audioMessage','audio/ogg; codecs=opus','/mms/ptt/'];
        yield ['document','documentMessage','application/pdf','/mms/document/'];
        yield ['sticker','stickerMessage','image/webp','/mms/sticker/'];
    }

    public function testNativeImageDimensionsAndMimeAreDetected(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aYuQAAAAASUVORK5CYII=',true);
        $fields = $this->fixture()->prepare(['type'=>'image','media'=>['bytes'=>$png]])['imageMessage'];
        self::assertSame(1,$fields['width']);
        self::assertSame(1,$fields['height']);
        self::assertSame('image/png',$fields['mimetype']);
    }

    public function testNativeMp4DurationDimensionsAndWavDurationAreDetected(): void
    {
        $box = static fn(string $type,string $payload):string=>pack('N',strlen($payload)+8).$type.$payload;
        $mvhd = str_repeat("\0",12).pack('N2',1000,2500).str_repeat("\0",80);
        $tkhd = str_repeat("\0",76).pack('N2',640*65536,480*65536);
        $mp4 = $box('ftyp','isom'.pack('N',0)).$box('moov',$box('mvhd',$mvhd).$box('trak',$box('tkhd',$tkhd))).$box('mdat','video');
        self::assertSame(['seconds'=>3,'width'=>640,'height'=>480],MediaMetadata::probe($mp4,'video'));
        $fmt = pack('vvVVvv',1,1,8000,16000,2,16);
        $wav = 'RIFF'.pack('V',36+32000).'WAVEfmt '.pack('V',16).$fmt.'data'.pack('V',32000).str_repeat("\0",32000);
        self::assertSame(2,MediaMetadata::probe($wav,'audio')['seconds']);
    }

    public function testAnimatedWebpIncludesFirstFrameAuthentication(): void
    {
        $webp = 'RIFF'.pack('V',42).'WEBPVP8X'.pack('V',10).str_repeat("\0",10).'ANMF'.pack('V',12).str_repeat('x',12);
        $fields = $this->fixture()->prepare(['type'=>'sticker','media'=>['bytes'=>$webp]])['stickerMessage'];
        self::assertTrue($fields['isAnimated']);
        self::assertSame(strlen($webp),$fields['firstFrameLength']);
        self::assertSame(10,strlen($fields['firstFrameSidecar']));
    }

    public function testDocumentUsesOriginalLocalFileNameAndStreamsAreSupported(): void
    {
        $file = tempnam(sys_get_temp_dir(),'mvo-doc-');
        file_put_contents($file,'%PDF-1.4 document');
        try {
            $fields = $this->fixture()->prepare(['type'=>'document','media'=>$file])['documentMessage'];
            self::assertSame(basename($file),$fields['fileName']);
            self::assertSame('application/pdf',$fields['mimetype']);
        } finally { unlink($file); }
        $stream = fopen('php://temp','w+b');
        fwrite($stream,'stream bytes');
        rewind($stream);
        try {
            $fields = $this->fixture()->prepare(['type'=>'document','media'=>$stream])['documentMessage'];
            self::assertSame(12,$fields['fileLength']);
            self::assertIsResource($stream);
        } finally { fclose($stream); }
    }

    public function testGifSourceMustAlreadyBeConvertedToMp4(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('require MP4');
        try { $this->fixture()->prepare(['type'=>'gif','media'=>['bytes'=>'GIF89a image']]); }
        finally { self::assertSame([],$this->requests); }
    }

    #[DataProvider('invalidSources')]
    public function testMediaDoesNotImplicitlyFetchUrlsOrTreatStringsAsBytes(mixed $source): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MediaSource::read($source,1000);
    }

    public static function invalidSources(): iterable
    {
        yield ['https://example.test/image.jpg'];
        yield ['file:///secret'];
        yield ['data://text/plain,secret'];
        yield ['raw bytes are not a path'];
        yield [['url'=>'https://example.test/image.jpg']];
        yield [null];
    }

    public function testSourceStreamLimitDoesNotCloseCallerStream(): void
    {
        $stream = fopen('php://temp','w+b');
        fwrite($stream,'12345'); rewind($stream);
        try {
            $this->expectException(\LengthException::class);
            MediaSource::read($stream,4);
        } finally {
            self::assertIsResource($stream);
            fclose($stream);
        }
    }

    public function testStickerPackUploadsZipAndSeparateThumbnailUnderSharedMediaKey(): void
    {
        $pack = $this->fixture()->prepare($this->pack())['stickerPackMessage'];
        self::assertCount(2,$this->requests);
        self::assertStringContainsString('/mms/sticker-pack/',$this->requests[0]['url']);
        self::assertStringContainsString('/mms/thumbnail-sticker-pack/',$this->requests[1]['url']);
        $zipBytes = MediaCrypto::decrypt($this->requests[0]['body'],'sticker-pack',$pack['mediaKey'],$pack['fileSha256'],$pack['fileEncSha256']);
        self::assertStringStartsWith("PK\x03\x04",$zipBytes);
        $cover = MediaCrypto::decrypt($this->requests[1]['body'],'thumbnail-sticker-pack',$pack['mediaKey'],$pack['thumbnailSha256'],$pack['thumbnailEncSha256']);
        self::assertSame('jpeg cover',$cover);
        self::assertSame(base64_encode(hash('sha256',$cover,true)),$pack['imageDataHash']);
        self::assertSame('tray.png',$pack['trayIconFileName']);
        self::assertSame(2,$pack['stickerPackOrigin']);
        self::assertSame('sticker.webp',$pack['stickers'][0]['fileName']);
        self::assertArrayNotHasKey('url',$pack);
        $built = ContentBuilder::build(['stickerPackMessage'=>$pack],'5511999999999@s.whatsapp.net');
        $decoded = ContentDecoder::decode($built['bytes']);
        self::assertSame('sticker-pack',$decoded['kind']);
        self::assertSame($pack['mediaKey'],$decoded['data']['mediaKey']);
        self::assertSame('sticker_pack',$built['encAttrs']['mediatype']);
        // PHP's independent ZIP reader validates central offsets, entries and CRCs.
        if (class_exists(\ZipArchive::class)) {
            $file = tempnam(sys_get_temp_dir(),'mvo-zip-');
            file_put_contents($file,$zipBytes);
            try {
                $zip = new \ZipArchive();
                self::assertTrue($zip->open($file));
                self::assertSame('webp sticker',$zip->getFromName('sticker.webp'));
                self::assertSame('png tray',$zip->getFromName('tray.png'));
                $zip->close();
            } finally { unlink($file); }
        }
    }

    #[DataProvider('unsafePackNames')]
    public function testStickerPackRejectsUnsafeOrCollidingZipNames(string $name): void
    {
        $pack = $this->pack();
        $pack['trayIcon']['fileName'] = $name;
        $this->expectException(\InvalidArgumentException::class);
        try { $this->fixture()->prepare($pack); }
        finally { self::assertSame([],$this->requests); }
    }

    public static function unsafePackNames(): iterable
    {
        foreach (['../tray.png','a/b.png','a\\b.png','C:tray.png',"tray\0.png",'sticker.webp','STICKER.WEBP',''] as $name) yield [$name];
    }

    public function testStickerZipAggregateSizeLimitIsEnforced(): void
    {
        $this->expectException(\LengthException::class);
        StickerPackZip::create([['fileName'=>'sticker.webp','bytes'=>str_repeat('x',30)],['fileName'=>'tray.png','bytes'=>'xx']],200);
    }

    private function fixture(): MediaTransfer
    {
        return new MediaTransfer(static fn(BinaryNode $node):BinaryNode=>new BinaryNode('iq',['type'=>'result'],[
            new BinaryNode('media_conn',['auth'=>'A','ttl'=>'3600'],[new BinaryNode('host',['hostname'=>'mmg.whatsapp.net'])]),
        ]),function(array $request):array {
            $this->requests[] = $request;
            return ['status'=>200,'body'=>'{"url":"https://mmg.whatsapp.net/media","direct_path":"/media"}'];
        });
    }

    /** @return array<string,mixed> */
    private function pack(): array
    {
        return ['type'=>'sticker-pack','stickerPackId'=>'my-pack','name'=>'Pack','publisher'=>'MVO PHP',
            'stickers'=>[['fileName'=>'sticker.webp','media'=>['bytes'=>'webp sticker'],'emojis'=>['😀']]],
            'trayIcon'=>['fileName'=>'tray.png','media'=>['bytes'=>'png tray']],
            'coverThumbnail'=>['bytes'=>'jpeg cover'],
        ];
    }
}
