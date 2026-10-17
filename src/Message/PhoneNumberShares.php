<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\History\HistoryStore;
use MVO\Protocol\Jid;
use MVO\Service\{AccountToolsService,IdentityService,StateFeatureInputs};

/** Explicit native consent observed in an authenticated conversation; never an inferred request reply. */
final class PhoneNumberShares
{
    /** Decode only an authenticated SHARE_PHONE_NUMBER message. Identity mappings alone are not consent.
     * @param array<string,mixed> $event
     * @return array<string,mixed>|null */
    public static function decode(array $event,?IdentityService $identities=null):?array
    {
        if(($event['encrypted']??false)===true||isset($event['decryptError'])||($event['authentication']??null)==='message-secret')return null;
        if(!is_array($event['proto']??null))return null;
        try{$message=ContentDecoder::unwrap($event['proto'])['message'];}
        catch(\UnexpectedValueException|\LengthException){return null;}
        if(!in_array($message['protocolMessage']['type']??null,[11,'SHARE_PHONE_NUMBER'],true))return null;
        $chat=self::user($event['chat']??null);$sender=self::user($event['authenticatedSender']??null);
        $id=$event['id']??null;$fromMe=$event['fromMe']??null;$timestamp=$event['timestamp']??null;
        if($chat===null||$sender===null||!is_string($id)||$id===''||strlen($id)>128||!is_bool($fromMe)||!is_int($timestamp)||$timestamp<0)return null;
        if($fromMe){if(($event['authenticatedOwnDevice']??false)!==true)return null;}
        elseif(($event['authenticatedOwnDevice']??false)===true||!self::same($sender,$chat,$identities))return null;
        $phone=null;$lid=str_ends_with($sender,'@lid')?$sender:null;$numberSource='unavailable';
        if(str_ends_with($sender,'@s.whatsapp.net')){$phone=$sender;$numberSource='authenticated_stanza';}
        // The alternative identifies the peer chat. On own-device echoes it cannot identify our own number.
        if(!$fromMe){
            $alternative=self::user($event['key']['remoteJidAlt']??null);
            if($alternative!==null&&self::same($sender,$alternative,$identities)){
                // incomingIdentities may derive this alternative from a stored mapping, not a header.
                if(str_ends_with($alternative,'@s.whatsapp.net')){if($phone===null){$phone=$alternative;$numberSource='authenticated_identity_mapping';}}
                elseif($lid===null)$lid=$alternative;
            }
        }
        if($identities!==null){
            if($phone===null&&$lid!==null){$phone=$identities->resolvePhone($lid);if($phone!==null)$numberSource='authenticated_identity_mapping';}
            if($lid===null&&$phone!==null)$lid=$identities->resolveLid($phone);
        }
        return ['messageId'=>$id,'chat'=>$chat,'fromMe'=>$fromMe,'timestamp'=>$timestamp,
            'phoneNumber'=>$phone===null?null:Jid::parse($phone)->user,'phoneJid'=>$phone,'lid'=>$lid,
            'numberSource'=>$numberSource,'requestId'=>null,'correlation'=>'conversation','source'=>'authenticated_protocol','native'=>true];
    }

    /** Only exact namespaces for the selected conversation and its confirmed aliases are read.
     * @return list<array<string,mixed>> */
    public static function list(HistoryStore $history,string $chat,int $limit=100,string $after=''):array
    {
        $chat=AccountToolsService::chat($chat,true);StateFeatureInputs::page($limit,$after);$rows=[];
        foreach($history->phoneNumberShareAliases($chat)as$alias)foreach($history->iterateStoredMessages($alias)as$event){
            $row=$history->decodePhoneNumberShare($event);if($row===null)continue;
            $cursor=rawurlencode($row['messageId']).':'.($row['fromMe']?'1':'0');if(strcmp($cursor,$after)<=0)continue;$key=$cursor;
            if(!isset($rows[$key])||$rows[$key]['numberSource']==='unavailable')$rows[$key]=[...$row,'chat'=>$chat,'cursor'=>$cursor];
            if(count($rows)>=$limit*2){ksort($rows,SORT_STRING);$rows=array_slice($rows,0,$limit,true);}
        }
        ksort($rows,SORT_STRING);return array_slice(array_values($rows),0,$limit);
    }

    private static function user(mixed $value):?string
    {
        if(!is_string($value))return null;
        try{$jid=Jid::parse($value)->canonical();}
        catch(\InvalidArgumentException){return null;}
        return in_array($jid->server,['s.whatsapp.net','lid'],true)&&ctype_digit($jid->user)&&strlen($jid->user)<=20?$jid->bare():null;
    }
    private static function same(string $a,string $b,?IdentityService $identities):bool
    {
        if($a===$b)return true;if($identities===null)return false;
        $canonical=static fn(string $jid):string=>str_ends_with($jid,'@lid')?$identities->currentLid($jid):($identities->resolveLid($jid)??$jid);
        return $canonical($a)===$canonical($b);
    }
}
