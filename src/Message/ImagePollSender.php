<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Protocol\SchemaCodec;

/** Newsletter photo polls: prepare all images before publishing the parent and its associated children. */
final class ImagePollSender
{
    private readonly \Closure $publish;
    private readonly \Closure $prepare;
    /** @param callable(string,array<string,mixed>,array<string,mixed>):array<string,mixed> $publish
     * @param callable(array<string,mixed>):array<string,mixed> $prepare */
    public function __construct(callable $publish,callable $prepare)
    {$this->publish=\Closure::fromCallable($publish);$this->prepare=\Closure::fromCallable($prepare);}

    /** Validation never uploads, discovers recipients or publishes a message.
     * @param array<string,mixed> $content */
    public static function validate(array $content):void
    {
        $allowed=['type','name','options','selectableCount','pollType','correctAnswer','endTime','hideParticipantName'];
        foreach($content as$key=>$_)if(!in_array($key,$allowed,true))throw new \InvalidArgumentException('Unknown image poll field '.$key);
        if(isset($content['type'])&&$content['type']!=='image-poll')throw new \InvalidArgumentException('Photo polls require type image-poll');
        $options=$content['options']??null;
        if(!is_array($options)||!array_is_list($options)||count($options)<2||count($options)>12)throw new \InvalidArgumentException('Photo polls require 2 to 12 image options');
        $poll=$content;$poll['type']='poll';$poll['pollContentType']='IMAGE';$poll['options']=[];
        foreach($options as$option){
            if(!is_array($option))throw new \InvalidArgumentException('Photo poll option must be an object');
            foreach($option as$key=>$_)if(!in_array($key,['name','media','mimetype','width','height','jpegThumbnail','accessibilityLabel','prepared'],true))throw new \InvalidArgumentException('Unknown photo poll option field '.$key);
            if(isset($option['prepared'])){
                if(isset($option['media'])||!is_array($option['prepared']))throw new \InvalidArgumentException('Use prepared image or media, not both');
                self::prepared($option['prepared']);
            }elseif((!is_string($option['media']??null)&&!is_array($option['media']??null))||$option['media']===''||$option['media']===[])throw new \InvalidArgumentException('Each photo poll option requires image media');
            foreach(['width','height']as$field)if(isset($option[$field])&&(!is_int($option[$field])||$option[$field]<1||$option[$field]>0xffffffff))throw new \InvalidArgumentException('Invalid image '.$field);
            if(isset($option['mimetype'])&&(!is_string($option['mimetype'])||!preg_match('~^image/[a-zA-Z0-9.+-]+$~D',$option['mimetype'])))throw new \InvalidArgumentException('Photo poll options require an image mimetype');
            if(isset($option['jpegThumbnail'])&&(!is_string($option['jpegThumbnail'])||strlen($option['jpegThumbnail'])>1048576))throw new \InvalidArgumentException('Invalid image thumbnail');
            if(isset($option['accessibilityLabel'])&&(!is_string($option['accessibilityLabel'])||preg_match('//u',$option['accessibilityLabel'])!==1))throw new \InvalidArgumentException('Invalid image accessibilityLabel');
            $poll['options'][]=['name'=>$option['name']??null,'optionHash'=>str_repeat('0',64)];
        }
        AdvancedContent::build($poll,'1@newsletter');
    }

    /** @param array<string,mixed> $content
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function send(string $jid,array $content,array $options=[]):array
    {
        if(preg_match('/^[0-9]+@newsletter$/D',$jid)!==1)throw new \InvalidArgumentException('Photo polls are supported only in newsletters');
        self::validate($content);foreach($options as$key=>$_)if($key!=='id')throw new \InvalidArgumentException('Unknown photo poll send option '.$key);
        $parentId=self::id($options['id']??null);$images=[];$poll=$content;$poll['type']='poll';$poll['pollContentType']='IMAGE';$poll['options']=[];
        foreach($content['options']as$option){
            $image=$option['prepared']??null;
            if($image===null){$typed=$option;unset($typed['name']);$typed['type']='image';$prepared=($this->prepare)($typed);$image=$prepared['imageMessage']??null;}
            if(!is_array($image))throw new \UnexpectedValueException('Photo poll preparation did not produce an image');
            self::prepared($image);$images[]=$image;
            $poll['options'][]=['name'=>$option['name'],'optionHash'=>PollOptionHash::image($option['name'],$image['fileSha256'])];
        }
        $parent=AdvancedContent::build($poll,$jid)??throw new \LogicException('Photo poll parent unavailable');
        $parentResult=$this->publishOne($jid,$parent,[...$options,'id'=>$parentId]);
        $key=['remoteJid'=>$jid,'fromMe'=>true,'id'=>$parentResult['id']];
        $result=['parent'=>$parentResult,'parentKey'=>$key,'messages'=>[],'complete'=>false,'sentCount'=>0];
        if(!self::acknowledged($parentResult)){$result['failure']=self::failure(null,$parentResult);return $result;}
        foreach($images as$index=>$image){
            $child=['pollCreationOptionImageMessage'=>['message'=>['imageMessage'=>$image]],'messageContextInfo'=>['messageAssociation'=>['associationType'=>'MEDIA_POLL','parentMessageKey'=>$key]]];
            SchemaCodec::encode('Message',$child);
            $childResult=$this->publishOne($jid,$child,[...$options,'id'=>self::id()]);$result['messages'][]=$childResult;
            if(!self::acknowledged($childResult)){$result['failure']=self::failure($index,$childResult);return $result;}
            ++$result['sentCount'];
        }
        $result['complete']=true;return $result;
    }

    /** @param array<string,mixed> $image */
    private static function prepared(array $image):void
    {
        if(!is_string($image['fileSha256']??null)||strlen($image['fileSha256'])!==32||!is_string($image['directPath']??null)||$image['directPath']==='')throw new \InvalidArgumentException('Photo poll requires an uploaded image descriptor with a plaintext hash');
        if(!empty($image['mediaKey'])||!empty($image['fileEncSha256'])||!empty($image['viewOnce']))throw new \InvalidArgumentException('Newsletter photo polls require plaintext, non-view-once image uploads');
        if(!is_string($image['mimetype']??null)||!str_starts_with(strtolower($image['mimetype']),'image/'))throw new \InvalidArgumentException('Photo poll descriptor requires an image mimetype');
        SchemaCodec::encode('Message.ImageMessage',$image);
    }
    /** @param array<string,mixed> $message
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    private function publishOne(string $jid,array $message,array $options):array
    {
        $id=$options['id'];
        try{$result=($this->publish)($jid,$message,$options);}
        catch(\MVO\Service\ServiceException $error){return ['id'=>$id,'to'=>$jid,'status'=>'failed']+self::serverFailure($error);}
        catch(\Throwable){return ['id'=>$id,'to'=>$jid,'status'=>'unknown'];}
        if(!is_string($result['id']??null)||$result['id']!==$id)throw new \UnexpectedValueException('Photo poll publisher must preserve the reserved message ID');
        return $result;
    }
    /** An explicit server NACK is distinct from a timeout; never copy server text or credentials.
     * @return array<string,string> */
    private static function serverFailure(\MVO\Service\ServiceException $error):array
    {return is_string($error->serverCode)&&preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$error->serverCode)===1?['serverCode'=>$error->serverCode]:[];}
    /** @param array<string,mixed> $result */
    private static function acknowledged(array $result):bool{return in_array($result['status']??null,['server_ack','delivered','read','played'],true);}
    private static function id(mixed $id=null):string{if($id===null)return '3EB0'.strtoupper(bin2hex(random_bytes(9)));if(!is_string($id)||preg_match('/^[a-zA-Z0-9_-]{1,128}$/D',$id)!==1)throw new \InvalidArgumentException('Invalid photo poll message ID');return $id;}
    /** @param array<string,mixed> $result
     * @return array<string,mixed> */
    private static function failure(?int $index,array $result):array{return ['index'=>$index,'messageId'=>$result['id'],'outcome'=>$result['status']??'unknown','retryWholePoll'=>false]+array_intersect_key($result,['serverCode'=>true]);}
}
