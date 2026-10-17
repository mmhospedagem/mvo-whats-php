<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Protocol\Jid;
use MVO\Service\StatusService;

/** Group Status V2 uses the group's real encrypted transport, never a contact broadcast. */
final class GroupStatusContent
{
    /** @param string|list<string> $groups
     * @param string|array<string,mixed> $content
     * @param array<string,mixed> $options */
    public static function validate(string|array $groups,string|array $content,array $options=[]):void
    {
        self::normalizeGroups($groups);self::normalizeContent($content);self::normalizeOptions($options);
    }
    /** @param string|list<string> $groups
     * @return list<string> */
    public static function normalizeGroups(string|array $groups):array
    {
        if(is_string($groups))$groups=[$groups];
        if(!array_is_list($groups)||$groups===[]||count($groups)>50)throw new \InvalidArgumentException('Group Status requires 1 to 50 explicit groups');
        $out=[];
        foreach($groups as$group){
            if(!is_string($group)||preg_match('/^[0-9]+(?:-[0-9]+)?@g\.us$/D',$group)!==1)throw new \InvalidArgumentException('Group Status destination must be a group JID');
            $jid=Jid::parse($group)->canonical();if($jid->server!=='g.us'||$jid->device!==0)throw new \InvalidArgumentException('Invalid Group Status group');$out[]=$jid->bare();
        }
        return array_values(array_unique($out));
    }
    /** @param string|array<string,mixed> $content
     * @return array<string,mixed> */
    public static function normalizeContent(string|array $content):array
    {
        if(is_string($content))$content=['type'=>'text','text'=>$content];
        $type=$content['type']??'text';
        if(!in_array($type,['text','image','video','audio','voice','ptt'],true))throw new \InvalidArgumentException('Group Status supports text, image, video, audio and voice');
        $allowed=match($type){'text'=>['type','text','font','backgroundColor','textColor'],'image'=>['type','media','caption','mimetype','width','height','jpegThumbnail','accessibilityLabel'],'video'=>['type','media','caption','mimetype','width','height','seconds','jpegThumbnail','accessibilityLabel','gifPlayback'],default=>['type','media','mimetype','seconds','waveform','backgroundColor','backgroundArgb','accessibilityLabel','normalizeVoice']};
        foreach(array_keys($content)as$field)if(!in_array($field,$allowed,true))throw new \InvalidArgumentException('Unknown Group Status content field: '.$field);
        // Reuse existing status validation and media preparation without exposing raw protocol fields.
        if($type==='audio'){$content['type']='voice';$prepared=StatusService::prepareContent($content);$prepared['type']='audio';return $prepared;}
        return StatusService::prepareContent($content);
    }
    /** @param array<string,mixed> $options
     * @return array<string,string> */
    public static function normalizeOptions(array $options):array
    {
        foreach($options as$field=>$value){
            if(!in_array($field,['listName','listEmoji'],true))throw new \InvalidArgumentException('Unknown Group Status option: '.$field);
            if(!is_string($value)||trim($value)===''||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1f\x7f]/',$value)||strlen($value)>($field==='listName'?512:64))throw new \InvalidArgumentException('Invalid Group Status '.$field);
        }
        return $options;
    }
    /** @param array<string,mixed> $message
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public static function wrap(array $message,array $options=[]):array
    {
        $options=self::normalizeOptions($options);
        $context=$message['messageContextInfo']??null;unset($message['messageContextInfo']);
        $fields=array_keys($message);
        if(count($fields)!==1||!in_array($fields[0],['extendedTextMessage','imageMessage','videoMessage','audioMessage','protocolMessage'],true)||!is_array($message[$fields[0]]))throw new \InvalidArgumentException('Unsupported Group Status body');
        $field=$fields[0];
        if($field!=='protocolMessage'){
            $body=$message[$field];$info=is_array($body['contextInfo']??null)?$body['contextInfo']:[];
            $info['isGroupStatus']=true;
            if($options!==[])$info['statusAudienceMetadata']=['audienceType'=>'CLOSE_FRIENDS',...$options];
            $body['contextInfo']=$info;$message[$field]=$body;
        }elseif(!in_array($message[$field]['type']??null,[0,'REVOKE'],true))throw new \InvalidArgumentException('Group Status protocol must revoke an authorized publication');
        $wrapped=['groupStatusMessageV2'=>['message'=>$message]];
        if($context!==null){if(!is_array($context))throw new \InvalidArgumentException('Invalid Group Status message context');$wrapped['messageContextInfo']=$context;}
        return $wrapped;
    }
}
