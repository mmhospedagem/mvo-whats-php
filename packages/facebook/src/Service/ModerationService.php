<?php
declare(strict_types=1);

namespace MVO\Facebook\Service;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\WebGraphQL;

/** Explicit Page ban and Messenger block operations from the Business Suite contact card. */
final class ModerationService
{
    public function __construct(private readonly WebGraphQL $graph) {}

    /** Page ban is distinct from the messaging-only block.
     * @return array<string,mixed>
     */
    public function banUser(string $pageId, string $userId, string $idempotencyKey): array
    {
        return $this->ban($pageId, $userId, true, $idempotencyKey);
    }

    /** @return array<string,mixed> */
    public function unbanUser(string $pageId, string $userId, string $idempotencyKey): array
    {
        return $this->ban($pageId, $userId, false, $idempotencyKey);
    }

    /** Set the requested state; do not toggle from a stale local boolean.
     * @return array<string,mixed>
     */
    public function setMessagesBlocked(string $pageId, string $userId, bool $blocked, string $idempotencyKey): array
    {
        ServiceInput::actor($pageId); ServiceInput::actor($userId); ServiceInput::key($idempotencyKey);
        return $this->graph->execute('BizKitContextCardMessengerBlockMutation', ['input' => [
            'request_id' => 'bizweb-mbt-srx-'.$pageId.'-'.$userId,
            'target_id' => $userId, 'should_be_blocked' => $blocked,
        ]], $pageId, 'https://business.facebook.com', $idempotencyKey,
            /** @param array<string,mixed> $data
             * @return array<string,mixed>
             */
            static function (array $data) use ($pageId, $userId, $blocked): array {
                $actor = $data['messenger_block']['messaging_actor'] ?? null;
                if (!is_array($actor) || ($actor['id'] ?? null) !== $userId
                    || !is_bool($actor['is_message_blocked_by_viewer'] ?? null)) ServiceInput::unexpected(true);
                if ($actor['is_message_blocked_by_viewer'] !== $blocked) {
                    throw new FacebookException('facebook_moderation_unconfirmed', 'The returned messaging-block state differs from the requested state', 'not_confirmed');
                }
                return ['page_id' => $pageId, 'user_id' => $userId, 'messages_blocked' => $blocked,
                    'status' => $blocked ? 'messages_blocked' : 'messages_unblocked', 'scope' => 'page_messaging'];
            });
    }

    /** @return array<string,mixed> */
    public function availability(): array
    {
        return ['validated_in_account' => false, 'operations' => ['ban_user' => true, 'unban_user' => true,
            'set_messages_blocked' => true, 'list_banned_users' => false, 'submit_report' => false],
            'limit' => 'Page ban and messaging block use separate observed Web bindings. Report submission depends on a server-driven CIX screen and action contract that has not been established; no automatic report is sent.'];
    }

    /** @return array<string,mixed> */
    private function ban(string $pageId, string $userId, bool $banned, string $idempotencyKey): array
    {
        ServiceInput::actor($pageId); ServiceInput::actor($userId); ServiceInput::key($idempotencyKey);
        $document = $banned ? 'BizKitContextCardBlockWithProductLogicMutation' : 'BizKitContextCardUnblockMutation';
        $input = $banned ? ['user_id' => $userId, 'blocksource' => 'META_BUSINESS_SUITE_WEB'] : ['blockee' => $userId];
        return $this->graph->execute($document, ['input' => $input], $pageId, 'https://business.facebook.com', $idempotencyKey,
            /** @param array<string,mixed> $data
             * @return array<string,mixed>
             */
            static function (array $data) use ($pageId, $userId, $banned): array {
                $field = $banned ? 'user_ban_biz_inbox' : 'user_unblock';
                if (($data[$field]['user']['id'] ?? null) !== $userId) ServiceInput::unexpected(true);
                return ['page_id' => $pageId, 'user_id' => $userId, 'status' => $banned ? 'ban_response_received' : 'unban_response_received',
                    'scope' => 'page_ban', 'current_state_confirmed' => false];
            });
    }
}
