<?php
declare(strict_types=1);
namespace MVO\Service;
use MVO\Message\ContentDecoder;
use MVO\Protocol\{BinaryNode,SchemaCodec,ArgoDecoder};

/** Pure incoming service parsing. ACK, durable message storage and session state belong to WaClient. */
final class IncomingEvents
{
    /** @return list<array{event:string,data:array<string,mixed>}> */
    public static function parse(BinaryNode $node): array
    {
        $base=self::base($node);
        if($node->tag==='presence') {
            $type=($node->attrs['type'] ?? '') === 'unavailable' ? 'unavailable' : 'available';$data=$base+['type'=>$type];
            if(str_ends_with($node->attrs['from'] ?? '','@g.us')) { $n=self::int($node->attrs['count'] ?? null); if($n!==null || $type==='unavailable') $data['groupOnlineCount']=$n ?? 0; }
            elseif($type==='unavailable' && isset($node->attrs['last'])) { $v=$node->attrs['last'];$data['lastSeen']=match($v) { 'deny'=>['kind'=>'privacy_denied'],'none'=>['kind'=>'never_online'],'error'=>['kind'=>'unknown'],default=>self::int($v) === null ? ['kind'=>'unknown'] : ['kind'=>'timestamp','unixSeconds'=>self::int($v)] }; }
            return [['event'=>'presence','data'=>$data]];
        }
        if($node->tag==='chatstate') { $c=ServiceTransport::children($node)[0] ?? null;if($c===null || !in_array($c->tag,['composing','paused'],true)) return [];$data=$base+['state'=>$c->tag];if($c->tag==='composing' && ($c->attrs['media'] ?? '')==='audio') $data['media']='audio';if(isset($node->attrs['participant'])) $data['participantJid']=$node->attrs['participant'];return [['event'=>'chatstate','data'=>$data]]; }
        if($node->tag==='message' && str_ends_with($node->attrs['from'] ?? '','@newsletter')) return self::newsletter($node);
        if($node->tag!=='notification') return [];
        $type=$node->attrs['type'] ?? '';$events=[];
        if($type==='w:gp2') { foreach(ServiceTransport::children($node) as $action) $events[]=['event'=>'group','data'=>self::group($node,$action)]; }
        elseif($type==='picture') { foreach(ServiceTransport::children($node) as $a) if(in_array($a->tag,['set','delete','request','set_avatar'],true)) $events[]=['event'=>'picture','data'=>$base+['action'=>$a->tag,'targetJid'=>$a->attrs['jid'] ?? null,'authorJid'=>$a->attrs['author'] ?? null,'pictureId'=>$a->tag==='set' ? self::int($a->attrs['id'] ?? null) : null,'contactHash'=>$a->attrs['hash'] ?? null]]; }
        elseif($type==='account_sync') {
            $u=$node->child('username'); if($u!==null) $events[]=['event'=>'own_username','data'=>$base+['kind'=>($u->attrs['action'] ?? '')==='modify' ? 'modify' : (isset($u->attrs['username']) ? 'set' : 'delete'),'username'=>($u->attrs['action'] ?? '')==='modify' ? null : (isset($u->attrs['username']) ? ltrim($u->attrs['username'],'@') : null)]];
            $events[]=['event'=>'account_sync','data'=>$base+['children'=>ServiceTransport::children($node)]];
        } elseif($type==='privacy' || $type==='blocklist') $events[]=['event'=>$type,'data'=>$base+['children'=>ServiceTransport::children($node)]];
        elseif($type==='mex') {
            $update=$node->child('update');$text=ServiceTransport::text($update);if($text!==null) {
                try {
                    $j=match($update?->attrs['format'] ?? 'json') { 'json'=>json_decode($text,true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING),'argo'=>ArgoDecoder::decode($text),default=>throw new \UnexpectedValueException('Unknown MEX notification format') };
                    if(!is_array($j)) throw new \UnexpectedValueException('Invalid MEX notification envelope');
                    $operation=$update?->attrs['op_name'] ?? null;
                    $identity=null;
                    if($operation==='LidChangeNotification'&&empty($j['errors'])){
                        $change=$j['data']['xwa2_notify_lid_change']??null;
                        if(!is_array($change)||!is_string($change['old']??null)||!is_string($change['new']??null))throw new \UnexpectedValueException('Invalid LID change notification');
                        try{$old=IdentityService::lid(str_contains($change['old'],'@')?$change['old']:$change['old'].'@lid');$new=IdentityService::lid(str_contains($change['new'],'@')?$change['new']:$change['new'].'@lid');}
                        catch(\InvalidArgumentException){throw new \UnexpectedValueException('Invalid LID change identity');}
                        $identity=['kind'=>'lid_change','oldLidJid'=>$old,'newLidJid'=>$new];
                    }
                    $events[]=['event'=>'mex_notification','data'=>$base+['operationName'=>$operation,'data'=>$j['data'] ?? null,'errors'=>$j['errors'] ?? []]+($identity??[])];
                    if(empty($j['errors'])&&is_string($operation)&&is_array($j['data']??null))$events=[...$events,...self::channelNotification($node,$operation,$j['data'])];
                } catch(\JsonException|\UnexpectedValueException|\LengthException $e) { $events[]=['event'=>'service_error','data'=>$base+['operation'=>'mex_notification','message'=>'Invalid MEX notification payload']]; }
            }
        } elseif(in_array($type,['business','w:biz'],true)) foreach(ServiceTransport::children($node) as $a) $events[]=['event'=>'business','data'=>$base+['action'=>$a->tag,'attrs'=>$a->attrs,'text'=>ServiceTransport::text($a),'node'=>$a]];
        $live=$node->child('live_updates')?->child('messages');if($live!==null) foreach(ServiceTransport::children($live,'message') as $inner) {
            $attrs=$inner->attrs+['from'=>$node->attrs['from'] ?? '','id'=>$node->attrs['id'] ?? '','t'=>$live->attrs['t'] ?? $node->attrs['t'] ?? ''];$events=[...$events,...self::newsletter(new BinaryNode('message',$attrs,$inner->content))];
        }
        return $events;
    }
    /** Decode only documented newsletter IQ wrappers. Fetching does not create read/view receipts.
     * @return list<array{event:string,data:array<string,mixed>}> */
    public static function parseNewsletterResponse(BinaryNode $response,string $jid):array
    {
        \MVO\History\ChannelStore::jid($jid);ServiceTransport::assertResult($response,'newsletter.history');
        $statuses=$response->child('statuses');
        $container=$response->child('messages')??$response->child('message_updates')?->child('messages')??$statuses;
        if($container===null)throw new \UnexpectedValueException('Newsletter messages response envelope missing');
        if(isset($container->attrs['jid'])&&$container->attrs['jid']!==$jid)throw new \UnexpectedValueException('Newsletter history response belongs to another channel');
        $events=[];
        foreach(ServiceTransport::children($container,$statuses!==null?'status':'message')as$message){
            $attrs=$message->attrs;
            if(isset($attrs['from'])&&$attrs['from']!==$jid)throw new \UnexpectedValueException('Newsletter history response belongs to another channel');
            if(!isset($attrs['id'])&&isset($attrs['server_id']))$attrs['id']='server:'.$attrs['server_id'];
            $attrs['from']=$jid;
            if(!isset($attrs['t'])&&isset($container->attrs['t']))$attrs['t']=$container->attrs['t'];
            foreach(self::newsletter(new BinaryNode('message',$attrs,$message->content))as$entry){$entry['data']['history']=true;if($statuses!==null)$entry['data']['isChannelStatus']=true;$events[]=$entry;}
        }
        return $events;
    }
    /** @param array<string,mixed> $data
     * @return list<array{event:string,data:array<string,mixed>}> */
    private static function channelNotification(BinaryNode $node,string $operation,array $data):array
    {
        $operations=[
            'NotificationNewsletterJoin'=>['xwa2_notify_newsletter_on_join','join'],
            'NotificationNewsletterLeave'=>['xwa2_notify_newsletter_on_leave','leave'],
            'NotificationNewsletterUpdate'=>['xwa2_notify_newsletter_on_metadata_update','metadata'],
            'NotificationNewsletterUserSettingChange'=>['xwa2_notify_newsletter_on_user_setting_change','user_setting'],
            'NotificationNewsletterStateChange'=>['xwa2_notify_newsletter_on_state_change','state'],
            'NotificationNewsletterAdminPromote'=>['xwa2_notify_newsletter_admin_promote','admin_promote'],
            'NotificationNewsletterAdminDemote'=>['xwa2_notify_newsletter_admin_demote','admin_demote'],
            'NotificationNewsletterOwnerUpdate'=>['xwa2_notify_newsletter_owner_on_metadata_update','owner_metadata'],
            'NotificationNewsletterAdminMetadataUpdate'=>['xwa2_notify_newsletter_on_admin_metadata_update','admin_metadata'],
        ];
        if(!isset($operations[$operation]))return [];
        [$root,$action]=$operations[$operation];$payload=$data[$root]??null;
        if(!is_array($payload)||!is_string($payload['id']??null))throw new \UnexpectedValueException('Invalid newsletter notification data');
        $jid=\MVO\History\ChannelStore::jid(ctype_digit($payload['id'])?$payload['id'].'@newsletter':$payload['id']);
        return [['event'=>'channel_update','data'=>array_replace(self::base($node),[
            'chatJid'=>$jid,'channelJid'=>$jid,'action'=>$action,'operationName'=>$operation,'data'=>$payload,
        ])]];
    }
    /** @return array<string,mixed> */
    private static function base(BinaryNode $node): array { return ['rawNode'=>$node,'stanzaId'=>$node->attrs['id'] ?? null,'chatJid'=>$node->attrs['from'] ?? null,'stanzaType'=>$node->attrs['type'] ?? null,'offline'=>isset($node->attrs['offline']),'timestampSeconds'=>self::int($node->attrs['t'] ?? null)]; }
    private static function int(?string $value): ?int { if($value===null || !ctype_digit($value) || strlen($value)>18) return null;return (int)$value; }
    /** @return array<string,mixed> */
    private static function group(BinaryNode $node,BinaryNode $action): array
    {
        $d=self::base($node)+['groupJid'=>$node->attrs['from'] ?? null,'authorJid'=>$node->attrs['participant'] ?? null,'action'=>$action->tag,'attrs'=>$action->attrs,'node'=>$action];$tag=$action->tag;
        if(in_array($tag,['add','remove','promote','demote','modify','revoke','linked_group_promote','linked_group_demote','revoked_membership_requests'],true)) $d['participants']=GroupService::parseParticipants($action);
        $toggles=['locked'=>['restrict',true],'unlocked'=>['restrict',false],'announcement'=>['announce',true],'not_announcement'=>['announce',false],'no_frequently_forwarded'=>['no_frequently_forwarded',true],'frequently_forwarded_ok'=>['no_frequently_forwarded',false],'suspended'=>['suspend',true],'unsuspended'=>['suspend',false],'group_history'=>['group_history',true],'no_group_history'=>['group_history',false],'allow_non_admin_sub_group_creation'=>['allow_non_admin_sub_group_creation',true],'not_allow_non_admin_sub_group_creation'=>['allow_non_admin_sub_group_creation',false],'allow_admin_reports'=>['allow_admin_reports',true],'not_allow_admin_reports'=>['allow_admin_reports',false]];
        if(isset($toggles[$tag])) { [$d['action'],$d['enabled']]=$toggles[$tag]; }
        elseif($tag==='subject') { $d['subject']=$action->attrs['subject'] ?? ServiceTransport::text($action);$d['subjectOwnerJid']=$action->attrs['s_o'] ?? null; }
        elseif($tag==='description') { $d['enabled']=$action->child('delete')===null && ($action->attrs['delete'] ?? '')!=='true';$d['description']=$d['enabled'] ? ServiceTransport::text($action->child('body')) : null;$d['descriptionId']=$action->attrs['id'] ?? null; }
        elseif($tag==='ephemeral' || $tag==='not_ephemeral') { $d['action']='ephemeral';$d['expirationSeconds']=$tag==='not_ephemeral' ? 0 : self::int($action->attrs['expiration'] ?? null);$d['mode']=$action->attrs['trigger'] ?? null; }
        elseif($tag==='invite') $d['code']=$action->attrs['code'] ?? null;
        elseif($tag==='membership_approval_mode') $d['enabled']=$action->child('group_join')?->attrs['state']==='on';
        elseif($tag==='link' || $tag==='unlink') { $d['linkedGroups']=array_map(static fn(BinaryNode $n):array=>GroupService::metadata($n),ServiceTransport::children($action,'group'));$d['mode']=$action->attrs[$tag.'_type'] ?? null;$d['unlinkReason']=$action->attrs['unlink_reason'] ?? null; }
        elseif($tag==='membership_approval_requests') $d['requests']=array_map(static fn(BinaryNode $n):array=>$n->attrs,ServiceTransport::children($action,'membership_approval_request'));
        elseif($tag==='membership_requests_action') {
            $d['requests']=[];foreach(ServiceTransport::children($action) as $change) $d['requests'][]=['action'=>$change->tag,'participants'=>GroupService::parseParticipants($change),'node'=>$change];
        }
        elseif($tag==='cancel_membership_requests') $d['participants']=GroupService::parseParticipants($action);
        elseif($tag==='parent') { $d['isParentGroup']=true;$d['defaultMembershipApprovalMode']=$action->attrs['default_membership_approval_mode'] ?? null; }
        elseif($tag==='delete_parent') $d['isParentGroup']=false;
        elseif($tag==='create' && $action->child('group')!==null) $d['metadata']=GroupService::metadata($action->child('group'));
        if(isset($action->attrs['reason'])) $d['reason']=$action->attrs['reason'];return $d;
    }
    /** @return list<array{event:string,data:array<string,mixed>}> */
    private static function newsletter(BinaryNode $n): array
    {
        $base=self::base($n)+['parentMessageServerId'=>self::int($n->attrs['server_id'] ?? null),'isSender'=>($n->attrs['is_sender'] ?? '')==='true'];$events=[];$edit=$n->attrs['edit'] ?? '';
        if(!preg_match('/^[0-9]{1,128}@newsletter$/D',$n->attrs['from']??''))return self::newsletterError($base,'Invalid newsletter channel');
        if(isset($n->attrs['server_id'])&&($base['parentMessageServerId']===null||$base['parentMessageServerId']<1))return self::newsletterError($base,'Invalid newsletter server ID');
        $reactions=$n->child('reactions');$reaction=$n->child('reaction');
        if($reactions!==null || $reaction!==null) {
            $list=[];foreach($reactions===null ? [$reaction] : ServiceTransport::children($reactions,'reaction') as $r) { if($r===null) continue;$entry=['code'=>$r->attrs['code'] ?? ''];if(isset($r->attrs['count'])) { $count=self::int($r->attrs['count']);if($count===null || $count<1) return self::newsletterError($base,'Invalid reaction count');$entry['count']=$count; }$list[]=$entry; }
            $events[]=['event'=>'newsletter_update','data'=>$base+['update'=>['kind'=>'reaction','snapshot'=>$reactions!==null,'revoked'=>$edit==='7' || ($n->attrs['type'] ?? '')==='reaction_revoke','reactions'=>$list]]];
        }
        $votes=$n->child('votes');if($votes!==null) {
            $entries=[];$counted=0;foreach(ServiceTransport::children($votes,'vote') as $v) { if(!is_string($v->content) || strlen($v->content)!==32) return self::newsletterError($base,'Invalid poll option hash');$entry=['optionHash'=>$v->content];if(isset($v->attrs['count'])) { $count=self::int($v->attrs['count']);if($count===null || $count<1) return self::newsletterError($base,'Invalid poll count');$entry['count']=$count;$counted++; }$entries[]=$entry; }
            if($counted>0 && $counted!==count($entries)) return self::newsletterError($base,'Inconsistent poll aggregation');$events[]=['event'=>'newsletter_update','data'=>$base+['update'=>['kind'=>'poll_vote','votes'=>$entries]]];
        }
        $counters=[];foreach(['views_count'=>'views','forwards_count'=>'forwards','responses_count'=>'responses'] as $tag=>$key) { $counter=$n->child($tag);if($counter===null)continue;$count=self::int($counter->attrs['count'] ?? null);if($count===null)return self::newsletterError($base,'Invalid newsletter counter');$counters[$key]=$count; }if($counters!==[]) $events[]=['event'=>'newsletter_update','data'=>$base+['update'=>['kind'=>'counters']+$counters]];
        if($edit==='8') return [['event'=>'newsletter_update','data'=>$base+['update'=>['kind'=>'revoke']]],...$events];
        $plain=ServiceTransport::text($n->child('plaintext'));if($plain===null) return $events!==[]?$events:self::newsletterError($base,'Newsletter plaintext missing');
        try { $proto=SchemaCodec::decode('Message',$plain);$decoded=ContentDecoder::decode($plain); }
        catch(\Throwable $error) { return self::newsletterError($base,'Newsletter protobuf invalid'); }
        if($edit==='3') return [['event'=>'newsletter_update','data'=>$base+['update'=>['kind'=>'edit','message'=>$proto,'data'=>$decoded]]],...$events];
        $id=$n->attrs['id'] ?? '';$jid=$n->attrs['from'] ?? '';
        if($id===''&&$base['parentMessageServerId']!==null)$id='server:'.$base['parentMessageServerId'];
        if($id==='')return self::newsletterError($base,'Newsletter message identity missing');
        $isResponse=$decoded['contentType']==='questionResponseMessage';
        $responseId=$n->child('meta')?->attrs['response_server_id']??null;
        $event=['id'=>$id,'from'=>$jid,'chat'=>$jid,'fromMe'=>($n->attrs['is_sender'] ?? '')==='true','isNewsletter'=>true,'serverId'=>self::int($n->attrs['server_id'] ?? null),'proto'=>$proto,'type'=>$decoded['kind'],'text'=>$decoded['text'] ?? null,'data'=>$decoded['data'] ?? null,'media'=>$decoded['media'] ?? null,'context'=>$decoded['context'] ?? [],'key'=>['remoteJid'=>$jid,'fromMe'=>($n->attrs['is_sender'] ?? '')==='true','id'=>$id,'isNewsletter'=>true,'serverId'=>self::int($n->attrs['server_id'] ?? null)]]+$base;
        $event['media']=\MVO\Media\MediaTransfer::resolveNewsletterPayload($proto)??$event['media'];
        if($isResponse){$event['isChannelQuestionResponse']=true;$event['questionServerId']=$event['serverId'];$event['serverId']=null;$event['key']['serverId']=null;if($responseId!==null)$event['responseServerId']=$responseId;}
        return [['event'=>'message','data'=>$event],['event'=>'newsletter_message','data'=>$event],...$events];
    }
    /** @param array<string,mixed> $base
     * @return list<array{event:string,data:array<string,mixed>}> */
    private static function newsletterError(array $base,string $message): array { return [['event'=>'service_error','data'=>$base+['operation'=>'newsletter.receive','message'=>$message]]]; }
}
