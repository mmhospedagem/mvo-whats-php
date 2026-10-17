<?php
declare(strict_types=1);
namespace MVO\Message;

/** JSON-friendly projections, without replacing the authenticated original fields. */
final class AdvancedEvents
{
    /** @param array<string,mixed> $message Unwrapped Message; preserve MessageContextInfo.
     * @param array<string,mixed> $data Plaintext content or an authenticated decrypted addon.
     * @param list<string> $wrappers
     * @return array<string,mixed> */
    public static function normalize(array $message,string $kind,array $data,array $wrappers=[]):array
    {
        $extra=[];
        if(isset($message['scheduledCallCreationMessage'])){
            $call=$message['scheduledCallCreationMessage'];$extra['kind']='scheduled-call';
            $extra['scheduledCall']=['action'=>'invite','title'=>$call['title']??null,'scheduledTimestampMs'=>$call['scheduledTimestampMs']??null,'callType'=>match($call['callType']??null){1,'VOICE'=>'voice',2,'VIDEO'=>'video',default=>'unknown'}];
        }elseif(isset($message['scheduledCallEditMessage'])){
            $call=$message['scheduledCallEditMessage'];$extra['kind']='scheduled-call-cancel';$extra['target']=$call['key']??null;
            $extra['scheduledCall']=['action'=>in_array($call['editType']??null,[1,'CANCEL'],true)?'cancel':'unknown','target'=>$call['key']??null];
        }
        if ($kind==='poll' || $kind==='quiz') {
            $type=in_array($data['pollType'] ?? 0,[1,'QUIZ'],true) ? 'quiz' : 'poll';
            $options=[];
            foreach ($data['options'] ?? [] as $index=>$option) {
                if (!is_array($option)) continue;
                $entry=['index'=>$index,'name'=>$option['optionName'] ?? null];
                if (isset($option['optionHash'])) $entry['hash']=$option['optionHash'];
                $options[]=$entry;
            }
            $extra['poll']=['name'=>$data['name'] ?? null,'type'=>$type,'contentType'=>in_array($data['pollContentType'] ?? 1,[2,'IMAGE'],true) ? 'image' : 'text','options'=>$options,'selectableCount'=>$data['selectableOptionsCount'] ?? null,'correctAnswer'=>$data['correctAnswer']['optionName'] ?? null,'endTime'=>$data['endTime'] ?? null,'hideParticipantName'=>($data['hideParticipantName'] ?? false)===true,'allowAddOption'=>($data['allowAddOption'] ?? false)===true];
        } elseif ($kind==='poll-vote' && isset($data['selectedOptions']) && is_array($data['selectedOptions'])) {
            $hashes=[];
            foreach ($data['selectedOptions'] as $hash) if (is_string($hash)) $hashes[]=bin2hex($hash);
            $extra['poll']=['action'=>'vote','selectedOptionHashes'=>$hashes,'withdrawn'=>$hashes===[]];
        } elseif ($kind==='poll-add-option') {
            $body=$data['pollAddOptionMessage'] ?? $data;
            $extra['poll']=['action'=>'add-option','option'=>['name'=>$body['addOption']['optionName'] ?? null],'target'=>$body['pollCreationMessageKey'] ?? null];
        } elseif ($kind==='poll-edit') {
            $body=$data['protocolMessage']['editedMessage'] ?? $data;
            foreach (['pollCreationMessageV6','pollCreationMessageV5','pollCreationMessageV3','pollCreationMessageV2','pollCreationMessage'] as $field) if (isset($body[$field])) {
                $extra['poll']=['action'=>'edit','name'=>$body[$field]['name'] ?? null,'target'=>$data['protocolMessage']['key'] ?? null]; break;
            }
        } elseif ($kind==='event' || $kind==='event-edit') {
            $body=$kind==='event-edit' ? ($data['protocolMessage']['editedMessage']['eventMessage'] ?? $data['eventMessage'] ?? $data) : $data;
            $extra['event']=['name'=>$body['name'] ?? null,'description'=>$body['description'] ?? null,'startTime'=>$body['startTime'] ?? null,'endTime'=>$body['endTime'] ?? null,'location'=>$body['location'] ?? null,'joinLink'=>$body['joinLink'] ?? null,'isCanceled'=>($body['isCanceled'] ?? false)===true,'extraGuestsAllowed'=>($body['extraGuestsAllowed'] ?? false)===true,'isScheduleCall'=>($body['isScheduleCall'] ?? false)===true,'hasReminder'=>($body['hasReminder'] ?? false)===true,'reminderOffsetSec'=>$body['reminderOffsetSec'] ?? null];
            if ($kind==='event-edit') $extra['event']+=['action'=>'edit','target'=>$data['protocolMessage']['key'] ?? null];
        } elseif ($kind==='event-response' && isset($data['response'])) {
            $response=match($data['response']) {1,'GOING'=>'going',2,'NOT_GOING'=>'not_going',3,'MAYBE'=>'maybe',default=>'unknown'};
            $extra['event']=['action'=>'response','response'=>$response,'timestampMs'=>$data['timestampMs'] ?? null,'extraGuestCount'=>$data['extraGuestCount'] ?? 0];
        } elseif ($kind==='event-invite') {
            $statuses=[0=>'unknown',1=>'active',2=>'canceled',3=>'suspended',4=>'deleted',5=>'left_event',6=>'removed_from_invite_list'];
            $status=$data['status'] ?? 0;
            $extra['event']=['action'=>'invite','id'=>$data['eventId'] ?? null,'title'=>$data['eventTitle'] ?? null,'startTime'=>$data['startTime'] ?? null,'endTime'=>$data['endTime'] ?? null,'caption'=>$data['caption'] ?? null,'callLink'=>$data['callLink'] ?? null,'locationName'=>$data['locationName'] ?? null,'coverImageHandle'=>$data['coverImageHandle'] ?? null,'hasThumbnail'=>isset($data['jpegThumbnail']) && $data['jpegThumbnail']!=='','isCanceled'=>($data['isCanceled'] ?? false)===true,'status'=>is_int($status) ? ($statuses[$status] ?? 'unknown') : strtolower((string)$status)];
        } elseif ($kind==='album') {
            $images=$data['expectedImageCount'] ?? 0; $videos=$data['expectedVideoCount'] ?? 0;
            $extra['album']=['expectedImageCount'=>$images,'expectedVideoCount'=>$videos,'expectedCount'=>(int)$images+(int)$videos];
        }
        $association=$message['messageContextInfo']['messageAssociation'] ?? null;
        if (is_array($association)) {
            $value=$association['associationType'] ?? 0;
            $type=match($value) {1,'MEDIA_ALBUM'=>'media_album',3,'EVENT_COVER_IMAGE'=>'event_cover_image',7,'MEDIA_POLL'=>'media_poll',20,'POLL_ADD_OPTION'=>'poll_add_option',default=>is_string($value) ? strtolower($value) : 'unknown'};
            $extra['association']=['type'=>$type,'typeCode'=>$value,'parent'=>$association['parentMessageKey'] ?? null,'index'=>$association['messageIndex'] ?? null];
            if ($type==='media_album') $extra['album']=array_replace($extra['album'] ?? [],['parent'=>$association['parentMessageKey'] ?? null,'index'=>$association['messageIndex'] ?? null]);
            if($type==='event_cover_image')$extra['eventCover']=['parent'=>$association['parentMessageKey']??null,'hasImage'=>isset($message['imageMessage'])];
            if($type==='media_poll'){$hash=$message['imageMessage']['fileSha256']??null;$extra['pollMedia']=['parent'=>$association['parentMessageKey']??null,'imageSha256'=>is_string($hash)?bin2hex($hash):null];}
        }
        foreach(['pollCreationMessageV4'=>'poll','statusAddYours'=>'add-yours','groupStatusMessage'=>'group-status','groupStatusMessageV2'=>'group-status']as$wrapper=>$type)if(in_array($wrapper,$wrappers,true))$extra['statusInteraction']=['type'=>$type,'wrapper'=>$wrapper,'action'=>'publication'];
        $notification=$message['statusNotificationMessage']??null;
        if(is_array($notification)){
            $type=match($notification['type']??0){1,'STATUS_ADD_YOURS'=>'add-yours',4,'STATUS_GROUP_STATUS_REPLY'=>'group-status',default=>null};
            if($type!==null)$extra['statusInteraction']=['type'=>$type,'wrapper'=>in_array('associatedChildMessage',$wrappers,true)?'associatedChildMessage':null,'action'=>'response','originalMessageKey'=>$notification['originalMessageKey']??null,'responseMessageKey'=>$notification['responseMessageKey']??null];
        }
        return $extra;
    }
}
