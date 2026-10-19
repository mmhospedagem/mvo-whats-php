<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\Catalog\PageDocuments;
use MVO\Facebook\FacebookException;
use MVO\Facebook\Media\VideoUploadContext;
use MVO\Facebook\Service\MediaService;
use MVO\Facebook\Storage\CommandLedger;
use MVO\Messenger\Auth\RawUploadWebSession;
use MVO\Messenger\Transport\{HttpResponse,UploadFile,VideoUploadPolicy};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VideoServiceFakeOwner implements RawUploadWebSession
{
    public array $calls=[];
    public array $configuration=['start_uri'=>VideoUploadContext::START,'receive_uri'=>VideoUploadContext::RECEIVE];
    public array $graphData=['story_create'=>['story_id'=>'STORY_OPAQUE+/==','post_id'=>'60006','private'=>'SYNTHETIC_PRIVATE']];
    public array $responses=[];
    public ?int $throwAt=null;
    public function accountId():string{return '10001';}
    public function privateRequestFields():array{return ['__user'=>'10001','fb_dtsg'=>'SYNTHETIC_CSRF_PRIVATE'];}
    public function request(string $method,string $url,array $form=[]):HttpResponse
    {
        $this->calls[]=['kind'=>'graphql','method'=>$method,'url'=>$url,'form'=>$form];
        return new HttpResponse(200,json_encode(['data'=>($form['fb_api_req_friendly_name']??'')==='useComposerVideoUploaderConfigQuery'
            ?['viewer'=>['comet_composer_video_uploader_config'=>json_encode($this->configuration,JSON_THROW_ON_ERROR)]]:$this->graphData],JSON_THROW_ON_ERROR));
    }
    public function requestWithHeaders(string $method,string $url,array $form=[],array $headers=[]):HttpResponse
    {
        VideoUploadPolicy::validate($method,$url,$form,$headers);$this->calls[]=['kind'=>'form','method'=>$method,'url'=>$url,'form'=>$form,'headers'=>$headers];return $this->next();
    }
    public function uploadRange(string $url,UploadFile $file,int $offset,int $length,array $headers):HttpResponse
    {
        VideoUploadPolicy::validate('POST',$url,[],$headers,true);$handle=$file->openRange($offset,$length);$body=$length===0?'':stream_get_contents($handle,$length);fclose($handle);
        $this->calls[]=['kind'=>'range','url'=>$url,'offset'=>$offset,'length'=>$length,'headers'=>$headers,'body'=>$body];return $this->next();
    }
    private function next():HttpResponse{if($this->throwAt===count($this->calls))throw new \RuntimeException('SYNTHETIC_PRIVATE_FAILURE');$next=array_shift($this->responses);if(!$next instanceof HttpResponse)throw new \LogicException('Unexpected network operation');return $next;}
}

/** Exact recorded Web fields, byte ranges and durable uncertainty; no account/network I/O. */
final class VideoUploadServiceTest extends TestCase
{
    private string $directory;
    private ?CommandLedger $ledger=null;
    private ?MediaService $service=null;
    private VideoServiceFakeOwner $owner;
    private UploadFile $file;
    protected function setUp():void
    {
        $this->directory=sys_get_temp_dir().'/mvo-video-'.bin2hex(random_bytes(6));mkdir($this->directory,0700);file_put_contents($this->directory.'/video.mp4','abcdefgh');
        $this->file=new UploadFile($this->directory.'/video.mp4','video.mp4','video/mp4');$this->owner=new VideoServiceFakeOwner();$this->ledger=new CommandLedger($this->directory.'/commands.sqlite');$this->service=new MediaService($this->owner,$this->ledger);$this->owner->responses=self::responses();
    }
    protected function tearDown():void
    {
        $this->service=null;$this->ledger?->close();$this->ledger=null;gc_collect_cycles();foreach(glob($this->directory.'/*')?:[] as $file)unlink($file);rmdir($this->directory);
    }
    public function testCurrentConfigurationIsFetchedForTheExactActorAndTarget():void
    {
        $context=$this->service->videoContext('20002','20002','photos_album',self::uuid(),'album_uploader');
        self::assertSame('10001',$context->accountId);self::assertSame('20002',$context->actorId);self::assertSame('album_uploader',$context->source);
        $call=$this->owner->calls[0];self::assertSame('9734072893355148',$call['form']['doc_id']);self::assertSame(['actorID'=>'20002','entryPoint'=>'photos_album','targetID'=>'20002'],json_decode($call['form']['variables'],true));
    }
    public function testOffsetProbeStreamsOnlyRemainingBytesAndFinalizesWithoutPublishing():void
    {
        $result=$this->service->uploadVideo('20002',$this->file,'video-key',self::context());self::assertSame($result,$this->service->uploadVideo('20002',$this->file,'video-key',self::context()));
        self::assertCount(4,$this->owner->calls);$start=$this->owner->calls[0];$probe=$this->owner->calls[1];$range=$this->owner->calls[2];$end=$this->owner->calls[3];
        self::assertSame(VideoUploadContext::START.'?av=20002',$start['url']);self::assertSame('10001',$start['form']['__user']);self::assertSame('20002',$start['form']['target_id']);self::assertSame('8',$start['form']['file_size']);self::assertSame('mp4',$start['form']['file_extension']);self::assertSame(self::uuid(),$start['headers']['X_FB_VIDEO_WATERFALL_ID']);
        self::assertSame('GET',$probe['method']);self::assertSame($probe['url'],$range['url']);self::assertMatchesRegularExpression('~^https://rupload.facebook.com/fb_video/[a-f0-9]{32}-0-8$~D',$probe['url']);
        self::assertSame(3,$range['offset']);self::assertSame(5,$range['length']);self::assertSame('defgh',$range['body']);self::assertSame('3',$range['headers']['Offset']);self::assertSame('8',$range['headers']['X-Entity-Length']);
        self::assertSame('40004',$range['headers']['id']);self::assertSame('30003',$range['headers']['product_media_id']);self::assertSame('SYNTHETIC_UPLOAD_HANDLE_PRIVATE',$end['form']['fbuploader_video_file_chunk']);
        self::assertSame(VideoUploadContext::RECEIVE.'?av=20002',$end['url']);self::assertSame('8',$end['form']['end_offset']);self::assertSame('30003',$result['video_id']);self::assertFalse($result['published']);self::assertTrue($result['uploaded']);self::assertNull($result['processing_ready']);
        self::assertSame(3,$result['resumed_from_offset']);self::assertStringNotContainsString('PRIVATE',json_encode($result,JSON_THROW_ON_ERROR));
        $db=new \PDO('sqlite:'.$this->directory.'/commands.sqlite');$stored=$db->query('SELECT * FROM facebook_commands')->fetch(\PDO::FETCH_ASSOC);self::assertSame('confirmed',$stored['state']);$serialized=json_encode($stored,JSON_THROW_ON_ERROR);self::assertStringNotContainsString('PRIVATE',$serialized);self::assertStringNotContainsString($this->directory,$serialized);unset($db);
    }
    public function testSkipUploadDoesNotTransferOrFinalizeAgain():void
    {
        $this->owner->responses=[self::json(['payload'=>['video_id'=>'30003','skip_upload'=>true]])];$r=$this->service->uploadVideo('20002',$this->file,'skipped',self::context());self::assertTrue($r['upload_skipped']);self::assertCount(1,$this->owner->calls);
    }
    public function testExistingCompleteBytesStillRequireAnOpaqueHandle():void
    {
        $this->owner->responses=self::responses(8);$r=$this->service->uploadVideo('20002',$this->file,'complete-offset',self::context());self::assertSame(8,$r['resumed_from_offset']);self::assertSame(0,$this->owner->calls[2]['length']);self::assertSame('',$this->owner->calls[2]['body']);
    }
    public function testTheNewWholeFilePipelineKeepsTheServerRangeForCorrelationButUploadsTheOriginalFileSuffix():void
    {
        // MediaUploadFBUploadServiceRequest passes n.file (the original File) to resume().
        // This is distinct from VideoUploadReceiveRequestManager, which passes a pre-sliced chunk.
        $this->owner->responses[0]=self::json(['payload'=>['video_id'=>'30003','upload_session_id'=>'40004','start_offset'=>0,'end_offset'=>4,'skip_upload'=>false]]);
        $this->service->uploadVideo('20002',$this->file,'whole-file-pipeline',self::context());$range=$this->owner->calls[2];
        self::assertStringEndsWith('-0-4',$range['url']);self::assertSame('4',$range['headers']['end_offset']);
        self::assertSame('8',$range['headers']['X-Entity-Length']);self::assertSame(3,$range['offset']);self::assertSame(5,$range['length']);self::assertSame('defgh',$range['body']);
        self::assertSame('8',$this->owner->calls[3]['form']['end_offset']);
    }
    /** @return iterable<string,array{int,HttpResponse,string}> */
    public static function badStages():iterable
    {
        yield 'bad start ID'=>[0,self::json(['payload'=>['video_id'=>'NOT_ID']]),'facebook_response_shape_unknown'];
        yield 'inverted start range'=>[0,self::json(['payload'=>['video_id'=>'30003','upload_session_id'=>'40004','start_offset'=>6,'end_offset'=>4]]),'facebook_response_shape_unknown'];
        yield 'start range beyond selected file'=>[0,self::json(['payload'=>['video_id'=>'30003','upload_session_id'=>'40004','start_offset'=>0,'end_offset'=>9]]),'facebook_video_offset_invalid'];
        yield 'nonboolean skip flag'=>[0,self::json(['payload'=>['video_id'=>'30003','skip_upload'=>'true']]),'facebook_response_shape_unknown'];
        yield 'bad probe offset'=>[1,self::json(['offset'=>9]),'facebook_video_offset_invalid'];
        yield 'negative probe offset'=>[1,self::json(['offset'=>-1]),'facebook_video_offset_invalid'];
        yield 'missing handle'=>[2,self::json(['offset'=>8]),'facebook_video_handle_unconfirmed'];
        yield 'incomplete final range'=>[3,self::json(['payload'=>['start_offset'=>7,'end_offset'=>8]]),'facebook_video_finalize_unconfirmed'];
        yield 'wrong final video'=>[3,self::json(['payload'=>['start_offset'=>8,'end_offset'=>8,'video_id'=>'99999']]),'facebook_response_shape_unknown'];
        yield 'forwarded refusal'=>[3,self::json(['payload'=>['__graph_error_forwarded'=>true,'error'=>['message'=>'SYNTHETIC_PRIVATE']]]),'facebook_video_upload_rejected'];
        yield 'HTTP error'=>[2,new HttpResponse(503,'SYNTHETIC_PRIVATE'),'facebook_video_http_failed'];
    }
    #[DataProvider('badStages')]
    public function testNoStageFailureConfirmsOrRepeatsTheWorkflow(int $stage,HttpResponse $response,string $reason):void
    {
        $this->owner->responses[$stage]=$response;
        foreach([$reason,'facebook_command_uncertain'] as $expected){try{$this->service->uploadVideo('20002',$this->file,'uncertain',self::context());self::fail('Invalid video stage accepted');}catch(FacebookException $e){self::assertSame($expected,$e->reason);self::assertNotNull($e->commandId);self::assertStringNotContainsString('PRIVATE',$e->getMessage());}}
        self::assertCount($stage+1,$this->owner->calls);
    }
    public function testRawTransportFailureIsMaskedAndNotRepeated():void
    {
        $this->owner->throwAt=3;
        foreach(['facebook_video_upload_unconfirmed','facebook_command_uncertain'] as $reason){try{$this->service->uploadVideo('20002',$this->file,'timeout',self::context());self::fail('Expected uncertainty');}catch(FacebookException $e){self::assertSame($reason,$e->reason);self::assertSame('unknown',$e->outcome);self::assertStringNotContainsString('PRIVATE',$e->getMessage());}}
        self::assertCount(3,$this->owner->calls);
    }
    public function testContextCannotChangeOwnerActorDomainOrAdvertisingSource():void
    {
        foreach([['10001','99999',[]],['10001','20002',['start_uri'=>'https://example.test/upload']],['10001','20002',['actor_id'=>['SECRET']]]] as [$account,$actor,$config]){
            try{$context=new VideoUploadContext($account,$actor,'20002','photos_album',self::uuid(),'album_uploader',$config);$this->service->uploadVideo('20002',$this->file,'bad-context',$context);self::fail('Bad context accepted');}catch(\InvalidArgumentException|FacebookException){}
        }
        self::assertSame([],$this->owner->calls);
    }
    public function testPhotosPublishAsNativeAttachmentsWithAnIndependentKey():void
    {
        $v=[];foreach(PageDocuments::providers('ComposerStoryCreateMutation')as $p)$v[$p]=str_contains($p,'AutoTranslationType')?'ORIGINAL':false;
        $ctx=['composer_entry_point'=>'SYNTHETIC_TIMELINE','composer_source_surface'=>'SYNTHETIC_PROFILE','composer_type'=>'profile','logging'=>['composer_session_id'=>self::uuid()],
            'audience'=>['privacy'=>['base_state'=>'SELF','allow'=>[],'deny'=>[],'tag_expansion_state'=>'UNSPECIFIED']],'tracking'=>[]];
        $r=$this->service->publishPhotos('20002',['70007','80008'],'Caption','publish-key',$ctx,$v);self::assertSame($r,$this->service->publishPhotos('20002',['70007','80008'],'Caption','publish-key',$ctx,$v));
        self::assertCount(1,$this->owner->calls);$input=json_decode($this->owner->calls[0]['form']['variables'],true)['input'];self::assertSame([['photo'=>['id'=>'70007']],['photo'=>['id'=>'80008']]],$input['attachments']);self::assertSame('Caption',$input['message']['text']);self::assertTrue($r['published']);self::assertFalse($r['client_rendering_validated']);
    }
    public function testUploadedVideoUsesTheObservedVideoTransformWithoutFabricatingEditingContext():void
    {
        [$ctx,$v]=self::publishingContext();$r=$this->service->publishVideo('20002','30003','Video caption','publish-video',$ctx,$v);
        self::assertSame($r,$this->service->publishVideo('20002','30003','Video caption','publish-video',$ctx,$v));self::assertCount(1,$this->owner->calls);
        $input=json_decode($this->owner->calls[0]['form']['variables'],true)['input'];
        self::assertSame([['video'=>['id'=>'30003','audio_descriptions'=>null,'additional_video_metadata'=>['translatedAudioMetadata'=>[]],
            'notify_when_processed'=>true,'transcriptions'=>null,'was_created_via_unified_video_flow'=>null]]],$input['attachments']);
        self::assertSame('Video caption',$input['message']['text']);self::assertArrayNotHasKey('video_generation_params',$input['attachments'][0]['video']);
        self::assertSame(['30003'],$r['attachment_ids_submitted']);self::assertTrue($r['published']);self::assertFalse($r['client_rendering_validated']);
    }
    public function testPublicationWithoutAStoryIdDoesNotConfirmOrRepeat():void
    {
        [$ctx,$v]=self::publishingContext();$this->owner->graphData=['story_create'=>['status'=>'OK','private'=>'SYNTHETIC_PRIVATE']];
        foreach(['facebook_response_shape_unknown','facebook_command_uncertain'] as $reason){try{$this->service->publishVideo('20002','30003','','unconfirmed-publication',$ctx,$v);self::fail('Missing story confirmed');}catch(FacebookException $e){self::assertSame($reason,$e->reason);}}
        self::assertCount(1,$this->owner->calls);
    }
    public function testUnknownVideoConfigurationFailsBeforeAnyRawUpload():void
    {
        $this->owner->configuration=['start_uri'=>'https://example.test/private-upload'];
        try{$this->service->videoContext('20002','20002','photos_album',self::uuid(),'album_uploader');self::fail('Unknown configuration accepted');}catch(FacebookException $e){self::assertSame('facebook_video_context_unavailable',$e->reason);}
        self::assertCount(1,$this->owner->calls);self::assertSame('graphql',$this->owner->calls[0]['kind']);
    }
    private static function publishingContext():array
    {
        $v=[];foreach(PageDocuments::providers('ComposerStoryCreateMutation')as $p)$v[$p]=str_contains($p,'AutoTranslationType')?'ORIGINAL':false;
        return [['composer_entry_point'=>'SYNTHETIC_TIMELINE','composer_source_surface'=>'SYNTHETIC_PROFILE','composer_type'=>'profile','logging'=>['composer_session_id'=>self::uuid()],
            'audience'=>['privacy'=>['base_state'=>'SELF','allow'=>[],'deny'=>[],'tag_expansion_state'=>'UNSPECIFIED']],'tracking'=>[]],$v];
    }
    private static function context():VideoUploadContext{return new VideoUploadContext('10001','20002','20002','photos_album',self::uuid(),'album_uploader',[]);}
    private static function uuid():string{return '12345678-1234-4234-8234-123456789abc';}
    private static function json(array $data):HttpResponse{return new HttpResponse(200,'for (;;);'.json_encode($data,JSON_THROW_ON_ERROR));}
    private static function responses(int $offset=3):array{return [self::json(['payload'=>['video_id'=>'30003','upload_session_id'=>'40004','start_offset'=>0,'end_offset'=>8,'region_hint'=>'ASH','skip_upload'=>false]]),self::json(['offset'=>$offset]),self::json(['h'=>'SYNTHETIC_UPLOAD_HANDLE_PRIVATE']),self::json(['payload'=>['video_id'=>'30003','start_offset'=>8,'end_offset'=>8]])];}
}
