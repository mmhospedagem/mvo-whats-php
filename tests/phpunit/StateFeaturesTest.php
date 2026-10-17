<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\AppState\{AppStateCrypto,AppStateProjector,AppStateSchema,AppStateService};
use MVO\History\{HistoryService,HistoryStore};
use MVO\Media\MediaTransfer;
use MVO\Protocol\{BinaryData,BinaryNode,SchemaCodec};
use MVO\Service\{BusinessToolsService,ModernStateService,StateFeatureInputs};
use MVO\Signal\SignalStore;
use PHPUnit\Framework\TestCase;

final class StateFeaturesTest extends TestCase
{
    private SignalStore $store;
    private SignalStore $peerStore;
    private AppStateProjector $projector;
    private HistoryStore $history;
    private BusinessToolsService $tools;
    private ModernStateService $features;
    private array $wire=[];
    private array $protocols=[];
    private array $historyRequests=[];
    private int $now=1800000000000;
    private bool $reject=false;
    private ?\Closure $beforeAck=null;

    protected function setUp():void
    {
        $this->store=new SignalStore(':memory:');$this->peerStore=new SignalStore(':memory:');$this->history=new HistoryStore($this->store);$this->projector=new AppStateProjector($this->store,$this->history);
        $peer=new AppStateService($this->peerStore,static fn()=>throw new \LogicException('No peer query'));
        $service=new AppStateService($this->store,function(BinaryNode $request)use($peer):BinaryNode{
            $collection=$request->child('sync')->child('collection');$name=$collection->attrs['name'];
            if($this->beforeAck!==null)($this->beforeAck)();
            if($this->reject)return new BinaryNode('iq',['type'=>'error'],[new BinaryNode('error',['code'=>'403'])]);
            if(($patchNode=$collection->child('patch'))!==null){$patch=SchemaCodec::decode('SyncdPatch',$patchNode->content);$patch['version']=['version'=>$peer->collectionState($name)['version']+1];foreach($peer->applyPatch($name,$patch)as$mutation)$this->wire[]=AppStateSchema::parse($mutation);}
            return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('sync',[],[new BinaryNode('collection',['name'=>$name,'version'=>(string)$peer->collectionState($name)['version']])])]);
        });
        $service->setProjector($this->projector);
        foreach([$service,$peer]as$appState){$appState->importKeys([['keyId'=>'KEY','keyData'=>str_repeat('k',32),'timestamp'=>1]]);foreach(['regular','regular_low','critical_unblock_low']as$collection)$appState->applySnapshot($collection,['version'=>['version'=>0],'records'=>[],'keyId'=>['id'=>'KEY'],'mac'=>AppStateCrypto::snapshotMac(str_repeat('k',32),str_repeat("\0",128),0,$collection)]);}
        $this->tools=new BusinessToolsService($service,$this->projector,$this->history);
        $this->features=$this->makeFeatures($this->store,$this->history,$this->projector);
    }
    protected function tearDown():void{$this->store->close();$this->peerStore->close();}
    private function makeFeatures(SignalStore $store,HistoryStore $history,AppStateProjector $projector):ModernStateService
    {
        return new ModernStateService($store,$history,$projector,static fn():array=>['meJid'=>'100:5@s.whatsapp.net','meLid'=>'900:5@lid'],function(string $to,array $protocol,array $options):array{$this->protocols[]=[$to,$protocol,$options];return ['id'=>$options['id'],'status'=>'server_ack'];},function(array $input):array{$this->historyRequests[]=$input;return ['id'=>'HISTORY','status'=>'server_ack'];},fn():int=>$this->now);
    }
    private function seed(string $schema,array $args,array $data,int $time=1):void{$this->projector->apply(AppStateSchema::mutation($schema,$args,$data,$time));}
    private function event(array $proto,string $sender='200:3@s.whatsapp.net',string $chat='200@s.whatsapp.net',string $id='INCOMING'):array
    {
        $own=str_starts_with($sender,'100:')||str_starts_with($sender,'900:');
        return ['id'=>$id,'chat'=>$chat,'authenticatedSender'=>$sender,'authenticatedOwnDevice'=>$own,'fromMe'=>$own,'timestamp'=>1800000000,'proto'=>$proto];
    }
    private function primaryCapability(bool $supported=true):void{$this->seed('DeviceCapabilitiesDevice',['deviceJid'=>'100:0@s.whatsapp.net'],['contactRefresh'=>['refreshSupported'=>$supported]],10);}
    private function response(string $id):array
    {
        return ['protocolMessage'=>['type'=>'PEER_DATA_OPERATION_REQUEST_RESPONSE_MESSAGE','peerDataOperationRequestResponseMessage'=>['peerDataOperationRequestType'=>'CONTACT_REFRESH_REQUEST','stanzaId'=>$id,'peerDataOperationResult'=>[['contactRefreshResponse'=>['coveredRequestIds'=>[$id],'collectionVersion'=>22,'primaryDurationMs'=>15,'uniqueContactCount'=>7]]]]]];
    }
    public function testSublistUsesObservedWireIndexAndAuthenticatedAcknowledgement():void
    {
        $this->beforeAck=function():void{self::assertSame([],$this->tools->listLabelSublists());};
        $row=$this->tools->setLabelSublist(2,'200',4);$this->beforeAck=null;
        self::assertSame('200@s.whatsapp.net',$row['chatJid']);self::assertSame(4,$row['subListId']);self::assertArrayNotHasKey('_raw',$row);
        self::assertSame('["label_sublist","2","200@s.whatsapp.net"]',$this->wire[0]['_raw']['index']);self::assertSame(['regular',1],[$this->wire[0]['collection'],$this->wire[0]['version']]);
        $this->tools->setLabelSublist(2,'300',5);self::assertCount(2,$this->tools->listLabelSublists());self::assertCount(1,$this->tools->listLabelSublists('300'));
        $this->tools->removeLabelSublist(2,'200');self::assertSame([],$this->tools->listLabelSublists('200'));self::assertSame('remove',$this->wire[2]['operation']);
    }
    public function testRejectedPatchDoesNotCreateSuccessfulProjection():void
    {
        $this->reject=true;
        try{$this->tools->setLabelSublist(1,'200',2);self::fail();}catch(\Throwable $error){self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class,$error);}
        self::assertSame([],$this->tools->listLabelSublists());
    }
    public function testManagerMetadataAndGroupHistoryUseDistinctObservedIndexes():void
    {
        $this->tools->setContactManagerHidden('200@lid',true);self::assertTrue($this->history->getContact('200@lid')['managerHidden']);
        self::assertSame('["contact_manager_metadata","200@lid"]',$this->wire[0]['_raw']['index']);
        $this->tools->setGroupHistorySharing('100-200@g.us',true);self::assertTrue($this->history->getChat('100-200@g.us')['groupHistorySharing']);
        self::assertSame('["group_history_toggle","100-200@g.us"]',$this->wire[1]['_raw']['index']);self::assertSame(1,$this->wire[1]['groupHistoryToggleMode']);
        $this->tools->setGroupHistorySharing('100-200@g.us',false);self::assertFalse($this->history->getChat('100-200@g.us')['groupHistorySharing']);
    }
    public function testBirthdayPreservesContactAndRequiresObservedContact():void
    {
        $this->seed('Contact',['id'=>'200@s.whatsapp.net'],['fullName'=>'Maria','firstName'=>'M','saveOnPrimaryAddressbook'=>true]);
        $row=$this->tools->setContactBirthday('200','2000-02-29');self::assertSame('Maria',$row['fullName']);self::assertTrue($row['saveOnPrimaryAddressbook']);self::assertSame('2000-02-29',$row['birthday']);
        try{$this->tools->setContactBirthday('201','2000-01-01');self::fail();}catch(\OutOfBoundsException){}
        self::assertNull($this->projector->getAction('Contact',['id'=>'201@s.whatsapp.net']));
        $this->seed('LidContact',['id'=>'300@lid'],['fullName'=>'LID Maria']);$row=$this->tools->setContactBirthday('300@lid','2001-02-28');self::assertSame('LidContact',$row['schema']);self::assertSame('LID Maria',$row['fullName']);
    }
    public function testCustomerDataCrudPreservesFieldsAndConvertsBirthdayToUtcSeconds():void
    {
        $first=$this->tools->saveCustomerData('200@lid',['email'=>'maria@example.invalid','birthday'=>'2000-02-29','contactType'=>1]);
        self::assertSame(951782400,$first['birthday']);self::assertSame('["customer_data","200@lid"]',$this->wire[0]['_raw']['index']);
        $second=$this->tools->saveCustomerData('200@lid',['address'=>'Rua MVO','leadStage'=>2]);self::assertSame($first['createdAt'],$second['createdAt']);self::assertSame($first['email'],$second['email']);
        $this->tools->deleteCustomerData('200@lid');self::assertNull($this->tools->getCustomerData('200@lid'));
    }
    public function testPendingCustomerActionsCanOnlyClearObservedAction():void
    {
        $this->seed('BBProPendingCustomerBase',['action'=>'observed-action'],['pending'=>true]);self::assertTrue($this->tools->listPendingCustomerActions()[0]['pending']);
        self::assertFalse($this->tools->clearPendingCustomerAction('observed-action')['pending']);self::assertSame('["bb_pro_pending_customer_base_action","observed-action"]',$this->wire[0]['_raw']['index']);self::assertSame(7,$this->wire[0]['version']);
        $this->expectException(\OutOfBoundsException::class);$this->tools->clearPendingCustomerAction('invented-action');
    }
    public function testPrimaryCapabilitiesArePerDeviceAndNeverReadFromForeignOrCompanion():void
    {
        self::assertNull($this->features->contactRefreshCapabilities()['refreshSupported']);
        $this->seed('DeviceCapabilitiesDevice',['deviceJid'=>'200:0@s.whatsapp.net'],['contactRefresh'=>['refreshSupported'=>true]],20);
        $this->seed('DeviceCapabilitiesV2',['deviceJid'=>'900:5@lid'],['contactRefresh'=>['refreshSupported'=>true]],30);
        self::assertFalse($this->features->contactRefreshCapabilities()['observed']);
        $this->primaryCapability(false);self::assertFalse($this->features->contactRefreshCapabilities()['refreshSupported']);
        $this->seed('DeviceCapabilitiesV2',['deviceJid'=>'900:0@lid'],['contactRefresh'=>['refreshSupported'=>true]],40);self::assertTrue($this->features->contactRefreshCapabilities()['refreshSupported']);
        self::assertSame('DeviceCapabilities',AppStateSchema::parse(AppStateSchema::mutation('DeviceCapabilities',[],[],1))['schema']);
    }
    public function testRefreshUsesOwnPrimaryAndCorrelatesOnlyAuthenticatedPrimaryResult():void
    {
        $this->primaryCapability();$request=$this->features->requestContactRefresh();$id=$request['requestId'];self::assertSame('server_ack',$request['status']);
        self::assertSame('100@s.whatsapp.net',$this->protocols[0][0]);self::assertSame(['peerDataOperationRequestType'=>'CONTACT_REFRESH_REQUEST'],$this->protocols[0][1]['peerDataOperationRequestMessage']);self::assertSame($id,$this->protocols[0][2]['id']);
        self::assertSame([],$this->features->ingestAuthenticatedEvent($this->event($this->response($id),'200:0@s.whatsapp.net','200@s.whatsapp.net')));
        self::assertSame([],$this->features->ingestAuthenticatedEvent($this->event($this->response($id),'100:2@s.whatsapp.net','100@s.whatsapp.net')));
        self::assertSame('server_ack',$this->features->getContactRefresh($id)['status']);
        $events=$this->features->ingestAuthenticatedEvent($this->event($this->response($id),'100:0@s.whatsapp.net','100@s.whatsapp.net'));
        self::assertSame('contact_refresh',$events[0]['name']);self::assertSame('completed',$this->features->getContactRefresh($id)['status']);self::assertSame(7,$events[0]['payload']['results'][0]['uniqueContactCount']);
        self::assertSame([],$this->features->ingestAuthenticatedEvent($this->event($this->response($id),'100:0@s.whatsapp.net','100@s.whatsapp.net')));
    }
    public function testRefreshRequiresCapabilityAndExpiresWithoutBlindResend():void
    {
        try{$this->features->requestContactRefresh();self::fail();}catch(\LogicException){}self::assertSame([],$this->protocols);
        $this->primaryCapability();$request=$this->features->requestContactRefresh();$this->now+=86400001;
        self::assertSame('expired',$this->features->getContactRefresh($request['requestId'])['status']);
        self::assertSame([],$this->features->ingestAuthenticatedEvent($this->event($this->response($request['requestId']),'100:0@s.whatsapp.net','100@s.whatsapp.net')));self::assertCount(1,$this->protocols);
    }
    public function testAcp2RejectsWrongChatStaleFutureAndFalseContextRiders():void
    {
        $proto=['acp2SettingMessage'=>['message'=>['protocolMessage'=>['type'=>'ACP2_SETTING','acp2Setting'=>['enabled'=>true,'settingTimestamp'=>$this->now,'trigger'=>'CHAT_SETTING']]]]];
        self::assertSame([],$this->features->ingestAuthenticatedEvent($this->event($proto,chat:'300@s.whatsapp.net')));
        $events=$this->features->ingestAuthenticatedEvent($this->event($proto));self::assertSame('acp2',$events[0]['name']);self::assertTrue($this->features->getAcp2('200')['enabled']);
        self::assertTrue($events[0]['payload']['enabledFromPrimaryOnly']);self::assertTrue($events[0]['payload']['linkedRestricted']);self::assertFalse($events[0]['payload']['configurationSupported']);self::assertFalse($events[0]['payload']['enforcementVerified']);
        $proto['acp2SettingMessage']['message']['protocolMessage']['acp2Setting']['settingTimestamp']--;
        self::assertSame([],$this->features->ingestAuthenticatedEvent($this->event($proto)));
        $proto['acp2SettingMessage']['message']['protocolMessage']['acp2Setting']['settingTimestamp']=$this->now+604800001;
        self::assertSame([],$this->features->ingestAuthenticatedEvent($this->event($proto)));
        self::assertSame([],$this->features->ingestAuthenticatedEvent($this->event(['conversation'=>'text','messageContextInfo'=>['acp2Setting'=>['enabled'=>false,'settingTimestamp'=>$this->now+1]]])));
    }
    public function testCtwaProjectionDeduplicatesAndKeepsOnlyPublicPromoMetadata():void
    {
        $proto=['extendedTextMessage'=>['text'=>'Promo','contextInfo'=>['externalAdReply'=>['containsCtwaPromo'=>true,'ctwaPromoResponseId'=>'PROMO','ctwaPromoOfferId'=>'OFFER','ctwaPromoResponseExpiresAtSeconds'=>1800000900,'thumbnail'=>'private-image-bytes']]]];
        $events=$this->features->ingestAuthenticatedEvent($this->event($proto));self::assertSame('ctwa_promotion',$events[0]['name']);self::assertArrayNotHasKey('thumbnail',$events[0]['payload']['promotion']);
        self::assertCount(1,$this->features->listCtwaPromotions('200'));self::assertSame([],$this->features->ingestAuthenticatedEvent($this->event($proto)));self::assertSame([],$this->features->listCtwaPromotions('300'));
        self::assertSame([],$this->features->ingestAuthenticatedEvent($this->event($proto,chat:'300@s.whatsapp.net',id:'FORGED')));
    }
    public function testCtwaNewFlagsAndIdsAreObservedWithoutInventingAdsFromProductAlone():void
    {
        foreach([['containsCtwaFlowsAutoLabel'=>true],['ctwaPromoOfferId'=>'OFFER'],['ctwaPromoAdEntryId'=>'ENTRY'],['ctwaPromoAdgroupId'=>'GROUP']]as$index=>$ad){
            $proto=['extendedTextMessage'=>['text'=>'CTWA','contextInfo'=>['externalAdReply'=>$ad]]];
            self::assertCount(1,$this->features->ingestAuthenticatedEvent($this->event($proto,'300:3@lid','300@lid','CTWA'.$index)));
        }
        self::assertCount(4,$this->features->listCtwaPromotions('300@lid'));self::assertSame([],$this->features->listCtwaPromotions('300@s.whatsapp.net'));
        self::assertSame([],$this->features->ingestAuthenticatedEvent($this->event(['extendedTextMessage'=>['text'=>'Product','contextInfo'=>['externalAdReply'=>['productId'=>'PRODUCT','containsCtwaPromo'=>false]]]])));
    }
    public function testUnknownNewAppStateIsObservedAndRemovedWithoutGuessingWritableIndex():void
    {
        $mutation=['collection'=>'regular_low','operation'=>'set','index'=>'["unconfirmed_nesting_index","100-200@g.us"]','value'=>['timestamp'=>10,'communityNestingStateAction'=>['nestingState'=>1]],'version'=>1,'timestamp'=>10];
        self::assertNull($this->projector->apply($mutation));$rows=$this->projector->listObservedActions('communityNestingStateAction');self::assertCount(1,$rows);self::assertFalse($rows[0]['writable']);
        $this->projector->replaceSnapshot('regular_low',[]);self::assertSame([],$this->projector->listObservedActions('communityNestingStateAction'));
    }
    public function testOmittedInventoryUsesPrimaryHistoryAndRecoveryAckDoesNotClaimDownload():void
    {
        $service=new HistoryService($this->store,new MediaTransfer(static fn()=>throw new \LogicException()),static fn():array=>['meJid'=>'100:5@s.whatsapp.net'],static function():void{},static function():void{});
        $bytes=SchemaCodec::encode('HistorySync',['syncType'=>'RECENT','omittedConversationsProvided'=>true,'omittedConversations'=>[['historyChatId'=>'200@s.whatsapp.net','accountLid'=>'300@lid']]]);
        $result=$service->ingest($bytes);self::assertSame(1,$result['omittedConversationsCount']);$inventory=$this->features->omittedConversations();self::assertTrue($inventory['provided']);$id=$inventory['conversations'][0]['id'];
        $request=$this->features->recoverConversation($id,20);self::assertSame(['chatJid'=>'200@s.whatsapp.net','count'=>20],$this->historyRequests[0]);self::assertFalse($request['historyReceived']);self::assertSame('omitted',$this->features->omittedConversations()['conversations'][0]['status']);
        $service->ingest(SchemaCodec::encode('HistorySync',['conversations'=>[['id'=>'200@s.whatsapp.net','acp2Setting'=>['enabled'=>true,'settingTimestamp'=>$this->now],'acp2HistoryWithheld'=>true]]]));
        self::assertSame('received',$this->features->omittedConversations()['conversations'][0]['status']);self::assertTrue($this->features->getAcp2('200')['historyWithheld']);
        $this->expectException(\OutOfBoundsException::class);$this->features->recoverConversation(str_repeat('a',64));
    }
    public function testMalformedInventoryHistoryRollsBackAndDoesNotCreateChat():void
    {
        $service=new HistoryService($this->store,new MediaTransfer(static fn()=>throw new \LogicException()),static fn():array=>[],static function():void{},static function():void{});
        try{$service->ingest(SchemaCodec::encode('HistorySync',['omittedConversationsProvided'=>true,'omittedConversations'=>[['historyChatId'=>'200@s.whatsapp.net']]])."\x12\xff");self::fail();}catch(\UnexpectedValueException){}
        self::assertSame(['provided'=>false,'conversations'=>[]],$this->features->omittedConversations());self::assertSame([],$this->history->listChats());
    }
    public function testOmittedInventoryDoesNotUndoAlreadyReceivedConversationInSameOrLaterChunk():void
    {
        $service=new HistoryService($this->store,new MediaTransfer(static fn()=>throw new \LogicException()),static fn():array=>[],static function():void{},static function():void{});
        $entry=['historyChatId'=>'200@s.whatsapp.net'];
        $service->ingest(SchemaCodec::encode('HistorySync',['conversations'=>[['id'=>'200@s.whatsapp.net']],'omittedConversations'=>[$entry]]));
        self::assertSame('received',$this->features->omittedConversations()['conversations'][0]['status']);
        $service->ingest(SchemaCodec::encode('HistorySync',['omittedConversations'=>[$entry]]));self::assertSame('received',$this->features->omittedConversations()['conversations'][0]['status']);
    }
    public function testGroupHistoryThemeMessageIsImportedAndDoesNotExpireAsOrdinary28DayMessage():void
    {
        $service=new HistoryService($this->store,new MediaTransfer(static fn()=>throw new \LogicException()),static fn():array=>['meJid'=>'100:5@s.whatsapp.net'],static function():void{},static function():void{});
        $theme=['key'=>['remoteJid'=>'100-200@g.us','id'=>'THEME','fromMe'=>false,'participant'=>'200@s.whatsapp.net'],'messageTimestamp'=>10,'message'=>['protocolMessage'=>['type'=>'CHAT_THEME_SETTING','chatThemeSetting'=>['colorSchemeId'=>'ocean','settingTimestampMs'=>10000]]]];
        $result=$service->ingestGroupCompressed(gzcompress(SchemaCodec::encode('GroupHistory',['themeMessage'=>$theme])),'100-200@g.us',1800000000);
        self::assertSame(1,$result['themeMessageCount']);self::assertSame('ocean',$this->history->getChat('100-200@g.us')['groupTheme']['colorSchemeId']);self::assertSame('THEME',$this->history->getChat('100-200@g.us')['groupThemeMessageId']);
        self::assertNull($result['oldestTimestampMs']);
        $theme['key']['id']='OLDER';$theme['message']['protocolMessage']['chatThemeSetting']=['colorSchemeId'=>'older','settingTimestampMs'=>9999];
        $service->ingestGroupCompressed(gzcompress(SchemaCodec::encode('GroupHistory',['themeMessage'=>$theme])),'100-200@g.us',1800000000);
        self::assertSame('ocean',$this->history->getChat('100-200@g.us')['groupTheme']['colorSchemeId']);
    }
    public function testRefreshAndPromoPersistAcrossServiceAndDatabaseRestart():void
    {
        $path=tempnam(sys_get_temp_dir(),'mvo-state-');
        try{
            $store=new SignalStore($path);$history=new HistoryStore($store);$projector=new AppStateProjector($store,$history);$projector->apply(AppStateSchema::mutation('DeviceCapabilitiesV2',['deviceJid'=>'900:0@lid'],['contactRefresh'=>['refreshSupported'=>true]],1));
            $features=$this->makeFeatures($store,$history,$projector);$id=$features->requestContactRefresh()['requestId'];$store->close();
            $store=new SignalStore($path);$history=new HistoryStore($store);$projector=new AppStateProjector($store,$history);$features=$this->makeFeatures($store,$history,$projector);
            self::assertSame('server_ack',$features->getContactRefresh($id)['status']);$features->ingestAuthenticatedEvent($this->event($this->response($id),'100:0@s.whatsapp.net','100@s.whatsapp.net'));self::assertSame('completed',$features->getContactRefresh($id)['status']);$store->close();
        }finally{foreach([$path,$path.'-wal',$path.'-shm']as$file)if(is_file($file))unlink($file);}
    }
    public function testPureInputValidationRejectsImpossibleDatesAndWrongJidTopology():void
    {
        foreach(['2025-02-29','2000-13-01','01/02/2000']as$date){try{StateFeatureInputs::contactBirthday($date);self::fail();}catch(\InvalidArgumentException){}}
        foreach([fn()=>StateFeatureInputs::contactManager('200@s.whatsapp.net'),fn()=>StateFeatureInputs::groupHistory('200@lid'),fn()=>StateFeatureInputs::labelSublist(-1),fn()=>StateFeatureInputs::customerData('200@lid',['invented'=>true])]as$invalid){try{$invalid();self::fail();}catch(\InvalidArgumentException){}}
        self::assertSame('2000-02-29',StateFeatureInputs::contactBirthday('2000-02-29'));
    }
}
