<?php
declare(strict_types=1);

namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Service\PostsService;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/PageServiceFixture.php';

final class PostsServiceTest extends TestCase
{
    public function testTextPostUsesPageActorAndDurableReplayKeepsTheSamePayload(): void
    {
        $fixture = new PageServiceFixture(['story_create' => ['post_id' => '30003', 'story_id' => 'STORY_OPAQUE',
            'story' => ['id' => 'STORY_OPAQUE', 'url' => 'https://www.facebook.com/example/posts/30003']]]);
        try {
            $context = PageServiceFixture::composer();
            $variables = PageServiceFixture::providers('ComposerStoryCreateMutation');
            $first = (new PostsService($fixture->graph))->createText('20002', '  Texto de teste  ', 'post-key', $context, $variables);
            $second = (new PostsService($fixture->graph))->createText('20002', '  Texto de teste  ', 'post-key', $context, $variables);
            self::assertSame($first, $second);
            self::assertSame('30003', $first['post_id']);
            self::assertCount(1, $fixture->session->requests);
            $form = $fixture->session->requests[0]['form'];
            self::assertSame('10001', $form['__user']);
            self::assertSame('20002', $form['av']);
            self::assertSame('29143768885313011', $form['doc_id']);
            $input = $fixture->session->variables()['input'];
            self::assertSame('20002', $input['actor_id']);
            self::assertSame(['ranges' => [], 'text' => 'Texto de teste'], $input['message']);
            self::assertSame('WWW', $input['source']);
            self::assertSame($context['audience'], $input['audience']);
            try { (new PostsService($fixture->graph))->createText('20002', 'Outro texto', 'post-key', $context, $variables); self::fail('Expected command conflict'); }
            catch (FacebookException $error) { self::assertSame('facebook_idempotency_conflict', $error->reason); }
            self::assertCount(1, $fixture->session->requests);
        } finally { unset($service); $fixture->close(); }
    }

    public function testMissingProvidersAndMalformedComposerDoNotPerformIO(): void
    {
        $fixture = new PageServiceFixture();
        try {
            $service = new PostsService($fixture->graph);
            try { $service->createText('20002', 'Texto', 'key', PageServiceFixture::composer()); self::fail('Expected current providers'); }
            catch (FacebookException $error) { self::assertSame('facebook_provider_context_required', $error->reason); }
            try { $service->createText('20002', 'Texto', 'key', []); self::fail('Expected composer context'); }
            catch (FacebookException $error) { self::assertSame('facebook_operation_context_required', $error->reason); }
            self::assertCount(0, $fixture->session->requests);
        } finally { unset($service); $fixture->close(); }
    }

    public function testUncertainWriteIsNeverAutomaticallyRepeated(): void
    {
        $fixture = new PageServiceFixture([], true);
        try {
            $service = new PostsService($fixture->graph);
            $providers = PageServiceFixture::providers('ComposerStoryCreateMutation');
            foreach (['facebook_transport_failed', 'facebook_command_uncertain'] as $reason) {
                try { $service->createText('20002', 'Texto', 'uncertain', PageServiceFixture::composer(), $providers); self::fail('Expected uncertain write'); }
                catch (FacebookException $error) { self::assertSame($reason, $error->reason); self::assertNotNull($error->commandId); self::assertSame('unknown', $error->outcome); }
            }
            self::assertCount(1, $fixture->session->requests);
        } finally { unset($service); $fixture->close(); }
    }

    public function testTimelineUsesTheReturnedCursorAndDistinctPaginationDocument(): void
    {
        $user = ['timeline_list_feed_units' => ['edges' => [['cursor' => 'CURSOR+/==', 'node' => ['id' => 'STORY_ID',
            'comet_sections' => ['content' => ['story' => ['message' => ['text' => 'Publicado']]]]]]],
            'page_info' => ['end_cursor' => 'CURSOR+/==', 'has_next_page' => true]]];
        $fixture = new PageServiceFixture(['user' => $user]);
        try {
            $service = new PostsService($fixture->graph);
            $first = $service->list('20002', PageServiceFixture::providers('ProfileCometTimelineFeedQuery'), '20002');
            self::assertSame('Publicado', $first['items'][0]['text']);
            self::assertTrue($first['page_info']['has_next_page']);
            $fixture->session->data = ['node' => $user];
            $service->next('20002', $first['page_info']['end_cursor'], PageServiceFixture::providers('ProfileCometTimelineFeedRefetchQuery'), '20002');
            self::assertSame('28842066948779041', $fixture->session->requests[1]['form']['doc_id']);
            self::assertSame('CURSOR+/==', $fixture->session->variables(1)['cursor']);
            self::assertSame('20002', $fixture->session->variables(1)['id']);
        } finally { unset($service); $fixture->close(); }
    }
}
