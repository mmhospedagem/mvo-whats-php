<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Plugin\{PluginContext,PluginDefinition,PluginHost};
use MVO\WaClient;

final class PluginHostTest extends TestCase
{
    public function testOnceReentrantAndOff():void {
        $dir=sys_get_temp_dir().'/mvo-plugin-'.bin2hex(random_bytes(6)); $client=new WaClient(authDirectory:$dir); $events=[];
        $client->once('sample',function(array $e) use($client,&$events):void { $events[]=$e; $client->emitEvent('sample',['recursive'=>true]); });
        $client->emitEvent('sample',['once'=>true]); self::assertSame([['once'=>true]],$events);
        unset($client); rmdir($dir.'/default'); rmdir($dir);
    }
    public function testDisposeRemovesHandlersAndRunsReverseOrderEvenAfterFailure():void {
        $dir=sys_get_temp_dir().'/mvo-plugin-'.bin2hex(random_bytes(6)); $client=new WaClient(authDirectory:$dir); $seen=[];
        $host=new PluginHost([
            new PluginDefinition('one',function(PluginContext $ctx) use(&$seen):string { $ctx->once('event',function() use(&$seen):void { $seen[]='event'; }); $ctx->registerDispose(function() use(&$seen):void { $seen[]='cleanup1'; }); return 'instance'; },function() use(&$seen):void { $seen[]='dispose1'; }),
            new PluginDefinition('two',fn()=>null,function() use(&$seen):void { $seen[]='dispose2'; throw new \RuntimeException('dispose error'); }),
        ]);
        $host->install($client); self::assertSame('instance',$host->get('one'));
        try { $host->dispose(); self::fail(); } catch (\RuntimeException $e) { self::assertSame('dispose error',$e->getMessage()); }
        $client->emitEvent('event'); self::assertSame(['dispose2','dispose1','cleanup1'],$seen);
        $host->dispose(); unset($client); rmdir($dir.'/default'); rmdir($dir);
    }
    public function testSetupFailureUnwindsEarlierPlugins():void {
        $dir=sys_get_temp_dir().'/mvo-plugin-'.bin2hex(random_bytes(6)); $client=new WaClient(authDirectory:$dir); $clean=[];
        $host=new PluginHost([new PluginDefinition('one',fn()=>1,function() use(&$clean):void { $clean[]='one'; }),new PluginDefinition('two',function(PluginContext $ctx) use(&$clean):never { $ctx->registerDispose(function() use(&$clean):void { $clean[]='two'; }); throw new \RuntimeException('setup error'); })]);
        try { $host->install($client); self::fail(); } catch (\RuntimeException) { self::assertSame(['two','one'],$clean); }
        unset($client); rmdir($dir.'/default'); rmdir($dir);
    }
}
