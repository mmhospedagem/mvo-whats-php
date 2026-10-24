<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Message\{MessageCodec,MessageService};
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Signal\{SenderKeyProtocol,SignalCodec,SignalProtocol,SignalStore};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Real Signal/sender-key fixtures, without sessions, accounts or network access. */
final class MessageServiceGroupInboundTest extends TestCase
{
    private const GROUP='120363000000000000@g.us';
    private const ALICE='551199990001:2@s.whatsapp.net';
    private const BOB='551199990002:3@s.whatsapp.net';
    private string $directory;
    private array $alice;
    private array $bob;
    private SignalStore $local;
    private SignalStore $remote;
    private SignalProtocol $sender;
    private MessageService $service;
    private array $sent=[];
    private array $events=[];

    protected function setUp():void
    {
        $this->directory=sys_get_temp_dir().'/mvo-group-inbound-'.bin2hex(random_bytes(8));mkdir($this->directory,0700);
        $this->alice=self::credentials(self::ALICE);$this->bob=self::credentials(self::BOB);
        $this->local=new SignalStore($this->directory.'/local.sqlite');$this->remote=new SignalStore($this->directory.'/remote.sqlite');
        $preKey=$this->local->generatePreKeys(1)[0];
        $this->sender=new SignalProtocol($this->remote,$this->alice);
        $this->sender->ensureSession(self::BOB,['registrationId'=>$this->bob['registrationId'],'identityKey'=>$this->bob['identityKeyPair']['public'],
            'signedPreKey'=>array_intersect_key($this->bob['signedPreKey'],array_flip(['id','public','signature'])),'preKey'=>['id'=>$preKey['id'],'public'=>$preKey['public']]]);
        $this->connectLocal();
    }

    protected function tearDown():void
    {
        unset($this->service,$this->sender);$this->local->close();$this->remote->close();gc_collect_cycles();
        foreach(glob($this->directory.'/*')?:[]as$file)unlink($file);rmdir($this->directory);
    }

    private static function credentials(string $jid):array
    {
        $c=CredentialStore::fresh();$c['registered']=true;$c['meJid']=$jid;$c['serverHasPreKeys']=true;
        $c['signedIdentity']=['details'=>'fixture','accountSignatureKey'=>str_repeat('k',32),'accountSignature'=>str_repeat('a',64),'deviceSignature'=>str_repeat('s',64)];return $c;
    }

    private function connectLocal():void
    {
        $this->service=new MessageService(new SignalProtocol($this->local,$this->bob),$this->local,fn():array=>$this->bob,static function(array $credentials):void{},
            static fn():BinaryNode=>throw new \LogicException('No account queries in incoming fixture'),
            function(BinaryNode $node):void{$this->sent[]=$node;},function(string $event,array $data):void{$this->events[]=[$event,$data];});
    }

    private function restartLocal():void
    {
        unset($this->service);$this->local->close();$this->local=new SignalStore($this->directory.'/local.sqlite');$this->connectLocal();
    }

    private function carrier(array $message):BinaryNode
    {
        $encrypted=$this->sender->encrypt(self::BOB,MessageCodec::pad(SchemaCodec::encode('Message',$message)));
        return new BinaryNode('enc',['v'=>'2','type'=>$encrypted['type']],$encrypted['ciphertext']);
    }

    /** @return array{node:BinaryNode,distribution:string} */
    private function groupMessage(string $id='group-fixture'):array
    {
        $keys=new SenderKeyProtocol($this->remote);$distribution=$keys->createDistribution(self::GROUP,self::ALICE);
        $direct=$this->carrier(['senderKeyDistributionMessage'=>['groupId'=>self::GROUP,'axolotlSenderKeyDistributionMessage'=>$distribution],'messageContextInfo'=>['messageSecret'=>str_repeat('m',32)]]);
        $ciphertext=$keys->encrypt(self::GROUP,self::ALICE,MessageCodec::pad(MessageCodec::text('Authenticated group content')));
        return ['node'=>new BinaryNode('message',['id'=>$id,'from'=>self::GROUP,'participant'=>self::ALICE,'t'=>'123'],[$direct,new BinaryNode('enc',['type'=>'skmsg','v'=>'2'],$ciphertext)]),'distribution'=>$distribution];
    }

    private function messages():array{return array_values(array_filter($this->events,static fn(array $e):bool=>$e[0]==='message'));}
    private function retries():array{return array_values(array_filter($this->sent,static fn(BinaryNode $n):bool=>$n->tag==='receipt'&&($n->attrs['type']??'')==='retry'));}
    private function deliveries():array{return array_values(array_filter($this->sent,static fn(BinaryNode $n):bool=>$n->tag==='receipt'&&($n->attrs['type']??'')!=='retry'));}

    public static function carrierCases():array{return ['ordinary'=>['senderKeyDistributionMessage'],'fast ratchet'=>['fastRatchetKeySenderKeyDistributionMessage']];}
    public static function controlCases():array{return [
        'ordinary direct'=>['senderKeyDistributionMessage',false,false],
        'ordinary group'=>['senderKeyDistributionMessage',true,false],
        'ordinary wrapped'=>['senderKeyDistributionMessage',false,true],
        'fast direct'=>['fastRatchetKeySenderKeyDistributionMessage',false,false],
        'fast group'=>['fastRatchetKeySenderKeyDistributionMessage',true,false],
        'fast wrapped'=>['fastRatchetKeySenderKeyDistributionMessage',false,true],
    ];}

    #[DataProvider('controlCases')]
    public function testAuthenticatedDistributionWithOnlyMessageContextDoesNotEmitUserContent(string $field,bool $group,bool $wrapped):void
    {
        $distribution=(new SenderKeyProtocol($this->remote))->createDistribution(self::GROUP,self::ALICE);
        $message=[$field=>['groupId'=>self::GROUP,'axolotlSenderKeyDistributionMessage'=>$distribution],'messageContextInfo'=>['messageSecret'=>str_repeat('c',32)]];
        if($wrapped)$message=['ephemeralMessage'=>['message'=>$message]];
        $attrs=['id'=>'distribution-only','from'=>$group?self::GROUP:self::ALICE];if($group)$attrs['participant']=self::ALICE;
        $node=new BinaryNode('message',$attrs,[$this->carrier($message)]);
        $this->service->handleNode($node);
        self::assertSame([],$this->messages());self::assertSame([],$this->retries());self::assertCount(1,$this->deliveries());
        self::assertNotNull($this->local->getSession(self::ALICE));
        if($field==='fastRatchetKeySenderKeyDistributionMessage')self::assertSame([],$this->local->entries('sender-key'),'An ignored fast-ratchet carrier must not install an ordinary sender key');
        else self::assertCount(1,$this->local->entries('sender-key'));
    }

    #[DataProvider('carrierCases')]
    public function testDistributionAlongsideRealContentStillEmitsTheContent(string $field):void
    {
        $distribution=(new SenderKeyProtocol($this->remote))->createDistribution(self::GROUP,self::ALICE);
        $this->service->handleNode(new BinaryNode('message',['id'=>'with-content','from'=>self::ALICE],[$this->carrier([$field=>['groupId'=>self::GROUP,'axolotlSenderKeyDistributionMessage'=>$distribution],'messageContextInfo'=>[],'conversation'=>'Content beside control metadata'])]));
        self::assertCount(1,$this->messages());self::assertSame('Content beside control metadata',$this->messages()[0][1]['text']);self::assertSame([],$this->retries());
    }

    public static function contextCases():array{return ['direct'=>[false,false],'group'=>[true,false],'wrapped'=>[false,true]];}

    #[DataProvider('contextCases')]
    public function testMessageContextWithoutDistributionIsNotSilenced(bool $group,bool $wrapped):void
    {
        $message=['messageContextInfo'=>[]];if($wrapped)$message=['ephemeralMessage'=>['message'=>$message]];
        $attrs=['id'=>'context-only','from'=>$group?self::GROUP:self::ALICE];if($group)$attrs['participant']=self::ALICE;
        $this->service->handleNode(new BinaryNode('message',$attrs,[$this->carrier($message)]));
        self::assertCount(1,$this->messages());self::assertSame('message-context-info',$this->messages()[0][1]['type']);
    }

    public static function unknownCases():array{return [
        'ordinary direct'=>['senderKeyDistributionMessage',false],
        'ordinary wrapped'=>['senderKeyDistributionMessage',true],
        'fast direct'=>['fastRatchetKeySenderKeyDistributionMessage',false],
        'fast wrapped'=>['fastRatchetKeySenderKeyDistributionMessage',true],
    ];}

    #[DataProvider('unknownCases')]
    public function testFutureContentNextToDistributionIsNotDiscarded(string $field,bool $wrapped):void
    {
        $distribution=(new SenderKeyProtocol($this->remote))->createDistribution(self::GROUP,self::ALICE);$future=MessageCodec::bytes(16000,'future-content');
        $message=[$field=>['groupId'=>self::GROUP,'axolotlSenderKeyDistributionMessage'=>$distribution],'messageContextInfo'=>[],'_unknown'=>[$future]];
        if($wrapped)$message=['ephemeralMessage'=>['message'=>$message]];
        $this->service->handleNode(new BinaryNode('message',['id'=>'future-content','from'=>self::ALICE],[$this->carrier($message)]));
        self::assertCount(1,$this->messages());$proto=$this->messages()[0][1]['proto'];if($wrapped)$proto=$proto['ephemeralMessage']['message'];
        self::assertSame([$future],$proto['_unknown']);self::assertSame([],$this->retries());
    }

    public function testFailedSenderKeyAfterValidCarrierRetriesDurablyWithoutEmittingOrCommittingPartialKeys():void
    {
        $fixture=$this->groupMessage();$original=$fixture['node'];$children=$original->content;$bad=$children[1]->content;$bad[strlen($bad)-1]=$bad[strlen($bad)-1]^"\x01";
        $children[1]=new BinaryNode('enc',$children[1]->attrs,$bad);$tampered=new BinaryNode('message',$original->attrs,$children);
        $this->service->handleNode($tampered);
        self::assertCount(1,$this->retries());self::assertSame([],$this->deliveries());self::assertSame([],$this->messages());
        self::assertNull($this->local->getSession(self::ALICE));self::assertSame([],$this->local->entries('sender-key'));
        self::assertSame(self::GROUP,$this->retries()[0]->attrs['to']);self::assertSame(self::ALICE,$this->retries()[0]->attrs['participant']);self::assertSame('1',$this->retries()[0]->child('retry')->attrs['count']);
        $this->restartLocal();$this->service->handleNode($tampered);
        self::assertCount(2,$this->retries());self::assertSame('2',$this->retries()[1]->child('retry')->attrs['count']);self::assertSame([],$this->messages());
        $this->service->handleNode($original);
        self::assertCount(1,$this->messages());self::assertSame('Authenticated group content',$this->messages()[0][1]['text']);self::assertCount(1,$this->deliveries());
        $this->service->handleNode($original);self::assertCount(1,$this->messages());self::assertCount(2,$this->deliveries());
    }

    public function testAuthenticatedSenderKeySurvivesAFailingOptionalDirectCarrier():void
    {
        $fixture=$this->groupMessage();(new SenderKeyProtocol($this->local))->processDistribution(self::GROUP,self::ALICE,$fixture['distribution']);
        $original=$fixture['node'];$children=$original->content;$parsed=SignalCodec::readPreKeyMessage($children[0]->content);$bad=$parsed['body'];$bad[strlen($bad)-1]=$bad[strlen($bad)-1]^"\x01";
        $bad=SignalCodec::writePreKeyMessage($parsed['registrationId'],$parsed['preKeyId'],$parsed['signedPreKeyId'],$parsed['baseKey'],$parsed['identityKey'],$bad);
        $children[0]=new BinaryNode('enc',$children[0]->attrs,$bad);$node=new BinaryNode('message',$original->attrs,$children);
        $this->service->handleNode($node);
        self::assertCount(1,$this->messages());self::assertSame('Authenticated group content',$this->messages()[0][1]['text']);self::assertSame([],$this->retries());self::assertCount(1,$this->deliveries());
        self::assertNull($this->local->getSession(self::ALICE),'Unauthenticated direct ratchets must remain rolled back');
        $this->service->handleNode($node);self::assertCount(1,$this->messages());self::assertCount(2,$this->deliveries());
    }

    public function testDuplicateSenderKeyEnvelopesRemainRejectedEvenWhenOneCouldDecrypt():void
    {
        $fixture=$this->groupMessage();$original=$fixture['node'];$children=$original->content;$children[]=$children[1];
        $this->service->handleNode(new BinaryNode('message',$original->attrs,$children));
        self::assertCount(1,$this->retries());self::assertSame([],$this->messages());self::assertSame([],$this->deliveries());self::assertNull($this->local->getSession(self::ALICE));
    }

    public function testAllGroupFailuresRequestAtMostThreeRetriesAndNeverDeliverTheCarrier():void
    {
        $node=new BinaryNode('message',['id'=>'all-bad','from'=>self::GROUP,'participant'=>self::ALICE],[new BinaryNode('enc',['type'=>'pkmsg'],'bad'),new BinaryNode('enc',['type'=>'skmsg'],'bad')]);
        for($i=0;$i<5;++$i)$this->service->handleNode($node);
        self::assertCount(3,$this->retries());self::assertSame([],$this->messages());self::assertSame([],$this->deliveries());
        self::assertSame(['1','2','3'],array_map(static fn(BinaryNode $n):string=>$n->child('retry')->attrs['count'],$this->retries()));
    }

    public function testAValidDirectPayloadDoesNotRetryBecauseAnotherEnvelopeIsBad():void
    {
        $this->service->handleNode(new BinaryNode('message',['id'=>'direct-multi','from'=>self::ALICE],[$this->carrier(['conversation'=>'Valid direct content']),new BinaryNode('enc',['type'=>'msg'],'bad')]));
        self::assertCount(1,$this->messages());self::assertSame('Valid direct content',$this->messages()[0][1]['text']);self::assertSame([],$this->retries());self::assertCount(1,$this->deliveries());
    }
}
