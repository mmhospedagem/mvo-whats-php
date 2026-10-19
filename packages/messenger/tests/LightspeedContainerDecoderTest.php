<?php
declare(strict_types=1);
namespace MVO\Messenger\Tests;

use MVO\Messenger\MessengerException;
use MVO\Messenger\Protocol\{LightspeedDecoder,LightspeedProcedureResult};
use PHPUnit\Framework\TestCase;

final class LightspeedContainerDecoderTest extends TestCase
{
    public function testWireTupleUnpacksOncePreservingNullAndMissingUndefined():void
    {
        $resolved=0;$resolver=static function(string $name,array $args)use(&$resolved):LightspeedProcedureResult{
            if($name==='LSKnownTuple'){$resolved++;return LightspeedProcedureResult::known([null,'second']);}
            return LightspeedProcedureResult::unknown();
        };
        $calls=(new LightspeedDecoder(procedureResultResolver:$resolver))->decode([1,
            [4,4,3,[5,'LSKnownTuple']],
            [5,'LSFixture',[2,4],[2,5],[2,6],[29,[2,4],null],[29,[2,6],null]],
        ]);
        self::assertSame(1,$resolved);self::assertSame([null,'second',null,true,false],$calls[1]['args']);
    }
    public function testUnusedUnknownTupleSlotsRemainUnknownWhenTheyAreLaterConsumed():void
    {
        $decoder=new LightspeedDecoder();$calls=$decoder->decode([1,[4,2,1,[5,'LSUnknownTuple']],[5,'LSFixture','independent']]);
        self::assertCount(2,$calls);
        try{$decoder->decode([1,[4,2,1,[5,'LSUnknownTuple']],[23,[2,2],[5,'LSFixture','must not escape']]]);self::fail('An unknown result cannot authorize a branch');}
        catch(MessengerException $error){self::assertSame('unsupported_lightspeed_result',$error->reason);}
        self::assertSame([['name'=>'LSFixture','args'=>['new batch']]],$decoder->decode([5,'LSFixture','new batch']));
    }
    public function testKnownFalseTupleDoesNotBecomeTheStoreCountOrATrueGuard():void
    {
        $resolver=static fn(string $name,array $args):LightspeedProcedureResult=>LightspeedProcedureResult::known([false]);
        $calls=(new LightspeedDecoder(procedureResultResolver:$resolver))->decode([1,[4,0,1,[5,'LSKnownFalseTuple']],[23,[2,0],[105,'unreachable']]]);
        self::assertCount(1,$calls);self::assertSame('LSKnownFalseTuple',$calls[0]['name']);
    }
    public function testArraysAppendThroughAliasesAndCaptureArgumentsAtTheTimeOfEachCall():void
    {
        $calls=(new LightspeedDecoder())->decode([1,
            [3,0,[50]],[3,1,[2,0]],[3,2,[51,[2,1],'a']],
            [5,'LSFixture',[2,0]], [51,[2,2],'b'],
            [3,3,[50,[2,0]]],[51,[2,3],'copy-only'],
            [5,'LSFixture',[2,0],[2,3],[52,[2,0]]],
        ]);
        self::assertSame([['a']],$calls[0]['args']);
        self::assertSame([['a','b'],['a','b','copy-only'],'2'],$calls[1]['args']);
    }
    public function testMapsPreserveAliasesAndStringNumericKeysAsObjects():void
    {
        $calls=(new LightspeedDecoder())->decode([1,
            [3,0,[53]],[3,1,[2,0]],[3,2,[55,[2,1],'0','zero']],
            [55,[2,2],'name','MVO'],[55,[2,0],null,'ignored'],
            [5,'LSFixture',[2,0],[53],[50]],
        ]);
        self::assertInstanceOf(\stdClass::class,$calls[0]['args'][0]);
        self::assertSame('{"0":"zero","name":"MVO"}',json_encode($calls[0]['args'][0],JSON_THROW_ON_ERROR));
        self::assertSame('{}',json_encode($calls[0]['args'][1],JSON_THROW_ON_ERROR));
        self::assertSame([],$calls[0]['args'][2]);
    }
    public function testTypedMapKeysNeverCollapseIntoOnePhpArrayKey():void
    {
        $calls=(new LightspeedDecoder())->decode([1,[3,0,[53]],
            [55,[2,0],'0','string'],[55,[2,0],0,'number'],[55,[2,0],[19,'0'],'i64'],
            [5,'LSFixture',[54,[2,0],'0'],[54,[2,0],0.0],[54,[2,0],[19,'0']],[27,[54,[2,0],'missing']]],
        ]);
        self::assertSame(['string','number','i64',true],$calls[0]['args']);
        try{(new LightspeedDecoder())->decode([1,[3,0,[53]],[55,[2,0],0,'number'],[5,'LSFixture',[2,0]]]);self::fail('Mixed maps cannot be silently converted to string-key objects');}
        catch(MessengerException $error){self::assertSame('invalid_lightspeed_map_key',$error->reason);}
    }
    public function testCyclicContainersAbortWithoutReturningAnyCollectedEffects():void
    {
        foreach([
            [1,[3,0,[50]],[51,[2,0],[2,0]],[5,'LSFixture','first'],[5,'LSFixture',[2,0]]],
            [1,[3,0,[53]],[55,[2,0],'self',[2,0]],[5,'LSFixture','first'],[5,'LSFixture',[2,0]]],
        ] as $payload){
            $decoder=new LightspeedDecoder();
            try{$decoder->decode($payload);self::fail('Cyclic data must be bounded');}
            catch(MessengerException $error){self::assertSame('invalid_lightspeed_expression',$error->reason);}
            self::assertSame([['name'=>'LSFixture','args'=>['clean']]],$decoder->decode([5,'LSFixture','clean']));
        }
    }
    public function testContainerExportDepthAndUnpackCountShareTheDecoderBudgets():void
    {
        $payload=[1,[3,0,[50]]];for($i=1;$i<=10;$i++)$payload[]=[3,$i,[50,[8,[2,$i-1]]]];$payload[]=[5,'LSFixture',[2,10]];
        foreach([[$payload,new LightspeedDecoder(maximumDepth:6)],[[4,0,100,[8]],new LightspeedDecoder(maximumNodes:30)]] as [$ast,$decoder]){
            try{$decoder->decode($ast);self::fail('Container operations must remain bounded');}
            catch(MessengerException $error){self::assertSame('lightspeed_limit',$error->reason);}
        }
    }
    public function testMalformedContainerOperandsAreRejected():void
    {
        foreach([[4,0,-1,[8]],[4,65535,2,[8]],[4,0,1,false],[50,[8],null],[51,[53],'wrong type'],[55,[50],'key','wrong type']] as $payload){
            try{(new LightspeedDecoder())->decode($payload);self::fail('Expected invalid container operands');}
            catch(MessengerException $error){self::assertSame('invalid_lightspeed_expression',$error->reason);}
        }
    }
    public function testCurrentBooleanTupleAndShortCircuitContracts():void
    {
        $calls=(new LightspeedDecoder())->decode([1,[3,2,'two'],[3,3,'three'],
            [5,'LSFixture',[12,2,4],[13,true],[13,false],[24,'chosen',[105,'unreachable']],[25,0,[105,'unreachable']],[27,[9]],[13,[19,'0']]],
            [5,'LSFixture','after tuple expression'],
        ]);
        self::assertSame([[null,null,'two','three'],'1','0','chosen',0,true,'1'],$calls[0]['args']);
        self::assertSame(['after tuple expression'],$calls[1]['args']);
    }
    public function testProcedureOperandIsEvaluatedWithoutEverCallingPhpByName():void
    {
        $calls=(new LightspeedDecoder())->decode([1,[3,7,'fixture'],[5,[2,7],'text']],['fixture'=>'LSFixture']);
        self::assertSame([['name'=>'LSFixture','args'=>['text']]],$calls);
        $this->expectException(MessengerException::class);
        (new LightspeedDecoder())->decode([1,[3,7,'system'],[5,[2,7],'never execute']]);
    }
}
