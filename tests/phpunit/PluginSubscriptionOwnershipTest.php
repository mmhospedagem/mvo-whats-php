<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Plugin\{PluginContext,PluginDefinition};
use MVO\Protocol\BinaryNode;
use MVO\Transport\NodeSession;
use MVO\WaClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Subscription cleanup belongs to its context, even when a callback opens another login. */
final class PluginSubscriptionOwnershipTest extends TestCase
{
    private string $directory;
    private ?WaClient $client=null;

    protected function setUp():void
    {
        $this->directory=sys_get_temp_dir().'/mvo-plugin-ownership-'.bin2hex(random_bytes(8));
        mkdir($this->directory,0700);
        $credentials=CredentialStore::fresh();$credentials['registered']=true;
        $credentials['meJid']='5511999999999:12@s.whatsapp.net';$credentials['serverHasPreKeys']=true;
        $store=new CredentialStore($this->directory);$store->save($credentials);$store->close();
    }

    protected function tearDown():void
    {
        $this->client?->stop();$this->client=null;gc_collect_cycles();
        foreach(glob($this->directory.'/*/*')?:[]as$path)unlink($path);
        foreach(glob($this->directory.'/*')?:[]as$path)rmdir($path);
        rmdir($this->directory);
    }

    public function testDisposeOnlyRemovesItsOwnSubscriptionOfTheSameCallable():void
    {
        $client=$this->client=new WaClient(authDirectory:$this->directory);
        $old=new PluginContext($client);$new=new PluginContext($client);$calls=0;
        $shared=static function()use(&$calls):void{++$calls;};
        $old->on('probe',$shared);$new->on('probe',$shared);$client->on('probe',$shared);
        $old->dispose();$old->dispose();$client->emitEvent('probe');
        self::assertSame(2,$calls,'Old cleanup must preserve the new context and application subscription');
        $new->dispose();$client->emitEvent('probe');self::assertSame(3,$calls);
        $client->off('probe',$shared);$client->emitEvent('probe');self::assertSame(3,$calls);
    }

    public function testDisposeCannotRemoveOtherOwnersOnceSubscriptionOfTheSameCallable():void
    {
        $client=$this->client=new WaClient(authDirectory:$this->directory);
        $old=new PluginContext($client);$new=new PluginContext($client);$calls=0;
        $shared=static function()use(&$calls):void{++$calls;};
        $old->on('probe',$shared);$new->once('probe',$shared);$client->once('probe',$shared);
        $old->dispose();$client->emitEvent('probe');$client->emitEvent('probe');
        self::assertSame(2,$calls,'Disposal must preserve both separately owned once subscriptions');
        $new->dispose();$client->emitEvent('probe');self::assertSame(2,$calls);
    }

    public function testOnceUnsubscribesBeforeRecursiveEmissionAndAnException():void
    {
        $client=$this->client=new WaClient(authDirectory:$this->directory);$context=new PluginContext($client);$calls=0;
        $context->once('probe',static function()use($client,&$calls):void{
            ++$calls;$client->emitEvent('probe');throw new \RuntimeException('handler failed');
        });
        try{$client->emitEvent('probe');self::fail('The handler error should propagate');}
        catch(\RuntimeException $error){self::assertSame('handler failed',$error->getMessage());}
        $client->emitEvent('probe');$context->dispose();$context->dispose();self::assertSame(1,$calls);
    }

    public function testDisposeReleasesHandlersAndSkipsOnceThatHasNotFired():void
    {
        $client=$this->client=new WaClient(authDirectory:$this->directory);$context=new PluginContext($client);
        $calls=0;$handler=static function()use(&$calls):void{++$calls;};$weak=\WeakReference::create($handler);
        $context->on('probe',$handler);$context->once('probe',$handler);unset($handler);
        self::assertNotNull($weak->get());$context->dispose();$context->dispose();gc_collect_cycles();
        self::assertNull($weak->get(),'Neither listener storage nor disposed cleanup may retain the handler');
        $client->emitEvent('probe');self::assertSame(0,$calls);
    }

    /** @return iterable<string,array{bool}> */
    public static function cleanupOrigins():iterable {yield 'definition dispose'=>[false];yield 'context cleanup'=>[true];}

    /** @return array{WaClient,PluginOwnershipNodeSession,PluginOwnershipNodeSession} */
    private function reconnectingClient(bool $fromContext,\Closure $handler,bool $throw):array
    {
        $first=new PluginOwnershipNodeSession();$second=new PluginOwnershipNodeSession();$opens=0;$reentered=false;
        $cleanup=static function(PluginContext $context)use(&$reentered,$throw):void{
            if($reentered)return;$reentered=true;$context->client->connect();
            if($throw)throw new \RuntimeException('old plugin disposal failed');
        };
        $plugin=new PluginDefinition('owned',static function(PluginContext $context)use($handler,$cleanup,$fromContext):void{
            $context->on('probe',$handler);
            if($fromContext)$context->registerDispose(static function()use($context,$cleanup):void{$cleanup($context);});
        },$fromContext?null:static function(mixed $instance,PluginContext $context)use($cleanup):void{$cleanup($context);});
        $client=$this->client=new WaClient(authDirectory:$this->directory,automaticMaintenance:false,plugins:[$plugin],
            sessionFactory:static function()use(&$opens,$first,$second):NodeSession{return ++$opens===1?$first:$second;});
        $client->connect();return[$client,$first,$second];
    }

    #[DataProvider('cleanupOrigins')]
    public function testOldDisposalCannotRemoveReconnectedPluginWithTheSameCallable(bool $fromContext):void
    {
        $calls=0;[$client,$first,$second]=$this->reconnectingClient($fromContext,static function()use(&$calls):void{++$calls;},false);
        $client->stop();$client->emitEvent('probe');
        self::assertSame(1,$calls,'Only the replacement plugin subscription must remain active');
        self::assertTrue($client->isRunning());self::assertSame('authenticating',$client->getState());
        self::assertSame(1,$first->closes);self::assertSame(1,$second->starts);self::assertSame(0,$second->closes);
        $client->stop();$client->emitEvent('probe');self::assertSame(1,$calls);self::assertSame(1,$second->closes);
    }

    #[DataProvider('cleanupOrigins')]
    public function testOldDisposalErrorCannotBeEmittedIntoTheReconnectedGeneration(bool $fromContext):void
    {
        [$client,$first,$second]=$this->reconnectingClient($fromContext,static function():void{},true);$errors=[];
        $client->on('plugin_error',static function(array $event)use(&$errors):void{$errors[]=$event['message'];});
        $client->stop();
        self::assertSame([],$errors,'A failed old cleanup does not belong to the replacement connection');
        self::assertTrue($client->isRunning());self::assertSame('authenticating',$client->getState());
        self::assertSame(1,$first->closes);self::assertSame(1,$second->starts);self::assertSame(0,$second->closes);
    }

    public function testCurrentGenerationStillReportsPluginDisposalErrorsAndCompletesCleanup():void
    {
        $calls=0;$errors=[];$session=new PluginOwnershipNodeSession();
        $plugin=new PluginDefinition('owned',static function(PluginContext $context)use(&$calls):void{
            $context->on('probe',static function()use(&$calls):void{++$calls;});
        },static function():void{throw new \RuntimeException('current plugin disposal failed');});
        $client=$this->client=new WaClient(authDirectory:$this->directory,automaticMaintenance:false,plugins:[$plugin],
            sessionFactory:static fn():NodeSession=>$session);
        $client->on('plugin_error',static function(array $event)use(&$errors):void{$errors[]=$event['message'];});
        $client->connect();$client->stop();$client->emitEvent('probe');$client->stop();
        self::assertSame(['current plugin disposal failed'],$errors);self::assertSame(0,$calls);
        self::assertFalse($client->isRunning());self::assertSame('stopped',$client->getState());self::assertSame(1,$session->closes);
        $observer=new CredentialStore($this->directory);
        try{$observer->acquire();self::assertTrue($observer->ownsLock());}finally{$observer->close();}
    }
}

final class PluginOwnershipNodeSession implements NodeSession
{
    public int $starts=0;public int $closes=0;
    public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string{++$this->starts;return str_repeat('s',32);}
    public function sendNode(BinaryNode $node):void{}
    public function receiveNode(float $timeoutSeconds=1.0):?BinaryNode{return null;}
    public function close():void{++$this->closes;}
}
