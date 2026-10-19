<?php
declare(strict_types=1);
namespace MVO\Messenger\Protocol;

/** Current BizInbox text task. Page routing is a transport asset binding, not sender_fbid. */
final class PageTaskBuilder
{
    /** @return array<string,mixed> */
    public static function text(string $threadId, string $text, string $offlineId, int $taskId = 1): array
    {
        $thread = TaskBuilder::decimalId($threadId); TaskBuilder::decimalId($offlineId);
        if ($taskId < 1 || $text === '' || strlen($text) > 20000 || preg_match('//u', $text) !== 1) throw new \InvalidArgumentException('Invalid Page Lightspeed text task');
        // LSBizInboxHardCodedInitSyncWithThreadAwareness seeds PAGE database 205.
        // useBizInboxLSSendMessage sets UNIFIED_BUSINESS_INBOX and mark_read=true.
        $payload = ['thread_id' => $thread, 'otid' => $offlineId, 'source' => 4456448, 'send_type' => 1,
            'sync_group' => 205, 'mark_thread_read' => true, 'text' => $text];
        return ['failure_count' => null, 'label' => '46', 'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'queue_name' => $threadId, 'task_id' => $taskId];
    }
}
