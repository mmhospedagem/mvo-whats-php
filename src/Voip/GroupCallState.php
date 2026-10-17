<?php
declare(strict_types=1);
namespace MVO\Voip;

use MVO\Protocol\{BinaryNode,Jid};

/** Server-ordered complete roster. A peer's Signal message cannot rewrite membership. */
final class GroupCallState
{
    /** @var array<string,array<string,mixed>> */
    private array $participants=[];
    /** Membership and connected sender indexes change only with the roster.
     * @var array<string,true> */
    private array $deviceIndex=[];
    /** @var array<string,true> */
    private array $connectedDeviceIndex=[];
    private ?int $transaction=null;
    private bool $closed=false;
    /** @param list<array<string,mixed>> $seed */
    public function __construct(public readonly string $callId,array $seed=[])
    {
        foreach($seed as $participant){$record=self::participant($participant);if(!self::leaving($record))$this->participants[$record['jid']]=$record;}
        $this->indexDevices();
    }
    public static function device(string $jid):string { $p=Jid::parse($jid)->canonical();if(!in_array($p->server,['lid','s.whatsapp.net'],true))throw new \InvalidArgumentException('Call device must belong to a user');return $p->user.':'.$p->device.'@'.$p->server; }
    public static function account(string $jid):string { return Jid::parse(self::device($jid))->bare(); }
    /** @return array{applied:bool,transactionId:?int,added:list<string>,removed:list<string>,devicesAdded:list<string>,devicesRemoved:list<string>,rekey:bool,selfPresent:bool} */
    public function applyRoster(BinaryNode $groupInfo,string $authenticatedSender,string $ownDevice,?string $wrapperTransaction=null):array
    {
        if($this->closed)throw new \LogicException('Group call roster is closed');
        if($authenticatedSender!==$this->callId.'@call')throw new \UnexpectedValueException('Group roster must come from the authenticated call server');
        $parsed=self::readRoster($groupInfo);$explicit=$groupInfo->attrs['transaction-id']??$wrapperTransaction;$transaction=$explicit===null?null:self::integer($explicit,'transaction ID');
        if($transaction===null){foreach($parsed as $record)if($record['transactionId']!==null)$transaction=max($transaction??0,$record['transactionId']);$transaction??=($this->transaction??0)+1;}
        $base=['applied'=>false,'transactionId'=>$this->transaction,'added'=>[],'removed'=>[],'devicesAdded'=>[],'devicesRemoved'=>[],'rekey'=>false,'selfPresent'=>$this->hasDevice($ownDevice)];
        if($this->transaction!==null && $transaction<=$this->transaction){if($transaction===$this->transaction)$base['rekey']=self::flag($groupInfo->attrs['rekey']??null) || array_filter($parsed,static fn($record)=>$record['rekey'])!==[];return $base;}
        if($parsed===[])return $base;
        $next=[];foreach($parsed as $record)if(!self::leaving($record))$next[$record['jid']]=$record;
        $oldDevices=$this->devices();$newDevices=[];foreach($next as $record)$newDevices=[...$newDevices,...$record['devices']];
        $added=array_values(array_diff(array_keys($next),array_keys($this->participants)));$removed=array_values(array_diff(array_keys($this->participants),array_keys($next)));
        $devicesAdded=array_values(array_diff($newDevices,$oldDevices));$devicesRemoved=array_values(array_diff($oldDevices,$newDevices));
        $this->participants=$next;$this->transaction=$transaction;$this->indexDevices();
        return ['applied'=>true,'transactionId'=>$transaction,'added'=>$added,'removed'=>$removed,'devicesAdded'=>$devicesAdded,'devicesRemoved'=>$devicesRemoved,'rekey'=>self::flag($groupInfo->attrs['rekey']??null) || array_filter($parsed,static fn($record)=>$record['rekey'])!==[],'selfPresent'=>$this->hasDevice($ownDevice)];
    }
    /** Incoming offer rosters are complete server descriptors too.
     * @return list<array<string,mixed>> */
    public static function readRoster(BinaryNode $node):array
    {
        if($node->tag!=='group_info' || !isset($node->attrs['media']))throw new \UnexpectedValueException('Group info missing media');
        self::integer($node->attrs['connected_limit']??$node->attrs['connected-limit']??null,'connected limit');
        if(isset($node->attrs['group_phash'],$node->attrs['lightweight_key']))throw new \UnexpectedValueException('Group info has conflicting hash modes');
        $users=[];$participants=[];
        foreach(is_array($node->content)?$node->content:[] as $child){if($child->tag==='user')$users[]=$child;elseif($child->tag==='participant')$participants[]=$child;}
        if($users!==[] && $participants!==[])throw new \UnexpectedValueException('Group info mixes user and participant nodes');
        $records=[];$seen=[];
        foreach($users!==[]?$users:$participants as $user) {
            $jid=self::account($user->attrs['jid']??'');if(isset($seen[$jid]))throw new \UnexpectedValueException('Duplicate group participant');$seen[$jid]=true;$devices=[];$pids=[];
            $nacked=isset($user->attrs['reason']) || isset($user->attrs['error']);
            foreach(is_array($user->content)?$user->content:[] as $device){if($device->tag!=='device')continue;$address=self::device($device->attrs['jid']??'');if(self::account($address)!==$jid)throw new \UnexpectedValueException('Group device does not belong to its participant');if(in_array($address,$devices,true))throw new \UnexpectedValueException('Duplicate group device');$devices[]=$address;if(isset($device->attrs['pid']))$pids[$address]=self::integer($device->attrs['pid'],'participant media ID');}
            if(count($devices)>5 || ($user->tag==='user' && ((!$nacked && $devices===[]) || ($nacked && $devices!==[]))))throw new \UnexpectedValueException('Group participant device count invalid');
            $records[]=self::participant(['jid'=>$jid,'devices'=>$devices,'devicePids'=>$pids,'state'=>$user->attrs['state']??null,'nacked'=>$nacked,'tosNotAccepted'=>self::flag($user->attrs['tos_not_accepted']??null),'rekey'=>self::flag($user->attrs['rekey']??null),'transactionId'=>isset($user->attrs['transaction-id'])?self::integer($user->attrs['transaction-id'],'participant transaction ID'):null]);
            if(count($records)>256)throw new \LengthException('Group roster exceeds 256 participants');
        }
        return $records;
    }
    /** @return list<array<string,mixed>> */
    public function participants():array { return array_values($this->participants); }
    /** @return list<string> */
    public function devices(bool $connectedOnly=false):array { return array_keys($connectedOnly?$this->connectedDeviceIndex:$this->deviceIndex); }
    public function hasDevice(string $jid):bool { try{return isset($this->deviceIndex[self::device($jid)]);}catch(\InvalidArgumentException){return false;} }
    public function hasAccount(string $jid):bool { try{return isset($this->participants[self::account($jid)]);}catch(\InvalidArgumentException){return false;} }
    public function transactionId():?int { return $this->transaction; }
    /** A participant's terminate leaves their account, rather than kicking a different account. */
    public function removeDepartedParticipant(string $jid):void { unset($this->participants[self::account($jid)]);$this->indexDevices(); }
    public function close():void { $this->closed=true;$this->participants=[];$this->deviceIndex=[];$this->connectedDeviceIndex=[]; }
    private function indexDevices():void { $this->deviceIndex=[];$this->connectedDeviceIndex=[];foreach($this->participants as $record)foreach($record['devices'] as $device){$this->deviceIndex[$device]=true;if($record['state']==='connected' && !$record['tosNotAccepted'])$this->connectedDeviceIndex[$device]=true;} }
    /** @param array<string,mixed> $record
     * @return array<string,mixed> */
    private static function participant(array $record):array
    {
        if(!is_string($record['jid']??null) || !is_array($record['devices']??null))throw new \InvalidArgumentException('Group participant requires jid and devices');$jid=self::account($record['jid']);$devices=[];
        foreach($record['devices'] as $device){if(!is_string($device))throw new \InvalidArgumentException('Invalid group device');$device=self::device($device);if(self::account($device)!==$jid)throw new \InvalidArgumentException('Mixed group account devices');$devices[]=$device;}
        if(count($devices)>5)throw new \LengthException('A group participant can have at most five devices');
        usort($devices,static fn($a,$b)=>Jid::parse($a)->device<=>Jid::parse($b)->device);
        return ['jid'=>$jid,'devices'=>array_values(array_unique($devices)),'devicePids'=>$record['devicePids']??[],'state'=>$record['state']??null,'nacked'=>($record['nacked']??false)===true,'tosNotAccepted'=>($record['tosNotAccepted']??false)===true,'rekey'=>($record['rekey']??false)===true,'transactionId'=>$record['transactionId']??null];
    }
    /** @param array<string,mixed> $record */
    private static function leaving(array $record):bool { return $record['nacked'] || in_array($record['state'],['terminated','invalid','rejected','timedout','cancel_offer'],true); }
    private static function integer(mixed $value,string $name):int { if(!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D',$value) || (float)$value>PHP_INT_MAX)throw new \UnexpectedValueException('Invalid group '.$name);return (int)$value; }
    private static function flag(?string $value):bool { return $value!==null && $value!=='' && $value!=='0' && strtolower($value)!=='false'; }
}
