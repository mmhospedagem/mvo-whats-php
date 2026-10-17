<?php
declare(strict_types=1);
namespace MVO\Testing;

use MVO\Crypto\{Crypto,XEdDsa};
use MVO\Protocol\{BinaryCodec,BinaryData,BinaryNode,Jid,SchemaCodec};
use MVO\Service\ServiceTransport;
use MVO\Signal\{SignalState,SignalStore};

/** Stateful, explicit offline account registry and IQ router. Unknown operations fail with 501. */
final class FakeProtocol
{
    private readonly SignalStore $store;
    /** @var array<string,array<string,mixed>> */
    private array $accounts=[];
    /** @var array<string,string> */
    private array $aliases=[];
    /** @var array<string,list<array{id:int,public:string}>> */
    private array $prekeys=[];
    /** @var array<string,FakePeer> */
    private array $peers=[];
    /** @var array<string,array<string,mixed>> */
    private array $groups=[];
    /** @var array<string,list<BinaryNode>> */
    private array $outbound=[];
    /** @var list<array{sender:string,node:BinaryNode}> */
    private array $captured=[];
    /** @var list<int> */
    private array $capturedSizes=[];
    private int $capturedBytes=0;
    private int $droppedCaptures=0;
    /** @var array<string,\Closure(BinaryNode,string):?list<BinaryNode>> */
    private array $routes=[];
    private ?FakePairing $pairing=null;
    private ?FakeAppState $appState=null;
    private ?FakeMediaServer $media=null;
    public function __construct(?SignalStore $store=null,private readonly int $maxCapturedBytes=16777216)
    {
        if($maxCapturedBytes<1024||$maxCapturedBytes>268435456)throw new \InvalidArgumentException('Invalid fake capture bound');
        $this->store=$store ?? new SignalStore(':memory:','fake-server');$saved=$this->store->get('fake-server','state');if($saved!==null){$state=BinaryData::decode($saved);if(!is_array($state))throw new \UnexpectedValueException('Invalid fake server state');$this->accounts=$state['accounts'];$this->aliases=$state['aliases'];$this->prekeys=$state['prekeys'];$this->groups=$state['groups'];}
    }
    public function configurePairing(FakePairing $pairing):void {$this->pairing=$pairing;}
    public function pairing():?FakePairing {return $this->pairing;}
    public function configureAppState(FakeAppState $state):void {$this->appState=$state;}
    public function configureMedia(FakeMediaServer $media):void {$this->media=$media;}
    /** @param callable(BinaryNode,string):?list<BinaryNode> $handler */
    public function route(string $namespace,callable $handler):void {$this->routes[$namespace]=\Closure::fromCallable($handler);}
    /** Registers only public material; private keys supplied by the fixture are discarded.
     * @param array<string,mixed> $credentials */
    public function registerClient(array $credentials):void
    {
        $jid=(string)Jid::parse($credentials['meJid'] ?? '')->canonical();$identity=SignalState::rawPublicKey($credentials['identityKeyPair']['public'] ?? '');$signed=$credentials['signedPreKey'] ?? [];$public=SignalState::rawPublicKey($signed['public'] ?? '');$signature=$signed['signature'] ?? '';$id=$signed['id'] ?? null;$registration=$credentials['registrationId'] ?? null;
        if(!is_int($id)||$id<1||$id>0xffffff||!is_int($registration)||$registration<1||$registration>0xffffffff||!is_string($signature)||!XEdDsa::verify($identity,"\x05".$public,$signature))throw new \InvalidArgumentException('Invalid fake public Signal registration');
        $noise=$credentials['noiseKeyPair']['public'] ?? null;if($noise!==null)Crypto::length($noise,32);$old=$this->accounts[$jid] ?? null;if($old!==null&&!hash_equals($old['identityKeyPair']['public'],$identity))throw new \LogicException('Fake account identity replacement requires a separate registry');
        $record=['meJid'=>$jid,'registrationId'=>$registration,'identityKeyPair'=>['public'=>$identity],'signedPreKey'=>['id'=>$id,'public'=>$public,'signature'=>$signature]];if($noise!==null)$record['noiseKeyPair']=['public'=>$noise];
        $this->accounts[$jid]=$record;$this->aliases[$jid]=$jid;$this->prekeys[$jid] ??= [];
        if(is_string($credentials['meLid'] ?? null)){$lid=Jid::parse($credentials['meLid'])->canonical();$pn=Jid::parse($jid);$alias=(string)new Jid($lid->user,'lid',$pn->device);$this->aliases[$alias]=$jid;$this->accounts[$jid]['meLid']=$alias;}$this->persist();
    }
    public function createPeer(string $jid,string $path=':memory:'):FakePeer
    {
        $address=Jid::parse($jid)->canonical();$jid=(string)$address;if(isset($this->peers[$jid]))throw new \LogicException('Fake peer already exists');$primary=$this->peers[$address->bare()] ?? null;if($address->device!==0&&$primary===null)throw new \LogicException('Create the fake primary peer before its companion devices');$peer=new FakePeer($this,$jid,$path);if($primary!==null)$peer->certifyCompanion($primary);$this->registerClient($peer->registration());$this->peers[$jid]=$peer;return $peer;
    }
    /** Authenticate the decrypted login payload against a fixture's registered Noise public key. */
    public function authenticate(string $bytes,string $clientStatic):string
    {
        $payload=SchemaCodec::decode('ClientPayload',$bytes);$username=$payload['username'] ?? null;
        if($username===null){if($this->pairing===null)throw new \UnexpectedValueException('Fake account is not registered and pairing is not configured');foreach($this->pairing->start($bytes,$clientStatic) as $node)$this->deliver('__pairing__',$node);return '__pairing__';}
        $device=$payload['device'] ?? 0;if((!is_int($username)&&!is_string($username))||!ctype_digit((string)$username)||!is_int($device))throw new \UnexpectedValueException('Invalid fake login identity');$jid=(string)new Jid((string)$username,'s.whatsapp.net',$device);$record=$this->accounts[$jid] ?? null;
        if($record===null||!is_string($record['noiseKeyPair']['public'] ?? null)||!hash_equals($record['noiseKeyPair']['public'],$clientStatic))throw new \UnexpectedValueException('Unregistered fake account or Noise identity mismatch');return $jid;
    }
    public function isRegistered(string $device):bool {return isset($this->accounts[$device]);}
    /** @param list<string> $users
     * @return array<string,list<string>> */
    public function devices(array $users):array
    {
        $result=[];foreach($users as $user){$bare=Jid::parse($user)->canonical()->bare();$devices=[];foreach($this->aliases as $alias=>$canonical)if(Jid::parse($alias)->bare()===$bare)$devices[]=$alias;sort($devices,SORT_STRING);$result[$bare]=array_values(array_unique($devices));}return $result;
    }
    /** @return array{registrationId:int,identityKey:string,signedPreKey:array{id:int,public:string,signature:string},preKey?:array{id:int,public:string}} */
    public function bundle(string $device):array
    {
        $device=(string)Jid::parse($device)->canonical();$canonical=$this->aliases[$device] ?? $device;if(isset($this->peers[$canonical]))return $this->peers[$canonical]->bundle();$record=$this->accounts[$canonical] ?? throw new \OutOfBoundsException('Unknown fake device');$bundle=['registrationId'=>$record['registrationId'],'identityKey'=>$record['identityKeyPair']['public'],'signedPreKey'=>$record['signedPreKey']];$key=array_shift($this->prekeys[$canonical]);if($key!==null)$bundle['preKey']=$key;$this->persist();return $bundle;
    }
    /** @param list<string> $participants */
    public function createGroup(string $jid,string $subject,array $participants,string $addressingMode='pn'):void
    {
        $jid=Jid::parse($jid)->canonical()->bare();if(!str_ends_with($jid,'@g.us')||!in_array($addressingMode,['pn','lid'],true)||$subject==='')throw new \InvalidArgumentException('Invalid fake group');$members=[];foreach($participants as $member){$bare=Jid::parse($member)->canonical()->bare();if(($this->devices([$bare])[$bare] ?? [])===[])throw new \InvalidArgumentException('Fake group member is not registered');$members[$bare]='member';}$this->groups[$jid]=['id'=>$jid,'subject'=>$subject,'mode'=>$addressingMode,'participants'=>$members];$this->persist();
    }
    /** @return list<array{sender:string,node:BinaryNode}> */
    public function captured():array {return $this->captured;}
    /** @return array{bytes:int,dropped:int,limit:int} */
    public function captureInfo():array {return ['bytes'=>$this->capturedBytes,'dropped'=>$this->droppedCaptures,'limit'=>$this->maxCapturedBytes];}
    public function query(BinaryNode $node,string $sender):BinaryNode {$out=$this->dispatch($node,$sender);foreach($out as $result)if($result->tag==='iq'){ServiceTransport::assertResult($result,'fake.peer.query');return $result;}throw new \UnexpectedValueException('Fake peer query has no IQ result');}
    /** @return list<BinaryNode> */
    public function dispatch(BinaryNode $node,string $sender):array
    {
        if($sender==='__pairing__'&&$this->pairing!==null){$response=$this->pairing->handle($node);$credentials=$this->pairing->completeCredentials();if($credentials!==null)$this->registerClient($credentials);return $response ?? [];}
        if(!$this->isRegistered($this->aliases[$sender] ?? $sender))throw new \UnexpectedValueException('Fake stanza sender is not authenticated');$this->capture($sender,$node);
        if($node->tag==='message'){$this->routeMessage($sender,$node);return [new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id'] ?? '','from'=>$node->attrs['to'] ?? 's.whatsapp.net','t'=>(string)time()])];}
        if($node->tag==='receipt'){return [new BinaryNode('ack',['class'=>'receipt','id'=>$node->attrs['id'] ?? '','to'=>$sender])];}
        if($node->tag!=='iq')return [];$namespace=$node->attrs['xmlns'] ?? '';if(isset($this->routes[$namespace])){$result=($this->routes[$namespace])($node,$sender);if($result!==null)return $result;}
        try{
            if(in_array($namespace,['w:p','urn:xmpp:ping'],true))return [self::result($node)];
            if($namespace==='passive'&&($node->child('active')!==null||$node->child('passive')!==null))return [self::result($node)];
            if($namespace==='abt'&&$node->child('props')!==null)return [self::result($node,[new BinaryNode('props',['protocol'=>'1','hash'=>'fake-abprops-v1','refresh'=>'86400','refresh_id'=>'1','delta_update'=>'false'])])];
            if($namespace==='usync')return [$this->usync($node)];
            if($namespace==='encrypt')return [$this->encryptIq($node,$sender)];
            if($namespace==='w:g2')return [$this->groupIq($node,$sender)];
            if($namespace==='privacy'&&$node->child('tokens')!==null){foreach(ServiceTransport::children($node->child('tokens'),'token') as $token){$to=$token->attrs['jid'] ?? '';if(($this->devices([$to])[$to] ?? [])===[])return [self::error($node,404,'fake-unknown-token-recipient')];$this->store->set('fake-privacy-token',hash('sha256',$sender."\0".$to),BinaryData::encode(['sender'=>$sender,'recipient'=>$to,'timestamp'=>$token->attrs['t'] ?? '']));}return [self::result($node)];}
            if($namespace==='w:sync:app:state'&&$node->child('sync')!==null){if($this->appState!==null)return [$this->appState->handle($node)];$collections=[];foreach(ServiceTransport::children($node->child('sync'),'collection') as $collection){if($collection->child('patch')!==null)return [self::error($node,501,'fake-appstate-keys-not-configured')];$collections[]=new BinaryNode('collection',['name'=>$collection->attrs['name'] ?? '','version'=>'0','has_more_patches'=>'false']);}return [self::result($node,[new BinaryNode('sync',[],$collections)])];}
            if($namespace==='w:m'&&$node->child('media_conn')!==null&&$this->media!==null)return [self::result($node,[$this->media->connectionNode()])];
        }catch(\InvalidArgumentException|\UnexpectedValueException $error){return [self::error($node,400,'fake-invalid-protocol-input')];}
        return [self::error($node,501,'fake-route-not-implemented')];
    }
    public function routeMessage(string $sender,BinaryNode $node):void
    {
        $senderCanonical=$this->aliases[$sender] ?? $sender;if(!$this->isRegistered($senderCanonical))throw new \InvalidArgumentException('Fake message sender is not authenticated');$to=$node->attrs['to'] ?? '';if(!isset($node->attrs['id'])||$to==='')throw new \InvalidArgumentException('Fake message identity missing');$group=str_ends_with($to,'@g.us')||str_ends_with($to,'@broadcast');$destinations=[];
        foreach(ServiceTransport::children($node->child('participants'),'to') as $participant){$device=$participant->attrs['jid'] ?? '';if(isset($destinations[$device]))throw new \InvalidArgumentException('Duplicate fake fanout destination');$destinations[$device]=ServiceTransport::children($participant,'enc');}
        if($group){$members=$this->groups[$to]['participants'] ?? null;if($members===null)throw new \InvalidArgumentException('Unknown fake group');$allowed=[];foreach($this->devices(array_keys($members)) as $devices)foreach($devices as $device)$allowed[$device]=true;$member=false;foreach(array_keys($allowed) as $device)if(($this->aliases[$device] ?? $device)===$senderCanonical)$member=true;if(!$member)throw new \InvalidArgumentException('Fake group sender is not a member');foreach(array_keys($destinations) as $device)if(!isset($allowed[$device])||($this->aliases[$device] ?? $device)===$senderCanonical)throw new \InvalidArgumentException('Fake group fanout targets a non-member or the current sender device');foreach(array_keys($allowed) as $device)if(($this->aliases[$device] ?? $device)!==$senderCanonical)$destinations[$device] ??= [];}
        elseif($destinations===[]){foreach($this->devices([$to])[$to] ?? [] as $device)$destinations[$device]=ServiceTransport::children($node,'enc');if(isset($this->aliases[$to]))$destinations[$to]=ServiceTransport::children($node,'enc');}
        foreach($destinations as $device=>$envelopes){if($device==='')throw new \InvalidArgumentException('Invalid fake message device');$canonical=$this->aliases[$device] ?? $device;if(!isset($this->accounts[$canonical]))throw new \InvalidArgumentException('Unknown fake message device');$children=$envelopes;if($group)$children=[...$children,...ServiceTransport::children($node,'enc')];$attrs=['id'=>$node->attrs['id'],'from'=>$group ? $to : $sender,'type'=>$node->attrs['type'] ?? 'text','t'=>(string)time()];if($group)$attrs['participant']=$sender;foreach(['category','push_priority','edit'] as $key)if(isset($node->attrs[$key]))$attrs[$key]=$node->attrs[$key];$incoming=new BinaryNode('message',$attrs,$children);
            if(isset($this->peers[$canonical])){$this->peers[$canonical]->receive($sender,$incoming);$this->deliver($sender,new BinaryNode('receipt',['id'=>$node->attrs['id'],'from'=>$group ? $to : $device]+($group ? ['participant'=>$device] : [])));}else $this->deliver($canonical,$incoming);}
    }
    public function deliver(string $device,BinaryNode $node):void {$key=$this->aliases[$device] ?? $device;if(count($this->outbound[$key] ?? [])>=1000)throw new \LengthException('Fake outbound queue limit');$this->outbound[$key][]=$node;}
    /** @return list<BinaryNode> */
    public function drainOutbound(string $device):array {$key=$this->aliases[$device] ?? $device;$nodes=$this->outbound[$key] ?? [];unset($this->outbound[$key]);return $nodes;}
    private function usync(BinaryNode $iq):BinaryNode
    {
        $sync=$iq->child('usync') ?? throw new \InvalidArgumentException('Missing usync');$users=[];$protocols=ServiceTransport::children($sync->child('query'));
        foreach(ServiceTransport::children($sync->child('list'),'user') as $user){$jid=$user->attrs['jid'] ?? '';if($jid===''&&is_string($user->child('contact')?->content))$jid=ltrim($user->child('contact')->content,'+').'@s.whatsapp.net';$bare=Jid::parse($jid)->canonical()->bare();$devices=$this->devices([$bare])[$bare];$children=[];
            foreach($protocols as $protocol){if($protocol->tag==='devices')$children[]=new BinaryNode('devices',[],[new BinaryNode('device-list',[],array_map(static fn(string $d):BinaryNode=>new BinaryNode('device',['id'=>(string)Jid::parse($d)->device]),$devices))]);
                elseif($protocol->tag==='contact')$children[]=new BinaryNode('contact',['type'=>$devices===[] ? 'out' : 'in'],is_string($user->child('contact')?->content) ? $user->child('contact')->content : '+'.Jid::parse($bare)->user);
                elseif($protocol->tag==='lid'){foreach($devices as $device){$record=$this->accounts[$this->aliases[$device] ?? $device];if(isset($record['meLid'])){$children[]=new BinaryNode('lid',['val'=>Jid::parse($record['meLid'])->user]);break;}}}
                else $children[]=new BinaryNode($protocol->tag,[],[new BinaryNode('error',['code'=>'501'])]);}
            $users[]=new BinaryNode('user',['jid'=>$bare],$children);}
        return self::result($iq,[new BinaryNode('usync',['sid'=>$sync->attrs['sid'] ?? 'fake-usync','index'=>'0','last'=>'true'],[new BinaryNode('list',[],$users)])]);
    }
    private function encryptIq(BinaryNode $iq,string $sender):BinaryNode
    {
        $sender=$this->aliases[$sender] ?? $sender;$record=$this->accounts[$sender];
        if(($iq->attrs['type'] ?? '')==='get'&&$iq->child('key')!==null){$users=[];foreach(ServiceTransport::children($iq->child('key'),'user') as $user){$jid=$user->attrs['jid'] ?? '';try{$bundle=$this->bundle($jid);}catch(\OutOfBoundsException){$users[]=new BinaryNode('user',['jid'=>$jid],[new BinaryNode('error',['code'=>'404'])]);continue;}$children=[new BinaryNode('registration',[],pack('N',$bundle['registrationId'])),new BinaryNode('identity',[],$bundle['identityKey']),self::signedNode($bundle['signedPreKey'])];if(isset($bundle['preKey']))$children[]=new BinaryNode('key',[],[new BinaryNode('id',[],self::idBytes($bundle['preKey']['id'])),new BinaryNode('value',[],$bundle['preKey']['public'])]);$users[]=new BinaryNode('user',['jid'=>$jid],$children);}return self::result($iq,[new BinaryNode('list',[],$users)]);}
        if($iq->child('digest')!==null){$signed=$record['signedPreKey'];$keys=$this->prekeys[$sender];$hash=$record['identityKeyPair']['public'].$signed['public'].$signed['signature'];foreach($keys as $key)$hash.=$key['public'];return self::result($iq,[new BinaryNode('digest',[],[new BinaryNode('registration',[],pack('N',$record['registrationId'])),new BinaryNode('type',[],"\x05"),new BinaryNode('identity',[],$record['identityKeyPair']['public']),self::signedNode($signed),new BinaryNode('list',[],array_map(static fn(array $k):BinaryNode=>new BinaryNode('id',[],self::idBytes($k['id'])),$keys)),new BinaryNode('hash',[],hash('sha1',$hash,true))])]);}
        $rotate=$iq->child('rotate');$signed=$rotate?->child('skey') ?? $iq->child('skey');$parsed=null;if($signed!==null){$parsed=['id'=>self::uint(self::bytes($signed->child('id'),3)),'public'=>self::bytes($signed->child('value'),32),'signature'=>self::bytes($signed->child('signature'),64)];if($parsed['id']<1||!XEdDsa::verify($record['identityKeyPair']['public'],"\x05".$parsed['public'],$parsed['signature']))return self::error($iq,406,'fake-signed-key-signature-invalid');}
        if($rotate!==null){if($parsed===null)throw new \InvalidArgumentException('Missing rotated key');$this->accounts[$sender]['signedPreKey']=$parsed;$this->persist();return self::result($iq);}
        if($iq->child('list')!==null){if(!hash_equals($record['identityKeyPair']['public'],self::bytes($iq->child('identity'),32))||self::uint(self::bytes($iq->child('registration'),4))!==$record['registrationId']||self::bytes($iq->child('type'),1)!=="\x05")return self::error($iq,409,'fake-key-upload-identity-mismatch');$keys=[];foreach(ServiceTransport::children($iq->child('list'),'key') as $key){$id=self::uint(self::bytes($key->child('id'),3));if($id<1||isset($keys[$id]))throw new \InvalidArgumentException('Duplicate/invalid uploaded key');$keys[$id]=['id'=>$id,'public'=>self::bytes($key->child('value'),32)];if(count($keys)>1000)throw new \LengthException('Fake prekey upload limit');}if($parsed!==null)$this->accounts[$sender]['signedPreKey']=$parsed;$this->prekeys[$sender]=array_values($keys);$this->persist();return self::result($iq);}
        return self::error($iq,501,'fake-encrypt-route-not-implemented');
    }
    private function groupIq(BinaryNode $iq,string $sender):BinaryNode
    {
        $to=$iq->attrs['to'] ?? '';if($iq->child('participating')!==null){$groups=[];foreach($this->groups as $group)if(isset($group['participants'][Jid::parse($sender)->bare()]))$groups[]=$this->groupNode($group);return self::result($iq,[new BinaryNode('groups',[],$groups)]);}
        $group=$this->groups[$to] ?? null;if($group===null)return self::error($iq,404,'fake-group-not-found');if(!isset($group['participants'][Jid::parse($sender)->bare()]))return self::error($iq,403,'fake-not-a-group-member');
        if($iq->child('query')!==null)return self::result($iq,[$this->groupNode($group)]);
        if($iq->child('subject')!==null){$subject=$iq->child('subject')->content;if(!is_string($subject)||$subject==='')throw new \InvalidArgumentException('Invalid group subject');$this->groups[$to]['subject']=$subject;$this->persist();return self::result($iq);}
        return self::error($iq,501,'fake-group-route-not-implemented');
    }
    /** @param array<string,mixed> $group */
    private function groupNode(array $group):BinaryNode {return new BinaryNode('group',['id'=>explode('@',$group['id'])[0],'subject'=>$group['subject'],'addressing_mode'=>$group['mode']],array_map(static fn(string $jid,string $role):BinaryNode=>new BinaryNode('participant',['jid'=>$jid]+($role==='admin' ? ['type'=>'admin'] : [])),array_keys($group['participants']),array_values($group['participants'])));}
    private function persist():void {$this->store->set('fake-server','state',BinaryData::encode(['accounts'=>$this->accounts,'aliases'=>$this->aliases,'prekeys'=>$this->prekeys,'groups'=>$this->groups]));}
    private function capture(string $sender,BinaryNode $node):void
    {
        $bytes=strlen((new BinaryCodec())->encode($node));if($bytes>$this->maxCapturedBytes){$this->droppedCaptures++;return;}while($this->captured!==[]&&(count($this->captured)>=10000||$this->capturedBytes+$bytes>$this->maxCapturedBytes)){$this->capturedBytes-=array_shift($this->capturedSizes);array_shift($this->captured);$this->droppedCaptures++;}$this->captured[]=['sender'=>$sender,'node'=>$node];$this->capturedSizes[]=$bytes;$this->capturedBytes+=$bytes;
    }
    /** @param list<BinaryNode>|null $children */
    public static function result(BinaryNode $iq,?array $children=null):BinaryNode {return new BinaryNode('iq',['type'=>'result','id'=>$iq->attrs['id'] ?? '','from'=>$iq->attrs['to'] ?? 's.whatsapp.net'],$children);}
    public static function error(BinaryNode $iq,int $code,string $text):BinaryNode {return new BinaryNode('iq',['type'=>'error','id'=>$iq->attrs['id'] ?? '','from'=>$iq->attrs['to'] ?? 's.whatsapp.net'],[new BinaryNode('error',['code'=>(string)$code,'text'=>$text])]);}
    /** @param array{id:int,public:string,signature:string} $key */
    private static function signedNode(array $key):BinaryNode {return new BinaryNode('skey',[],[new BinaryNode('id',[],self::idBytes($key['id'])),new BinaryNode('value',[],$key['public']),new BinaryNode('signature',[],$key['signature'])]);}
    private static function bytes(?BinaryNode $node,int $length):string {if(!is_string($node?->content)||strlen($node->content)!==$length)throw new \InvalidArgumentException('Invalid fake key bytes');return $node->content;}
    private static function idBytes(int $id):string {return substr(pack('N',$id),1);}
    private static function uint(string $bytes):int {$id=0;foreach(str_split($bytes) as $b)$id=($id<<8)|ord($b);return $id;}
    public function close():void {foreach($this->peers as $peer)$peer->close();$this->peers=[];$this->store->close();}
}
