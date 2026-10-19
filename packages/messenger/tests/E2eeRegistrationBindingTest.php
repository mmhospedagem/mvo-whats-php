<?php
declare(strict_types=1);
namespace MVO\Messenger\Tests;

use MVO\Messenger\Auth\{CookieJar,SessionStore};
use MVO\Messenger\E2ee\{CryptoAuthToken,DeviceKeys,DeviceManager,RegistrationTransport};
use MVO\Messenger\MessengerException;
use MVO\Messenger\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

/** Independent regression: a confirmed device ID cannot be attached to restored/replaced keys. */
final class E2eeRegistrationBindingTest extends TestCase
{
    private string $directory;
    private ?SessionStore $store = null;
    protected function setUp(): void
    {
        if (!extension_loaded('gmp')) self::markTestSkipped('Optional E2EE signatures require GMP');
        $this->directory = sys_get_temp_dir().'/mvo-device-binding-'.bin2hex(random_bytes(8));
        $this->store = new SessionStore($this->directory, 'binding');
        $this->store->acquire();
        $this->store->saveCookies(CookieJar::fromJson('{"c_user":"10001","xs":"SYNTHETIC_DEVICE_BINDING"}'));
    }
    protected function tearDown(): void
    {
        $this->store?->release(); $this->store = null; gc_collect_cycles();
        if (!isset($this->directory) || !is_dir($this->directory)) return;
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->directory);
    }

    public function testRegistrationConfirmsTheIdentityFingerprintAndRejectsRestoredOtherDeviceKeys(): void
    {
        $transport = new DeviceBindingTransport(); $manager = $this->manager($transport);
        $roster = $manager->registrationRoster(); $manager->register($roster['snapshot_hash']);
        $stored = json_decode($this->store->get('e2ee.device.registration.v1'), true, 16, JSON_THROW_ON_ERROR);
        $keys = DeviceKeys::fromPrivateJson($this->store->get('e2ee.device.keys.v1'), '10001');
        self::assertSame(hash('sha256', $keys->identityPublic()), $stored['identity_fingerprint']);
        self::assertTrue($manager->status()['registration_confirmed']);
        $this->store->set('e2ee.device.keys.v1', DeviceKeys::generate('10001')->exportPrivateJson());
        $calls = $transport->calls;
        try { $manager->status(); self::fail('A registration for another device key was treated as confirmed'); }
        catch (MessengerException $error) { self::assertSame('messenger_device_identity_mismatch', $error->reason); }
        try { $manager->register($roster['snapshot_hash']); self::fail('An early registered return bypassed key identity validation'); }
        catch (MessengerException $error) { self::assertSame('messenger_device_identity_mismatch', $error->reason); }
        self::assertSame($calls, $transport->calls);
    }

    public function testReplacingIdentityWhileKeepingTheSameUuidCannotReuseTheConfirmedDeviceId(): void
    {
        $transport = new DeviceBindingTransport(); $manager = $this->manager($transport);
        $roster = $manager->registrationRoster(); $manager->register($roster['snapshot_hash']);
        $original = json_decode($this->store->get('e2ee.device.keys.v1'), true, 16, JSON_THROW_ON_ERROR);
        $replacement = json_decode(DeviceKeys::generate('10001')->exportPrivateJson(), true, 16, JSON_THROW_ON_ERROR);
        $replacement['uuid'] = $original['uuid'];
        $this->store->set('e2ee.device.keys.v1', json_encode($replacement, JSON_THROW_ON_ERROR));
        $this->expectException(MessengerException::class);
        $manager->status();
    }

    public function testOwnerGuardBlocksRetainedRegistrationManagerBeforeTokenOrTransportAfterStop(): void
    {
        $active = true; $transport = new DeviceBindingTransport(); $tokens = 0;
        $manager = new DeviceManager($this->store, '10001', $transport,
            static function () use (&$tokens): CryptoAuthToken { ++$tokens; return new CryptoAuthToken('10001', '123', 'SYNTHETIC_CAT', time()+600); },
            static function () use (&$active): void { if (!$active) throw new \LogicException('Owner stopped'); });
        $manager->prepare(); $active = false;
        foreach (['prepare', 'registrationRoster'] as $method) {
            try { $manager->$method(); self::fail('A retained device manager bypassed its owner guard'); }
            catch (\LogicException) {}
        }
        try { $manager->register(str_repeat('a', 64)); self::fail('A stopped owner started registration'); }
        catch (\LogicException) {}
        self::assertSame(0, $tokens); self::assertSame(0, $transport->calls);
    }

    private function manager(DeviceBindingTransport $transport): DeviceManager
    {
        return new DeviceManager($this->store, '10001', $transport, static fn(): CryptoAuthToken => new CryptoAuthToken('10001', '123', 'SYNTHETIC_CAT', time()+600));
    }
}

final class DeviceBindingTransport implements RegistrationTransport
{
    public int $calls = 0;
    public function request(string $operation, array $form): HttpResponse
    {
        ++$this->calls;
        return new HttpResponse(200, $operation === 'fetch'
            ? '{"status":200,"icdc_seq":0,"device_identities":[]}'
            : '{"status":200,"product":"msgr","wa_device_id":7,"icdc_success":true}');
    }
}
