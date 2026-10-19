<?php
declare(strict_types=1);
namespace MVO\Messenger\Protocol;

use MVO\Messenger\MessengerException;

/**
 * Read-only projection of the current shipped V4 sync guard's branch conditions.
 * It models only network_requests epoch/failure and sync_groups cursor/status,
 * never executes arbitrary procedures and never commits database changes.
 *
 * A snapshot reader returns null for UNKNOWN state; explicit request/group null
 * means the corresponding row is KNOWN to be absent. Present rows must contain
 * the named fields. An absent cursor key is not the same as a null cursor.
 * Initial seeded groups explicitly use cursor=null,cursorKind="undefined";
 * this is distinct from the wire JSON last_applied_cursor=null. Omitted
 * cursorKind retains the existing explicit null/string snapshot contract.
 * The request row must be the network_requests row for taskQueueName="" and
 * the requested database, rather than a queued task with another name.
 * @phpstan-type SyncSnapshot array{request:?array{epochId:?string,failureCount:int},group:?array{cursor:?string,cursorKind?:'undefined'|'null'|'string',syncStatus?:mixed}}
 */
final class LightspeedSyncGuard implements LightspeedProcedureResolver
{
    private readonly \Closure $reader;
    /** @var array<int,SyncSnapshot|null> */ private array $snapshots=[];
    /** @var array<int,array{epoch:string,accepted:bool}> */ private array $transactions=[];
    private bool $rejected=false;
    private bool $unknownGuard=false;
    private bool $syncFailed=false;

    /** @param callable(int):(?array{request:?array{epochId:?string,failureCount:int},group:?array{cursor:?string,cursorKind?:'undefined'|'null'|'string',syncStatus?:mixed}}) $snapshotReader */
    public function __construct(callable $snapshotReader) { $this->reader=\Closure::fromCallable($snapshotReader); }

    public function beginBatch(): void { $this->snapshots=[]; $this->transactions=[]; $this->rejected=false; $this->unknownGuard=false; $this->syncFailed=false; }

    /** @param list<mixed> $arguments */
    public function resolve(string $procedure,array $arguments): LightspeedProcedureResult
    {
        $name=str_starts_with($procedure,'LS')?lcfirst(substr($procedure,2)):$procedure;
        $result=match($name) {
            'executeFirstBlockForSyncTransactionV4'=>$this->first($arguments),
            'executeFinallyBlockForSyncTransaction'=>$this->finally($arguments),
            'getCursor'=>$this->cursor($arguments),
            'handleSyncFailure'=>$this->failure($arguments),
            default=>LightspeedProcedureResult::unknown(),
        };
        // The projector also recognizes the old first-block name. Its result is
        // not established here; collecting it must never bypass the V4 guard.
        if (!$result->known && in_array($name,['executeFirstBlockForSyncTransaction','executeFirstBlockForSyncTransactionV4','executeFinallyBlockForSyncTransaction'],true)) $this->unknownGuard=true;
        return $result;
    }

    public function finishBatch(): void
    {
        if ($this->unknownGuard) throw new MessengerException('unsupported_lightspeed_result','The sync guard requires an explicit network epoch and group snapshot; the batch was not applied');
        if ($this->rejected) throw new MessengerException('sync_guard_rejected','Messenger sync epoch or cursor did not match the explicit local snapshot; the batch was not applied');
        if ($this->transactions!==[]) throw new MessengerException('incomplete_sync_transaction','Messenger sync did not close its guarded transaction; the batch was not applied');
        if ($this->syncFailed) throw new MessengerException('messenger_sync_failed','Messenger refused synchronization; saved credentials and cursors were preserved',true);
    }

    /** @param list<mixed> $arguments */
    private function failure(array $arguments):LightspeedProcedureResult
    {
        if(count($arguments)!==2||$this->database($arguments[0])===null||!is_string($arguments[1]))throw new MessengerException('invalid_sync_transaction','Messenger returned an invalid sync failure record');
        // The remote message is private diagnostic input, not a public error or
        // an instruction to reset credentials/cursors or repeat authentication.
        $this->syncFailed=true;
        return LightspeedProcedureResult::known([]);
    }

    /** @param list<mixed> $arguments */
    private function first(array $arguments): LightspeedProcedureResult
    {
        // The current V4 source reads slots0..9; slot10 is optional trace annotations.
        if (count($arguments)<10 || ($database=$this->database($arguments[0]??null))===null) return LightspeedProcedureResult::unknown();
        $epoch=$this->decimal($arguments[1]??null);
        $previous=$arguments[2]??null; $next=$arguments[3]??null;
        if ($epoch===null || (!is_string($previous)&&$previous!==null&&!($previous instanceof LightspeedUndefinedValue)) || !is_string($next)) return LightspeedProcedureResult::unknown();
        $snapshot=$this->snapshot($database);
        if ($snapshot===null) return LightspeedProcedureResult::unknown();
        if (isset($this->transactions[$database])) throw new MessengerException('invalid_sync_transaction','Nested Messenger V4 sync guards are not supported');
        $request=$snapshot['request']; $group=$snapshot['group'];
        $cursorMatches=$group!==null && (($group['cursorKind']??null)==='undefined'
            ? $previous instanceof LightspeedUndefinedValue
            : !($previous instanceof LightspeedUndefinedValue) && $group['cursor']===$previous);
        if ($request===null || $request['epochId']!==$epoch || !$cursorMatches) {
            $this->transactions[$database]=['epoch'=>$epoch,'accepted'=>false];
            $this->rejected=true;
            return LightspeedProcedureResult::known([false]);
        }
        $this->transactions[$database]=['epoch'=>$epoch,'accepted'=>true];
        // These are transient values only. The separate projector commits after all instructions validate.
        $this->snapshots[$database]=['request'=>['epochId'=>$epoch,'failureCount'=>-1],
            'group'=>['cursor'=>$next,'cursorKind'=>'string','syncStatus'=>$arguments[5]]];
        return LightspeedProcedureResult::known([true]);
    }

    /** @param list<mixed> $arguments */
    private function finally(array $arguments): LightspeedProcedureResult
    {
        if (count($arguments)<3 || !is_bool($arguments[0]) || ($database=$this->database($arguments[1]??null))===null) return LightspeedProcedureResult::unknown();
        $epoch=$this->decimal($arguments[2]);
        if ($epoch===null) return LightspeedProcedureResult::unknown();
        $snapshot=$this->snapshot($database);
        if ($snapshot===null) return LightspeedProcedureResult::unknown();
        $transaction=$this->transactions[$database]??null;
        if ($transaction!==null && $transaction['epoch']!==$epoch) throw new MessengerException('invalid_sync_transaction','Messenger V4 sync finalizer epoch does not match its guard');
        $request=$snapshot['request'];
        if ($request!==null && $request['epochId']===$epoch) {
            $previous=$request['failureCount'];
            if ($arguments[0] || $previous!==-1) $request['epochId']=null;
            $request['failureCount']=$previous===-1?0:$previous+1;
            $snapshot['request']=$request; $this->snapshots[$database]=$snapshot;
        }
        if ($transaction!==null) {
            // Slot 0 controls request-epoch retention, not transaction success.
            // The shipped procedure only updates the matching network row and returns [].
            if (!$transaction['accepted']) $this->rejected=true;
            unset($this->transactions[$database]);
        }
        // The shipped finalizer returns an empty array, regardless of row-match count.
        return LightspeedProcedureResult::known([]);
    }

    /** @param list<mixed> $arguments */
    private function cursor(array $arguments): LightspeedProcedureResult
    {
        if (count($arguments)!==1 || ($database=$this->database($arguments[0]))===null) return LightspeedProcedureResult::unknown();
        $snapshot=$this->snapshot($database);
        if ($snapshot===null) return LightspeedProcedureResult::unknown();
        // Both a known missing row and a seeded group's cursor return JS undefined.
        if ($snapshot['group']===null || ($snapshot['group']['cursorKind']??null)==='undefined') return LightspeedProcedureResult::known([new LightspeedUndefinedValue()]);
        return LightspeedProcedureResult::known([$snapshot['group']['cursor']]);
    }

    /** @return SyncSnapshot|null */
    private function snapshot(int $database): ?array
    {
        if (array_key_exists($database,$this->snapshots)) return $this->snapshots[$database];
        $value=($this->reader)($database);
        if ($value===null) return $this->snapshots[$database]=null;
        if (!is_array($value) || !array_key_exists('request',$value) || !array_key_exists('group',$value)) throw new \InvalidArgumentException('An explicit Messenger sync snapshot must identify both request and group rows');
        $request=$value['request'];$group=$value['group'];
        if ($request!==null && (!is_array($request) || !array_key_exists('epochId',$request) || ($request['epochId']!==null&&$this->decimal($request['epochId'])===null) || !is_int($request['failureCount']??null) || $request['failureCount'] < -1 || $request['failureCount']>1000000)) throw new \InvalidArgumentException('Invalid Messenger network request snapshot');
        if ($group!==null) {
            if (!is_array($group) || !array_key_exists('cursor',$group) || ($group['cursor']!==null&&!is_string($group['cursor']))) throw new \InvalidArgumentException('Invalid Messenger sync group snapshot');
            $kind=array_key_exists('cursorKind',$group)?$group['cursorKind']:($group['cursor']===null?'null':'string');
            if (!in_array($kind,['undefined','null','string'],true) || ($kind==='string'?!is_string($group['cursor']):$group['cursor']!==null)) throw new \InvalidArgumentException('Invalid Messenger sync cursor kind');
            $group['cursorKind']=$kind;
        }
        return $this->snapshots[$database]=['request'=>$request,'group'=>$group];
    }

    private function decimal(mixed $value): ?string
    {
        if (is_int($value)) $value=(string)$value;
        return is_string($value)&&preg_match('/^(?:0|[1-9][0-9]{0,18})$/D',$value) && (strlen($value)<19 || strcmp($value,'9223372036854775807')<=0)?$value:null;
    }
    private function database(mixed $value): ?int
    {
        if (is_string($value)&&preg_match('/^(?:0|[1-9][0-9]{0,5})$/D',$value)) $value=(int)$value;
        return is_int($value)&&$value>=0&&$value<=65535?$value:null;
    }
    /** @return array{cachedDatabases:list<int>,openTransactions:int} */
    public function __debugInfo(): array { return ['cachedDatabases'=>array_keys($this->snapshots),'openTransactions'=>count($this->transactions)]; }
}
