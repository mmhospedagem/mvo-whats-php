<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Media\MediaSource;
use MVO\Protocol\SchemaCodec;

/** Bounded authoring contract. Cards are prepared together before the single message write. */
final class CarouselContent
{
    /**
     * @param array<string,mixed> $content
     * @return array<string,mixed> */
    public static function normalize(array $content):array
    {
        self::keys($content,['type','cards','text','footer','messageId','quote','spoiler','contextInfo','mentions'],'carousel');
        foreach(['text','footer']as$field)if(isset($content[$field]))self::text($content[$field],$field,4096);
        $cards=$content['cards']??null;
        if(!is_array($cards)||!array_is_list($cards)||count($cards)<2||count($cards)>10)throw new \InvalidArgumentException('Carousel requires 2 to 10 cards');
        foreach($cards as&$card){
            if(!is_array($card)||array_is_list($card))throw new \InvalidArgumentException('Carousel card must be an object');
            self::keys($card,['type','media','prepared','title','subtitle','caption','footer','buttons','mimetype','width','height','seconds','jpegThumbnail','accessibilityLabel'],'card');
            if(!in_array($card['type']??null,['image','video'],true))throw new \InvalidArgumentException('Carousel card requires image or video');
            if(isset($card['prepared'])&&isset($card['media']))throw new \InvalidArgumentException('Carousel card cannot contain both prepared media and a source');
            if(!isset($card['prepared'])&&!isset($card['media']))throw new \InvalidArgumentException('Carousel card requires media');
            foreach(['title'=>256,'subtitle'=>256,'caption'=>4096,'footer'=>1024,'accessibilityLabel'=>1024]as$field=>$limit)if(isset($card[$field]))self::text($card[$field],$field,$limit);
            foreach(['width','height','seconds']as$field)if(isset($card[$field])&&(!is_int($card[$field])||$card[$field]<0||$card[$field]>0xffffffff))throw new \InvalidArgumentException('Invalid card '.$field);
            if(isset($card['mimetype'])&&(!is_string($card['mimetype'])||preg_match('~^'.$card['type'].'/[A-Za-z0-9.+-]+$~D',$card['mimetype'])!==1))throw new \InvalidArgumentException('Card mimetype does not match media type');
            if(isset($card['jpegThumbnail'])&&(!is_string($card['jpegThumbnail'])||strlen($card['jpegThumbnail'])>1048576))throw new \InvalidArgumentException('Invalid card thumbnail');
            $buttons=$card['buttons']??null;
            if(!is_array($buttons)||!array_is_list($buttons)||count($buttons)<1||count($buttons)>3)throw new \InvalidArgumentException('Each card requires 1 to 3 buttons');
            $ids=[];
            foreach($buttons as$button){
                if(!is_array($button)||array_is_list($button))throw new \InvalidArgumentException('Card button must be an object');
                self::keys($button,['type','label','id','url','phoneNumber','copyCode'],'button');
                self::text($button['label']??null,'button label',80,true);
                $required=match($button['type']??null){'url'=>'url','reply'=>'id','call'=>'phoneNumber','copy'=>'copyCode',default=>throw new \InvalidArgumentException('Unsupported carousel button type')};
                self::text($button[$required]??null,$required,$required==='url'?2048:256,true);
                foreach(['id','url','phoneNumber','copyCode']as$field)if($field!==$required&&array_key_exists($field,$button))throw new \InvalidArgumentException('Button fields do not match its type');
                if($required==='url')self::url($button['url']);
                if($required==='phoneNumber'&&preg_match('/^\+?[1-9][0-9]{6,14}$/D',$button['phoneNumber'])!==1)throw new \InvalidArgumentException('Invalid call button phoneNumber');
                if($required==='id'){if(isset($ids[$button['id']]))throw new \InvalidArgumentException('Duplicate card reply ID');$ids[$button['id']]=true;}
            }
        }unset($card);
        return $content;
    }

    /** No upload is attempted until every local source has been read within the aggregate limit.
     *
     * @param array<string,mixed> $content
     * @param callable(array<string,mixed>):array<string,mixed> $upload
     * @return array<string,mixed> */
    public static function prepare(array $content,callable $upload,int $maxBytes=33554432):array
    {
        $content=self::normalize($content);$remaining=$maxBytes;
        foreach($content['cards']as&$card){
            if(isset($card['prepared']))continue;
            $bytes=MediaSource::read($card['media'],$remaining);
            if($bytes==='')throw new \InvalidArgumentException('Carousel media cannot be empty');
            $remaining-=strlen($bytes);$card['media']=['bytes'=>$bytes];
        }unset($card);
        foreach($content['cards']as&$card){
            if(isset($card['prepared']))continue;
            $media=array_intersect_key($card,array_flip(['type','media','mimetype','width','height','seconds','jpegThumbnail','accessibilityLabel']));
            $uploaded=$upload($media);$field=$card['type'].'Message';
            if(!is_array($uploaded[$field]??null))throw new \UnexpectedValueException('Carousel upload did not return the requested media type');
            $card['prepared']=$uploaded[$field];unset($card['media']);
        }unset($card);
        self::build($content);
        return $content;
    }

    /**
     * @param array<string,mixed> $content
     * @return array<string,mixed> */
    public static function build(array $content):array
    {
        $content=self::normalize($content);$cards=[];
        foreach($content['cards']as$card){
            if(!is_array($card['prepared']??null))throw new \InvalidArgumentException('Carousel media requires authenticated preparation');
            $header=['hasMediaAttachment'=>true,$card['type'].'Message'=>$card['prepared']];
            foreach(['title','subtitle']as$field)if(isset($card[$field]))$header[$field]=$card[$field];
            $buttons=[];
            foreach($card['buttons']as$button){
                [$name,$params]=match($button['type']){
                    'url'=>['cta_url',['display_text'=>$button['label'],'url'=>$button['url'],'merchant_url'=>$button['url']]],
                    'reply'=>['quick_reply',['display_text'=>$button['label'],'id'=>$button['id']]],
                    'call'=>['cta_call',['display_text'=>$button['label'],'phone_number'=>$button['phoneNumber']]],
                    'copy'=>['cta_copy',['display_text'=>$button['label'],'copy_code'=>$button['copyCode']]],
                    default=>throw new \InvalidArgumentException('Unsupported carousel button type'),
                };
                $buttons[]=['name'=>$name,'buttonParamsJson'=>json_encode($params,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
            }
            $body=['header'=>$header,'nativeFlowMessage'=>['buttons'=>$buttons]];
            if(isset($card['caption']))$body['body']=['text'=>$card['caption']];
            if(isset($card['footer']))$body['footer']=['text'=>$card['footer']];
            $cards[]=$body;
        }
        $interactive=['carouselMessage'=>['cards'=>$cards,'messageVersion'=>1,'carouselCardType'=>'HSCROLL_CARDS']];
        if(isset($content['text']))$interactive['body']=['text'=>$content['text']];
        if(isset($content['footer']))$interactive['footer']=['text'=>$content['footer']];
        $message=['interactiveMessage'=>$interactive];SchemaCodec::encode('Message',$message);return $message;
    }

    /**
     * @param array<string,mixed> $object
     * @param list<string> $allowed */
    private static function keys(array $object,array $allowed,string $label):void{foreach($object as$key=>$_)if(!in_array($key,$allowed,true))throw new \InvalidArgumentException('Unknown '.$label.' field '.$key);}
    private static function text(mixed $value,string $label,int $max,bool $required=false):void{if(!is_string($value)||!preg_match('//u',$value)||strlen($value)>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)||($required&&trim($value)===''))throw new \InvalidArgumentException('Invalid '.$label);}
    public static function url(string $url):void{$p=parse_url($url);if($p===false||($p['scheme']??null)!=='https'||!isset($p['host'])||isset($p['user'])||isset($p['pass'])||preg_match('/[\x00-\x20\x7f]/',$url))throw new \InvalidArgumentException('Button URL must be HTTPS without credentials or whitespace');}
}
