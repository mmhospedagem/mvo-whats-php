<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Service\{MexService,NewsletterService,ServiceException,ServiceTransport};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NewsletterProtocolTest extends TestCase
{
    private array $queries=[];
    private array $sent=[];
    private array $responses=[];
    private NewsletterService $service;
    protected function setUp(): void
    {
        $wire=new ServiceTransport(function(BinaryNode $node):BinaryNode {
            $this->queries[]=$node;if($this->responses!==[]) return array_shift($this->responses);
            return $node->tag==='message'?new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id'],'server_id'=>'42','t'=>'1700000000']):new BinaryNode('iq',['type'=>'result']);
        },function(BinaryNode $node):void {$this->sent[]=$node;},static fn():array=>[]);
        $this->service=new NewsletterService($wire,new MexService($wire));
    }
    private function last(): BinaryNode { return $this->queries[array_key_last($this->queries)]; }
    private function proto(): array { return SchemaCodec::decode('Message',$this->last()->child('plaintext')->content); }
    public function testTextAcknowledgementExposesOnlyActualServerFacts(): void
    {
        $result=$this->service->send('123@newsletter','Olá','POST1');self::assertSame('server_ack',$result['status']);self::assertSame(42,$result['serverId']);self::assertSame(1700000000,$result['timestampSeconds']);self::assertSame(['extendedTextMessage'=>['text'=>'Olá']],$this->proto());self::assertNull($this->last()->child('enc'));
        $this->responses[]=new BinaryNode('ack',['class'=>'message','id'=>'POST2']);$result=$this->service->send('123@newsletter','Olá','POST2');self::assertArrayNotHasKey('serverId',$result);self::assertArrayNotHasKey('timestampSeconds',$result);
    }
    public function testBadAckCannotPersistAsSuccess(): void
    {
        $this->responses[]=new BinaryNode('ack',['class'=>'message','id'=>'POST1','error'=>'403']);$this->expectException(ServiceException::class);$this->service->send('123@newsletter','Olá','POST1');
    }
    public function testQuestionHasBothNativeFutureProofWrapperAndContextFlag(): void
    {
        $this->service->sendQuestion('123@newsletter','Qual opção?','QUESTION1');self::assertSame('text',$this->last()->attrs['type']);self::assertSame(['questiontype'=>'question'],$this->last()->child('meta')->attrs);
        self::assertSame(['questionMessage'=>['message'=>['extendedTextMessage'=>['text'=>'Qual opção?','contextInfo'=>['isQuestion'=>true]]]]],$this->proto());
    }
    public function testMediaQuestionKeepsPlaintextUploadAndQuestionWrapper(): void
    {
        $uploaded=0;$wire=new ServiceTransport(function(BinaryNode $node):BinaryNode {$this->queries[]=$node;return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);},static function():void {},static fn():array=>[]);
        $service=new NewsletterService($wire,new MexService($wire),static function(array $content)use(&$uploaded):array {++$uploaded;self::assertSame('image',$content['type']);return ['imageMessage'=>['url'=>'https://example.invalid/image','caption'=>'Qual imagem?']];});
        $service->sendQuestion('123@newsletter',['type'=>'image','media'=>['bytes'=>'test image']],'QUESTION2');self::assertSame(1,$uploaded);self::assertSame('image',$this->last()->child('plaintext')->attrs['mediatype']);
        $proto=$this->proto();self::assertSame('Qual imagem?',$proto['questionMessage']['message']['imageMessage']['caption']);self::assertTrue($proto['questionMessage']['message']['imageMessage']['contextInfo']['isQuestion']);self::assertArrayNotHasKey('messageContextInfo',$proto);
    }
    public function testQuestionResponseUsesOwnKeyAndParentInEnvelope(): void
    {
        $this->responses[]=new BinaryNode('ack',['class'=>'message','id'=>'RESPONSE1','response_server_id'=>'opaque-response-1','t'=>'1700000000']);
        $result=$this->service->respondQuestion('123@newsletter',50,'Minha resposta','RESPONSE1');self::assertSame('opaque-response-1',$result['responseServerId']);self::assertArrayNotHasKey('serverId',$result);self::assertSame(1700000000,$result['timestampSeconds']);self::assertSame(['to'=>'123@newsletter','id'=>'RESPONSE1','server_id'=>'50','type'=>'text'],$this->last()->attrs);self::assertSame(['questiontype'=>'response'],$this->last()->child('meta')->attrs);
        self::assertSame(['questionResponseMessage'=>['key'=>['remoteJid'=>'123@newsletter','fromMe'=>true,'id'=>'RESPONSE1'],'text'=>'Minha resposta']],$this->proto());
    }
    public function testResharedResponseQuotesTheOriginalQuestionAndResponse(): void
    {
        $question=['questionMessage'=>['message'=>['extendedTextMessage'=>['text'=>'Qual opção?','contextInfo'=>['isQuestion'=>true]]]]];$response=['questionResponseMessage'=>['key'=>['id'=>'RESPONSE1','remoteJid'=>'123@newsletter','fromMe'=>false],'text'=>'Minha resposta']];
        $this->service->reshareQuestionResponse('123@newsletter',50,'response-server-1','Resposta selecionada',$question,$response,'RESHARE1');
        self::assertSame(['questiontype'=>'reply','parent_server_id'=>'50','response_server_id'=>'response-server-1'],$this->last()->child('meta')->attrs);
        $proto=$this->proto();self::assertArrayHasKey('questionReplyMessage',$proto);$context=$proto['questionReplyMessage']['message']['extendedTextMessage']['contextInfo']['questionReplyQuotedMessage'];
        self::assertSame(50,$context['serverQuestionId']);self::assertSame($question,$context['quotedQuestion']);self::assertSame('Minha resposta',$context['quotedResponse']['questionResponseMessage']['text']);
    }
    public function testPollSnapshotHasPollEnvelopeAndNativeProtobuf(): void
    {
        $votes=[['optionName'=>'Sim','optionVoteCount'=>15],['optionName'=>'Não','optionVoteCount'=>0]];
        foreach([false,true] as $version3) { $this->service->sendPollResultSnapshot('123@newsletter','Resultado',$votes,'SNAPSHOT1',$version3);self::assertSame('poll',$this->last()->attrs['type']);self::assertSame(['polltype'=>'result_snapshot'],$this->last()->child('meta')->attrs);self::assertSame(['name'=>'Resultado','pollVotes'=>$votes],$this->proto()[$version3?'pollResultSnapshotMessageV3':'pollResultSnapshotMessage']); }
    }
    public function testEmptyVoteRetractsWithNoFabricatedServerAck(): void
    {
        $result=$this->service->votePoll('123@newsletter',42,[]);self::assertSame('sent',$result['status']);self::assertArrayNotHasKey('serverId',$result);self::assertSame([],$this->sent[0]->child('votes')->content);self::assertSame(['polltype'=>'vote'],$this->sent[0]->child('meta')->attrs);self::assertSame([],$this->queries);
        $this->service->revokeReaction('123@newsletter',42);self::assertSame('7',$this->sent[1]->attrs['edit']);self::assertSame([],$this->sent[1]->child('reaction')->attrs);
    }
    public function testPublicMediaTypesMatchTheirActualProtoFlags(): void
    {
        foreach([['audioMessage',['url'=>'https://example.invalid/a','ptt'=>true],'ptt'],['videoMessage',['url'=>'https://example.invalid/v','gifPlayback'=>true],'gif'],['stickerPackMessage',['directPath'=>'/sticker-pack'],'sticker_pack']] as [$field,$payload,$mediaType]) {
            $this->service->send('123@newsletter',['type'=>'raw','message'=>[$field=>$payload]],'MEDIA1');self::assertSame('media',$this->last()->attrs['type']);self::assertSame($mediaType,$this->last()->child('plaintext')->attrs['mediatype']);self::assertSame([$field=>$payload],$this->proto());
        }
        $this->service->send('123@newsletter',['type'=>'contact','displayName'=>'MVO','vcard'=>"BEGIN:VCARD\nVERSION:3.0\nFN:MVO\nEND:VCARD"],'CONTACT1');self::assertSame('vcard',$this->last()->child('plaintext')->attrs['mediatype']);
    }
    public function testPollWithAddOptionFlagUsesLatestExistingSchemaWithoutDirectSecrets(): void
    {
        $this->service->send('123@newsletter',['type'=>'poll','name'=>'Preferência','options'=>['Um','Dois'],'allowAddOption'=>true],'POLL1');self::assertSame(['polltype'=>'creation'],$this->last()->child('meta')->attrs);self::assertArrayHasKey('pollCreationMessageV6',$this->proto());self::assertArrayNotHasKey('messageContextInfo',$this->proto());
    }
    public function testPagingAndStatusHistoryUseDistinctPublicIqs(): void
    {
        $this->service->fetchMessages('123@newsletter',25,null,10,'ADMIN');self::assertSame(['type'=>'get','to'=>'s.whatsapp.net','xmlns'=>'newsletter'],$this->last()->attrs);self::assertSame(['type'=>'jid','jid'=>'123@newsletter','count'=>'25','view_role'=>'ADMIN','after'=>'10'],$this->last()->child('messages')->attrs);
        $this->service->fetchMessageUpdates('123@newsletter',20,1700000000,80);self::assertSame('123@newsletter',$this->last()->attrs['to']);self::assertSame(['count'=>'20','before'=>'80','since'=>'1700000000'],$this->last()->child('message_updates')->attrs);
        $this->service->fetchStatuses('123@newsletter',100,50,null,'OWNER');self::assertSame(['type'=>'jid','jid'=>'123@newsletter','count'=>'100','view_role'=>'OWNER','before'=>'50'],$this->last()->child('statuses')->attrs);
    }
    public function testTosMissingStateIsUnknownRatherThanAccepted(): void
    {
        $this->responses[]=new BinaryNode('iq',['type'=>'result'],[new BinaryNode('tos',[],[new BinaryNode('notice',['id'=>'newsletter','state'=>'false']),new BinaryNode('notice',['id'=>'other'])])]);
        self::assertSame(['refreshSeconds'=>null,'notices'=>[['id'=>'newsletter','accepted'=>false],['id'=>'other','accepted'=>null]]],$this->service->queryTosState(['newsletter','other']));
    }
    public function testLiveUpdatesMustReturnAnActualSupportedDuration(): void
    {
        $this->responses[]=new BinaryNode('iq',['type'=>'result'],[new BinaryNode('live_updates',['duration'=>'120'])]);self::assertSame(['durationSeconds'=>120],$this->service->subscribeLiveUpdates('123@newsletter'));
        $this->responses[]=new BinaryNode('iq',['type'=>'result'],[new BinaryNode('live_updates',['duration'=>'120broken'])]);$this->expectException(\UnexpectedValueException::class);$this->service->subscribeLiveUpdates('123@newsletter');
    }
    #[DataProvider('invalidPosts')]
    public function testInvalidPostsDoNotReachWire(string $method,array $arguments): void
    {
        try{$this->service->$method(...$arguments);self::fail('Invalid post accepted');}catch(\InvalidArgumentException){self::assertSame([],$this->queries);self::assertSame([],$this->sent);}
    }
    public static function invalidPosts(): iterable
    {
        yield ['send',['123@newsletter',['type'=>'raw','message'=>['extendedTextMessage'=>['text'=>'text'],'locationMessage'=>['degreesLatitude'=>1,'degreesLongitude'=>2]]]]];
        yield ['send',['123@newsletter',['type'=>'raw','message'=>['imageMessage'=>['url'=>'https://example.invalid/a'],'videoMessage'=>['url'=>'https://example.invalid/b']]]]];
        yield ['send',['123@newsletter',['type'=>'raw','message'=>['audioMessage'=>['url'=>'https://example.invalid/a','mediaKey'=>str_repeat('k',32)]]]]];
        yield ['send',['123@newsletter',['type'=>'text','text'=>'secret','viewOnce'=>true]]];yield ['send',['123@newsletter',['type'=>'text','text'=>'secret','ephemeralExpiration'=>3600]]];
        yield ['sendQuestion',['123@newsletter',['type'=>'poll','name'=>'Question','options'=>['One','Two']]]];yield ['respondQuestion',['123@newsletter',1,'']];
        yield ['reshareQuestionResponse',['123@newsletter',1,'response','reply',[],[]]];yield ['reshareQuestionResponse',['123@newsletter',1,'response','reply',['conversation'=>'Question'],['questionResponseMessage'=>['key'=>['id'=>'R1','remoteJid'=>'999@newsletter'],'text'=>'Answer']]]];
        yield ['sendPollResultSnapshot',['123@newsletter','Results',[['optionName'=>'One','optionVoteCount'=>-1]]]];yield ['sendPollResultSnapshot',['123@newsletter','Results',[['optionName'=>'One','optionVoteCount'=>1,'unknown'=>true]]]];
        yield ['editMessage',['123@newsletter','POLL1',['type'=>'poll','name'=>'Question','options'=>['One','Two']]]];
    }
}
