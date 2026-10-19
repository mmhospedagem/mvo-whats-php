<?php
declare(strict_types=1);

namespace MVO\Facebook\Monitoring;

/** One ordered page of a caller-owned collection attempt. */
final readonly class SnapshotPage
{
    /** complete means the entire collection was observed, not just an HTTP success.
     * A terminal partial result has nextCursor=null and complete=false.
     * @param list<SnapshotItem> $items
     */
    public function __construct(
        public string $snapshotId,
        public array $items,
        public ?string $cursor = null,
        public ?string $nextCursor = null,
        public bool $complete = false,
    ) {
        if ($snapshotId === '' || strlen($snapshotId) > 256 || !preg_match('//u', $snapshotId)) {
            throw new \InvalidArgumentException('Invalid monitor snapshot ID');
        }
        if (!array_is_list($items) || count($items) > 5_000) throw new \InvalidArgumentException('Invalid normalized snapshot page');
        foreach ($items as $item) if (!$item instanceof SnapshotItem) throw new \InvalidArgumentException('Expected normalized snapshot items');
        foreach ([$cursor, $nextCursor] as $value) {
            if ($value !== null && ($value === '' || strlen($value) > 16_384 || !preg_match('//u', $value))) throw new \InvalidArgumentException('Invalid monitor pagination cursor');
        }
        if ($complete && $nextCursor !== null) throw new \InvalidArgumentException('An exhaustive snapshot must end pagination');
        if ($nextCursor !== null && $nextCursor === $cursor) throw new \InvalidArgumentException('Monitor pagination did not advance');
    }

    /** @return array{snapshot_id:string,items:list<array{id:string,fields:array<string,string|int|bool|null>,counters:array<string,int>}>,cursor:?string,next_cursor:?string,complete:bool} */
    public function toArray(): array
    {
        return ['snapshot_id' => $this->snapshotId, 'items' => array_map(static fn(SnapshotItem $item): array => $item->toArray(), $this->items), 'cursor' => $this->cursor, 'next_cursor' => $this->nextCursor, 'complete' => $this->complete];
    }
    public function __debugInfo(): array { return ['snapshot_id' => $this->snapshotId, 'item_count' => count($this->items), 'complete' => $this->complete]; }
}
