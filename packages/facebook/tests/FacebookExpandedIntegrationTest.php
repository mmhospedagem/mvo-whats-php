<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\{FacebookConnection,FacebookException};
use MVO\Facebook\Media\PhotoUploadContext;
use MVO\Facebook\Service\{MediaService,PageAdministrationService,AlbumService,ModerationService,ContentInsightsService};
use MVO\Messenger\Auth\{BootstrapWebSession,CookieJar,MultipartWebSession,PageMailboxWebSession,WebLoginService};
use MVO\Messenger\E2ee\{DeviceManager,RegistrationTransport};
use MVO\Messenger\{MessengerClient,MessengerException};
use MVO\Messenger\Protocol\{DgwCodec,PageMailboxConfig,PageTaskBuilder,TaskBuilder};
use MVO\Messenger\Transport\{HttpResponse,MultipartHttpClient,Socket,UploadFile};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/FacebookConnectionFixture.php';

/** The actual public facade and Messenger owner closures, with synthetic peers and no external I/O. */
final class FacebookExpandedIntegrationTest extends TestCase
{
    private string $directory;
    /** @var list<FacebookConnection|MessengerClient> */
    private array $clients = [];
    private UploadFile $file;
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/mvo-expanded-integration-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory.'/photo.png', 'SYNTHETIC_UPLOAD_FILE');
        $this->file = new UploadFile($this->directory.'/photo.png', 'photo.png', 'image/png');
    }
    protected function tearDown(): void
    {
        foreach ($this->clients as $client) $client->stop();
        unset($client); $this->clients = []; gc_collect_cycles();
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->directory);
    }

    public function testFacebookConnectionDelegatesNewServicesToOneOwnerAndRevokesRetainedOperationsOnStop(): void
    {
        $http = new ExpandedConnectionHttp(); $peer = new FacebookConnectionPeer();
        $connection = new FacebookConnection('expanded', $this->directory.'/auth', $this->directory.'/services', $http,
            static fn(string $url, string $origin, array $headers): Socket => $peer, static fn(array $urls): array => [], .1);
        $this->clients[] = $connection;
        foreach (['media', 'administration', 'e2eeDevice', 'e2eeRuntime', 'albums', 'moderation', 'contentInsights'] as $method) {
            try { $connection->$method(); self::fail('An unauthenticated facade exposed a new service'); }
            catch (MessengerException $error) { self::assertSame('not_connected', $error->reason); }
        }
        $connection->login('person@example.invalid', FacebookConnectionFixture::PASSWORD,
            new WebLoginService(new FacebookConnectionLoginTransport(), static fn(array $urls): array => []));
        $connection->connect();
        $media = $connection->media();
        self::assertInstanceOf(MediaService::class, $media);
        self::assertInstanceOf(PageAdministrationService::class, $connection->administration());
        $albums=$connection->albums();$moderation=$connection->moderation();$contentInsights=$connection->contentInsights();
        self::assertInstanceOf(AlbumService::class,$albums);
        self::assertInstanceOf(ModerationService::class,$moderation);
        self::assertInstanceOf(ContentInsightsService::class,$contentInsights);
        $documents=array_column($connection->services()->documentInventory(),'name');
        foreach(['ProfileCometLegacyAlbumGridViewPaginationQuery','BizKitContextCardBlockWithProductLogicMutation',
            'BizWebUnifiedTableCursorPaginator_data_refetchable','BizWebUnifiedTableCustomColumnModalQuery']as $name)self::assertContains($name,$documents);
        $transport = new ExpandedRegistrationTransport();
        $device = $connection->e2eeDevice($transport);
        self::assertInstanceOf(DeviceManager::class, $device);
        self::assertFalse($device->status()['e2ee_ready']);
        $encrypted=$connection->e2eeRuntime();
        self::assertSame($encrypted,$connection->e2eeRuntime(),'One encrypted controller must share the original owner');
        self::assertFalse($encrypted->status()['e2ee_ready']);self::assertFalse($encrypted->status()['login_confirmed']);
        $before=count($http->urls);
        try{$encrypted->connect();self::fail('No encrypted registration exists');}
        catch(MessengerException $error){self::assertSame(extension_loaded('gmp')&&extension_loaded('zlib')?'messenger_device_registration_required':'messenger_e2ee_dependency_missing',$error->reason);}
        self::assertCount($before,$http->urls,'Creating/inspecting a controller cannot register or perform encrypted I/O');
        $context = $media->photoContext(FacebookConnectionFixture::PAGE);
        self::assertSame(FacebookConnectionFixture::ACCOUNT, $context->accountId);
        self::assertSame(FacebookConnectionFixture::ACCOUNT, $context->formData()['profile_id']);
        self::assertCount(1, $http->urls, 'Reading an existing bootstrap module must not issue HTTP');
        $result = $media->uploadPhoto(FacebookConnectionFixture::PAGE, $this->file, 'expanded-photo', $context);
        self::assertSame('30003', $result['photo_id']);
        self::assertCount(1, $http->uploads);
        self::assertSame(FacebookConnectionFixture::ACCOUNT, $http->uploads[0]['account']);
        self::assertSame(PhotoUploadContext::ENDPOINT.'?av='.FacebookConnectionFixture::PAGE, $http->uploads[0]['url']);
        self::assertSame([], $transport->operations, 'Metadata connection and upload must not register an E2EE device');
        $connection->stop();
        self::assertFalse($encrypted->status()['login_confirmed']);
        try{$encrypted->messages();self::fail('A retained encrypted runtime used a stopped owner');}
        catch(MessengerException $error){self::assertSame('not_connected',$error->reason);}
        try { $media->photoContext(FacebookConnectionFixture::PAGE); self::fail('A retained service read stopped-owner bootstrap'); }
        catch (FacebookException $error) { self::assertSame('facebook_upload_context_unavailable', $error->reason); }
        try { $media->uploadPhoto(FacebookConnectionFixture::PAGE, $this->file, 'after-stop', $context); self::fail('A retained service uploaded after stop'); }
        catch (MessengerException $error) { self::assertSame('not_connected', $error->reason); }
        $httpCount=count($http->urls);
        foreach([
            fn()=>$albums->items(FacebookConnectionFixture::PAGE,'30003'),
            fn()=>$moderation->banUser(FacebookConnectionFixture::PAGE,'40004','stopped-owner-ban'),
            fn()=>$contentInsights->columns(FacebookConnectionFixture::PAGE,[
                '__relay_internal__pv__shouldFetchAssociatedAdsInforelayprovider'=>false,
                '__relay_internal__pv__shouldFetchAMPostPreviewrelayprovider'=>false]),
        ]as $operation){
            try{$operation();self::fail('A retained expanded service reached I/O after its original owner stopped');}
            catch(MessengerException $error){self::assertSame('not_connected',$error->reason);}
        }
        foreach(['albums','moderation','contentInsights']as $method){
            try{$connection->$method();self::fail('A stopped connection exposed a new service');}
            catch(MessengerException $error){self::assertSame('not_connected',$error->reason);}
        }
        self::assertCount($httpCount,$http->urls,'Stopped-owner guards must run before any HTTP request');
        try { $device->registrationRoster(); self::fail('A retained device manager started protocol I/O after stop'); }
        catch (MessengerException $error) { self::assertSame('not_connected', $error->reason); }
        self::assertCount(1, $http->uploads);
        self::assertSame([], $transport->operations);
    }

    /** @return iterable<string,array{string}> */
    public static function rejectedUploadUrls(): iterable
    {
        $endpoint = PhotoUploadContext::ENDPOINT;
        yield 'host' => ['https://attacker.invalid/ajax/react_composer/attachments/photo/upload?av=20002'];
        yield 'scheme' => [str_replace('https:', 'http:', $endpoint).'?av=20002'];
        yield 'port' => [str_replace('upload.facebook.com', 'upload.facebook.com:444', $endpoint).'?av=20002'];
        yield 'userinfo' => [str_replace('upload.facebook.com', 'person@upload.facebook.com', $endpoint).'?av=20002'];
        yield 'fragment' => [$endpoint.'?av=20002#context'];
        yield 'path' => ['https://upload.facebook.com/api/graphql/?av=20002'];
        yield 'extra query' => [$endpoint.'?av=20002&redirect=https%3A%2F%2Fexample.invalid'];
        yield 'array actor' => [$endpoint.'?av[]=20002'];
        yield 'duplicate actor' => [$endpoint.'?av=20002&av=30003'];
        yield 'no actor' => [$endpoint];
    }

    #[DataProvider('rejectedUploadUrls')]
    public function testActualOwnerRejectsUnsupportedUploadDestinationsBeforeTheHttpTransport(string $url): void
    {
        $http = new ExpandedConnectionHttp(); $client = $this->runtime($http);
        $session = $client->webSession();
        self::assertInstanceOf(MultipartWebSession::class, $session);
        try { $session->upload($url, ['__user' => FacebookConnectionFixture::ACCOUNT], ['farr' => $this->file]); self::fail('An unobserved destination reached the upload transport'); }
        catch (\InvalidArgumentException) {}
        self::assertSame([], $http->uploads);
    }

    public function testActualOwnerRetainsCookieScopeAndRejectsForeignIdentityAndWrongFileField(): void
    {
        $http = new ExpandedConnectionHttp(); $client = $this->runtime($http);
        $session = $client->webSession(); self::assertInstanceOf(MultipartWebSession::class, $session);
        $url = PhotoUploadContext::ENDPOINT.'?av=20002';
        foreach ([['__user' => '999'], []] as $form) {
            try { $session->upload($url, $form, ['farr' => $this->file]); self::fail('A foreign account reached upload'); }
            catch (\InvalidArgumentException) {}
        }
        try { $session->upload($url, ['__user' => FacebookConnectionFixture::ACCOUNT], ['farr[]' => $this->file]); self::fail('An unobserved file field reached upload'); }
        catch (\InvalidArgumentException) {}
        self::assertSame([], $http->uploads);
    }

    public function testHostOnlyFacebookCookiesAreNotBroadenedToTheUploadHost(): void
    {
        $http = new ExpandedConnectionHttp(); $client = $this->runtime($http, true);
        $session = $client->webSession(); self::assertInstanceOf(MultipartWebSession::class, $session);
        try { $session->upload(PhotoUploadContext::ENDPOINT.'?av=20002', ['__user' => FacebookConnectionFixture::ACCOUNT], ['farr' => $this->file]); self::fail('Host-only cookies were widened'); }
        catch (MessengerException $error) { self::assertSame('facebook_scope_unavailable', $error->reason); }
        self::assertSame([], $http->uploads);
    }

    public function testRetainedOwnerClosureEnforcesCallbacksStopAndBootstrapAllowlist(): void
    {
        $http = new ExpandedConnectionHttp(); $client = $this->runtime($http);
        $session = $client->webSession();
        self::assertInstanceOf(MultipartWebSession::class, $session);
        self::assertInstanceOf(BootstrapWebSession::class, $session);
        self::assertIsArray($session->privateModule('ReactComposerMediaConfig'));
        try { $session->privateModule('DTSGInitialData'); self::fail('An arbitrary private module was exposed'); }
        catch (\InvalidArgumentException) {}
        $client->stop(); $guarded = false;
        $client->on('connection', function (array $event) use ($session, &$guarded): void {
            if ($event['state'] !== 'metadata_connected') return;
            try { $session->upload(PhotoUploadContext::ENDPOINT.'?av=20002', ['__user' => FacebookConnectionFixture::ACCOUNT], ['farr' => $this->file]); TestCase::fail('Upload started from a protocol callback'); }
            catch (\LogicException) { $guarded = true; }
        });
        $client->connect(); self::assertTrue($guarded); self::assertSame([], $http->uploads);
        $client->stop();
        try { $session->upload(PhotoUploadContext::ENDPOINT.'?av=20002', ['__user' => FacebookConnectionFixture::ACCOUNT], ['farr' => $this->file]); self::fail('Stopped owner closure reached HTTP'); }
        catch (MessengerException $error) { self::assertSame('not_connected', $error->reason); }
        try { $session->privateModule('ReactComposerMediaConfig'); self::fail('Stopped owner exposed private bootstrap'); }
        catch (MessengerException $error) { self::assertSame('not_connected', $error->reason); }
        self::assertSame([], $http->uploads);
    }

    public function testNativePageMailboxFactoryUsesSeparatePageSocketAndRetainsOriginalAccountOwner(): void
    {
        $factory = new ExpandedPageFactory();
        $client = $this->runtime(new ExpandedConnectionHttp(), false, $factory);
        $session = $client->webSession();
        self::assertInstanceOf(PageMailboxWebSession::class, $session);
        $configuration = self::pageConfig();
        $gateway = $session->pageMailbox($configuration);
        self::assertSame(1, $factory->opens, 'Creating the child transport must not connect it eagerly');
        try {
            $request = self::pageRequest();
            $response = $gateway->request($request);
            self::assertSame(1, $response['request_id']);
            self::assertSame(2, $factory->opens);
            self::assertSame(1, $factory->pageOpens);
            self::assertSame($configuration->jsonSerialize(), $gateway->config()->jsonSerialize());
            self::assertTrue($client->isConnected());
            $gateway->close();
            self::assertFalse($factory->page->isConnected());
            self::assertTrue($client->isConnected(), 'Closing the Page child must preserve the personal owner and lock');
            $other = new MessengerClient('runtime', $this->directory.'/auth');
            try { $other->importCookies(new CookieJar(FacebookConnectionFixture::cookies())); self::fail('The Page child released the owner lock'); }
            catch (\RuntimeException) {}
            unset($other);
            try { $session->pageMailbox(self::pageConfig('99999')); self::fail('Another account was accepted by the Page factory'); }
            catch (\InvalidArgumentException) {}
            self::assertSame(2, $factory->opens);
        } finally { $gateway->close(); }
    }

    public function testParentStopClosesPageSocketAndRejectsRetainedPageRequestsAndFactories(): void
    {
        $factory = new ExpandedPageFactory();
        $client = $this->runtime(new ExpandedConnectionHttp(), false, $factory);
        $session = $client->webSession();
        self::assertInstanceOf(PageMailboxWebSession::class, $session);
        $gateway = $session->pageMailbox(self::pageConfig());
        try {
            $gateway->request(self::pageRequest()); self::assertTrue($factory->page->isConnected());
            $client->stop();
            try { $gateway->request(self::pageRequest()); self::fail('A stopped owner allowed a retained Page request'); }
            catch (MessengerException $error) { self::assertSame('not_connected', $error->reason); }
            try { $session->pageMailbox(self::pageConfig()); self::fail('A stopped owner created another Page transport'); }
            catch (MessengerException $error) { self::assertSame('not_connected', $error->reason); }
            self::assertSame(2, $factory->opens);
            self::assertFalse($factory->page->isConnected(), 'Stopping the owner must close all authenticated Page child sockets before releasing its lock');
        } finally { $gateway->close(); }
    }

    private static function pageConfig(string $account = FacebookConnectionFixture::ACCOUNT): PageMailboxConfig
    {
        return new PageMailboxConfig($account, FacebookConnectionFixture::PAGE, '123', '1', '456', '28761380846847381', 'synthetic-page-device');
    }

    /** @return array<string,mixed> */
    private static function pageRequest(): array
    {
        return json_decode(TaskBuilder::request('456', 1, 3, TaskBuilder::batch('28761380846847381',
            [PageTaskBuilder::text('40004', 'Synthetic Page fixture', '7130316800000000001')], '7130316800000000002')), true, 64, JSON_THROW_ON_ERROR);
    }

    private function runtime(ExpandedConnectionHttp $http, bool $hostOnly = false, ?ExpandedPageFactory $pageFactory = null): MessengerClient
    {
        $peer = new FacebookConnectionPeer();
        $client = new MessengerClient('runtime', $this->directory.'/auth', $http,
            $pageFactory ?? static fn(string $url, string $origin, array $headers): Socket => $peer, static fn(array $urls): array => [], .1);
        $this->clients[] = $client;
        $cookies = FacebookConnectionFixture::cookies();
        if ($hostOnly) foreach ($cookies as &$cookie) { $cookie['domain'] = 'www.facebook.com'; $cookie['hostOnly'] = true; }
        unset($cookie);
        $client->importCookies(new CookieJar($cookies));
        $client->connect();
        return $client;
    }
}

final class ExpandedConnectionHttp implements MultipartHttpClient
{
    /** @var list<string> */ public array $urls = [];
    /** @var list<array{url:string,account:string}> */ public array $uploads = [];
    public function request(string $method, string $url, CookieJar $cookies, array $form = []): HttpResponse
    {
        $this->urls[] = $url;
        $response = (new FacebookConnectionHttp())->request($method, $url, $cookies, $form);
        $modules = [['ReactComposerMediaConfig', [], ['photo' => ['uploadEndpoint' => PhotoUploadContext::ENDPOINT,
            'uploadData' => ['source' => 8, 'profile_id' => FacebookConnectionFixture::ACCOUNT, 'waterfallxapp' => 'comet']]], 0],
            ['MessengerWebInitData', [], ['appId' => '123', 'cryptoAuthToken' => ['encryptedSerializedCat' => 'SYNTHETIC_EXPANDED_CAT', 'expirationTimeInSeconds' => time()+600]], 0]];
        return new HttpResponse($response->status, $response->body.'<script type="application/json">'.json_encode(['define' => $modules], JSON_THROW_ON_ERROR).'</script>');
    }
    public function upload(string $url, CookieJar $cookies, array $form, array $files): HttpResponse
    {
        TestCase::assertStringContainsString('xs='.FacebookConnectionFixture::SESSION, $cookies->headerFor($url));
        TestCase::assertSame(FacebookConnectionFixture::ACCOUNT, $form['__user']);
        TestCase::assertSame(['farr'], array_keys($files));
        $this->uploads[] = ['url' => $url, 'account' => $cookies->accountId()];
        return new HttpResponse(200, 'for (;;);{"payload":{"photoID":"30003","width":1,"height":1}}');
    }
}

final class ExpandedRegistrationTransport implements RegistrationTransport
{
    /** @var list<string> */ public array $operations = [];
    public function request(string $operation, array $form): HttpResponse
    {
        $this->operations[] = $operation;
        throw new \LogicException('This integration fixture must never register or fetch devices implicitly');
    }
}

final class ExpandedPageFactory
{
    public int $opens = 0;
    public int $pageOpens = 0;
    public readonly ExpandedPageSocket $page;
    private readonly FacebookConnectionPeer $personal;
    public function __construct() { $this->page = new ExpandedPageSocket(); $this->personal = new FacebookConnectionPeer(); }
    /** @param array<string,string> $headers */
    public function __invoke(string $url, string $origin, array $headers): Socket
    {
        ++$this->opens; parse_str(parse_url($url, PHP_URL_QUERY), $query);
        TestCase::assertSame(FacebookConnectionFixture::ACCOUNT, $query['x-dgw-uuid']);
        TestCase::assertStringContainsString('c_user='.FacebookConnectionFixture::ACCOUNT, $headers['Cookie']);
        if (isset($query['x-dgw-app-asset_ids'])) {
            ++$this->pageOpens;
            TestCase::assertSame(['PAGE' => FacebookConnectionFixture::PAGE], json_decode($query['x-dgw-app-asset_ids'], true, 16, JSON_THROW_ON_ERROR));
            TestCase::assertSame('https://business.facebook.com', $origin);
            TestCase::assertSame('123', $query['x-dgw-appid']);
            return $this->page;
        }
        TestCase::assertSame('https://www.facebook.com', $origin);
        return $this->personal;
    }
}

final class ExpandedPageSocket implements Socket
{
    private bool $connected = false;
    /** @var list<string> */ private array $incoming = [];
    public function connect(): void { $this->connected = true; }
    public function isConnected(): bool { return $this->connected; }
    public function close(): void { $this->connected = false; $this->incoming = []; }
    public function receive(float $timeoutSeconds = .5): ?string { return array_shift($this->incoming); }
    public function send(string $payload): void
    {
        foreach (DgwCodec::decode($payload) as $frame) {
            if ($frame['type'] !== DgwCodec::DATA) continue;
            $request = json_decode($frame['payload'], true, 64, JSON_THROW_ON_ERROR);
            TestCase::assertSame('456', $request['app_id']); TestCase::assertSame(3, $request['type']);
            $stream = $frame['stream_id'];
            $this->incoming[] = DgwCodec::encode(['type' => DgwCodec::ESTABLISH, 'stream_id' => $stream, 'parameters' => 200])
                . DgwCodec::encode(['type' => DgwCodec::ACK, 'stream_id' => $stream, 'ack_id' => $frame['ack_id']])
                . DgwCodec::encode(['type' => DgwCodec::DATA, 'stream_id' => $stream, 'ack_id' => 0, 'requires_ack' => true,
                    'payload' => json_encode(['request_id' => $request['request_id'], 'payload' => '{"step":[1]}', 'sp' => []], JSON_THROW_ON_ERROR)]);
        }
    }
}
