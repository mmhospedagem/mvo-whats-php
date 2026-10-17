<?php
declare(strict_types=1);

namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Store\SqliteStore;

final class SqliteStoreTest extends TestCase
{
    private string $path;
    private ?SqliteStore $store = null;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mvo-phpunit-');
        if ($path === false) {
            throw new \RuntimeException('Cannot create temporary database.');
        }
        $this->path = $path;
        $this->store = new SqliteStore($this->path, 'session-a');
    }

    protected function tearDown(): void
    {
        $this->store?->close();
        $this->store = null;
        foreach ([$this->path, $this->path.'-wal', $this->path.'-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testBinaryValuesPersistAfterReopeningDatabase(): void
    {
        $value = "\0\xffprivate-key\0";
        $this->store->set('auth', 'key', $value);
        $this->store = null;
        $this->store = new SqliteStore($this->path, 'session-a');

        self::assertSame($value, $this->store->get('auth', 'key'));
    }

    public function testSessionsAndNamespacesAreIsolated(): void
    {
        $this->store->set('auth', 'key', 'first');
        $this->store->set('signal', 'key', 'second');
        $otherSession = new SqliteStore($this->path, 'session-b');
        $otherSession->set('auth', 'key', 'third');

        self::assertSame('first', $this->store->get('auth', 'key'));
        self::assertSame('second', $this->store->get('signal', 'key'));
        self::assertSame('third', $otherSession->get('auth', 'key'));
        self::assertNull($otherSession->get('signal', 'key'));
    }

    public function testEmptyStoredValueIsDifferentFromMissingKey(): void
    {
        $this->store->set('auth', 'empty', '');
        self::assertSame('', $this->store->get('auth', 'empty'));
        self::assertNull($this->store->get('auth', 'missing'));
    }

    public function testTransactionCommitsAllChangesAndReturnsCallbackResult(): void
    {
        $result = $this->store->transaction(static function (SqliteStore $store): string {
            $store->set('auth', 'one', 'a');
            $store->set('auth', 'two', 'b');
            return 'committed';
        });

        self::assertSame('committed', $result);
        self::assertSame('a', $this->store->get('auth', 'one'));
        self::assertSame('b', $this->store->get('auth', 'two'));
    }

    public function testFailedTransactionRollsBackUpdateAndInsert(): void
    {
        $this->store->set('auth', 'existing', 'original');
        try {
            $this->store->transaction(static function (SqliteStore $store): void {
                $store->set('auth', 'existing', 'changed');
                $store->set('auth', 'new', 'inserted');
                throw new \RuntimeException('deliberate rollback');
            });
            self::fail('Transaction should propagate callback failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('deliberate rollback', $error->getMessage());
        }

        self::assertSame('original', $this->store->get('auth', 'existing'));
        self::assertNull($this->store->get('auth', 'new'));
    }

    public function testNestedTransactionIsRejectedAndOuterChangesAreRolledBack(): void
    {
        try {
            $this->store->transaction(static function (SqliteStore $store): void {
                $store->set('auth', 'new', 'inserted');
                $store->transaction(static fn (): string => 'nested');
            });
            self::fail('Nested transaction should be rejected.');
        } catch (\LogicException $error) {
            self::assertSame('Nested transaction', $error->getMessage());
        }

        self::assertNull($this->store->get('auth', 'new'));
        self::assertSame('usable', $this->store->transaction(static fn (): string => 'usable'));
    }

    public function testDeleteRemovesOnlySelectedKey(): void
    {
        $this->store->set('auth', 'key', 'auth-value');
        $this->store->set('signal', 'key', 'signal-value');
        $this->store->delete('auth', 'key');

        self::assertNull($this->store->get('auth', 'key'));
        self::assertSame('signal-value', $this->store->get('signal', 'key'));
    }
}
