<?php
declare(strict_types=1);
namespace MVO\Testing;

use MVO\Auth\CredentialStore;
use MVO\Crypto\XEdDsa;
use MVO\Mobile\CompanionCrypto;
use MVO\Message\{ContentBuilder,ContentDecoder,GroupTransport,MessageCodec};
use MVO\Protocol\{BinaryCodec,BinaryData,BinaryNode,Jid,SchemaCodec};
use MVO\Signal\{SignalProtocol,SignalState,SignalStore};

/** Actual Signal device for offline integration tests; all plaintext is exposed only to explicit test APIs. */
final class FakePeer
{
    public readonly string $jid;
    private readonly SignalStore $store;
    private readonly SignalProtocol $signal;
    private readonly GroupTransport $groups;
    /** @var array<string,mixed> */
    private array $credentials;
    /** @var (\Closure(self,array<string,mixed>):void)|null */
    private ?\Closure $onMessage=null;
    public function __construct(private readonly FakeProtocol $server,string $jid,string $path=':memory:')
    {
        $address=Jid::parse($jid)->canonical();if(!in_array($address->server,['s.whatsapp.net','lid'],true))throw new \InvalidArgumentException('Fake peers require a user device JID');$this->jid=(string)$address;$this->store=new SignalStore($path,'fake-peer');$saved=$this->store->get('fake-peer','credentials');$this->credentials=$saved===null ? CredentialStore::fresh() : BinaryData::decode($saved);
        $this->credentials['meJid']=$this->jid;$this->credentials['registered']=true;$this->credentials['role']=$address->device===0 ? 'primary' : 'companion';$this->store->set('fake-peer','credentials',BinaryData::encode($this->credentials));$this->signal=new SignalProtocol($this->store,$this->credentials);
        $this->groups=new GroupTransport($this->signal,$this->store,fn(BinaryNode $node,float $timeout):BinaryNode=>$server->query($node,$this->jid),fn(array $users):array=>$server->devices($users),function(array $devices):void{$this->ensure($devices);},fn():array=>$this->credentials);
    }
    /** @return array<string,mixed> Public device registration; private keys stay inside this peer. */
    public function registration():array {$c=$this->credentials;return ['meJid'=>$this->jid,'registrationId'=>$c['registrationId'],'identityKeyPair'=>['public'=>$c['identityKeyPair']['public']],'signedPreKey'=>array_intersect_key($c['signedPreKey'],array_flip(['id','public','signature']))];}
    /** Creates a primary signing authority without exporting the peer's private identity. */
    public function primaryPairing(int $deviceIndex=1):FakePairing {return new FakePairing($this->credentials,$deviceIndex);}
    /** Signs a test companion using the same ADV/account/device signatures as production pairing. */
    public function certifyCompanion(self $primary):void
    {
        $jid=Jid::parse($this->jid);$owner=Jid::parse($primary->jid);if($jid->device===0||$owner->device!==0||$jid->bare()!==$owner->bare())throw new \InvalidArgumentException('Fake companion must belong to its primary account');
        $signed=CompanionCrypto::signedCompanion($primary->credentials['identityKeyPair'],$this->credentials['identityKeyPair']['public'],random_bytes(32),12345,$jid->device,time(),[0,$jid->device]);$hmac=SchemaCodec::decode('ADVSignedDeviceIdentityHMAC',$signed['deviceIdentityBytes']);$identity=SchemaCodec::decode('ADVSignedDeviceIdentity',$hmac['details']);$identity['accountSignatureKey']=$primary->credentials['identityKeyPair']['public'];$identity['deviceSignature']=XEdDsa::sign($this->credentials['identityKeyPair']['private'],"\x06\x01".$identity['details'].$this->credentials['identityKeyPair']['public'].$identity['accountSignatureKey']);$this->credentials['signedIdentity']=$identity;$this->store->set('fake-peer','credentials',BinaryData::encode($this->credentials));
    }
    /** @return array{registrationId:int,identityKey:string,signedPreKey:array{id:int,public:string,signature:string},preKey:array{id:int,public:string}} */
    public function bundle():array {$c=$this->credentials;$key=$this->store->generatePreKeys(1)[0];$this->store->markPreKeysUploaded([$key['id']]);return ['registrationId'=>$c['registrationId'],'identityKey'=>$c['identityKeyPair']['public'],'signedPreKey'=>array_intersect_key($c['signedPreKey'],array_flip(['id','public','signature'])),'preKey'=>['id'=>$key['id'],'public'=>$key['public']]];}
    /** @param callable(self,array<string,mixed>):void|null $callback */
    public function onMessage(?callable $callback):void {$this->onMessage=$callback===null ? null : \Closure::fromCallable($callback);}
    /** @return list<array<string,mixed>> */
    public function messages():array {$out=[];foreach($this->store->entries('fake-peer-messages',1000) as $bytes)$out[]=BinaryData::decode($bytes);return $out;}
    /** @param string|array<string,mixed> $content
     * @return array{id:string,to:string,devices:list<string>} */
    public function sendMessage(string $to,string|array $content):array
    {
        $target=Jid::recipient($to)->canonical();$to=(string)$target;$built=ContentBuilder::build($content,$target->bare());$id='FAKE'.strtoupper(bin2hex(random_bytes(10)));
        if($target->server==='g.us'){$plan=$this->groups->plan($to);$node=$this->groups->prepare($to,$id,$built['bytes'],$built['attrs']['type'] ?? 'text',null,$plan);$devices=$plan['devices'];$this->server->routeMessage($this->jid,$node);$this->groups->acknowledge($id);}
        else{$devices=$target->device!==0 ? [$to] : ($this->server->devices([$to])[$to] ?? []);if($devices===[])throw new \UnexpectedValueException('No fake destination devices');$this->ensure($devices);$participants=[];
            foreach($devices as $device){$enc=$this->signal->encrypt($device,MessageCodec::pad($built['bytes']));$participants[]=new BinaryNode('to',['jid'=>$device],[new BinaryNode('enc',['type'=>$enc['type'],'v'=>'2'],$enc['ciphertext'])]);}
            $node=new BinaryNode('message',['id'=>$id,'to'=>$to,'type'=>$built['attrs']['type'] ?? 'text'],[new BinaryNode('participants',[],$participants)]);$this->server->routeMessage($this->jid,$node);}
        $this->store->set('fake-peer-outbox',$id,(new BinaryCodec())->encode($node));return ['id'=>$id,'to'=>$to,'devices'=>$devices];
    }
    public function replaySentMessage(string $id):void {$bytes=$this->store->get('fake-peer-outbox',$id) ?? throw new \OutOfBoundsException('Unknown fake outgoing message');$this->server->routeMessage($this->jid,(new BinaryCodec())->decode($bytes));}
    public function sendConversation(string $to,string $text):string {return $this->sendMessage($to,$text)['id'];}
    public function sendMessageEdit(string $to,string $targetId,string $text):string {return $this->sendMessage($to,['type'=>'raw','message'=>['protocolMessage'=>['key'=>['remoteJid'=>$to,'fromMe'=>true,'id'=>$targetId],'type'=>14,'editedMessage'=>['conversation'=>$text],'timestampMs'=>(int)floor(microtime(true)*1000)]]])['id'];}
    public function sendMessageRevoke(string $to,string $targetId):string {return $this->sendMessage($to,['type'=>'raw','message'=>['protocolMessage'=>['key'=>['remoteJid'=>$to,'fromMe'=>true,'id'=>$targetId],'type'=>0]]])['id'];}
    public function sendReaction(string $to,string $targetId,string $emoji):string {return $this->sendMessage($to,['type'=>'raw','message'=>['reactionMessage'=>['key'=>['remoteJid'=>$to,'fromMe'=>false,'id'=>$targetId],'text'=>$emoji,'senderTimestampMs'=>(int)floor(microtime(true)*1000)]]])['id'];}
    /** Sends the real compressed history protocol. Caller supplies a valid HistorySync protobuf. */
    public function sendHistorySync(string $to,string $historyBytes):string {if(strlen($historyBytes)>16777216)throw new \LengthException('Fake history limit');return $this->sendMessage($to,['type'=>'raw','message'=>['protocolMessage'=>['type'=>5,'historySyncNotification'=>['syncType'=>3,'initialHistBootstrapInlinePayload'=>gzcompress($historyBytes)]]]])['id'];}
    /** @param list<array<string,mixed>> $keys */
    public function sendAppStateSyncKeyShare(string $to,array $keys):string {return $this->sendMessage($to,['type'=>'raw','message'=>['protocolMessage'=>['type'=>6,'appStateSyncKeyShare'=>['keys'=>$keys]]]])['id'];}
    /** @param list<string> $ids Explicit test action; receiving a message never invents a read receipt. */
    public function sendReceipt(string $to,array $ids,string $type='read',?string $group=null):void
    {
        if($ids===[]||count($ids)>100||!in_array($type,['delivery','read','played'],true))throw new \InvalidArgumentException('Invalid fake receipt');foreach($ids as $id)if($id==='')throw new \InvalidArgumentException('Empty fake receipt ID');$attrs=['id'=>$ids[0],'from'=>$group ?? $this->jid,'t'=>(string)time()];if($type!=='delivery')$attrs['type']=$type;if($group!==null){if(!str_ends_with($group,'@g.us'))throw new \InvalidArgumentException('Fake receipt group must be g.us');$attrs['participant']=$this->jid;}$children=count($ids)>1 ? [new BinaryNode('list',[],array_map(static fn(string $id):BinaryNode=>new BinaryNode('item',['id'=>$id]),array_slice($ids,1)))] : null;$this->server->deliver($to,new BinaryNode('receipt',$attrs,$children));
    }
    public function sendRetryReceipt(string $to,string $id,int $count=1):void
    {
        if($count<1||$count>5)throw new \InvalidArgumentException('Invalid fake retry count');$bundle=$this->bundle();$s=$bundle['signedPreKey'];$key=$bundle['preKey'];$this->server->deliver($to,new BinaryNode('receipt',['id'=>$id,'from'=>$this->jid,'type'=>'retry'],[new BinaryNode('retry',['id'=>$id,'count'=>(string)$count,'v'=>'1','t'=>(string)time()]),new BinaryNode('registration',[],pack('N',$bundle['registrationId'])),new BinaryNode('keys',[],[new BinaryNode('type',[],"\x05"),new BinaryNode('identity',[],$bundle['identityKey']),new BinaryNode('key',[],[new BinaryNode('id',[],substr(pack('N',$key['id']),1)),new BinaryNode('value',[],$key['public'])]),new BinaryNode('skey',[],[new BinaryNode('id',[],substr(pack('N',$s['id']),1)),new BinaryNode('value',[],$s['public']),new BinaryNode('signature',[],$s['signature'])])])]));
    }
    /** Authentication/decryption errors propagate; the server never invents delivered/read success.
     * @return array<string,mixed>|null */
    public function receive(string $sender,BinaryNode $node):?array
    {
        $id=$node->attrs['id'] ?? '';if($id==='')throw new \UnexpectedValueException('Fake message missing ID');$from=$node->attrs['from'] ?? $sender;$group=str_ends_with($from,'@g.us')||str_ends_with($from,'@broadcast');$hash=hash('sha256',implode('',array_map(static fn(BinaryNode $n):string=>is_string($n->content)?$n->content:'',self::children($node,'enc'))));$key=hash('sha256',$from."\0".$sender."\0".$id);$seen=$this->store->get('fake-peer-seen',$key);$previous=$seen===null ? [] : BinaryData::decode($seen);if(in_array($hash,$previous,true))return null;
        $raw=$group ? $this->groups->processIncoming($node,$sender) : MessageCodec::unpad($this->signal->decrypt($sender,$node->child('enc')?->attrs['type'] ?? '',self::content($node->child('enc'))));
        if(!$group&&$raw!==null&&$this->groups->consumeDistribution($sender,$raw))$raw=null;$this->store->set('fake-peer-seen',$key,BinaryData::encode([...$previous,$hash]));if($raw===null)return null;$decoded=MessageCodec::decode($raw);$event=['id'=>$id,'from'=>$sender,'chat'=>$group ? $from : Jid::parse($sender)->bare(),'timestamp'=>(int)($node->attrs['t'] ?? time()),'proto'=>$decoded['proto'],'type'=>$decoded['kind'],'text'=>$decoded['text'] ?? null,'data'=>$decoded['data'] ?? []];
        $existing=$this->store->get('fake-peer-messages',$key);$this->store->set('fake-peer-messages',$key,BinaryData::encode($event));if($existing===null && $this->onMessage!==null)($this->onMessage)($this,$event);return $event;
    }
    /** @param list<string> $devices */
    private function ensure(array $devices):void {foreach($devices as $device)if(!$this->signal->hasSession($device))$this->signal->ensureSession($device,$this->server->bundle($device));}
    public function close():void {$this->store->close();}
    /** @return list<BinaryNode> */
    private static function children(BinaryNode $n,string $tag):array {return is_array($n->content) ? array_values(array_filter($n->content,static fn(BinaryNode $c):bool=>$c->tag===$tag)) : [];}
    private static function content(?BinaryNode $node):string {if(!is_string($node?->content))throw new \UnexpectedValueException('Missing fake ciphertext');return $node->content;}
}
