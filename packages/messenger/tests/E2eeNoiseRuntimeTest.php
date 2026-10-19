<?php
declare(strict_types=1);
namespace MVO\Messenger\Tests;
use MVO\Messenger\E2ee\{Crypto,CryptoAuthToken,DeviceKeys,ProtoWire as W,XEdDsa};
use MVO\Messenger\E2ee\Noise\{NoiseConnection,NoiseHandshake,NoiseChannel,NoiseCertificate,FrameCodec};
use MVO\Messenger\E2ee\Protocol\{BinaryCodec,BinaryNode,ClientPayload};
use MVO\Messenger\Transport\Socket;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/fixtures/NoisePeer.php';

final class E2eeNoiseRuntimeTest extends TestCase
{
    protected function setUp():void{if(!extension_loaded('gmp'))self::markTestSkipped('Optional GMP is required for the Messenger trust profile');}
    public function testCryptoMatchesRfc5869CaseOne():void
    {
        self::assertSame('3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865',bin2hex(Crypto::hkdf(str_repeat("\x0b",22),42,hex2bin('f0f1f2f3f4f5f6f7f8f9'),hex2bin('000102030405060708090a0b0c'))));
    }
    public function testFreshXxAuthenticatesCertificateAndExchangesFragmentedEncryptedFrames():void
    {
        $peer=new MessengerNoisePeer();$client=new NoiseConnection($peer,testRootPublic:$peer->root['public']);$keys=DeviceKeys::generate('10001');
        $payload=ClientPayload::login($keys,7,new CryptoAuthToken('10001','123','SYNTHETIC-CAT',time()+600),[999,1,2],'Synthetic test user agent');
        self::assertSame($peer->server['public'],$client->start($keys->noiseKeyPair(),$payload));self::assertSame($payload,$peer->clientPayload);self::assertTrue($client->isEstablished());
        $fields=W::decode($peer->clientPayload);self::assertSame([1],$fields[20]);self::assertSame([7],$fields[18]);self::assertSame(['SYNTHETIC-CAT'],$fields[21]);self::assertSame([32],W::decode($fields[5][0])[1]);
        self::assertSame('success',$client->receiveNode()->tag);$node=new BinaryNode('iq',['type'=>'get','id'=>'request-1'],[new BinaryNode('ping')]);$client->sendNode($node);self::assertEquals([$node],$peer->received);
        $peer->enqueue(new BinaryNode('iq',['type'=>'result','id'=>'request-1']));self::assertEquals(new BinaryNode('iq',['type'=>'result','id'=>'request-1']),$client->receiveNode());$client->close();self::assertTrue($peer->closed);
    }
    public function testExpiredFutureWrongStaticAndBrokenSignatureNeverFinishHandshake():void
    {
        foreach(['expired','future','static','signature']as$failure){$peer=new MessengerNoisePeer($failure);$client=new NoiseConnection($peer,testRootPublic:$peer->root['public']);
            try{$client->start(Crypto::x25519KeyPair(),'SYNTHETIC_PRIVATE_PAYLOAD');self::fail('Invalid certificate '.$failure);}catch(\UnexpectedValueException){self::assertTrue($peer->closed);self::assertFalse($client->isEstablished());self::assertSame('',$peer->clientPayload);}}
    }
    public function testCatAccountExpiryAndVersionCannotFallBackToReferenceConstants():void
    {
        $keys=DeviceKeys::generate('10001');
        foreach([new CryptoAuthToken('20002','123','SYNTHETIC',time()+600),new CryptoAuthToken('10001','123','SYNTHETIC',time()-1)]as$token){try{ClientPayload::login($keys,7,$token,[999,1,2],'Synthetic');self::fail('Unusable CAT');}catch(\MVO\Messenger\MessengerException){self::assertTrue(true);}}
        $this->expectException(\InvalidArgumentException::class);ClientPayload::login($keys,7,new CryptoAuthToken('10001','123','SYNTHETIC',time()+600),[],'Synthetic');
    }
    public function testNoCertificateCanAuthenticateAgainstAnUnrelatedTrustRoot():void
    {
        $peer=new MessengerNoisePeer();$client=new NoiseConnection($peer,testRootPublic:Crypto::x25519KeyPair()['public']);$this->expectException(\UnexpectedValueException::class);$client->start(Crypto::x25519KeyPair(),'synthetic');
    }
}
