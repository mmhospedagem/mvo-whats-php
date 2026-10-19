<?php
declare(strict_types=1);
namespace MVO\Facebook\Service;

use MVO\Facebook\Catalog\MediaDocuments;
use MVO\Facebook\Protocol\WebGraphQL;

/** Album CRUD and existing uploaded media, using the observed Comet composer. */
final class AlbumService
{
    public function __construct(private readonly WebGraphQL $graph) {}

    /** The media-set reference token is opaque; it must not be manufactured from an album ID.
     * @param array<string,mixed> $variables Current scale and Relay providers.
     * @return array<string,mixed>
     */
    public function get(string $actorId,string $referenceToken,array $variables):array
    {
        ServiceInput::actor($actorId);ServiceInput::opaque($referenceToken,'media-set reference token');self::scale($variables);
        $data=$this->graph->execute('ProfileCometLegacyAlbumViewRootQuery',MediaDocuments::arguments('ProfileCometLegacyAlbumViewRootQuery',[
            ...$variables,'mediaSetToken'=>$referenceToken,'feedbackSource'=>67,'feedLocation'=>'ALBUM_FEED',
            'privacySelectorRenderLocation'=>'COMET_STREAM','renderLocation'=>'album_feed','useDefaultActor'=>false]),$actorId);
        $album=$data['mediaset']??null;
        if(!is_array($album)||ServiceInput::scalarId($album['id']??null)===null)ServiceInput::unexpected();
        return self::album($album)+['items'=>self::media($album['grid_media']??null)];
    }

    /** @return array<string,mixed> */
    public function items(string $actorId,string $albumId,?string $cursor=null,int $count=8,float $scale=1.0):array
    {
        ServiceInput::actor($actorId);ServiceInput::actor($albumId);
        if($cursor!==null)ServiceInput::opaque($cursor,'album cursor');
        if($count<1||$count>50||!is_finite($scale)||$scale<=0)throw new \InvalidArgumentException('Use 1-50 album items and a positive finite display scale');
        $data=$this->graph->execute('ProfileCometLegacyAlbumGridViewPaginationQuery',MediaDocuments::arguments('ProfileCometLegacyAlbumGridViewPaginationQuery',[
            'id'=>$albumId,'cursor'=>$cursor,'count'=>$count,'scale'=>$scale]),$actorId);
        $node=$data['node']??null;
        if(!is_array($node)||ServiceInput::scalarId($node['id']??null)!==$albumId)ServiceInput::unexpected();
        return ['album_id'=>$albumId,...self::media($node['grid_media']??null)];
    }

    /** Create the album container. Add uploaded photos/videos separately; a partial two-step flow is not retried.
     * @param array<string,mixed> $privacy Current composer privacy (allow/base_state/deny/tag_expansion_state).
     * @param array<string,mixed> $variables Current scale and Relay providers.
     * @param list<string> $contributors Exact contributor IDs selected by the application.
     * @return array<string,mixed>
     */
    public function create(string $actorId,string $title,string $description,array $privacy,string $creationSessionId,string $idempotencyKey,array $variables,array $contributors=[]):array
    {
        ServiceInput::actor($actorId);$title=ServiceInput::text($title);$description=self::optionalText($description);self::privacy($privacy);
        ServiceInput::opaque($creationSessionId,'composer session ID',256);ServiceInput::key($idempotencyKey);self::scale($variables);self::ids($contributors,50,true);
        $input=['title'=>$title,'description'=>$description,'privacy'=>$privacy,'contributors'=>$contributors,'client_mutation_id'=>$creationSessionId];
        return $this->graph->execute('useCometPhotoAlbumCreateMutation',MediaDocuments::arguments('useCometPhotoAlbumCreateMutation',[...$variables,'input'=>$input]),$actorId,'https://www.facebook.com',$idempotencyKey,
            static function(array $data):array{$album=$data['album_create']['album']??null;if(!is_array($album)||ServiceInput::scalarId($album['id']??null)===null)ServiceInput::unexpected(true);return ['album_id'=>ServiceInput::scalarId($album['id']),'url'=>self::url($album['url']??null),'created'=>true,'media_added'=>false];});
    }

    /** @param array<string,mixed> $changes title, description, privacy, contributors_to_add/remove.
     * @param array<string,mixed> $variables Current scale and Relay providers.
     * @return array<string,mixed>
     */
    public function edit(string $actorId,string $albumId,array $changes,string $idempotencyKey,array $variables):array
    {
        ServiceInput::actor($actorId);ServiceInput::actor($albumId);ServiceInput::key($idempotencyKey);self::scale($variables);
        ServiceInput::keys($changes,['title','description','privacy','contributors_to_add','contributors_to_remove']);
        if($changes===[])throw new \InvalidArgumentException('Supply at least one album change');
        foreach(['title','description'] as $field)if(array_key_exists($field,$changes)){
            if(!is_string($changes[$field]))throw new \InvalidArgumentException('Album text must be UTF-8 strings');
            $changes[$field]=$field==='title'?ServiceInput::text($changes[$field]):self::optionalText($changes[$field]);
        }
        if(array_key_exists('privacy',$changes)){if(!is_array($changes['privacy']))throw new \InvalidArgumentException('Supply the current composer privacy');self::privacy($changes['privacy']);}
        foreach(['contributors_to_add','contributors_to_remove'] as $field)if(array_key_exists($field,$changes)){
            if(!is_array($changes[$field]))throw new \InvalidArgumentException('Supply a list of contributor IDs');self::ids($changes[$field],50,true);
        }
        if(array_intersect($changes['contributors_to_add']??[],$changes['contributors_to_remove']??[])!==[])throw new \InvalidArgumentException('A contributor cannot be added and removed in one change');
        return $this->graph->execute('CometAlbumEditAlbumMutation',MediaDocuments::arguments('CometAlbumEditAlbumMutation',[
            ...$variables,'input'=>['album_id'=>$albumId,...$changes],'privacySelectorRenderLocation'=>'COMET_ALBUM','renderLocation'=>'permalink']),$actorId,'https://www.facebook.com',$idempotencyKey,
            static function(array $data)use($albumId,$changes):array{
                $album=$data['album_edit']['album']??null;if(!is_array($album)||ServiceInput::scalarId($album['id']??null)!==$albumId)ServiceInput::unexpected(true);
                if(isset($changes['title'])&&($album['title']['text']??null)!==$changes['title'])ServiceInput::unexpected(true);
                if(isset($changes['description'])&&($album['message']['text']??null)!==$changes['description'])ServiceInput::unexpected(true);
                return self::album($album)+['updated'=>true];});
    }

    /** Deleting an album also removes its contents as the native confirmation dialog explains.
     * @param array<string,mixed> $variables Current scale and Relay providers.
     * @return array<string,mixed>
     */
    public function delete(string $actorId,string $albumId,string $idempotencyKey,array $variables):array
    {
        ServiceInput::actor($actorId);ServiceInput::actor($albumId);ServiceInput::key($idempotencyKey);self::scale($variables);
        return $this->graph->execute('CometAlbumDeleteAlbumMutation',MediaDocuments::arguments('CometAlbumDeleteAlbumMutation',[...$variables,'input'=>['album_id'=>$albumId]]),$actorId,'https://www.facebook.com',$idempotencyKey,
            static function(array $data)use($albumId):array{if(ServiceInput::scalarId($data['album_delete']['deleted_album_id']??null)!==$albumId)ServiceInput::unexpected(true);return ['album_id'=>$albumId,'deleted'=>true];});
    }

    /** @param list<array{type:string,id:string,caption?:string}> $attachments Already uploaded assets, with optional per-item captions.
     * @param array<string,mixed> $variables Current scale and Relay providers.
     * @return array<string,mixed>
     */
    public function addMedia(string $actorId,string $albumId,array $attachments,string $creationSessionId,string $idempotencyKey,array $variables):array
    {
        ServiceInput::actor($actorId);ServiceInput::actor($albumId);ServiceInput::key($idempotencyKey);ServiceInput::opaque($creationSessionId,'composer session ID',256);self::scale($variables);
        $media=self::attachments($attachments);$ids=array_column($attachments,'id');
        return $this->graph->execute('useCometPhotoAlbumStoryCreateMutation',MediaDocuments::arguments('useCometPhotoAlbumStoryCreateMutation',[
            ...$variables,'input'=>['audience'=>['to_id'=>$albumId],'source'=>'WWW','attachments'=>$media,'logging'=>['composer_session_id'=>$creationSessionId]],
            'feedbackSource'=>67,'feedLocation'=>'ALBUM_FEED','privacySelectorRenderLocation'=>'COMET_STREAM','renderLocation'=>'permalink']),$actorId,'https://www.facebook.com',$idempotencyKey,
            static function(array $data)use($albumId,$ids):array{
                $story=$data['story_create']['story']??null;if(!is_array($story))ServiceInput::unexpected(true);
                $view=$story['profileAlbumView']??$story['editAlbumView']??$story['albumPhotoCollage']??null;
                if(!is_array($view)||ServiceInput::scalarId($view['id']??null)!==$albumId)ServiceInput::unexpected(true);
                $echo=[];foreach(['profileAlbumView','editAlbumView','albumPhotoCollage'] as $name)foreach(['media','grid_media'] as $field){
                    $edges=$story[$name][$field]['edges']??[];if(!is_array($edges))ServiceInput::unexpected(true);
                    foreach($edges as $edge){$id=is_array($edge)?ServiceInput::scalarId($edge['node']['id']??null):null;if($id!==null)$echo[]=$id;}
                }
                if(array_diff($ids,$echo)!==[])ServiceInput::unexpected(true);
                return ['album_id'=>$albumId,'added_media_ids'=>$ids,'added'=>true];});
    }

    /** @param array<string,mixed> $variables Current scale and Relay providers.
     * @return array<string,mixed>
     */
    public function setCover(string $actorId,string $albumId,string $photoId,string $idempotencyKey,array $variables):array
    {
        ServiceInput::actor($actorId);ServiceInput::actor($albumId);ServiceInput::actor($photoId);ServiceInput::key($idempotencyKey);self::scale($variables);
        return $this->graph->execute('useCometPhotoAlbumCoverUpdateMutation',MediaDocuments::arguments('useCometPhotoAlbumCoverUpdateMutation',[...$variables,'input'=>['album_id'=>$albumId,'cover_media_id'=>$photoId]]),$actorId,'https://www.facebook.com',$idempotencyKey,
            static function(array $data)use($albumId,$photoId):array{$a=$data['album_edit']['album']??null;if(!is_array($a)||ServiceInput::scalarId($a['id']??null)!==$albumId||ServiceInput::scalarId($a['album_cover_focused_image']['image']['id']??null)!==$photoId)ServiceInput::unexpected(true);return ['album_id'=>$albumId,'cover_photo_id'=>$photoId,'updated'=>true];});
    }

    /** Reorder a bounded list. Full order is confirmed only if all requested IDs are echoed in that order.
     * @param list<string> $mediaIds
     * @param array<string,mixed> $variables Current scale and Relay providers.
     * @return array<string,mixed>
     */
    public function reorder(string $actorId,string $albumId,array $mediaIds,string $idempotencyKey,array $variables):array
    {
        ServiceInput::actor($actorId);ServiceInput::actor($albumId);ServiceInput::key($idempotencyKey);self::ids($mediaIds,50);self::scale($variables);
        return $this->graph->execute('useCometAlbumEditOrderMutation',MediaDocuments::arguments('useCometAlbumEditOrderMutation',[
            ...$variables,'input'=>['album_id'=>$albumId,'media_fbids'=>$mediaIds],'feedbackSource'=>67,'feedLocation'=>'ALBUM_FEED',
            'privacySelectorRenderLocation'=>'COMET_STREAM','renderLocation'=>'album_feed']),$actorId,'https://www.facebook.com',$idempotencyKey,
            static function(array $data)use($albumId,$mediaIds):array{$a=$data['album_reorder']['editAlbumView']??null;if(!is_array($a)||ServiceInput::scalarId($a['id']??null)!==$albumId)ServiceInput::unexpected(true);
                $page=self::media($a['media']??null);$echo=array_column($page['items'],'id');if(array_slice($echo,0,count($mediaIds))!==$mediaIds)ServiceInput::unexpected(true);return ['album_id'=>$albumId,'ordered_media_ids'=>$mediaIds,'order_confirmed'=>true];});
    }

    /** Validate application input before constructing the observed album attachment shape.
     * @param array<mixed> $attachments
     * @return list<array<string,mixed>>
     */
    public static function attachments(array $attachments):array
    {
        if(!array_is_list($attachments)||count($attachments)<1||count($attachments)>50)throw new \InvalidArgumentException('Supply 1-50 uploaded media items');
        $result=[];$seen=[];
        foreach($attachments as $item){
            if(!is_array($item))throw new \InvalidArgumentException('Supply explicit uploaded photo/video objects');ServiceInput::keys($item,['type','id','caption']);
            if(!in_array($item['type']??null,['photo','video'],true)||!is_string($item['id']??null))throw new \InvalidArgumentException('Use photo/video and the exact uploaded asset ID');
            $id=ServiceInput::actor($item['id']);if(isset($seen[$id]))throw new \InvalidArgumentException('Duplicate uploaded media ID');$seen[$id]=true;$value=['id'=>$id];
            if(array_key_exists('caption',$item)){if(!is_string($item['caption']))throw new \InvalidArgumentException('Media captions must be UTF-8 text');$value['message']=['text'=>self::optionalText($item['caption'])];}
            $result[]=[$item['type']=>$value];
        }
        return $result;
    }
    /** @param array<string,mixed> $variables */
    private static function scale(array $variables):void{if(!is_int($variables['scale']??null)&&!is_float($variables['scale']??null))throw new \InvalidArgumentException('Supply the current numeric display scale');if(!is_finite((float)$variables['scale'])||$variables['scale']<=0)throw new \InvalidArgumentException('Use a positive finite display scale');}
    /** @param array<mixed> $privacy */
    private static function privacy(array $privacy):void
    {
        ServiceInput::keys($privacy,['allow','base_state','deny','tag_expansion_state']);
        foreach(['base_state','tag_expansion_state'] as $field)ServiceInput::requiredString($privacy,$field);
        foreach(['allow','deny'] as $field){$list=$privacy[$field]??null;if(!is_array($list)||!array_is_list($list)||count($list)>100)throw new \InvalidArgumentException('Supply the current composer privacy lists');foreach($list as $id)if(!is_string($id))throw new \InvalidArgumentException('Privacy IDs must remain exact strings');}
    }
    /** @param array<mixed> $ids */
    private static function ids(array $ids,int $max,bool $empty=false):void{if(!array_is_list($ids)||(!$empty&&$ids===[])||count($ids)>$max||count(array_unique($ids,SORT_REGULAR))!==count($ids))throw new \InvalidArgumentException('Supply a bounded list of distinct media/contributor IDs');foreach($ids as $id){if(!is_string($id))throw new \InvalidArgumentException('IDs must remain exact strings');ServiceInput::actor($id);}}
    private static function optionalText(string $text):string{if(strlen($text)>60000||preg_match('//u',$text)!==1)throw new \InvalidArgumentException('Use UTF-8 album text within the SDK limit');return $text;}
    private static function url(mixed $url):?string{return is_string($url)&&str_starts_with($url,'https://')?$url:null;}
    /** @param array<string,mixed> $album
     * @return array<string,mixed>
     */
    private static function album(array $album):array{return ['album_id'=>ServiceInput::scalarId($album['id']??null),'title'=>is_string($album['title']['text']??null)?$album['title']['text']:null,'description'=>is_string($album['message']['text']??null)?$album['message']['text']:null,'reference_token'=>is_string($album['reference_token']??null)?$album['reference_token']:null,'owner_id'=>ServiceInput::scalarId($album['owner']['id']??null),'url'=>self::url($album['url']??null),'can_upload'=>is_bool($album['can_upload']??null)?$album['can_upload']:null];}
    /** @return array{items:list<array<string,mixed>>,page_info:array{end_cursor:?string,has_next_page:bool}} */
    private static function media(mixed $connection):array
    {
        if(!is_array($connection)||!is_array($connection['edges']??null)||!array_is_list($connection['edges'])||count($connection['edges'])>1000||!is_array($connection['page_info']??null)||!is_bool($connection['page_info']['has_next_page']??null))ServiceInput::unexpected();
        $items=[];foreach($connection['edges'] as $edge){$n=is_array($edge)?($edge['node']??null):null;if(!is_array($n)||ServiceInput::scalarId($n['id']??null)===null||!is_string($n['__typename']??null))ServiceInput::unexpected();$items[]=['id'=>ServiceInput::scalarId($n['id']),'type'=>$n['__typename'],'image_url'=>self::url($n['image']['uri']??null),'caption'=>is_string($n['accessibility_caption']??null)?$n['accessibility_caption']:null,'title'=>is_string($n['title']['text']??null)?$n['title']['text']:null,'owner_id'=>ServiceInput::scalarId($n['owner']['id']??null),'creation_story_id'=>ServiceInput::scalarId($n['creation_story']['id']??null),'cursor'=>is_string($edge['cursor']??null)?$edge['cursor']:null];}
        $info=$connection['page_info'];$cursor=$info['end_cursor']??null;if($cursor!==null&&!is_string($cursor))ServiceInput::unexpected();if($info['has_next_page']&&(!is_string($cursor)||$cursor===''))ServiceInput::unexpected();return ['items'=>$items,'page_info'=>['end_cursor'=>$cursor,'has_next_page'=>$info['has_next_page']]];
    }
}
