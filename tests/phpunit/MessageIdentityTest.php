<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Message\{MessageCodec,MessageService};
use MVO\Protocol\{BinaryData,BinaryNode,Jid,SchemaCodec};
use MVO\Service\IdentityService;
use MVO\Signal\{SenderKeyProtocol,SignalProtocol,SignalStore};
use PHPUnit\Framework\TestCase;

final class MessageIdentityTest extends TestCase
{
    private const OWN='5511000000000:5@s.whatsapp.net';
    private const OWN_LID='88880000000@lid';
    private const PN='5522000000000@s.whatsapp.net';
    private const LID='77770000000@lid';
    private array $paths=[];private array $stores=[];private array $events=[];private array $credentials;
    private SignalStore $store;private SignalProtocol $signal;
    protected function setUp():void
    {
        $this->credentials=self::credentials(self::OWN);$this->credentials['meLid']=self::OWN_LID;
        $this->store=$this->database();$this->signal=new SignalProtocol($this->store,$this->credentials);
    }
    protected function tearDown():void
    {
        foreach($this->stores as$store)$store->close();$this->stores=[];gc_collect_cycles();
        foreach($this->paths as$path)foreach([$path,$path.'-wal',$path.'-shm']as$file)if(is_file($file))unlink($file);
    }
    private function database():SignalStore
    {
        $path=tempnam(sys_get_temp_dir(),'mvo-identities-');if($path===false)throw new \RuntimeException('No fixture database');
        $this->paths[]=$path;$store=new SignalStore($path);$this->stores[]=$store;return $store;
    }
    private static function credentials(string $jid):array
    {
        return [...CredentialStore::fresh(),'registered'=>true,'meJid'=>$jid,'serverHasPreKeys'=>true,
            'signedIdentity'=>['details'=>'fixture','accountSignatureKey'=>str_repeat('k',32),'accountSignature'=>str_repeat('a',64),'deviceSignature'=>str_repeat('s',64)]];
    }
    private function service(?\Closure $query=null):MessageService
    {
        return new MessageService($this->signal,$this->store,fn():array=>$this->credentials,static function():void{},
            $query??static fn():BinaryNode=>throw new \LogicException('Incoming processing must not query'),static function():void{},
            function(string $name,array $event):void{$this->events[]=[$name,$event];});
    }
    private function peer(string $jid):array
    {
        $credentials=self::credentials($jid);$store=$this->database();
        return ['jid'=>$jid,'credentials'=>$credentials,'key'=>$store->generatePreKeys(1)[0],'store'=>$store,'signal'=>new SignalProtocol($store,$credentials)];
    }
    private static function bundle(array $peer):array
    {
        $c=$peer['credentials'];$signed=$c['signedPreKey'];
        return ['registrationId'=>$c['registrationId'],'identityKey'=>$c['identityKeyPair']['public'],
            'signedPreKey'=>['id'=>$signed['id'],'public'=>$signed['public'],'signature'=>$signed['signature']],
            'preKey'=>['id'=>$peer['key']['id'],'public'=>$peer['key']['public']]];
    }
    private static function bundles(array $peers,?string $badSignature=null):BinaryNode
    {
        $users=[];foreach($peers as$jid=>$peer){$c=$peer['credentials'];$s=$c['signedPreKey'];
            $users[]=new BinaryNode('user',['jid'=>$jid],[new BinaryNode('registration',[],pack('N',$c['registrationId'])),
                new BinaryNode('identity',[],$c['identityKeyPair']['public']),new BinaryNode('skey',[],[
                    new BinaryNode('id',[],substr(pack('N',$s['id']),1)),new BinaryNode('value',[],$s['public']),
                    new BinaryNode('signature',[],$jid===$badSignature?str_repeat('x',64):$s['signature'])]),
                new BinaryNode('key',[],[new BinaryNode('id',[],substr(pack('N',$peer['key']['id']),1)),new BinaryNode('value',[],$peer['key']['public'])])]);}
        return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('list',[],$users)]);
    }
    private static function devices(string $jid,array $ids):BinaryNode
    {
        return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('usync',[],[new BinaryNode('list',[],[
            new BinaryNode('user',['jid'=>$jid],[new BinaryNode('devices',[],[new BinaryNode('device-list',[],
                array_map(static fn(int $id):BinaryNode=>new BinaryNode('device',['id'=>(string)$id]),$ids))])])])])]);
    }
    private function incoming(array $peer,string $id,array $attrs=[],?string $bytes=null):BinaryNode
    {
        $key=$this->store->generatePreKeys(1)[0];$local=['credentials'=>$this->credentials,'key'=>$key];
        $peer['signal']->ensureSession(self::OWN,self::bundle($local));
        $encrypted=$peer['signal']->encrypt(self::OWN,MessageCodec::pad($bytes??MessageCodec::text('authenticated')));
        return new BinaryNode('message',['id'=>$id,'from'=>$peer['jid'],...$attrs],[new BinaryNode('enc',['type'=>$encrypted['type'],'v'=>'2'],$encrypted['ciphertext'])]);
    }

    public function testFreshSyncRefreshesAllDiscoveredDevicesAndCanonicalizesHostedDevice():void
    {
        $peers=[];foreach([self::PN,'5522000000000:2@s.whatsapp.net','5522000000000:99@s.whatsapp.net']as$jid)$peers[$jid]=$this->peer($jid);
        $this->signal->ensureSession(self::PN,self::bundle($peers[self::PN]));$before=$this->store->getSession(self::PN);$queries=[];
        $expected=array_keys($peers);sort($expected,SORT_STRING);
        $service=$this->service(function(BinaryNode $node)use($peers,$before,$expected,&$queries):BinaryNode{
            $queries[]=$node->attrs['xmlns'];self::assertSame($before,$this->store->getSession(self::PN),'No session reset before the complete bundle response');
            if($node->attrs['xmlns']==='usync')return self::devices(self::PN,[99,2,0]);
            self::assertSame($expected,array_map(static fn(BinaryNode $u):string=>$u->attrs['jid'],$node->child('key')->content));
            return self::bundles($peers);
        });
        $result=$service->syncSignalSession(self::PN);self::assertSame(['usync','encrypt'],$queries);
        self::assertSame(['jid'=>self::PN,'devices'=>$expected,'synced'=>3],$result);self::assertNotSame($before,$this->store->getSession(self::PN));
        foreach($peers as$jid=>$peer){$enc=$this->signal->encrypt($jid,'fresh '.$jid);self::assertSame('fresh '.$jid,$peer['signal']->decrypt(self::OWN,$enc['type'],$enc['ciphertext']));}
    }

    public function testExplicitHostedLidDeviceSynchronizesOnlyThatCanonicalDevice():void
    {
        $jid='77770000000:9@lid';$peer=$this->peer($jid);$queries=0;
        $service=$this->service(static function(BinaryNode $node)use($peer,$jid,&$queries):BinaryNode{++$queries;self::assertSame('encrypt',$node->attrs['xmlns']);
            self::assertSame($jid,$node->child('key')->child('user')->attrs['jid']);return self::bundles([$jid=>$peer]);});
        self::assertSame(['jid'=>$jid,'devices'=>[$jid],'synced'=>1],$service->syncSignalSession('77770000000:9@hosted.lid'));self::assertSame(1,$queries);
    }

    public function testExplicitPrimaryZeroSkipsDeviceDiscovery():void
    {
        $peer=$this->peer(self::PN);$service=$this->service(static function(BinaryNode $node)use($peer):BinaryNode{
            self::assertSame('encrypt',$node->attrs['xmlns']);return self::bundles([self::PN=>$peer]);});
        self::assertSame(['jid'=>self::PN,'devices'=>[self::PN],'synced'=>1],$service->syncSignalSession('5522000000000:0@hosted'));
    }

    public function testMappedPhoneSyncUsesCurrentLidAndFailurePreservesMappingsAndOldRatchet():void
    {
        $identities=new IdentityService($this->store);$identities->remember(self::PN,self::LID,true);$old=$this->peer(self::LID);
        $this->signal->ensureSession(self::LID,self::bundle($old));$oldState=$this->store->getSession(self::LID);$next='99990000000@lid';$identities->changeLid(self::LID,$next);
        $mappings=[$this->store->entries('message-pn-to-lid'),$this->store->entries('message-lid-to-pn'),$this->store->entries('identity-lid-successor')];
        $service=$this->service(static function(BinaryNode $node)use($next):BinaryNode{
            if($node->attrs['xmlns']==='usync'){self::assertSame($next,$node->child('usync')->child('list')->child('user')->attrs['jid']);return self::devices($next,[0,2]);}
            return self::bundles([]);
        });
        try{$service->syncSignalSession(self::PN);self::fail('Missing new LID bundles accepted');}catch(\RuntimeException){}
        self::assertSame($mappings,[$this->store->entries('message-pn-to-lid'),$this->store->entries('message-lid-to-pn'),$this->store->entries('identity-lid-successor')]);
        self::assertSame($oldState,$this->store->getSession(self::LID));self::assertNull($this->store->getSession($next));
    }

    public function testInvalidSecondBundleRollsBackEverySessionAndTrustedIdentity():void
    {
        $second='5522000000000:2@s.whatsapp.net';$peers=[self::PN=>$this->peer(self::PN),$second=>$this->peer($second)];$states=[];$identities=[];
        foreach($peers as$jid=>$peer){$this->signal->ensureSession($jid,self::bundle($peer));$states[$jid]=$this->store->getSession($jid);$identities[$jid]=$this->store->getIdentity($jid);}
        $service=$this->service(static fn(BinaryNode $node):BinaryNode=>$node->attrs['xmlns']==='usync'?self::devices(self::PN,[0,2]):self::bundles($peers,$second));
        try{$service->syncSignalSession(self::PN);self::fail('Invalid bundle accepted');}catch(\UnexpectedValueException $error){self::assertStringContainsString('signature',$error->getMessage());}
        foreach($states as$jid=>$state){self::assertSame($state,$this->store->getSession($jid));self::assertSame($identities[$jid],$this->store->getIdentity($jid));}
    }

    public function testMissingBundleOrNetworkFailurePreservesExistingSession():void
    {
        $peer=$this->peer(self::PN);$this->signal->ensureSession(self::PN,self::bundle($peer));$before=$this->store->getSession(self::PN);
        foreach(['missing','network','empty_devices']as$failure){$service=$this->service(static function(BinaryNode $node)use($failure):BinaryNode{
            if($node->attrs['xmlns']==='usync')return self::devices(self::PN,$failure==='empty_devices'?[]:[0]);
            if($failure==='network')throw new \RuntimeException('Fixture offline');return self::bundles([]);});
            try{$service->syncSignalSession(self::PN);self::fail('Failed sync reported success');}catch(\RuntimeException|\UnexpectedValueException){self::assertSame($before,$this->store->getSession(self::PN));}}
    }

    public function testInvalidSignalTargetsNeverQueryOrAlterState():void
    {
        $queries=0;$service=$this->service(static function()use(&$queries):BinaryNode{++$queries;throw new \LogicException('Must not query');});
        foreach(['100-200@g.us','status@broadcast','123@newsletter','123@bot','cliente.exemplo','abc@lid','55:256@s.whatsapp.net','55:-1@s.whatsapp.net','55@unknown']as$target){
            try{$service->syncSignalSession($target);self::fail('Invalid target accepted: '.$target);}catch(\InvalidArgumentException){self::assertSame(0,$queries);}}
    }

    public function testAuthenticatedHostedLidMessageExposesPeerAndReceivingDeviceAlternativesInHistory():void
    {
        $peer=$this->peer('77770000000:2@lid');$node=$this->incoming($peer,'dual',['from'=>'77770000000:2@hosted.lid','sender_pn'=>self::PN]);
        $service=$this->service();$service->handleNode($node);self::assertSame('message',$this->events[0][0]);$event=$this->events[0][1];
        self::assertSame(['id'=>'dual','remoteJid'=>self::LID,'fromMe'=>false,'remoteJidAlt'=>self::PN,
            'recipientJid'=>'88880000000:5@lid','recipientAlt'=>self::OWN,'addressingMode'=>'lid'],$event['key']);
        self::assertSame('77770000000:2@lid',$event['authenticatedSender']);self::assertSame(2,$event['senderDevice']);
        self::assertSame($event['key'],$service->history()->store->getMessage(self::LID,'dual')['key']);
        self::assertSame(self::LID,(new IdentityService($this->store))->resolveLid(self::PN));
    }

    public function testTamperedSignalDoesNotLearnAliasesAndOriginalStillAuthenticates():void
    {
        $peer=$this->peer('77770000000:2@lid');$node=$this->incoming($peer,'tampered',['sender_pn'=>self::PN]);$enc=$node->child('enc');
        $bad=$enc->content;$bad[strlen($bad)-1]=$bad[strlen($bad)-1]^"\x01";
        $this->service()->handleNode(new BinaryNode('message',$node->attrs,[new BinaryNode('enc',$enc->attrs,$bad)]));
        self::assertSame(['message_error'],array_column($this->events,0));self::assertNull($this->store->get('message-pn-to-lid',self::PN));
        self::assertFalse($this->signal->hasSession($peer['jid']));$this->service()->handleNode($node);
        self::assertSame('message',$this->events[1][0]);self::assertSame(self::PN,$this->events[1][1]['key']['remoteJidAlt']);
    }

    public function testMessageSecretAeadAlternativesDoNotCloneAnUnauthenticatedSignalRatchet():void
    {
        $peer=$this->peer(self::LID);$this->signal->ensureSession(self::LID,self::bundle($peer));$state=$this->store->getSession(self::LID);
        $pn='13135550002@s.whatsapp.net';
        (new IdentityService($this->store))->remember($pn,self::LID,true);$parent=random_bytes(32);$own=Jid::parse(self::OWN)->bare();$id='secret-proof';
        $this->store->set('message-secrets',hash('sha256',$pn."\0".'parent'),BinaryData::encode(['secret'=>$parent,'author'=>$own]));
        $iv=random_bytes(12);$key=hash_hkdf('sha256',hash_hkdf('sha256',$parent,32,'Bot Message'),32,$id.$own.$pn);$tag='';
        $cipher=openssl_encrypt(MessageCodec::text('AEAD authenticated'),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$id."\0".$pn);
        $payload=SchemaCodec::encode('MessageSecretMessage',['encIv'=>$iv,'encPayload'=>$cipher.$tag]);
        $node=new BinaryNode('message',['id'=>$id,'from'=>$pn,'sender_lid'=>self::LID],[new BinaryNode('enc',['type'=>'msmsg'],$payload),
            new BinaryNode('bot',['edit'=>'first']),new BinaryNode('meta',['target_id'=>'parent','target_sender_jid'=>$own])]);
        $this->service()->handleNode($node);$chunks=array_values(array_filter($this->events,static fn(array $event):bool=>$event[0]==='bot_chunk'));
        self::assertCount(1,$chunks);self::assertSame(self::LID,$chunks[0][1]['key']['remoteJidAlt']);
        self::assertNull($this->store->getSession($pn),'A message-secret proof authenticates content, not the selected Signal ratchet');
        self::assertSame($state,$this->store->getSession(self::LID));
    }

    public function testLearningLidDoesNotLoseEstablishedPhoneRatchetForNextMessage():void
    {
        $peer=$this->peer('5522000000000:2@s.whatsapp.net');$first=$this->incoming($peer,'pn-1',['sender_lid'=>self::LID]);$service=$this->service();$service->handleNode($first);
        $reply=$this->signal->encrypt($peer['jid'],'acknowledge-session');$peer['signal']->decrypt(self::OWN,$reply['type'],$reply['ciphertext']);
        $next=$peer['signal']->encrypt(self::OWN,MessageCodec::pad(MessageCodec::text('second')));self::assertSame('msg',$next['type']);
        $service->handleNode(new BinaryNode('message',['id'=>'pn-2','from'=>$peer['jid']],[new BinaryNode('enc',['type'=>'msg'],$next['ciphertext'])]));
        self::assertSame(['message','message'],array_column($this->events,0));self::assertSame('second',$this->events[1][1]['text']);
        self::assertSame(self::PN,$this->events[1][1]['key']['remoteJid']);self::assertSame(self::LID,$this->events[1][1]['key']['remoteJidAlt']);
    }

    public function testOwnDeviceEchoUsesPeerAlternativesWithoutReplacingChatWithOwnIdentity():void
    {
        $peer=$this->peer('5511000000000@s.whatsapp.net');$bytes=MessageCodec::deviceSent(MessageCodec::text('echo'),self::LID);
        $node=$this->incoming($peer,'echo',['recipient'=>self::LID,'recipient_pn'=>self::PN,'sender_lid'=>self::OWN_LID],$bytes);
        $this->service()->handleNode($node);$event=$this->events[0][1];self::assertTrue($event['fromMe']);self::assertSame(self::LID,$event['chat']);
        self::assertSame(self::LID,$event['key']['remoteJid']);self::assertSame(self::PN,$event['key']['remoteJidAlt']);
        self::assertSame(self::LID,$event['key']['recipientJid']);self::assertSame(self::PN,$event['key']['recipientAlt']);
    }

    public function testAuthenticatedGroupParticipantAndRecipientAlternativesPreserveGroupAndSenderDevice():void
    {
        $peer=$this->peer('77770000000:2@lid');$group='100-200@g.us';$keys=new SenderKeyProtocol($peer['store']);
        $distribution=$keys->createDistribution($group,$peer['jid']);$body=MessageCodec::bytes(2,MessageCodec::bytes(1,$group).MessageCodec::bytes(2,$distribution));
        $direct=$this->incoming($peer,'group',['from'=>$group,'participant'=>$peer['jid'],'participant_pn'=>self::PN,'recipient'=>'88880000000:5@lid','recipient_pn'=>self::OWN],$body);
        $encrypted=$keys->encrypt($group,$peer['jid'],MessageCodec::pad(MessageCodec::text('group identities')));
        $this->service()->handleNode(new BinaryNode('message',$direct->attrs,[$direct->child('enc'),new BinaryNode('enc',['type'=>'skmsg'],$encrypted)]));
        self::assertSame('message',$this->events[0][0]);$key=$this->events[0][1]['key'];
        self::assertSame($group,$key['remoteJid']);self::assertSame(self::LID,$key['participant']);self::assertSame(self::PN,$key['participantAlt']);
        self::assertSame('88880000000:5@lid',$key['recipientJid']);self::assertSame(self::OWN,$key['recipientAlt']);self::assertArrayNotHasKey('remoteJidAlt',$key);
        self::assertSame(2,$this->events[0][1]['senderDevice']);
    }

    public function testUnknownOrMismatchedPeerAlternativeDoesNotInventPhoneNumber():void
    {
        foreach([null,'5522000000000:3@s.whatsapp.net','abc@s.whatsapp.net','888@lid']as$index=>$alias){
            $peer=$this->peer((string)(77770000000+$index).':2@lid');$node=$this->incoming($peer,'unknown-'.$index,$alias===null?[]:['sender_pn'=>$alias]);
            $this->service()->handleNode($node);$event=$this->events[$index][1];self::assertSame('message',$this->events[$index][0]);
            self::assertArrayNotHasKey('remoteJidAlt',$event['key']);self::assertNull((new IdentityService($this->store))->resolvePhone(Jid::parse($peer['jid'])->bare()));
        }
    }

    public function testDelayedOldLidAuthenticatesWithoutRevertingConfirmedSuccessor():void
    {
        $identities=new IdentityService($this->store);$identities->remember(self::PN,self::LID,true);$identities->changeLid(self::LID,'99990000000@lid');
        $peer=$this->peer('77770000000:2@lid');$this->service()->handleNode($this->incoming($peer,'old',['sender_pn'=>self::PN]));
        self::assertSame('message',$this->events[0][0]);self::assertSame(self::LID,$this->events[0][1]['key']['remoteJid']);
        self::assertSame('99990000000@lid',$identities->resolveLid(self::PN));self::assertTrue($this->signal->hasSession($peer['jid']));
        self::assertFalse($this->signal->hasSession('99990000000:2@lid'),'A LID change must not copy old ratchets into the new identity');
    }

    public function testNewSendToRetiredLidRoutesOnlyToConfirmedSuccessor():void
    {
        $identities=new IdentityService($this->store);$identities->remember(self::PN,self::LID,true);$next='99990000000@lid';$identities->changeLid(self::LID,$next);$peer=$this->peer($next);
        $service=$this->service(function(BinaryNode $node)use($next,$peer):BinaryNode{
            if($node->tag==='message'){self::assertSame($next,$node->attrs['to']);self::assertSame(self::PN,$node->attrs['peer_recipient_pn']);
                $target=$node->child('participants')->child('to');self::assertSame($next,$target->attrs['jid']);$enc=$target->child('enc');
                self::assertSame('current identity',MessageCodec::decode(MessageCodec::unpad($peer['signal']->decrypt('88880000000:5@lid',$enc->attrs['type'],$enc->content)))['text']);
                return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);}
            if($node->attrs['xmlns']==='usync')return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('usync',[],[new BinaryNode('list',[],[
                self::devices($next,[0])->child('usync')->child('list')->child('user'),self::devices(self::OWN_LID,[5])->child('usync')->child('list')->child('user')])])]);
            return self::bundles([$next=>$peer]);
        });
        self::assertSame($next,$service->sendText(self::LID,'current identity')['to']);self::assertNull($this->store->getSession(self::LID));
    }

    public function testGroupAcknowledgementExposesServerAddressingModeCorrectionWithoutChangingPersistedCiphertext():void
    {
        $group='100-200@g.us';$peer=$this->peer(self::PN);$sent=null;
        $service=$this->service(function(BinaryNode $node)use($group,$peer,&$sent):BinaryNode{
            if($node->tag==='message'){$sent=$node;self::assertSame('pn',$node->attrs['addressing_mode']);self::assertArrayHasKey('phash',$node->attrs);
                return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id'],'addressing_mode'=>'lid']);}
            if($node->attrs['xmlns']==='w:g2')return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('group',['id'=>$group,'addressing_mode'=>'pn'],[
                new BinaryNode('participant',['jid'=>Jid::parse(self::OWN)->bare()]),new BinaryNode('participant',['jid'=>self::PN])])]);
            if($node->attrs['xmlns']==='usync')return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('usync',[],[new BinaryNode('list',[],[
                self::devices(Jid::parse(self::OWN)->bare(),[5])->child('usync')->child('list')->child('user'),self::devices(self::PN,[0])->child('usync')->child('list')->child('user')])])]);
            return self::bundles([self::PN=>$peer]);
        });
        $result=$service->sendText($group,'server corrected mode');self::assertSame('lid',$result['addressingMode']);self::assertSame(['addressingMode'=>'lid'],$result['ack']);
        self::assertSame($result,$service->getMessageStatus($result['id']));self::assertSame('pn',$sent->attrs['addressing_mode']);
        self::assertSame($result,$this->events[0][1]);
    }
}
