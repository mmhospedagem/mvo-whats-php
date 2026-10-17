<?php
declare(strict_types=1);
namespace MVO\Message;

/** A scheduled invitation does not initiate a live call. */
final class ScheduledCallContent
{
    /** Pure input validation; cancellation authority is verified against local authenticated history.
     * @param array<string,mixed> $content */
    public static function validate(array $content,?int $nowMs=null):void
    {
        $type=$content['type']??null;$options=['contextInfo','quote','forward','mentions','pushPriority','ephemeralExpiration','messageId'];
        $allowed=$type==='scheduled-call'?['type','title','scheduledTimestampMs','callType',...$options]:['type','target',...$options];
        foreach($content as$key=>$_)if(!in_array($key,$allowed,true))throw new \InvalidArgumentException('Unknown scheduled call field '.$key);
        if($type==='scheduled-call'){
            if(!is_string($content['title']??null)||trim($content['title'])===''||preg_match('//u',$content['title'])!==1||strlen($content['title'])>1024)throw new \InvalidArgumentException('Scheduled call title must contain 1 to 1024 UTF-8 bytes');
            $nowMs??=(int)floor(microtime(true)*1000);
            if(!is_int($content['scheduledTimestampMs']??null)||$content['scheduledTimestampMs']<=$nowMs)throw new \InvalidArgumentException('Scheduled call timestamp must be a future Unix millisecond integer');
            if(!in_array($content['callType']??null,['voice','video'],true))throw new \InvalidArgumentException('Scheduled call type must be voice or video');
        }elseif($type==='scheduled-call-cancel'){
            $target=$content['target']??null;if(!is_array($target)||!is_string($target['id']??null)||trim($target['id'])==='')throw new \InvalidArgumentException('Scheduled call cancellation requires its original message ID');
            foreach($target as$key=>$_)if(!in_array($key,['id','remoteJid','fromMe','participant'],true))throw new \InvalidArgumentException('Unknown scheduled call target field '.$key);
            if(isset($target['fromMe'])&&$target['fromMe']!==true)throw new \InvalidArgumentException('Only the original sender may cancel a scheduled call');
            foreach(['remoteJid','participant']as$field)if(isset($target[$field])&&(!is_string($target[$field])||trim($target[$field])===''))throw new \InvalidArgumentException('Invalid scheduled call '.$field);
        }else throw new \InvalidArgumentException('Unknown scheduled call operation');
    }
    /** @param array<string,mixed> $content
     * @param array<string,mixed> $context
     * @return array<string,mixed> */
    public static function build(array $content,string $to,array $context=[],?int $nowMs=null):array
    {
        self::validate($content,$nowMs);
        if($content['type']==='scheduled-call')return ['scheduledCallCreationMessage'=>['scheduledTimestampMs'=>$content['scheduledTimestampMs'],'callType'=>$content['callType']==='voice'?'VOICE':'VIDEO','title'=>$content['title']]];
        if(($context['scheduledCallCancellationAuthorized']??false)!==true)throw new \InvalidArgumentException('Cancellation requires the authenticated original scheduled call');
        $key=$content['target'];if(isset($key['remoteJid'])&&$key['remoteJid']!==$to)throw new \InvalidArgumentException('Scheduled call target belongs to another conversation');
        $key['remoteJid']=$to;$key['fromMe']=true;
        return ['scheduledCallEditMessage'=>['key'=>$key,'editType'=>'CANCEL']];
    }
}
