<?php
declare(strict_types=1);

namespace MVO\Channels\WhatsApp\Tests;

use MVO\Auth\CredentialStore;
use MVO\Channels\PersistentClient;
use MVO\Channels\WhatsApp\WhatsAppClient;
use MVO\Protocol\BinaryNode;
use MVO\Transport\NodeSession;
use MVO\WaClient;
use PHPUnit\Framework\TestCase;

final class WhatsAppClientTest extends TestCase
{
    private string $directory;
    /** @var list<WaClient> */
    private array $clients = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/mvo-whatsapp-adapter-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->stop();
        }
        $this->clients = [];
        gc_collect_cycles();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->directory);
    }

    private function native(string $id, FixtureNodeSession $session): WaClient
    {
        $client = new WaClient(
            sessionId: $id,
            authDirectory: $this->directory,
            sessionFactory: static fn (): NodeSession => $session,
            automaticMaintenance: false,
        );
        $this->clients[] = $client;
        return $client;
    }

    public function testWrappingDoesNotStartAnotherSessionAndNativeCallbacksPreserveExactPayloads(): void
    {
        $session = new FixtureNodeSession();
        $native = $this->native('one', $session);
        $adapter = new WhatsAppClient($native);
        self::assertInstanceOf(PersistentClient::class, $adapter);
        self::assertSame($native, $adapter->nativeClient());
        self::assertFalse($session->started);
        self::assertSame('whatsapp', $adapter->channel());
        self::assertSame('mvo_protocol', $adapter->provider());
        self::assertTrue($adapter->capabilities()->supports('message.text'));
        self::assertFalse($adapter->capabilities()->supports('instagram.comments'));
        $received = [];
        self::assertSame($adapter, $adapter->on('message', static function (array $event) use (&$received): void {
            $received[] = $event;
        }));
        $node = new BinaryNode('fixture');
        $payload = [
            'id' => '000123', 'from' => '800@lid', 'chat' => '120363000000000001@g.us',
            'key' => ['remoteJid' => '800@lid', 'id' => '000123', 'fromMe' => false],
            'node' => $node, 'bytes' => "\x00\xff",
        ];
        $native->emitEvent('message', $payload);
        self::assertSame([$payload], $received);
        self::assertSame($node, $received[0]['node']);
    }

    public function testSeparateWrappedClientsDoNotShareEventsOrInventForeignIdentities(): void
    {
        $a = new WhatsAppClient($this->native('one', new FixtureNodeSession()));
        $b = new WhatsAppClient($this->native('two', new FixtureNodeSession()));
        $first = [];
        $second = [];
        $a->on('message', static function (array $event) use (&$first): void { $first[] = $event; });
        $b->on('message', static function (array $event) use (&$second): void { $second[] = $event; });
        $a->nativeClient()->emitEvent('message', ['from' => '800@lid', 'id' => '000123']);
        self::assertSame([['from' => '800@lid', 'id' => '000123']], $first);
        self::assertSame([], $second);
        self::assertSame($a->nativeClient()->accountInfo(), $a->accountInfo());
        self::assertNull($a->accountInfo()['phoneNumber']);
        self::assertNull($a->accountInfo()['lid']);
    }

    public function testConnectIsNotConnectedAndPollAndStopPreserveNativeLifecycle(): void
    {
        $session = new FixtureNodeSession();
        $native = $this->native('pairing', $session);
        $adapter = new WhatsAppClient($native);
        $states = [];
        $adapter->on('connection', static function (array $event) use (&$states): void { $states[] = $event; });
        $adapter->connect();
        self::assertTrue($session->started);
        self::assertFalse($adapter->isConnected());
        self::assertSame(['connecting', 'pairing'], array_column($states, 'state'));
        $adapter->poll(0.0);
        self::assertContains(0.0, $session->timeouts);
        $adapter->stop();
        self::assertTrue($session->closed);
        self::assertFalse($adapter->isConnected());
        self::assertSame('stopped', $native->getState());
        self::assertSame('stopped', $states[array_key_last($states)]['state']);
    }

    public function testPairedAccountSnapshotPreservesDistinctPnLidAndLocalStopKeepsRegistration(): void
    {
        $store = new CredentialStore($this->directory, 'paired');
        $credentials = CredentialStore::fresh();
        $credentials['registered'] = true;
        $credentials['meJid'] = '5511000000000:5@s.whatsapp.net';
        $credentials['meLid'] = '900:5@lid';
        $credentials['serverHasPreKeys'] = true;
        $store->save($credentials);
        $store->close();
        $session = new FixtureNodeSession();
        $native = $this->native('paired', $session);
        $adapter = new WhatsAppClient($native);
        $adapter->connect();
        $session->incoming[] = new BinaryNode('success', ['lid' => '900:5@lid']);
        $adapter->poll(0.0);
        self::assertTrue($adapter->isConnected());
        $info = $adapter->accountInfo();
        self::assertSame('5511000000000@s.whatsapp.net', $info['jid']);
        self::assertSame('900@lid', $info['lid']);
        self::assertSame('5511000000000', $info['phoneNumber']);
        self::assertSame($native->accountInfo(), $info);
        self::assertArrayNotHasKey('noiseKeyPair', $info);
        self::assertArrayNotHasKey('identityKeyPair', $info);
        $adapter->stop();
        self::assertTrue($native->isRegistered());
        self::assertFalse($adapter->isConnected());
        self::assertNotSame('logged_out', $native->getState());
    }

    public function testSendPreservesNativeDisconnectedFailureInsteadOfReportingFalseSuccess(): void
    {
        $adapter = new WhatsAppClient($this->native('one', new FixtureNodeSession()));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('WhatsApp is not connected');
        $adapter->sendText('800@lid', 'Test');
    }
}

/** Node-level fixture only; no DNS, network or account access. */
final class FixtureNodeSession implements NodeSession
{
    public bool $started = false;
    public bool $closed = false;
    /** @var list<BinaryNode> */
    public array $incoming = [];
    /** @var list<float> */
    public array $timeouts = [];

    public function start(string $staticPrivate, string $staticPublic, string $clientPayload): string
    {
        $this->started = true;
        return str_repeat('f', 32);
    }

    public function sendNode(BinaryNode $node): void
    {
        if ($node->tag === 'iq' && in_array($node->attrs['type'] ?? '', ['get', 'set'], true)) {
            $this->incoming[] = new BinaryNode('iq', ['id' => $node->attrs['id'], 'type' => 'result']);
        }
    }

    public function receiveNode(float $timeoutSeconds = 1.0): ?BinaryNode
    {
        $this->timeouts[] = $timeoutSeconds;
        return array_shift($this->incoming);
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
