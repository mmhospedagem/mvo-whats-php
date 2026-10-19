<?php
declare(strict_types=1);

namespace MVO\Facebook\Service;

use MVO\Facebook\Catalog\PageDocuments;
use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\WebGraphQL;

/** Read-only Page inbox. This service does not route Page messages through personal Messenger. */
final class InboxService
{
    public function __construct(private readonly WebGraphQL $graph) {}

    /** $actorId and $pageId are distinct where the account uses a delegated Page.
     * @param list<string> $tags Exact observed inbox tags, or an empty filter.
     * @return array<string,mixed>
     */
    public function threads(string $actorId, string $pageId, int $limit = 20, ?string $beforeTimePrecise = null, array $tags = []): array
    {
        self::scope($actorId, $pageId, $limit, $beforeTimePrecise);
        if (!array_is_list($tags) || count($tags) > 20) throw new \InvalidArgumentException('Use a bounded list of observed inbox tags');
        foreach ($tags as $tag) {
            if (!is_string($tag)) throw new \InvalidArgumentException('Inbox tags must be strings');
            ServiceInput::opaque($tag, 'inbox tag', 128);
        }
        $arguments = PageDocuments::arguments('BizInboxUnifiedThreadsQuery', [
            'page_id' => $pageId, 'msgr_params' => ['last' => $limit, 'before_time_precise' => $beforeTimePrecise, 'with_tags' => $tags],
            'loadThreads' => true, 'loadThreadItems' => false, 'load_messages' => false,
        ]);
        $data = $this->graph->execute('BizInboxUnifiedThreadsQuery', $arguments, $actorId);
        $connection = $data['xfb_biz_inbox_unified_thread_list']['msgr_threads'] ?? null;
        if (!is_array($connection) || !is_array($connection['nodes'] ?? null)) ServiceInput::unexpected();
        $items = [];
        foreach ($connection['nodes'] as $row) if (is_array($row)) $items[] = self::thread($row);
        return ['items' => $items, 'sync_sequence_id' => ServiceInput::scalarId($connection['sync_sequence_id'] ?? null),
            // No page_info/exhaustion flag exists on the established Messenger inbox connection.
            'has_more' => null];
    }

    /** Use selection_id returned by threads(); preserve the original GraphQL id separately.
     * @return array<string,mixed>
     */
    public function messages(string $actorId, string $pageId, string $selectedItemId, int $limit = 20, ?string $beforeTimePrecise = null): array
    {
        self::scope($actorId, $pageId, $limit, $beforeTimePrecise);
        ServiceInput::opaque($selectedItemId, 'selected inbox item ID');
        $arguments = PageDocuments::arguments('BizInboxUnifiedThreadsQuery', [
            'page_id' => $pageId, 'selected_item_id' => $selectedItemId, 'msgr_params' => null,
            'message_limit' => $limit, 'before' => $beforeTimePrecise, 'loadThreads' => false,
            'loadThreadItems' => false, 'load_messages' => true, 'load_read_receipts' => true,
        ]);
        $data = $this->graph->execute('BizInboxUnifiedThreadsQuery', $arguments, $actorId);
        $thread = $data['xfb_biz_inbox_unified_thread_list']['selected_thread']['selected_message_thread'] ?? null;
        if (!is_array($thread) || !is_array($thread['messages']['nodes'] ?? null)) ServiceInput::unexpected();
        $projected = self::thread($thread);
        if (($projected['selection_id'] ?? $projected['id']) !== $selectedItemId) {
            throw new FacebookException('facebook_inbox_selection_mismatch', 'Facebook returned a different inbox item than requested');
        }
        $items = [];
        foreach ($thread['messages']['nodes'] as $row) {
            if (!is_array($row)) continue;
            $items[] = ['id' => ServiceInput::scalarId($row['id'] ?? null),
                'message_id' => ServiceInput::scalarId($row['message_id'] ?? null),
                'offline_threading_id' => ServiceInput::scalarId($row['offline_threading_id'] ?? null),
                'sender_id' => ServiceInput::scalarId($row['message_sender']['id'] ?? null),
                'text' => is_string($row['message']['text'] ?? null) ? $row['message']['text'] : null,
                'snippet' => is_string($row['snippet'] ?? null) ? $row['snippet'] : null,
                'timestamp_precise' => ServiceInput::scalarId($row['timestamp_precise'] ?? null),
                'unread' => is_bool($row['unread'] ?? null) ? $row['unread'] : null];
        }
        return ['thread' => $projected, 'items' => $items, 'has_more' => null];
    }

    private static function scope(string $actorId, string $pageId, int $limit, ?string $before): void
    {
        ServiceInput::actor($actorId);
        ServiceInput::actor($pageId);
        if ($limit < 1 || $limit > 100) throw new \InvalidArgumentException('Use an inbox limit between 1 and 100');
        if ($before !== null && !preg_match('/^[0-9]{1,24}$/D', $before)) throw new \InvalidArgumentException('Use the exact observed timestamp cursor as a string');
    }

    /** @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function thread(array $row): array
    {
        $id = ServiceInput::scalarId($row['id'] ?? null);
        $key = is_array($row['thread_key'] ?? null) ? $row['thread_key'] : [];
        return ['id' => $id, 'selection_id' => self::selectionId($id),
            'thread_key' => ['thread_fbid' => ServiceInput::scalarId($key['thread_fbid'] ?? null),
                'other_user_id' => ServiceInput::scalarId($key['other_user_id'] ?? null)],
            'name' => is_string($row['name'] ?? null) ? $row['name'] : null,
            'folder' => is_string($row['folder'] ?? null) ? $row['folder'] : null,
            'unread_count' => is_int($row['unread_count'] ?? null) ? $row['unread_count'] : null,
            'messages_count' => is_int($row['messages_count'] ?? null) ? $row['messages_count'] : null,
            'updated_time_precise' => ServiceInput::scalarId($row['updated_time_precise'] ?? null),
            'cannot_reply_reason' => is_string($row['cannot_reply_reason'] ?? null) ? $row['cannot_reply_reason'] : null,
            'composer_input_disabled' => is_bool($row['composer_input_disabled'] ?? null) ? $row['composer_input_disabled'] : null];
    }

    /** Mirrors the shipped inbox's decoding of a returned GraphQL thread ID, never manufactures one. */
    private static function selectionId(?string $id): ?string
    {
        if ($id === null) return null;
        if (ctype_digit($id)) return $id;
        $decoded = base64_decode($id, true);
        if ($decoded === false || substr_count($decoded, ':') !== 1) return null;
        [, $selection] = explode(':', $decoded, 2);
        return $selection !== '' && !str_contains($selection, "\0") ? $selection : null;
    }
}
