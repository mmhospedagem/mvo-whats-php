<?php
declare(strict_types=1);
namespace MVO\Messenger\Tests;

use MVO\Messenger\Auth\{CookieJar,SessionStore};
use MVO\Messenger\E2ee\{CryptoAuthToken,DeviceKeys,DeviceManager,IdentityRoster,ProtoWire,RegistrationTransport,XEdDsa};
use MVO\Messenger\MessengerException;
use MVO\Messenger\Protocol\BootstrapConfig;
use MVO\Messenger\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

final class E2eeDeviceTest extends TestCase
{
    private string $directory;
    private ?SessionStore $store = null;
    protected function setUp(): void
    {
        if (!extension_loaded('gmp')) self::markTestSkipped('Optional Messenger device signatures require GMP');
        $this->directory = sys_get_temp_dir() . '/mvo-e2ee-' . bin2hex(random_bytes(8));
        $this->store = new SessionStore($this->directory, 'test');
        $this->store->acquire();
        $this->store->saveCookies(new CookieJar([['name' => 'c_user', 'value' => '100000000001'], ['name' => 'xs', 'value' => 'synthetic-test-session']]));
    }
    protected function tearDown(): void
    {
        $this->store?->release(); $this->store = null; gc_collect_cycles();
        if (!isset($this->directory) || !is_dir($this->directory)) return;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->directory);
    }

    public function testSignatureMatchesIndependentSignalVectorWithoutWhatsappAutoload(): void
    {
        $private = str_repeat("\0", 32); $private[8] = "\xbd";
        // Signal curve25519-java internal_fast_tests.c curvesigs_fast_test().
        $signature = XEdDsa::sign($private, str_repeat("\0", 200), str_repeat("\0", 64));
        self::assertSame('cf873d0379ac20e8893e5567ee0f8951f8db840d26b243b463526689d01ca718ac189fb1678574ebdde569330659448b0bd6c1973f7d780ab39518626803d782', bin2hex($signature));
        self::assertTrue(XEdDsa::verify(sodium_crypto_scalarmult_base($private), str_repeat("\0", 200), $signature));
        self::assertFalse(XEdDsa::verify(sodium_crypto_scalarmult_base($private), 'altered', $signature));
    }

    public function testDevicePersistsSameIdentityWithIndependentKeysAndRedactedDiagnostics(): void
    {
        $keys = DeviceKeys::generate('100000000001'); $private = $keys->exportPrivateJson();
        $saved = json_decode($private, true, 16, JSON_THROW_ON_ERROR);
        self::assertNotSame($saved['identity_private'], $saved['noise_private']);
        self::assertNotSame($saved['identity_private'], $saved['signed_prekey_private']);
        $restored = DeviceKeys::fromPrivateJson($private, '100000000001');
        self::assertSame($keys->identityPublic(), $restored->identityPublic());
        self::assertSame($keys->uuid, $restored->uuid);
        self::assertSame($private, $restored->exportPrivateJson());
        $safe = json_encode($keys, JSON_THROW_ON_ERROR) . print_r($keys, true);
        foreach (['identity_private', 'noise_private', 'signed_prekey_private', 'signed_prekey_signature'] as $name) self::assertStringNotContainsString($saved[$name], $safe);
    }

    public function testRestoringDeviceUnderAnotherAccountFails(): void
    {
        $keys = DeviceKeys::generate('100000000001');
        $this->expectException(MessengerException::class);
        DeviceKeys::fromPrivateJson($keys->exportPrivateJson(), '100000000002');
    }

    public function testPrivateObjectsCannotEnterSerializedJobsAccidentally(): void
    {
        foreach ([DeviceKeys::generate('100000000001'), new CryptoAuthToken('100000000001', '123', 'synthetic-private-cat', time() + 600)] as $object) {
            try { serialize($object); self::fail('Private object was serialized'); } catch (\LogicException) { self::assertTrue(true); }
        }
    }

    public function testManagerRejectsDifferentAccountBeforeReadingDeviceState(): void
    {
        $this->expectException(MessengerException::class);
        new DeviceManager($this->store, '100000000002', new E2eeFixtureTransport([]), static fn(): CryptoAuthToken => new CryptoAuthToken('100000000002', '123', 'synthetic-cat', time() + 600));
    }

    public function testChangedSavedPrekeySignatureFailsClosed(): void
    {
        $keys = DeviceKeys::generate('100000000001'); $data = json_decode($keys->exportPrivateJson(), true, 16, JSON_THROW_ON_ERROR);
        $data['signed_prekey_signature'] = base64_encode(str_repeat('x', 64));
        $this->expectException(MessengerException::class);
        DeviceKeys::fromPrivateJson(json_encode($data, JSON_THROW_ON_ERROR), '100000000001');
    }

    public function testIcdcFieldsMatchWireSpecificationAndSignExactOrderedList(): void
    {
        $keys = DeviceKeys::generate('100000000001'); $other = sodium_crypto_scalarmult_base(str_repeat('b', 32));
        $fields = $keys->registrationFields(new IdentityRoster(3, [$other]), 1700000000);
        self::assertSame(pack('N', $keys->registrationId), base64_decode($fields['e_regid'], true));
        self::assertSame("\x05", base64_decode($fields['e_keytype'], true));
        self::assertSame("\0\0\x01", base64_decode($fields['e_skey_id'], true));
        self::assertTrue(XEdDsa::verify($keys->identityPublic(), "\x05" . base64_decode($fields['e_skey_val'], true), base64_decode($fields['e_skey_sig'], true)));
        $signed = ProtoWire::decode(base64_decode($fields['icdc_list'], true)); $details = $signed[1][0];
        self::assertIsString($details); self::assertTrue(XEdDsa::verify($keys->identityPublic(), $details, $signed[2][0]));
        self::assertSame([1 => [4], 2 => [1700000000], 3 => [$other, $keys->identityPublic()], 4 => [1]], ProtoWire::decode($details));
        $identities = [$other, $keys->identityPublic()]; sort($identities, SORT_STRING);
        self::assertSame(base64_encode(substr(hash('sha256', implode('', $identities), true), 0, 10)), $fields['ihash']);
        $present = (new IdentityRoster(4, [$other, $keys->identityPublic()]))->append($keys->identityPublic(), 1700000001);
        self::assertSame(4, $present['sequence']); self::assertSame(1, $present['signing_index']);
    }

    public function testIcdcEmptyListHasIndependentProtobufFixture(): void
    {
        $identity = str_repeat('i', 32); $value = (new IdentityRoster(0, []))->append($identity, 1);
        self::assertSame('080110011a20' . bin2hex($identity) . '2000', bin2hex($value['details']));
    }

    public function testMalformedAndDuplicateRosterRejected(): void
    {
        foreach (['+/', str_repeat('A', 43), str_repeat('x', 44)] as $encoded) {
            try { IdentityRoster::fromResponse(['status' => 200, 'icdc_seq' => 1, 'device_identities' => [$encoded]]); self::fail('Malformed key accepted'); }
            catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
        $this->expectException(\UnexpectedValueException::class); new IdentityRoster(1, [str_repeat('x', 32), str_repeat('x', 32)]);
    }

    public function testCatUsesMessengerAppAndExpirationAndNeverAppearsInDiagnostics(): void
    {
        $bootstrap = new BootstrapConfig('100000000001', '99', 'public-device', null, '1', 'https://www.facebook.com',
            ['MessengerWebInitData' => ['appId' => '123', 'cryptoAuthToken' => ['encryptedSerializedCat' => 'synthetic-private-cat', 'expirationTimeInSeconds' => '2000000000']]]);
        $cat = CryptoAuthToken::fromBootstrap($bootstrap);
        self::assertSame('123', $cat->privateForm('100000000001', 'uuid', 100)['app_id']);
        self::assertStringNotContainsString('synthetic-private-cat', json_encode($cat, JSON_THROW_ON_ERROR) . print_r($cat, true));
        $this->expectException(MessengerException::class); $cat->privateForm('100000000001', 'uuid', 2000000000);
    }

    public function testExpiredOrWrongAccountCatDoesNotCauseIo(): void
    {
        $transport = new E2eeFixtureTransport([]); $manager = $this->manager($transport, new CryptoAuthToken('100000000001', '123', 'synthetic-cat', 1));
        try { $manager->registrationRoster(); self::fail('Expired CAT accepted'); } catch (MessengerException $error) { self::assertSame('messenger_cat_expired', $error->reason); }
        self::assertCount(0, $transport->calls);
        $this->expectException(MessengerException::class); (new CryptoAuthToken('100000000002', '123', 'synthetic-cat', time() + 600))->privateForm('100000000001', 'uuid');
    }

    public function testPrepareIsLocalAndPreservesIdentityAcrossManagerRecreation(): void
    {
        $transport = new E2eeFixtureTransport([]); $manager = $this->manager($transport);
        self::assertSame('uninitialized', $manager->status()['state']); self::assertSame('keys_prepared', $manager->prepare()['state']);
        $saved = $this->store->get('e2ee.device.keys.v1');
        $this->manager($transport)->prepare(); self::assertSame($saved, $this->store->get('e2ee.device.keys.v1')); self::assertCount(0, $transport->calls);
        self::assertFalse($manager->status()['e2ee_ready']);
    }

    public function testRegistrationRequiresReviewedRosterAndConfirmsOnlyDeviceNotMessaging(): void
    {
        $transport = new E2eeFixtureTransport([$this->roster(), $this->roster(), new HttpResponse(200, '{"status":200,"product":"msgr","wa_device_id":7,"icdc_success":true}')]);
        $manager = $this->manager($transport); $snapshot = $manager->registrationRoster(); $status = $manager->register($snapshot['snapshot_hash']);
        self::assertTrue($status['registration_confirmed']); self::assertSame('100000000001:7@msgr', $status['device_address']);
        self::assertFalse($status['e2ee_ready']); self::assertFalse($status['encrypted_send_available']);
        self::assertSame(['fetch', 'fetch', 'register'], array_column($transport->calls, 'operation'));
        self::assertSame('100000000001', $transport->calls[2]['form']['fbid']);
        self::assertSame($status, $manager->register($snapshot['snapshot_hash'])); self::assertCount(3, $transport->calls);
        self::assertSame($status, $this->manager(new E2eeFixtureTransport([]))->status());
        self::assertStringNotContainsString('synthetic-cat', $this->store->get('e2ee.device.registration.v1'));
        self::assertSame([], $this->store->events());
    }

    public function testChangedRosterBlocksRegisterBeforeStateChangingRequest(): void
    {
        $transport = new E2eeFixtureTransport([$this->roster(1)]); $manager = $this->manager($transport);
        try { $manager->register((new IdentityRoster(0, []))->snapshotHash()); self::fail('Changed roster accepted'); }
        catch (MessengerException $error) { self::assertSame('messenger_identity_roster_changed', $error->reason); }
        self::assertSame(['fetch'], array_column($transport->calls, 'operation'));
        self::assertFalse($manager->status()['registration_confirmed']);
    }

    public function testInterruptedRegistrationPersistsUncertaintyAndNeverRetriesOrRegenerates(): void
    {
        $transport = new E2eeFixtureTransport([$this->roster(), new \RuntimeException('private-network-message')]);
        $manager = $this->manager($transport); $hash = (new IdentityRoster(0, []))->snapshotHash();
        try { $manager->register($hash); self::fail('Uncertain registration accepted'); }
        catch (MessengerException $error) { self::assertSame('messenger_device_registration_uncertain', $error->reason); self::assertStringNotContainsString('private-network-message', $error->getMessage()); }
        $saved = $this->store->get('e2ee.device.keys.v1');
        try { $this->manager($transport)->register($hash); self::fail('Uncertain registration retried'); }
        catch (MessengerException $error) { self::assertSame('messenger_device_registration_uncertain', $error->reason); }
        self::assertCount(2, $transport->calls); self::assertSame($saved, $this->store->get('e2ee.device.keys.v1'));
        self::assertFalse($manager->status()['registration_confirmed']);
    }

    public function testHttpSuccessWithoutProductOrInnerConfirmationIsUncertain(): void
    {
        $transport = new E2eeFixtureTransport([$this->roster(), new HttpResponse(200, '{"wa_device_id":7}')]);
        $manager = $this->manager($transport);
        try { $manager->register((new IdentityRoster(0, []))->snapshotHash()); self::fail('Incomplete confirmation accepted'); }
        catch (MessengerException $error) { self::assertSame('messenger_device_registration_uncertain', $error->reason); }
        self::assertSame('registration_uncertain', $manager->status()['state']);
    }

    public function testKnownRegistrationRejectionIsRecordedWithoutPretendingReady(): void
    {
        $transport = new E2eeFixtureTransport([$this->roster(), new HttpResponse(200, '{"status":403}')]); $manager = $this->manager($transport);
        try { $manager->register((new IdentityRoster(0, []))->snapshotHash()); self::fail('Rejected registration accepted'); }
        catch (MessengerException $error) { self::assertSame('messenger_device_registration_rejected', $error->reason); }
        self::assertSame('registration_rejected', $manager->status()['state']); self::assertFalse($manager->status()['e2ee_ready']);
    }

    public function testReleasedOwnerCannotPrepareOrReadRemoteRoster(): void
    {
        $transport = new E2eeFixtureTransport([]); $manager = $this->manager($transport); $this->store->release();
        $this->expectException(\LogicException::class); $manager->prepare();
    }

    public function testConflictingOwnerCannotProvisionTheSameSession(): void
    {
        $other = new SessionStore($this->directory, 'test');
        $this->expectException(\RuntimeException::class); $other->acquire();
    }

    public function testProtoRejectsTruncationAndOversizedVarints(): void
    {
        foreach (["\x0a\x02x", "\x80", "\x00", "\x08" . str_repeat("\x80", 10)] as $wire) {
            try { ProtoWire::decode($wire); self::fail('Malformed protobuf accepted'); } catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
    }

    private function roster(int $sequence = 0): HttpResponse { return new HttpResponse(200, json_encode(['status' => 200, 'icdc_seq' => $sequence, 'device_identities' => []], JSON_THROW_ON_ERROR)); }
    private function manager(E2eeFixtureTransport $transport, ?CryptoAuthToken $token = null): DeviceManager
    {
        return new DeviceManager($this->store, '100000000001', $transport, static fn(): CryptoAuthToken => $token ?? new CryptoAuthToken('100000000001', '123', 'synthetic-cat', time() + 600));
    }
}

final class E2eeFixtureTransport implements RegistrationTransport
{
    /** @var list<array{operation:string,form:array<string,string>}> */ public array $calls = [];
    /** @param list<HttpResponse|\Throwable> $responses */ public function __construct(private array $responses) {}
    public function request(string $operation, #[\SensitiveParameter] array $form): HttpResponse
    {
        $this->calls[] = ['operation' => $operation, 'form' => $form]; $next = array_shift($this->responses);
        if ($next instanceof \Throwable) throw $next;
        if (!$next instanceof HttpResponse) throw new \LogicException('Unexpected test I/O'); return $next;
    }
}
