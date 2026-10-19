<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\Media\VideoUploadContext;
use MVO\Facebook\Service\MediaService;
use MVO\Facebook\Storage\CommandLedger;
use MVO\Messenger\Auth\{BoundWebSession,CookieJar};
use MVO\Messenger\Transport\{CurlHttpClient,CurlTransportCapture,HttpResponse,UploadFile};
use PHPUnit\Framework\TestCase;

/** Uses native PHP cURL configuration/stream callbacks, intercepted before network I/O. */
final class MediaNativeUploadIntegrationTest extends TestCase
{
    public function testTheVideoServiceKeepsTheSameCookiesAndStreamsOnlyTheProbedSuffix():void
    {
        // Load after suite discovery so a combined monorepo run can reuse the
        // equivalent Messenger seam without depending on its test files.
        require_once __DIR__.'/fixtures/curl-capture.php';
        $directory=sys_get_temp_dir().'/mvo-video-native-'.bin2hex(random_bytes(6));mkdir($directory,0700);$path=$directory.'/video.mp4';file_put_contents($path,'abcdefgh');
        $ledger=null;$service=null;CurlTransportCapture::$active=true;CurlTransportCapture::$headers=[];CurlTransportCapture::$status=200;
        try{
            $ownerActive=true;$calls=[];$native=new CurlHttpClient();$cookies=CookieJar::fromJson('{"c_user":"10001","xs":"SYNTHETIC_COOKIE"}');
            $guard=static function()use(&$ownerActive):void{if(!$ownerActive)throw new \LogicException('Owner stopped');};
            $sender=static function(string $method,string $url,array $form,array $headers)use($guard,$native,$cookies,&$calls):HttpResponse{
                $guard();$value=str_contains($url,'/start/')?['payload'=>['video_id'=>'30003','upload_session_id'=>'40004','start_offset'=>0,'end_offset'=>4,'skip_upload'=>false]]:
                    ($method==='GET'?['offset'=>3]:['payload'=>['video_id'=>'30003','start_offset'=>8,'end_offset'=>8]]);
                CurlTransportCapture::$body=json_encode($value,JSON_THROW_ON_ERROR);$result=$native->requestWithHeaders($method,$url,$cookies,$form,$headers);
                $calls[]=['url'=>$url,'options'=>CurlTransportCapture::$options];return $result;
            };
            $uploader=static function(string $url,UploadFile $file,int $offset,int $length,array $headers)use($guard,$native,$cookies,&$calls):HttpResponse{
                $guard();CurlTransportCapture::$body='{"h":"SYNTHETIC_HANDLE"}';$result=$native->uploadRange($url,$cookies,$file,$offset,$length,$headers);
                $calls[]=['url'=>$url,'options'=>CurlTransportCapture::$options,'body'=>CurlTransportCapture::$uploadedBody];return $result;
            };
            $session=new BoundWebSession('10001',static function()use($guard):array{$guard();return ['__user'=>'10001','fb_dtsg'=>'SYNTHETIC_CSRF'];},
                static fn():HttpResponse=>throw new \LogicException('No generic request expected'),headerSender:$sender,rangeUploader:$uploader);
            $ledger=new CommandLedger($directory.'/commands.sqlite');$service=new MediaService($session,$ledger);
            $context=new VideoUploadContext('10001','20002','20002','photos_album','12345678-1234-4234-8234-123456789abc','album_uploader',[]);
            $result=$service->uploadVideo('20002',new UploadFile($path,'video.mp4','video/mp4'),'native-video',$context);
            self::assertCount(4,$calls);self::assertTrue($result['uploaded']);self::assertFalse($result['published']);self::assertSame('defgh',$calls[2]['body']);
            foreach($calls as $call){$options=$call['options'];self::assertContains('Cookie: c_user=10001; xs=SYNTHETIC_COOKIE',$options[CURLOPT_HTTPHEADER]);self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);}
            self::assertSame(5,$calls[2]['options'][CURLOPT_INFILESIZE]);self::assertContains('Offset: 3',$calls[2]['options'][CURLOPT_HTTPHEADER]);self::assertContains('end_offset: 4',$calls[2]['options'][CURLOPT_HTTPHEADER]);self::assertContains('X-Entity-Length: 8',$calls[2]['options'][CURLOPT_HTTPHEADER]);
            parse_str($calls[0]['options'][CURLOPT_POSTFIELDS],$start);self::assertSame('10001',$start['__user']);self::assertSame('20002',$start['target_id']);
            parse_str($calls[3]['options'][CURLOPT_POSTFIELDS],$receive);self::assertSame('8',$receive['end_offset']);self::assertSame('SYNTHETIC_HANDLE',$receive['fbuploader_video_file_chunk']);
            $ownerActive=false;try{$session->requestWithHeaders('GET',$calls[1]['url']);self::fail('Stopped owner allowed a raw request');}catch(\LogicException){self::assertCount(4,$calls);}
        }finally{
            CurlTransportCapture::$active=false;CurlTransportCapture::$options=[];CurlTransportCapture::$headers=[];$service=null;$ledger?->close();$ledger=null;gc_collect_cycles();foreach(glob($directory.'/*')?:[] as $file)unlink($file);rmdir($directory);
        }
    }
}
