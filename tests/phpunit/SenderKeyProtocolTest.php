<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Crypto\XEdDsa;
use MVO\Signal\{SenderKeyCodec,SenderKeyProtocol,SignalStore};

final class SenderKeyProtocolTest extends TestCase
{
    private string $directory;
    private array $stores = [];
    private const GROUP = '1203631234567890@g.us';
    private const ALICE = '5511999990001:2@s.whatsapp.net';
    private const BOB = '8888888888:3@lid';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/mvo-sender-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
    }
    protected function tearDown(): void
    {
        foreach ($this->stores as $store) $store->close();
        $this->stores = []; gc_collect_cycles();
        foreach (glob($this->directory.'/*') ?: [] as $file) unlink($file);
        rmdir($this->directory);
    }
    private function store(string $name): SignalStore
    {
        return $this->stores[] = new SignalStore($this->directory.'/'.$name.'.sqlite');
    }

    public function testDecryptsIndependentOpenSslAndEd25519WireVector(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../fixtures/sender-key-vector.json'), true, 32, JSON_THROW_ON_ERROR);
        $derived = SenderKeyProtocol::derive(hex2bin($fixture['chain']));
        self::assertSame($fixture['seed0'], bin2hex($derived['seed']));
        self::assertSame($fixture['next0'], bin2hex($derived['next']));
        $receiver = new SenderKeyProtocol($this->store('vector'));
        $receiver->processDistribution(self::GROUP, self::ALICE, hex2bin($fixture['distribution']));
        self::assertSame(hex2bin($fixture['plaintext']), $receiver->decrypt(self::GROUP, self::ALICE, hex2bin($fixture['message'])));
    }

    public function testTwoSendersGroupOutOfOrderKeysAndRestart(): void
    {
        $a = new SenderKeyProtocol($this->store('alice')); $b = new SenderKeyProtocol($this->store('bob'));
        $receiverStore = $this->store('receiver'); $r = new SenderKeyProtocol($receiverStore);
        $r->processDistribution(self::GROUP, self::ALICE, $a->createDistribution(self::GROUP, self::ALICE));
        $r->processDistribution(self::GROUP, self::BOB, $b->createDistribution(self::GROUP, self::BOB));
        $messages = [];
        for ($i=0;$i<7;$i++) $messages[] = $a->encrypt(self::GROUP, self::ALICE, 'A'.$i);
        self::assertSame('B0', $r->decrypt(self::GROUP, self::BOB, $b->encrypt(self::GROUP, self::BOB, 'B0')));
        self::assertSame('A6', $r->decrypt(self::GROUP, self::ALICE, $messages[6]));
        $receiverStore->close(); $r = new SenderKeyProtocol($this->store('receiver'));
        foreach ([1,3,0,5,2,4] as $i) self::assertSame('A'.$i, $r->decrypt(self::GROUP, self::ALICE, $messages[$i]));
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('replayed');
        $r->decrypt(self::GROUP, self::ALICE, $messages[0]);
    }

    public function testTamperingDoesNotAdvanceChain(): void
    {
        $a = new SenderKeyProtocol($this->store('alice')); $r = new SenderKeyProtocol($this->store('receiver'));
        $r->processDistribution(self::GROUP, self::ALICE, $a->createDistribution(self::GROUP, self::ALICE));
        $message = $a->encrypt(self::GROUP, self::ALICE, 'Autêntica');
        for ($i=0;$i<strlen($message);$i++) {
            $tampered = $message; $tampered[$i] = $tampered[$i]^"\x01";
            try { $r->decrypt(self::GROUP, self::ALICE, $tampered); self::fail('Tampered byte '.$i.' accepted'); }
            catch (\UnexpectedValueException) { /* Original remains decryptable. */ }
        }
        self::assertSame('Autêntica', $r->decrypt(self::GROUP, self::ALICE, $message));
    }

    public function testAuthenticatedInvalidCbcPaddingDoesNotAdvanceChain(): void
    {
        $aStore = $this->store('alice'); $a = new SenderKeyProtocol($aStore); $r = new SenderKeyProtocol($this->store('receiver'));
        $distribution = $a->createDistribution(self::GROUP, self::ALICE); $r->processDistribution(self::GROUP, self::ALICE, $distribution);
        $key = hash('sha256', self::GROUP."\0".self::ALICE);
        $state = \MVO\Signal\SignalState::unpack(json_decode($aStore->get('sender-key', $key), true, 32, JSON_THROW_ON_ERROR))[0];
        $seed = SenderKeyProtocol::derive($state['chain'])['seed'];
        $ciphertext = openssl_encrypt(str_repeat("\0", 16), 'aes-256-cbc', substr($seed, 16, 32), OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING, substr($seed, 0, 16));
        $body = SenderKeyCodec::message($state['id'], 0, $ciphertext);
        $bad = $body.XEdDsa::sign($state['private'], $body);
        try { $r->decrypt(self::GROUP, self::ALICE, $bad); self::fail('Authenticated invalid CBC padding accepted'); }
        catch (\UnexpectedValueException $error) { self::assertStringContainsString('padding', $error->getMessage()); }
        self::assertSame('Normal', $r->decrypt(self::GROUP, self::ALICE, $a->encrypt(self::GROUP, self::ALICE, 'Normal')));
    }

    public function testNewerDistributionKeepsSkippedQueuedMessages(): void
    {
        $a = new SenderKeyProtocol($this->store('alice')); $r = new SenderKeyProtocol($this->store('receiver'));
        $r->processDistribution(self::GROUP, self::ALICE, $a->createDistribution(self::GROUP, self::ALICE));
        $messages = [];
        for ($i=0;$i<3;$i++) $messages[] = $a->encrypt(self::GROUP, self::ALICE, (string)$i);
        $r->processDistribution(self::GROUP, self::ALICE, $a->createDistribution(self::GROUP, self::ALICE));
        foreach ([2,0,1] as $i) self::assertSame((string)$i, $r->decrypt(self::GROUP, self::ALICE, $messages[$i]));
    }

    public function testOldDistributionCannotResetReplayProtection(): void
    {
        $a = new SenderKeyProtocol($this->store('alice')); $r = new SenderKeyProtocol($this->store('receiver'));
        $distribution = $a->createDistribution(self::GROUP, self::ALICE);
        $r->processDistribution(self::GROUP, self::ALICE, $distribution);
        $message = $a->encrypt(self::GROUP, self::ALICE, 'Uma vez');
        self::assertSame('Uma vez', $r->decrypt(self::GROUP, self::ALICE, $message));
        $r->processDistribution(self::GROUP, self::ALICE, $distribution);
        $this->expectException(\UnexpectedValueException::class);
        $r->decrypt(self::GROUP, self::ALICE, $message);
    }

    public function testRotationPreservesDelayedMessagesAndIsolatesGroupsAndDevices(): void
    {
        $a = new SenderKeyProtocol($this->store('alice')); $r = new SenderKeyProtocol($this->store('receiver'));
        $r->processDistribution(self::GROUP, self::ALICE, $a->createDistribution(self::GROUP, self::ALICE));
        $delayed = $a->encrypt(self::GROUP, self::ALICE, 'Atrasada');
        $a->rotate(self::GROUP, self::ALICE);
        $r->processDistribution(self::GROUP, self::ALICE, $a->createDistribution(self::GROUP, self::ALICE));
        $next = $a->encrypt(self::GROUP, self::ALICE, 'Nova chave');
        self::assertSame('Nova chave', $r->decrypt(self::GROUP, self::ALICE, $next));
        self::assertSame('Atrasada', $r->decrypt(self::GROUP, self::ALICE, $delayed));
        foreach ([['120363999@g.us',self::ALICE],[self::GROUP,'5511999990001:1@s.whatsapp.net'],[self::GROUP,self::BOB]] as [$group,$sender]) {
            try { $r->decrypt($group, $sender, $next); self::fail('Wrong group/sender accepted'); }
            catch (\UnexpectedValueException $error) { self::assertStringContainsString('not found', $error->getMessage()); }
        }
    }

    public function testFailedOuterTransactionRollsBackEncryptionCounter(): void
    {
        $store = $this->store('alice'); $a = new SenderKeyProtocol($store);
        $distribution = $a->createDistribution(self::GROUP, self::ALICE);
        try { $store->transaction(function () use ($a):void { $a->encrypt(self::GROUP, self::ALICE, 'Not persisted'); throw new \RuntimeException('outbox failure'); }); }
        catch (\RuntimeException) { self::assertSame($distribution, $a->createDistribution(self::GROUP, self::ALICE)); }
        self::assertSame(0, SenderKeyCodec::readMessage($a->encrypt(self::GROUP, self::ALICE, 'Persisted'))['iteration']);
    }

    public function testFutureCounterIsBoundedAndSignedBeforeSelection(): void
    {
        $aStore = $this->store('alice'); $a = new SenderKeyProtocol($aStore); $r = new SenderKeyProtocol($this->store('receiver'));
        $distribution = $a->createDistribution(self::GROUP, self::ALICE); $r->processDistribution(self::GROUP, self::ALICE, $distribution);
        $key = hash('sha256', self::GROUP."\0".self::ALICE);
        $state = \MVO\Signal\SignalState::unpack(json_decode($aStore->get('sender-key', $key), true, 32, JSON_THROW_ON_ERROR))[0];
        $body = SenderKeyCodec::message($state['id'], 20001, str_repeat("\0", 16));
        $far = $body.XEdDsa::sign($state['private'], $body);
        try { $r->decrypt(self::GROUP, self::ALICE, $far); self::fail('Unbounded future iteration accepted'); }
        catch (\UnexpectedValueException $error) { self::assertStringContainsString('future', $error->getMessage()); }
        self::assertSame('Normal', $r->decrypt(self::GROUP, self::ALICE, $a->encrypt(self::GROUP, self::ALICE, 'Normal')));
    }
}
