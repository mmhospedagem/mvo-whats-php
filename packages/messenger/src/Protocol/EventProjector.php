<?php
declare(strict_types=1);
namespace MVO\Messenger\Protocol;

use MVO\Messenger\Auth\SessionStore;
use MVO\Messenger\MessengerException;

/** Projection is explicit per stored procedure; unknown rows are retained privately. */
final class EventProjector
{
    public function __construct(private readonly SessionStore $store, private readonly string $accountId) {}

    /** Upgrade only routing metadata from already committed private batches.
     * This never replays messages, acknowledgments, sync guards or cursor changes.
     */
    public function restoreRouting():void
    {
        if($this->store->get('projection.cutover.v1')==='complete')return;
        $this->store->transaction(function():void{
            $this->store->replayPrivateBatches(function(array $calls):void{
                foreach($calls as $call){
                    $name=str_starts_with($call['name'],'LS')?lcfirst(substr($call['name'],2)):$call['name'];
                    $this->routing($name,$call['args']);
                }
            });
            $this->store->set('projection.cutover.v1','complete');
        });
    }

    /** @param list<array{name:string,args:array<mixed>}> $calls
     * @return array{events:list<array{type:string,payload:array<string,mixed>}>,cursors:array<int,string>,completed:list<int>,unknown:list<string>}
     */
    public function apply(array $calls,?string $receiptDigest=null): array
    {
        $digest=hash('sha256',json_encode($calls,JSON_THROW_ON_ERROR));
        return $this->store->transaction(function () use($calls,$digest,$receiptDigest):array {
            if($receiptDigest!==null){
                if(!preg_match('/^[a-f0-9]{64}$/D',$receiptDigest))throw new \InvalidArgumentException('Invalid Messenger receipt digest');
                $this->store->set('receipt.'.$receiptDigest,'committed');
            }
            if (!$this->store->saveBatch($digest,$calls)) return ['events'=>[],'cursors'=>[],'completed'=>[],'unknown'=>[]];
            $events=[];$unknown=[];$transactions=[];$cursors=[];$completed=[];
            foreach($calls as $call){
                $name=str_starts_with($call['name'],'LS')?lcfirst(substr($call['name'],2)):$call['name'];$args=$call['args'];
                switch($name){
                    case 'insertMessage': case 'upsertMessage':
                        // Upsert offsets corroborated in shipped Meta code; Insert compared to the pinned protocol reference.
                        $thread=self::id($args[3]??null);$sender=self::id($args[10]??null);$id=$args[8]??null;
                        if (!is_string($id)||$id===''||strlen($id)>512||!is_string($args[0]??null)) throw new MessengerException('invalid_message_row','Messenger returned an invalid message row');
                        $payload=['channel'=>'messenger','id'=>$id,'thread_id'=>$thread,'sender_id'=>$sender,'from_me'=>$sender===$this->accountId,
                            'timestamp_ms'=>self::integer($args[5]??null),'type'=>'text','text'=>$args[0],'unsent'=>($args[17]??false)===true,
                            'is_admin_message'=>($args[12]??false)===true,'reply_to'=>is_string($args[23]??null)?$args[23]:null,'encrypted'=>false];
                        $this->append($events,'message',$payload,'message:'.$thread.':'.$id.':'.hash('sha256',json_encode([$args[0],$args[17]??false],JSON_THROW_ON_ERROR)));
                        break;
                    case 'deleteThenInsertThread':
                        $id=self::id($args[7]??null);$type=self::integer($args[9]??null);
                        $thread=['channel'=>'messenger','id'=>$id,'name'=>is_string($args[3]??null)?$args[3]:null,'type'=>$type,
                            'encrypted'=>in_array($type,[7,8,13,15,16],true),'last_activity_ms'=>self::integer($args[0]??null),
                            'plaintext_send_supported'=>in_array($type,[1,2,5,14],true)];
                        $previous=$this->store->thread($id);
                        // A known encrypted link never becomes a plaintext route after a metadata refresh.
                        if (($previous['encrypted_thread_id']??null)!==null) $thread=[...$thread,'encrypted'=>true,'plaintext_send_supported'=>false,'encrypted_thread_id'=>$previous['encrypted_thread_id']];
                        if(($args[10]??null)==='e2ee_cutover'||($previous['e2ee_cutover']??false)===true)$thread=[...$thread,'encrypted'=>true,'plaintext_send_supported'=>false,'e2ee_cutover'=>true];
                        $this->store->putThread($id,$thread);
                        $thread=$this->store->thread($id)??$thread;
                        $this->append($events,'thread',$thread,'thread:'.$id.':'.hash('sha256',json_encode($thread,JSON_THROW_ON_ERROR)));
                        break;
                    case 'createOpenToE2EEThreadLink': case 'moveThreadToE2EECutoverFolder':
                        $this->routing($name,$args);
                        $thread=$this->store->thread(self::id($args[0]??null));
                        if($thread!==null)$this->append($events,'thread',$thread,'thread:'.$thread['id'].':'.hash('sha256',json_encode($thread,JSON_THROW_ON_ERROR)));
                        break;
                    case 'hybridThreadDelete':
                        // The shipped Web implementation calls an empty native shim.
                        // In particular, this is not evidence to erase a cutover link.
                        break;
                    case 'replaceOptimsiticMessage': case 'replaceOptimisticMessage':
                        $offline=self::id($args[0]??null);$server=$args[1]??null;
                        if (!is_string($server)||$server===''||strlen($server)>512) throw new MessengerException('invalid_send_result','Invalid Messenger send acknowledgment');
                        if ($this->store->outgoing($offline)!==null){$this->store->markSent($offline,'server_ack',$server);$this->append($events,'message_ack',['id'=>$server,'offline_id'=>$offline,'status'=>'server_ack'],'ack:'.$offline.':'.$server);}
                        break;
                    case 'markOptimisticMessageFailed': case 'handleFailedTask':
                        $rawOffline=$args[$name==='handleFailedTask'?1:0]??null;
                        if((!is_string($rawOffline)&&!is_int($rawOffline))||!preg_match('/^[1-9][0-9]{0,24}$/D',(string)$rawOffline))break;
                        $offline=(string)$rawOffline;
                        if ($this->store->outgoing($offline)!==null){$this->store->markSent($offline,'failed');$this->append($events,'message_failed',['offline_id'=>$offline,'code'=>'upstream_task_failed'],'failed:'.$offline);}
                        break;
                    case 'executeFirstBlockForSyncTransaction': case 'executeFirstBlockForSyncTransactionV4':
                        $db=self::integer($args[0]??null);$epoch=self::epoch($args[1]??null);$current=$args[2]??null;$next=$args[3]??null;
                        if (($current!==null&&!is_string($current))||!is_string($next)) throw new MessengerException('invalid_sync_transaction','Invalid Messenger sync cursor');
                        if (isset($transactions[$db])) throw new MessengerException('invalid_sync_transaction','Nested Messenger sync transactions are not supported');
                        if(!is_bool($args[6]??null))throw new MessengerException('invalid_sync_transaction','Messenger returned an invalid sync parameter flag');
                        $transactions[$db]=['epoch'=>$epoch,'current'=>$current,'next'=>$next,'send_params'=>$args[6]];
                        break;
                    case 'executeFinallyBlockForSyncTransaction':
                        $db=self::integer($args[1]??null);$epoch=self::epoch($args[2]??null);$transaction=$transactions[$db]??null;
                        if ($transaction===null||$transaction['epoch']!==$epoch) throw new MessengerException('invalid_sync_transaction','Messenger sync transaction did not match');
                        // The boolean controls clearing the network request epoch.
                        // It is not an abort flag; the decoder validates guard acceptance.
                        if(!is_bool($args[0]??null))throw new MessengerException('invalid_sync_transaction','Messenger returned an invalid sync finalizer flag');
                        $stored=$this->store->get('cursor.'.$db);
                        if ($stored!==null&&$stored!==$transaction['current']&&$stored!==$transaction['next']) throw new MessengerException('sync_cursor_mismatch','Messenger sync cursor differs from the persisted transaction');
                        if($transaction['next']!==''&&$transaction['next']!=='dummy_cursor'){$this->store->set('cursor.'.$db,$transaction['next']);$cursors[$db]=$transaction['next'];}
                        $this->store->set('sync_send_params.'.$db,$transaction['send_params']?'1':'0');
                        $completed[]=$db;unset($transactions[$db]);
                        break;
                    default:
                        $unknown[$name]=true;
                }
            }
            if ($transactions!==[]) throw new MessengerException('incomplete_sync_transaction','Messenger sync transaction is incomplete; the previous cursor was preserved');
            return ['events'=>$events,'cursors'=>$cursors,'completed'=>array_values(array_unique($completed)),'unknown'=>array_keys($unknown)];
        });
    }
    /** @param array<mixed> $args */
    private function routing(string $name,array $args):void
    {
        if($name==='createOpenToE2EEThreadLink'){
            if(!is_bool($args[2]??null))throw new MessengerException('invalid_native_value','Messenger returned an invalid cutover history flag');
            $this->store->putCutoverLink(self::id($args[0]??null),self::id($args[1]??null),$args[2],self::integer($args[3]??null));
        }elseif($name==='moveThreadToE2EECutoverFolder'){
            $id=self::id($args[0]??null);$thread=$this->store->thread($id);
            if($thread!==null)$this->store->putThread($id,[...$thread,'encrypted'=>true,'plaintext_send_supported'=>false,'e2ee_cutover'=>true]);
        }
    }
    private static function id(mixed $value):string
    {
        if (!is_string($value)&&!is_int($value)) throw new MessengerException('invalid_native_id','Messenger returned an invalid native ID');
        $id=(string)$value;
        if (!preg_match('/^[1-9][0-9]{0,24}$/D',$id)) throw new MessengerException('invalid_native_id','Messenger returned an invalid native ID');
        return $id;
    }
    private static function epoch(mixed $value):string
    {
        if(is_int($value))$value=(string)$value;
        if(!is_string($value)||!preg_match('/^(?:0|[1-9][0-9]{0,18})$/D',$value)||(strlen($value)===19&&strcmp($value,'9223372036854775807')>0))throw new MessengerException('invalid_sync_transaction','Messenger returned an invalid sync epoch');
        return $value;
    }
    private static function integer(mixed $value):int
    {
        if (is_int($value)) return $value;
        if (is_string($value)&&preg_match('/^-?[0-9]{1,18}$/D',$value)) return (int)$value;
        throw new MessengerException('invalid_native_value','Messenger returned an invalid numeric field');
    }
    /** @param list<array{type:string,payload:array<string,mixed>}> $events
     * @param array<string,mixed> $payload
     */
    private function append(array &$events,string $type,array $payload,string $key):void
    {
        if ($this->store->event($type,$payload,$key)) $events[]=['type'=>$type,'payload'=>$payload];
    }
}
