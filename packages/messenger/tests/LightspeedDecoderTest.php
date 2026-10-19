<?php
declare(strict_types=1);
namespace MVO\Messenger\Tests;

use MVO\Messenger\MessengerException;
use MVO\Messenger\Protocol\LightspeedDecoder;
use PHPUnit\Framework\TestCase;

final class LightspeedDecoderTest extends TestCase
{
    public function testBlocksLocalsDependenciesAndExactSignedIntegers():void
    {
        $payload=['step'=>[1,[3,0,[19,'9223372036854775807']],[5,'m','Olá MVO',[2,0],[19,'-9223372036854775808']]]];
        $calls=(new LightspeedDecoder())->decode($payload,[['name'=>'m','value'=>['__dr'=>'LSUpsertMessage']]]);
        self::assertSame([['name'=>'LSUpsertMessage','args'=>['Olá MVO','9223372036854775807','-9223372036854775808']]],$calls);
    }
    public function testRuntimeProcedureListSupportsExactWireNames():void
    {
        $calls=(new LightspeedDecoder())->decode('[1,[5,"upsertMessage","text"]]',['upsertMessage']);
        self::assertSame('upsertMessage',$calls[0]['name']);
    }
    public function testUntakenConditionalIsNotTraversed():void
    {
        $calls=(new LightspeedDecoder())->decode([23,[30,[19,'9'],[19,'9']],[5,'LSUpsertMessage','yes'],[105,'unsupported loop']]);
        self::assertSame('yes',$calls[0]['args'][0]);
        self::assertSame([], (new LightspeedDecoder())->decode([23,false,[5,'LSUpsertMessage','wrong']]));
        self::assertCount(1, (new LightspeedDecoder())->decode([23,[19,'0'],[5,'LSUpsertMessage','typed I64 is an object']]));
        self::assertCount(1, (new LightspeedDecoder())->decode([23,'0',[5,'LSUpsertMessage','string is true']]));
    }
    public function testDecimalComparisonDoesNotUseFloatingPoint():void
    {
        $calls=(new LightspeedDecoder())->decode([23,[42,[19,'9007199254740992'],[19,'9007199254740993']],[5,'LSUpsertMessage','exact']]);
        self::assertSame('exact',$calls[0]['args'][0]);
        $calls=(new LightspeedDecoder())->decode([23,[36,[19,'-9007199254740992'],[19,'-9007199254740993']],[5,'LSUpsertMessage','negative']]);
        self::assertSame('negative',$calls[0]['args'][0]);
    }
    public function testUnknownOpcodeAbortsAllCollectedCallsAndNextDecodeStartsClean():void
    {
        $decoder=new LightspeedDecoder();
        try{$decoder->decode([1,[5,'LSUpsertMessage','must not escape'],[105,'loop']]);self::fail('Unknown opcode must abort');}
        catch(MessengerException $e){self::assertSame('unsupported_lightspeed_opcode',$e->reason);}
        self::assertSame([['name'=>'LSUpsertMessage','args'=>['new batch']]],$decoder->decode([5,'LSUpsertMessage','new batch']));
    }
    public function testUnimplementedDatabaseResultCannotControlBranches():void
    {
        $this->expectException(MessengerException::class);$this->expectExceptionMessage('return value');
        (new LightspeedDecoder())->decode([1,[3,0,[5,'LSGetCursor']],[23,[2,0],[5,'LSUpsertMessage','guessed']]]);
    }
    public function testMissingLocalDoesNotBecomeZero():void
    {
        $this->expectException(MessengerException::class);
        (new LightspeedDecoder())->decode([5,'LSUpsertMessage',[2,42]]);
    }
    public function testMissingDependencyDoesNotBecomeExecutableName():void
    {
        $this->expectException(MessengerException::class);
        (new LightspeedDecoder())->decode([5,'system','untrusted']);
    }
    public function testInstructionBudgetAndCallBudgetAreEnforced():void
    {
        foreach([new LightspeedDecoder(maximumNodes:2),new LightspeedDecoder(maximumCalls:1)] as $decoder) {
            try{$decoder->decode([1,[5,'LSUpsertMessage','one'],[5,'LSUpsertMessage','two']]);self::fail('Expected bounded batch');}
            catch(MessengerException $e){self::assertSame('lightspeed_limit',$e->reason);}
        }
    }
    public function testNestedExpressionsAreBounded():void
    {
        $value=[5,'LSUpsertMessage','text'];
        for($i=0;$i<10;$i++)$value=[1,$value];
        $this->expectException(MessengerException::class);
        (new LightspeedDecoder(maximumDepth:4))->decode($value);
    }
    public function testUnsafeFloatAndOutOfRangeIntAreRejected():void
    {
        foreach([[5,'LSUpsertMessage',9007199254740992.0],[5,'LSUpsertMessage',[19,'9223372036854775808']],[5,'LSUpsertMessage',[18,[19,'9007199254740993']]]] as $payload) {
            try{(new LightspeedDecoder())->decode($payload);self::fail('Expected lossless number boundary');}
            catch(MessengerException $e){self::assertSame('invalid_lightspeed_number',$e->reason);}
        }
    }
    public function testListNullUndefinedAndReturnRemainInert():void
    {
        $calls=(new LightspeedDecoder())->decode([1,[5,'LSUpsertMessage',[8,'a',[19,'123'],[9],null]],[12,0,0],[5,'LSUpsertMessage','after tuple']]);
        self::assertSame([['name'=>'LSUpsertMessage','args'=>[['a','123',null,null]]],['name'=>'LSUpsertMessage','args'=>['after tuple']]],$calls);
    }
    public function testNullAndUndefinedAreNotEqual():void
    {
        self::assertSame([], (new LightspeedDecoder())->decode([23,[29,[9],null],[5,'LSUpsertMessage','wrong']]));
    }
    public function testRemoteLogsAndThrowsCannotLeakPayloadContent():void
    {
        ob_start();
        try {
            self::assertSame([], (new LightspeedDecoder())->decode([1,[48,'PRIVATE REMOTE LOG'],[49,'PRIVATE REMOTE LOG']]));
            self::assertSame('',ob_get_contents());
            try{(new LightspeedDecoder())->decode([47,'PRIVATE SERVER ERROR']);self::fail('Expected server throw');}
            catch(MessengerException $e){self::assertStringNotContainsString('PRIVATE',$e->getMessage());}
        } finally {ob_end_clean();}
    }
}
