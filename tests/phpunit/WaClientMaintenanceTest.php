<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Auth\CredentialStore;
use MVO\Protocol\BinaryNode;
use MVO\Service\AbPropsService;
use MVO\Transport\NodeSession;
use MVO\WaClient;

final class WaClientMaintenanceTest extends TestCase
{
    private string $dir;
    private WaClient $client;
    private MaintenancePeer $peer;
    protected function setUp():void {
        $this->dir=sys_get_temp_dir().'/mvo-maintenance-client-'.bin2hex(random_bytes(6));
        $c=CredentialStore::fresh();$c['registered']=true;$c['serverHasPreKeys']=true;$c['meJid']='5511000000000:8@s.whatsapp.net';$c['meLid']='42:8@lid';
        $store=new CredentialStore($this->dir);$store->save($c);$store->close();
        $this->peer=new MaintenancePeer();$this->client=new WaClient(authDirectory:$this->dir,sessionFactory:fn()=>$this->peer);
    }
    protected function tearDown():void {
        $this->client->stop();unset($this->client);gc_collect_cycles();
        foreach(glob($this->dir.'/*/*')?:[] as $file)unlink($file);rmdir($this->dir.'/default');rmdir($this->dir);
    }
    public function testDefaultPostLoginMaintainsKeysAndLoadsTypedProperties():void {
        $errors=[];$this->client->on('service_error',static function(array $e)use(&$errors):void{$errors[]=$e;});
        $this->client->connect();$this->peer->incoming[]=new BinaryNode('success');$this->client->poll(0);
        self::assertTrue($this->client->isConnected());self::assertSame([],$errors);
        $uploads=array_values(array_filter($this->peer->sent,static fn(BinaryNode $n):bool=>($n->attrs['xmlns']??'')==='encrypt' && $n->child('list')!==null));
        self::assertCount(1,$uploads);self::assertCount(812,$uploads[0]->child('list')->content);
        self::assertSame(60,$this->client->abprops()->getConfigValue('tctoken_duration'));
        $count=count($this->peer->sent);$this->client->poll(0);self::assertCount($count,$this->peer->sent);
        $this->peer->incoming[]=new BinaryNode('notification',['type'=>'privacy_token','id'=>'token','from'=>'99@lid'],[new BinaryNode('tokens',[],[new BinaryNode('token',['type'=>'trusted_contact','t'=>(string)time()],'receiver-token')])]);$this->client->poll(0);
        $this->client->presence()->subscribe('99@lid');$last=$this->peer->sent[array_key_last($this->peer->sent)];
        self::assertSame('presence',$last->tag);self::assertSame('receiver-token',$last->child('tctoken')->content);
    }
    public function testRawNegativeIqResultDoesNotTriggerRecoveryBeforeCallerClassifiesIt():void {
        $this->client->connect();$this->peer->incoming[]=new BinaryNode('success');$this->client->poll(0);
        $response=$this->client->query(new BinaryNode('iq',['type'=>'get','xmlns'=>'test:negative']),allowErrorResponse:true);
        self::assertSame('error',$response->attrs['type']);self::assertSame('503',$response->child('error')->attrs['code']);self::assertTrue($this->client->isConnected());
    }
}
final class MaintenancePeer implements NodeSession
{
    /** @var list<BinaryNode> */public array $incoming=[];
    /** @var list<BinaryNode> */public array $sent=[];
    public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string{return str_repeat('s',32);}
    public function sendNode(BinaryNode $node):void {
        $this->sent[]=$node;if($node->tag!=='iq')return;$attrs=['id'=>$node->attrs['id'],'type'=>'result'];$body=null;$ns=$node->attrs['xmlns']??'';
        if($ns==='encrypt' && $node->child('digest')!==null){$attrs['type']='error';$body=[new BinaryNode('error',['code'=>'404'])];}
        elseif($ns==='test:negative'){$attrs['type']='error';$body=[new BinaryNode('error',['code'=>'503'])];}
        elseif($ns==='abt')$body=[new BinaryNode('props',['hash'=>'test','refresh'=>'600'],[new BinaryNode('prop',['config_code'=>(string)AbPropsService::catalogue()['tctoken_duration']['code'],'config_value'=>'60'])])];
        elseif($ns==='w:sync:app:state')$body=[new BinaryNode('sync',[],array_map(static fn(BinaryNode $c):BinaryNode=>new BinaryNode('collection',['name'=>$c->attrs['name'],'version'=>$c->attrs['version']]),$node->child('sync')->content))];
        $this->incoming[]=new BinaryNode('iq',$attrs,$body);
    }
    public function receiveNode(float $timeoutSeconds=1):?BinaryNode{return array_shift($this->incoming);}
    public function close():void{}
}
