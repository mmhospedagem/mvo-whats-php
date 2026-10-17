<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Auth\CredentialStore;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Transport\{NodeSession,ConnectionFailure};
use MVO\WaClient;

final class WaClientFeaturesTest extends TestCase
{
    private string $dir;
    private WaClient $client;
    private FeaturesSession $peer;
    protected function setUp():void {
        $this->dir=sys_get_temp_dir().'/mvo-features-'.bin2hex(random_bytes(6));
        $creds=CredentialStore::fresh(); $creds['registered']=true; $creds['meJid']='5511999999999:52@s.whatsapp.net'; $creds['serverHasPreKeys']=true;
        $store=new CredentialStore($this->dir); $store->save($creds); $store->close();
        $this->peer=new FeaturesSession(); $this->client=new WaClient(authDirectory:$this->dir,sessionFactory:fn()=>$this->peer,automaticMaintenance:false);
        $this->client->connect(); $this->peer->incoming[]=new BinaryNode('success'); $this->client->poll(0);
    }
    protected function tearDown():void {
        $this->client->stop(); unset($this->client); gc_collect_cycles();
        foreach (glob($this->dir.'/*/*') ?: [] as $file) unlink($file); rmdir($this->dir.'/default'); rmdir($this->dir);
    }
    public function testIncomingCallRoutesWithoutAutoAcceptAndClosesOnDisconnect():void {
        $incoming=[]; $states=[];
        $this->client->on('voip_incoming_call',static function(array $event) use(&$incoming):void { $incoming[]=$event; });
        $this->client->on('voip_call_state',static function(array $event) use(&$states):void { $states[]=$event; });
        $service=$this->client->voip(); self::assertSame($service,$this->client->voip());
        // A group offer is recognized; preaccept announces ringing without accepting or opening media.
        $this->peer->incoming[]=new BinaryNode('call',['id'=>'group-offer','from'=>'5511888888888:1@s.whatsapp.net'],[
            new BinaryNode('offer',['call-id'=>'group-call','call-creator'=>'5511888888888:1@s.whatsapp.net','group-jid'=>'123@g.us'])
        ]);
        $this->client->poll(0);
        self::assertCount(1,$incoming); self::assertSame('ringing',$service->getCall('group-call')['state']);
        $acks=array_values(array_filter($this->peer->sent,static fn(BinaryNode $node):bool=>$node->tag==='ack' && ($node->attrs['class'] ?? '')==='call'));
        self::assertCount(1,$acks); self::assertSame('group-offer',$acks[0]->attrs['id']);
        $callReplies=array_values(array_filter($this->peer->sent,static fn(BinaryNode $node):bool=>$node->tag==='call'));
        self::assertCount(1,$callReplies);self::assertSame('preaccept',$callReplies[0]->content[0]->tag);self::assertSame('group-call@call',$callReplies[0]->attrs['to']);self::assertNull($callReplies[0]->child('accept'));
        $this->client->stop();
        self::assertSame('ended',$service->getCall('group-call')['state']); self::assertSame('connection_closed',$states[array_key_last($states)]['endReason']);
        self::assertFalse($this->client->isRunning());
    }
    public function testServicesReuseConnectionAndInboxChannelMessagesSurviveRestart():void {
        self::assertTrue($this->client->isConnected()); self::assertSame($this->client,$this->client->lowlevel());
        $this->client->presence()->sendChatstate('5511888888888@s.whatsapp.net','composing','audio');
        self::assertSame('chatstate',$this->peer->sent[array_key_last($this->peer->sent)]->tag);
        $events=[]; $this->client->on('message',function(array $e) use(&$events):void { $events[]=$e; });
        $post=new BinaryNode('message',['id'=>'post-1','from'=>'123@newsletter','server_id'=>'8','t'=>'1700000000'],[new BinaryNode('plaintext',[],SchemaCodec::encode('Message',['extendedTextMessage'=>['text'=>'Canal PHP']]))]);
        $this->peer->incoming[]=$post; $this->client->poll(0); $this->peer->incoming[]=$post; $this->client->poll(0);
        self::assertCount(1,$events); self::assertSame('Canal PHP',$this->client->history()->getMessage('123@newsletter','post-1')['text']);
        self::assertSame('message',$this->peer->sent[array_key_last($this->peer->sent)]->attrs['class']);
        $this->client->stop(); $this->client->connect(); $this->peer->incoming[]=new BinaryNode('success'); $this->client->poll(0);
        self::assertSame('Canal PHP',$this->client->history()->getMessage('123@newsletter','post-1')['text']);
    }
    public function testFilteringAcksApplicationTrafficButCannotDropPeerProtocol():void {
        $this->client->ignoreKey(['id'=>'ignore']);
        $this->peer->incoming[]=new BinaryNode('message',['id'=>'ignore','from'=>'5511888888888@s.whatsapp.net']); $this->client->poll(0);
        self::assertSame('ack',$this->peer->sent[array_key_last($this->peer->sent)]->tag);
        $seen=[]; $remove=$this->client->registerIncomingHandler(function(BinaryNode $node) use(&$seen):bool { if (($node->attrs['category'] ?? '')==='peer') { $seen[]=$node; return true; } return false; });
        $this->peer->incoming[]=new BinaryNode('message',['id'=>'ignore','category'=>'peer','from'=>'5511999999999@s.whatsapp.net']); $this->client->poll(0);
        self::assertCount(1,$seen); $remove();
    }
    public function testOffAlsoUnsubscribesOnceUsingOriginalHandler():void {
        $calls=0; $handler=function() use(&$calls):void { ++$calls; }; $this->client->once('x',$handler)->off('x',$handler); $this->client->emitEvent('x'); self::assertSame(0,$calls);
    }
    public function testLogoutRemovesCompanionAndPreservesRevokedIdentity():void {
        $this->client->logout(); self::assertSame('logged_out',$this->client->getState());
        $store=new CredentialStore($this->dir); self::assertTrue($store->load()['revoked']); $store->close();
        $last=$this->peer->sent[array_key_last($this->peer->sent)]; self::assertSame('md',$last->attrs['xmlns']); self::assertNotNull($last->child('remove-companion-device'));
        $this->expectException(ConnectionFailure::class); $this->client->connect();
    }
}

final class FeaturesSession implements NodeSession
{
    /** @var list<BinaryNode> */ public array $incoming=[];
    /** @var list<BinaryNode> */ public array $sent=[];
    public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string { return str_repeat('s',32); }
    public function sendNode(BinaryNode $node):void {
        $this->sent[]=$node;
        if ($node->tag!=='iq') return;
        $content=null;
        if (($node->attrs['xmlns'] ?? '')==='w:sync:app:state') $content=[new BinaryNode('sync',[],array_map(static fn(BinaryNode $c):BinaryNode=>new BinaryNode('collection',['name'=>$c->attrs['name'],'version'=>$c->attrs['version']]),$node->child('sync')->content))];
        $this->incoming[]=new BinaryNode('iq',['id'=>$node->attrs['id'],'type'=>'result'],$content);
    }
    public function receiveNode(float $timeoutSeconds=1):?BinaryNode { return array_shift($this->incoming); }
    public function close():void {}
}
