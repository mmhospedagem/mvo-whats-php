<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Auth\{CredentialStore,PairingFlow};
use MVO\Crypto\Crypto;
use MVO\Protocol\{BinaryNode,ClientPayload,SchemaCodec};
use MVO\Testing\{FakePairing,NoiseResponder};
use MVO\Transport\{NoiseSession,WebSocket};

final class FakePairingTest extends TestCase
{
    private function primary():array { $primary=CredentialStore::fresh();$primary['registered']=true;$primary['meJid']='5511999999999@s.whatsapp.net';$primary['meLid']='123456@lid';return $primary; }
    public function testRealNoiseQrAdvDeviceProofAndPersistedIkLogin():void { $this->pairAndRestart(false); }
    public function testRealNoiseCodeAdvDeviceProofAndPersistedIkLogin():void { $this->pairAndRestart(true); }
    private function pairAndRestart(bool $code):void
    {
        $directory=sys_get_temp_dir().'/mvo-fake-pair-'.bin2hex(random_bytes(8));$store=new CredentialStore($directory);$credentials=CredentialStore::fresh();$store->save($credentials);$primary=$this->primary();$pairing=new FakePairing($primary,7);$root=Crypto::x25519KeyPair();$server=Crypto::x25519KeyPair();$responder=new NoiseResponder($root,$server);$wire=new FakePairingSocket($responder,$pairing);$session=new NoiseSession($wire,rootPublicKey:$root['public']);$static=$session->start($credentials['noiseKeyPair']['private'],$credentials['noiseKeyPair']['public'],ClientPayload::registration($credentials,'2.3000.100'));$events=[];$pending=[];
        $flow=new PairingFlow(function()use(&$credentials){return $credentials;},function($next)use(&$credentials,$store){$credentials=$next;$store->save($next);},$session->sendNode(...),function($node)use($session,&$pending){$id='IQ-'.bin2hex(random_bytes(4));$session->sendNode(new BinaryNode($node->tag,[...$node->attrs,'id'=>$id],$node->content));while(($reply=$session->receiveNode(0))!==null){if(($reply->attrs['id']??null)===$id)return $reply;$pending[]=$reply;}throw new \RuntimeException('Fake pairing query reply missing');},function($event,$data)use(&$events){$events[$event]=$data;});
        try{
            self::assertTrue($flow->handleNode($session->receiveNode()));self::assertArrayHasKey('auth_qr',$events);self::assertNull($pairing->completeCredentials());
            if($code){self::assertSame('ABCD1234',$flow->requestCode('5511999999999','ABCD1234'));$wire->enqueue($pairing->approveCode('ABCD1234'));$flow->handleNode($session->receiveNode());$flow->handleNode($session->receiveNode());}
            else{$wire->enqueue($pairing->scanQr($events['auth_qr']['qr']));$flow->handleNode($session->receiveNode());}
            self::assertTrue($credentials['registered']);self::assertSame('5511999999999:7@s.whatsapp.net',$credentials['meJid']);self::assertSame('123456:7@lid',$credentials['meLid']);$registry=$pairing->completeCredentials();self::assertNotNull($registry);self::assertSame($credentials['identityKeyPair']['public'],$registry['identityKeyPair']['public']);self::assertSame($credentials['noiseKeyPair']['public'],$registry['noiseKeyPair']['public']);self::assertSame($credentials['signedIdentity']['deviceSignature'],$registry['signedIdentity']['deviceSignature']);self::assertSame('515',$session->receiveNode()->attrs['code']);self::assertArrayNotHasKey('private',$registry['identityKeyPair']);$session->close();$store->close();
            $reopened=new CredentialStore($directory);$saved=$reopened->load();self::assertTrue($saved['registered']);self::assertSame($credentials['identityKeyPair']['private'],$saved['identityKeyPair']['private']);$resumedResponder=new NoiseResponder($root,$server);$resumedWire=new FakePairingSocket($resumedResponder);$resumed=new NoiseSession($resumedWire,rootPublicKey:$root['public'],serverStaticKey:$static,routingInfo:"saved-route\0");self::assertSame($static,$resumed->start($saved['noiseKeyPair']['private'],$saved['noiseKeyPair']['public'],ClientPayload::login($saved,'2.3000.100')));self::assertSame('IK',$resumedResponder->mode());self::assertSame("saved-route\0",$resumedResponder->routingInfo());$login=SchemaCodec::decode('ClientPayload',$resumedResponder->clientPayload());self::assertSame(7,$login['device']);self::assertSame($registry['noiseKeyPair']['public'],$resumedResponder->clientStaticKey());self::assertSame('success',$resumed->receiveNode()->tag);$resumed->close();$reopened->close();
        }finally{$session->close();$store->close();foreach(['state.sqlite','state.sqlite-wal','state.sqlite-shm','connection.lock'] as $file)if(is_file($directory.'/default/'.$file))unlink($directory.'/default/'.$file);if(is_dir($directory.'/default'))rmdir($directory.'/default');if(is_dir($directory))rmdir($directory);}
    }
    public function testStaleStaticFallsBackAndBadCertificateCannotRegister():void
    {
        $root=Crypto::x25519KeyPair();$server=Crypto::x25519KeyPair();$client=Crypto::x25519KeyPair();$responder=new NoiseResponder($root,$server);$session=new NoiseSession(new FakePairingSocket($responder),rootPublicKey:$root['public'],serverStaticKey:Crypto::x25519KeyPair()['public']);self::assertSame($server['public'],$session->start($client['private'],$client['public'],'login'));self::assertSame('XXfallback',$responder->mode());self::assertSame('login',$responder->clientPayload());$session->close();
        $bad=new NoiseResponder($root,$server,true,true);$socket=new FakePairingSocket($bad);$session=new NoiseSession($socket,rootPublicKey:$root['public'],serverStaticKey:$server['public']);try{$session->start($client['private'],$client['public'],'payload');self::fail('Tampered fallback CA was accepted');}catch(\UnexpectedValueException){self::assertTrue($socket->closed);self::assertFalse($bad->isAuthenticated());}
    }
    public function testUnrelatedQrAndWrongCodeNeverProduceCompletedCredentials():void
    {
        $credentials=CredentialStore::fresh();$pairing=new FakePairing($this->primary());$events=[];$flow=new PairingFlow(function()use(&$credentials){return $credentials;},function($next)use(&$credentials){$credentials=$next;},static fn()=>null,function($node)use($pairing){return $pairing->handle(new BinaryNode($node->tag,[...$node->attrs,'id'=>'HELLO'],$node->content))[0];},function($event,$data)use(&$events){$events[$event]=$data;});$flow->handleNode($pairing->start(ClientPayload::registration($credentials,'2.3000.100'),$credentials['noiseKeyPair']['public'])[0]);$parts=explode(',',$events['auth_qr']['qr']);$parts[1]=base64_encode(Crypto::x25519KeyPair()['public']);try{$pairing->scanQr(implode(',',$parts));self::fail('Unrelated Noise key accepted');}catch(\UnexpectedValueException){}self::assertNull($pairing->completeCredentials());$flow->requestCode('5511999999999','ABCD1234');$notification=$pairing->approveCode('ABCDEF12');try{$flow->handleNode($notification);self::fail('Wrong pairing code accepted');}catch(\UnexpectedValueException){}self::assertNull($pairing->completeCredentials());self::assertFalse($credentials['registered']);
    }
    public function testFakeServerRequiresTheCompanionsActualSignedAdvProof():void
    {
        $credentials=CredentialStore::fresh();$pairing=new FakePairing($this->primary());$sent=[];$qr=null;$flow=new PairingFlow(function()use(&$credentials){return $credentials;},function($next)use(&$credentials){$credentials=$next;},function($node)use(&$sent){$sent[]=$node;},static fn()=>throw new \LogicException('QR requires no query'),function($event,$data)use(&$qr){if($event==='auth_qr')$qr=$data['qr'];});$flow->handleNode($pairing->start(ClientPayload::registration($credentials,'2.3000.100'),$credentials['noiseKeyPair']['public'])[0]);$flow->handleNode($pairing->scanQr($qr));self::assertNull($pairing->completeCredentials());$proof=$sent[1];$identity=$proof->child('pair-device-sign')->child('device-identity');$decoded=SchemaCodec::decode('ADVSignedDeviceIdentity',$identity->content);$decoded['deviceSignature'][0]=chr(ord($decoded['deviceSignature'][0])^1);$corrupt=new BinaryNode('iq',$proof->attrs,[new BinaryNode('pair-device-sign',[],[new BinaryNode('device-identity',$identity->attrs,SchemaCodec::encode('ADVSignedDeviceIdentity',$decoded))])]);try{$pairing->handle($corrupt);self::fail('Fake registry accepted an invalid companion proof');}catch(\UnexpectedValueException){}self::assertNull($pairing->completeCredentials());self::assertSame('515',$pairing->handle($proof)[0]->attrs['code']);self::assertTrue($pairing->completeCredentials()['registered']);
    }
}

/** Only socket I/O is simulated; all Noise/ADV/code crypto uses production implementations. */
final class FakePairingSocket extends WebSocket
{
    private array $queue=[];public bool $closed=false;
    public function __construct(private readonly NoiseResponder $responder,private readonly ?FakePairing $pairing=null) { parent::__construct(); }
    public function send(string $payload):void
    {
        foreach(str_split($payload,7) as $bytes){$result=$this->responder->push($bytes);foreach($result['out'] as $out)foreach(str_split($out,5) as $chunk)$this->queue[]=$chunk;if($result['authenticated']){foreach($this->pairing?->start($this->responder->clientPayload(),$this->responder->clientStaticKey())??[new BinaryNode('success')] as $node)$this->enqueue($node);}foreach($result['nodes'] as $node)foreach($this->pairing?->handle($node)??[] as $reply)$this->enqueue($reply);}
    }
    public function enqueue(BinaryNode $node):void { $this->queue[]=$this->responder->encode($node); }
    public function receive(float $timeoutSeconds=1.0):?string { return array_shift($this->queue); }
    public function close():void { $this->closed=true;$this->queue=[]; }
}
