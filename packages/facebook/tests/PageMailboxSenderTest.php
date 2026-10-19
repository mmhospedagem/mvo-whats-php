<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\Catalog\PageDocuments;
use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\WebGraphQL;
use MVO\Facebook\Service\InboxService;
use MVO\Facebook\Storage\CommandLedger;
use MVO\Messenger\Auth\PageMailboxWebSession;
use MVO\Messenger\Protocol\PageMailboxConfig;
use MVO\Messenger\Transport\{HttpResponse,PageMailboxTransport};
use PHPUnit\Framework\TestCase;

final class PageMailboxSenderTest extends TestCase
{
    private string $directory; private ?CommandLedger $ledger = null; private ?InboxService $service = null; private PageMailboxTestSession $session;
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mvo-page-send-' . bin2hex(random_bytes(8)); mkdir($this->directory, 0700);
        $this->ledger = new CommandLedger($this->directory . '/commands.sqlite'); $this->session = new PageMailboxTestSession();
        $this->service = new InboxService(new WebGraphQL($this->session, PageDocuments::registry(), $this->ledger), $this->session, $this->ledger);
    }
    protected function tearDown(): void
    {
        $this->service = null; $this->ledger?->close(); $this->ledger = null; gc_collect_cycles();
        foreach (glob($this->directory . '/commands.sqlite*') ?: [] as $file) unlink($file); rmdir($this->directory);
    }
    public function testCurrentPageSendUsesScopedTask46AndNeverMercuryOrPersonalSender(): void
    {
        $result = $this->send(); self::assertSame('lightspeed_page_dgw', $result['transport']); self::assertSame('mid.PAGE', $result['message_id']);
        self::assertSame('30003', $result['page_id']); self::assertSame('50005', $result['recipient_id']); self::assertFalse($result['delivery_confirmed']);
        self::assertCount(1, $this->session->http); self::assertStringEndsWith('/api/graphql/', $this->session->http[0]['url']);
        $gateway = $this->session->gateway; self::assertNotNull($gateway); self::assertSame('30003', $gateway->config()->pageId); self::assertTrue($gateway->closed);
        $request = $gateway->requests[0]; self::assertSame('456', $request['app_id']); self::assertSame(3, $request['type']);
        $batch = json_decode($request['payload'], true, 64, JSON_THROW_ON_ERROR); self::assertSame('28761380846847381', $batch['version_id']);
        $task = $batch['tasks'][0]; self::assertSame('46', $task['label']); $payload = json_decode($task['payload'], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(205, $payload['sync_group']); self::assertSame(4456448, $payload['source']); self::assertArrayNotHasKey('sender_fbid', $payload);
        self::assertSame('7130316800000000001', $payload['otid']); self::assertSame(40004, $payload['thread_id']);
    }
    public function testConfirmedReplayDoesNotQueryOrOpenAnotherStream(): void
    {
        $first = $this->send(); self::assertSame($first, $this->send()); self::assertCount(1, $this->session->http); self::assertSame(1, $this->session->opens);
    }
    public function testTimeoutPersistsMessageIdentityAndNeverRetries(): void
    {
        $this->session->fail = true;
        try { $this->send(); self::fail('Timeout accepted'); }
        catch (FacebookException $error) { self::assertSame('facebook_inbox_send_uncertain', $error->reason); $id = $error->commandId; }
        self::assertSame('7130316800000000001', $this->ledger->get($id)['result']['offline_threading_id']);
        try { $this->send(); self::fail('Uncertain send retried'); } catch (FacebookException $error) { self::assertSame('facebook_command_uncertain', $error->reason); }
        self::assertSame(1, $this->session->opens); self::assertTrue($this->session->gateway->closed);
    }
    public function testDifferentTransportScopeIsRejectedBeforeAnyDgwWrite(): void
    {
        $this->session->wrongScope = true;
        try { $this->send(); self::fail('Wrong asset binding accepted'); } catch (FacebookException $error) { self::assertSame('facebook_page_gateway_scope_mismatch', $error->reason); }
        self::assertSame([], $this->session->gateway->requests); self::assertTrue($this->session->gateway->closed);
    }
    public function testWrongAuthorThreadOrTextCannotConfirmAnAcknowledgment(): void
    {
        foreach (['sender' => '10001', 'thread' => '99999', 'text' => 'Other text'] as $field => $value) {
            $this->session->rowOverride = [$field => $value];
            try { $this->send('case-' . $field); self::fail('Mismatched row accepted'); } catch (FacebookException $error) { self::assertSame('facebook_inbox_ack_mismatch', $error->reason); }
        }
    }
    public function testTransportAckOrOptimisticReplacementWithoutServerRowIsNotEnough(): void
    {
        $this->session->ackOnly = true;
        try { $this->send(); self::fail('Transport-only acknowledgment accepted'); } catch (FacebookException $error) { self::assertSame('facebook_inbox_ack_unconfirmed', $error->reason); }
    }
    public function testPreflightPageMismatchDoesNotOpenGateway(): void
    {
        $this->session->page = '99999';
        try { $this->send(); self::fail('Wrong Page accepted'); } catch (FacebookException $error) { self::assertSame('facebook_inbox_context_unconfirmed', $error->reason); }
        self::assertSame(0, $this->session->opens);
    }

    public function testOversizedTextIsRejectedBeforeReservationPreflightOrGatewayCreation(): void
    {
        foreach ([str_repeat('x', 20001), str_repeat('á', 10001)] as $index => $text) {
            try { $this->send('oversized-'.$index, $text); self::fail('Oversized Page text reached preflight'); }
            catch (\InvalidArgumentException) {}
        }
        self::assertSame([], $this->session->http); self::assertSame(0, $this->session->opens);
        $database = new \PDO('sqlite:'.$this->directory.'/commands.sqlite');
        self::assertSame(0, (int)$database->query('SELECT COUNT(*) FROM facebook_commands')->fetchColumn());
        unset($database);
        $result = $this->send('boundary-20000', str_repeat('á', 10000));
        self::assertSame('server_ack', $result['status']);
    }

    public function testUnexpectedLocalFactoryFailureIsNotExecutedAndTheRecordedCommandCannotBeRetried(): void
    {
        $this->session->preflightFailure = true;
        try { $this->send(); self::fail('Local factory failure was accepted'); }
        catch (FacebookException $error) {
            self::assertSame('facebook_inbox_preflight_failed', $error->reason); self::assertSame('not_executed', $error->outcome);
            self::assertNotNull($error->commandId);
            $command = $this->ledger->get($error->commandId);
            self::assertFalse($command['result']['execution_started']);
            self::assertSame('facebook_inbox_preflight_failed', $command['result']['reason']);
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE_FACTORY_FAILURE', $error->getMessage());
        }
        try { $this->send(); self::fail('A failed preflight was repeated'); }
        catch (FacebookException $error) { self::assertSame('facebook_inbox_preflight_failed', $error->reason); self::assertSame('not_executed', $error->outcome); }
        self::assertCount(1, $this->session->http); self::assertSame(0, $this->session->opens);
    }
    /** @return array<string,mixed> */
    private function send(string $key = 'same-key', string $text = 'Teste MVO'): array
    {
        return $this->service->sendTextLightspeed('20002', '30003', '40004', $text, $key,
            ['dgw_app_id' => '123', 'dgw_app_version' => '1', 'server_app_id' => '456', 'schema_version' => '28761380846847381', 'device_id' => 'biz-device',
                'recipient_id' => '50005', 'offline_threading_id' => '7130316800000000001', 'epoch_id' => '7130316800000000002']);
    }
}

final class PageMailboxTestSession implements PageMailboxWebSession
{
    /** @var list<array{url:string,form:array<string,string>}> */ public array $http = [];
    public ?PageMailboxFixtureGateway $gateway = null; public int $opens = 0; public bool $wrongScope = false; public bool $fail = false; public bool $ackOnly = false;
    public bool $preflightFailure = false;
    /** @var array<string,string> */ public array $rowOverride = []; public string $page = '30003';
    public function accountId(): string { return '10001'; }
    public function privateRequestFields(): array { return ['__user' => '10001', 'fb_dtsg' => 'SYNTHETIC_PRIVATE']; }
    public function request(string $method, string $url, array $form = []): HttpResponse
    {
        $this->http[] = ['url' => $url, 'form' => $form];
        return new HttpResponse(200, json_encode(['data' => ['xfb_biz_inbox_unified_thread_list' => ['selected_page' => ['id' => $this->page],
            'selected_thread' => ['selected_message_thread' => ['id' => base64_encode('MessageThread:40004'), 'thread_key' => ['thread_fbid' => '40004', 'other_user_id' => '50005'],
                'composer_input_disabled' => false, 'cannot_reply_reason' => null]]]]], JSON_THROW_ON_ERROR));
    }
    public function pageMailbox(PageMailboxConfig $config): PageMailboxTransport
    {
        if ($this->preflightFailure) throw new \RuntimeException('SYNTHETIC_PRIVATE_FACTORY_FAILURE');
        ++$this->opens;
        if ($this->wrongScope) $config = new PageMailboxConfig($config->accountId, '99999', $config->dgwAppId, $config->dgwAppVersion, $config->serverAppId, $config->schemaVersion, $config->deviceId, $config->origin);
        return $this->gateway = new PageMailboxFixtureGateway($config, $this->fail, $this->ackOnly, $this->rowOverride);
    }
}

final class PageMailboxFixtureGateway implements PageMailboxTransport
{
    /** @var list<array<string,mixed>> */ public array $requests = []; public bool $closed = false;
    /** @param array<string,string> $rowOverride */
    public function __construct(private PageMailboxConfig $configuration, private bool $fail, private bool $ackOnly, private array $rowOverride) {}
    public function config(): PageMailboxConfig { return $this->configuration; }
    public function request(array $request): array
    {
        $this->requests[] = $request; if ($this->fail) throw new \RuntimeException('SYNTHETIC_PRIVATE_TRANSPORT');
        $batch = json_decode($request['payload'], true, 64, JSON_THROW_ON_ERROR); $task = json_decode($batch['tasks'][0]['payload'], true, 64, JSON_THROW_ON_ERROR);
        $calls = [[5, 'LSReplaceOptimsiticMessage', $task['otid'], 'mid.PAGE']];
        if (!$this->ackOnly) {
            $row = array_fill(0, 24, null); $row[0] = $this->rowOverride['text'] ?? $task['text']; $row[3] = $this->rowOverride['thread'] ?? (string)$task['thread_id'];
            $row[8] = 'mid.PAGE'; $row[9] = $task['otid']; $row[10] = $this->rowOverride['sender'] ?? $this->configuration->pageId; $calls[] = [5, 'LSUpsertMessage', ...$row];
        }
        return ['request_id' => 1, 'payload' => json_encode(['step' => [1, ...$calls]], JSON_THROW_ON_ERROR), 'sp' => []];
    }
    public function close(): void { $this->closed = true; }
}
