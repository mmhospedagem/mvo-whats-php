<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Message\{ContentBuilder,ContentDecoder,MessageService,ProviderNoticeContent};
use MVO\Protocol\BinaryNode;
use MVO\Signal\{SignalProtocol,SignalStore};
use MVO\Testing\FakeProtocol;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderNoticeContentTest extends TestCase
{
    public function testTypedNoticePreservesOrdinaryTextAndQuoteWithoutBusinessMetadata(): void
    {
        $built = ContentBuilder::build([
            'type'=>'provider-notice', 'privacyUrl'=>'https://www.mvo.host/privacidade?origem=chat',
            'quote'=>['id'=>'ORIGINAL', 'participant'=>'111@s.whatsapp.net', 'message'=>['conversation'=>'Olá']],
        ], '222@s.whatsapp.net');
        $decoded = ContentDecoder::decode($built['bytes']);
        self::assertSame('text', $decoded['kind']);
        self::assertSame("Esta empresa utiliza a plataforma MVO para gerenciar esta conversa.\nSaiba mais: https://www.mvo.host/privacidade?origem=chat", $decoded['text']);
        self::assertSame('ORIGINAL', $decoded['proto']['extendedTextMessage']['contextInfo']['stanzaId']);
        self::assertSame(['conversation'=>'Olá'], $decoded['proto']['extendedTextMessage']['contextInfo']['quotedMessage']);
        self::assertSame(['type'=>'text'], $built['attrs']);
        self::assertSame([], $built['encAttrs']);
        self::assertSame([], $built['companionNodes']);
        self::assertSame([], $built['secrets']);
        self::assertArrayNotHasKey('matchedText', $decoded['proto']['extendedTextMessage']);
        self::assertArrayNotHasKey('messageContextInfo', $decoded['proto']);
    }

    public static function invalidPrivacyUrls(): iterable
    {
        yield 'HTTP'=>['http://www.mvo.host/privacy'];
        yield 'relative'=>['/privacy'];
        yield 'protocol relative'=>['//www.mvo.host/privacy'];
        yield 'credentials'=>['https://user:secret@www.mvo.host/privacy'];
        yield 'empty credentials'=>['https://@www.mvo.host/privacy'];
        yield 'fragment'=>['https://www.mvo.host/privacy#details'];
        yield 'empty fragment'=>['https://www.mvo.host/privacy#'];
        yield 'literal control'=>["https://www.mvo.host/privacy\n"];
        yield 'encoded control'=>['https://www.mvo.host/privacy%0D%0A'];
        yield 'encoded Unicode control'=>['https://www.mvo.host/privacy%C2%85'];
        yield 'literal Unicode control'=>["https://www.mvo.host/privacy\u{0085}"];
        yield 'leading whitespace'=>[' https://www.mvo.host/privacy'];
        yield 'embedded whitespace'=>['https://www.mvo.host/privacy policy'];
        yield 'empty'=>[''];
        yield 'null'=>[null];
        yield 'object'=>[['url'=>'https://www.mvo.host']];
    }

    #[DataProvider('invalidPrivacyUrls')]
    public function testInvalidPrivacyUrlFailsDuringPurePreflight(mixed $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('privacyUrl');
        ProviderNoticeContent::validateOptions(['privacyUrl'=>$url]);
    }

    public function testValidPrivacyUrlIsKeptExactlyAndNeverFetched(): void
    {
        // An intentionally unresolvable domain proves formatting has no DNS dependency.
        $url = 'https://provider-notice.invalid/privacy%20policy?lang=pt-BR';
        $proto = ProviderNoticeContent::build(['privacyUrl'=>$url]);
        self::assertStringEndsWith("\nSaiba mais: " . $url, $proto['extendedTextMessage']['text']);
        self::assertSame(['extendedTextMessage'], array_keys($proto));
        self::assertSame(['text'], array_keys($proto['extendedTextMessage']));
        $default = ProviderNoticeContent::build();
        self::assertSame('Esta empresa utiliza a plataforma MVO para gerenciar esta conversa.', $default['extendedTextMessage']['text']);
    }

    public function testNativeModeUsesVisibleTextAndCurrentThirdPartyMetadataWithoutCallerOverrides(): void
    {
        $before = time();
        $built = ContentBuilder::build(['type'=>'provider-notice','format'=>'native','text'=>"Atendimento MVO\nComo podemos ajudar?",
            'actual_actors'=>'4','host_storage'=>'2','privacy_mode_ts'=>1], '222@s.whatsapp.net');
        self::assertSame(['extendedTextMessage'=>['text'=>"Atendimento MVO\nComo podemos ajudar?"]], $built['proto']);
        self::assertSame(['type'=>'text'], $built['attrs']);
        self::assertSame([], $built['encAttrs']);
        self::assertSame([], $built['secrets']);
        self::assertCount(1, $built['companionNodes']);
        $biz = $built['companionNodes'][0];
        self::assertSame('biz', $biz->tag);
        self::assertSame(['actual_actors','host_storage','privacy_mode_ts'], array_keys($biz->attrs));
        self::assertSame('2', $biz->attrs['actual_actors']);
        self::assertSame('1', $biz->attrs['host_storage']);
        self::assertGreaterThanOrEqual($before, (int)$biz->attrs['privacy_mode_ts']);
        self::assertLessThanOrEqual(time(), (int)$biz->attrs['privacy_mode_ts']);
        self::assertNull($biz->child('biz'));
        self::assertNull($biz->child('interactive'));
    }

    public static function invalidNoticeOptions(): iterable
    {
        yield 'unknown format'=>[['format'=>'meta']];
        yield 'null format'=>[['format'=>null]];
        yield 'text override without native'=>[['text'=>'Custom']];
        yield 'native missing text'=>[['format'=>'native']];
        yield 'native null text'=>[['format'=>'native','text'=>null]];
        yield 'native invalid UTF8'=>[['format'=>'native','text'=>"\xFF"]];
        yield 'native oversized'=>[['format'=>'native','text'=>str_repeat('a',65537)]];
        yield 'native empty'=>[['format'=>'native','text'=>'']];
        yield 'native whitespace'=>[['format'=>'native','text'=>" \n\t\u{00A0}"]];
        yield 'native invisible formatting'=>[['format'=>'native','text'=>"\u{200B}\u{200D}\u{FEFF}"]];
        yield 'native invisible filler'=>[['format'=>'native','text'=>"\u{3164}\u{2800}"]];
        yield 'native control'=>[['format'=>'native','text'=>"Atendimento\x00MVO"]];
        yield 'native Unicode control'=>[['format'=>'native','text'=>"Atendimento\u{0085}MVO"]];
        yield 'native privacy link'=>[['format'=>'native','text'=>'Atendimento MVO','privacyUrl'=>'https://www.mvo.host']];
        yield 'native null privacy link'=>[['format'=>'native','text'=>'Atendimento MVO','privacyUrl'=>null]];
    }

    #[DataProvider('invalidNoticeOptions')]
    public function testInvalidNativeCombinationsFailPurePreflight(array $options): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProviderNoticeContent::validateOptions($options);
    }

    public static function noticeFormats(): iterable
    {
        yield 'ordinary text'=>[['privacyUrl'=>'https://provider-notice.invalid/privacy'],false];
        yield 'native third-party declaration'=>[['format'=>'native','text'=>"Atendimento MVO\nComo podemos ajudar?"],true];
    }

    #[DataProvider('noticeFormats')]
    public function testRealSignalTransportDeliversVisibleTextWithOnlyExplicitThirdPartyMetadata(array $options,bool $native): void
    {
        $credentials = CredentialStore::fresh();
        $credentials['registered'] = true;
        $credentials['meJid'] = '111:5@s.whatsapp.net';
        $credentials['signedIdentity'] = ['details'=>'provider-notice-fixture','accountSignatureKey'=>random_bytes(32),'accountSignature'=>random_bytes(64),'deviceSignature'=>random_bytes(64)];
        $sender = $credentials['meJid'];
        $server = new FakeProtocol();
        $store = new SignalStore(':memory:', 'provider-notice-fixture');
        $events = [];
        try {
            $server->registerClient($credentials);
            $peer = $server->createPeer('222@s.whatsapp.net');
            $ownPrimary = $server->createPeer('111@s.whatsapp.net');
            $own = $server->createPeer('111:2@s.whatsapp.net');
            $service = new MessageService(new SignalProtocol($store,$credentials), $store, static fn(): array=>$credentials,
                static function(): void {},
                static fn(BinaryNode $node,float $timeout): BinaryNode=>$server->dispatch($node,$sender)[0],
                static function(BinaryNode $node) use ($server,$sender): void { $server->dispatch($node,$sender); },
                static function(string $name,array $data) use (&$events): void { $events[]=[$name,$data]; });
            $service->initialize();
            self::assertSame([], $peer->messages(), 'Initialization must not send a notice automatically');
            $before = time();
            $result = $service->sendMessage($peer->jid, ['type'=>'provider-notice'] + $options);
            self::assertSame('server_ack', $result['status']);
            $received = $peer->messages();
            self::assertCount(1, $received);
            self::assertSame('text', $received[0]['type']);
            self::assertSame($native ? $options['text'] : "Esta empresa utiliza a plataforma MVO para gerenciar esta conversa.\nSaiba mais: https://provider-notice.invalid/privacy", $received[0]['text']);
            self::assertSame(['extendedTextMessage'=>['text'=>$received[0]['text']]], $received[0]['proto']);
            foreach ([$ownPrimary,$own] as $device) self::assertSame(['deviceSentMessage'=>['destinationJid'=>$peer->jid,'message'=>$received[0]['proto']]], $device->messages()[0]['proto']);
            $sent = array_values(array_filter($server->captured(),static fn(array $entry): bool=>$entry['node']->tag==='message'));
            self::assertCount(1, $sent);
            $stanza = $sent[0]['node'];
            self::assertSame('text', $stanza->attrs['type']);
            $bizNodes = array_values(array_filter(is_array($stanza->content) ? $stanza->content : [],static fn(BinaryNode $node): bool=>$node->tag==='biz'));
            self::assertCount($native ? 1 : 0, $bizNodes);
            if ($native) {
                $biz = $bizNodes[0];
                self::assertSame('2', $biz->attrs['actual_actors']);
                self::assertSame('1', $biz->attrs['host_storage']);
                self::assertGreaterThanOrEqual($before,(int)$biz->attrs['privacy_mode_ts']);
                self::assertLessThanOrEqual(time(),(int)$biz->attrs['privacy_mode_ts']);
                self::assertSame(['actual_actors','host_storage','privacy_mode_ts'],array_keys($biz->attrs));
                self::assertNull($biz->child('biz'));
                self::assertNull($biz->child('interactive'));
            }
            self::assertNull($stanza->child('verified_name'));
            foreach ($stanza->child('participants')?->content ?? [] as $participant) {
                self::assertArrayNotHasKey('mediatype', $participant->child('enc')->attrs);
            }
            foreach (['actual_actors','host_storage','privacy_mode_ts'] as $field) self::assertArrayNotHasKey($field,$stanza->attrs);
            self::assertNotContains('message_receipt', array_column($events,0), 'A server ACK must not invent delivered or read receipts');
        } finally {
            $server->close();
            $store->close();
        }
    }
}
