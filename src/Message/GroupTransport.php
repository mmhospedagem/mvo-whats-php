<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Protocol\{BinaryNode,Jid,Protobuf};
use MVO\Signal\{SenderKeyCodec,SenderKeyProtocol,SignalProtocol,SignalStore};
use MVO\Service\IdentityService;

/**
 * Group metadata, device fanout and native Signal sender-key envelopes.
 * plan() performs network I/O; prepare(..., plan) is entirely transactional.
 * @phpstan-type Plan array{group:string,sender:string,mode:'pn'|'lid',devices:list<string>,fingerprint:string,subject?:string}
 */
final class GroupTransport
{
    private readonly SenderKeyProtocol $senderKeys;

    /**
     * @param \Closure(BinaryNode,float):BinaryNode $query
     * @param \Closure(list<string>):array<string,list<string>> $discoverDevices
     * @param \Closure(list<string>):void $ensureSessions
     * @param \Closure():array<string,mixed> $getCredentials
     * @param (\Closure(string):string)|null $sessionJid Maps transport PN/LID aliases onto the direct ratchet.
     */
    public function __construct(
        private readonly SignalProtocol $signal,
        private readonly SignalStore $store,
        private readonly \Closure $query,
        private readonly \Closure $discoverDevices,
        private readonly \Closure $ensureSessions,
        private readonly \Closure $getCredentials,
        private readonly ?\Closure $sessionJid = null
    ) { $this->senderKeys = new SenderKeyProtocol($store); }

    /**
     * Fresh membership and device lists ensure removed members never receive the next chain.
     * @return Plan
     */
    public function plan(string $group,bool $requireCanPost=false): array
    {
        $stage='metadata';
        try{return $this->planInternal($group,$requireCanPost,$stage);}
        catch(\Throwable $error){
            // Opt in only for Group Status. Existing direct group-message exception
            // contracts and explicit permission/server errors remain unchanged.
            if(!$requireCanPost||$error instanceof \LogicException||$error instanceof \MVO\Service\ServiceException)throw $error;
            throw GroupPreparationException::fromFailure($group,$stage,$error);
        }
    }

    /** @return Plan */
    private function planInternal(string $group,bool $requireCanPost,string &$stage):array
    {
        $group = self::group($group);
        if (!str_ends_with($group,'@g.us')) throw new \InvalidArgumentException('Group metadata requires a g.us JID');
        $credentials = ($this->getCredentials)();
        if (($credentials['registered'] ?? false) !== true || !is_string($credentials['meJid'] ?? null)) throw new \LogicException('Pair the account before sending group messages');
        $response = ($this->query)(new BinaryNode('iq', ['type'=>'get','to'=>$group,'xmlns'=>'w:g2'], [new BinaryNode('query')]), 30);
        if ($response->tag !== 'iq' || ($response->attrs['type'] ?? '') !== 'result' || $response->child('error') !== null) throw new \RuntimeException('WhatsApp group metadata query rejected');
        $metadata = $response->child('group');
        if ($metadata === null) throw new \UnexpectedValueException('Group metadata response is missing its group');
        $responseId = $metadata->attrs['id'] ?? $metadata->attrs['jid'] ?? '';
        if ($responseId !== '' && self::group(str_contains($responseId, '@') ? $responseId : $responseId.'@g.us') !== $group) throw new \UnexpectedValueException('Group metadata belongs to another group');
        $members = self::children($metadata, 'participant');
        if ($members === []) throw new \UnexpectedValueException('Group metadata has no participant roster');
        $mode = $metadata->attrs['addressing_mode'] ?? '';
        if (!in_array($mode, ['pn','lid'], true)) {
            $mode='pn';
            foreach($members as$member)if(Jid::parse($member->attrs['jid']??'')->canonical()->server==='lid')$mode='lid';
        }
        $stage='identity';
        $me = Jid::parse($credentials['meJid'])->canonical();
        $sender = $me;
        if ($mode === 'lid') {
            if (!is_string($credentials['meLid'] ?? null)) throw new \LogicException('LID group requires the paired account LID');
            $lid = Jid::parse($credentials['meLid'])->canonical();
            $sender = new Jid($lid->user, 'lid', $lid->device ?: $me->device);
        }
        $users = []; $isMember = false;$isAdmin=false;$mappings=[];$identities=new IdentityService($this->store);
        foreach ($members as $participant) {
            $raw = $participant->attrs[$mode === 'lid' ? 'lid' : 'phone_number'] ?? $participant->attrs['jid'] ?? '';
            $address = Jid::parse($raw)->canonical();
            $pn = $participant->attrs['phone_number'] ?? null; $lid = $participant->attrs['lid'] ?? null;
            $primary = Jid::parse($participant->attrs['jid'] ?? $raw)->canonical();
            if ($primary->server === 'lid') $lid ??= $primary->bare();
            if ($primary->server === 's.whatsapp.net') $pn ??= $primary->bare();
            if ($pn !== null && $lid !== null) {
                $phone = Jid::parse($pn)->canonical(); $localId = Jid::parse($lid)->canonical();
                if ($phone->server !== 's.whatsapp.net' || $localId->server !== 'lid') throw new \UnexpectedValueException('Invalid group participant aliases');
                $mappings[]=[$phone->bare(),$localId->bare()];
            }
            if($address->server!==($mode==='lid'?'lid':'s.whatsapp.net')){
                $alias=$mode==='lid'?$identities->resolveLid($address->bare()):$identities->resolvePhone($address->bare());
                if($address->bare()===$me->bare()&&$mode==='lid')$alias=$sender->bare();
                if($alias===null)throw new \UnexpectedValueException('Group roster does not match its addressing mode');
                $address=Jid::parse($alias)->canonical();
            }
            if($address->server!==($mode==='lid'?'lid':'s.whatsapp.net')||$address->device!==0)throw new \UnexpectedValueException('Invalid group participant identity');
            $users[]=$address->bare();$own=$address->bare()===$sender->bare();$isMember=$isMember||$own;
            if($own&&in_array($participant->attrs['type']??'', ['admin','superadmin'],true))$isAdmin=true;
        }
        if (!$isMember) throw new \LogicException('The paired account is not a member of this group');
        if($requireCanPost&&($metadata->child('suspended')!==null||$metadata->child('deactivated')!==null))throw new \LogicException('The group is not available for publication');
        if($requireCanPost&&$metadata->child('announcement')!==null&&!$isAdmin)throw new \LogicException('Only current group administrators can publish in an announcement group');
        $this->store->transaction(function()use($mappings,$identities):void{foreach($mappings as[$phone,$lid])$identities->remember($phone,$lid,true);});
        $users = array_values(array_unique($users)); sort($users, SORT_STRING);
        $stage='device_discovery';
        $resolved = ($this->discoverDevices)($users); $devices = [];
        foreach ($users as $user) {
            if (!array_key_exists($user, $resolved)) throw new \UnexpectedValueException('No device list returned for group participant '.$user);
            if($resolved[$user]===[])throw new \UnexpectedValueException('Empty device list returned for group participant '.$user);
            foreach ($resolved[$user] as $device) {
                $address = Jid::parse($device)->canonical();
                if ($address->bare() !== $user || $address->device > 255) throw new \UnexpectedValueException('Device discovery returned an unexpected group participant');
                if ((string)$address !== (string)$sender) $devices[] = $device;
            }
        }
        $devices = array_values(array_unique($devices)); sort($devices, SORT_STRING);
        $stage='signal_sessions';
        ($this->ensureSessions)($devices);
        return ['group'=>$group, 'sender'=>(string)$sender, 'mode'=>$mode, 'devices'=>$devices,'subject'=>$metadata->attrs['subject']??'',
            'fingerprint'=>hash('sha256', json_encode([$mode,$users,$devices], JSON_THROW_ON_ERROR))];
    }

    /** Explicit broadcast audience; changing it rotates the sender chain.
     * @param list<string> $users
     * @return Plan */
    public function planBroadcast(string $list,array $users,bool $status=false):array
    {
        $list=self::group($list);
        if (!str_ends_with($list,'@broadcast') || ($status && $list!=='status@broadcast')) throw new \InvalidArgumentException('Invalid broadcast list JID');
        $credentials=($this->getCredentials)();
        $me=Jid::parse($credentials['meJid'] ?? '')->canonical();
        $sender=$me;
        if ($status) {
            if (!is_string($credentials['meLid'] ?? null)) throw new \LogicException('Publishing status requires the account LID');
            $lid=Jid::parse($credentials['meLid'])->canonical();
            $sender=new Jid($lid->user,'lid',$lid->device ?: $me->device);
        }
        $users[]= $sender->bare();
        $users=array_values(array_unique($users)); sort($users,SORT_STRING);
        $resolved=($this->discoverDevices)($users); $devices=[];
        foreach ($users as $user) {
            if (empty($resolved[$user])) throw new \UnexpectedValueException('No devices for broadcast recipient');
            foreach ($resolved[$user] as $device) {
                $parsed=Jid::parse($device)->canonical();
                if ($parsed->bare()!==$user) throw new \UnexpectedValueException('Unexpected broadcast device');
                if ((string)$parsed!==(string)$sender) $devices[]=(string)$parsed;
            }
        }
        $devices=array_values(array_unique($devices)); sort($devices,SORT_STRING);
        ($this->ensureSessions)($devices);
        return ['group'=>$list,'sender'=>(string)$sender,'mode'=>$status ? 'lid' : 'pn','devices'=>$devices,
            'fingerprint'=>hash('sha256',json_encode([$users,$devices],JSON_THROW_ON_ERROR))];
    }

    /** @param Plan $plan */
    public function prepareBroadcast(string $list,string $id,string $message,string $type,?string $mediaType,array $plan,?string $statusSetting=null):BinaryNode
    {
        $node=$this->prepare($list,$id,$message,$type,$mediaType,$plan);
        $attrs=$node->attrs; unset($attrs['addressing_mode']);
        $children=is_array($node->content) ? $node->content : [];
        if ($statusSetting!==null) {
            unset($attrs['phash']);
            $children[]=new BinaryNode('meta',['status_setting'=>$statusSetting]);
            $existing=[]; $participants=[];
            $distribution=$node->child('participants');
            foreach ($distribution===null ? [] : self::children($distribution,'to') as $participant) { $existing[$participant->attrs['jid']]=true; $participants[]=$participant; }
            foreach ($plan['devices'] as $device) {
                $jid=Jid::parse($device);
                if ($jid->device===0 && !isset($existing[(string)$jid]) && !in_array($jid->server,['hosted','hosted.lid'],true)) $participants[]=new BinaryNode('to',['jid'=>$jid->bare()]);
            }
            $children=array_values(array_filter($children,static fn(BinaryNode $child):bool=>$child->tag!=='participants'));
            if ($participants!==[]) array_unshift($children,new BinaryNode('participants',[],$participants));
        }
        return new BinaryNode('message',$attrs,$children);
    }

    /** Group-history contents are encrypted only to their eligible audience, never with the group key.
     * @param Plan $plan
     * @param list<string> $receivers */
    public function prepareDirect(string $group,string $id,string $message,string $type,?string $mediaType,array $plan,array $receivers):BinaryNode
    {
        $group=self::group($group); $targets=array_values(array_unique(array_map(static fn(string $jid):string=>Jid::parse($jid)->canonical()->bare(),$receivers)));
        $members=array_values(array_unique(array_map(static fn(string $jid):string=>Jid::parse($jid)->canonical()->bare(),$plan['devices'])));
        $sender=Jid::parse($plan['sender'])->canonical()->bare();
        foreach ($targets as $target) if (!in_array($target,$members,true) || $target===$sender) throw new \InvalidArgumentException('History receiver must be another current group member');
        $participants=[]; $identity=false;
        foreach ($plan['devices'] as $device) {
            $bare=Jid::parse($device)->canonical()->bare();
            if (!in_array($bare,$targets,true) && $bare!==$sender) continue;
            $enc=$this->signal->encrypt($this->sessionAddress($device),MessageCodec::pad($message)); $identity=$identity || $enc['type']==='pkmsg';
            $attrs=['v'=>'2','type'=>$enc['type']]; if ($mediaType!==null) $attrs['mediatype']=$mediaType;
            $participants[]=new BinaryNode('to',['jid'=>$device],[new BinaryNode('enc',$attrs,$enc['ciphertext'])]);
        }
        if ($participants===[]) throw new \RuntimeException('Group history audience has no devices');
        $children=[new BinaryNode('participants',[],$participants)];
        $credentials=($this->getCredentials)();
        if ($identity && self::needsDeviceIdentity($credentials)) $children[]=new BinaryNode('device-identity',[],self::deviceIdentity($credentials));
        return new BinaryNode('message',['id'=>$id,'to'=>$group,'type'=>$type,'addressing_mode'=>$plan['mode']],$children);
    }

    /**
     * Caller must persist the exact returned node with the outbox inside the
     * same outer SignalStore transaction. A retry reuses that ciphertext/ID.
     * @param Plan|null $plan
     */
    public function prepare(string $group, string $id, string $message, string $stanzaType = 'text', ?string $mediaType = null, ?array $plan = null): BinaryNode
    {
        $group = self::group($group); $plan ??= $this->plan($group);
        if ($id === '' || $plan['group'] !== $group) throw new \InvalidArgumentException('Invalid group message plan or ID');
        return $this->store->transaction(function () use ($group,$id,$message,$stanzaType,$mediaType,$plan):BinaryNode {
            $sender = $plan['sender']; $membershipKey = hash('sha256', $group."\0".$sender);
            $previous = $this->store->get('group-membership', $membershipKey);
            if ($previous !== null && !hash_equals($previous, $plan['fingerprint'])) $this->senderKeys->rotate($group, $sender);
            $distribution = $this->senderKeys->createDistribution($group, $sender);
            $keyId = SenderKeyCodec::readDistribution($distribution)['id'];
            $token = hash('sha256', $group."\0".$sender."\0".$keyId);
            $distributionMessage = MessageCodec::bytes(2, MessageCodec::bytes(1, $group).MessageCodec::bytes(2, $distribution));
            $participants = []; $pending = []; $needsIdentity = false;
            foreach ($plan['devices'] as $device) {
                $deviceKey = hash('sha256', $membershipKey."\0".(string)Jid::parse($device)->canonical());
                if ($this->store->get('group-distributed', $deviceKey) === $token) continue;
                $encrypted = $this->signal->encrypt($this->sessionAddress($device), MessageCodec::pad($distributionMessage));
                $needsIdentity = $needsIdentity || $encrypted['type'] === 'pkmsg';
                $participants[] = new BinaryNode('to', ['jid'=>$device], [new BinaryNode('enc', ['v'=>'2','type'=>$encrypted['type']], $encrypted['ciphertext'])]);
                $pending[] = $deviceKey;
            }
            $children = [];
            if ($participants !== []) $children[] = new BinaryNode('participants', [], $participants);
            $attrs = ['v'=>'2','type'=>'skmsg'];
            if ($mediaType !== null) $attrs['mediatype'] = $mediaType;
            $children[] = new BinaryNode('enc', $attrs, $this->senderKeys->encrypt($group, $sender, MessageCodec::pad($message)));
            $credentials=($this->getCredentials)();
            if ($needsIdentity && self::needsDeviceIdentity($credentials)) $children[] = new BinaryNode('device-identity', [], self::deviceIdentity($credentials));
            $this->store->set('group-membership', $membershipKey, $plan['fingerprint']);
            $this->store->set('group-distribution-pending', $id, json_encode(['token'=>$token,'devices'=>$pending], JSON_THROW_ON_ERROR));
            $phashDevices = array_values(array_filter($plan['devices'], static fn(string $device):bool => !in_array(Jid::parse($device)->server, ['hosted','hosted.lid'], true)));
            return new BinaryNode('message', ['id'=>$id,'to'=>$group,'type'=>$stanzaType,'addressing_mode'=>$plan['mode'], 'phash'=>self::phash([...$phashDevices,$sender])], $children);
        });
    }

    /** Record distribution only after this exact persisted message is server-acknowledged. */
    public function acknowledge(string $id): void
    {
        $this->store->transaction(function () use ($id):void {
            $bytes = $this->store->get('group-distribution-pending', $id);
            if ($bytes === null) return;
            $pending = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($pending) || !is_string($pending['token'] ?? null) || !is_array($pending['devices'] ?? null)) throw new \UnexpectedValueException('Invalid pending group distribution');
            foreach ($pending['devices'] as $key) {
                if (!is_string($key)) throw new \UnexpectedValueException('Invalid pending group device');
                $this->store->set('group-distributed', $key, $pending['token']);
            }
            $this->store->delete('group-distribution-pending', $id);
        });
    }

    /** A peer that lost its session must receive the current sender-key distribution again. */
    public function forgetDistribution(string $group,string $sender,string $device):void
    {
        $membershipKey=hash('sha256',self::group($group)."\0".$sender);
        $deviceKey=hash('sha256',$membershipKey."\0".(string)Jid::parse($device)->canonical());
        $this->store->delete('group-distributed',$deviceKey);
    }

    /** Returns an unpadded authenticated Message, or null for a distribution-only message. */
    public function processIncoming(BinaryNode $node, ?string $directSessionJid = null): ?string
    {
        $group = self::group($node->attrs['from'] ?? '');
        $sender = (string)Jid::parse($node->attrs['participant'] ?? '')->canonical();
        if (!in_array(Jid::parse($sender)->server, ['s.whatsapp.net','lid'], true)) throw new \UnexpectedValueException('Invalid group message participant');
        return $this->store->transaction(function () use ($group,$sender,$node,$directSessionJid):?string {
            $directContent = null; $skmsg = null; $found = false;
            foreach (self::children($node, 'enc') as $enc) {
                $type = $enc->attrs['type'] ?? '';
                if (!is_string($enc->content)) throw new \UnexpectedValueException('Group ciphertext is missing');
                if ($type === 'skmsg') {
                    if ($skmsg !== null) throw new \UnexpectedValueException('Duplicate group sender ciphertext');
                    $skmsg = $enc->content; $found = true;
                } elseif (in_array($type, ['msg','pkmsg'], true)) {
                    $body = MessageCodec::unpad($this->signal->decrypt($directSessionJid ?? $this->sessionAddress($sender), $type, $enc->content));
                    if (!$this->consumeDistribution($sender, $body, $group)) $directContent = $body;
                    $found = true;
                }
            }
            if ($skmsg !== null) return MessageCodec::unpad($this->senderKeys->decrypt($group, $sender, $skmsg));
            if (!$found) throw new \UnexpectedValueException('No supported encrypted group payload');
            return $directContent;
        });
    }

    /** Consume a Message.senderKeyDistributionMessage after direct Signal authentication. */
    public function consumeDistribution(string $sender, string $message, ?string $expectedGroup = null, int $depth = 0): bool
    {
        if ($depth > 8) throw new \LengthException('Sender key wrapper nesting exceeded');
        $fields = Protobuf::decode($message, 16777216, 10000);
        $distribution = self::field($fields, 2);
        if ($distribution !== null) {
            $inner = Protobuf::decode($distribution, 4096, 100);
            $group = self::group(self::field($inner, 1) ?? '');
            $bytes = self::field($inner, 2) ?? throw new \UnexpectedValueException('Sender key distribution payload missing');
            if ($expectedGroup !== null && $group !== self::group($expectedGroup)) throw new \UnexpectedValueException('Sender key distribution belongs to another group');
            $this->senderKeys->processDistribution($group, $sender, $bytes);
            // Content alongside a distribution must still reach the application.
            foreach ($fields as $field) if (!in_array($field['number'], [2,35], true)) return false;
            return true;
        }
        foreach ([31,37,40,53,55,58,59,62] as $number) {
            $wrapper = self::field($fields, $number);
            if ($wrapper === null) continue;
            $inner = Protobuf::decode($wrapper, 16777216, 10000);
            $body = self::field($inner, $number === 31 ? 2 : 1);
            if ($body !== null) return $this->consumeDistribution($sender, $body, $expectedGroup, $depth+1);
        }
        return false;
    }

    /** @param list<string> $devices */
    public static function phash(array $devices): string
    {
        if ($devices === []) return '2:';
        $canonical = [];
        foreach ($devices as $device) {
            $jid = Jid::parse($device);
            $user = str_ends_with($jid->user, '.0') ? substr($jid->user, 0, -2) : $jid->user;
            $canonical[] = $user.'.0:'.$jid->device.'@'.($jid->server === 'c.us' ? 's.whatsapp.net' : $jid->server);
        }
        sort($canonical, SORT_STRING);
        return '2:'.base64_encode(substr(hash('sha256', implode('', $canonical), true), 0, 6));
    }

    private function sessionAddress(string $jid): string
    {
        return $this->sessionJid === null ? (string)Jid::parse($jid)->canonical() : ($this->sessionJid)($jid);
    }
    private static function group(string $jid): string
    {
        $address = Jid::parse($jid)->canonical();
        if (!in_array($address->server,['g.us','broadcast'],true) || $address->device !== 0) throw new \InvalidArgumentException('Sender-key transport requires a group or broadcast JID');
        return $address->bare();
    }
    /** @param array<string,mixed> $credentials */
    private static function deviceIdentity(array $credentials): string
    {
        $identity = $credentials['signedIdentity'] ?? null;
        if (!is_array($identity)) throw new \LogicException('Paired signed device identity is missing');
        $bytes = '';
        foreach ([1=>'details',2=>'accountSignatureKey',3=>'accountSignature',4=>'deviceSignature'] as $number=>$name) {
            if (!is_string($identity[$name] ?? null)) throw new \UnexpectedValueException('Incomplete paired device identity');
            $bytes .= MessageCodec::bytes($number, $identity[$name]);
        }
        return $bytes;
    }
    /** @param array<string,mixed> $credentials */
    private static function needsDeviceIdentity(array $credentials):bool
    {
        return is_array($credentials['signedIdentity'] ?? null) || ($credentials['role'] ?? '')!=='primary' ||
            !is_string($credentials['meJid'] ?? null) || Jid::parse($credentials['meJid'])->device!==0;
    }
    /** @return list<BinaryNode> */
    private static function children(BinaryNode $node, string $tag): array
    {
        return is_array($node->content) ? array_values(array_filter($node->content, static fn(BinaryNode $child):bool => $child->tag === $tag)) : [];
    }
    /** @param list<array{number:int,wire:int,value:int|string}> $fields */
    private static function field(array $fields, int $number): ?string
    {
        $value = null;
        foreach ($fields as $field) if ($field['number'] === $number) {
            if ($value !== null || $field['wire'] !== 2 || !is_string($field['value'])) throw new \UnexpectedValueException('Invalid sender key distribution message field');
            $value = $field['value'];
        }
        return $value;
    }
}
