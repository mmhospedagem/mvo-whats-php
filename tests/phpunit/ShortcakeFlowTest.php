<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Auth\{ShortcakeCrypto,ShortcakeFlow};
use MVO\Crypto\Crypto;
use MVO\Protocol\{BinaryNode,SchemaCodec};

final class ShortcakeFlowTest extends TestCase
{
    private array $credentials;
    private array $queries=[];
    private array $acks=[];
    private array $events=[];
    private float $clock=1000.0;
    private ?BinaryNode $prologue=null;
    private ?string $companionNonce=null;
    private ?string $envelope=null;
    protected function setUp():void { $this->credentials=['noiseKeyPair'=>Crypto::x25519KeyPair(),'identityKeyPair'=>Crypto::x25519KeyPair(),'advSecretKey'=>str_repeat('a',32),'registered'=>false]; }
    private function flow(?\Closure $signer=null,bool $autoConfirm=true,?\Closure $query=null):ShortcakeFlow
    {
        return new ShortcakeFlow(fn()=>$this->credentials,function($c){$this->credentials=$c;},function($node){$this->acks[]=$node;},$query??function(BinaryNode $node,float $timeout){
            self::assertSame(30.0,$timeout);$this->queries[]=$node;$child=$node->content[0];
            if($child->tag==='ref')return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('ref',[],'the-ref')]);
            if($child->tag==='passkey_request_options')return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('passkey_request_options',[],'{"rpId":"whatsapp.com","challenge":"abc"}')]);
            if($child->tag==='passkey_prologue')$this->prologue=$child;
            if($child->tag==='companion_nonce')$this->companionNonce=$child->content;
            if($child->tag==='encrypted_pairing_request')$this->envelope=$child->content;
            return new BinaryNode('iq',['type'=>'result']);
        },function($name,$data){$this->events[]=['name'=>$name,'data'=>$data];},$signer,1,$autoConfirm,fn()=>$this->clock);
    }
    private static function signer(string $options):array { self::assertStringContainsString('whatsapp.com',$options);return ['credentialId'=>'credential','webauthnAssertion'=>'assertion']; }
    private static function notification(string $type,string $id,array $content=[]):BinaryNode { return new BinaryNode('notification',['from'=>'s.whatsapp.net','type'=>$type,'id'=>$id],$content); }
    public function testMandatoryPasskeyHandshakeDefersQueriesRotatesAdvAndPrimaryDecryptsPayload():void
    {
        $oldAdv=$this->credentials['advSecretKey'];$flow=$this->flow(self::signer(...));
        $flow->handleNode(self::notification('passkey_prologue_request','1',[new BinaryNode('passkey_request_options',[],'{"rpId":"whatsapp.com"}')]));
        self::assertCount(1,$this->acks);self::assertSame([],$this->queries);self::assertSame($oldAdv,$this->credentials['advSecretKey']);
        $flow->drainPending();self::assertSame('waiting_for_primary_identity',$flow->stage());self::assertNotSame($oldAdv,$this->credentials['advSecretKey']);self::assertCount(2,$this->queries);
        $payload=$this->prologue->child('prologue_payload')->content;$prologue=SchemaCodec::decode('ProloguePayload',$payload);$identity=SchemaCodec::decode('CompanionEphemeralIdentity',$prologue['companionEphemeralIdentity']);
        self::assertSame('the-ref',$identity['ref']);self::assertSame(Crypto::hmac(ShortcakeCrypto::handoffKey($oldAdv),$payload),$this->prologue->child('pairing_handoff_proof')->content);
        $primary=Crypto::x25519KeyPair();$nonce=random_bytes(32);$primaryBytes=SchemaCodec::encode('PrimaryEphemeralIdentity',['publicKey'=>$primary['public'],'nonce'=>$nonce]);
        $flow->handleNode(self::notification('crsc_continuation','2',[new BinaryNode('primary_ephemeral_identity',[],$primaryBytes)]));self::assertNull($this->companionNonce);
        $flow->drainPending();self::assertSame('waiting_for_pairing',$flow->stage());
        self::assertSame($prologue['commitment']['hash'],Crypto::sha256($prologue['companionEphemeralIdentity'].$this->companionNonce));
        self::assertSame(ShortcakeCrypto::verificationCode($this->companionNonce,['publicKey'=>$primary['public'],'nonce'=>$nonce]),$flow->getVerificationCode());
        $key=Crypto::hkdf(Crypto::x25519($primary['private'],$identity['publicKey']),32,'Pairing Information Encryption Key','Companion Pairing 1 with ref the-ref');
        $envelope=SchemaCodec::decode('EncryptedPairingRequest',$this->envelope);$pair=SchemaCodec::decode('PairingRequest',Crypto::decryptGcm($key,$envelope['iv'],$envelope['encryptedPayload']));
        self::assertSame($this->credentials['noiseKeyPair']['public'],$pair['companionPublicKey']);self::assertSame($this->credentials['identityKeyPair']['public'],$pair['companionIdentityKey']);self::assertSame($this->credentials['advSecretKey'],$pair['advSecret']);
    }
    public function testNoSignerEmitsOneRequiredEventAndCanResumeAfterSignerConfigured():void
    {
        $flow=$this->flow();$node=self::notification('passkey_prologue_request','1',[new BinaryNode('passkey_request_options',[],'{"rpId":"whatsapp.com"}')]);
        $flow->handleNode($node);$flow->drainPending();$flow->drainPending();self::assertSame([],$this->queries);self::assertCount(1,$this->events);self::assertSame('auth_passkey_required',$this->events[0]['name']);
        $flow->setSigner(self::signer(...));$flow->drainPending();self::assertTrue($flow->hasSession());self::assertCount(2,$this->queries);
    }
    public function testManualConfirmationWaitsAndCannotBeRepeated():void
    {
        $flow=$this->flow(self::signer(...),false);$flow->requestPasskeyPairing();self::assertNull($this->prologue->child('pairing_handoff_proof'));
        $primary=Crypto::x25519KeyPair();$flow->handleNode(self::notification('crsc_continuation','1',[new BinaryNode('primary_ephemeral_identity',[],SchemaCodec::encode('PrimaryEphemeralIdentity',['publicKey'=>$primary['public'],'nonce'=>random_bytes(32)]))]));
        $flow->drainPending();self::assertSame('waiting_for_confirmation',$flow->stage());self::assertNull($this->envelope);
        $flow->confirmVerificationCode();self::assertSame('waiting_for_pairing',$flow->stage());self::assertNotNull($this->envelope);
        $this->expectException(\LogicException::class);$flow->confirmVerificationCode();
    }
    public function testDuplicateNotificationsAreAcknowledgedWithoutDuplicatingPrologue():void
    {
        $flow=$this->flow(self::signer(...));$node=self::notification('passkey_prologue_request','1',[new BinaryNode('passkey_request_options',[],'{"rpId":"whatsapp.com"}')]);$flow->handleNode($node);$flow->handleNode($node);$flow->drainPending();
        self::assertCount(2,$this->acks);self::assertCount(2,$this->queries);
    }
    public function testExpiredSessionsAndQueuedRequirementsDoNotContinue():void
    {
        $flow=$this->flow(self::signer(...));$flow->requestPasskeyPairing();$this->clock+=300;$flow->tick();self::assertFalse($flow->hasSession());self::assertSame('auth_passkey_expired',$this->events[array_key_last($this->events)]['name']);
        $this->expectException(\LogicException::class);$flow->confirmVerificationCode();
    }
    public function testForeignServerNotificationIsRejectedBeforeAcknowledgment():void
    {
        $flow=$this->flow();try{$flow->handleNode(new BinaryNode('notification',['from'=>'123@s.whatsapp.net','type'=>'passkey_prologue_request','id'=>'1']));self::fail();}catch(\UnexpectedValueException){}
        self::assertSame([],$this->acks);self::assertSame([],$this->queries);
    }
    public function testMalformedContinuationClearsSessionWithoutRevealingNonce():void
    {
        $flow=$this->flow(self::signer(...));$flow->requestPasskeyPairing();$flow->handleNode(self::notification('crsc_continuation','1',[new BinaryNode('primary_ephemeral_identity',[],SchemaCodec::encode('PrimaryEphemeralIdentity',['publicKey'=>'short','nonce'=>random_bytes(32)]))]));
        try{$flow->drainPending();self::fail();}catch(\UnexpectedValueException){}
        self::assertNull($this->companionNonce);self::assertFalse($flow->hasSession());self::assertSame('auth_passkey_error',$this->events[array_key_last($this->events)]['name']);
    }
    public function testRegistrationAndSignerRequirementsFailWithoutNetwork():void
    {
        $flow=$this->flow();try{$flow->requestPasskeyPairing();self::fail();}catch(\LogicException $e){self::assertStringContainsString('signer',$e->getMessage());}
        $this->credentials['registered']=true;$flow=$this->flow(self::signer(...));try{$flow->requestPasskeyPairing();self::fail();}catch(\LogicException $e){self::assertStringContainsString('registered',$e->getMessage());}
        self::assertSame([],$this->queries);
    }
}
