<?php
declare(strict_types=1);

namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Service\ReactionsService;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/PageServiceFixture.php';

final class ReactionsServiceTest extends TestCase
{
    public function testReactionReadsKeepCountsAndActualPeopleSeparateAndPaginateTheNativeConnection(): void
    {
        $node = ['id' => 'FEEDBACK_OPAQUE', 'top_reactions' => ['count' => 2, 'summary' => [
            ['reaction_count' => 10, 'reaction_count_reduced' => '10', 'reaction' => ['id' => '1635855486666999', 'localized_name' => 'Curtir']],
            ['reaction_count' => 20, 'reaction_count_reduced' => '20', 'reaction' => ['id' => '1678524932434102', 'localized_name' => 'Amei']]]],
            'cross_universe_feedback_info' => ['ig_reaction_count' => 5, 'aggregated_reaction_count' => 50],
            'reaction_display_config' => ['reaction_display_strategy' => 'SHOW_COUNTS'],
            'important_reactors' => ['edges' => [['node' => ['id' => '60006', '__typename' => 'User', 'name' => 'Destaque']]]],
            'reactors' => ['edges' => [['cursor' => 'EDGE_CURSOR', 'node' => ['id' => '50005', '__typename' => 'User', 'name' => 'Pessoa',
                'profile_picture' => ['uri' => 'https://example.invalid/synthetic.png']], 'feedback_reaction_info' => ['id' => '1635855486666999']]],
                'page_info' => ['end_cursor' => 'REACTOR_CURSOR+/==', 'has_next_page' => true]]];
        $fixture = new PageServiceFixture(['node' => $node]);
        try {
            $service = new ReactionsService($fixture->graph);
            $summary = $service->summary('FEEDBACK_OPAQUE', 1.0, '20002');
            self::assertSame(2, $summary['reaction_types_count']);
            self::assertSame(30, $summary['facebook_reaction_count']);
            self::assertSame(5, $summary['instagram_reaction_count']);
            self::assertSame(50, $summary['aggregated_reaction_count']);
            self::assertArrayNotHasKey('items', $summary);
            $first = $service->list('FEEDBACK_OPAQUE', '1635855486666999', 1.0, '20002');
            self::assertSame('50005', $first['items'][0]['id']);
            self::assertSame('1635855486666999', $first['items'][0]['reaction_id']);
            self::assertSame('60006', $first['important_items'][0]['id']);
            self::assertCount(1, $first['items']);
            self::assertSame('28862578480043890', $fixture->session->requests[1]['form']['doc_id']);
            $service->next('FEEDBACK_OPAQUE', $first['page_info']['end_cursor'], $first['reaction_filter'], 10, 1.0, '20002');
            self::assertSame('29689789980621692', $fixture->session->requests[2]['form']['doc_id']);
            $arguments = $fixture->session->variables(2);
            self::assertSame('REACTOR_CURSOR+/==', $arguments['cursor']);
            self::assertSame('1635855486666999', $arguments['reactionID']);
            self::assertSame('FEEDBACK_OPAQUE', $arguments['id']);
            self::assertSame(10, $arguments['count']);
        } finally { unset($service); $fixture->close(); }
    }

    public function testUnknownOrHiddenCountsAreNotReplacedWithZero(): void
    {
        $fixture = new PageServiceFixture(['node' => ['id' => 'FEEDBACK_OPAQUE',
            'top_reactions' => ['summary' => [['reaction_count' => null, 'reaction' => ['id' => '1635855486666999']]]],
            'reaction_display_config' => ['reaction_display_strategy' => 'HIDE_COUNTS']]]);
        try {
            $service = new ReactionsService($fixture->graph);
            $summary = $service->summary('FEEDBACK_OPAQUE');
            self::assertNull($summary['facebook_reaction_count']);
            self::assertNull($summary['aggregated_reaction_count']);
            self::assertSame('HIDE_COUNTS', $summary['display_strategy']);
            self::assertNull($summary['reactions'][0]['count']);
        } finally { unset($service); $fixture->close(); }
    }

    public function testReactionAndRemovalUseTheNativeMutationWithDifferentIdempotencyKeys(): void
    {
        $fixture = new PageServiceFixture(['feedback_react' => ['feedback' => ['id' => 'FEEDBACK_OPAQUE',
            'viewer_feedback_reaction_info' => ['id' => '1635855486666999'], 'reaction_count' => ['count' => 4],
            'supported_reaction_infos' => [['id' => '1635855486666999', 'private_extra' => 'SYNTHETIC_PRIVATE']]]]]);
        try {
            $service = new ReactionsService($fixture->graph);
            $context = ['session_id' => 'SYNTHETIC_SESSION', 'feedback_source' => 'PROFILE'];
            $providers = PageServiceFixture::providers('CometUFIFeedbackReactMutation');
            $set = $service->set('20002', 'FEEDBACK_OPAQUE', '1635855486666999', 'react-key', $context, $providers);
            self::assertTrue($set['viewer_reaction_known']);
            self::assertSame('1635855486666999', $set['viewer_reaction_id']);
            self::assertSame([['id' => '1635855486666999']], $set['supported_reaction_infos']);
            self::assertSame($set, $service->set('20002', 'FEEDBACK_OPAQUE', '1635855486666999', 'react-key', $context, $providers));
            self::assertCount(1, $fixture->session->requests);
            $fixture->session->data = ['feedback_react' => ['feedback' => ['id' => 'FEEDBACK_OPAQUE', 'viewer_feedback_reaction_info' => null]]];
            $removed = $service->remove('20002', 'FEEDBACK_OPAQUE', 'remove-key', $context, $providers);
            self::assertNull($removed['viewer_reaction_id']);
            self::assertTrue($removed['viewer_reaction_known']);
            self::assertSame('0', $fixture->session->variables(1)['input']['feedback_reaction_id']);
            self::assertSame('20002', $fixture->session->requests[1]['form']['av']);
        } finally { unset($service); $fixture->close(); }
    }

    public function testMissingProvidersFailBeforeIOAndAResponseForDifferentFeedbackIsRejected(): void
    {
        $fixture = new PageServiceFixture(['feedback_react' => ['feedback' => ['id' => 'OTHER_FEEDBACK']]]);
        try {
            $service = new ReactionsService($fixture->graph);
            $context = ['session_id' => 'S', 'feedback_source' => 'PROFILE'];
            try { $service->set('20002', 'FEEDBACK_OPAQUE', '1635855486666999', 'key', $context); self::fail('Expected missing provider'); }
            catch (FacebookException $error) { self::assertSame('facebook_provider_context_required', $error->reason); }
            self::assertCount(0, $fixture->session->requests);
            try { $service->set('20002', 'FEEDBACK_OPAQUE', '1635855486666999', 'key', $context, PageServiceFixture::providers('CometUFIFeedbackReactMutation')); self::fail('Expected feedback mismatch'); }
            catch (FacebookException $error) { self::assertSame('facebook_response_shape_unknown', $error->reason); }
            self::assertCount(1, $fixture->session->requests);
        } finally { unset($service); $fixture->close(); }
    }
}
