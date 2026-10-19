<?php
declare(strict_types=1);

namespace MVO\Facebook\Tests;

use MVO\Facebook\Catalog\PageDocuments;
use MVO\Facebook\FacebookClient;
use MVO\Facebook\FacebookException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PageServiceFixture.php';

/** Real service/ledger integration using an in-memory HTTP fixture, never Facebook. */
final class MutationProjectionTest extends TestCase
{
    private string $directory;
    private ?FacebookClient $client = null;
    private ?PageFakeSession $session = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mvo-mutation-projection-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        $this->client?->close();
        $this->client = null; $this->session = null; gc_collect_cycles();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->directory);
    }

    /** @return iterable<string,array{string}> */
    public static function mutations(): iterable
    {
        foreach (['post', 'comment', 'reaction', 'reaction_remove'] as $kind) yield $kind => [$kind];
    }

    #[DataProvider('mutations')]
    public function testOnlyTheExplicitProjectionIsPersistedAndReturnedByCommandAfterRestart(string $kind): void
    {
        $this->session = new PageFakeSession(self::data($kind, true));
        $this->client = new FacebookClient($this->session, $this->directory, PageDocuments::registry());
        $result = $this->invoke($kind);
        $id = $this->persistedId();
        $command = $this->client->command($id);
        self::assertNotNull($command);
        self::assertSame('confirmed', $command['state']);
        self::assertSame(['projection' => $result], $command['result']);
        self::assertStringNotContainsString('UNPROJECTED_SYNTHETIC_PRIVATE', json_encode([$result, $command], JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('UNPROJECTED_SYNTHETIC_PRIVATE', $this->persistedResult());
        self::assertCount(1, $this->session->requests);

        // Changed upstream data must not cause another request or corrupt durable replay.
        $this->session->data = self::data($kind, false);
        $this->client->close();
        $this->client = null;
        $this->client = new FacebookClient($this->session, $this->directory, PageDocuments::registry());
        self::assertSame($result, $this->invoke($kind));
        self::assertSame($command, $this->client->command($id));
        self::assertCount(1, $this->session->requests);
    }

    #[DataProvider('mutations')]
    public function testUnknownApplicationIdsKeepTheCommandIdAndCannotConfirmOrReplayTheMutation(string $kind): void
    {
        $this->session = new PageFakeSession(self::data($kind, false));
        $this->client = new FacebookClient($this->session, $this->directory, PageDocuments::registry());
        $commandId = null;
        try { $this->invoke($kind); self::fail('A response without the expected native ID was accepted'); }
        catch (FacebookException $error) {
            self::assertSame('facebook_response_shape_unknown', $error->reason);
            self::assertSame('not_confirmed', $error->outcome);
            self::assertNotNull($error->commandId);
            $commandId = $error->commandId;
        }
        self::assertSame($this->persistedId(), $commandId);
        $command = $this->client->command($commandId);
        self::assertNotNull($command);
        self::assertSame('response_received', $command['state']);
        self::assertNull($command['result']);
        self::assertStringNotContainsString('UNPROJECTED_SYNTHETIC_PRIVATE', json_encode($command, JSON_THROW_ON_ERROR));
        self::assertCount(1, $this->session->requests);

        try { $this->invoke($kind); self::fail('An unconfirmed mutation was repeated'); }
        catch (FacebookException $error) {
            self::assertSame('facebook_command_uncertain', $error->reason);
            self::assertSame('unknown', $error->outcome);
            self::assertSame($commandId, $error->commandId);
        }
        self::assertCount(1, $this->session->requests);
    }

    /** @return array<string,mixed> */
    private function invoke(string $kind): array
    {
        $client = $this->client ?? throw new \LogicException('The synthetic client was not initialized');
        $context = ['session_id' => 'SYNTHETIC_SESSION', 'feedback_source' => 'PROFILE'];
        return match ($kind) {
            'post' => $client->posts()->createText('20002', 'Synthetic post', 'projection-post', PageServiceFixture::composer(), PageServiceFixture::providers('ComposerStoryCreateMutation')),
            'comment' => $client->comments()->create('20002', 'FEEDBACK_OPAQUE', 'Synthetic comment', 'projection-comment', $context, PageServiceFixture::providers('useCometUFICreateCommentMutation')),
            'reaction' => $client->reactions()->set('20002', 'FEEDBACK_OPAQUE', '1635855486666999', 'projection-reaction', $context, PageServiceFixture::providers('CometUFIFeedbackReactMutation')),
            'reaction_remove' => $client->reactions()->remove('20002', 'FEEDBACK_OPAQUE', 'projection-remove', $context, PageServiceFixture::providers('CometUFIFeedbackReactMutation')),
            default => throw new \LogicException('Unknown synthetic mutation'),
        };
    }

    /** @return array<string,mixed> */
    private static function data(string $kind, bool $valid): array
    {
        $private = 'UNPROJECTED_SYNTHETIC_PRIVATE';
        return match ($kind) {
            'post' => ['private_fixture' => $private, 'story_create' => ['private_fixture' => $private, 'post_id' => $valid ? '30003' : null,
                'publishing_flow' => ['private_fixture' => $private], 'story' => ['private_fixture' => $private]]],
            'comment' => ['private_fixture' => $private, 'comment_create' => ['feedback_comment_edge' => ['node' => ['id' => $valid ? 'COMMENT_OPAQUE' : null,
                'private_fixture' => $private, 'body' => ['text' => 'Synthetic comment', 'private_fixture' => $private],
                'author' => ['id' => '20002', 'name' => 'Synthetic page', 'private_fixture' => $private]]]]],
            'reaction', 'reaction_remove' => ['private_fixture' => $private, 'feedback_react' => ['feedback' => ['id' => $valid ? 'FEEDBACK_OPAQUE' : 'OTHER_FEEDBACK',
                'private_fixture' => $private, 'viewer_feedback_reaction_info' => $kind === 'reaction_remove' ? null : ['id' => '1635855486666999', 'private_fixture' => $private],
                'supported_reaction_infos' => [['id' => '1635855486666999', 'private_fixture' => $private]]]]],
            default => throw new \LogicException('Unknown synthetic response'),
        };
    }

    private function persistedId(): string
    {
        $database = new \PDO('sqlite:' . $this->directory . '/10001/commands.sqlite');
        $id = $database->query('SELECT id FROM facebook_commands')->fetchColumn();
        if (!is_string($id)) throw new \LogicException('The synthetic command did not reach its durable reservation');
        return $id;
    }

    private function persistedResult(): string
    {
        $database = new \PDO('sqlite:' . $this->directory . '/10001/commands.sqlite');
        $result = $database->query('SELECT result FROM facebook_commands')->fetchColumn();
        if (!is_string($result)) throw new \LogicException('The synthetic command did not persist its normalized result');
        return $result;
    }
}
