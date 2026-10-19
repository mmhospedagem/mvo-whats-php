<?php
declare(strict_types=1);
namespace MVO\Messenger\Tests;

use MVO\Messenger\Auth\CookieJar;
use MVO\Messenger\Auth\SessionStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    private array $directories=[];
    protected function tearDown():void
    {
        gc_collect_cycles();
        foreach($this->directories as $root){
            $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
            foreach($iterator as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}
            rmdir($root);
        }
    }
    private function root():string
    {
        $path=sys_get_temp_dir().'/mvo-messenger-tests-'.bin2hex(random_bytes(8));mkdir($path,0700);$this->directories[]=$path;return $path;
    }
    private static function jar(string $id='123456789012345',string $token='SYNTHETIC_SESSION_VALUE'):CookieJar {return CookieJar::fromJson(json_encode(['c_user'=>$id,'xs'=>$token,'datr'=>'SYNTHETIC_DEVICE_VALUE'],JSON_THROW_ON_ERROR));}

    public function testCookieImportRetainsTheExactFacebookIdentityAndRedactsSecrets():void
    {
        $jar=self::jar();
        self::assertSame('123456789012345',$jar->accountId());
        self::assertStringContainsString('xs=SYNTHETIC_SESSION_VALUE',$jar->headerFor('https://www.facebook.com/messages/'));
        self::assertStringNotContainsString('SYNTHETIC_SESSION_VALUE',print_r($jar,true));
        self::assertSame($jar->headerFor('https://www.facebook.com/'),CookieJar::fromJson($jar->exportPrivate())->headerFor('https://www.facebook.com/'));
    }
    public function testCookieDomainAndPathBoundariesAreEnforced():void
    {
        $jar=new CookieJar([
            ['name'=>'c_user','value'=>'123','domain'=>'.facebook.com'],['name'=>'xs','value'=>'SYNTHETIC','domain'=>'.facebook.com'],
            ['name'=>'scoped','value'=>'private','domain'=>'www.facebook.com','hostOnly'=>true,'path'=>'/messages'],
            ['name'=>'expired','value'=>'gone','expirationDate'=>1],
        ]);
        self::assertStringContainsString('scoped=private',$jar->headerFor('https://www.facebook.com/messages/t/42'));
        self::assertStringNotContainsString('scoped=',$jar->headerFor('https://www.facebook.com/messages-evil'));
        self::assertStringNotContainsString('scoped=',$jar->headerFor('https://gateway.facebook.com/messages'));
        self::assertSame('',$jar->headerFor('https://www.messenger.com/'));
        self::assertStringNotContainsString('expired=',$jar->headerFor('https://www.facebook.com/'));
    }
    public function testSameAccountMayHaveDifferentAuthenticationTokensPerSsoDomain():void
    {
        $jar=new CookieJar([
            ['name'=>'c_user','value'=>'123','domain'=>'.facebook.com'],['name'=>'xs','value'=>'FACEBOOK_SYNTHETIC','domain'=>'.facebook.com'],
            ['name'=>'c_user','value'=>'123','domain'=>'.messenger.com'],['name'=>'xs','value'=>'MESSENGER_SYNTHETIC','domain'=>'.messenger.com'],
        ]);
        self::assertSame('123',$jar->accountId());
        self::assertSame('c_user=123; xs=FACEBOOK_SYNTHETIC',$jar->headerFor('https://www.facebook.com/messages/'));
        self::assertSame('c_user=123; xs=MESSENGER_SYNTHETIC',$jar->headerFor('https://www.messenger.com/'));
    }
    public static function badDestinations():iterable
    {
        yield ['http://www.facebook.com/'];yield ['https://facebook.com.evil.invalid/'];yield ['https://evilfacebook.com/'];yield ['https://user:pass@facebook.com/'];yield ['https://127.0.0.1/'];
    }
    #[DataProvider('badDestinations')]
    public function testSessionCannotBeForwardedToOtherOrigins(string $url):void
    {
        $this->expectException(\InvalidArgumentException::class);self::jar()->headerFor($url);
    }
    public static function invalidImports():iterable
    {
        yield ['{"c_user":"123"}'];yield ['{"c_user":"0","xs":"x"}'];yield ['{"c_user":"123@lid","xs":"x"}'];yield ['{"c_user":"123","xs":"x\\r\\nInjected:yes"}'];
        yield ['[{"name":"c_user","value":"123"},{"name":"xs","value":"x"},{"name":"c_user","value":"999","domain":"www.facebook.com"}]'];
        yield ['[{"name":"c_user","value":"123"},{"name":"xs","value":"x","domain":"facebook.com.evil.invalid"}]'];
    }
    #[DataProvider('invalidImports')]
    public function testRejectsInvalidOrAmbiguousCredentials(string $json):void
    {
        $this->expectException(\InvalidArgumentException::class);CookieJar::fromJson($json);
    }
    public function testOnlyOneOwnerCanMutateAClientAndStoppingPreservesCredentials():void
    {
        $root=$this->root();$first=new SessionStore($root,'demo');$other=new SessionStore($root,'demo');
        $first->acquire();$first->saveCookies(self::jar());
        try{$other->acquire();self::fail('Expected lock conflict');}catch(\RuntimeException $e){self::assertStringContainsString('active owner',$e->getMessage());}
        $first->release();$other->acquire();self::assertSame('123456789012345',$other->loadCookies()?->accountId());
        $other->release();unset($first,$other);
    }
    public function testIsolatedEventsAndExactIdsSurviveRestartWithDeduplication():void
    {
        $root=$this->root();$first=new SessionStore($root,'one');$second=new SessionStore($root,'two');$first->acquire();$second->acquire();
        self::assertTrue($first->event('message',['id'=>'mid.test','thread_id'=>'12345678901234567'],'message:mid.test'));
        self::assertFalse($first->event('message',['id'=>'mid.test'],'message:mid.test'));
        self::assertSame([],$second->events());
        $first->putThread('12345678901234567',['id'=>'12345678901234567','type'=>15,'encrypted'=>true]);
        $first->enqueue('42','12345678901234567','Test',1);$first->markSent('42','uncertain');
        self::assertSame('uncertain',$first->outgoing('42')['state']);
        self::assertArrayNotHasKey('text',$first->outgoing('42'));
        $first->release();unset($first);$restart=new SessionStore($root,'one');
        self::assertSame('12345678901234567',$restart->events()[0]['payload']['thread_id']);self::assertTrue($restart->thread('12345678901234567')['encrypted']);
        self::assertSame([],$restart->events(1));$second->release();unset($restart,$second);
    }
    public function testAnImportedSessionCannotSwitchFacebookAccount():void
    {
        $root=$this->root();$store=new SessionStore($root,'one');$store->acquire();$store->saveCookies(self::jar());
        try{$store->saveCookies(self::jar('999'));self::fail('Expected account conflict');}catch(\InvalidArgumentException $e){self::assertStringContainsString('another Facebook account',$e->getMessage());}
        $store->release();unset($store);
    }

    public function testFailureWritingTheFirstAccountIdentityRollsBackCookiesAndAllowsCleanRetry():void
    {
        $root=$this->root();$store=new SessionStore($root,'one');$store->acquire();
        $database=new \PDO('sqlite:'.$root.'/messenger/one/state.sqlite',null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $database->exec("CREATE TRIGGER fail_account_identity BEFORE INSERT ON settings WHEN NEW.name='account_id' BEGIN SELECT RAISE(ABORT,'synthetic identity failure'); END");
        $failed=false;
        try{$store->saveCookies(self::jar());}catch(\PDOException){$failed=true;}
        self::assertTrue($failed,'The fixture must interrupt the second write');
        self::assertNull($store->get('cookies'),'A failed initial identity write must not leave durable credentials');
        self::assertNull($store->get('account_id'));
        self::assertNull($store->loadCookies());
        self::assertTrue($store->ownsLock(),'The caller retains its session lock after rollback');
        $database->exec('DROP TRIGGER fail_account_identity');
        $store->saveCookies(self::jar());
        $store->release();unset($store,$database);gc_collect_cycles();
        $restart=new SessionStore($root,'one');$restart->acquire();
        self::assertSame('123456789012345',$restart->get('account_id'));
        self::assertSame(self::jar()->exportPrivate(),$restart->loadCookies()?->exportPrivate());
        try{$restart->saveCookies(self::jar('999'));self::fail('A committed session switched account after retry');}
        catch(\InvalidArgumentException){self::assertSame('123456789012345',$restart->get('account_id'));}
        self::assertSame(self::jar()->exportPrivate(),$restart->loadCookies()?->exportPrivate());
        $restart->release();unset($restart);
    }

    public function testFailedCredentialRefreshPreservesBothPreviousIdentityAndExactCookiesAfterRestart():void
    {
        $root=$this->root();$store=new SessionStore($root,'one');$store->acquire();
        $original=self::jar(token:'SYNTHETIC_ORIGINAL');$store->saveCookies($original);
        $database=new \PDO('sqlite:'.$root.'/messenger/one/state.sqlite',null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $database->exec("CREATE TRIGGER fail_account_identity BEFORE INSERT ON settings WHEN NEW.name='account_id' BEGIN SELECT RAISE(ABORT,'synthetic identity failure'); END");
        $failed=false;
        try{$store->saveCookies(self::jar(token:'SYNTHETIC_ROTATED'));}catch(\PDOException){$failed=true;}
        self::assertTrue($failed,'The fixture must interrupt the identity upsert after the cookie update');
        self::assertSame('123456789012345',$store->get('account_id'));
        self::assertSame($original->exportPrivate(),$store->loadCookies()?->exportPrivate());
        $database->exec('DROP TRIGGER fail_account_identity');
        $store->release();unset($store,$database);gc_collect_cycles();
        $restart=new SessionStore($root,'one');$restart->acquire();
        self::assertSame('123456789012345',$restart->get('account_id'));
        self::assertSame($original->exportPrivate(),$restart->loadCookies()?->exportPrivate());
        try{$restart->saveCookies(self::jar('999'));self::fail('A failed refresh weakened the account boundary');}
        catch(\InvalidArgumentException){self::assertSame('123456789012345',$restart->get('account_id'));}
        self::assertSame($original->exportPrivate(),$restart->loadCookies()?->exportPrivate());
        $restart->release();unset($restart);
    }
}
