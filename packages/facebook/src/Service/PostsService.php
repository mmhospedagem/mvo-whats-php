<?php
declare(strict_types=1);

namespace MVO\Facebook\Service;

use MVO\Facebook\Catalog\PageDocuments;
use MVO\Facebook\Protocol\WebGraphQL;

/** Text posts and the profile timeline, using the shipped Web composer contract. */
final class PostsService
{
    public function __construct(private readonly WebGraphQL $graph) {}

    /** Required context: composer_entry_point, composer_source_surface, composer_type,
     * logging.composer_session_id, audience and tracking. Relay provider values belong in $variables.
     * @param array<string,mixed> $context
     * @param array<string,mixed> $variables
     * @return array<string,mixed>
     */
    public function createText(string $actorId, string $text, string $idempotencyKey, array $context, array $variables = []): array
    {
        ServiceInput::actor($actorId);
        $text = ServiceInput::text($text);
        $token = ServiceInput::mutationId($idempotencyKey);
        ServiceInput::keys($context, ['composer_entry_point', 'composer_source_surface', 'composer_type', 'logging', 'audience', 'tracking', 'navigation_data', 'is_tracking_encrypted']);
        $logging = ServiceInput::requiredArray($context, 'logging');
        ServiceInput::keys($logging, ['composer_session_id']);
        ServiceInput::requiredString($logging, 'composer_session_id');
        $audience = ServiceInput::requiredArray($context, 'audience');
        if ($audience === [] || array_is_list($audience)) throw new \InvalidArgumentException('Supply the actual composer audience object');
        $tracking = ServiceInput::requiredArray($context, 'tracking');
        if (!array_is_list($tracking) || count(array_filter($tracking, 'is_string')) !== count($tracking)) throw new \InvalidArgumentException('Tracking must be a list of strings from the current composer context');
        $input = [
            'composer_entry_point' => ServiceInput::requiredString($context, 'composer_entry_point'),
            'composer_source_surface' => ServiceInput::requiredString($context, 'composer_source_surface'),
            'composer_type' => ServiceInput::requiredString($context, 'composer_type'),
            'source' => 'WWW', 'actor_id' => $actorId, 'client_mutation_id' => $token,
            'idempotence_token' => $token, 'message' => ['ranges' => [], 'text' => $text],
            'logging' => $logging, 'audience' => $audience, 'tracking' => $tracking,
        ];
        if ($input['composer_type'] !== 'profile') throw new \InvalidArgumentException('This method implements the profile timeline composer');
        foreach (['navigation_data', 'is_tracking_encrypted'] as $field) if (array_key_exists($field, $context)) $input[$field] = $context[$field];
        if (isset($input['navigation_data']) && !is_array($input['navigation_data'])) throw new \InvalidArgumentException('Navigation data must be an object');
        if (isset($input['is_tracking_encrypted']) && !is_bool($input['is_tracking_encrypted'])) throw new \InvalidArgumentException('is_tracking_encrypted must be boolean');
        // ProfileCometComposerHelpers and ComposerStoryCreateMutation derive these for a timeline post.
        $arguments = PageDocuments::arguments('ComposerStoryCreateMutation', [...$variables,
            'input' => $input, 'feedbackSource' => 0, 'feedLocation' => 'TIMELINE',
            'renderLocation' => 'timeline', 'privacySelectorRenderLocation' => 'COMET_STREAM',
            'isTimeline' => true, 'isFeed' => false, 'isGroup' => false, 'isEvent' => false,
            'isFundraiser' => false, 'isFunFactPost' => false, 'isSocialLearning' => false,
            'isPageNewsFeed' => false, 'isProfileReviews' => false, 'isWorkSharedDraft' => false,
        ]);
        return $this->graph->execute('ComposerStoryCreateMutation', $arguments, $actorId, 'https://www.facebook.com', $idempotencyKey,
            /** @param array<string,mixed> $data
             * @return array<string,mixed>
             */
            static function (array $data): array {
                $created = $data['story_create'] ?? null;
                if (!is_array($created)) ServiceInput::unexpected(true);
                $story = is_array($created['story'] ?? null) ? $created['story'] : [];
                $storyId = ServiceInput::scalarId($created['story_id'] ?? $story['id'] ?? null);
                $postId = ServiceInput::scalarId($created['post_id'] ?? null);
                if ($storyId === null && $postId === null) ServiceInput::unexpected(true);
                return ['post_id' => $postId, 'story_id' => $storyId,
                    'url' => is_string($story['url'] ?? null) ? $story['url'] : null,
                    'publishing_flow' => is_string($created['publishing_flow'] ?? null) ? $created['publishing_flow'] : null,
                    'scheduled_publish_time' => is_scalar($story['scheduled_publish_time'] ?? null) ? $story['scheduled_publish_time'] : null];
            });
    }

    /** @param array<string,mixed> $variables Current Relay provider values and optional timeline filters.
     * @return array<string,mixed>
     */
    public function list(string $profileId, array $variables = [], ?string $actorId = null): array
    {
        ServiceInput::actor($profileId);
        if ($actorId !== null) ServiceInput::actor($actorId);
        $arguments = PageDocuments::arguments('ProfileCometTimelineFeedQuery', [...$variables, 'userID' => $profileId]);
        $data = $this->graph->execute('ProfileCometTimelineFeedQuery', $arguments, $actorId);
        return self::timeline($data['user'] ?? null);
    }

    /** @param array<string,mixed> $variables
     * @return array<string,mixed>
     */
    public function next(string $profileId, string $cursor, array $variables = [], ?string $actorId = null): array
    {
        ServiceInput::actor($profileId);
        ServiceInput::opaque($cursor, 'timeline cursor');
        if ($actorId !== null) ServiceInput::actor($actorId);
        $arguments = PageDocuments::arguments('ProfileCometTimelineFeedRefetchQuery', [...$variables, 'id' => $profileId, 'cursor' => $cursor]);
        $data = $this->graph->execute('ProfileCometTimelineFeedRefetchQuery', $arguments, $actorId);
        return self::timeline($data['node'] ?? null);
    }

    /** @return array<string,mixed> */
    private static function timeline(mixed $user): array
    {
        $connection = is_array($user) ? ($user['timeline_list_feed_units'] ?? null) : null;
        if (!is_array($connection) || !is_array($connection['edges'] ?? null)) ServiceInput::unexpected();
        $items = [];
        foreach ($connection['edges'] as $edge) {
            if (!is_array($edge) || !is_array($edge['node'] ?? null)) continue;
            $story = $edge['node'];
            $content = is_array($story['comet_sections']['content']['story'] ?? null) ? $story['comet_sections']['content']['story'] : $story;
            $feedback = $story['feedback'] ?? $story['comet_sections']['feedback']['story']['feedback'] ?? null;
            $items[] = ['id' => ServiceInput::scalarId($story['id'] ?? null),
                'post_id' => ServiceInput::scalarId($story['post_id'] ?? $story['legacy_story_hideable_id'] ?? null),
                'text' => is_string($content['message']['text'] ?? null) ? $content['message']['text'] : null,
                'url' => is_string($story['url'] ?? $content['url'] ?? null) ? ($story['url'] ?? $content['url']) : null,
                'feedback_id' => is_array($feedback) ? ServiceInput::scalarId($feedback['id'] ?? null) : null,
                'cursor' => is_string($edge['cursor'] ?? null) ? $edge['cursor'] : null];
        }
        $pageInfo = is_array($connection['page_info'] ?? null) ? $connection['page_info'] : [];
        return ['items' => $items, 'page_info' => [
            'end_cursor' => is_string($pageInfo['end_cursor'] ?? null) ? $pageInfo['end_cursor'] : null,
            'has_next_page' => is_bool($pageInfo['has_next_page'] ?? null) ? $pageInfo['has_next_page'] : null]];
    }
}
