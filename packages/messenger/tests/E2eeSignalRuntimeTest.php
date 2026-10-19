<?php
declare(strict_types=1);
namespace MVO\Messenger\Tests;
use MVO\Messenger\Auth\SessionStore;
use MVO\Messenger\E2ee\{DeviceKeys,Crypto,ProtoWire,MessageEngine};
use MVO\Messenger\E2ee\Protocol\{Address,BinaryCodec,BinaryNode,MessageEnvelope,DeviceDirectory};
use MVO\Messenger\E2ee\Signal\{SignalProtocol,SignalStore};
use MVO\Messenger\MessengerException;
use PHPUnit\Framework\TestCase;

final class E2eeSignalRuntimeTest extends TestCase
{
    private string $root;private SessionStore $aOwner;private SessionStore $bOwner;private SignalStore $aStore;private SignalStore $bStore;private DeviceKeys $aKeys;private DeviceKeys $bKeys;private SignalProtocol $a;private SignalProtocol $b;
    private const A='10001:1@msgr';private const B='20002:2@msgr';
    protected function setUp():void
    {
        if(!extension_loaded('gmp'))self::markTestSkipped('Explicit E2EE cryptography requires GMP');
        $this->root=sys_get_temp_dir().'/mvo-msgr-signal-'.bin2hex(random_bytes(8));mkdir($this->root,0700);
        $this->aOwner=new SessionStore($this->root,'alice');$this->aOwner->acquire();$this->aOwner->set('account_id','10001');
        $this->bOwner=new SessionStore($this->root,'bob');$this->bOwner->acquire();$this->bOwner->set('account_id','20002');
        $this->aStore=new SignalStore($this->aOwner,'10001');$this->bStore=new SignalStore($this->bOwner,'20002');$this->aKeys=DeviceKeys::generate('10001');$this->bKeys=DeviceKeys::generate('20002');
        $this->a=new SignalProtocol($this->aStore,$this->aKeys->signalCredentials());$this->b=new SignalProtocol($this->bStore,$this->bKeys->signalCredentials());
    }
    protected function tearDown():void
    {
        if(!isset($this->root))return;
        $this->aOwner->release();$this->bOwner->release();unset($this->a,$this->b,$this->aStore,$this->bStore,$this->aOwner,$this->bOwner);gc_collect_cycles();
        foreach(['alice','bob']as$name){$dir=$this->root.'/messenger/'.$name;foreach(glob($dir.'/*')?:[]as$file)unlink($file);rmdir($dir);}rmdir($this->root.'/messenger');rmdir($this->root);
    }
    /** @return array<string,mixed> */
    private function bundle(bool $prekey=true):array
    {
        $credentials=$this->bKeys->signalCredentials();$signed=$credentials['signedPreKey'];$bundle=['registrationId'=>$this->bKeys->registrationId,'identityKey'=>$this->bKeys->identityPublic(),'signedPreKey'=>['id'=>$signed['id'],'public'=>$signed['public'],'signature'=>$signed['signature']]];
        if($prekey){$key=$this->bStore->generatePreKeys(1)[0];$bundle['preKey']=['id'=>$key['id'],'public'=>$key['public']];}return $bundle;
    }
    private function approve():void
    {
        $this->aStore->approveIdentity(self::B,$this->bKeys->identityPublic(),hash('sha256',$this->bKeys->identityPublic()));$this->bStore->approveIdentity(self::A,$this->aKeys->identityPublic(),hash('sha256',$this->aKeys->identityPublic()));
    }
    public function testUnknownPeerNeverAutoTrustsAnOtherwiseValidSignedBundle():void
    {
        try{$this->a->ensureSession(self::B,$this->bundle());self::fail('Unapproved identity');}catch(MessengerException $e){self::assertSame('messenger_peer_trust_required',$e->reason);}
        self::assertNull($this->aStore->getSession(self::B));self::assertNull($this->aStore->getIdentity(self::B));
    }
    public function testIndependentMessengerPeersExchangePkmsgThenRatchetBothWays():void
    {
        $this->approve();$bundle=$this->bundle();$this->a->ensureSession(self::B,$bundle);
        $envelope=MessageEnvelope::text('Olá, Messenger!');$send=$this->a->encrypt(self::B,MessageEnvelope::transport($envelope['application']));self::assertSame('pkmsg',$send['type']);
        $plain=$this->b->decrypt(self::A,$send['type'],$send['ciphertext']);self::assertSame('Olá, Messenger!',MessageEnvelope::read($plain,$envelope['franking_tag'])['text']);
        self::assertNull($this->bStore->getPreKey($bundle['preKey']['id']));
        for($i=0;$i<3;++$i){$reply=$this->b->encrypt(self::A,'resposta '.$i);self::assertSame('msg',$reply['type']);self::assertSame('resposta '.$i,$this->a->decrypt(self::B,$reply['type'],$reply['ciphertext']));$next=$this->a->encrypt(self::B,'pergunta '.$i);self::assertSame('msg',$next['type']);self::assertSame('pergunta '.$i,$this->b->decrypt(self::A,$next['type'],$next['ciphertext']));}
    }
    public function testSignalAndEnvelopeFailureRollBackRatchetAndOneTimeKeyAtomically():void
    {
        $this->approve();$bundle=$this->bundle();$this->a->ensureSession(self::B,$bundle);$envelope=MessageEnvelope::text('Conteúdo íntegro');$send=$this->a->encrypt(self::B,MessageEnvelope::transport($envelope['application']));
        try{$this->bStore->transaction(function()use($send){$plain=$this->b->decrypt(self::A,$send['type'],$send['ciphertext']);MessageEnvelope::read($plain,str_repeat('x',32));});self::fail('Wrong franking');}catch(\UnexpectedValueException){}
        self::assertNull($this->bStore->getSession(self::A));self::assertNotNull($this->bStore->getPreKey($bundle['preKey']['id']));
        $valid=$this->bStore->transaction(fn()=>MessageEnvelope::read($this->b->decrypt(self::A,$send['type'],$send['ciphertext']),$envelope['franking_tag']));self::assertSame('Conteúdo íntegro',$valid['text']);
        $before=$this->bStore->getSession(self::A);
        try{$this->b->decrypt(self::A,$send['type'],$send['ciphertext']);self::fail('Duplicate accepted');}catch(\UnexpectedValueException){}
        self::assertSame($before,$this->bStore->getSession(self::A));
    }
    public function testOutOfOrderMessagesAndRestoredSessionsDoNotLoseKeys():void
    {
        $this->approve();$this->a->ensureSession(self::B,$this->bundle());$first=$this->a->encrypt(self::B,'first');$this->b->decrypt(self::A,$first['type'],$first['ciphertext']);$reply=$this->b->encrypt(self::A,'reply');$this->a->decrypt(self::B,$reply['type'],$reply['ciphertext']);
        $one=$this->a->encrypt(self::B,'one');$two=$this->a->encrypt(self::B,'two');self::assertSame('two',$this->b->decrypt(self::A,$two['type'],$two['ciphertext']));
        $this->bStore=new SignalStore($this->bOwner,'20002');$this->b=new SignalProtocol($this->bStore,$this->bKeys->signalCredentials());self::assertSame('one',$this->b->decrypt(self::A,$one['type'],$one['ciphertext']));
        $next=$this->b->encrypt(self::A,'restored');self::assertSame('restored',$this->a->decrypt(self::B,$next['type'],$next['ciphertext']));
    }
    public function testChangedIdentityAndBadSignatureCannotResetTrustedSession():void
    {
        $this->approve();$this->a->ensureSession(self::B,$this->bundle(false));$before=$this->aStore->getSession(self::B);$keys=DeviceKeys::generate('20002');$c=$keys->signalCredentials();
        try{$this->a->ensureSession(self::B,['registrationId'=>$keys->registrationId,'identityKey'=>$keys->identityPublic(),'signedPreKey'=>['id'=>1,'public'=>$c['signedPreKey']['public'],'signature'=>$c['signedPreKey']['signature']]],true);self::fail('Identity replacement');}catch(MessengerException $e){self::assertSame('messenger_peer_identity_changed',$e->reason);}
        $bad=$this->bundle(false);$bad['signedPreKey']['signature']=str_repeat('x',64);try{$this->a->ensureSession(self::B,$bad,true);self::fail('Bad signature');}catch(\UnexpectedValueException){}
        self::assertSame($before,$this->aStore->getSession(self::B));
    }
    public function testLostOwnerLockBlocksEveryStateMutation():void
    {
        $this->aOwner->release();$this->expectException(\LogicException::class);$this->aStore->generatePreKeys(1);
    }
    public function testCredentialsCannotBeAttachedToAnotherAccountJournal():void
    {
        $this->expectException(\InvalidArgumentException::class);new SignalProtocol($this->aStore,$this->bKeys->signalCredentials());
    }
    public function testMinimalEnvelopeMatchesIndependentProtobufBytes():void
    {
        $value=MessageEnvelope::text('Teste MVO',str_repeat("\0",32));
        $hex='0a1b2219080012150a110a0f0a0d0a0b0a095465737465204d564f100112244220'.str_repeat('00',32).'4800';
        self::assertSame($hex,bin2hex($value['application']));
        self::assertSame(hash_hmac('sha256',hex2bin($hex),str_repeat("\0",32),true),$value['franking_tag']);
        self::assertSame(['text'=>'Teste MVO','destination'=>'20002@msgr','franking_verified'=>true],MessageEnvelope::read(MessageEnvelope::transport($value['application'],'20002@msgr'),$value['franking_tag']));
    }
    public function testDedicatedFbJidEncodingPreservesUint16DeviceAndRejectsOtherDomains():void
    {
        $codec=new BinaryCodec();$node=new BinaryNode('user',['jid'=>'20002:65535@msgr']);$bytes=$codec->encode($node);self::assertStringContainsString("\xf6",$bytes);self::assertEquals($node,$codec->decode($bytes));
        self::assertSame('20002:0@msgr',Address::device('20002@msgr'));
        $this->expectException(\InvalidArgumentException::class);$codec->encode(new BinaryNode('user',['jid'=>'20002:2@s.whatsapp.net']));
    }
    /** @return array{MessageEngine,MessageEngine,BinaryNode} */
    private function submitted(string $id='MVO-SYNTHETIC-1'):array
    {
        $this->approve();$this->a->ensureSession(self::B,$this->bundle());$sender=new MessageEngine($this->aStore,$this->a,self::A);$receiver=new MessageEngine($this->bStore,$this->b,self::B);$sender->prepareText($id,'key-'.$id,'20002@msgr','Mensagem cifrada',[self::B]);$wire=null;
        $state=$sender->submit($id,static function(BinaryNode $node)use(&$wire):void{$wire=$node;});self::assertSame('submitted',$state['state']);self::assertFalse($state['delivery_confirmed']);self::assertInstanceOf(BinaryNode::class,$wire);return [$sender,$receiver,$wire];
    }
    private function delivery(BinaryNode $wire,?string $frankingOverride=null):BinaryNode
    {
        $enc=$wire->child('participants')->content[0]->child('enc');$franking=$wire->child('franking');if($frankingOverride!==null)$franking=new BinaryNode('franking',[],[new BinaryNode('franking_tag',[],$frankingOverride)]);
        return new BinaryNode('message',['id'=>$wire->attrs['id'],'from'=>self::A],[$enc,$franking]);
    }
    public function testEncryptedJournalCorrelatesAckDeliveryReadAndDeduplicatesInbox():void
    {
        [$sender,$receiver,$wire]=$this->submitted();$delivery=$this->delivery($wire);$message=$receiver->receive($delivery);self::assertSame('Mensagem cifrada',$message['text']);self::assertSame(1,$message['sequence']);self::assertTrue($message['franking_verified']);self::assertTrue($receiver->receive($delivery)['duplicate']);self::assertCount(1,$receiver->messages());
        $receipt=$receiver->pendingReceipts()[0];self::assertSame(self::A,$receipt['node']->attrs['to']);$receiver->receiptSent($receipt['sequence']);self::assertSame([],$receiver->pendingReceipts());
        $ack=$sender->acknowledge(new BinaryNode('ack',['class'=>'message','id'=>$wire->attrs['id'],'from'=>'20002@msgr']));self::assertSame('server_ack',$ack['state']);self::assertFalse($ack['delivery_confirmed']);
        $delivered=$sender->acknowledge(new BinaryNode('receipt',['id'=>$wire->attrs['id'],'from'=>self::B]));self::assertTrue($delivered['delivery_confirmed']);self::assertFalse($delivered['read_confirmed']);
        $read=$sender->acknowledge(new BinaryNode('receipt',['id'=>$wire->attrs['id'],'from'=>self::B,'type'=>'read']));self::assertTrue($read['read_confirmed']);self::assertSame('read',$sender->acknowledge(new BinaryNode('ack',['class'=>'message','id'=>$wire->attrs['id']]))['state']);
        $calls=0;self::assertSame('read',$sender->submit($wire->attrs['id'],static function()use(&$calls):void{++$calls;})['state']);self::assertSame(0,$calls);
    }
    public function testFrankingFailureRollsBackJournalAndSignalBeforeAValidRetransmission():void
    {
        [, $receiver,$wire]=$this->submitted();$before=$this->bStore->getSession(self::A);
        try{$receiver->receive($this->delivery($wire,str_repeat('x',32)));self::fail('Wrong franking accepted');}catch(\UnexpectedValueException){}
        self::assertSame($before,$this->bStore->getSession(self::A));self::assertSame([],$receiver->messages());self::assertSame([],$receiver->pendingReceipts());self::assertSame('Mensagem cifrada',$receiver->receive($this->delivery($wire))['text']);
    }
    public function testSubmissionTimeoutCannotBeResentAndStablePreparationDoesNotAdvanceRatchet():void
    {
        $this->approve();$this->a->ensureSession(self::B,$this->bundle());$sender=new MessageEngine($this->aStore,$this->a,self::A);$sender->prepareText('MVO-TIMEOUT','same-key','20002@msgr','Texto',[self::B]);$ratchet=$this->aStore->getSession(self::B);
        $sender->prepareText('MVO-TIMEOUT','same-key','20002@msgr','Texto',[self::B]);self::assertSame($ratchet,$this->aStore->getSession(self::B));$calls=0;
        for($i=0;$i<2;++$i){try{$sender->submit('MVO-TIMEOUT',static function()use(&$calls):void{++$calls;throw new \RuntimeException('SYNTHETIC_PRIVATE_TRANSPORT');});self::fail('Uncertain submission');}catch(MessengerException $e){self::assertSame('messenger_encrypted_send_uncertain',$e->reason);self::assertStringNotContainsString('SYNTHETIC_PRIVATE',$e->getMessage());}}
        self::assertSame(1,$calls);self::assertSame('unknown',$sender->outgoing('MVO-TIMEOUT')['state']);
    }
    public function testPartialFanoutFailureLeavesNoCiphertextOrAdvancedRatchet():void
    {
        $this->approve();$this->a->ensureSession(self::B,$this->bundle());$before=$this->aStore->getSession(self::B);$engine=new MessageEngine($this->aStore,$this->a,self::A);
        try{$engine->prepareText('MVO-NO-PARTIAL','key','20002@msgr','Texto',[self::B,'10001:9@msgr']);self::fail('Missing device session');}catch(\UnexpectedValueException){}
        self::assertSame($before,$this->aStore->getSession(self::B));self::assertNull($engine->outgoing('MVO-NO-PARTIAL'));
    }
    public function testJournalAndPendingReceiptRestoreUnderTheSameAccountOwner():void
    {
        [, $receiver,$wire]=$this->submitted();$receiver->receive($this->delivery($wire));unset($receiver);$this->bOwner->release();unset($this->b,$this->bStore,$this->bOwner);gc_collect_cycles();
        $this->bOwner=new SessionStore($this->root,'bob');$this->bOwner->acquire();$this->bStore=new SignalStore($this->bOwner,'20002');$this->b=new SignalProtocol($this->bStore,$this->bKeys->signalCredentials());$restored=new MessageEngine($this->bStore,$this->b,self::B);
        self::assertSame('Mensagem cifrada',$restored->messages()[0]['text']);self::assertCount(1,$restored->pendingReceipts());self::assertTrue($restored->receive($this->delivery($wire))['duplicate']);
    }
    public function testUnknownOrWrongSenderReceiptNeverConfirmsDelivery():void
    {
        [$sender,,$wire]=$this->submitted();foreach(['90009:1@msgr','10001:9@msgr']as$wrong){try{$sender->acknowledge(new BinaryNode('receipt',['id'=>$wire->attrs['id'],'from'=>$wrong,'type'=>'read']));self::fail('Wrong sender');}catch(\UnexpectedValueException){}}
        self::assertSame('submitted',$sender->outgoing($wire->attrs['id'])['state']);
    }
    public function testDirectoryScopesUsersDevicesAndPrekeyBundlesByIqId():void
    {
        $request=DeviceDirectory::discovery('devices-1',['10001@msgr','20002@msgr']);self::assertSame('fbid:devices',$request->attrs['xmlns']);
        $response=new BinaryNode('iq',['id'=>'devices-1','type'=>'result'],[new BinaryNode('users',[],[new BinaryNode('user',['jid'=>'10001@msgr'],[new BinaryNode('devices',['dhash'=>'A'],[new BinaryNode('device',['id'=>'1'])])]),new BinaryNode('user',['jid'=>'20002@msgr'],[new BinaryNode('devices',['dhash'=>'B'],[new BinaryNode('device',['id'=>'2'])])])])]);
        $users=DeviceDirectory::devices($response,'devices-1',['10001@msgr','20002@msgr']);self::assertSame([self::B],$users['20002@msgr']['devices']);self::assertFalse($users['20002@msgr']['remote_identity_trust_verified']);
        $this->bStore->generatePreKeys(2);$upload=DeviceDirectory::uploadPrekeys('upload-1',$this->bKeys,$this->bStore->pendingPreKeys());self::assertCount(2,$upload->child('list')->content);self::assertSame("\x05",$upload->child('type')->content);
        self::assertCount(2,$this->bStore->pendingPreKeys());DeviceDirectory::result(new BinaryNode('iq',['id'=>'upload-1','type'=>'result']),'upload-1');$this->bStore->markPreKeysUploaded([1,2]);self::assertSame([],$this->bStore->pendingPreKeys());self::assertNotNull($this->bStore->getPreKey(1));
        $this->expectException(\UnexpectedValueException::class);DeviceDirectory::devices($response,'wrong-request',['10001@msgr','20002@msgr']);
    }
    public function testScopedBundleProjectionEstablishesOnlyTheApprovedDevice():void
    {
        $this->approve();$signed=$this->bKeys->signalCredentials()['signedPreKey'];$prekey=$this->bStore->generatePreKeys(1)[0];
        $result=new BinaryNode('iq',['id'=>'bundle-1','type'=>'result'],[new BinaryNode('list',[],[new BinaryNode('user',['jid'=>self::B],[new BinaryNode('registration',[],pack('N',$this->bKeys->registrationId)),new BinaryNode('identity',[],$this->bKeys->identityPublic()),new BinaryNode('skey',[],[new BinaryNode('id',[],"\0\0\1"),new BinaryNode('value',[],$signed['public']),new BinaryNode('signature',[],$signed['signature'])]),new BinaryNode('key',[],[new BinaryNode('id',[],substr(pack('N',$prekey['id']),1)),new BinaryNode('value',[],$prekey['public'])])])])]);
        $request=DeviceDirectory::prekeys('bundle-1',[self::B]);self::assertSame('identity',$request->child('key')->content[0]->attrs['reason']);$bundles=DeviceDirectory::bundles($result,'bundle-1',[self::B]);self::assertSame($prekey['id'],$bundles[self::B]['preKey']['id']);$this->a->ensureSession(self::B,$bundles[self::B]);self::assertTrue($this->a->hasSession(self::B));
        $this->expectException(\UnexpectedValueException::class);DeviceDirectory::bundles($result,'bundle-1',['90009:1@msgr']);
    }
    public function testFrankingIsNotClaimedVerifiedWhenTheServerDidNotForwardATag():void
    {
        $value=MessageEnvelope::text('Sem tag encaminhada');$read=MessageEnvelope::read(MessageEnvelope::transport($value['application']));self::assertSame('Sem tag encaminhada',$read['text']);self::assertFalse($read['franking_verified']);
    }
    public function testOwnOtherDeviceReceivesDsmWithoutSendingToActiveDevice():void
    {
        $this->approve();$this->a->ensureSession(self::B,$this->bundle());$owner=new SessionStore($this->root,'ownother');$owner->acquire();$owner->set('account_id','10001');
        try{$store=new SignalStore($owner,'10001');$keys=DeviceKeys::generate('10001');$signal=new SignalProtocol($store,$keys->signalCredentials());$ownOther='10001:9@msgr';$this->aStore->approveIdentity($ownOther,$keys->identityPublic(),hash('sha256',$keys->identityPublic()));$store->approveIdentity(self::A,$this->aKeys->identityPublic(),hash('sha256',$this->aKeys->identityPublic()));$signed=$keys->signalCredentials()['signedPreKey'];$this->a->ensureSession($ownOther,['registrationId'=>$keys->registrationId,'identityKey'=>$keys->identityPublic(),'signedPreKey'=>['id'=>1,'public'=>$signed['public'],'signature'=>$signed['signature']]]);
            $engine=new MessageEngine($this->aStore,$this->a,self::A);$engine->prepareText('MVO-DSM','key-dsm','20002@msgr','Cópia própria',[self::A,self::B,$ownOther]);$wire=null;$engine->submit('MVO-DSM',static function(BinaryNode $node)use(&$wire):void{$wire=$node;});self::assertCount(2,$wire->child('participants')->content);$ownEnc=null;foreach($wire->child('participants')->content as$target)if($target->attrs['jid']===$ownOther)$ownEnc=$target->child('enc');self::assertNotNull($ownEnc);
            $receiver=new MessageEngine($store,$signal,$ownOther);$copy=$receiver->receive(new BinaryNode('message',['id'=>'MVO-DSM','from'=>self::A],[$ownEnc,$wire->child('franking')]));self::assertTrue($copy['from_me']);self::assertSame('20002@msgr',$copy['conversation']);self::assertSame('Cópia própria',$copy['text']);
        }finally{$owner->release();unset($receiver,$signal,$store,$owner);gc_collect_cycles();$dir=$this->root.'/messenger/ownother';foreach(glob($dir.'/*')?:[]as$file)unlink($file);rmdir($dir);}
    }
    public function testPrivateCryptoObjectsCannotLeakInDebugOrSerializedJobs():void
    {
        $private=$this->aKeys->signalCredentials()['identityKeyPair']['private'];self::assertStringNotContainsString($private,print_r($this->a,true));self::assertStringNotContainsString(base64_encode($private),print_r($this->aStore,true));
        $this->expectException(\LogicException::class);serialize($this->a);
    }
}
