<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Message\{AddonCrypto,AdvancedContent};
use MVO\Protocol\SchemaCodec;
use PHPUnit\Framework\TestCase;

final class AdvancedContentTest extends TestCase
{
    private const TO='456@lid';
    private const ME='123@lid';

    public function testQuizCorrectAnswerAndDeadlineSurviveActualWireEncoding():void
    {
        $proto=AdvancedContent::build(['type'=>'quiz','name'=>'Capital?','options'=>['Brasília','São Paulo'],'correctAnswer'=>'Brasília','endTime'=>2000000100],self::TO);
        $decoded=SchemaCodec::decode('Message',SchemaCodec::encode('Message',$proto));
        self::assertSame(1,$decoded['pollCreationMessageV6']['pollType']);
        self::assertSame(['optionName'=>'Brasília'],$decoded['pollCreationMessageV6']['correctAnswer']);
        self::assertSame(2000000100,$decoded['pollCreationMessageV6']['endTime']);
        $plain=AdvancedContent::build(['type'=>'quiz','name'=>'Capital?','options'=>['A','B'],'correctAnswer'=>'A'],self::TO);
        self::assertArrayHasKey('pollCreationMessageV3',$plain);
    }

    public function testQuizCannotSilentlyIgnoreMissingOrUnknownCorrectAnswer():void
    {
        foreach ([null,'C'] as $answer) {
            try {AdvancedContent::build(['type'=>'quiz','name'=>'Test','options'=>['A','B'],'correctAnswer'=>$answer],self::TO); self::fail('Accepted invalid quiz');}
            catch (\InvalidArgumentException $error) {self::assertStringContainsString('correctAnswer',$error->getMessage());}
        }
    }

    public function testDuplicateOptionsAndInvalidPollFlagsAreRejected():void
    {
        foreach ([['options'=>['A','A']],['allowAddOption'=>'true'],['endTime'=>-1],['pollType'=>'unknown']] as $change) {
            try {AdvancedContent::build(array_replace(['type'=>'poll','name'=>'Test','options'=>['A','B']],$change),self::TO); self::fail('Accepted invalid poll');}
            catch (\InvalidArgumentException) {self::assertTrue(true);}
        }
    }

    public function testPollAddOptionAuthenticatesWithParentSecretAndRetainsAssociation():void
    {
        $parent=$this->parent(['pollCreationMessageV6'=>['name'=>'Choose','options'=>[['optionName'=>'A'],['optionName'=>'B']],'allowAddOption'=>true]]);
        $proto=AdvancedContent::build(['type'=>'poll-add-option','poll'=>$parent,'optionName'=>'C'],self::TO,['meJid'=>self::ME]);
        $wire=SchemaCodec::decode('Message',SchemaCodec::encode('Message',$proto));
        $body=$this->decrypt($wire,'Poll Add Option');
        self::assertSame(['optionName'=>'C'],$body['pollAddOptionMessage']['addOption']);
        self::assertSame(20,$wire['messageContextInfo']['messageAssociation']['associationType']);
        self::assertSame($wire['messageContextInfo']['messageSecret'],$body['messageContextInfo']['messageSecret']);
        self::assertSame('PARENT',$body['pollAddOptionMessage']['pollCreationMessageKey']['id']);
    }

    public function testAddingOptionToClosedOrDisallowedPollIsRejected():void
    {
        $this->expectException(\InvalidArgumentException::class);
        AdvancedContent::build(['type'=>'poll-add-option','poll'=>$this->parent(['pollCreationMessageV3'=>['name'=>'Choose']]),'optionName'=>'C'],self::TO,['meJid'=>self::ME]);
    }

    public function testPollEditUsesProtocolEnvelopeAndPreservesOriginalRestrictions():void
    {
        $parent=$this->parent(['pollCreationMessageV6'=>['name'=>'Old','options'=>[['optionName'=>'A'],['optionName'=>'B']],'endTime'=>2000000100,'hideParticipantName'=>true,'allowAddOption'=>true]]);
        $proto=AdvancedContent::build(['type'=>'poll-edit','poll'=>$parent,'name'=>'New'],self::TO,['meJid'=>self::ME],123000);
        $body=$this->decrypt($proto,'Poll Edit');
        self::assertSame(14,$body['protocolMessage']['type']);
        self::assertSame(123000,$body['protocolMessage']['timestampMs']);
        self::assertSame(['name'=>'New','endTime'=>2000000100,'hideParticipantName'=>true,'allowAddOption'=>true],$body['protocolMessage']['editedMessage']['pollCreationMessageV6']);
    }

    public function testPollEditRefusesChangingOptionsOrQuizCorrectAnswer():void
    {
        foreach (['options'=>['A','B'],'correctAnswer'=>'A','endTime'=>1] as $field=>$value) {
            try {AdvancedContent::build(['type'=>'poll-edit','poll'=>$this->parent(['pollCreationMessageV3'=>['name'=>'Old']]),'name'=>'New',$field=>$value],self::TO,['meJid'=>self::ME]); self::fail('Accepted unsupported poll edit');}
            catch (\InvalidArgumentException $error) {self::assertStringContainsString('only the name',$error->getMessage());}
        }
    }

    public function testEditingAnotherAuthorsEventIsRejected():void
    {
        $parent=$this->parent(['eventMessage'=>['name'=>'Meeting','startTime'=>2000000000]]); $parent['authorJid']='999@lid';
        $this->expectException(\InvalidArgumentException::class);
        AdvancedContent::build(['type'=>'event-edit','event'=>$parent,'name'=>'Changed'],self::TO,['meJid'=>self::ME]);
    }

    public function testEventEditRetainsOmittedFieldsAndEncryptsCompleteReplacement():void
    {
        $parent=$this->parent(['eventMessage'=>['name'=>'Meeting','description'=>'Keep me','startTime'=>2000000000,'endTime'=>2000000100,'extraGuestsAllowed'=>true]]);
        $proto=AdvancedContent::build(['type'=>'event-edit','event'=>$parent,'isCanceled'=>true],self::TO,['meJid'=>self::ME],123000);
        $body=$this->decrypt($proto,'Event Edit');
        self::assertSame(['isCanceled'=>true,'name'=>'Meeting','description'=>'Keep me','startTime'=>2000000000,'endTime'=>2000000100,'extraGuestsAllowed'=>true],$body['protocolMessage']['editedMessage']['eventMessage']);
    }

    public function testEventInviteHasNativeFieldsAndRejectsInvalidDates():void
    {
        $proto=AdvancedContent::build(['type'=>'event-invite','eventId'=>'EVENT','eventTitle'=>'Meeting','startTime'=>2000000000,'endTime'=>2000000100,'jpegThumbnail'=>"\xff\xd8",'status'=>'ACTIVE','coverImageHandle'=>'existing-handle'],self::TO);
        $body=SchemaCodec::decode('Message',SchemaCodec::encode('Message',$proto))['eventInviteMessage'];
        self::assertSame(1,$body['status']); self::assertSame('existing-handle',$body['coverImageHandle']);
        $this->expectException(\InvalidArgumentException::class);
        AdvancedContent::build(['type'=>'event-invite','eventId'=>'EVENT','eventTitle'=>'Meeting','startTime'=>100,'endTime'=>1],self::TO);
    }

    /** @param array<string,mixed> $message
     * @return array<string,mixed> */
    private function parent(array $message):array {return ['id'=>'PARENT','fromMe'=>true,'authorJid'=>self::ME,'messageSecret'=>str_repeat('s',32),'message'=>$message];}
    /** @param array<string,mixed> $proto
     * @return array<string,mixed> */
    private function decrypt(array $proto,string $type):array
    {
        $enc=$proto['secretEncryptedMessage'];
        return SchemaCodec::decode('Message',AddonCrypto::decrypt(str_repeat('s',32),'PARENT',self::ME,self::ME,$type,$enc['encPayload'],$enc['encIv']));
    }
}
