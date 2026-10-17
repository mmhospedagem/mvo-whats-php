<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Protocol\SchemaCodec;

/** Uploads every child before publishing a parent and ordered media association. */
final class AlbumSender
{
    private readonly \Closure $send;
    private readonly \Closure $prepare;

    /** @param callable(string,array<string,mixed>,array<string,mixed>):array<string,mixed> $send Sends a raw Message and returns its real key/id.
     * @param callable(array<string,mixed>):array<string,mixed> $prepare Prepares one media content object into a raw Message. */
    public function __construct(callable $send,callable $prepare)
    {
        $this->send=\Closure::fromCallable($send); $this->prepare=\Closure::fromCallable($prepare);
    }

    /** A published parent cannot be rolled back. A failed child stops further sends and
     * returns complete=false and the acknowledged IDs; never retry the whole album blindly.
     * @param list<array<string,mixed>> $items Typed image/video items or raw prepared Messages.
     * @param array<string,mixed> $options Message options; participant identifies the sender in groups.
     * @return array<string,mixed> */
    public function send(string $to,array $items,array $options=[]):array
    {
        if (trim($to)==='') throw new \InvalidArgumentException('Album recipient is required');
        if (!array_is_list($items) || count($items)<2 || count($items)>100) throw new \InvalidArgumentException('Album requires 2 to 100 images/videos');
        if (isset($options['caption']) && !is_string($options['caption'])) throw new \InvalidArgumentException('Album caption must be text');
        $prepared=[]; $images=0; $videos=0;
        // Validate every input before uploading any item.
        foreach ($items as $index=>$input) {
            $item=self::item($input);
            if ($index===0 && isset($options['caption'])) {
                $field=isset($item['imageMessage']) ? 'imageMessage' : (isset($item['videoMessage']) ? 'videoMessage' : null);
                if ($field!==null && !array_key_exists('caption',$item[$field])) $item[$field]['caption']=$options['caption'];
                elseif ($field===null && !array_key_exists('caption',$item)) $item['caption']=$options['caption'];
                $items[$index]=$item;
            }
            if (isset($item['imageMessage']) || isset($item['videoMessage'])) $proto=$item;
            elseif (in_array($item['type'] ?? null,['image','video'],true)) $proto=null;
            else throw new \InvalidArgumentException('Albums support only images and videos');
            if (($item['viewOnce'] ?? false) || ($item['imageMessage']['viewOnce'] ?? false) || ($item['videoMessage']['viewOnce'] ?? false)) throw new \InvalidArgumentException('Album children cannot be view-once');
            $prepared[]=$proto;
        }
        foreach ($items as $index=>$item) {
            $proto=$prepared[$index] ?? ($this->prepare)($item);
            $image=isset($proto['imageMessage']); $video=isset($proto['videoMessage']);
            if ($image===$video || !is_array($proto[$image ? 'imageMessage' : 'videoMessage'])) throw new \UnexpectedValueException('Prepared album item must contain exactly one image or video');
            if (($proto[$image ? 'imageMessage' : 'videoMessage']['viewOnce'] ?? false)===true) throw new \InvalidArgumentException('Album children cannot be view-once');
            if (isset($proto['messageContextInfo']['messageAssociation'])) throw new \InvalidArgumentException('Album child already has a message association');
            foreach (array_keys($proto) as $field) if (!in_array($field,[$image ? 'imageMessage' : 'videoMessage','messageContextInfo'],true)) throw new \InvalidArgumentException('Album item has unrelated message content');
            SchemaCodec::encode('Message',$proto);
            $prepared[$index]=$proto;
            if ($image) ++$images; else ++$videos;
        }
        $parent=['albumMessage'=>['expectedImageCount'=>$images,'expectedVideoCount'=>$videos]];
        $parentResult=($this->send)($to,$parent,$options);
        $key=self::parentKey($to,$parentResult,$options);
        $result=['parent'=>$parentResult,'parentKey'=>$key,'messages'=>[],'expectedImageCount'=>$images,'expectedVideoCount'=>$videos,'complete'=>true];
        if (!self::acknowledged($parentResult)) {
            $result['complete']=false; $result['sentCount']=0;
            $result['failure']=['index'=>null,'message'=>'Album parent was not acknowledged','outcome'=>$parentResult['status'] ?? 'unknown','messageId'=>$key['id'],'retryWholeAlbum'=>false];
            return $result;
        }
        $acknowledged=0;
        foreach ($prepared as $index=>$proto) {
            $proto['messageContextInfo']['messageAssociation']=['associationType'=>'MEDIA_ALBUM','parentMessageKey'=>$key,'messageIndex'=>$index];
            try {
                $child=($this->send)($to,$proto,$options);
                $result['messages'][]=$child;
                if (!self::acknowledged($child)) {
                    $result['complete']=false;
                    $result['failure']=['index'=>$index,'message'=>'Album child was not acknowledged','outcome'=>$child['status'] ?? 'unknown','messageId'=>$child['id'] ?? $child['messageId'] ?? $child['key']['id'] ?? null,'retryWholeAlbum'=>false];
                    break;
                }
                ++$acknowledged;
            }
            catch (\Throwable $error) {
                $result['complete']=false;
                $result['failure']=['index'=>$index,'message'=>$error->getMessage(),'outcome'=>'unknown','retryWholeAlbum'=>false];
                break;
            }
        }
        $result['sentCount']=$acknowledged;
        return $result;
    }

    /** @return array<string,mixed> */
    private static function item(mixed $item):array
    {
        if (!is_array($item)) throw new \InvalidArgumentException('Album items must be objects');
        return $item;
    }

    /** @param array<string,mixed> $result */
    private static function acknowledged(array $result):bool
    {
        return in_array($result['status'] ?? null,['server_ack','delivered','read','played'],true);
    }

    /** @param array<string,mixed> $result
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    private static function parentKey(string $to,array $result,array $options):array
    {
        $key=$result['key'] ?? ['remoteJid'=>$result['to'] ?? $to,'id'=>$result['id'] ?? $result['messageId'] ?? null,'fromMe'=>true];
        if (!is_array($key) || !is_string($key['id'] ?? null) || $key['id']==='' || !is_string($key['remoteJid'] ?? null)) throw new \UnexpectedValueException('Album parent response has no acknowledged message key');
        $key=array_intersect_key($key,array_flip(['remoteJid','id','fromMe','participant']));
        $key['fromMe']=true;
        if (str_ends_with($key['remoteJid'],'@g.us')) {
            $participant=$key['participant'] ?? $options['participant'] ?? null;
            if (!is_string($participant) || trim($participant)==='') throw new \UnexpectedValueException('Album group parent requires its original sender participant');
            $key['participant']=ContentBuilder::bare($participant);
        }
        return $key;
    }
}
