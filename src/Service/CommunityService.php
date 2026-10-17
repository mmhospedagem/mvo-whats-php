<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\Protocol\Jid;

/** Community administration over the client's existing authenticated group/profile services.
 * Server permissions, partial failures and nullable MEX data are returned unchanged.
 */
final class CommunityService
{
    public function __construct(private readonly GroupService $groups,private readonly ProfileService $profile) {}

    /** @return list<array<string,mixed>> */
    public function queryAllCommunities():array
    {
        return array_values(array_filter($this->groups->queryAllGroups(),static fn(array $group):bool=>($group['isParentGroup'] ?? false)===true));
    }

    /** @return array<string,mixed> */
    public function queryCommunityMetadata(string $jid):array
    {
        $metadata=$this->groups->queryGroupMetadata(self::group($jid));
        if(($metadata['isParentGroup'] ?? false)!==true) throw new \UnexpectedValueException('The requested group is not a community');
        return $metadata;
    }

    /** @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function createCommunity(string $subject,array $options=[]):array
    {
        self::subject($subject);self::creationOptions($options,true);
        return $this->groups->createCommunity($subject,$options);
    }

    public function setSubject(string $jid,string $subject):void
    {
        self::group($jid);self::subject($subject);$this->groups->setSubject($jid,$subject);
    }

    /** Omit previousId to fetch the current description ID before the compare-and-set. */
    public function setDescription(string $jid,?string $description,?string $previousId=null):void
    {
        self::group($jid);self::description($description);
        if($previousId!==null) self::text($previousId,'previous description ID',256,false);
        else $previousId=$this->groups->queryGroupMetadata($jid)['descId'] ?? null;
        $this->groups->setDescription($jid,$description==='' ? null : $description,$previousId);
    }

    /** @return array<string,string> */
    public function getProfilePicture(string $jid,string $type='image'):array
    {
        self::group($jid);if(!in_array($type,['preview','image'],true)) throw new \InvalidArgumentException('Invalid community picture type');
        return $this->profile->getProfilePicture($jid,$type);
    }

    public function setProfilePicture(string $jid,string $jpegBytes):?string
    {
        self::group($jid);
        if($jpegBytes==='' || strlen($jpegBytes)>8*1024*1024) throw new \InvalidArgumentException('Community picture must be a JPEG of at most 8 MiB');
        $image=@getimagesizefromstring($jpegBytes);
        if($image===false || $image[2]!==IMAGETYPE_JPEG || $image[0]<1 || $image[1]<1) throw new \InvalidArgumentException('Community picture must contain valid JPEG bytes');
        return $this->profile->setProfilePicture($jpegBytes,$jid);
    }

    public function removeProfilePicture(string $jid):void { $this->profile->deleteProfilePicture(self::group($jid)); }
    public function setSetting(string $jid,string $setting,bool $enabled):void { $this->groups->setSetting(self::group($jid),$setting,$enabled); }
    public function setMemberAddMode(string $jid,string $mode):void { $this->groups->setMemberAddMode(self::group($jid),$mode); }
    public function setMemberLinkMode(string $jid,string $mode):void { $this->groups->setMemberLinkMode(self::group($jid),$mode); }
    public function setMemberShareGroupHistoryMode(string $jid,string $mode):void { $this->groups->setMemberShareGroupHistoryMode(self::group($jid),$mode); }
    public function setEphemeralDuration(string $jid,int $seconds,?int $trigger=null):void { $this->groups->setEphemeralDuration(self::group($jid),$seconds,$trigger); }

    /** @param list<string> $participants
     * @return list<array<string,mixed>> */
    public function addParticipants(string $jid,array $participants):array { return $this->groups->addParticipants(self::group($jid),self::users($participants)); }
    /** @param list<string> $participants
     * @return list<array<string,mixed>> */
    public function removeParticipants(string $jid,array $participants):array { return $this->groups->removeParticipants(self::group($jid),self::users($participants)); }
    /** @param list<string> $participants
     * @return list<array<string,mixed>> */
    public function promoteParticipants(string $jid,array $participants):array { return $this->groups->promoteParticipants(self::group($jid),self::users($participants)); }
    /** @param list<string> $participants
     * @return list<array<string,mixed>> */
    public function demoteParticipants(string $jid,array $participants):array { return $this->groups->demoteParticipants(self::group($jid),self::users($participants)); }

    public function queryInviteCode(string $jid):string { return $this->groups->queryInviteCode(self::group($jid)); }
    /** @return array{code:string,affectedParticipants:list<\MVO\Protocol\BinaryNode>} */
    public function revokeInvite(string $jid):array { return $this->groups->revokeInvite(self::group($jid)); }
    /** @return array<string,mixed> */
    public function queryCommunityInviteInfo(string $code):array
    {
        $metadata=$this->groups->queryGroupInviteInfo(self::inviteCode($code));
        if(($metadata['isParentGroup'] ?? false)!==true) throw new \UnexpectedValueException('The invitation does not refer to a community');
        return $metadata;
    }
    /** Returns status=pending when WhatsApp requires approval; it never claims that request joined.
     * @return array<string,mixed> */
    public function joinCommunityViaInvite(string $code):array { return $this->groups->joinGroupViaInvite(self::inviteCode($code)); }
    public function leaveCommunity(string $jid):void { $this->groups->leaveGroup([self::group($jid)]); }
    public function deactivateCommunity(string $jid):void { $this->groups->deactivateCommunity(self::group($jid)); }

    /** Creates the subgroup with linked_parent in the same server request.
     * @param list<string> $participants
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function createSubGroup(string $community,string $subject,array $participants=[],array $options=[]):array
    {
        self::group($community);self::subject($subject);self::creationOptions($options,false);
        $participants=self::users($participants,true);
        return $this->groups->createGroup($subject,$participants,['linkedParentJid'=>$community]+$options);
    }
    /** @param list<string|array{jid:string,hidden?:bool}> $subgroups
     * @return array<string,mixed> */
    public function linkSubGroups(string $jid,array $subgroups):array { return $this->groups->linkSubGroups(self::group($jid),self::links($jid,$subgroups)); }
    /** @param list<string> $subgroups
     * @return array<string,mixed> */
    public function unlinkSubGroups(string $jid,array $subgroups,bool $removeOrphanedMembers=false):array { return $this->groups->unlinkSubGroups(self::group($jid),self::subgroups($jid,$subgroups),$removeOrphanedMembers); }
    /** Raw persisted MEX operation data, including null and all server fields.
     * @return array<string,mixed>|null */
    public function fetchSubGroups(string $jid):?array { return $this->groups->fetchSubGroups(self::group($jid)); }
    /** Lists linked groups using the group IQ protocol.
     * @return list<array<string,mixed>> */
    public function querySubGroups(string $jid):array { return $this->groups->querySubGroups(self::group($jid)); }
    /** @return list<array<string,mixed>> */
    public function queryLinkedGroupsParticipants(string $jid):array { return $this->groups->queryLinkedGroupsParticipants(self::group($jid)); }
    public function joinLinkedGroup(string $community,string $subgroup,?string $type=null):void
    {
        self::group($community);self::subgroups($community,[$subgroup]);
        if($type!==null) self::text($type,'linked group join type',64,false);
        $this->groups->joinLinkedGroup($community,$subgroup,$type);
    }
    /** @return list<array<string,string>> */
    public function queryMembershipApprovalRequests(string $jid):array { return $this->groups->queryMembershipApprovalRequests(self::group($jid)); }
    /** @param list<string> $jids
     * @return list<array<string,mixed>> */
    public function approveMembershipRequests(string $group,array $jids):array { return $this->groups->approveMembershipRequests(self::group($group),self::users($jids)); }
    /** @param list<string> $jids
     * @return list<array<string,mixed>> */
    public function rejectMembershipRequests(string $group,array $jids):array { return $this->groups->rejectMembershipRequests(self::group($group),self::users($jids)); }
    /** @param list<string> $jids
     * @return list<array<string,mixed>> */
    public function cancelMembershipRequests(string $group,array $jids):array { return $this->groups->cancelMembershipRequests(self::group($group),self::users($jids)); }
    public function transferCommunityOwnership(string $jid,string $newOwner):void { $this->groups->transferCommunityOwnership(self::group($jid),self::users([$newOwner])[0]); }
    /** @return array<string,mixed>|null */
    public function fetchSubgroupSuggestions(string $jid,?string $hint=null):?array
    {
        self::group($jid);if($hint!==null) self::subgroups($jid,[$hint]);return $this->groups->fetchSubgroupSuggestions($jid,$hint);
    }
    /** @return array<string,mixed>|null */
    public function isInternalGroup(string $jid):?array { return $this->groups->isInternalGroup(self::group($jid)); }
    /** @return array<string,mixed>|null */
    public function submitGroupSuspensionAppeal(string $jid,?string $reason=null,string $debugInfo='{}'):?array
    {
        self::group($jid);if($reason!==null) self::text($reason,'suspension appeal reason',8192,false);
        self::text($debugInfo,'suspension appeal debug info',65536,false);
        try { $debug=json_decode($debugInfo,false,32,JSON_THROW_ON_ERROR); } catch(\JsonException $e) { throw new \InvalidArgumentException('Suspension appeal debug info must contain JSON',0,$e); }
        if(!$debug instanceof \stdClass) throw new \InvalidArgumentException('Suspension appeal debug info must contain a JSON object');
        return $this->groups->submitGroupSuspensionAppeal($jid,$reason,$debugInfo);
    }

    private static function group(string $jid):string
    {
        if(!preg_match('/^[0-9]+(?:-[0-9]+)?@g\.us$/D',$jid)) throw new \InvalidArgumentException('Expected a community or group JID ending in @g.us');
        return $jid;
    }
    private static function subject(string $subject):void { self::text($subject,'community/group subject',100,false); }
    private static function description(?string $description):void { if($description!==null) self::text($description,'community/group description',2048,true); }
    private static function text(string $value,string $name,int $limit,bool $empty):void
    {
        if(preg_match('//u',$value)!==1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value) || (!$empty && trim($value)==='') || preg_match_all('/./us',$value)>$limit) throw new \InvalidArgumentException('Invalid '.$name);
    }
    /** @param array<string,mixed> $options */
    private static function creationOptions(array $options,bool $parent):void
    {
        $allowed=$parent ? ['description','membershipApprovalMode','allowNonAdminSubGroupCreation','createGeneralChat'] : ['description'];
        foreach($options as $key=>$value) {
            if(!in_array($key,$allowed,true)) throw new \InvalidArgumentException('Invalid community/group creation option: '.$key);
            if($key==='description') { if($value!==null && !is_string($value)) throw new \InvalidArgumentException('Description must be a string or null');self::description($value); }
            elseif($key==='membershipApprovalMode') { if(!in_array($value,['open','request_required'],true)) throw new \InvalidArgumentException('Invalid default community membership approval mode'); }
            elseif(!is_bool($value)) throw new \InvalidArgumentException('Community creation flags must be boolean');
        }
    }
    /** @param list<string> $users
     * @return list<string> */
    private static function users(array $users,bool $allowEmpty=false):array
    {
        if(!array_is_list($users) || (!$allowEmpty && $users===[])) throw new \InvalidArgumentException('Expected a non-empty participant list');
        $out=[];
        foreach($users as $user) {
            if(!is_string($user)) throw new \InvalidArgumentException('Participant must be a phone number or user JID');
            $jid=Jid::recipient($user)->canonical();
            if(!in_array($jid->server,['s.whatsapp.net','lid'],true) || $jid->device!==0 || !ctype_digit($jid->user)) throw new \InvalidArgumentException('Participant must be a phone number or bare user JID');
            $out[]=(string)$jid;
        }
        return array_values(array_unique($out));
    }
    /** @param list<string> $subgroups
     * @return list<string> */
    private static function subgroups(string $parent,array $subgroups):array
    {
        if(!array_is_list($subgroups) || $subgroups===[]) throw new \InvalidArgumentException('Expected a non-empty subgroup list');
        foreach($subgroups as $jid) {
            if(!is_string($jid)) throw new \InvalidArgumentException('Subgroup must be a group JID');
            self::group($jid);if($jid===$parent) throw new \InvalidArgumentException('A community cannot link itself');
        }
        return array_values(array_unique($subgroups));
    }
    /** @param list<string|array{jid:string,hidden?:bool}> $subgroups
     * @return list<string|array{jid:string,hidden?:bool}> */
    private static function links(string $parent,array $subgroups):array
    {
        if(!array_is_list($subgroups) || $subgroups===[]) throw new \InvalidArgumentException('Expected a non-empty subgroup list');
        $seen=[];
        foreach($subgroups as $subgroup) {
            if(is_string($subgroup)) $jid=$subgroup;
            elseif(is_array($subgroup) && is_string($subgroup['jid'] ?? null) && array_diff(array_keys($subgroup),['jid','hidden'])===[] && (!array_key_exists('hidden',$subgroup) || is_bool($subgroup['hidden']))) $jid=$subgroup['jid'];
            else throw new \InvalidArgumentException('Linked subgroup must be a JID or an object containing jid and optional boolean hidden');
            self::subgroups($parent,[$jid]);
            if(isset($seen[$jid])) throw new \InvalidArgumentException('Duplicate linked subgroup');$seen[$jid]=true;
        }
        return $subgroups;
    }
    private static function inviteCode(string $code):string
    {
        if(str_starts_with($code,'https://chat.whatsapp.com/')) $code=substr($code,strlen('https://chat.whatsapp.com/'));
        if(strlen($code)>256 || !preg_match('/^[A-Za-z0-9_-]+$/D',$code)) throw new \InvalidArgumentException('Invalid community invite code');return $code;
    }
}
