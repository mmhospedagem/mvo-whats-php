<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Auth\CredentialStore;
use MVO\Message\{GroupTransport,MessageCodec};
use MVO\Protocol\{BinaryNode,Jid};
use MVO\Signal\{SenderKeyCodec,SenderKeyProtocol,SignalProtocol,SignalStore};

final class GroupTransportTest extends TestCase
{
    private string $directory;
    private array $stores = [];
    private array $signals = [];
    private array $credentials = [];
    private array $members = [];
    private array $devices = [];
    private string $mode = 'pn';
    private int $queries = 0;
    private const GROUP = '1203631234567@g.us';
    private const ALICE = '551199990001:2@s.whatsapp.net';
    private const BOB = '551199990002:3@s.whatsapp.net';
    private const CAROL = '551199990003:4@s.whatsapp.net';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/mvo-group-'.bin2hex(random_bytes(6)); mkdir($this->directory, 0700);
        foreach ([self::ALICE,self::BOB,self::CAROL] as $index=>$jid) {
            $credentials = CredentialStore::fresh();
            $credentials['registered'] = true; $credentials['meJid'] = $jid; $credentials['meLid'] = (string)new Jid('88888888000'.$index,'lid',Jid::parse($jid)->device);
            $credentials['signedIdentity'] = ['details'=>'d','accountSignatureKey'=>str_repeat('k',32),'accountSignature'=>str_repeat('a',64),'deviceSignature'=>str_repeat('s',64)];
            $this->credentials[$jid] = $credentials;
            $this->stores[$jid] = new SignalStore($this->directory.'/'.$index.'.sqlite');
            $this->signals[$jid] = new SignalProtocol($this->stores[$jid], $credentials);
            $this->devices[Jid::parse($jid)->bare()] = [$jid];
        }
        $this->members = [Jid::parse(self::ALICE)->bare(),Jid::parse(self::BOB)->bare()];
    }
    protected function tearDown(): void
    {
        foreach ($this->stores as $store) $store->close();
        $this->signals = $this->stores = []; gc_collect_cycles();
        foreach (glob($this->directory.'/*') ?: [] as $file) unlink($file);
        rmdir($this->directory);
    }
    private function transport(string $local, ?\Closure $query = null,?\Closure $discover=null,?\Closure $ensure=null): GroupTransport
    {
        return new GroupTransport($this->signals[$local], $this->stores[$local], $query ?? function (BinaryNode $request, float $timeout):BinaryNode {
            ++$this->queries;
            self::assertSame('w:g2', $request->attrs['xmlns']); self::assertSame(self::GROUP, $request->attrs['to']);
            self::assertNotNull($request->child('query'));
            return new BinaryNode('iq', ['type'=>'result'], [new BinaryNode('group', ['id'=>self::GROUP,'addressing_mode'=>$this->mode], array_map(static fn(string $jid):BinaryNode => new BinaryNode('participant', ['jid'=>$jid]), $this->members))]);
        }, $discover??fn(array $users):array => array_intersect_key($this->devices, array_flip($users)), $ensure??function (array $devices) use ($local):void {
            foreach ($devices as $device) {
                if ($this->signals[$local]->hasSession($device)) continue;
                $peer = $this->credentials[$device];
                $this->signals[$local]->ensureSession($device, ['registrationId'=>$peer['registrationId'],'identityKey'=>$peer['identityKeyPair']['public'], 'signedPreKey'=>array_intersect_key($peer['signedPreKey'], array_flip(['id','public','signature']))]);
            }
        }, fn():array => $this->credentials[$local]);
    }
    public function testGroupStatusMetadataFailureHasSafeTypedDiagnosticsAndOriginalPrivateCause():void
    {
        $transport=$this->transport(self::ALICE,static fn():BinaryNode=>new BinaryNode('iq',['type'=>'result']));
        try{$transport->plan(self::GROUP,true);self::fail('Missing group accepted');}catch(\MVO\Message\GroupPreparationException $error){self::assertSame(self::GROUP,$error->groupJid);self::assertSame(['stage'=>'metadata','reason'=>'metadata_group_missing'],$error->diagnostic());self::assertInstanceOf(\UnexpectedValueException::class,$error->getPrevious());}
    }
    public function testGroupStatusIdentityFailureDoesNotDiscoverOrInitializeDevices():void
    {
        $this->members[]='123456@lid';$io=0;$transport=$this->transport(self::ALICE,null,static function()use(&$io):array{++$io;return [];},static function()use(&$io):void{++$io;});
        try{$transport->plan(self::GROUP,true);self::fail('Mixed roster accepted');}catch(\MVO\Message\GroupPreparationException $error){self::assertSame(['stage'=>'identity','reason'=>'roster_mode_mismatch'],$error->diagnostic());}self::assertSame(0,$io);
    }
    public function testGroupStatusDiscoveryDistinguishesAbsentAndExplicitlyEmptyDeviceLists():void
    {
        foreach(['device_list_missing'=>[],'device_list_empty'=>[Jid::parse(self::BOB)->bare()=>[]]]as$reason=>$response){
            $transport=$this->transport(self::ALICE,null,fn():array=>[Jid::parse(self::ALICE)->bare()=>[self::ALICE],...$response],static function():never{self::fail('Must not initialize an incomplete roster');});
            try{$transport->plan(self::GROUP,true);self::fail('Incomplete device roster accepted');}catch(\MVO\Message\GroupPreparationException $error){self::assertSame(['stage'=>'device_discovery','reason'=>$reason],$error->diagnostic());self::assertStringNotContainsString(self::BOB,$error->getMessage());}
        }
    }
    public function testSignalSessionFailurePreservesPrivateReasonWithoutLeakingParticipant():void
    {
        $original=new \UnexpectedValueException('Signal identity changed for '.self::BOB);$transport=$this->transport(self::ALICE,null,null,static function()use($original):never{throw $original;});
        try{$transport->plan(self::GROUP,true);self::fail('Identity error ignored');}catch(\MVO\Message\GroupPreparationException $error){self::assertSame($original,$error->getPrevious());self::assertSame(['stage'=>'signal_sessions','reason'=>'signal_trusted_identity_changed'],$error->diagnostic());self::assertStringNotContainsString(self::BOB,json_encode($error->diagnostic(),JSON_THROW_ON_ERROR));self::assertStringNotContainsString(self::BOB,$error->getMessage());}
    }
    public function testExplicitServiceRejectionAndPermissionExceptionKeepOriginalContracts():void
    {
        $original=new \MVO\Service\ServiceException('usync','429','Rejected');$transport=$this->transport(self::ALICE,null,static function()use($original):never{throw $original;});try{$transport->plan(self::GROUP,true);self::fail('Server error ignored');}catch(\MVO\Service\ServiceException $error){self::assertSame($original,$error);}
        $this->members=[Jid::parse(self::BOB)->bare()];$transport=$this->transport(self::ALICE);try{$transport->plan(self::GROUP,true);self::fail('Membership error ignored');}catch(\LogicException $error){self::assertStringContainsString('not a member',$error->getMessage());}
    }
    private static function incoming(BinaryNode $sent, string $sender, string $recipient): BinaryNode
    {
        $children = [];
        foreach ($sent->child('participants')?->content ?? [] as $to) if ($to->attrs['jid'] === $recipient) $children[] = $to->child('enc');
        $children[] = $sent->child('enc');
        return new BinaryNode('message', ['from'=>self::GROUP,'participant'=>$sender,'id'=>$sent->attrs['id']], $children);
    }
    private static function keyId(BinaryNode $node): int { return SenderKeyCodec::readMessage($node->child('enc')->content)['id']; }

    public function testGroupFanoutAndBidirectionalMessagesSurviveRestart(): void
    {
        $alice = $this->transport(self::ALICE); $bob = $this->transport(self::BOB);
        $plan = $alice->plan(self::GROUP); $queries = $this->queries;
        $first = $alice->prepare(self::GROUP, 'first', MessageCodec::text('Olá grupo'), 'text', null, $plan);
        self::assertSame($queries, $this->queries, 'prepare with a plan must not perform network I/O');
        self::assertSame('pn', $first->attrs['addressing_mode']);
        self::assertCount(1, $first->child('participants')->content);
        self::assertNotNull($first->child('device-identity'));
        self::assertSame('Olá grupo', MessageCodec::decode($bob->processIncoming(self::incoming($first, self::ALICE, self::BOB)))['text']);
        $alice->acknowledge('first');
        $this->stores[self::ALICE]->close();
        $this->stores[self::ALICE] = new SignalStore($this->directory.'/0.sqlite');
        $this->signals[self::ALICE] = new SignalProtocol($this->stores[self::ALICE], $this->credentials[self::ALICE]);
        $alice = $this->transport(self::ALICE);
        $second = $alice->prepare(self::GROUP, 'second', MessageCodec::text('Persistiu'));
        self::assertNull($second->child('participants'), 'Acknowledged distributions survive restart');
        self::assertSame(self::keyId($first), self::keyId($second));
        self::assertSame('Persistiu', MessageCodec::decode($bob->processIncoming(self::incoming($second, self::ALICE, self::BOB)))['text']);
        $reply = $bob->prepare(self::GROUP, 'reply', MessageCodec::text('Recebido'));
        self::assertSame('Recebido', MessageCodec::decode($alice->processIncoming(self::incoming($reply, self::BOB, self::ALICE)))['text']);
    }

    public function testMembershipChangeRotatesSenderChainAndRemovesFanout(): void
    {
        $alice = $this->transport(self::ALICE); $bob = $this->transport(self::BOB); $carol = $this->transport(self::CAROL);
        $first = $alice->prepare(self::GROUP, 'm1', MessageCodec::text('Dois membros')); $alice->acknowledge('m1');
        $bob->processIncoming(self::incoming($first, self::ALICE, self::BOB));
        $this->members[] = Jid::parse(self::CAROL)->bare();
        $added = $alice->prepare(self::GROUP, 'm2', MessageCodec::text('Três membros')); $alice->acknowledge('m2');
        self::assertNotSame(self::keyId($first), self::keyId($added)); self::assertCount(2, $added->child('participants')->content);
        self::assertSame('Três membros', MessageCodec::decode($carol->processIncoming(self::incoming($added, self::ALICE, self::CAROL)))['text']);
        $bob->processIncoming(self::incoming($added, self::ALICE, self::BOB));
        $this->members = [Jid::parse(self::ALICE)->bare(),Jid::parse(self::CAROL)->bare()];
        $removed = $alice->prepare(self::GROUP, 'm3', MessageCodec::text('Depois da remoção'));
        self::assertNotSame(self::keyId($added), self::keyId($removed)); self::assertCount(1, $removed->child('participants')->content);
        self::assertSame(self::CAROL, $removed->child('participants')->content[0]->attrs['jid']);
        self::assertSame('Depois da remoção', MessageCodec::decode($carol->processIncoming(self::incoming($removed, self::ALICE, self::CAROL)))['text']);
        $this->expectException(\UnexpectedValueException::class);
        $bob->processIncoming(self::incoming($removed, self::ALICE, self::BOB));
    }

    public function testMissingAckKeepsDistributionPendingAndUncommittedOutboxRollsBackRatchets(): void
    {
        $alice = $this->transport(self::ALICE); $bob = $this->transport(self::BOB);
        $plan = $alice->plan(self::GROUP);
        try {
            $this->stores[self::ALICE]->transaction(function () use ($alice,$plan):void {
                $alice->prepare(self::GROUP, 'rolledback', MessageCodec::text('Não persistiu'), 'text', null, $plan);
                throw new \RuntimeException('outbox failure');
            });
        } catch (\RuntimeException) { self::assertNull($this->stores[self::ALICE]->get('group-distribution-pending', 'rolledback')); }
        $first = $alice->prepare(self::GROUP, 'first', MessageCodec::text('0'), 'text', null, $plan);
        self::assertSame(0, SenderKeyCodec::readMessage($first->child('enc')->content)['iteration']);
        self::assertSame('0', MessageCodec::decode($bob->processIncoming(self::incoming($first, self::ALICE, self::BOB)))['text']);
        $second = $alice->prepare(self::GROUP, 'second', MessageCodec::text('1'));
        self::assertNotNull($second->child('participants'));
        self::assertSame('1', MessageCodec::decode($bob->processIncoming(self::incoming($second, self::ALICE, self::BOB)))['text']);
    }

    public function testTamperedGroupPayloadRollsBackDirectPrekeyAndDistribution(): void
    {
        $alice = $this->transport(self::ALICE); $bob = $this->transport(self::BOB);
        $sent = $alice->prepare(self::GROUP, 'tamper', MessageCodec::text('Original'));
        $original = self::incoming($sent, self::ALICE, self::BOB);
        $children = $original->content;
        $bad = $children[1]->content; $bad[strlen($bad)-1] = $bad[strlen($bad)-1]^"\x01";
        $children[1] = new BinaryNode('enc', $children[1]->attrs, $bad);
        try { $bob->processIncoming(new BinaryNode('message', $original->attrs, $children)); self::fail('Tamper accepted'); }
        catch (\UnexpectedValueException) { self::assertFalse($this->signals[self::BOB]->hasSession(self::ALICE)); }
        self::assertSame('Original', MessageCodec::decode($bob->processIncoming($original))['text']);
    }

    public function testWrongGroupDistributionDoesNotCommitAnyDirectSession(): void
    {
        $alice = $this->transport(self::ALICE); $bob = $this->transport(self::BOB);
        $sent = $alice->prepare(self::GROUP, 'm1', MessageCodec::text('Original'));
        $incoming = self::incoming($sent, self::ALICE, self::BOB);
        $attrs = $incoming->attrs; $attrs['from'] = '120363999@g.us';
        try { $bob->processIncoming(new BinaryNode('message', $attrs, $incoming->content)); self::fail('Cross-group distribution accepted'); }
        catch (\UnexpectedValueException) { self::assertFalse($this->signals[self::BOB]->hasSession(self::ALICE)); }
        self::assertSame('Original', MessageCodec::decode($bob->processIncoming($incoming))['text']);
    }

    public function testBroadcastAndNewsletterDoNotUseGroupCrypto(): void
    {
        foreach (['status@broadcast','123@newsletter',self::BOB] as $jid) {
            try { $this->transport(self::ALICE)->plan($jid); self::fail('Unsupported chat accepted'); }
            catch (\InvalidArgumentException) { self::assertSame(0, $this->queries); }
        }
    }

    public function testLidGroupFanoutPreservesSenderDeviceAndMediaBytes(): void
    {
        $this->mode = 'lid';
        $aliceLid = $this->credentials[self::ALICE]['meLid']; $bobLid = $this->credentials[self::BOB]['meLid'];
        $this->members = [Jid::parse($aliceLid)->bare(), Jid::parse($bobLid)->bare()];
        $this->devices = [Jid::parse($aliceLid)->bare()=>[$aliceLid], Jid::parse($bobLid)->bare()=>[$bobLid]];
        $aliceSignal = $this->signals[self::ALICE]; $bobCredentials = $this->credentials[self::BOB];
        $aliceSignal->ensureSession($bobLid, ['registrationId'=>$bobCredentials['registrationId'], 'identityKey'=>$bobCredentials['identityKeyPair']['public'], 'signedPreKey'=>array_intersect_key($bobCredentials['signedPreKey'], array_flip(['id','public','signature']))]);
        // A bare success.lid must retain the device from success.jid.
        $this->credentials[self::ALICE]['meLid'] = Jid::parse($aliceLid)->bare();
        $query = function (BinaryNode $request, float $timeout):BinaryNode {
            return new BinaryNode('iq', ['type'=>'result'], [new BinaryNode('group', ['id'=>self::GROUP,'addressing_mode'=>'lid'], array_map(static fn(string $jid):BinaryNode => new BinaryNode('participant', ['jid'=>$jid]), $this->members))]);
        };
        $alice = new GroupTransport($aliceSignal, $this->stores[self::ALICE], $query,
            fn(array $users):array => array_intersect_key($this->devices, array_flip($users)), static function (array $devices):void {}, fn():array => $this->credentials[self::ALICE]);
        $bob = $this->transport(self::BOB);
        $media = MessageCodec::bytes(3, MessageCodec::bytes(1, 'https://example.test/image.enc').MessageCodec::bytes(2, 'image/jpeg'));
        $plan = $alice->plan(self::GROUP); self::assertSame($aliceLid, $plan['sender']);
        $sent = $alice->prepare(self::GROUP, 'lid-media', $media, 'media', 'image', $plan);
        self::assertSame('lid', $sent->attrs['addressing_mode']); self::assertSame('media', $sent->attrs['type']);
        self::assertSame('image', $sent->child('enc')->attrs['mediatype']);
        self::assertSame($media, $bob->processIncoming(self::incoming($sent, $aliceLid, $bobLid)));
    }

    public function testDistributionOnlyMessageIsConsumedWithoutAnApplicationMessage(): void
    {
        $alice = $this->transport(self::ALICE); $bob = $this->transport(self::BOB);
        $sent = $alice->prepare(self::GROUP, 'm1', MessageCodec::text('Conteúdo separado'));
        $incoming = self::incoming($sent, self::ALICE, self::BOB);
        self::assertNull($bob->processIncoming(new BinaryNode('message', $incoming->attrs, [$incoming->content[0]])));
        self::assertSame('Conteúdo separado', MessageCodec::decode($bob->processIncoming(new BinaryNode('message', $incoming->attrs, [$incoming->content[1]])))['text']);
    }

    public function testExistingPhoneSessionAuthenticatesDistributionFromNewLidParticipant(): void
    {
        $alice = $this->transport(self::ALICE); $bob = $this->transport(self::BOB);
        $first = $alice->prepare(self::GROUP, 'first', MessageCodec::text('Inicial'));
        $bob->processIncoming(self::incoming($first,self::ALICE,self::BOB));
        $reply = $this->signals[self::BOB]->encrypt(self::ALICE, 'direct reply');
        self::assertSame('direct reply', $this->signals[self::ALICE]->decrypt(self::BOB, $reply['type'], $reply['ciphertext']));
        $aliceLid = $this->credentials[self::ALICE]['meLid'];
        $senderKeys = new SenderKeyProtocol($this->stores[self::ALICE]);
        $distribution = $senderKeys->createDistribution(self::GROUP, $aliceLid);
        $distributionMessage = MessageCodec::bytes(2, MessageCodec::bytes(1,self::GROUP).MessageCodec::bytes(2,$distribution));
        $direct = $this->signals[self::ALICE]->encrypt(self::BOB, MessageCodec::pad($distributionMessage));
        self::assertSame('msg',$direct['type']);
        $skmsg = $senderKeys->encrypt(self::GROUP,$aliceLid,MessageCodec::pad(MessageCodec::text('Migrando para LID')));
        $incoming = new BinaryNode('message',['from'=>self::GROUP,'participant'=>$aliceLid,'participant_pn'=>Jid::parse(self::ALICE)->bare(),'id'=>'migrated'],[
            new BinaryNode('enc',['type'=>$direct['type'],'v'=>'2'],$direct['ciphertext']),new BinaryNode('enc',['type'=>'skmsg','v'=>'2'],$skmsg)]);
        self::assertFalse($this->signals[self::BOB]->hasSession($aliceLid));
        try { $bob->processIncoming($incoming); self::fail('An unavailable LID direct session should fail'); }
        catch (\UnexpectedValueException $error) { self::assertStringContainsString('session not found',$error->getMessage()); }
        self::assertSame('Migrando para LID', MessageCodec::decode($bob->processIncoming($incoming,self::ALICE))['text']);
    }

    public function testUnknownMembershipAndIncompleteDevicesRejectBeforeEncryption(): void
    {
        $alice = $this->transport(self::ALICE);
        $this->members = [Jid::parse(self::BOB)->bare()];
        try { $alice->plan(self::GROUP); self::fail('Nonmember can send'); }
        catch (\LogicException $error) { self::assertStringContainsString('not a member', $error->getMessage()); }
        $this->members[] = Jid::parse(self::ALICE)->bare(); unset($this->devices[Jid::parse(self::BOB)->bare()]);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('No device list');
        $alice->plan(self::GROUP);
    }

    public function testPhashCanonicalizesAndSortsParticipantDevices(): void
    {
        $devices = ['5511:0@c.us','5511.0:2@s.whatsapp.net','99:1@lid'];
        $canonical = ['5511.0:0@s.whatsapp.net','5511.0:2@s.whatsapp.net','99.0:1@lid'];
        sort($canonical, SORT_STRING);
        $expected = '2:'.base64_encode(substr(hash('sha256', implode('', $canonical), true), 0, 6));
        self::assertSame($expected, GroupTransport::phash($devices));
        self::assertSame($expected, GroupTransport::phash(array_reverse($devices)));
        self::assertSame('2:', GroupTransport::phash([]));
    }
}
