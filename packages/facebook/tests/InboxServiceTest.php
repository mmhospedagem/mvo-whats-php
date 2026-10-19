<?php
declare(strict_types=1);

namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Service\InboxService;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/PageServiceFixture.php';

final class InboxServiceTest extends TestCase
{
    public function testInboxSeparatesOwnerActorAndDelegatePageWithoutInventingExhaustion(): void
    {
        $fixture = new PageServiceFixture(['xfb_biz_inbox_unified_thread_list' => ['msgr_threads' => [
            'nodes' => [['id' => base64_encode('MessageThread:40004'), 'name' => 'Conversa de teste',
                'thread_key' => ['thread_fbid' => '40004', 'other_user_id' => '50005'],
                'updated_time_precise' => '17900000000000001', 'unread_count' => 2]], 'sync_sequence_id' => '123456789123456789']]]);
        try {
            $service = new InboxService($fixture->graph);
            $result = $service->threads('20002', '30003', 15, '17900000000000002', ['INBOX']);
            self::assertSame('40004', $result['items'][0]['selection_id']);
            self::assertSame(base64_encode('MessageThread:40004'), $result['items'][0]['id']);
            self::assertSame('50005', $result['items'][0]['thread_key']['other_user_id']);
            self::assertNull($result['has_more']);
            self::assertSame('123456789123456789', $result['sync_sequence_id']);
            $form = $fixture->session->requests[0]['form'];
            self::assertSame('10001', $form['__user']);
            self::assertSame('20002', $form['av']);
            self::assertSame('28756520980630964', $form['doc_id']);
            $variables = $fixture->session->variables();
            self::assertSame('30003', $variables['page_id']);
            self::assertSame(['last' => 15, 'before_time_precise' => '17900000000000002', 'with_tags' => ['INBOX']], $variables['msgr_params']);
        } finally { unset($service); $fixture->close(); }
    }

    public function testMessageHistoryPreservesIdentifiersAndOnlyReturnsDeliberateFields(): void
    {
        $thread = ['id' => base64_encode('MessageThread:40004'), 'thread_key' => ['thread_fbid' => '40004'],
            'messages' => ['nodes' => [['id' => 'GRAPHQL_MESSAGE_ID', 'message_id' => 'mid.OPAQUE',
                'offline_threading_id' => '179000000000000001', 'message_sender' => ['id' => '50005', 'email' => 'private@example.invalid'],
                'message' => ['text' => 'Mensagem de teste'], 'timestamp_precise' => '17900000000000002', 'unread' => false]]]];
        $fixture = new PageServiceFixture(['xfb_biz_inbox_unified_thread_list' => ['selected_thread' => ['selected_message_thread' => $thread]]]);
        try {
            $service = new InboxService($fixture->graph);
            $result = $service->messages('20002', '30003', '40004', 10);
            self::assertSame('mid.OPAQUE', $result['items'][0]['message_id']);
            self::assertSame('Mensagem de teste', $result['items'][0]['text']);
            self::assertSame('179000000000000001', $result['items'][0]['offline_threading_id']);
            self::assertArrayNotHasKey('email', $result['items'][0]);
            self::assertSame('40004', $fixture->session->variables()['selected_item_id']);
            self::assertSame(10, $fixture->session->variables()['message_limit']);
            try { $service->messages('20002', '30003', '99999'); self::fail('Expected selected thread mismatch'); }
            catch (FacebookException $error) { self::assertSame('facebook_inbox_selection_mismatch', $error->reason); }
        } finally { unset($service); $fixture->close(); }
    }

    public function testMalformedPaginationIsRejectedBeforeIO(): void
    {
        $fixture = new PageServiceFixture();
        try {
            $service = new InboxService($fixture->graph);
            foreach ([0, 101] as $limit) {
                try { $service->threads('20002', '30003', $limit); self::fail('Expected bounded request'); }
                catch (\InvalidArgumentException) {}
            }
            try { $service->threads('20002', '30003', 20, 'invented-time'); self::fail('Expected exact timestamp cursor'); }
            catch (\InvalidArgumentException) {}
            self::assertCount(0, $fixture->session->requests);
        } finally { unset($service); $fixture->close(); }
    }
}
