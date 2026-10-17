<?php
declare(strict_types=1);

namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Auth\CredentialStore;
use MVO\Crypto\{Crypto,XEdDsa};
use MVO\Protocol\Protobuf;
use MVO\Signal\{SignalCodec,SignalProtocol,SignalRatchet,SignalState,SignalStore};

final class SignalProtocolTest extends TestCase
{
    private string $directory;
    private array $aliceCredentials;
    private array $bobCredentials;
    private ?SignalStore $aliceStore = null;
    private ?SignalStore $bobStore = null;
    private ?SignalProtocol $alice = null;
    private ?SignalProtocol $bob = null;
    private array $bobPreKey;
    private const ALICE = '5511999990001:2@s.whatsapp.net';
    private const BOB = '5511999990002:3@s.whatsapp.net';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/mvo-signal-'.bin2hex(random_bytes(6));
        mkdir($this->directory,0700);
        $this->aliceCredentials = CredentialStore::fresh();
        $this->bobCredentials = CredentialStore::fresh();
        $this->aliceStore = new SignalStore($this->directory.'/alice.sqlite');
        $this->bobStore = new SignalStore($this->directory.'/bob.sqlite');
        $this->bobPreKey = $this->bobStore->generatePreKeys(1)[0];
        $this->alice = new SignalProtocol($this->aliceStore,$this->aliceCredentials);
        $this->bob = new SignalProtocol($this->bobStore,$this->bobCredentials);
    }

    protected function tearDown(): void
    {
        $this->aliceStore?->close(); $this->bobStore?->close();
        $this->alice = $this->bob = null;
        $this->aliceStore = $this->bobStore = null;
        gc_collect_cycles(); // Exception traces can retain transaction closures/PDO handles on Windows.
        foreach (glob($this->directory.'/*') ?: [] as $file) unlink($file);
        rmdir($this->directory);
    }

    private function bundle(bool $withOneTime = true): array
    {
        $bundle = [
            'registrationId'=>$this->bobCredentials['registrationId'],
            'identityKey'=>$this->bobCredentials['identityKeyPair']['public'],
            'signedPreKey'=>array_intersect_key($this->bobCredentials['signedPreKey'],array_flip(['id','public','signature'])),
        ];
        if ($withOneTime) $bundle['preKey'] = ['id'=>$this->bobPreKey['id'],'public'=>$this->bobPreKey['public']];
        return $bundle;
    }

    private function sendAlice(string $text): array
    {
        $encrypted = $this->alice->encrypt(self::BOB,$text);
        self::assertSame($text,$this->bob->decrypt(self::ALICE,$encrypted['type'],$encrypted['ciphertext']));
        return $encrypted;
    }

    private function sendBob(string $text): array
    {
        $encrypted = $this->bob->encrypt(self::ALICE,$text);
        self::assertSame($text,$this->alice->decrypt(self::BOB,$encrypted['type'],$encrypted['ciphertext']));
        return $encrypted;
    }

    public function testX3dhAndMultipleBidirectionalRatchets(): void
    {
        $this->alice->ensureSession(self::BOB,$this->bundle());
        self::assertSame('pkmsg',$this->sendAlice('Primeira mensagem')['type']);
        self::assertNull($this->bobStore->getPreKey($this->bobPreKey['id']));
        self::assertSame('pkmsg',$this->sendAlice('Ainda aguardando resposta')['type']);
        self::assertSame('msg',$this->sendBob('Olá, recebido')['type']);
        for ($i=0;$i<12;$i++) {
            self::assertSame('msg',$this->sendAlice('Mensagem '.$i)['type']);
            self::assertSame('msg',$this->sendBob('Resposta '.$i)['type']);
        }
        self::assertLessThanOrEqual(5,count($this->aliceStore->getSession(self::BOB)['receive']));
        self::assertLessThanOrEqual(5,count($this->bobStore->getSession(self::ALICE)['receive']));
    }

    public function testHandshakeWithoutOneTimePreKey(): void
    {
        $this->alice->ensureSession(self::BOB,$this->bundle(false));
        $this->sendAlice('Sem prekey de uso único');
        $this->sendBob('Resposta');
        self::assertNotNull($this->bobStore->getPreKey($this->bobPreKey['id']));
    }

    public function testZeroRemoteRegistrationCompletesAuthenticatedHandshakeAndSurvivesRestart():void
    {
        $bundle=$this->bundle();$bundle['registrationId']=0;$this->alice->ensureSession(self::BOB,$bundle);
        self::assertSame(0,$this->aliceStore->getSession(self::BOB)['remoteRegistration']);$this->sendAlice('Registro remoto zero');$this->sendBob('Resposta autenticada');
        $this->aliceStore->close();$this->alice=null;$this->aliceStore=new SignalStore($this->directory.'/alice.sqlite');$this->alice=new SignalProtocol($this->aliceStore,$this->aliceCredentials);
        self::assertSame(0,$this->aliceStore->getSession(self::BOB)['remoteRegistration']);self::assertSame('msg',$this->sendAlice('Após reinício')['type']);$this->sendBob('Sem substituir identidade');
    }
    public function testZeroRemoteRegistrationNeverSkipsSignatureValidation():void
    {
        $bundle=$this->bundle();$bundle['registrationId']=0;$bundle['signedPreKey']['signature'][0]=chr(ord($bundle['signedPreKey']['signature'][0])^1);
        try{$this->alice->ensureSession(self::BOB,$bundle);self::fail('Invalid signature accepted');}catch(\UnexpectedValueException $error){self::assertSame('Invalid remote Signal signed prekey signature',$error->getMessage());}
        self::assertNull($this->aliceStore->getSession(self::BOB));self::assertNull($this->aliceStore->getIdentity(self::BOB));
    }
    public function testZeroRemoteRegistrationDoesNotReplaceTrustedIdentityEvenWithForce():void
    {
        $bundle=$this->bundle();$bundle['registrationId']=0;$this->alice->ensureSession(self::BOB,$bundle);$before=$this->aliceStore->getSession(self::BOB);$trusted=$this->aliceStore->getIdentity(self::BOB);$impostor=CredentialStore::fresh();
        try{$this->alice->ensureSession(self::BOB,['registrationId'=>0,'identityKey'=>$impostor['identityKeyPair']['public'],'signedPreKey'=>$impostor['signedPreKey']],true);self::fail('Changed identity accepted');}catch(\UnexpectedValueException $error){self::assertStringContainsString('Signal identity changed',$error->getMessage());}
        self::assertSame($before,$this->aliceStore->getSession(self::BOB));self::assertSame($trusted,$this->aliceStore->getIdentity(self::BOB));
    }
    public function testRemoteRegistrationStillRequiresAnUnsignedIntegerAndLocalGenerationStaysNonzero():void
    {
        foreach([-1,0x100000000,'0',0.0,null]as$invalid){$bundle=$this->bundle();$bundle['registrationId']=$invalid;try{$this->alice->ensureSession(self::BOB,$bundle);self::fail('Invalid remote registration accepted');}catch(\UnexpectedValueException $error){self::assertSame('Invalid remote Signal registration ID',$error->getMessage());}self::assertNull($this->aliceStore->getSession(self::BOB));}
        $bundle=$this->bundle();$bundle['registrationId']=0xffffffff;$this->alice->ensureSession(self::BOB,$bundle);self::assertSame(0xffffffff,$this->aliceStore->getSession(self::BOB)['remoteRegistration']);
        $local=$this->aliceCredentials;$local['registrationId']=0;try{new SignalProtocol($this->aliceStore,$local);self::fail('Changed local policy');}catch(\InvalidArgumentException $error){self::assertSame('Invalid Signal registration ID',$error->getMessage());}
    }
    public function testSignalPrekeyWirePreservesZeroRegistrationOnAuthenticatedReceive():void
    {
        $this->alice->ensureSession(self::BOB,$this->bundle());$encrypted=$this->alice->encrypt(self::BOB,'Campo uint32 zero');$parsed=SignalCodec::readPreKeyMessage($encrypted['ciphertext']);
        $wire=SignalCodec::writePreKeyMessage(0,$parsed['preKeyId'],$parsed['signedPreKeyId'],$parsed['baseKey'],$parsed['identityKey'],$parsed['body']);self::assertSame(0,SignalCodec::readPreKeyMessage($wire)['registrationId']);
        self::assertSame('Campo uint32 zero',$this->bob->decrypt(self::ALICE,'pkmsg',$wire));self::assertSame(0,$this->bobStore->getSession(self::ALICE)['remoteRegistration']);
    }

    public function testSessionAndSkippedKeysSurviveApplicationRestart(): void
    {
        $this->alice->ensureSession(self::BOB,$this->bundle());
        $first = $this->alice->encrypt(self::BOB,'0');
        $second = $this->alice->encrypt(self::BOB,'1');
        self::assertSame('1',$this->bob->decrypt(self::ALICE,$second['type'],$second['ciphertext']));
        $this->alice = $this->bob = null;
        $this->aliceStore = $this->bobStore = null;
        $this->aliceStore = new SignalStore($this->directory.'/alice.sqlite');
        $this->bobStore = new SignalStore($this->directory.'/bob.sqlite');
        $this->alice = new SignalProtocol($this->aliceStore,$this->aliceCredentials);
        $this->bob = new SignalProtocol($this->bobStore,$this->bobCredentials);
        self::assertTrue($this->alice->hasSession(self::BOB));
        self::assertTrue($this->bob->hasSession(self::ALICE));
        self::assertSame('0',$this->bob->decrypt(self::ALICE,$first['type'],$first['ciphertext']));
        $this->sendBob('Sessão restaurada');
        self::assertSame('msg',$this->sendAlice('Continua criptografada')['type']);
    }

    public function testOutOfOrderMessagesUseSkippedKeys(): void
    {
        $this->alice->ensureSession(self::BOB,$this->bundle());
        $messages = [];
        for ($i=0;$i<8;$i++) $messages[] = $this->alice->encrypt(self::BOB,(string)$i);
        foreach ([7,1,3,0,6,2,4,5] as $index) {
            $message = $messages[$index];
            self::assertSame((string)$index,$this->bob->decrypt(self::ALICE,$message['type'],$message['ciphertext']));
        }
        self::assertSame([],$this->bobStore->getSession(self::ALICE)['receive'][0]['unused']);
    }

    public function testDelayedMessageFromPreviousReceiveChain(): void
    {
        $this->alice->ensureSession(self::BOB,$this->bundle());
        $this->sendAlice('Estabelecer');
        $delayed = $this->alice->encrypt(self::BOB,'Atrasada');
        $this->sendBob('Nova chave');
        $this->sendAlice('Outra cadeia');
        self::assertSame('Atrasada',$this->bob->decrypt(self::ALICE,$delayed['type'],$delayed['ciphertext']));
    }

    public function testTamperedFirstMessageDoesNotConsumeKeyOrTrustIdentity(): void
    {
        $this->alice->ensureSession(self::BOB,$this->bundle());
        $message = $this->alice->encrypt(self::BOB,'Autêntica');
        $tampered = $message['ciphertext'];
        // Last bytes include integer metadata; tamper the inner Signal MAC explicitly.
        $parsed = SignalCodec::readPreKeyMessage($tampered);
        $inner = $parsed['body']; $inner[strlen($inner)-1] = $inner[strlen($inner)-1]^"\x01";
        $tampered = SignalCodec::writePreKeyMessage($parsed['registrationId'],$parsed['preKeyId'],$parsed['signedPreKeyId'],$parsed['baseKey'],$parsed['identityKey'],$inner);
        try { $this->bob->decrypt(self::ALICE,'pkmsg',$tampered); self::fail('Tampered MAC must fail'); }
        catch (\UnexpectedValueException $error) { self::assertStringContainsString('MAC',$error->getMessage()); }
        self::assertNull($this->bobStore->getSession(self::ALICE));
        self::assertNull($this->bobStore->getIdentity(self::ALICE));
        self::assertNotNull($this->bobStore->getPreKey($this->bobPreKey['id']));
        self::assertSame('Autêntica',$this->bob->decrypt(self::ALICE,'pkmsg',$message['ciphertext']));
    }

    public function testTamperingPreservesExistingRatchetForValidRetry(): void
    {
        $this->alice->ensureSession(self::BOB,$this->bundle());
        $this->sendAlice('0'); $this->sendBob('1');
        $message = $this->alice->encrypt(self::BOB,'2');
        $before = $this->bobStore->getSession(self::ALICE);
        $tampered = $message['ciphertext'];
        $tampered[strlen($tampered)-1] = $tampered[strlen($tampered)-1]^"\x80";
        try { $this->bob->decrypt(self::ALICE,'msg',$tampered); self::fail('Tampered MAC must fail'); }
        catch (\UnexpectedValueException) { self::assertSame($before,$this->bobStore->getSession(self::ALICE)); }
        self::assertSame('2',$this->bob->decrypt(self::ALICE,'msg',$message['ciphertext']));
    }

    public function testReplayRejectedWithoutChangingRatchet(): void
    {
        $this->alice->ensureSession(self::BOB,$this->bundle());
        $message = $this->sendAlice('Uma vez');
        $before = $this->bobStore->getSession(self::ALICE);
        try { $this->bob->decrypt(self::ALICE,$message['type'],$message['ciphertext']); self::fail('Replay accepted'); }
        catch (\UnexpectedValueException $error) { self::assertStringContainsString('Duplicate',$error->getMessage()); }
        self::assertSame($before,$this->bobStore->getSession(self::ALICE));
    }

    public function testRejectsChangedTrustedIdentity(): void
    {
        $this->alice->ensureSession(self::BOB,$this->bundle());
        $before = $this->aliceStore->getSession(self::BOB);
        $impostor = CredentialStore::fresh();
        $bundle = ['registrationId'=>$impostor['registrationId'],'identityKey'=>$impostor['identityKeyPair']['public'],'signedPreKey'=>$impostor['signedPreKey']];
        try { $this->alice->ensureSession(self::BOB,$bundle); self::fail('Changed identity accepted'); }
        catch (\UnexpectedValueException $error) { self::assertStringContainsString('identity changed',$error->getMessage()); }
        self::assertSame($before,$this->aliceStore->getSession(self::BOB));
    }

    public function testRejectsInvalidSignedPrekeyWithoutPersistingSession(): void
    {
        $bundle = $this->bundle();
        $bundle['signedPreKey']['signature'][0] = $bundle['signedPreKey']['signature'][0]^"\x01";
        try { $this->alice->ensureSession(self::BOB,$bundle); self::fail('Invalid signature accepted'); }
        catch (\UnexpectedValueException $error) { self::assertStringContainsString('signature',$error->getMessage()); }
        self::assertFalse($this->alice->hasSession(self::BOB));
        self::assertNull($this->aliceStore->getIdentity(self::BOB));
    }

    public function testEnsureSessionReusesStateWithoutResettingRatchet(): void
    {
        $this->alice->ensureSession(self::BOB,$this->bundle());
        $this->sendAlice('0'); $this->sendBob('1');
        $before = $this->aliceStore->getSession(self::BOB);
        $this->alice->ensureSession(self::BOB,$this->bundle());
        self::assertSame($before,$this->aliceStore->getSession(self::BOB));
        self::assertSame('msg',$this->sendAlice('2')['type']);
    }

    public function testPreviousSessionFallbackAcceptsDelayedAuthenticatedMessages(): void
    {
        $this->alice->ensureSession(self::BOB,$this->bundle());
        $this->sendAlice('Primeira sessão'); $this->sendBob('Confirmada');
        $old = $this->alice->encrypt(self::BOB,'Sessão anterior');
        $this->bobPreKey = $this->bobStore->generatePreKeys(1)[0];
        $this->alice->ensureSession(self::BOB,$this->bundle(),true);
        $this->sendAlice('Nova sessão');
        self::assertSame('Sessão anterior',$this->bob->decrypt(self::ALICE,$old['type'],$old['ciphertext']));
        $this->sendBob('Resposta da sessão anterior');
    }

    public function testBinaryProtobufPayloadIsPreservedExactly(): void
    {
        $this->alice->ensureSession(self::BOB,$this->bundle());
        $payload = Protobuf::encode([['number'=>1,'wire'=>2,'value'=>"Olá\0\xff\x80\n"]]);
        $this->sendAlice($payload);
        $this->sendBob('');
    }

    public function testMissingPrekeyDoesNotCreateSession(): void
    {
        $this->alice->ensureSession(self::BOB,$this->bundle());
        $message = $this->alice->encrypt(self::BOB,'Sem chave');
        $this->bobStore->consumePreKey($this->bobPreKey['id']);
        $this->expectExceptionMessage('one-time prekey not found');
        $this->bob->decrypt(self::ALICE,'pkmsg',$message['ciphertext']);
    }

    public function testTooFarFutureCounterRejectedBeforeCommittingState(): void
    {
        $this->alice->ensureSession(self::BOB,$this->bundle());
        $message = $this->alice->encrypt(self::BOB,'Excessivo');
        $parsed = SignalCodec::readPreKeyMessage($message['ciphertext']);
        $inner = SignalCodec::writeMessage($parsed['ratchetKey'],2001,0,$parsed['ciphertext']).str_repeat("\0",8);
        $outer = SignalCodec::writePreKeyMessage($parsed['registrationId'],$parsed['preKeyId'],$parsed['signedPreKeyId'],$parsed['baseKey'],$parsed['identityKey'],$inner);
        try { $this->bob->decrypt(self::ALICE,'pkmsg',$outer); self::fail('Future counter accepted'); }
        catch (\UnexpectedValueException $error) { self::assertStringContainsString('future',$error->getMessage()); }
        self::assertNull($this->bobStore->getSession(self::ALICE));
        self::assertNotNull($this->bobStore->getPreKey($this->bobPreKey['id']));
    }

    public function testOutboxMetadataAndRatchetAdvanceRollBackTogether(): void
    {
        $this->alice->ensureSession(self::BOB,$this->bundle());
        $before = $this->aliceStore->getSession(self::BOB);
        try {
            $this->aliceStore->transaction(function(SignalStore $store): void {
                $encrypted = $this->alice->encrypt(self::BOB,'Não foi enfileirada');
                $store->set('outbox','id',$encrypted['ciphertext']);
                throw new \RuntimeException('Interrompida');
            });
            self::fail('Transaction failure swallowed');
        } catch (\RuntimeException $error) { self::assertSame('Interrompida',$error->getMessage()); }
        self::assertSame($before,$this->aliceStore->getSession(self::BOB));
        self::assertNull($this->aliceStore->get('outbox','id'));
        $this->sendAlice('Counter não perdido');
    }

    public function testPrekeysPersistAcrossRestartAndUploadMarking(): void
    {
        self::assertCount(1,$this->bobStore->pendingPreKeys());
        $more = $this->bobStore->generatePreKeys(3);
        self::assertSame([2,3,4],array_column($more,'id'));
        $this->bobStore->markPreKeysUploaded([1,3]);
        self::assertSame([2,4],array_column($this->bobStore->pendingPreKeys(),'id'));
        $this->bob = null; $this->bobStore = null;
        $this->bobStore = new SignalStore($this->directory.'/bob.sqlite');
        self::assertSame([2,4],array_column($this->bobStore->pendingPreKeys(),'id'));
        self::assertNotNull($this->bobStore->getPreKey(1),'Uploaded keys remain available for incoming decryption');
        self::assertSame(5,$this->bobStore->generatePreKeys(1)[0]['id']);
    }

    public function testCanonicalAddressAndNamespacesRemainIsolated(): void
    {
        $this->alice->ensureSession(self::BOB,$this->bundle());
        self::assertTrue($this->alice->hasSession('5511999990002:3@hosted'));
        self::assertFalse($this->alice->hasSession('5511999990002:4@s.whatsapp.net'));
        self::assertFalse($this->alice->hasSession('5511999990002:3@lid'));
        $other = new SignalStore($this->directory.'/alice.sqlite','other');
        self::assertNull($other->getSession(self::BOB));
        $this->aliceStore->set('inbox','id',"\0\xff");
        self::assertNull($other->get('inbox','id'));
        self::assertSame("\0\xff",$this->aliceStore->get('inbox','id'));
    }

    public function testInvalidVersionAndUnsupportedEnvelopeRejected(): void
    {
        $this->expectExceptionMessage('Unsupported Signal version');
        $this->bob->decrypt(self::ALICE,'msg',"\x22".str_repeat('x',30));
    }

    public function testUnsupportedGroupEnvelopeIsExplicitlyRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bob->decrypt(self::ALICE,'skmsg','payload');
    }

    public function testDifferentLocalIdentityCannotReuseOldSession(): void
    {
        $this->alice->ensureSession(self::BOB,$this->bundle());
        $replacement = new SignalProtocol($this->aliceStore,CredentialStore::fresh());
        $this->expectExceptionMessage('different local identity');
        $replacement->encrypt(self::BOB,'Inválida');
    }

    public function testStateBinaryRoundtripAndMalformedPrefix(): void
    {
        $value = ['private'=>"\0\xff\x80",'counter'=>3,'nested'=>[null,true,false,'text']];
        self::assertSame($value,SignalState::unpack(json_decode(json_encode(SignalState::pack($value),JSON_THROW_ON_ERROR),true,128,JSON_THROW_ON_ERROR)));
        $this->expectExceptionMessage('Invalid Signal public key');
        SignalState::publicKey("\x06".random_bytes(32));
    }

    private function independentVector(): array
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../fixtures/signal-v3-vector.json'),true,128,JSON_THROW_ON_ERROR);
        unset($fixture['provenance']);
        return array_map(static fn(string $hex): string => hex2bin($hex),$fixture);
    }

    public function testDecryptsIndependentPythonOpenSslX3dhWireVector(): void
    {
        $vector = $this->independentVector();
        $identity = ['private'=>$vector['bobIdentityPrivate'],'public'=>$vector['bobIdentityPublic']];
        $signed = ['id'=>17,'private'=>$vector['bobSignedPrivate'],'public'=>$vector['bobSignedPublic'],'signature'=>XEdDsa::sign($identity['private'],$vector['bobSignedPublic'])];
        $this->bob = new SignalProtocol($this->bobStore,['registrationId'=>31337,'identityKeyPair'=>$identity,'signedPreKey'=>$signed]);
        $this->bobStore->putPreKey(23,['private'=>$vector['bobPreKeyPrivate'],'public'=>$vector['bobPreKeyPublic']]);
        self::assertSame($vector['plaintext'],$this->bob->decrypt(self::ALICE,'pkmsg',$vector['preKeySignalMessage']));
        self::assertNull($this->bobStore->getPreKey(23));
        self::assertSame($vector['aliceIdentityPublic'],$this->bobStore->getIdentity(self::ALICE));
    }

    public function testEncryptionExactlyMatchesIndependentPythonOpenSslWireVector(): void
    {
        $vector = $this->independentVector();
        $identity = ['private'=>$vector['aliceIdentityPrivate'],'public'=>$vector['aliceIdentityPublic']];
        $signed = $this->aliceCredentials['signedPreKey'];
        $signed['signature'] = XEdDsa::sign($identity['private'],SignalState::publicKey($signed['public']));
        $this->alice = new SignalProtocol($this->aliceStore,['registrationId'=>12345,'identityKeyPair'=>$identity,'signedPreKey'=>$signed]);
        $this->aliceStore->putSession(self::BOB,[
            'localIdentity'=>$vector['aliceIdentityPublic'],'remoteIdentity'=>$vector['bobIdentityPublic'],
            'localRegistration'=>12345,'remoteRegistration'=>31337,'root'=>$vector['sendRoot'],
            'send'=>['pair'=>['private'=>$vector['aliceRatchetPrivate'],'public'=>$vector['aliceRatchetPublic']],'index'=>0,'key'=>$vector['sendChain']],
            'receive'=>[['public'=>$vector['bobSignedPublic'],'index'=>0,'key'=>$vector['initialChain'],'unused'=>[]]],
            'pending'=>['preKeyId'=>23,'signedPreKeyId'=>17,'baseKey'=>$vector['aliceBasePublic']],
            'previousCounter'=>0,'baseKey'=>$vector['aliceBasePublic'],'previous'=>[],
        ]);
        $keys = SignalRatchet::deriveMessageKey(0,$vector['sendChain']);
        self::assertSame($vector['nextChain'],$keys['nextKey']);
        self::assertSame($vector['cipherKey'],$keys['messageKey']['cipherKey']);
        self::assertSame($vector['macKey'],$keys['messageKey']['macKey']);
        self::assertSame($vector['iv'],$keys['messageKey']['iv']);
        self::assertSame(['type'=>'pkmsg','ciphertext'=>$vector['preKeySignalMessage']],$this->alice->encrypt(self::BOB,$vector['plaintext']));
    }

    public function testIncomingChangedIdentityCannotReplaceAuthenticatedSession(): void
    {
        $this->alice->ensureSession(self::BOB,$this->bundle());
        $this->sendAlice('Identidade confiável');
        $before = $this->bobStore->getSession(self::ALICE);
        $thirdStore = new SignalStore($this->directory.'/third.sqlite');
        try {
            $third = new SignalProtocol($thirdStore,CredentialStore::fresh());
            $third->ensureSession(self::BOB,$this->bundle(false));
            $message = $third->encrypt(self::BOB,'Identidade diferente');
            try { $this->bob->decrypt(self::ALICE,$message['type'],$message['ciphertext']); self::fail('Changed identity accepted'); }
            catch (\UnexpectedValueException $error) { self::assertStringContainsString('identity changed',$error->getMessage()); }
            self::assertSame($before,$this->bobStore->getSession(self::ALICE));
        } finally { $thirdStore->close(); }
    }

    public function testCaughtNestedFailureStillRollsBackOuterMetadata(): void
    {
        $this->alice->ensureSession(self::BOB,$this->bundle());
        $this->sendAlice('Inicial');
        $before = $this->bobStore->getSession(self::ALICE);
        try {
            $this->bobStore->transaction(function(SignalStore $store): void {
                $store->set('inbox','before','must roll back');
                try { $this->bob->encrypt('5511000000000@s.whatsapp.net','No session'); }
                catch (\UnexpectedValueException) { /* Caller must not accidentally commit a partial transaction. */ }
                $store->set('inbox','after','must also roll back');
            });
            self::fail('Outer transaction committed after nested failure');
        } catch (\RuntimeException $error) { self::assertStringContainsString('nested failure',$error->getMessage()); }
        self::assertSame($before,$this->bobStore->getSession(self::ALICE));
        self::assertNull($this->bobStore->get('inbox','before'));
        self::assertNull($this->bobStore->get('inbox','after'));
        $this->sendBob('Store continua utilizável');
    }

    public function testPrekeyAllocationCannotOverwriteAKeyAndInvalidCountsFail(): void
    {
        $original = $this->bobStore->getPreKey($this->bobPreKey['id']);
        try { $this->bobStore->putPreKey($this->bobPreKey['id'],Crypto::x25519KeyPair()); self::fail('Prekey overwritten'); }
        catch (\LogicException $error) { self::assertStringContainsString('overwrite',$error->getMessage()); }
        self::assertSame($original,$this->bobStore->getPreKey($this->bobPreKey['id']));
        $this->expectException(\InvalidArgumentException::class);
        $this->bobStore->generatePreKeys(0);
    }
}
