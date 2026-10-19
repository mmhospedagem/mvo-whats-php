<?php
declare(strict_types=1);

namespace MVO\Facebook\Service;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Media\PhotoUploadContext;
use MVO\Facebook\Media\VideoUploadContext;
use MVO\Facebook\Catalog\{MediaDocuments,PageDocuments};
use MVO\Facebook\Protocol\{DocumentRegistry,WebGraphQL};
use MVO\Facebook\Storage\CommandLedger;
use MVO\Messenger\Auth\BootstrapWebSession;
use MVO\Messenger\Auth\MultipartWebSession;
use MVO\Messenger\Auth\WebSession;
use MVO\Messenger\Auth\RawUploadWebSession;
use MVO\Messenger\Transport\HttpResponse;
use MVO\Messenger\Transport\UploadFile;

/** Native photo/video assets and explicit publishing through the existing account owner. */
final class MediaService
{
    private int $sequence = 0;
    private readonly WebGraphQL $graph;
    public function __construct(private readonly WebSession $session, private readonly CommandLedger $commands,?WebGraphQL $graph=null)
    {
        $this->graph=$graph??new WebGraphQL($session,new DocumentRegistry([...MediaDocuments::documents(),...PageDocuments::documents()]),$commands);
    }

    /** Fetch the current actor/target configuration; the source is the actual non-advertising composer source.
     * @return VideoUploadContext
     */
    public function videoContext(string $actorId,string $targetId,string $entryPoint,string $creationSessionId,string $source='composer'):VideoUploadContext
    {
        ServiceInput::actor($actorId);ServiceInput::actor($targetId);ServiceInput::opaque($entryPoint,'video composer entry point',256);
        // Validate application identifiers before any query, including UUID and supported source.
        new VideoUploadContext($this->session->accountId(),$actorId,$targetId,$entryPoint,$creationSessionId,$source,[]);
        $data=$this->graph->execute('useComposerVideoUploaderConfigQuery',MediaDocuments::arguments('useComposerVideoUploaderConfigQuery',[
            'actorID'=>$actorId,'entryPoint'=>$entryPoint,'targetID'=>$targetId]),$actorId);
        $raw=$data['viewer']['comet_composer_video_uploader_config']??null;
        if(!is_string($raw)||strlen($raw)>262144)throw new FacebookException('facebook_video_context_unavailable','The current composer did not provide its video upload configuration');
        try{$configuration=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}
        catch(\JsonException){throw new FacebookException('facebook_video_context_unavailable','The current composer returned an unsupported video upload configuration');}
        if(!is_array($configuration)||($configuration!==[]&&array_is_list($configuration)))throw new FacebookException('facebook_video_context_unavailable','The current composer returned an unsupported video upload configuration');
        try{return new VideoUploadContext($this->session->accountId(),$actorId,$targetId,$entryPoint,$creationSessionId,$source,$configuration);}
        catch(\InvalidArgumentException){throw new FacebookException('facebook_video_context_unavailable','The configured video routes or actor are outside the observed upload contract');}
    }

    /** Start, read the rupload offset, stream only the remaining bytes and finalize the uploaded asset.
     * No publish request is made. The native server can skip bytes or return a nonzero offset.
     * Failures stop the command; there is no automatic start/finalization retry after uncertainty.
     * @return array<string,mixed>
     */
    public function uploadVideo(string $actorId,UploadFile $file,string $idempotencyKey,VideoUploadContext $context):array
    {
        ServiceInput::actor($actorId);ServiceInput::key($idempotencyKey);
        if($context->accountId!==$this->session->accountId()||$context->actorId!==$actorId)throw new FacebookException('facebook_upload_context_mismatch','The video upload context belongs to another account or actor');
        if(!$this->session instanceof RawUploadWebSession)throw new FacebookException('facebook_upload_transport_unavailable','The account owner does not expose the optional raw video upload transport');
        if(!in_array($file->mediaType,['video/mp4','video/webm','video/quicktime'],true))throw new \InvalidArgumentException('This helper accepts actual MP4, WebM or QuickTime video files');
        $extension=strtolower(pathinfo($file->filename,PATHINFO_EXTENSION));
        if(!in_array($extension,['mp4','webm','mov'],true))throw new \InvalidArgumentException('Use an actual supported video filename extension');
        $digest=$file->sha256();$fields=$this->session->privateRequestFields();
        if(($fields['__user']??null)!==$this->session->accountId()||(!isset($fields['fb_dtsg'])&&!isset($fields['lsd'])))throw new FacebookException('facebook_csrf_unavailable','The current account did not provide request fields');
        $command=$this->commands->reserve($this->session->accountId(),'media.video.upload',$actorId,
            ['context'=>$context->commonForm(),'file'=>['sha256'=>$digest,'bytes'=>$file->size,'filename'=>$file->filename,'media_type'=>$file->mediaType]],$idempotencyKey);
        if(!$command['execute']){
            if($command['state']==='confirmed'&&is_array($command['result']['projection']??null))return $command['result']['projection'];
            throw new FacebookException('facebook_command_uncertain','This video upload already exists without a confirmed result; inspect it before trying another key','unknown',false,$command['id']);
        }
        $common=$context->commonForm();$base=[...$fields,'__a'=>'1'];$waterfall=['X_FB_VIDEO_WATERFALL_ID'=>$context->creationSessionId];
        $this->commands->finish($command['id'],'unknown');
        try{
            $start=self::videoPayload($this->session->requestWithHeaders('POST',VideoUploadContext::START.'?av='.$actorId,[...$base,...$common,'__req'=>base_convert((string)++$this->sequence,10,36),
                'file_size'=>(string)$file->size,'file_extension'=>$extension,'supports_file_api'=>'true',
                'partition_start_offset'=>'0','partition_end_offset'=>(string)$file->size],$waterfall));
            $videoId=ServiceInput::scalarId($start['video_id']??null);
            if($videoId===null||preg_match('/^[1-9][0-9]{0,24}$/D',$videoId)!==1)ServiceInput::unexpected(true);
            $skip=$start['skip_upload']??false;if(!is_bool($skip))ServiceInput::unexpected(true);
            $resumedOffset=0;
            if(!$skip){
                $sessionId=ServiceInput::scalarId($start['upload_session_id']??null);
                if($sessionId===null||preg_match('/^[1-9][0-9]{0,24}$/D',$sessionId)!==1)ServiceInput::unexpected(true);
                $startOffset=self::offset($start['start_offset']??null,$file->size);$endOffset=self::offset($start['end_offset']??null,$file->size);
                if($endOffset<$startOffset)ServiceInput::unexpected(true);
                // The Web uses a stable MD5 client correlation key followed by the server's start/end offsets.
                // The SDK binds that key to the selected content; it is not an authorization or idempotency token.
                $key=md5($context->creationSessionId.'-'.$digest.'-'.$file->filename.'-'.$file->size.'-'.$command['id']).'-'.$startOffset.'-'.$endOffset;
                $url=VideoUploadContext::UPLOAD.$key;
                $probe=self::videoJson($this->session->requestWithHeaders('GET',$url));
                $resumedOffset=self::offset($probe['offset']??null,$file->size);
                $headers=['X-Entity-Name'=>str_replace(['%21','%27','%28','%29','%2A'],['!',"'",'(',')','*'],rawurlencode($file->filename)),
                    'X-Entity-Type'=>$file->mediaType,'X-Entity-Length'=>(string)$file->size,'Offset'=>(string)$resumedOffset,
                    'start_offset'=>(string)$startOffset,'end_offset'=>(string)$endOffset,'composer_session_id'=>$context->creationSessionId,
                    'id'=>$sessionId,'product_media_id'=>$videoId,'X-Total-Asset-Size'=>(string)$file->size];
                $region=$start['fb_region']??$start['region_hint']??null;
                if($region!==null){if(!is_string($region)||preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$region)!==1)ServiceInput::unexpected(true);$headers['X-FB-Region']=$region;}
                $file->sha256();$began=microtime(true);
                $bytes=self::videoJson($this->session->uploadRange($url,$file,$resumedOffset,$file->size-$resumedOffset,$headers));
                $handle=$bytes['h']??null;
                if(!is_string($handle)||$handle===''||strlen($handle)>16384||preg_match('/[\x00-\x1f\x7f]/',$handle))throw new FacebookException('facebook_video_handle_unconfirmed','The video byte upload did not confirm its opaque handle','not_confirmed');
                $speed=(string)(int)floor(($file->size-$resumedOffset)/max(microtime(true)-$began,0.001));
                $received=self::videoPayload($this->session->requestWithHeaders('POST',VideoUploadContext::RECEIVE.'?av='.$actorId,[...$base,...$common,'__req'=>base_convert((string)++$this->sequence,10,36),
                    'video_id'=>$videoId,'supports_upload_service'=>'true','partition_start_offset'=>'0','partition_end_offset'=>(string)$file->size,
                    'start_offset'=>'0','end_offset'=>(string)$file->size,'upload_speed'=>$speed,'fbuploader_video_file_chunk'=>$handle],$waterfall));
                if(self::offset($received['start_offset']??null,$file->size)!==$file->size||self::offset($received['end_offset']??null,$file->size)!==$file->size)throw new FacebookException('facebook_video_finalize_unconfirmed','The receive endpoint did not confirm the complete video range','not_confirmed');
                if(isset($received['video_id'])&&ServiceInput::scalarId($received['video_id'])!==$videoId)ServiceInput::unexpected(true);
            }
            $this->commands->finish($command['id'],'response_received');
            $result=['video_id'=>$videoId,'uploaded'=>true,'published'=>false,'bytes'=>$file->size,'resumed_from_offset'=>$resumedOffset,'upload_skipped'=>$skip,'processing_ready'=>null];
            $this->commands->finish($command['id'],'confirmed',['projection'=>$result]);return $result;
        }catch(\Throwable $error){
            throw new FacebookException($error instanceof FacebookException?$error->reason:'facebook_video_upload_unconfirmed',
                $error instanceof FacebookException?$error->getMessage():'The video upload has no durable confirmed result',
                $error instanceof FacebookException?$error->outcome:'unknown',false,$command['id']);
        }
    }

    /** Publish already uploaded photos through the profile timeline composer. Upload and publication use different keys.
     * @param list<string> $photoIds
     * @param array<string,mixed> $context The current composer context, audience, logging and tracking.
     * @param array<string,mixed> $variables Current Relay providers.
     * @return array<string,mixed>
     */
    public function publishPhotos(string $actorId,array $photoIds,string $text,string $idempotencyKey,array $context,array $variables):array
    {
        if(!array_is_list($photoIds)||count($photoIds)<1||count($photoIds)>50||count(array_unique($photoIds,SORT_REGULAR))!==count($photoIds))throw new \InvalidArgumentException('Supply 1-50 distinct uploaded photo IDs');
        $attachments=[];foreach($photoIds as $id){ServiceInput::actor($id);$attachments[]=['photo'=>['id'=>$id]];}
        return $this->publishAttachments($actorId,$attachments,$photoIds,$text,$idempotencyKey,$context,$variables);
    }

    /** Publish one uploaded video without crop, music, dubbing, collaboration or unified-video edits.
     * The VIDEO branch of mediaAttachmentAreaTransformUtil is distinct from the photo branch.
     * @param array<string,mixed> $context Current profile timeline composer context.
     * @param array<string,mixed> $variables Current Relay providers.
     * @return array<string,mixed>
     */
    public function publishVideo(string $actorId,string $videoId,string $text,string $idempotencyKey,array $context,array $variables):array
    {
        ServiceInput::actor($videoId);
        $video=['id'=>$videoId,'audio_descriptions'=>null,'additional_video_metadata'=>['translatedAudioMetadata'=>[]],
            'notify_when_processed'=>true,'transcriptions'=>null,'was_created_via_unified_video_flow'=>null];
        return $this->publishAttachments($actorId,[['video'=>$video]],[$videoId],$text,$idempotencyKey,$context,$variables);
    }

    /** Edit the media caption, including an item viewed in an album; this edits the asset itself.
     * @param array<string,mixed> $variables Current scale for photos (not required by the video caller).
     * @return array<string,mixed>
     */
    public function editCaption(string $actorId,string $type,string $mediaId,string $text,string $idempotencyKey,array $variables=[]):array
    {
        ServiceInput::actor($actorId);ServiceInput::actor($mediaId);ServiceInput::key($idempotencyKey);
        if(!in_array($type,['photo','video'],true)||strlen($text)>60000||preg_match('//u',$text)!==1)throw new \InvalidArgumentException('Use photo/video and UTF-8 caption text within the SDK limit');
        if($type==='photo')self::displayScale($variables);
        $name=$type==='photo'?'CometMediaViewerPhotoActionsEditMessageMutation':'CometMediaViewerVideoActionsEditMessageMutation';
        return $this->graph->execute($name,MediaDocuments::arguments($name,[...$variables,'input'=>[$type.'_id'=>$mediaId,'message'=>['text'=>$text]]]),$actorId,'https://www.facebook.com',$idempotencyKey,
            static function(array $data)use($type,$mediaId,$text):array{$node=$data[$type.'_edit_message'][$type]??null;
                if(!is_array($node)||ServiceInput::scalarId($node['id']??null)!==$mediaId||($node['message']['text']??null)!==$text)ServiceInput::unexpected(true);
                return ['media_id'=>$mediaId,'type'=>$type,'caption'=>$text,'updated'=>true];});
    }

    /** Delete the asset itself, including its use in an album. This is not merely unlinking a copy.
     * @param array<string,mixed> $context Current native flags: isCoverPhoto/isProfilePic or isProfileVideo.
     * @param array<string,mixed> $variables Current scale, optional renderLocation and Relay providers.
     * @return array<string,mixed>
     */
    public function deleteMedia(string $actorId,string $type,string $mediaId,string $idempotencyKey,array $context,array $variables):array
    {
        ServiceInput::actor($actorId);ServiceInput::actor($mediaId);ServiceInput::key($idempotencyKey);self::displayScale($variables);
        if(!in_array($type,['photo','video'],true))throw new \InvalidArgumentException('Use photo or video');
        $flags=$type==='photo'?['isCoverPhoto','isProfilePic']:['isProfileVideo'];ServiceInput::keys($context,$flags);
        foreach($flags as $flag)if(!is_bool($context[$flag]??null))throw new FacebookException('facebook_operation_context_required','Supply the current native profile/cover-media flags before deleting an asset');
        $name=$type==='photo'?'CometMediaViewerPhotoDeleteActionMutation':'CometMediaViewerVideoDeleteActionMutation';
        return $this->graph->execute($name,MediaDocuments::arguments($name,[...$variables,...$context,'input'=>[$type.'_id'=>$mediaId],
            'feedLocation'=>'COMET_MEDIA_VIEWER','privacySelectorRenderLocation'=>'COMET_MEDIA_VIEWER']),$actorId,'https://www.facebook.com',$idempotencyKey,
            static function(array $data)use($type,$mediaId):array{if(ServiceInput::scalarId($data[$type.'_delete']['deleted_'.$type.'_id']??null)!==$mediaId)ServiceInput::unexpected(true);
                return ['media_id'=>$mediaId,'type'=>$type,'deleted'=>true,'scope'=>'asset'];});
    }

    /** @param array<string,mixed> $variables */
    private static function displayScale(array $variables):void
    {
        $scale=$variables['scale']??null;if((!is_int($scale)&&!is_float($scale))||!is_finite((float)$scale)||$scale<=0)throw new \InvalidArgumentException('Supply the current positive finite display scale');
    }

    /** @param list<array<string,mixed>> $attachments
     * @param list<string> $ids
     * @param array<string,mixed> $context
     * @param array<string,mixed> $variables
     * @return array<string,mixed>
     */
    private function publishAttachments(string $actorId,array $attachments,array $ids,string $text,string $idempotencyKey,array $context,array $variables):array
    {
        ServiceInput::actor($actorId);$token=ServiceInput::mutationId($idempotencyKey);
        if(strlen($text)>60000||preg_match('//u',$text)!==1)throw new \InvalidArgumentException('Use UTF-8 caption text within the SDK limit');
        ServiceInput::keys($context,['composer_entry_point','composer_source_surface','composer_type','logging','audience','tracking','navigation_data','is_tracking_encrypted']);
        $logging=ServiceInput::requiredArray($context,'logging');ServiceInput::keys($logging,['composer_session_id']);ServiceInput::requiredString($logging,'composer_session_id');
        $audience=ServiceInput::requiredArray($context,'audience');if($audience===[]||array_is_list($audience))throw new \InvalidArgumentException('Supply the actual composer audience object');
        $tracking=ServiceInput::requiredArray($context,'tracking');if(!array_is_list($tracking)||count(array_filter($tracking,'is_string'))!==count($tracking))throw new \InvalidArgumentException('Use the current composer tracking strings');
        if(ServiceInput::requiredString($context,'composer_type')!=='profile')throw new \InvalidArgumentException('This helper implements the observed profile timeline composer');
        $input=['composer_entry_point'=>ServiceInput::requiredString($context,'composer_entry_point'),'composer_source_surface'=>ServiceInput::requiredString($context,'composer_source_surface'),
            'composer_type'=>'profile','source'=>'WWW','actor_id'=>$actorId,'client_mutation_id'=>$token,'idempotence_token'=>$token,
            'message'=>['ranges'=>[],'text'=>$text],'attachments'=>$attachments,'logging'=>$logging,'audience'=>$audience,'tracking'=>$tracking];
        if(isset($context['navigation_data'])){if(!is_array($context['navigation_data']))throw new \InvalidArgumentException('Navigation data must be an object');$input['navigation_data']=$context['navigation_data'];}
        if(isset($context['is_tracking_encrypted'])){if(!is_bool($context['is_tracking_encrypted']))throw new \InvalidArgumentException('Tracking encryption flag must be boolean');$input['is_tracking_encrypted']=$context['is_tracking_encrypted'];}
        $arguments=PageDocuments::arguments('ComposerStoryCreateMutation',[...$variables,'input'=>$input,'feedbackSource'=>0,'feedLocation'=>'TIMELINE',
            'renderLocation'=>'timeline','privacySelectorRenderLocation'=>'COMET_STREAM','isTimeline'=>true,'isFeed'=>false,'isGroup'=>false,'isEvent'=>false,
            'isFundraiser'=>false,'isFunFactPost'=>false,'isSocialLearning'=>false,'isPageNewsFeed'=>false,'isProfileReviews'=>false,'isWorkSharedDraft'=>false]);
        return $this->graph->execute('ComposerStoryCreateMutation',$arguments,$actorId,'https://www.facebook.com',$idempotencyKey,
            static function(array $data)use($ids):array{$created=$data['story_create']??null;if(!is_array($created))ServiceInput::unexpected(true);
                $story=is_array($created['story']??null)?$created['story']:[];$storyId=ServiceInput::scalarId($created['story_id']??$story['id']??null);$postId=ServiceInput::scalarId($created['post_id']??null);
                if($storyId===null&&$postId===null)ServiceInput::unexpected(true);
                return ['story_id'=>$storyId,'post_id'=>$postId,'url'=>is_string($story['url']??null)&&str_starts_with($story['url'],'https://')?$story['url']:null,
                    'published'=>true,'attachment_ids_submitted'=>$ids,'client_rendering_validated'=>false];});
    }

    /** @return array<string,mixed> */
    private static function videoJson(#[\SensitiveParameter] HttpResponse $response):array
    {
        if($response->status!==200)throw new FacebookException('facebook_video_http_failed','The video endpoint did not return its expected Web response','unknown');
        $body=trim($response->body);if(strlen($body)>2097152)throw new FacebookException('facebook_video_response_invalid','The video endpoint response exceeds the SDK limit','not_confirmed');
        if(str_starts_with($body,'for (;;);'))$body=ltrim(substr($body,9));elseif(str_starts_with($body,'for(;;);'))$body=ltrim(substr($body,8));
        try{$value=json_decode($body,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new FacebookException('facebook_video_response_invalid','The video endpoint returned an unsupported response','not_confirmed');}
        if(!is_array($value)||array_is_list($value))throw new FacebookException('facebook_video_response_invalid','The video endpoint returned an unsupported response','not_confirmed');
        if(!empty($value['error'])||!empty($value['errors'])||($value['payload']['__graph_error_forwarded']??false)===true)throw new FacebookException('facebook_video_upload_rejected','Facebook did not accept this video upload stage','not_confirmed');
        return $value;
    }
    /** @return array<string,mixed> */
    private static function videoPayload(#[\SensitiveParameter] HttpResponse $response):array{$value=self::videoJson($response);if(!is_array($value['payload']??null)||array_is_list($value['payload']))ServiceInput::unexpected(true);return $value['payload'];}
    private static function offset(mixed $value,int $maximum):int
    {
        if(is_string($value)&&preg_match('/^(?:0|[1-9][0-9]{0,8})$/D',$value)===1)$value=(int)$value;
        if(!is_int($value)||$value<0||$value>$maximum)throw new FacebookException('facebook_video_offset_invalid','The video endpoint returned an invalid file offset','not_confirmed');return $value;
    }

    /** Read only the authenticated owner's current composer module, without HTTP or fallback config.
     * The resulting context is bound to this account and the explicit actor; it does not grant Page access.
     */
    public function photoContext(string $actorId): PhotoUploadContext
    {
        ServiceInput::actor($actorId);
        if (!$this->session instanceof BootstrapWebSession) throw new FacebookException('facebook_upload_context_unavailable', 'The account owner does not expose its authenticated composer bootstrap');
        try { $module = $this->session->privateModule('ReactComposerMediaConfig'); }
        catch (\Throwable) { throw new FacebookException('facebook_upload_context_unavailable', 'The authenticated composer bootstrap is not available in the current owner state'); }
        if (!is_array($module) || !is_array($module['photo'] ?? null)) throw new FacebookException('facebook_upload_context_unavailable', 'The authenticated bootstrap did not provide the observed photo upload configuration');
        try { return new PhotoUploadContext($this->session->accountId(), $actorId, $module['photo']); }
        catch (\InvalidArgumentException) { throw new FacebookException('facebook_upload_context_unavailable', 'The authenticated photo upload configuration does not match the supported Web contract'); }
    }

    /** Upload one image; a returned photo ID is an uploaded asset, not a published post or message.
     * A stable key binds account, actor, current upload context and file digest. No uncertain upload repeats.
     * The 25 MiB bound is SDK policy, not a claimed Facebook upload limit.
     * @return array<string,mixed>
     */
    public function uploadPhoto(string $actorId, UploadFile $file, string $idempotencyKey, PhotoUploadContext $context): array
    {
        ServiceInput::actor($actorId);
        ServiceInput::key($idempotencyKey);
        if ($context->accountId !== $this->session->accountId() || $context->actorId !== $actorId) throw new FacebookException('facebook_upload_context_mismatch', 'The photo upload context belongs to another account or actor');
        if (!$this->session instanceof MultipartWebSession) throw new FacebookException('facebook_upload_transport_unavailable', 'The account owner does not expose the optional multipart transport');
        if (!in_array($file->mediaType, ['image/jpeg', 'image/png', 'image/webp'], true)) throw new \InvalidArgumentException('This photo helper accepts JPEG, PNG or WebP; use the corresponding actual media type');
        if ($file->size > 26214400) throw new \LengthException('The photo exceeds the SDK limit of 25 MiB');
        $digest = $file->sha256();
        $fields = $this->session->privateRequestFields();
        if (($fields['__user'] ?? null) !== $this->session->accountId() || (!isset($fields['fb_dtsg']) && !isset($fields['lsd']))) throw new FacebookException('facebook_csrf_unavailable', 'The authenticated account did not supply current request fields');
        $url = PhotoUploadContext::ENDPOINT.'?av='.rawurlencode($actorId);
        $command = $this->commands->reserve($this->session->accountId(), 'media.photo.upload', $actorId,
            ['endpoint' => PhotoUploadContext::ENDPOINT, 'upload_data' => $context->formData(),
                'file' => ['sha256' => $digest, 'bytes' => $file->size, 'filename' => $file->filename, 'media_type' => $file->mediaType]], $idempotencyKey);
        if (!$command['execute']) {
            if ($command['state'] === 'confirmed' && is_array($command['result']['projection'] ?? null)) return $command['result']['projection'];
            throw new FacebookException('facebook_command_uncertain', 'This upload already exists without a confirmed result; inspect its command before trying another key', 'unknown', false, $command['id']);
        }
        // PhotosUploadID emits decimal strings. This stable correlation ID does not imply server idempotency.
        $uploadId = (string)(1024 + (int)hexdec(substr(hash('sha256', $command['id']), 0, 12)));
        $form = [...$fields, ...$context->formData(), '__a' => '1', '__req' => base_convert((string)++$this->sequence, 10, 36), 'upload_id' => $uploadId];
        $this->commands->finish($command['id'], 'unknown');
        try {
            // The file field is farr (not farr[]): AsyncUploadBase appends each individual upload by its name.
            try { $response = $this->session->upload($url, $form, ['farr' => $file]); }
            catch (\Throwable) { throw new FacebookException('facebook_upload_transport_failed', 'The photo upload did not complete; the server outcome is unknown', 'unknown'); }
            if ($response->status !== 200) throw new FacebookException('facebook_upload_http_failed', 'The photo upload did not return its expected Web response', 'unknown');
            $body = trim($response->body);
            if (strlen($body) > 2097152) throw new FacebookException('facebook_upload_response_too_large', 'The upload response exceeded the SDK bound', 'not_confirmed');
            if (str_starts_with($body, 'for (;;);')) $body = ltrim(substr($body, 9));
            elseif (str_starts_with($body, 'for(;;);')) $body = ltrim(substr($body, 8));
            try { $value = json_decode($body, true, 64, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new FacebookException('facebook_upload_response_invalid', 'Facebook returned an unsupported upload response', 'not_confirmed'); }
            if (!is_array($value) || array_is_list($value)) throw new FacebookException('facebook_upload_response_invalid', 'Facebook returned an unsupported upload response', 'not_confirmed');
            $this->commands->finish($command['id'], 'response_received');
            if (!empty($value['error']) || !empty($value['errors'])) throw new FacebookException('facebook_upload_rejected', 'Facebook did not accept this upload; inspect the current account and permissions', 'not_confirmed');
            $payload = $value['payload'] ?? null;
            $photoId = is_array($payload) ? ServiceInput::scalarId($payload['photoID'] ?? null) : null;
            if ($photoId === null || !preg_match('/^[1-9][0-9]{0,24}$/D', $photoId)) ServiceInput::unexpected(true);
            $result = ['photo_id' => $photoId, 'uploaded' => true, 'published' => false,
                'width' => is_int($payload['width'] ?? null) && $payload['width'] > 0 ? $payload['width'] : null,
                'height' => is_int($payload['height'] ?? null) && $payload['height'] > 0 ? $payload['height'] : null,
                'image_url' => is_string($payload['imageSrc'] ?? null) && str_starts_with($payload['imageSrc'], 'https://') ? $payload['imageSrc'] : null,
                'media_taken_time' => is_int($payload['mediaTakenTime'] ?? null) ? $payload['mediaTakenTime'] : null];
            $this->commands->finish($command['id'], 'confirmed', ['projection' => $result]);
            return $result;
        } catch (\Throwable $error) {
            throw new FacebookException($error instanceof FacebookException ? $error->reason : 'facebook_upload_unconfirmed',
                $error instanceof FacebookException ? $error->getMessage() : 'The upload has no durable confirmed result',
                $error instanceof FacebookException ? $error->outcome : 'unknown', false, $command['id']);
        }
    }
}
