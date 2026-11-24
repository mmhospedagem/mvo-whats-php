<?php
declare(strict_types=1);

use MVO\Messenger\Auth\AndroidDeviceIdentity;
use MVO\Messenger\Auth\SessionStore;
use MVO\Messenger\MessengerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

final class AndroidDeviceIdentityTest extends TestCase
{
    private const SETTING = 'android.device_identity';
    private const DEVICE = '00000000-0000-4000-8000-000000000001';
    private const ADID = '00000000-0000-4000-8000-000000000002';
    /** @var list<string> */ private array $roots = [];
    /** @var list<SessionStore> */ private array $stores = [];

    protected function tearDown(): void
    {
        foreach ($this->stores as $store) $store->release();
        unset($store);
        $this->stores = [];
        gc_collect_cycles();
        $temporary = realpath(sys_get_temp_dir());
        foreach ($this->roots as $root) {
            $resolved = realpath($root);
            self::assertIsString($temporary);
            self::assertIsString($resolved);
            self::assertStringStartsWith($temporary . DIRECTORY_SEPARATOR, $resolved);
            self::assertMatchesRegularExpression('/\Amvo-android-identity-[a-f0-9]{16}\z/', basename($resolved));
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
                else unlink($file->getPathname());
            }
            rmdir($resolved);
        }
    }

    public function testConstructorDoesNotReadOrCreateIdentityAndSafeStatusDoesNotGenerateIt(): void
    {
        $store = $this->store();
        $identity = new AndroidDeviceIdentity($store);
        self::assertNull($store->get(self::SETTING));
        $store->acquire();
        self::assertSame(['initialized' => false, 'machine_id_retained' => false], $identity->safeProjection());
        self::assertNull($store->get(self::SETTING));
        self::assertSame([], $store->events());
    }

    #[DataProvider('ownerOperations')]
    public function testEveryIdentityOperationRequiresOwnershipBeforeReadingOrWriting(string $method, array $arguments): void
    {
        $store = $this->store();
        $identity = new AndroidDeviceIdentity($store);
        try {
            $identity->$method(...$arguments);
            self::fail('An unlocked helper must fail');
        } catch (LogicException $error) {
            self::assertStringContainsString('session lock', $error->getMessage());
        }
        self::assertNull($store->get(self::SETTING));
    }

    /** @return iterable<string,array{string,list<string>}> */
    public static function ownerOperations(): iterable
    {
        foreach (['ensure', 'getDeviceId', 'getAdvertisingId', 'privateMachineId', 'safeProjection'] as $method) yield $method => [$method, []];
        yield 'rememberMachineIdIfEmpty' => ['rememberMachineIdIfEmpty', ['SYNTHETIC_MACHINE_ID']];
    }

    public function testRandomUuidPairIsStoredOnceAndStableAcrossHelpersAndStoreRestart(): void
    {
        $root = $this->root();
        $store = $this->store($root, 'session');
        $store->acquire();
        $store->set('account_id', '123456789012345');
        $first = new AndroidDeviceIdentity($store);
        $first->ensure();
        $saved = $store->get(self::SETTING);
        $device = $first->getDeviceId();
        $adid = $first->getAdvertisingId();
        self::assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $device);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $adid);
        self::assertNotSame($device, $adid);
        self::assertNotSame('123456789012345', $device);
        self::assertNull($first->privateMachineId());
        self::assertSame(['version' => 1, 'device_id' => $device, 'adid' => $adid, 'machine_id' => null], json_decode($saved, true, 8, JSON_THROW_ON_ERROR));
        $second = new AndroidDeviceIdentity($store);
        $second->ensure();
        self::assertSame($device, $second->getDeviceId());
        self::assertSame($adid, $second->getAdvertisingId());
        self::assertSame($saved, $store->get(self::SETTING));
        self::assertNull($store->loadCookies());
        self::assertSame([], $store->events());
        $store->release();
        $restart = $this->store($root, 'session');
        $restart->acquire();
        $restored = new AndroidDeviceIdentity($restart);
        self::assertSame($device, $restored->getDeviceId());
        self::assertSame($adid, $restored->getAdvertisingId());
        self::assertSame($saved, $restart->get(self::SETTING));
    }

    public function testDifferentSessionsAndTenantRootsHaveIndependentIdentity(): void
    {
        $root = $this->root();
        $identities = [];
        foreach ([[$root, 'one'], [$root, 'two'], [$this->root(), 'one']] as [$tenantRoot, $session]) {
            $store = $this->store($tenantRoot, $session);
            $store->acquire();
            $identity = new AndroidDeviceIdentity($store);
            $identities[] = [$identity->getDeviceId(), $identity->getAdvertisingId()];
        }
        self::assertCount(6, array_unique(array_merge(...$identities)));
    }

    public function testMachineIdIsRememberedOnlyOnceAndNeverThroughStaleCachedState(): void
    {
        $root = $this->root();
        $store = $this->store($root, 'session');
        $store->acquire();
        $first = new AndroidDeviceIdentity($store);
        $second = new AndroidDeviceIdentity($store);
        $device = $first->getDeviceId();
        $adid = $first->getAdvertisingId();
        self::assertNull($first->privateMachineId());
        self::assertTrue($second->rememberMachineIdIfEmpty('SYNTHETIC_FIRST_MACHINE'));
        self::assertSame('SYNTHETIC_FIRST_MACHINE', $first->privateMachineId());
        self::assertFalse($first->rememberMachineIdIfEmpty('SYNTHETIC_SECOND_MACHINE'));
        self::assertSame($device, $first->getDeviceId());
        self::assertSame($adid, $first->getAdvertisingId());
        $saved = $store->get(self::SETTING);
        $store->release();
        $next = $this->store($root, 'session');
        $next->acquire();
        $restored = new AndroidDeviceIdentity($next);
        self::assertSame('SYNTHETIC_FIRST_MACHINE', $restored->privateMachineId());
        self::assertFalse($restored->rememberMachineIdIfEmpty('SYNTHETIC_REPLACEMENT'));
        self::assertSame($saved, $next->get(self::SETTING));
        try { $first->getDeviceId(); self::fail('A previous helper cannot use the next owner lock'); }
        catch (LogicException $error) { self::assertStringContainsString('session lock', $error->getMessage()); }
    }

    #[DataProvider('invalidStates')]
    public function testMalformedPrivateStateFailsWithoutReplacingOrResettingIt(string $json): void
    {
        $store = $this->store();
        $store->acquire();
        $store->set(self::SETTING, $json);
        $identity = new AndroidDeviceIdentity($store);
        try { $identity->ensure(); self::fail('Malformed identity must not reset'); }
        catch (MessengerException $error) { self::assertSame('login_native_identity_invalid', $error->reason); }
        self::assertSame($json, $store->get(self::SETTING));
        self::assertSame([], $store->events());
    }

    /** @return iterable<string,array{string}> */
    public static function invalidStates(): iterable
    {
        foreach (['', '{', 'null', '[]', '{}', str_repeat('x', 32769)] as $i => $json) yield 'invalid JSON or shape ' . $i => [$json];
        $valid = ['version' => 1, 'device_id' => self::DEVICE, 'adid' => self::ADID, 'machine_id' => null];
        foreach ([['version', 2], ['version', '1'], ['device_id', '123456789012345'], ['device_id', '00000000-0000-1000-8000-000000000001'], ['device_id', '00000000-0000-4000-0000-000000000001'], ['adid', self::DEVICE], ['machine_id', 123], ['machine_id', ''], ['machine_id', "bad\0value"], ['machine_id', str_repeat('p', 4097)]] as $i => [$field, $value]) {
            $state = $valid;
            $state[$field] = $value;
            yield 'invalid field ' . $i => [json_encode($state, JSON_THROW_ON_ERROR)];
        }
        $missing = $valid;
        unset($missing['machine_id']);
        yield 'missing explicit machine slot' => [json_encode($missing, JSON_THROW_ON_ERROR)];
        yield 'unknown field' => [json_encode([...$valid, 'account_id' => '123'], JSON_THROW_ON_ERROR)];
    }

    public function testPreviouslyReadIdentityDoesNotHideSubsequentSavedStateCorruption(): void
    {
        $store = $this->store();
        $store->acquire();
        $identity = new AndroidDeviceIdentity($store);
        $identity->ensure();
        $store->set(self::SETTING, '{malformed');
        try { $identity->privateMachineId(); self::fail('Stale memory cannot hide invalid persisted state'); }
        catch (MessengerException $error) { self::assertSame('login_native_identity_invalid', $error->reason); }
        self::assertSame('{malformed', $store->get(self::SETTING));
    }

    public function testMachineIdValidationDoesNotCreateStateOrLeakInput(): void
    {
        $store = $this->store();
        $store->acquire();
        $identity = new AndroidDeviceIdentity($store);
        foreach (['', "SYNTHETIC\0PRIVATE", "\xc0\xaf", str_repeat('p', 4097)] as $invalid) {
            try { $identity->rememberMachineIdIfEmpty($invalid); self::fail('Invalid machine ID must fail'); }
            catch (InvalidArgumentException $error) { self::assertSame('Invalid Android login machine ID', $error->getMessage()); }
            self::assertNull($store->get(self::SETTING));
        }
    }

    public function testSafeProjectionDebugAndJsonDoNotExposePrivateIdentityOrCreateEvents(): void
    {
        $store = $this->store();
        $store->acquire();
        $identity = new AndroidDeviceIdentity($store);
        $identity->rememberMachineIdIfEmpty('SYNTHETIC_PRIVATE_MACHINE');
        self::assertSame(['initialized' => true, 'machine_id_retained' => true], $identity->safeProjection());
        $projection = json_encode($identity->safeProjection(), JSON_THROW_ON_ERROR) . print_r($identity, true) . json_encode($identity, JSON_THROW_ON_ERROR);
        foreach ([$identity->getDeviceId(), $identity->getAdvertisingId(), 'SYNTHETIC_PRIVATE_MACHINE'] as $private) self::assertStringNotContainsString($private, $projection);
        self::assertSame([], $store->events());
        self::assertCount(1, (new ReflectionMethod(AndroidDeviceIdentity::class, 'rememberMachineIdIfEmpty'))->getParameters()[0]->getAttributes(SensitiveParameter::class));
        $this->expectException(LogicException::class);
        serialize($identity);
    }

    public function testFailedMachineWriteKeepsCompleteOriginalIdentityAndCanBeRetried(): void
    {
        $root = $this->root();
        $store = $this->store($root, 'session');
        $store->acquire();
        $identity = new AndroidDeviceIdentity($store);
        $identity->ensure();
        $original = $store->get(self::SETTING);
        $database = new PDO('sqlite:' . $root . '/messenger/session/state.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database->exec("CREATE TRIGGER fail_android_identity BEFORE UPDATE ON settings WHEN NEW.name='android.device_identity' BEGIN SELECT RAISE(ABORT,'synthetic identity write failure'); END");
        try { $identity->rememberMachineIdIfEmpty('SYNTHETIC_MACHINE'); self::fail('Expected fixture write failure'); }
        catch (PDOException $error) { self::assertStringContainsString('synthetic identity write failure', $error->getMessage()); }
        self::assertSame($original, $store->get(self::SETTING));
        self::assertNull($identity->privateMachineId());
        self::assertTrue($store->ownsLock());
        $database->exec('DROP TRIGGER fail_android_identity');
        self::assertTrue($identity->rememberMachineIdIfEmpty('SYNTHETIC_MACHINE'));
        self::assertSame('SYNTHETIC_MACHINE', $identity->privateMachineId());
        $database = null;
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mvo-android-identity-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $this->roots[] = $root;
        return $root;
    }

    private function store(?string $root = null, string $session = 'session'): SessionStore
    {
        $store = new SessionStore($root ?? $this->root(), $session);
        $this->stores[] = $store;
        return $store;
    }
}
