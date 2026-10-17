<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Message\AdvancedEvents;
use PHPUnit\Framework\TestCase;

final class AdvancedEventsTest extends TestCase
{
    public function testPollProjectionKeepsWireDataAndExposesQuizFields():void
    {
        $data=['name'=>'Question','pollType'=>1,'options'=>[['optionName'=>'A'],['optionName'=>'B']],'correctAnswer'=>['optionName'=>'B'],'endTime'=>2000000000,'selectableOptionsCount'=>1];
        $result=AdvancedEvents::normalize([],'poll',$data);
        self::assertSame('quiz',$result['poll']['type']); self::assertSame('B',$result['poll']['correctAnswer']);
        self::assertSame(2000000000,$result['poll']['endTime']); self::assertSame('A',$data['options'][0]['optionName']);
    }

    public function testVoteHashesAreHexAndEmptyVoteIsWithdrawal():void
    {
        $hash=hash('sha256','A',true);
        self::assertSame(['action'=>'vote','selectedOptionHashes'=>[bin2hex($hash)],'withdrawn'=>false],AdvancedEvents::normalize([],'poll-vote',['selectedOptions'=>[$hash]])['poll']);
        self::assertTrue(AdvancedEvents::normalize([],'poll-vote',['selectedOptions'=>[]])['poll']['withdrawn']);
    }

    public function testResponsesAndEncryptedEditPlaintextHaveUsefulFields():void
    {
        self::assertSame('maybe',AdvancedEvents::normalize([],'event-response',['response'=>3,'timestampMs'=>123,'extraGuestCount'=>2])['event']['response']);
        $edit=['protocolMessage'=>['key'=>['id'=>'PARENT'],'editedMessage'=>['pollCreationMessageV3'=>['name'=>'New']]]];
        self::assertSame(['action'=>'edit','name'=>'New','target'=>['id'=>'PARENT']],AdvancedEvents::normalize([],'poll-edit',$edit)['poll']);
        $event=AdvancedEvents::normalize([],'event-edit',['protocolMessage'=>['editedMessage'=>['eventMessage'=>['name'=>'Meeting','startTime'=>100,'isCanceled'=>true]]]]);
        self::assertTrue($event['event']['isCanceled']); self::assertSame('edit',$event['event']['action']);
    }

    public function testAlbumParentAndChildAssociationAreDistinctAndOrdered():void
    {
        self::assertSame(3,AdvancedEvents::normalize([],'album',['expectedImageCount'=>2,'expectedVideoCount'=>1])['album']['expectedCount']);
        $message=['messageContextInfo'=>['messageAssociation'=>['associationType'=>1,'parentMessageKey'=>['id'=>'PARENT'],'messageIndex'=>2]]];
        $child=AdvancedEvents::normalize($message,'image',['caption'=>'third']);
        self::assertSame('media_album',$child['association']['type']);
        self::assertSame(['parent'=>['id'=>'PARENT'],'index'=>2],$child['album']);
    }

    public function testEventInviteProjectionDoesNotLeakBinaryThumbnailIntoJson():void
    {
        $event=AdvancedEvents::normalize([],'event-invite',['eventId'=>'E','eventTitle'=>'Meeting','startTime'=>100,'jpegThumbnail'=>"\xff\xd8",'status'=>2])['event'];
        self::assertTrue($event['hasThumbnail']); self::assertSame('canceled',$event['status']);
        self::assertArrayNotHasKey('jpegThumbnail',$event);
    }
}
