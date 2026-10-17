<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Message\MessageService;
use MVO\Protocol\{BinaryCodec,BinaryNode};
use MVO\Service\ServiceException;
use MVO\Signal\{SignalProtocol,SignalStore};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageRefusalTest extends TestCase
{
    private string $path;
    private SignalStore $store;
    protected function setUp():void
    {
        $path=tempnam(sys_get_temp_dir(),'mvo-refusal-');if($path===false)throw new \RuntimeException('No fixture database');
        $this->path=$path;$this->store=new SignalStore($path);
    }
    protected function tearDown():void
    {
        $this->store->close();unset($this->store);gc_collect_cycles();
        foreach([$this->path,$this->path.'-wal',$this->path.'-shm']as$path)if(is_file($path))unlink($path);
    }
    public static function refusals():iterable
    {
        yield 'attribute'=>['attribute'];yield 'child'=>['child'];yield 'transport typed exception'=>['exception'];
    }
    #[DataProvider('refusals')]
    public function testExplicitRefusalPreservesCiphertextAndSafeServerCode(string $shape):void
    {
        [$service,$node,$metadata,$events,$calls]=$this->fixture(function(BinaryNode $node)use($shape):BinaryNode{
            if($shape==='exception')throw new ServiceException('message','406','WhatsApp rejected outgoing message');
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]+($shape==='attribute'?['error'=>'406']:[]),
                $shape==='child'?[new BinaryNode('error',['code'=>'406','text'=>'private server detail'])]:null);
        });
        $original=$this->store->get('message-outbox',$metadata['id']);
        try{(new \ReflectionMethod(MessageService::class,'submit'))->invoke($service,$node,$metadata);self::fail('Refused message accepted');}
        catch(ServiceException $error){self::assertSame('406',$error->serverCode);self::assertSame('message',$error->operation);self::assertStringNotContainsString('private server detail',$error->getMessage());}
        $status=$service->getMessageStatus($metadata['id']);self::assertSame('rejected',$status['status']);self::assertSame('406',$status['serverCode']);
        $stored=json_decode($this->store->get('message-outbox',$metadata['id']),true,32,JSON_THROW_ON_ERROR);
        self::assertSame(json_decode($original,true)['node'],$stored['node']);self::assertSame('ciphertext',$stored['plaintext']);
        $service->drainPending();self::assertSame(1,$calls->value,'A refused send must not be automatically replayed');
        self::assertSame('send_not_acknowledged',$events->items[0][1]['reason']);self::assertSame('406',$events->items[0][1]['serverCode']);
        self::assertSame('rejected',$service->history()->store->getMessage($metadata['to'],$metadata['id'])['status']);
    }
    public static function observedStates():iterable
    {
        foreach(['server_ack','delivered','read','played']as$status)yield $status=>[$status];
    }
    #[DataProvider('observedStates')]
    public function testRefusalCannotEraseDeliveryObservedWhileWaiting(string $observed):void
    {
        [$service,$node,$metadata]=$this->fixture(function(BinaryNode $node)use($observed):BinaryNode{
            $record=json_decode($this->store->get('message-outbox',$node->attrs['id']),true,32,JSON_THROW_ON_ERROR);
            $record['metadata']['status']=$observed;$this->store->set('message-outbox',$node->attrs['id'],json_encode($record,JSON_THROW_ON_ERROR));
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id'],'error'=>'406']);
        });
        try{(new \ReflectionMethod(MessageService::class,'submit'))->invoke($service,$node,$metadata);self::fail('Refused ACK accepted');}catch(ServiceException){}
        self::assertSame($observed,$service->getMessageStatus($metadata['id'])['status']);
    }
    public function testTimeoutRemainsUnknownAndMalformedAckIsNotARefusal():void
    {
        foreach(['timeout','wrong-id']as$failure){
            [$service,$node,$metadata,$events,$calls]=$this->fixture(static function(BinaryNode $node)use($failure):BinaryNode{
                if($failure==='timeout')throw new \RuntimeException('Transport timeout');
                return new BinaryNode('ack',['class'=>'message','id'=>'different','error'=>'406']);
            });
            try{(new \ReflectionMethod(MessageService::class,'submit'))->invoke($service,$node,$metadata);self::fail('Expected uncertain send');}
            catch(\RuntimeException $error){self::assertNotInstanceOf(ServiceException::class,$error);}
            $status=$service->getMessageStatus($metadata['id']);self::assertSame('unknown',$status['status']);self::assertArrayNotHasKey('serverCode',$status);
            $service->drainPending();self::assertSame(1,$calls->value);self::assertArrayNotHasKey('serverCode',$events->items[0][1]);
        }
    }
    private function fixture(\Closure $response):array
    {
        $credentials=CredentialStore::fresh();$credentials['registered']=true;$credentials['meJid']='5511000000000:5@s.whatsapp.net';$credentials['serverHasPreKeys']=true;
        $events=(object)['items'=>[]];$calls=(object)['value'=>0];
        $service=new MessageService(new SignalProtocol($this->store,$credentials),$this->store,static fn():array=>$credentials,static function():void{},
            static function(BinaryNode $node)use($response,$calls):BinaryNode{++$calls->value;return $response($node);},static function():void{},
            static function(string $event,array $data)use($events):void{$events->items[]=[$event,$data];});
        $id='REFUSAL_'.bin2hex(random_bytes(4));$metadata=['id'=>$id,'to'=>'5522000000000@s.whatsapp.net','status'=>'pending','timestamp'=>time(),'type'=>'text'];
        $node=new BinaryNode('message',['id'=>$id,'to'=>$metadata['to']],[]);
        $this->store->set('message-outbox',$id,json_encode(['node'=>base64_encode((new BinaryCodec())->encode($node)),'metadata'=>$metadata,'plaintext'=>'ciphertext'],JSON_THROW_ON_ERROR));
        $service->history()->store->upsertMessage([...$metadata,'chat'=>$metadata['to'],'fromMe'=>true]);
        return [$service,$node,$metadata,$events,$calls];
    }
}
