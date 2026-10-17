<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Auth\CredentialStore;
use MVO\Message\MessageService;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Signal\{SignalProtocol,SignalStore};
use MVO\Testing\{FakeAppState,FakeProtocol};
use MVO\AppState\AppStateService;

final class FakeWhatsAppTest extends TestCase
{
    private const OWN='5511000000000:5@s.whatsapp.net';
    private const PEER='5522000000000@s.whatsapp.net';
    /** @return array{FakeProtocol,MessageService,SignalStore,array<string,mixed>} */
    private function setupProtocol(array &$events):array
    {
        $credentials=CredentialStore::fresh();$credentials['registered']=true;$credentials['meJid']=self::OWN;$credentials['signedIdentity']=['details'=>'explicit-test-registration','accountSignatureKey'=>random_bytes(32),'accountSignature'=>random_bytes(64),'deviceSignature'=>random_bytes(64)];
        $server=new FakeProtocol();$server->registerClient($credentials);$store=new SignalStore(':memory:','fake-client');$signal=new SignalProtocol($store,$credentials);
        $query=fn(BinaryNode $node,float $t):BinaryNode=>$server->dispatch($node,self::OWN)[0];
        $service=new MessageService($signal,$store,fn():array=>$credentials,function(array $next)use(&$credentials):void{$credentials=$next;},$query,function(BinaryNode $node)use($server):void{$server->dispatch($node,self::OWN);},function(string $event,array $data)use(&$events):void{$events[]=[$event,$data];});
        $service->initialize();return [$server,$service,$store,$credentials];
    }
    private function pump(FakeProtocol $server,MessageService $client):void {for($i=0;$i<20;$i++){$nodes=$server->drainOutbound(self::OWN);if($nodes===[])return;foreach($nodes as $node)$client->handleNode($node);}}
    public function testRealSignalFanoutReplyEditRevokeReactionAndReplay():void
    {
        $events=[];[$server,$client,$store]=$this->setupProtocol($events);$peer=$server->createPeer(self::PEER);$second=$server->createPeer('5522000000000:2@s.whatsapp.net');
        $sent=$client->sendText(self::PEER,'Signal fanout');self::assertSame('server_ack',$sent['status']);self::assertSame('Signal fanout',$peer->messages()[0]['text']);self::assertSame('Signal fanout',$second->messages()[0]['text']);
        $reply=$peer->sendConversation(self::OWN,'real encrypted reply');$this->pump($server,$client);self::assertContains('real encrypted reply',array_column(array_column($events,1),'text'));
        $count=count($events);$peer->replaySentMessage($reply);$this->pump($server,$client);self::assertCount($count,$events,'Exact replay is deduplicated before Signal decrypt');
        $peer->sendMessageEdit(self::OWN,$reply,'edited text');$peer->sendReaction(self::OWN,$sent['id'],'👍');$peer->sendMessageRevoke(self::OWN,$reply);$this->pump($server,$client);
        self::assertContains('message_protocol',array_column($events,0));self::assertContains(14,array_column(array_column(array_column($events,1),'protocolMessage'),'type'));self::assertContains(0,array_column(array_column(array_column($events,1),'protocolMessage'),'type'));self::assertContains('reaction',array_column(array_column($events,1),'type'));
        $server->close();$store->close();
    }
    public function testRealSenderKeyGroupRoundTrip():void
    {
        $events=[];[$server,$client,$store]=$this->setupProtocol($events);$peer=$server->createPeer(self::PEER);$companion=$server->createPeer('5522000000000:2@s.whatsapp.net');$server->createGroup('120363000000000001@g.us','Offline group',[self::OWN,self::PEER]);
        $sent=$client->sendText('120363000000000001@g.us','sender-key plaintext');self::assertSame('server_ack',$sent['status']);self::assertSame('sender-key plaintext',$peer->messages()[0]['text']);
        $peer->sendConversation('120363000000000001@g.us','group encrypted reply');$this->pump($server,$client);self::assertContains('group encrypted reply',array_column(array_column($events,1),'text'));
        $companion->sendConversation('120363000000000001@g.us','companion sender-key reply');$this->pump($server,$client);self::assertContains('companion sender-key reply',array_column(array_column($events,1),'text'));
        $server->close();$store->close();
    }
    public function testRetryConsumesFreshPrekeyAndDoesNotDeliverTwice():void
    {
        $events=[];[$server,$client,$store]=$this->setupProtocol($events);$peer=$server->createPeer(self::PEER);$sent=$client->sendText(self::PEER,'Retry me');$server->drainOutbound(self::OWN);$peer->sendRetryReceipt(self::OWN,$sent['id']);$this->pump($server,$client);$client->drainPending();$this->pump($server,$client);
        self::assertCount(1,$peer->messages());self::assertContains('message_retry',array_column($events,0));$server->close();$store->close();
    }
    public function testAppStateAuthenticatesPatchesAndRejectsCorruption():void
    {
        $server=new FakeAppState();$store=new SignalStore(':memory:','appstate-client');$client=new AppStateService($store,fn(BinaryNode $node,float $t):BinaryNode=>$server->handle($node));$client->importKeys([$server->key()]);
        $server->appendMutation('Star',['remote'=>self::PEER,'id'=>'FAKE-MESSAGE','fromMe'=>false],['starred'=>true]);$result=$client->sync(['regular_high']);self::assertSame('success',$result['collections'][0]['state']);self::assertSame(1,$client->collectionState('regular_high')['version']);
        $client->setMessageStar(['remoteJid'=>self::PEER,'id'=>'FAKE-MESSAGE'],false);self::assertSame(2,$server->service()->collectionState('regular_high')['version']);
        $snapshot=$server->snapshot('regular_high');$snapshot['mac'][0]=chr(ord($snapshot['mac'][0])^1);try{$client->applySnapshot('regular_high',$snapshot);self::fail('Corrupt snapshot accepted');}catch(\UnexpectedValueException $expected){self::assertStringContainsString('MAC',$expected->getMessage());}
        $server->close();$store->close();
    }
    public function testUnknownIqAndInvalidKeyUploadCannotBecomeSuccess():void
    {
        $events=[];[$server,$client,$store]=$this->setupProtocol($events);$unknown=$server->dispatch(new BinaryNode('iq',['id'=>'unknown','type'=>'set','xmlns'=>'unknown:production-route']),self::OWN)[0];self::assertSame('501',$unknown->child('error')->attrs['code']);
        $bad=$server->dispatch(new BinaryNode('iq',['xmlns'=>'encrypt','type'=>'set'],[new BinaryNode('identity',[],random_bytes(32)),new BinaryNode('registration',[],pack('N',1)),new BinaryNode('type',[],"\x05"),new BinaryNode('list')]),self::OWN)[0];self::assertSame('409',$bad->child('error')->attrs['code']);
        self::assertTrue($client->passiveTasks()->validateDigest()['valid']);$server->close();$store->close();
    }
    public function testEncryptedOwnPrimaryHistoryAndKeyShareReachProductionHandlers():void
    {
        $events=[];[$server,$client,$store]=$this->setupProtocol($events);$primary=$server->createPeer('5511000000000@s.whatsapp.net');$appstate=new FakeAppState();
        $primary->sendHistorySync(self::OWN,SchemaCodec::encode('HistorySync',['syncType'=>3,'conversations'=>[['id'=>self::PEER,'messages'=>[['message'=>['key'=>['remoteJid'=>self::PEER,'id'=>'HISTORY-1','fromMe'=>false],'message'=>['conversation'=>'synced history text'],'messageTimestamp'=>1234]]]]]]));
        $primary->sendAppStateSyncKeyShare(self::OWN,$appstate->keyShare());$this->pump($server,$client);$client->drainPending();
        self::assertContains('history_sync_chunk',array_column($events,0));self::assertSame('synced history text',$client->history()->store->getMessage(self::PEER,'HISTORY-1')['text']);
        $ownProtocol=array_values(array_filter(array_column($events,1),fn(array $e):bool=>($e['protocolMessage']['type'] ?? null)===6));self::assertTrue($ownProtocol[0]['authenticatedOwnDevice']);self::assertSame($appstate->key()['keyData'],$ownProtocol[0]['protocolMessage']['appStateSyncKeyShare']['keys'][0]['keyData']['keyData']);
        $appstate->close();$server->close();$store->close();
    }
    public function testGroupCannotFanoutToThirdPartyAndCaptureBytesAreBounded():void
    {
        $server=new FakeProtocol(maxCapturedBytes:1024);$sender=$server->createPeer('5511000000000@s.whatsapp.net');$member=$server->createPeer(self::PEER);$third=$server->createPeer('5533000000000@s.whatsapp.net');$server->createGroup('120363000000000001@g.us','Group',[$sender->jid,$member->jid]);
        try {$server->routeMessage($sender->jid,new BinaryNode('message',['to'=>'120363000000000001@g.us','id'=>'BAD'],[new BinaryNode('participants',[],[new BinaryNode('to',['jid'=>$third->jid],[new BinaryNode('enc',['type'=>'pkmsg'],random_bytes(200))])])]));self::fail('Third party group fanout accepted');}catch(\InvalidArgumentException $expected){self::assertStringContainsString('non-member',$expected->getMessage());}
        for($i=0;$i<10;$i++)$server->dispatch(new BinaryNode('iq',['xmlns'=>'unknown','id'=>(string)$i],[new BinaryNode('body',[],random_bytes(400))]),$sender->jid);self::assertLessThanOrEqual(1024,$server->captureInfo()['bytes']);self::assertGreaterThan(0,$server->captureInfo()['dropped']);self::assertLessThan(10,count($server->captured()));$server->close();
    }
    public function testAppStateConflictRebasesAndRemovalMatchesAuthenticatedSnapshot():void
    {
        $server=new FakeAppState();$store=new SignalStore(':memory:','appstate-conflict');$conflicted=false;$client=new AppStateService($store,function(BinaryNode $node,float $timeout)use($server,&$conflicted):BinaryNode {foreach(is_array($node->child('sync')?->content)?$node->child('sync')->content:[] as $collection)if($collection->child('patch')!==null&&!$conflicted){$conflicted=true;$server->appendMutation('Star',['remote'=>self::PEER,'id'=>'RACING','fromMe'=>false],['starred'=>true]);}return $server->handle($node);});$client->importKeys([$server->key()]);$client->setMessageStar(['remoteJid'=>self::PEER,'id'=>'LOCAL'],true);self::assertTrue($conflicted);self::assertSame([],$client->pendingMutations());self::assertSame(2,$server->service()->collectionState('regular_high')['version']);
        $server->appendMutation('Star',['remote'=>self::PEER,'id'=>'RACING','fromMe'=>false],null,true);$result=$client->sync(['regular_high']);self::assertSame('success',$result['collections'][0]['state']);self::assertSame(3,$client->collectionState('regular_high')['version']);$snapshot=$server->snapshot('regular_high');self::assertCount(1,$snapshot['records']);$client->applySnapshot('regular_high',$snapshot);self::assertSame($server->service()->collectionState('regular_high')['hash'],$client->collectionState('regular_high')['hash']);$server->close();$store->close();
    }
}
