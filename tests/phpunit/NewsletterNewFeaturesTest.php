<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\History\{ChannelStore,HistoryStore};
use MVO\Message\PollOptionHash;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Service\{NewsletterService,ServiceTransport,MexService};
use MVO\Signal\SignalStore;
use PHPUnit\Framework\TestCase;

final class NewsletterNewFeaturesTest extends TestCase
{
    public function testPhotoPollPublishesAllPreparedPlaintextImagesWithCorrectAssociations():void
    {
        $timeline=[];$nodes=[];$images=[];
        $transport=new ServiceTransport(function(BinaryNode $node)use(&$timeline,&$nodes):BinaryNode{
            $timeline[]='publish';$nodes[]=$node;return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id'],'server_id'=>(string)(10+count($nodes))]);
        },static function():void{},static fn():array=>[]);
        $prepare=function(array $content)use(&$timeline,&$images):array{
            $timeline[]='prepare';$image=['directPath'=>'/image/'.count($images),'mimetype'=>'image/jpeg','fileSha256'=>hash('sha256',$content['media'],true),'fileLength'=>10];$images[]=$image;return ['imageMessage'=>$image];
        };
        $service=new NewsletterService($transport,new MexService($transport),$prepare);
        $result=$service->sendImagePoll('123@newsletter',['name'=>'Qual imagem?','options'=>[['name'=>'A','media'=>'first'],['name'=>'B','media'=>'second']]],'PHOTO-PARENT');
        self::assertTrue($result['complete']);self::assertSame(2,$result['sentCount']);self::assertSame(['prepare','prepare','publish','publish','publish'],$timeline);
        $parent=SchemaCodec::decode('Message',$nodes[0]->child('plaintext')->content)['pollCreationMessageV3'];
        self::assertSame(2,$parent['pollContentType']);self::assertSame('poll',$nodes[0]->attrs['type']);self::assertSame(['polltype'=>'creation','contenttype'=>'image'],$nodes[0]->child('meta')->attrs);
        foreach([1,2]as$index){
            $node=$nodes[$index];$proto=SchemaCodec::decode('Message',$node->child('plaintext')->content);
            self::assertSame('image',$node->child('plaintext')->attrs['mediatype']);
            self::assertSame('media_poll',$node->child('meta')->attrs['message_association_type']);
            $expected=$images[$index-1];$actual=$proto['pollCreationOptionImageMessage']['message']['imageMessage'];ksort($expected);ksort($actual);self::assertSame($expected,$actual);
            $association=$proto['messageContextInfo']['messageAssociation'];self::assertSame(7,$association['associationType']);self::assertSame('PHOTO-PARENT',$association['parentMessageKey']['id']);
            self::assertSame('123@newsletter',$association['parentMessageKey']['remoteJid']);self::assertArrayNotHasKey('messageIndex',$association);
            self::assertSame(PollOptionHash::image($index===1?'A':'B',$images[$index-1]['fileSha256']),$parent['options'][$index-1]['optionHash']);
            self::assertArrayNotHasKey('messageSecret',$proto['messageContextInfo']);
        }
    }

    public function testPhotoPollStopsAfterUncertainAcknowledgementAndKeepsAttemptedIds():void
    {
        $nodes=[];
        $transport=new ServiceTransport(function(BinaryNode $node)use(&$nodes):BinaryNode{
            $nodes[]=$node;if(count($nodes)===2)throw new \RuntimeException('ACK timeout');return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        },static function():void{},static fn():array=>[]);
        $prepare=static fn():array=>['imageMessage'=>['directPath'=>'/a','fileSha256'=>str_repeat('x',32),'mimetype'=>'image/jpeg']];
        $service=new NewsletterService($transport,new MexService($transport),$prepare);
        $result=$service->sendImagePoll('123@newsletter',['name'=>'Qual?','options'=>[['name'=>'A','media'=>'a'],['name'=>'B','media'=>'b'],['name'=>'C','media'=>'c']]],'PARENT');
        self::assertFalse($result['complete']);self::assertCount(2,$nodes);self::assertCount(1,$result['messages']);
        self::assertSame($nodes[1]->attrs['id'],$result['failure']['messageId']);self::assertFalse($result['failure']['retryWholePoll']);
    }

    public function testScheduledChannelObservationSurvivesRestartAndDoesNotExposeRevealKeys():void
    {
        $path=tempnam(sys_get_temp_dir(),'mvo-observed-schedule-');$store=new SignalStore($path);
        try{
            $history=new HistoryStore($store);$channels=new ChannelStore($store,$history);
            $record=['chat'=>'1@newsletter','id'=>'NATIVE','timestamp'=>10,'proto'=>['newsletterScheduledMessage'=>['message'=>['conversation'=>'Original']]],
                'webMessageInfo'=>['scheduledMessageMetadata'=>['revealKeyId'=>'PRIVATE-ID','revealKey'=>'PRIVATE-KEY','scheduledTime'=>'1800000000000']]];
            $history->upsertMessage($record);$channels->indexMessage($record);
            $channels->indexMessage(['chat'=>'1@newsletter','id'=>'NORMAL','proto'=>['conversation'=>'Normal']]);
            self::assertSame([],$channels->listObservedSchedules('2@newsletter'));
            $store->close();$store=new SignalStore($path);$channels=new ChannelStore($store,new HistoryStore($store));
            $rows=$channels->listObservedSchedules('1@newsletter');self::assertCount(1,$rows);
            self::assertTrue($rows[0]['newsletterWrapper']);self::assertSame('1800000000000',$rows[0]['scheduledTime']);self::assertSame('observed',$rows[0]['state']);
            self::assertStringNotContainsString('PRIVATE',json_encode($rows));self::assertArrayNotHasKey('text',$rows[0]);
            $channels->indexMessage(['chat'=>'1@newsletter','id'=>'NATIVE','revoked'=>true,'proto'=>[]]);
            self::assertTrue($channels->listObservedSchedules('1@newsletter')[0]['revoked']);
        }finally{$store->close();foreach([$path,$path.'-wal',$path.'-shm']as$file)if(is_file($file))unlink($file);}
    }
}
