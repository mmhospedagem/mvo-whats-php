<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Protocol\SchemaCodec;

/** Current Web poll/event envelopes and authenticated modifications. */
final class AdvancedContent
{
    /**
     * Media uploads and parent lookup belong to the caller. Parent messageSecret is binary.
     * @param array<string,mixed> $content
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    public static function build(array $content,string $to,array $context=[],?int $nowMs=null):?array
    {
        $nowMs ??= $context['nowMs'] ?? (int)floor(microtime(true)*1000);
        return match($content['type'] ?? null) {
            'poll','quiz'=>self::poll($content),
            'poll-edit','poll-add-option','event-edit'=>self::modify($content,$to,$context,$nowMs),
            'event-invite'=>['eventInviteMessage'=>self::invite($content)],
            'scheduled-call','scheduled-call-cancel'=>ScheduledCallContent::build($content,$to,$context,$nowMs),
            default=>null,
        };
    }

    /** @param array<string,mixed> $content
     * @return array<string,mixed> */
    private static function poll(array $content):array
    {
        $options=$content['options'] ?? null;
        if (!is_array($options) || !array_is_list($options) || count($options)<2 || count($options)>12) throw new \InvalidArgumentException('Poll requires 2 to 12 options');
        $body=['name'=>self::text($content['name'] ?? null,'poll name'),'options'=>[],'selectableOptionsCount'=>$content['selectableCount'] ?? 1];
        $seen=[];
        foreach ($options as $option) {
            $name=self::text(is_array($option) ? ($option['name'] ?? $option['optionName'] ?? null) : $option,'poll option');
            if (in_array($name,$seen,true)) throw new \InvalidArgumentException('Poll options must be unique');
            $seen[]=$name; $entry=['optionName'=>$name];
            if (is_array($option) && isset($option['optionHash'])) $entry['optionHash']=self::text($option['optionHash'],'poll optionHash');
            $body['options'][]=$entry;
        }
        $count=$body['selectableOptionsCount'];
        if (!is_int($count) || $count<0 || $count>count($options)) throw new \InvalidArgumentException('Invalid selectable poll count');
        $pollType=$content['pollType'] ?? (($content['type'] ?? '')==='quiz' ? 'QUIZ' : 'POLL');
        $quiz=in_array($pollType,['quiz','QUIZ',1],true);
        if (!$quiz && !in_array($pollType,['poll','POLL',0],true)) throw new \InvalidArgumentException('pollType must be POLL or QUIZ');
        if ($quiz) {
            if ($count!==1) throw new \InvalidArgumentException('Quiz requires exactly one selectable option');
            $answer=$content['correctAnswer'] ?? null;
            if (is_array($answer)) $answer=$answer['name'] ?? $answer['optionName'] ?? null;
            if (!is_string($answer) || !in_array($answer,$seen,true)) throw new \InvalidArgumentException('Quiz correctAnswer must match an option name');
            $body['pollType']='QUIZ';
            $body['correctAnswer']=$body['options'][array_search($answer,$seen,true)];
        } elseif (isset($content['correctAnswer'])) throw new \InvalidArgumentException('correctAnswer requires pollType QUIZ');
        elseif (isset($content['pollType'])) $body['pollType']='POLL';
        if (isset($content['pollContentType'])) {
            $ct=$content['pollContentType'];
            $body['pollContentType']=match($ct) {'text','TEXT',1=>'TEXT','image','IMAGE',2=>'IMAGE',default=>throw new \InvalidArgumentException('pollContentType must be TEXT or IMAGE')};
            if ($body['pollContentType']==='IMAGE') foreach ($body['options'] as $option) if (!isset($option['optionHash'])) throw new \InvalidArgumentException('Image poll options require optionHash and associated option media');
        }
        if (isset($content['endTime'])) $body['endTime']=self::integer($content['endTime'],'poll endTime');
        foreach (['hideParticipantName','allowAddOption'] as $flag) {
            if (isset($content[$flag]) && !is_bool($content[$flag])) throw new \InvalidArgumentException($flag.' must be boolean');
            if (($content[$flag] ?? false)===true) $body[$flag]=true;
        }
        $field=isset($body['endTime']) || isset($body['hideParticipantName']) || isset($body['allowAddOption']) ? 'pollCreationMessageV6' : ($count===1 || ($body['pollContentType'] ?? null)==='IMAGE' ? 'pollCreationMessageV3' : 'pollCreationMessage');
        return [$field=>$body];
    }

    /** @param array<string,mixed> $content
     * @param array<string,mixed> $context
     * @return array<string,mixed> */
    private static function modify(array $content,string $to,array $context,int $nowMs):array
    {
        $kind=$content['type']; $isEvent=$kind==='event-edit';
        $parent=$content[$isEvent ? 'event' : 'poll'] ?? null;
        if (!is_array($parent)) throw new \InvalidArgumentException('Modification requires the authenticated parent message');
        $sender=ContentBuilder::bare(self::text($context['meJid'] ?? null,'addon sender meJid'));
        $author=ContentBuilder::bare(self::text($parent['authorJid'] ?? null,'parent authorJid'));
        $parentId=self::text($parent['id'] ?? $parent['key']['id'] ?? null,'parent id');
        $secret=$parent['messageSecret'] ?? null;
        if (!is_string($secret) || strlen($secret)!==32) throw new \InvalidArgumentException('Parent messageSecret must be 32 bytes');
        $key=self::key($parent,$to);
        $original=self::original($parent,$isEvent);
        $newSecret=$context['messageSecret'] ?? random_bytes(32);
        if (!is_string($newSecret) || strlen($newSecret)!==32) throw new \InvalidArgumentException('Message secret must be 32 bytes');
        if ($kind==='poll-add-option') {
            if (($original['allowAddOption'] ?? false)!==true) throw new \InvalidArgumentException('The original poll does not allow adding options');
            $option=self::text($content['optionName'] ?? $content['option']['name'] ?? $content['option']['optionName'] ?? null,'added option');
            foreach ($original['options'] ?? [] as $entry) if (($entry['optionName'] ?? null)===$option) throw new \InvalidArgumentException('Poll option already exists');
            $payload=['pollCreationMessageKey'=>$key,'addOption'=>['optionName'=>$option]];
            $metadata=self::pollMetadata($parent,$original);
            if ($metadata!==[]) $payload['metadata']=$metadata;
            $plain=['pollAddOptionMessage'=>$payload,'messageContextInfo'=>['messageSecret'=>$newSecret]];
            $type='Poll Add Option'; $encType='POLL_ADD_OPTION';
        } else {
            $mine=[$sender];
            if (is_string($context['meLid'] ?? null)) $mine[]=ContentBuilder::bare($context['meLid']);
            if (($parent['fromMe'] ?? $parent['key']['fromMe'] ?? true)!==true || !in_array($author,$mine,true)) throw new \InvalidArgumentException('Only the original author can edit this message');
            if ($original===[]) throw new \InvalidArgumentException('Editing requires the original message fields');
            if ($isEvent) {
                $edited=['eventMessage'=>self::event(array_replace($original,self::pick($content,['name','description','startTime','endTime','joinLink','extraGuestsAllowed','isScheduleCall','isCanceled','hasReminder','reminderOffsetSec','location'])))];
                $type='Event Edit'; $encType='EVENT_EDIT';
            } else {
                foreach (['options','correctAnswer','pollType','pollContentType','selectableCount','endTime','hideParticipantName','allowAddOption'] as $immutable) if (array_key_exists($immutable,$content)) throw new \InvalidArgumentException('Poll edits support only the name; use poll-add-option to add an option');
                $poll=['name'=>self::text($content['name'] ?? null,'poll name')];
                $poll+=self::pick($original,['endTime','hideParticipantName','allowAddOption']);
                $field=isset($poll['endTime']) || ($poll['hideParticipantName'] ?? false) || ($poll['allowAddOption'] ?? false) ? 'pollCreationMessageV6' : 'pollCreationMessageV3';
                $edited=[$field=>$poll]; $type='Poll Edit'; $encType='POLL_EDIT';
            }
            $plain=['protocolMessage'=>['key'=>$key,'type'=>'MESSAGE_EDIT','editedMessage'=>$edited,'timestampMs'=>self::integer($content['timestampMs'] ?? $nowMs,'edit timestampMs')]];
        }
        $encrypted=AddonCrypto::encrypt($secret,$parentId,$author,$sender,$type,SchemaCodec::encode('Message',$plain));
        $result=['secretEncryptedMessage'=>['targetMessageKey'=>$key,'secretEncType'=>$encType]+$encrypted,'messageContextInfo'=>['messageSecret'=>$newSecret]];
        if ($kind==='poll-add-option') $result['messageContextInfo']['messageAssociation']=['associationType'=>'POLL_ADD_OPTION','parentMessageKey'=>$key];
        return $result;
    }

    /** @param array<string,mixed> $parent
     * @return array<string,mixed> */
    private static function original(array $parent,bool $event):array
    {
        $explicit=$parent[$event ? 'eventCreation' : 'pollCreation'] ?? null;
        if (is_array($explicit)) return $explicit;
        $message=$parent['message'] ?? $parent['proto'] ?? null;
        if (!is_array($message)) return [];
        $message=ContentDecoder::unwrap($message)['message'];
        foreach ($message as $field=>$value) if (is_array($value) && ($event ? $field==='eventMessage' : str_starts_with($field,'pollCreationMessage'))) return $value;
        return [];
    }

    /** @param array<string,mixed> $parent
     * @param array<string,mixed> $original
     * @return array<string,mixed> */
    private static function pollMetadata(array $parent,array $original):array
    {
        $metadata=[];
        // Do not derive an undocumented metadata hash from the human-readable title.
        if (isset($parent['pollNameHash'])) {
            if (!is_string($parent['pollNameHash']) || strlen($parent['pollNameHash'])!==32) throw new \InvalidArgumentException('pollNameHash must be 32 binary bytes');
            $metadata['pollNameHash']=$parent['pollNameHash'];
        }
        if (isset($parent['lastEditStanzaId'])) $metadata['lastEditStanzaId']=self::text($parent['lastEditStanzaId'],'lastEditStanzaId');
        return $metadata;
    }

    /** @param array<string,mixed> $body
     * @return array<string,mixed> */
    private static function event(array $body):array
    {
        $body=self::pick($body,['name','description','startTime','endTime','joinLink','extraGuestsAllowed','isScheduleCall','isCanceled','hasReminder','reminderOffsetSec','location','contextInfo']);
        $body['name']=self::text($body['name'] ?? null,'event name');
        $body['startTime']=self::integer($body['startTime'] ?? null,'event startTime');
        if (isset($body['endTime']) && self::integer($body['endTime'],'event endTime')<$body['startTime']) throw new \InvalidArgumentException('Event endTime precedes startTime');
        foreach (['extraGuestsAllowed','isScheduleCall','isCanceled','hasReminder'] as $flag) if (isset($body[$flag]) && !is_bool($body[$flag])) throw new \InvalidArgumentException($flag.' must be boolean');
        if (isset($body['reminderOffsetSec'])) self::integer($body['reminderOffsetSec'],'reminderOffsetSec');
        if (isset($body['location'])) {
            if (!is_array($body['location'])) throw new \InvalidArgumentException('Event location must be an object');
            $lat=$body['location']['latitude'] ?? $body['location']['degreesLatitude'] ?? null;
            $lon=$body['location']['longitude'] ?? $body['location']['degreesLongitude'] ?? null;
            if ((!is_float($lat) && !is_int($lat)) || (!is_float($lon) && !is_int($lon)) || !is_finite((float)$lat) || !is_finite((float)$lon) || $lat< -90 || $lat>90 || $lon< -180 || $lon>180) throw new \InvalidArgumentException('Invalid event location');
            $body['location']=['degreesLatitude'=>$lat,'degreesLongitude'=>$lon]+self::pick($body['location'],['name','address','url','jpegThumbnail']);
        }
        return $body;
    }

    /** @param array<string,mixed> $content
     * @return array<string,mixed> */
    private static function invite(array $content):array
    {
        $body=self::pick($content,['eventId','eventTitle','jpegThumbnail','startTime','caption','isCanceled','endTime','callLink','coverImageHandle','locationName','lastUpdatedTsUsec','status']);
        $body['eventId']=self::text($content['eventId'] ?? null,'eventId');
        $body['eventTitle']=self::text($content['eventTitle'] ?? $content['name'] ?? null,'eventTitle');
        $body['startTime']=self::integer($content['startTime'] ?? null,'event startTime');
        if (isset($body['endTime']) && self::integer($body['endTime'],'event endTime')<$body['startTime']) throw new \InvalidArgumentException('Event endTime precedes startTime');
        if (isset($body['lastUpdatedTsUsec'])) self::integer($body['lastUpdatedTsUsec'],'lastUpdatedTsUsec');
        if (isset($body['isCanceled']) && !is_bool($body['isCanceled'])) throw new \InvalidArgumentException('isCanceled must be boolean');
        if (isset($body['jpegThumbnail']) && (!is_string($body['jpegThumbnail']) || strlen($body['jpegThumbnail'])>1048576)) throw new \InvalidArgumentException('Event jpegThumbnail must be at most 1 MiB');
        if (isset($body['status']) && !in_array($body['status'],[0,1,2,3,4,5,6,'UNKNOWN','ACTIVE','CANCELED','SUSPENDED','DELETED','LEFT_EVENT','REMOVED_FROM_INVITE_LIST'],true)) throw new \InvalidArgumentException('Invalid event invite status');
        foreach (['caption','callLink','coverImageHandle','locationName'] as $field) if (isset($body[$field]) && !is_string($body[$field])) throw new \InvalidArgumentException($field.' must be a string');
        return $body;
    }

    /** @param array<string,mixed> $parent
     * @return array<string,mixed> */
    private static function key(array $parent,string $to):array
    {
        $target=$parent['key'] ?? $parent;
        if (!is_array($target)) throw new \InvalidArgumentException('Parent key must be an object');
        $key=['remoteJid'=>$to,'id'=>self::text($target['id'] ?? null,'parent id'),'fromMe'=>$target['fromMe'] ?? false];
        if (!is_bool($key['fromMe'])) throw new \InvalidArgumentException('Parent fromMe must be boolean');
        if (str_ends_with($to,'@g.us')) $key['participant']=ContentBuilder::bare(self::text($target['participant'] ?? $parent['authorJid'] ?? null,'parent participant'));
        return $key;
    }

    private static function text(mixed $value,string $label):string
    {
        if (!is_string($value) || trim($value)==='' || preg_match('//u',$value)!==1) throw new \InvalidArgumentException($label.' must be non-empty UTF-8 text');
        return $value;
    }
    private static function integer(mixed $value,string $label):int
    {
        if (!is_int($value) || $value<0) throw new \InvalidArgumentException($label.' must be a non-negative integer');
        return $value;
    }
    /** @param array<string,mixed> $body
     * @param list<string> $fields
     * @return array<string,mixed> */
    private static function pick(array $body,array $fields):array {return array_intersect_key($body,array_flip($fields));}
}
