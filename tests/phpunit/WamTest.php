<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Telemetry\{WamEncoder,WamBatch,WamService,WamRegistry};
use MVO\Protocol\BinaryNode;
use MVO\Service\ServiceException;

final class WamTest extends TestCase
{
    public static function globals(): iterable
    {
        yield [5,0,'1005'];yield [5,1,'2005'];yield [5,100,'300564'];yield [5,300,'4005012c'];yield [5,'hi','8005026869'];yield [5,null,'0005'];yield [5,true,'2005'];yield [300,1,'28012c'];yield [5,-128,'300580'];yield [5,128,'40050080'];yield [5,-32768,'40058000'];yield [5,32768,'500500008000'];yield [5,2147483648,'60050000000080000000'];yield [5,PHP_INT_MIN,'60058000000000000000'];yield [5,PHP_INT_MAX,'60057fffffffffffffff'];
    }
    #[DataProvider('globals')]
    public function testNativeScalarsMatchUpstreamWireClasses(int $id,int|string|bool|null $value,string $hex): void {self::assertSame($hex,bin2hex(WamEncoder::global($id,$value)));}
    public function testByteExactUpstreamBatchVector(): void
    {
        $b=new WamBatch('regular',7,1);$b->writeEvent(100000,472,1,[['id'=>1,'kind'=>'int','value'=>3]]);self::assertTrue($b->hasEvents());self::assertSame('57414d0507000100302f642901d8360103',bin2hex($b->toBytes()));
    }
    public function testFloatBooleanStringLengthClassesAndEmptyEvent(): void
    {
        self::assertSame('76013ff8000000000000',bin2hex(WamEncoder::field(1,'float',1.5,true)));self::assertSame('1601',bin2hex(WamEncoder::field(1,'bool',false,true)));self::assertSame('90010100',bin2hex(substr(WamEncoder::global(1,str_repeat('a',256)),0,4)));self::assertSame('a00100010000',bin2hex(substr(WamEncoder::global(1,str_repeat('a',65536)),0,6)));self::assertSame('2505',bin2hex(WamEncoder::event(5,1,false)));
    }
    public function testDeltaGlobalsAndFailedAppendDoNotMutateBatch(): void
    {
        $b=new WamBatch(globals:[3543=>42]);$bytes=$b->toBytes();$b->setGlobal(3543,42);self::assertSame($bytes,$b->toBytes());$b->setGlobal(3543,99);self::assertGreaterThan(strlen($bytes),$b->size());$before=$b->toBytes();try{$b->writeEvent(1,1,1,[['id'=>1,'kind'=>'string','value'=>str_repeat('x',64000)]]);self::fail('Expected limit');}catch(\LengthException){}self::assertSame($before,$b->toBytes());self::assertFalse($b->hasEvents());
    }
    public function testRegistryPinnedEnumsAndFields(): void
    {
        self::assertCount(426,WamRegistry::events());$event=WamRegistry::event('UiAction');self::assertIsInt($event['id']);$fields=WamRegistry::fields('UiAction',['uiActionType'=>'CHAT_OPEN','uiActionT'=>142]);self::assertCount(2,$fields);self::assertSame('int',$fields[0]['kind']);self::assertIsInt($fields[0]['value']);$byId=array_column($fields,'value','id');self::assertSame(142,$byId[$event['fields']['uiActionT']['id']]);self::assertSame(3,$byId[$event['fields']['uiActionType']['id']]);
    }
    public function testDisabledByDefaultNeverSendsEvenOnCommitAndFlush(): void
    {
        $calls=0;$s=new WamService(static function(BinaryNode $n,float $t)use(&$calls):BinaryNode{$calls++;return new BinaryNode('iq',['type'=>'result']);});self::assertFalse($s->commit('UiAction',['uiActionType'=>'CHAT_OPEN']));self::assertSame([],$s->flush());self::assertSame(0,$calls);
    }
    public function testEnabledWamUploadsActualStatsIqAndKeepsFailedBatches(): void
    {
        $nodes=[];$fail=true;$s=new WamService(static function(BinaryNode $n,float $t)use(&$nodes,&$fail):BinaryNode{$nodes[]=$n;return $fail ? new BinaryNode('iq',['type'=>'error'],[new BinaryNode('error',['code'=>'500'])]) : new BinaryNode('iq',['type'=>'result']);},enabled:true,streamId:7);
        $s->commitById(472,[['id'=>1,'kind'=>'int','value'=>3]],commitTimeMs:100000);try{$s->flush();self::fail('Expected server refusal');}catch(ServiceException $e){self::assertSame('500',$e->serverCode);}self::assertArrayHasKey('regular',$s->pending());$fail=false;$done=$s->flush();self::assertSame(strlen($nodes[0]->child('add')->content),$done['regular']);self::assertSame('w:stats',$nodes[0]->attrs['xmlns']);self::assertSame('57414d0507000100302f642901d8360103',bin2hex($nodes[0]->child('add')->content));self::assertSame([],$s->pending());
    }
    public static function invalid(): iterable
    {
        yield ['id',-1];yield ['id',65536];yield ['field','int','3'];yield ['field','bool',1];yield ['field','float',INF];yield ['field','string',"\xff"];yield ['field','unknown',1];
    }
    #[DataProvider('invalid')]
    public function testInvalidWireValuesFail(string $type,mixed $kind,mixed $value=null): void {$this->expectException(\InvalidArgumentException::class);if($type==='id')WamEncoder::global($kind,1);else WamEncoder::field(1,$kind,$value,false);}
}
