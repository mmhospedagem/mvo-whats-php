<?php
declare(strict_types=1);
namespace MVO\Voip;
use MVO\Protocol\BinaryNode;

final class RelayDescriptor
{
    /** Returns only descriptors; never opens a network socket.
     *
     * @return list<array<string,mixed>> */
    public static function parse(BinaryNode $node):array
    {
        $relays=[];$children=is_array($node->content)?$node->content:[];
        if($node->tag==='relay')$children=[$node];
        foreach($children as $child) {
            if($child->tag!=='relay'){if(in_array($child->tag,['call','offer','accept','relays'],true))$relays=[...$relays,...self::parse($child)];continue;}
            $tokens=[];$authTokens=[];$key=$child->child('key')?->content;$key=is_string($key)?$key:'';
            foreach(is_array($child->content)?$child->content:[] as $entry)if(is_string($entry->content)){if($entry->tag==='token')$tokens[$entry->attrs['id']??'0']=$entry->content;elseif($entry->tag==='auth_token')$authTokens[$entry->attrs['id']??'0']=$entry->content;}
            foreach(is_array($child->content)?$child->content:[] as $entry) {
                if(!in_array($entry->tag,['te','te2'],true) || !is_string($entry->content) || !in_array(strlen($entry->content),[6,18,24],true))continue;
                $blocks=strlen($entry->content)===24?[substr($entry->content,0,6),substr($entry->content,6)]:[$entry->content];
                $tokenId=$entry->attrs['token_id']??'0';$authId=$entry->attrs['auth_token_id']??'';$raw=$tokens[$tokenId]??'';$auth=$authTokens[$authId]??null;
                foreach($blocks as $block){$ip=inet_ntop(substr($block,0,-2));$port=unpack('n',substr($block,-2))[1];if($ip===false || $port<1)continue;$relays[]=['ip'=>$ip,'port'=>$port,'key'=>$key,'token'=>base64_encode($raw),'rawToken'=>$raw,'authToken'=>$auth===null?null:base64_encode($auth),'rawAuthToken'=>$auth,'authTokenId'=>$authId!==''?$authId:$tokenId,'tokenId'=>$tokenId,'relayId'=>(int)($entry->attrs['relay_id']??0),'relayName'=>$entry->attrs['relay_name']??'','protocol'=>(int)($entry->attrs['protocol']??0),'isFna'=>($entry->attrs['is_fna']??'')==='1','domainName'=>$child->attrs['domain_name']??null,'enableEdgerayDtlsActiveMode'=>in_array($child->attrs['enable_edgeray_dtls_active_mode']??'',['1','true'],true),'c2rRtt'=>(int)($entry->attrs['c2r_rtt']??PHP_INT_MAX),'selfPid'=>(int)($child->attrs['self_pid']??0),'peerPid'=>(int)($child->attrs['peer_pid']??0),'addressBytes'=>$block];}
            }
        }
        usort($relays,static fn($a,$b)=>$a['c2rRtt']<=>$b['c2rRtt']);return $relays;
    }
}

