<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\History\HistoryStore;
use MVO\Protocol\Jid;
use MVO\Service\{AccountToolsService,StateFeatureInputs};

/** Correlates only explicit self-contact replies quoting an observed phone request. No hidden PN lookup. */
final class PhoneNumberRequests
{
    /** @return array<string,mixed> */
    public static function content():array{return ['type'=>'request-phone-number'];}
    /** @return list<array<string,mixed>> */
    public static function list(HistoryStore $history,string $chat,int $limit=100,string $after=''):array
    {
        $chat=AccountToolsService::chat($chat,true);StateFeatureInputs::page($limit,$after);$requests=[];$responses=[];
        foreach($history->iterateMessages($chat)as$event){$proto=$event['proto']??null;if(!is_array($proto)||!is_string($event['id']??null))continue;$message=ContentDecoder::unwrap($proto)['message'];
            if(is_array($message['requestPhoneNumberMessage']??null)){$id=$event['id'];$requests[$id]=['id'=>$id,'chat'=>$chat,'fromMe'=>($event['fromMe']??false)===true,'timestamp'=>$event['timestamp']??null,'state'=>'awaiting_response','responses'=>[],'source'=>'authenticated_message'];}
            $contact=$message['contactMessage']??null;$context=is_array($contact)?($contact['contextInfo']??null):null;
            if(!is_array($contact)||($contact['isSelfContact']??false)!==true||!is_array($context)||!is_string($context['stanzaId']??null))continue;
            $sender=$event['authenticatedSender']??$event['from']??null;if(!is_string($sender))continue;try{$sender=Jid::parse($sender)->canonical()->bare();}catch(\InvalidArgumentException){continue;}
            $responses[]=['requestId'=>$context['stanzaId'],'id'=>$event['id'],'timestamp'=>$event['timestamp']??null,'sender'=>$sender,'fromMe'=>($event['fromMe']??false)===true];
        }
        foreach($responses as$response){$request=$requests[$response['requestId']]??null;if($request===null||$request['fromMe']===$response['fromMe']||($request['fromMe']&&$response['sender']!==$chat))continue;
            if(is_int($request['timestamp'])&&is_int($response['timestamp'])&&$response['timestamp']<$request['timestamp'])continue;
            $requests[$response['requestId']]['state']='self_contact_shared';$requests[$response['requestId']]['responses'][]=['messageId'=>$response['id'],'timestamp'=>$response['timestamp'],'explicitSelfContact'=>true];
        }
        ksort($requests,SORT_STRING);$out=[];foreach($requests as$id=>$request)if(strcmp($id,$after)>0){$request['cursor']=$id;$out[]=$request;if(count($out)===$limit)break;}return $out;
    }
}
