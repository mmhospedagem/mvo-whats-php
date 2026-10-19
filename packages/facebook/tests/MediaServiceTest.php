<?php
declare(strict_types=1);

namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Media\PhotoUploadContext;
use MVO\Facebook\Service\MediaService;
use MVO\Facebook\Storage\CommandLedger;
use MVO\Messenger\Auth\MultipartWebSession;
use MVO\Messenger\Auth\BootstrapWebSession;
use MVO\Messenger\Transport\HttpResponse;
use MVO\Messenger\Transport\UploadFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhotoFakeSession implements MultipartWebSession
{
    /** @var list<array{url:string,form:array<string,string>,files:array<string,UploadFile>}> */
    public array $uploads = [];
    public string $body = 'for (;;);{"__ar":1,"payload":{"photoID":"30003","width":1,"height":1,"imageSrc":"https://example.test/image.png","unexpected":"SYNTHETIC_PRIVATE"}}';
    public int $status = 200;
    public bool $fail = false;
    public function accountId(): string { return '10001'; }
    public function privateRequestFields(): array { return ['__user' => '10001', 'fb_dtsg' => 'SYNTHETIC_PRIVATE']; }
    public function request(string $method, string $url, array $form = []): HttpResponse { throw new \LogicException('Uploads must not use the URL-encoded transport'); }
    public function upload(string $url, array $form, array $files): HttpResponse
    {
        $this->uploads[] = ['url' => $url, 'form' => $form, 'files' => $files];
        if ($this->fail) throw new \RuntimeException('SYNTHETIC_PRIVATE_FAILURE');
        return new HttpResponse($this->status, $this->body);
    }
}

final class PhotoBootstrapFakeSession extends PhotoFakeSession implements BootstrapWebSession
{
    /** @var list<string> */
    public array $moduleReads = [];
    public mixed $module = ['photo' => ['uploadEndpoint' => PhotoUploadContext::ENDPOINT,
        'uploadData' => ['source' => 8, 'profile_id' => '10001', 'waterfallxapp' => 'comet']]];
    public function privateModule(string $name): mixed { $this->moduleReads[] = $name; return $this->module; }
}

/** No Facebook I/O: the synthetic owner records the exact multipart boundary and ledger state. */
final class MediaServiceTest extends TestCase
{
    private string $directory;
    private PhotoFakeSession $session;
    private ?CommandLedger $ledger = null;
    private ?MediaService $service = null;
    private UploadFile $file;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/mvo-photo-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory.'/photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2U3sAAAAASUVORK5CYII=', true));
        $this->file = new UploadFile($this->directory.'/photo.png', 'photo.png', 'image/png');
        $this->session = new PhotoFakeSession();
        $this->ledger = new CommandLedger($this->directory.'/commands.sqlite');
        $this->service = new MediaService($this->session, $this->ledger);
    }

    protected function tearDown(): void
    {
        $this->ledger?->close();
        $this->service = null;
        $this->ledger = null;
        gc_collect_cycles();
        foreach (glob($this->directory.'/*') ?: [] as $file) unlink($file);
        rmdir($this->directory);
    }

    public function testThePhotoContractUsesTheExistingOwnerAndOnlyProjectsTheUploadedAsset(): void
    {
        $result = $this->service->uploadPhoto('20002', $this->file, 'photo-key', self::context());
        self::assertSame($result, $this->service->uploadPhoto('20002', $this->file, 'photo-key', self::context()));
        self::assertCount(1, $this->session->uploads);
        $request = $this->session->uploads[0];
        self::assertSame(PhotoUploadContext::ENDPOINT.'?av=20002', $request['url']);
        self::assertSame('10001', $request['form']['__user']);
        self::assertSame('10001', $request['form']['profile_id']);
        self::assertSame('8', $request['form']['source']);
        self::assertSame('comet', $request['form']['waterfallxapp']);
        self::assertSame('1', $request['form']['__a']);
        self::assertMatchesRegularExpression('/^[0-9]+$/D', $request['form']['upload_id']);
        self::assertSame(['farr' => $this->file], $request['files']);
        self::assertSame('30003', $result['photo_id']);
        self::assertTrue($result['uploaded']);
        self::assertFalse($result['published']);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
        $db = new \PDO('sqlite:'.$this->directory.'/commands.sqlite');
        $stored = $db->query('SELECT state,result FROM facebook_commands')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('confirmed', $stored['state']);
        self::assertSame(['projection' => $result], json_decode($stored['result'], true, 64, JSON_THROW_ON_ERROR));
        unset($db);
    }

    /** @return iterable<string,array{int,string,string,string}> */
    public static function badResponses(): iterable
    {
        yield 'HTTP' => [503, '{}', 'facebook_upload_http_failed', 'unknown'];
        yield 'non-JSON' => [200, '<html>SYNTHETIC_PRIVATE</html>', 'facebook_upload_response_invalid', 'unknown'];
        yield 'another field is not photoID' => [200, '{"payload":{"id":"30003","unexpected":"SYNTHETIC_PRIVATE"}}', 'facebook_response_shape_unknown', 'response_received'];
        yield 'explicit refusal' => [200, '{"error":1357004,"errorDescription":"SYNTHETIC_PRIVATE","payload":{"photoID":"30003"}}', 'facebook_upload_rejected', 'response_received'];
        yield 'non-numeric asset' => [200, '{"payload":{"photoID":"INVALID"}}', 'facebook_response_shape_unknown', 'response_received'];
    }

    #[DataProvider('badResponses')]
    public function testUnconfirmedResponsesAreNotStoredAsSuccessOrRepeated(int $status, string $body, string $reason, string $state): void
    {
        $this->session->status = $status; $this->session->body = $body;
        try { $this->service->uploadPhoto('20002', $this->file, 'bad-photo', self::context()); self::fail('Expected an unconfirmed upload'); }
        catch (FacebookException $error) {
            self::assertSame($reason, $error->reason);
            self::assertNotNull($error->commandId);
            $command = $this->ledger?->get($error->commandId);
            self::assertSame($state, $command['state']);
            self::assertNull($command['result']);
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE', $error->getMessage());
        }
        try { $this->service->uploadPhoto('20002', $this->file, 'bad-photo', self::context()); self::fail('An unconfirmed upload was repeated'); }
        catch (FacebookException $error) { self::assertSame('facebook_command_uncertain', $error->reason); }
        self::assertCount(1, $this->session->uploads);
    }

    public function testTimeoutCannotAutomaticallyRepeatTheUpload(): void
    {
        $this->session->fail = true;
        foreach (['facebook_upload_transport_failed', 'facebook_command_uncertain'] as $reason) {
            try { $this->service->uploadPhoto('20002', $this->file, 'timeout', self::context()); self::fail('Expected uncertainty'); }
            catch (FacebookException $error) { self::assertSame($reason, $error->reason); self::assertSame('unknown', $error->outcome); }
        }
        self::assertCount(1, $this->session->uploads);
    }

    public function testContentDigestParticipatesInIdempotencyAndNoLocalPathIsStored(): void
    {
        $this->service->uploadPhoto('20002', $this->file, 'bound-file', self::context());
        $content = file_get_contents($this->directory.'/photo.png');
        $content[20] = chr(ord($content[20]) ^ 1);
        file_put_contents($this->directory.'/photo.png', $content);
        $changedFile = new UploadFile($this->directory.'/photo.png', 'photo.png', 'image/png');
        try { $this->service->uploadPhoto('20002', $changedFile, 'bound-file', self::context()); self::fail('A changed asset reused the same command'); }
        catch (FacebookException $error) { self::assertSame('facebook_idempotency_conflict', $error->reason); }
        self::assertCount(1, $this->session->uploads);
        $db = new \PDO('sqlite:'.$this->directory.'/commands.sqlite');
        $row = $db->query('SELECT * FROM facebook_commands')->fetch(\PDO::FETCH_ASSOC);
        self::assertStringNotContainsString($this->directory, json_encode($row, JSON_THROW_ON_ERROR));
        unset($db);
    }

    public function testUploadContextMustRemainWithinTheObservedEndpointAccountAndActor(): void
    {
        foreach ([self::context('30003', '20002'), self::context('10001', '30003')] as $context) {
            try { $this->service->uploadPhoto('20002', $this->file, 'scope', $context); self::fail('A foreign context was accepted'); }
            catch (FacebookException $error) { self::assertSame('facebook_upload_context_mismatch', $error->reason); }
        }
        foreach (['https://example.test/upload', PhotoUploadContext::ENDPOINT.'?av=999', 'https://upload.facebook.com/ajax/other'] as $endpoint) {
            try { new PhotoUploadContext('10001', '20002', ['uploadEndpoint' => $endpoint, 'uploadData' => ['source' => 8, 'profile_id' => '10001', 'waterfallxapp' => 'comet']]); self::fail('An unobserved endpoint was accepted'); }
            catch (\InvalidArgumentException) {}
        }
        self::assertCount(0, $this->session->uploads);
    }

    public function testCurrentPhotoContextComesOnlyFromTheAuthenticatedOwnerModuleWithoutHTTP(): void
    {
        $owner = new PhotoBootstrapFakeSession();
        $service = new MediaService($owner, $this->ledger);
        $context = $service->photoContext('20002');
        self::assertSame('10001', $context->accountId);
        self::assertSame('20002', $context->actorId);
        self::assertSame(['source' => '8', 'profile_id' => '10001', 'waterfallxapp' => 'comet'], $context->formData());
        self::assertSame(['ReactComposerMediaConfig'], $owner->moduleReads);
        self::assertCount(0, $owner->uploads);
    }

    public function testMissingOrUnknownOwnerBootstrapDoesNotProduceAnAnonymousUploadContext(): void
    {
        try { $this->service->photoContext('20002'); self::fail('An owner without bootstrap capability was accepted'); }
        catch (FacebookException $error) { self::assertSame('facebook_upload_context_unavailable', $error->reason); self::assertSame('not_executed', $error->outcome); }
        $owner = new PhotoBootstrapFakeSession();
        $service = new MediaService($owner, $this->ledger);
        foreach ([null, [], ['photo' => null], ['photo' => ['uploadEndpoint' => 'https://example.test/upload', 'uploadData' => ['source' => 8, 'profile_id' => '10001', 'waterfallxapp' => 'comet']]]] as $module) {
            $owner->module = $module;
            try { $service->photoContext('20002'); self::fail('An absent or unknown module was replaced by a fabricated context'); }
            catch (FacebookException $error) { self::assertSame('facebook_upload_context_unavailable', $error->reason); self::assertSame('not_executed', $error->outcome); }
        }
        self::assertCount(0, $owner->uploads);
        self::assertCount(0, $this->session->uploads);
    }

    private static function context(string $accountId = '10001', string $actorId = '20002'): PhotoUploadContext
    {
        return new PhotoUploadContext($accountId, $actorId, ['uploadEndpoint' => PhotoUploadContext::ENDPOINT,
            'uploadData' => ['source' => 8, 'profile_id' => '10001', 'waterfallxapp' => 'comet']]);
    }
}
