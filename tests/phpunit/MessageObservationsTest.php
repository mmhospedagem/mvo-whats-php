<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\History\{HistoryService,HistoryStore};
use MVO\Media\MediaTransfer;
use MVO\Message\ModernEvents;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Service\HistoryFeaturesService;
use MVO\Signal\SignalStore;
use PHPUnit\Framework\TestCase;

final class MessageObservationsTest extends TestCase
{
    private SignalStore $store;
    private HistoryStore $history;
    protected function setUp():void{$this->store=new SignalStore(':memory:');$this->history=new HistoryStore($this->store);}
    protected function tearDown():void{$this->store->close();}
    private function parent(string $chat='123@g.us'):void{$this->history->recordEvent(['id'=>'P','chat'=>$chat,'from'=>'100@s.whatsapp.net','participant'=>'100@s.whatsapp.net','timestamp'=>100,'proto'=>['conversation'=>'Original'],'text'=>'Original','type'=>'text']);}
    private function event(string $id,string $actor,int $time,array $proto,array $extra=[]):void{$this->history->recordEvent(['id'=>$id,'chat'=>'123@g.us','authenticatedSender'=>$actor,'from'=>$actor,'timestamp'=>$time,'proto'=>$proto,...$extra]);}
    private function react(string $id,string $actor,int $ms,string $emoji,array $extra=[]):void{$this->event($id,$actor,intdiv($ms,1000),['reactionMessage'=>['key'=>['id'=>'P','remoteJid'=>'123@g.us','participant'=>'100@s.whatsapp.net'],'text'=>$emoji,'senderTimestampMs'=>$ms]],$extra);}
    private function edit(string $id,string $actor,int $ms,string $text):void{$this->event($id,$actor,intdiv($ms,1000),['protocolMessage'=>['type'=>14,'key'=>['id'=>'P','remoteJid'=>'123@g.us','participant'=>'100@s.whatsapp.net'],'timestampMs'=>$ms,'editedMessage'=>['conversation'=>$text]]]);}
    public function testReactionsReplaceRemoveOrderAndPaginateWithoutCountingCiphertext():void
    {
        $this->parent();$this->react('R1','200@s.whatsapp.net',200000,'👍');$this->react('R2','300@s.whatsapp.net',201000,'👍');
        $s=$this->history->messageObservations()->reactions('123@g.us','P',1);self::assertSame(2,$s['totalReactions']);self::assertSame([['emoji'=>'👍','count'=>2]],$s['counts']);self::assertCount(1,$s['items']);
        $next=$this->history->messageObservations()->reactions('123@g.us','P',1,$s['nextCursor']);self::assertCount(1,$next['items']);self::assertNotSame($s['items'][0]['author'],$next['items'][0]['author']);
        $this->react('R3','200@s.whatsapp.net',203000,'❤️');$this->react('OLD','200@s.whatsapp.net',202000,'👎');$this->react('REMOVE','300@s.whatsapp.net',204000,'');$this->react('BAD','400@s.whatsapp.net',205000,'👍',['encrypted'=>true]);
        $s=$this->history->messageObservations()->reactions('123@g.us','P');self::assertSame(1,$s['totalReactions']);self::assertSame([['emoji'=>'❤️','count'=>1]],$s['counts']);
        $this->event('CROSS','400@s.whatsapp.net',300,['reactionMessage'=>['key'=>['id'=>'P','remoteJid'=>'456@g.us'],'text'=>'👍']]);self::assertSame(1,$this->history->messageObservations()->reactions('123@g.us','P')['totalReactions']);
    }
    public function testAliasMigrationDoesNotDoubleCountTheSameParticipant():void
    {
        $this->parent();$this->react('PN','200@s.whatsapp.net',200000,'👍');$this->store->set('message-pn-to-lid','200@s.whatsapp.net','900@lid');$this->store->set('message-lid-to-pn','900@lid','200@s.whatsapp.net');$this->react('LID','900@lid',201000,'❤️');
        $s=$this->history->messageObservations()->reactions('123@g.us','P');self::assertSame(1,$s['totalReactions']);self::assertSame('❤️',$s['items'][0]['emoji']);
        $this->react('REMOVE','900@lid',202000,'');self::assertSame(0,$this->history->messageObservations()->reactions('123@g.us','P')['totalReactions']);
    }
    public function testOutOfOrderHistoryReplaysOnlyAuthenticatedMatchingAdditions():void
    {
        $this->react('R1','200@s.whatsapp.net',201000,'👍');$this->edit('E1','100@s.whatsapp.net',202000,'Edit before parent');$this->edit('FORGED','300@s.whatsapp.net',203000,'Hacked');
        self::assertCount(3,$this->store->entries('message-observations-pending',1000));$this->parent();
        self::assertSame('Edit before parent',$this->history->getMessage('123@g.us','P')['text']);self::assertSame(1,$this->history->messageObservations()->reactions('123@g.us','P')['totalReactions']);self::assertSame([],$this->store->entries('message-observations-pending',1000));
        $r=$this->history->messageObservations()->revisions('123@g.us','P',1);self::assertCount(1,$r['items']);self::assertSame('E1',$r['items'][0]['messageId']);self::assertSame([],$this->history->messageObservations()->revisions('123@g.us','P',1,$r['nextCursor'])['items']);
    }
    public function testEditsPreserveOriginalAndRejectCrossAuthorWrongTargetAndFutureTimestamp():void
    {
        $this->parent();$this->edit('FORGED','200@s.whatsapp.net',200000,'Hacked');self::assertSame('Original',$this->history->getMessage('123@g.us','P')['text']);
        $this->edit('E2','100@s.whatsapp.net',202000,'Latest');$this->edit('E1','100@s.whatsapp.net',201000,'Earlier');$this->edit('E2','100@s.whatsapp.net',202000,'Latest');
        self::assertSame('Latest',$this->history->getMessage('123@g.us','P')['text']);self::assertSame('Original',$this->history->getStoredMessage('123@g.us','P')['text']);$listed=array_column($this->history->listMessages('123@g.us',100),null,'id');self::assertSame('Latest',$listed['P']['text']);
        $this->event('BADTIME','100@s.whatsapp.net',203,['protocolMessage'=>['type'=>14,'key'=>['id'=>'P'],'timestampMs'=>PHP_INT_MAX,'editedMessage'=>['conversation'=>'Future']]]);
        $r=$this->history->messageObservations()->revisions('123@g.us','P');self::assertCount(2,$r['items']);self::assertSame('Original',$r['originalText']);self::assertSame('Latest',$r['currentText']);self::assertSame(['E1','E2'],array_column($r['items'],'messageId'));
        $this->history->updateMessage('123@g.us','P',['read'=>true]);self::assertSame('Original',$this->history->getStoredMessage('123@g.us','P')['text']);self::assertSame('Latest',$this->history->getMessage('123@g.us','P')['text']);
    }
    public function testRevocationIsTerminalAndLocalDeletionIsDifferent():void
    {
        $this->parent();$this->edit('E1','100@s.whatsapp.net',201000,'Edited');$this->event('DELETE','100@s.whatsapp.net',202,['protocolMessage'=>['type'=>0,'key'=>['id'=>'P'],'timestampMs'=>202000]]);$this->edit('TOO_LATE','100@s.whatsapp.net',203000,'Resurrect');
        $m=$this->history->getMessage('123@g.us','P');self::assertTrue($m['revoked']);self::assertNull($m['text']);self::assertArrayNotHasKey('originalText',$m);self::assertArrayNotHasKey('originalProto',$m);self::assertSame('Original',$this->history->messageObservations()->revisions('123@g.us','P')['originalText']);self::assertSame([],$m['proto']);self::assertSame('revoked',$m['type']);
        $this->history->deleteMessage('123@g.us','P');self::assertNull($this->history->getMessage('123@g.us','P'));
    }
    public function testDecryptedCommentsNeedAnObservedMatchingParentAndDoNotAdvertiseSend():void
    {
        $this->parent();$this->event('C','200@s.whatsapp.net',201,[],['type'=>'comment','target'=>['id'=>'P','remoteJid'=>'123@g.us'],'data'=>['message'=>['conversation'=>'Reply'],'targetMessageKey'=>['id'=>'P']],'encrypted'=>false]);
        $this->event('FAIL','200@s.whatsapp.net',202,[],['type'=>'comment','data'=>['message'=>['conversation'=>'Secret'],'targetMessageKey'=>['id'=>'P']],'decryptError'=>'addon_authentication_failed']);
        $rows=$this->history->messageObservations()->list('comments',['chat'=>'123@g.us','targetMessageId'=>'P']);self::assertCount(1,$rows);self::assertSame('Reply',$rows[0]['text']);self::assertFalse($rows[0]['nativeSendSupported']);self::assertArrayNotHasKey('proto',$rows[0]);
    }
    public function testLiveCoordinatesAndAfterReadAreObservedWithoutInventingLifecycleOrExpiry():void
    {
        $this->event('LOC','200@s.whatsapp.net',201,['liveLocationMessage'=>['degreesLatitude'=>-16.5,'degreesLongitude'=>-49.5,'sequenceNumber'=>2,'timeOffset'=>5,'contextInfo'=>['afterReadDuration'=>3600],'jpegThumbnail'=>'PRIVATE']]);
        $this->event('BAD','200@s.whatsapp.net',202,['liveLocationMessage'=>['degreesLatitude'=>91.0,'degreesLongitude'=>-49.5]]);
        $rows=$this->history->messageObservations()->list('live-locations');self::assertCount(1,$rows);self::assertSame(-16.5,$rows[0]['latitude']);self::assertFalse($rows[0]['nativeLifecycleSupported']);self::assertArrayNotHasKey('jpegThumbnail',$rows[0]);
        $policy=$this->history->messageObservations()->list('after-read')[0];self::assertSame(3600,$policy['reportedDuration']);self::assertFalse($policy['expiryKnown']);self::assertFalse($policy['nativeEnableSupported']);self::assertArrayNotHasKey('expiresAt',$policy);
    }
    public function testHistoryImportsFormerParticipantsAndNativeMemberLabels():void
    {
        $service=new HistoryService($this->store,new MediaTransfer(static fn():BinaryNode=>throw new \LogicException('No network')),static fn():array=>['meJid'=>'100:5@s.whatsapp.net'],static function():void{},static function():void{});
        $chunk=SchemaCodec::encode('HistorySync',['syncType'=>'FULL','pastParticipants'=>[['groupJid'=>'123@g.us','pastParticipants'=>[['userJid'=>'200@s.whatsapp.net','leaveReason'=>'LEFT','leaveTs'=>150],['userJid'=>'300@s.whatsapp.net','leaveReason'=>'REMOVED','leaveTs'=>160]]]],'conversations'=>[['id'=>'123@g.us','participant'=>[['userJid'=>'100@s.whatsapp.net','memberLabel'=>['label'=>'Equipe','labelTimestamp'=>150]]]]]]);
        $service->handleProtocol(['id'=>'H','from'=>'100@s.whatsapp.net','fromMe'=>true,'proto'=>['protocolMessage'=>['historySyncNotification'=>['syncType'=>2,'initialHistBootstrapInlinePayload'=>gzcompress($chunk)]]]]);$service->drainPending();
        $rows=$this->history->messageObservations()->list('past-participants',['chat'=>'123@g.us']);self::assertCount(2,$rows);self::assertFalse($rows[0]['currentMembershipKnown']);$rows=$this->history->messageObservations()->list('member-labels');self::assertSame('Equipe',$rows[0]['label']);self::assertFalse($rows[0]['nativeSetSupported']);
        $this->event('LABEL','100@s.whatsapp.net',200,['protocolMessage'=>['type'=>30,'memberLabel'=>['label'=>'Suporte','labelTimestamp'=>200]]]);self::assertSame('Suporte',$this->history->messageObservations()->list('member-labels')[0]['label']);
    }
    public function testBotMetadataIsBoundedTypedAndOnlyPersistedForAuthenticatedBot():void
    {
        $metadata=['progressIndicatorMetadata'=>['progressDescription'=>'Buscando','estimatedCompletionTime'=>10,'stepsMetadata'=>[['statusTitle'=>'Pesquisa','statusBody'=>'Fontes','status'=>1,'privateKey'=>'SECRET']]],'suggestedPromptMetadata'=>['suggestedPrompts'=>['Próxima pergunta']],'memoryMetadata'=>['addedFacts'=>[['fact'=>'Português','factId'=>'F1','secret'=>'SECRET']],'removedFacts'=>[],'disclaimer'=>'Observado'],'capabilityMetadata'=>['capabilities'=>[1]],'internalMetadata'=>'SECRET'];
        $proto=['messageContextInfo'=>['botMetadata'=>$metadata],'conversation'=>'Resposta'];$modern=ModernEvents::normalize($proto);self::assertSame('Buscando',$modern['botProgress']['description']);self::assertSame('Português',$modern['botMemory']['added'][0]['text']);self::assertFalse($modern['botMemory']['writeSupported']);self::assertStringNotContainsString('SECRET',json_encode($modern));
        $this->event('FAKE','200@s.whatsapp.net',201,$proto);self::assertSame([],$this->history->messageObservations()->list('bot-observations'));
        $this->event('BOT','867051314767696@bot',202,$proto);$rows=$this->history->messageObservations()->list('bot-observations');self::assertCount(1,$rows);self::assertFalse($rows[0]['memoryWriteSupported']);
        $f=new HistoryFeaturesService($this->store,$this->history,static fn():array=>[]);$c=$f->capabilities();foreach(['comments','liveLocation','afterRead']as$name)self::assertFalse($c['experimental'][$name]['nativePublish']);
    }
    public function testHistoryGlobalSettingsAreSanitizedBeforePublicProjection():void
    {
        $service=new HistoryService($this->store,new MediaTransfer(static fn():BinaryNode=>throw new \LogicException('No network')),static fn():array=>['meJid'=>'100:5@s.whatsapp.net'],static function():void{},static function():void{});
        $settings=['securityNotifications'=>true,'autoUnarchiveChats'=>false,'showIndividualNotificationsPreview'=>false,'autoDownloadWiFi'=>['downloadImages'=>true],'chatLockSettings'=>['secretCode'=>['transformedData'=>'PRIVATE']],'avatarUserSettings'=>['password'=>'PRIVATE']];
        $chunk=SchemaCodec::encode('HistorySync',['syncType'=>'FULL','globalSettings'=>$settings]);
        $service->handleProtocol(['id'=>'H_GLOBAL','from'=>'100@s.whatsapp.net','fromMe'=>true,'proto'=>['protocolMessage'=>['historySyncNotification'=>['syncType'=>2,'initialHistBootstrapInlinePayload'=>gzcompress($chunk)]]]]);$service->drainPending();
        $r=$this->history->observedGlobalSettings();self::assertSame('authenticated_history',$r['source']);self::assertTrue($r['settings']['securityNotifications']);self::assertFalse($r['settings']['autoUnarchiveChats']);self::assertArrayNotHasKey('chatLockSettings',$r['settings']);self::assertArrayNotHasKey('avatarUserSettings',$r['settings']);self::assertStringNotContainsString('PRIVATE',json_encode($r));
    }
    public function testIncomingDirectKeysMayNameOnlyTheTransportAuthenticatedLocalRecipient():void
    {
        $peer='900@lid';$own='800@lid';
        $this->history->recordEvent(['id'=>'OWN','chat'=>$peer,'from'=>$own,'fromMe'=>true,'timestamp'=>100,'text'=>'Own original','proto'=>['conversation'=>'Own original']]);
        $base=['chat'=>$peer,'from'=>$peer,'authenticatedSender'=>$peer,'authenticatedRecipientJid'=>'100@s.whatsapp.net','authenticatedRecipientAlt'=>$own,'fromMe'=>false,'timestamp'=>200];
        $key=['id'=>'OWN','remoteJid'=>$own,'fromMe'=>false];
        $this->history->recordEvent($base+['id'=>'REACTION','proto'=>['reactionMessage'=>['key'=>$key,'text'=>'👍','senderTimestampMs'=>200000]]]);self::assertSame(1,$this->history->messageObservations()->reactions($peer,'OWN')['totalReactions']);
        foreach([['id'=>'CROSS','remote'=>'700@lid','fromMe'=>false],['id'=>'OUTGOING','remote'=>$own,'fromMe'=>true]]as$bad)$this->history->recordEvent([...$base,'id'=>$bad['id'],'fromMe'=>$bad['fromMe'],'proto'=>['reactionMessage'=>['key'=>['id'=>'OWN','remoteJid'=>$bad['remote']],'text'=>'❤️','senderTimestampMs'=>201000]]]);
        self::assertSame([['emoji'=>'👍','count'=>1]],$this->history->messageObservations()->reactions($peer,'OWN')['counts']);
        $this->history->recordEvent(['id'=>'PEER','chat'=>$peer,'from'=>$peer,'fromMe'=>false,'timestamp'=>100,'text'=>'Peer original','proto'=>['conversation'=>'Peer original']]);
        $this->history->recordEvent($base+['id'=>'EDIT_DIRECT','proto'=>['protocolMessage'=>['key'=>['id'=>'PEER','remoteJid'=>$own,'fromMe'=>true],'type'=>14,'timestampMs'=>200000,'editedMessage'=>['conversation'=>'Peer edited']]]]);self::assertSame('Peer edited',$this->history->getMessage($peer,'PEER')['text']);
        $this->history->recordEvent([...$base,'timestamp'=>201,'id'=>'REVOKE_DIRECT','proto'=>['protocolMessage'=>['key'=>['id'=>'PEER','remoteJid'=>$own,'fromMe'=>true],'type'=>0]]]);self::assertTrue($this->history->getMessage($peer,'PEER')['revoked']);
        $this->parent();$this->event('GROUP_BAD','200@s.whatsapp.net',202,['reactionMessage'=>['key'=>['id'=>'P','remoteJid'=>$own],'text'=>'👍']],['fromMe'=>false,'authenticatedRecipientAlt'=>$own]);self::assertSame(0,$this->history->messageObservations()->reactions('123@g.us','P')['totalReactions']);
    }
    public function testSenderPerspectiveKeysAlsoWorkForAuthenticatedHistoryImportsAndPendingOriginals():void
    {
        $this->history->recordWebMessage(['key'=>['id'=>'R','fromMe'=>false],'messageTimestamp'=>200,'message'=>['reactionMessage'=>['key'=>['id'=>'P','remoteJid'=>'100@s.whatsapp.net','fromMe'=>false],'text'=>'👍','senderTimestampMs'=>200000]]],'200@s.whatsapp.net','100:5@s.whatsapp.net');
        self::assertCount(1,$this->store->entries('message-observations-pending',1000));
        $this->history->recordWebMessage(['key'=>['id'=>'P','fromMe'=>true],'messageTimestamp'=>100,'message'=>['conversation'=>'Own historical original']],'200@s.whatsapp.net','100:5@s.whatsapp.net');
        self::assertSame(1,$this->history->messageObservations()->reactions('200@s.whatsapp.net','P')['totalReactions']);self::assertSame([],$this->store->entries('message-observations-pending',1000));
    }
}
