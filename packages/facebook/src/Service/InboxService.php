<?php
declare(strict_types=1);

namespace MVO\Facebook\Service;

use MVO\Facebook\Catalog\PageDocuments;
use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\WebGraphQL;
use MVO\Facebook\Protocol\PageInboxSender;
use MVO\Facebook\Protocol\PageMailboxSender;
use MVO\Facebook\Protocol\PageMailboxBootstrapReader;
use MVO\Facebook\Storage\CommandLedger;
use MVO\Messenger\Auth\WebSession;
use MVO\Messenger\Protocol\{BootstrapParser,TaskBuilder};
use MVO\Messenger\Transport\PublicBundleLoader;

/** Page mailbox reads and an explicit experimental Mercury sender; never personal Messenger fallback. */
final class InboxService
{
    public function __construct(private readonly WebGraphQL $graph, private readonly ?WebSession $session = null, private readonly ?CommandLedger $commands = null) {}

    /** Experimental legacy Mercury send. Context must come from the current Page composer.
     * Preserve it with the idempotency key; no generated message identity changes on retry.
     * @param array<string,mixed> $context See PageInboxSender; permission booleans are not accepted.
     * @return array<string,mixed>
     */
    public function sendText(string $actorId, string $pageId, string $selectedItemId, string $text, string $idempotencyKey, array $context): array
    {
        if ($this->session === null || $this->commands === null) throw new FacebookException('facebook_inbox_sender_unavailable', 'Configure the account WebSession and private command ledger for Page inbox sends');
        return (new PageInboxSender($this->session, $this->graph, $this->commands))->sendText($actorId, $pageId, $selectedItemId, $text, $idempotencyKey, $context);
    }

    /** Current asset-bound Lightspeed task-46 path; requires the runtime's PageMailboxWebSession.
     * @param array<string,mixed> $context Current BizWeb gateway/schema IDs and preserved OTID/epoch.
     * @return array<string,mixed>
     */
    public function sendTextLightspeed(string $actorId, string $pageId, string $selectedItemId, string $text, string $idempotencyKey, array $context): array
    {
        if ($this->session === null || $this->commands === null) throw new FacebookException('facebook_inbox_sender_unavailable', 'Configure the account WebSession and private command ledger for Page inbox sends');
        return (new PageMailboxSender($this->session, $this->graph, $this->commands))->sendText($actorId, $pageId, $selectedItemId, $text, $idempotencyKey, $context);
    }

    /** Read-only context acquisition; it does not open DGW or send a message.
     * Persist the returned context with the future idempotency key before sending.
     * @param callable(list<string>):list<string>|null $bundleLoader Anonymous public-CDN loader/test seam.
     * @return array<string,string>
     */
    public function lightspeedContext(string $actorId, string $pageId, string $selectedItemId, ?callable $bundleLoader = null): array
    {
        self::scope($actorId, $pageId, 1, null); ServiceInput::opaque($selectedItemId, 'selected inbox item ID');
        if ($this->session === null || $this->commands === null) throw new FacebookException('facebook_inbox_sender_unavailable', 'Configure the active account and private command storage before reading Page gateway context');
        if ($this->session->accountId() !== $this->graph->accountId()) throw new FacebookException('facebook_inbox_session_mismatch', 'The Page bootstrap and queries must share the same account owner');
        $arguments = PageDocuments::arguments('BizInboxUnifiedThreadsQuery', ['page_id' => $pageId, 'selected_item_id' => $selectedItemId,
            'msgr_params' => null, 'loadThreads' => false, 'loadThreadItems' => false, 'load_messages' => false]);
        $data = $this->graph->execute('BizInboxUnifiedThreadsQuery', $arguments, $actorId); $list = $data['xfb_biz_inbox_unified_thread_list'] ?? null;
        $thread = is_array($list) ? ($list['selected_thread']['selected_message_thread'] ?? null) : null;
        if (!is_array($list) || ($list['selected_page']['id'] ?? null) !== $pageId || !is_array($thread)) throw new FacebookException('facebook_inbox_context_unconfirmed', 'Facebook did not confirm the requested Page mailbox');
        $projected = self::thread($thread); $recipient = $projected['thread_key']['other_user_id'];
        if ($projected['selection_id'] !== $selectedItemId || !is_string($recipient) || ($projected['composer_input_disabled'] ?? null) !== false
            || !array_key_exists('cannot_reply_reason', $thread) || $thread['cannot_reply_reason'] !== null) throw new FacebookException('facebook_inbox_reply_unavailable', 'The selected Page thread did not provide a usable reply context');
        ServiceInput::actor($recipient);
        $origin = 'https://business.facebook.com'; $url = $origin . '/latest/inbox/all?' . http_build_query(['asset_id' => $pageId, 'selected_item_id' => $selectedItemId], '', '&', PHP_QUERY_RFC3986);
        try { $response = $this->session->request('GET', $url); } catch (\Throwable) { throw new FacebookException('facebook_transport_failed', 'The Page inbox document request did not complete'); }
        if (in_array($response->status, [301,302,303,307,308,401], true)) throw new FacebookException('facebook_authentication_required', 'The Page inbox requires authentication or verification');
        if ($response->status !== 200) throw new FacebookException('facebook_http_failed', 'Facebook did not return a successful Page inbox document');
        $reader = new PageMailboxBootstrapReader(); $deviceId = $this->commands->clientDeviceId($this->session->accountId());
        try { $config = $reader->read($response->body, $this->session->accountId(), $pageId, $deviceId); }
        catch (FacebookException $error) {
            if ($error->reason !== 'facebook_page_schema_unavailable') throw $error;
            $load = $bundleLoader ?? (new PublicBundleLoader())->loadVersionBundles(...);
            $scripts = $load(BootstrapParser::scriptUrls($response->body));
            $config = $reader->read($response->body, $this->session->accountId(), $pageId, $deviceId, $scripts);
        }
        return ['dgw_app_id' => $config->dgwAppId, 'dgw_app_version' => $config->dgwAppVersion, 'server_app_id' => $config->serverAppId,
            'schema_version' => $config->schemaVersion, 'device_id' => $config->deviceId, 'origin' => $config->origin,
            'recipient_id' => $recipient, 'offline_threading_id' => TaskBuilder::epoch(), 'epoch_id' => TaskBuilder::epoch()];
    }

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
