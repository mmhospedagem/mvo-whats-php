<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\Catalog\PageDocuments;
use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\WebGraphQL;
use MVO\Facebook\Service\InboxService;
use MVO\Facebook\Storage\CommandLedger;
use MVO\Messenger\Auth\WebSession;
use MVO\Messenger\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

final class InboxSendTest extends TestCase
{
    private string $directory;
    private ?CommandLedger $ledger = null;
    private ?InboxService $service = null;
    private InboxSendSession $session;
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mvo-inbox-send-' . bin2hex(random_bytes(8)); mkdir($this->directory, 0700);
        $this->ledger = new CommandLedger($this->directory . '/commands.sqlite'); $this->session = new InboxSendSession();
        $this->service = new InboxService(new WebGraphQL($this->session, PageDocuments::registry(), $this->ledger), $this->session, $this->ledger);
    }
    protected function tearDown(): void
    {
        $this->service = null; $this->ledger?->close(); $this->ledger = null; gc_collect_cycles();
        foreach (glob($this->directory . '/commands.sqlite*') ?: [] as $file) unlink($file); rmdir($this->directory);
    }

    public function testSendPreservesOwnerActorPageThreadRecipientAndExactMercuryIds(): void
    {
        $result = $this->send();
        self::assertSame(['page_id' => '30003', 'thread_id' => '40004', 'recipient_id' => '50005', 'message_id' => 'mid.SERVER_ID',
            'offline_threading_id' => '7130316800000000001', 'status' => 'server_ack', 'transport' => 'mercury_page_inbox_experimental',
            'delivery_confirmed' => false, 'read_confirmed' => false], $result);
        self::assertCount(2, $this->session->requests); $query = $this->session->requests[0]; $send = $this->session->requests[1];
        self::assertSame('https://www.facebook.com/api/graphql/', $query['url']);
        $variables = json_decode($query['form']['variables'], true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('30003', $variables['page_id']); self::assertSame('40004', $variables['selected_item_id']);
        self::assertSame('https://business.facebook.com/messaging/send/', $send['url']);
        self::assertSame('10001', $send['form']['__user']); self::assertSame('20002', $send['form']['av']); self::assertSame('30003', $send['form']['request_user_id']);
        self::assertSame('50005', $send['form']['other_user_fbid']); self::assertSame('40004', $send['form']['thread_fbid']);
        self::assertSame('user:50005', $send['form']['client_thread_id']); self::assertSame('ma-type:user-generated-message', $send['form']['action_type']);
        self::assertSame('7130316800000000001', $send['form']['offline_threading_id']); self::assertSame('1700000000000', $send['form']['timestamp']);
        self::assertSame(md5('7130316800000000001'), $send['form']['signature_id']); self::assertArrayNotHasKey('doc_id', $send['form']);
        self::assertArrayNotHasKey('fb_api_req_friendly_name', $send['form']); self::assertArrayNotHasKey('variables', $send['form']);
    }

    public function testSdkGeneratesMercuryOtidUsingTimestampAndLow22RandomBits(): void
    {
        $created = \MVO\Facebook\Protocol\PageInboxSender::newOfflineIdentity();
        self::assertSame((int)$created['timestamp_ms'], intdiv((int)$created['offline_threading_id'], 4194304));
        self::assertLessThanOrEqual((int)floor(microtime(true) * 1000), (int)$created['timestamp_ms']);
        self::assertGreaterThan(0, (int)$created['offline_threading_id']);
    }

    public function testConfirmedReplayHasNoNewQueryOrSend(): void
    {
        $first = $this->send(); $this->session->fail = true;
        self::assertSame($first, $this->send()); self::assertCount(2, $this->session->requests);
    }

    public function testDifferentRecipientBodyOrOtidWithSameKeyIsConflictBeforeIo(): void
    {
        $this->send();
        foreach (['recipient_id' => '60006', 'offline_threading_id' => '7130316800000000002'] as $field => $value) {
            $context = self::context(); $context[$field] = $value;
            try { $this->send($context); self::fail('Conflicting idempotency content accepted'); }
            catch (FacebookException $error) { self::assertSame('facebook_idempotency_conflict', $error->reason); }
        }
        try { $this->service->sendText('20002', '30003', '40004', 'Other body', 'same-key', self::context()); self::fail('Different text accepted'); }
        catch (FacebookException $error) { self::assertSame('facebook_idempotency_conflict', $error->reason); }
        self::assertCount(2, $this->session->requests);
    }

    public function testTimeoutSavesOriginalOtidAndBlocksRetryWithoutAnotherRequest(): void
    {
        $this->session->fail = true; $id = null;
        try { $this->send(); self::fail('Timeout accepted'); }
        catch (FacebookException $error) { self::assertSame('facebook_inbox_send_uncertain', $error->reason); $id = $error->commandId; self::assertNotNull($id); self::assertStringNotContainsString('PRIVATE_TRANSPORT', $error->getMessage()); }
        $command = $this->ledger->get($id); self::assertSame('unknown', $command['state']);
        self::assertSame('7130316800000000001', $command['result']['offline_threading_id']);
        try { $this->send(); self::fail('Uncertain send retried'); }
        catch (FacebookException $error) { self::assertSame('facebook_command_uncertain', $error->reason); self::assertSame($id, $error->commandId); }
        self::assertCount(2, $this->session->requests);
    }

    public function testHttp200WithoutCorrelatedActionNeverMeansSent(): void
    {
        $this->session->payload = ['actions' => []];
        try { $this->send(); self::fail('Empty acknowledgment accepted'); }
        catch (FacebookException $error) {
            self::assertSame('facebook_inbox_ack_unconfirmed', $error->reason);
            self::assertSame('response_received', $this->ledger->get($error->commandId)['state']);
        }
    }

    public function testWrongSenderThreadBodyRecipientAndEchoedLocalIdAreRejected(): void
    {
        foreach (['author' => 'fbid:10001', 'thread_fbid' => '99999', 'body' => 'Another message', 'other_user_fbid' => '60006', 'message_id' => 'CLIENT_MESSAGE_ID'] as $name => $value) {
            $this->session->payload = ['actions' => [[...InboxSendSession::action(), $name => $value]]];
            try { $this->service->sendText('20002', '30003', '40004', 'Teste MVO', 'mismatch-' . $name, self::context()); self::fail('Mismatched acknowledgment accepted'); }
            catch (FacebookException $error) { self::assertSame('facebook_inbox_ack_mismatch', $error->reason); }
        }
    }

    public function testDuplicateMatchingServerActionsAreNotConfirmed(): void
    {
        $this->session->payload = ['actions' => [InboxSendSession::action(), InboxSendSession::action()]];
        $this->expectException(FacebookException::class); $this->send();
    }

    public function testPageScopeMismatchStopsBeforeSendAndRecordsNotExecuted(): void
    {
        $this->session->pageId = '99999';
        try { $this->send(); self::fail('Wrong Page mailbox accepted'); }
        catch (FacebookException $error) {
            self::assertSame('facebook_inbox_context_unconfirmed', $error->reason); self::assertSame('not_executed', $error->outcome);
            $command = $this->ledger->get($error->commandId); self::assertFalse($command['result']['execution_started']);
        }
        self::assertCount(1, $this->session->requests);
    }

    public function testDisabledOrUnknownReplyStateDoesNotUseCallerPermissionFlags(): void
    {
        foreach ([true, null] as $disabled) {
            $this->session->disabled = $disabled;
            try { $this->service->sendText('20002', '30003', '40004', 'Teste MVO', 'disabled-' . var_export($disabled, true), self::context()); self::fail('Unconfirmed composer accepted'); }
            catch (FacebookException $error) { self::assertSame('facebook_inbox_reply_unavailable', $error->reason); }
        }
        $context = self::context(); $context['can_send'] = true;
        try { $this->send($context); self::fail('Caller permission flag accepted'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        self::assertCount(2, $this->session->requests);
    }

    public function testRecipientAndClientThreadMustMatchFreshThreadBeforeSend(): void
    {
        foreach (['recipient_id' => '60006', 'client_thread_id' => 'thread:99999'] as $name => $value) {
            $context = self::context(); $context[$name] = $value;
            try { $this->service->sendText('20002', '30003', '40004', 'Teste MVO', 'bad-' . $name, $context); self::fail('Wrong recipient/thread accepted'); }
            catch (FacebookException $error) { self::assertSame('facebook_inbox_selection_mismatch', $error->reason); }
        }
        self::assertCount(2, $this->session->requests);
    }

    public function testInvalidTimestampOtidOriginAndContextFailBeforeIo(): void
    {
        foreach (['timestamp_ms' => '1700000000001', 'offline_threading_id' => '9223372036854775808', 'origin' => 'https://example.invalid', 'client_thread_id' => '5511999999999@s.whatsapp.net'] as $name => $value) {
            $context = self::context(); $context[$name] = $value;
            try { $this->send($context); self::fail('Malformed context accepted'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        self::assertCount(0, $this->session->requests);
    }

    public function testPrivateServerErrorAndTransportDetailsAreNotProjected(): void
    {
        $this->session->wire = 'for (;;);' . json_encode(['error' => 123, 'errorDescription' => 'PRIVATE_SERVER_DETAILS', 'payload' => []], JSON_THROW_ON_ERROR);
        try { $this->send(); self::fail('Server error accepted'); }
        catch (FacebookException $error) {
            self::assertStringNotContainsString('PRIVATE_SERVER_DETAILS', $error->getMessage());
            self::assertStringNotContainsString('PRIVATE_SERVER_DETAILS', json_encode($this->ledger->get($error->commandId), JSON_THROW_ON_ERROR));
        }
    }

    public function testGraphOnlyServiceCannotAttemptSend(): void
    {
        $service = new InboxService(new WebGraphQL($this->session, PageDocuments::registry()));
        try { $service->sendText('20002', '30003', '40004', 'Teste MVO', 'key', self::context()); self::fail('Unconfigured sender accepted'); }
        catch (FacebookException $error) { self::assertSame('facebook_inbox_sender_unavailable', $error->reason); }
        self::assertCount(0, $this->session->requests);
    }

    /** @return array<string,string> */
    private static function context(): array
    {
        return ['client_thread_id' => 'user:50005', 'recipient_id' => '50005', 'client' => 'PagesManagerMessagesInterface',
            'source' => 'source:page_unified_inbox', 'message_id' => 'CLIENT_MESSAGE_ID', 'offline_threading_id' => '7130316800000000001', 'timestamp_ms' => '1700000000000'];
    }
    /** @param array<string,mixed>|null $context @return array<string,mixed> */
    private function send(?array $context = null): array { return $this->service->sendText('20002', '30003', '40004', 'Teste MVO', 'same-key', $context ?? self::context()); }
}

final class InboxSendSession implements WebSession
{
    /** @var list<array{method:string,url:string,form:array<string,string>}> */ public array $requests = [];
    public bool $fail = false;
    public ?string $wire = null;
    /** @var array<string,mixed>|null */ public ?array $payload = null;
    public string $pageId = '30003';
    public ?bool $disabled = false;
    public function accountId(): string { return '10001'; }
    public function privateRequestFields(): array { return ['__user' => '10001', 'fb_dtsg' => 'SYNTHETIC_PRIVATE_CSRF']; }
    public function request(string $method, string $url, array $form = []): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'form' => $form];
        if (str_ends_with($url, '/api/graphql/')) return new HttpResponse(200, json_encode(['data' => ['xfb_biz_inbox_unified_thread_list' => [
            'selected_page' => ['id' => $this->pageId], 'selected_thread' => ['selected_message_thread' => [
                'id' => base64_encode('MessageThread:40004'), 'thread_key' => ['thread_fbid' => '40004', 'other_user_id' => '50005'],
                'composer_input_disabled' => $this->disabled, 'cannot_reply_reason' => null]]]]], JSON_THROW_ON_ERROR));
        if (!str_ends_with($url, '/messaging/send/')) throw new \LogicException('Unexpected test route');
        if ($this->fail) throw new \RuntimeException('PRIVATE_TRANSPORT_DETAILS');
        return new HttpResponse(200, $this->wire ?? 'for (;;);' . json_encode(['__ar' => 1, 'payload' => $this->payload ?? ['actions' => [self::action()]]], JSON_THROW_ON_ERROR));
    }
    /** @return array<string,mixed> */
    public static function action(): array
    {
        return ['message_id' => 'mid.SERVER_ID', 'offline_threading_id' => '7130316800000000001', 'thread_fbid' => '40004', 'other_user_fbid' => '50005',
            'author' => 'fbid:30003', 'body' => 'Teste MVO', 'graphql_payload' => ['PRIVATE_UNPROJECTED']];
    }
}
