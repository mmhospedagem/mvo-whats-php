<?php
declare(strict_types=1);
namespace MVO\Voip;

use MVO\Message\MessageCodec;
use MVO\Protocol\{BinaryNode,Jid,SchemaCodec};

/** Native call signaling. Receiving an offer never automatically accepts or opens media sockets. */
final class VoipService
{
    /** @var array<string,array<string,mixed>> */
    private array $calls=[];
    /** @var array<string,string> */
    private array $keys=[];
    /** A callee may authenticate a distinct sending key in its accept.
     * @var array<string,string> */
    private array $peerKeys=[];
    /** @var array<string,list<array<string,mixed>>> */
    private array $relays=[];
    /** @var array<string,MediaSession> */
    private array $media=[];
    /** @var array<string,GroupCallState> */
    private array $groups=[];
    /** @var array<string,GroupMediaSession> */
    private array $groupMedia=[];
    /** @var array<string,RelayPool> */
    private array $relayPools=[];
    /** @var array<string,string> */
    private array $relayModes=[];
    /** @var array<string,true> */
    private array $relayDirty=[];
    /** @var array<string,int> */
    private array $keyEpoch=[];
    /** @var array<string,int> */
    private array $minimumKeyEpoch=[];
    /** @var array<string,array<string,true>> */
    private array $revokedKeys=[];
    /** @var array<string,array<string,mixed>> */
    private array $rekeyJobs=[];
    /** @var array<string,list<array{sender:string,creator:string,key:string,transaction:int,expires:float}>> */
    private array $pendingKeys=[];
    /** @var array<string,array<string,mixed>> */
    private array $reactions=[];
    /** @var array<string,int> */
    private array $appDataCounters=[];
    /** @var array<string,int> */
    private array $appDataTimestamps=[];
    /** @var array<string,true> */
    private array $seenAppData=[];
    /** @var array<string,array{callId:string,node:BinaryNode,attempts:int,next:float,expires:float,active:bool,remember:bool,awaiting:bool}> */
    private array $signals=[];
    /** @var array<string,string> stanza ID to deferred job. Only call stanzas expect call ACKs. */
    private array $signalIds=[];
    /** @var array<string,true> */
    private array $signalSent=[];
    /** @var array<string,true> */
    private array $signalAbandoned=[];
    /** @var array<string,list<string>> */
    private array $destinations=[];
    /** @var array<string,array<string,string>> last attempted destination composition per relay name. */
    private array $relayReportTargets=[];
    /** @var array<string,array{attempts:int,next:float}> */
    private array $resolveJobs=[];
    /** @var array<string,float> */
    private array $heartbeatDue=[];
    /** @var array<string,array{trigger:string,since:float,deadline:float,readyAt:?float}> */
    private array $videoHolds=[];
    /** @var array<string,array{creator:string,from:string,expires:float}> */
    private array $earlyTerminates=[];
    /** Authenticated media last heard per group device; relay traffic is never evidence.
     * @var array<string,array<string,float>> */
    private array $groupLastMedia=[];
    /** @var array<string,array<string,float>> */
    private array $groupWaitingSince=[];
    /** @var array<string,array<string,true>> */
    private array $groupInvisible=[];
    /** @var array<string,float> */
    private array $groupSweepDue=[];
    private bool $drainingSignals=false;
    /**
     * @param \Closure():array<string,mixed> $getCredentials
     *
     * @param \Closure(BinaryNode,float):BinaryNode $query
     *
     * @param \Closure(BinaryNode):void $sendNode
     *
     * @param \Closure(string):list<string> $resolveDevices
     *
     * @param \Closure(list<string>,string):array{nodes:list<BinaryNode>,deviceIdentity:?string} $encryptForDevices
     *
     * @param \Closure(string,string,string):string $decryptCallKey
     *
     * @param \Closure(string,array<string,mixed>):void $emit
     *
     * @param \Closure():float|null $clock
     * @param \Closure(array<string,mixed>):RelayTransport|null $relayFactory */
    public function __construct(private readonly \Closure $getCredentials,private readonly \Closure $query,private readonly \Closure $sendNode,private readonly \Closure $resolveDevices,private readonly \Closure $encryptForDevices,private readonly \Closure $decryptCallKey,private readonly \Closure $emit,private readonly ?\Closure $clock=null,private readonly ?\Closure $relayFactory=null) {}
    /**
     * @return array<string,mixed> */
    public function offer(string $peer,bool $video=false):array
    {
        $peer=(string)Jid::recipient($peer)->canonical();if(!in_array(Jid::parse($peer)->server,['s.whatsapp.net','lid'],true))throw new \InvalidArgumentException('Native call offers currently require a user JID');$this->capacity();$me=$this->ownDevice();$id=strtoupper(bin2hex(random_bytes(16)));$key=random_bytes(32);
        $devices=$this->offerDevices($peer);
        $peer=Jid::parse($devices[0])->canonical()->bare();foreach($devices as $device)if(!self::sameUser($device,$peer))throw new \UnexpectedValueException('Call device resolution mixed different accounts');
        $encrypted=($this->encryptForDevices)($devices,MessageCodec::pad(SchemaCodec::encode('Message',['call'=>['callKey'=>$key]])));
        if(count($encrypted['nodes'])!==count($devices))throw new \UnexpectedValueException('Call key encryption omitted a device');
        $children=[new BinaryNode('audio',['enc'=>'opus','rate'=>'8000']),new BinaryNode('audio',['enc'=>'opus','rate'=>'16000'])];if($video)$children[]=new BinaryNode('video',['enc'=>'h.264','dec'=>'H264','screen_width'=>'1920','screen_height'=>'1080','device_orientation'=>'0']);
        $children[] = new BinaryNode('net',['medium'=>'3']);$children[]=new BinaryNode('capability',['ver'=>'1'],hex2bin($video?'0105f709e0fa03':'0105f709e4bb07'));$children[]=new BinaryNode('destination',[],$encrypted['nodes']);$children[]=new BinaryNode('encopt',['keygen'=>'2']);if($encrypted['deviceIdentity']!==null)$children[]=new BinaryNode('device-identity',[],$encrypted['deviceIdentity']);
        $call=['callId'=>$id,'peerJid'=>$peer,'callCreator'=>$me,'direction'=>'outgoing','video'=>$video,'state'=>'offering','createdAt'=>$this->now(),'group'=>false];$this->calls[$id]=$call;$this->keys[$id]=$key;
        try{$ack=$this->submit($this->stanza($peer,'offer',$id,$me,[],$children));$this->applySettings($id,$ack);$this->relays[$id]=RelayDescriptor::parse($ack);$this->destinations[$id]=array_values(array_unique([...array_map([GroupCallState::class,'device'],$devices),...self::relayParticipants($ack)]));if($this->calls[$id]['state']==='offering')$this->calls[$id]['state']='offered';$this->queuePreaccept($id);$this->state($id);return $this->calls[$id];}
        catch(\Throwable $error){$this->end($id,'setup_failed');throw $error;}
    }
    public function accept(string $id):void
    {
        $call=$this->requireCall($id);if($call['direction']!=='incoming' || $call['state']!=='ringing')throw new \LogicException('Call is not awaiting acceptance');if($call['group'] && (!isset($this->groups[$id]) || !$this->groups[$id]->hasDevice($this->ownDevice())))throw new \LogicException('Group call roster is unavailable for this account');
        $children=[new BinaryNode('audio',['enc'=>'opus','rate'=>'16000']),new BinaryNode('net',['medium'=>'3'])];if($call['video'])$children[]=new BinaryNode('video',['enc'=>'h.264','dec'=>'H264']);
        if($call['group'])$children[]=new BinaryNode('encopt',['keygen'=>'2']);
        // Arm acceptance before yielding to a query: peer announcements may arrive inside it.
        $this->calls[$id]['state']='connecting';$this->calls[$id]['acceptedAt']=$this->now();if($call['video'])$this->holdVideo($id,'born-video');
        $this->queueTransport($id,$call['peerJid'],'1','1');$this->drainSignals();try{$ack=$this->submit($this->stanza($call['group']?$id.'@call':$call['peerJid'],'accept',$id,$call['callCreator'],[],$children));}catch(\Throwable $error){$this->end($id,'accept_failed');throw $error;}if($this->calls[$id]['state']==='ended')return;$this->applySettings($id,$ack);$relays=RelayDescriptor::parse($ack);if($relays!==[])$this->relays[$id]=$relays;$this->rememberParticipants($id,$ack);$this->applyPendingKeys($id);if($call['group']){$this->heartbeatDue[$id]=$this->now();if(isset($call['rekeyTransactionRequested']))$this->queueRekey($id,$call['rekeyTransactionRequested']);}$this->state($id);
    }
    public function reject(string $id):void { $call=$this->requireCall($id);if($call['direction']!=='incoming' || $call['state']!=='ringing')throw new \LogicException('Call is not ringing');$this->submit($this->stanza($call['group']?$id.'@call':$call['peerJid'],'reject',$id,$call['callCreator'],$call['group']?['count'=>'0']:[]));$this->end($id,'rejected'); }
    public function terminate(string $id,string $reason='user_ended'):void {
        $call=$this->requireCall($id);if($call['state']==='ended')return;$attrs=[];
        if(isset($call['connectedAt'])){$duration=(int)(($this->now()-$call['connectedAt'])*1000);$attrs=['duration'=>(string)$duration,'audio_duration'=>(string)$duration];}
        if($reason!=='user_ended')$attrs['reason']=$reason;
        $node=$this->stanza($call['group']?$id.'@call':$call['peerJid'],'terminate',$id,$call['callCreator'],$attrs);
        $this->end($id,$reason);
        // Termination is a single write, not an acknowledged call transaction.
        ($this->sendNode)($node);
    }
    public function setMuted(string $id,bool $muted):void { $call=$this->requireCall($id);if(!in_array($call['state'],['connecting','connected'],true))throw new \LogicException('Call has not been accepted');$this->calls[$id]['audioMuted']=$muted;$this->cancelOwnStateSignals($id,'mute_v2');($this->media[$id]??null)?->setAudioEnabled(!$muted);($this->groupMedia[$id]??null)?->setAudioEnabled(!$muted);$this->submit($this->stanza($call['group']?$id.'@call':$call['peerJid'],'mute_v2',$id,$call['callCreator'],['mute-state'=>$muted?'1':'0']));$this->state($id); }
    public function preaccept(string $id):void
    {
        $call=$this->requireCall($id);if($call['state']==='ended')throw new \LogicException('Call has ended');$this->queuePreaccept($id);$this->drainSignals();
    }
    public function announceTransport(string $id,string $messageType='0',string $candidateRound='0'):void
    {
        $call=$this->requireCall($id);if(!in_array($call['state'],['connecting','connected'],true) || $call['group'])throw new \LogicException('Transport announcement requires an accepted one-to-one call');
        $this->queueTransport($id,$call['peerJid'],$messageType,$candidateRound);$this->drainSignals();
    }
    /** Uses measured latency values, never invented connectivity evidence.
     * @param list<array{relayName:string,latencyMs:int,addressBytes?:string}> $relays */
    public function reportRelayLatency(string $id,array $relays):void
    {
        $call=$this->requireCall($id);if(!in_array($call['state'],['connecting','connected'],true) || $call['group'])throw new \LogicException('Relay report requires an accepted one-to-one call');$children=[];$seen=[];
        foreach($relays as $relay){if($relay['relayName']==='' || isset($seen[$relay['relayName']]))continue;if($relay['latencyMs']<0 || $relay['latencyMs']>0xffffff)throw new \InvalidArgumentException('Invalid measured relay latency');$seen[$relay['relayName']]=true;$children[]=new BinaryNode('te',['latency'=>(string)(0x2000000+$relay['latencyMs']),'relay_name'=>$relay['relayName']],$relay['addressBytes']??null);}
        if($children===[])throw new \InvalidArgumentException('No relay measurements to report');$targets=$this->destinations[$id]??[GroupCallState::device($call['peerJid'])];$children[]=new BinaryNode('destination',[],array_map(static fn($jid)=>new BinaryNode('to',['jid'=>$jid]),$targets));$this->submit($this->stanza(Jid::parse($call['peerJid'])->bare(),'relaylatency',$id,$call['callCreator'],[],$children));
    }
    /** The offer carries no call key; the server's group_info/rekey chooses its subsequent distributor.
     * @param list<string|array<string,mixed>> $participants
     * @return array<string,mixed> */
    public function offerGroup(string $groupJid,array $participants,bool $video=false):array
    {
        $group=Jid::recipient($groupJid)->canonical();if($group->server!=='g.us' || $participants===[])throw new \InvalidArgumentException('Group call requires a group JID and participants');$this->capacity();$own=$this->ownDevice();$id=strtoupper(bin2hex(random_bytes(16)));$records=[['jid'=>GroupCallState::account($own),'devices'=>[GroupCallState::device($own)],'state'=>null]];$seen=[GroupCallState::account($own)=>true];
        foreach($participants as $input){$account=is_string($input)?$input:($input['jid']??null);if(!is_string($account))throw new \InvalidArgumentException('Group call participant requires jid');$devices=is_array($input)?($input['devices']??[]):[];if($devices===[])$devices=$this->offerDevices($account);$account=GroupCallState::account($devices[0]);if(isset($seen[$account]))continue;$seen[$account]=true;$records[]=['jid'=>$account,'devices'=>$devices,'state'=>null];}
        if(count($records)<2 || count($records)>256)throw new \InvalidArgumentException('Group call needs at least one other participant and at most 256');$roster=new GroupCallState($id,$records);$users=[];
        foreach($roster->participants() as $index=>$record){$devices=[];foreach($record['devices'] as $device)$devices[]=new BinaryNode('device',['jid'=>$device],$index===0?[new BinaryNode('capability',['ver'=>'1'],hex2bin($video?'0105f709e0fa03':'0105f709e4bb07'))]:null);$users[]=new BinaryNode('user',['jid'=>$record['jid']],$devices);}
        $children=[new BinaryNode('audio',['enc'=>'opus','rate'=>'8000']),new BinaryNode('audio',['enc'=>'opus','rate'=>'16000'])];if($video)$children[]=new BinaryNode('video',['enc'=>'h.264','dec'=>'H264','screen_width'=>'1920','screen_height'=>'1080','device_orientation'=>'0']);$children[]=new BinaryNode('net',['medium'=>'3']);$children[]=new BinaryNode('group_info',[],$users);
        $this->groups[$id]=$roster;$this->calls[$id]=['callId'=>$id,'peerJid'=>$records[1]['jid'],'callCreator'=>$own,'direction'=>'outgoing','video'=>$video,'state'=>'offering','createdAt'=>$this->now(),'group'=>true,'groupJid'=>$group->bare(),'joinable'=>true];
        try{$ack=$this->submit($this->stanza($id.'@call','offer',$id,$own,['group-jid'=>$group->bare()],$children));$this->applySettings($id,$ack);$this->relays[$id]=RelayDescriptor::parse($ack);$this->destinations[$id]=array_values(array_unique([...$roster->devices(),...self::relayParticipants($ack)]));if($this->calls[$id]['state']==='offering')$this->calls[$id]['state']='offered';$this->state($id);return $this->calls[$id];}catch(\Throwable $e){$this->calls[$id]['joinable']=false;$this->end($id,'setup_failed');throw $e;}
    }
    public function join(string $id):void
    {
        $call=$this->requireCall($id);if(!$call['group'] || !($call['joinable']??false) || $call['state']!=='ended' || !isset($this->groups[$id]))throw new \LogicException('No live joinable group call is available');
        // Joining is an accept addressed to the call; the server decides membership in the next roster.
        $children=[new BinaryNode('audio',['enc'=>'opus','rate'=>'16000']),new BinaryNode('net',['medium'=>'3']),new BinaryNode('encopt',['keygen'=>'2'])];if($call['video'])$children[]=new BinaryNode('video',['enc'=>'h.264','dec'=>'H264']);$this->calls[$id]['state']='connecting';$this->calls[$id]['acceptedAt']=$this->now();$this->queueTransport($id,$call['peerJid'],'1','1');$this->drainSignals();try{$ack=$this->submit($this->stanza($id.'@call','accept',$id,$call['callCreator'],[],$children));}catch(\Throwable $error){$this->end($id,'join_failed');throw $error;}if($this->calls[$id]['state']==='ended')return;$this->applySettings($id,$ack);$relays=RelayDescriptor::parse($ack);if($relays!==[])$this->relays[$id]=$relays;$this->rememberParticipants($id,$ack);$this->applyPendingKeys($id);$this->heartbeatDue[$id]=$this->now();unset($this->calls[$id]['endedAt'],$this->calls[$id]['endReason']);$this->state($id);
    }
    public function leave(string $id):void { if(!$this->requireCall($id)['group'])throw new \LogicException('leave() is for group calls');$this->terminate($id,'user_ended'); }
    /** Asks a participant to mute; only their subsequent announcement changes their microphone state. */
    public function requestParticipantMute(string $id,string $device):void { $call=$this->accepted($id);if(!$call['group'] || !isset($this->groups[$id]) || !$this->groups[$id]->hasDevice($device) || $this->isOwn($device))throw new \InvalidArgumentException('Target must be another current group participant');$this->submit($this->stanza(GroupCallState::device($device),'mute_v2',$id,$call['callCreator'],['request-state'=>'1'])); }
    /** @return list<array<string,mixed>> */
    public function participants(string $id):array { if(!isset($this->groups[$id]))throw new \InvalidArgumentException('Unknown group call');return $this->groups[$id]->participants(); }
    public function groupMedia(string $id):GroupMediaSession
    {
        $call=$this->requireCall($id);if(!$call['group'] || !in_array($call['state'],['connecting','connected'],true) || !isset($this->groups[$id]))throw new \LogicException('An accepted group call is required');
        if(!isset($this->groupMedia[$id])){$session=new GroupMediaSession($id,$this->ownDevice(),$this->groups[$id],function($event,$data)use($id){$this->mediaEvent($id,$event,$data);},$this->clock);if(isset($call['voipSettings']))$session->setVoipSettings($call['voipSettings']);$session->setVideoCoverage($call['video']);$session->setAudioEnabled(!($call['audioMuted']??false));$session->setVideoEnabled(!isset($this->videoHolds[$id]) && (($call['videoEnabled']??false) || ($call['screenSharing']??false)));foreach($call['participantVideoState']??[] as $device=>$state)$session->setParticipantVideoState($device,$state===1);if(isset($this->keys[$id],$this->keyEpoch[$id]))$session->rotateKey($this->keys[$id],$this->keyEpoch[$id]);$this->groupMedia[$id]=$session;}return $this->groupMedia[$id];
    }
    /** Explicitly opens real media transport; signaling acceptance alone opens no sockets. */
    public function connectMedia(string $id,?string $transport=null):MediaSession|GroupMediaSession
    {
        $call=$this->accepted($id);$credentials=($this->getCredentials)();if($transport===null && ($credentials['role']??'companion')==='primary')throw new \InvalidArgumentException('Choose raw-udp or webrtc explicitly for a primary account');$transport??='webrtc';if(!in_array($transport,['webrtc','raw-udp'],true))throw new \InvalidArgumentException('Call transport must be webrtc or raw-udp');$session=$call['group']?$this->groupMedia($id):$this->media($id);if($session->hasTransport())throw new \LogicException('Call media transport already configured');$descriptors=$this->mediaDescriptors($id,$transport);if($descriptors===[])throw new \LogicException('No relay descriptors have been delivered for this call');$pool=new RelayPool($descriptors,$this->relayFactory,$this->clock,function(string $event,array $data)use($id):void{($this->emit)($event,['callId'=>$id,...$data]);if($event==='voip_transport_lost' && ($this->calls[$id]['state']??'ended')!=='ended')$this->end($id,'transport_failed');});
        try{$session->connectTransport($pool);$session->setMediaFlowing();}catch(\Throwable $error){$pool->close();throw $error;}$this->relayPools[$id]=$pool;$this->relayModes[$id]=$transport;return $session;
    }
    /** @param list<array{participantJid:string,quality?:int}>|null $subscriptions */
    public function setVideoSubscriptions(string $id,?array $subscriptions):void { $this->groupMedia($id)->setVideoSubscriptions($subscriptions); }
    public function requestVideoUpgrade(string $id):void
    {
        $call=$this->accepted($id);if($call['video']){$this->setVideoEnabled($id,true);return;}
        if(($call['videoUpgrade']['state']??null)==='pending'){if(!$call['group'] && $call['videoUpgrade']['direction']==='incoming')$this->acceptVideoUpgrade($id);return;}
        $this->calls[$id]['videoUpgrade']=['direction'=>'outgoing','deadline'=>$this->now()+5.0,'state'=>'pending'];
        try{$this->sendVideoState($id,$call['group']?3:11);}catch(\Throwable $error){if($this->calls[$id]['videoUpgrade']['state']==='pending')$this->calls[$id]['videoUpgrade']['state']='error';throw $error;}
    }
    public function acceptVideoUpgrade(string $id):void
    {
        $call=$this->accepted($id);if($call['group'])throw new \LogicException('Group video upgrades are granted by the call server');if(($call['videoUpgrade']['direction']??null)!=='incoming' || ($call['videoUpgrade']['state']??null)!=='pending')throw new \LogicException('No incoming video upgrade request');
        $this->sendVideoState($id,4);if($this->calls[$id]['state']==='ended')return;$this->holdVideo($id,'upgrade');$this->calls[$id]['video']=true;$this->calls[$id]['videoEnabled']=true;$this->calls[$id]['videoUpgrade']['state']='accepted';$this->applyVideoSend($id);$this->queueVideoState($id,1,'upgrade-enabled');$this->state($id);
    }
    public function rejectVideoUpgrade(string $id):void { $call=$this->accepted($id);if($call['group'] || ($call['videoUpgrade']['direction']??null)!=='incoming' || ($call['videoUpgrade']['state']??null)!=='pending')throw new \LogicException('No incoming one-to-one video upgrade request');$this->sendVideoState($id,5);$this->calls[$id]['videoUpgrade']['state']='rejected';$this->state($id); }
    public function cancelVideoUpgrade(string $id):void { $call=$this->accepted($id);if(($call['videoUpgrade']['direction']??null)!=='outgoing' || ($call['videoUpgrade']['state']??null)!=='pending')return;$this->calls[$id]['videoUpgrade']['state']='cancelled';$this->sendVideoState($id,8);$this->state($id); }
    public function setVideoEnabled(string $id,bool $enabled):void { $call=$this->accepted($id);if(!$call['video'] && $enabled)throw new \LogicException('Negotiate video before enabling its stream');$this->calls[$id]['videoEnabled']=$enabled;$this->cancelOwnStateSignals($id,'video');$this->applyVideoSend($id);$this->sendVideoState($id,$enabled?1:6);$this->state($id); }
    public function setScreenSharing(string $id,bool $started):void
    {
        $call=$this->accepted($id);if(!$call['video'] && $started)throw new \LogicException('Screen sharing requires negotiated video');$counter=($call['screenShareId']??0)+1;$this->calls[$id]['screenShareId']=$counter;$this->calls[$id]['screenSharing']=$started;if($started)$this->calls[$id]['videoEnabled']=false;$this->applyVideoSend($id);$this->submit($this->stanza($call['group']?$id.'@call':$call['peerJid'],'screen_share',$id,$call['callCreator'],['screenshare_state'=>$started?'1':'2','version'=>'2','screen_share_id'=>(string)$counter]));$this->state($id);
    }
    public function setHandRaised(string $id,bool $raised):void { $call=$this->accepted($id);$this->calls[$id]['handRaised']=$raised;$this->cancelOwnStateSignals($id,'user_action');$this->submit($this->stanza($call['group']?$id.'@call':$call['peerJid'],'user_action',$id,$call['callCreator'],['action'=>'raise_hand',...($call['group']?['broadcast'=>'1']:[])],[new BinaryNode('raise_hand',['raise-hand-state'=>$raised?'1':'0'])]));$this->state($id); }
    public function sendReaction(string $id,string $emoji):void
    {
        $call=$this->accepted($id);if(self::appDataSframe($call))throw new \LogicException('SFrame app-data is not implemented');$counter=($this->appDataCounters[$id]??0)+1;if($counter>=PHP_INT_MAX)throw new \OverflowException('App-data transaction counter exhausted');$this->appDataCounters[$id]=$counter;$payload=AppDataProtocol::reaction($counter,$emoji);$now=$this->now();$this->appDataTimestamps[$id]??=random_int(0,0xffffffff);$this->reactions[$id]=['payload'=>$payload,'expires'=>$now+0.6,'next'=>$now+0.06,'timestamp'=>$this->appDataTimestamps[$id]];$this->sendAppData($id,$payload,$this->reactions[$id]['timestamp']);
    }
    public function sendArEffect(string $id,string $effect):void { $this->accepted($id);$counter=($this->appDataCounters[$id]??0)+1;if($counter>=PHP_INT_MAX)throw new \OverflowException('App-data transaction counter exhausted');$this->appDataCounters[$id]=$counter;$this->appDataTimestamps[$id]??=random_int(0,0xffffffff);$this->sendAppData($id,AppDataProtocol::arEffect($counter,$effect),$this->appDataTimestamps[$id]); }
    public function sendAppData(string $id,string $payload,?int $timestamp=null):void { $call=$this->accepted($id);if(self::appDataSframe($call))throw new \LogicException('SFrame app-data is not implemented');if($payload==='' || strlen($payload)>65536)throw new \InvalidArgumentException('Invalid app-data payload');$session=$call['group']?$this->groupMedia($id):$this->media($id);$session->sendPayload($payload,$timestamp??random_int(0,0xffffffff),119,6); }
    /** Incoming encrypted call keys are decrypted locally by the injected Signal bridge. */
    public function handleNode(BinaryNode $node):bool
    {
        if($node->tag==='ack' && ($node->attrs['class']??'')==='call')return $this->handleSignalAck($node);
        if($node->tag!=='call')return false;$inner=is_array($node->content)?($node->content[0]??null):null;if(!$inner instanceof BinaryNode)return false;
        $from=$node->attrs['from']??'';$id=$inner->attrs['call-id']??'';$creator=$inner->attrs['call-creator']??'';
        if($from==='' || $id==='' || strlen($id)>128 || $creator==='')throw new \UnexpectedValueException('Incomplete incoming call stanza');
        Jid::parse($from);Jid::parse($creator);
        ($this->sendNode)(new BinaryNode('ack',array_filter(['to'=>$from,'id'=>$node->attrs['id']??'','class'=>'call','type'=>$inner->tag],static fn($v)=>$v!=='')));
        if($inner->tag==='enc_rekey'){$this->receiveRekey($node,$inner,$from,$id,$creator);return true;}
        if($inner->tag==='offer') {
            if(isset($this->calls[$id]) && $this->calls[$id]['state']!=='ended')return true;$group=isset($inner->attrs['group-jid']) || isset($inner->attrs['group_jid']) || $inner->child('group_info')!==null;
            if(!self::sameUser($from,$creator) && !($group && $from===$id.'@call'))throw new \UnexpectedValueException('Call offer creator does not match the authenticated sender');
            $early=$this->earlyTerminates[$id]??null;unset($this->earlyTerminates[$id]);if($early!==null && $early['creator']===$creator && $early['expires']>$this->now() && ($inner->attrs['re-ring']??null)!=='1')return true;
            if(isset($this->calls[$id])){$this->forgetEnded($id);}else $this->capacity();
            $key=$group?null:$this->decryptKey($inner,$from);
            if(!$group && $key===null){($this->emit)('voip_error',['callId'=>$id,'error'=>'Call key could not be authenticated']);return true;}
            $groupJid=null;$groupSeed=null;$groupInfo=$inner->child('group_info');if($group){$groupJid=$inner->attrs['group-jid']??$inner->attrs['group_jid']??null;if($groupJid!==null && Jid::parse($groupJid)->server!=='g.us')throw new \UnexpectedValueException('Invalid group call JID');if($groupInfo!==null){$groupSeed=GroupCallState::readRoster($groupInfo);if($from===$id.'@call'){$probe=new GroupCallState($id,$groupSeed);$probe->applyRoster($groupInfo,$from,$this->ownDevice());}}}
            $this->calls[$id]=['callId'=>$id,'peerJid'=>$from,'callCreator'=>$creator,'direction'=>'incoming','video'=>$inner->child('video')!==null,'state'=>'ringing','createdAt'=>$this->now(),'group'=>$group,'joinable'=>self::flag($inner->attrs['joinable']??null)];if($key!==null)$this->keys[$id]=$key;$this->relays[$id]=RelayDescriptor::parse($inner);
            if($group){$this->calls[$id]['groupJid']=$groupJid;$this->calls[$id]['joinable']=self::flag($inner->attrs['joinable']??null);if($groupInfo!==null && $groupSeed!==null){$this->groups[$id]=new GroupCallState($id,$groupSeed);if($from===$id.'@call')$this->updateGroup($id,$groupInfo,$from);$this->calls[$id]['participants']=$this->groups[$id]->participants();}}
            $this->applySettings($id,$node);$this->rememberParticipants($id,$inner);if(!$group){$this->destinations[$id]=array_values(array_unique([...($this->destinations[$id]??[]),GroupCallState::device($from)]));if(Jid::parse($from)->device===0)$this->resolveJobs[$id]=['attempts'=>0,'next'=>$this->now()];}$this->queuePreaccept($id);
            ($this->emit)('voip_incoming_call',$this->calls[$id]);$this->state($id);return true;
        }
        if(!isset($this->calls[$id])){if($inner->tag==='terminate' && !self::flag($inner->attrs['joinable']??null) && ($from===$id.'@call' || self::sameUser($from,$creator))){if(count($this->earlyTerminates)>=64)unset($this->earlyTerminates[array_key_first($this->earlyTerminates)]);$this->earlyTerminates[$id]=['creator'=>$creator,'from'=>$from,'expires'=>$this->now()+30.0];}return true;}$call=$this->calls[$id];
        if($creator!==$call['callCreator'])throw new \UnexpectedValueException('Call creator changed during the session');
        if(!$call['group'] && $inner->tag==='group_update' && $from===$id.'@call' && $inner->child('group_info')!==null){if($call['state']!=='ended')$this->promoteGroup($id,$inner,$from);return true;}
        if(!$call['group'] && $inner->tag==='accept' && $call['direction']==='incoming' && $this->isOwn($from)){if($call['state']==='ringing')$this->end($id,'accepted_elsewhere');return true;}
        if(!$call['group'] && $inner->tag==='terminate' && ($inner->attrs['reason']??'')==='accepted_elsewhere' && $this->isOwn($from)){if($call['direction']==='incoming' && $call['state']==='ringing')$this->end($id,'accepted_elsewhere');return true;}
        if($this->isOwn($from) && in_array($inner->tag,['mute_v2','video','screen_share','screen','user_action','raise_hand'],true))return true;
        if($call['group']){
            $server=$from===$id.'@call';$member=!$server && isset($this->groups[$id]) && $this->groups[$id]->hasDevice($from);
            if(!$server && !$member && !self::sameUser($from,$call['callCreator']))throw new \UnexpectedValueException('Call stanza belongs to another group participant');
            if($inner->tag==='group_update'){if(!$server)throw new \UnexpectedValueException('Group update must originate at the call server');$this->receiveGroupUpdate($id,$inner,$from);return true;}
            if($inner->tag==='terminate' && ($inner->attrs['reason']??'')==='group_call_ended'){if(!$server)throw new \UnexpectedValueException('Only the call server can end the group call globally');$this->calls[$id]['joinable']=false;$this->end($id,'group_call_ended');return true;}
            if(in_array($inner->tag,['terminate','reject'],true)){
                if($server){if(($inner->attrs['reason']??'')==='user_invisible')$this->end($id,'invisible');elseif($call['state']==='ringing' && !isset($inner->attrs['reason']) && self::flag($inner->attrs['joinable']??null))$this->end($id,'rung_out');return true;}
                if($member){$this->groups[$id]->removeDepartedParticipant($from);$this->invalidateGroupKey($id,($this->groups[$id]->transactionId()??0)+1);$this->calls[$id]['participants']=$this->groups[$id]->participants();$this->pruneGroupState($id);($this->emit)('voip_group_participant_left',['callId'=>$id,'participantJid'=>GroupCallState::device($from),'reason'=>$inner->attrs['reason']??$inner->tag]);$this->state($id);}return true;
            }
        }elseif(!self::sameUser($from,$call['peerJid']))throw new \UnexpectedValueException('Call stanza belongs to another peer');
        if($call['state']==='ended')return true;
        $this->applySettings($id,$node);
        if($inner->tag==='accept' && !$call['group'] && $call['direction']==='outgoing' && in_array($call['state'],['offering','offered','ringing'],true)) {
            if($inner->child('enc')!==null){$peerKey=$this->decryptKey($inner,$from);if($peerKey===null){($this->emit)('voip_error',['callId'=>$id,'operation'=>'accept','error'=>'Peer sending key could not be authenticated']);return true;}$this->peerKeys[$id]=$peerKey;}
            $answer=$from;foreach(is_array($inner->content)?$inner->content:[] as $relay)if($relay->tag==='relay')foreach(is_array($relay->content)?$relay->content:[] as $participant)if($participant->tag==='participant' && isset($participant->attrs['jid']) && self::sameUser($participant->attrs['jid'],$call['peerJid'])){$answer=$participant->attrs['jid'];if(isset($participant->attrs['pid']) && ctype_digit($participant->attrs['pid']))foreach($this->relays[$id]??[] as $index=>$descriptor)$this->relays[$id][$index]['peerPid']=(int)$participant->attrs['pid'];break 2;}
            $this->calls[$id]['peerJid']=$answer;$this->calls[$id]['state']='connecting';$this->calls[$id]['acceptedAt']=$this->now();$this->rememberParticipants($id,$inner);foreach($this->destinations[$id]??[] as $device)if(self::sameUser($device,$answer) && GroupCallState::device($device)!==GroupCallState::device($answer))$this->queueSignal($id,'accepted-elsewhere:'.$device,$this->stanza($device,'terminate',$id,$creator,['reason'=>'accepted_elsewhere']),true);$this->queueTransport($id,$answer,'1','1');if(isset($node->attrs['id']))$this->queueSignal($id,'accept-receipt:'.$node->attrs['id'],new BinaryNode('receipt',['to'=>$answer,'id'=>$node->attrs['id'],'from'=>$this->ownDevice()],[new BinaryNode('accept',['call-id'=>$id,'call-creator'=>$creator])]),true);$this->state($id);
        }elseif($inner->tag==='preaccept' && !$call['group'] && $call['direction']==='outgoing'){if(in_array($call['state'],['offered','ringing'],true)){$this->calls[$id]['state']='ringing';$this->state($id);}$this->queueTransport($id,Jid::parse($from)->bare(),'0','0');}
        elseif(in_array($inner->tag,['reject','terminate'],true)){if(!$call['group'] && ($inner->attrs['reason']??'')==='accepted_elsewhere' && in_array($call['state'],['connecting','connected'],true) && GroupCallState::device($from)!==GroupCallState::device($call['peerJid']))return true;$this->end($id,$inner->attrs['reason']??($inner->tag==='reject'?'rejected':'remote_ended'));}
        elseif(in_array($inner->tag,['mute_v2','video','screen_share','screen','user_action','raise_hand'],true))$this->receiveControl($id,$from,$inner);
        elseif(in_array($inner->tag,['transport','relaylatency'],true)){$this->rememberParticipants($id,$inner);$relays=RelayDescriptor::parse($inner);if($relays!==[]){$this->relays[$id]=$relays;if(isset($this->relayPools[$id]))$this->relayDirty[$id]=true;}($this->emit)('voip_transport',['callId'=>$id,'tag'=>$inner->tag,'attrs'=>$inner->attrs]);}
        return true;
    }
    /** Media creation is explicit and cannot establish a call merely from a signaling ACK. */
    public function media(string $id):MediaSession
    {
        $call=$this->requireCall($id);if($call['group'] || !in_array($call['state'],['connecting','connected'],true) || !isset($this->keys[$id]))throw new \LogicException('Accepted one-to-one call is required before creating media');
        if(!isset($this->media[$id])){$session=new MediaSession($id,$this->keys[$id],$this->ownDevice(),$call['peerJid'],function(string $event,array $data)use($id):void{$this->mediaEvent($id,$event,$data);});if(isset($this->peerKeys[$id]))$session->setPeerCallKey($this->peerKeys[$id]);if(isset($call['voipSettings']))$session->setVoipSettings($call['voipSettings']);$session->setAudioEnabled(!($call['audioMuted']??false));$session->setVideoEnabled(!isset($this->videoHolds[$id]) && (($call['videoEnabled']??false) || ($call['screenSharing']??false)));$this->media[$id]=$session;}return $this->media[$id];
    }
    /** Contains relay tokens; callers must treat this result as credential material.
     *
     * @return list<array<string,mixed>> */
    public function relayDescriptors(string $id):array { $this->requireCall($id);return $this->relays[$id]??[]; }
    /**
     * @return array<string,mixed> */
    public function getCall(string $id):array { return $this->requireCall($id); }
    /**
     * @return list<array<string,mixed>> */
    public function getCalls():array { return array_values($this->calls); }
    public function tick(bool $allowQueries=true):void
    {
        $now=$this->now();foreach($this->calls as $id=>$call){if($call['state']==='ended')continue;if(in_array($call['state'],['offered','ringing','connecting'],true) && $now-($call['acceptedAt']??$call['createdAt'])>120){$this->end($id,'timeout');continue;}if(($call['videoUpgrade']['state']??null)==='pending' && $now>=$call['videoUpgrade']['deadline']){$this->calls[$id]['videoUpgrade']['state']='timeout';if(!$call['group'] && $call['videoUpgrade']['direction']==='outgoing')$this->queueVideoState($id,0,'upgrade-timeout');($this->emit)('voip_video_upgrade',['callId'=>$id,'state'=>'timeout']);$this->state($id);}}
        foreach($this->earlyTerminates as $id=>$entry)if($entry['expires']<=$now)unset($this->earlyTerminates[$id]);
        foreach($this->videoHolds as $id=>$hold)if($now>=$hold['deadline'] || ($hold['readyAt']!==null && $now>=$hold['readyAt'])){$reason=$hold['readyAt']!==null && $hold['readyAt']<=$hold['deadline']?'peer-ready':'timeout';unset($this->videoHolds[$id]);$this->calls[$id]['videoSendHeld']=false;$this->applyVideoSend($id);($this->emit)('voip_video_send_released',['callId'=>$id,'trigger'=>$hold['trigger'],'reason'=>$reason,'heldMs'=>max(0,(int)(($now-$hold['since'])*1000))]);}
        foreach($this->pendingKeys as $id=>$items){$this->pendingKeys[$id]=array_values(array_filter($items,static fn($item)=>$item['expires']>$now));if($this->pendingKeys[$id]===[])unset($this->pendingKeys[$id]);}
        foreach($this->calls as $id=>$call)if($call['group'] && in_array($call['state'],['connecting','connected'],true) && $now>=($this->heartbeatDue[$id]??INF)){$this->queueSignal($id,'heartbeat',$this->stanza($id.'@call','heartbeat',$id,$call['callCreator']),true,false);$this->heartbeatDue[$id]=$now+($call['voipSettings']['heartbeatIntervalMs']??10000)/1000;}
        $this->drainPending($allowQueries);foreach([$this->media,$this->groupMedia] as $sessions)foreach($sessions as $id=>$session){try{$session->poll(0);}catch(\Throwable $error){($this->emit)('voip_media_error',['callId'=>$id,'reason'=>'transport_failed','error'=>$error->getMessage()]);if(($this->calls[$id]['state']??'ended')!=='ended')$this->end($id,'transport_failed');}}$this->sweepGroupMedia();$this->queueMeasuredLatencies();$this->drainSignals();
    }
    /** Runs encrypted key distribution outside the incoming-stanza handler, avoiding query reentry. */
    public function drainPending(bool $allowQueries=true):void
    {
        $this->drainSignals();if($allowQueries)$this->resolveDestinations();
        foreach(array_keys($this->relayDirty) as $id){unset($this->relayDirty[$id]);if(!isset($this->relayPools[$id],$this->relayModes[$id]) || ($this->calls[$id]['state']??'ended')==='ended')continue;try{$this->relayPools[$id]->configure($this->mediaDescriptors($id,$this->relayModes[$id]),$this->calls[$id]['relayTransactionId']??null);}catch(\Throwable $error){($this->emit)('voip_media_error',['callId'=>$id,'reason'=>'relay_reissue_failed','error'=>$error->getMessage()]);$this->end($id,'transport_failed');}}
        $rekeyBudget=64;if($allowQueries)foreach(array_keys($this->rekeyJobs) as $id){if(!isset($this->calls[$id],$this->groups[$id])){unset($this->rekeyJobs[$id]);continue;}$job=&$this->rekeyJobs[$id];$call=$this->calls[$id];if(!in_array($call['state'],['connecting','connected'],true) || $job['transaction']!==$this->groups[$id]->transactionId() || ($job['expires']??INF)<=$this->now()){unset($this->rekeyJobs[$id]);unset($job);continue;}if(($job['retryAt']??0)>$this->now()){unset($job);continue;}
            try{
                if(!isset($job['key'])){$job['key']=random_bytes(32);$job['pending']=[];foreach($this->groups[$id]->devices(true) as $device)if(!$this->isOwn($device))$job['pending'][$device]=null;$this->installGroupKey($id,$job['key'],$job['transaction']);}
                foreach(array_keys($job['pending']) as $device){if(!$this->groups[$id]->hasDevice($device)){unset($job['pending'][$device]);continue;}if($rekeyBudget--<=0 || count($this->signals)>=1024)break;$node=$job['pending'][$device];if(!$node instanceof BinaryNode){$encrypted=($this->encryptForDevices)([$device],MessageCodec::pad(SchemaCodec::encode('Message',['call'=>['callKey'=>$job['key']]])));if(count($encrypted['nodes'])!==1 || GroupCallState::device($encrypted['nodes'][0]->attrs['jid']??'')!==$device)throw new \UnexpectedValueException('Signal bridge returned a different rekey target');$enc=$encrypted['nodes'][0]->child('enc');if($enc===null)throw new \UnexpectedValueException('Signal bridge omitted rekey ciphertext');$children=[new BinaryNode('encopt',['keygen'=>'2']),$enc];if($encrypted['deviceIdentity']!==null)$children[]=new BinaryNode('device-identity',[],$encrypted['deviceIdentity']);$node=$this->stanza($device,'enc_rekey',$id,$call['callCreator'],['transaction-id'=>(string)$job['transaction']],$children);$job['pending'][$device]=$node;}$this->queueSignal($id,'rekey:'.$job['transaction'].':'.$device,$node,true);unset($job['pending'][$device]);}
                if($job['pending']===[])unset($this->rekeyJobs[$id]);
            }catch(\Throwable $error){$job['attempts']=($job['attempts']??0)+1;$retrying=$job['attempts']<3;if($retrying)$job['retryAt']=$this->now()+1.0;else unset($this->rekeyJobs[$id]);($this->emit)('voip_error',['callId'=>$id,'operation'=>'enc_rekey','retrying'=>$retrying,'error'=>$error->getMessage()]);}unset($job);
        }
        $now=$this->now();foreach($this->reactions as $id=>$job){if($now>=$job['expires'] || ($this->calls[$id]['state']??'ended')==='ended'){unset($this->reactions[$id]);continue;}if($now<$job['next'])continue;$this->reactions[$id]['next']=$now+0.06;try{$this->sendAppData($id,$job['payload'],$job['timestamp']);}catch(\Throwable $error){($this->emit)('voip_error',['callId'=>$id,'operation'=>'reaction','error'=>$error->getMessage()]);}}$this->drainSignals();
    }
    public function close():void { foreach($this->calls as $id=>$call){$this->calls[$id]['joinable']=false;if($call['state']!=='ended')$this->end($id,'connection_closed');}$this->keys=[];$this->peerKeys=[];$this->earlyTerminates=[];$this->pendingKeys=[];$this->rekeyJobs=[];$this->reactions=[]; }
    private function ownDevice():string { $c=($this->getCredentials)();$jid=$c['meLid']??$c['meJid']??null;if(!is_string($jid))throw new \LogicException('Calls require a connected registered account');return (string)Jid::parse($jid)->canonical(); }
    private function isOwn(string $jid):bool { $credentials=($this->getCredentials)();foreach(['meLid','meJid'] as $field)if(is_string($credentials[$field]??null) && self::sameUser($jid,$credentials[$field]))return true;return false; }
    /** Explicit companions stay alone; a failed discovery can still ring the primary.
     * @return list<string> */
    private function offerDevices(string $peer):array { $peer=(string)Jid::recipient($peer)->canonical();if(Jid::parse($peer)->device>0)return [GroupCallState::device($peer)];try{$devices=($this->resolveDevices)($peer);}catch(\Throwable){$devices=[];}return $devices===[]?[GroupCallState::device($peer)]:$devices; }
    /**
     * @return array<string,mixed> */
    private function requireCall(string $id):array { return $this->calls[$id]??throw new \InvalidArgumentException('Unknown call ID'); }
    /**
     * @param array<string,string> $attrs
     *
     * @param list<BinaryNode>|null $children */
    private function stanza(string $to,string $tag,string $id,string $creator,array $attrs=[],?array $children=null):BinaryNode { return new BinaryNode('call',['to'=>$to,'id'=>strtoupper(bin2hex(random_bytes(16)))],[new BinaryNode($tag,['call-id'=>$id,'call-creator'=>$creator,...$attrs],$children)]); }
    private function submit(BinaryNode $node):BinaryNode { $ack=($this->query)($node,30.0);if($ack->tag!=='ack' || ($ack->attrs['class']??'')!=='call' || ($ack->attrs['id']??'')!==$node->attrs['id'] || isset($ack->attrs['error']) || $ack->child('error')!==null)throw new \RuntimeException('Call signaling was not acknowledged');return $ack; }
    private function decryptKey(BinaryNode $offer,string $sender):?string
    {
        $candidates=[];foreach(is_array($offer->content)?$offer->content:[] as $child)if($child->tag==='enc')$candidates[]=$child;
        foreach(is_array($offer->child('destination')?->content)?$offer->child('destination')->content:[] as $to)foreach(is_array($to->content)?$to->content:[] as $enc)if($enc->tag==='enc')$candidates[]=$enc;
        foreach($candidates as $enc){if(!is_string($enc->content) || !in_array($enc->attrs['type']??'',['msg','pkmsg'],true))continue;try{$plain=($this->decryptCallKey)($sender,$enc->attrs['type'],$enc->content);$message=SchemaCodec::decode('Message',MessageCodec::unpad($plain));$key=$message['call']['callKey']??null;if(is_string($key) && strlen($key)===32)return $key;}catch(\Throwable){}}
        return null;
    }
    private function queuePreaccept(string $id):void
    {
        $call=$this->requireCall($id);$this->queueSignal($id,'preaccept',$this->stanza($call['group']?$id.'@call':$call['peerJid'],'preaccept',$id,$call['callCreator'],[],[new BinaryNode('audio',['enc'=>'opus','rate'=>'16000']),new BinaryNode('encopt',['keygen'=>'2']),new BinaryNode('capability',['ver'=>'1'],hex2bin('0105ff09e4bb07'))]));
    }
    private function queueTransport(string $id,string $target,string $type,string $round):void { $call=$this->requireCall($id);$this->queueSignal($id,'transport:'.$target.':'.$type.':'.$round,$this->stanza($target,'transport',$id,$call['callCreator'],['transport-message-type'=>$type,'p2p-cand-round'=>$round],[new BinaryNode('net',['medium'=>'2','protocol'=>'0'])])); }
    private function queueSignal(string $id,string $purpose,BinaryNode $node,bool $active=false,bool $remember=true):void
    {
        if(($this->calls[$id]['state']??'ended')==='ended')return;$key=$id.'|'.$purpose;if(isset($this->signals[$key]) || ($remember && (isset($this->signalSent[$key]) || isset($this->signalAbandoned[$key]))))return;
        if(count($this->signals)>=1024){($this->emit)('voip_error',['callId'=>$id,'operation'=>'signaling','error'=>'Call signaling queue limit reached']);return;}$now=$this->now();$this->signals[$key]=['callId'=>$id,'node'=>$node,'attempts'=>0,'next'=>$now,'expires'=>$now+30.0,'active'=>$active,'remember'=>$remember,'awaiting'=>false];
    }
    private function handleSignalAck(BinaryNode $ack):bool
    {
        $key=$this->signalIds[$ack->attrs['id']??'']??null;if($key===null || !isset($this->signals[$key]))return false;$job=$this->signals[$key];$type=is_array($job['node']->content)?($job['node']->content[0]->tag??''):'';
        if(isset($ack->attrs['type']) && $ack->attrs['type']!==$type)return false;
        if(isset($ack->attrs['error']) || $ack->child('error')!==null){if($job['awaiting'])$this->failSignal($key,'Call signaling received a negative ACK');return true;}
        $this->finishSignal($key,true);($this->emit)('voip_signaling_ack',['callId'=>$job['callId'],'type'=>$type,'attempts'=>$job['attempts']]);return true;
    }
    private function finishSignal(string $key,bool $acknowledged):void
    {
        if(!isset($this->signals[$key]))return;$job=$this->signals[$key];unset($this->signalIds[$job['node']->attrs['id']??''],$this->signals[$key]);if($job['remember']){if($acknowledged)$this->signalSent[$key]=true;else $this->signalAbandoned[$key]=true;}
    }
    private function failSignal(string $key,string $error):void
    {
        if(!isset($this->signals[$key]))return;$job=$this->signals[$key];$retrying=$job['attempts']<3 && $job['expires']>$this->now();if($retrying){$this->signals[$key]['awaiting']=false;$this->signals[$key]['next']=$this->now()+0.5*2**max(0,$job['attempts']-1);}else $this->finishSignal($key,false);
        ($this->emit)('voip_error',['callId'=>$job['callId'],'operation'=>'signaling','attempt'=>$job['attempts'],'retrying'=>$retrying,'error'=>$error]);
    }
    private function drainSignals():void
    {
        if($this->drainingSignals)return;$this->drainingSignals=true;$sent=0;$now=$this->now();try{foreach(array_keys($this->signals) as $key){if(!isset($this->signals[$key]))continue;$job=$this->signals[$key];$state=$this->calls[$job['callId']]['state']??'ended';if($state==='ended'){$this->finishSignal($key,false);continue;}if($job['expires']<=$now){$this->failSignal($key,'Call signaling deadline expired');continue;}if($job['active'] && !in_array($state,['connecting','connected'],true))continue;
                $mute=$job['node']->child('mute_v2');if($mute!==null){$value=($this->calls[$job['callId']]['audioMuted']??false)?'1':'0';if(($mute->attrs['mute-state']??null)!==$value){unset($this->signalIds[$job['node']->attrs['id']]);$job['node']=$this->stanza($job['node']->attrs['to'],'mute_v2',$job['callId'],$this->calls[$job['callId']]['callCreator'],['mute-state'=>$value]);$job['attempts']=0;$job['awaiting']=false;$job['next']=$now;$job['expires']=$now+30.0;$this->signals[$key]=$job;}}
                if($job['node']->child('user_action')!==null && !($this->calls[$job['callId']]['handRaised']??false)){$this->finishSignal($key,false);continue;}
                if($job['next']>$now)continue;if($job['awaiting']){$this->failSignal($key,'Call signaling ACK timed out');continue;}if(++$sent>64)break;
                $awaitAck=$job['node']->tag==='call';$this->signals[$key]['attempts']=$job['attempts']+1;$this->signals[$key]['awaiting']=$awaitAck;$this->signals[$key]['next']=$now+3.0;if($awaitAck)$this->signalIds[$job['node']->attrs['id']]=$key;
                try{($this->sendNode)($job['node']);if(!$awaitAck && isset($this->signals[$key]))$this->finishSignal($key,true);}
                catch(\Throwable $error){$this->failSignal($key,$error->getMessage());}
            }}finally{$this->drainingSignals=false;}
    }
    private function resolveDestinations():void
    {
        foreach($this->resolveJobs as $id=>$job){if(($this->calls[$id]['state']??'ended')==='ended'){unset($this->resolveJobs[$id]);continue;}if($job['next']>$this->now())continue;try{$devices=($this->resolveDevices)(Jid::parse($this->calls[$id]['peerJid'])->bare());$normalized=[];foreach($devices as $device){$normalized[]=GroupCallState::device($device);if(count($normalized)>=32)break;}$this->destinations[$id]=array_values(array_unique([...($this->destinations[$id]??[]),...$normalized]));unset($this->resolveJobs[$id]);}catch(\Throwable $error){if($job['attempts']>=2)unset($this->resolveJobs[$id]);else $this->resolveJobs[$id]=['attempts'=>$job['attempts']+1,'next'=>$this->now()+1.0];($this->emit)('voip_error',['callId'=>$id,'operation'=>'resolve_call_devices','error'=>$error->getMessage()]);}}
    }
    private function rememberParticipants(string $id,BinaryNode $node):void { $this->destinations[$id]=array_values(array_unique([...($this->destinations[$id]??[]),...self::relayParticipants($node),...(isset($this->groups[$id])?$this->groups[$id]->devices():[])])); }
    /** @return list<string> */
    private static function relayParticipants(BinaryNode $node,int $depth=0):array
    {
        if($depth>4)return [];$devices=[];foreach(is_array($node->content)?$node->content:[] as $child){if(in_array($child->tag,['device','participant'],true) && isset($child->attrs['jid'])){try{$devices[]=GroupCallState::device($child->attrs['jid']);}catch(\InvalidArgumentException){}}elseif(in_array($child->tag,['user','relay','relays','call','offer','accept','group_update','group_info'],true))$devices=[...$devices,...self::relayParticipants($child,$depth+1)];if(count($devices)>=1280)break;}return array_values(array_unique(array_slice($devices,0,1280)));
    }
    private function queueMeasuredLatencies():void
    {
        foreach($this->relayPools as $id=>$pool){$call=$this->calls[$id];if(!in_array($call['state'],['connecting','connected'],true))continue;$targets=$this->destinations[$id]??[];if($targets===[])continue;sort($targets,SORT_STRING);$composition=hash('sha256',implode("\0",$targets));foreach($pool->measuredRelays() as $relay){$purpose='relaylatency:'.$relay['relayName'];$key=$id.'|'.$purpose;if(isset($this->signals[$key]) || ($this->relayReportTargets[$id][$relay['relayName']]??null)===$composition)continue;unset($this->signalSent[$key],$this->signalAbandoned[$key]);$children=[new BinaryNode('te',['latency'=>(string)(0x2000000+$relay['latencyMs']),'relay_name'=>$relay['relayName']],$relay['addressBytes']),new BinaryNode('destination',[],array_map(static fn($jid)=>new BinaryNode('to',['jid'=>$jid]),$targets))];$this->queueSignal($id,$purpose,$this->stanza(Jid::parse($call['peerJid'])->bare(),'relaylatency',$id,$call['callCreator'],[],$children),true);if(isset($this->signals[$key]))$this->relayReportTargets[$id][$relay['relayName']]=$composition;}}
    }
    private function queueOwnState(string $id,string $purpose,bool $hand=false):void
    {
        $call=$this->calls[$id];$target=$call['group']?$id.'@call':$call['peerJid'];$this->queueSignal($id,$purpose.':mute',$this->stanza($target,'mute_v2',$id,$call['callCreator'],['mute-state'=>($call['audioMuted']??false)?'1':'0']),true,$purpose!=='roster');if($hand && ($call['handRaised']??false))$this->queueSignal($id,$purpose.':hand',$this->stanza($target,'user_action',$id,$call['callCreator'],['action'=>'raise_hand','broadcast'=>'1'],[new BinaryNode('raise_hand',['raise-hand-state'=>'1'])]),true,$purpose!=='roster');
    }
    private function cancelOwnStateSignals(string $id,string $tag):void { foreach(array_keys($this->signals) as $key)if($this->signals[$key]['callId']===$id && $this->signals[$key]['node']->child($tag)!==null)$this->finishSignal($key,false); }
    private function updateGroup(string $id,BinaryNode $info,string $from,?string $wrapperTransaction=null):void
    {
        $roster=$this->groups[$id]??=new GroupCallState($id);$before=$roster->devices(true);$hadMedia=$this->calls[$id]['state']==='connected';$result=$roster->applyRoster($info,$from,$this->ownDevice(),$wrapperTransaction);$transaction=$result['transactionId'];if($result['rekey'])$this->calls[$id]['rekeyTransactionRequested']=$transaction;
        if(!$result['applied']){if($result['rekey'])$this->queueRekey($id,$transaction);return;}
        if($result['devicesAdded']!==[] || $result['devicesRemoved']!==[])$this->invalidateGroupKey($id,$transaction??0);
        else ($this->groupMedia[$id]??null)?->updateRoster();
        $this->calls[$id]['participants']=$roster->participants();$this->calls[$id]['rosterTransactionId']=$transaction;if(in_array($info->attrs['media']??'',['video','2'],true)){$this->calls[$id]['video']=true;($this->groupMedia[$id]??null)?->setVideoCoverage(true);}
        $this->pruneGroupState($id);
        if(!$result['selfPresent'] && in_array($this->calls[$id]['state'],['connecting','connected'],true)){$this->end($id,'membership_removed');}
        elseif($this->calls[$id]['direction']==='outgoing' && in_array($this->calls[$id]['state'],['offering','offered','ringing'],true) && in_array(GroupCallState::device($this->ownDevice()),$roster->devices(true),true) && array_filter($roster->devices(true),fn($jid)=>!$this->isOwn($jid))!==[]){$this->calls[$id]['state']='connecting';$this->calls[$id]['acceptedAt']=$this->now();$this->heartbeatDue[$id]=$this->now();}
        $this->destinations[$id]=$roster->devices();$arrived=array_filter([...$result['added'],...array_diff($roster->devices(true),$before)],fn($jid)=>!$this->isOwn($jid));if($hadMedia && $arrived!==[] && in_array($this->calls[$id]['state'],['connecting','connected'],true))$this->queueOwnState($id,'roster',true);
        if($result['rekey'])$this->queueRekey($id,$transaction);
        $this->applyPendingKeys($id);($this->emit)('voip_group_roster',['callId'=>$id,...$result,'participants'=>$roster->participants()]);$this->state($id);
    }
    private function receiveGroupUpdate(string $id,BinaryNode $inner,string $from):void
    {
        $this->applySettings($id,$inner);
        $info=$inner->child('group_info');$incoming=$info?->attrs['transaction-id']??$inner->attrs['transaction-id']??null;$held=($this->groups[$id]??null)?->transactionId();$transaction=$incoming!==null && preg_match('/^(0|[1-9][0-9]*)$/D',$incoming) && (float)$incoming<=PHP_INT_MAX?(int)$incoming:null;
        if($transaction!==null && $held!==null && $transaction<$held)return;
        if($info!==null)$this->updateGroup($id,$info,$from,$inner->attrs['transaction-id']??null);$relays=RelayDescriptor::parse($inner);$relayHeld=$this->calls[$id]['relayTransactionId']??null;if($relays!==[] && ($transaction===null || $relayHeld===null || $transaction>=$relayHeld) && ($this->relays[$id]??[])!==$relays){$this->relays[$id]=$relays;if($transaction!==null)$this->calls[$id]['relayTransactionId']=$transaction;if(isset($this->relayPools[$id]))$this->relayDirty[$id]=true;}
        $upgrader=$inner->child('av_upgrade')?->attrs['av-upgrader']??null;if($upgrader!==null && isset($this->groups[$id]) && $this->groups[$id]->hasAccount($upgrader)){$this->calls[$id]['video']=true;($this->groupMedia[$id]??null)?->setVideoCoverage(true);$asked=($this->calls[$id]['videoUpgrade']['state']??null)==='pending' && ($this->calls[$id]['videoUpgrade']['direction']??null)==='outgoing';if($asked){$this->calls[$id]['videoUpgrade']['state']='accepted';$this->calls[$id]['videoEnabled']=true;}elseif(!($this->calls[$id]['videoEnabled']??false))$this->queueVideoState($id,6,'group-upgrade-stopped');$this->applyVideoSend($id);($this->emit)('voip_video_upgrade',['callId'=>$id,'state'=>'accepted','participantJid'=>$upgrader]);$this->state($id);}
    }
    /** A 1:1 can grow into a roster call without acquiring a WhatsApp group JID. */
    private function promoteGroup(string $id,BinaryNode $inner,string $from):void
    {
        $info=$inner->child('group_info');if($info===null)return;$probe=new GroupCallState($id);$result=$probe->applyRoster($info,$from,$this->ownDevice(),$inner->attrs['transaction-id']??null);if(!$result['applied'])return;
        $accepted=in_array($this->calls[$id]['state'],['connecting','connected'],true);$transport=($this->media[$id]??null)?->releaseTransport();unset($this->media[$id],$this->peerKeys[$id],$this->videoHolds[$id]);$this->calls[$id]['group']=true;$this->calls[$id]['videoSendHeld']=false;$this->groups[$id]=new GroupCallState($id);
        $this->receiveGroupUpdate($id,$inner,$from);if(($this->calls[$id]['state']??'ended')==='ended'){$transport?->close();return;}
        if($accepted){$this->heartbeatDue[$id]=$this->now();if($transport!==null){try{$session=$this->groupMedia($id);$session->setMediaFlowing();$session->adoptTransport($transport);}catch(\Throwable $error){$transport->close();($this->emit)('voip_media_error',['callId'=>$id,'reason'=>'group_promotion_failed','error'=>$error->getMessage()]);$this->end($id,'transport_failed');return;}}}
        ($this->emit)('voip_group_promoted',['callId'=>$id,'transactionId'=>$result['transactionId']]);
    }
    /** @return list<array<string,mixed>> */
    private function mediaDescriptors(string $id,string $transport):array
    {
        $out=[];$seen=[];$group=$this->calls[$id]['group']?$this->groupMedia($id):null;foreach($this->relays[$id]??[] as $descriptor){if(($descriptor['protocol']??0)!==0 || !is_string($descriptor['key']??null) || $descriptor['key']==='' || !is_string($descriptor['rawToken']??null) || $descriptor['rawToken']==='')continue;$endpoint=$descriptor['ip'].':'.$descriptor['port'];if(isset($seen[$endpoint]))continue;$seen[$endpoint]=true;$adopted=$group!==null && isset($this->calls[$id]['relayTransactionId']);$mode=$adopted && empty($descriptor['authToken'])?'raw-udp':$transport;$descriptor['transport']=$mode;if($mode==='webrtc' && $descriptor['port']!==3480)$descriptor['fallbackPort']=3480;if($group!==null){$plan=$group->streamPlan();$descriptor['streamDescriptor']=Stun::streamDescriptor($plan['selfLayers']);$descriptor['rxSubscriptions']=Stun::rxSubscriptions($group->rxSubscriptions());}$out[]=$descriptor;if(count($out)>=16)break;}return $out;
    }
    private function queueRekey(string $id,?int $transaction):void
    {
        $roster=$this->groups[$id];if($transaction!==null && in_array($this->calls[$id]['state'],['connecting','connected'],true) && in_array(GroupCallState::device($this->ownDevice()),$roster->devices(true),true) && count(array_unique(array_map([GroupCallState::class,'account'],$roster->devices(true))))>=2 && (!isset($this->keyEpoch[$id]) || $this->keyEpoch[$id]<$transaction) && !isset($this->rekeyJobs[$id]))$this->rekeyJobs[$id]=['transaction'=>$transaction,'expires'=>$this->now()+30.0];
    }
    private function invalidateGroupKey(string $id,int $minimum):void
    {
        $this->minimumKeyEpoch[$id]=max($this->minimumKeyEpoch[$id]??0,$minimum);if(isset($this->keys[$id]))$this->revokedKeys[$id][hash('sha256',$this->keys[$id])]=true;unset($this->keys[$id],$this->rekeyJobs[$id],$this->groupLastMedia[$id],$this->groupWaitingSince[$id],$this->groupInvisible[$id]);foreach(array_keys($this->signals) as $job)if($this->signals[$job]['callId']===$id && $this->signals[$job]['node']->child('enc_rekey')!==null)$this->finishSignal($job,false);($this->groupMedia[$id]??null)?->invalidateForMembershipChange($minimum);
        if(($this->calls[$id]['state']??null)==='connected'){$this->calls[$id]['state']='connecting';$this->calls[$id]['acceptedAt']=$this->now();unset($this->calls[$id]['connectedAt']);}
    }
    private function receiveRekey(BinaryNode $outer,BinaryNode $inner,string $from,string $id,string $creator):void
    {
        // The receipt is sent even when a key is unreadable, matching the peer's retry protocol.
        if(isset($outer->attrs['id']))($this->sendNode)(new BinaryNode('receipt',['to'=>$from,'id'=>$outer->attrs['id'],'from'=>$this->ownDevice()],[new BinaryNode('enc_rekey',['call-id'=>$id,'call-creator'=>$creator])]));
        $transaction=$inner->attrs['transaction-id']??'';if(!preg_match('/^(0|[1-9][0-9]*)$/D',$transaction) || (float)$transaction>PHP_INT_MAX)return;
        $sender=GroupCallState::device($from);$key=$this->decryptKey($inner,$from);if($key===null){($this->emit)('voip_error',['callId'=>$id,'operation'=>'enc_rekey','error'=>'Call key could not be authenticated']);return;}
        if(isset($this->calls[$id]) && $this->calls[$id]['callCreator']!==$creator)throw new \UnexpectedValueException('Rekey belongs to another call creator');if(isset($this->calls[$id]) && !$this->calls[$id]['group'])return;
        $item=['sender'=>$sender,'creator'=>$creator,'key'=>$key,'transaction'=>(int)$transaction,'expires'=>$this->now()+30.0];
        if($this->eligibleKey($id,$item)){$this->installGroupKey($id,$key,(int)$transaction);return;}
        if(isset($this->groups[$id]) && $this->groups[$id]->transactionId()!==null){$current=$this->groups[$id]->transactionId();if((int)$transaction<$current || ((int)$transaction===$current && !in_array($sender,$this->groups[$id]->devices(true),true)))return;}
        if(!isset($this->pendingKeys[$id]) && count($this->pendingKeys)>=64)unset($this->pendingKeys[array_key_first($this->pendingKeys)]);$this->pendingKeys[$id][]=$item;if(count($this->pendingKeys[$id])>4)array_shift($this->pendingKeys[$id]);
    }
    /** @param array{sender:string,creator:string,key:string,transaction:int,expires:float} $item */
    private function eligibleKey(string $id,array $item):bool
    {
        if(!isset($this->calls[$id],$this->groups[$id]) || !$this->calls[$id]['group'] || $this->calls[$id]['callCreator']!==$item['creator'] || !in_array($this->calls[$id]['state'],['connecting','connected'],true) || $item['expires']<=$this->now())return false;
        $roster=$this->groups[$id];return $roster->transactionId()===$item['transaction'] && $item['transaction']>=($this->minimumKeyEpoch[$id]??0) && in_array($item['sender'],$roster->devices(true),true) && !$this->isOwn($item['sender']) && $roster->hasDevice($this->ownDevice());
    }
    private function applyPendingKeys(string $id):void
    {
        foreach($this->pendingKeys[$id]??[] as $item)if($this->eligibleKey($id,$item))$this->installGroupKey($id,$item['key'],$item['transaction']);
        if(isset($this->groups[$id])){$current=$this->groups[$id]->transactionId();$active=in_array($this->calls[$id]['state'],['connecting','connected'],true);$this->pendingKeys[$id]=array_values(array_filter($this->pendingKeys[$id]??[],fn($item)=>$item['expires']>$this->now() && ($current===null || $item['transaction']>$current || (!$active && $item['transaction']===$current))));if($this->pendingKeys[$id]===[])unset($this->pendingKeys[$id]);}
    }
    private function installGroupKey(string $id,string $key,int $transaction):void
    {
        if($transaction<($this->minimumKeyEpoch[$id]??0) || (isset($this->keyEpoch[$id]) && $transaction<$this->keyEpoch[$id]))return;
        if(isset($this->revokedKeys[$id][hash('sha256',$key)]))throw new \UnexpectedValueException('Group rekey reused key material exposed to excluded members');
        if(isset($this->keys[$id],$this->keyEpoch[$id]) && $transaction===$this->keyEpoch[$id]){if(!hash_equals($this->keys[$id],$key))throw new \UnexpectedValueException('Conflicting group call keys in one epoch');return;}
        ($this->groupMedia[$id]??null)?->rotateKey($key,$transaction);$this->keys[$id]=$key;$this->keyEpoch[$id]=$transaction;($this->emit)('voip_group_key_rotated',['callId'=>$id,'transactionId'=>$transaction]);
    }
    /** @return array<string,mixed> */
    private function accepted(string $id):array { $call=$this->requireCall($id);if(!in_array($call['state'],['connecting','connected'],true))throw new \LogicException('Call has not been accepted');return $call; }
    private function sendVideoState(string $id,int $state):void
    {
        $this->submit($this->videoStanza($id,$state));
    }
    private function videoStanza(string $id,int $state):BinaryNode { $call=$this->accepted($id);$transaction=($call['videoTransactionId']??0)+1;$this->calls[$id]['videoTransactionId']=$transaction;$attrs=['state'=>(string)$state,'device_orientation'=>'0','dec'=>'H264','transaction-id'=>(string)$transaction];if(in_array($state,[3,11],true))$attrs['voip_settings']='video';return $this->stanza($call['group']?$id.'@call':$call['peerJid'],'video',$id,$call['callCreator'],$attrs); }
    private function queueVideoState(string $id,int $state,string $purpose):void { if(!in_array($this->calls[$id]['state']??null,['connecting','connected'],true))return;$this->queueSignal($id,'video:'.$purpose,$this->videoStanza($id,$state),true); }
    private function holdVideo(string $id,string $trigger):void { if(isset($this->videoHolds[$id]))return;$now=$this->now();$this->videoHolds[$id]=['trigger'=>$trigger,'since'=>$now,'deadline'=>$now+($trigger==='upgrade'?3.0:2.0),'readyAt'=>null];$this->calls[$id]['videoSendHeld']=true;$this->applyVideoSend($id); }
    private function markVideoReady(string $id,string $trigger):void { if(($this->videoHolds[$id]['trigger']??null)===$trigger && $this->videoHolds[$id]['readyAt']===null)$this->videoHolds[$id]['readyAt']=$this->now()+($trigger==='upgrade'?0.3:0.15); }
    private function applyVideoSend(string $id):void { $call=$this->calls[$id];$enabled=!isset($this->videoHolds[$id]) && (($call['videoEnabled']??false) || ($call['screenSharing']??false));($this->media[$id]??null)?->setVideoEnabled($enabled);($this->groupMedia[$id]??null)?->setVideoEnabled($enabled); }
    private function receiveControl(string $id,string $from,BinaryNode $inner):void
    {
        if(!in_array($this->calls[$id]['state'],['connecting','connected'],true) || $this->isOwn($from))return;
        if($this->calls[$id]['group'] && (!isset($this->groups[$id]) || !$this->groups[$id]->hasDevice($from)))return;
        $device=GroupCallState::device($from);$base=['callId'=>$id,'participantJid'=>$device];
        if($inner->tag==='mute_v2'){
            if(isset($inner->attrs['request-state'])){if($this->calls[$id]['group'])($this->emit)('voip_mute_requested',[...$base,'requestState'=>$inner->attrs['request-state']]);return;}
            $this->markVideoReady($id,'born-video');
            $value=$inner->attrs['mute-state']??null;if(!in_array($value,['0','1'],true))return;$this->calls[$id]['participantAudioMuted'][$device]=$value==='1';if(!$this->calls[$id]['group'])$this->calls[$id]['peerAudioMuted']=$value==='1';($this->emit)('voip_participant_mute',[...$base,'muted'=>$value==='1']);
        }elseif(in_array($inner->tag,['user_action','raise_hand'],true)){
            if($inner->tag==='user_action' && ($inner->attrs['action']??null)!=='raise_hand')return;$hand=$inner->tag==='raise_hand'?$inner:$inner->child('raise_hand');$value=$hand?->attrs['raise-hand-state']??null;if(!in_array($value,['0','1'],true))return;if(($this->calls[$id]['raisedHands'][$device]??false)===($value==='1'))return;$this->calls[$id]['raisedHands'][$device]=$value==='1';($this->emit)('voip_hand_raised',[...$base,'raised'=>$value==='1']);
        }elseif(in_array($inner->tag,['screen_share','screen'],true)){
            $value=$inner->attrs['screenshare_state']??null;$request=$inner->attrs['request-state']??$inner->attrs['request_state']??null;if(($value===null || !is_numeric($value)) && ($request===null || !is_numeric($request)))return;$share=['state'=>$value!==null && is_numeric($value)?(int)$value:null,'requestState'=>$request!==null && is_numeric($request)?(int)$request:null];if($share['state']!==null)$share['sharing']=$share['state']===1;foreach(['version','screen_share_id','screen_width','screen_height','device_orientation'] as $attr)if(isset($inner->attrs[$attr]) && ctype_digit($inner->attrs[$attr]))$share[$attr]=(int)$inner->attrs[$attr];$held=$this->calls[$id]['participantScreenShare'][$device]??[];if(isset($held['screen_share_id'],$share['screen_share_id']) && $share['screen_share_id']<=$held['screen_share_id'])return;$this->calls[$id]['participantScreenShare'][$device]=[...$held,...$share];($this->emit)('voip_screen_share',[...$base,...$share]);
        }elseif($inner->tag==='video'){
            $value=$inner->attrs['state']??'';if(!ctype_digit($value) || (float)$value>2147483647)return;$transaction=$inner->attrs['transaction-id']??null;if($transaction!==null){if(!ctype_digit($transaction) || (float)$transaction>PHP_INT_MAX)return;$tx=(int)$transaction;$held=$this->calls[$id]['participantVideoTransaction'][$device]??-1;if(($this->calls[$id]['voipSettings']['videoStateTxnRecvEnforce']??false) && $tx<=$held)return;$this->calls[$id]['participantVideoTransaction'][$device]=max($tx,$held);}$state=(int)$value;$this->calls[$id]['participantVideoState'][$device]=$state;($this->groupMedia[$id]??null)?->setParticipantVideoState($device,$state===1);
            if(!$this->calls[$id]['group']){
                if(in_array($state,[3,11],true)){if(($this->calls[$id]['videoUpgrade']['state']??null)!=='pending')$this->calls[$id]['videoUpgrade']=['direction'=>'incoming','participantJid'=>$device,'deadline'=>$this->now()+5.0,'state'=>'pending'];$this->calls[$id]['peerVideoUpgradeRequested']=true;}
                elseif($state===1)$this->markVideoReady($id,'upgrade');
                else{
                    $this->calls[$id]['peerVideoUpgradeRequested']=false;$pending=($this->calls[$id]['videoUpgrade']['state']??null)==='pending';$outgoing=($this->calls[$id]['videoUpgrade']['direction']??null)==='outgoing';
                    if($state===4 && $pending && $outgoing){$this->calls[$id]['video']=true;$this->calls[$id]['videoEnabled']=true;$this->calls[$id]['videoUpgrade']['state']='accepted';$this->applyVideoSend($id);$this->queueVideoState($id,1,'upgrade-enabled');}
                    elseif(in_array($state,[5,7,20],true) && $pending && $outgoing)$this->calls[$id]['videoUpgrade']['state']=match($state){7=>'rejected_by_timeout',20=>'error',default=>'rejected'};
                    elseif(in_array($state,[0,8,9],true) && $pending && !$outgoing)$this->calls[$id]['videoUpgrade']['state']='cancelled';
                }
            }
            ($this->emit)('voip_video_state',[...$base,'state'=>$state,'transactionId'=>$transaction===null?null:(int)$transaction]);
        }
        $this->state($id);
    }
    /** Only authenticated media invokes this callback.
     * @param array<string,mixed> $data */
    private function mediaEvent(string $id,string $event,array $data):void
    {
        if(in_array($event,['voip_media_ready','voip_group_media_ready'],true) && in_array($this->calls[$id]['state'],['connecting','connected'],true)){if($this->calls[$id]['state']!=='connected'){$this->calls[$id]['state']='connected';$this->calls[$id]['connectedAt']=$this->now();$this->state($id);}}
        if(in_array($event,['voip_media_ready','voip_group_media_ready'],true) && ($this->calls[$id]['state']??'ended')==='connected')$this->queueOwnState($id,'initial');
        if($event==='voip_group_media_packet' && is_string($data['senderJid']??null) && ($this->calls[$id]['state']??'ended')!=='ended'){$device=$data['senderJid'];$account=GroupCallState::account($device);$now=$this->now();$last=$this->groupLastMedia[$id][$device]??null;$this->groupLastMedia[$id][$device]=$now;$reported=isset($this->groupInvisible[$id][$account]);unset($this->groupWaitingSince[$id][$device],$this->groupInvisible[$id][$account]);if($reported)foreach(array_keys($this->signals) as $job)if($this->signals[$job]['callId']===$id && $this->signals[$job]['node']->child('peer_state')?->child('user')?->attrs['jid']===$account)$this->finishSignal($job,false);if($last===null || $now-$last>=20.0)($this->emit)('voip_group_participant_media',['callId'=>$id,'participantJid'=>$device,'receiving'=>true]);}
        if(in_array($event,['voip_media_packet','voip_group_media_packet'],true) && ($data['kind']??null)==='rtp' && ($data['payloadType']??null)===119 && !self::appDataSframe($this->calls[$id])){foreach(AppDataProtocol::decode($data['payload']) as $item){$key=$id.'|'.($data['senderJid']??$this->calls[$id]['peerJid']).'|'.$data['ssrc'].'|'.$item['kind'].'|'.$item['transactionId'];if(isset($this->seenAppData[$key]))continue;if(count($this->seenAppData)>=4096)unset($this->seenAppData[array_key_first($this->seenAppData)]);$this->seenAppData[$key]=true;($this->emit)('voip_app_data',['callId'=>$id,'participantJid'=>$data['senderJid']??$this->calls[$id]['peerJid'],...$item]);}}
        ($this->emit)($event,$data);
    }
    private function pruneGroupState(string $id):void
    {
        $devices=$this->groups[$id]->devices();foreach(['participantAudioMuted','participantVideoState','participantVideoTransaction','participantScreenShare','raisedHands'] as $field)foreach(array_keys($this->calls[$id][$field]??[]) as $device)if(!in_array($device,$devices,true))unset($this->calls[$id][$field][$device]);
        foreach(array_keys($this->groupLastMedia[$id]??[]) as $device)if(!in_array($device,$devices,true))unset($this->groupLastMedia[$id][$device],$this->groupWaitingSince[$id][$device]);foreach(array_keys($this->groupInvisible[$id]??[]) as $account)if(!$this->groups[$id]->hasAccount($account))unset($this->groupInvisible[$id][$account]);
    }
    private function sweepGroupMedia():void
    {
        $now=$this->now();foreach($this->groups as $id=>$roster){if(!in_array($this->calls[$id]['state']??null,['connecting','connected'],true) || $now<($this->groupSweepDue[$id]??0))continue;$this->groupSweepDue[$id]=$now+5.0;$heard=false;
            foreach($this->groupLastMedia[$id]??[] as $device=>$last){if($now-$last<20.0){$heard=true;continue;}if(!isset($this->groupWaitingSince[$id][$device])){$this->groupWaitingSince[$id][$device]=$last+20.0;($this->emit)('voip_group_participant_media',['callId'=>$id,'participantJid'=>$device,'receiving'=>false]);}}
            if(!$heard)continue;foreach($roster->participants() as $participant){$account=$participant['jid'];if($participant['state']!=='connected' || $this->isOwn($account) || isset($this->groupInvisible[$id][$account]) || $participant['devices']===[])continue;$silent=INF;
                foreach($participant['devices'] as $device){if(isset($this->groupLastMedia[$id][$device]) && $now-$this->groupLastMedia[$id][$device]<20.0){$silent=-1;break;}$since=$this->groupWaitingSince[$id][$device]??=$now;$silent=min($silent,$now-$since);}
                if($silent<20.0)continue;$this->groupInvisible[$id][$account]=true;$this->queueSignal($id,'invisible:'.$account,$this->stanza($id.'@call','peer_state',$id,$this->calls[$id]['callCreator'],['t'=>(string)(int)floor($now*1000)],[new BinaryNode('user',['jid'=>$account,'state'=>'invisible'])]),true,false);
            }
        }
    }
    private function forgetEnded(string $id):void { unset($this->calls[$id],$this->groups[$id],$this->relays[$id],$this->destinations[$id],$this->minimumKeyEpoch[$id],$this->revokedKeys[$id],$this->pendingKeys[$id],$this->appDataCounters[$id],$this->appDataTimestamps[$id]); }
    private static function flag(?string $value):bool { return in_array($value,['1','true'],true); }
    /** SFrame is negotiated for app-data only, and only when both flags are on.
     * @param array<string,mixed> $call */
    private static function appDataSframe(array $call):bool { return ($call['voipSettings']['sframe']??false)===true && ($call['voipSettings']['sframeRx']??false)===true; }
    /** The server's known uncompressed variant carries JSON directly or base64-encoded. */
    private function applySettings(string $id,BinaryNode $node):void
    {
        $settings=$this->readSettings($node);if($settings===null)return;$this->calls[$id]['voipSettings']=$settings;try{($this->media[$id]??null)?->setVoipSettings($settings);($this->groupMedia[$id]??null)?->setVoipSettings($settings);}catch(\LogicException $error){($this->emit)('voip_error',['callId'=>$id,'operation'=>'voip_settings','error'=>$error->getMessage()]);if($this->calls[$id]['state']!=='ended')$this->end($id,'unsupported_media');return;}
        if($settings['sframe'] && $settings['sframeRx']){unset($this->reactions[$id]);($this->emit)('voip_error',['callId'=>$id,'operation'=>'app_data','error'=>'SFrame app-data is not implemented; audio and video still use SRTP']);}
    }
    /** @return array<string,mixed>|null */
    private function readSettings(BinaryNode $node,int $depth=0):?array
    {
        if($depth>2)return null;if($node->tag!=='voip_settings'){foreach(is_array($node->content)?$node->content:[] as $child){$found=$this->readSettings($child,$depth+1);if($found!==null)return $found;}return null;}
        if(isset($node->attrs['uncompressed']) && !in_array(strtolower($node->attrs['uncompressed']),['1','true'],true))return null;if(!is_string($node->content) || strlen($node->content)>262144)return null;$raw=ltrim($node->content);if(!str_starts_with($raw,'{')){$decoded=base64_decode(trim($raw),true);if($decoded===false)return null;$raw=$decoded;}
        try{$object=json_decode($raw,false,64,JSON_THROW_ON_ERROR);if(!$object instanceof \stdClass)return null;$root=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){return null;}
        $flag=static function(string $section,string $key)use($root):bool{$bucket=$root[$section]??null;$value=is_array($bucket)?($bucket[$key]??false):false;return $value===true || ((is_int($value) || is_float($value)) && is_finite((float)$value) && $value!=0) || (is_string($value) && in_array(strtolower(trim($value)),['true','1'],true));};
        $number=static function(string $section,string $key)use($root):?int{$bucket=$root[$section]??null;$value=is_array($bucket)?($bucket[$key]??null):null;if(!(is_int($value) || is_float($value) || is_string($value)) || !is_numeric($value) || !is_finite((float)$value) || (float)$value<1 || (float)$value>PHP_INT_MAX)return null;return (int)$value;};
        $heartbeat=$number('options','heartbeat_interval_s');return ['disableRtcpRemb'=>$flag('vid_rc','disable_rtcp_remb'),'rtcpIntervalMs'=>$number('rc','rtcp_interval_ms'),'audioFrameMs'=>$number('encode','frame_ms'),'audioMaxFramesPerPacket'=>$number('rc','maxfpp'),'videoStateTxnRecvEnforce'=>$flag('options','video_state_txn_id_recv_enforce'),'heartbeatIntervalMs'=>$heartbeat!==null && $heartbeat<=intdiv(PHP_INT_MAX,1000)?$heartbeat*1000:null,'appDataStream'=>$flag('options','enable_app_data_stream'),'appDataStreamVersion'=>$number('options','app_data_stream_version')??0,'sframe'=>$flag('sframe','enable_sframe'),'sframeRx'=>$flag('sframe','enable_sframe_rx')];
    }
    private function state(string $id):void { ($this->emit)('voip_call_state',$this->calls[$id]); }
    private function end(string $id,string $reason):void { ($this->media[$id]??null)?->close();($this->groupMedia[$id]??null)?->close();foreach(array_keys($this->signals) as $key)if($this->signals[$key]['callId']===$id)$this->finishSignal($key,false);foreach([array_keys($this->signalSent),array_keys($this->signalAbandoned)] as $keys)foreach($keys as $key)if(str_starts_with($key,$id.'|'))unset($this->signalSent[$key],$this->signalAbandoned[$key]);unset($this->media[$id],$this->groupMedia[$id],$this->relayPools[$id],$this->relayModes[$id],$this->relayDirty[$id],$this->relayReportTargets[$id],$this->heartbeatDue[$id],$this->resolveJobs[$id],$this->keys[$id],$this->peerKeys[$id],$this->keyEpoch[$id],$this->rekeyJobs[$id],$this->reactions[$id],$this->videoHolds[$id],$this->groupLastMedia[$id],$this->groupWaitingSince[$id],$this->groupInvisible[$id],$this->groupSweepDue[$id]);if(($this->calls[$id]['videoUpgrade']['state']??null)==='pending')$this->calls[$id]['videoUpgrade']['state']='cancelled';$this->calls[$id]['videoSendHeld']=false;$this->calls[$id]['state']='ended';$this->calls[$id]['endReason']=$reason;$this->calls[$id]['endedAt']=$this->now();$this->state($id); }
    private function capacity():void { if(count($this->calls)>=64){foreach($this->calls as $id=>$call)if($call['state']==='ended'){unset($this->calls[$id],$this->relays[$id],$this->groups[$id],$this->minimumKeyEpoch[$id],$this->revokedKeys[$id],$this->pendingKeys[$id],$this->appDataCounters[$id],$this->appDataTimestamps[$id]);return;}throw new \LengthException('Too many active calls');} }
    private static function sameUser(string $a,string $b):bool { return Jid::parse($a)->canonical()->bare()===Jid::parse($b)->canonical()->bare(); }
    private function now():float { return $this->clock===null?microtime(true):($this->clock)(); }
}

