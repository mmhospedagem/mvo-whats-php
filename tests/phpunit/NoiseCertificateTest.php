<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Tests\Fixtures\NoisePeer;
use MVO\Transport\NoiseCertificate;

require_once __DIR__ . '/../fixtures/NoisePeer.php';

final class NoiseCertificateTest extends TestCase
{
    public function testCorrectSignaturesAndBindingPassVerification(): void
    {
        $peer = new NoisePeer();
        NoiseCertificate::verify($peer->chain, $peer->server['public'], $peer->root['public']);
        $this->addToAssertionCount(1);
    }

    public function testValidChainCannotAuthenticateAnotherStaticKey(): void
    {
        $peer = new NoisePeer();
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('key differs from server static key');
        NoiseCertificate::verify($peer->chain, random_bytes(32), $peer->root['public']);
    }

    public function testIssuerSerialMustMatchPinnedRoot(): void
    {
        $peer = new NoisePeer();
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('issuer mismatch');
        NoiseCertificate::verify($peer->chain, $peer->server['public'], $peer->root['public'], 1);
    }

    public function testCorrectlySignedLeafWithWrongIssuerIsRejected(): void
    {
        $peer = new NoisePeer();
        $parts = NoisePeer::fields($peer->chain);
        $chain = NoisePeer::message([1 => NoisePeer::certificate(11, 8, $peer->server['public'], $peer->intermediate['private']), 2 => $parts[2]]);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('leaf certificate issuer mismatch');
        NoiseCertificate::verify($chain, $peer->server['public'], $peer->root['public']);
    }

    public function testDuplicateLeafCannotBeSilentlyOverwritten(): void
    {
        $peer = new NoisePeer();
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Duplicate Noise certificate field');
        NoiseCertificate::verify($peer->chain . NoisePeer::message([1 => 'duplicate']), $peer->server['public'], $peer->root['public']);
    }

    public function testMissingCertificateIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        NoiseCertificate::verify('', random_bytes(32));
    }
}
