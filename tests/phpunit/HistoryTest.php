<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\History\{HistoryService,HistoryStore};
use MVO\Media\{MediaTransfer,MediaCrypto};
use MVO\Protocol\{BinaryData,BinaryNode,SchemaCodec};
use MVO\Signal\SignalStore;

final class HistoryTest extends TestCase
{
    private string $path;
    private SignalStore $signal;
    protected function setUp():void { $this->path=tempnam(sys_get_temp_dir(),'mvo-history-'); $this->signal=new SignalStore($this->path); }
    protected function tearDown():void { $this->signal->close(); foreach ([$this->path,$this->path.'-wal',$this->path.'-shm'] as $file) if(is_file($file)) unlink($file); }
    private function service(?MediaTransfer $media=null,?\Closure $emit=null,?\Closure $receipt=null,int $max=67108864):HistoryService
    {
        return new HistoryService($this->signal,$media ?? new MediaTransfer(static fn():BinaryNode=>throw new \LogicException('No query')),static fn():array=>['meJid'=>'100:5@s.whatsapp.net'],
            $emit ?? static function():void{},$receipt ?? static function():void{},$max);
    }
    private static function chunk():string
    {
        return SchemaCodec::encode('HistorySync',['syncType'=>'RECENT','chunkOrder'=>2,'progress'=>75,
            'conversations'=>[['id'=>'200@s.whatsapp.net','name'=>'Chat','unreadCount'=>3,'archived'=>true,
                'messages'=>[['message'=>['key'=>['id'=>'old','fromMe'=>false],'messageTimestamp'=>100,'message'=>['conversation'=>'old text']]],
                    ['message'=>['key'=>['id'=>'own','fromMe'=>true],'messageTimestamp'=>200,'message'=>['imageMessage'=>['jpegThumbnail'=>"\0\xff"]]]]]]],
            'pushnames'=>[['id'=>'200@s.whatsapp.net','pushname'=>'Maria']],
            'phoneNumberToLidMappings'=>[['pnJid'=>'200@s.whatsapp.net','lidJid'=>'900@lid']],
            'inlineContacts'=>[['pnJid'=>'200@s.whatsapp.net','lidJid'=>'900@lid','fullName'=>'Maria Silva','username'=>'maria']],
            'nctSalt'=>"\xff\0salt"]);
    }
    public function testInventoryIteratorsPreserveMoreThanOnePageOfEqualTimestampMessages():void
    {
        $history=new HistoryStore($this->signal);
        $this->signal->transaction(function() use($history):void {
            for ($index=0;$index<1050;$index++) $history->upsertMessage(['chat'=>'200@s.whatsapp.net','id'=>'same-time-'.$index,'timestamp'=>1700000000,'text'=>'Payload '.$index]);
            $history->upsertMessage(['chat'=>'300@s.whatsapp.net','id'=>'other-chat','timestamp'=>1700000000,'text'=>'Other']);
            for ($index=0;$index<1005;$index++) $history->upsertContact(['jid'=>(string)(10000+$index).'@s.whatsapp.net','displayName'=>'Contact '.$index]);
        });
        $ids=[];
        foreach ($history->iterateMessages('200@s.whatsapp.net') as $message) {
            self::assertSame(1700000000,$message['timestamp']);self::assertSame('200@s.whatsapp.net',$message['chat']);$ids[]=$message['id'];
        }
        self::assertCount(1050,$ids);self::assertCount(1050,array_unique($ids));
        self::assertContains('same-time-0',$ids);self::assertContains('same-time-1049',$ids);
        self::assertCount(1051,iterator_to_array($history->iterateMessages(),false));
        self::assertSame(['200@s.whatsapp.net','300@s.whatsapp.net'],array_column(iterator_to_array($history->iterateChats(),false),'jid'));
        self::assertCount(1005,iterator_to_array($history->iterateContacts(),false));
    }
    public function testZlibAndGzipHistoryPersistsMessagesChatsContactsMappingsAndBinary():void
    {
        foreach ([gzcompress(self::chunk()),gzencode(self::chunk())] as $compressed) {
            $service=$this->service(); $result=$service->ingestCompressed($compressed);
            self::assertSame(2,$result['messagesCount']); self::assertSame(1,$result['conversationsCount']); self::assertSame(75,$result['progress']);
            self::assertTrue($service->store->getChat('200@s.whatsapp.net')['archived']);
            $messages=$service->store->listMessages('200@s.whatsapp.net');
            self::assertSame(['own','old'],array_column($messages,'id'));
            self::assertSame("\0\xff",$messages[0]['proto']['imageMessage']['jpegThumbnail']);
            self::assertSame('100@s.whatsapp.net',$messages[0]['from']);
            self::assertSame('Maria',$service->store->getContact('900@lid')['pushName']);
            self::assertSame('Maria Silva',$service->store->getContact('900@lid')['displayName']);
            self::assertSame('900@lid',$this->signal->get('message-pn-to-lid','200@s.whatsapp.net'));
            self::assertSame("\xff\0salt",$this->signal->get('history-meta','nctSalt'));
        }
        $this->signal->close(); $this->signal=new SignalStore($this->path);
        self::assertSame('old text',(new HistoryStore($this->signal))->getMessage('200@s.whatsapp.net','old')['text']);
    }
    public function testConversationFieldsMayArriveBeforeItsIdAndGroupAuthorUsesOwnIdentity():void
    {
        $own=['message'=>['key'=>['id'=>'self','fromMe'=>true],'message'=>['conversation'=>'Mine']]];
        $other=['message'=>['key'=>['id'=>'same','fromMe'=>false,'participant'=>'201:3@s.whatsapp.net'],'message'=>['conversation'=>'Other']]];
        $conversation=\MVO\Message\MessageCodec::bytes(2,SchemaCodec::encode('HistorySyncMsg',$own)).
            \MVO\Message\MessageCodec::bytes(2,SchemaCodec::encode('HistorySyncMsg',$other)).
            \MVO\Message\MessageCodec::bytes(1,'100-200@g.us');
        $service=$this->service(); $service->ingest(\MVO\Message\MessageCodec::bytes(2,$conversation));
        self::assertSame('100@s.whatsapp.net',$service->store->getMessage('100-200@g.us','self')['participant']);
        self::assertSame('201@s.whatsapp.net',$service->store->getMessage('100-200@g.us','same')['participant']);
    }
    public function testMalformedTailRollsBackAllImportedRecords():void
    {
        try { $this->service()->ingest(self::chunk()."\x12\xff"); self::fail('Expected malformed field'); }
        catch (\UnexpectedValueException) {}
        self::assertSame([], $this->service()->store->listChats());
        self::assertNull($this->signal->get('message-pn-to-lid','200@s.whatsapp.net'));
    }
    public function testInflationLimitAndTruncatedStreamAreRejected():void
    {
        try { $this->service(max:100)->ingestCompressed(gzcompress(str_repeat('a',10000))); self::fail('Expected expansion bound'); }
        catch (\LengthException) {}
        try { $this->service()->ingestCompressed(substr(gzcompress(self::chunk()),0,-2)); self::fail('Expected truncation'); }
        catch (\UnexpectedValueException) {}
        self::assertSame([], $this->service()->store->listChats());
    }
    public function testNotificationsAreDeferredAndUntrustedAccountCannotImportHistory():void
    {
        $download=0; $receipts=[]; $events=[]; $compressed=gzcompress(self::chunk()); $encrypted=MediaCrypto::encrypt($compressed,'history');
        $media=new MediaTransfer(static fn():BinaryNode=>throw new \LogicException('No IQ needed'),
            function() use(&$download,$encrypted):array { ++$download; return ['status'=>200,'body'=>$encrypted['body']]; });
        $service=$this->service($media,function(string $event,array $payload)use(&$events):void{$events[]=[$event,$payload];},
            function(array $key,string $type)use(&$receipts):void{$receipts[]=[$key,$type];});
        $notification=['directPath'=>'/history','mediaKey'=>$encrypted['mediaKey'],'fileSha256'=>$encrypted['fileSha256'],
            'fileEncSha256'=>$encrypted['fileEncSha256'],'fileLength'=>$encrypted['fileLength'],'syncType'=>3];
        $event=['id'=>'notification','from'=>'100@s.whatsapp.net','fromMe'=>false,'proto'=>['protocolMessage'=>['historySyncNotification'=>$notification]]];
        $service->handleProtocol($event); $service->drainPending(); self::assertSame(0,$download);
        $event['fromMe']=true; $service->handleProtocol($event); self::assertSame(0,$download); self::assertSame([],$receipts);
        $service->drainPending(); self::assertSame(1,$download); self::assertSame('hist_sync',$receipts[0][1]); self::assertSame('history_sync_chunk',$events[0][0]);
        $service->handleProtocol($event); $service->drainPending(); self::assertSame(1,$download); self::assertCount(2,$receipts); self::assertCount(1,$events);
    }
    public function testFailedHistoryAuthenticationLeavesQueueAndDoesNotAcknowledgeChunk():void
    {
        $encrypted=MediaCrypto::encrypt(gzcompress(self::chunk()),'history'); $receipts=[]; $events=[];
        $media=new MediaTransfer(static fn():BinaryNode=>throw new \LogicException(),static fn():array=>['status'=>200,'body'=>$encrypted['body'].'tamper']);
        $service=$this->service($media,function(string $event)use(&$events):void{$events[]=$event;},function()use(&$receipts):void{$receipts[]=1;});
        $service->handleProtocol(['id'=>'bad','from'=>'100@s.whatsapp.net','fromMe'=>true,'proto'=>['protocolMessage'=>['historySyncNotification'=>[
            'syncType'=>3,'directPath'=>'/bad','mediaKey'=>$encrypted['mediaKey'],'fileSha256'=>$encrypted['fileSha256'],'fileEncSha256'=>$encrypted['fileEncSha256']]]]]);
        $service->drainPending(); self::assertSame([],$receipts); self::assertSame(['history_sync_error'],$events);
        self::assertSame([],$service->store->listChats()); self::assertCount(1,$this->signal->entries('history-pending'));
    }
    public function testHistoryMessageSecretIsStoredForEncryptedResponses():void
    {
        $secret=random_bytes(32);
        $chunk=SchemaCodec::encode('HistorySync',['conversations'=>[['id'=>'200@s.whatsapp.net','messages'=>[['message'=>[
            'key'=>['id'=>'poll','fromMe'=>false],'message'=>['pollCreationMessage'=>['name'=>'Quando?'],'messageContextInfo'=>['messageSecret'=>$secret]]]]]]]]);
        $this->service()->ingest($chunk);
        $record=BinaryData::decode($this->signal->get('message-secrets',hash('sha256',"200@s.whatsapp.net\0poll")));
        self::assertSame($secret,$record['secret']); self::assertSame('200@s.whatsapp.net',$record['author']);
    }
    public function testStorePaginationEditsLocalDeletionAndGroupMessageIdCollision():void
    {
        $store=new HistoryStore($this->signal);
        foreach (['100@s.whatsapp.net','200@s.whatsapp.net','300@s.whatsapp.net'] as $jid) $store->upsertContact(['jid'=>$jid]);
        self::assertSame(['200@s.whatsapp.net','300@s.whatsapp.net'],array_column($store->listContacts(2,'100@s.whatsapp.net'),'jid'));
        foreach (['100@s.whatsapp.net','200@s.whatsapp.net'] as $participant) $store->upsertMessage(['id'=>'same','chat'=>'100-200@g.us','participant'=>$participant,'timestamp'=>100]);
        self::assertCount(2,$store->listMessages('100-200@g.us'));
        $store->updateMessage('100-200@g.us','same',['starred'=>true],'100@s.whatsapp.net');
        self::assertTrue($store->getMessage('100-200@g.us','same','100@s.whatsapp.net')['starred']);
        $store->deleteMessage('100-200@g.us','same','100@s.whatsapp.net'); self::assertCount(1,$store->listMessages('100-200@g.us'));
        $store->deleteChat('100-200@g.us'); self::assertSame([],$store->listMessages('100-200@g.us')); self::assertNull($store->getChat('100-200@g.us'));
    }

    public function testGroupHistoryDropsExpiredForeignAndOldMessagesButRetainsOldPinnedMessage():void
    {
        $group='100-200@g.us'; $now=1800000000;
        $make=static fn(string $id,int $timestamp):array=>['key'=>['id'=>$id,'remoteJid'=>$group,'participant'=>'200@s.whatsapp.net'],'messageTimestamp'=>$timestamp,'message'=>['conversation'=>$id]];
        $foreign=$make('foreign',$now); $foreign['key']['remoteJid']='300-400@g.us';
        $expired=$make('expired',$now-100); $expired['ephemeralDuration']=60;
        $payload=SchemaCodec::encode('GroupHistory',['messages'=>[$make('recent',$now-1),$make('old',$now-3000000),$foreign,$expired],
            'outOfWindowPinnedMessages'=>[$make('pin',$now-3000000)]]);
        $service=$this->service(); $result=$service->ingestGroupCompressed(gzcompress($payload),$group,$now);
        self::assertSame(2,$result['messagesCount']); self::assertSame(3,$result['droppedCount']); self::assertSame(1,$result['outOfWindowPinsCount']);
        self::assertSame(['recent','pin'],array_column($service->store->listMessages($group),'id'));
    }

    public function testGroupHistoryDownloadIsRestrictedToNamedAccountAndDeferred():void
    {
        $group='100-200@g.us'; $payload=SchemaCodec::encode('GroupHistory',['messages'=>[['key'=>['id'=>'shared','remoteJid'=>$group,'participant'=>'200@s.whatsapp.net'],
            'messageTimestamp'=>time(),'message'=>['conversation'=>'Compartilhada']]]]);
        $encrypted=MediaCrypto::encrypt(gzcompress($payload),'group-history'); $downloads=0; $events=[];
        $media=new MediaTransfer(static fn():BinaryNode=>throw new \LogicException('No IQ'),function()use($encrypted,&$downloads):array{++$downloads;return ['status'=>200,'body'=>$encrypted['body']];});
        $service=$this->service($media,function(string $event,array $payload)use(&$events):void{$events[]=[$event,$payload];});
        $bundle=['directPath'=>'/group','mediaKey'=>$encrypted['mediaKey'],'fileSha256'=>$encrypted['fileSha256'],'fileEncSha256'=>$encrypted['fileEncSha256'],
            'messageHistoryMetadata'=>['historyReceivers'=>['999@s.whatsapp.net']]];
        $event=['id'=>'bundle','chat'=>$group,'from'=>'200@s.whatsapp.net','participant'=>'200@s.whatsapp.net','timestamp'=>time(),'proto'=>['messageHistoryBundle'=>$bundle]];
        $service->handleMessage($event); $service->drainPending(); self::assertSame(0,$downloads);
        $event['proto']['messageHistoryBundle']['messageHistoryMetadata']['historyReceivers']=['100@s.whatsapp.net'];
        $service->handleMessage($event); self::assertSame(0,$downloads); $service->drainPending();
        self::assertSame(1,$downloads); self::assertSame('group_history_bundle',$events[0][0]); self::assertSame('Compartilhada',$service->store->getMessage($group,'shared')['text']);
        $service->handleMessage($event); $service->drainPending(); self::assertSame(1,$downloads);
    }
}
