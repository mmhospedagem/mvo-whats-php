<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Transport\WebSocket;

final class WebSocketTest extends TestCase
{
    /** @var resource|null */
    private $process = null;
    /** @var array<int,resource> */
    private array $pipes = [];
    private ?WebSocket $socket = null;

    private function peer(string $mode): WebSocket
    {
        $command = [PHP_BINARY];
        if (php_ini_loaded_file() !== false) array_push($command, '-c', php_ini_loaded_file());
        array_push($command, __DIR__ . '/../fixtures/websocket-server.php', $mode);
        $this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        self::assertIsResource($this->process);
        stream_set_timeout($this->pipes[1], 6);
        $address = trim((string) fgets($this->pipes[1]));
        self::assertMatchesRegularExpression('/^127\.0\.0\.1:[0-9]+$/D', $address);
        if (str_starts_with($mode,'proxy-')) return $this->socket=new WebSocket('ws://peer.invalid/ws/chat',2.0,proxy:($mode==='proxy-http' ? 'http' : 'socks5h').'://test:pass@'.$address);
        return $this->socket = new WebSocket('ws://' . $address . '/ws/chat', 2.0);
    }

    protected function tearDown(): void
    {
        $this->socket?->close();
        foreach ($this->pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        if (is_resource($this->process)) { proc_terminate($this->process); proc_close($this->process); }
    }

    public function testUpgradeMaskingFragmentsAndPingPongAgainstNativePeer(): void
    {
        $socket = $this->peer('valid');
        $socket->connect();
        self::assertTrue($socket->isConnected());
        $socket->send('test payload');
        self::assertSame('hello', $socket->receive(2));
        $result = json_decode((string) fgets($this->pipes[1]), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(['opcode' => 2, 'masked' => true, 'payload' => 'test payload'], $result['message']);
        self::assertSame(['opcode' => 10, 'masked' => true, 'payload' => 'p'], $result['pong']);
    }
    /** @return iterable<string,array{string}> */
    public static function proxies():iterable { yield 'HTTP CONNECT'=>['proxy-http']; yield 'SOCKS domain/auth'=>['proxy-socks']; }
    #[DataProvider('proxies')]
    public function testAuthenticatedProxyTunnelBeforeWebSocketUpgrade(string $mode):void {
        $socket=$this->peer($mode); $socket->connect(); $socket->send('through-proxy');
        self::assertSame('hello',$socket->receive(2));
        $result=json_decode((string)fgets($this->pipes[1]),true,32,JSON_THROW_ON_ERROR);
        self::assertSame('through-proxy',$result['message']['payload']); self::assertTrue($result['message']['masked']);
    }

    public function testExtendedMessageLengthsInBothDirections(): void
    {
        $socket = $this->peer('large');
        $socket->connect();
        $socket->send(str_repeat('x', 70000));
        self::assertSame(str_repeat('r', 70000), $socket->receive(2));
        $result = json_decode((string) fgets($this->pipes[1]), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(['length' => 70000, 'masked' => true], $result);
    }

    public function testIdleTimeoutKeepsConnectionOpen(): void
    {
        $socket = $this->peer('idle');
        $socket->connect();
        self::assertNull($socket->receive(0.01));
        self::assertTrue($socket->isConnected());
    }

    /** @return iterable<string,array{string}> */
    public static function invalidUpgrade(): iterable
    {
        yield 'invalid accept' => ['bad-accept'];
        yield 'unnegotiated extension' => ['bad-extension'];
    }

    #[DataProvider('invalidUpgrade')]
    public function testRejectsInvalidUpgrade(string $mode): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->peer($mode)->connect();
    }

    /** @return iterable<string,array{string,class-string<\Throwable>}> */
    public static function invalidFrame(): iterable
    {
        yield 'masked server frame' => ['masked-frame', \UnexpectedValueException::class];
        yield 'reserved opcode' => ['bad-opcode', \UnexpectedValueException::class];
        yield 'fragmented control' => ['bad-control', \UnexpectedValueException::class];
        yield 'noncanonical length' => ['noncanonical', \UnexpectedValueException::class];
        yield 'oversized length' => ['oversized', \LengthException::class];
        yield 'server closes' => ['close', \RuntimeException::class];
    }

    #[DataProvider('invalidFrame')]
    public function testProtocolFailuresCloseConnection(string $mode, string $exception): void
    {
        $socket = $this->peer($mode);
        $socket->connect();
        try { $socket->receive(2); self::fail('Expected protocol failure'); }
        catch (\Throwable $error) { self::assertInstanceOf($exception, $error); }
        self::assertFalse($socket->isConnected());
    }

    public function testRejectsCredentialsInUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new WebSocket('wss://username@web.whatsapp.com/ws/chat'))->connect();
    }
}
