<?php
declare(strict_types=1);

namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Transport\NoiseChannel;
use MVO\Transport\NoiseHandshake;

/** Local symmetric-state tests; these do not claim WhatsApp server interoperability. */
final class NoiseStateTest extends TestCase
{
    public function testMatchingTranscriptsSplitIntoOppositeChannelDirections(): void
    {
        $initiator = new NoiseHandshake();
        $responder = new NoiseHandshake();
        foreach (['ephemeral-material', 'static-material'] as $material) {
            $initiator->authenticate($material);
            $responder->authenticate($material);
            $initiator->mixKey($material);
            $responder->mixKey($material);
            self::assertSame('hello', $responder->decrypt($initiator->encrypt('hello')));
            self::assertSame('ack', $initiator->decrypt($responder->encrypt('ack')));
        }

        $outgoing = $initiator->finish();
        $incoming = $responder->finish(initiator: false);

        self::assertSame('one', $incoming->decrypt($outgoing->encrypt('one')));
        self::assertSame('two', $outgoing->decrypt($incoming->encrypt('two')));
        self::assertSame('three', $incoming->decrypt($outgoing->encrypt('three')));
    }

    public function testFinishedHandshakeCannotBeReused(): void
    {
        $handshake = new NoiseHandshake();
        $handshake->finish();

        $this->expectException(\LogicException::class);
        $handshake->finish();
    }

    public function testDivergentTranscriptInvalidatesHandshakePermanently(): void
    {
        $sender = new NoiseHandshake();
        $receiver = new NoiseHandshake();
        $receiver->authenticate('unexpected transcript data');

        try {
            $receiver->decrypt($sender->encrypt('payload'));
            self::fail('Divergent transcript should fail authentication.');
        } catch (\UnexpectedValueException $error) {
            self::assertSame('GCM authentication failed', $error->getMessage());
        }

        $this->expectException(\LogicException::class);
        $receiver->finish();
    }

    public function testReplayedChannelCiphertextInvalidatesBothReadAndWrite(): void
    {
        $key = str_repeat('k', 32);
        $sender = new NoiseChannel($key, $key);
        $receiver = new NoiseChannel($key, $key);
        $cipher = $sender->encrypt('payload');
        self::assertSame('payload', $receiver->decrypt($cipher));

        try {
            $receiver->decrypt($cipher);
            self::fail('Replayed ciphertext should fail authentication.');
        } catch (\UnexpectedValueException $error) {
            self::assertSame('GCM authentication failed', $error->getMessage());
        }
        try {
            $receiver->decrypt($sender->encrypt('next'));
            self::fail('Invalidated channel should reject reads.');
        } catch (\LogicException $error) {
            self::assertSame('Channel invalidated', $error->getMessage());
        }

        $this->expectException(\LogicException::class);
        $receiver->encrypt('response');
    }

    public function testDirectionKeysAndCountersRemainIndependent(): void
    {
        $forward = str_repeat('f', 32);
        $backward = str_repeat('b', 32);
        $sender = new NoiseChannel($forward, $backward);
        $receiver = new NoiseChannel($backward, $forward);
        $first = $sender->encrypt('same payload', 'context');
        $second = $sender->encrypt('same payload', 'context');

        self::assertNotSame($first, $second);
        self::assertSame('same payload', $receiver->decrypt($first, 'context'));
        self::assertSame('same payload', $receiver->decrypt($second, 'context'));
        self::assertSame('reply', $sender->decrypt($receiver->encrypt('reply')));
    }
}
