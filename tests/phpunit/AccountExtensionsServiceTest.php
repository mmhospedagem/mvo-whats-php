<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\AppState\{AppStateCrypto,AppStateProjector,AppStateSchema,AppStateService};
use MVO\History\HistoryStore;
use MVO\Message\{ContentBuilder,LinkPreviewBuilder,PhoneNumberRequests};
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Service\AccountExtensionsService;
use MVO\Signal\SignalStore;
use PHPUnit\Framework\TestCase;

final class AccountExtensionsServiceTest extends TestCase
{
    private SignalStore $store;private SignalStore $remote;private HistoryStore $history;private AppStateProjector $projector;private AppStateService $app;private AccountExtensionsService $service;private array $actions=[];private int $queries=0;private bool $reject=false;
    protected function setUp():void
    {
        $this->store=new SignalStore(':memory:');$this->remote=new SignalStore(':memory:');$this->history=new HistoryStore($this->store);$this->projector=new AppStateProjector($this->store,$this->history);$peer=new AppStateService($this->remote,static fn():never=>throw new \LogicException('No remote query'));
        $this->app=new AppStateService($this->store,function(BinaryNode $request)use($peer):BinaryNode{++$this->queries;$responses=[];foreach($request->child('sync')->content as$collection){$name=$collection->attrs['name'];$patch=$collection->child('patch');if($patch!==null){if($this->reject)return new BinaryNode('iq',['type'=>'error']);$decoded=SchemaCodec::decode('SyncdPatch',$patch->content);$decoded['version']=['version'=>$peer->collectionState($name)['version']+1];foreach($peer->applyPatch($name,$decoded)as$mutation)$this->actions[]=AppStateSchema::parse($mutation);}$responses[]=new BinaryNode('collection',['name'=>$name,'version'=>(string)$peer->collectionState($name)['version']]);}return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('sync',[],$responses)]);});$this->app->setProjector($this->projector);
        foreach([$this->app,$peer]as$service){$service->importKeys([['keyId'=>'KEY1','keyData'=>str_repeat('k',32),'timestamp'=>1]]);foreach(AppStateService::COLLECTIONS as$name)$service->applySnapshot($name,['version'=>['version'=>0],'records'=>[],'keyId'=>['id'=>'KEY1'],'mac'=>AppStateCrypto::snapshotMac(str_repeat('k',32),str_repeat("\0",128),0,$name)]);}$this->service=new AccountExtensionsService($this->app,$this->projector,$this->history);
    }
    protected function tearDown():void{$this->store->close();$this->remote->close();}
    private function seed(string $schema,array $args,array $data):void{$this->projector->apply(AppStateSchema::mutation($schema,$args,$data,123));}
    public function testPreferencesKeepUnknownDistinctFromFalseAndUseAcknowledgedExactIndexes():void
    {
        $before=$this->service->preferences();self::assertFalse($before['items'][0]['observed']);self::assertNull($before['items'][0]['value']);
        foreach(['disableLinkPreviews'=>false,'relayAllCalls'=>true,'locale'=>'pt_BR','twentyFourHourFormat'=>true,'unarchiveChats'=>false]as$name=>$value){$result=$this->service->setPreference($name,$value);self::assertSame($value,$result['value']);self::assertSame('set',$this->actions[array_key_last($this->actions)]['operation']);}
        self::assertSame('["setting_unarchiveChats"]',$this->actions[array_key_last($this->actions)]['_raw']['index']);self::assertTrue($this->service->preferences()['items'][0]['observed']);
    }
    public function testRejectedPreferenceNeverReportsSuccessAndInvalidInputsNeverEnqueue():void
    {
        foreach([['locale','bad locale'],['relayAllCalls','true'],['invented',true]]as[$name,$value]){try{$this->service->setPreference($name,$value);self::fail('Expected validation');}catch(\InvalidArgumentException){}}
        self::assertSame(0,$this->queries);self::assertSame([],$this->app->pendingMutations());$this->reject=true;try{$this->service->setPreference('relayAllCalls',true);self::fail('Expected rejection');}catch(\RuntimeException){self::assertFalse($this->service->preferences()['items'][1]['observed']);self::assertCount(1,$this->app->pendingMutations());}
    }
    public function testPlatformPreferencePreservesOtherIndexesAndValidatesActualProtoType():void
    {
        $a=$this->service->setPlatformPreference('WEB','IS_MESSAGES_NOTIFICATION_ENABLED','5511999999999',false);self::assertFalse($a['isMessagesNotificationEnabled']);
        $this->service->setPlatformPreference('WINDOWS','IS_MESSAGES_NOTIFICATION_ENABLED','5511999999999',true);self::assertCount(2,$this->service->platformPreferences());self::assertSame('["settings_sync","3","7","5511999999999@s.whatsapp.net"]',$this->actions[array_key_last($this->actions)]['_raw']['index']);
        $before=$this->queries;try{$this->service->setPlatformPreference('WEB','IS_MESSAGES_NOTIFICATION_ENABLED','5511999999999','yes');self::fail('Expected validation');}catch(\InvalidArgumentException){self::assertSame($before,$this->queries);}
    }
    public function testAiThreadsRequireObservedIdentityAndPreserveSibling():void
    {
        foreach(['one','two']as$id)$this->history->recordEvent(['id'=>'m-'.$id,'chat'=>'867051314767696@bot','fromMe'=>true,'proto'=>['conversation'=>'x','messageContextInfo'=>['threadId'=>[['threadType'=>2,'threadKey'=>['remoteJid'=>'867051314767696@bot','id'=>$id,'fromMe'=>true]]]]]]);
        self::assertCount(2,$this->service->aiThreads());$this->service->mutateAiThread('867051314767696@bot','one','rename','MVO');$this->service->mutateAiThread('867051314767696@bot','one','pin',true);$rows=$this->service->aiThreads();$one=array_values(array_filter($rows,static fn(array $r):bool=>$r['threadId']==='one'))[0];self::assertSame('MVO',$one['title']);self::assertTrue($one['pinned']);$this->service->mutateAiThread('867051314767696@bot','one','delete');self::assertCount(2,$this->service->aiThreads());
        $before=$this->queries;try{$this->service->mutateAiThread('867051314767696@bot','unknown','delete');self::fail('Expected missing thread');}catch(\OutOfBoundsException){self::assertSame($before,$this->queries);}
    }
    public function testStickersUseObservedDescriptorsAndTimestampsWithoutLeakingKeys():void
    {
        $hash=str_repeat('h',32);$id=bin2hex($hash);$this->history->recordEvent(['id'=>'sticker','chat'=>'5511999999999@s.whatsapp.net','proto'=>['stickerMessage'=>['fileSha256'=>$hash,'mediaKey'=>str_repeat('m',32),'fileEncSha256'=>str_repeat('e',32),'directPath'=>'/mms/sticker/x','mimetype'=>'image/webp']]]);
        $result=$this->service->setFavoriteSticker($id,true);self::assertTrue($result['favorite']);self::assertArrayNotHasKey('mediaKey',$result);$last=$this->actions[array_key_last($this->actions)];self::assertSame('FavoriteSticker',$last['schema']);self::assertSame(base64_encode($hash),$last['filehash']);
        $this->history->observed()->sticker(['fileSha256'=>$hash,'lastStickerSentTs'=>1234],'recent');self::assertTrue($this->service->removeRecentSticker($id)['removed']);self::assertSame(1234,$this->actions[array_key_last($this->actions)]['lastStickerSentTs']);
        $before=$this->queries;try{$this->service->setFavoriteSticker(str_repeat('0',64),true);self::fail('Expected observed guard');}catch(\OutOfBoundsException){self::assertSame($before,$this->queries);}
    }
    public function testNativeDiagnosticsAreObservedOnlyAndKeepDisabledValues():void
    {
        $this->seed('PrimaryFeature',[],['flags'=>['test-feature']]);$this->seed('SubscriptionsSyncV2',[],['paidFeature'=>[['name'=>'test-feature','enabled'=>false,'limit'=>0,'expirationTime'=>123]]]);$result=$this->service->nativeDiagnostics();self::assertFalse($result['canEnablePaidFeatures']);self::assertFalse($result['serverEligibilityConfirmed']);self::assertSame('unknown',$result['items'][2]['state']);self::assertFalse($result['items'][1]['records'][0]['paidFeature'][0]['enabled']);self::assertSame(0,$this->queries);
    }
    public function testPhoneRequestRequiresExplicitSelfContactQuotedAndOppositeDirection():void
    {
        $chat='5511999999999@s.whatsapp.net';$this->history->recordEvent(['id'=>'request','chat'=>$chat,'fromMe'=>true,'timestamp'=>10,'proto'=>ContentBuilder::build(PhoneNumberRequests::content(),$chat)['proto']]);
        foreach([['random',false,false,'request'],['unquoted',false,true,'other'],['own',true,true,'request'],['answer',false,true,'request']]as[$id,$fromMe,$self,$quote])$this->history->recordEvent(['id'=>$id,'chat'=>$chat,'from'=>$chat,'fromMe'=>$fromMe,'timestamp'=>11,'proto'=>['contactMessage'=>['displayName'=>'Voluntary','vcard'=>'BEGIN:VCARD','isSelfContact'=>$self,'contextInfo'=>['stanzaId'=>$quote]]]]);
        $rows=PhoneNumberRequests::list($this->history,$chat);self::assertCount(1,$rows);self::assertSame('self_contact_shared',$rows[0]['state']);self::assertCount(1,$rows[0]['responses']);self::assertSame('answer',$rows[0]['responses'][0]['messageId']);self::assertArrayNotHasKey('phoneNumber',$rows[0]);
    }
    public function testLinkPreviewExtractsSafeMetadataAndRejectsBoundAndPrivateUrls():void
    {
        $builder=new LinkPreviewBuilder(static fn():string=>'<title>Fallback</title><meta property="og:title" content="MVO &amp; Cloud"><meta name="description" content="Hospedagem">');$content=$builder->build('Acesse https://www.mvo.host/','https://www.mvo.host/',false);self::assertSame('MVO & Cloud',$content['linkPreview']['title']);self::assertSame('Hospedagem',$content['linkPreview']['description']);self::assertArrayNotHasKey('jpegThumbnail',$content['linkPreview']);
        foreach(['http://www.mvo.host/','https://127.0.0.1/','https://100.64.0.1/','https://[::1]/','https://user:pass@www.mvo.host/','https://www.mvo.host:8443/','https://foo.internal/']as$url){try{LinkPreviewBuilder::url($url);self::fail('Expected private URL rejection');}catch(\InvalidArgumentException){}}
        $this->expectException(\LengthException::class);(new LinkPreviewBuilder(static fn():string=>str_repeat('x',1048577)))->build('https://www.mvo.host/','https://www.mvo.host/',false);
    }
    public function testPreviewPreparesBoundedJpegAndPreservesEncryptedThumbnailFields():void
    {
        if(!function_exists('imagecreatetruecolor'))self::markTestSkipped('Optional ext-gd unavailable');$image=imagecreatetruecolor(800,400);ob_start();imagepng($image);$png=ob_get_clean();unset($image);$uploaded=null;
        $builder=new LinkPreviewBuilder(static fn(string $url):string=>str_ends_with($url,'.png')?$png:'<meta property="og:title" content=""><meta property="og:image" content="/card.png">',static function(string $jpeg)use(&$uploaded):array{$uploaded=$jpeg;return ['directPath'=>'/mms/thumbnail-link/x','fileSha256'=>str_repeat('s',32),'fileEncSha256'=>str_repeat('e',32),'mediaKey'=>str_repeat('k',32),'mediaKeyTimestamp'=>123];});
        $content=$builder->build('https://www.mvo.host/','https://www.mvo.host/');self::assertSame(640,$content['linkPreview']['thumbnailWidth']);self::assertSame(320,$content['linkPreview']['thumbnailHeight']);self::assertSame('/mms/thumbnail-link/x',$content['linkPreview']['thumbnailDirectPath']);self::assertSame(str_repeat('k',32),$content['linkPreview']['mediaKey']);self::assertSame(IMAGETYPE_JPEG,getimagesizefromstring($uploaded)[2]);self::assertSame(32,strlen($content['linkPreview']['thumbnailEncSha256']));SchemaCodec::encode('Message',ContentBuilder::build($content,'5511999999999@s.whatsapp.net')['proto']);
    }
    public function testPreviewRejectsPrivateImageBeforeCallingItsFetcher():void
    {
        $fetches=0;$builder=new LinkPreviewBuilder(static function()use(&$fetches):string{++$fetches;return '<meta property="og:image" content="https://127.0.0.1/private">';});try{$builder->build('https://www.mvo.host/','https://www.mvo.host/');self::fail('Expected private image rejection');}catch(\InvalidArgumentException){self::assertSame(1,$fetches);}
    }
}
