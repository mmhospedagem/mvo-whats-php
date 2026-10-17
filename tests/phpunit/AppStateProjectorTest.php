<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\AppState\{AppStateProjector,AppStateSchema,AppStateService,AppStateCrypto};
use MVO\History\HistoryStore;
use MVO\Signal\SignalStore;

final class AppStateProjectorTest extends TestCase
{
    private SignalStore $store;
    private HistoryStore $history;
    private AppStateProjector $projector;
    protected function setUp():void { $this->store=new SignalStore(':memory:');$this->history=new HistoryStore($this->store);$this->projector=new AppStateProjector($this->store,$this->history); }
    protected function tearDown():void { $this->store->close(); }
    private function apply(string $schema,array $args,array $data,int $timestamp=10):array { return $this->projector->apply(AppStateSchema::mutation($schema,$args,$data,$timestamp)); }
    public function testChatContactLabelsAndAccountSettingsAreQueryable():void
    {
        $jid='123@s.whatsapp.net';
        $this->apply('Archive',['chatJid'=>$jid],['archived'=>true]);
        $this->apply('Mute',['chatJid'=>$jid],['muted'=>true,'muteEndTimestamp'=>123456]);
        $this->apply('Pin',['chatJid'=>$jid],['pinned'=>false]);
        $this->apply('MarkChatAsRead',['chatJid'=>$jid],['read'=>true]);
        $this->apply('Contact',['id'=>$jid],['fullName'=>'Cliente','lidJid'=>'456@lid']);
        $this->apply('LabelEdit',['id'=>'12'],['name'=>'Clientes','color'=>3]);
        $this->apply('LabelJid',['chatJid'=>$jid,'labelId'=>'12'],['labeled'=>true]);
        $this->apply('SettingPushName',[],['name'=>'Minha conta']);
        $chat=$this->history->getChat($jid);
        self::assertTrue($chat['archived']);self::assertTrue($chat['muted']);self::assertSame(123456,$chat['muteEndTimestamp']);self::assertFalse($chat['pinned']);self::assertSame(0,$chat['unreadCount']);self::assertSame(['12'],$chat['labelIds']);
        self::assertSame('Cliente',$this->history->getContact('456@lid')['displayName']);self::assertSame($jid,$this->history->getContact('456@lid')['phoneNumber']);
        self::assertSame('Clientes',$this->projector->listLabels()[0]['name']);self::assertSame('Minha conta',$this->projector->getSetting('SettingPushName')['name']);
        self::assertSame('Cliente',$this->projector->getAction('Contact',['id'=>$jid])['fullName']);
    }
    public function testMessageRangeRetainsSecondsAndIncomingGroupParticipant():void
    {
        $chat='123@g.us';$this->history->upsertMessage(['chat'=>$chat,'id'=>'A','fromMe'=>false,'participant'=>'456:2@lid','timestamp'=>123]);
        $this->history->upsertMessage(['chat'=>$chat,'id'=>'B','fromMe'=>true,'timestamp'=>124]);
        $this->history->upsertMessage(['chat'=>$chat,'id'=>'INVALID','fromMe'=>false,'timestamp'=>125]);
        $range=$this->projector->messageRange($chat);
        self::assertSame(124,$range['lastMessageTimestamp']);self::assertCount(2,$range['messages']);self::assertSame(124,$range['messages'][0]['timestamp']);
        self::assertSame('456@lid',$range['messages'][1]['key']['participant']);
    }
    public function testClearRangePreservesStarredAndNewerMessagesWhileDeleteChatRemovesStars():void
    {
        $chat='123@s.whatsapp.net';
        foreach([['A',100,true],['B',100,false],['C',200,false]] as [$id,$timestamp,$starred])$this->history->upsertMessage(['chat'=>$chat,'id'=>$id,'timestamp'=>$timestamp,'fromMe'=>false,'starred'=>$starred]);
        $this->apply('ClearChat',['chatJid'=>$chat,'deleteStarred'=>'0','deleteMedia'=>'0'],['messageRange'=>['lastMessageTimestamp'=>100]]);
        self::assertNotNull($this->history->getMessage($chat,'A'));self::assertNull($this->history->getMessage($chat,'B'));self::assertNotNull($this->history->getMessage($chat,'C'));
        $this->apply('DeleteChat',['chatJid'=>$chat,'deleteMedia'=>'0'],['messageRange'=>['lastMessageTimestamp'=>200]]);
        self::assertSame([],$this->history->listMessages($chat));self::assertNull($this->history->getChat($chat));
    }
    public function testStarAndDeleteForMeCannotAffectOppositeSenderWithSameId():void
    {
        $chat='123@s.whatsapp.net';$this->history->upsertMessage(['chat'=>$chat,'id'=>'ID','timestamp'=>100,'fromMe'=>false]);
        $this->apply('Star',['remote'=>$chat,'id'=>'ID','fromMe'=>true,'participant'=>'0'],['starred'=>true]);
        self::assertArrayNotHasKey('starred',$this->history->getMessage($chat,'ID'));
        $this->apply('Star',['remote'=>$chat,'id'=>'ID','fromMe'=>false,'participant'=>'0'],['starred'=>true]);self::assertTrue($this->history->getMessage($chat,'ID')['starred']);
        $this->apply('DeleteMessageForMe',['remote'=>$chat,'id'=>'ID','fromMe'=>true,'participant'=>'0'],['deleteMedia'=>false]);self::assertNotNull($this->history->getMessage($chat,'ID'));
        $this->apply('DeleteMessageForMe',['remote'=>$chat,'id'=>'ID','fromMe'=>false,'participant'=>'0'],['deleteMedia'=>false]);self::assertNull($this->history->getMessage($chat,'ID'));
    }
    public function testDeletingLabelRemovesChatAssociationsAndSnapshotReconcilesMissingRecords():void
    {
        $this->apply('LabelEdit',['id'=>'12'],['name'=>'Clientes']);$this->apply('LabelJid',['chatJid'=>'123','labelId'=>'12'],['labeled'=>true]);
        $this->projector->replaceSnapshot('regular',[]);
        self::assertNull($this->projector->getLabel('12'));self::assertSame([],$this->history->getChat('123@s.whatsapp.net')['labelIds']);self::assertNull($this->projector->getAction('LabelEdit',['id'=>'12']));
    }
    public function testEnumIndicesAndFull66SchemaRoundTripPreserveActionArguments():void
    {
        $input=AppStateSchema::mutation('SettingsSync',['settingPlatform'=>'WEB','settingKey'=>'LANGUAGE','chatJid'=>'123'],['language'=>'pt_BR'],12);
        self::assertSame('["settings_sync","1","3","123@s.whatsapp.net"]',$input['index']);
        $parsed=AppStateSchema::parse($input);self::assertSame('WEB',$parsed['settingPlatform']);self::assertSame('LANGUAGE',$parsed['settingKey']);self::assertSame('pt_BR',$parsed['language']);
        foreach(AppStateSchema::all() as $name=>$schema) {
            $args=[];
            foreach($schema['indexParts'] as $part) {
                if($part['type']==='literal')continue;
                $args[$part['name']]=match($part['type']){'boolString'=>true,'jid','jidOrZero'=>'123@s.whatsapp.net','enum'=>array_key_first($part['enumValues']),default=>'value'};
            }
            $mutation=AppStateSchema::mutation($name,$args,[],1);
            self::assertSame($name,AppStateSchema::parse($mutation)['schema']);
        }
    }
    public function testProjectionErrorRollsBackCryptographicCollectionAndHistoryTogether():void
    {
        $service=new AppStateService($this->store,static fn()=>throw new \LogicException());$service->setProjector($this->projector);
        $id='key';$key=str_repeat('k',32);$service->importKeys([['keyId'=>$id,'keyData'=>$key]]);
        $input=AppStateSchema::mutation('Contact',['id'=>'valid'],['fullName'=>'Nome'],1); // Contact's string index can be structurally valid but not a JID.
        $encrypted=AppStateCrypto::encryptMutation(0,$id,$key,$input['index'],$input['value'],$input['version']);
        $snapshot=['version'=>['version'=>1],'records'=>[['keyId'=>['id'=>$id],'index'=>['blob'=>$encrypted['indexMac']],'value'=>['blob'=>$encrypted['valueBlob']]]],'keyId'=>['id'=>$id],
            'mac'=>AppStateCrypto::snapshotMac($key,AppStateCrypto::ltHash(str_repeat("\0",128),[$encrypted['valueMac']]),1,'critical_unblock_low')];
        // Numeric user JIDs can be normalized by the projector; a malformed identifier must fail atomically.
        $input['index']='["contact","bad@jid@invalid"]';$encrypted=AppStateCrypto::encryptMutation(0,$id,$key,$input['index'],$input['value'],$input['version']);
        $snapshot['records'][0]['index']['blob']=$encrypted['indexMac'];$snapshot['records'][0]['value']['blob']=$encrypted['valueBlob'];
        $snapshot['mac']=AppStateCrypto::snapshotMac($key,AppStateCrypto::ltHash(str_repeat("\0",128),[$encrypted['valueMac']]),1,'critical_unblock_low');
        try{$service->applySnapshot('critical_unblock_low',$snapshot);self::fail();}catch(\InvalidArgumentException){}
        self::assertFalse($service->collectionState('critical_unblock_low')['initialized']);self::assertSame([],$this->history->listContacts());
    }
}
