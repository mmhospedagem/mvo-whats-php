<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Protocol\SchemaCodec;

/** A real associated image; the event's invite thumbnail is a separate protocol field. */
final class EventCoverSender
{
    private readonly \Closure $send;
    private readonly \Closure $prepare;
    /** @param callable(string,array<string,mixed>,array<string,mixed>):array<string,mixed> $send
     * @param callable(array<string,mixed>):array<string,mixed> $prepare */
    public function __construct(callable $send,callable $prepare){$this->send=\Closure::fromCallable($send);$this->prepare=\Closure::fromCallable($prepare);}

    /** @param array<string,mixed> $content */
    public static function validate(array $content):void
    {
        if(($content['type']??null)!=='event')throw new \InvalidArgumentException('An event cover requires type event');
        if(isset($content['messageId']))self::id($content['messageId']);
        $cover=$content['cover']??null;if(!is_array($cover))throw new \InvalidArgumentException('Event cover must contain image media');
        foreach($cover as$field=>$_)if(!in_array($field,['media','prepared','mimetype','width','height','jpegThumbnail','accessibilityLabel'],true))throw new \InvalidArgumentException('Unknown event cover field '.$field);
        if(isset($cover['prepared'])){if(isset($cover['media'])||!is_array($cover['prepared']))throw new \InvalidArgumentException('Use prepared cover or media, not both');self::prepared($cover['prepared']);}
        elseif((!is_string($cover['media']??null)&&!is_array($cover['media']??null))||$cover['media']===''||$cover['media']===[])throw new \InvalidArgumentException('Event cover requires an image source');
        if(isset($cover['mimetype'])&&(!is_string($cover['mimetype'])||!preg_match('~^image/[a-zA-Z0-9.+-]+$~D',$cover['mimetype'])))throw new \InvalidArgumentException('Event cover must be an image');
        foreach(['width','height']as$field)if(isset($cover[$field])&&(!is_int($cover[$field])||$cover[$field]<1||$cover[$field]>0xffffffff))throw new \InvalidArgumentException('Invalid cover '.$field);
        if(isset($cover['jpegThumbnail'])&&(!is_string($cover['jpegThumbnail'])||strlen($cover['jpegThumbnail'])>1048576))throw new \InvalidArgumentException('Invalid cover thumbnail');
        if(isset($cover['accessibilityLabel'])&&(!is_string($cover['accessibilityLabel'])||preg_match('//u',$cover['accessibilityLabel'])!==1))throw new \InvalidArgumentException('Invalid cover accessibilityLabel');
        $event=$content;unset($event['cover']);ContentBuilder::build($event,'1@lid');
    }
    /** @param array<string,mixed> $content
     * @return array<string,mixed> */
    public function send(string $to,array $content):array
    {
        self::validate($content);$cover=$content['cover'];$event=$content;unset($event['cover']);
        $parent=ContentBuilder::build($event,$to)['proto'];$image=$cover['prepared']??null;
        if($image===null){$prepared=($this->prepare)(['type'=>'image',...$cover]);$image=$prepared['imageMessage']??null;}
        if(!is_array($image))throw new \UnexpectedValueException('Event cover preparation did not produce an image');self::prepared($image);
        $parentId=self::id($content['messageId']??null);$parentResult=$this->publish($to,$parent,$parentId);
        $key=$parentResult['key']??['id'=>$parentId,'remoteJid'=>$parentResult['to']??$to,'fromMe'=>true];
        if(!is_array($key)||!is_string($key['remoteJid']??null)||!is_string($key['id']??null))throw new \UnexpectedValueException('Event publisher returned no parent key');
        $key=array_intersect_key($key,array_flip(['id','remoteJid','fromMe','participant']));$key['fromMe']=true;
        $result=['parent'=>$parentResult,'parentKey'=>$key,'messages'=>[],'complete'=>false,'sentCount'=>0];
        if(!self::acknowledged($parentResult)){$result['failure']=self::failure(null,$parentResult);return $result;}
        if(str_ends_with($key['remoteJid'],'@g.us')&&!is_string($key['participant']??null))throw new \UnexpectedValueException('Group event cover requires its sender participant');
        $child=['eventCoverImage'=>['message'=>['imageMessage'=>$image]],'messageContextInfo'=>['messageAssociation'=>['associationType'=>'EVENT_COVER_IMAGE','parentMessageKey'=>$key]]];
        $childResult=$this->publish($key['remoteJid'],$child,self::id());$result['messages'][]=$childResult;
        if(!self::acknowledged($childResult)){$result['failure']=self::failure(0,$childResult);return $result;}
        $result['complete']=true;$result['sentCount']=1;return $result;
    }
    /** @param array<string,mixed> $image */
    private static function prepared(array $image):void
    {
        foreach(['fileSha256','fileEncSha256','mediaKey']as$field)if(!is_string($image[$field]??null)||strlen($image[$field])!==32)throw new \InvalidArgumentException('Cover '.$field.' must contain 32 binary bytes');
        if(!is_string($image['directPath']??null)||$image['directPath']===''||!is_string($image['mimetype']??null)||!str_starts_with(strtolower($image['mimetype']),'image/')||!empty($image['viewOnce']))throw new \InvalidArgumentException('Event cover requires an uploaded, non-view-once image');
        SchemaCodec::encode('Message.ImageMessage',$image);
    }
    /** @param array<string,mixed> $proto
     * @return array<string,mixed> */
    private function publish(string $to,array $proto,string $id):array
    {
        try{$result=($this->send)($to,$proto,['id'=>$id]);}
        catch(\MVO\Service\ServiceException $error){return ['id'=>$id,'to'=>$to,'status'=>'failed']+self::serverFailure($error);}
        catch(\Throwable){return ['id'=>$id,'to'=>$to,'status'=>'unknown'];}
        if(($result['id']??null)!==$id)throw new \UnexpectedValueException('Event publisher must preserve the reserved ID');return $result;
    }
    /** An explicit server NACK is distinct from a timeout; never copy server text or credentials.
     * @return array<string,string> */
    private static function serverFailure(\MVO\Service\ServiceException $error):array
    {return is_string($error->serverCode)&&preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$error->serverCode)===1?['serverCode'=>$error->serverCode]:[];}
    /** @param array<string,mixed> $result */
    private static function acknowledged(array $result):bool{return in_array($result['status']??null,['server_ack','delivered','read','played'],true);}
    private static function id(mixed $id=null):string{if($id===null)return '3EB0'.strtoupper(bin2hex(random_bytes(9)));if(!is_string($id)||preg_match('/^[A-Za-z0-9_-]{8,128}$/D',$id)!==1)throw new \InvalidArgumentException('Invalid event message ID');return $id;}
    /** @param array<string,mixed> $result
     * @return array<string,mixed> */
    private static function failure(?int $index,array $result):array{return ['index'=>$index,'messageId'=>$result['id'],'outcome'=>$result['status']??'unknown','retryWholeEvent'=>false]+array_intersect_key($result,['serverCode'=>true]);}
}
