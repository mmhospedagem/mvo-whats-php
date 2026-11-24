<?php
declare(strict_types=1);

namespace MVO\Facebook\Protocol;

use MVO\Facebook\FacebookException;

/** Projects only shipped-procedure fields from a decoded, confirmed Page mailbox batch.
 * Never downloads media or interprets an attachment belonging to another message/thread.
 */
final class PageInboxAttachmentProjector
{
    /** @var array<string,true> */ private array $unsupported=[];
    public function __construct(private readonly ?int $nowMilliseconds=null) {}

    /** LSInsertBlobAttachment offsets are established by the shipped table16 writer;
     * LSInsertOptimisticAttachment has its own independently established field map.
     * The caller must already have validated the complete native sync transaction.
     * @param list<array{name:string,args:list<mixed>}> $calls
     * @param list<array<string,mixed>> $messages Confirmed message projections from this same Page batch.
     * @return array<string,list<array<string,mixed>>> Attachments by exact message_id; no global/XMA guessing.
     */
    public function project(array $calls,array $messages,string $pageId):array
    {
        $this->unsupported=[];
        if(self::id($pageId)===null)throw new \InvalidArgumentException('Preserve the exact numeric Page identity');
        if(!array_is_list($calls)||!array_is_list($messages)||count($calls)>50000||count($messages)>5000)self::invalid();
        $participants=[];$business=[];$nativeMessages=[];
        foreach($calls as $call){
            $args=$call['args'];
            if($call['name']==='LSAddParticipantIdToGroupThread'){
                $thread=self::id($args[0]??null);$participant=self::id($args[1]??null);
                if($thread!==null&&$participant!==null)$participants[$thread][$participant]=true;
            }elseif($call['name']==='LSSetBusinessThreadInfo'){
                $thread=self::id($args[0]??null);$comm=self::id($args[1]??null);
                if($thread!==null&&$comm!==null)$business[$thread]=true;
            }elseif(in_array($call['name'],['LSInsertMessage','LSUpsertMessage'],true)){
                $thread=self::id($args[3]??null);$message=self::messageId($args[8]??null);$sender=self::id($args[10]??null);
                if($thread!==null&&$message!==null&&$sender!==null){
                    $key=$thread."\0".$message;if(isset($nativeMessages[$key])&&$nativeMessages[$key]!==$sender)self::invalid();
                    $nativeMessages[$key]=$sender;
                }
            }
        }
        $known=[];
        foreach($messages as $message){
            $id=self::messageId($message['message_id']??$message['id']??null);$thread=self::id($message['thread_id']??null);$sender=self::id($message['sender_id']??null);
            if($id===null||$thread===null||$sender===null||!isset($business[$thread],$participants[$thread][$pageId],$participants[$thread][$sender])
                ||($nativeMessages[$thread."\0".$id]??null)!==$sender||isset($known[$id])&&$known[$id]!==$thread)self::invalid();
            $known[$id]=$thread;
        }
        $rows=[];$levels=[];$total=0;
        foreach($calls as $call){
            $args=$call['args'];$blob=$call['name']==='LSInsertBlobAttachment';$optimistic=$call['name']==='LSInsertOptimisticAttachment';$xma=$call['name']==='LSInsertXmaAttachment';
            if(!$blob&&!$optimistic&&!$xma){
                if(in_array($call['name'],['LSInsertStickerAttachment','LSInsertAttachment','LSInsertAttachmentItem','LSUpdateExtraAttachmentColumns'],true))$this->unsupported[$call['name']]=true;
                continue;
            }
            $thread=self::id($args[$blob?27:($xma?25:0)]??null);$message=self::messageId($args[$blob?32:($xma?30:2)]??null);
            // IDs are bound as a tuple; sharing only a message ID cannot select a foreign attachment.
            if($thread===null||$message===null||($known[$message]??null)!==$thread)continue;
            $id=self::attachmentId($args[$blob?34:($xma?32:4)]??null);$level=$blob?self::number($args[48]??null):($xma?self::number($args[132]??null):20);
            if($id===null||$level===null)self::invalid();
            $scope=$blob?self::number($args[28]??null):($xma?self::number($args[26]??null):0);
            if($scope!==0){$this->unsupported[$xma?'unsupported_xma_scope':'unsupported_blob_scope']=true;continue;}
            $row=$blob?$this->blob($args,$id):($xma?$this->xma($args,$id):self::optimistic($args,$id));
            if(isset($rows[$message][$id])){
                if($levels[$message][$id]>$level)continue;
                if($levels[$message][$id]===$level&&$rows[$message][$id]!==$row)self::invalid();
            }else{
                if(++$total>1000||count($rows[$message]??[])>=100)throw new FacebookException('facebook_document_limit','The Page snapshot contains too many message attachments');
            }
            $rows[$message][$id]=$row;$levels[$message][$id]=$level;
        }
        $result=[];foreach($rows as $message=>$items)$result[$message]=array_values($items);
        return $result;
    }

    /** Coverage gaps are snapshot-wide and never attached to an arbitrary message.
     * @return array{partial:bool,unsupported_procedures:list<string>}
     */
    public function coverage():array{return ['partial'=>$this->unsupported!==[],'unsupported_procedures'=>array_keys($this->unsupported)];}

    /**
     * @param list<mixed> $args
     * @return array<string,mixed>
     */
    private function blob(array $args,string $id):array
    {
        $type=self::type($args[29]??null);$now=$this->nowMilliseconds??(int)floor(microtime(true)*1000);
        [$playable,$playExpiry]=$this->url($args[3]??null,$args[4]??null,$args[5]??null,$now);
        [$preview,$previewExpiry]=$this->url($args[45]??$args[8]??null,$args[9]??null,$args[10]??null,$now);
        if($preview===null)[$preview,$previewExpiry]=$this->url($args[8]??null,$args[9]??null,$args[10]??null,$now);
        $playMime=self::mime($args[6]??null);$previewMime=self::mime($args[11]??null);
        $url=$playable;$mime=$playMime;$expiry=$playExpiry;
        if(in_array($type,['image','sticker'],true)){
            // A GIF's playable stream may be MP4; image rendering uses only an image-typed stream or the native preview.
            if($playable===null||$playMime!==null&&!str_starts_with($playMime,'image/')){$url=$preview;$mime=$previewMime;$expiry=$previewExpiry;}
        }
        $unsupported=$type===null;$type??='file';
        if($unsupported){$url=null;$preview=null;$mime=null;$expiry=null;$previewExpiry=null;$this->unsupported['unsupported_attachment_type']=true;}
        $available=$url!==null?'available':($preview!==null?'preview_only':'unavailable');
        $reason=$unsupported?'unsupported_attachment_type':($available==='unavailable'?'media_url_unavailable':null);
        return ['id'=>$id,'type'=>$type,'url'=>$url,'preview_url'=>$preview,'mime_type'=>$mime,'filename'=>self::filename($args[0]??null),
            'width'=>self::dimension($args[14]??null),'height'=>self::dimension($args[15]??null),'duration_ms'=>self::number($args[22]??null),
            'size_bytes'=>self::number($args[1]??null),'availability'=>$available,'unavailable_reason'=>$reason,
            'url_expires_at_ms'=>$expiry,'preview_expires_at_ms'=>$previewExpiry];
    }

    /**
     * @param list<mixed> $args
     * @return array<string,mixed>
     */
    private static function optimistic(array $args,string $id):array
    {
        return ['id'=>$id,'type'=>self::type($args[5]??null)??'file','url'=>null,'preview_url'=>null,'mime_type'=>null,
            'filename'=>self::filename($args[6]??null),'width'=>null,'height'=>null,'duration_ms'=>null,'size_bytes'=>null,
            'availability'=>'unavailable','unavailable_reason'=>'media_url_unavailable','url_expires_at_ms'=>null,'preview_expires_at_ms'=>null];
    }

    /** Field map from the shipped LSInsertXmaAttachment table16 writer.
     * Generic action/story/CTA URLs are deliberately excluded; only native media fields pass CDN policy.
     * @param list<mixed> $args
     * @return array<string,mixed>
     */
    private function xma(array $args,string $id):array
    {
        $now=$this->nowMilliseconds??(int)floor(microtime(true)*1000);
        [$playable,$playExpiry]=$this->url($args[4]??null,$args[5]??null,$args[6]??null,$now);
        [$preview,$previewExpiry]=$this->url($args[114]??$args[8]??null,$args[9]??null,$args[10]??null,$now);
        if($preview===null)[$preview,$previewExpiry]=$this->url($args[8]??null,$args[9]??null,$args[10]??null,$now);
        [$image,$imageExpiry]=$this->url($args[54]??null,$args[55]??null,$args[56]??null,$now);
        $mime=self::mime($args[7]??null);$previewMime=self::mime($args[11]??null);
        $type='file';$url=$playable;$expiry=$playExpiry;
        if($mime!==null&&str_starts_with($mime,'video/'))$type='video';
        elseif($mime!==null&&str_starts_with($mime,'audio/'))$type='audio';
        elseif($mime!==null&&str_starts_with($mime,'image/'))$type='image';
        elseif(($playable===null||$mime===null)&&($image!==null||$preview!==null)){$type='image';$url=$image??$preview;$expiry=$image!==null?$imageExpiry:$previewExpiry;$mime=$previewMime;}
        if($url===null&&PageInboxContactProjector::photoUrl($args[126]??null)!==null){$url=PageInboxContactProjector::photoUrl($args[126]);$expiry=null;$type='audio';$mime=null;}
        if($preview===null&&$image!==null){$preview=$image;$previewExpiry=$imageExpiry;}
        $available=$url!==null?'available':($preview!==null?'preview_only':'unavailable');
        return ['id'=>$id,'type'=>$type,'native_type'=>'xma','url'=>$url,'preview_url'=>$preview,'mime_type'=>$mime,
            'filename'=>self::filename($args[1]??null),'width'=>self::dimension($args[13]??null),'height'=>self::dimension($args[14]??null),
            'duration_ms'=>null,'size_bytes'=>self::number($args[2]??null),'availability'=>$available,
            'unavailable_reason'=>$available==='unavailable'?'native_card_media_unavailable':null,
            'url_expires_at_ms'=>$expiry,'preview_expires_at_ms'=>$previewExpiry,
            'title'=>self::filename($args[58]??null),'description'=>self::filename($args[63]??null)];
    }

    /** Mirrors LSMediaUrlAttachment's expiration/fallback choice; URLs stay literal native CDN URLs.
     * @return array{?string,?int}
     */
    private function url(mixed $primary,mixed $fallback,mixed $expiration,int $now):array
    {
        $expiry=self::number($expiration);$expired=$expiration!==null&&($expiry===null||$expiry<=$now);
        $url=$expired?null:PageInboxContactProjector::photoUrl($primary);
        return $url!==null?[$url,$expiry]:[PageInboxContactProjector::photoUrl($fallback),null];
    }

    /** MessagingAttachmentType is a shipped enum; unsupported kinds never gain an invented media URL. */
    private static function type(mixed $value):?string
    {
        return match(self::number($value)){1,10,15=>'sticker',2,3=>'image',4=>'video',5=>'audio',6=>'file',default=>null};
    }
    private static function mime(mixed $value):?string
    {
        if(!is_string($value)||strlen($value)>255)return null;
        $mime=strtolower(trim(explode(';',$value,2)[0]));
        return preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$~D',$mime)===1?$mime:null;
    }
    private static function filename(mixed $value):?string {return is_string($value)&&$value!==''&&strlen($value)<=1024&&preg_match('//u',$value)===1&&preg_match('/[\x00-\x1f\x7f]/',$value)!==1?$value:null;}
    private static function dimension(mixed $value):?int{$number=self::number($value);return $number!==null&&$number>0&&$number<=32768?$number:null;}
    private static function number(mixed $value):?int
    {
        if(is_int($value))return $value>=0?$value:null;
        if(!is_string($value)||preg_match('/^(?:0|[1-9][0-9]{0,18})$/D',$value)!==1||strlen($value)===19&&strcmp($value,(string)PHP_INT_MAX)>0)return null;
        return (int)$value;
    }
    private static function id(mixed $value):?string{$number=self::number($value);return $number!==null&&$number>0?(string)$number:null;}
    private static function attachmentId(mixed $value):?string{return is_int($value)&&$value>=0?(string)$value:self::messageId($value);}
    private static function messageId(mixed $value):?string{return is_string($value)&&$value!==''&&strlen($value)<=512&&preg_match('//u',$value)===1&&preg_match('/[\x00-\x1f\x7f]/',$value)!==1?$value:null;}
    private static function invalid():never{throw new FacebookException('facebook_inbox_attachment_unconfirmed','The Page snapshot did not confirm a message attachment identity, owner or consistent native row');}
    /** @return array<string,mixed> */ public function __debugInfo():array{return ['coverage'=>$this->coverage()];}
}
