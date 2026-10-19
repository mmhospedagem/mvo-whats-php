<?php
declare(strict_types=1);
namespace MVO\Facebook\Media;

/** Actor-bound current composer configuration; contains no login tokens or private file path. */
final class VideoUploadContext
{
    public const START='https://vupload-edge.facebook.com/ajax/video/upload/requests/start/';
    public const RECEIVE='https://vupload-edge.facebook.com/ajax/video/upload/requests/receive/';
    public const UPLOAD='https://rupload.facebook.com/fb_video/';
    /** @param array<string,mixed> $configuration JSON from useComposerVideoUploaderConfigQuery. */
    public function __construct(public readonly string $accountId,public readonly string $actorId,public readonly string $targetId,
        public readonly string $entryPoint,public readonly string $creationSessionId,public readonly string $source,array $configuration)
    {
        foreach([$accountId,$actorId,$targetId] as $id)if(preg_match('/^[1-9][0-9]{0,24}$/D',$id)!==1)throw new \InvalidArgumentException('Bind video uploads to exact account, actor and target IDs');
        if($entryPoint===''||strlen($entryPoint)>256||preg_match('/[\x00-\x1f\x7f]/',$entryPoint))throw new \InvalidArgumentException('Supply the current composer entry point');
        if(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',$creationSessionId)!==1)throw new \InvalidArgumentException('Supply the current composer session UUID');
        if(!in_array($source,['composer','newsfeed_composer','biz_composer','album_uploader'],true))throw new \InvalidArgumentException('Use an observed non-advertising composer video source');
        foreach(['actor_id'=>$actorId,'as_actor_id'=>$actorId,'target_id'=>$targetId] as $field=>$expected)if(isset($configuration[$field])){
            $id=$configuration[$field];if((!is_string($id)&&!is_int($id))||(string)$id!==$expected)throw new \InvalidArgumentException('The video configuration belongs to another actor or target');
        }
        if(isset($configuration['composer_dialog_version'])&&$configuration['composer_dialog_version']!=='V2')throw new \InvalidArgumentException('This configured composer version has not been implemented');
        // The Web retriever has these explicit defaults. Configured overrides stay within this bounded contract.
        foreach([['chunk_start_uri','start_uri',self::START],['chunk_receive_uri','receive_uri',self::RECEIVE]] as [$chunk,$normal,$expected]){
            $uri=$configuration[$chunk]??$configuration[$normal]??$expected;
            if($uri!==$expected)throw new \InvalidArgumentException('This configured video upload route has not been implemented');
        }
        foreach(['resumable_service_name'=>'rupload','resumable_service_domain'=>'facebook.com'] as $field=>$expected)if(isset($configuration[$field])&&$configuration[$field]!==$expected)throw new \InvalidArgumentException('This configured resumable service has not been implemented');
    }
    /** @return array<string,string> */
    public function commonForm():array{return ['waterfall_id'=>$this->creationSessionId,'target_id'=>$this->targetId,'source'=>$this->source,
        'composer_entry_point_ref'=>$this->entryPoint,'supports_chunking'=>'true','has_file_been_replaced'=>'false','composer_dialog_version'=>'V2'];}
}
