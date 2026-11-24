<?php
declare(strict_types=1);

namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Service\InboxService;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/PageServiceFixture.php';

final class InboxServiceTest extends TestCase
{
    public function testSnapshotCannotBorrowAnotherOwnerOrRunWithoutAnActiveSession(): void
    {
        $fixture = new PageServiceFixture();
        $foreign = new class implements \MVO\Messenger\Auth\WebSession {
            public int $requests = 0;
            public function accountId(): string { return '99999'; }
            public function privateRequestFields(): array { return []; }
            public function request(string $method, string $url, array $form = []): \MVO\Messenger\Transport\HttpResponse {
                ++$this->requests;
                throw new \LogicException('Cross-account I/O must not run');
            }
        };
        try {
            foreach ([null, $foreign] as $session) {
                $service = new InboxService($fixture->graph, $session);
                try { $service->snapshot('20002', '30003'); self::fail('A current snapshot requires the same active owner'); }
                catch (FacebookException $error) {
                    self::assertSame($session === null ? 'facebook_inbox_session_required' : 'facebook_inbox_session_mismatch', $error->reason);
                }
            }
            self::assertSame(0, $foreign->requests);
            self::assertSame([], $fixture->session->requests);
        } finally { unset($service); $fixture->close(); }
    }

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

    public function testSuppliedPageIdentityMustMatchBeforeReturningThreadsOrMessages(): void
    {
        $data = ['xfb_biz_inbox_unified_thread_list' => ['selected_page' => ['id' => '30003'],
            'msgr_threads' => ['nodes' => []],
            'selected_thread' => ['selected_message_thread' => ['id' => '40004', 'messages' => ['nodes' => []]]]]];
        $fixture = new PageServiceFixture($data);
        try {
            $service = new InboxService($fixture->graph);
            self::assertSame([], $service->threads('20002', '30003')['items']);
            self::assertSame([], $service->messages('20002', '30003', '40004')['items']);
            foreach ([['id' => '99999'], null, []] as $wrongPage) {
                $fixture->session->data['xfb_biz_inbox_unified_thread_list']['selected_page'] = $wrongPage;
                foreach (['threads', 'messages'] as $method) {
                    try {
                        if ($method === 'threads') $service->threads('20002', '30003');
                        else $service->messages('20002', '30003', '40004');
                        self::fail('A missing or different supplied mailbox identity cannot be displayed.');
                    } catch (FacebookException $error) {
                        self::assertSame($wrongPage === ['id' => '99999'] ? 'facebook_inbox_page_mismatch' : 'facebook_inbox_context_unconfirmed', $error->reason);
                    }
                }
            }
        } finally { unset($service); $fixture->close(); }
    }
}
