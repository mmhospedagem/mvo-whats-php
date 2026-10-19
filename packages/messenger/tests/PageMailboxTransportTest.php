<?php
declare(strict_types=1);
namespace MVO\Messenger\Tests;

use MVO\Messenger\Auth\{CookieJar,SessionStore};
use MVO\Messenger\MessengerException;
use MVO\Messenger\Protocol\{DgwCodec,PageMailboxConfig,PageTaskBuilder};
use MVO\Messenger\Transport\{PageMailboxGateway,Socket};
use PHPUnit\Framework\TestCase;

final class PageMailboxTransportTest extends TestCase
{
    public function testGatewayUsesPersonalAuthenticationWithExplicitPageAssetBinding(): void
    {
        $config = self::config(); $url = parse_url($config->gatewayUrl()); parse_str($url['query'], $query);
        self::assertSame('gateway.facebook.com', $url['host']); self::assertSame('/ws/lightspeed', $url['path']);
        self::assertSame('10001', $query['x-dgw-uuid']); self::assertSame('123', $query['x-dgw-appid']);
        self::assertSame(['PAGE' => '30003'], json_decode($query['x-dgw-app-asset_ids'], true, 16, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('sender_fbid', $query); self::assertSame('biz-device', $query['x-dgw-deviceid']);
    }

    public function testPageTextTaskUsesEstablishedBusinessSourceAndDatabase205(): void
    {
        $task = PageTaskBuilder::text('40004', 'Teste MVO', '7130316800000000001');
        self::assertSame('46', $task['label']); self::assertSame('40004', $task['queue_name']);
        self::assertSame(['thread_id' => 40004, 'otid' => '7130316800000000001', 'source' => 4456448, 'send_type' => 1,
            'sync_group' => 205, 'mark_thread_read' => true, 'text' => 'Teste MVO'], json_decode($task['payload'], true, 16, JSON_THROW_ON_ERROR));
    }

    public function testNativeGatewaySendsCorrelatedDgwFramesUnderTheExistingOwner(): void
    {
        $root = sys_get_temp_dir() . '/mvo-page-gateway-' . bin2hex(random_bytes(8)); $owner = new SessionStore($root, 'test');
        $owner->acquire(); $owner->saveCookies(new CookieJar([['name' => 'c_user', 'value' => '10001'], ['name' => 'xs', 'value' => 'synthetic-session']]));
        $socket = new PageMailboxSocket(); $opened = [];
        $gateway = new PageMailboxGateway($owner, self::config(), static function ($url, $origin, $headers) use ($socket, &$opened): Socket { $opened = [$url, $origin, $headers]; return $socket; });
        try {
            $response = $gateway->request(['app_id' => '456', 'request_id' => 1, 'type' => 3, 'payload' => '{}']);
            self::assertSame(1, $response['request_id']); self::assertSame('https://business.facebook.com', $opened[1]);
            self::assertStringContainsString('c_user=10001', $opened[2]['Cookie']); self::assertStringContainsString('x-dgw-app-asset_ids=', $opened[0]);
            $frames = DgwCodec::decode($socket->sent[0]); self::assertSame(DgwCodec::ESTABLISH, $frames[0]['type']);
            self::assertSame('456', json_decode($frames[1]['payload'], true, 16, JSON_THROW_ON_ERROR)['app_id']);
            self::assertTrue($owner->ownsLock()); $gateway->close(); self::assertTrue($owner->ownsLock()); self::assertFalse($socket->connected);
        } finally {
            $gateway->close(); unset($gateway); $owner->release(); unset($owner); gc_collect_cycles();
            foreach (glob($root . '/messenger/test/*') ?: [] as $file) unlink($file);
            rmdir($root . '/messenger/test'); rmdir($root . '/messenger'); rmdir($root);
        }
    }

    public function testGatewayRejectsDifferentApplicationOrReleasedOwnerBeforeOpeningSocket(): void
    {
        $root = sys_get_temp_dir() . '/mvo-page-gateway-' . bin2hex(random_bytes(8)); $owner = new SessionStore($root, 'test');
        $owner->acquire(); $owner->saveCookies(new CookieJar([['name' => 'c_user', 'value' => '10001'], ['name' => 'xs', 'value' => 'synthetic-session']]));
        $opened = 0; $gateway = new PageMailboxGateway($owner, self::config(), static function () use (&$opened): Socket { ++$opened; return new PageMailboxSocket(); });
        try {
            try { $gateway->request(['app_id' => '999', 'type' => 3]); self::fail('Wrong app accepted'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
            $owner->release();
            try { $gateway->request(['app_id' => '456', 'type' => 3]); self::fail('Released owner accepted'); } catch (MessengerException $error) { self::assertSame('page_mailbox_owner_required', $error->reason); }
            self::assertSame(0, $opened);
        } finally {
            unset($gateway); $owner->release(); unset($owner); gc_collect_cycles();
            foreach (glob($root . '/messenger/test/*') ?: [] as $file) unlink($file);
            rmdir($root . '/messenger/test'); rmdir($root . '/messenger'); rmdir($root);
        }
    }

    private static function config(): PageMailboxConfig { return new PageMailboxConfig('10001', '30003', '123', '1', '456', '28761380846847381', 'biz-device'); }
}

final class PageMailboxSocket implements Socket
{
    public bool $connected = false;
    /** @var list<string> */ public array $sent = [];
    /** @var list<string> */ private array $incoming = [];
    public function connect(): void { $this->connected = true; }
    public function send(string $payload): void
    {
        $this->sent[] = $payload;
        foreach (DgwCodec::decode($payload) as $frame) {
            if ($frame['type'] !== DgwCodec::DATA) continue;
            $request = json_decode($frame['payload'], true, 16, JSON_THROW_ON_ERROR); $stream = $frame['stream_id'];
            $this->incoming[] = DgwCodec::encode(['type' => DgwCodec::ESTABLISH, 'stream_id' => $stream, 'parameters' => ['code' => 200]])
                . DgwCodec::encode(['type' => DgwCodec::ACK, 'stream_id' => $stream, 'ack_id' => $frame['ack_id']])
                . DgwCodec::encode(['type' => DgwCodec::DATA, 'stream_id' => $stream, 'ack_id' => 0, 'requires_ack' => true,
                    'payload' => json_encode(['request_id' => $request['request_id'], 'payload' => '{"step":[1]}', 'sp' => []], JSON_THROW_ON_ERROR)]);
        }
    }
    public function receive(float $timeoutSeconds = 0.5): ?string { return array_shift($this->incoming); }
    public function close(): void { $this->connected = false; }
    public function isConnected(): bool { return $this->connected; }
}
