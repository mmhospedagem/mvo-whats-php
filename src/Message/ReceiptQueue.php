<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Protocol\{BinaryCodec,BinaryData,BinaryNode};
use MVO\Signal\SignalStore;

/** Receipts commit before transport and retry failed writes, without requiring a server ACK. */
final class ReceiptQueue
{
    private const PAGE_SIZE = 256;
    private const RETRIES_PER_DRAIN = 32;
    private bool $loaded = false;
    private int $version = 0;
    /** @var array<string,array{due:int,version:int,ack:string}> */
    private array $pending = [];
    /** @var array<string,array<string,true>> */
    private array $acks = [];
    /** @var \SplPriorityQueue<array{int,int},array{key:string,version:int}> */
    private \SplPriorityQueue $due;

    /** @param \Closure(BinaryNode):void $send */
    public function __construct(private readonly SignalStore $store,private readonly \Closure $send)
    {
        $this->due = new \SplPriorityQueue();
        $this->due->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
    }

    public function enqueue(BinaryNode $node):void
    {
        if ($node->tag!=='receipt' || empty($node->attrs['id']) || empty($node->attrs['to'])) throw new \InvalidArgumentException('Invalid queued receipt');
        $this->load();
        $encoded=(new BinaryCodec())->encode($node);
        $key=hash('sha256',$encoded);
        if ($this->store->get('receipt-queue',$key)===null) $this->store->set('receipt-queue',$key,BinaryData::encode([
            'node'=>$encoded,'attrs'=>$node->attrs,'attempt'=>0,'nextAttempt'=>0,'createdAt'=>time(),
        ]));
        $this->flush($key);
        $this->compact();
    }

    public function handleAck(BinaryNode $node):bool
    {
        if ($node->tag!=='ack' || ($node->attrs['class'] ?? '')!=='receipt') return false;
        if (isset($node->attrs['error']) || $node->child('error')!==null) return true;
        $this->load();
        foreach (array_keys($this->acks[self::ackKey($node->attrs)] ?? []) as $indexedKey) {
            $key=(string)$indexedKey;
            // Only candidates for this ACK are loaded, even beyond the first persisted page.
            $record=$this->store->transaction(function()use($node,$key):?array {
                $bytes=$this->store->get('receipt-queue',$key);
                if($bytes===null)return null;
                $record=self::record($bytes);$attrs=$record['attrs'];
                if (self::ackKey($attrs)!==self::ackKey($node->attrs)
                    || (isset($node->attrs['from']) && $node->attrs['from']!==($attrs['to'] ?? ''))) return $record;
                $this->store->delete('receipt-queue',$key);
                return null;
            });
            if($record===null)$this->forget($key);
            else $this->remember($key,$record);
        }
        $this->compact();
        return true;
    }

    public function drainPending():void
    {
        $this->load();
        $attempted=0;$examined=0;
        while(!$this->due->isEmpty() && $attempted<self::RETRIES_PER_DRAIN && $examined<self::PAGE_SIZE) {
            $entry=$this->due->top();$pending=$this->pending[$entry['key']] ?? null;
            if($pending===null || $pending['version']!==$entry['version']) {
                $this->due->extract();++$examined;continue;
            }
            // Compare the wall clock afresh, including after clock changes. No database scan while idle.
            if($pending['due']>time())break;
            $this->due->extract();++$examined;++$attempted;
            try{$this->flush($entry['key'],true);}
            catch(\Throwable $error) {
                if(($this->pending[$entry['key']]['version'] ?? null)===$entry['version'])
                    $this->due->insert($entry,[-$pending['due'],-$entry['version']]);
                throw $error;
            }
        }
        $this->compact();
    }
    private function flush(string $key,bool $retry=false):void
    {
        $bytes=$this->store->get('receipt-queue',$key);
        if ($bytes===null) {$this->forget($key);return;}
        $record=self::record($bytes);
        // Revalidate durable state: another code path may have removed or postponed this receipt.
        if($retry && ($record['nextAttempt'] ?? 0)>time()){$this->remember($key,$record);return;}
        $record['attempt']=($record['attempt'] ?? 0)+1;
        $record['nextAttempt']=time()+min(300,2**min(8,$record['attempt']));
        $committed=BinaryData::encode($record);
        $this->store->set('receipt-queue',$key,$committed);
        $this->remember($key,$record);
        $version=$this->pending[$key]['version'];
        // Commit and update the local index before transport. A synchronous ACK must stay removed.
        try { ($this->send)((new BinaryCodec())->decode($record['node'])); }
        catch (\Throwable) { return; /* Persisted; reconnect drain will retry the exact node. */ }
        // A successful socket write completes this receipt, not message delivery/read.
        // A callback may ACK or re-enqueue the same node: never delete its newer generation.
        if(($this->pending[$key]['version'] ?? null)!==$version)return;
        $remaining=$this->store->transaction(function()use($key,$committed):?array {
            $current=$this->store->get('receipt-queue',$key);
            if($current===null)return null;
            if($current!==$committed)return self::record($current);
            $this->store->delete('receipt-queue',$key);
            return null;
        });
        if(($this->pending[$key]['version'] ?? null)!==$version)return;
        if($remaining===null)$this->forget($key);
        else $this->remember($key,$remaining);
    }

    /** Restore derived indexes once per owner; the existing durable record remains authoritative. */
    private function load():void
    {
        if($this->loaded)return;
        $this->pending=[];$this->acks=[];$this->due=new \SplPriorityQueue();
        $this->due->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
        $after='';
        do {
            $page=$this->store->entries('receipt-queue',self::PAGE_SIZE,$after);
            foreach($page as$key=>$bytes){$after=(string)$key;$this->remember((string)$key,self::record($bytes));}
        }while(count($page)===self::PAGE_SIZE);
        $this->loaded=true;
    }

    /** @param array<string,string> $attrs */
    private static function ackKey(array $attrs):string
    {
        return hash('sha256',BinaryData::encode([$attrs['id'] ?? '',$attrs['type'] ?? '',$attrs['participant'] ?? '',$attrs['recipient'] ?? '']));
    }

    /** @return array{node:string,attrs:array<string,string>,attempt?:int,nextAttempt?:int,createdAt?:int} */
    private static function record(string $bytes):array
    {
        $record=BinaryData::decode($bytes);
        if(!is_array($record) || !is_string($record['node'] ?? null) || !is_array($record['attrs'] ?? null))
            throw new \UnexpectedValueException('Invalid pending receipt');
        foreach($record['attrs']as$value)if(!is_string($value))throw new \UnexpectedValueException('Invalid pending receipt attributes');
        foreach(['attempt','nextAttempt','createdAt']as$field)
            if(isset($record[$field]) && !is_int($record[$field]))throw new \UnexpectedValueException('Invalid pending receipt time or attempt');
        return $record;
    }

    /** @param array{node:string,attrs:array<string,string>,attempt?:int,nextAttempt?:int,createdAt?:int} $record */
    private function remember(string $key,array $record):void
    {
        $this->forget($key);
        $ack=self::ackKey($record['attrs']);$version=++$this->version;$due=$record['nextAttempt'] ?? 0;
        $this->pending[$key]=['due'=>$due,'version'=>$version,'ack'=>$ack];
        $this->acks[$ack][$key]=true;
        $this->due->insert(['key'=>$key,'version'=>$version],[-$due,-$version]);
    }

    private function forget(string $key):void
    {
        $ack=$this->pending[$key]['ack'] ?? null;
        if($ack!==null){unset($this->acks[$ack][$key]);if($this->acks[$ack]===[])unset($this->acks[$ack]);}
        unset($this->pending[$key]);
    }

    private function compact():void
    {
        if($this->due->count()<=max(self::PAGE_SIZE,2*count($this->pending)))return;
        $this->due=new \SplPriorityQueue();$this->due->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
        foreach($this->pending as$key=>$pending)
            $this->due->insert(['key'=>(string)$key,'version'=>$pending['version']],[-$pending['due'],-$pending['version']]);
    }
}
