<?php
declare(strict_types=1);
namespace MVO\Mobile;

use MVO\AppState\AppStateService;
use MVO\Protocol\{BinaryData,BinaryNode,Jid,SchemaCodec};
use MVO\Signal\SignalStore;

/** Registered primary-side companion hosting. Every public operation checks the primary role. */
final class MobileService
{
    /** @var array<string,mixed>|null */
    private ?array $hello=null;
    /** @var array<string,mixed>|null */
    private ?array $codeSession=null;
    private bool $busy=false;
    /** @param \Closure():array<string,mixed> $getCredentials
     * @param \Closure(BinaryNode,float):BinaryNode $query
     * @param \Closure(string,array<string,mixed>):mixed $sendProtocolToDevice
     * @param \Closure(string,array<string,mixed>):void $emit
     * @param \Closure():bool $isPrimary
     * @param \Closure(float):mixed|null $pump
     * @param \Closure(string):array<string,mixed>|null $uploadHistory
     * @param \Closure():float|null $clock */
    public function __construct(private readonly SignalStore $store,private readonly \Closure $getCredentials,private readonly \Closure $query,private readonly \Closure $sendProtocolToDevice,private readonly AppStateService $appState,private readonly \Closure $emit,private readonly \Closure $isPrimary,private readonly ?\Closure $pump=null,private readonly ?\Closure $uploadHistory=null,private readonly bool $includePem=false,private readonly ?\Closure $clock=null) {}

    /** @return array{deviceJid:string,keyIndex:int} */
    public function linkCompanion(string $qr):array
    {
        $this->requirePrimary();$parsed=CompanionCrypto::parseQr($qr);
        return $this->completeLink($parsed['ref'],$parsed['identityPublicKey'],$parsed['noisePublicKey'],$parsed['advSecretKey'],$parsed['platform']);
    }
    /** Waits through the injected outer transport pump; state machine methods can also be driven separately.
     * @return array{deviceJid:string,keyIndex:int} */
    public function linkCompanionByCode(string $code):array
    {
        $this->beginCompanionByCode($code);
        if($this->codeSession!==null && !isset($this->codeSession['finish']) && $this->pump===null)throw new \LogicException('Drive incoming nodes then call finishCompanionByCode(), or provide a mobile transport pump');
        $this->busy=true;
        try {
            while($this->codeSession!==null && !isset($this->codeSession['finish'])) {
                if($this->now()>=$this->codeSession['deadline']){$this->codeSession=null;throw new \RuntimeException('Mobile companion_finish timed out');}
                if($this->pump!==null)($this->pump)(min(0.25,max(0.0,$this->codeSession['deadline']-$this->now())));
            }
            return $this->finishCompanionByCode();
        }finally{$this->busy=false;}
    }
    public function beginCompanionByCode(string $code):void
    {
        $credentials=$this->requirePrimary();
        if($this->hello===null || $this->now()-$this->hello['receivedAt']>180)throw new \LogicException('Companion must first request a fresh code for this primary account');
        if($this->codeSession!==null)throw new \LogicException('Mobile pairing-code flow already in progress');
        $prepared=CompanionCrypto::preparePrimaryHello($code,$this->hello['wrapped']);
        $this->codeSession=['hello'=>$this->hello,'prepared'=>$prepared,'deadline'=>$this->now()+120];
        try {
            $this->iq(new BinaryNode('link_code_companion_reg',['stage'=>'primary_hello'],[
                new BinaryNode('link_code_pairing_wrapped_primary_ephemeral_pub',[],$prepared['wrappedPrimaryEphemeralPub']),
                new BinaryNode('primary_identity_pub',[],$credentials['identityKeyPair']['public']),new BinaryNode('link_code_pairing_ref',[],$this->hello['ref'])]),120.0);
        }catch(\Throwable $error){$this->codeSession=null;throw $error;}
    }
    /** @return array{deviceJid:string,keyIndex:int} */
    public function finishCompanionByCode():array
    {
        $credentials=$this->requirePrimary();$session=$this->codeSession;
        if($session===null || !isset($session['finish']))throw new \LogicException('No companion_finish available');
        if($this->now()>=$session['deadline']){$this->codeSession=null;throw new \RuntimeException('Mobile companion_finish expired');}
        $finish=$session['finish'];
        $adv=CompanionCrypto::completePrimary($session['prepared']['sharedEphemeral'],$finish['bundle'],$finish['identity'],$credentials['identityKeyPair']);
        $this->codeSession=null;$this->hello=null;
        return $this->completeLink($session['hello']['ref'],$finish['identity'],$session['hello']['noise'],$adv,'link-code');
    }

    /** Records server pushes only; no query or peer-message send occurs during node handling. */
    public function handleNode(BinaryNode $node):bool
    {
        if($node->tag!=='notification' || !($this->isPrimary)())return false;
        $link=$node->child('link_code_companion_reg');$devices=$node->child('devices');
        if($link===null && (($node->attrs['type']??'')!=='account_sync' || $devices===null))return false;
        $this->requirePrimary();
        if(($node->attrs['from']??'')!=='s.whatsapp.net')throw new \UnexpectedValueException('Primary companion notifications must come from the authenticated server');
        if($devices!==null && ($node->attrs['type']??'')==='account_sync') {
            $ids=[];$indexes=[0];
            foreach(self::children($devices,'device') as $device) {
                if(!isset($device->attrs['jid']) || !self::sameAccount($device->attrs['jid'],($this->getCredentials)()))throw new \UnexpectedValueException('Account-sync device list contains an invalid account JID');
                $ids[]=$device->attrs['jid'];
                $index=$device->attrs['key-index']??null;if(is_string($index) && ctype_digit($index) && (float)$index<=0xffffffff)$indexes[]=(int)$index;
            }
            $state=$this->epoch();$state['accountKeyIndexes']=array_values(array_unique($indexes));$this->saveEpoch($state);
            $this->reconcileCompanions($ids,true);
        }
        if($link!==null) {
            $stage=$link->attrs['stage']??'';$ref=self::content($link->child('link_code_pairing_ref'),'pairing ref');
            if($stage==='companion_hello') {
                $wrapped=self::content($link->child('link_code_pairing_wrapped_companion_ephemeral_pub'),'wrapped companion ephemeral');$noise=self::content($link->child('companion_server_auth_key_pub'),'companion noise key');
                if(strlen($wrapped)!==80 || strlen($noise)!==32)throw new \UnexpectedValueException('Invalid mobile companion hello');
                $this->hello=['ref'=>$ref,'wrapped'=>$wrapped,'noise'=>$noise,'receivedAt'=>$this->now()];($this->emit)('companion_host_code_requested',[]);
            }elseif($stage==='companion_finish' && $this->codeSession!==null && $ref===$this->codeSession['hello']['ref']) {
                $identity=self::content($link->child('companion_identity_public'),'companion identity');$bundle=self::content($link->child('link_code_pairing_wrapped_key_bundle'),'wrapped key bundle');
                if(strlen($identity)!==32 || strlen($bundle)!==156)throw new \UnexpectedValueException('Invalid mobile companion finish');
                $this->codeSession['finish']=['identity'=>$identity,'bundle'=>$bundle];
            }
        }
        return false; // The normal notification dispatcher owns the ACK.
    }

    /** @return list<array<string,mixed>> */
    public function listCompanions():array { $this->requirePrimary();return $this->epoch()['companions']; }
    public function publishKeyIndexList():void
    {
        $credentials=$this->requirePrimary();$state=$this->epoch();$indexes=self::validIndexes($state);$timestamp=(int)$this->now();
        $bytes=CompanionCrypto::signedKeyIndexList($credentials['identityKeyPair'],$state['rawId'],max($indexes),$timestamp,$indexes);
        $this->iq(new BinaryNode('key-index-list',['ts'=>(string)$timestamp],$bytes),32.0);
    }
    public function revokeCompanion(string $jid,string $reason='user_initiated'):void
    {
        $this->requirePrimary();$record=$this->companion($jid);$this->iq(new BinaryNode('remove-companion-device',['jid'=>$record['deviceJid'],'reason'=>$reason]),32.0);
        $state=$this->epoch();$state['companions']=array_values(array_filter($state['companions'],static fn($c)=>$c['deviceJid']!==$record['deviceJid']));$state['accountKeyIndexes']=array_values(array_filter($state['accountKeyIndexes'],static fn($i)=>$i!==$record['keyIndex']));$this->saveEpoch($state);
        $this->store->delete('mobile-provision',$record['deviceJid']);
        ($this->emit)('companion_host_revoked',['deviceJid'=>$record['deviceJid']]);
        try{$this->publishKeyIndexList();}catch(\Throwable $error){($this->emit)('companion_host_error',['operation'=>'publish_key_indexes','error'=>$error->getMessage()]);}
    }
    public function revokeAllCompanions(string $reason='user_initiated',bool $excludeHostedCompanion=false):void
    {
        $this->requirePrimary();$attrs=['all'=>'true','reason'=>$reason];if($excludeHostedCompanion)$attrs['exclude_hosted_companion']='true';$this->iq(new BinaryNode('remove-companion-device',$attrs),32.0);
        $state=$this->epoch();$kept=[];$removed=[];
        foreach($state['companions'] as $record) {
            $hosted=in_array(Jid::parse($record['deviceJid'])->server,['hosted','hosted.lid'],true);
            if($excludeHostedCompanion && $hosted)$kept[]=$record;else $removed[]=$record;
        }
        $state['companions']=$kept;$state['accountKeyIndexes']=[0,...array_column($kept,'keyIndex')];$this->saveEpoch($state);
        foreach($removed as $record){$this->store->delete('mobile-provision',$record['deviceJid']);($this->emit)('companion_host_revoked',['deviceJid'=>$record['deviceJid']]);}
        try{$this->publishKeyIndexList();}catch(\Throwable $error){($this->emit)('companion_host_error',['operation'=>'publish_key_indexes','error'=>$error->getMessage()]);}
    }

    /** If no device list is supplied, queries the authenticated server's current usync list.
     * @param list<string> $deviceJids
     * @return list<string> */
    public function reconcileCompanions(array $deviceJids=[],bool $authoritative=false):array
    {
        $credentials=$this->requirePrimary();$state=$this->epoch();if($state['companions']===[])return [];
        if(!$authoritative && $deviceJids===[]) {
            $jid=Jid::parse($credentials['meJid'])->bare();
            $request=new BinaryNode('iq',['to'=>'s.whatsapp.net','type'=>'get','xmlns'=>'usync'],[new BinaryNode('usync',['sid'=>bin2hex(random_bytes(12)),'index'=>'0','last'=>'true','mode'=>'query','context'=>'interactive'],[new BinaryNode('query',[],[new BinaryNode('devices',['version'=>'2'])]),new BinaryNode('list',[],[new BinaryNode('user',['jid'=>$jid])])])]);
            $response=($this->query)($request,30.0);self::assertIq($response);$user=$response->child('usync')?->child('list')?->child('user');
            if($user===null || !self::sameAccount($user->attrs['jid']??'',$credentials))throw new \UnexpectedValueException('Mobile device list omitted the primary account');
            $list=$user->child('devices')?->child('device-list')??throw new \UnexpectedValueException('Mobile device list missing');
            foreach(self::children($list,'device') as $device) {
                $id=$device->attrs['id']??'';if(!ctype_digit($id) || (int)$id>65535)throw new \UnexpectedValueException('Invalid mobile device slot');
                $deviceJids[]=(string)new Jid(Jid::parse($jid)->user,($device->attrs['is_hosted']??'')==='true'?'hosted':'s.whatsapp.net',(int)$id);
            }
        }
        $slots=[0];foreach($deviceJids as $jid){if(!self::sameAccount($jid,$credentials))throw new \UnexpectedValueException('Cannot reconcile companions with another account device list');$slots[]=Jid::parse($jid)->device;}
        $kept=[];$removed=[];
        foreach($state['companions'] as $record)if(in_array(Jid::parse($record['deviceJid'])->device,$slots,true))$kept[]=$record;else $removed[]=$record;
        if($removed!==[]){$state['companions']=$kept;$removedIndexes=array_column($removed,'keyIndex');$state['accountKeyIndexes']=array_values(array_filter($state['accountKeyIndexes'],static fn($i)=>!in_array($i,$removedIndexes,true)));$this->saveEpoch($state);foreach($removed as $record){$this->store->delete('mobile-provision',$record['deviceJid']);($this->emit)('companion_host_revoked',['deviceJid'=>$record['deviceJid']]);}}
        return array_column($removed,'deviceJid');
    }
    public function shareAppStateSyncKeys(string $jid):void
    {
        $this->requirePrimary();$record=$this->companion($jid);$key=$this->appState->activeKey()??throw new \LogicException('Primary app-state sync key is not initialized');
        ($this->sendProtocolToDevice)($record['deviceJid'],['protocolMessage'=>['type'=>'APP_STATE_SYNC_KEY_SHARE','appStateSyncKeyShare'=>['keys'=>[['keyId'=>['keyId'=>$key['keyId']],'keyData'=>array_filter(['keyData'=>$key['keyData'],'timestamp'=>$key['timestamp'],'fingerprint'=>$key['fingerprint']??null],static fn($v)=>$v!==null)]]]]]);
    }
    /** @param array<string,mixed> $options */
    public function sendHistorySyncBootstrap(string $jid,array $options=[]):void
    {
        $this->requirePrimary();$record=$this->companion($jid);$built=HistoryBootstrap::build($options,$this->uploadHistory);
        ($this->sendProtocolToDevice)($record['deviceJid'],$built['message']);
    }
    /** Initializes only this primary's own key inventory; companion sessions must import their phone's keys. */
    public function initializeAppState():void
    {
        $credentials=$this->requirePrimary();$this->appState->setPrimaryMode(true);
        if($this->appState->activeKey()===null)$this->appState->importKeys([['keyId'=>pack('nN',0,random_int(1,65536)),'keyData'=>random_bytes(32),'timestamp'=>(int)floor($this->now()*1000),'fingerprint'=>['rawId'=>random_int(0,0xffffffff),'currentIndex'=>0,'deviceIndexes'=>[0]]]]);
        if($this->store->get('mobile','push-name-seeded')===null) {
            $name=$credentials['pushName']??Jid::parse($credentials['meJid'])->user;$this->appState->setPushName(is_string($name)?$name:'');$this->store->set('mobile','push-name-seeded','1');
        }
    }
    /** Retried provisioning outside node dispatch, persisted across restarts. */
    public function drainPending():void
    {
        if($this->busy || !($this->isPrimary)())return;
        foreach($this->store->entries('mobile-provision',100) as $jid=>$bytes) {
            $job=BinaryData::decode($bytes);if(!is_array($job))throw new \UnexpectedValueException('Invalid mobile provisioning job');
            if(($job['nextAttempt']??0)>$this->now())continue;
            try {
                if(!($job['historyDone']??false)){$this->sendHistorySyncBootstrap((string)$jid);$job['historyDone']=true;$this->store->set('mobile-provision',(string)$jid,BinaryData::encode($job));}
                $this->shareAppStateSyncKeys((string)$jid);$this->store->delete('mobile-provision',(string)$jid);($this->emit)('companion_host_provisioned',['deviceJid'=>(string)$jid]);
            }catch(\Throwable $error){$job['attempt']=($job['attempt']??0)+1;$job['nextAttempt']=$job['attempt']>=8?PHP_INT_MAX:$this->now()+1.5;$job['error']=$error->getMessage();$this->store->set('mobile-provision',(string)$jid,BinaryData::encode($job));($this->emit)('companion_host_error',['deviceJid'=>(string)$jid,'attempt'=>$job['attempt'],'error'=>$error->getMessage()]);}
        }
    }

    /** @return array{deviceJid:string,keyIndex:int} */
    private function completeLink(string $ref,string $identity,string $noise,string $adv,string $platform):array
    {
        $credentials=$this->requirePrimary();$this->initializeAppState();$state=$this->epoch();$valid=self::validIndexes($state);$index=max($state['currentKeyIndex'],max($valid))+1;
        if($index>0xffffffff)throw new \OverflowException('Primary companion key indexes exhausted');
        // Reserve before network I/O: a lost pair-device acknowledgment must never cause index reuse.
        $state['currentKeyIndex']=$index;$this->saveEpoch($state);$timestamp=(int)$this->now();$valid[]=$index;sort($valid);
        $signed=CompanionCrypto::signedCompanion($credentials['identityKeyPair'],$identity,$adv,$state['rawId'],$index,$timestamp,$valid);
        $lidNative=is_string($credentials['meLid']??null) && $credentials['meLid']!=='';
        $children=[new BinaryNode('ref',[],$ref),new BinaryNode('pub-key',[],$noise),new BinaryNode('device-identity',[],$signed['deviceIdentityBytes']),new BinaryNode('key-index-list',['ts'=>(string)$timestamp],$signed['keyIndexListBytes']),new BinaryNode('client-props',[],SchemaCodec::encode('ClientPairingProps',['isChatDbLidMigrated'=>$lidNative,'isSyncdPureLidSession'=>$lidNative,'isSyncdSnapshotRecoveryEnabled'=>false]))];
        if($this->includePem)$children[]=new BinaryNode('pem',['version'=>'1','algorithm'=>'rsa2048'],[new BinaryNode('pem',[],CompanionCrypto::pemKey($adv,$noise)),new BinaryNode('ttl',['ts_s'=>'432000']),new BinaryNode('key_id',[],'1')]);
        $response=$this->iq(new BinaryNode('pair-device',[],$children),32.0);$device=$response->child('device');
        if($device===null && is_array($response->content))foreach($response->content as $wrapper)if(($device=$wrapper->child('device'))!==null)break;
        $jid=$device?->attrs['jid']??'';
        if($jid==='' || !self::sameAccount($jid,$credentials) || Jid::parse($jid)->device===0)throw new \UnexpectedValueException('Pair-device response missing a valid own-account companion device');
        $state=$this->epoch();$record=['deviceJid'=>$jid,'keyIndex'=>$index,'companionIdentityPublicKey'=>$identity,'addedAtSeconds'=>$timestamp,'platform'=>$platform];
        $state['companions']=array_values(array_filter($state['companions'],static fn($c)=>$c['deviceJid']!==$jid));$state['companions'][]=$record;
        $this->store->transaction(function()use($state,$jid):void{$this->saveEpoch($state);$this->store->set('mobile-provision',$jid,BinaryData::encode(['attempt'=>0,'nextAttempt'=>0,'historyDone'=>false]));});
        $result=['deviceJid'=>$jid,'keyIndex'=>$index];($this->emit)('companion_host_linked',$result);return $result;
    }
    /** @return array<string,mixed> */
    private function requirePrimary():array
    {
        if(!($this->isPrimary)())throw new \LogicException('Mobile companion hosting requires an explicitly configured registered primary session');
        $credentials=($this->getCredentials)();PrimaryCredentials::validate($credentials);return $credentials;
    }
    /** @return array<string,mixed> */
    private function epoch():array
    {
        $bytes=$this->store->get('mobile','epoch');
        if($bytes!==null){$state=BinaryData::decode($bytes);if(!is_array($state) || !is_int($state['rawId']??null) || !is_int($state['currentKeyIndex']??null) || !is_array($state['companions']??null))throw new \UnexpectedValueException('Invalid primary companion epoch');$state['accountKeyIndexes']??=[0];return $state;}
        $state=['rawId'=>random_int(1,0x7fffffff),'currentKeyIndex'=>0,'companions'=>[],'accountKeyIndexes'=>[0]];$this->saveEpoch($state);return $state;
    }
    /** @param array<string,mixed> $state */
    private function saveEpoch(array $state):void { $this->store->set('mobile','epoch',BinaryData::encode($state)); }
    /** @param array<string,mixed> $state
     * @return list<int> */
    private static function validIndexes(array $state):array { $indexes=array_values(array_unique([0,...($state['accountKeyIndexes']??[]),...array_column($state['companions'],'keyIndex')]));sort($indexes);return $indexes; }
    /** @return array<string,mixed> */
    private function companion(string $jid):array { $this->requirePrimary();foreach($this->epoch()['companions'] as $record)if($record['deviceJid']===$jid)return $record;throw new \InvalidArgumentException('Companion is not tracked by this primary'); }
    private function iq(BinaryNode $node,float $timeout):BinaryNode { $result=($this->query)(new BinaryNode('iq',['to'=>'s.whatsapp.net','type'=>'set','xmlns'=>'md'],[$node]),$timeout);self::assertIq($result);return $result; }
    private static function assertIq(BinaryNode $node):void { if($node->tag!=='iq' || ($node->attrs['type']??'')!=='result' || $node->child('error')!==null)throw new \RuntimeException('Mobile primary IQ failed'); }
    /** @param array<string,mixed> $credentials */
    private static function sameAccount(string $jid,array $credentials):bool { try{$candidate=Jid::parse($jid)->canonical()->bare();return $candidate===Jid::parse($credentials['meJid'])->canonical()->bare() || (is_string($credentials['meLid']??null) && $candidate===Jid::parse($credentials['meLid'])->canonical()->bare());}catch(\InvalidArgumentException){return false;} }
    private static function content(?BinaryNode $node,string $name):string { if($node===null || !is_string($node->content) || $node->content==='')throw new \UnexpectedValueException('Mobile notification missing '.$name);return $node->content; }
    /** @return list<BinaryNode> */
    private static function children(?BinaryNode $node,string $tag):array { return array_values(array_filter(is_array($node?->content)?$node->content:[],static fn(BinaryNode $c)=>$c->tag===$tag)); }
    private function now():float { return $this->clock===null?microtime(true):($this->clock)(); }
}
