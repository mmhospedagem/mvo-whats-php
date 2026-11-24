<?php
declare(strict_types=1);
namespace MVO\Messenger\Tests;

use MVO\Messenger\Auth\{CookieJar,SessionStore};
use MVO\Messenger\{MessengerClient,MessengerException};
use MVO\Messenger\Transport\{HttpClient,HttpResponse};
use PHPUnit\Framework\TestCase;

final class FreshBrowserLoginTest extends TestCase
{
    private string $root;
    private ?MessengerClient $client=null;
    protected function setUp():void{$this->root=sys_get_temp_dir().'/mvo-fresh-browser-'.bin2hex(random_bytes(8));mkdir($this->root,0700);}
    protected function tearDown():void{
        $this->client?->stop();$this->client=null;gc_collect_cycles();
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST)as$file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($this->root);
    }
    public function testFreshBrowserNeedsNoCredentialsOrNetworkAndRetainsExclusiveOwnership():void{
        $http=new FreshBrowserHttp('123');$client=$this->client=new MessengerClient('test',$this->root,$http);
        $handoff=$client->beginBrowserLogin();self::assertSame('https://www.facebook.com/login/',$handoff['url']);self::assertSame([],$handoff['cookies']);self::assertGreaterThan(time(),$handoff['expires_at']);self::assertSame(0,$http->calls);
        $other=new SessionStore($this->root,'test');self::assertNull($other->get('cookies'));
        try{$other->acquire();self::fail('A second owner must be blocked');}catch(\RuntimeException $error){self::assertStringContainsString('active owner',$error->getMessage());}
        $client->completeBrowserLogin(CookieJar::fromJson('{"c_user":"123","xs":"SYNTHETIC_XS"}'));
        self::assertSame(1,$http->calls);self::assertSame('123',$other->get('account_id'));$other->acquire();$other->release();
    }
    public function testAnUnconfirmedIdentityNeverBecomesDurable():void{
        $client=$this->client=new MessengerClient('test',$this->root,new FreshBrowserHttp('456'));$client->beginBrowserLogin();
        try{$client->completeBrowserLogin(CookieJar::fromJson('{"c_user":"123","xs":"SYNTHETIC_XS"}'));self::fail('Identity mismatch accepted');}catch(MessengerException $error){self::assertSame('login_identity_mismatch',$error->reason);}
        $store=new SessionStore($this->root,'test');self::assertNull($store->get('cookies'));
    }
    public function testExpiredFreshLoginCannotVerifyOrPersistAndReleasesOwnership():void{
        $http=new FreshBrowserHttp('123');$client=$this->client=new MessengerClient('test',$this->root,$http);$client->beginBrowserLogin();
        (new \ReflectionProperty($client,'browserLoginExpiresAt'))->setValue($client,time()-1);
        try{$client->completeBrowserLogin(CookieJar::fromJson('{"c_user":"123","xs":"SYNTHETIC_XS"}'));self::fail('Expired browser accepted');}catch(MessengerException $error){self::assertSame('login_challenge_expired',$error->reason);}
        self::assertSame(0,$http->calls);$store=new SessionStore($this->root,'test');$store->acquire();self::assertNull($store->get('cookies'));$store->release();
    }
}
final class FreshBrowserHttp implements HttpClient
{
    public int $calls=0;
    public function __construct(private readonly string $identity){}
    public function request(string $method,string $url,CookieJar $cookies,array $form=[]):HttpResponse{++$this->calls;return new HttpResponse(200,'<script type="application/json">'.json_encode(['define'=>[['CurrentUserInitialData',[],['USER_ID'=>$this->identity],1]]],JSON_THROW_ON_ERROR).'</script>');}
}
