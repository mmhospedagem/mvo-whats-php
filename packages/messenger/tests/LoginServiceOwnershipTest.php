<?php
declare(strict_types=1);

namespace MVO\Messenger\Tests;

use MVO\Messenger\Auth\{CookieJar,LoginService,SessionStore};
use MVO\Messenger\{MessengerClient,MessengerException};
use PHPUnit\Framework\TestCase;

final class LoginServiceOwnershipTest extends TestCase
{
    private string $directory;
    private ?MessengerClient $client=null;

    protected function setUp():void
    {
        $this->directory=sys_get_temp_dir().'/mvo-login-owner-'.bin2hex(random_bytes(8));
        mkdir($this->directory,0700);
    }

    protected function tearDown():void
    {
        $this->client?->stop();$this->client=null;gc_collect_cycles();
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $file){
            if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testAlternativeAuthenticationCannotRunBeforeAcquiringTheExistingOwnerLock():void
    {
        $store=new SessionStore($this->directory,'tenant');$store->acquire();
        $service=new IsolatedLoginService();
        $this->client=new MessengerClient('tenant',$this->directory);
        try{$this->client->login('test@example.invalid','SYNTHETIC_PASSWORD',$service);self::fail('Another owner must block login');}
        catch(\RuntimeException $error){self::assertStringContainsString('active owner',$error->getMessage());}
        self::assertSame(0,$service->attempts);self::assertNull($store->loadCookies());
        $store->release();
    }

    public function testTwoFactorContinuationUsesTheSameIdentityAndLockWithoutPersistingItsSecrets():void
    {
        $service=new IsolatedLoginService();$this->client=new MessengerClient('tenant',$this->directory);
        try{$this->client->login('test@example.invalid','SYNTHETIC_PASSWORD',$service);self::fail('Expected challenge');}
        catch(MessengerException $error){self::assertSame('login_two_factor_required',$error->reason);}
        self::assertSame('awaiting_two_factor',$this->client->getState());
        self::assertTrue($this->client->loginChallenge()['ready']);
        $store=new SessionStore($this->directory,'tenant');self::assertNull($store->loadCookies());
        try{$store->acquire();self::fail('Pending verification must retain the owner');}
        catch(\RuntimeException $error){self::assertStringContainsString('active owner',$error->getMessage());}
        $this->client->confirmLogin('123456');
        self::assertSame('123',$store->loadCookies()?->accountId());
        self::assertSame('session_imported',$this->client->getState());
        self::assertFalse($this->client->isConnected());
        self::assertFalse($this->client->accountInfo()['e2ee_ready']);
        self::assertNull($this->client->loginChallenge());
        $events=json_encode($store->events(),JSON_THROW_ON_ERROR);
        foreach(['SYNTHETIC_PASSWORD','123456','SYNTHETIC_FIRST_FACTOR'] as $secret)self::assertStringNotContainsString($secret,$events);
        $store->acquire();$store->release();
    }

    public function testStoppingAlternativeLoginCancelsPendingContextAndReleasesItsLock():void
    {
        $service=new IsolatedLoginService();$this->client=new MessengerClient('tenant',$this->directory);
        try{$this->client->login('test@example.invalid','SYNTHETIC_PASSWORD',$service);}catch(MessengerException){}
        $this->client->stop();self::assertNull($service->challenge());
        $store=new SessionStore($this->directory,'tenant');$store->acquire();
        self::assertNull($store->loadCookies());$store->release();
    }
}

/** Synthetic, independently confirmed fixture service: no network or account operations. */
final class IsolatedLoginService implements LoginService
{
    public int $attempts=0;
    private bool $pending=false;
    private ?string $firstFactor=null;
    public function login(#[\SensitiveParameter]string $email,#[\SensitiveParameter]string $password):CookieJar
    {
        ++$this->attempts;$this->pending=true;$this->firstFactor='SYNTHETIC_FIRST_FACTOR';
        throw new MessengerException('login_two_factor_required','Confirm the synthetic verification');
    }
    public function challenge():?array{return $this->pending?['type'=>'two_factor','ready'=>true,'expires_at'=>time()+60]:null;}
    public function refreshMethods():array{return $this->challenge()??[];}
    public function requestMethod(string $methodId):array{throw new \LogicException('Not offered in fixture');}
    public function checkApproval():?CookieJar{throw new \LogicException('Not offered in fixture');}
    public function confirm(#[\SensitiveParameter]string $code):CookieJar
    {
        if(!$this->pending||$this->firstFactor===null||$code!=='123456')throw new \LogicException('Unexpected fixture confirmation');
        $this->pending=false;$this->firstFactor=null;
        return CookieJar::fromJson('{"c_user":"123","xs":"SYNTHETIC_SESSION"}');
    }
    public function cancel():void{$this->pending=false;$this->firstFactor=null;}
    public function exportPrivateChallenge():array{throw new \LogicException('No browser fallback');}
}
