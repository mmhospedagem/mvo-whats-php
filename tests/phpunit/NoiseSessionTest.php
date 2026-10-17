<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Crypto\Crypto;
use MVO\Protocol\BinaryNode;
use MVO\Tests\Fixtures\NoisePeer;
use MVO\Transport\NoiseSession;

require_once __DIR__ . '/../fixtures/NoisePeer.php';

final class NoiseSessionTest extends TestCase
{
    public function testIkResumeAuthenticatesKnownStaticAndExchangesTraffic():void {
        $peer=new NoisePeer(resume:true);
        $client=Crypto::x25519KeyPair();
        $session=new NoiseSession($peer,rootPublicKey:$peer->root['public'],serverStaticKey:$peer->server['public'],routingInfo:"route\0blob");
        self::assertSame($peer->server['public'],$session->start($client['private'],$client['public'],'resume-payload'));
        self::assertSame('resume-payload',$peer->clientPayload);
        self::assertSame($client['public'],$peer->clientStatic);
        self::assertSame("route\0blob",$peer->routingInfo);
        self::assertSame('success',$session->receiveNode()->tag);
        $session->sendNode(new BinaryNode('iq',['id'=>'ik']));
        self::assertSame('ik',$peer->received[0]->attrs['id']);
    }
    public function testIkFallbackVerifiesNewCertificateAndCompletesXxFallback():void {
        $peer=new NoisePeer(resume:true,fallback:true);
        $client=Crypto::x25519KeyPair();
        $session=new NoiseSession($peer,rootPublicKey:$peer->root['public'],serverStaticKey:Crypto::x25519KeyPair()['public']);
        self::assertSame($peer->server['public'],$session->start($client['private'],$client['public'],'fallback-payload'));
        self::assertSame('fallback-payload',$peer->clientPayload);
        self::assertSame('success',$session->receiveNode()->tag);
        $session->sendNode(new BinaryNode('iq',['id'=>'fallback']));
        self::assertSame('fallback',$peer->received[0]->attrs['id']);
    }
    public function testIkFallbackCannotBypassCertificateVerification():void {
        $peer=new NoisePeer(corruptCertificate:true,resume:true,fallback:true);
        $client=Crypto::x25519KeyPair();
        $session=new NoiseSession($peer,rootPublicKey:$peer->root['public'],serverStaticKey:$peer->server['public']);
        try { $session->start($client['private'],$client['public'],'payload'); self::fail('Accepted invalid fallback certificate'); }
        catch (\UnexpectedValueException) { self::assertTrue($peer->closed); self::assertSame('',$peer->clientPayload); }
    }
    public function testAuthenticatedXxCompletesAndExchangesEncryptedStanzas(): void
    {
        $peer = new NoisePeer();
        $session = new NoiseSession($peer, rootPublicKey: $peer->root['public']);
        $client = Crypto::x25519KeyPair();
        self::assertSame($peer->server['public'], $session->start($client['private'], $client['public'], 'registration-protobuf'));
        self::assertSame($client['public'], $peer->clientStatic);
        self::assertSame('registration-protobuf', $peer->clientPayload);
        self::assertEquals(new BinaryNode('success', ['t' => '123']), $session->receiveNode());
        $request = new BinaryNode('iq', ['type' => 'get', 'id' => '42'], [new BinaryNode('ping')]);
        $session->sendNode($request);
        self::assertEquals([$request], $peer->received);
        $peer->enqueue(new BinaryNode('iq', ['type' => 'result', 'id' => '42']));
        self::assertEquals(new BinaryNode('iq', ['type' => 'result', 'id' => '42']), $session->receiveNode());
        self::assertNull($session->receiveNode(0));
        self::assertFalse($peer->closed);
        $session->close();
        self::assertTrue($peer->closed);
    }

    public function testInvalidCertificateCannotFinishHandshake(): void
    {
        $peer = new NoisePeer(true);
        $session = new NoiseSession($peer, rootPublicKey: $peer->root['public']);
        $client = Crypto::x25519KeyPair();
        try { $session->start($client['private'], $client['public'], 'payload'); self::fail('Invalid certificate accepted'); }
        catch (\UnexpectedValueException $error) { self::assertStringContainsString('signature is invalid', $error->getMessage()); }
        self::assertTrue($peer->closed);
        self::assertSame('', $peer->clientPayload);
    }

    public function testProductionTrustDoesNotAcceptAnUnrelatedRoot(): void
    {
        $peer = new NoisePeer();
        $session = new NoiseSession($peer);
        $client = Crypto::x25519KeyPair();
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('intermediate certificate signature is invalid');
        $session->start($client['private'], $client['public'], 'payload');
    }

    public function testTamperedEncryptedStanzaPermanentlyClosesSession(): void
    {
        $peer = new NoisePeer();
        $session = new NoiseSession($peer, rootPublicKey: $peer->root['public']);
        $client = Crypto::x25519KeyPair();
        $session->start($client['private'], $client['public'], 'payload');
        $peer->tamperNext();
        try { $session->receiveNode(); self::fail('Corrupt ciphertext accepted'); }
        catch (\UnexpectedValueException $error) { self::assertSame('GCM authentication failed', $error->getMessage()); }
        self::assertTrue($peer->closed);
        $this->expectException(\LogicException::class);
        $session->sendNode(new BinaryNode('iq'));
    }

    public function testMismatchedClientStaticPairIsRejected(): void
    {
        $peer = new NoisePeer();
        $session = new NoiseSession($peer, rootPublicKey: $peer->root['public']);
        $this->expectException(\InvalidArgumentException::class);
        $session->start(random_bytes(32), random_bytes(32), 'payload');
    }

    public function testSessionCannotRestartWithOldCounters(): void
    {
        $peer = new NoisePeer();
        $session = new NoiseSession($peer, rootPublicKey: $peer->root['public']);
        $client = Crypto::x25519KeyPair();
        $session->start($client['private'], $client['public'], 'payload');
        $this->expectException(\LogicException::class);
        $session->start($client['private'], $client['public'], 'payload');
    }
}
