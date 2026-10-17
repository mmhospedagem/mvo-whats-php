<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Auth\CredentialStore;

final class CredentialStoreTest extends TestCase
{
    private string $directory;
    /** @var list<CredentialStore> */ private array $stores = [];
    protected function setUp(): void { $this->directory = sys_get_temp_dir().'/mvo-creds-'.bin2hex(random_bytes(8)); mkdir($this->directory,0700); }
    protected function tearDown(): void {
        foreach ($this->stores as $store) $store->close(); $this->stores = [];
        gc_collect_cycles();
        foreach (glob($this->directory.'/*/*') ?: [] as $file) unlink($file);
        foreach (glob($this->directory.'/*') ?: [] as $dir) rmdir($dir);
        rmdir($this->directory);
    }
    public function testFreshCredentialsSurviveBinaryRoundTripAndSessionsAreIsolated(): void {
        $store = new CredentialStore($this->directory,'one');
        $creds = CredentialStore::fresh(); $creds['testBytes'] = "\0\xff\xfe";
        $store->save($creds); self::assertSame($creds,$store->load());
        $other = new CredentialStore($this->directory,'two'); self::assertNull($other->load());
        unset($store,$other);
        self::assertSame($creds,(new CredentialStore($this->directory,'one'))->load());
    }
    public function testActiveSessionHasExclusiveLockAndCanBeReacquired(): void {
        $a = new CredentialStore($this->directory,'same'); $b = new CredentialStore($this->directory,'same'); $this->stores=[$a,$b];
        $a->acquire(); self::assertTrue($a->ownsLock());
        try { $b->acquire(); self::fail('Concurrent lock acquired'); } catch (\RuntimeException $e) { self::assertStringContainsString('already in use',$e->getMessage()); }
        $a->release(); $b->acquire(); self::assertTrue($b->ownsLock()); $b->release();
    }
    public function testCorruptCredentialsCannotOverwriteLastValidState(): void {
        $store = new CredentialStore($this->directory); $this->stores=[$store]; $good = CredentialStore::fresh(); $store->save($good);
        $bad = $good; $bad['signedPreKey']['signature'][0] = chr(ord($bad['signedPreKey']['signature'][0])^1);
        try { $store->save($bad); self::fail('Accepted corrupt signature'); } catch (\UnexpectedValueException) { self::assertSame($good,$store->load()); }
        $bad = $good; $bad['noiseKeyPair']['public'] = random_bytes(32);
        $this->expectException(\UnexpectedValueException::class); $store->save($bad);
    }
    public function testRejectsSessionDirectoryTraversal(): void {
        $this->expectException(\InvalidArgumentException::class); new CredentialStore($this->directory,'../outside');
    }
}
