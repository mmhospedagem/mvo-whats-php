<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Protocol\{BinaryCodec,BinaryData,BinaryNode};
use MVO\Signal\SignalStore;

/** Receipts commit before transport and remain pending until the server ACK. */
final class ReceiptQueue
{
    /** @param \Closure(BinaryNode):void $send */
    public function __construct(private readonly SignalStore $store,private readonly \Closure $send) {}

    public function enqueue(BinaryNode $node):void
    {
        if ($node->tag!=='receipt' || empty($node->attrs['id']) || empty($node->attrs['to'])) throw new \InvalidArgumentException('Invalid queued receipt');
        $encoded=(new BinaryCodec())->encode($node);
        $key=hash('sha256',$encoded);
        if ($this->store->get('receipt-queue',$key)===null) $this->store->set('receipt-queue',$key,BinaryData::encode([
            'node'=>$encoded,'attrs'=>$node->attrs,'attempt'=>0,'nextAttempt'=>0,'createdAt'=>time(),
        ]));
        $this->flush($key);
    }

    public function handleAck(BinaryNode $node):bool
    {
        if ($node->tag!=='ack' || ($node->attrs['class'] ?? '')!=='receipt') return false;
        if (isset($node->attrs['error']) || $node->child('error')!==null) return true;
        foreach ($this->store->entries('receipt-queue',1000) as $key=>$bytes) {
            $record=BinaryData::decode($bytes); $attrs=$record['attrs'] ?? [];
            if (($attrs['id'] ?? '')!==($node->attrs['id'] ?? '')) continue;
            if (isset($node->attrs['from']) && $node->attrs['from']!==($attrs['to'] ?? '')) continue;
            if (($attrs['type'] ?? '')!==($node->attrs['type'] ?? '')) continue;
            if (($attrs['participant'] ?? '')!==($node->attrs['participant'] ?? '')) continue;
            $this->store->delete('receipt-queue',(string)$key);
        }
        return true;
    }

    public function drainPending():void
    {
        foreach ($this->store->entries('receipt-queue',1000) as $key=>$bytes) {
            $record=BinaryData::decode($bytes);
            if (($record['nextAttempt'] ?? 0)<=time()) $this->flush((string)$key);
        }
    }
    private function flush(string $key):void
    {
        $bytes=$this->store->get('receipt-queue',$key); if ($bytes===null) return;
        $record=BinaryData::decode($bytes);
        if (!is_array($record) || !is_string($record['node'] ?? null)) throw new \UnexpectedValueException('Invalid pending receipt');
        $record['attempt']=($record['attempt'] ?? 0)+1;
        $record['nextAttempt']=time()+min(300,2**min(8,$record['attempt']));
        $this->store->set('receipt-queue',$key,BinaryData::encode($record));
        try { ($this->send)((new BinaryCodec())->decode($record['node'])); }
        catch (\Throwable) { /* Persisted; reconnect drain will retry the exact node. */ }
    }
}
