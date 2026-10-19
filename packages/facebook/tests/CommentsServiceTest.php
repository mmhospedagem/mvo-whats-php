<?php
declare(strict_types=1);

namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Service\CommentsService;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/PageServiceFixture.php';

final class CommentsServiceTest extends TestCase
{
    public function testCommentReadsPreserveServerOrderingAndUseBothPaginationDirections(): void
    {
        $node = ['id' => 'FEEDBACK_OPAQUE', 'comment_rendering_instance_for_feed_location' => [
            'selected_intent' => ['intent_token' => 'SERVER_ORDERING+/=='], 'comments' => [
                'count' => 1, 'total_count' => 15, 'edges' => [['cursor' => 'COMMENT_CURSOR+/==', 'node' => [
                    'id' => 'COMMENT_OPAQUE', 'body' => ['text' => 'Comentário publicado'],
                    'author' => ['id' => '50005', 'name' => 'Pessoa de teste'], 'private_extra' => 'SYNTHETIC_PRIVATE']]],
                'page_info' => ['end_cursor' => 'END+/==', 'start_cursor' => 'START+/==', 'has_next_page' => true, 'has_previous_page' => false]]]];
        $fixture = new PageServiceFixture(['node' => $node]);
        try {
            $service = new CommentsService($fixture->graph);
            $rootVariables = [...PageServiceFixture::providers('CommentListComponentsRootQuery'), 'feedLocation' => 'TIMELINE'];
            $first = $service->list('FEEDBACK_OPAQUE', $rootVariables, '20002');
            self::assertSame('SERVER_ORDERING+/==', $first['intent_token']);
            self::assertSame('Comentário publicado', $first['items'][0]['text']);
            self::assertSame('50005', $first['items'][0]['author']['id']);
            self::assertArrayNotHasKey('private_extra', $first['items'][0]);
            self::assertSame('29409681848615704', $fixture->session->requests[0]['form']['doc_id']);
            $paginationVariables = [...PageServiceFixture::providers('CommentsListComponentsPaginationQuery'), 'feedLocation' => 'TIMELINE'];
            $service->next('FEEDBACK_OPAQUE', $first['page_info']['end_cursor'], $first['intent_token'], 7, $paginationVariables, '20002');
            $next = $fixture->session->variables(1);
            self::assertSame('29265821953021786', $fixture->session->requests[1]['form']['doc_id']);
            self::assertSame('SERVER_ORDERING+/==', $next['commentsIntentToken']);
            self::assertSame('END+/==', $next['commentsAfterCursor']);
            self::assertSame(7, $next['commentsAfterCount']);
            self::assertNull($next['commentsBeforeCursor']);
            self::assertNull($next['commentsBeforeCount']);
            $service->previous('FEEDBACK_OPAQUE', $first['page_info']['start_cursor'], $first['intent_token'], 5, $paginationVariables, '20002');
            $previous = $fixture->session->variables(2);
            self::assertSame('START+/==', $previous['commentsBeforeCursor']);
            self::assertSame(5, $previous['commentsBeforeCount']);
            self::assertNull($previous['commentsAfterCursor']);
            self::assertNull($previous['commentsAfterCount']);
        } finally { unset($service); $fixture->close(); }
    }

    public function testCommentReadRequiresActualProvidersAndNeverCreatesAnOrderingToken(): void
    {
        $fixture = new PageServiceFixture();
        try {
            $service = new CommentsService($fixture->graph);
            try { $service->list('FEEDBACK_OPAQUE', ['feedLocation' => 'TIMELINE']); self::fail('Expected runtime providers'); }
            catch (FacebookException $error) { self::assertSame('facebook_provider_context_required', $error->reason); }
            try { $service->next('FEEDBACK_OPAQUE', 'CURSOR', '', 20, ['feedLocation' => 'TIMELINE']); self::fail('Expected real intent token'); }
            catch (\InvalidArgumentException) {}
            self::assertCount(0, $fixture->session->requests);
        } finally { unset($service); $fixture->close(); }
    }

    public function testCommentPreservesOpaqueFeedbackParentAndAuthorWhileReplayingOnlyOnce(): void
    {
        $fixture = new PageServiceFixture(['comment_create' => ['feedback_comment_edge' => ['node' => [
            'id' => 'COMMENT_OPAQUE', 'legacy_fbid' => '40004', 'body' => ['text' => 'Resposta'],
            'author' => ['id' => '20002', 'name' => 'Página de teste'], 'comment_direct_parent' => ['id' => 'PARENT_OPAQUE'],
            'feedback' => ['id' => 'COMMENT_FEEDBACK_OPAQUE'], 'created_time' => 1790000000]]]]);
        try {
            $context = ['session_id' => 'SYNTHETIC_SESSION', 'feedback_source' => 'PROFILE', 'reply_comment_parent_fbid' => 'PARENT_OPAQUE'];
            $variables = [...PageServiceFixture::providers('useCometUFICreateCommentMutation'), 'feedbackSource' => 0,
                'feedLocation' => 'TIMELINE', 'useDefaultActor' => false];
            $service = new CommentsService($fixture->graph);
            $first = $service->create('20002', 'FEEDBACK+/OPAQUE==', 'Resposta', 'comment-key', $context, $variables);
            $second = (new CommentsService($fixture->graph))->create('20002', 'FEEDBACK+/OPAQUE==', 'Resposta', 'comment-key', $context, $variables);
            self::assertSame($first, $second);
            self::assertSame('COMMENT_OPAQUE', $first['id']);
            self::assertSame('PARENT_OPAQUE', $first['parent_id']);
            self::assertSame('20002', $first['author']['id']);
            self::assertCount(1, $fixture->session->requests);
            $input = $fixture->session->variables()['input'];
            self::assertSame('FEEDBACK+/OPAQUE==', $input['feedback_id']);
            self::assertSame('PARENT_OPAQUE', $input['reply_comment_parent_fbid']);
            self::assertSame('PROFILE', $input['feedback_source']);
            self::assertSame(['ranges' => [], 'text' => 'Resposta'], $input['message']);
            self::assertSame('40023273857271114', $fixture->session->requests[0]['form']['doc_id']);
        } finally { unset($service); $fixture->close(); }
    }

    public function testAbsentProviderOrAttemptToOverrideActorNeverPerformsIO(): void
    {
        $fixture = new PageServiceFixture();
        try {
            $service = new CommentsService($fixture->graph);
            $context = ['session_id' => 'SYNTHETIC_SESSION', 'feedback_source' => 'PROFILE'];
            try { $service->create('20002', 'OPAQUE', 'Texto', 'key', $context); self::fail('Expected missing providers'); }
            catch (FacebookException $error) { self::assertSame('facebook_provider_context_required', $error->reason); }
            try { $service->create('20002', 'OPAQUE', 'Texto', 'key', [...$context, 'actor_id' => '30003']); self::fail('Expected invalid context'); }
            catch (\InvalidArgumentException) {}
            self::assertCount(0, $fixture->session->requests);
        } finally { unset($service); $fixture->close(); }
    }

    public function testMissingCommentResultIsNotPresentedAsSuccessfulCreation(): void
    {
        $fixture = new PageServiceFixture(['comment_create' => ['private_unexpected_field' => 'SYNTHETIC_PRIVATE']]);
        try {
            $service = new CommentsService($fixture->graph);
            try {
                $service->create('20002', 'OPAQUE', 'Texto', 'key', ['session_id' => 'S', 'feedback_source' => 'PROFILE'], PageServiceFixture::providers('useCometUFICreateCommentMutation'));
                self::fail('Expected unknown response shape');
            } catch (FacebookException $error) {
                self::assertSame('facebook_response_shape_unknown', $error->reason);
                self::assertSame('not_confirmed', $error->outcome);
                self::assertStringNotContainsString('SYNTHETIC_PRIVATE', $error->getMessage());
            }
        } finally { unset($service); $fixture->close(); }
    }
}
