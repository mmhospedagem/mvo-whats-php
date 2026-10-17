<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Media\MediaTransfer;
use MVO\Message\{ContentBuilder,ContentDecoder,MessageService,ScheduledCallContent};
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Signal\{SignalProtocol,SignalStore};
use MVO\Testing\FakeProtocol;
use PHPUnit\Framework\TestCase;

final class AdvancedExtensionsTransportTest extends TestCase
{
    private SignalStore $store;
    private FakeProtocol $server;
    private array $credentials;
    private array $events=[];
    protected function setUp():void
    {
        $this->store=new SignalStore(':memory:');$this->server=new FakeProtocol();$this->credentials=CredentialStore::fresh();$this->credentials['registered']=true;$this->credentials['meJid']='111:5@s.whatsapp.net';$this->credentials['serverHasPreKeys']=true;
        $this->credentials['signedIdentity']=['details'=>'fixture','accountSignatureKey'=>random_bytes(32),'accountSignature'=>random_bytes(64),'deviceSignature'=>random_bytes(64)];$this->server->registerClient($this->credentials);
    }
    protected function tearDown():void{$this->server->close();$this->store->close();}
    private function service(?MediaTransfer $media=null):MessageService
    {
        $sender=$this->credentials['meJid'];return new MessageService(new SignalProtocol($this->store,$this->credentials),$this->store,fn():array=>$this->credentials,static function():void{},fn(BinaryNode $node,float $timeout):BinaryNode=>$this->server->dispatch($node,$sender)[0],function(BinaryNode $node)use($sender):void{$this->server->dispatch($node,$sender);},function(string $name,array $data):void{$this->events[]=[$name,$data];},$media);
    }
    public function testScheduledCallAndAuthorizedCancellationCrossRealSignalAndKeepOwnCopies():void
    {
        $peer=$this->server->createPeer('222@s.whatsapp.net');$own=$this->server->createPeer('111@s.whatsapp.net');$service=$this->service();$service->initialize();$when=time()*1000+3600000;
        $invite=$service->sendMessage($peer->jid,['type'=>'scheduled-call','title'=>'Reunião MVO','scheduledTimestampMs'=>$when,'callType'=>'video']);
        $cancel=$service->sendMessage($peer->jid,['type'=>'scheduled-call-cancel','target'=>['id'=>$invite['id']]]);self::assertSame('server_ack',$invite['status']);self::assertSame('server_ack',$cancel['status']);
        $captured=array_values(array_filter($this->server->captured(),static fn(array $row):bool=>$row['node']->tag==='message'));self::assertCount(2,$captured);
        foreach($captured as$index=>$row){self::assertSame('media',$row['node']->attrs['type']);foreach($row['node']->child('participants')->content as$device)self::assertSame($index===0?'scheduled_call':'scheduled_call_edit',$device->child('enc')->attrs['mediatype']);}
        foreach([$peer,$own]as$device){$messages=$device->messages();self::assertCount(2,$messages);$byType=[];foreach($messages as$message)$byType[$message['type']]=$message;
            $created=ContentDecoder::decode(SchemaCodec::encode('Message',$byType['scheduled-call']['proto']));self::assertSame('video',$created['scheduledCall']['callType']);self::assertSame($when,$created['scheduledCall']['scheduledTimestampMs']);
            $removed=ContentDecoder::decode(SchemaCodec::encode('Message',$byType['scheduled-call-cancel']['proto']));self::assertSame($invite['id'],$removed['scheduledCall']['target']['id']);self::assertSame('cancel',$removed['scheduledCall']['action']);
            if($device===$own)self::assertSame($peer->jid,$removed['destination']);
        }
        self::assertNotContains('message_receipt',array_column($this->events,0),'Server ACK is not a delivered/read receipt');
    }
    public function testCancellationRejectsUnknownForeignAndWrongMessageParentsBeforeMessageSubmission():void
    {
        $this->server->createPeer('222@s.whatsapp.net');$service=$this->service();$service->initialize();
        foreach([['id'=>'MISSING'],['id'=>'FOREIGN'],['id'=>'TEXT']]as$target){
            if($target['id']!=='MISSING')$service->history()->store->upsertMessage(['chat'=>'222@s.whatsapp.net','id'=>$target['id'],'fromMe'=>$target['id']==='TEXT','from'=>$target['id']==='TEXT'?'111@s.whatsapp.net':'222@s.whatsapp.net','proto'=>$target['id']==='TEXT'?['conversation'=>'ordinary']:['scheduledCallCreationMessage'=>['title'=>'Foreign','scheduledTimestampMs'=>time()*1000+3600000]]]);
            try{$service->sendMessage('222@s.whatsapp.net',['type'=>'scheduled-call-cancel','target'=>$target]);self::fail('Accepted unauthorized cancellation');}catch(\InvalidArgumentException $error){self::assertStringContainsString('known scheduled call',$error->getMessage());}
        }
        self::assertCount(0,array_filter($this->server->captured(),static fn(array $row):bool=>$row['node']->tag==='message'));
    }
    public function testEventCoverUsesAuthenticatedUploadAndRealAssociatedSignalMessage():void
    {
        $peer=$this->server->createPeer('222@s.whatsapp.net');$own=$this->server->createPeer('111@s.whatsapp.net');$uploaded=[];
        $media=new MediaTransfer(static fn():BinaryNode=>new BinaryNode('iq',['type'=>'result'],[new BinaryNode('media_conn',['auth'=>'fixture','ttl'=>'3600'],[new BinaryNode('host',['hostname'=>'mmg.whatsapp.net'])])]),static function(array $request)use(&$uploaded):array{$uploaded[]=$request['body'];return ['status'=>200,'body'=>'{"url":"https://mmg.whatsapp.net/cover","direct_path":"/cover"}'];});
        $service=$this->service($media);$service->initialize();$plain=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jVZkAAAAASUVORK5CYII=',true);
        $result=$service->sendMessage($peer->jid,['type'=>'event','name'=>'MVO','startTime'=>time()+3600,'cover'=>['media'=>['bytes'=>$plain],'mimetype'=>'image/png']]);self::assertTrue($result['complete']);self::assertCount(1,$uploaded);self::assertNotSame($plain,$uploaded[0]);
        foreach([$peer,$own]as$device){$messages=$device->messages();self::assertCount(2,$messages);$cover=null;foreach($messages as$message){$decoded=ContentDecoder::decode(SchemaCodec::encode('Message',$message['proto']));if(isset($decoded['eventCover']))$cover=$decoded;}
            self::assertNotNull($cover);self::assertEquals($result['parentKey'],$cover['eventCover']['parent']);self::assertSame(hash('sha256',$plain,true),$cover['data']['fileSha256']);self::assertSame(32,strlen($cover['data']['mediaKey']));self::assertContains('eventCoverImage',$cover['wrappers']);
        }
    }
    public function testPastScheduledCallAndPureUnauthorizedCancelAreRejected():void
    {
        foreach([['type'=>'scheduled-call','title'=>'Past','scheduledTimestampMs'=>0,'callType'=>'voice'],['type'=>'scheduled-call-cancel','target'=>['id'=>'ID']]]as$content){try{ContentBuilder::build($content,'222@lid');self::fail('Accepted invalid scheduled call');}catch(\InvalidArgumentException){self::assertTrue(true);}}
        ScheduledCallContent::validate(['type'=>'scheduled-call-cancel','target'=>['id'=>'ID']]);
    }
}
