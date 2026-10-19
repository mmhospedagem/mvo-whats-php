<?php
declare(strict_types=1);

namespace MVO\Facebook\Tests;

use MVO\Facebook\Catalog\ContentManagementDocuments;
use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\WebGraphQL;
use MVO\Facebook\Service\CommentsService;
use MVO\Facebook\Service\PostsService;
use MVO\Facebook\Storage\CommandLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/PageServiceFixture.php';

/** Synthetic transport assertions, not evidence of a mutation on a real Facebook account. */
final class ContentManagementServiceTest extends TestCase
{
    private string $directory;
    private ?CommandLedger $ledger = null;
    private ?WebGraphQL $graph = null;
    private PageFakeSession $session;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/mvo-content-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->session = new PageFakeSession();
        $this->ledger = new CommandLedger($this->directory.'/commands.sqlite');
        $this->graph = new WebGraphQL($this->session, ContentManagementDocuments::registry(), $this->ledger);
    }

    protected function tearDown(): void
    {
        $this->ledger?->close();
        $this->graph = null;
        $this->ledger = null;
        gc_collect_cycles();
        foreach (glob($this->directory.'/commands.sqlite*') ?: [] as $file) unlink($file);
        rmdir($this->directory);
    }

    /** @return iterable<string,array{string,string}> */
    public static function mutations(): iterable
    {
        yield 'post edit' => ['post_edit', 'ComposerStoryEditMutation'];
        yield 'post delete' => ['post_delete', 'useCometFeedStoryDeleteMutation'];
        yield 'comment edit' => ['comment_edit', 'useCometUFIEditCommentMutation'];
        yield 'comment delete' => ['comment_delete', 'useCometUFIDeleteCommentMutation'];
        yield 'hide' => ['hide', 'CometUFIHideCommentMutation'];
        yield 'unhide' => ['unhide', 'CometUFIUnhideCommentMutation'];
    }

    #[DataProvider('mutations')]
    public function testExactOperationActorAndProjectionAreDurableAndNotReissued(string $kind, string $document): void
    {
        $this->session->data = self::response($kind);
        $result = $this->invoke($kind);
        self::assertSame($result, $this->invoke($kind));
        self::assertCount(1, $this->session->requests);
        $request = $this->session->requests[0]['form'];
        self::assertSame(ContentManagementDocuments::registry()->get($document)->id, $request['doc_id']);
        self::assertSame('10001', $request['__user']);
        self::assertSame('20002', $request['av']);
        $input = $this->session->variables()['input'];
        self::assertSame(str_starts_with($kind, 'post') ? 'STORY_OPAQUE+/==' : 'COMMENT_OPAQUE+/==', $input[str_starts_with($kind, 'post') ? 'story_id' : 'comment_id']);
        self::assertArrayNotHasKey('actor_id', $input);
        self::assertStringNotContainsString('SYNTHETIC_UNPROJECTED_PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
        $db = new \PDO('sqlite:'.$this->directory.'/commands.sqlite');
        $row = $db->query('SELECT state,result FROM facebook_commands')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('confirmed', $row['state']);
        self::assertSame(['projection' => $result], json_decode($row['result'], true, 64, JSON_THROW_ON_ERROR));
        unset($db);
        if ($kind === 'post_delete') self::assertSame('FEED', $input['story_location']);
        if ($kind === 'comment_delete') self::assertSame('MENU', $input['remove_location']);
        if ($kind === 'hide' || $kind === 'unhide') {
            self::assertSame('comet', $input['site']);
            self::assertSame(0, $input['feedback_source']);
            self::assertSame($kind === 'hide', $result['is_hidden_by_viewer']);
            self::assertFalse($result['is_hidden_by_content_owner']);
        }
    }

    #[DataProvider('mutations')]
    public function testAnotherObjectIdDoesNotConfirmOrRepeatTheMutation(string $kind, string $document): void
    {
        $this->session->data = self::response($kind, false);
        try { $this->invoke($kind); self::fail('A mismatched result was accepted'); }
        catch (FacebookException $error) {
            self::assertSame('facebook_response_shape_unknown', $error->reason);
            self::assertNotNull($error->commandId);
            self::assertSame('response_received', $this->ledger?->get($error->commandId)['state']);
        }
        try { $this->invoke($kind); self::fail('An uncertain command was repeated'); }
        catch (FacebookException $error) { self::assertSame('facebook_command_uncertain', $error->reason); }
        self::assertCount(1, $this->session->requests);
        self::assertSame($document, $this->session->requests[0]['form']['fb_api_req_friendly_name']);
    }

    #[DataProvider('mutations')]
    public function testTransportFailureIsUncertainAndCannotAutomaticallyRepeat(string $kind, string $document): void
    {
        $this->session->fail = true;
        foreach (['facebook_transport_failed', 'facebook_command_uncertain'] as $reason) {
            try { $this->invoke($kind); self::fail('Expected an uncertain command'); }
            catch (FacebookException $error) {
                self::assertSame($reason, $error->reason);
                self::assertSame('unknown', $error->outcome);
                self::assertNotNull($error->commandId);
            }
        }
        self::assertCount(1, $this->session->requests);
        self::assertSame($document, $this->session->requests[0]['form']['fb_api_req_friendly_name']);
    }

    public function testMissingProviderAndSyntheticActorOverrideAreRejectedBeforeIO(): void
    {
        try { (new PostsService($this->graph))->delete('20002', 'STORY', 'key', ['scale' => 1]); self::fail('Expected provider context'); }
        catch (FacebookException $error) { self::assertSame('facebook_provider_context_required', $error->reason); }
        try { (new CommentsService($this->graph))->editText('20002', 'COMMENT', 'Text', 'key', [...self::commentContext(), 'actor_id' => '30003'], self::variables('useCometUFIEditCommentMutation')); self::fail('Expected actor guard'); }
        catch (\InvalidArgumentException) {}
        self::assertCount(0, $this->session->requests);
    }

    public function testContradictoryVisibilityAndTextAreNotConfirmed(): void
    {
        $this->session->data = ['comment_hide' => ['comment' => ['id' => 'COMMENT_OPAQUE+/==', 'is_hidden_by_viewer' => false, 'is_hidden_by_content_owner' => true]]];
        try { $this->invoke('hide'); self::fail('A hide result with the opposite state was accepted'); }
        catch (FacebookException $error) { self::assertSame('facebook_response_shape_unknown', $error->reason); }
        $this->session->data = ['comment_edit' => ['comment' => ['id' => 'COMMENT_OPAQUE+/==', 'body' => ['text' => 'Different text']]]];
        try { $this->invoke('comment_edit'); self::fail('A different body was accepted'); }
        catch (FacebookException $error) { self::assertSame('facebook_response_shape_unknown', $error->reason); }
    }

    /** @return array<string,mixed> */
    private function invoke(string $kind): array
    {
        $posts = new PostsService($this->graph);
        $comments = new CommentsService($this->graph);
        $story = 'STORY_OPAQUE+/=='; $comment = 'COMMENT_OPAQUE+/==';
        $context = ['composer_entry_point' => 'SYNTHETIC_EDIT', 'composer_source_surface' => 'SYNTHETIC_SURFACE', 'composer_type' => 'edit',
            'logging' => ['composer_session_id' => 'SYNTHETIC_SESSION'], 'tracking' => []];
        return match ($kind) {
            'post_edit' => $posts->editText('20002', $story, 'New text', $kind, $context, self::variables('ComposerStoryEditMutation')),
            'post_delete' => $posts->delete('20002', $story, $kind, self::variables('useCometFeedStoryDeleteMutation')),
            'comment_edit' => $comments->editText('20002', $comment, 'New text', $kind, self::commentContext(), self::variables('useCometUFIEditCommentMutation')),
            'comment_delete' => $comments->delete('20002', $comment, $kind, ['attribution_id_v2' => 'SYNTHETIC_CHAIN', 'tracking' => []], self::variables('useCometUFIDeleteCommentMutation')),
            'hide' => $comments->setHidden('20002', $comment, true, $kind, ['feedback_source' => 0, 'attribution_id_v2' => 'SYNTHETIC_CHAIN'], self::variables('CometUFIHideCommentMutation')),
            'unhide' => $comments->setHidden('20002', $comment, false, $kind, ['feedback_source' => 0], self::variables('CometUFIUnhideCommentMutation')),
            default => throw new \LogicException('Unknown fixture'),
        };
    }

    /** @return array<string,mixed> */
    private static function variables(string $name): array
    {
        $values = [];
        foreach (ContentManagementDocuments::providers($name) as $provider) $values[$provider] = str_contains($provider, 'AutoTranslationType') ? 'ORIGINAL' : false;
        $values['scale'] = 1;
        if ($name === 'ComposerStoryEditMutation') $values += ['feedLocation' => 'TIMELINE', 'renderLocation' => 'timeline', 'ppeEnabled' => false];
        if ($name === 'useCometUFIEditCommentMutation') $values += ['feedLocation' => 'TIMELINE', 'translationType' => 'ORIGINAL', 'useDefaultActor' => false];
        if (in_array($name, ['CometUFIHideCommentMutation', 'CometUFIUnhideCommentMutation'], true)) $values += ['feedLocation' => 'TIMELINE', 'commentActionLinksRewriteEnabled' => false];
        return $values;
    }

    /** @return array<string,mixed> */
    private static function commentContext(): array { return ['formatting_style' => 'PLAIN_TEXT', 'attribution_id_v2' => 'SYNTHETIC_CHAIN', 'tracking' => []]; }

    /** @return array<string,mixed> */
    private static function response(string $kind, bool $matching = true): array
    {
        $story = $matching ? 'STORY_OPAQUE+/==' : 'OTHER'; $comment = $matching ? 'COMMENT_OPAQUE+/==' : 'OTHER';
        $private = 'SYNTHETIC_UNPROJECTED_PRIVATE';
        return match ($kind) {
            'post_edit' => ['story_edit' => ['story' => ['id' => $story, 'message' => ['text' => 'New text'], 'private' => $private]]],
            'post_delete' => ['story_delete' => ['deleted_story_id' => $story, 'private' => $private]],
            'comment_edit' => ['comment_edit' => ['comment' => ['id' => $comment, 'body' => ['text' => 'New text'], 'private' => $private]]],
            'comment_delete' => ['comment_delete' => ['deleted_comment_id' => $comment, 'private' => $private]],
            'hide', 'unhide' => [$kind === 'hide' ? 'comment_hide' : 'comment_unhide' => ['comment' => ['id' => $comment, 'is_hidden_by_viewer' => $kind === 'hide', 'is_hidden_by_content_owner' => false, 'private' => $private]]],
            default => throw new \LogicException('Unknown fixture'),
        };
    }
}
