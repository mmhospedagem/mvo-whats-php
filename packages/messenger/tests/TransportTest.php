<?php
declare(strict_types=1);

use MVO\Messenger\Auth\CookieJar;
use MVO\Messenger\Auth\SessionStore;
use MVO\Messenger\MessengerException;
use MVO\Messenger\Transport\CurlHttpClient;
use MVO\Messenger\Transport\CurlTransportCapture;
use MVO\Messenger\Transport\WebSocket;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/MessengerException.php';
require_once __DIR__ . '/../src/Auth/CookieJar.php';
require_once __DIR__ . '/../src/Auth/SessionStore.php';
require_once __DIR__ . '/../src/Transport/Socket.php';
require_once __DIR__ . '/../src/Transport/HttpClient.php';
require_once __DIR__ . '/../src/Transport/HttpResponse.php';
require_once __DIR__ . '/../src/Transport/CurlHttpClient.php';
require_once __DIR__ . '/../src/Transport/WebSocket.php';
require_once __DIR__ . '/fixtures/curl-capture.php';

final class TransportTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        CurlTransportCapture::$active = false;
        CurlTransportCapture::$options = [];
        CurlTransportCapture::$headers = [];
        CurlTransportCapture::$body = '';
        gc_collect_cycles();
        foreach ($this->roots as $root) self::removeTemporary($root);
    }

    public function testCookieSelectionHonoursDomainHostOnlyPathExpiryAndWss(): void
    {
        $cookies = new CookieJar([
            ...self::cookieRows(),
            ['name' => 'path_token', 'value' => 'scoped', 'domain' => '.facebook.com', 'path' => '/messages'],
            ['name' => 'host_token', 'value' => 'onehost', 'domain' => 'www.facebook.com', 'hostOnly' => true],
            ['name' => 'expired', 'value' => 'old', 'domain' => '.facebook.com', 'expires' => time() - 1],
            ['name' => 'msgr', 'value' => 'separate', 'domain' => '.messenger.com'],
        ]);
        $header = $cookies->headerFor('https://www.facebook.com/messages/t/123');
        self::assertStringStartsWith('path_token=scoped;', $header);
        self::assertStringContainsString('host_token=onehost', $header);
        self::assertStringNotContainsString('expired=', $header);
        self::assertStringNotContainsString('msgr=', $header);
        self::assertStringNotContainsString('path_token=', $cookies->headerFor('https://www.facebook.com/messages-other'));
        $gateway = $cookies->headerFor('wss://gateway.facebook.com/ws/lightspeed');
        self::assertStringContainsString('xs=fake-session-token', $gateway);
        self::assertStringNotContainsString('host_token=', $gateway);
        self::assertSame('msgr=separate', $cookies->headerFor('https://www.messenger.com/'));
    }

    #[DataProvider('invalidCookieValues')]
    public function testCookiesRejectInjectionAndInvalidDomains(string $field, mixed $value): void
    {
        $rows = self::cookieRows();
        $rows[1][$field] = $value;
        $this->expectException(InvalidArgumentException::class);
        new CookieJar($rows);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidCookieValues(): iterable
    {
        yield 'CRLF' => ['value', "a\r\nInjected: yes"];
        yield 'semicolon' => ['value', 'a;other=evil'];
        yield 'quote' => ['value', '"quoted"'];
        yield 'backslash' => ['value', 'a\\b'];
        yield 'unicode cookie octet' => ['value', 'ação'];
        yield 'domain suffix trick' => ['domain', 'facebook.com.example.test'];
        yield 'domain embedded space' => ['domain', 'evil host.facebook.com'];
        yield 'domain invalid label' => ['domain', '-bad.facebook.com'];
        yield 'domain array' => ['domain', ['facebook.com']];
        yield 'duplicate leading dots' => ['domain', '..facebook.com'];
        yield 'bad path' => ['path', "/messages\nHeader"];
    }

    #[DataProvider('unsafeUrls')]
    public function testCookieJarNeverForwardsSessionToUnapprovedUrls(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CookieJar(self::cookieRows()))->headerFor($url);
    }

    /** @return iterable<array{string}> */
    public static function unsafeUrls(): iterable
    {
        yield ['https://facebook.com.example.test/'];
        yield ['https://notfacebook.com/'];
        yield ['http://www.facebook.com/'];
        yield ['ws://gateway.facebook.com/'];
        yield ['https://user:pass@www.facebook.com/'];
        yield ['https://www.facebook.com@evil.test/'];
    }

    public function testCookiesRequireUnambiguousAccountAndDebugDoesNotContainSecrets(): void
    {
        $jar = CookieJar::fromJson('{"c_user":"100000000001","xs":"fake-session-token"}');
        self::assertSame('100000000001', $jar->accountId());
        ob_start(); var_dump($jar); $debug = ob_get_clean();
        self::assertStringNotContainsString('fake-session-token', $debug);
        self::assertStringContainsString('[REDACTED]', $debug);
        $this->expectException(InvalidArgumentException::class);
        new CookieJar([...self::cookieRows(), ['name' => 'c_user', 'value' => '100000000002', 'domain' => '.messenger.com']]);
    }

    public function testAccountIdentityRemainsStableWhenCookiesExpireAndAreNoLongerSelected(): void
    {
        $rows = self::cookieRows();
        foreach ($rows as &$row) $row['expires'] = time() + 5;
        unset($row);
        $jar = new CookieJar($rows);
        self::assertSame('', $jar->headerFor('https://www.facebook.com/', time() + 10));
        self::assertSame('100000000001', $jar->accountId());
    }

    public function testExpiredCookieReloadDoesNotPreventRenewalOfSameStoredAccount(): void
    {
        $root = $this->root();
        $store = new SessionStore($root, 'renew');
        $store->acquire();
        $store->saveCookies(new CookieJar(self::cookieRows()));
        $expired = self::cookieRows();
        foreach ($expired as &$row) $row['expires'] = time() - 1;
        unset($row);
        $store->set('cookies', json_encode($expired, JSON_THROW_ON_ERROR));
        $store->saveCookies(new CookieJar(self::cookieRows()));
        self::assertSame('100000000001', $store->loadCookies()->accountId());
        $different = self::cookieRows(); $different[0]['value'] = '100000000002';
        try {
            $store->saveCookies(new CookieJar($different));
            self::fail('A session must not change Facebook identity.');
        } catch (InvalidArgumentException) {
            self::assertSame('100000000001', $store->get('account_id'));
        }
        $store->release();
        unset($store);
    }

    public function testSessionQueriesNeedNoOwnerAndMutationsRequireExclusiveLock(): void
    {
        $root = $this->root();
        $owner = new SessionStore($root, 'owner');
        $owner->acquire();
        $owner->saveCookies(new CookieJar(self::cookieRows()));
        $owner->putThread('12345678901234567890', ['id' => '12345678901234567890', 'name' => 'Synthetic']);
        self::assertTrue($owner->event('message', ['id' => 'm1'], 'message:m1'));
        self::assertFalse($owner->event('message', ['id' => 'm1'], 'message:m1'));
        $owner->enqueue('local-1', '12345678901234567890', 'PRIVATE-TEXT', 1);
        $owner->markSent('local-1', 'uncertain');
        $reader = new SessionStore($root, 'owner');
        self::assertSame('100000000001', $reader->loadCookies()->accountId());
        self::assertCount(1, $reader->events());
        self::assertCount(1, $reader->threads());
        self::assertSame('12345678901234567890', $reader->thread('12345678901234567890')['id']);
        self::assertSame('uncertain', $reader->outgoing('local-1')['state']);
        self::assertArrayNotHasKey('text', $reader->outgoing('local-1'));
        try { $reader->acquire(); self::fail('Second owner must be rejected.'); }
        catch (RuntimeException) { self::assertFalse($reader->ownsLock()); }
        foreach ([
            fn () => $reader->set('a', 'b'), fn () => $reader->saveCookies(new CookieJar(self::cookieRows())),
            fn () => $reader->event('x', []), fn () => $reader->putThread('1', []),
            fn () => $reader->enqueue('x', '1', 'text', 1), fn () => $reader->markSent('local-1', 'submitted'),
        ] as $write) {
            try { $write(); self::fail('Write without session ownership must fail.'); }
            catch (LogicException) { self::assertFalse($reader->ownsLock()); }
        }
        $owner->release();
        $reader->acquire();
        self::assertTrue($reader->ownsLock());
        $reader->release();
        unset($owner, $reader);
    }

    public function testBatchProjectionAndCursorCommitOrRollbackTogetherAndDeduplicateAfterRestart(): void
    {
        $root = $this->root();
        $store = new SessionStore($root, 'atomic');
        $store->acquire();
        $store->set('cursor.1', 'before');
        $calls = [['name' => 'unknownPrivateProcedure', 'args' => ['PRIVATE-RAW-ROW']]];
        $digest = hash('sha256', json_encode($calls, JSON_THROW_ON_ERROR));
        try {
            $store->transaction(function () use ($store, $calls, $digest): void {
                self::assertTrue($store->saveBatch($digest, $calls));
                $store->event('message', ['id' => 'rollback'], 'rollback');
                $store->putThread('1', ['id' => '1']);
                $store->set('cursor.1', 'must-roll-back');
                throw new DomainException('Synthetic projection failure');
            });
            self::fail('Expected synthetic rollback.');
        } catch (DomainException) {
            self::assertSame('before', $store->get('cursor.1'));
            self::assertSame([], $store->events());
            self::assertNull($store->thread('1'));
        }
        self::assertSame('committed', $store->transaction(function () use ($store, $calls, $digest): string {
            self::assertTrue($store->saveBatch($digest, $calls));
            $store->event('message', ['id' => 'committed'], 'committed');
            $store->set('cursor.1', 'after');
            return 'committed';
        }));
        $store->release(); unset($store);
        $restored = new SessionStore($root, 'atomic'); $restored->acquire();
        self::assertFalse($restored->transaction(fn (): bool => $restored->saveBatch($digest, $calls)));
        self::assertSame('after', $restored->get('cursor.1'));
        self::assertCount(1, $restored->events());
        self::assertStringNotContainsString('PRIVATE-RAW-ROW', json_encode($restored->events(), JSON_THROW_ON_ERROR));
        $restored->release(); unset($restored);
    }

    public function testCurlRedirectPolicyKeepsCookiesOnInitialRequestAndVerifiesTls(): void
    {
        CurlTransportCapture::$active = true;
        CurlTransportCapture::$status = 302;
        CurlTransportCapture::$headers = ["HTTP/1.1 302 Found\r\n", "Location: https://evil.example/steal\r\n", "\r\n"];
        $response = (new CurlHttpClient())->request('GET', 'https://www.facebook.com/messages/', new CookieJar(self::cookieRows()));
        self::assertSame(302, $response->status);
        self::assertSame('https://evil.example/steal', $response->header('Location'));
        self::assertFalse(CurlTransportCapture::$options[CURLOPT_FOLLOWLOCATION]);
        self::assertTrue(CurlTransportCapture::$options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, CurlTransportCapture::$options[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(CURLPROTO_HTTPS, CurlTransportCapture::$options[CURLOPT_PROTOCOLS]);
        self::assertContains('Cookie: c_user=100000000001; xs=fake-session-token', CurlTransportCapture::$options[CURLOPT_HTTPHEADER]);
        $headers=CurlTransportCapture::$options[CURLOPT_HTTPHEADER];
        self::assertContains('Sec-Fetch-Dest: document',$headers);self::assertContains('Sec-Fetch-Mode: navigate',$headers);
        self::assertContains('Upgrade-Insecure-Requests: 1',$headers);
        self::assertContains('Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',$headers);
        self::assertSame([],array_values(array_filter($headers,static fn(string $header):bool=>str_starts_with($header,'Origin:'))));
    }

    public function testCurlBoundsDecompressedBodyAndHeadersWithoutLeakingTheirContent(): void
    {
        CurlTransportCapture::$active = true;
        CurlTransportCapture::$headers = ["HTTP/1.1 200 OK\r\n"];
        CurlTransportCapture::$body = 'PRIVATE-BODY-IS-TOO-LARGE';
        try {
            (new CurlHttpClient(maxBytes: 8))->request('GET', 'https://www.facebook.com/', new CookieJar(self::cookieRows()));
            self::fail('Expected response limit.');
        } catch (MessengerException $error) {
            self::assertSame('response_too_large', $error->reason);
            self::assertStringNotContainsString('PRIVATE', $error->getMessage());
        }
        CurlTransportCapture::$body = '';
        CurlTransportCapture::$headers = ["HTTP/1.1 200 OK\r\n", 'X-Padding: ' . str_repeat('x', 65536) . "\r\n"];
        $this->expectException(MessengerException::class);
        (new CurlHttpClient())->request('GET', 'https://www.facebook.com/', new CookieJar(self::cookieRows()));
    }

    public function testSecretBearingParametersAreRedactedEvenWhenExceptionArgsAreEnabled(): void
    {
        foreach ([
            [CookieJar::class, '__construct', 'cookies'], [CookieJar::class, 'fromJson', 'json'],
            [WebSocket::class, '__construct', 'headers'], [WebSocket::class, '__construct', 'proxy'],
            [WebSocket::class, 'writeAll', 'bytes'], [WebSocket::class, 'send', 'payload'],
            [CurlHttpClient::class, 'request', 'cookies'], [CurlHttpClient::class, 'request', 'form'],
        ] as [$class, $method, $name]) {
            $parameters = (new ReflectionMethod($class, $method))->getParameters();
            $parameter = array_values(array_filter($parameters, static fn (ReflectionParameter $p): bool => $p->getName() === $name))[0];
            self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class), "$class::$method($name)");
        }
        $socket = new WebSocket('wss://gateway.facebook.com/ws/lightspeed', 'https://www.facebook.com', ['Cookie' => 'PRIVATE-COOKIE'], proxy: 'http://user:PRIVATE-PASS@127.0.0.1:8080');
        ob_start(); var_dump($socket); $debug = ob_get_clean();
        self::assertStringNotContainsString('PRIVATE', $debug);
    }

    public function testNativeLoopbackUpgradePreservesOriginAndCookiesAndReassemblesBinaryFragments(): void
    {
        [$process, $pipes, $port] = $this->peer('roundtrip');
        $socket = new WebSocket("ws://127.0.0.1:$port/ws/lightspeed?x-dgw-version=5", 'https://www.facebook.com', ['Cookie' => 'c_user=100000000001; xs=SYNTHETIC', 'User-Agent' => 'MVO synthetic peer'], timeoutSeconds: 2);
        try {
            $socket->connect();
            self::assertTrue($socket->isConnected());
            self::assertSame("\x00hello\xff", $socket->receive(2));
            $socket->send("\x01payload\x80");
            $socket->close();
            $result = $this->finishPeer($process, $pipes);
            self::assertStringContainsString("Origin: https://www.facebook.com\r\n", $result['request']);
            self::assertStringContainsString("Cookie: c_user=100000000001; xs=SYNTHETIC\r\n", $result['request']);
            self::assertStringStartsWith('GET /ws/lightspeed?x-dgw-version=5 HTTP/1.1', $result['request']);
            self::assertSame([
                ['opcode' => 10, 'masked' => true, 'hex' => '70'],
                ['opcode' => 2, 'masked' => true, 'hex' => '017061796c6f616480'],
                ['opcode' => 8, 'masked' => true, 'hex' => '03e8'],
            ], $result['frames']);
        } finally { $socket->close(); $this->stopPeer($process, $pipes); }
    }

    #[DataProvider('invalidPeerModes')]
    public function testNativePeerProtocolFailuresCloseTheSocket(string $mode): void
    {
        [$process, $pipes, $port] = $this->peer($mode);
        $socket = new WebSocket("ws://127.0.0.1:$port/", 'https://www.facebook.com', timeoutSeconds: 2, maxMessageBytes: $mode === 'fragment-overflow' ? 6 : 1024);
        try {
            try { $socket->connect(); $socket->receive(2); self::fail('Expected invalid peer rejection: ' . $mode); }
            catch (RuntimeException|LengthException $error) {
                self::assertFalse($socket->isConnected());
                self::assertStringNotContainsString('PRIVATE-REFLECTED', $error->getMessage());
            }
        } finally { $socket->close(); $this->stopPeer($process, $pipes); }
    }

    /** @return iterable<array{string}> */
    public static function invalidPeerModes(): iterable
    {
        foreach (['bad-accept', 'large-headers', 'refusal', 'masked-server', 'text-server', 'bad-close-utf8', 'noncanonical', 'oversize', 'fragment-overflow'] as $mode) yield [$mode];
    }

    public function testWebSocketRejectsHeaderAndUrlInjectionBeforeNetwork(): void
    {
        try { new WebSocket('ws://127.0.0.1/', 'https://www.facebook.com', ['Cookie' => "a\r\nInjected: x"]); self::fail('Expected header rejection.'); }
        catch (InvalidArgumentException) { self::assertTrue(true); }
        $this->expectException(InvalidArgumentException::class);
        (new WebSocket('ws://127.0.0.1/bad path', 'https://www.facebook.com'))->connect();
    }

    /** @return list<array<string,mixed>> */
    private static function cookieRows(): array
    {
        return [
            ['name' => 'c_user', 'value' => '100000000001', 'domain' => '.facebook.com'],
            ['name' => 'xs', 'value' => 'fake-session-token', 'domain' => '.facebook.com'],
        ];
    }

    private function root(): string
    {
        $root = dirname(__DIR__, 3) . '/.cache/messenger-transport-test-' . bin2hex(random_bytes(8));
        $this->roots[] = $root;
        return $root;
    }

    /** @return array{resource,array<int,resource>,int} */
    private function peer(string $mode): array
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/websocket-peer.php', $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) self::fail('Unable to start synthetic localhost peer.');
        fclose($pipes[0]); unset($pipes[0]);
        stream_set_timeout($pipes[1], 4);
        $port = trim(fgets($pipes[1]) ?: '');
        if (!ctype_digit($port)) { $this->stopPeer($process, $pipes); self::fail('Synthetic peer did not bind.'); }
        return [$process, $pipes, (int) $port];
    }

    /** @param resource $process @param array<int,resource> $pipes @return array<string,mixed> */
    private function finishPeer($process, array $pipes): array
    {
        $line = fgets($pipes[1]);
        self::assertIsString($line);
        return json_decode($line, true, 32, JSON_THROW_ON_ERROR);
    }

    /** @param resource $process @param array<int,resource> $pipes */
    private function stopPeer($process, array $pipes): void
    {
        if (is_resource($process)) {
            if (proc_get_status($process)['running']) proc_terminate($process);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_close($process);
        }
    }

    private static function removeTemporary(string $path): void
    {
        $resolved = realpath($path);
        $cache = realpath(dirname(__DIR__, 3) . '/.cache');
        if ($resolved === false || $cache === false || !str_starts_with($resolved, $cache . DIRECTORY_SEPARATOR . 'messenger-transport-test-')) return;
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        // PHPUnit can retain SQLite objects in exception diagnostics until process
        // exit on Windows. These synthetic ignored-cache files may then remain.
        foreach ($walk as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($resolved);
    }
}
