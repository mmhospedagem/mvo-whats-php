<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\History\{ChannelStore,HistoryStore};
use MVO\Media\MediaTransfer;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Service\{NewsletterInbox,Services,ServiceException};
use MVO\Signal\SignalStore;
use PHPUnit\Framework\TestCase;

final class NewsletterLifecycleTest extends TestCase
{
    private string $path;
    private SignalStore $store;
    protected function setUp():void {$this->path=tempnam(sys_get_temp_dir(),'mvo-channel-life-');$this->store=new SignalStore($this->path);}
    protected function tearDown():void {$this->store->close();foreach([$this->path,$this->path.'-wal',$this->path.'-shm']as$file)if(is_file($file))unlink($file);}
    /** @param callable(BinaryNode):BinaryNode $query
     * @param list<string> $events */
    private function services(callable $query,array &$events):Services
    {
        $history=new HistoryStore($this->store);$inbox=new NewsletterInbox($this->store,$history,static function(string $event)use(&$events):void{$events[]=$event;});
        $media=new MediaTransfer(static fn()=>throw new \LogicException('Unexpected upload'),static fn():array=>['status'=>200,'body'=>'public image']);
        return new Services($query,static function():void{},static fn():array=>[],
            ingestChannelMessages:$inbox->ingestResponse(...),channelStore:fn():ChannelStore=>new ChannelStore($this->store,$history),
            downloadChannelMedia:$media->downloadNewsletter(...),recordChannelPublication:$inbox->recordPublication(...));
    }

    public function testOwnPostsAndMutationsPersistOnlyAfterAckWithoutFakeIncomingEvents():void
    {
        $events=[];$timestamp=100;$services=$this->services(static function(BinaryNode $post)use(&$timestamp):BinaryNode{return new BinaryNode('ack',['class'=>'message','id'=>$post->attrs['id'],'server_id'=>'10','t'=>(string)$timestamp++]);},$events);
        self::assertSame($services->newsletter(),$services->channels());$channels=$services->channels();
        $result=$channels->send('1@newsletter','Original','POST');self::assertSame(10,$result['serverId']);self::assertSame('Original',$channels->getLocalMessage('1@newsletter','POST')['text']);
        self::assertTrue($channels->getLocalMessage('1@newsletter','POST')['serverAcknowledged']);
        $channels->editMessage('1@newsletter','POST','Editada');self::assertSame('Editada',$channels->getLocalMessage('1@newsletter','POST')['text']);
        $channels->revoke('1@newsletter','POST');self::assertTrue($channels->getLocalMessage('1@newsletter','POST')['revoked']);self::assertNull($channels->getLocalMessage('1@newsletter','POST')['text']);
        self::assertSame([],$events);
        $this->store->close();$this->store=new SignalStore($this->path);
        self::assertTrue((new ChannelStore($this->store,new HistoryStore($this->store)))->findByServerId('1@newsletter',10)['revoked']);
    }

    public function testRejectedPublicationDoesNotWriteLocalHistory():void
    {
        $events=[];$channels=$this->services(static fn(BinaryNode $post):BinaryNode=>new BinaryNode('ack',['class'=>'message','id'=>$post->attrs['id'],'error'=>'403']),$events)->channels();
        try{$channels->send('1@newsletter','Recusada','POST');self::fail();}catch(ServiceException $e){self::assertSame('403',$e->serverCode);}
        self::assertSame([],$channels->listLocalMessages('1@newsletter'));self::assertSame([],$events);
    }

    public function testQuestionResponseAckPreservesOpaqueIdentityWithoutReplacingQuestion():void
    {
        $events=[];$channels=$this->services(static function(BinaryNode $post):BinaryNode {
            return new BinaryNode('ack',['class'=>'message','id'=>$post->attrs['id'],'t'=>'100']+(($post->child('meta')?->attrs['questiontype']??null)==='response'?['response_server_id'=>'opaque/response==']:['server_id'=>'10']));
        },$events)->channels();
        $channels->sendQuestion('1@newsletter','Pergunta','QUESTION');$result=$channels->respondQuestion('1@newsletter',10,'Resposta','RESPONSE');
        self::assertSame('opaque/response==',$result['responseServerId']);self::assertArrayNotHasKey('serverId',$result);
        $question=$channels->getLocalMessage('1@newsletter','QUESTION');$response=$channels->getLocalMessage('1@newsletter','RESPONSE');
        self::assertSame('Pergunta',$question['text']);self::assertSame('Resposta',$response['text']);self::assertSame(10,$response['questionServerId']);
        self::assertNull($response['serverId']);self::assertSame('opaque/response==',$response['responseServerId']);
        self::assertSame('QUESTION',(new ChannelStore($this->store,new HistoryStore($this->store)))->findByServerId('1@newsletter',10)['id']);
    }

    public function testFetchedPlaintextChannelMediaUsesOriginalHashAndNoFakeEncryptionKey():void
    {
        $events=[];$proto=['questionMessage'=>['message'=>['imageMessage'=>['url'=>'https://mmg.whatsapp.net/a','mimetype'=>'image/jpeg','fileLength'=>12,'fileSha256'=>hash('sha256','public image',true)]]]];
        $channels=$this->services(static fn():BinaryNode=>new BinaryNode('iq',['type'=>'result'],[new BinaryNode('messages',['jid'=>'1@newsletter'],[
            new BinaryNode('message',['id'=>'PHOTO','server_id'=>'10','t'=>'100'],[new BinaryNode('plaintext',[],SchemaCodec::encode('Message',$proto))]),
        ])]),$events)->channels();
        $decoded=$channels->fetchMessagesDecoded('1@newsletter');self::assertTrue($decoded[0]['data']['media']['plaintext']);self::assertArrayNotHasKey('mediaKey',$decoded[0]['data']['media']);
        self::assertSame('public image',$channels->downloadMessageMedia('1@newsletter','PHOTO'));self::assertSame(['message','newsletter_message'],$events);
        self::assertSame('image',$channels->getLocalMessage('1@newsletter','PHOTO')['type']);
    }
}
