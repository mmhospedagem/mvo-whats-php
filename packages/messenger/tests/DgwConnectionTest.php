<?php
declare(strict_types=1);

use MVO\Messenger\MessengerException;
use MVO\Messenger\Protocol\DgwCodec;
use MVO\Messenger\Transport\DgwConnection;
use MVO\Messenger\Transport\Socket;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/MessengerException.php';
require_once __DIR__ . '/../src/Protocol/DgwCodec.php';
require_once __DIR__ . '/../src/Transport/Socket.php';
require_once __DIR__ . '/../src/Transport/DgwConnection.php';

final class DgwConnectionTest extends TestCase
{
    public function testWaitsForEstablishmentAckAndCorrelatedResponseEvenOutOfOrder(): void
    {
        $socket = new DgwFixtureSocket([
            hex2bin('09'),
            self::data(0, '{"request_id":7,"payload":"{\\"step\\":[1]}"}', 5),
            hex2bin('0f00000300003230300c00000200000000'),
        ]);
        $connection = new DgwConnection($socket);
        $connection->connect();
        $response = $connection->request(['request_id' => 7, 'type' => 3, 'payload' => '{}']);
        self::assertSame(7, $response['request_id']);
        self::assertSame('{"step":[1]}', $response['payload']);
        self::assertContains("\x0a", $socket->sent);
        self::assertContains(hex2bin('0c00000200000500'), $socket->sent);
        self::assertSame(hex2bin('0e0000'), $socket->sent[array_key_last($socket->sent)]);
        $initial = DgwCodec::decode($socket->sent[0]);
        self::assertCount(2, $initial);
        self::assertSame(DgwCodec::ESTABLISH, $initial[0]['type']);
        self::assertSame(0, $initial[1]['stream_id']);
        self::assertTrue($initial[1]['requires_ack']);
    }

    public function testBinaryAckByItselfIsNotAnApplicationResponse(): void
    {
        $socket = new DgwFixtureSocket([self::establish(0), self::ack(0, 0)]);
        $connection = new DgwConnection($socket);
        $connection->connect();
        try {
            $connection->request(['request_id' => 9, 'type' => 3], 0.002);
            self::fail('ACK must not be reported as operation success.');
        } catch (MessengerException $error) {
            self::assertSame('dgw_timeout', $error->reason);
            self::assertFalse($error->retryable);
        }
        self::assertSame(hex2bin('0e0000'), $socket->sent[array_key_last($socket->sent)]);
    }

    public function testPersistentSyncDataSurvivesAnInterleavedOneOffRequest(): void
    {
        $socket = new DgwFixtureSocket([self::establish(0) . self::ack(0, 0)]);
        $connection = new DgwConnection($socket);
        $connection->connect();
        self::assertSame(0, $connection->subscribe(['request_id' => 1, 'type' => 1]));
        $socket->incoming[] = self::data(0, '{"request_id":1,"payload":"sync","cursor":"c1"}', 4)
            . self::establish(1) . self::ack(1, 0)
            . self::data(1, '{"request_id":2,"payload":"task"}', 0);
        self::assertSame('task', $connection->request(['request_id' => 2, 'type' => 3])['payload']);
        self::assertSame([
            'stream_id' => 0, 'payload' => ['request_id' => 1, 'payload' => 'sync', 'cursor' => 'c1'],
        ], $connection->receive(0));
        self::assertNull($connection->receive(0));
    }

    public function testDataReceivedBeforePersistentStreamAckIsQueued(): void
    {
        $socket = new DgwFixtureSocket([
            self::data(0, '{"request_id":1,"payload":"initial"}', 0),
            self::establish(0) . self::ack(0, 0),
        ]);
        $connection = new DgwConnection($socket);
        $connection->connect();
        $connection->subscribe(['request_id' => 1, 'type' => 1]);
        self::assertSame('initial', $connection->receive(0)['payload']['payload']);
    }

    public function testWrongRequestIdentifierCannotConfirmACommand(): void
    {
        $socket = new DgwFixtureSocket([self::establish(0) . self::ack(0, 0) . self::data(0, '{"request_id":99}', 0)]);
        $connection = new DgwConnection($socket);
        $connection->connect();
        try {
            $connection->request(['request_id' => 7]);
            self::fail('Mismatched response must fail.');
        } catch (MessengerException $error) {
            self::assertSame('dgw_request_mismatch', $error->reason);
        }
    }

    public function testResponseRetainsUnsigned64BitIdentifier(): void
    {
        $socket = new DgwFixtureSocket([self::establish(0) . self::ack(0, 0) . self::data(0, '{"request_id":18446744073709551615}', 0)]);
        $connection = new DgwConnection($socket);
        $connection->connect();
        self::assertSame('18446744073709551615', $connection->request(['request_id' => '18446744073709551615'])['request_id']);
    }

    public function testEstablishmentRejectionNeverSucceedsEvenWithData(): void
    {
        $socket = new DgwFixtureSocket([
            DgwCodec::encode(['type' => DgwCodec::ESTABLISH, 'stream_id' => 0, 'parameters' => ['code' => 401]]) . self::ack(0, 0),
        ]);
        $connection = new DgwConnection($socket);
        $connection->connect();
        try {
            $connection->subscribe(['request_id' => 1]);
            self::fail('Rejected establishment must fail.');
        } catch (MessengerException $error) {
            self::assertSame('dgw_stream_rejected', $error->reason);
        }
    }

    public function testDeauthenticationClosesTransport(): void
    {
        $socket = new DgwFixtureSocket(["\x04"]);
        $connection = new DgwConnection($socket);
        $connection->connect();
        try {
            $connection->receive(0);
            self::fail('Expected deauthentication.');
        } catch (MessengerException $error) {
            self::assertSame('dgw_unauthorized', $error->reason);
        }
        self::assertFalse($connection->isConnected());
        self::assertFalse($socket->connected);
    }

    public function testMalformedResponseClosesTransportWithoutLeakingPayload(): void
    {
        $socket = new DgwFixtureSocket([self::data(0, 'private-not-json', 0)]);
        $connection = new DgwConnection($socket);
        $connection->connect();
        try {
            $connection->request(['request_id' => 1]);
            self::fail('Expected malformed response.');
        } catch (MessengerException $error) {
            self::assertSame('dgw_invalid_response', $error->reason);
            self::assertStringNotContainsString('private', $error->getMessage());
        }
        self::assertFalse($connection->isConnected());
    }

    public function testPersistentStreamSubsequentWritesUseNewAckIds(): void
    {
        $socket = new DgwFixtureSocket([self::establish(0) . self::ack(0, 0)]);
        $connection = new DgwConnection($socket);
        $connection->connect();
        $stream = $connection->subscribe(['request_id' => 1]);
        $socket->incoming[] = self::ack(0, 0); // stale ACK is insufficient
        $socket->incoming[] = self::ack(0, 1);
        $connection->sendOnStream($stream, ['request_id' => 2]);
        self::assertSame(1, DgwCodec::decode($socket->sent[array_key_last($socket->sent)])[0]['ack_id']);
        $socket->incoming[] = self::ack(0, 2);
        $connection->sendOnStream($stream, ['request_id' => 3]);
        self::assertSame(2, DgwCodec::decode($socket->sent[array_key_last($socket->sent)])[0]['ack_id']);
    }

    public function testPersistentCloseIsReportedForCursorRecovery(): void
    {
        $socket = new DgwFixtureSocket([self::establish(0) . self::ack(0, 0)]);
        $connection = new DgwConnection($socket);
        $connection->connect();
        $connection->subscribe(['request_id' => 1]);
        $socket->incoming[] = hex2bin('10000001000008');
        self::assertSame(['stream_id' => 0, 'payload' => ['event' => 'stream_closed', 'reason' => 8]], $connection->receive(0));
        $this->expectException(MessengerException::class);
        $connection->sendOnStream(0, ['request_id' => 2]);
    }

    public function testQueueOverflowClosesWithoutDroppingMessagesSilently(): void
    {
        $socket = new DgwFixtureSocket([self::establish(0) . self::ack(0, 0)]);
        $connection = new DgwConnection($socket, maxQueuedEvents: 1);
        $connection->connect();
        $connection->subscribe(['request_id' => 1]);
        $socket->incoming[] = self::data(0, '{"request_id":1}', 1) . self::data(0, '{"request_id":2}', 2);
        try {
            $connection->receive(0);
            self::fail('Expected bounded-queue failure.');
        } catch (MessengerException $error) {
            self::assertSame('dgw_event_overflow', $error->reason);
        }
        self::assertFalse($connection->isConnected());
    }

    private static function establish(int $stream): string
    {
        return DgwCodec::encode(['type' => DgwCodec::ESTABLISH, 'stream_id' => $stream, 'parameters' => ['code' => 200]]);
    }

    private static function ack(int $stream, int $ack): string
    {
        return DgwCodec::encode(['type' => DgwCodec::ACK, 'stream_id' => $stream, 'ack_id' => $ack]);
    }

    private static function data(int $stream, string $payload, int $ack): string
    {
        return DgwCodec::encode(['type' => DgwCodec::DATA, 'stream_id' => $stream, 'payload' => $payload, 'ack_id' => $ack, 'requires_ack' => true]);
    }
}

/** Synthetic, deterministic binary WebSocket fixture; never reaches Facebook. */
final class DgwFixtureSocket implements Socket
{
    public bool $connected = false;
    /** @var list<string> */
    public array $sent = [];

    /** @param list<string> $incoming */
    public function __construct(public array $incoming) {}
    public function connect(): void { $this->connected = true; }
    public function send(string $payload): void { $this->sent[] = $payload; }
    public function receive(float $timeoutSeconds = 0.5): ?string { return array_shift($this->incoming); }
    public function close(): void { $this->connected = false; }
    public function isConnected(): bool { return $this->connected; }
}
