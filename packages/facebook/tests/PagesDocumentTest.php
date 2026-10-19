<?php
declare(strict_types=1);

namespace MVO\Facebook\Tests;

use MVO\Facebook\Catalog\PageDocuments;
use MVO\Facebook\FacebookException;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/PageServiceFixture.php';

final class PagesDocumentTest extends TestCase
{
    public function testCatalogPinsDistinctCurrentOperationsAndCanonicalProvenance(): void
    {
        $registry = PageDocuments::registry();
        self::assertCount(13, $registry->inventory());
        self::assertSame('28881790801455989', $registry->get('PageCometLaunchpointLeftNavMenuRootQuery')->id);
        self::assertSame('28970191379242230', $registry->get('PagesCometLaunchPointUnifiedQueryPagesListRedesignedUpdatedPagesSectionQuery')->id);
        self::assertSame('40023273857271114', $registry->get('useCometUFICreateCommentMutation')->id);
        self::assertSame('28756520980630964', $registry->get('BizInboxUnifiedThreadsQuery')->id);
        self::assertSame('29409681848615704', $registry->get('CommentListComponentsRootQuery')->id);
        self::assertSame('29265821953021786', $registry->get('CommentsListComponentsPaginationQuery')->id);
        self::assertSame('28862578480043890', $registry->get('CometUFIReactionsDialogQuery')->id);
        self::assertSame('29689789980621692', $registry->get('CometUFIReactionsDialogTabContentRefetchQuery')->id);
        foreach (PageDocuments::documents() as $document) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $document->sourceSha256);
            self::assertStringStartsWith('https://static.xx.fbcdn.net/rsrc.php/', $document->sourceUrl);
            self::assertStringNotContainsString('?', $document->sourceUrl);
            self::assertStringNotContainsString('.cache', $document->sourceUrl);
        }
    }

    public function testDynamicProvidersHaveNoInventedNullOrFalseFallback(): void
    {
        $name = 'CometUFIFeedbackReactMutation';
        $document = PageDocuments::registry()->get($name);
        foreach (PageDocuments::providers($name) as $provider) self::assertArrayNotHasKey($provider, $document->defaults);
        try { PageDocuments::arguments($name, ['input' => []]); self::fail('Expected missing runtime context'); }
        catch (FacebookException $error) {
            self::assertSame('facebook_provider_context_required', $error->reason);
            self::assertSame('not_executed', $error->outcome);
        }
        $explicit = PageServiceFixture::providers($name);
        $arguments = PageDocuments::arguments($name, $explicit);
        foreach ($explicit as $provider => $value) self::assertSame($value, $arguments[$provider]);
    }

    public function testCompletePagesBindingNeedsNoAccountSpecificProviderValues(): void
    {
        self::assertSame([], PageDocuments::providers('PageCometLaunchpointLeftNavMenuRootQuery'));
        $arguments = PageDocuments::arguments('PageCometLaunchpointLeftNavMenuRootQuery', [
            'useNewPagesYouManage' => true, 'useAdminedPagesForActingAccount' => false]);
        self::assertTrue($arguments['useNewPagesYouManage']);
        self::assertFalse($arguments['useAdminedPagesForActingAccount']);
        $this->expectException(\InvalidArgumentException::class);
        PageDocuments::arguments('PageCometLaunchpointLeftNavMenuRootQuery', ['invented' => true]);
    }
}
