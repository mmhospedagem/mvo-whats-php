<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\History\{HistoryService,HistoryStore,ProtocolValues};
use MVO\Media\MediaTransfer;
use MVO\Message\{BotResponseContent,ModernEvents};
use MVO\Protocol\{BinaryData,BinaryNode,SchemaCodec};
use MVO\Service\HistoryFeaturesService;
use MVO\Signal\SignalStore;
use PHPUnit\Framework\TestCase;

final class HistoryExtensionsTest extends TestCase
{
    private SignalStore $store;
    private HistoryStore $history;
    private array $events=[];
    private array $sent=[];
    protected function setUp():void{$this->store=new SignalStore(':memory:');$this->history=new HistoryStore($this->store);}
    protected function tearDown():void{$this->store->close();}
    private function service():HistoryService{return new HistoryService($this->store,new MediaTransfer(static fn():BinaryNode=>throw new \LogicException('No network')),
        static fn():array=>['meJid'=>'100:5@s.whatsapp.net'],function(string $event,array $data):void{$this->events[]=[$event,$data];},static function():void{});}
    private function features(?\Closure $publisher=null,array $credentials=[]):HistoryFeaturesService{return new HistoryFeaturesService($this->store,$this->history,static fn():array=>$credentials+['meJid'=>'100:5@s.whatsapp.net'],$publisher??function(string $to,array $protocol,array $options):array{$this->sent[]=[$to,$protocol,$options];return ['id'=>$options['id'],'to'=>$to,'status'=>'server_ack'];});}
    private function notify(array $notification,string $id='CHUNK'):string
    {
        $this->service()->handleProtocol(['id'=>$id,'from'=>'100@s.whatsapp.net','fromMe'=>true,'proto'=>['protocolMessage'=>['historySyncNotification'=>$notification]]]);return hash('sha256',"100@s.whatsapp.net\0".$id);
    }
    public function testFullChunksReallyImportAndAccessNotificationsDoNotRequireMedia():void
    {
        $chunk=SchemaCodec::encode('HistorySync',['syncType'=>'FULL','chunkOrder'=>0,'progress'=>100,'conversations'=>[['id'=>'200@s.whatsapp.net','messages'=>[['message'=>['key'=>['id'=>'OLD'],'message'=>['conversation'=>'Historical text'],'messageTimestamp'=>100]]]]]]);
        $id=$this->notify(['syncType'=>2,'chunkOrder'=>0,'initialHistBootstrapInlinePayload'=>gzcompress($chunk)]);$this->service()->drainPending();
        self::assertSame('Historical text',$this->history->getMessage('200@s.whatsapp.net','OLD')['text']);$sync=$this->features()->getSync($id);self::assertSame('imported',$sync['state']);self::assertSame(1,$sync['result']['messagesCount']);self::assertSame(100,$sync['result']['progress']);
        $id=$this->notify(['syncType'=>8,'messageAccessStatus'=>['completeAccessGranted'=>false]],'ACCESS');$this->service()->drainPending();self::assertFalse($this->features()->getSync($id)['completeAccessGranted']);self::assertSame('imported',$this->features()->getSync($id)['state']);
    }
    public function testFailedChunksExposeSafeErrorAndRetryRequestsAreCorrelatedAndNotRepeatedWhileUnknown():void
    {
        $id=$this->notify(['syncType'=>3,'chunkOrder'=>2,'initialHistBootstrapInlinePayload'=>'not-zlib']);$this->service()->drainPending();
        $row=$this->features()->getSync($id);self::assertSame('failed',$row['state']);self::assertSame('history_integrity_or_format_error',$row['error']);self::assertStringNotContainsString('not-zlib',json_encode($row));
        $f=$this->features();$request=$f->retryChunk($id);self::assertSame('server_ack',$request['state']);self::assertFalse($request['historyReceived']);self::assertSame('HISTORY_SYNC_CHUNK_RETRY',$this->sent[0][1]['peerDataOperationRequestMessage']['peerDataOperationRequestType']);
        self::assertSame('CHUNK',$this->sent[0][1]['peerDataOperationRequestMessage']['historySyncChunkRetryRequest']['chunkNotificationId']);
        try{$f->retryChunk($id);self::fail('Unresolved request repeated');}catch(\LogicException){}self::assertCount(1,$this->sent);
        $response=['id'=>'REPLY','fromMe'=>true,'authenticatedOwnDevice'=>true,'authenticatedSender'=>'999@s.whatsapp.net','proto'=>['protocolMessage'=>['peerDataOperationRequestResponseMessage'=>['peerDataOperationRequestType'=>10,'stanzaId'=>$request['requestId'],'peerDataOperationResult'=>[['historySyncChunkRetryResponse'=>['requestId'=>$request['requestId'],'responseCode'=>1,'canRecover'=>true]]]]]]];
        $f->ingestResponse($response);self::assertSame('server_ack',$f->getSync($request['id'])['state']);$response['authenticatedSender']='100@s.whatsapp.net';$f->ingestResponse($response);self::assertSame('primary_responded',$f->getSync($request['id'])['state']);self::assertTrue($f->getSync($request['id'])['canRecover']);
        $f->retryChunk($id);self::assertCount(2,$this->sent);try{$f->retryChunk($id);self::fail('Limit bypass');}catch(\LogicException){}
    }
    public function testUnknownRequestRetainsItsReservedIdAndBlocksAnotherAttempt():void
    {
        $id=$this->notify(['syncType'=>3,'chunkOrder'=>4,'initialHistBootstrapInlinePayload'=>'bad']);$this->service()->drainPending();$f=$this->features(static fn():array=>throw new \RuntimeException('private transport reason'));
        try{$f->retryChunk($id);self::fail();}catch(\RuntimeException){}$attempts=array_values(array_filter($f->listSyncs(),static fn(array $r):bool=>($r['kind']??null)==='request'));self::assertCount(1,$attempts);self::assertSame('unknown',$attempts[0]['state']);self::assertSame('history_request_outcome_unknown',$attempts[0]['error']);
        try{$f->retryChunk($id);self::fail();}catch(\LogicException){}self::assertSame(1,$f->getSync($id)['recoveryRequests']);
    }
    public function testFullHistoryNeedsObservedCoexContextAndOnlyReceptionMarksHistoryReceived():void
    {
        $plain=SchemaCodec::encode('HistorySync',['syncType'=>'RECENT']);$id=$this->notify(['syncType'=>3,'initialHistBootstrapInlinePayload'=>gzcompress($plain)]);
        try{$this->features()->requestFullHistory(['syncId'=>$id,'historyDurationDays'=>30]);self::fail();}catch(\LogicException){}self::assertSame([],$this->sent);
        $context=['requestId'=>'ORIGINAL','businessProduct'=>'Coex','connection'=>['providerId'=>123,'product'=>'business'],'opaqueClientData'=>"\xffsecret"];
        $id=$this->notify(['syncType'=>3,'initialHistBootstrapInlinePayload'=>gzcompress($plain),'fullHistorySyncOnDemandRequestMetadata'=>$context],'COEX');$request=$this->features()->requestFullHistory(['syncId'=>$id,'historyDurationDays'=>30]);self::assertSame('server_ack',$request['state']);self::assertFalse($request['historyReceived']);self::assertStringNotContainsString('opaqueClientData',json_encode($this->features()->listSyncs()));
        $context['requestId']=$request['requestId'];$this->notify(['syncType'=>2,'initialHistBootstrapInlinePayload'=>gzcompress(SchemaCodec::encode('HistorySync',['syncType'=>'FULL'])),'fullHistorySyncOnDemandRequestMetadata'=>$context],'FULL-REPLY');$this->service()->drainPending();self::assertTrue($this->features()->getSync($request['id'])['historyReceived']);
        self::assertSame('received',$this->features()->getSync($request['id'])['state']);
    }
    public function testCallsAndStickerMetadataPersistAtomicallyWithoutPublicDownloadSecrets():void
    {
        $data=['callLogRecords'=>[['callId'=>'call-1','callCreatorJid'=>'200@s.whatsapp.net','startTime'=>10,'duration'=>20,'isVideo'=>true,'callResult'=>'MISSED','participants'=>[['userJid'=>'200@s.whatsapp.net']]]],
            'recentStickers'=>[['fileSha256'=>str_repeat('h',32),'mediaKey'=>str_repeat('s',32),'directPath'=>'/private','isLottie'=>true,'height'=>512]],'favoriteStickers'=>[['fileSha256'=>str_repeat('h',32),'mediaKey'=>str_repeat('s',32)]]];
        $wire=SchemaCodec::encode('HistorySync',$data);try{$this->service()->ingest($wire."\x12\xff");self::fail();}catch(\UnexpectedValueException){}self::assertSame([],$this->features()->listCallLogs());self::assertSame([],$this->features()->listStickers());
        $this->service()->ingest($wire);self::assertTrue($this->features()->listCallLogs()[0]['isVideo']);self::assertSame(4,$this->features()->listCallLogs()[0]['callResult']);$stickers=$this->features()->listStickers(['collection'=>'favorite']);self::assertSame(['recent','favorite'],$stickers[0]['collections']);self::assertStringNotContainsString('mediaKey',json_encode($stickers));self::assertStringNotContainsString('directPath',json_encode($stickers));
    }
    public function testStatusAnswersAndStickerReactionsRetainActualRespondentsAndOriginalKeys():void
    {
        $key=['id'=>'POST','remoteJid'=>'status@broadcast','participant'=>'100@s.whatsapp.net'];
        foreach(['200','300']as$user)$this->history->recordEvent(['id'=>'SAME-ANSWER','chat'=>$user.'@s.whatsapp.net','from'=>$user.'@s.whatsapp.net','timestamp'=>10,'proto'=>['statusQuestionAnswerMessage'=>['key'=>$key,'text'=>'Answer '.$user]]]);
        $this->history->recordEvent(['id'=>'STICKER','chat'=>'200@s.whatsapp.net','from'=>'200@s.whatsapp.net','proto'=>['statusStickerInteractionMessage'=>['key'=>$key,'stickerKey'=>'heart','type'=>1]]]);
        $this->history->recordEvent(['id'=>'QUOTE','chat'=>'200@s.whatsapp.net','from'=>'200@s.whatsapp.net','proto'=>['statusQuotedMessage'=>['originalStatusId'=>$key,'text'=>'Reshare','thumbnail'=>'private-binary','type'=>1]]]);
        $rows=$this->features()->listStatusInteractions(['messageId'=>'POST']);self::assertCount(4,$rows);self::assertCount(1,$this->features()->listStatusInteractions(['messageId'=>'POST','respondent'=>'300@s.whatsapp.net']));self::assertSame([], $this->features()->listStatusInteractions(['author'=>'999@s.whatsapp.net']));self::assertStringNotContainsString('private-binary',json_encode($rows));
        $this->history->recordEvent(['id'=>'PLAIN','chat'=>'200@s.whatsapp.net','from'=>'200@s.whatsapp.net','proto'=>['extendedTextMessage'=>['text'=>'Ordinary reply','contextInfo'=>['remoteJid'=>'status@broadcast','stanzaId'=>'POST','participant'=>'100@s.whatsapp.net']]]]);
        $this->history->recordEvent(['id'=>'REACTION','chat'=>'200@s.whatsapp.net','from'=>'200@s.whatsapp.net','proto'=>['reactionMessage'=>['key'=>$key,'text'=>'❤']]]);
        self::assertCount(6,$this->features()->listStatusInteractions(['messageId'=>'POST']));
    }
    public function testBotReminderStateRejectsOrdinarySenderAndOlderUpdates():void
    {
        $reminder=['requestMessageKey'=>['id'=>'PROMPT'],'action'=>2,'name'=>'Meeting','nextTriggerTimestamp'=>1800000000,'frequency'=>3];
        $event=['id'=>'CREATE','chat'=>'867051314767696@bot','from'=>'200@s.whatsapp.net','timestamp'=>10,'proto'=>['conversation'=>'Reminder','messageContextInfo'=>['botMetadata'=>['reminderMetadata'=>$reminder]]]];
        $this->history->recordEvent($event);self::assertSame([],$this->features()->listBotReminders());$event['from']='867051314767696@bot';$this->history->recordEvent($event);$row=$this->features()->listBotReminders()[0];self::assertSame('weekly',$row['frequency']);self::assertSame('active',$row['state']);
        $event['id']='DELETE';$event['timestamp']=11;$event['proto']['messageContextInfo']['botMetadata']['reminderMetadata']['action']=3;$this->history->recordEvent($event);self::assertSame('deleted',$this->features()->getBotReminder($row['id'])['state']);
        $event['id']='STALE';$event['timestamp']=9;$event['proto']['messageContextInfo']['botMetadata']['reminderMetadata']['action']=4;$this->history->recordEvent($event);self::assertSame('deleted',$this->features()->getBotReminder($row['id'])['state']);
    }
    public function testRichMediaMapsAndCitationsHaveBoundedSafePublicProjections():void
    {
        $rich=['richResponseMessage'=>['submessages'=>[
            ['messageType'=>1,'gridImageMetadata'=>['imageUrls'=>[['imageHighResUrl'=>'https://example.com/image.jpg','sourceUrl'=>'javascript:alert(1)']]]],
            ['messageType'=>3,'imageMetadata'=>['imageText'=>'Picture','tapLinkUrl'=>'https://user:pass@example.com/private','imageUrl'=>['imagePreviewUrl'=>'https://example.com/a.jpg']]],
            ['messageType'=>7,'mapMetadata'=>['centerLatitude'=>-5.1,'centerLongitude'=>-35.2,'annotations'=>[['title'=>'Natal','latitude'=>-5.1,'longitude'=>-35.2]]]],
            ['messageType'=>9,'contentItemsMetadata'=>['itemsMetadata'=>[['reelItem'=>['title'=>'Demo','videoUrl'=>'https://example.com/video.mp4']]]]],
        ]],'messageContextInfo'=>['botMetadata'=>['richResponseSourcesMetadata'=>['sources'=>[['sourceTitle'=>'Source','sourceProviderUrl'=>'https://example.com/article','citationNumber'=>1,'thumbnailCdnUrl'=>'https://example.com/img?token=SECRET']]]]]];
        $result=BotResponseContent::decode($rich);self::assertSame(['image-grid','image','map','content-items'],array_column($result['richResponse']['blocks'],'type'));self::assertSame(-5.1,$result['richResponse']['blocks'][2]['centerLatitude']);self::assertNull($result['richResponse']['blocks'][1]['url']);self::assertStringNotContainsString('javascript',json_encode($result));$sources=ModernEvents::normalize($rich)['botSources'];self::assertSame(1,$sources[0]['citationNumber']);self::assertArrayNotHasKey('thumbnailCdnUrl',$sources[0]);
        self::assertSame($sources,$result['richResponse']['sources']);
        self::assertNull(ProtocolValues::url('https://127.0.0.1/private'));self::assertNull(ProtocolValues::url('https://example.com/a?access_token=secret'));self::assertSame('https://example.com/a',ProtocolValues::url('https://example.com/a#fragment'));
    }
    public function testObservedStateAndFailedSyncSurviveReopeningTheSessionStore():void
    {
        $path=tempnam(sys_get_temp_dir(),'mvo-history-ext-');$store=new SignalStore($path);
        try{$history=new HistoryStore($store);$history->observed()->callLog(['callId'=>'RESTORED','callResult'=>4]);$history->syncs()->put(str_repeat('f',64),['id'=>str_repeat('f',64),'state'=>'failed','attempt'=>3,'error'=>'history_transfer_failed']);$store->close();$store=new SignalStore($path);$history=new HistoryStore($store);
            self::assertSame('RESTORED',$history->observed()->list('calls')[0]['callId']);self::assertSame(3,$history->syncs()->get(str_repeat('f',64))['attempt']);
        }finally{$store->close();foreach([$path,$path.'-wal',$path.'-shm']as$file)if(is_file($file))unlink($file);}
    }
    public function testPrimaryCannotReservePeerRecoveryAndInvalidFullParametersNeverSend():void
    {
        $id=$this->notify(['syncType'=>3,'chunkOrder'=>0,'initialHistBootstrapInlinePayload'=>'bad']);$this->service()->drainPending();
        try{$this->features(credentials:['role'=>'primary'])->retryChunk($id);self::fail();}catch(\LogicException){}self::assertArrayNotHasKey('recoveryRequests',$this->features()->getSync($id));
        foreach([['syncId'=>$id,'historyDurationDays'=>366],['syncId'=>$id,'historyDurationDays'=>1,'providerId'=>123]]as$input){try{$this->features()->requestFullHistory($input);self::fail();}catch(\InvalidArgumentException){}}self::assertSame([],$this->sent);
    }
    public function testRichProjectionCutsAnOversizedLegacyBlockInsteadOfReturningUnboundedJson():void
    {
        $rich=['richResponseMessage'=>['submessages'=>[['messageType'=>2,'messageText'=>str_repeat('x',1048576)],['messageType'=>2,'messageText'=>str_repeat('y',1048576)]]]];$result=BotResponseContent::decode($rich);self::assertTrue($result['richResponse']['truncated']);self::assertLessThanOrEqual(2097152,strlen(json_encode($result['richResponse']['blocks'])));
    }
    public function testReceiptFailureDoesNotTurnAnImportedChunkIntoARecoverableFailure():void
    {
        $receipts=0;$service=new HistoryService($this->store,new MediaTransfer(static fn():BinaryNode=>throw new \LogicException('No network')),static fn():array=>['meJid'=>'100:5@s.whatsapp.net'],static function():void{},function()use(&$receipts):void{if(++$receipts===1)throw new \RuntimeException('Private receipt error');});
        $id=$this->notify(['syncType'=>2,'initialHistBootstrapInlinePayload'=>gzcompress(SchemaCodec::encode('HistorySync',['syncType'=>'FULL','conversations'=>[['id'=>'200@s.whatsapp.net','messages'=>[['message'=>['key'=>['id'=>'KEPT'],'message'=>['conversation'=>'Kept']]]]]]]))]);$service->drainPending();
        $row=$this->features()->getSync($id);self::assertSame('imported',$row['state']);self::assertTrue($row['receiptPending']);self::assertSame('history_receipt_pending',$row['error']);self::assertSame('Kept',$this->history->getMessage('200@s.whatsapp.net','KEPT')['text']);
        try{$this->features()->retryChunk($id);self::fail('Imported chunk must not regenerate');}catch(\LogicException){}self::assertSame([],$this->sent);
        $pending=BinaryData::decode($this->store->get('history-pending',$id));$pending['nextAttempt']=0;$this->store->set('history-pending',$id,BinaryData::encode($pending));$service->drainPending();
        self::assertSame(2,$receipts);self::assertSame('imported',$this->features()->getSync($id)['state']);self::assertFalse($this->features()->getSync($id)['receiptPending']);self::assertNull($this->store->get('history-pending',$id));self::assertCount(1,$this->history->listMessages('200@s.whatsapp.net'));
    }
}
