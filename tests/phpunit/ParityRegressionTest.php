<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\AppState\{AppStateProjector,AppStateSchema,AppStateService};
use MVO\History\HistoryStore;
use MVO\Message\MessageCodec;
use MVO\Protocol\{BinaryCodec,BinaryNode};
use MVO\Service\{IncomingEvents,NewsletterInbox};
use MVO\Signal\SignalStore;

final class ParityRegressionTest extends TestCase
{
    private string $path;
    private SignalStore $store;
    protected function setUp():void { $this->path=tempnam(sys_get_temp_dir(),'mvo-parity-'); $this->store=new SignalStore($this->path); }
    protected function tearDown():void { $this->store->close(); foreach([$this->path,$this->path.'-wal',$this->path.'-shm'] as $file)if(is_file($file))unlink($file); }

    public function testChannelCallbacksResumeFromFailedEventAfterProcessRestart():void
    {
        $node=new BinaryNode('message',['from'=>'123@newsletter','id'=>'message-id','server_id'=>'10','t'=>'100'],[
            new BinaryNode('plaintext',[],MessageCodec::text('Persistente'))]);
        $messages=0; $newsletter=0;
        $inbox=new NewsletterInbox($this->store,new HistoryStore($this->store),function(string $event)use(&$messages):void {
            if ($event==='message') ++$messages;
            if ($event==='newsletter_message') throw new \RuntimeException('application unavailable');
        });
        try { $inbox->accept($node,IncomingEvents::parse($node)); self::fail('Expected failed callback'); }
        catch(\RuntimeException $error) { self::assertSame('application unavailable',$error->getMessage()); }
        self::assertSame('Persistente',(new HistoryStore($this->store))->getMessage('123@newsletter','message-id')['text']);
        self::assertCount(1,$this->store->entries('newsletter-inbox-pending'));
        $this->store->close(); $this->store=new SignalStore($this->path);
        $inbox=new NewsletterInbox($this->store,new HistoryStore($this->store),function(string $event,array $payload)use(&$messages,&$newsletter,$node):void {
            if ($event==='message') ++$messages;
            if ($event==='newsletter_message') {
                ++$newsletter; self::assertTrue($payload['replayed']);
                self::assertSame((new BinaryCodec())->encode($node),(new BinaryCodec())->encode($payload['rawNode']));
            }
        });
        $inbox->drainPending(); $inbox->accept($node,IncomingEvents::parse($node));
        self::assertSame(1,$messages); self::assertSame(1,$newsletter); self::assertSame([],$this->store->entries('newsletter-inbox-pending'));
    }

    public function testChannelEditRevokeCountersAndReactionUpdatesPersistByServerIdentity():void
    {
        $history=new HistoryStore($this->store); $events=[];
        $inbox=new NewsletterInbox($this->store,$history,function(string $event)use(&$events):void{$events[]=$event;});
        $node=new BinaryNode('message',['from'=>'123@newsletter','id'=>'original-id','server_id'=>'10','t'=>'100'],[new BinaryNode('plaintext',[],MessageCodec::text('Original'))]);
        $inbox->accept($node,IncomingEvents::parse($node));
        $edit=new BinaryNode('message',['from'=>'123@newsletter','id'=>'edit-id','server_id'=>'10','t'=>'101','edit'=>'3'],[new BinaryNode('plaintext',[],MessageCodec::text('Editada'))]);
        $inbox->accept($edit,IncomingEvents::parse($edit)); self::assertSame('Editada',$history->getMessage('123@newsletter','original-id')['text']);
        $counters=new BinaryNode('message',['from'=>'123@newsletter','id'=>'counter-id','server_id'=>'10','t'=>'102'],[new BinaryNode('views_count',['count'=>'20'])]);
        $inbox->accept($counters,IncomingEvents::parse($counters)); self::assertSame(20,$history->getMessage('123@newsletter','original-id')['views']);
        $reaction=new BinaryNode('message',['from'=>'123@newsletter','id'=>'reaction-id','server_id'=>'10','t'=>'103'],[new BinaryNode('reaction',['code'=>'👍','count'=>'2'])]);
        $inbox->accept($reaction,IncomingEvents::parse($reaction)); self::assertSame([['code'=>'👍','count'=>2]],$history->getMessage('123@newsletter','original-id')['reactions']);
        $revoke=new BinaryNode('message',['from'=>'123@newsletter','id'=>'revoke-id','server_id'=>'10','t'=>'104','edit'=>'8']);
        $inbox->accept($revoke,IncomingEvents::parse($revoke)); self::assertTrue($history->getMessage('123@newsletter','original-id')['revoked']);
        self::assertNull($history->getMessage('123@newsletter','original-id')['text']); self::assertSame([],$history->getMessage('123@newsletter','original-id')['proto']);
        $inbox->accept($edit,IncomingEvents::parse($edit)); self::assertNull($history->getMessage('123@newsletter','original-id')['text']);
        self::assertCount(6,$events);
    }

    public function testChannelUpdateBeforeOriginalDoesNotResurrectOldBodyOrDuplicateRecord():void
    {
        $history=new HistoryStore($this->store); $inbox=new NewsletterInbox($this->store,$history,static function():void{});
        $edit=new BinaryNode('message',['from'=>'123@newsletter','id'=>'edit-id','server_id'=>'10','t'=>'101','edit'=>'3'],[new BinaryNode('plaintext',[],MessageCodec::text('Editada'))]);
        $inbox->accept($edit,IncomingEvents::parse($edit));
        $original=new BinaryNode('message',['from'=>'123@newsletter','id'=>'original-id','server_id'=>'10','t'=>'100'],[new BinaryNode('plaintext',[],MessageCodec::text('Original'))]);
        $inbox->accept($original,IncomingEvents::parse($original));
        self::assertCount(1,$history->listMessages('123@newsletter')); self::assertSame('Editada',$history->getMessage('123@newsletter','original-id')['text']);
        $sameUpdate=new BinaryNode('message',['from'=>'123@newsletter','id'=>'different-wrapper-id','server_id'=>'10','t'=>'101','edit'=>'3'],$edit->content);
        $inbox->accept($sameUpdate,IncomingEvents::parse($sameUpdate)); self::assertCount(2,$this->store->entries('newsletter-inbox-done'));
    }

    public function testExplicitAppStateMessageRangeDeletesItsTargetWithoutTimestampCutoff():void
    {
        $history=new HistoryStore($this->store); $chat='123@s.whatsapp.net';
        $history->upsertMessage(['chat'=>$chat,'id'=>'delete','timestamp'=>200,'fromMe'=>false]);
        $history->upsertMessage(['chat'=>$chat,'id'=>'keep','timestamp'=>300,'fromMe'=>false]);
        $projector=new AppStateProjector($this->store,$history);
        $mutation=AppStateSchema::mutation('ClearChat',['chatJid'=>$chat,'deleteStarred'=>'1','deleteMedia'=>'0'],[
            'messageRange'=>['messages'=>[['key'=>['remoteJid'=>$chat,'id'=>'delete','fromMe'=>false],'timestamp'=>200]]]],10);
        $projector->apply($mutation);
        self::assertNull($history->getMessage($chat,'delete')); self::assertNotNull($history->getMessage($chat,'keep'));
    }

    public function testFailedCollectionBootstrapRemainsScheduledForRetry():void
    {
        $service=new AppStateService($this->store,static fn():BinaryNode=>new BinaryNode('iq',['type'=>'result'],[new BinaryNode('sync')]));
        $service->requestSync(); $result=$service->sync(['regular']);
        self::assertSame('error_retry',$result['collections'][0]['state']); self::assertTrue($service->needsSync());
    }

    public function testMobileTcpDisconnectsAreTransientAndConfigurationFailuresAreFatal():void
    {
        $dir=$this->path.'-auth'; $client=new \MVO\WaClient(authDirectory:$dir);
        try {
            $method=new \ReflectionMethod($client,'isTransient');
            foreach (['Mobile TCP connection failed: reset','Mobile TCP socket closed','Mobile TCP read failed or connection closed',
                'Mobile TCP write timed out','Mobile TCP write failed','Mobile TCP select failed'] as $message) self::assertTrue($method->invoke($client,new \RuntimeException($message)));
            self::assertFalse($method->invoke($client,new \InvalidArgumentException('Expected a tcp://host:port mobile endpoint')));
            self::assertFalse($method->invoke($client,new \RuntimeException('Mobile proxy CONNECT was refused')));
        } finally { $client->stop(); unset($client); rmdir($dir.'/default'); rmdir($dir); }
    }

    public function testChannelCallbackCanPumpDuplicateStanzaWithoutRecursiveDelivery():void
    {
        $node=new BinaryNode('message',['from'=>'123@newsletter','id'=>'id','server_id'=>'10'],[new BinaryNode('plaintext',[],MessageCodec::text('Olá'))]);
        $inbox=null; $events=[];
        $inbox=new NewsletterInbox($this->store,new HistoryStore($this->store),function(string $event)use(&$inbox,&$events,$node):void {
            $events[]=$event;
            if ($event==='message') { $inbox->accept($node,IncomingEvents::parse($node)); $inbox->drainPending(); }
        });
        $inbox->accept($node,IncomingEvents::parse($node));
        self::assertSame(['message','newsletter_message'],$events); self::assertSame([],$this->store->entries('newsletter-inbox-pending'));
    }

    public function testDirectMessageCallbackCanPumpDuplicateStanzaWithoutRecursiveDelivery():void
    {
        $credentials=\MVO\Auth\CredentialStore::fresh(); $credentials['registered']=true; $credentials['meJid']='100:5@s.whatsapp.net'; $credentials['serverHasPreKeys']=true;
        $peerCredentials=\MVO\Auth\CredentialStore::fresh(); $peerCredentials['registered']=true; $peerCredentials['meJid']='200@s.whatsapp.net';
        $key=$this->store->generatePreKeys(1)[0]; $peerStore=new SignalStore(':memory:');
        try {
            $peer=new \MVO\Signal\SignalProtocol($peerStore,$peerCredentials);
            $peer->ensureSession($credentials['meJid'],['registrationId'=>$credentials['registrationId'],'identityKey'=>$credentials['identityKeyPair']['public'],
                'signedPreKey'=>['id'=>$credentials['signedPreKey']['id'],'public'=>$credentials['signedPreKey']['public'],'signature'=>$credentials['signedPreKey']['signature']],
                'preKey'=>['id'=>$key['id'],'public'=>$key['public']]]);
            $encrypted=$peer->encrypt($credentials['meJid'],MessageCodec::pad(MessageCodec::text('Olá')));
            $node=new BinaryNode('message',['from'=>$peerCredentials['meJid'],'id'=>'duplicate-during-callback'],[new BinaryNode('enc',['type'=>$encrypted['type']],$encrypted['ciphertext'])]);
            $service=null; $calls=0;
            $service=new \MVO\Message\MessageService(new \MVO\Signal\SignalProtocol($this->store,$credentials),$this->store,
                static fn():array=>$credentials,static function():void{},static fn():BinaryNode=>throw new \LogicException('No query'),static function():void{},
                function(string $event)use(&$service,$node,&$calls):void { if($event==='message' && ++$calls===1)$service->handleNode($node); });
            $service->handleNode($node); self::assertSame(1,$calls);
        } finally { $peerStore->close(); }
    }

    public function testRootProtocolIntegrationAcceptsAuthenticatedWrappedOwnKeyShare():void
    {
        $keyId=pack('nN',0,1); $keyData=random_bytes(32);
        $protocol=['type'=>6,'appStateSyncKeyShare'=>['keys'=>[['keyId'=>['keyId'=>$keyId],'keyData'=>['keyData'=>$keyData,'timestamp'=>0]]]]];
        $service=new AppStateService($this->store,static fn():BinaryNode=>throw new \LogicException('No query'),ownDevice:static fn(string $sender):bool=>$sender==='100:2@s.whatsapp.net');
        $dir=$this->path.'-wrapped-auth'; $client=new \MVO\WaClient(authDirectory:$dir);
        try {
            (new \ReflectionProperty($client,'appState'))->setValue($client,$service);
            (new \ReflectionMethod($client,'messageEvent'))->invoke($client,'message_protocol',[
                'authenticatedOwnDevice'=>true,'authenticatedSender'=>'100:2@s.whatsapp.net','protocolMessage'=>$protocol,
                'proto'=>['deviceSentMessage'=>['destinationJid'=>'100@s.whatsapp.net','message'=>['protocolMessage'=>$protocol]]],
            ]);
            $saved=$service->getKey($keyId); self::assertNotNull($saved); self::assertSame($keyData,$saved['keyData']);
        } finally { $client->stop(); unset($client); rmdir($dir.'/default'); rmdir($dir); }
    }
}
