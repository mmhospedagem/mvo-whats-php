<?php
declare(strict_types=1);

namespace MVO\Facebook\Tests;

use MVO\Facebook\Monitoring\ResourceScope;
use MVO\Facebook\Monitoring\SnapshotItem;
use MVO\Facebook\Monitoring\SnapshotMonitor;
use MVO\Facebook\Monitoring\SnapshotPage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Monitoring/ResourceScope.php';
require_once __DIR__ . '/../src/Monitoring/SnapshotItem.php';
require_once __DIR__ . '/../src/Monitoring/SnapshotPage.php';
require_once __DIR__ . '/../src/Monitoring/SnapshotMonitor.php';

final class MonitoringTest extends TestCase
{
    /** @var list<string> */ private array $directories = [];

    public function testPaginatedBaselineResumesAfterRestartAndDoesNotNotifyHistoricalItems(): void
    {
        $file = $this->file(); $scope = $this->scope(); $monitor = new SnapshotMonitor($file);
        $first = new SnapshotPage('baseline-1', [new SnapshotItem('page_post_a', ['text' => 'Historical post'])], nextCursor: 'page:2');
        self::assertSame(['duplicate' => false, 'committed' => false, 'baseline_created' => false, 'events_created' => 0], $monitor->ingest($scope, $first));
        self::assertSame([], $monitor->items($scope)); self::assertSame([], $monitor->events($scope));
        self::assertFalse($monitor->state($scope)['baseline_ready']); unset($monitor);
        $monitor = new SnapshotMonitor($file);
        self::assertSame('page:2', $monitor->state($scope)['next_cursor']);
        self::assertSame(1, $monitor->state($scope)['pending_pages']);
        self::assertTrue($monitor->ingest($scope, $first)['duplicate']);
        $result = $monitor->ingest($scope, new SnapshotPage('baseline-1', [new SnapshotItem('page_post_b', ['text' => 'Another historical post'])], 'page:2', complete: true));
        self::assertTrue($result['baseline_created']); self::assertTrue($result['committed']);
        self::assertCount(2, $monitor->items($scope)); self::assertSame([], $monitor->events($scope));
        self::assertTrue($monitor->state($scope)['baseline_ready']); self::assertNull($monitor->state($scope)['next_cursor']);
    }

    public function testIncompleteInitialCollectionCannotCreateABaselineOrFalseNewItemEvents(): void
    {
        $monitor = new SnapshotMonitor($this->file()); $scope = $this->scope();
        $monitor->ingest($scope, new SnapshotPage('limited-first', [new SnapshotItem('a')]));
        self::assertFalse($monitor->state($scope)['baseline_ready']); self::assertCount(1, $monitor->items($scope));
        $monitor->ingest($scope, new SnapshotPage('first-full', [new SnapshotItem('a'), new SnapshotItem('b')], complete: true));
        self::assertTrue($monitor->state($scope)['baseline_ready']); self::assertSame([], $monitor->events($scope));
        $monitor->ingest($scope, new SnapshotPage('next-full', [new SnapshotItem('a'), new SnapshotItem('b'), new SnapshotItem('c')], complete: true));
        self::assertSame(['c'], array_column($monitor->events($scope), 'item_id'));
    }

    public function testPartialUpdatesDoNotInferDeletionAndIdempotentReplaySurvivesRestart(): void
    {
        $file = $this->file(); $monitor = new SnapshotMonitor($file); $scope = $this->scope();
        $monitor->ingest($scope, new SnapshotPage('first', [new SnapshotItem('a', ['text' => 'Original'], ['comments' => 3, 'likes' => 4]), new SnapshotItem('b')], complete: true));
        $partial = new SnapshotPage('partial', [new SnapshotItem('a', ['text' => 'Edited'], ['comments' => 2]), new SnapshotItem('c')]);
        self::assertSame(3, $monitor->ingest($scope, $partial)['events_created']);
        self::assertSame(['item.updated', 'counters.changed', 'item.created'], array_column($monitor->events($scope), 'type'));
        self::assertSame(['a', 'b', 'c'], array_column($monitor->items($scope), 'id'));
        self::assertSame(4, $monitor->items($scope)[0]['counters']['likes']);
        self::assertSame(-1, $monitor->events($scope)[1]['payload']['changes']['comments']['delta']);
        $events = $monitor->events($scope); unset($monitor); $monitor = new SnapshotMonitor($file);
        self::assertTrue($monitor->ingest($scope, $partial)['duplicate']); self::assertSame($events, $monitor->events($scope));
        $monitor->ingest($scope, new SnapshotPage('full', [new SnapshotItem('a'), new SnapshotItem('c')], complete: true));
        self::assertSame('item.removed', $monitor->events($scope)[3]['type']); self::assertSame('b', $monitor->events($scope)[3]['item_id']);
    }

    public function testCursorsItemsAndEventsAreSeparatedByAccountActingIdentityAndResource(): void
    {
        $monitor = new SnapshotMonitor($this->file());
        $scopes = [$this->scope(), $this->scope('account-b'), $this->scope(identity: 'page-b'), $this->scope(resource: 'page-other'), $this->scope(type: 'post.comments')];
        foreach ($scopes as $index => $scope) {
            $monitor->ingest($scope, new SnapshotPage('same-snapshot', [new SnapshotItem('native_1', ['text' => (string) $index])], nextCursor: 'cursor-' . $index));
        }
        foreach ($scopes as $index => $scope) {
            self::assertSame('cursor-' . $index, $monitor->state($scope)['next_cursor']);
            $monitor->ingest($scope, new SnapshotPage('same-snapshot', [], 'cursor-' . $index, complete: true));
            self::assertSame((string) $index, $monitor->items($scope)[0]['fields']['text']);
        }
        $monitor->ingest($scopes[1], new SnapshotPage('change', [new SnapshotItem('native_2')], complete: true));
        self::assertCount(2, $monitor->events($scopes[1]));
        foreach ([0, 2, 3, 4] as $index) self::assertSame([], $monitor->events($scopes[$index]));
    }

    public function testChangedRetriesOutOfOrderPagesAndPaginationCyclesAreRejectedWithoutChanges(): void
    {
        $monitor = new SnapshotMonitor($this->file()); $scope = $this->scope();
        $monitor->ingest($scope, new SnapshotPage('one', [new SnapshotItem('a')], nextCursor: 'cursor-a'));
        $expected = $monitor->state($scope);
        $invalidPages = [
            new SnapshotPage('one', [new SnapshotItem('changed')], nextCursor: 'cursor-a'),
            new SnapshotPage('one', [], 'cursor-wrong', complete: true),
            new SnapshotPage('different', [], complete: true),
        ];
        foreach ($invalidPages as $page) {
            try { $monitor->ingest($scope, $page); self::fail('An invalid pagination sequence was accepted'); } catch (\LogicException) { }
            self::assertSame($expected, $monitor->state($scope)); self::assertSame([], $monitor->items($scope));
        }
        $monitor->ingest($scope, new SnapshotPage('one', [], 'cursor-a', 'cursor-b'));
        try { $monitor->ingest($scope, new SnapshotPage('one', [], 'cursor-b', 'cursor-a')); self::fail('A pagination cycle was accepted'); } catch (\LogicException) { }
        self::assertSame('cursor-b', $monitor->state($scope)['next_cursor']);
        $monitor->ingest($scope, new SnapshotPage('one', [], 'cursor-b', complete: true));
        self::assertSame(['a'], array_column($monitor->items($scope), 'id'));
    }

    public function testExplicitDiscardDoesNotChangeCommittedProjectionAndCannotBeResumed(): void
    {
        $monitor = new SnapshotMonitor($this->file()); $scope = $this->scope();
        $monitor->ingest($scope, new SnapshotPage('baseline', [new SnapshotItem('old')], complete: true));
        $monitor->ingest($scope, new SnapshotPage('aborted', [new SnapshotItem('unconfirmed')], nextCursor: 'more'));
        $monitor->discardPending($scope, 'aborted');
        self::assertSame(['old'], array_column($monitor->items($scope), 'id')); self::assertSame([], $monitor->events($scope));
        try { $monitor->ingest($scope, new SnapshotPage('aborted', [], 'more', complete: true)); self::fail('Discarded snapshot resumed'); } catch (\LogicException) { }
        $monitor->ingest($scope, new SnapshotPage('fresh', [new SnapshotItem('old')], complete: true));
        self::assertSame('fresh', $monitor->state($scope)['last_snapshot_id']);
    }

    public function testProjectionEventsReceiptAndCursorRollBackTogetherAfterSqliteFault(): void
    {
        $file = $this->file(); $monitor = new SnapshotMonitor($file); $scope = $this->scope();
        $monitor->ingest($scope, new SnapshotPage('baseline', [new SnapshotItem('a', ['text' => 'Before']), new SnapshotItem('b')], complete: true));
        $monitor->ingest($scope, new SnapshotPage('change', [new SnapshotItem('a', ['text' => 'After'])], nextCursor: 'terminal'));
        $expectedState = $monitor->state($scope); $expectedItems = $monitor->items($scope);
        $fault = new \PDO('sqlite:' . $file); $fault->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $fault->exec("CREATE TRIGGER fail_monitor_event BEFORE INSERT ON monitor_events WHEN NEW.type='item.removed' BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
        $last = new SnapshotPage('change', [new SnapshotItem('c')], 'terminal', complete: true);
        try { $monitor->ingest($scope, $last); self::fail('SQLite fault did not abort'); } catch (\PDOException) { }
        self::assertSame($expectedState, $monitor->state($scope)); self::assertSame($expectedItems, $monitor->items($scope)); self::assertSame([], $monitor->events($scope));
        $fault->exec('DROP TRIGGER fail_monitor_event');
        self::assertFalse($monitor->ingest($scope, $last)['duplicate']);
        self::assertSame(['a', 'c'], array_column($monitor->items($scope), 'id'));
        self::assertSame(['item.updated', 'item.created', 'item.removed'], array_column($monitor->events($scope), 'type'));
    }

    public function testMissingMeasurementsDoNotNotifyAndExplicitFieldClearingDoes(): void
    {
        $monitor = new SnapshotMonitor($this->file()); $scope = $this->scope(type: 'post.reactions');
        $monitor->ingest($scope, new SnapshotPage('one', [new SnapshotItem('actor:123', ['reaction_type' => 'LIKE', 'text' => 'value'])], complete: true));
        $monitor->ingest($scope, new SnapshotPage('measurement', [new SnapshotItem('actor:123', counters: ['total' => 0])], complete: true));
        self::assertSame([], $monitor->events($scope));
        $monitor->ingest($scope, new SnapshotPage('changed', [new SnapshotItem('actor:123', ['reaction_type' => 'LOVE', 'text' => null], ['total' => 1])], complete: true));
        $events = $monitor->events($scope);
        self::assertSame(['before' => 'LIKE', 'after' => 'LOVE'], $events[0]['payload']['changes']['reaction_type']);
        self::assertSame(['before' => 'value', 'after' => null], $events[0]['payload']['changes']['text']);
        self::assertSame(['before' => 0, 'after' => 1, 'delta' => 1], $events[1]['payload']['changes']['total']);
    }

    public function testEventAndItemPaginationIsStableAcrossReaderInstances(): void
    {
        $file = $this->file(); $writer = new SnapshotMonitor($file); $reader = new SnapshotMonitor($file); $scope = $this->scope();
        $writer->ingest($scope, new SnapshotPage('zero', [], complete: true));
        $page = new SnapshotPage('one', [new SnapshotItem('0001'), new SnapshotItem('native_2'), new SnapshotItem('native_3')], complete: true);
        $writer->ingest($scope, $page); self::assertTrue($reader->ingest($scope, $page)['duplicate']);
        $first = $reader->events($scope, limit: 1); self::assertCount(1, $first);
        $rest = $writer->events($scope, $first[0]['id'], 2);
        self::assertSame(['native_2', 'native_3'], array_column($rest, 'item_id'));
        self::assertSame(['0001'], array_column($reader->items($scope, limit: 1), 'id'));
        self::assertSame(['native_2', 'native_3'], array_column($reader->items($scope, '0001'), 'id'));
        self::assertCount(3, $reader->events($scope));
    }

    public function testEmptyExhaustiveSnapshotsRemoveOnlyAfterBaselineAndRawSecretFieldsAreRejected(): void
    {
        $monitor = new SnapshotMonitor($this->file()); $scope = $this->scope();
        $monitor->ingest($scope, new SnapshotPage('one', [new SnapshotItem('a')], complete: true));
        $monitor->ingest($scope, new SnapshotPage('partial-empty', []));
        self::assertCount(1, $monitor->items($scope)); self::assertSame([], $monitor->events($scope));
        $monitor->ingest($scope, new SnapshotPage('full-empty', [], complete: true));
        self::assertSame([], $monitor->items($scope)); self::assertSame('item.removed', $monitor->events($scope)[0]['type']);
        $sensitive = new SnapshotItem('safe-id', ['text' => 'not-in-debug']);
        self::assertStringNotContainsString('not-in-debug', print_r($sensitive, true));
        $this->expectException(\InvalidArgumentException::class); new SnapshotItem('a', ['access_token' => 'do-not-store']);
    }

    public function testRepeatedItemAcrossPagesPreservesUnknownFieldsAndUsesLastObservedValues(): void
    {
        $monitor = new SnapshotMonitor($this->file()); $scope = $this->scope();
        $monitor->ingest($scope, new SnapshotPage('one', [new SnapshotItem('a', ['text' => 'Known', 'author_id' => 'actor-a'], ['comments' => 3])], nextCursor: 'more'));
        $monitor->ingest($scope, new SnapshotPage('one', [new SnapshotItem('a', ['text' => 'Latest'], ['likes' => 5])], 'more', complete: true));
        self::assertSame(['id' => 'a', 'fields' => ['text' => 'Latest', 'author_id' => 'actor-a'], 'counters' => ['comments' => 3, 'likes' => 5]], $monitor->items($scope)[0]);
        self::assertSame([], $monitor->events($scope));
    }

    private function scope(string $account = 'account-a', string $identity = 'page-a', string $resource = 'native-page', string $type = 'page.posts'): ResourceScope
    { return new ResourceScope($account, $identity, $type, $resource); }
    private function file(): string
    {
        $directory = sys_get_temp_dir() . '/mvo-facebook-monitor-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true); $this->directories[] = $directory; return $directory . '/monitor.sqlite';
    }
    protected function tearDown(): void
    {
        gc_collect_cycles();
        foreach ($this->directories as $directory) { foreach (glob($directory . '/*') ?: [] as $file) if (is_file($file)) unlink($file); rmdir($directory); }
    }
}
