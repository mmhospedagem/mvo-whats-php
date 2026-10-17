<?php
declare(strict_types=1);
namespace MVO\Service;
use MVO\Protocol\BinaryNode;

/** Group and community administration. Server ACL errors and per-member failures are preserved. */
final class GroupService
{
    private const SETTINGS=['announcement'=>['announcement','not_announcement'],'restrict'=>['locked','unlocked'],'ephemeral'=>['ephemeral','not_ephemeral'],'membership_approval_mode'=>['membership_approval_mode','membership_approval_mode'],'allow_non_admin_sub_group_creation'=>['allow_non_admin_sub_group_creation','not_allow_non_admin_sub_group_creation'],'group_history'=>['group_history','no_group_history'],'allow_admin_reports'=>['allow_admin_reports','not_allow_admin_reports'],'no_frequently_forwarded'=>['no_frequently_forwarded','frequently_forwarded_ok']];
    public function __construct(private readonly ServiceTransport $transport,private readonly MexService $mex) {}
    /**
     * @return array<string,mixed> */
    public function queryGroupMetadata(string $jid): array { return self::metadata($this->iq('metadata','get',$jid,[new BinaryNode('query')])); }
    /**
     * @return list<array<string,mixed>> */
    public function queryAllGroups(): array
    {
        $r=$this->iq('list','get','g.us',[new BinaryNode('participating',[],[new BinaryNode('participants'),new BinaryNode('description')])]);
        $groups=ServiceTransport::children($r->child('groups') ?? $r,'group'); return array_map(self::metadata(...),$groups);
    }
    /**
     * @param list<string> $participants
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function createGroup(string $subject,array $participants,array $options=[]): array
    {
        if (trim($subject)==='') throw new \InvalidArgumentException('Group subject is required');
        $children=$participants === [] ? [] : ServiceTransport::users($participants);
        if (isset($options['description'])) $children[]=new BinaryNode('description',['id'=>self::timestamp()],[new BinaryNode('body',[],(string)$options['description'])]);
        if (isset($options['linkedParentJid'])) $children[]=new BinaryNode('linked_parent',['jid'=>ServiceTransport::jid($options['linkedParentJid'],'g.us')]);
        if ($options['parent'] ?? false) $children[]=new BinaryNode('parent',($options['membershipApprovalMode'] ?? 'request_required') === 'open' ? [] : ['default_membership_approval_mode'=>'request_required']);
        foreach (['allowNonAdminSubGroupCreation'=>'allow_non_admin_sub_group_creation','createGeneralChat'=>'create_general_chat'] as $k=>$tag) if ($options[$k] ?? false) $children[]=new BinaryNode($tag);
        return self::metadata($this->iq('create','set','g.us',[new BinaryNode('create',['subject'=>$subject],$children)]));
    }
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function createCommunity(string $subject,array $options=[]): array { return $this->createGroup($subject,[],['parent'=>true]+$options); }
    public function setSubject(string $jid,string $subject): void { $this->iq('setSubject','set',$jid,[new BinaryNode('subject',[],$subject)]); }
    public function setDescription(string $jid,?string $description,?string $previousId=null): void
    {
        $attrs=['id'=>self::timestamp()]; if ($previousId !== null) $attrs['prev']=$previousId; if ($description === null) $attrs['delete']='true';
        $this->iq('setDescription','set',$jid,[new BinaryNode('description',$attrs,$description === null ? null : [new BinaryNode('body',[],$description)])]);
    }
    public function setSetting(string $jid,string $setting,bool $enabled): void
    {
        if (!isset(self::SETTINGS[$setting])) throw new \InvalidArgumentException('Invalid group setting');
        $this->iq('setSetting','set',$jid,[new BinaryNode(self::SETTINGS[$setting][$enabled ? 0 : 1],[],$setting === 'membership_approval_mode' ? [new BinaryNode('group_join',['state'=>$enabled ? 'on' : 'off'])] : null)]);
    }
    public function setMemberAddMode(string $jid,string $mode): void { $this->mode($jid,'member_add_mode',$mode,['admin_add','all_member_add']); }
    public function setMemberLinkMode(string $jid,string $mode): void { $this->mode($jid,'member_link_mode',$mode,['admin_link','all_member_link']); }
    public function setMemberShareGroupHistoryMode(string $jid,string $mode): void { $this->mode($jid,'member_share_group_history_mode',$mode,['admin_share','all_member_share']); }
    /**
     * @param list<string> $allowed */
    private function mode(string $jid,string $tag,string $mode,array $allowed): void { if (!in_array($mode,$allowed,true)) throw new \InvalidArgumentException('Invalid group member mode'); $this->iq($tag,'set',$jid,[new BinaryNode($tag,[],$mode)]); }
    public function setEphemeralDuration(string $jid,int $seconds,?int $trigger=null): void
    {
        if ($seconds<0 || ($trigger !== null && $trigger<0)) throw new \InvalidArgumentException('Negative expiration/trigger');
        $attrs=['expiration'=>(string)$seconds]; if ($trigger !== null) $attrs['trigger']=(string)$trigger; $this->iq('setEphemeralDuration','set',$jid,[new BinaryNode('ephemeral',$attrs)]);
    }
    /**
     * @param list<string> $participants
     * @return list<array<string,mixed>> */
    public function addParticipants(string $jid,array $participants): array { return $this->participants($jid,'add',$participants); }
    /**
     * @param list<string> $participants
     * @return list<array<string,mixed>> */
    public function removeParticipants(string $jid,array $participants): array { return $this->participants($jid,'remove',$participants); }
    /**
     * @param list<string> $participants
     * @return list<array<string,mixed>> */
    public function promoteParticipants(string $jid,array $participants): array { return $this->participants($jid,'promote',$participants); }
    /**
     * @param list<string> $participants
     * @return list<array<string,mixed>> */
    public function demoteParticipants(string $jid,array $participants): array { return $this->participants($jid,'demote',$participants); }
    /**
     * @param list<string> $participants
     * @return list<array<string,mixed>> */
    private function participants(string $jid,string $action,array $participants): array
    {
        $r=$this->iq($action,'set',$jid,[new BinaryNode($action,[],ServiceTransport::users($participants))]);
        return self::participantResults($r->child($action) ?? $r);
    }
    /** @return list<array<string,mixed>> */
    private static function participantResults(?BinaryNode $node):array
    {
        $out=[];
        foreach (ServiceTransport::children($node,'participant') as $p) {
            if (!isset($p->attrs['jid'])) continue; $code=(int)($p->attrs['error'] ?? $p->child('error')?->attrs['code'] ?? '200');
            $out[]=['jid'=>$p->attrs['jid'],'status'=>$code>=200 && $code<300 ? 'ok' : 'error','code'=>$code,'phoneNumber'=>$p->attrs['phone_number'] ?? null,'username'=>$p->attrs['username'] ?? null,'raw'=>$p];
        } return $out;
    }
    /**
     * @param list<string> $jids */
    public function leaveGroup(array $jids): void { $nodes=[]; foreach (ServiceTransport::users($jids,'group','g.us') as $n) $nodes[]=new BinaryNode('group',['id'=>$n->attrs['jid']]); $this->iq('leave','set','g.us',[new BinaryNode('leave',[],$nodes)]); }
    public function queryInviteCode(string $jid): string { return $this->invite($jid,'get')->child('invite')?->attrs['code'] ?? throw new \UnexpectedValueException('Invite code missing'); }
    /**
     * @return array{code:string,affectedParticipants:list<BinaryNode>} */
    public function revokeInvite(string $jid): array { $r=$this->invite($jid,'set'); return ['code'=>$r->child('invite')?->attrs['code'] ?? throw new \UnexpectedValueException('Invite code missing'),'affectedParticipants'=>ServiceTransport::children($r,'participant')]; }
    private function invite(string $jid,string $type): BinaryNode { return $this->iq('invite',$type,$jid,[new BinaryNode('invite')]); }
    /**
     * @return array<string,mixed> */
    public function queryGroupInviteInfo(string $code): array { return $this->inviteInfo($code,'get'); }
    /**
     * @return array<string,mixed> */
    public function joinGroupViaInvite(string $code): array { return $this->inviteInfo($code,'set'); }
    /**
     * @return array<string,mixed> */
    private function inviteInfo(string $code,string $type): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D',$code)) throw new \InvalidArgumentException('Invalid invite code');
        $result=$this->iq('inviteInfo',$type,'g.us',[new BinaryNode('invite',['code'=>$code])]);
        $pending=$result->child('membership_approval_request');
        if($type==='set' && $pending!==null) {
            $jid=$pending->attrs['jid'] ?? throw new \UnexpectedValueException('Membership approval response missing group JID');ServiceTransport::jid($jid,'g.us');
            return ['jid'=>$jid,'status'=>'pending','raw'=>$pending];
        }
        $metadata=self::metadata($result);return $type==='set' ? $metadata+['status'=>'joined'] : $metadata;
    }
    public function deactivateCommunity(string $jid): void { $this->iq('deactivateCommunity','set',$jid,[new BinaryNode('delete_parent')]); }
    /**
     * @param list<string|array{jid:string,hidden?:bool}> $subgroups
     * @return array<string,mixed> */
    public function linkSubGroups(string $jid,array $subgroups): array
    {
        if(!array_is_list($subgroups) || $subgroups===[]) throw new \InvalidArgumentException('At least one subgroup is required');
        $nodes=[];
        foreach($subgroups as $subgroup) {
            if(is_string($subgroup)) $subgroup=['jid'=>$subgroup];
            if(!is_array($subgroup) || !is_string($subgroup['jid'] ?? null) || array_diff(array_keys($subgroup),['jid','hidden'])!==[] || (array_key_exists('hidden',$subgroup) && !is_bool($subgroup['hidden']))) throw new \InvalidArgumentException('Invalid linked subgroup');
            $nodes[]=new BinaryNode('group',['jid'=>ServiceTransport::jid($subgroup['jid'],'g.us')],($subgroup['hidden'] ?? false) ? [new BinaryNode('hidden_group')] : null);
        }
        $r=$this->iq('linkSubGroups','set',$jid,[new BinaryNode('links',[],[new BinaryNode('link',['link_type'=>'sub_group'],$nodes)])]);
        return self::linkResults($r->child('links')?->child('link'),'linkedJids');
    }
    /**
     * @param list<string> $subgroups
     * @return array<string,mixed> */
    public function unlinkSubGroups(string $jid,array $subgroups,bool $removeOrphanedMembers=false): array
    {
        $nodes=[]; foreach (ServiceTransport::users($subgroups,'group','g.us') as $n) $nodes[]=new BinaryNode('group',$n->attrs+($removeOrphanedMembers ? ['remove_orphaned_members'=>'true'] : []));
        return self::linkResults($this->iq('unlinkSubGroups','set',$jid,[new BinaryNode('unlink',['unlink_type'=>'sub_group'],$nodes)])->child('unlink'),'unlinkedJids');
    }
    /**
     * @return list<array<string,mixed>> */
    public function queryLinkedGroupsParticipants(string $jid): array { return self::parseParticipants($this->iq('linkedGroupsParticipants','get',$jid,[new BinaryNode('linked_groups_participants')])->child('linked_groups_participants')); }
    /**
     * @return list<array<string,string>> */
    public function queryMembershipApprovalRequests(string $jid): array { return array_map(static fn(BinaryNode $n):array=>$n->attrs,ServiceTransport::children($this->iq('membershipRequests','get',$jid,[new BinaryNode('membership_approval_requests')])->child('membership_approval_requests'),'membership_approval_request')); }
    /**
     * @param list<string> $jids
     * @return list<array<string,mixed>> */
    public function approveMembershipRequests(string $group,array $jids): array { return $this->membership($group,$jids,'approve'); }
    /**
     * @param list<string> $jids
     * @return list<array<string,mixed>> */
    public function rejectMembershipRequests(string $group,array $jids): array { return $this->membership($group,$jids,'reject'); }
    /**
     * @param list<string> $jids
     * @return list<array<string,mixed>> */
    public function cancelMembershipRequests(string $group,array $jids): array { return self::participantResults($this->iq('cancelMembershipRequests','set',$group,[new BinaryNode('cancel_membership_requests',[],ServiceTransport::users($jids))])->child('cancel_membership_requests')); }
    /**
     * @param list<string> $jids
     * @return list<array<string,mixed>> */
    private function membership(string $group,array $jids,string $action): array { return self::participantResults($this->iq('membershipRequests.'.$action,'set',$group,[new BinaryNode('membership_requests_action',[],[new BinaryNode($action,[],ServiceTransport::users($jids))])])->child('membership_requests_action')?->child($action)); }
    public function joinLinkedGroup(string $community,string $subgroup,?string $type=null): void { $this->iq('joinLinkedGroup','set',$community,[new BinaryNode('join_linked_group',['jid'=>ServiceTransport::jid($subgroup,'g.us')]+($type === null ? [] : ['type'=>$type]))]); }
    /** MEX responses retain every upstream field.
     * @return array<string,mixed>|null */
    public function fetchSubGroups(string $jid): ?array { ServiceTransport::jid($jid,'g.us'); return $this->mex->run('FetchAllSubgroups',['group_id'=>$jid,'query_context'=>'INTERACTIVE']); }
    /** @return list<array<string,mixed>> */
    public function querySubGroups(string $jid):array
    {
        $result=$this->iq('subGroups','get',$jid,[new BinaryNode('sub_groups')]);
        $subgroups=$result->child('sub_groups') ?? throw new \UnexpectedValueException('Subgroup list missing from response');
        return array_map(self::metadata(...),ServiceTransport::children($subgroups,'group'));
    }
    /**
     * @return array<string,mixed>|null */
    public function isInternalGroup(string $jid): ?array { ServiceTransport::jid($jid,'g.us'); return $this->mex->run('FetchGroupIsInternal',['id'=>$jid]); }
    public function transferCommunityOwnership(string $jid,string $newOwner): void { ServiceTransport::jid($jid,'g.us'); $this->mex->run('TransferCommunityOwnership',['input'=>['group_id'=>$jid,'role_updates'=>[['new_role'=>'SUPERADMIN_MEMBER','user_jid'=>ServiceTransport::user($newOwner)]]]]); }
    /**
     * @return array<string,mixed>|null */
    public function fetchSubgroupSuggestions(string $jid,?string $hint=null): ?array { ServiceTransport::jid($jid,'g.us'); return $this->mex->run('FetchSubgroupSuggestions',['group_id'=>$jid,'query_context'=>'INTERACTIVE']+($hint === null ? [] : ['sub_group_hint_id'=>ServiceTransport::jid($hint,'g.us')])); }
    /**
     * @return array<string,mixed>|null */
    public function submitGroupSuspensionAppeal(string $jid,?string $reason=null,string $debugInfo='{}'): ?array { ServiceTransport::jid($jid,'g.us'); return $this->mex->run('GroupSuspensionAppeal',['input'=>['group_jid'=>explode('@',$jid)[0],'debug_info'=>$debugInfo]+($reason === null ? [] : ['appeal_reason'=>$reason])]); }
    /**
     * @param list<BinaryNode> $nodes */
    private function iq(string $op,string $type,string $jid,array $nodes): BinaryNode { if ($jid !== 'g.us') ServiceTransport::jid($jid,'g.us'); return $this->transport->iq('group.'.$op,$type,$jid,'w:g2',$nodes); }
    private static function timestamp(): string { return (string)(int)floor(microtime(true)*1000); }
    /**
     * @return array<string,mixed> */
    private static function linkResults(?BinaryNode $node,string $key): array { $ok=[];$failed=[]; foreach(ServiceTransport::children($node,'group') as $g) { $jid=$g->attrs['jid'] ?? null; if ($jid === null) continue; $e=$g->child('error'); if ($e !== null || isset($g->attrs['error'])) $failed[]=['jid'=>$jid,'error'=>(int)($g->attrs['error'] ?? $e?->attrs['code'] ?? 0)]; else $ok[]=$jid; } return [$key=>$ok,'failed'=>$failed]; }
    /**
     * @return list<array<string,mixed>> */
    public static function parseParticipants(?BinaryNode $node): array
    {
        $out=[]; foreach(ServiceTransport::children($node,'participant') as $p) { if (!isset($p->attrs['jid'])) continue; $type=$p->attrs['type'] ?? 'participant'; $out[]=['jid'=>$p->attrs['jid'],'type'=>$type,'isAdmin'=>in_array($type,['admin','superadmin'],true),'isSuperAdmin'=>$type === 'superadmin','lid'=>$p->attrs['lid'] ?? null,'phoneNumber'=>$p->attrs['phone_number'] ?? null,'displayName'=>$p->attrs['display_name'] ?? null,'username'=>$p->attrs['username'] ?? null]; } return $out;
    }
    /**
     * @return array<string,mixed> */
    public static function metadata(BinaryNode $result): array
    {
        $g=$result->tag === 'group' ? $result : $result->child('group'); if ($g === null) throw new \UnexpectedValueException('Group response missing group');
        $a=$g->attrs; $jid=$a['id'] ?? $a['jid'] ?? ''; if ($jid !== '' && !str_contains($jid,'@')) $jid.='@g.us';
        $out=['jid'=>$jid,'subject'=>$a['subject'] ?? '','owner'=>$a['creator'] ?? $a['owner'] ?? null,'subjectOwner'=>$a['s_o'] ?? $a['subject_owner'] ?? null,'ownerPhoneNumber'=>$a['creator_pn'] ?? null,'addressingMode'=>$a['addressing_mode'] ?? null,'participants'=>self::parseParticipants($g),'desc'=>ServiceTransport::text($g->child('description')?->child('body')),'descId'=>$g->child('description')?->attrs['id'] ?? null,'membershipApprovalEnabled'=>($g->child('membership_approval_mode')?->child('group_join')?->attrs['state'] ?? null) === 'on','linkedParentJid'=>$g->child('linked_parent')?->attrs['jid'] ?? null,'isClosedCommunity'=>($g->child('parent')?->attrs['default_membership_approval_mode'] ?? null) === 'request_required','defaultMembershipApprovalMode'=>$g->child('parent')?->attrs['default_membership_approval_mode'] ?? null,'raw'=>$g];
        foreach (['size'=>'size','creation'=>'creation','s_t'=>'subjectTime'] as $attr=>$key) if (isset($a[$attr])) $out[$key]=(int)$a[$attr];
        foreach (['restrict'=>'locked','announce'=>'announcement','isParentGroup'=>'parent','defaultSubgroup'=>'default_sub_group','generalSubgroup'=>'general_chat','hiddenSubgroup'=>'hidden_group','allowNonAdminSubGroupCreation'=>'allow_non_admin_sub_group_creation','groupHistory'=>'group_history','support'=>'support','suspended'=>'suspended','incognito'=>'incognito','allowAdminReports'=>'allow_admin_reports','autoAddDisabled'=>'auto_add_disabled','noFrequentlyForwarded'=>'no_frequently_forwarded','capi'=>'capi','groupSafetyCheck'=>'group_safety_check','participantLabelEnabled'=>'participant_label_enabled','limitSharingEnabled'=>'limit_sharing_enabled'] as $key=>$tag) $out[$key]=$g->child($tag) !== null;
        foreach (['expiration'=>'ephemeral','trigger'=>'ephemeralTrigger'] as $attr=>$key) if (isset($g->child('ephemeral')?->attrs[$attr])) $out[$key]=(int)$g->child('ephemeral')->attrs[$attr];
        foreach (['memberAddMode'=>'member_add_mode','memberLinkMode'=>'member_link_mode','memberShareGroupHistoryMode'=>'member_share_group_history_mode'] as $key=>$tag) $out[$key]=ServiceTransport::text($g->child($tag));
        return $out;
    }
}
