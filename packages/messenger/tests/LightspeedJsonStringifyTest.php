<?php
declare(strict_types=1);

namespace MVO\Messenger\Tests;

use MVO\Messenger\MessengerException;
use MVO\Messenger\Protocol\LightspeedDecoder;
use PHPUnit\Framework\TestCase;

/** Opcode 61: LSDatascriptJSONEval -> LS.toJSON -> LSJson.stringify. */
final class LightspeedJsonStringifyTest extends TestCase
{
    public function testNativeJsonRetainsTypedIntegersAndNumericBooleans():void
    {
        $calls=(new LightspeedDecoder())->decode([1,[3,0,[53]],
            [55,[2,0],'id',[19,'9223372036854775807']],
            [55,[2,0],'active',true],[55,[2,0],'missing',[9]],
            [55,[2,0],'text',"MVO / \"texto\"\nç"],
            [5,'LSFixture',[61,[2,0]],[61,[8,false,null,[19,'-9223372036854775808']]]],
        ]);
        self::assertSame('{"id":9223372036854775807,"active":1,"missing":null,"text":"MVO / \\"texto\\"\\nç"}',$calls[0]['args'][0]);
        self::assertSame('[0,null,-9223372036854775808]',$calls[0]['args'][1]);
    }

    public function testEmptyContainersAndNullishValuesKeepNativeShape():void
    {
        $calls=(new LightspeedDecoder())->decode([5,'LSFixture',[61,[53]],[61,[50]],[61,[9]],[61,true],[61,false],[61,'0']]);
        self::assertSame(['{}','[]','null','1','0','"0"'],$calls[0]['args']);
    }

    public function testNumbersFollowJavascriptFixedAndExponentialFormatting():void
    {
        $calls=(new LightspeedDecoder())->decode([5,'LSFixture',[61,[8,1.0,-0.0,1.25,0.00001,0.000001,0.0000001,-0.0000001]]]);
        self::assertSame('[1,0,1.25,0.00001,0.000001,1e-7,-1e-7]',$calls[0]['args'][0]);
    }

    public function testSerializationEvaluatesTheOperandOnceAndCapturesAnImmutableString():void
    {
        $calls=(new LightspeedDecoder())->decode([1,[3,0,[50]],
            [3,1,[61,[51,[2,0],'first']]],[51,[2,0],'later'],[5,'LSFixture',[2,1],[61,[2,0]]],
        ]);
        self::assertSame(['["first"]','["first","later"]'],$calls[0]['args']);
    }

    public function testUnsupportedOperandsAndCyclesAbortTheWholeBatch():void
    {
        foreach([
            [61],[61,'extra','operand'],
            [1,[3,0,[53]],[55,[2,0],12,'not a string key'],[61,[2,0]]],
            [1,[3,0,[50]],[51,[2,0],[2,0]],[61,[2,0]]],
            [1,[3,0,[5,'LSUnknownProcedure']],[61,[2,0]]],
            [61,9223372036854775807],
        ]as $expression){
            $decoder=new LightspeedDecoder();
            try{$decoder->decode([1,[5,'LSFixture','must not escape'],$expression]);self::fail('Invalid JSON must abort the batch');}
            catch(MessengerException $error){self::assertContains($error->reason,['invalid_lightspeed_expression','invalid_lightspeed_map_key','unsupported_lightspeed_result','invalid_lightspeed_number']);}
            self::assertSame([['name'=>'LSFixture','args'=>['clean']]],$decoder->decode([5,'LSFixture','clean']));
        }
    }

    public function testSerializationUsesTheSharedEvaluationAndDepthBudgets():void
    {
        $ast=[1,[3,0,[8,'leaf']]];
        for($i=1;$i<12;$i++)$ast[]=[3,$i,[8,[2,$i-1]]];
        $ast[]=[5,'LSFixture',[61,[2,11]]];
        foreach([new LightspeedDecoder(maximumDepth:7),new LightspeedDecoder(maximumNodes:40)]as $decoder){
            try{$decoder->decode($ast);self::fail('Serialization must remain bounded');}
            catch(MessengerException $error){self::assertSame('lightspeed_limit',$error->reason);}
        }
    }
}
