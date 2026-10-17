<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\History\{HistoryStore,StatusStore};
use MVO\Message\{ContentDecoder,MessageCodec};
use MVO\Protocol\SchemaCodec;
use MVO\Service\StatusService;
use MVO\Signal\SignalStore;
use PHPUnit\Framework\TestCase;

final class InteractiveStatusTest extends TestCase
{
    private string $path;
    private SignalStore $signal;
    private HistoryStore $history;
    private StatusStore $statuses;
    private const NOW=1900000000;
    protected function setUp():void{$this->path=tempnam(sys_get_temp_dir(),'mvo-interactive-status-');$this->open();}
    private function open():void{$this->signal=new SignalStore($this->path);$this->history=new HistoryStore($this->signal);$this->statuses=new StatusStore($this->signal,$this->history,static fn():int=>self::NOW);}
    protected function tearDown():void{$this->signal->close();foreach([$this->path,$this->path.'-wal',$this->path.'-shm']as$file)if(is_file($file))unlink($file);}
    private function record(string $id,string $chat,array $proto,int $age=0,string $author='200@lid'):void
    {$decoded=ContentDecoder::decode(SchemaCodec::encode('Message',$proto));$this->history->recordEvent(['id'=>$id,'chat'=>$chat,'participant'=>$author,'from'=>$author,'fromMe'=>false,'timestamp'=>self::NOW-$age,'proto'=>$proto,'type'=>$decoded['kind'],'text'=>$decoded['text'],'key'=>['id'=>$id,'remoteJid'=>$chat,'fromMe'=>false,'participant'=>$author]]);}
    private static function poll():array{return ['pollCreationMessageV4'=>['message'=>['pollCreationMessage'=>['name'=>'Qual opção?','options'=>[['optionName'=>'A'],['optionName'=>'B']],'selectableOptionsCount'=>1]]]];}

    public function testWrappedBroadcastStatusesSurviveRestartWithExactLifetimeAndAreNotHiddenByOrdinaryRows():void
    {
        $this->record('poll',StatusStore::CHAT,self::poll(),10);
        $this->record('prompt',StatusStore::CHAT,['statusAddYours'=>['message'=>['extendedTextMessage'=>['text'=>'Compartilhe sua foto']]]],20);
        $this->record('expired',StatusStore::CHAT,self::poll(),86400);
        for($index=0;$index<5;++$index)$this->record('ordinary'.$index,StatusStore::CHAT,['conversation'=>'ordinary']);
        self::assertSame(['poll'],array_column($this->statuses->listInteractiveStatuses(['limit'=>1]),'id'));
        self::assertSame(['poll','prompt'],array_column($this->statuses->listInteractiveStatuses(),'id'));
        self::assertSame(['expired'],array_column($this->statuses->listInteractiveStatuses(['type'=>'poll','beforeTimestamp'=>self::NOW-20,'includeExpired'=>true]),'id'));
        $this->signal->close();$this->open();$row=$this->statuses->listInteractiveStatuses(['type'=>'poll'])[0];
        self::assertSame(['type'=>'poll','wrapper'=>'pollCreationMessageV4','action'=>'publication'],$row['statusInteraction']);self::assertFalse($row['publicationSupported']);
        self::assertSame(StatusStore::CHAT,$row['key']['remoteJid']);self::assertSame('200@lid',$row['key']['participant']);self::assertSame(self::NOW-10+86400,$row['expiresAt']);
        $this->statuses->markRevoked('poll','200@lid');self::assertSame([],$this->statuses->listInteractiveStatuses(['type'=>'poll']));self::assertCount(1,$this->statuses->listInteractiveStatuses(['type'=>'poll','includeRevoked'=>true]));
    }
    public function testGroupStatusQueryPreservesGroupIdentityAndDoesNotInventBroadcastExpiryOrViews():void
    {
        $this->record('group','123@g.us',['groupStatusMessageV2'=>['message'=>['extendedTextMessage'=>['text'=>'Aviso do grupo']]]],100000);
        $this->record('other-group','456@g.us',['groupStatusMessageV2'=>['message'=>['conversation'=>'Other']]]);
        $this->record('ordinary','123@g.us',['conversation'=>'ordinary']);
        self::assertSame([],$this->statuses->listInteractiveStatuses());
        $rows=$this->statuses->listInteractiveStatuses(['type'=>'group-status','groupJid'=>'123@g.us']);self::assertCount(1,$rows);$row=$rows[0];
        self::assertSame('123@g.us',$row['chat']);self::assertSame('Aviso do grupo',$row['text']);self::assertSame('200@lid',$row['author']);self::assertSame('group-status',$row['statusInteraction']['type']);
        self::assertSame(['id'=>'group','remoteJid'=>'123@g.us','fromMe'=>false,'participant'=>'200@lid'],$row['key']);
        foreach(['expiresAt','isExpired','viewed','viewedAt','muted','audience']as$field)self::assertArrayNotHasKey($field,$row);
        self::assertSame([],$this->statuses->listInteractiveStatuses(['groupJid'=>'123@g.us','author'=>'300@lid']));
    }
    public function testRealAddYoursResponseEnvelopePreservesBothKeysWithoutReclassifyingItAsPublication():void
    {
        $original=['remoteJid'=>StatusStore::CHAT,'id'=>'ORIGINAL','participant'=>'200@lid','fromMe'=>false];$response=['remoteJid'=>StatusStore::CHAT,'id'=>'RESPONSE','participant'=>'300@lid','fromMe'=>true];
        $proto=['associatedChildMessage'=>['message'=>['statusNotificationMessage'=>['type'=>'STATUS_ADD_YOURS','originalMessageKey'=>$original,'responseMessageKey'=>$response]]],'messageContextInfo'=>['messageSecret'=>str_repeat('S',32),'messageAssociation'=>['associationType'=>'STATUS_NOTIFICATION','parentMessageKey'=>$response]]];
        $decoded=ContentDecoder::decode(SchemaCodec::encode('Message',$proto));$normal=$decoded['statusInteraction'];
        self::assertSame('add-yours',$normal['type']);self::assertSame('response',$normal['action']);self::assertSame('associatedChildMessage',$normal['wrapper']);self::assertEquals($original,$normal['originalMessageKey']);self::assertEquals($response,$normal['responseMessageKey']);self::assertArrayNotHasKey('messageSecret',$normal);
        $this->record('notification',StatusStore::CHAT,$proto);self::assertSame([],$this->statuses->listInteractiveStatuses());
        self::assertSame('status-notification',$decoded['kind']);self::assertSame(32,strlen($decoded['messageContextInfo']['messageSecret']));
    }
    public function testGroupLookupRejectsAmbiguousReceivedIdsAndCanSelectAnExplicitAuthor():void
    {
        $proto=['groupStatusMessageV2'=>['message'=>['extendedTextMessage'=>['text'=>'Status']]]];$this->record('COLLISION','123@g.us',$proto,0,'200@lid');$this->record('COLLISION','123@g.us',$proto,0,'300@lid');
        try{$this->statuses->getGroupStatus('123@g.us','COLLISION');self::fail('Ambiguous authors accepted');}catch(\InvalidArgumentException $error){self::assertStringContainsString('author',$error->getMessage());}
        self::assertSame('300@lid',$this->statuses->getGroupStatus('123@g.us','COLLISION','300@lid')['author']);self::assertNull($this->statuses->getGroupStatus('456@g.us','COLLISION'));self::assertNull($this->statuses->getGroupStatus('123@g.us','COLLISION','400@lid'));
    }
    public function testCapabilitiesDoNotAdvertiseUnprovenPublicationAndQueriesNeverSend():void
    {
        $calls=0;$service=new StatusService(static function()use(&$calls):never{++$calls;throw new \RuntimeException('Must not access network');},static function()use(&$calls):array{++$calls;return [];},fn():StatusStore=>$this->statuses);
        foreach(StatusService::interactiveCapabilities()['features']as$type=>$feature){self::assertTrue($feature['receive']);self::assertTrue($feature['localQuery']);self::assertSame($type==='group-status',$feature['publish']);}
        self::assertSame([],$service->listInteractiveStatuses());self::assertSame(0,$calls);
        try{$service->send(['type'=>'raw','message'=>self::poll()],['200@lid']);self::fail('Published an unverified status wrapper');}catch(\InvalidArgumentException){self::assertSame(0,$calls);}
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFilters')]
    public function testInvalidFiltersFailEvenWhenHistoryIsEmpty(array $filters):void{$this->expectException(\InvalidArgumentException::class);$this->statuses->listInteractiveStatuses($filters);}
    public static function invalidFilters():array{return [[['type'=>'unknown']],[['type'=>'group-status']],[['type'=>'poll','groupJid'=>'123@g.us']],[['groupJid'=>'200@lid']],[['groupJid'=>'123:5@g.us']],[['limit'=>0]],[['beforeTimestamp'=>-1]],[['includeExpired'=>'true']],[['author'=>'status@broadcast']],[['unknown'=>true]]];}
}
