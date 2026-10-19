<?php
declare(strict_types=1);

use MVO\Messenger\Auth\{BoundWebSession,CookieJar};
use MVO\Messenger\Transport\{CurlHttpClient,CurlTransportCapture,HttpResponse,UploadFile,VideoUploadPolicy};
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/fixtures/curl-capture.php';

final class RawUploadTest extends TestCase
{
    private string $file;
    private const URL='https://rupload.facebook.com/fb_video/0123456789abcdef0123456789abcdef-0-15';
    protected function setUp():void
    {
        $this->file=tempnam(sys_get_temp_dir(),'mvo-raw-');file_put_contents($this->file,'0123456789ABCDE');
        CurlTransportCapture::$active=true;CurlTransportCapture::$options=[];CurlTransportCapture::$headers=[];CurlTransportCapture::$body='{"h":"SYNTHETIC"}';CurlTransportCapture::$status=200;CurlTransportCapture::$uploadedBody='';
    }
    protected function tearDown():void{CurlTransportCapture::$active=false;CurlTransportCapture::$options=[];if(is_file($this->file))unlink($this->file);}
    public function testNativeTransferStreamsOnlySelectedRemainingBytesAndPreservesTLSAndScopedCookies():void
    {
        $file=new UploadFile($this->file,'example.mp4','video/mp4');
        $r=(new CurlHttpClient())->uploadRange(self::URL,CookieJar::fromJson('{"c_user":"10001","xs":"SYNTHETIC"}'),$file,5,6,['Offset'=>'5','X-Entity-Length'=>'15','X-Entity-Type'=>'video/mp4']);
        self::assertSame(200,$r->status);self::assertSame('56789A',CurlTransportCapture::$uploadedBody);
        self::assertSame(6,CurlTransportCapture::$options[CURLOPT_INFILESIZE]);self::assertSame('POST',CurlTransportCapture::$options[CURLOPT_CUSTOMREQUEST]);
        self::assertSame(false,CurlTransportCapture::$options[CURLOPT_FOLLOWLOCATION]);self::assertTrue(CurlTransportCapture::$options[CURLOPT_SSL_VERIFYPEER]);
        self::assertContains('Cookie: c_user=10001; xs=SYNTHETIC',CurlTransportCapture::$options[CURLOPT_HTTPHEADER]);
        self::assertContains('Origin: https://www.facebook.com',CurlTransportCapture::$options[CURLOPT_HTTPHEADER]);
        self::assertArrayNotHasKey(CURLOPT_POSTFIELDS,CurlTransportCapture::$options);
    }
    public function testZeroLengthFinalHandleAndFormRequestUseTheirOwnProfiles():void
    {
        $client=new CurlHttpClient();$cookies=CookieJar::fromJson('{"c_user":"10001","xs":"SYNTHETIC"}');$file=new UploadFile($this->file,'example.mp4','video/mp4');
        $client->uploadRange(self::URL,$cookies,$file,15,0,['Offset'=>'15','X-Entity-Length'=>'15']);self::assertSame('',CurlTransportCapture::$uploadedBody);
        $client->requestWithHeaders('POST','https://vupload-edge.facebook.com/ajax/video/upload/requests/start/?av=20002',$cookies,['__user'=>'10001','file_size'=>'15'],['X_FB_VIDEO_WATERFALL_ID'=>'SYNTHETIC']);
        self::assertSame('__user=10001&file_size=15',CurlTransportCapture::$options[CURLOPT_POSTFIELDS]);
        self::assertContains('X_FB_VIDEO_WATERFALL_ID: SYNTHETIC',CurlTransportCapture::$options[CURLOPT_HTTPHEADER]);
        $client->requestWithHeaders('GET',self::URL,$cookies);self::assertContains('Origin: https://www.facebook.com',CurlTransportCapture::$options[CURLOPT_HTTPHEADER]);
    }
    public function testEndpointHeaderAndRangeViolationsAreRejectedBeforeTransfer():void
    {
        foreach([
            ['POST','https://attacker.invalid/fb_video/id',[],[],true],
            ['POST',self::URL.'?redirect=1',[],[],true],
            ['POST',str_replace('0123456789abcdef0123456789abcdef','ABC',self::URL),[],[],true],
            ['GET',self::URL,['body'=>'x'],[],false],
            ['POST',self::URL,[],['Authorization'=>'Bearer forbidden'],true],
            ['POST',self::URL,[],['Cookie'=>'forbidden'],true],
            ['POST',self::URL,[],['Offset'=>"0\r\nInjected: x"],true],
            ['POST',self::URL,[],['Offset'=>'0','offset'=>'0'],true],
            ['POST','https://vupload-edge.facebook.com/ajax/video/upload/requests/start/?av=20002&extra=1',[],[],false],
        ] as [$method,$url,$form,$headers,$raw]){
            try{VideoUploadPolicy::validate($method,$url,$form,$headers,$raw);self::fail('Invalid upload policy');}catch(InvalidArgumentException){self::assertSame([],CurlTransportCapture::$options);}
        }
        $file=new UploadFile($this->file,'example.mp4','video/mp4');
        foreach([[-1,1],[0,16],[15,1],[16,0]] as [$offset,$length]){
            try{$file->openRange($offset,$length);self::fail('Invalid range');}catch(OutOfBoundsException){self::assertSame([],CurlTransportCapture::$options);}
        }
        file_put_contents($this->file,str_repeat('X',15));
        try{$file->openRange(0,1);self::fail('Changed selected file');}catch(RuntimeException){self::assertSame([],CurlTransportCapture::$options);}
    }
    public function testOptionalBoundRawCallbacksRetainOwnerLifecycle():void
    {
        $active=true;$calls=0;$guard=static function()use(&$active,&$calls):HttpResponse{if(!$active)throw new LogicException('Owner stopped');$calls++;return new HttpResponse(200,'{}');};
        $session=new BoundWebSession('10001',static fn():array=>[],static fn():HttpResponse=>new HttpResponse(200,''),headerSender:$guard,rangeUploader:$guard);
        $session->requestWithHeaders('GET',self::URL);$session->uploadRange(self::URL,new UploadFile($this->file,'example.mp4','video/mp4'),0,1,[]);
        $active=false;try{$session->requestWithHeaders('GET',self::URL);self::fail('Stopped owner');}catch(LogicException){self::assertSame(2,$calls);}
        $legacy=new BoundWebSession('10001',static fn():array=>[],static fn():HttpResponse=>new HttpResponse(200,''));
        $this->expectException(LogicException::class);$legacy->requestWithHeaders('GET',self::URL);
    }
    public function testAnnouncedRangeAndFileLengthCannotDivergeFromSelectedBytes():void
    {
        $file=new UploadFile($this->file,'example.mp4','video/mp4');$client=new CurlHttpClient();$cookies=CookieJar::fromJson('{"c_user":"10001","xs":"SYNTHETIC"}');
        foreach([
            ['Offset'=>'0','X-Entity-Length'=>'15'],
            ['Offset'=>'5','X-Entity-Length'=>'3'],
            ['Offset'=>'5'],
            ['Offset'=>'5','X-Entity-Length'=>'15','X-Total-Asset-Size'=>'3'],
            ['Offset'=>'5','X-Entity-Length'=>'15','X-Entity-Type'=>'video/webm'],
        ] as $headers){
            try{$client->uploadRange(self::URL,$cookies,$file,5,3,$headers);self::fail('Incorrect range metadata');}
            catch(InvalidArgumentException){self::assertSame([],CurlTransportCapture::$options);}
        }
    }
}
