<?php
declare(strict_types=1);

namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Auth\PairingFlow;
use MVO\Crypto\Crypto;
use MVO\Crypto\XEdDsa;
use MVO\Protocol\BinaryNode;
use MVO\Protocol\Protobuf;

final class PairingFlowTest extends TestCase
{
    private array $credentials;
    private array $sent = [];
    private array $events = [];
    private array $queries = [];
    private int $saves = 0;

    protected function setUp(): void
    {
        $this->credentials = ['noiseKeyPair' => Crypto::x25519KeyPair(), 'identityKeyPair' => Crypto::x25519KeyPair(), 'advSecretKey' => random_bytes(32), 'registered' => false, 'meJid' => null];
    }

    private function flow(?\Closure $query = null, ?\Closure $save = null): PairingFlow
    {
        return new PairingFlow(
            fn() => $this->credentials,
            $save ?? function (array $credentials): void { $this->credentials = $credentials; ++$this->saves; },
            function (BinaryNode $node): void { $this->sent[] = $node; },
            $query ?? function (BinaryNode $node, float $timeout): BinaryNode {
                $this->queries[] = $node;
                self::assertSame(30.0, $timeout);
                return new BinaryNode('iq', ['type' => 'result'], [new BinaryNode('link_code_companion_reg', [], [new BinaryNode('link_code_pairing_ref', [], 'code-ref')])]);
            },
            function (string $event, array $payload): void { $this->events[] = [$event, $payload]; },
        );
    }

    public function testQrPayloadRotatesRefsAndAdvSecretBeforeAcknowledgment(): void
    {
        $flow = $this->flow();
        $oldSecret = $this->credentials['advSecretKey'];
        $start = microtime(true);
        self::assertTrue($flow->handleNode(new BinaryNode('iq', ['id' => 'pair-1', 'type' => 'set', 'from' => 's.whatsapp.net'], [
            new BinaryNode('pair-device', [], array_map(fn(int $i) => new BinaryNode('ref', [], 'ref-' . $i), range(1, 6))),
        ])));
        self::assertNotSame($oldSecret, $this->credentials['advSecretKey']);
        self::assertSame(1, $this->saves);
        self::assertSame(['to' => 's.whatsapp.net', 'type' => 'result', 'id' => 'pair-1'], $this->sent[0]->attrs);
        self::assertSame('auth_qr', $this->events[0][0]);
        $parts = explode(',', $this->events[0][1]['qr']);
        self::assertSame(['ref-1', base64_encode($this->credentials['noiseKeyPair']['public']), base64_encode($this->credentials['identityKeyPair']['public']), base64_encode($this->credentials['advSecretKey']), '1'], $parts);
        self::assertSame(60, $this->events[0][1]['expiresIn']);
        $flow->tick($start + 59);
        self::assertCount(1, $this->events);
        $flow->tick($start + 61);
        self::assertStringStartsWith('ref-2,', $this->events[1][1]['qr']);
        self::assertSame(20, $this->events[1][1]['expiresIn']);
        $flow->clear();
        $flow->tick($start + 1000);
        self::assertCount(2, $this->events);
    }

    public function testPersistenceFailureDoesNotAcknowledgePairDevice(): void
    {
        $flow = $this->flow(save: static function (array $credentials): void { throw new \RuntimeException('Disk full'); });
        try {
            $flow->handleNode(new BinaryNode('iq', ['type' => 'set'], [new BinaryNode('pair-device', [], [new BinaryNode('ref', [], 'ref')])]));
            self::fail('Expected failure');
        } catch (\RuntimeException $error) {
            self::assertSame('Disk full', $error->getMessage());
        }
        self::assertSame([], $this->sent);
        self::assertSame([], $this->events);
    }

    public function testValidPairSuccessSignsAndCommitsIdentity(): void
    {
        $flow = $this->flow();
        $node = $this->pairSuccess();
        self::assertTrue($flow->handleNode($node));
        self::assertTrue($this->credentials['registered']);
        self::assertSame('5511999999999:7@s.whatsapp.net', $this->credentials['meJid']);
        self::assertSame('1234:7@lid', $this->credentials['meLid']);
        self::assertSame('android', $this->credentials['platform']);
        $identity = $this->credentials['signedIdentity'];
        self::assertTrue(XEdDsa::verify($this->credentials['identityKeyPair']['public'], "\x06\x01" . $identity['details'] . $this->credentials['identityKeyPair']['public'] . $identity['accountSignatureKey'], $identity['deviceSignature']));
        $response = $this->sent[0]->child('pair-device-sign')->child('device-identity');
        self::assertSame('19', $response->attrs['key-index']);
        self::assertSame([1, 3, 4], array_column(Protobuf::decode($response->content), 'number'));
        self::assertSame('auth_paired', $this->events[0][0]);
        $flow->handleNode($node);
        self::assertCount(1, $this->sent);
        self::assertSame(1, $this->saves);
    }

    public function testHostedPairSuccessUsesHostedPrefixes(): void
    {
        $flow = $this->flow();
        self::assertTrue($flow->handleNode($this->pairSuccess(hosted: true)));
        self::assertTrue($this->credentials['registered']);
    }

    public function testBadHmacCannotRegisterOrSaveAndReceivesNotAuthorized(): void
    {
        $this->assertInvalidPairSuccess($this->pairSuccess(badHmac: true), 'HMAC');
    }

    public function testBadAccountSignatureCannotRegisterEvenWithValidHmac(): void
    {
        $this->assertInvalidPairSuccess($this->pairSuccess(badSignature: true), 'signature');
    }

    public function testPairSuccessIdentityBoundIsEnforced(): void
    {
        $flow = $this->flow();
        $this->expectException(\LengthException::class);
        try {
            $flow->handleNode(new BinaryNode('iq', ['type' => 'set'], [new BinaryNode('pair-success', [], [new BinaryNode('device-identity', [], str_repeat('a', 501))])]));
        } finally {
            self::assertSame(0, $this->saves);
            self::assertSame('401', $this->sent[0]->child('error')->attrs['code']);
        }
    }

    public function testPairingCodeHelloIncludesCorrectWireFieldsAndNormalizesPhone(): void
    {
        $flow = $this->flow();
        self::assertSame('ABCD1234', $flow->requestCode('+55 (11) 99999-9999', 'abcd-1234'));
        $hello = $this->queries[0]->child('link_code_companion_reg');
        self::assertSame('5511999999999@s.whatsapp.net', $hello->attrs['jid']);
        self::assertSame('companion_hello', $hello->attrs['stage']);
        self::assertSame('true', $hello->attrs['should_show_push_notification']);
        self::assertSame('', $hello->child('link_code_pairing_nonce')->content);
        self::assertSame($this->credentials['noiseKeyPair']['public'], $hello->child('companion_server_auth_key_pub')->content);
        self::assertSame(['auth_code', ['code' => 'ABCD1234']], $this->events[0]);
        $flow->handleNode($this->notification('refresh_code', 'unrelated'));
        self::assertCount(1, $this->events);
        $flow->handleNode($this->notification('refresh_code', 'code-ref'));
        self::assertSame(['auth_code_refresh', ['forceManual' => false]], $this->events[1]);
    }

    public function testCodeExpiryPreventsCompanionFinish(): void
    {
        $flow = $this->flow();
        $flow->requestCode('5511999999999', 'ABCD1234');
        $flow->tick(microtime(true) + 181);
        self::assertSame(['auth_code_expired', []], $this->events[1]);
        $flow->handleNode($this->notification('primary_hello', 'code-ref'));
        self::assertCount(1, $this->queries);
    }

    public function testCompanionFinishCanBeDecodedByPrimaryAndAttemptsAreBounded(): void
    {
        $primary = Crypto::x25519KeyPair();
        $primaryIdentity = Crypto::x25519KeyPair();
        $companionPublic = '';
        $query = function (BinaryNode $node, float $timeout) use ($primary, $primaryIdentity, &$companionPublic): BinaryNode {
            $this->queries[] = $node;
            $link = $node->child('link_code_companion_reg');
            if ($link->attrs['stage'] === 'companion_hello') {
                $wrapped = $link->child('link_code_pairing_wrapped_companion_ephemeral_pub')->content;
                $key = hash_pbkdf2('sha256', 'ABCD1234', substr($wrapped, 0, 32), 131072, 32, true);
                $companionPublic = openssl_decrypt(substr($wrapped, 48), 'aes-256-ctr', $key, OPENSSL_RAW_DATA, substr($wrapped, 32, 16));
                return new BinaryNode('iq', ['type' => 'result'], [new BinaryNode('link_code_companion_reg', [], [new BinaryNode('link_code_pairing_ref', [], 'code-ref')])]);
            }
            self::assertSame('companion_finish', $link->attrs['stage']);
            $wrapped = $link->child('link_code_pairing_wrapped_key_bundle')->content;
            $sharedEphemeral = sodium_crypto_scalarmult($primary['private'], $companionPublic);
            $key = hash_hkdf('sha256', $sharedEphemeral, 32, 'link_code_pairing_key_bundle_encryption_key', substr($wrapped, 0, 32));
            $plain = Crypto::decryptGcm($key, substr($wrapped, 32, 12), substr($wrapped, 44));
            self::assertSame($this->credentials['identityKeyPair']['public'] . $primaryIdentity['public'], substr($plain, 0, 64));
            $sharedIdentity = sodium_crypto_scalarmult($primaryIdentity['private'], $this->credentials['identityKeyPair']['public']);
            self::assertSame(hash_hkdf('sha256', $sharedEphemeral . $sharedIdentity . substr($plain, 64), 32, 'adv_secret'), $this->credentials['advSecretKey']);
            return new BinaryNode('iq', ['type' => 'result']);
        };
        $flow = $this->flow($query);
        $flow->requestCode('5511999999999', 'ABCD1234');
        $salt = random_bytes(32);
        $counter = random_bytes(16);
        $key = hash_pbkdf2('sha256', 'ABCD1234', $salt, 131072, 32, true);
        $wrapped = $salt . $counter . openssl_encrypt($primary['public'], 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $counter);
        $notify = $this->notification('primary_hello', 'code-ref', [new BinaryNode('link_code_pairing_wrapped_primary_ephemeral_pub', [], $wrapped), new BinaryNode('primary_identity_pub', [], $primaryIdentity['public'])]);
        for ($i = 0; $i < 3; ++$i) self::assertTrue($flow->handleNode($notify));
        self::assertCount(4, $this->queries);
        self::assertFalse($this->credentials['registered']);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('exceeded three');
        $flow->handleNode($notify);
    }

    public function testRejectedHelloDoesNotEmitCode(): void
    {
        $flow = $this->flow(static fn(BinaryNode $node, float $timeout) => new BinaryNode('iq', ['type' => 'error']));
        try {
            $flow->requestCode('5511999999999', 'ABCD1234');
            self::fail('Expected server rejection');
        } catch (\UnexpectedValueException $error) {
            self::assertStringContainsString('companion_hello', $error->getMessage());
        }
        self::assertSame([], $this->events);
    }

    public function testInvalidPhoneDoesNotSendOrModifyCredentials(): void
    {
        $flow = $this->flow();
        try {
            $flow->requestCode('abc5511999999999');
            self::fail('Invalid phone accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->queries);
            self::assertSame(0, $this->saves);
        }
    }

    private function assertInvalidPairSuccess(BinaryNode $node, string $reason): void
    {
        $before = $this->credentials;
        try {
            $this->flow()->handleNode($node);
            self::fail('Invalid pairing was accepted');
        } catch (\UnexpectedValueException $error) {
            self::assertStringContainsString($reason, $error->getMessage());
        }
        self::assertSame($before, $this->credentials);
        self::assertSame(0, $this->saves);
        self::assertSame([], $this->events);
        self::assertSame('401', $this->sent[0]->child('error')->attrs['code']);
        self::assertSame('not-authorized', $this->sent[0]->child('error')->attrs['text']);
    }

    private function pairSuccess(bool $badHmac = false, bool $badSignature = false, bool $hosted = false): BinaryNode
    {
        $account = Crypto::x25519KeyPair();
        $details = Protobuf::encode([['number' => 1, 'wire' => 0, 'value' => 9], ['number' => 3, 'wire' => 0, 'value' => 19], ['number' => 5, 'wire' => 0, 'value' => $hosted ? 1 : 0]]);
        $signature = XEdDsa::sign($account['private'], ($hosted ? "\x06\x05" : "\x06\0") . $details . $this->credentials['identityKeyPair']['public']);
        if ($badSignature) $signature[0] = $signature[0] ^ "\x01";
        $identity = Protobuf::encode([['number' => 1, 'wire' => 2, 'value' => $details], ['number' => 2, 'wire' => 2, 'value' => $account['public']], ['number' => 3, 'wire' => 2, 'value' => $signature]]);
        $hmac = Crypto::hmac($this->credentials['advSecretKey'], ($hosted ? "\x06\x05" : '') . $identity);
        if ($badHmac) $hmac[0] = $hmac[0] ^ "\x01";
        $wrapped = Protobuf::encode([['number' => 1, 'wire' => 2, 'value' => $identity], ['number' => 2, 'wire' => 2, 'value' => $hmac], ['number' => 3, 'wire' => 0, 'value' => $hosted ? 1 : 0]]);
        return new BinaryNode('iq', ['id' => 'pair-success-1', 'from' => 's.whatsapp.net', 'type' => 'set'], [new BinaryNode('pair-success', [], [
            new BinaryNode('device-identity', [], $wrapped), new BinaryNode('device', ['jid' => '5511999999999:7@s.whatsapp.net', 'lid' => '1234:7@lid']), new BinaryNode('platform', ['name' => 'android']),
        ])]);
    }

    private function notification(string $stage, string $ref, array $children = []): BinaryNode
    {
        return new BinaryNode('notification', ['id' => 'code-n1', 'type' => 'link_code_companion_reg', 'from' => 's.whatsapp.net'], [
            new BinaryNode('link_code_companion_reg', ['stage' => $stage], [new BinaryNode('link_code_pairing_ref', [], $ref), ...$children]),
        ]);
    }
}
