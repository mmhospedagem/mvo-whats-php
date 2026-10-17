<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Auth\CredentialStore;
use MVO\Message\MessageCodec;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Transport\NodeSession;
use MVO\Voip\{MediaSession,RelayTransport,VoipService};
use MVO\WaClient;

final class WaClientVoipPollingTest extends TestCase
{
    public function testAnOutstandingIqDoesNotStarveMediaOrStartAnotherQuery():void
    {
        $directory=sys_get_temp_dir().'/mvo-client-voip-poll-'.bin2hex(random_bytes(8));$credentials=CredentialStore::fresh();$credentials['registered']=true;$credentials['serverHasPreKeys']=true;$credentials['meJid']='111:1@s.whatsapp.net';
        $store=new CredentialStore($directory);$store->save($credentials);$store->close();$socket=new MediaPollingSession();$client=new WaClient(authDirectory:$directory,sessionFactory:fn()=>$socket,automaticMaintenance:false);
        $key=random_bytes(32);$queryAllowed=true;$queryCalls=0;
        $voip=new VoipService(static fn()=>['meJid'=>'111:1@lid'],function(BinaryNode $node)use(&$queryAllowed,&$queryCalls):BinaryNode{if(!$queryAllowed)throw new \LogicException('Nested media query');++$queryCalls;return new BinaryNode('ack',['id'=>$node->attrs['id'],'class'=>'call']);},$client->sendNode(...),static fn()=>['222:2@lid'],static fn()=>throw new \LogicException('No key fanout expected'),static fn()=>MessageCodec::pad(SchemaCodec::encode('Message',['call'=>['callKey'=>$key]])),static fn()=>null);
        try{
            $client->connect();$socket->incoming[]=new BinaryNode('success');$client->poll(0);self::assertTrue($client->isConnected());
            // Inject the independently exercised VoIP service to isolate poll scheduling from Signal setup.
            (new \ReflectionProperty(WaClient::class,'voip'))->setValue($client,$voip);
            $voip->handleNode(new BinaryNode('call',['id'=>'offer','from'=>'222:2@lid'],[new BinaryNode('offer',['call-id'=>'POLL','call-creator'=>'222:2@lid'],[new BinaryNode('enc',['type'=>'msg'],'fixture')])]));$voip->accept('POLL');
            $media=$voip->media('POLL');$remote=new MediaSession('POLL',$key,'222:2@lid','111:1@lid');$relay=new MediaPollingRelay($remote->protectPayload("\xf8\xff\xfe",16000));$media->connectTransport($relay);self::assertFalse($media->isReady());$before=$queryCalls;$queryAllowed=false;
            $reply=$client->query(new BinaryNode('iq',['type'=>'get','xmlns'=>'test:delayed']));
            self::assertSame('result',$reply->attrs['type']);self::assertGreaterThanOrEqual(3,$socket->delayedReads);self::assertTrue($media->isReady());self::assertGreaterThan(0,$relay->ticks);self::assertSame($before,$queryCalls);self::assertSame('connected',$voip->getCall('POLL')['state']);$remote->close();
        }finally{$client->stop();$voip->close();unset($client,$voip);gc_collect_cycles();foreach(glob($directory.'/*/*')?:[] as $file)unlink($file);rmdir($directory.'/default');rmdir($directory);}
    }
}
final class MediaPollingSession implements NodeSession
{
    public array $incoming=[];
    public int $delayedReads=0;
    private ?BinaryNode $deferred=null;
    public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string{return str_repeat('s',32);}
    public function sendNode(BinaryNode $node):void
    {
        if($node->tag!=='iq')return;$reply=new BinaryNode('iq',['id'=>$node->attrs['id'],'type'=>'result']);
        if(($node->attrs['xmlns']??'')==='test:delayed'){$this->deferred=$reply;return;}$this->incoming[]=$reply;
    }
    public function receiveNode(float $timeoutSeconds=1):?BinaryNode
    {
        if($this->incoming!==[])return array_shift($this->incoming);if($this->deferred!==null && ++$this->delayedReads>=3){$reply=$this->deferred;$this->deferred=null;return $reply;}return null;
    }
    public function close():void{}
}
final class MediaPollingRelay implements RelayTransport
{
    public int $ticks=0;
    public function __construct(private ?string $packet){}
    public function connect(array $selfSsrcs,array $peerSsrcs=[]):void{}
    public function send(string $packet):void{}
    public function receive(float $timeout=0.0):?string{$packet=$this->packet;$this->packet=null;return $packet;}
    public function tick():void{++$this->ticks;}
    public function close():void{}
    public function reregister():void{}
    public function updateSubscriptions(array $selfSsrcs,array $peerSsrcs,?string $streamDescriptor=null,?string $rxSubscriptions=null):void{}
    public function metrics():array{return ['open'=>true,'allocated'=>true,'lastPongAt'=>0.0,'openedAt'=>0.0,'rttMs'=>null];}
}
