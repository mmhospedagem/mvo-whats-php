<?php
declare(strict_types=1);

namespace MVO\History;

/**
 * Trusted, synchronous consumer of authenticated HistorySync records.
 *
 * Items are provisional until commit(). Stage them under the stable chunk id and
 * deduplicate retries; the SDK cannot make an external database transaction atomic
 * with its own store. Callbacks must return before the next item is read and must
 * not issue SDK queries, sends, or recursively drain history. Keep this consumer
 * private: raw records can contain message content and cryptographic metadata.
 */
interface HistorySyncSink
{
    /**
     * A repeated field produces one item per value. Conversation messages use
     * kind=conversations.messages and conversationId; kind=conversations contains
     * the conversation metadata without its messages, after that conversation's
     * message items. Unknown protobuf fields and syncType are not separate items.
     *
     * @param array{kind:string,value:mixed,conversationId?:string} $item
     * @param array<string,mixed> $chunk Stable id plus syncType/chunk metadata.
     */
    public function item(array $item, array $chunk): void;

    /**
     * Publish the staged chunk. The SDK acknowledges it only after this returns.
     * A repeated commit must be safe if the process died before its checkpoint.
     *
     * @param array<string,mixed> $chunk
     */
    public function commit(array $chunk): void;

    /**
     * Discard/mark provisional work from a failed attempt. Cleanup must tolerate
     * connection cancellation. A cleanup exception never replaces the cause.
     *
     * @param array<string,mixed> $chunk
     */
    public function abort(array $chunk, \Throwable $error): void;
}
