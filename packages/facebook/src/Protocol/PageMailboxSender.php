<?php
declare(strict_types=1);
namespace MVO\Facebook\Protocol;

use MVO\Facebook\Catalog\PageDocuments;
use MVO\Facebook\FacebookException;
use MVO\Facebook\Service\ServiceInput;
use MVO\Facebook\Storage\CommandLedger;
use MVO\Messenger\Auth\{WebSession,PageMailboxWebSession};
use MVO\Messenger\Protocol\{LightspeedDecoder,PageMailboxConfig,PageTaskBuilder,TaskBuilder};
use MVO\Messenger\Transport\PageMailboxTransport;

/** Current task-46 Page path through a separate asset-bound DGW group. */
final class PageMailboxSender
{
    public function __construct(private readonly WebSession $session, private readonly WebGraphQL $graph, private readonly CommandLedger $commands) {}

    /** The gateway values are from the current BizWeb bootstrap. IDs are saved before I/O.
     * @param array<string,mixed> $context dgw_app_id, dgw_app_version, server_app_id, schema_version, device_id, recipient_id, offline_threading_id, epoch_id; optional origin.
     * @return array<string,mixed>
     */
    public function sendText(string $actorId, string $pageId, string $selectedItemId, string $text, string $idempotencyKey, array $context): array
    {
        ServiceInput::actor($actorId); ServiceInput::actor($pageId); ServiceInput::opaque($selectedItemId, 'selected inbox item ID');
        $text = ServiceInput::text($text); ServiceInput::key($idempotencyKey);
        if (strlen($text) > 20000) throw new \InvalidArgumentException('Page Lightspeed text must contain at most 20000 UTF-8 bytes');
        if (!$this->session instanceof PageMailboxWebSession) throw new FacebookException('facebook_page_gateway_unavailable', 'The active account owner does not expose a Page-bound Lightspeed gateway');
        if ($this->session->accountId() !== $this->graph->accountId()) throw new FacebookException('facebook_inbox_session_mismatch', 'The Page mailbox and its queries must share the same authenticated account');
        ServiceInput::keys($context, ['dgw_app_id', 'dgw_app_version', 'server_app_id', 'schema_version', 'device_id', 'recipient_id', 'offline_threading_id', 'epoch_id', 'origin']);
        foreach (['dgw_app_id', 'dgw_app_version', 'server_app_id', 'schema_version', 'device_id', 'recipient_id', 'offline_threading_id', 'epoch_id'] as $name) ServiceInput::requiredString($context, $name);
        ServiceInput::actor($context['recipient_id']); TaskBuilder::decimalId($context['offline_threading_id']); TaskBuilder::decimalId($context['epoch_id']);
        $config = new PageMailboxConfig($this->session->accountId(), $pageId, $context['dgw_app_id'], $context['dgw_app_version'],
            $context['server_app_id'], $context['schema_version'], $context['device_id'], $context['origin'] ?? 'https://business.facebook.com');
        $command = $this->commands->reserve($this->session->accountId(), 'page.inbox.sendText.lightspeed', $actorId,
            ['page_id' => $pageId, 'selected_item_id' => $selectedItemId, 'text' => $text, 'context' => $context], $idempotencyKey);
        if (!$command['execute']) {
            if ($command['state'] === 'confirmed' && is_array($command['result']['projection'] ?? null)) return $command['result']['projection'];
            if (($command['result']['execution_started'] ?? null) === false) throw new FacebookException('facebook_inbox_preflight_failed', 'This Page command stopped before sending; inspect its recorded reason', 'not_executed', false, $command['id']);
            throw new FacebookException('facebook_command_uncertain', 'The Page Lightspeed command has no confirmed result; do not send it again', 'unknown', false, $command['id']);
        }
        $gateway = null; $started = false;
        try {
            $threadId = $this->thread($actorId, $pageId, $selectedItemId, $context['recipient_id']);
            $gateway = $this->session->pageMailbox($config);
            if ($gateway->config()->jsonSerialize() !== $config->jsonSerialize()) throw new FacebookException('facebook_page_gateway_scope_mismatch', 'The runtime returned a different account, Page or protocol configuration');
            $task = PageTaskBuilder::text($threadId, $text, $context['offline_threading_id']);
            $request = json_decode(TaskBuilder::request($config->serverAppId, 1, 3, TaskBuilder::batch($config->schemaVersion, [$task], $context['epoch_id'])), true, 64, JSON_THROW_ON_ERROR);
            $this->commands->finish($command['id'], 'unknown', ['offline_threading_id' => $context['offline_threading_id'], 'epoch_id' => $context['epoch_id']]); $started = true;
            try { $response = $gateway->request($request); }
            catch (\Throwable) { throw new FacebookException('facebook_inbox_send_uncertain', 'The Page Lightspeed result is uncertain; keep the original command and message ID', 'unknown'); }
            if (($response['request_id'] ?? null) !== 1 || (!is_string($response['payload'] ?? null) && !is_array($response['payload'] ?? null)) || !is_array($response['sp'] ?? [])) throw new FacebookException('facebook_inbox_ack_unconfirmed', 'The Page gateway did not return the correlated Lightspeed response', 'not_confirmed');
            $calls = (new LightspeedDecoder())->decode($response['payload'], $response['sp'] ?? []);
            $this->commands->finish($command['id'], 'response_received', ['offline_threading_id' => $context['offline_threading_id'], 'epoch_id' => $context['epoch_id']]);
            $projection = self::project($calls, $pageId, $threadId, $context['recipient_id'], $text, $context['offline_threading_id']);
            $this->commands->finish($command['id'], 'confirmed', ['projection' => $projection]);
            return $projection;
        } catch (\Throwable $error) {
            if (!$started) $this->commands->finish($command['id'], 'not_confirmed', ['execution_started' => false, 'reason' => $error instanceof FacebookException ? $error->reason : 'facebook_inbox_preflight_failed']);
            throw new FacebookException($error instanceof FacebookException ? $error->reason : ($started ? 'facebook_inbox_send_unconfirmed' : 'facebook_inbox_preflight_failed'),
                $error instanceof FacebookException ? $error->getMessage() : 'The Page Lightspeed command has no confirmed result',
                !$started ? 'not_executed' : ($error instanceof FacebookException ? $error->outcome : 'unknown'), false, $command['id']);
        } finally { try { $gateway?->close(); } catch (\Throwable) { /* Closing a socket cannot change the recorded send result. */ } }
    }

    private function thread(string $actorId, string $pageId, string $selectedItemId, string $recipientId): string
    {
        $arguments = PageDocuments::arguments('BizInboxUnifiedThreadsQuery', ['page_id' => $pageId, 'selected_item_id' => $selectedItemId,
            'msgr_params' => null, 'loadThreads' => false, 'loadThreadItems' => false, 'load_messages' => false]);
        $data = $this->graph->execute('BizInboxUnifiedThreadsQuery', $arguments, $actorId); $list = $data['xfb_biz_inbox_unified_thread_list'] ?? null;
        $thread = is_array($list) ? ($list['selected_thread']['selected_message_thread'] ?? null) : null;
        if (!is_array($list) || ($list['selected_page']['id'] ?? null) !== $pageId || !is_array($thread)) throw new FacebookException('facebook_inbox_context_unconfirmed', 'Facebook did not confirm this Page mailbox and thread');
        $id = ServiceInput::scalarId($thread['id'] ?? null); $selection = $id;
        if ($id !== null && !ctype_digit($id)) { $decoded = base64_decode($id, true); $selection = $decoded !== false && substr_count($decoded, ':') === 1 ? explode(':', $decoded, 2)[1] : null; }
        $threadId = ServiceInput::scalarId($thread['thread_key']['thread_fbid'] ?? null);
        if ($selection !== $selectedItemId || $threadId === null || ServiceInput::scalarId($thread['thread_key']['other_user_id'] ?? null) !== $recipientId) throw new FacebookException('facebook_inbox_selection_mismatch', 'Facebook returned a different Page thread or recipient');
        TaskBuilder::decimalId($threadId);
        if (($thread['composer_input_disabled'] ?? null) !== false || !array_key_exists('cannot_reply_reason', $thread) || $thread['cannot_reply_reason'] !== null) throw new FacebookException('facebook_inbox_reply_unavailable', 'The Page thread did not explicitly enable its reply composer');
        return $threadId;
    }

    /** @param list<array{name:string,args:list<mixed>}> $calls
     * @return array<string,mixed>
     */
    private static function project(array $calls, string $pageId, string $threadId, string $recipientId, string $text, string $offlineId): array
    {
        $acks = []; $messages = [];
        foreach ($calls as $call) {
            $name = str_starts_with($call['name'], 'LS') ? lcfirst(substr($call['name'], 2)) : $call['name']; $args = $call['args'];
            if (in_array($name, ['replaceOptimsiticMessage', 'replaceOptimisticMessage'], true) && ServiceInput::scalarId($args[0] ?? null) === $offlineId) {
                $id = ServiceInput::scalarId($args[1] ?? null); if ($id === null || $id === $offlineId) throw new FacebookException('facebook_inbox_ack_unconfirmed', 'The Page task did not provide a server message identity', 'not_confirmed'); $acks[] = $id;
            } elseif (in_array($name, ['insertMessage', 'upsertMessage'], true)) {
                $id = ServiceInput::scalarId($args[8] ?? null);
                if ($id !== null) $messages[$id] = ['thread_id' => ServiceInput::scalarId($args[3] ?? null), 'offline_id' => ServiceInput::scalarId($args[9] ?? null),
                    'sender_id' => ServiceInput::scalarId($args[10] ?? null), 'text' => $args[0] ?? null];
            } elseif ($name === 'markOptimisticMessageFailed' && ServiceInput::scalarId($args[0] ?? null) === $offlineId
                || $name === 'handleFailedTask' && ServiceInput::scalarId($args[1] ?? null) === $offlineId) throw new FacebookException('facebook_inbox_message_rejected', 'The Page Lightspeed task reported a message failure', 'not_confirmed');
        }
        if (count($acks) !== 1 || !isset($messages[$acks[0]])) throw new FacebookException('facebook_inbox_ack_unconfirmed', 'The Page task has no unique acknowledgment with a matching server message row', 'not_confirmed');
        $message = $messages[$acks[0]];
        if ($message['thread_id'] !== $threadId || $message['offline_id'] !== $offlineId || $message['sender_id'] !== $pageId || $message['text'] !== $text) throw new FacebookException('facebook_inbox_ack_mismatch', 'The Page task acknowledged a different sender, thread, message identity or body', 'not_confirmed');
        return ['page_id' => $pageId, 'thread_id' => $threadId, 'recipient_id' => $recipientId, 'message_id' => $acks[0], 'offline_threading_id' => $offlineId,
            'status' => 'server_ack', 'transport' => 'lightspeed_page_dgw', 'delivery_confirmed' => false, 'read_confirmed' => false];
    }
    /** @return array<string,string> */
    public function __debugInfo(): array { return ['session' => '[PRIVATE]', 'transport' => 'lightspeed_page_dgw']; }
}
