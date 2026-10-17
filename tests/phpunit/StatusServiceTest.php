<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\AppState\AppStateService;
use MVO\Auth\CredentialStore;
use MVO\History\{HistoryStore,StatusStore};
use MVO\Media\MediaTransfer;
use MVO\Message\{ContentBuilder,MessageCodec,MessageService};
use MVO\Protocol\BinaryNode;
use MVO\Service\{Services,StatusService};
use MVO\Signal\{SignalProtocol,SignalStore};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusServiceTest extends TestCase
{
    private array $stores=[];
    private function store():SignalStore {$store=new SignalStore(':memory:');$this->stores[]=$store;return $store;}
    protected function tearDown():void {foreach($this->stores as $store)$store->close();}
    private function history(SignalStore $store):StatusStore {return new StatusStore($store,new HistoryStore($store));}
    private function facade(?callable $publisher=null,?StatusStore $history=null,?callable $receipt=null,?callable $direct=null,?callable $download=null):StatusService
    {
        return new StatusService(static fn():AppStateService=>throw new \LogicException('Unexpected app-state'),$publisher??static fn():array=>throw new \LogicException('Unexpected publication'),$history===null?null:static fn():StatusStore=>$history,$receipt,$direct,$download);
    }
    private function record(SignalStore $store,string $id,bool $own,string $author,array $proto=['extendedTextMessage'=>['text'=>'Status']]):StatusStore
    {
        $history=new HistoryStore($store);$history->upsertMessage(['chat'=>StatusStore::CHAT,'id'=>$id,'fromMe'=>$own,'participant'=>$author,'timestamp'=>time(),'proto'=>$proto]);return new StatusStore($store,$history);
    }

    public function testStyledTextUsesActualWireFieldsAndNormalizesExplicitAudience():void
    {
        $captured=null;$service=$this->facade(function(...$args)use(&$captured):array{$captured=$args;return ['id'=>'A','status'=>'server_ack'];});
        $result=$service->sendText('MVO',['+55 (11) 90000-0001','5511900000001@s.whatsapp.net','900@lid'],['font'=>'SYSTEM_BOLD','backgroundColor'=>'#0099ff','textColor'=>0],'allowlist',['id'=>'A']);
        self::assertSame('server_ack',$result['status']);self::assertSame('status@broadcast',$captured[0]);
        self::assertSame(['5511900000001@s.whatsapp.net','900@lid'],$captured[2]);self::assertSame(['id'=>'A','statusSetting'=>'allowlist'],$captured[3]);
        $proto=ContentBuilder::build($captured[1],StatusStore::CHAT)['proto'];self::assertSame(['text'=>'MVO','font'=>6,'backgroundArgb'=>0xff0099ff,'textArgb'=>0],$proto['extendedTextMessage']);
    }
    #[DataProvider('invalidPublications')]
    public function testInvalidPublicationDoesNotReachAnyIo(mixed $content,array $recipients,?string $setting=null,array $options=[]):void
    {
        $calls=0;$service=$this->facade(function()use(&$calls):array{++$calls;throw new \LogicException('Unexpected publication');});
        try{$service->send($content,$recipients,$setting,$options);self::fail('Accepted invalid status');}catch(\InvalidArgumentException){}self::assertSame(0,$calls);
    }
    public static function invalidPublications():iterable
    {
        $audience=['900@lid'];
        yield ['x',[]];yield ['x',['100-200@g.us']];yield ['x',['status@broadcast']];yield ['x',['alice@lid']];yield ['x',['900:2@lid']];yield ['x',['alice@example.com']];yield ['x',['cliente.exemplo']];yield ['x',['bad'=> '900@lid']];yield ['x',[3]];
        yield ['', $audience];yield [['type'=>'text','text'=>'x','font'=>3],$audience];yield [['type'=>'text','text'=>'x','font'=>'UNKNOWN'],$audience];yield [['type'=>'text','text'=>'x','backgroundColor'=>'red'],$audience];yield [['type'=>'text','text'=>'x','textColor'=>-1],$audience];yield [['type'=>'text','text'=>'x','textColor'=>0x100000000],$audience];yield [['type'=>'text','text'=>'x','textColor'=>false],$audience];
        yield [['type'=>'poll','name'=>'No'],$audience];yield [['type'=>'image','media'=>['bytes'=>'x'],'caption'=>[]],$audience];yield [['type'=>'image','media'=>['bytes'=>'x'],'height'=>-1],$audience];yield [['type'=>'video','media'=>['bytes'=>'x'],'seconds'=>'1'],$audience];yield [['type'=>'voice','media'=>['bytes'=>'x'],'normalizeVoice'=>'yes'],$audience];yield [['type'=>'image','media'=>[]],$audience];yield [['type'=>'image','media'=>'x','viewOnce'=>true],$audience];
        yield [['type'=>'raw','message'=>['protocolMessage'=>['type'=>'REVOKE']]],$audience];yield [['type'=>'raw','message'=>['conversation'=>'text']],$audience];yield [['type'=>'raw','message'=>['extendedTextMessage'=>['invalid'=>'x']]],$audience];
        yield ['x',$audience,'unknown'];yield ['x',$audience,'contacts',['statusSetting'=>'denylist']];yield ['x',$audience,null,['id'=>"bad id"]];yield ['x',$audience,null,['extra'=>true]];
    }
    public function testAllTypedMediaHelpersPreserveUploadSourceAndVoiceColor():void
    {
        $sent=[];$service=$this->facade(function(string $to,array $content)use(&$sent):array{$sent[]=$content;return ['id'=>'A','status'=>'server_ack'];});
        $service->sendImage(['bytes'=>'image'],['900@lid'],['caption'=>'Photo']);$service->sendVideo(['bytes'=>'video'],['900@lid'],['seconds'=>12,'gifPlayback'=>false]);
        $service->sendVoice(['bytes'=>'audio'],['900@lid'],['seconds'=>3,'backgroundColor'=>'#800099ff']);
        self::assertSame(['image','video','ptt'],array_column($sent,'type'));self::assertSame(['bytes'=>'image'],$sent[0]['media']);self::assertSame(0x800099ff,$sent[2]['backgroundArgb']);
        self::assertSame($sent[2],StatusService::prepareContent($sent[2]),'Pure canonical content must be safe to validate twice');
    }
    public function testMediaOptionsCannotOverrideTypeOrSource():void
    {
        $service=$this->facade();$this->expectException(\InvalidArgumentException::class);$service->sendImage('photo.jpg',['900@lid'],['type'=>'video']);
    }
    public function testRevokeUsesOnlyOriginalOwnAudienceAndKey():void
    {
        $store=$this->store();$history=$this->record($store,'OWN',true,'100@lid');$history->recordAudience('OWN','100@lid',['901@lid','900@lid'],'allowlist',time());$captured=null;
        $service=$this->facade(function(...$args)use(&$captured):array{$captured=$args;return ['id'=>'DELETE','status'=>'server_ack'];},$history);
        self::assertSame('server_ack',$service->revokeStatus('OWN')['status']);self::assertSame(['900@lid','901@lid'],$captured[2]);self::assertSame('allowlist',$captured[3]['statusSetting']);
        $key=$captured[1]['message']['protocolMessage']['key'];self::assertSame(['remoteJid'=>StatusStore::CHAT,'fromMe'=>true,'id'=>'OWN','participant'=>'100@lid'],$key);
    }
    public function testUnknownReceivedOrChangedAudienceCannotBeRevoked():void
    {
        $store=$this->store();$history=$this->record($store,'THEIRS',false,'900@lid');$this->record($store,'OWN',true,'100@lid');$history->recordAudience('OWN','100@lid',['900@lid'],'contacts',time());
        $calls=0;$service=$this->facade(function()use(&$calls):array{++$calls;throw new \LogicException('Unexpected publication');},$history);
        foreach([['UNKNOWN',[],null],['THEIRS',['900@lid'],null],['OWN',['901@lid'],null],['OWN',[],'allowlist']] as [$id,$audience,$setting]) {
            try{$service->revokeStatus($id,$audience,$setting);self::fail('Accepted unauthorized revoke');}catch(\LogicException){}
        }self::assertSame(0,$calls);
    }
    public function testRevokeWithoutDurableProvenanceFailsClosed():void
    {
        $this->expectException(\LogicException::class);$this->facade()->revokeStatus('A',['900@lid']);
    }
    public function testReadQueuesExactStatusParticipantWithoutOptimisticView():void
    {
        $history=$this->record($this->store(),'STATUS',false,'900@lid');$receipt=null;
        $service=$this->facade(null,$history,function($keys,$type)use(&$receipt):void{$receipt=[$keys,$type];});
        self::assertSame('receipt_queued',$service->readStatus('STATUS','900@lid')['status']);self::assertSame('read',$receipt[1]);
        self::assertSame([['id'=>'STATUS','remoteJid'=>StatusStore::CHAT,'fromMe'=>false,'participant'=>'900@lid']],$receipt[0]);self::assertFalse($history->getStatus('STATUS','900@lid')['viewed']);
    }
    public function testPrivateReplyQuotesStatusAndReactionRetainsBroadcastKey():void
    {
        $history=$this->record($this->store(),'STATUS',false,'900@lid');$published=[];
        $service=$this->facade(null,$history,null,function($to,$content)use(&$published):array{$published[]=[$to,ContentBuilder::build($content,$to)['proto']];return ['id'=>'REPLY','status'=>'server_ack'];});
        $service->replyToStatus('STATUS','900@lid','Great');$service->reactToStatus('STATUS','900@lid','👍');$service->reactToStatus('STATUS','900@lid','');
        foreach($published as $sent)self::assertSame('900@lid',$sent[0]);$ctx=$published[0][1]['extendedTextMessage']['contextInfo'];self::assertSame('STATUS',$ctx['stanzaId']);self::assertSame(StatusStore::CHAT,$ctx['remoteJid']);self::assertSame('900@lid',$ctx['participant']);self::assertSame('Status',$ctx['quotedMessage']['extendedTextMessage']['text']);
        self::assertSame(StatusStore::CHAT,$published[1][1]['reactionMessage']['key']['remoteJid']);self::assertSame('900@lid',$published[1][1]['reactionMessage']['key']['participant']);self::assertSame('',$published[2][1]['reactionMessage']['text']);
    }
    public function testOwnUnknownExpiredAndWrongAuthorCannotBeReadRepliedOrReacted():void
    {
        $store=$this->store();$history=$this->record($store,'OWN',true,'100@lid');$this->record($store,'THEIRS',false,'900@lid');
        (new HistoryStore($store))->upsertMessage(['chat'=>StatusStore::CHAT,'id'=>'OLD','fromMe'=>false,'participant'=>'900@lid','timestamp'=>time()-86401,'proto'=>['extendedTextMessage'=>['text'=>'Expired']]]);
        $calls=0;$io=function()use(&$calls){++$calls;throw new \RuntimeException('Unexpected I/O');};$service=$this->facade(null,$history,$io,$io);
        foreach([['OWN','100@lid'],['UNKNOWN','900@lid'],['THEIRS','901@lid'],['OLD','900@lid']] as [$id,$author])foreach(['readStatus','replyToStatus','reactToStatus'] as $method) {
            try{$method==='readStatus'?$service->$method($id,$author):$service->$method($id,$author,'x');self::fail('Accepted unavailable status');}catch(\LogicException){}
        }self::assertSame(0,$calls);
    }
    public function testPrivacyAndMuteInputsAreValidatedBeforeAppStateAccess():void
    {
        $service=$this->facade();foreach([['mode'=>'INVALID'],['mode'=>'CONTACTS','shareToFB'=>1],['mode'=>'CONTACTS','userJids'=>['100-200@g.us']],['mode'=>'CONTACTS','userJids'=>['900:2@lid']],['mode'=>'CONTACTS','unexpected'=>true]] as $input) {
            try{$service->setPrivacy($input);self::fail('Accepted invalid privacy');}catch(\InvalidArgumentException){}
        }
        $this->expectException(\InvalidArgumentException::class);$service->setUserMuted('status@broadcast',true);
    }
    public function testQueriesReturnOnlyStoredDataAndUnknownPrivacyIsNull():void
    {
        $store=$this->store();$history=$this->record($store,'A',false,'900@lid');$service=$this->facade(null,$history);self::assertCount(1,$service->listStatuses());self::assertSame('A',$service->getStatus('A')['id']);
        self::assertNull($service->getPrivacy());self::assertNull($service->isUserMuted('900@lid'));self::assertSame([],$service->listMuted());self::assertSame([],$service->listViewers('A'));self::assertNull($service->getAudience('A'));
    }
    public function testDownloadRejectsTextAndNeverUsesCallerSuppliedMedia():void
    {
        $history=$this->record($this->store(),'A',false,'900@lid');$this->expectException(\InvalidArgumentException::class);$this->facade(null,$history)->downloadStatus('A','900@lid');
    }
    public function testServicesPreservesLazyFacadeAndOptionalHistoryCallbacks():void
    {
        $history=$this->history($this->store());$services=new Services(static fn():BinaryNode=>throw new \LogicException(),static function():void{},static fn():array=>[],null,static fn():AppStateService=>throw new \LogicException(),static fn():array=>[],statusStore:static fn():StatusStore=>$history);
        self::assertSame($services->status(),$services->status());self::assertNull($services->status()->getPrivacy());
    }

    public function testNativeSignalStatusMediaStyleAndRevocationDecryptAtExplicitRecipient():void
    {
        $senderCredentials=self::credentials('5511000000000:5@s.whatsapp.net');$senderCredentials['meLid']='100:5@lid';$senderStore=$this->store();$senderSignal=new SignalProtocol($senderStore,$senderCredentials);$peers=[];
        foreach(['100@lid','900@lid'] as $jid){$credentials=self::credentials($jid);$store=$this->store();$peers[$jid]=['credentials'=>$credentials,'key'=>$store->generatePreKeys(1)[0],'store'=>$store,'signal'=>new SignalProtocol($store,$credentials)];}
        $received=[];$receiver=new MessageService($peers['900@lid']['signal'],$peers['900@lid']['store'],static fn():array=>$peers['900@lid']['credentials'],static function():void{},static fn():BinaryNode=>throw new \LogicException('No receiving query'),static function():void{},function(string $event,array $payload)use(&$received):void{if($event==='message')$received[]=$payload;});
        $uploads=[];$media=new MediaTransfer(static fn():BinaryNode=>new BinaryNode('iq',['type'=>'result'],[new BinaryNode('media_conn',['auth'=>'test','ttl'=>'3600'],[new BinaryNode('host',['hostname'=>'mmg.whatsapp.net'])])]),function(array $request)use(&$uploads):array {if($request['method']==='GET')return ['status'=>200,'body'=>$uploads[parse_url($request['url'],PHP_URL_PATH)]];$index=count($uploads);$uploads['/file'.$index]=$request['body'];return ['status'=>200,'body'=>json_encode(['url'=>'https://mmg.whatsapp.net/file'.$index,'direct_path'=>'/file'.$index],JSON_THROW_ON_ERROR)];});
        $messages=new MessageService($senderSignal,$senderStore,static fn():array=>$senderCredentials,static function():void{},function(BinaryNode $node)use($receiver,$peers):BinaryNode {
            if($node->tag==='iq'&&($node->attrs['xmlns']??'')==='usync')return self::devices(['100@lid'=>[0,5],'900@lid'=>[0]]);if($node->tag==='iq')return self::bundles($peers);
            self::assertSame(StatusStore::CHAT,$node->attrs['to']);self::assertSame('allowlist',$node->child('meta')->attrs['status_setting']);$children=[$node->child('enc')];foreach($node->child('participants')->content as $participant)if($participant->attrs['jid']==='900@lid'&&$participant->child('enc')!==null)$children[]=$participant->child('enc');
            $receiver->handleNode(new BinaryNode('message',['from'=>StatusStore::CHAT,'participant'=>'100:5@lid','id'=>$node->attrs['id'],'t'=>(string)time()],$children));return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        },static function():void{},static function():void{},$media);
        $statuses=new StatusService(static fn():AppStateService=>throw new \LogicException(),$messages->sendBroadcast(...),$messages->statusStore(...),download:$messages->downloadMedia(...));
        $text=$statuses->sendText('Native PHP',['900@lid'],['font'=>6,'backgroundColor'=>'#0099ff','textColor'=>'#ffffff'],'allowlist');
        self::assertSame('server_ack',$text['status']);self::assertSame(6,$received[0]['proto']['extendedTextMessage']['font']);self::assertSame(0xff0099ff,$received[0]['proto']['extendedTextMessage']['backgroundArgb']);
        $imageBytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLbtAAAAABJRU5ErkJggg==');$image=$statuses->sendImage(['bytes'=>$imageBytes],['900@lid'],['caption'=>'Photo','mimetype'=>'image/png'],'allowlist');
        $statuses->sendVideo(['bytes'=>"\0\0\0\x18ftypmp42fixture"],['900@lid'],['seconds'=>2,'mimetype'=>'video/mp4'],'allowlist');$statuses->sendVoice(['bytes'=>'OggSfixture'],['900@lid'],['mimetype'=>'audio/ogg; codecs=opus','seconds'=>2,'backgroundColor'=>'#123456'],'allowlist');
        self::assertCount(3,$uploads);self::assertSame('image',$received[1]['type']);self::assertSame('Photo',$received[1]['proto']['imageMessage']['caption']);self::assertSame('video',$received[2]['type']);self::assertTrue($received[3]['proto']['audioMessage']['ptt']);self::assertSame(0xff123456,$received[3]['proto']['audioMessage']['backgroundArgb']);
        self::assertSame($imageBytes,$statuses->downloadStatus($image['id']),'Status media verifies hashes and decrypts the stored descriptor');
        self::assertSame(['900@lid'],$statuses->getAudience($text['id'])['recipients']);self::assertSame('server_ack',$statuses->revokeStatus($text['id'])['status']);
        self::assertNull($statuses->getStatus($text['id']));self::assertTrue($statuses->getStatus($text['id'],null,false,true)['revoked']);
    }
    private static function credentials(string $jid):array
    {
        $credentials=CredentialStore::fresh();$credentials['registered']=true;$credentials['meJid']=$jid;$credentials['serverHasPreKeys']=true;$credentials['signedIdentity']=['details'=>'fixture','accountSignatureKey'=>random_bytes(32),'accountSignature'=>random_bytes(64),'deviceSignature'=>random_bytes(64)];return $credentials;
    }
    private static function devices(array $devices):BinaryNode
    {
        $users=[];foreach($devices as $jid=>$ids)$users[]=new BinaryNode('user',['jid'=>$jid],[new BinaryNode('devices',[],[new BinaryNode('device-list',[],array_map(static fn(int $id):BinaryNode=>new BinaryNode('device',['id'=>(string)$id]),$ids))])]);return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('usync',[],[new BinaryNode('list',[],$users)])]);
    }
    private static function bundles(array $peers):BinaryNode
    {
        $users=[];foreach($peers as $jid=>$peer){$c=$peer['credentials'];$k=$peer['key'];$s=$c['signedPreKey'];$users[]=new BinaryNode('user',['jid'=>$jid],[new BinaryNode('registration',[],pack('N',$c['registrationId'])),new BinaryNode('identity',[],$c['identityKeyPair']['public']),new BinaryNode('skey',[],[new BinaryNode('id',[],substr(pack('N',$s['id']),1)),new BinaryNode('value',[],$s['public']),new BinaryNode('signature',[],$s['signature'])]),new BinaryNode('key',[],[new BinaryNode('id',[],substr(pack('N',$k['id']),1)),new BinaryNode('value',[],$k['public'])])]);}return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('list',[],$users)]);
    }
}
