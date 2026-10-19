<?php
declare(strict_types=1);
namespace MVO\Messenger\Tests;

use MVO\Messenger\MessengerException;
use MVO\Messenger\Protocol\{LightspeedDecoder,LightspeedProcedureResult,LightspeedSyncGuard,LightspeedUndefinedValue};
use PHPUnit\Framework\TestCase;

final class LightspeedSyncGuardTest extends TestCase
{
    private static function snapshot(?string $cursor=null,?string $epoch='123'):array
    {
        return ['request'=>['epochId'=>$epoch,'failureCount'=>0],'group'=>['cursor'=>$cursor,'syncStatus'=>0]];
    }
    private static function first(?string $previous=null,string $epoch='123',mixed $status=1):array
    {
        return ['1',$epoch,$previous,'next-cursor','99',$status,true,'0',false,'1'];
    }
    public function testCurrentV4AcceptsOnlyMatchingRequestEpochAndCursorAndKeepsTupleResult():void
    {
        $snapshot=self::snapshot();$reads=0;
        $guard=new LightspeedSyncGuard(static function(int $db)use(&$reads,$snapshot):array{$reads++;self::assertSame(1,$db);return $snapshot;});
        $guard->beginBatch();
        self::assertSame([true],$guard->resolve('LSExecuteFirstBlockForSyncTransactionV4',self::first())->value());
        self::assertSame(['next-cursor'],$guard->resolve('LSGetCursor',['1'])->value());
        self::assertSame([],$guard->resolve('LSExecuteFinallyBlockForSyncTransaction',[false,'1','123'])->value());
        $guard->finishBatch();
        self::assertSame(1,$reads);self::assertNull($snapshot['group']['cursor']);
    }
    public function testKnownMissingEpochOrCursorMatchReturnsFalseAndBatchCannotAdvance():void
    {
        foreach([
            ['request'=>null,'group'=>['cursor'=>null]],
            ['request'=>['epochId'=>'999','failureCount'=>0],'group'=>['cursor'=>null]],
            ['request'=>['epochId'=>'123','failureCount'=>0],'group'=>['cursor'=>'different']],
            ['request'=>['epochId'=>'123','failureCount'=>0],'group'=>null],
        ] as $snapshot){
            $guard=new LightspeedSyncGuard(static fn(int $db):array=>$snapshot);$guard->beginBatch();
            self::assertSame([false],$guard->resolve('executeFirstBlockForSyncTransactionV4',self::first())->value());
            $guard->resolve('executeFinallyBlockForSyncTransaction',[true,'1','123']);
            try{$guard->finishBatch();self::fail('A rejected guard cannot commit its cursor');}
            catch(MessengerException $e){self::assertSame('sync_guard_rejected',$e->reason);}
        }
    }
    public function testUnknownSnapshotNeverFabricatesAnAcceptedGuard():void
    {
        $guard=new LightspeedSyncGuard(static fn(int $db):null=>null);$guard->beginBatch();
        self::assertFalse($guard->resolve('executeFirstBlockForSyncTransactionV4',self::first())->known);
        $this->expectException(MessengerException::class);$guard->finishBatch();
    }
    public function testStatusIsPropagatedWithoutInventingARejectionCondition():void
    {
        foreach([0,1,2,'3',null] as $status){
            $guard=new LightspeedSyncGuard(static fn(int $db):array=>self::snapshot());$guard->beginBatch();
            self::assertSame([true],$guard->resolve('executeFirstBlockForSyncTransactionV4',self::first(status:$status))->value());
            $guard->resolve('executeFinallyBlockForSyncTransaction',[false,'1','123']);$guard->finishBatch();
        }
    }
    public function testGuardWorkingStateAllowsCursorChainOnlyWithinTheSameDecodedBatch():void
    {
        $snapshot=self::snapshot();$guard=new LightspeedSyncGuard(static fn(int $db):array=>$snapshot);$guard->beginBatch();
        $guard->resolve('executeFirstBlockForSyncTransactionV4',self::first());$guard->resolve('executeFinallyBlockForSyncTransaction',[false,'1','123']);
        self::assertSame([true],$guard->resolve('executeFirstBlockForSyncTransactionV4',self::first('next-cursor'))->value());
        $guard->resolve('executeFinallyBlockForSyncTransaction',[false,'1','123']);$guard->finishBatch();
        $guard->beginBatch();self::assertSame([null],$guard->resolve('getCursor',['1'])->value());
    }
    public function testFullDecoderValidatesBeforeReturningAnyPersistentProcedureCalls():void
    {
        $guard=new LightspeedSyncGuard(static fn(int $db):array=>self::snapshot());
        $decoder=new LightspeedDecoder(procedureResultResolver:$guard);
        $first=[5,'LSExecuteFirstBlockForSyncTransactionV4',...self::first()];
        $finally=[5,'LSExecuteFinallyBlockForSyncTransaction',false,'1','123'];
        $calls=$decoder->decode([1,$first,[5,'LSUpsertMessage','synthetic'],$finally]);
        self::assertCount(3,$calls);
        try{$decoder->decode([1,$first,[105,'not implemented'],$finally]);self::fail('No partially decoded batch may escape');}
        catch(MessengerException $e){self::assertSame('unsupported_lightspeed_opcode',$e->reason);}
        self::assertCount(2,$decoder->decode([1,$first,$finally]));
    }
    public function testRejectedFullDecoderBatchDoesNotReturnProjectableCalls():void
    {
        $guard=new LightspeedSyncGuard(static fn(int $db):array=>self::snapshot('wrong'));
        $this->expectException(MessengerException::class);
        (new LightspeedDecoder(procedureResultResolver:$guard))->decode([1,
            [5,'LSExecuteFirstBlockForSyncTransactionV4',...self::first()],
            [5,'LSUpsertMessage','must not escape'],
            [5,'LSExecuteFinallyBlockForSyncTransaction',true,'1','123'],
        ]);
    }
    public function testExplicitCallbackCanReturnFalseAndNullWithoutClaimingUnknownValue():void
    {
        $resolver=static fn(string $procedure,array $arguments):LightspeedProcedureResult=>match($procedure){
            'LSKnownFalse'=>LightspeedProcedureResult::known(false),
            'LSKnownNull'=>LightspeedProcedureResult::known(null),
            default=>LightspeedProcedureResult::unknown(),
        };
        $calls=(new LightspeedDecoder(procedureResultResolver:$resolver))->decode([1,
            [23,[5,'LSKnownFalse'],[5,'LSUpsertMessage','wrong']],
            [23,[27,[5,'LSKnownNull']],[5,'LSUpsertMessage','null matched']],
        ]);
        self::assertSame('null matched',$calls[2]['args'][0]);
    }
    public function testUnknownLegacyProcedureResultCannotAuthorizeAnUnpackedBranch():void
    {
        $guard=new LightspeedSyncGuard(static fn(int $db):array=>self::snapshot());$guard->beginBatch();
        self::assertFalse($guard->resolve('executeFirstBlockForSyncTransaction',self::first())->known);
        $this->expectException(MessengerException::class);
        (new LightspeedDecoder(procedureResultResolver:$guard))->decode([1,[4,0,1,[5,'LSUpsertMessage','unknown return']],[23,[2,0],[5,'LSUpsertMessage','must not escape']]]);
    }
    public function testUndefinedCursorOperandIsNotTreatedAsNull():void
    {
        $guard=new LightspeedSyncGuard(static fn(int $db):array=>self::snapshot());
        $arguments=self::first();$arguments[2]=[9];
        $this->expectException(MessengerException::class);
        (new LightspeedDecoder(procedureResultResolver:$guard))->decode([1,[5,'LSExecuteFirstBlockForSyncTransactionV4',...$arguments],[5,'LSExecuteFinallyBlockForSyncTransaction',false,'1','123']]);
    }
    public function testSeededUndefinedCursorMatchesOnlyAnUndefinedOperand():void
    {
        $snapshot=self::snapshot();$snapshot['group']['cursorKind']='undefined';
        $guard=new LightspeedSyncGuard(static fn(int $db):array=>$snapshot);
        $arguments=self::first();$arguments[2]=[9];
        $calls=(new LightspeedDecoder(procedureResultResolver:$guard))->decode([1,
            [4,0,1,[5,'LSExecuteFirstBlockForSyncTransactionV4',...$arguments]],
            [23,[2,0],[5,'LSUpsertMessage','accepted synthetic init']],
            [5,'LSExecuteFinallyBlockForSyncTransaction',false,'1','123'],
        ]);
        self::assertCount(3,$calls);
        self::assertSame('accepted synthetic init',$calls[1]['args'][0]);
        self::assertSame(['next-cursor'],$guard->resolve('getCursor',['1'])->value());
        // The reader's own initial snapshot was not modified by the transient guard.
        self::assertNull($snapshot['group']['cursor']);
        self::assertSame('undefined',$snapshot['group']['cursorKind']);
    }
    public function testExplicitUndefinedNeverMatchesNullEmptyOrStoredString():void
    {
        class_exists(LightspeedDecoder::class);
        foreach ([null,'','stored-cursor'] as $previous) {
            $snapshot=self::snapshot();$snapshot['group']['cursorKind']='undefined';
            $guard=new LightspeedSyncGuard(static fn(int $db):array=>$snapshot);$guard->beginBatch();
            self::assertSame([false],$guard->resolve('executeFirstBlockForSyncTransactionV4',self::first($previous))->value());
        }
        foreach ([null,'','stored-cursor'] as $cursor) {
            $guard=new LightspeedSyncGuard(static fn(int $db):array=>self::snapshot($cursor));$guard->beginBatch();
            $arguments=self::first();$arguments[2]=new LightspeedUndefinedValue();
            self::assertSame([false],$guard->resolve('executeFirstBlockForSyncTransactionV4',$arguments)->value());
        }
    }
    public function testGetCursorPreservesKnownUndefinedRatherThanReturningNull():void
    {
        class_exists(LightspeedDecoder::class);
        foreach ([null,['cursor'=>null,'cursorKind'=>'undefined']] as $group) {
            $snapshot=['request'=>['epochId'=>'123','failureCount'=>0],'group'=>$group];
            $guard=new LightspeedSyncGuard(static fn(int $db):array=>$snapshot);$guard->beginBatch();
            $result=$guard->resolve('getCursor',['1']);
            self::assertTrue($result->known);
            self::assertInstanceOf(LightspeedUndefinedValue::class,$result->value()[0]);
        }
        $guard=new LightspeedSyncGuard(static fn(int $db):array=>self::snapshot());$guard->beginBatch();
        self::assertSame([null],$guard->resolve('getCursor',['1'])->value());
    }
    public function testCursorKindMustBeExplicitAndConsistentWithItsValue():void
    {
        foreach ([
            ['cursorKind'=>'undefined'],
            ['cursor'=>'stored','cursorKind'=>'undefined'],
            ['cursor'=>null,'cursorKind'=>'string'],
            ['cursor'=>'stored','cursorKind'=>'null'],
            ['cursor'=>null,'cursorKind'=>'unknown'],
            ['cursor'=>null,'cursorKind'=>null],
        ] as $group) {
            $snapshot=['request'=>['epochId'=>'123','failureCount'=>0],'group'=>$group];
            $guard=new LightspeedSyncGuard(static fn(int $db):array=>$snapshot);$guard->beginBatch();
            try {
                $guard->resolve('getCursor',['1']);self::fail('Invalid cursor kinds cannot authorize a snapshot');
            } catch (\InvalidArgumentException $exception) {
                self::assertMatchesRegularExpression('/Invalid Messenger sync (?:group snapshot|cursor kind)/',$exception->getMessage());
            }
        }
        $guard=new LightspeedSyncGuard(static fn(int $db):null=>null);$guard->beginBatch();
        self::assertFalse($guard->resolve('getCursor',['1'])->known);
    }
    public function testEitherFinalizerFlagCompletesAnAcceptedGuardWithoutInventingAbort():void
    {
        foreach ([false,true] as $clearEpoch) {
            $guard=new LightspeedSyncGuard(static fn(int $db):array=>self::snapshot());$guard->beginBatch();
            self::assertSame([true],$guard->resolve('executeFirstBlockForSyncTransactionV4',self::first())->value());
            self::assertSame([],$guard->resolve('executeFinallyBlockForSyncTransaction',[$clearEpoch,'1','123'])->value());
            $guard->finishBatch();
            self::assertSame(['next-cursor'],$guard->resolve('getCursor',['1'])->value());
        }
    }
    public function testFinalizerFlagControlsEpochRetentionForSubsequentTransactions():void
    {
        foreach ([false,true] as $clearEpoch) {
            $guard=new LightspeedSyncGuard(static fn(int $db):array=>self::snapshot());$guard->beginBatch();
            $guard->resolve('executeFirstBlockForSyncTransactionV4',self::first());
            $guard->resolve('executeFinallyBlockForSyncTransaction',[$clearEpoch,'1','123']);
            self::assertSame([!$clearEpoch],$guard->resolve('executeFirstBlockForSyncTransactionV4',self::first('next-cursor'))->value());
        }
    }
    public function testFinalizerFlagCannotRescueARejectedGuard():void
    {
        foreach ([false,true] as $clearEpoch) {
            $guard=new LightspeedSyncGuard(static fn(int $db):array=>self::snapshot('wrong'));$guard->beginBatch();
            self::assertSame([false],$guard->resolve('executeFirstBlockForSyncTransactionV4',self::first())->value());
            $guard->resolve('executeFinallyBlockForSyncTransaction',[$clearEpoch,'1','123']);
            try {$guard->finishBatch();self::fail('A finalizer cannot rescue an unmatched first block');}
            catch (MessengerException $exception) {self::assertSame('sync_guard_rejected',$exception->reason);}
        }
    }
    public function testFinalizerFlagDoesNotRelaxEpochOrMissingFinalizerChecks():void
    {
        foreach ([false,true] as $clearEpoch) {
            $guard=new LightspeedSyncGuard(static fn(int $db):array=>self::snapshot());$guard->beginBatch();
            $guard->resolve('executeFirstBlockForSyncTransactionV4',self::first());
            try {$guard->resolve('executeFinallyBlockForSyncTransaction',[$clearEpoch,'1','999']);self::fail('A different finalizer epoch must be rejected');}
            catch (MessengerException $exception) {self::assertSame('invalid_sync_transaction',$exception->reason);}
            try {$guard->finishBatch();self::fail('The accepted first block still requires its own finalizer');}
            catch (MessengerException $exception) {self::assertSame('incomplete_sync_transaction',$exception->reason);}
        }
    }
}
