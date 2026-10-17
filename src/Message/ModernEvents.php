<?php
declare(strict_types=1);
namespace MVO\Message;

/** Convenience fields supplement the complete authenticated protocol message. */
final class ModernEvents
{
    /**
     * @param array<string,mixed> $message
     * @param list<string> $wrappers
     *
     * @return array<string,mixed> */
    public static function normalize(array $message,array $wrappers=[]):array
    {
        $unwrapped=ContentDecoder::unwrap($message);$message=$unwrapped['message'];
        $wrappers=array_values(array_unique([...$wrappers,...$unwrapped['wrappers']]));$out=[];
        foreach(['paymentReminderMessage'=>'payment-reminder','splitPaymentMessage'=>'split-payment','splitPaymentUpdateMessage'=>'split-payment-update']as$field=>$type)if(is_array($message[$field]??null)){$out['payment']=self::payment($message[$field],$type);$out['kind']=$type;}
        $bot=$unwrapped['context']['botMetadata']??$message['messageContextInfo']['botMetadata']??null;
        if(is_array($bot)){
            $progress=$bot['progressIndicatorMetadata']??null;
            if(is_array($progress)){
                $out['botProgress']=['description'=>\MVO\History\ProtocolValues::text($progress['progressDescription']??null,4096),'steps'=>[]];
                if(is_int($progress['estimatedCompletionTime']??null)&&$progress['estimatedCompletionTime']>=0)$out['botProgress']['estimatedCompletionTime']=$progress['estimatedCompletionTime'];
                foreach(array_slice(is_array($progress['stepsMetadata']??null)?$progress['stepsMetadata']:[],0,100)as$step)if(is_array($step)){
                    $item=['title'=>\MVO\History\ProtocolValues::text($step['statusTitle']??null,1024),'body'=>\MVO\History\ProtocolValues::text($step['statusBody']??null,8192)];
                    foreach(['status','isReasoning','isEnhancedSearch']as$field)if(is_int($step[$field]??null)||is_string($step[$field]??null)||is_bool($step[$field]??null))$item[$field]=$step[$field];$out['botProgress']['steps'][]=$item;
                }
            }
            $suggestions=$bot['suggestedPromptMetadata']['suggestedPrompts']??null;
            if(is_array($suggestions)){$out['botSuggestions']=[];foreach(array_slice($suggestions,0,50)as$value){$text=\MVO\History\ProtocolValues::text($value,4096);if($text!==null)$out['botSuggestions'][]=$text;}}
            $memory=$bot['memoryMetadata']??null;
            if(is_array($memory)){
                $out['botMemory']=['added'=>[],'removed'=>[],'disclaimer'=>\MVO\History\ProtocolValues::text($memory['disclaimer']??null,4096),'observedOnly'=>true,'writeSupported'=>false];
                foreach(['addedFacts'=>'added','removedFacts'=>'removed']as$field=>$target)foreach(array_slice(is_array($memory[$field]??null)?$memory[$field]:[],0,100)as$fact)if(is_array($fact)){$text=\MVO\History\ProtocolValues::text($fact['fact']??null,8192);$id=\MVO\History\ProtocolValues::text($fact['factId']??null,256);if($text!==null||$id!==null)$out['botMemory'][$target][]=['id'=>$id,'text'=>$text];}
            }
            if(is_array($bot['capabilityMetadata']['capabilities']??null))$out['botCapabilities']=array_values(array_filter(array_slice($bot['capabilityMetadata']['capabilities'],0,100),static fn(mixed $v):bool=>is_int($v)||(is_string($v)&&strlen($v)<=256)));
            $sources=self::sources($bot['richResponseSourcesMetadata']??null);if($sources!==[])$out['botSources']=$sources;
            $r=$bot['reminderMetadata']??null;
            if(is_array($r)){
                $action=match($r['action']??null){1,'NOTIFY'=>'notify',2,'CREATE'=>'create',3,'DELETE'=>'delete',4,'UPDATE'=>'update',default=>null};
                $frequency=match($r['frequency']??null){1,'ONCE'=>'once',2,'DAILY'=>'daily',3,'WEEKLY'=>'weekly',4,'BIWEEKLY'=>'biweekly',5,'MONTHLY'=>'monthly',default=>null};
                $key=\MVO\History\ProtocolValues::key($r['requestMessageKey']??null);
                if($action!==null&&$key!==null){$out['botReminder']=['requestMessageKey'=>$key,'action'=>$action,'name'=>\MVO\History\ProtocolValues::text($r['name']??null,1024),'frequency'=>$frequency];if(is_int($r['nextTriggerTimestamp']??null)&&$r['nextTriggerTimestamp']>=0)$out['botReminder']['nextTriggerTimestamp']=$r['nextTriggerTimestamp'];}
            }
        }
        if(isset($message['musicMessage'])){
            $body=$message['musicMessage'];$out['kind']='music';$out['music']=$body;
            $out['music']['styleName']=match($body['style']??0){1,'VINYL'=>'vinyl',2,'MESSAGE_BUBBLE'=>'message_bubble',3,'LYRICS'=>'lyrics',default=>'unknown'};
        }
        $sticker=$message['stickerMessage']??null;
        if(is_array($sticker)&&(isset($sticker['audioMessage'])||in_array('audioStickerMessage',$wrappers,true))){
            $out['kind']='audio-sticker';$out['audioSticker']=['sticker'=>array_diff_key($sticker,['audioMessage'=>true]),'audio'=>$sticker['audioMessage']??null];
        }
        if(isset($message['requestLocationMessage']))$out+=['kind'=>'request-location','locationRequest'=>['action'=>'request']];
        if(isset($message['requestLocationUpdateMessage'])){
            $body=$message['requestLocationUpdateMessage'];$out+=['kind'=>'cancel-location-request','target'=>$body['key']??null,
                'locationRequest'=>['action'=>in_array($body['updateType']??null,[1,'CANCEL'],true)?'cancel':'unknown','target'=>$body['key']??null,'timestampMs'=>$body['senderTimestampMs']??null]];
        }
        $protocol=$message['protocolMessage']??null;
        if(is_array($protocol)&&in_array($protocol['type']??null,[39,'ACP2_SETTING'],true)&&is_array($protocol['acp2Setting']??null))$out['acp2']=$protocol['acp2Setting'];
        if(is_array($unwrapped['context']['acp2Setting']??null))$out['acp2Context']=$unwrapped['context']['acp2Setting'];
        if(is_array($protocol)&&is_array($protocol['peerDataOperationRequestResponseMessage']??null)){
            $response=$protocol['peerDataOperationRequestResponseMessage'];
            if(in_array($response['peerDataOperationRequestType']??null,[14,'CONTACT_REFRESH_REQUEST'],true)){
                $results=[];foreach($response['peerDataOperationResult']??[]as$result)if(is_array($result['contactRefreshResponse']??null))$results[]=$result['contactRefreshResponse'];
                $out['contactRefresh']=['requestId'=>$response['stanzaId']??null,'results'=>$results];
            }
        }
        foreach($message as$body)if(is_array($body)&&is_array($body['contextInfo']['externalAdReply']??null)){
            $ad=$body['contextInfo']['externalAdReply'];$fields=['title','body','mediaType','sourceType','sourceId','sourceUrl','ctwaClid','containsAutoReply','containsCtwaFlowsAutoLabel','productId','containsCtwaPromo','ctwaPromoResponseId','ctwaPromoOfferId','ctwaPromoAdEntryId','ctwaPromoResponseExpiresAtSeconds','ctwaPromoSchemaVersion','ctwaPromoAdgroupId'];
            $isCtwa=($ad['containsCtwaPromo']??false)===true||($ad['containsCtwaFlowsAutoLabel']??false)===true;
            foreach(['ctwaClid','ctwaPromoResponseId','ctwaPromoOfferId','ctwaPromoAdEntryId','ctwaPromoAdgroupId']as$field)if(is_string($ad[$field]??null)&&$ad[$field]!=='')$isCtwa=true;
            if($isCtwa)$out['ctwa']=array_intersect_key($ad,array_flip($fields));
        }
        if(is_array($protocol)&&in_array($protocol['type']??null,[34,'CHAT_THEME_SETTING'],true)&&isset($protocol['chatThemeSetting'])){
            $theme=$protocol['chatThemeSetting'];$out['chatTheme']=$theme;
            $out['chatTheme']['action']=($theme['clearTheme']??false)?'reset':'set';
            $out['chatTheme']['wallpaperType']=null;
            foreach(['defaultWallpaper'=>'default','solidColor'=>'solid-color','stockImage'=>'stock-image','customImage'=>'custom-image','animatedWallpaper'=>'animated']as$field=>$type)if(isset($theme[$field]))$out['chatTheme']['wallpaperType']=$type;
        }
        $status=[];
        if(isset($message['statusLinkPreviewMetadata']))$status['linkPreview']=$message['statusLinkPreviewMetadata'];
        if(in_array('questionMessage',$wrappers,true)){
            $status['question']=true;
            if(($message['extendedTextMessage']['contextInfo']['isQuestion']??false)===true)$out['kind']='status-question';
        }
        if(isset($message['statusQuestionAnswerMessage'])){$status['answer']=$message['statusQuestionAnswerMessage'];$out['kind']='status-answer';$out['target']=$message['statusQuestionAnswerMessage']['key']??null;$out['statusInteraction']=['type'=>'question','action'=>'answer','originalMessageKey'=>$out['target'],'text'=>$message['statusQuestionAnswerMessage']['text']??null];}
        if(is_array($message['statusQuotedMessage']??null)){$s=$message['statusQuotedMessage'];$status['quoted']=['type'=>$s['type']??null,'text'=>\MVO\History\ProtocolValues::text($s['text']??null,16384),'originalStatusId'=>\MVO\History\ProtocolValues::key($s['originalStatusId']??null)];$out['statusInteraction']=['type'=>'question','action'=>'reshare','originalMessageKey'=>$s['originalStatusId']??null,'text'=>$status['quoted']['text']];}
        if(is_array($message['statusStickerInteractionMessage']??null)){$s=$message['statusStickerInteractionMessage'];$out['statusInteraction']=['type'=>'sticker','action'=>in_array($s['type']??null,[1,'REACTION'],true)?'reaction':'unknown','originalMessageKey'=>$s['key']??null,'stickerKey'=>\MVO\History\ProtocolValues::text($s['stickerKey']??null,256)];}
        if(isset($message['questionResponseMessage'])){$s=$message['questionResponseMessage'];$status['questionResponse']=$s;if(($s['key']['remoteJid']??null)==='status@broadcast')$out['statusInteraction']=['type'=>'question','action'=>'answer','originalMessageKey'=>$s['key'],'text'=>\MVO\History\ProtocolValues::text($s['text']??null,16384)];}
        if(isset($message['statusNotificationMessage'])){$s=$message['statusNotificationMessage'];$status['notification']=$s;$type=match($s['type']??null){1,'STATUS_ADD_YOURS'=>'add-yours',2,'STATUS_RESHARE'=>'status',3,'STATUS_QUESTION_ANSWER_RESHARE'=>'question',4,'STATUS_GROUP_STATUS_REPLY'=>'group-status',default=>null};if($type!==null)$out['statusInteraction']=['type'=>$type,'wrapper'=>in_array('associatedChildMessage',$wrappers,true)?'associatedChildMessage':null,'action'=>in_array($s['type']??null,[2,3,'STATUS_RESHARE','STATUS_QUESTION_ANSWER_RESHARE'],true)?'reshare':'response','originalMessageKey'=>$s['originalMessageKey']??null,'responseMessageKey'=>$s['responseMessageKey']??null];}
        foreach(['extendedTextMessage','imageMessage','videoMessage','audioMessage']as$field){
            $body=$message[$field]??null;if(!is_array($body))continue;
            if(isset($body['musicMetadata']))$status['music']=$body['musicMetadata'];
            if(isset($body['linkPreviewMetadata']['musicMetadata']))$status['linkMusic']=$body['linkPreviewMetadata']['musicMetadata'];
            if(isset($body['contextInfo']['statusAttributions']))$status['attributions']=$body['contextInfo']['statusAttributions'];
            if(isset($body['contextInfo']['statusAttributionType']))$status['attributionType']=$body['contextInfo']['statusAttributionType'];
            if(($body['contextInfo']['isQuestion']??false)===true)$status['question']=true;
            foreach([...($body['annotations']??[]),...($body['interactiveAnnotations']??[])]as$annotation)if(isset($annotation['embeddedContent']['embeddedMusic']))$status['musicAnnotations'][]=$annotation;
            $quoted=$body['contextInfo']??null;
            if(!isset($out['statusInteraction'])&&is_array($quoted)&&($quoted['remoteJid']??null)==='status@broadcast'&&is_string($quoted['stanzaId']??null)){
                $key=['id'=>$quoted['stanzaId'],'remoteJid'=>'status@broadcast'];if(is_string($quoted['participant']??null))$key['participant']=$quoted['participant'];
                $out['statusInteraction']=['type'=>'status','action'=>'reply','originalMessageKey'=>$key,'text'=>\MVO\History\ProtocolValues::text($body['text']??$body['caption']??null,16384)];
            }
        }
        if(!isset($out['statusInteraction'])&&is_array($message['reactionMessage']??null)&&($message['reactionMessage']['key']['remoteJid']??null)==='status@broadcast')$out['statusInteraction']=['type'=>'emoji','action'=>($message['reactionMessage']['text']??'')===''?'reaction-remove':'reaction','originalMessageKey'=>$message['reactionMessage']['key'],'text'=>\MVO\History\ProtocolValues::text($message['reactionMessage']['text']??null,128)];
        $association=$unwrapped['context']['messageAssociation']??$message['messageContextInfo']['messageAssociation']??null;
        if(is_array($association)){
            $associationMessage=$message;$associationMessage['messageContextInfo']['messageAssociation']=$association;
            $out['association']=AdvancedEvents::normalize($associationMessage,'',[])['association'];
        }
        if($status!==[])$out['statusContent']=$status;return $out;
    }
    /** @return list<array<string,mixed>> */
    public static function sources(mixed $metadata):array
    {
        $sources=[];$bytes=0;if(!is_array($metadata))return $sources;
        foreach(array_slice(is_array($metadata['sources']??null)?$metadata['sources']:[],0,100)as$source){if(!is_array($source))continue;$item=[];
            foreach(['sourceProviderUrl','thumbnailCdnUrl','faviconCdnUrl']as$field){$url=\MVO\History\ProtocolValues::url($source[$field]??null);if($url!==null)$item[$field]=$url;}
            foreach(['sourceTitle','sourceQuery']as$field){$text=\MVO\History\ProtocolValues::text($source[$field]??null,2048);if($text!==null)$item[$field]=$text;}
            foreach(['citationNumber','provider']as$field)if(is_int($source[$field]??null)||is_string($source[$field]??null))$item[$field]=$source[$field];
            if($item!==[]){$size=strlen(json_encode($item,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));if($bytes+$size>1048576)break;$bytes+=$size;$sources[]=$item;}
        }return $sources;
    }
    /** Money is an exact integer and protocol offset, never a floating-point calculation.
     * @return array{value:string,offset:int,currencyCode:string}|null */
    public static function money(mixed $value):?array
    {
        if(!is_array($value)||!is_int($value['offset']??null)||$value['offset']<0||$value['offset']>0xffffffff||!is_string($value['currencyCode']??null)||preg_match('/^[A-Z]{3}$/D',$value['currencyCode'])!==1)return null;
        $number=$value['value']??null;if(is_int($number))$number=(string)$number;
        if(!is_string($number)||preg_match('/^(?:0|[1-9][0-9]{0,18})$/D',$number)!==1||strlen($number)>19||(strlen($number)===19&&strcmp($number,'9223372036854775807')>0))return null;
        return ['value'=>$number,'offset'=>$value['offset'],'currencyCode'=>$value['currencyCode']];
    }
    /** @param array<string,mixed> $body
     * @return array<string,mixed> */
    private static function payment(array $body,string $type):array
    {
        $out=['type'=>$type,'observedOnly'=>true,'paymentVerified'=>false];
        foreach(['reminderId','instanceId','splitId']as$field)if(isset($body[$field])){if(is_string($body[$field])&&$body[$field]!==''&&strlen($body[$field])<=256&&!preg_match('/[\x00-\x20\x7f]/',$body[$field])&&preg_match('//u',$body[$field])===1)$out[$field]=$body[$field];else$out['invalidReference']=true;}
        $description=\MVO\History\ProtocolValues::text($body['description']??null,8192);if($description!==null)$out['description']=$description;
        foreach(['payeeJid','payerJid','requesterJid','participantJid']as$field)if(isset($body[$field])){$jid=self::paymentUser($body[$field]);if($jid!==null)$out[$field]=$jid;else$out['invalidReference']=true;}
        foreach(['amount','totalAmount']as$field){$money=self::money($body[$field]??null);if($money!==null)$out[$field]=$money;}
        if(is_int($body['createdAtMs']??null)&&$body['createdAtMs']>=0)$out['createdAtMs']=$body['createdAtMs'];
        if($type==='payment-reminder'){
            $out['frequency']=match($body['frequency']??null){1,'WEEKLY'=>'weekly',2,'BI_WEEKLY'=>'biweekly',3,'MONTHLY'=>'monthly',4,'QUARTERLY'=>'quarterly',default=>'unknown'};
            $out['reportedStatus']=match($body['status']??null){1,'ACTIVE'=>'active',2,'CANCELLED_BY_CREATOR'=>'cancelled_by_creator',3,'STOPPED_BY_RECEIVER'=>'stopped_by_receiver',4,'EXPIRED'=>'expired',5,'PAID'=>'paid',default=>'unknown'};
        }
        if($type==='split-payment'){$out['participants']=[];$seen=[];$participants=is_array($body['participants']??null)?$body['participants']:[];
            foreach(array_slice($participants,0,2048)as$p){if(!is_array($p)||($jid=self::paymentUser($p['jid']??null))===null||isset($seen[$jid])){$out['participantsIncomplete']=true;continue;}$seen[$jid]=true;$row=['jid'=>$jid,'reportedStatus'=>in_array($p['status']??null,[1,'PAID'],true)?'paid':'pending'];$money=self::money($p['amount']??null);if($money!==null)$row['amount']=$money;$out['participants'][]=$row;}
            if(count($participants)>2048)$out['participantsIncomplete']=true;
        }
        return $out;
    }
    private static function paymentUser(mixed $value):?string
    {
        if(!is_string($value)||strlen($value)>256)return null;try{$jid=\MVO\Protocol\Jid::parse($value)->canonical();return in_array($jid->server,['s.whatsapp.net','lid'],true)&&ctype_digit($jid->user)?$jid->bare():null;}catch(\InvalidArgumentException){return null;}
    }
}
