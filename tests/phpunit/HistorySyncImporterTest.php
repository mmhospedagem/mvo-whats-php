<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\History\{HistoryStore,HistorySyncImporter};
use MVO\Protocol\BinaryData;
use MVO\Signal\SignalStore;
use PHPUnit\Framework\TestCase;

final class HistorySyncImporterTest extends TestCase
{
    private SignalStore $store;
    private HistoryStore $history;
    protected function setUp():void { $this->store=new SignalStore(':memory:');$this->history=new HistoryStore($this->store); }
    protected function tearDown():void { $this->store->close(); }
    private function importer(bool $persist=true):HistorySyncImporter
    {
        return new HistorySyncImporter($this->store,static fn():array=>['meJid'=>'100:5@s.whatsapp.net'],['syncType'=>3,'progress'=>12],$persist);
    }
    /** @return list<array<string,mixed>> */
    private static function items():array
    {
        return [
            ['kind'=>'conversations.messages','conversationId'=>'200@s.whatsapp.net','value'=>['msgOrderId'=>123,'message'=>[
                'key'=>['id'=>'received','fromMe'=>false],'message'=>['conversation'=>'Historical text','messageContextInfo'=>['messageSecret'=>str_repeat('s',32)]],'messageTimestamp'=>123]]],
            ['kind'=>'conversations.messages','conversationId'=>'200@s.whatsapp.net','value'=>['msgOrderId'=>122]],
            ['kind'=>'conversations','value'=>['id'=>'200@s.whatsapp.net','name'=>'Customer','tcToken'=>"\0token",'tcTokenTimestamp'=>100,'tcTokenSenderTimestamp'=>101,'archived'=>true]],
            ['kind'=>'pushnames','value'=>['id'=>'200@s.whatsapp.net','pushname'=>'Maria']],
            ['kind'=>'inlineContacts','value'=>['pnJid'=>'200@s.whatsapp.net','fullName'=>'Original PN contact']],
            // A mapping may follow both the chat and the pushname on the wire.
            ['kind'=>'phoneNumberToLidMappings','value'=>['pnJid'=>'200@s.whatsapp.net','lidJid'=>'900@lid']],
            ['kind'=>'inlineContacts','value'=>['pnJid'=>'200@s.whatsapp.net','lidJid'=>'900@lid','fullName'=>'Maria Silva','username'=>'maria']],
            ['kind'=>'statusV3Messages','value'=>['key'=>['id'=>'status','remoteJid'=>'status@broadcast','participant'=>'300:7@s.whatsapp.net'],'message'=>['conversation'=>'Status text'],'messageTimestamp'=>120]],
            ['kind'=>'nctSalt','value'=>"\0\xffsalt"],
            ['kind'=>'globalSettings','value'=>['securityNotifications'=>true,'autoUnarchiveChats'=>true,'chatLockSettings'=>['secretCode'=>'private']]],
            ['kind'=>'omittedConversationsProvided','value'=>true],
            ['kind'=>'omittedConversations','value'=>['historyChatId'=>'400@s.whatsapp.net']],
            ['kind'=>'callLogRecords','value'=>['callId'=>'call','isVideo'=>true]],
            ['kind'=>'recentStickers','value'=>['fileSha256'=>str_repeat('h',32),'height'=>512]],
            ['kind'=>'favoriteStickers','value'=>['fileSha256'=>str_repeat('h',32)]],
            ['kind'=>'pastParticipants','value'=>['groupJid'=>'100-200@g.us','pastParticipants'=>[]]],
            ['kind'=>'progress','value'=>100],
            ['kind'=>'chunkOrder','value'=>2],
        ];
    }
    public function testStreamedItemsPreserveLocalProjectionsAndContactsRegardlessOfWireOrder():void
    {
        $result=$this->store->transaction(function():array {
            $importer=$this->importer();
            foreach (self::items() as $item) $importer->item($item);
            return $importer->result();
        });
        self::assertSame(3,$result['syncType']);
        self::assertSame(2,$result['messagesCount']);
        self::assertSame(1,$result['conversationsCount']);
        self::assertSame(1,$result['pushnamesCount']);
        self::assertSame(2,$result['inlineContactsCount']);
        self::assertSame(100,$result['progress']);
        self::assertSame(2,$result['chunkOrder']);
        self::assertSame(1,$result['omittedConversationsCount']);
        self::assertSame(1,$result['callLogsCount']);
        self::assertSame(2,$result['stickersCount']);
        self::assertSame(1,$result['pastParticipantGroupsCount']);
        self::assertTrue($this->history->getChat('200@s.whatsapp.net')['archived']);
        self::assertSame('Historical text',$this->history->getMessage('200@s.whatsapp.net','received')['text']);
        self::assertSame('Status text',$this->history->getMessage('status@broadcast','status','300@s.whatsapp.net')['text']);
        self::assertSame('Maria',$this->history->getContact('900@lid')['pushName']);
        self::assertSame('Maria Silva',$this->history->getContact('900@lid')['displayName']);
        self::assertSame('maria',$this->history->getContact('900@lid')['username']);
        self::assertSame('900@lid',$this->store->get('message-pn-to-lid','200@s.whatsapp.net'));
        self::assertSame('200@s.whatsapp.net',$this->store->get('message-lid-to-pn','900@lid'));
        self::assertSame("\0\xffsalt",$this->store->get('history-meta','nctSalt'));
        self::assertTrue($this->history->observedGlobalSettings()['settings']['securityNotifications']);
        self::assertArrayNotHasKey('chatLockSettings',$this->history->observedGlobalSettings()['settings']);
        self::assertCount(1,$this->history->observed()->list('calls'));
        self::assertSame(['recent','favorite'],$this->history->observed()->list('stickers')[0]['collections']);
    }
    public function testSinkOnlyPreservesProtocolStateWithoutBulkHistoryOrObservations():void
    {
        $result=$this->store->transaction(function():array {
            $importer=$this->importer(false);
            foreach (self::items() as $item) $importer->item($item);
            return $importer->result();
        });
        self::assertSame(2,$result['messagesCount']);
        self::assertSame(1,$result['conversationsCount']);
        self::assertSame(1,$result['pushnamesCount']);
        self::assertSame([], $this->history->listChats());
        self::assertSame([], $this->history->listContacts());
        self::assertSame([], $this->history->listMessages('200@s.whatsapp.net'));
        self::assertSame([], $this->history->listMessages('status@broadcast'));
        self::assertSame([], $this->history->observed()->list('calls'));
        self::assertSame([], $this->history->observed()->list('stickers'));
        self::assertSame([], $this->history->omittedConversations()['conversations']);
        self::assertSame('900@lid',$this->store->get('message-pn-to-lid','200@s.whatsapp.net'));
        $secret=BinaryData::decode($this->store->get('message-secrets',hash('sha256',"200@s.whatsapp.net\0received")));
        self::assertSame(str_repeat('s',32),$secret['secret']);
        self::assertSame('200@s.whatsapp.net',$secret['author']);
        $token=BinaryData::decode($this->store->get('privacy-token-records','200@s.whatsapp.net'));
        self::assertSame("\0token",$token['tcToken']);
        self::assertSame(100,$token['tcTokenTimestamp']);
        self::assertSame(101,$token['tcTokenSenderTimestamp']);
        self::assertSame("\0\xffsalt",$this->store->get('history-meta','nctSalt'));
        self::assertTrue($this->history->observedGlobalSettings()['settings']['securityNotifications']);
        self::assertArrayNotHasKey('chatLockSettings',$this->history->observedGlobalSettings()['settings']);
    }
    public function testSinkOnlyMessageSecretsKeepOwnGroupAuthorAndNeverInventAnUnknownAuthor():void
    {
        $importer=$this->importer(false);
        $this->store->transaction(function() use($importer):void {
            foreach ([['id'=>'own','fromMe'=>true],['id'=>'other','participant'=>'201:3@s.whatsapp.net'],['id'=>'unknown']] as $key) {
                $importer->item(['kind'=>'conversations.messages','conversationId'=>'100-200@g.us','value'=>['message'=>['key'=>$key,'messageSecret'=>str_repeat('x',32)]]]);
            }
            $importer->finish();
        });
        $own=BinaryData::decode($this->store->get('message-secrets',hash('sha256',"100-200@g.us\0own")));
        $other=BinaryData::decode($this->store->get('message-secrets',hash('sha256',"100-200@g.us\0other")));
        self::assertSame('100@s.whatsapp.net',$own['author']);
        self::assertSame('201@s.whatsapp.net',$other['author']);
        self::assertNull($this->store->get('message-secrets',hash('sha256',"100-200@g.us\0unknown")));
        self::assertSame([],$this->history->listChats());
    }
    public function testCallerTransactionRollsBackSecretsMappingsAndDeferredContactsOnFailure():void
    {
        try {
            $this->store->transaction(function():void {
                $importer=$this->importer();
                foreach (self::items() as $item) $importer->item($item);
                $importer->item(['kind'=>'phoneNumberToLidMappings','value'=>['pnJid'=>'200@s.whatsapp.net','lidJid'=>'bad@g.us']]);
            });
            self::fail('Expected invalid mapping');
        } catch (\UnexpectedValueException) {}
        self::assertNull($this->store->get('message-pn-to-lid','200@s.whatsapp.net'));
        self::assertNull($this->store->get('message-secrets',hash('sha256',"200@s.whatsapp.net\0received")));
        self::assertSame([],$this->history->listChats());
        self::assertSame([],$this->history->listContacts());
        $importer=$this->importer();
        $this->store->transaction(function() use($importer):void {
            $importer->item(['kind'=>'pushnames','value'=>['id'=>'200@s.whatsapp.net','pushname'=>'Fresh']]);
            $importer->finish();
        });
        self::assertSame('Fresh',$this->history->getContact('200@s.whatsapp.net')['pushName']);
        self::assertNull($this->history->getContact('900@lid'));
    }
    public function testSinkOnlyOlderHistoryCannotReplaceNewerLivePrivacyTokens():void
    {
        $this->store->set('privacy-token-records','200@s.whatsapp.net',BinaryData::encode(['tcToken'=>'newer','tcTokenTimestamp'=>500,'tcTokenSenderTimestamp'=>600]));
        $this->history->upsertChat(['id'=>'300@s.whatsapp.net','tcToken'=>'legacy','tcTokenTimestamp'=>700,'tcTokenSenderTimestamp'=>800]);
        $this->store->transaction(function():void {
            $importer=$this->importer(false);
            foreach (['200@s.whatsapp.net','300@s.whatsapp.net'] as $id) $importer->item(['kind'=>'conversations','value'=>['id'=>$id,'tcToken'=>'old','tcTokenTimestamp'=>100,'tcTokenSenderTimestamp'=>200]]);
            $importer->finish();
        });
        $row=BinaryData::decode($this->store->get('privacy-token-records','200@s.whatsapp.net'));
        self::assertSame('newer',$row['tcToken']);
        self::assertSame(500,$row['tcTokenTimestamp']);
        self::assertSame(600,$row['tcTokenSenderTimestamp']);
        self::assertNull($this->store->get('privacy-token-records','300@s.whatsapp.net'));
        $this->store->transaction(function():void {
            $importer=$this->importer(false);
            $importer->item(['kind'=>'conversations','value'=>['id'=>'200@s.whatsapp.net','tcToken'=>'fresh','tcTokenTimestamp'=>900,'tcTokenSenderTimestamp'=>1000]]);
            $importer->finish();
        });
        $row=BinaryData::decode($this->store->get('privacy-token-records','200@s.whatsapp.net'));
        self::assertSame('fresh',$row['tcToken']);
        self::assertSame(900,$row['tcTokenTimestamp']);
        self::assertSame(1000,$row['tcTokenSenderTimestamp']);
    }
    public function testResultIsStableButFinishedImporterCannotAcceptNewItems():void
    {
        $importer=$this->importer();
        self::assertSame($importer->result(),$importer->result());
        $this->expectException(\LogicException::class);
        $importer->item(['kind'=>'progress','value'=>99]);
    }
}
