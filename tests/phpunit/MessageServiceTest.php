<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use MVO\Auth\CredentialStore;
use MVO\Message\{AddonCrypto,ContentBuilder,MessageCodec,MessageService};
use MVO\Media\MediaTransfer;
use MVO\Protocol\{BinaryData,BinaryNode,BinaryCodec,Jid};
use MVO\Signal\{SenderKeyProtocol,SignalProtocol,SignalStore};

final class MessageServiceTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];
    /** @var array<string,mixed> */
    private array $credentials;
    private SignalStore $store;
    private SignalProtocol $signal;
    /** @var list<BinaryNode> */
    private array $sent = [];
    /** @var list<array{string,array<string,mixed>}> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->credentials = self::credentials('5511000000000:5@s.whatsapp.net');
        $this->store = new SignalStore($this->path());
        $this->signal = new SignalProtocol($this->store, $this->credentials);
    }

    protected function tearDown(): void
    {
        unset($this->signal, $this->store);
        gc_collect_cycles();
        foreach ($this->paths as $path) foreach ([$path,$path.'-wal',$path.'-shm'] as $file) if (is_file($file)) @unlink($file);
    }

    private function path(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mvo-message-');
        if ($path === false) throw new \RuntimeException('Cannot create temporary database');
        $this->paths[] = $path;
        return $path;
    }

    /** @return array<string,mixed> */
    private static function credentials(string $jid): array
    {
        $credentials = CredentialStore::fresh();
        $credentials['registered'] = true;
        $credentials['meJid'] = $jid;
        $credentials['serverHasPreKeys'] = true;
        $credentials['signedIdentity'] = ['details'=>'fixture','accountSignatureKey'=>random_bytes(32),
            'accountSignature'=>random_bytes(64),'deviceSignature'=>random_bytes(64)];
        return $credentials;
    }

    /** @param \Closure(BinaryNode,float):BinaryNode $query */
    private function service(\Closure $query, ?MediaTransfer $media=null): MessageService
    {
        return new MessageService($this->signal, $this->store,
            fn():array => $this->credentials,
            function (array $credentials):void { $this->credentials = $credentials; }, $query,
            function (BinaryNode $node):void { $this->sent[] = $node; },
            function (string $event, array $payload):void { $this->events[] = [$event,$payload]; },$media);
    }

    public function testPreKeysAreDurableBeforeUploadAndSurviveFailedIq(): void
    {
        $this->credentials['serverHasPreKeys'] = false;
        $first = null; $attempt = 0;
        $service = $this->service(function (BinaryNode $iq) use (&$first, &$attempt):BinaryNode {
            self::assertSame('encrypt', $iq->attrs['xmlns']);
            self::assertSame("\x05", $iq->child('type')->content);
            self::assertCount(812, $this->store->pendingPreKeys());
            $encoded = (new BinaryCodec())->encode($iq);
            if ($attempt++ === 0) { $first = $encoded; throw new \RuntimeException('network interrupted'); }
            self::assertSame($first, $encoded, 'Failed uploads must reuse the same persisted keys');
            return new BinaryNode('iq', ['type'=>'result']);
        });
        try { $service->initialize(); self::fail('Expected upload failure'); }
        catch (\RuntimeException $error) { self::assertSame('network interrupted', $error->getMessage()); }
        self::assertFalse($this->credentials['serverHasPreKeys']);
        $service->initialize();
        self::assertTrue($this->credentials['serverHasPreKeys']);
        self::assertSame([], $this->store->pendingPreKeys());
        $service->initialize();
        self::assertSame(2, $attempt);
    }

    public function testTextFanoutDecryptsOnRecipientDevicesAndOwnPhone(): void
    {
        $recipient = '5522000000000@s.whatsapp.net';
        $own = '5511000000000@s.whatsapp.net';
        $peers = $this->peers([$recipient,'5522000000000:2@s.whatsapp.net',$own]);
        $seen = [];
        $service = $this->service(function (BinaryNode $node) use ($recipient, $own, $peers, &$seen):BinaryNode {
            if ($node->tag === 'iq' && ($node->attrs['xmlns'] ?? '') === 'usync') return self::devices([$recipient=>[0,2],$own=>[0,5]]);
            if ($node->tag === 'iq') return self::bundles($peers);
            self::assertSame('message', $node->tag);
            self::assertNotNull($this->store->get('message-outbox', $node->attrs['id']), 'Persist before transport');
            self::assertNotNull($node->child('device-identity'));
            foreach ($node->child('participants')->content as $to) {
                $jid = $to->attrs['jid']; $enc = $to->child('enc');
                $plaintext = $peers[$jid]['signal']->decrypt($this->credentials['meJid'], $enc->attrs['type'], $enc->content);
                $decoded = MessageCodec::decode(MessageCodec::unpad($plaintext));
                self::assertSame('Olá do PHP', $decoded['text']);
                self::assertSame($jid === $own ? $recipient : null, $decoded['destination']);
                $seen[] = $jid;
            }
            return new BinaryNode('ack', ['class'=>'message','id'=>$node->attrs['id']]);
        });
        $result = $service->sendText('+55 (22) 00000-0000', 'Olá do PHP');
        self::assertSame('server_ack', $result['status']);
        self::assertSame($recipient, $result['to']);
        self::assertCount(3, $seen);
        self::assertNotContains('5511000000000:5@s.whatsapp.net', $seen);
        self::assertSame('message_ack', $this->events[0][0]);
        self::assertSame($result, $service->getMessageStatus($result['id']));
    }

    public function testTimedOutSendCanRetryExactCiphertextAndIdWithoutAnotherRatchetStep(): void
    {
        $jid = '5522000000000@s.whatsapp.net';
        $peers = $this->peers([$jid]);
        $encoded = null; $messageId = null; $attempt = 0;
        $service = $this->service(function (BinaryNode $node) use ($jid, $peers, &$encoded, &$messageId, &$attempt):BinaryNode {
            if ($node->tag === 'iq' && ($node->attrs['xmlns'] ?? '') === 'usync') return self::devices([$jid=>[0]]);
            if ($node->tag === 'iq') return self::bundles($peers);
            $bytes = (new BinaryCodec())->encode($node);
            if ($attempt++ === 0) { $encoded = $bytes; $messageId = $node->attrs['id']; throw new \RuntimeException('timeout'); }
            self::assertSame($encoded, $bytes);
            return new BinaryNode('ack', ['class'=>'message','id'=>$node->attrs['id']]);
        });
        try { $service->sendText($jid, 'one'); self::fail('Expected timeout'); } catch (\RuntimeException $error) { self::assertSame('timeout', $error->getMessage()); }
        self::assertSame('unknown', $service->getMessageStatus($messageId)['status']);
        self::assertNotContains('message_ack', array_column($this->events,0));
        $result = $service->retryMessage($messageId);
        self::assertSame('server_ack', $result['status']);
        self::assertSame($messageId, $result['id']);
        $service->retryMessage($messageId);
        self::assertSame(2, $attempt, 'Already acknowledged messages must never resend');
    }

    public function testWrongAckNeverReportsSuccess(): void
    {
        $jid = '5522000000000@s.whatsapp.net'; $peers = $this->peers([$jid]);
        $service = $this->service(static function (BinaryNode $node) use ($jid, $peers):BinaryNode {
            if ($node->tag === 'iq' && ($node->attrs['xmlns'] ?? '') === 'usync') return self::devices([$jid=>[0]]);
            if ($node->tag === 'iq') return self::bundles($peers);
            return new BinaryNode('ack', ['class'=>'message','id'=>$node->attrs['id'],'error'=>'479']);
        });
        $this->expectException(\RuntimeException::class);
        try { $service->sendText($jid, 'no'); }
        finally { self::assertNotContains('message_ack', array_column($this->events,0)); }
    }

    public function testAuthenticatedIncomingTextPersistsAndDeduplicatesAfterServiceRestart(): void
    {
        $peerJid = '5522000000000:2@s.whatsapp.net';
        $peer = $this->peers([$peerJid])[$peerJid];
        $localKey = $this->store->generatePreKeys(1)[0];
        $peer['signal']->ensureSession($this->credentials['meJid'], self::bundle($this->credentials, $localKey));
        $encrypted = $peer['signal']->encrypt($this->credentials['meJid'], MessageCodec::pad(MessageCodec::text('incoming')));
        $node = new BinaryNode('message', ['id'=>'incoming-1','from'=>$peerJid,'type'=>'text','t'=>'1760000000'], [
            new BinaryNode('enc', ['type'=>$encrypted['type'],'v'=>'2'], $encrypted['ciphertext'])
        ]);
        $query = static fn():BinaryNode => throw new \LogicException('No query during incoming dispatch');
        self::assertTrue($this->service($query)->handleNode($node));
        self::assertSame('message', $this->events[0][0]);
        self::assertSame('incoming', $this->events[0][1]['text']);
        self::assertSame('5522000000000@s.whatsapp.net', $this->events[0][1]['from']);
        self::assertFalse($this->events[0][1]['fromMe']);
        self::assertSame('receipt', $this->sent[0]->tag);
        self::assertArrayNotHasKey('type', $this->sent[0]->attrs, 'Delivery must not mark as read');
        unset($this->signal, $this->store);
        $this->store = new SignalStore($this->paths[0]);
        $this->signal = new SignalProtocol($this->store, $this->credentials);
        $this->service($query)->handleNode($node);
        self::assertCount(1, $this->events);
        self::assertCount(2, $this->sent);
    }

    public function testTamperedIncomingCiphertextNeverEmitsTextOrDeliveryReceipt(): void
    {
        $node = new BinaryNode('message', ['id'=>'bad','from'=>'5522000000000:2@s.whatsapp.net','type'=>'text'], [
            new BinaryNode('enc', ['type'=>'pkmsg','v'=>'2'], "\x33bad")
        ]);
        $this->service(static fn():BinaryNode => throw new \LogicException('No query'))->handleNode($node);
        self::assertSame(['message_error'], array_column($this->events,0));
        self::assertSame('decrypt_failed', $this->events[0][1]['reason']);
        self::assertSame('retry', $this->sent[0]->attrs['type']);
        self::assertSame('1', $this->sent[0]->child('retry')->attrs['count']);
    }

    public function testAuthenticatedNativePhoneShareDecodesAfterStanzaAliasAndPersistsBeforeCallback():void
    {
        $pn='5522000000000@s.whatsapp.net';$lid='77770000000@lid';$peer=$this->peers([$pn])[$pn];
        $this->signal->ensureSession($pn,self::bundle($peer['credentials'],$peer['key']));$first=$this->signal->encrypt($pn,MessageCodec::pad(MessageCodec::text('first')));$peer['signal']->decrypt($this->credentials['meJid'],$first['type'],$first['ciphertext']);
        $raw=\MVO\Protocol\SchemaCodec::encode('Message',['protocolMessage'=>['type'=>11]]);$reply=$peer['signal']->encrypt($this->credentials['meJid'],MessageCodec::pad($raw));
        $node=new BinaryNode('message',['id'=>'native-phone-share','from'=>$lid,'sender_pn'=>$pn,'t'=>'1760000000'],[new BinaryNode('enc',['type'=>$reply['type'],'v'=>'2'],$reply['ciphertext'])]);
        $service=$this->service(static fn():BinaryNode=>throw new \LogicException('No query'));$service->handleNode($node);
        self::assertSame('message_protocol',$this->events[0][0]);$share=$this->events[0][1]['phoneNumberShare'];self::assertSame($pn,$share['phoneJid']);self::assertSame('authenticated_identity_mapping',$share['numberSource']);self::assertNull($share['requestId']);self::assertSame($share['messageId'],$service->history()->store->phoneNumberShares($pn)[0]['messageId']);
        $service->handleNode($node);self::assertCount(1,$this->events);self::assertCount(1,$service->history()->store->phoneNumberShares($pn));
    }

    public function testAcknowledgedOutgoingPhoneShareKeepsOwnDirectionAndOriginalProtocol():void
    {
        $jid='5522000000000@s.whatsapp.net';$peers=$this->peers([$jid]);$service=$this->service(function(BinaryNode $node)use($jid,$peers):BinaryNode{if($node->tag==='iq'&&($node->attrs['xmlns']??'')==='usync')return self::devices([$jid=>[0]]);if($node->tag==='iq')return self::bundles($peers);return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);});
        $result=$service->sendMessage($jid,['type'=>'raw','message'=>['protocolMessage'=>['type'=>11]]]);$rows=$service->history()->store->phoneNumberShares($jid);self::assertCount(1,$rows);self::assertSame($result['id'],$rows[0]['messageId']);self::assertTrue($rows[0]['fromMe']);self::assertSame('5511000000000@s.whatsapp.net',$rows[0]['phoneJid']);self::assertArrayNotHasKey('delivered',$rows[0]);self::assertSame(11,$service->history()->store->getStoredMessage($jid,$result['id'])['proto']['protocolMessage']['type']);
    }

    public function testMalformedGroupSenderIsRejectedAndReceiptsAreAcknowledged(): void
    {
        $service = $this->service(static fn():BinaryNode => throw new \LogicException('No query'));
        $service->handleNode(new BinaryNode('message', ['id'=>'g1','from'=>'1234-5678@g.us'], [new BinaryNode('enc',['type'=>'skmsg'],'bytes')]));
        self::assertSame('message_error', $this->events[0][0]);
        self::assertSame('ack', $this->sent[0]->tag);
        $service->handleNode(new BinaryNode('receipt', ['id'=>'m1','from'=>'5522000000000@s.whatsapp.net','type'=>'read'], [
            new BinaryNode('list', [], [new BinaryNode('item', ['id'=>'m2'])])
        ]));
        self::assertSame('receipt', $this->sent[1]->attrs['class']);
        self::assertSame('read', $this->events[1][1]['type']);
        self::assertSame('m2', $this->events[2][1]['id']);
    }

    public function testLowPrekeyNotificationDefersNetworkUntilDrain(): void
    {
        $queries = 0;
        $service = $this->service(static function () use (&$queries):BinaryNode { ++$queries; return new BinaryNode('iq', ['type'=>'result']); });
        $service->handleNode(new BinaryNode('notification', ['id'=>'n1','from'=>'s.whatsapp.net','type'=>'encrypt'], [new BinaryNode('count', ['value'=>'2'])]));
        self::assertSame(0, $queries);
        self::assertSame('notification', $this->sent[0]->attrs['class']);
        $service->drainPending();
        self::assertSame(1, $queries);
        $service->drainPending();
        self::assertSame(1, $queries);
    }

    public function testFanoutFailureRollsBackRatchetBeforeAnyTransportSend(): void
    {
        $jid = '5522000000000@s.whatsapp.net';
        $peer = $this->peers([$jid])[$jid];
        $this->signal->ensureSession($jid, self::bundle($peer['credentials'], $peer['key']));
        $state = $this->store->getSession($jid);
        unset($this->credentials['signedIdentity']);
        $service = $this->service(static function (BinaryNode $node) use ($jid):BinaryNode {
            self::assertSame('usync', $node->attrs['xmlns'], 'No message should reach transport on encryption failure');
            return self::devices([$jid=>[0]]);
        });
        try { $service->sendText($jid, 'atomic'); self::fail('Expected missing identity failure'); }
        catch (\LogicException $error) { self::assertSame('Paired signed device identity is missing', $error->getMessage()); }
        self::assertSame($state, $this->store->getSession($jid));
        self::assertSame([], $this->events);
    }

    public function testAuthenticatedExtendedOwnLidMessageResolvesOriginalChat(): void
    {
        $this->credentials['meLid'] = '88880000000@lid';
        $phoneJid = '88880000000@lid';
        $phone = $this->peers([$phoneJid])[$phoneJid];
        $key = $this->store->generatePreKeys(1)[0];
        $phone['signal']->ensureSession('88880000000:5@lid', self::bundle($this->credentials, $key));
        $wrapped = MessageCodec::deviceSent(hex2bin('32040a026f69'), '5522000000000@s.whatsapp.net');
        $encrypted = $phone['signal']->encrypt('88880000000:5@lid', MessageCodec::pad($wrapped));
        // Own PN sender aliases to the LID ratchet used by the encrypted payload.
        $node = new BinaryNode('message', ['id'=>'own-1','from'=>'5511000000000@s.whatsapp.net'], [
            new BinaryNode('enc', ['type'=>$encrypted['type'],'v'=>'2'], $encrypted['ciphertext'])
        ]);
        $this->service(static fn():BinaryNode => throw new \LogicException('No query'))->handleNode($node);
        self::assertSame('message', $this->events[0][0]);
        self::assertTrue($this->events[0][1]['fromMe']);
        self::assertSame('oi', $this->events[0][1]['text']);
        self::assertSame('5522000000000@s.whatsapp.net', $this->events[0][1]['chat']);
        self::assertTrue($this->signal->hasSession($phoneJid));
        self::assertFalse($this->signal->hasSession('5511000000000@s.whatsapp.net'));
    }

    /** @return array<string,array{string,?string,string,bool,bool}> */
    public static function ownEchoReceiptCases():array
    {
        return [
            'phone PN to PN'=>['5511000000000@s.whatsapp.net',null,'5522000000000@s.whatsapp.net',true,false],
            'phone LID to LID'=>['88880000000@lid',null,'77770000000@lid',true,true],
            'own PN aliases LID ratchet'=>['5511000000000@s.whatsapp.net',null,'77770000000@lid',true,true],
            'companion participant keeps device'=>['88880000000@lid','88880000000:7@lid','5522000000000@s.whatsapp.net',true,true],
            'destination only in encrypted envelope'=>['5511000000000@s.whatsapp.net',null,'5522000000000@s.whatsapp.net',false,false],
            'encrypted LID destination'=>['88880000000@lid',null,'77770000000@lid',false,true],
            'hosted PN sender'=>['5511000000000@hosted',null,'5522000000000@s.whatsapp.net',true,false],
            'hosted LID sender'=>['88880000000@hosted.lid',null,'77770000000@lid',true,true],
        ];
    }

    #[DataProvider('ownEchoReceiptCases')]
    public function testOwnDirectEchoUsesSenderReceiptAndCompletesQueueOnSuccessfulWriteAcrossReplay(string $from,?string $participant,string $destination,bool $stanzaRecipient,bool $lid):void
    {
        if($lid)$this->credentials['meLid']='88880000000:5@lid';
        $node=$this->encryptedEcho($from,$participant,$destination,$stanzaRecipient);
        $query=static fn():BinaryNode=>throw new \LogicException('No query during own echo');
        $service=$this->service($query);$service->handleNode($node);
        $expected=['id'=>'own-receipt','to'=>$participant ?? $from,'type'=>'sender','recipient'=>$destination];
        self::assertSame($expected,$this->sent[0]->attrs);self::assertSame('receipt',$this->sent[0]->tag);
        self::assertSame($destination,$this->events[0][1]['chat']);self::assertTrue($this->events[0][1]['fromMe']);
        self::assertSame('Own receipt fixture',$this->events[0][1]['text']);
        self::assertCount($stanzaRecipient ? 0 : 1,$this->store->entries('message-inbox-receipt-chat'));
        self::assertSame([],$this->store->entries('receipt-queue'),'A completed transport write must not wait indefinitely for a receipt ACK');
        $ack=new BinaryNode('ack',['class'=>'receipt','id'=>'own-receipt','from'=>$expected['to'],'type'=>'sender','recipient'=>$destination]);
        $service->handleNode($ack);self::assertSame([],$this->store->entries('receipt-queue'));
        $this->sent=[];$service=$this->service($query);$service->handleNode($node);
        self::assertSame($expected,$this->sent[0]->attrs,'Replay restores the original chat even without a stanza recipient');
        self::assertCount(1,$this->events,'Replay must not emit or decrypt the same own message again');
        self::assertSame([],$this->store->entries('receipt-queue'),'The duplicate receipt also completes when written');
        $service->handleNode($ack);self::assertSame([],$this->store->entries('receipt-queue'));
        self::assertSame([],$this->store->entries('message-outbox'),'An Android echo does not become an API outgoing message');
    }

    public function testUncertainOwnSenderReceiptKeepsDurableNodeAndRequiresExactAckRecipient():void
    {
        $this->credentials['meLid']='88880000000:5@lid';$destination='77770000000@lid';$from='88880000000@lid';
        $node=$this->encryptedEcho($from,null,$destination,true);
        $service=new MessageService($this->signal,$this->store,fn():array=>$this->credentials,static function():void{},
            static fn():BinaryNode=>throw new \LogicException('No query during uncertain receipt write'),
            function(BinaryNode $receipt):void{$this->sent[]=$receipt;throw new \RuntimeException('Synthetic partial write');},
            function(string $event,array $data):void{$this->events[]=[$event,$data];});
        $service->handleNode($node);$rows=$this->store->entries('receipt-queue');self::assertCount(1,$rows);
        self::assertSame(['id'=>'own-receipt','to'=>$from,'type'=>'sender','recipient'=>$destination],BinaryData::decode(reset($rows))['attrs']);
        $service->handleNode(new BinaryNode('ack',['class'=>'receipt','id'=>'own-receipt','from'=>$from,'type'=>'sender','recipient'=>'99990000000@lid']));
        self::assertCount(1,$this->store->entries('receipt-queue'));
        $service->handleNode(new BinaryNode('ack',['class'=>'receipt','id'=>'own-receipt','from'=>$from,'recipient'=>$destination]));
        self::assertCount(1,$this->store->entries('receipt-queue'));
        $service->handleNode(new BinaryNode('ack',['class'=>'receipt','id'=>'own-receipt','from'=>$from,'type'=>'sender','recipient'=>$destination]));
        self::assertSame([],$this->store->entries('receipt-queue'));self::assertCount(1,$this->events);
        self::assertSame([],$this->store->entries('message-outbox'));
    }

    public function testPeerCategoryKeepsPeerReceiptInsteadOfOwnSenderReceipt():void
    {
        $this->credentials['meLid']='88880000000:5@lid';
        $node=$this->encryptedEcho('88880000000@lid',null,'5522000000000@s.whatsapp.net',true);
        $node=new BinaryNode('message',[...$node->attrs,'category'=>'peer'],$node->content);
        $service=$this->service(static fn():BinaryNode=>throw new \LogicException('No peer query'));$service->handleNode($node);
        self::assertSame(['id'=>'own-receipt','to'=>'88880000000@lid','type'=>'peer_msg'],$this->sent[0]->attrs);
        self::assertSame([],$this->store->entries('message-inbox-receipt-chat'));
        self::assertSame([],$this->store->entries('receipt-queue'));
        $service->handleNode(new BinaryNode('ack',['class'=>'receipt','id'=>'own-receipt','from'=>'88880000000@lid','type'=>'peer_msg']));
        self::assertSame([],$this->store->entries('receipt-queue'));
    }

    public function testOwnEchoWithoutKnownConversationOnlyAcknowledgesTransport():void
    {
        $node=$this->encryptedEcho('5511000000000@s.whatsapp.net',null,'5522000000000@s.whatsapp.net',false,false);
        $service=$this->service(static fn():BinaryNode=>throw new \LogicException('No query without echo route'));
        $service->handleNode($node);$service->handleNode($node);
        self::assertCount(1,$this->events);self::assertCount(2,$this->sent);
        foreach($this->sent as$ack){self::assertSame('ack',$ack->tag);self::assertSame(['class'=>'message','to'=>'5511000000000@s.whatsapp.net','id'=>'own-receipt'],$ack->attrs);}
        self::assertSame([],$this->store->entries('receipt-queue'));self::assertSame([],$this->store->entries('message-inbox-receipt-chat'));
    }

    public function testOwnEchoWithMalformedStanzaConversationDoesNotInventSenderReceipt():void
    {
        $node=$this->encryptedEcho('5511000000000@s.whatsapp.net',null,'5522000000000@s.whatsapp.net',true);
        $node=new BinaryNode('message',[...$node->attrs,'recipient'=>'invalid route'],$node->content);
        $this->service(static fn():BinaryNode=>throw new \LogicException('No query with invalid route'))->handleNode($node);
        self::assertSame('ack',$this->sent[0]->tag);self::assertSame('message',$this->sent[0]->attrs['class']);
        self::assertSame([],$this->store->entries('receipt-queue'));
    }

    public function testOwnDirectStanzaWithEncryptedGroupDestinationUsesGroupSenderRouting():void
    {
        $group='100-200@g.us';$author='5511000000000@s.whatsapp.net';
        $node=$this->encryptedEcho($author,null,$group,false);
        $service=$this->service(static fn():BinaryNode=>throw new \LogicException('No group echo query'));$service->handleNode($node);
        self::assertSame(['id'=>'own-receipt','to'=>$group,'type'=>'sender','participant'=>$author],$this->sent[0]->attrs);
        $service->handleNode(new BinaryNode('ack',['class'=>'receipt','id'=>'own-receipt','from'=>$group,'type'=>'sender','participant'=>$author]));
        self::assertSame([],$this->store->entries('receipt-queue'));
    }

    /** @return array<string,array{string,bool,bool}> */
    public static function groupReceiptCases():array
    {
        return [
            'own phone PN'=>['5511000000000@s.whatsapp.net',false,false],
            'own phone LID'=>['88880000000@lid',true,false],
            'remote participant'=>['5522000000000:2@s.whatsapp.net',false,false],
            'peer priority'=>['5511000000000@s.whatsapp.net',false,true],
        ];
    }

    #[DataProvider('groupReceiptCases')]
    public function testAuthenticatedGroupReceiptPreservesGroupParticipantAndCategory(string $author,bool $lid,bool $peerCategory):void
    {
        if($lid)$this->credentials['meLid']='88880000000:5@lid';
        $group='100-200@g.us';$remote=$this->peers([$author])[$author];$key=$this->store->generatePreKeys(1)[0];
        $target=$lid ? '88880000000:5@lid' : $this->credentials['meJid'];
        $remote['signal']->ensureSession($target,self::bundle($this->credentials,$key));
        $senderKeys=new SenderKeyProtocol($remote['store']);$distribution=$senderKeys->createDistribution($group,$author);
        $distribution=MessageCodec::bytes(2,MessageCodec::bytes(1,$group).MessageCodec::bytes(2,$distribution));
        $direct=$remote['signal']->encrypt($target,MessageCodec::pad($distribution));
        $encrypted=$senderKeys->encrypt($group,$author,MessageCodec::pad(MessageCodec::text('Group receipt fixture')));
        $attrs=['id'=>'group-receipt','from'=>$group,'participant'=>$author];if($peerCategory)$attrs['category']='peer';
        $node=new BinaryNode('message',$attrs,[new BinaryNode('enc',['type'=>$direct['type'],'v'=>'2'],$direct['ciphertext']),new BinaryNode('enc',['type'=>'skmsg','v'=>'2'],$encrypted)]);
        $service=$this->service(static fn():BinaryNode=>throw new \LogicException('No group query'));$service->handleNode($node);
        self::assertSame('Group receipt fixture',$this->events[0][1]['text']);
        $expected=['id'=>'group-receipt','to'=>$group,'participant'=>$author];
        if($peerCategory)$expected['type']='peer_msg';elseif($author!=='5522000000000:2@s.whatsapp.net')$expected['type']='sender';
        self::assertSame($expected,$this->sent[0]->attrs);self::assertArrayNotHasKey('recipient',$this->sent[0]->attrs);
        self::assertSame([],$this->store->entries('receipt-queue'),'Group receipts complete on a successful transport write too');
        $ack=['class'=>'receipt','id'=>'group-receipt','from'=>$group,'participant'=>$author];if(isset($expected['type']))$ack['type']=$expected['type'];
        $service->handleNode(new BinaryNode('ack',$ack));self::assertSame([],$this->store->entries('receipt-queue'));
    }

    /** A real Signal envelope from another own device, not a mocked receipt() call. */
    private function encryptedEcho(string $from,?string $participant,string $destination,bool $stanzaRecipient,bool $wrapped=true):BinaryNode
    {
        $author=Jid::parse($participant ?? $from)->canonical();$ratchet=$author;$target=$this->credentials['meJid'];
        if(is_string($this->credentials['meLid'] ?? null)) {
            $own=Jid::parse($this->credentials['meLid']);$target=(string)new Jid($own->user,$own->server,5);
            if($author->server==='s.whatsapp.net')$ratchet=new Jid($own->user,$own->server,$author->device);
        }
        $phone=$this->peers([(string)$ratchet])[(string)$ratchet];$key=$this->store->generatePreKeys(1)[0];
        $phone['signal']->ensureSession($target,self::bundle($this->credentials,$key));
        $body=MessageCodec::text('Own receipt fixture');if($wrapped)$body=MessageCodec::deviceSent($body,$destination);
        $encrypted=$phone['signal']->encrypt($target,MessageCodec::pad($body));
        $attrs=['id'=>'own-receipt','from'=>$from];if($participant!==null)$attrs['participant']=$participant;if($stanzaRecipient)$attrs['recipient']=$destination;
        return new BinaryNode('message',$attrs,[new BinaryNode('enc',['type'=>$encrypted['type'],'v'=>'2'],$encrypted['ciphertext'])]);
    }

    public function testContactLidResolutionRoutesFanoutAndPreservesPhoneCrossReference(): void
    {
        $pn = '5522000000000@s.whatsapp.net'; $canonicalPn = '5522900000000@s.whatsapp.net'; $lid = '77770000000@lid';
        $own = '88880000000@lid'; $this->credentials['meLid'] = $own;
        $peers = $this->peers([$lid,$own]); $lookups = 0;
        $service = $this->service(function (BinaryNode $node) use ($pn, $canonicalPn, $lid, $own, $peers, &$lookups):BinaryNode {
            if ($node->tag === 'iq' && ($node->attrs['xmlns'] ?? '') === 'usync') {
                if ($node->child('usync')->child('query')->child('contact') !== null) {
                    ++$lookups;
                    self::assertSame('+5522000000000', $node->child('usync')->child('list')->child('user')->child('contact')->content);
                    return new BinaryNode('iq', ['type'=>'result'], [new BinaryNode('usync', [], [new BinaryNode('list', [], [
                        new BinaryNode('user', ['jid'=>$canonicalPn], [new BinaryNode('contact', ['type'=>'in'], '+5522000000000'),new BinaryNode('lid', ['val'=>$lid])])
                    ])])]);
                }
                return self::devices([$lid=>[0],$own=>[0,5]]);
            }
            if ($node->tag === 'iq') return self::bundles($peers);
            self::assertSame($lid, $node->attrs['to']);
            self::assertSame($canonicalPn, $node->attrs['peer_recipient_pn']);
            foreach ($node->child('participants')->content as $to) {
                $enc = $to->child('enc');
                $plaintext = $peers[$to->attrs['jid']]['signal']->decrypt('88880000000:5@lid', $enc->attrs['type'], $enc->content);
                self::assertSame('LID text', MessageCodec::decode(MessageCodec::unpad($plaintext))['text']);
            }
            return new BinaryNode('ack', ['class'=>'message','id'=>$node->attrs['id']]);
        });
        self::assertSame($lid, $service->sendText($pn, 'LID text')['to']);
        $service->sendText($pn, 'LID text');
        self::assertSame(1, $lookups, 'A validated PN/LID mapping persists between sends');
        self::assertSame($lid, $this->store->get('message-pn-to-lid', $pn));
        self::assertSame($lid, $this->store->get('message-pn-to-lid', $canonicalPn));
        self::assertSame($canonicalPn, $this->store->get('message-lid-to-pn', $lid));
    }

    public function testExistingPnRatchetAuthenticatesLidReplyBeforeAliasMigration(): void
    {
        $pn = '5522000000000@s.whatsapp.net'; $lid = '77770000000@lid';
        $peer = $this->peers([$pn])[$pn];
        $this->signal->ensureSession($pn, self::bundle($peer['credentials'], $peer['key']));
        $first = $this->signal->encrypt($pn, MessageCodec::pad(MessageCodec::text('first')));
        $peer['signal']->decrypt($this->credentials['meJid'], $first['type'], $first['ciphertext']);
        $reply = $peer['signal']->encrypt($this->credentials['meJid'], MessageCodec::pad(MessageCodec::text('reply')));
        self::assertSame('msg', $reply['type']);
        $node = new BinaryNode('message', ['id'=>'lid-reply','from'=>$lid,'sender_pn'=>$pn], [
            new BinaryNode('enc', ['type'=>$reply['type'],'v'=>'2'], $reply['ciphertext'])
        ]);
        $this->service(static fn():BinaryNode => throw new \LogicException('No query'))->handleNode($node);
        self::assertSame('message', $this->events[0][0]);
        self::assertSame('reply', $this->events[0][1]['text']);
        self::assertSame($lid, $this->store->get('message-pn-to-lid', $pn));
        self::assertSame($this->store->getSession($pn), $this->store->getSession($lid));
    }

    public function testInterruptedApplicationCallbackReplaysCommittedInboxAfterDatabaseReopen(): void
    {
        $peerJid = '5522000000000@s.whatsapp.net'; $peer = $this->peers([$peerJid])[$peerJid];
        $key = $this->store->generatePreKeys(1)[0];
        $peer['signal']->ensureSession($this->credentials['meJid'], self::bundle($this->credentials, $key));
        $enc = $peer['signal']->encrypt($this->credentials['meJid'], MessageCodec::pad(MessageCodec::text('recover me')));
        $node = new BinaryNode('message', ['id'=>'pending-app','from'=>$peerJid], [new BinaryNode('enc', ['type'=>$enc['type']], $enc['ciphertext'])]);
        $service = new MessageService($this->signal, $this->store, fn():array => $this->credentials,
            static function ():void {}, static fn():BinaryNode => throw new \LogicException('No query'),
            function (BinaryNode $node):void { $this->sent[] = $node; },
            static function ():void { throw new \RuntimeException('application stopped'); });
        try { $service->handleNode($node); self::fail('Expected callback interruption'); }
        catch (\RuntimeException $error) { self::assertSame('application stopped', $error->getMessage()); }
        self::assertTrue($this->signal->hasSession($peerJid), 'Authenticated ratchet already committed');
        unset($service, $this->signal);
        $this->store->close();
        $this->store = new SignalStore($this->paths[0]);
        $this->signal = new SignalProtocol($this->store, $this->credentials);
        $restarted = $this->service(static fn():BinaryNode => throw new \LogicException('No query'));
        $restarted->drainPending();
        self::assertCount(1, $this->events);
        self::assertSame('recover me', $this->events[0][1]['text']);
        self::assertTrue($this->events[0][1]['replayed']);
        $restarted->drainPending();
        $restarted->handleNode($node);
        self::assertCount(1, $this->events, 'Delivery marker suppresses repeat processing after recovery');
        self::assertSame('[]', $this->store->get('message-inbox-pending', 'index'));
        self::assertTrue(ctype_digit($this->store->get('message-inbox', hash('sha256', $peerJid."\0pending-app"))));
    }

    public function testCloseDuringFailedTransportDefersUntilUnknownOutboxStatusPersists(): void
    {
        $jid = '5522000000000@s.whatsapp.net'; $peers = $this->peers([$jid]);
        $service = null; $id = null;
        $service = $this->service(static function (BinaryNode $node) use ($jid, $peers, &$service, &$id):BinaryNode {
            if ($node->tag === 'iq' && ($node->attrs['xmlns'] ?? '') === 'usync') return self::devices([$jid=>[0]]);
            if ($node->tag === 'iq') return self::bundles($peers);
            $id = $node->attrs['id'];
            $service->close();
            throw new \RuntimeException('socket closed');
        });
        try { $service->sendText($jid, 'persist before close'); self::fail('Expected transport close'); }
        catch (\RuntimeException $error) { self::assertSame('socket closed', $error->getMessage()); }
        $fresh = new SignalStore($this->paths[0]);
        $record = json_decode($fresh->get('message-outbox', $id), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('unknown', $record['metadata']['status']);
        $fresh->close();
        $this->expectException(\LogicException::class);
        $service->drainPending();
    }

    public function testDirectParticipantSelectsItsDeviceRatchetWithoutChangingChatAddress(): void
    {
        $peerJid = '5522000000000:2@s.whatsapp.net'; $peer = $this->peers([$peerJid])[$peerJid];
        $key = $this->store->generatePreKeys(1)[0];
        $peer['signal']->ensureSession($this->credentials['meJid'], self::bundle($this->credentials, $key));
        $enc = $peer['signal']->encrypt($this->credentials['meJid'], MessageCodec::pad(MessageCodec::text('device two')));
        $node = new BinaryNode('message', ['id'=>'participant','from'=>'5522000000000@s.whatsapp.net','participant'=>$peerJid], [
            new BinaryNode('enc', ['type'=>$enc['type']], $enc['ciphertext'])
        ]);
        $this->service(static fn():BinaryNode => throw new \LogicException('No query'))->handleNode($node);
        self::assertSame('device two', $this->events[0][1]['text']);
        self::assertSame(2, $this->events[0][1]['senderDevice']);
        self::assertSame('5522000000000@s.whatsapp.net', $this->events[0][1]['chat']);
        self::assertTrue($this->signal->hasSession($peerJid));
        self::assertFalse($this->signal->hasSession('5522000000000@s.whatsapp.net'));
    }

    public function testStructuredMessagesUseDirectSignalFanoutAndCorrectEnvelopeAttributes():void
    {
        $jid='5522000000000@s.whatsapp.net'; $peers=$this->peers([$jid]); $decoded=[]; $nodes=[];
        $service=$this->service(function(BinaryNode $node)use($jid,$peers,&$decoded,&$nodes):BinaryNode {
            if($node->tag==='iq' && ($node->attrs['xmlns'] ?? '')==='usync') return self::devices([$jid=>[0]]);
            if($node->tag==='iq') return self::bundles($peers);
            $enc=$node->child('participants')->content[0]->child('enc');
            $raw=$peers[$jid]['signal']->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content);
            $decoded[]=MessageCodec::decode(MessageCodec::unpad($raw)); $nodes[]=$node;
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        });
        $service->sendMessage($jid,['type'=>'location','latitude'=>-23.55,'longitude'=>-46.63,'name'=>'São Paulo']);
        $service->sendMessage($jid,['type'=>'reaction','emoji'=>'👍','target'=>['id'=>'parent','fromMe'=>false]]);
        $service->sendMessage($jid,['type'=>'contact','displayName'=>'Maik','vcard'=>"BEGIN:VCARD\nVERSION:3.0\nFN:Maik\nEND:VCARD"]);
        $service->sendMessage($jid,['type'=>'edit','target'=>['id'=>'mine'],'text'=>'Corrigida']);
        $service->sendMessage($jid,['type'=>'raw','message'=>['listMessage'=>['title'=>'Escolha','listType'=>'SINGLE_SELECT','buttonText'=>'Abrir','sections'=>[['title'=>'Opções','rows'=>[['rowId'=>'1','title'=>'Uma']]]]]]]);
        self::assertSame(['location','reaction','contact','protocol','list'],array_column($decoded,'kind'));
        self::assertSame(-23.55,$decoded[0]['data']['degreesLatitude']);
        self::assertSame('reaction',$nodes[1]->attrs['type']);
        self::assertSame('hide',$nodes[1]->child('participants')->content[0]->child('enc')->attrs['decrypt-fail']);
        self::assertSame('1',$nodes[3]->attrs['edit']);
        self::assertSame('product_list',$nodes[4]->child('biz')->child('list')->attrs['type']);
    }

    public function testNativeButtonsFanoutCopiesContextToOwnDevicesAndPersistsExactBusinessEnvelope():void
    {
        $jid='5522000000000@s.whatsapp.net'; $own=Jid::parse($this->credentials['meJid'])->bare();
        $peers=$this->peers([$jid,$own]); $wire=null; $attempt=0; $secret=null;
        $service=$this->service(function(BinaryNode $node)use($jid,$own,$peers,&$wire,&$attempt,&$secret):BinaryNode {
            if($node->tag==='iq' && ($node->attrs['xmlns'] ?? '')==='usync') return self::devices([$jid=>[0],$own=>[0,5]]);
            if($node->tag==='iq') return self::bundles($peers);
            self::assertSame('false',$node->attrs['device_fanout']);
            self::assertSame('text',$node->attrs['type']);
            self::assertSame('2',$node->child('biz')->attrs['actual_actors']);
            self::assertSame('2',$node->child('biz')->attrs['host_storage']);
            self::assertTrue(ctype_digit($node->child('biz')->attrs['privacy_mode_ts']));
            self::assertLessThanOrEqual(time(),(int)$node->child('biz')->attrs['privacy_mode_ts']);
            self::assertSame(['v'=>'9','name'=>'mixed'],$node->child('biz')->child('interactive')->child('native_flow')->attrs);
            $record=json_decode($this->store->get('message-outbox',$node->attrs['id']),true);
            self::assertSame((new BinaryCodec())->encode($node),base64_decode($record['node'],true));
            if($attempt++>0) {
                self::assertSame($wire,(new BinaryCodec())->encode($node));
                return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
            }
            $wire=(new BinaryCodec())->encode($node);
            foreach($node->child('participants')->content as $recipient) {
                $enc=$recipient->child('enc');
                self::assertArrayNotHasKey('mediatype',$enc->attrs);
                $raw=$peers[$recipient->attrs['jid']]['signal']->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content);
                $decoded=MessageCodec::decode(MessageCodec::unpad($raw));
                self::assertSame('interactive',$decoded['kind']);
                self::assertArrayNotHasKey('messageVersion',$decoded['data']['nativeFlowMessage']);
                self::assertArrayNotHasKey('messageParamsJson',$decoded['data']['nativeFlowMessage']);
                $context=$decoded['messageContextInfo'];
                self::assertSame(32,strlen($context['messageSecret']));
                $secret ??= $context['messageSecret']; self::assertSame($secret,$context['messageSecret']);
                if($recipient->attrs['jid']===$own) {
                    self::assertSame($context,$decoded['proto']['messageContextInfo']);
                    self::assertSame($context,$decoded['proto']['deviceSentMessage']['message']['messageContextInfo']);
                    self::assertSame($jid,$decoded['destination']);
                }
            }
            self::assertSame($secret,BinaryData::decode($this->store->get('message-secrets',hash('sha256',$jid."\0".$node->attrs['id'])))['secret']);
            throw new \RuntimeException('transport timeout after acceptance');
        });
        try { $service->sendMessage($jid,['type'=>'buttons','contentText'=>'Escolha','buttons'=>[['buttonId'=>'sim','buttonText'=>['displayText'=>'Sim']]]]); self::fail('Expected timeout'); }
        catch(\RuntimeException $error) { self::assertSame('transport timeout after acceptance',$error->getMessage()); }
        $id=$this->events[0][1]['id'];
        self::assertSame('unknown',$service->getMessageStatus($id)['status']);
        self::assertSame('server_ack',$service->retryMessage($id)['status']);
        self::assertSame(2,$attempt);
    }

    public function testButtonTextFallbackUsesNormalEncryptedTextWithoutBusinessNodes():void
    {
        $jid='5522000000000@s.whatsapp.net'; $peers=$this->peers([$jid]);
        $service=$this->service(function(BinaryNode $node)use($jid,$peers):BinaryNode {
            if($node->tag==='iq' && ($node->attrs['xmlns'] ?? '')==='usync') return self::devices([$jid=>[0]]);
            if($node->tag==='iq') return self::bundles($peers);
            self::assertSame('text',$node->attrs['type']);
            self::assertNull($node->child('biz'));
            self::assertNull($node->child('bot'));
            $enc=$node->child('participants')->content[0]->child('enc');
            $decoded=MessageCodec::decode(MessageCodec::unpad($peers[$jid]['signal']->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content)));
            self::assertSame('text',$decoded['kind']);
            self::assertStringContainsString('1. Sim',$decoded['text']);
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        });
        $content=['type'=>'buttons','buttonMode'=>'text','contentText'=>'Escolha','buttons'=>[['buttonId'=>'1','buttonText'=>['displayText'=>'Sim']]]];
        self::assertSame('server_ack',$service->sendMessage($jid,$content)['status']);
    }

    public function testMediaUploadsOnceThenExactOutboxRetryAndVerifiedDownload():void
    {
        $jid='5522000000000@s.whatsapp.net'; $peers=$this->peers([$jid]); $uploads=0; $cipher=''; $attempt=0; $wire=null; $received=null;
        $query=function(BinaryNode $node)use($jid,$peers,&$attempt,&$wire,&$received):BinaryNode {
            if($node->tag==='iq' && ($node->attrs['xmlns'] ?? '')==='usync') return self::devices([$jid=>[0]]);
            if($node->tag==='iq') return self::bundles($peers);
            if($attempt++===0) { $wire=(new BinaryCodec())->encode($node); throw new \RuntimeException('timeout'); }
            self::assertSame($wire,(new BinaryCodec())->encode($node));
            $enc=$node->child('participants')->content[0]->child('enc');
            self::assertSame('document',$enc->attrs['mediatype']);
            $raw=$peers[$jid]['signal']->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content);
            $received=MessageCodec::decode(MessageCodec::unpad($raw));
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        };
        $media=new MediaTransfer(static fn():BinaryNode=>new BinaryNode('iq',['type'=>'result'],[
            new BinaryNode('media_conn',['auth'=>'test','ttl'=>'3600'],[new BinaryNode('host',['hostname'=>'mmg.whatsapp.net'])])
        ]),function(array $request)use(&$uploads,&$cipher):array {
            if($request['method']==='POST') { ++$uploads; $cipher=$request['body']; return ['status'=>200,'body'=>'{"url":"https://mmg.whatsapp.net/file","direct_path":"/file"}']; }
            return ['status'=>200,'body'=>$cipher];
        });
        $service=$this->service($query,$media);
        try { $service->sendMessage($jid,['type'=>'document','media'=>['bytes'=>'%PDF-1.7 fixture'],'fileName'=>'teste.pdf']); self::fail('Expected timeout'); }
        catch(\RuntimeException $error) { self::assertSame('timeout',$error->getMessage()); }
        $id=$this->events[0][1]['id'];
        $service->retryMessage($id);
        self::assertSame(1,$uploads);
        self::assertSame('document',$received['kind']);
        self::assertSame('teste.pdf',$received['data']['fileName']);
        self::assertSame('%PDF-1.7 fixture',$service->downloadMedia($received));
    }

    public function testReceivedLocationWithBinaryThumbnailReplaysAfterCallbackFailure():void
    {
        $jid='5522000000000:2@s.whatsapp.net'; $peer=$this->peers([$jid])[$jid];
        $key=$this->store->generatePreKeys(1)[0];
        $peer['signal']->ensureSession($this->credentials['meJid'],self::bundle($this->credentials,$key));
        $built=ContentBuilder::build(['type'=>'location','latitude'=>-23.55,'longitude'=>-46.63,'jpegThumbnail'=>"\0\xff\x80"],$this->credentials['meJid']);
        $encrypted=$peer['signal']->encrypt($this->credentials['meJid'],MessageCodec::pad($built['bytes']));
        $node=new BinaryNode('message',['from'=>$jid,'id'=>'binary-location'],[new BinaryNode('enc',['type'=>$encrypted['type']],$encrypted['ciphertext'])]);
        $query=static fn():BinaryNode=>throw new \LogicException('No queries during incoming');
        $failing=new MessageService($this->signal,$this->store,fn():array=>$this->credentials,static function():void{},$query,static function():void{},static function():void{throw new \RuntimeException('callback failed');});
        try { $failing->handleNode($node); self::fail('Expected callback failure'); } catch(\RuntimeException $error) { self::assertSame('callback failed',$error->getMessage()); }
        $service=$this->service($query); $service->drainPending();
        self::assertSame('location',$this->events[0][1]['type']);
        self::assertSame(-23.55,$this->events[0][1]['data']['degreesLatitude']);
        self::assertSame("\0\xff\x80",$this->events[0][1]['data']['jpegThumbnail']);
        self::assertTrue($this->events[0][1]['replayed']);
        $service->handleNode($node); self::assertCount(1,$this->events);
    }

    public function testOutgoingPollSecretSurvivesRestartAndAuthenticatesIncomingVote():void
    {
        $jid='5522000000000@s.whatsapp.net'; $peers=$this->peers([$jid]); $poll=null;
        $query=function(BinaryNode $node)use($jid,$peers,&$poll):BinaryNode {
            if($node->tag==='iq' && ($node->attrs['xmlns'] ?? '')==='usync') return self::devices([$jid=>[0]]);
            if($node->tag==='iq') return self::bundles($peers);
            $enc=$node->child('participants')->content[0]->child('enc');
            $poll=MessageCodec::decode(MessageCodec::unpad($peers[$jid]['signal']->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content)));
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        };
        $service=$this->service($query);
        $result=$service->sendMessage($jid,['type'=>'poll','name'=>'Dia?','options'=>['Hoje','Amanhã']]);
        $secret=$poll['messageContextInfo']['messageSecret'];
        self::assertSame($secret,BinaryData::decode($this->store->get('message-secrets',hash('sha256',$jid."\0".$result['id'])))['secret']);
        unset($service,$this->signal); $this->store->close(); $this->store=new SignalStore($this->paths[0]); $this->signal=new SignalProtocol($this->store,$this->credentials);
        $vote=ContentBuilder::build(['type'=>'poll-vote','poll'=>['id'=>$result['id'],'authorJid'=>$this->credentials['meJid'],'messageSecret'=>$secret],'selectedOptionNames'=>['Hoje']],$this->credentials['meJid'],['meJid'=>$jid,'outgoingId'=>'vote-1']);
        $encrypted=$peers[$jid]['signal']->encrypt($this->credentials['meJid'],MessageCodec::pad($vote['bytes']));
        $this->service(static fn():BinaryNode=>throw new \LogicException('No query'))->handleNode(new BinaryNode('message',['from'=>$jid,'id'=>'vote-1'],[new BinaryNode('enc',['type'=>$encrypted['type']],$encrypted['ciphertext'])]));
        $event=$this->events[1][1];
        self::assertSame('poll-vote',$event['type']); self::assertFalse($event['encrypted']);
        self::assertSame([hash('sha256','Hoje',true)],$event['data']['selectedOptions']);
    }

    public function testIncomingEventSecretAutomaticallySuppliesOutgoingResponse():void
    {
        $jid='5522000000000@s.whatsapp.net'; $peer=$this->peers([$jid])[$jid];
        $key=$this->store->generatePreKeys(1)[0];
        $peer['signal']->ensureSession($this->credentials['meJid'],self::bundle($this->credentials,$key));
        $event=ContentBuilder::build(['type'=>'event','name'=>'Reunião','startTime'=>1790000000],$this->credentials['meJid']);
        $encrypted=$peer['signal']->encrypt($this->credentials['meJid'],MessageCodec::pad($event['bytes']));
        $response=null;
        $service=$this->service(function(BinaryNode $node)use($jid,$peer,$event,&$response):BinaryNode {
            if($node->tag==='iq') return self::devices([$jid=>[0]]);
            $enc=$node->child('participants')->content[0]->child('enc');
            $decoded=MessageCodec::decode(MessageCodec::unpad($peer['signal']->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content)));
            $addon=AddonCrypto::identify($decoded['proto']);
            $plain=AddonCrypto::decrypt($event['secrets']['messageSecret'],'event-1',$jid,ContentBuilder::bare($this->credentials['meJid']),$addon['modificationType'],$addon['encPayload'],$addon['encIv']);
            $response=AddonCrypto::decodePlaintext('event-response',$plain);
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        });
        $service->handleNode(new BinaryNode('message',['from'=>$jid,'id'=>'event-1'],[new BinaryNode('enc',['type'=>$encrypted['type']],$encrypted['ciphertext'])]));
        self::assertSame('event',$this->events[0][1]['type']);
        $service->sendMessage($jid,['type'=>'event-response','event'=>['id'=>'event-1'],'response'=>'going']);
        self::assertSame(1,$response['response']);
    }

    public function testReceiptStatesPersistMonotonicallyAndIgnoreUnauthorizedSenders():void
    {
        $jid='5522000000000@s.whatsapp.net'; $peers=$this->peers([$jid]);
        $service=$this->service(function(BinaryNode $node)use($jid,$peers):BinaryNode {
            if ($node->tag==='iq' && ($node->attrs['xmlns'] ?? '')==='usync') return self::devices([$jid=>[0]]);
            if ($node->tag==='iq') return self::bundles($peers);
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        });
        $result=$service->sendText($jid,'Receipt'); $id=$result['id'];
        $service->handleNode(new BinaryNode('receipt',['from'=>'5533000000000@s.whatsapp.net','id'=>$id,'type'=>'read']));
        self::assertSame('server_ack',$service->getMessageStatus($id)['status']);
        $service->handleNode(new BinaryNode('receipt',['from'=>$jid,'id'=>$id,'type'=>'read','t'=>'200']));
        $service->handleNode(new BinaryNode('receipt',['from'=>$jid,'id'=>$id,'t'=>'100']));
        self::assertSame('read',$service->getMessageStatus($id)['status']);
        self::assertSame('read',$service->history()->store->getMessage($jid,$id)['status']);
        self::assertSame('read',$service->retryMessage($id)['status']);
        $service->handleNode(new BinaryNode('receipt',['from'=>$jid,'id'=>$id,'type'=>'played','t'=>'300']));
        self::assertSame('played',$service->getMessageStatus($id)['receipts'][$jid]['status']);
    }

    public function testRecipientRetryIsDeferredAuthorizedBoundedAndReencryptedForOneDevice():void
    {
        $jid='5522000000000@s.whatsapp.net'; $peers=$this->peers([$jid]); $retryNodes=[]; $calls=0; $initial=null;
        $service=$this->service(function(BinaryNode $node)use($jid,$peers,&$retryNodes,&$calls,&$initial):BinaryNode {
            ++$calls;
            if ($node->tag==='iq' && ($node->attrs['xmlns'] ?? '')==='usync') return self::devices([$jid=>[0]]);
            if ($node->tag==='iq') return self::bundles($peers);
            if ($node->child('participants')!==null) $initial=$node->child('participants')->content[0]->child('enc')->content;
            else {
                self::assertSame($jid,$node->attrs['to']); self::assertSame('1',$node->child('enc')->attrs['count']);
                $enc=$node->child('enc');
                $plain=MessageCodec::unpad($peers[$jid]['signal']->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content));
                self::assertSame('Retry body',MessageCodec::decode($plain)['text']);
                self::assertNotSame($initial,$enc->content);
                self::assertCount(1,$this->store->entries('recipient-retry-pending'),'Ciphertext must commit before transport');
                $retryNodes[]=$node;
            }
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        });
        $id=$service->sendText($jid,'Retry body')['id']; $initialCalls=$calls;
        $request=new BinaryNode('receipt',['id'=>$id,'from'=>$jid,'type'=>'retry'],[
            new BinaryNode('retry',['id'=>$id,'count'=>'1']),new BinaryNode('registration',[],pack('N',$peers[$jid]['credentials']['registrationId']))]);
        $service->handleNode($request); self::assertSame($initialCalls,$calls); self::assertCount(0,$retryNodes);
        $service->drainPending(); self::assertCount(1,$retryNodes);
        $service->handleNode($request); $service->drainPending(); self::assertCount(1,$retryNodes);
        $service->handleNode(new BinaryNode('receipt',['id'=>$id,'from'=>'5533000000000@s.whatsapp.net','type'=>'retry'],$request->content));
        $service->drainPending(); self::assertCount(1,$retryNodes);
        $service->handleNode(new BinaryNode('receipt',['id'=>$id,'from'=>$jid,'type'=>'retry'],[new BinaryNode('retry',['count'=>'99'])]));
        $service->drainPending(); self::assertCount(1,$retryNodes);
    }

    public function testRetryFailureReusesPersistedCiphertextAndRatchetAfterRestart():void
    {
        $jid='5522000000000@s.whatsapp.net'; $peers=$this->peers([$jid]); $first=null; $retryCount=0;
        $query=function(BinaryNode $node)use($jid,$peers,&$first,&$retryCount):BinaryNode {
            if ($node->tag==='iq' && ($node->attrs['xmlns'] ?? '')==='usync') return self::devices([$jid=>[0]]);
            if ($node->tag==='iq') return self::bundles($peers);
            if ($node->child('enc')!==null) {
                $bytes=(new BinaryCodec())->encode($node); ++$retryCount;
                if ($first===null) { $first=$bytes; throw new \RuntimeException('Connection dropped'); }
                self::assertSame($first,$bytes);
            }
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        };
        $service=$this->service($query); $id=$service->sendText($jid,'Persist retry')['id'];
        $service->handleNode(new BinaryNode('receipt',['id'=>$id,'from'=>$jid,'type'=>'retry'],[
            new BinaryNode('retry',['id'=>$id,'count'=>'1']),new BinaryNode('registration',[],pack('N',$peers[$jid]['credentials']['registrationId']))]));
        $service->drainPending(); self::assertSame(1,$retryCount);
        $pending=$this->store->entries('recipient-retry-pending'); $key=array_key_first($pending); $job=BinaryData::decode($pending[$key]);
        $state=$this->store->getSession($jid); $job['nextAttempt']=0; $this->store->set('recipient-retry-pending',$key,BinaryData::encode($job));
        unset($service,$this->signal); $this->store->close(); $this->store=new SignalStore($this->paths[0]); $this->signal=new SignalProtocol($this->store,$this->credentials);
        $this->service($query)->drainPending(); self::assertSame(2,$retryCount); self::assertSame($state,$this->store->getSession($jid));
        self::assertSame([],$this->store->entries('recipient-retry-pending'));
    }

    public function testRetrySignedBundleRestoresRecipientAfterSessionLoss():void
    {
        $jid='5522000000000@s.whatsapp.net'; $peers=$this->peers([$jid]);
        $credentials=$peers[$jid]['credentials']; $freshStore=new SignalStore($this->path());
        $freshKey=$freshStore->generatePreKeys(1)[0]; $freshSignal=new SignalProtocol($freshStore,$credentials); $received=null;
        $service=$this->service(function(BinaryNode $node)use($jid,$peers,$freshSignal,&$received):BinaryNode {
            if ($node->tag==='iq' && ($node->attrs['xmlns'] ?? '')==='usync') return self::devices([$jid=>[0]]);
            if ($node->tag==='iq') return self::bundles($peers);
            if (($enc=$node->child('enc'))!==null) {
                $received=MessageCodec::decode(MessageCodec::unpad($freshSignal->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content)));
            }
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        });
        $id=$service->sendText($jid,'Recover session')['id']; $signed=$credentials['signedPreKey'];
        $service->handleNode(new BinaryNode('receipt',['id'=>$id,'from'=>$jid,'type'=>'retry'],[
            new BinaryNode('retry',['id'=>$id,'count'=>'2']),new BinaryNode('registration',[],pack('N',$credentials['registrationId'])),
            new BinaryNode('keys',[],[
                new BinaryNode('identity',[],$credentials['identityKeyPair']['public']),
                new BinaryNode('skey',[],[new BinaryNode('id',[],substr(pack('N',$signed['id']),1)),new BinaryNode('value',[],$signed['public']),new BinaryNode('signature',[],$signed['signature'])]),
                new BinaryNode('key',[],[new BinaryNode('id',[],substr(pack('N',$freshKey['id']),1)),new BinaryNode('value',[],$freshKey['public'])]),
            ])]));
        $service->drainPending(); self::assertSame('Recover session',$received['text']);
        self::assertSame([],$this->store->entries('recipient-retry-pending'));
    }

    public function testReadReceiptQueueSurvivesRepeatedUncertainWritesAndCanAcceptLateAck():void
    {
        $service=new MessageService($this->signal,$this->store,fn():array=>$this->credentials,static function():void{},
            static fn():BinaryNode=>throw new \LogicException('No query'),static function():void{throw new \RuntimeException('Offline');},static function():void{});
        $keys=[['id'=>'one','remoteJid'=>'100-200@g.us','participant'=>'5522000000000:2@s.whatsapp.net'],
            ['id'=>'two','remoteJid'=>'100-200@g.us','participant'=>'5522000000000:2@s.whatsapp.net']];
        $service->sendReceipt($keys,'read'); $entries=$this->store->entries('receipt-queue'); self::assertCount(1,$entries);
        $record=BinaryData::decode(reset($entries)); $node=(new BinaryCodec())->decode($record['node']);
        self::assertSame('read',$node->attrs['type']); self::assertSame('two',$node->child('list')->content[0]->attrs['id']);
        $record['nextAttempt']=0; $this->store->set('receipt-queue',array_key_first($entries),BinaryData::encode($record));
        $service=new MessageService($this->signal,$this->store,fn():array=>$this->credentials,static function():void{},
            static fn():BinaryNode=>throw new \LogicException('No query'),
            function(BinaryNode $receipt):void{$this->sent[]=$receipt;throw new \RuntimeException('Synthetic partial write');},static function():void{});
        $service->drainPending(); self::assertCount(1,$this->sent);
        self::assertCount(1,$this->store->entries('receipt-queue'));
        $service->handleNode(new BinaryNode('ack',['class'=>'receipt','id'=>'one','from'=>'100-200@g.us','type'=>'read','participant'=>'5522000000000:2@s.whatsapp.net']));
        self::assertSame([],$this->store->entries('receipt-queue'));
    }

    public function testHistoryRequestIsEncryptedPeerProtocolToPrimaryPhone():void
    {
        $own='5511000000000@s.whatsapp.net'; $peers=$this->peers([$own]); $decoded=null;
        $service=$this->service(function(BinaryNode $node)use($peers,$own,&$decoded):BinaryNode {
            if ($node->tag==='iq') return self::bundles($peers);
            self::assertSame('peer',$node->attrs['category']); self::assertSame('high_force',$node->attrs['push_priority']); self::assertSame($own,$node->attrs['to']);
            $enc=$node->child('enc'); $decoded=MessageCodec::decode(MessageCodec::unpad($peers[$own]['signal']->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content)));
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        });
        $result=$service->requestHistorySync(['chatJid'=>'5522000000000@s.whatsapp.net','oldestMsgId'=>'anchor','oldestMsgFromMe'=>false,'oldestMsgTimestampMs'=>100000,'count'=>50]);
        self::assertSame('server_ack',$result['status']);
        $body=$decoded['proto']['protocolMessage']['peerDataOperationRequestMessage']['historySyncOnDemandRequest'];
        self::assertSame(50,$body['onDemandMsgCount']); self::assertSame('anchor',$body['oldestMsgId']); self::assertTrue($body['supportInlineResponse']);
    }

    public function testBroadcastUsesRealSenderKeyEncryptionAndCachesDistributionForRecipient():void
    {
        $jid='5522000000000@s.whatsapp.net'; $own='5511000000000@s.whatsapp.net'; $peers=$this->peers([$jid,$own]); $received=[];
        $receiver=new MessageService($peers[$jid]['signal'],$peers[$jid]['store'],static fn():array=>$peers[$jid]['credentials'],static function():void{},
            static fn():BinaryNode=>throw new \LogicException('No receiving query'),static function():void{},function(string $event,array $payload)use(&$received):void{if($event==='message')$received[]=$payload;});
        $service=$this->service(function(BinaryNode $node)use($jid,$own,$peers,$receiver):BinaryNode {
            if ($node->tag==='iq' && ($node->attrs['xmlns'] ?? '')==='usync') return self::devices([$jid=>[0],$own=>[0,5]]);
            if ($node->tag==='iq') return self::bundles($peers);
            self::assertSame('12345@broadcast',$node->attrs['to']); self::assertSame('skmsg',$node->child('enc')->attrs['type']);
            $children=[$node->child('enc')];
            foreach ($node->child('participants')?->content ?? [] as $participant) if ($participant->attrs['jid']===$jid) $children[]=$participant->child('enc');
            $receiver->handleNode(new BinaryNode('message',['from'=>'12345@broadcast','participant'=>$this->credentials['meJid'],'id'=>$node->attrs['id']],$children));
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        });
        $service->sendBroadcast('12345@broadcast','Broadcast one',[$jid]);
        $service->sendBroadcast('12345@broadcast','Broadcast two',[$jid]);
        self::assertSame(['Broadcast one','Broadcast two'],array_column($received,'text'));
        self::assertSame('12345@broadcast',$received[0]['chat']);
    }

    public function testStatusUsesLidSenderAndExplicitAudienceWithCachedPrimaryAckHints():void
    {
        $this->credentials['meLid']='100:5@lid'; $jid='900@lid'; $own='100@lid'; $peers=$this->peers([$jid,$own]); $nodes=[]; $received=[];
        $receiver=new MessageService($peers[$jid]['signal'],$peers[$jid]['store'],static fn():array=>$peers[$jid]['credentials'],static function():void{},
            static fn():BinaryNode=>throw new \LogicException('No receiving query'),static function():void{},function(string $event,array $payload)use(&$received):void{if($event==='message')$received[]=$payload;});
        $service=$this->service(function(BinaryNode $node)use($jid,$own,$peers,$receiver,&$nodes):BinaryNode {
            if ($node->tag==='iq' && ($node->attrs['xmlns'] ?? '')==='usync') return self::devices([$jid=>[0],$own=>[0,5]]);
            if ($node->tag==='iq') return self::bundles($peers);
            self::assertSame('status@broadcast',$node->attrs['to']); self::assertSame('allowlist',$node->child('meta')->attrs['status_setting']);
            self::assertArrayNotHasKey('phash',$node->attrs); self::assertSame('skmsg',$node->child('enc')->attrs['type']);
            $children=[$node->child('enc')];
            foreach ($node->child('participants')?->content ?? [] as $participant) if ($participant->attrs['jid']===$jid && $participant->child('enc')!==null) $children[]=$participant->child('enc');
            $receiver->handleNode(new BinaryNode('message',['from'=>'status@broadcast','participant'=>'100:5@lid','id'=>$node->attrs['id']],$children));
            $nodes[]=$node;
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        });
        $service->sendBroadcast('status@broadcast','Status one',[$jid],['statusSetting'=>'allowlist']);
        $service->sendBroadcast('status@broadcast','Status two',[$jid],['statusSetting'=>'allowlist']);
        self::assertSame(['Status one','Status two'],array_column($received,'text'));
        $participants=$nodes[1]->child('participants')->content;
        self::assertSame(['100@lid','900@lid'],array_map(static fn(BinaryNode $node):string=>$node->attrs['jid'],$participants));
        foreach ($participants as $participant) self::assertNull($participant->child('enc'));
    }

    public function testBotPromptUsesStandardDirectSignalParticipantAndOwnDeviceEnvelope():void
    {
        $bot='867051314767696@bot'; $own='5511000000000@s.whatsapp.net'; $peers=$this->peers([$bot,$own]); $received=[];
        $service=$this->service(function(BinaryNode $node)use($bot,$own,$peers,&$received):BinaryNode {
            if ($node->tag==='iq' && ($node->attrs['xmlns'] ?? '')==='usync') {
                self::assertSame([$own],array_map(static fn(BinaryNode $n):string=>$n->attrs['jid'],$node->child('usync')->child('list')->content));
                return self::devices([$own=>[0,5]]);
            }
            if ($node->tag==='iq') return self::bundles($peers);
            self::assertSame($bot,$node->attrs['to']); self::assertNull($node->child('bot'));
            foreach ($node->child('participants')->content as $participant) {
                $jid=$participant->attrs['jid']; $enc=$participant->child('enc');
                $received[$jid]=MessageCodec::decode(MessageCodec::unpad($peers[$jid]['signal']->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content)));
            }
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        });
        $result=$service->sendBotPrompt($bot,'Olá IA',['aiThreadId'=>'my-thread']);
        self::assertSame('my-thread',$result['aiThreadId']); self::assertSame('Olá IA',$received[$bot]['text']);
        self::assertSame($bot,$received[$own]['destination']);
        $secret=$received[$bot]['messageContextInfo']['messageSecret'];
        $saved=BinaryData::decode($this->store->get('message-secrets',hash('sha256',$bot."\0".$result['id'])));
        self::assertSame($secret,$saved['secret']);self::assertSame($bot,$saved['expectedBot']);
    }

    public function testGroupBotMentionHasSeparatelyEncryptedSidecarWithDerivedSecret():void
    {
        $bot='867051314767696@bot'; $jid='5522000000000@s.whatsapp.net'; $own='5511000000000@s.whatsapp.net';
        $peers=$this->peers([$bot,$jid]); $copy=null; $parent=null;
        $service=$this->service(function(BinaryNode $node)use($bot,$jid,$own,$peers,&$copy,&$parent):BinaryNode {
            if ($node->tag==='iq' && ($node->attrs['xmlns'] ?? '')==='w:g2') return new BinaryNode('iq',['type'=>'result'],[
                new BinaryNode('group',['id'=>'100-200','addressing_mode'=>'pn'],[new BinaryNode('participant',['jid'=>$own]),new BinaryNode('participant',['jid'=>$jid])])]);
            if ($node->tag==='iq' && ($node->attrs['xmlns'] ?? '')==='usync') return self::devices([$own=>[5],$jid=>[0]]);
            if ($node->tag==='iq') return self::bundles($peers);
            $to=$node->child('bot')->child('to'); self::assertSame($bot,$to->attrs['jid']); $enc=$to->child('enc');
            $copy=MessageCodec::decode(MessageCodec::unpad($peers[$bot]['signal']->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content)))['proto'];
            $record=json_decode($this->store->get('message-outbox',$node->attrs['id']),true,32,JSON_THROW_ON_ERROR);
            $parent=MessageCodec::decode(base64_decode($record['plaintext']))['proto'];
            self::assertSame('skmsg',$node->child('enc')->attrs['type']);
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        });
        $result=$service->sendBotPrompt('100-200@g.us','@Meta AI ajuda',['botJid'=>$bot]);
        self::assertArrayNotHasKey('messageSecret',$copy['messageContextInfo']);
        self::assertSame(hash_hkdf('sha256',$parent['messageContextInfo']['messageSecret'],32,'Bot Message'),$copy['messageContextInfo']['botMessageSecret']);
        self::assertSame('@Meta AI ajuda',$copy['botInvokeMessage']['message']['extendedTextMessage']['text']);
        self::assertSame($bot,BinaryData::decode($this->store->get('message-secrets',hash('sha256',"100-200@g.us\0".$result['id'])))['expectedBot']);
    }

    public function testMsmsgBotChunkAuthenticatesBeforePersistenceAndReplaysDurably():void
    {
        $bot='867051314767696@bot'; $own='5511000000000@s.whatsapp.net'; $parent=random_bytes(32); $parentId='prompt-id';
        $this->store->set('message-secrets',hash('sha256',$bot."\0".$parentId),BinaryData::encode(['secret'=>$parent,'author'=>$own]));
        $salt='chunk-first'; $iv=random_bytes(12); $key=hash_hkdf('sha256',hash_hkdf('sha256',$parent,32,'Bot Message'),32,$salt.$own.$bot);
        $tag=''; $cipher=openssl_encrypt(MessageCodec::text('Resposta'),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$salt."\0".$bot);
        $payload=\MVO\Protocol\SchemaCodec::encode('MessageSecretMessage',['encIv'=>$iv,'encPayload'=>$cipher.$tag]);
        $node=new BinaryNode('message',['from'=>$bot,'id'=>$salt],[
            new BinaryNode('enc',['type'=>'msmsg'],$payload),new BinaryNode('bot',['edit'=>'first']),new BinaryNode('meta',['target_id'=>$parentId,'target_sender_jid'=>$own])]);
        $failing=new MessageService($this->signal,$this->store,fn():array=>$this->credentials,static function():void{},
            static fn():BinaryNode=>throw new \LogicException('No query'),static function():void{},static function(string $event):void{if($event==='bot_chunk')throw new \RuntimeException('callback failed');});
        try { $failing->handleNode($node); self::fail('Expected callback failure'); } catch(\RuntimeException $e) { self::assertSame('callback failed',$e->getMessage()); }
        $service=$this->service(static fn():BinaryNode=>throw new \LogicException('No query')); $service->drainPending();
        $chunks=array_values(array_filter($this->events,static fn(array $event):bool=>$event[0]==='bot_chunk'));
        self::assertCount(1,$chunks); self::assertSame('Resposta',$chunks[0][1]['text']); self::assertTrue($chunks[0][1]['replayed']);
        self::assertFalse($chunks[0][1]['authenticatedOwnDevice']);
        $service->handleNode($node); self::assertCount(2,$this->events);
        $tampered=new BinaryNode('message',['from'=>$bot,'id'=>'tamper'],[
            new BinaryNode('enc',['type'=>'msmsg'],$payload),new BinaryNode('bot',['edit'=>'first']),new BinaryNode('meta',['target_id'=>$parentId,'target_sender_jid'=>$own])]);
        $service->handleNode($tampered);
        self::assertSame('bot_chunk_authentication_failed',$this->events[2][1]['reason']);
        self::assertNull($service->history()->store->getMessage($bot,'tamper'));
    }

    public function testRichBotReplyAndItsPromptCorrelationSurviveHistoryAndInboxReplay():void
    {
        $bot='867051314767696@bot';$own='5511000000000@s.whatsapp.net';$parent=random_bytes(32);$parentId='rich-prompt';$id='rich-first';
        $this->store->set('message-secrets',hash('sha256',$bot."\0".$parentId),BinaryData::encode(['secret'=>$parent,'author'=>$own,'expectedBot'=>$bot]));
        $proto=['richResponseMessage'=>['messageType'=>1,'submessages'=>[['messageType'=>2,'messageText'=>'MVO BOT OK']]]];
        $plain=\MVO\Protocol\SchemaCodec::encode('Message',$proto);$iv=random_bytes(12);$key=hash_hkdf('sha256',hash_hkdf('sha256',$parent,32,'Bot Message'),32,$id.$own.$bot);$tag='';
        $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$id."\0".$bot);
        $payload=\MVO\Protocol\SchemaCodec::encode('MessageSecretMessage',['encIv'=>$iv,'encPayload'=>$cipher.$tag]);
        $node=new BinaryNode('message',['from'=>$bot,'id'=>$id],[new BinaryNode('enc',['type'=>'msmsg'],$payload),
            new BinaryNode('bot',['edit'=>'first']),new BinaryNode('meta',['target_id'=>$parentId,'target_sender_jid'=>$own])]);
        $failing=new MessageService($this->signal,$this->store,fn():array=>$this->credentials,static function():void{},
            static fn():BinaryNode=>throw new \LogicException('No query'),static function():void{},static function(string $event):void{if($event==='bot_chunk')throw new \RuntimeException('callback failed');});
        try{$failing->handleNode($node);self::fail('Callback must fail after durable commit');}catch(\RuntimeException $e){self::assertSame('callback failed',$e->getMessage());}
        $service=$this->service(static fn():BinaryNode=>throw new \LogicException('No query'));$service->drainPending();
        $history=$service->history()->store->getMessage($bot,$id);
        self::assertSame('MVO BOT OK',$history['text']);self::assertSame('rich-response',$history['type']);
        self::assertSame($proto,$history['proto']);self::assertSame($parentId,$history['botChunk']['targetMessageId']);
        self::assertSame('replace',$history['botChunk']['responseMode']);self::assertArrayNotHasKey('plaintext',$history);
        self::assertArrayNotHasKey('secretEncryptedMessage',$history['proto']);
        $chunks=array_values(array_filter($this->events,static fn(array $event):bool=>$event[0]==='bot_chunk'));
        self::assertCount(1,$chunks);self::assertSame('MVO BOT OK',$chunks[0][1]['text']);self::assertTrue($chunks[0][1]['replayed']);
    }

    public function testBotReplyRequiresAnOwnPromptAndTheExpectedInvokedBotEvenWhenAeadIsValid():void
    {
        $bot='867051314767696@bot';$other='1807055946647696@bot';$own='5511000000000@s.whatsapp.net';$group='100-200@g.us';$parent=random_bytes(32);
        $make=function(string $id,string $sender,string $chat,string $promptId,array $message)use($own,$parent):BinaryNode{
            $salt=$id;$iv=random_bytes(12);$key=hash_hkdf('sha256',hash_hkdf('sha256',$parent,32,'Bot Message'),32,$salt.$own.$sender);$tag='';
            $cipher=openssl_encrypt(\MVO\Protocol\SchemaCodec::encode('Message',$message),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$salt."\0".$sender);
            $payload=\MVO\Protocol\SchemaCodec::encode('MessageSecretMessage',['encIv'=>$iv,'encPayload'=>$cipher.$tag]);
            $attrs=['from'=>$chat,'id'=>$id];if(str_ends_with($chat,'@g.us'))$attrs['participant']=$sender;
            return new BinaryNode('message',$attrs,[new BinaryNode('enc',['type'=>'msmsg'],$payload),new BinaryNode('bot',['edit'=>'full']),
                new BinaryNode('meta',['target_id'=>$promptId,'target_sender_jid'=>$own,'target_chat_jid'=>$chat])]);
        };
        $this->store->set('message-secrets',hash('sha256',$bot."\0".'foreign-prompt'),BinaryData::encode(['secret'=>$parent,'author'=>'999@s.whatsapp.net','expectedBot'=>$bot]));
        $this->store->set('message-secrets',hash('sha256',$other."\0".'wrong-bot'),BinaryData::encode(['secret'=>$parent,'author'=>$own,'expectedBot'=>$bot]));
        $this->store->set('message-secrets',hash('sha256',$group."\0".'no-history'),BinaryData::encode(['secret'=>$parent,'author'=>$own]));
        $this->store->set('message-secrets',hash('sha256',$group."\0".'group-prompt'),BinaryData::encode(['secret'=>$parent,'author'=>$own]));
        $service=$this->service(static fn():BinaryNode=>throw new \LogicException('No query'));
        $service->history()->store->upsertMessage(['id'=>'group-prompt','chat'=>$group,'fromMe'=>true,'proto'=>[
            'botInvokeMessage'=>['message'=>['extendedTextMessage'=>['text'=>'prompt','contextInfo'=>['mentionedJid'=>[$bot]]]]]]]);
        foreach ([['foreign-owner',$bot,$bot,'foreign-prompt'],['wrong-bot',$other,$other,'wrong-bot'],
            ['unbound-group',$bot,$group,'no-history'],['wrong-group-bot',$other,$group,'group-prompt']] as [$id,$sender,$chat,$promptId]) {
            $service->handleNode($make($id,$sender,$chat,$promptId,['conversation'=>'must not be accepted']));
            self::assertNull($service->history()->store->getMessage($chat,$id));
        }
        self::assertSame(['message_error','message_error','message_error','message_error'],array_column($this->events,0));
        $service->handleNode($make('valid-group',$bot,$group,'group-prompt',['conversation'=>'valid answer']));
        self::assertSame('valid answer',$service->history()->store->getMessage($group,'valid-group')['text']);
        self::assertSame('group-prompt',$service->history()->store->getMessage($group,'valid-group')['botChunk']['targetMessageId']);
    }

    public function testGroupHistoryShareUsesRestrictedSignalAudienceAndSeparateSenderKeyNotice():void
    {
        $group='100-200@g.us'; $jid='5522000000000@s.whatsapp.net'; $other='5533000000000@s.whatsapp.net'; $own='5511000000000@s.whatsapp.net';
        $peers=$this->peers([$jid,$other]); $uploadBytes=''; $messages=[]; $decodedBundle=null;
        $media=new MediaTransfer(static fn():BinaryNode=>new BinaryNode('iq',['type'=>'result'],[
            new BinaryNode('media_conn',['auth'=>'test','ttl'=>'3600'],[new BinaryNode('host',['hostname'=>'mmg.whatsapp.net'])])]),
            function(array $request)use(&$uploadBytes):array{$uploadBytes=$request['body'];return ['status'=>200,'body'=>'{"url":"https://mmg.whatsapp.net/group","direct_path":"/group"}'];});
        $service=$this->service(function(BinaryNode $node)use($group,$jid,$other,$own,$peers,&$messages,&$decodedBundle):BinaryNode {
            if ($node->tag==='iq' && ($node->attrs['xmlns'] ?? '')==='w:g2') return new BinaryNode('iq',['type'=>'result'],[
                new BinaryNode('group',['id'=>'100-200','addressing_mode'=>'pn'],[new BinaryNode('participant',['jid'=>$own]),new BinaryNode('participant',['jid'=>$jid]),new BinaryNode('participant',['jid'=>$other])])]);
            if ($node->tag==='iq' && ($node->attrs['xmlns'] ?? '')==='usync') return self::devices([$own=>[5],$jid=>[0],$other=>[0]]);
            if ($node->tag==='iq') return self::bundles($peers);
            $messages[]=$node;
            if ($node->child('enc')===null) {
                $participants=$node->child('participants')->content;
                self::assertSame([$jid],array_map(static fn(BinaryNode $n):string=>$n->attrs['jid'],$participants));
                $enc=$participants[0]->child('enc');
                $decodedBundle=MessageCodec::decode(MessageCodec::unpad($peers[$jid]['signal']->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content)))['proto']['messageHistoryBundle'];
            }
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        },$media);
        $result=$service->shareGroupHistory($group,['toJids'=>[$jid],'messages'=>[['key'=>['id'=>'shared','remoteJid'=>$group,'participant'=>$own,'fromMe'=>true],
            'message'=>['conversation'=>'Mensagem antiga'],'messageTimestamp'=>time()-100]]]);
        self::assertCount(2,$messages); self::assertSame('skmsg',$messages[1]->child('enc')->attrs['type']);
        self::assertSame([$jid],$result['historyReceivers']); self::assertSame([$other],$result['nonHistoryReceivers']);
        $plain=\MVO\Media\MediaCrypto::decrypt($uploadBytes,'group-history',$decodedBundle['mediaKey'],$decodedBundle['fileSha256'],$decodedBundle['fileEncSha256']);
        $history=\MVO\Protocol\SchemaCodec::decode('GroupHistory',gzuncompress($plain));
        self::assertSame('Mensagem antiga',$history['messages'][0]['message']['conversation']);
    }

    public function testExpiredMediaRequestsOneReuploadAndRevalidatesOriginalHashes():void
    {
        $media=\MVO\Media\MediaCrypto::encrypt('restored file','document'); $requests=0; $http=0; $service=null;
        $transfer=new MediaTransfer(static fn():BinaryNode=>throw new \LogicException('No IQ'),function(array $request)use($media,&$http):array {
            ++$http; return str_contains($request['url'],'/fresh') ? ['status'=>200,'body'=>$media['body']] : ['status'=>410,'body'=>'gone'];
        });
        $send=function(BinaryNode $node)use(&$service,$media,&$requests):void {
            if ($node->tag!=='receipt' || ($node->attrs['type'] ?? '')!=='server-error') return;
            ++$requests; $id=$node->attrs['id']; $iv=random_bytes(12); $key=hash_hkdf('sha256',$media['mediaKey'],32,'WhatsApp Media Retry Notification'); $tag='';
            $cipher=openssl_encrypt(\MVO\Protocol\SchemaCodec::encode('MediaRetryNotification',['stanzaId'=>$id,'result'=>'SUCCESS','directPath'=>'/fresh']),
                'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$id);
            $service->handleNode(new BinaryNode('notification',['id'=>$id,'from'=>'5511000000000@s.whatsapp.net','type'=>'mediaretry'],[
                new BinaryNode('encrypt',[],[new BinaryNode('enc_p',[],$cipher.$tag),new BinaryNode('enc_iv',[],$iv)])]));
        };
        $service=new MessageService($this->signal,$this->store,fn():array=>$this->credentials,static function():void{},
            static fn():BinaryNode=>throw new \LogicException('No IQ'),$send,static function():void{},$transfer,static function():void{});
        $event=['id'=>'expired-file','chat'=>'5522000000000@s.whatsapp.net','fromMe'=>false,'media'=>['mediaType'=>'document','directPath'=>'/expired',
            'mediaKey'=>$media['mediaKey'],'fileSha256'=>$media['fileSha256'],'fileEncSha256'=>$media['fileEncSha256'],'fileLength'=>$media['fileLength']]];
        $service->history()->store->recordEvent($event);
        self::assertSame('restored file',$service->downloadMedia($event)); self::assertSame(1,$requests); self::assertSame(3,$http);
        self::assertSame('/fresh',$service->history()->store->getMessage($event['chat'],$event['id'])['media']['directPath']);
        $path=tempnam(sys_get_temp_dir(),'mvo-restored-');
        try { self::assertSame($path,$service->downloadMediaStream($event,$path)); self::assertSame('restored file',file_get_contents($path)); }
        finally { unlink($path); }
        self::assertSame(2,$requests);
    }

    public function testImportedPrimaryCanSendWithoutCompanionCertificateButCompanionCannot():void
    {
        $jid='5522000000000@s.whatsapp.net'; $peers=$this->peers([$jid]);
        $this->credentials['role']='primary'; $this->credentials['meJid']='5511000000000@s.whatsapp.net'; unset($this->credentials['signedIdentity']);
        $service=$this->service(function(BinaryNode $node)use($jid,$peers):BinaryNode {
            if ($node->tag==='iq' && ($node->attrs['xmlns'] ?? '')==='usync') return self::devices([$jid=>[0]]);
            if ($node->tag==='iq') return self::bundles($peers);
            self::assertNull($node->child('device-identity')); $enc=$node->child('participants')->content[0]->child('enc');
            self::assertSame('Primary',MessageCodec::decode(MessageCodec::unpad($peers[$jid]['signal']->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content)))['text']);
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        });
        self::assertSame('server_ack',$service->sendText($jid,'Primary')['status']);
        $this->credentials['role']='companion'; $this->credentials['meJid']='5511000000000:5@s.whatsapp.net';
        $state=$this->store->getSession($jid);
        try { $service->sendText($jid,'Must fail'); self::fail('Expected companion certificate'); }
        catch(\LogicException $error) { self::assertSame('Paired signed device identity is missing',$error->getMessage()); }
        self::assertSame($state,$this->store->getSession($jid));
    }

    public function testCallDeviceResolutionKeepsRoutedPrimaryFirstAndExplicitCompanionAlone():void
    {
        $queries=0;$service=$this->service(function(BinaryNode $node)use(&$queries):BinaryNode{++$queries;self::assertSame('usync',$node->attrs['xmlns']);return self::devices(['123@lid'=>[4,2]]);});
        self::assertSame(['123@lid','123:4@lid','123:2@lid'],$service->resolveCallDevices('123@lid'));
        self::assertSame(['123:7@lid'],$service->resolveCallDevices('123:7@lid'));self::assertSame(1,$queries);
    }

    public function testCallKeyBridgeSharesNativeSignalAndCachesExactEnvelope():void
    {
        $jid='5522000000000@s.whatsapp.net'; $peers=$this->peers([$jid]);
        $service=$this->service(function(BinaryNode $node) use($peers):BinaryNode { return self::bundles($peers); });
        $plain=MessageCodec::pad(\MVO\Protocol\SchemaCodec::encode('Message',['call'=>['callKey'=>str_repeat('c',32)]]));
        $first=$service->encryptCallKeys([$jid],$plain); self::assertNotNull($first['deviceIdentity']);
        $state=$this->store->getSession($jid); $again=$service->encryptCallKeys([$jid],$plain);
        self::assertEquals($first,$again); self::assertSame($state,$this->store->getSession($jid));
        $enc=$first['nodes'][0]->child('enc');
        self::assertSame($plain,$peers[$jid]['signal']->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content));
        $reply=$peers[$jid]['signal']->encrypt($this->credentials['meJid'],MessageCodec::pad('call-reply'));
        self::assertSame('call-reply',MessageCodec::unpad($service->decryptCallKey($jid,$reply['type'],$reply['ciphertext'])));
    }

    /** @param list<string> $jids @return array<string,array{credentials:array<string,mixed>,key:array<string,mixed>,signal:SignalProtocol,store:SignalStore}> */
    public function testPartialQuoteUsesStoredParentAndSurvivesSignalFanoutWithSpoiler():void
    {
        $recipient='5522000000000@s.whatsapp.net';$own='5511000000000@s.whatsapp.net';$peers=$this->peers([$recipient,$own]);$seen=0;
        $service=$this->service(function(BinaryNode $node)use($recipient,$own,$peers,&$seen):BinaryNode{
            if($node->tag==='iq'&&($node->attrs['xmlns']??'')==='usync')return self::devices([$recipient=>[0],$own=>[0,5]]);
            if($node->tag==='iq')return self::bundles($peers);
            foreach($node->child('participants')->content as$to){$enc=$to->child('enc');$plain=$peers[$to->attrs['jid']]['signal']->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content);$decoded=MessageCodec::decode(MessageCodec::unpad($plain));self::assertSame('plano Cloud',$decoded['quote']['selectedText']);self::assertSame($recipient,$decoded['quote']['participant']);self::assertTrue($decoded['spoiler']);++$seen;}
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        });
        $service->history()->store->upsertMessage(['chat'=>$recipient,'id'=>'PARENT-123','from'=>$recipient,'fromMe'=>false,'proto'=>['conversation'=>'Gostaria de conhecer o plano Cloud.']]);
        $result=$service->sendPartialQuote($recipient,'PARENT-123','plano Cloud',['type'=>'text','text'=>'Claro!','spoiler'=>true]);self::assertSame('server_ack',$result['status']);self::assertSame(2,$seen);
    }
    public function testPartialQuoteRejectsInventedTextAndAmbiguousParentBeforeDeviceRequestsOrWrite():void
    {
        $calls=0;$service=$this->service(static function()use(&$calls):BinaryNode{++$calls;throw new \RuntimeException('No network expected');});$chat='222@lid';
        $service->history()->store->upsertMessage(['chat'=>$chat,'id'=>'PARENT-123','from'=>$chat,'proto'=>['conversation'=>'Texto original']]);
        try{$service->sendPartialQuote($chat,'PARENT-123','Texto inventado','Resposta');self::fail('Invented quote accepted');}catch(\InvalidArgumentException){self::assertSame(0,$calls);}
        $service->history()->store->upsertMessage(['chat'=>$chat,'id'=>'PARENT-123','participant'=>'333@lid','from'=>'333@lid','proto'=>['conversation'=>'Texto original']]);
        try{$service->sendPartialQuote($chat,'PARENT-123','Texto original','Resposta');self::fail('Ambiguous quote accepted');}catch(\InvalidArgumentException){self::assertSame(0,$calls);}
        self::assertSame([],$this->store->entries('message-outbox',100));
    }
    public function testCarouselPublishesExactlyOnceAfterAllEncryptedCardUploads():void
    {
        $recipient='5522000000000@s.whatsapp.net';$own='5511000000000@s.whatsapp.net';$peers=$this->peers([$recipient,$own]);$uploads=0;$writes=0;
        $media=new MediaTransfer(static fn():BinaryNode=>new BinaryNode('iq',['type'=>'result'],[new BinaryNode('media_conn',['auth'=>'fixture','ttl'=>'3600'],[new BinaryNode('host',['hostname'=>'mmg.whatsapp.net'])])]),static function()use(&$uploads):array{++$uploads;return ['status'=>200,'body'=>'{"direct_path":"/card","url":"https://mmg.whatsapp.net/card"}'];});
        $service=$this->service(function(BinaryNode $node)use($recipient,$own,$peers,&$uploads,&$writes):BinaryNode{
            if($node->tag==='iq'&&($node->attrs['xmlns']??'')==='usync')return self::devices([$recipient=>[0],$own=>[0,5]]);if($node->tag==='iq')return self::bundles($peers);
            ++$writes;self::assertSame(2,$uploads);foreach($node->child('participants')->content as$to){$enc=$to->child('enc');$plain=$peers[$to->attrs['jid']]['signal']->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content);self::assertSame(2,MessageCodec::decode(MessageCodec::unpad($plain))['carousel']['cardCount']);}
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        },$media);
        $card=['type'=>'image','media'=>['bytes'=>'fixture'],'mimetype'=>'image/jpeg','buttons'=>[['type'=>'url','label'=>'MVO','url'=>'https://mvo.host']]];
        $result=$service->sendCarousel($recipient,[$card,$card],['text'=>'Nossos planos']);self::assertSame('server_ack',$result['status']);self::assertSame(1,$writes);
    }
    private function peers(array $jids): array
    {
        $peers = [];
        foreach ($jids as $jid) {
            $credentials = self::credentials($jid);
            $store = new SignalStore($this->path());
            $peers[$jid] = ['credentials'=>$credentials,'key'=>$store->generatePreKeys(1)[0],
                'signal'=>new SignalProtocol($store, $credentials),'store'=>$store];
        }
        return $peers;
    }

    /** @param array<string,list<int>> $devices */
    private static function devices(array $devices): BinaryNode
    {
        $users = [];
        foreach ($devices as $jid=>$ids) $users[] = new BinaryNode('user', ['jid'=>$jid], [new BinaryNode('devices', [], [
            new BinaryNode('device-list', [], array_map(static fn(int $id):BinaryNode => new BinaryNode('device', ['id'=>(string) $id]), $ids))
        ])]);
        return new BinaryNode('iq', ['type'=>'result'], [new BinaryNode('usync', [], [new BinaryNode('list', [], $users)])]);
    }

    /** @param array<string,array{credentials:array<string,mixed>,key:array<string,mixed>,signal:SignalProtocol,store:SignalStore}> $peers */
    private static function bundles(array $peers): BinaryNode
    {
        $users = [];
        foreach ($peers as $jid=>$peer) {
            $credentials = $peer['credentials']; $key = $peer['key']; $signed = $credentials['signedPreKey'];
            $users[] = new BinaryNode('user', ['jid'=>$jid], [
                new BinaryNode('registration', [], pack('N', $credentials['registrationId'])),
                new BinaryNode('identity', [], $credentials['identityKeyPair']['public']),
                new BinaryNode('skey', [], [new BinaryNode('id', [], substr(pack('N', $signed['id']),1)),
                    new BinaryNode('value', [], $signed['public']),new BinaryNode('signature', [], $signed['signature'])]),
                new BinaryNode('key', [], [new BinaryNode('id', [], substr(pack('N', $key['id']),1)),new BinaryNode('value', [], $key['public'])]),
            ]);
        }
        return new BinaryNode('iq', ['type'=>'result'], [new BinaryNode('list', [], $users)]);
    }

    /** @param array<string,mixed> $credentials @param array<string,mixed> $key @return array<string,mixed> */
    private static function bundle(array $credentials, array $key): array
    {
        return ['registrationId'=>$credentials['registrationId'],'identityKey'=>$credentials['identityKeyPair']['public'],
            'signedPreKey'=>['id'=>$credentials['signedPreKey']['id'],'public'=>$credentials['signedPreKey']['public'],'signature'=>$credentials['signedPreKey']['signature']],
            'preKey'=>['id'=>$key['id'],'public'=>$key['public']]];
    }
}
