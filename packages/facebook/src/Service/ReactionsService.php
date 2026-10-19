<?php
declare(strict_types=1);

namespace MVO\Facebook\Service;

use MVO\Facebook\Catalog\PageDocuments;
use MVO\Facebook\Protocol\WebGraphQL;

final class ReactionsService
{
    public function __construct(private readonly WebGraphQL $graph) {}

    /** Counts are returned independently from reactor identities; no identity is inferred from a count.
     * @return array<string,mixed>
     */
    public function summary(string $feedbackId, float $scale = 1.0, ?string $actorId = null): array
    {
        $node = $this->reactionNode($feedbackId, null, $scale, $actorId);
        $top = $node['top_reactions'] ?? null;
        if (!is_array($top) || !is_array($top['summary'] ?? null)) ServiceInput::unexpected();
        $items = [];
        $facebookCount = 0;
        $completeCounts = true;
        foreach ($top['summary'] as $row) {
            if (!is_array($row)) { $completeCounts = false; continue; }
            $count = is_int($row['reaction_count'] ?? null) && $row['reaction_count'] >= 0 ? $row['reaction_count'] : null;
            if ($count === null) $completeCounts = false;
            else $facebookCount += $count;
            $items[] = ['reaction_id' => ServiceInput::scalarId($row['reaction']['id'] ?? null),
                'name' => is_string($row['reaction']['localized_name'] ?? null) ? $row['reaction']['localized_name'] : null,
                'count' => $count, 'count_label' => is_string($row['reaction_count_reduced'] ?? null) ? $row['reaction_count_reduced'] : null];
        }
        $cross = is_array($node['cross_universe_feedback_info'] ?? null) ? $node['cross_universe_feedback_info'] : [];
        return ['feedback_id' => $feedbackId, 'reactions' => $items,
            'reaction_types_count' => is_int($top['count'] ?? null) ? $top['count'] : null,
            'facebook_reaction_count' => $completeCounts ? $facebookCount : null,
            'instagram_reaction_count' => is_int($cross['ig_reaction_count'] ?? null) ? $cross['ig_reaction_count'] : null,
            'aggregated_reaction_count' => is_int($cross['aggregated_reaction_count'] ?? null) ? $cross['aggregated_reaction_count'] : null,
            'display_strategy' => is_string($node['reaction_display_config']['reaction_display_strategy'] ?? null) ? $node['reaction_display_config']['reaction_display_strategy'] : null];
    }

    /** The pageable reactors connection is separate from the server's important_reactors subset.
     * @return array<string,mixed>
     */
    public function list(string $feedbackId, ?string $reactionId = null, float $scale = 1.0, ?string $actorId = null): array
    {
        $node = $this->reactionNode($feedbackId, $reactionId, $scale, $actorId);
        return self::reactorPage($node, $feedbackId, $reactionId);
    }

    /** Keep reactionId unchanged when following a cursor from a filtered page.
     * @return array<string,mixed>
     */
    public function next(string $feedbackId, string $cursor, ?string $reactionId = null, int $count = 10, float $scale = 1.0, ?string $actorId = null): array
    {
        self::readScope($feedbackId, $reactionId, $scale, $actorId);
        ServiceInput::opaque($cursor, 'reactors cursor');
        if ($count < 1 || $count > 100) throw new \InvalidArgumentException('Use a reactors page size between 1 and 100');
        $arguments = PageDocuments::arguments('CometUFIReactionsDialogTabContentRefetchQuery', [
            'id' => $feedbackId, 'feedbackTargetID' => $feedbackId, 'reactionID' => $reactionId,
            'cursor' => $cursor, 'count' => $count, 'scale' => $scale]);
        $data = $this->graph->execute('CometUFIReactionsDialogTabContentRefetchQuery', $arguments, $actorId);
        $node = $data['node'] ?? null;
        if (!is_array($node) || ($node['id'] ?? null) !== $feedbackId) ServiceInput::unexpected();
        return self::reactorPage($node, $feedbackId, $reactionId);
    }

    /** @return array<string,mixed> */
    private function reactionNode(string $feedbackId, ?string $reactionId, float $scale, ?string $actorId): array
    {
        self::readScope($feedbackId, $reactionId, $scale, $actorId);
        $arguments = PageDocuments::arguments('CometUFIReactionsDialogQuery', [
            'feedbackTargetID' => $feedbackId, 'reactionID' => $reactionId, 'scale' => $scale]);
        $data = $this->graph->execute('CometUFIReactionsDialogQuery', $arguments, $actorId);
        $node = $data['node'] ?? null;
        if (!is_array($node) || ($node['id'] ?? null) !== $feedbackId) ServiceInput::unexpected();
        return $node;
    }

    private static function readScope(string $feedbackId, ?string $reactionId, float $scale, ?string $actorId): void
    {
        ServiceInput::opaque($feedbackId, 'feedback ID');
        if ($reactionId !== null && !preg_match('/^[1-9][0-9]{0,24}$/D', $reactionId)) throw new \InvalidArgumentException('Use an observed reaction ID or null for all reactions');
        if (!is_finite($scale) || $scale <= 0 || $scale > 4) throw new \InvalidArgumentException('Use a pixel scale greater than zero and at most four');
        if ($actorId !== null) ServiceInput::actor($actorId);
    }

    /** @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private static function reactorPage(array $node, string $feedbackId, ?string $reactionId): array
    {
        $connection = $node['reactors'] ?? null;
        if (!is_array($connection) || !is_array($connection['edges'] ?? null)) ServiceInput::unexpected();
        $page = is_array($connection['page_info'] ?? null) ? $connection['page_info'] : [];
        return ['feedback_id' => $feedbackId, 'reaction_filter' => $reactionId,
            'items' => self::reactors($connection['edges']),
            'important_items' => self::reactors(is_array($node['important_reactors']['edges'] ?? null) ? $node['important_reactors']['edges'] : []),
            'page_info' => ['end_cursor' => is_string($page['end_cursor'] ?? null) ? $page['end_cursor'] : null,
                'has_next_page' => is_bool($page['has_next_page'] ?? null) ? $page['has_next_page'] : null]];
    }

    /** @param array<mixed> $edges
     * @return list<array<string,mixed>>
     */
    private static function reactors(array $edges): array
    {
        $items = [];
        foreach ($edges as $edge) {
            if (!is_array($edge) || !is_array($edge['node'] ?? null)) continue;
            $node = $edge['node'];
            $items[] = ['id' => ServiceInput::scalarId($node['id'] ?? null),
                'type' => is_string($node['__typename'] ?? null) ? $node['__typename'] : null,
                'name' => is_string($node['name'] ?? null) ? $node['name'] : null,
                'profile_picture_url' => is_string($node['profile_picture']['uri'] ?? null) ? $node['profile_picture']['uri'] : null,
                'url' => is_string($node['profile_url'] ?? $node['url'] ?? null) ? ($node['profile_url'] ?? $node['url']) : null,
                'reaction_id' => ServiceInput::scalarId($edge['feedback_reaction_info']['id'] ?? null),
                'cursor' => is_string($edge['cursor'] ?? null) ? $edge['cursor'] : null];
        }
        return $items;
    }

    /** Use a reaction ID actually returned by supported_reaction_infos.
     * @param array<string,mixed> $context Requires session_id and feedback_source enum name.
     * @param array<string,mixed> $variables Requires the current Relay provider values.
     * @return array<string,mixed>
     */
    public function set(string $actorId, string $feedbackId, string $reactionId, string $idempotencyKey, array $context, array $variables = []): array
    {
        ServiceInput::actor($actorId);
        ServiceInput::opaque($feedbackId, 'feedback ID');
        ServiceInput::opaque($reactionId, 'reaction ID', 128);
        if (!preg_match('/^[0-9]+$/D', $reactionId)) throw new \InvalidArgumentException('Use the observed numeric reaction ID');
        ServiceInput::keys($context, ['session_id', 'feedback_source', 'attribution_id_v2', 'tracking', 'feedback_referrer']);
        $source = ServiceInput::requiredString($context, 'feedback_source');
        PageDocuments::feedbackSource($source);
        $input = ['actor_id' => $actorId, 'client_mutation_id' => ServiceInput::mutationId($idempotencyKey),
            'feedback_id' => $feedbackId, 'feedback_reaction_id' => $reactionId, 'feedback_source' => $source,
            'session_id' => ServiceInput::requiredString($context, 'session_id')];
        foreach (['attribution_id_v2', 'tracking', 'feedback_referrer'] as $field) if (array_key_exists($field, $context)) $input[$field] = $context[$field];
        foreach (['attribution_id_v2', 'feedback_referrer'] as $field) {
            if (isset($input[$field]) && !is_string($input[$field])) throw new \InvalidArgumentException('Reaction context string required');
        }
        if (isset($input['tracking']) && (!is_array($input['tracking']) || !array_is_list($input['tracking'])
            || count(array_filter($input['tracking'], 'is_string')) !== count($input['tracking']))) throw new \InvalidArgumentException('Tracking must be a list of strings');
        $arguments = PageDocuments::arguments('CometUFIFeedbackReactMutation', [...$variables, 'input' => $input]);
        return $this->graph->execute('CometUFIFeedbackReactMutation', $arguments, $actorId, 'https://www.facebook.com', $idempotencyKey,
            /** @param array<string,mixed> $data
             * @return array<string,mixed>
             */
            static function (array $data) use ($feedbackId): array {
                $feedback = $data['feedback_react']['feedback'] ?? null;
                if (!is_array($feedback) || ($feedback['id'] ?? null) !== $feedbackId) ServiceInput::unexpected(true);
                return ['feedback_id' => $feedbackId,
                    'viewer_reaction_known' => array_key_exists('viewer_feedback_reaction_info', $feedback),
                    'viewer_reaction_id' => ServiceInput::scalarId($feedback['viewer_feedback_reaction_info']['id'] ?? null),
                    'reaction_count' => is_int($feedback['reaction_count']['count'] ?? null) ? $feedback['reaction_count']['count'] : null,
                    'supported_reaction_infos' => self::supported($feedback['supported_reaction_infos'] ?? null)];
            });
    }

    /** The shipped mutation explicitly uses "0" for removing the viewer's reaction.
     * @param array<string,mixed> $context
     * @param array<string,mixed> $variables
     * @return array<string,mixed>
     */
    public function remove(string $actorId, string $feedbackId, string $idempotencyKey, array $context, array $variables = []): array
    {
        return $this->set($actorId, $feedbackId, '0', $idempotencyKey, $context, $variables);
    }

    /** @return list<array{id:string}> */
    private static function supported(mixed $rows): array
    {
        $result = [];
        if (is_array($rows)) foreach ($rows as $row) {
            $id = is_array($row) ? ServiceInput::scalarId($row['id'] ?? null) : null;
            if ($id !== null) $result[] = ['id' => $id];
        }
        return $result;
    }
}
