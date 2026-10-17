<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\History\HistoryStore;
use MVO\Message\{PhoneNumberRequests,PhoneNumberShares};
use MVO\Service\IdentityService;
use MVO\Signal\SignalStore;
use PHPUnit\Framework\TestCase;

final class PhoneNumberSharesTest extends TestCase
{
    private const PN='5511999999999@s.whatsapp.net';private const LID='123456789@lid';
    private SignalStore $store;private HistoryStore $history;private IdentityService $identities;
    protected function setUp():void{$this->store=new SignalStore(':memory:');$this->history=new HistoryStore($this->store);$this->identities=new IdentityService($this->store);}
    protected function tearDown():void{$this->store->close();}
    private function event(string $id='share'):array{return ['id'=>$id,'chat'=>self::PN,'fromMe'=>false,'timestamp'=>100,'authenticatedSender'=>'5511999999999:7@s.whatsapp.net','authenticatedOwnDevice'=>false,'proto'=>['protocolMessage'=>['type'=>11]]];}
    public function testNativeProtocolNeedsNoKeyOrContextAndPreservesAbsenceOfRequestCorrelation():void
    {
        $share=$this->history->decodePhoneNumberShare($this->event());self::assertSame('5511999999999',$share['phoneNumber']);self::assertSame(self::PN,$share['phoneJid']);self::assertSame('authenticated_stanza',$share['numberSource']);self::assertNull($share['requestId']);self::assertSame('conversation',$share['correlation']);self::assertTrue($share['native']);self::assertFalse($share['fromMe']);self::assertArrayNotHasKey('status',$share);
    }
    public function testLidFrameUsesOnlyConfirmedAuthenticatedAlternative():void
    {
        $this->identities->remember(self::PN,self::LID,true);$event=$this->event();$event['chat']=self::LID;$event['authenticatedSender']='123456789:7@lid';$event['key']=['remoteJid'=>self::LID,'remoteJidAlt'=>self::PN];
        $share=$this->history->decodePhoneNumberShare($event);self::assertSame(self::PN,$share['phoneJid']);self::assertSame(self::LID,$share['lid']);self::assertSame('authenticated_identity_mapping',$share['numberSource']);
    }
    public function testUnconfirmedAlternativeCannotSpoofAReceivedNumber():void
    {
        $event=$this->event();$event['chat']=self::LID;$event['authenticatedSender']='123456789:7@lid';$event['key']=['remoteJidAlt'=>self::PN];$event['senderPn']=self::PN;$event['phoneNumber']='5511999999999';
        $share=$this->history->decodePhoneNumberShare($event);self::assertNull($share['phoneJid']);self::assertNull($share['phoneNumber']);self::assertSame('unavailable',$share['numberSource']);
    }
    public function testKnownMappingSuppliesNumberOnlyAfterExplicitNativeProtocol():void
    {
        $this->identities->remember(self::PN,self::LID,true);$event=$this->event();$event['chat']=self::LID;$event['authenticatedSender']='123456789:7@lid';
        $share=$this->history->decodePhoneNumberShare($event);self::assertSame(self::PN,$share['phoneJid']);self::assertSame('authenticated_identity_mapping',$share['numberSource']);$event['proto']=['conversation'=>'a known PN is not consent'];self::assertNull($this->history->decodePhoneNumberShare($event));
    }
    public function testArbitrarySelfContactAndVcardNeverCountAsNativeConsent():void
    {
        $event=$this->event();$event['proto']=['contactMessage'=>['isSelfContact'=>true,'vcard'=>'BEGIN:VCARD\nTEL:12345','contextInfo'=>['stanzaId'=>'request']]];self::assertNull($this->history->decodePhoneNumberShare($event));
    }
    public function testMissingAuthenticationOrContradictorySenderIsRejected():void
    {
        $event=$this->event();unset($event['authenticatedSender']);$event['from']=self::PN;self::assertNull($this->history->decodePhoneNumberShare($event));
        $event=$this->event();$event['authenticatedSender']='5511888888888@s.whatsapp.net';self::assertNull($this->history->decodePhoneNumberShare($event));
        $event=$this->event();$event['fromMe']=true;self::assertNull($this->history->decodePhoneNumberShare($event));
    }
    public function testGroupAndUnsupportedIdentitiesCannotBecomeDirectSharing():void
    {
        foreach(['12345@g.us','status@broadcast','12345@newsletter','12345@bot']as$chat){$event=$this->event();$event['chat']=$chat;self::assertNull($this->history->decodePhoneNumberShare($event));}
    }
    public function testMalformedPayloadsAreRejectedWithoutInventingTimestampOrDirection():void
    {
        foreach([['timestamp',null],['timestamp',-1],['fromMe','false'],['id',''],['proto',[]]]as[$key,$value]){$event=$this->event();$event[$key]=$value;self::assertNull($this->history->decodePhoneNumberShare($event));}
    }
    public function testOwnDeviceEchoNeverUsesPeerAlternativeAsOwnPhone():void
    {
        $this->identities->remember(self::PN,self::LID,true);$event=$this->event();$event['chat']=self::LID;$event['fromMe']=true;$event['authenticatedOwnDevice']=true;$event['authenticatedSender']='5511222222222:7@s.whatsapp.net';$event['key']=['remoteJidAlt'=>self::PN];
        $share=$this->history->decodePhoneNumberShare($event);self::assertTrue($share['fromMe']);self::assertSame('5511222222222@s.whatsapp.net',$share['phoneJid']);self::assertNull($share['requestId']);
    }
    public function testConfirmedAliasChatsAreReadDeduplicatedAndPaginatedWithoutOtherChats():void
    {
        $this->identities->remember(self::PN,self::LID,true);$this->history->recordEvent($this->event('a'));$this->history->recordEvent($this->event('b'));
        $duplicate=$this->event('a');$duplicate['chat']=self::LID;$duplicate['authenticatedSender']='123456789:7@lid';$this->history->recordEvent($duplicate);
        $unrelated=$this->event('c');$unrelated['chat']='5511777777777@s.whatsapp.net';$unrelated['authenticatedSender']=$unrelated['chat'];$this->history->recordEvent($unrelated);
        $first=$this->history->phoneNumberShares(self::LID,1);self::assertCount(1,$first);self::assertSame('a',$first[0]['messageId']);self::assertSame(self::LID,$first[0]['chat']);$next=$this->history->phoneNumberShares(self::LID,1,$first[0]['cursor']);self::assertSame(['b'],array_column($next,'messageId'));self::assertSame([],PhoneNumberShares::list($this->history,self::PN,1,$next[0]['cursor']));
    }
    public function testNativeShareDoesNotFalselyCompleteAnUnquotedPhoneNumberRequest():void
    {
        $this->history->recordEvent(['id'=>'request','chat'=>self::PN,'fromMe'=>true,'timestamp'=>99,'proto'=>['requestPhoneNumberMessage'=>[]]]);$this->history->recordEvent($this->event());
        self::assertSame('awaiting_response',PhoneNumberRequests::list($this->history,self::PN)[0]['state']);self::assertNull($this->history->phoneNumberShares(self::PN)[0]['requestId']);
    }
    public function testHistoryImportedWithoutVerifiedSenderDoesNotBecomeNativeSharing():void
    {
        $this->history->recordWebMessage(['key'=>['id'=>'imported','fromMe'=>false],'messageTimestamp'=>100,'message'=>['protocolMessage'=>['type'=>11]]],self::PN,'5511222222222@s.whatsapp.net');self::assertSame([],$this->history->phoneNumberShares(self::PN));
    }
    public function testDecryptionFailuresAndContradictoryOwnDeviceEvidenceAreRejected():void
    {
        foreach([['encrypted',true],['decryptError','authentication_failed'],['authentication','message-secret'],['authenticatedOwnDevice',true]]as[$field,$value]){$event=$this->event();$event[$field]=$value;self::assertNull($this->history->decodePhoneNumberShare($event));}
    }
    public function testNativeObservationUsesOriginalHistoryWhenCurrentMessageWasRevoked():void
    {
        $this->history->recordEvent($this->event());$revoke=$this->event('revoke');$revoke['timestamp']=101;$revoke['proto']=['protocolMessage'=>['type'=>0,'key'=>['id'=>'share','remoteJid'=>self::PN,'fromMe'=>true]]];$this->history->recordEvent($revoke);
        self::assertTrue($this->history->getMessage(self::PN,'share')['revoked']);self::assertSame([],$this->history->getMessage(self::PN,'share')['proto']);self::assertSame('share',$this->history->phoneNumberShares(self::PN)[0]['messageId']);
    }
    public function testWrappedEnumNameIsDecodedAndRawRecordRemainsDurableAcrossHistoryFacades():void
    {
        $event=$this->event();$event['proto']=['deviceSentMessage'=>['destinationJid'=>self::PN,'message'=>['protocolMessage'=>['type'=>'SHARE_PHONE_NUMBER']]]];$this->history->recordEvent($event);$reopened=new HistoryStore($this->store);self::assertSame('share',$reopened->phoneNumberShares(self::PN)[0]['messageId']);self::assertSame($event['proto'],$reopened->getStoredMessage(self::PN,'share')['proto']);
    }
    public function testLidSuccessionDoesNotCombineAnUnrelatedOldIdentity():void
    {
        $this->identities->remember(self::PN,self::LID,true);$this->identities->changeLid(self::LID,'987654321@lid');$event=$this->event();$event['chat']='987654321@lid';$event['authenticatedSender']='987654321:7@lid';$this->history->recordEvent($event);self::assertCount(1,$this->history->phoneNumberShares(self::LID));self::assertSame([], $this->history->phoneNumberShares('11223344@lid'));
    }
    public function testCursorPreservesDifferentDirectionsWithTheSameMessageId():void
    {
        $this->identities->remember(self::PN,self::LID,true);$this->history->recordEvent($this->event('same-id'));$own=$this->event('same-id');$own['chat']=self::LID;$own['fromMe']=true;$own['authenticatedOwnDevice']=true;$own['authenticatedSender']='5511222222222:7@s.whatsapp.net';$this->history->recordEvent($own);
        $first=$this->history->phoneNumberShares(self::PN,1);$next=$this->history->phoneNumberShares(self::PN,1,$first[0]['cursor']);self::assertFalse($first[0]['fromMe']);self::assertTrue($next[0]['fromMe']);self::assertNotSame($first[0]['cursor'],$next[0]['cursor']);self::assertSame([],$this->history->phoneNumberShares(self::PN,1,$next[0]['cursor']));
    }
}
