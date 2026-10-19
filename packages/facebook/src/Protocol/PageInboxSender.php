<?php
declare(strict_types=1);
namespace MVO\Facebook\Protocol;

use MVO\Facebook\Catalog\PageDocuments;
use MVO\Facebook\FacebookException;
use MVO\Facebook\Service\ServiceInput;
use MVO\Facebook\Storage\CommandLedger;
use MVO\Messenger\Auth\WebSession;

/** Experimental legacy Mercury Page sender; never falls back to personal Messenger or WEC. */
final class PageInboxSender
{
    public function __construct(private readonly WebSession $session, private readonly WebGraphQL $graph, private readonly CommandLedger $commands) {}

    /** Local Mercury OTID creation. Persist these values with the caller's actual
     * client message ID/context before sending; never regenerate on replay.
     * @return array{offline_threading_id:string,timestamp_ms:string}
     */
    public static function newOfflineIdentity(): array
    {
        $timestamp = (int)floor(microtime(true) * 1000);
        if ($timestamp < 1 || $timestamp > 2199023255551) throw new \RuntimeException('The current timestamp exceeds the Mercury offline identifier window');
        return ['offline_threading_id' => (string)(($timestamp << 22) | random_int(0, 4194303)), 'timestamp_ms' => (string)$timestamp];
    }

    /** Keep the same context on replay. These are actual Mercury composer values, not permission flags.
     * @param array<string,mixed> $context client_thread_id, recipient_id, client, source, message_id, offline_threading_id, timestamp_ms; optional origin/privacy_mutation_token.
     * @return array<string,mixed>
     */
    public function sendText(string $actorId, string $pageId, string $selectedItemId, string $text, string $idempotencyKey, array $context): array
    {
        ServiceInput::actor($actorId); ServiceInput::actor($pageId); ServiceInput::opaque($selectedItemId, 'selected inbox item ID');
        $text = ServiceInput::text($text); ServiceInput::key($idempotencyKey);
        $context = self::context($context);
        if ($this->session->accountId() !== $this->graph->accountId()) throw new FacebookException('facebook_inbox_session_mismatch', 'Use the same account owner for Page inbox queries and sends');
        $fields = $this->session->privateRequestFields();
        if (($fields['__user'] ?? null) !== $this->session->accountId() || !is_string($fields['fb_dtsg'] ?? null) || $fields['fb_dtsg'] === '') throw new FacebookException('facebook_csrf_unavailable', 'The current owner did not provide Facebook CSRF fields for the Page inbox');
        $variables = ['actor_id' => $actorId, 'page_id' => $pageId, 'selected_item_id' => $selectedItemId, 'text' => $text, 'context' => $context];
        $command = $this->commands->reserve($this->session->accountId(), 'page.inbox.sendText.mercury', $actorId, $variables, $idempotencyKey);
        if (!$command['execute']) {
            if ($command['state'] === 'confirmed' && is_array($command['result']['projection'] ?? null)) return $command['result']['projection'];
            if (($command['result']['execution_started'] ?? null) === false) throw new FacebookException('facebook_inbox_preflight_failed', 'This command stopped before the Page send; inspect its saved reason before starting a new command', 'not_executed', false, $command['id']);
            throw new FacebookException('facebook_command_uncertain', 'This inbox command has no confirmed result; inspect it before attempting another send', 'unknown', false, $command['id']);
        }
        $started = false;
        try {
            // Query the exact mailbox/thread before changing it. Caller-provided
            // can_send/permission booleans are neither accepted nor trusted.
            $thread = $this->thread($actorId, $pageId, $selectedItemId, $context);
            $form = [...$fields, '__a' => '1', 'av' => $actorId, 'request_user_id' => $pageId, 'client' => $context['client'],
                'action_type' => 'ma-type:user-generated-message', 'body' => $text, 'client_thread_id' => $context['client_thread_id'],
                'message_id' => $context['message_id'], 'offline_threading_id' => $context['offline_threading_id'],
                'signature_id' => md5($context['offline_threading_id']), 'source' => $context['source'],
                'other_user_fbid' => $context['recipient_id'], 'thread_fbid' => $thread['thread_fbid'], 'timestamp' => $context['timestamp_ms'], 'has_attachment' => 'false'];
            if (isset($context['privacy_mutation_token'])) $form['privacy_mutation_token'] = $context['privacy_mutation_token'];
            // Only normalized correlation metadata is kept, never cookies/CSRF or response trees.
            $this->commands->finish($command['id'], 'unknown', ['offline_threading_id' => $context['offline_threading_id'], 'timestamp_ms' => $context['timestamp_ms']]);
            $started = true;
            try { $response = $this->session->request('POST', $context['origin'] . '/messaging/send/', $form); }
            catch (\Throwable) { throw new FacebookException('facebook_inbox_send_uncertain', 'The Page inbox request did not complete; inspect the saved command before resending', 'unknown'); }
            if ($response->status !== 200) throw new FacebookException('facebook_inbox_send_unconfirmed', 'Facebook did not confirm the Page inbox request', 'unknown');
            $payload = self::decode($response->body);
            $this->commands->finish($command['id'], 'response_received', ['offline_threading_id' => $context['offline_threading_id'], 'timestamp_ms' => $context['timestamp_ms']]);
            $projection = self::project($payload, $pageId, $thread['thread_fbid'], $text, $context);
            $this->commands->finish($command['id'], 'confirmed', ['projection' => $projection]);
            return $projection;
        } catch (\Throwable $error) {
            if (!$started) $this->commands->finish($command['id'], 'not_confirmed', ['execution_started' => false,
                'reason' => $error instanceof FacebookException ? $error->reason : 'facebook_inbox_preflight_failed']);
            throw new FacebookException($error instanceof FacebookException ? $error->reason : 'facebook_inbox_send_unconfirmed',
                $error instanceof FacebookException ? $error->getMessage() : 'The Page inbox command has no confirmed result',
                $error instanceof FacebookException ? $error->outcome : 'unknown', false, $command['id']);
        }
    }

    /** @param array<string,mixed> $context
     * @return array<string,string>
     */
    private static function context(array $context): array
    {
        ServiceInput::keys($context, ['client_thread_id', 'recipient_id', 'client', 'source', 'message_id', 'offline_threading_id', 'timestamp_ms', 'origin', 'privacy_mutation_token']);
        $out = [];
        foreach (['client_thread_id', 'recipient_id', 'client', 'source', 'message_id', 'offline_threading_id', 'timestamp_ms'] as $name) {
            $out[$name] = ServiceInput::requiredString($context, $name);
            if (strlen($out[$name]) > 256 || preg_match('/[\x00-\x1f\x7f]/', $out[$name])) throw new \InvalidArgumentException('Invalid Mercury inbox composer context');
        }
        ServiceInput::actor($out['recipient_id']);
        if (!preg_match('/^(?:user|thread):[1-9][0-9]{0,24}$/D', $out['client_thread_id']) || !str_starts_with($out['source'], 'source:')) throw new \InvalidArgumentException('Supply the exact Mercury thread and source context');
        foreach (['offline_threading_id', 'timestamp_ms'] as $name) if (!preg_match('/^[1-9][0-9]{0,18}$/D', $out[$name])
            || strlen($out[$name]) === 19 && strcmp($out[$name], (string)PHP_INT_MAX) > 0) throw new \InvalidArgumentException('Supply an exact positive 63-bit Mercury identifier/timestamp string');
        if (intdiv((int)$out['offline_threading_id'], 4194304) !== (int)$out['timestamp_ms']) throw new \InvalidArgumentException('The Mercury offline identifier does not match its original timestamp');
        $out['origin'] = $context['origin'] ?? 'https://business.facebook.com';
        if (!in_array($out['origin'], ['https://www.facebook.com', 'https://business.facebook.com'], true)) throw new \InvalidArgumentException('Use an observed Facebook Page inbox origin');
        if (isset($context['privacy_mutation_token'])) $out['privacy_mutation_token'] = ServiceInput::requiredString($context, 'privacy_mutation_token');
        return $out;
    }

    /** @param array<string,string> $context
     * @return array{thread_fbid:string}
     */
    private function thread(string $actorId, string $pageId, string $selectedItemId, array $context): array
    {
        $arguments = PageDocuments::arguments('BizInboxUnifiedThreadsQuery', ['page_id' => $pageId, 'selected_item_id' => $selectedItemId,
            'msgr_params' => null, 'loadThreads' => false, 'loadThreadItems' => false, 'load_messages' => false]);
        $data = $this->graph->execute('BizInboxUnifiedThreadsQuery', $arguments, $actorId);
        $list = $data['xfb_biz_inbox_unified_thread_list'] ?? null;
        $thread = is_array($list) ? ($list['selected_thread']['selected_message_thread'] ?? null) : null;
        if (!is_array($list) || ($list['selected_page']['id'] ?? null) !== $pageId || !is_array($thread)) throw new FacebookException('facebook_inbox_context_unconfirmed', 'Facebook did not confirm the selected Page mailbox and thread');
        $key = is_array($thread['thread_key'] ?? null) ? $thread['thread_key'] : [];
        $threadFbid = ServiceInput::scalarId($key['thread_fbid'] ?? null); $recipient = ServiceInput::scalarId($key['other_user_id'] ?? null);
        $id = ServiceInput::scalarId($thread['id'] ?? null); $selection = self::selection($id);
        if ($selection !== $selectedItemId || $threadFbid === null || $recipient !== $context['recipient_id']) throw new FacebookException('facebook_inbox_selection_mismatch', 'Facebook returned a different inbox thread or recipient');
        ServiceInput::actor($threadFbid);
        if ($context['client_thread_id'] !== 'user:' . $recipient && $context['client_thread_id'] !== 'thread:' . $threadFbid) throw new FacebookException('facebook_inbox_selection_mismatch', 'The current Mercury thread does not match the selected inbox conversation');
        if (($thread['composer_input_disabled'] ?? null) !== false || !array_key_exists('cannot_reply_reason', $thread) || $thread['cannot_reply_reason'] !== null) throw new FacebookException('facebook_inbox_reply_unavailable', 'The returned Page thread does not explicitly allow replies');
        return ['thread_fbid' => $threadFbid];
    }

    /** @return array<string,mixed> */
    private static function decode(#[\SensitiveParameter] string $body): array
    {
        if (strlen($body) > 4194304) throw new FacebookException('facebook_inbox_response_invalid', 'The Page inbox response exceeds its limit', 'unknown');
        $body = trim($body); if (str_starts_with($body, 'for (;;);')) $body = substr($body, 9);
        try { $response = json_decode($body, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new FacebookException('facebook_inbox_response_invalid', 'The Page inbox returned an unrecognized response', 'unknown'); }
        if (!is_array($response) || (($response['error'] ?? 0) !== 0 && ($response['error'] ?? 0) !== false)
            || !is_array($response['payload'] ?? null) || !empty($response['payload']['error_payload']) || !empty($response['payload']['kill_chat'])) throw new FacebookException('facebook_inbox_send_unconfirmed', 'Facebook returned an error or unrecognized Page inbox result', 'not_confirmed');
        return $response['payload'];
    }

    /** @param array<string,mixed> $payload
     * @param array<string,string> $context
     * @return array<string,mixed>
     */
    private static function project(array $payload, string $pageId, string $threadId, string $text, array $context): array
    {
        if (!is_array($payload['actions'] ?? null) || !array_is_list($payload['actions'])) ServiceInput::unexpected(true);
        $matches = [];
        foreach ($payload['actions'] as $action) {
            if (!is_array($action) || ServiceInput::scalarId($action['offline_threading_id'] ?? null) !== $context['offline_threading_id']) continue;
            $id = ServiceInput::scalarId($action['message_id'] ?? null);
            if ($id === null || $id === $context['message_id'] || ServiceInput::scalarId($action['thread_fbid'] ?? null) !== $threadId
                || ($action['author'] ?? null) !== 'fbid:' . $pageId || ($action['body'] ?? null) !== $text
                || isset($action['other_user_fbid']) && ServiceInput::scalarId($action['other_user_fbid']) !== $context['recipient_id']) throw new FacebookException('facebook_inbox_ack_mismatch', 'The Page inbox acknowledgment does not match this message, sender and conversation', 'not_confirmed');
            $matches[] = $id;
        }
        if (count($matches) !== 1) throw new FacebookException('facebook_inbox_ack_unconfirmed', 'The Page inbox did not return exactly one correlated server message', 'not_confirmed');
        return ['page_id' => $pageId, 'thread_id' => $threadId, 'recipient_id' => $context['recipient_id'], 'message_id' => $matches[0],
            'offline_threading_id' => $context['offline_threading_id'], 'status' => 'server_ack', 'transport' => 'mercury_page_inbox_experimental',
            'delivery_confirmed' => false, 'read_confirmed' => false];
    }

    private static function selection(?string $id): ?string
    {
        if ($id === null || ctype_digit($id)) return $id;
        $decoded = base64_decode($id, true); if ($decoded === false || substr_count($decoded, ':') !== 1) return null;
        [, $selection] = explode(':', $decoded, 2); return $selection !== '' ? $selection : null;
    }
    /** @return array<string,mixed> */
    public function __debugInfo(): array { return ['transport' => 'mercury_page_inbox_experimental', 'session' => '[PRIVATE]', 'commands' => '[PRIVATE]']; }
}
