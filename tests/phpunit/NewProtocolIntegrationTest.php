<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\History\StatusStore;
use MVO\Media\MediaTransfer;
use MVO\Message\{AddonCrypto,AdvancedContent,ContentBuilder,ContentDecoder,MessageService};
use MVO\Protocol\{BinaryData,BinaryNode,Jid,SchemaCodec};
use MVO\Service\StatusService;
use MVO\Signal\{SignalProtocol,SignalStore};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises public builders, persistent provenance, authenticated addons and real media crypto. */
final class NewProtocolIntegrationTest extends TestCase
{
    private SignalStore $store;
    private array $credentials;
    protected function setUp():void
    {
        $this->store=new SignalStore(':memory:');
        $this->credentials=CredentialStore::fresh();
        $this->credentials['registered']=true;
        $this->credentials['meJid']='111:5@s.whatsapp.net';
        $this->credentials['meLid']='123@lid';
        $this->credentials['serverHasPreKeys']=true;
        $this->credentials['signedIdentity']=['details'=>'fixture','accountSignatureKey'=>random_bytes(32),'accountSignature'=>random_bytes(64),'deviceSignature'=>random_bytes(64)];
    }
    protected function tearDown():void {$this->store->close();}

    public function testPublicQuizBuilderSuppliesSecretWireFieldsAndPollStanzaAttributes():void
    {
        $built=ContentBuilder::build(['type'=>'quiz','name'=>'Capital?','options'=>['Brasília','Rio'],'correctAnswer'=>'Brasília','endTime'=>2000000100],'456@lid');
        self::assertSame(['type'=>'poll'],$built['attrs']);self::assertSame('creation',$built['metaAttrs']['polltype']);
        self::assertSame(32,strlen($built['secrets']['messageSecret']));
        $decoded=ContentDecoder::decode($built['bytes']);
        self::assertSame('pollCreationMessageV6',$decoded['contentType']);
        self::assertSame('quiz',$decoded['poll']['type']);self::assertSame('Brasília',$decoded['poll']['correctAnswer']);
        self::assertSame($built['secrets']['messageSecret'],$decoded['messageContextInfo']['messageSecret']);
    }

    public function testAlbumHeaderIsTextAndChildAssociationSurvivesOwnDeviceWrapper():void
    {
        $header=ContentBuilder::build(['type'=>'raw','message'=>['albumMessage'=>['expectedImageCount'=>1,'expectedVideoCount'=>1]]],'456@lid');
        self::assertSame('text',$header['attrs']['type']);self::assertArrayNotHasKey('mediatype',$header['encAttrs']);
        $proto=['deviceSentMessage'=>['destinationJid'=>'456@lid','message'=>['imageMessage'=>['caption'=>'Second']]],'messageContextInfo'=>['messageAssociation'=>['associationType'=>'MEDIA_ALBUM','parentMessageKey'=>['id'=>'PARENT','remoteJid'=>'456@lid','fromMe'=>true],'messageIndex'=>1]]];
        $child=ContentDecoder::decode(SchemaCodec::encode('Message',$proto));
        self::assertSame('image',$child['kind']);self::assertSame('media_album',$child['association']['type']);
        self::assertSame('PARENT',$child['album']['parent']['id']);self::assertSame(1,$child['album']['index']);
    }

    public function testHydrationOverridesForgedSecretsAuthorsAndOriginalPollFields():void
    {
        $service=$this->service();$chat='456@lid';$id='PARENT';$secret=str_repeat('s',32);
        $this->store->set('message-secrets',hash('sha256',$chat."\0".$id),BinaryData::encode(['author'=>'111@s.whatsapp.net','secret'=>$secret]));
        $original=['pollCreationMessageV6'=>['name'=>'Original','options'=>[['optionName'=>'A'],['optionName'=>'B']],'allowAddOption'=>false]];
        $service->history()->store->upsertMessage(['id'=>$id,'chat'=>$chat,'fromMe'=>true,'timestamp'=>time(),'proto'=>$original]);
        $input=['type'=>'poll-add-option','optionName'=>'C','poll'=>['id'=>$id,'messageSecret'=>str_repeat('f',32),'authorJid'=>'999@lid','pollCreation'=>['name'=>'Forged','allowAddOption'=>true]]];
        $hydrated=(new \ReflectionMethod(MessageService::class,'hydrateAddon'))->invoke($service,$input,$chat,$this->credentials);
        self::assertSame($secret,$hydrated['poll']['messageSecret']);self::assertSame('111@s.whatsapp.net',$hydrated['poll']['authorJid']);
        self::assertSame($original,$hydrated['poll']['message']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not allow adding options');
        AdvancedContent::build($hydrated,$chat,['meJid'=>'111@s.whatsapp.net']);
    }

    public function testAuthenticatedIncomingVoteAndRsvpHaveNormalizedApiFields():void
    {
        $service=$this->service();$chat='456@lid';$secret=str_repeat('s',32);
        $this->store->set('message-secrets',hash('sha256',$chat."\0PARENT"),BinaryData::encode(['author'=>'111@s.whatsapp.net','secret'=>$secret]));
        $key=['remoteJid'=>$chat,'id'=>'PARENT','fromMe'=>false];
        foreach (['vote','rsvp'] as $kind) {
            $plain=$kind==='vote' ? SchemaCodec::encode('Message.PollVoteMessage',['selectedOptions'=>[hash('sha256','A',true)]]) : SchemaCodec::encode('Message.EventResponseMessage',['response'=>'MAYBE','timestampMs'=>123000,'extraGuestCount'=>2]);
            $enc=AddonCrypto::encrypt($secret,'PARENT','111@s.whatsapp.net',$chat,$kind==='vote' ? 'Poll Vote' : 'Event Response',$plain);
            $proto=$kind==='vote' ? ['pollUpdateMessage'=>['pollCreationMessageKey'=>$key,'vote'=>$enc]] : ['encEventResponseMessage'=>['eventCreationMessageKey'=>$key]+$enc];
            $decoded=ContentDecoder::decode(SchemaCodec::encode('Message',$proto));
            $node=new BinaryNode('message',['id'=>'INCOMING-'.$kind,'from'=>$chat,'type'=>$kind==='vote' ? 'poll' : 'event']);
            $result=(new \ReflectionMethod(MessageService::class,'incomingEvent'))->invoke($service,$node,Jid::parse($chat),Jid::parse($chat),$this->credentials,$decoded)['event'];
            self::assertFalse($result['encrypted']);self::assertSame('PARENT',$result['target']['id']);
            if ($kind==='vote') self::assertSame([hash('sha256','A')],$result['poll']['selectedOptionHashes']);
            else {self::assertSame('maybe',$result['event']['response']);self::assertSame(2,$result['event']['extraGuestCount']);}
        }
    }

    public function testSingleChoiceVoteUsesHydratedOriginalSelectionLimit():void
    {
        $service=$this->service();$chat='456@lid';$secret=str_repeat('s',32);
        $this->store->set('message-secrets',hash('sha256',$chat."\0PARENT"),BinaryData::encode(['author'=>'111@s.whatsapp.net','secret'=>$secret]));
        $service->history()->store->upsertMessage(['id'=>'PARENT','chat'=>$chat,'fromMe'=>true,'timestamp'=>time(),
            'proto'=>['pollCreationMessageV3'=>['name'=>'Choose one','options'=>[['optionName'=>'A'],['optionName'=>'B']],'selectableOptionsCount'=>1]]]);
        $input=['type'=>'poll-vote','poll'=>['id'=>'PARENT'],'selectedOptionNames'=>['A','B']];
        $hydrated=(new \ReflectionMethod(MessageService::class,'hydrateAddon'))->invoke($service,$input,$chat,$this->credentials);
        $this->expectException(\InvalidArgumentException::class);
        ContentBuilder::build($hydrated,$chat,['meJid'=>'111@s.whatsapp.net','outgoingId'=>'OUT']);
    }

    public function testStatusMusicAnnotationSurvivesAuthenticatedMediaUploadAndRefresh():void
    {
        $uploads=[];$httpCalls=0;$queries=0;
        $media=new MediaTransfer(static function() use(&$queries):BinaryNode {++$queries;return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('media_conn',['auth'=>'AUTH','ttl'=>'3600'],[new BinaryNode('host',['hostname'=>'mmg.whatsapp.net'])])]);},static function(array $request) use(&$uploads,&$httpCalls):array {
            if ($request['method']==='GET') return ['status'=>200,'body'=>$uploads[array_key_last($uploads)]];
            $uploads[]=$request['body'];++$httpCalls;
            return $httpCalls===1 ? ['status'=>403,'body'=>'expired'] : ['status'=>200,'body'=>'{"url":"https://mmg.whatsapp.net/media","direct_path":"/media"}'];
        });
        $service=$this->service($media);$bytes="image fixture\0bytes";
        $status=StatusService::prepareContent(['type'=>'image','media'=>['bytes'=>$bytes],'caption'=>'Status','musicAnnotation'=>['embeddedMusic'=>['songId'=>'SONG','title'=>'Title','author'=>'Artist']]]);
        self::assertArrayHasKey('statusMetadata',$status);
        $prepared=(new \ReflectionMethod(MessageService::class,'prepareContent'))->invoke($service,$status);
        $built=ContentBuilder::build($prepared,StatusStore::CHAT);
        $decoded=ContentDecoder::decode($built['bytes']);$image=$decoded['proto']['imageMessage'];
        self::assertSame('Status',$image['caption']);self::assertSame('SONG',$image['annotations'][0]['embeddedContent']['embeddedMusic']['songId']);
        self::assertSame(hash('sha256',$bytes,true),$image['fileSha256']);self::assertSame(32,strlen($image['mediaKey']));
        self::assertSame(2,$queries);self::assertSame($uploads[0],$uploads[1]);
        self::assertNotSame($bytes,$uploads[1]);self::assertSame($bytes,$media->download($decoded['media']));
    }

    public function testRealMessageServiceAlbumKeepsTimedOutChildOutboxId():void
    {
        $to='222@s.whatsapp.net';$peerStore=new SignalStore(':memory:');
        try {
            $peer=CredentialStore::fresh();$peer['registered']=true;$peer['meJid']=$to;
            $preKey=$peerStore->generatePreKeys(1)[0];
            (new SignalProtocol($this->store,$this->credentials))->ensureSession($to,['registrationId'=>$peer['registrationId'],'identityKey'=>$peer['identityKeyPair']['public'],
                'signedPreKey'=>['id'=>$peer['signedPreKey']['id'],'public'=>$peer['signedPreKey']['public'],'signature'=>$peer['signedPreKey']['signature']],
                'preKey'=>['id'=>$preKey['id'],'public'=>$preKey['public']]]);
            $messages=[];
            $service=$this->service(null,static function(BinaryNode $node) use($to,&$messages):BinaryNode {
                if ($node->tag==='iq' && ($node->attrs['xmlns'] ?? null)==='usync') return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('usync',[],[new BinaryNode('list',[],[
                    new BinaryNode('user',['jid'=>$to],[new BinaryNode('devices',[],[new BinaryNode('device-list',[],[new BinaryNode('device',['id'=>'0'])])])]),
                    new BinaryNode('user',['jid'=>'111@s.whatsapp.net'],[new BinaryNode('devices',[],[new BinaryNode('device-list',[],[new BinaryNode('device',['id'=>'5'])])])]),
                ])])]);
                if ($node->tag!=='message') throw new \LogicException('Unexpected network query');
                $messages[]=$node->attrs['id'];
                if (count($messages)===2) throw new \RuntimeException('ACK timeout');
                return new BinaryNode('ack',['id'=>$node->attrs['id'],'class'=>'message']);
            });
            $result=$service->sendAlbum($to,[['imageMessage'=>['url'=>'https://mmg.whatsapp.net/first']],['imageMessage'=>['url'=>'https://mmg.whatsapp.net/second']]]);
            self::assertCount(2,$messages);self::assertFalse($result['complete']);self::assertSame($messages[0],$result['parentKey']['id']);
            self::assertSame('unknown',$service->getMessageStatus($messages[1])['status']);
            self::assertSame($messages[1],$result['failure']['messageId']);
        } finally {$peerStore->close();}
    }

    public function testRealSignalTransportProjectsOwnPollChangesVotesRsvpAndCompleteAlbum():void
    {
        $server=new \MVO\Testing\FakeProtocol();$credentials=$this->credentials;unset($credentials['meLid']);
        $server->registerClient($credentials);$peer=$server->createPeer('222@s.whatsapp.net');$events=[];$sender=$credentials['meJid'];
        $service=new MessageService(new SignalProtocol($this->store,$credentials),$this->store,static fn():array=>$credentials,
            static function():void {},static fn(BinaryNode $node,float $timeout):BinaryNode=>$server->dispatch($node,$sender)[0],
            static function(BinaryNode $node)use($server,$sender):void{$server->dispatch($node,$sender);},
            static function(string $name,array $data)use(&$events):void{$events[]=[$name,$data];});
        try{
            $service->initialize();$to=$peer->jid;$now=(int)floor(microtime(true)*1000);
            $poll=$service->sendMessage($to,['type'=>'poll','name'=>'Original','options'=>['A','B'],'allowAddOption'=>true]);
            self::assertSame('server_ack',$poll['status']);
            $added=$service->sendMessage($to,['type'=>'poll-add-option','poll'=>['id'=>$poll['id']],'optionName'=>'C']);
            self::assertSame('server_ack',$added['status']);
            $voted=$service->sendMessage($to,['type'=>'poll-vote','poll'=>['id'=>$poll['id']],'selectedOptionNames'=>['C'],'senderTimestampMs'=>$now+100]);
            self::assertSame('server_ack',$voted['status']);
            $edited=$service->sendMessage($to,['type'=>'poll-edit','poll'=>['id'=>$poll['id']],'name'=>'Renamed','timestampMs'=>$now+200]);
            self::assertSame('server_ack',$edited['status']);
            $result=$service->interactions()->pollResults($to,$poll['id']);self::assertSame('Renamed',$result['name']);
            self::assertSame(['A','B','C'],array_column($result['options'],'name'));self::assertSame([0,0,1],array_column($result['options'],'votes'));self::assertSame(1,$result['totalVoters']);
            $event=$service->sendMessage($to,['type'=>'event','name'=>'Meeting','startTime'=>time()+3600]);
            $response=$service->sendMessage($to,['type'=>'event-response','event'=>['id'=>$event['id']],'response'=>'going','timestampMs'=>$now+300]);
            self::assertSame('server_ack',$response['status']);self::assertSame(1,$service->interactions()->eventResponses($to,$event['id'])['counts']['going']);
            $album=$service->sendAlbum($to,[['imageMessage'=>['url'=>'https://mmg.whatsapp.net/first','caption'=>'First']],['imageMessage'=>['url'=>'https://mmg.whatsapp.net/second','caption'=>'Second']]]);
            self::assertTrue($album['complete']);$receivedAlbums=array_values(array_filter($events,static fn(array $entry):bool=>$entry[0]==='album'));
            self::assertCount(1,$receivedAlbums);self::assertSame($album['parentKey']['id'],$receivedAlbums[0][1]['parentKey']['id']);
            self::assertTrue($receivedAlbums[0][1]['fromMe']);self::assertSame([0,1],array_column($receivedAlbums[0][1]['items'],'index'));
            self::assertSame(array_column($album['messages'],'id'),array_column($receivedAlbums[0][1]['items'],'messageId'));
            self::assertCount(9,$peer->messages(),'Each message crossed real Signal encryption and the fake transport');
            foreach($events as[$name,$data])if($name==='service_error')self::assertNotSame('history.interaction-projection',$data['operation']??null);
        }finally{$server->close();}
    }

    public function testLocationRequestUsesNativeMediaEnvelopeForEveryRealSignalDevice():void
    {
        $server=new \MVO\Testing\FakeProtocol();$credentials=$this->credentials;unset($credentials['meLid']);
        $server->registerClient($credentials);$sender=$credentials['meJid'];
        $recipients=[$server->createPeer('222@s.whatsapp.net'),$server->createPeer('222:2@s.whatsapp.net'),$server->createPeer('222:3@s.whatsapp.net')];
        $own=[$server->createPeer('111@s.whatsapp.net'),$server->createPeer('111:2@s.whatsapp.net')];$events=[];
        $service=new MessageService(new SignalProtocol($this->store,$credentials),$this->store,static fn():array=>$credentials,
            static function():void {},static fn(BinaryNode $node,float $timeout):BinaryNode=>$server->dispatch($node,$sender)[0],
            static function(BinaryNode $node)use($server,$sender):void{$server->dispatch($node,$sender);},
            static function(string $name,array $data)use(&$events):void{$events[]=[$name,$data];});
        try{
            $service->initialize();$to=$recipients[0]->jid;
            $result=$service->sendMessage($to,['type'=>'request-location','format'=>'protocol']);
            self::assertSame('server_ack',$result['status']);
            self::assertSame('server_ack',$service->getMessageStatus($result['id'])['status']);
            $sent=array_values(array_filter($server->captured(),static fn(array $entry):bool=>$entry['node']->tag==='message'));
            self::assertCount(1,$sent);$stanza=$sent[0]['node'];
            self::assertSame('media',$stanza->attrs['type']);
            self::assertSame($result['id'],$stanza->attrs['id']);
            $fanout=$stanza->child('participants')?->content;
            self::assertIsArray($fanout);self::assertCount(5,$fanout);
            $devices=[];
            foreach($fanout as $participant){
                self::assertSame('to',$participant->tag);$devices[]=$participant->attrs['jid'];
                $encrypted=$participant->child('enc');self::assertNotNull($encrypted);
                self::assertSame('request_location',$encrypted->attrs['mediatype']);
                self::assertContains($encrypted->attrs['type'],['pkmsg','msg']);
            }
            sort($devices);$expected=['222@s.whatsapp.net','222:2@s.whatsapp.net','222:3@s.whatsapp.net','111@s.whatsapp.net','111:2@s.whatsapp.net'];sort($expected);
            self::assertSame($expected,$devices);self::assertNotContains($sender,$devices);
            foreach($recipients as $peer){
                $received=$peer->messages();self::assertCount(1,$received);
                self::assertSame(['requestLocationMessage'=>[]],$received[0]['proto']);
                self::assertSame('c20800',bin2hex(SchemaCodec::encode('Message',$received[0]['proto'])));
                self::assertSame('request-location',$received[0]['type']);
            }
            foreach($own as $peer){
                $received=$peer->messages();self::assertCount(1,$received);
                self::assertSame(['deviceSentMessage'=>['destinationJid'=>$to,'message'=>['requestLocationMessage'=>[]]]],$received[0]['proto']);
                self::assertSame('request-location',$received[0]['type']);
            }
            $acknowledgements=array_values(array_filter($events,static fn(array $entry):bool=>$entry[0]==='message_ack'));
            self::assertCount(1,$acknowledgements);self::assertSame('server_ack',$acknowledgements[0][1]['status']);
            self::assertNotContains('message_receipt',array_column($events,0),'Queued delivery receipts are not consumed or invented by the server ACK');
        }finally{$server->close();}
    }

    public static function interactiveLocationRequests():iterable
    {
        yield 'default interactive'=>[['type'=>'request-location'],null];
        yield 'explicit interactive with custom text'=>[['type'=>'request-location','format'=>'interactive','text'=>'Envie sua localização para a entrega MVO.'],'Envie sua localização para a entrega MVO.'];
    }

    #[DataProvider('interactiveLocationRequests')]
    public function testInteractiveLocationRequestPreservesNativeActionAcrossAllSignalDevices(array $content,?string $expectedText):void
    {
        $server=new \MVO\Testing\FakeProtocol();$credentials=$this->credentials;unset($credentials['meLid']);
        $server->registerClient($credentials);$sender=$credentials['meJid'];
        $recipients=[$server->createPeer('222@s.whatsapp.net'),$server->createPeer('222:2@s.whatsapp.net'),$server->createPeer('222:3@s.whatsapp.net')];
        $own=[$server->createPeer('111@s.whatsapp.net'),$server->createPeer('111:2@s.whatsapp.net')];$events=[];
        $service=new MessageService(new SignalProtocol($this->store,$credentials),$this->store,static fn():array=>$credentials,
            static function():void {},static fn(BinaryNode $node,float $timeout):BinaryNode=>$server->dispatch($node,$sender)[0],
            static function(BinaryNode $node)use($server,$sender):void{$server->dispatch($node,$sender);},
            static function(string $name,array $data)use(&$events):void{$events[]=[$name,$data];});
        try{
            $service->initialize();$to=$recipients[0]->jid;$result=$service->sendMessage($to,$content);
            self::assertSame('server_ack',$result['status']);self::assertSame('server_ack',$service->getMessageStatus($result['id'])['status']);
            $sent=array_values(array_filter($server->captured(),static fn(array $entry):bool=>$entry['node']->tag==='message'));
            self::assertCount(1,$sent);$stanza=$sent[0]['node'];self::assertSame('text',$stanza->attrs['type']);self::assertSame($result['id'],$stanza->attrs['id']);
            $fanout=$stanza->child('participants')?->content;self::assertIsArray($fanout);self::assertCount(5,$fanout);$devices=[];
            foreach($fanout as $participant){
                self::assertSame('to',$participant->tag);$devices[]=$participant->attrs['jid'];$encrypted=$participant->child('enc');self::assertNotNull($encrypted);
                self::assertArrayNotHasKey('mediatype',$encrypted->attrs);self::assertContains($encrypted->attrs['type'],['pkmsg','msg']);
            }
            sort($devices);$expected=['222@s.whatsapp.net','222:2@s.whatsapp.net','222:3@s.whatsapp.net','111@s.whatsapp.net','111:2@s.whatsapp.net'];sort($expected);
            self::assertSame($expected,$devices);self::assertNotContains($sender,$devices);
            $business=array_values(array_filter($stanza->content,static fn(BinaryNode $node):bool=>$node->tag==='biz'));
            self::assertCount(1,$business);self::assertNull($business[0]->child('biz'),'The native action needs one business envelope, without a nested business wrapper');
            $interactive=$business[0]->child('interactive');self::assertNotNull($interactive);self::assertSame('native_flow',$interactive->attrs['type']);
            $flow=$interactive->child('native_flow');self::assertNotNull($flow);self::assertSame('location_request_message',$flow->attrs['name']);
            self::assertArrayNotHasKey('v',$flow->attrs,'The Android producer does not add a native-flow version to send_location');
            $peerProto=null;$secret=null;
            foreach($recipients as $peer){
                $received=$peer->messages();self::assertCount(1,$received);$proto=$received[0]['proto'];
                self::assertArrayNotHasKey('requestLocationMessage',$proto);self::assertSame('interactive',$received[0]['type']);
                self::assertSame([['name'=>'send_location','buttonParamsJson'=>'{}']],$proto['interactiveMessage']['nativeFlowMessage']['buttons']);
                $text=$proto['interactiveMessage']['body']['text'];self::assertIsString($text);self::assertNotSame('',trim($text));
                if($expectedText!==null)self::assertSame($expectedText,$text);
                self::assertSame(32,strlen($proto['messageContextInfo']['messageSecret']));
                if($peerProto===null){$peerProto=$proto;$secret=$proto['messageContextInfo']['messageSecret'];}
                else self::assertSame($peerProto,$proto,'All recipient devices decrypt the same authenticated native action');
            }
            self::assertNotNull($peerProto);self::assertNotNull($secret);
            foreach($own as $peer){
                $received=$peer->messages();self::assertCount(1,$received);$proto=$received[0]['proto'];
                self::assertSame($to,$proto['deviceSentMessage']['destinationJid']);self::assertSame($peerProto,$proto['deviceSentMessage']['message']);
                self::assertSame($secret,$proto['messageContextInfo']['messageSecret']);
                self::assertSame($secret,$proto['deviceSentMessage']['message']['messageContextInfo']['messageSecret']);
                self::assertSame('interactive',$received[0]['type']);
            }
            $acks=array_values(array_filter($events,static fn(array $entry):bool=>$entry[0]==='message_ack'));
            self::assertCount(1,$acks);self::assertSame('server_ack',$acks[0][1]['status']);
            self::assertNotContains('message_receipt',array_column($events,0),'Native-action support does not turn the server ACK into a delivery receipt');
        }finally{$server->close();}
    }

    private function service(?MediaTransfer $media=null,?\Closure $query=null):MessageService
    {
        return new MessageService(new SignalProtocol($this->store,$this->credentials),$this->store,fn():array=>$this->credentials,static function():void {},$query ?? static fn():BinaryNode=>throw new \LogicException('Unexpected transport'),static function():void {},static function():void {},$media);
    }
}
