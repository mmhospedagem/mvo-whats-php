<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\History\{CallbackHistorySyncSink,HistoryService,HistorySyncSink};
use MVO\Auth\CredentialStore;
use MVO\Media\{MediaCrypto,MediaTransfer};
use MVO\Message\MessageService;
use MVO\Protocol\{BinaryData,BinaryNode,SchemaCodec};
use MVO\Signal\{SignalProtocol,SignalStore};
use MVO\Transport\{NodeSession,SessionRestartRequired};
use MVO\WaClient;
use PHPUnit\Framework\TestCase;

final class HistorySinkTest extends TestCase
{
    private string $path;
    private SignalStore $signal;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'mvo-history-sink-');
        $this->signal = new SignalStore($this->path);
    }

    protected function tearDown(): void
    {
        $this->signal->close();
        foreach ([$this->path, $this->path.'-wal', $this->path.'-shm'] as $file) if (is_file($file)) unlink($file);
    }

    private function service(
        ?HistorySyncSink $sink = null,
        bool $persist = true,
        ?\Closure $receipt = null,
        ?MediaTransfer $media = null,
        ?\Closure $emit = null,
    ): HistoryService {
        return new HistoryService(
            $this->signal,
            $media ?? new MediaTransfer(static fn(): BinaryNode => throw new \LogicException('No query expected')),
            static fn(): array => ['meJid' => '100:5@s.whatsapp.net'],
            $emit ?? static function(): void {},
            $receipt ?? static function(): void {},
            sink: $sink,
            persist: $persist,
        );
    }

    private static function chunk(): string
    {
        return SchemaCodec::encode('HistorySync', [
            'syncType' => 'RECENT', 'chunkOrder' => 4, 'progress' => 80,
            'conversations' => [[
                'id' => '200@s.whatsapp.net', 'name' => 'Example',
                'messages' => [
                    ['message' => ['key' => ['id' => 'history-one', 'fromMe' => false], 'message' => ['conversation' => 'First']], 'msgOrderId' => 11],
                    ['message' => ['key' => ['id' => 'history-stub', 'fromMe' => false]], 'msgOrderId' => 12],
                ],
            ]],
            'pushnames' => [['id' => '200@s.whatsapp.net', 'pushname' => 'Example']],
        ]);
    }

    private function queue(HistoryService $service, string $id = 'carrier', ?string $compressed = null): string
    {
        $service->handleProtocol([
            'id' => $id, 'from' => '100@s.whatsapp.net', 'fromMe' => true,
            'proto' => ['protocolMessage' => ['historySyncNotification' => [
                'syncType' => 3, 'initialHistBootstrapInlinePayload' => $compressed ?? gzcompress(self::chunk()),
            ]]],
        ]);
        return hash('sha256', "100@s.whatsapp.net\0".$id);
    }

    private function allowRetry(string $key): void
    {
        $job = BinaryData::decode($this->signal->get('history-pending', $key));
        $job['nextAttempt'] = 0;
        $this->signal->set('history-pending', $key, BinaryData::encode($job));
    }

    public function testCallbacksAreSynchronousOutsideTheStoreTransactionAndCommitPrecedesReceipt(): void
    {
        $order = []; $items = []; $chunks = [];
        $sink = new CallbackHistorySyncSink(
            function(array $item, array $chunk) use (&$order, &$items, &$chunks): void {
                // Another SQLite writer can acquire the lock: user callbacks do
                // not run inside the SDK's local import transaction.
                $other = new \PDO('sqlite:'.$this->path);
                $other->exec('PRAGMA busy_timeout=20');
                $other->exec('BEGIN IMMEDIATE');
                $other->exec('ROLLBACK');
                $order[] = 'enter:'.$item['kind'];
                $items[] = $item; $chunks[] = $chunk;
                self::assertNotContains('commit', $order);
                $order[] = 'return:'.$item['kind'];
            },
            function(array $chunk) use (&$order): void {
                self::assertSame('carrier', $chunk['id']);
                self::assertSame(2, $chunk['messagesCount']);
                self::assertSame(1, $chunk['conversationsCount']);
                self::assertNotNull($this->service()->store->getChat('200@s.whatsapp.net'));
                $order[] = 'commit';
            },
            static function(): void { self::fail('Successful chunk must not abort'); },
        );
        $service = $this->service($sink, receipt: function() use (&$order): void {
            self::assertSame('commit', $order[array_key_last($order)]);
            $order[] = 'receipt';
        });
        $this->queue($service); $service->drainPending();

        self::assertSame(['commit', 'receipt'], array_slice($order, -2));
        foreach ($items as $index => $item) {
            self::assertSame('enter:'.$item['kind'], $order[$index * 2]);
            self::assertSame('return:'.$item['kind'], $order[$index * 2 + 1]);
            self::assertSame('carrier', $chunks[$index]['id']);
            self::assertSame(3, $chunks[$index]['syncType']);
            self::assertSame(4, $chunks[$index]['chunkOrder']);
            self::assertSame(80, $chunks[$index]['progress']);
        }
        $messages = array_values(array_filter($items, static fn(array $item): bool => $item['kind'] === 'conversations.messages'));
        self::assertCount(2, $messages);
        self::assertSame('200@s.whatsapp.net', $messages[1]['conversationId']);
        self::assertSame(12, $messages[1]['value']['msgOrderId']);
        self::assertArrayNotHasKey('message', $messages[1]['value']['message']);
        self::assertSame([], $this->signal->entries('history-pending'));
    }

    public function testItemFailureAbortsWithOriginalErrorAndRetriesTheSameChunk(): void
    {
        $error = new \RuntimeException('External staging failed');
        $attempt = 0; $ids = []; $aborts = []; $commits = 0; $receipts = 0;
        $sink = new CallbackHistorySyncSink(
            static function(array $item, array $chunk) use (&$attempt, &$ids, $error): void {
                $ids[] = $chunk['id'];
                if (++$attempt === 2) throw $error;
            },
            static function() use (&$commits): void { ++$commits; },
            static function(array $chunk, \Throwable $cause) use (&$aborts): void { $aborts[] = [$chunk['id'], $cause]; },
        );
        $service = $this->service($sink, receipt: static function() use (&$receipts): void { ++$receipts; });
        $key = $this->queue($service); $service->drainPending();
        self::assertSame([['carrier', $error]], $aborts);
        self::assertSame(0, $commits); self::assertSame(0, $receipts);
        self::assertNull($service->store->getChat('200@s.whatsapp.net'));
        self::assertNull($this->signal->get('history-processed', $key));
        $this->allowRetry($key); $service->drainPending();
        self::assertSame(1, $commits); self::assertSame(1, $receipts);
        self::assertSame(['carrier'], array_values(array_unique($ids)));
        self::assertCount(1, $aborts);
        self::assertNotNull($this->signal->get('history-processed', $key));
    }

    public function testCommitFailureDoesNotAcknowledgeAndReplayIsExplicitlyAtLeastOnce(): void
    {
        $commits = 0; $receipts = 0; $items = 0; $aborts = 0;
        $sink = new CallbackHistorySyncSink(
            static function() use (&$items): void { ++$items; },
            static function() use (&$commits): void { if (++$commits === 1) throw new \RuntimeException('External commit failed'); },
            static function() use (&$aborts): void { ++$aborts; },
        );
        $service = $this->service($sink, receipt: static function() use (&$receipts): void { ++$receipts; });
        $key = $this->queue($service); $service->drainPending(); $firstItems = $items;
        self::assertGreaterThan(0, $firstItems); self::assertSame(0, $receipts);
        self::assertNull($this->signal->get('history-processed', $key));
        self::assertSame(1, $aborts);
        $this->allowRetry($key); $service->drainPending();
        self::assertSame($firstItems * 2, $items); self::assertSame(2, $commits);
        self::assertSame(1, $receipts); self::assertSame(1, $aborts);
        self::assertCount(2, $service->store->listMessages('200@s.whatsapp.net'));
    }

    public function testReceiptFailureDoesNotAbortOrReplayCommittedItems(): void
    {
        $items = 0; $commits = 0; $receipts = 0; $aborts = 0;
        $sink = new CallbackHistorySyncSink(
            static function() use (&$items): void { ++$items; },
            static function() use (&$commits): void { ++$commits; },
            static function() use (&$aborts): void { ++$aborts; },
        );
        $service = $this->service($sink, receipt: static function() use (&$receipts): void {
            if (++$receipts === 1) throw new \RuntimeException('Receipt transport unavailable');
        });
        $key = $this->queue($service); $service->drainPending(); $firstItems = $items;
        self::assertNotNull($this->signal->get('history-processed', $key));
        self::assertSame(1, $commits); self::assertSame(0, $aborts);
        $this->allowRetry($key); $service->drainPending();
        self::assertSame($firstItems, $items); self::assertSame(1, $commits);
        self::assertSame(2, $receipts); self::assertSame(0, $aborts);
        self::assertSame([], $this->signal->entries('history-pending'));
    }

    public function testAbortFailureCannotMaskTheOriginalDirectImportError(): void
    {
        $error = new \DomainException('Original item error'); $aborted = null;
        $sink = new CallbackHistorySyncSink(
            static function() use ($error): void { throw $error; },
            abort: static function(array $chunk, \Throwable $cause) use (&$aborted): void {
                $aborted = $cause; throw new \RuntimeException('Cleanup also failed');
            },
        );
        try { $this->service($sink)->ingest(self::chunk(), ['id' => 'manual']); self::fail('Expected original error'); }
        catch (\Throwable $cause) { self::assertSame($error, $cause); }
        self::assertSame($error, $aborted);
    }

    public function testCancellationInsideItemDoesNotTouchAClosedStoreOrAcknowledge(): void
    {
        $items = 0; $commits = 0; $receipts = 0; $aborts = [];
        $messages = null;
        $sink = new CallbackHistorySyncSink(
            static function() use (&$messages, &$items): void {
                ++$items;
                // Same invalidation path as closing a session: MessageService
                // cancels its history importer and closes the underlying store.
                $messages->close();
            },
            static function() use (&$commits): void { ++$commits; },
            static function(array $chunk, \Throwable $error) use (&$aborts): void { $aborts[] = $error; },
        );
        $credentials = CredentialStore::fresh(); $credentials['meJid'] = '100:5@s.whatsapp.net';
        $messages = new MessageService(new SignalProtocol($this->signal, $credentials), $this->signal,
            static fn(): array => $credentials, static function(): void {},
            static fn(): BinaryNode => throw new \LogicException('No query expected'),
            static function() use (&$receipts): void { ++$receipts; }, static function(): void {},
            historySink: $sink);
        $service = $messages->history();
        $key = $this->queue($service);
        try { $service->drainPending(); self::fail('Cancelled importer must stop its old continuation'); }
        catch (SessionRestartRequired) {}
        self::assertSame(1, $items); self::assertSame(0, $commits); self::assertSame(0, $receipts);
        self::assertCount(1, $aborts); self::assertInstanceOf(SessionRestartRequired::class, $aborts[0]);
        $this->signal = new SignalStore($this->path);
        self::assertNull($this->signal->get('history-processed', $key));
        self::assertNotNull($this->signal->get('history-pending', $key));
        self::assertSame([], $this->service()->store->listChats());
    }

    public function testCloseDuringSuccessfulCommitDoesNotAbortAlreadyCommittedExternalWork(): void
    {
        $aborts = 0; $commits = 0; $receipts = 0; $service = null;
        $sink = new CallbackHistorySyncSink(static function(): void {},
            static function() use (&$service, &$commits): void { ++$commits; $service->close(); },
            static function() use (&$aborts): void { ++$aborts; });
        $service = $this->service($sink, receipt: static function() use (&$receipts): void { ++$receipts; });
        $key = $this->queue($service);
        try { $service->drainPending(); self::fail('Old connection must not acknowledge after commit callback closes it'); }
        catch (SessionRestartRequired) {}
        self::assertSame(1, $commits); self::assertSame(0, $aborts); self::assertSame(0, $receipts);
        self::assertNull($this->signal->get('history-processed', $key));
        self::assertNotNull($this->signal->get('history-pending', $key));
    }

    public function testDirectImportsUseAStableContentIdWithoutCarrierMetadata(): void
    {
        $ids = [];
        $sink = new CallbackHistorySyncSink(static function(): void {},
            static function(array $chunk) use (&$ids): void { $ids[] = $chunk['id']; });
        $service = $this->service($sink);
        $service->ingestCompressed(gzcompress(self::chunk()));
        $service->ingestCompressed(gzencode(self::chunk()));
        self::assertSame([hash('sha256', self::chunk()), hash('sha256', self::chunk())], $ids);
    }

    public function testSinkCannotRecursivelyDrainOrImportAnotherChunk(): void
    {
        $cause = null; $service = null;
        $sink = new CallbackHistorySyncSink(
            static function() use (&$service): void { $service->drainPending(); },
            abort: static function(array $chunk, \Throwable $error) use (&$cause): void { $cause = $error; });
        $service = $this->service($sink);
        try { $service->ingest(self::chunk()); self::fail('Expected reentrancy guard'); }
        catch (\LogicException $error) { self::assertSame($cause, $error); }
        self::assertSame([], $service->store->listChats());
    }

    public function testInvalidCompressionAbortsBeforeAnyItem(): void
    {
        $items = 0; $commits = 0; $aborts = 0; $receipts = 0;
        $sink = new CallbackHistorySyncSink(
            static function() use (&$items): void { ++$items; },
            static function() use (&$commits): void { ++$commits; },
            static function() use (&$aborts): void { ++$aborts; },
        );
        $service = $this->service($sink, receipt: static function() use (&$receipts): void { ++$receipts; });
        $this->queue($service, compressed: substr(gzcompress(self::chunk()), 0, -2));
        $service->drainPending();
        self::assertSame(0, $items); self::assertSame(0, $commits);
        self::assertSame(1, $aborts); self::assertSame(0, $receipts);
    }

    public function testDownloadAuthenticationCompletesBeforeAnyItem(): void
    {
        $encrypted = MediaCrypto::encrypt(gzcompress(self::chunk()), 'history');
        $items = 0; $commits = 0; $aborts = 0; $receipts = 0;
        $media = new MediaTransfer(static fn(): BinaryNode => throw new \LogicException('No query expected'),
            static fn(): array => ['status' => 200, 'body' => $encrypted['body']]);
        $sink = new CallbackHistorySyncSink(
            static function() use (&$items): void { ++$items; },
            static function() use (&$commits): void { ++$commits; },
            static function() use (&$aborts): void { ++$aborts; },
        );
        $service = $this->service($sink, media: $media, receipt: static function() use (&$receipts): void { ++$receipts; });
        $service->handleProtocol([
            'id' => 'bad-auth', 'from' => '100@s.whatsapp.net', 'fromMe' => true,
            'proto' => ['protocolMessage' => ['historySyncNotification' => [
                'syncType' => 3, 'directPath' => '/history', 'mediaKey' => $encrypted['mediaKey'],
                'fileEncSha256' => $encrypted['fileEncSha256'], 'fileLength' => $encrypted['fileLength'],
                'fileSha256' => str_repeat("\0", 32),
            ]]],
        ]);
        $service->drainPending();
        self::assertSame(0, $items); self::assertSame(0, $commits);
        self::assertSame(1, $aborts); self::assertSame(0, $receipts);
        self::assertSame([], $service->store->listChats());
    }

    public function testAuthenticatedDownloadStreamsItemsAndAcknowledgesAfterCommit(): void
    {
        $encrypted = MediaCrypto::encrypt(gzcompress(self::chunk()), 'history');
        $items = 0; $commits = 0; $receipts = 0; $downloads = 0; $events = [];
        $media = new MediaTransfer(static fn(): BinaryNode => throw new \LogicException('No query expected'),
            static function() use ($encrypted, &$items, &$downloads): array {
                self::assertSame(0, $items); ++$downloads;
                return ['status' => 200, 'body' => $encrypted['body']];
            });
        $sink = new CallbackHistorySyncSink(
            static function(array $item, array $chunk) use (&$items): void {
                self::assertSame('authenticated-download', $chunk['id']); ++$items;
            },
            static function(array $chunk) use (&$commits): void {
                self::assertSame(2, $chunk['messagesCount']); ++$commits;
            },
            static function(): void { self::fail('Valid authenticated download must not abort'); },
        );
        $service = $this->service($sink, media: $media,
            receipt: static function() use (&$receipts, &$commits): void { self::assertSame(1, $commits); ++$receipts; },
            emit: static function(string $name, array $event) use (&$events): void { $events[] = [$name, $event]; });
        $notification = [
            'syncType' => 3, 'directPath' => '/history', 'mediaKey' => $encrypted['mediaKey'],
            'fileSha256' => $encrypted['fileSha256'], 'fileEncSha256' => $encrypted['fileEncSha256'],
            'fileLength' => $encrypted['fileLength'],
        ];
        $carrier = ['id' => 'authenticated-download', 'from' => '100@s.whatsapp.net', 'fromMe' => true,
            'proto' => ['protocolMessage' => ['historySyncNotification' => $notification]]];
        $service->handleProtocol($carrier); $service->drainPending();
        self::assertGreaterThan(0, $items); self::assertSame(1, $downloads);
        self::assertSame(1, $commits); self::assertSame(1, $receipts);
        self::assertSame('history_sync_chunk', $events[0][0]);
        self::assertSame('authenticated-download', $events[0][1]['id']);
        self::assertNotNull($service->store->getMessage('200@s.whatsapp.net', 'history-one'));
        $firstItems = $items;
        $service->handleProtocol($carrier); $service->drainPending();
        self::assertSame(1, $downloads); self::assertSame($firstItems, $items);
        self::assertSame(1, $commits); self::assertSame(2, $receipts); self::assertCount(1, $events);
    }

    public function testInitialStatusV3ReachesTheSinkAndRetainsTheExistingLocalProjection(): void
    {
        $items = []; $committed = []; $receipts = 0;
        $sink = new CallbackHistorySyncSink(
            static function(array $item, array $chunk) use (&$items): void {
                self::assertSame(1, $chunk['syncType']); $items[] = $item;
            },
            static function(array $chunk) use (&$committed): void { $committed[] = $chunk; });
        $service = $this->service($sink, receipt: static function() use (&$receipts): void { ++$receipts; });
        $payload = SchemaCodec::encode('HistorySync', [
            'syncType' => 'INITIAL_STATUS_V3',
            'statusV3Messages' => [[
                'key' => ['id' => 'initial-status', 'remoteJid' => 'status@broadcast', 'participant' => '200@s.whatsapp.net'],
                'message' => ['conversation' => 'Status from history'],
            ]],
        ]);
        $service->handleProtocol(['id' => 'status-carrier', 'from' => '100@s.whatsapp.net', 'fromMe' => true,
            'proto' => ['protocolMessage' => ['historySyncNotification' => [
                'syncType' => 1, 'initialHistBootstrapInlinePayload' => gzcompress($payload),
            ]]]]);
        $service->drainPending();
        self::assertCount(1, $items); self::assertSame('statusV3Messages', $items[0]['kind']);
        self::assertSame('initial-status', $items[0]['value']['key']['id']);
        self::assertCount(1, $committed); self::assertSame(1, $committed[0]['messagesCount']);
        self::assertSame(1, $receipts);
        self::assertSame('Status from history', $service->store->getMessage('status@broadcast', 'initial-status', '200@s.whatsapp.net')['text']);
    }

    public function testCancellationDuringDownloadDoesNotDeliverItemsOrUseTheClosedStore(): void
    {
        $encrypted = MediaCrypto::encrypt(gzcompress(self::chunk()), 'history');
        $service = null; $items = 0; $commits = 0; $receipts = 0; $aborts = [];
        $media = new MediaTransfer(static fn(): BinaryNode => throw new \LogicException('No query expected'),
            function() use ($encrypted, &$service): array {
                $service->close(); $this->signal->close();
                return ['status' => 200, 'body' => $encrypted['body']];
            });
        $sink = new CallbackHistorySyncSink(
            static function() use (&$items): void { ++$items; },
            static function() use (&$commits): void { ++$commits; },
            static function(array $chunk, \Throwable $error) use (&$aborts): void { $aborts[] = [$chunk, $error]; });
        $service = $this->service($sink, media: $media, receipt: static function() use (&$receipts): void { ++$receipts; });
        $service->handleProtocol(['id' => 'cancelled-download', 'from' => '100@s.whatsapp.net', 'fromMe' => true,
            'proto' => ['protocolMessage' => ['historySyncNotification' => [
                'syncType' => 3, 'directPath' => '/history', 'mediaKey' => $encrypted['mediaKey'],
                'fileSha256' => $encrypted['fileSha256'], 'fileEncSha256' => $encrypted['fileEncSha256'],
                'fileLength' => $encrypted['fileLength'],
            ]]]]);
        try { $service->drainPending(); self::fail('Old download completion must be cancelled'); }
        catch (SessionRestartRequired) {}
        self::assertSame(0, $items); self::assertSame(0, $commits); self::assertSame(0, $receipts);
        self::assertCount(1, $aborts); self::assertSame('cancelled-download', $aborts[0][0]['id']);
        self::assertInstanceOf(SessionRestartRequired::class, $aborts[0][1]);
        $this->signal = new SignalStore($this->path);
        $key = hash('sha256', "100@s.whatsapp.net\0cancelled-download");
        self::assertNull($this->signal->get('history-processed', $key));
        self::assertNotNull($this->signal->get('history-pending', $key));
        self::assertSame([], $this->service()->store->listChats());
    }

    public function testCallbackAdapterSupportsOptionalHooksAndPreservesThrowable(): void
    {
        $items = [];
        $sink = new CallbackHistorySyncSink(static function(array $item, array $chunk) use (&$items): void { $items[] = [$item, $chunk]; });
        $item = ['kind' => 'progress', 'value' => 100]; $chunk = ['id' => 'adapter', 'syncType' => 3];
        $sink->item($item, $chunk); $sink->commit($chunk); $sink->abort($chunk, new \RuntimeException());
        self::assertSame([[$item, $chunk]], $items);
    }

    public function testThirtyTwoMiBConversationImportsWithBoundedMemoryAndNoBulkStore(): void
    {
        if (!function_exists('proc_open')) self::markTestSkipped('PHP subprocess support is required');
        $script = 'require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).';'.<<<'PHP'
use MVO\History\{CallbackHistorySyncSink,HistoryService};
use MVO\Media\MediaTransfer;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Signal\SignalStore;
$varint = static function(int $value): string {
    $out = '';
    do { $next = $value & 127; $value >>= 7; $out .= chr($next | ($value > 0 ? 128 : 0)); } while ($value > 0);
    return $out;
};
$record = SchemaCodec::encode('HistorySyncMsg', [
    'message' => ['key' => ['id' => 'bounded-record'], 'message' => ['conversation' => str_repeat('x', 8192)]],
]);
$record = "\x12".$varint(strlen($record)).$record;
$conversation = "\x0a".$varint(strlen('200@s.whatsapp.net')).'200@s.whatsapp.net';
$count = 4096; $length = strlen($conversation) + strlen($record) * $count;
$path = tempnam(sys_get_temp_dir(), 'mvo-history-memory-');
$db = tempnam(sys_get_temp_dir(), 'mvo-history-memory-db-');
$store = null;
try {
    // Construct >32 MiB without ever allocating the whole conversation or chunk.
    $compressed = gzopen($path, 'wb6');
    gzwrite($compressed, "\x08\x03\x12".$varint($length).$conversation);
    for ($index = 0; $index < $count; ++$index) gzwrite($compressed, $record);
    gzclose($compressed);
    $bytes = file_get_contents($path);
    $store = new SignalStore($db);
    $seen = 0; $commits = 0;
    $sink = new CallbackHistorySyncSink(
        static function(array $item) use (&$seen): void { if ($item['kind'] === 'conversations.messages') ++$seen; },
        static function(array $chunk) use (&$commits, $count): void {
            if ($chunk['messagesCount'] !== $count) throw new RuntimeException('Incomplete import');
            ++$commits;
        },
    );
    $service = new HistoryService($store, new MediaTransfer(static fn(): BinaryNode => throw new LogicException('No network')),
        static fn(): array => ['meJid' => '100:5@s.whatsapp.net'], static function(): void {}, static function(): void {},
        sink: $sink, persist: false);
    gc_collect_cycles(); memory_reset_peak_usage(); $baseline = memory_get_usage(true);
    $result = $service->ingestCompressed($bytes, ['id' => 'bounded-memory']);
    $growth = memory_get_peak_usage(true) - $baseline;
    echo json_encode(['seen' => $seen, 'commits' => $commits, 'messages' => $result['messagesCount'],
        'growth' => $growth, 'payloadBytes' => $length, 'chats' => $service->store->listChats(),
        'storedMessages' => $service->store->listMessages('200@s.whatsapp.net')], JSON_THROW_ON_ERROR);
} finally {
    $store?->close();
    foreach ([$path, $db, $db.'-wal', $db.'-shm'] as $file) if (is_file($file)) unlink($file);
}
PHP;
        $ini = php_ini_loaded_file();
        $command = [PHP_BINARY, ...($ini === false ? [] : ['-c', $ini]), '-d', 'memory_limit=64M', '-r', $script];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null,
            array_merge(getenv(), ['XDEBUG_MODE' => 'off']), ['bypass_shell' => true, 'create_new_console' => false]);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(4096, $result['seen']); self::assertSame(4096, $result['messages']);
        self::assertSame(1, $result['commits']); self::assertGreaterThan(33554432, $result['payloadBytes']);
        self::assertLessThan(16777216, $result['growth'], 'Inflater, reader and importer must not retain the entire conversation');
        self::assertSame([], $result['chats']); self::assertSame([], $result['storedMessages']);
    }

    public function testDisablingHistoryPersistenceRequiresAnExplicitSink(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service(persist: false);
    }

    public function testWaClientPassesTheSinkToEachLoginAndInvalidatesTheOldImporter(): void
    {
        $directory = sys_get_temp_dir().'/mvo-history-client-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700); $client = null; $peers = []; $commits = [];
        $credentials = CredentialStore::fresh();
        $credentials['registered'] = true; $credentials['serverHasPreKeys'] = true;
        $credentials['meJid'] = '100:5@s.whatsapp.net';
        $auth = new CredentialStore($directory); $auth->save($credentials); $auth->close();
        $sink = new CallbackHistorySyncSink(static function(): void {},
            static function(array $chunk) use (&$commits): void { $commits[] = $chunk['id']; });
        try {
            $client = new WaClient(authDirectory: $directory, automaticMaintenance: false,
                historySink: $sink, historyPersist: false,
                sessionFactory: static function() use (&$peers): NodeSession {
                    $peer = new class implements NodeSession {
                        public array $incoming = [];
                        public array $sent = [];
                        public bool $closed = false;
                        public function start(string $staticPrivate, string $staticPublic, string $clientPayload): string { return str_repeat('s', 32); }
                        public function sendNode(BinaryNode $node): void {
                            $this->sent[] = $node;
                            if ($node->tag === 'iq' && in_array($node->attrs['type'] ?? '', ['get', 'set'], true)) {
                                $this->incoming[] = new BinaryNode('iq', ['type' => 'result', 'id' => $node->attrs['id']]);
                            }
                        }
                        public function receiveNode(float $timeoutSeconds = 1): ?BinaryNode { return array_shift($this->incoming); }
                        public function close(): void { $this->closed = true; }
                    };
                    $peers[] = $peer; return $peer;
                });
            $client->connect(); $peers[0]->incoming[] = new BinaryNode('success'); $client->poll(0);
            self::assertTrue($client->isConnected());
            $old = $client->message()->history();
            $this->queue($old, 'facade-first'); $old->drainPending();
            self::assertSame(['facade-first'], $commits);
            self::assertNull($client->history()->getChat('200@s.whatsapp.net'));
            self::assertSame([], $client->history()->listMessages('200@s.whatsapp.net'));
            $receipts = array_filter($peers[0]->sent, static fn(BinaryNode $node): bool => $node->tag === 'receipt' && ($node->attrs['type'] ?? '') === 'hist_sync');
            self::assertCount(1, $receipts);
            $client->stop();
            try { $old->ingest(self::chunk()); self::fail('Old importer must be invalid after stop'); }
            catch (SessionRestartRequired) {}
            $client->connect(); $peers[1]->incoming[] = new BinaryNode('success'); $client->poll(0);
            self::assertTrue($client->isConnected());
            $fresh = $client->message()->history(); self::assertNotSame($old, $fresh);
            $this->queue($fresh, 'facade-second'); $fresh->drainPending();
            self::assertSame(['facade-first', 'facade-second'], $commits);
            self::assertNull($client->history()->getChat('200@s.whatsapp.net'));
        } finally {
            $client?->stop(); $client = null; gc_collect_cycles();
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
            rmdir($directory);
        }
    }
}
