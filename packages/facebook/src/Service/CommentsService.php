<?php
declare(strict_types=1);

namespace MVO\Facebook\Service;

use MVO\Facebook\Catalog\PageDocuments;
use MVO\Facebook\Catalog\ContentManagementDocuments;
use MVO\Facebook\Protocol\WebGraphQL;

final class CommentsService
{
    public function __construct(private readonly WebGraphQL $graph) {}

    /** Edit an existing text-only comment. The current formatting style is preserved explicitly.
     * @param array<string,mixed> $context formatting_style, attribution_id_v2, tracking and optional AI disclosure.
     * @param array<string,mixed> $variables Current providers, feedLocation, translationType, useDefaultActor and scale.
     * @return array<string,mixed>
     */
    public function editText(string $actorId, string $commentId, string $text, string $idempotencyKey, array $context, array $variables): array
    {
        ServiceInput::actor($actorId);
        ServiceInput::opaque($commentId, 'GraphQL comment ID');
        ServiceInput::key($idempotencyKey);
        $text = ServiceInput::text($text);
        ServiceInput::keys($context, ['formatting_style', 'attribution_id_v2', 'tracking', 'is_self_disclosed_as_ai_generated']);
        $input = ['comment_id' => $commentId, 'message' => ['ranges' => [], 'text' => $text], 'attachments' => [],
            'formatting_style' => ServiceInput::requiredString($context, 'formatting_style'),
            'attribution_id_v2' => ServiceInput::requiredString($context, 'attribution_id_v2'), 'tracking' => self::tracking($context)];
        if (array_key_exists('is_self_disclosed_as_ai_generated', $context)) {
            if (!is_bool($context['is_self_disclosed_as_ai_generated'])) throw new \InvalidArgumentException('AI disclosure must be boolean');
            $input['is_self_disclosed_as_ai_generated'] = $context['is_self_disclosed_as_ai_generated'];
        }
        self::renderingContext($variables);
        ServiceInput::requiredString($variables, 'translationType');
        $arguments = ContentManagementDocuments::arguments('useCometUFIEditCommentMutation', [...$variables, 'input' => $input]);
        return $this->graph->execute('useCometUFIEditCommentMutation', $arguments, $actorId, 'https://www.facebook.com', $idempotencyKey,
            static function (array $data) use ($commentId, $text): array {
                $comment = $data['comment_edit']['comment'] ?? null;
                if (!is_array($comment) || ($comment['id'] ?? null) !== $commentId || ($comment['body']['text'] ?? null) !== $text) ServiceInput::unexpected(true);
                return ['id' => $commentId, 'text' => $text];
            });
    }

    /** Delete a comment through the observed Comet menu action, with exact opaque IDs.
     * @param array<string,mixed> $context attribution_id_v2 and tracking from the current menu.
     * @param array<string,mixed> $variables Current providers, scale and optional group/render context.
     * @return array<string,mixed>
     */
    public function delete(string $actorId, string $commentId, string $idempotencyKey, array $context, array $variables): array
    {
        ServiceInput::actor($actorId);
        ServiceInput::opaque($commentId, 'GraphQL comment ID');
        ServiceInput::key($idempotencyKey);
        ServiceInput::keys($context, ['attribution_id_v2', 'tracking']);
        self::scale($variables);
        $arguments = ContentManagementDocuments::arguments('useCometUFIDeleteCommentMutation', [...$variables,
            'input' => ['comment_id' => $commentId, 'remove_location' => 'MENU',
                'attribution_id_v2' => ServiceInput::requiredString($context, 'attribution_id_v2'), 'tracking' => self::tracking($context)]]);
        return $this->graph->execute('useCometUFIDeleteCommentMutation', $arguments, $actorId, 'https://www.facebook.com', $idempotencyKey,
            static function (array $data) use ($commentId): array {
                if (($data['comment_delete']['deleted_comment_id'] ?? null) !== $commentId) ServiceInput::unexpected(true);
                return ['id' => $commentId, 'deleted' => true];
            });
    }

    /** Hide or unhide through the observed comment menu action. The returned owner/viewer flags
     * describe the actual server state; hiding for the viewer is not proof of global moderation.
     * @param array<string,mixed> $context numeric feedback_source; hiding also needs attribution_id_v2.
     * @param array<string,mixed> $variables Current providers, feedLocation, scale and commentActionLinksRewriteEnabled.
     * @return array<string,mixed>
     */
    public function setHidden(string $actorId, string $commentId, bool $hidden, string $idempotencyKey, array $context, array $variables): array
    {
        ServiceInput::actor($actorId);
        ServiceInput::opaque($commentId, 'GraphQL comment ID');
        ServiceInput::key($idempotencyKey);
        ServiceInput::keys($context, $hidden ? ['feedback_source', 'attribution_id_v2'] : ['feedback_source']);
        if (!is_int($context['feedback_source'] ?? null) || $context['feedback_source'] < 0) throw new \InvalidArgumentException('Use the actual numeric feedback source from the comment menu');
        ServiceInput::requiredString($variables, 'feedLocation');
        self::scale($variables);
        if (!is_bool($variables['commentActionLinksRewriteEnabled'] ?? null)) throw new \InvalidArgumentException('Supply the current action-links rewrite provider value');
        $input = ['comment_id' => $commentId, 'feedback_source' => $context['feedback_source'], 'site' => 'comet'];
        if ($hidden) {
            $input['hide_location'] = 'MENU';
            $input['attribution_id_v2'] = ServiceInput::requiredString($context, 'attribution_id_v2');
        }
        $name = $hidden ? 'CometUFIHideCommentMutation' : 'CometUFIUnhideCommentMutation';
        $arguments = ContentManagementDocuments::arguments($name, [...$variables, 'input' => $input, 'useDefaultActor' => false]);
        return $this->graph->execute($name, $arguments, $actorId, 'https://www.facebook.com', $idempotencyKey,
            static function (array $data) use ($commentId, $hidden): array {
                $comment = $data[$hidden ? 'comment_hide' : 'comment_unhide']['comment'] ?? null;
                if (!is_array($comment) || ($comment['id'] ?? null) !== $commentId || ($comment['is_hidden_by_viewer'] ?? null) !== $hidden
                    || !is_bool($comment['is_hidden_by_content_owner'] ?? null)) ServiceInput::unexpected(true);
                return ['id' => $commentId, 'is_hidden_by_viewer' => $hidden, 'is_hidden_by_content_owner' => $comment['is_hidden_by_content_owner'],
                    'spam_display_mode' => is_string($comment['spam_display_mode'] ?? null) ? $comment['spam_display_mode'] : null];
            });
    }

    /** @param array<string,mixed> $context
     * @return list<string>
     */
    private static function tracking(array $context): array
    {
        $tracking = ServiceInput::requiredArray($context, 'tracking');
        if (!array_is_list($tracking) || count(array_filter($tracking, 'is_string')) !== count($tracking)) throw new \InvalidArgumentException('Tracking must be a list of current menu strings');
        return $tracking;
    }

    /** @param array<string,mixed> $variables */
    private static function renderingContext(array $variables): void
    {
        ServiceInput::requiredString($variables, 'feedLocation');
        if (!is_bool($variables['useDefaultActor'] ?? null)) throw new \InvalidArgumentException('Supply the actual default-actor rendering choice');
        self::scale($variables);
    }

    /** @param array<string,mixed> $variables */
    private static function scale(array $variables): void
    {
        if (!is_numeric($variables['scale'] ?? null) || (float)$variables['scale'] <= 0 || !is_finite((float)$variables['scale'])) throw new \InvalidArgumentException('Supply the current display scale');
    }

    /** Initial server-selected comment ordering; return intent_token for subsequent pages.
     * @param array<string,mixed> $variables Requires current providers and the actual feedLocation.
     * @return array<string,mixed>
     */
    public function list(string $feedbackId, array $variables = [], ?string $actorId = null): array
    {
        ServiceInput::opaque($feedbackId, 'feedback ID');
        ServiceInput::requiredString($variables, 'feedLocation');
        if ($actorId !== null) ServiceInput::actor($actorId);
        $arguments = PageDocuments::arguments('CommentListComponentsRootQuery', [...$variables, 'id' => $feedbackId]);
        $data = $this->graph->execute('CommentListComponentsRootQuery', $arguments, $actorId);
        return self::commentPage($data['node'] ?? null, $feedbackId, null);
    }

    /** Use the end_cursor and intent_token returned by the initial comment page.
     * @param array<string,mixed> $variables Requires current providers and the same feedLocation.
     * @return array<string,mixed>
     */
    public function next(string $feedbackId, string $cursor, string $intentToken, int $count = 20, array $variables = [], ?string $actorId = null): array
    {
        return $this->paginate($feedbackId, $cursor, $intentToken, $count, $variables, $actorId, false);
    }

    /** Use start_cursor with the same server-provided ordering token.
     * @param array<string,mixed> $variables
     * @return array<string,mixed>
     */
    public function previous(string $feedbackId, string $cursor, string $intentToken, int $count = 20, array $variables = [], ?string $actorId = null): array
    {
        return $this->paginate($feedbackId, $cursor, $intentToken, $count, $variables, $actorId, true);
    }

    /** @param array<string,mixed> $variables
     * @return array<string,mixed>
     */
    private function paginate(string $feedbackId, string $cursor, string $intentToken, int $count, array $variables, ?string $actorId, bool $previous): array
    {
        ServiceInput::opaque($feedbackId, 'feedback ID');
        ServiceInput::opaque($cursor, 'comment cursor');
        ServiceInput::opaque($intentToken, 'server comment ordering token');
        ServiceInput::requiredString($variables, 'feedLocation');
        if ($actorId !== null) ServiceInput::actor($actorId);
        if ($count < 1 || $count > 100) throw new \InvalidArgumentException('Use a comment page size between 1 and 100');
        $arguments = PageDocuments::arguments('CommentsListComponentsPaginationQuery', [...$variables,
            'id' => $feedbackId, 'commentsIntentToken' => $intentToken,
            'commentsAfterCount' => $previous ? null : $count, 'commentsAfterCursor' => $previous ? null : $cursor,
            'commentsBeforeCount' => $previous ? $count : null, 'commentsBeforeCursor' => $previous ? $cursor : null]);
        $data = $this->graph->execute('CommentsListComponentsPaginationQuery', $arguments, $actorId);
        return self::commentPage($data['node'] ?? null, $feedbackId, $intentToken);
    }

    /** @return array<string,mixed> */
    private static function commentPage(mixed $node, string $feedbackId, ?string $requestedIntent): array
    {
        if (!is_array($node) || ($node['id'] ?? null) !== $feedbackId) ServiceInput::unexpected();
        $instance = $node['comment_rendering_instance_for_feed_location'] ?? null;
        $connection = is_array($instance) ? ($instance['comments'] ?? null) : null;
        if (!is_array($connection) || !is_array($connection['edges'] ?? null)) ServiceInput::unexpected();
        $items = [];
        foreach ($connection['edges'] as $edge) {
            if (!is_array($edge) || !is_array($edge['node'] ?? null)) continue;
            $comment = $edge['node'];
            $items[] = ['id' => ServiceInput::scalarId($comment['id'] ?? null),
                'legacy_fbid' => ServiceInput::scalarId($comment['legacy_fbid'] ?? null),
                'text' => is_string($comment['body']['text'] ?? null) ? $comment['body']['text'] : null,
                'author' => ['id' => ServiceInput::scalarId($comment['author']['id'] ?? null),
                    'name' => is_string($comment['author']['name'] ?? null) ? $comment['author']['name'] : null],
                'created_time' => is_scalar($comment['created_time'] ?? null) ? $comment['created_time'] : null,
                'feedback_id' => ServiceInput::scalarId($comment['feedback']['id'] ?? null),
                'parent_id' => ServiceInput::scalarId($comment['comment_direct_parent']['id'] ?? null),
                'cursor' => is_string($edge['cursor'] ?? null) ? $edge['cursor'] : null];
        }
        $page = is_array($connection['page_info'] ?? null) ? $connection['page_info'] : [];
        return ['feedback_id' => $feedbackId, 'items' => $items,
            'intent_token' => is_string($instance['selected_intent']['intent_token'] ?? null) ? $instance['selected_intent']['intent_token'] : $requestedIntent,
            'count' => is_int($connection['count'] ?? null) ? $connection['count'] : null,
            'total_count' => is_int($connection['total_count'] ?? null) ? $connection['total_count'] : null,
            'page_info' => ['end_cursor' => is_string($page['end_cursor'] ?? null) ? $page['end_cursor'] : null,
                'start_cursor' => is_string($page['start_cursor'] ?? null) ? $page['start_cursor'] : null,
                'has_next_page' => is_bool($page['has_next_page'] ?? null) ? $page['has_next_page'] : null,
                'has_previous_page' => is_bool($page['has_previous_page'] ?? null) ? $page['has_previous_page'] : null]];
    }

    /** $feedbackId is the exact GraphQL feedback ID, not a manufactured post ID.
     * Context requires session_id and feedback_source (the GraphQL enum name).
     * @param array<string,mixed> $context
     * @param array<string,mixed> $variables Current Relay provider values and rendering context.
     * @return array<string,mixed>
     */
    public function create(string $actorId, string $feedbackId, string $text, string $idempotencyKey, array $context, array $variables = []): array
    {
        ServiceInput::actor($actorId);
        ServiceInput::opaque($feedbackId, 'feedback ID');
        $text = ServiceInput::text($text);
        $token = ServiceInput::mutationId($idempotencyKey);
        ServiceInput::keys($context, ['session_id', 'feedback_source', 'attribution_id_v2', 'tracking', 'reply_comment_parent_fbid', 'reply_target_clicked', 'formatting_style', 'is_self_disclosed_as_ai_generated']);
        $source = ServiceInput::requiredString($context, 'feedback_source');
        $numericSource = PageDocuments::feedbackSource($source);
        $input = ['actor_id' => $actorId, 'client_mutation_id' => $token, 'idempotence_token' => $token,
            'feedback_id' => $feedbackId, 'message' => ['ranges' => [], 'text' => $text], 'attachments' => null,
            'session_id' => ServiceInput::requiredString($context, 'session_id'), 'feedback_source' => $source];
        foreach (['attribution_id_v2', 'tracking', 'reply_comment_parent_fbid', 'reply_target_clicked', 'formatting_style', 'is_self_disclosed_as_ai_generated'] as $field) {
            if (array_key_exists($field, $context)) $input[$field] = $context[$field];
        }
        if (isset($input['reply_comment_parent_fbid'])) {
            if (!is_string($input['reply_comment_parent_fbid'])) throw new \InvalidArgumentException('Use the exact parent comment ID');
            ServiceInput::opaque($input['reply_comment_parent_fbid'], 'parent comment ID');
        }
        foreach (['reply_target_clicked', 'is_self_disclosed_as_ai_generated'] as $field) {
            if (isset($input[$field]) && !is_bool($input[$field])) throw new \InvalidArgumentException('Comment context boolean required');
        }
        if (isset($input['tracking']) && (!is_array($input['tracking']) || !array_is_list($input['tracking'])
            || count(array_filter($input['tracking'], 'is_string')) !== count($input['tracking']))) throw new \InvalidArgumentException('Tracking must be a list of strings');
        foreach (['attribution_id_v2', 'formatting_style'] as $field) {
            if (isset($input[$field]) && !is_string($input[$field])) throw new \InvalidArgumentException('Comment context string required');
        }
        $arguments = PageDocuments::arguments('useCometUFICreateCommentMutation', [...$variables, 'input' => $input, 'feedbackSource' => $numericSource]);
        return $this->graph->execute('useCometUFICreateCommentMutation', $arguments, $actorId, 'https://www.facebook.com', $idempotencyKey,
            /** @param array<string,mixed> $data
             * @return array<string,mixed>
             */
            static function (array $data): array {
                $node = $data['comment_create']['feedback_comment_edge']['node'] ?? null;
                if (!is_array($node) || ServiceInput::scalarId($node['id'] ?? null) === null) ServiceInput::unexpected(true);
                $author = is_array($node['author'] ?? null) ? $node['author'] : [];
                return ['id' => ServiceInput::scalarId($node['id']), 'legacy_fbid' => ServiceInput::scalarId($node['legacy_fbid'] ?? null),
                    'text' => is_string($node['body']['text'] ?? null) ? $node['body']['text'] : null,
                    'author' => ['id' => ServiceInput::scalarId($author['id'] ?? null), 'name' => is_string($author['name'] ?? null) ? $author['name'] : null],
                    'created_time' => is_scalar($node['created_time'] ?? null) ? $node['created_time'] : null,
                    'feedback_id' => ServiceInput::scalarId($node['feedback']['id'] ?? null),
                    'parent_id' => ServiceInput::scalarId($node['comment_direct_parent']['id'] ?? null)];
            });
    }
}
