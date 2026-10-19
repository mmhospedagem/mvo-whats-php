<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Storage\SelectionStore;
use PHPUnit\Framework\TestCase;

final class SelectionStoreTest extends TestCase
{
    public function testSelectionsAreExplicitAccountScopedAndRestoredWithoutChangingTheOwner():void
    {
        $root=sys_get_temp_dir().'/mvo-facebook-selection-'.bin2hex(random_bytes(8));mkdir($root,0700);$path=$root.'/selection.sqlite';
        try{
            $one=new SelectionStore($path,'10001');$two=new SelectionStore($path,'20002');
            $one->remember('page',[['id'=>'30003','name'=>'Synthetic Page','actor_id'=>'30003','delegate_page_id'=>'40004']]);
            self::assertNull($one->selected('page'));
            try{$two->select('page','30003');self::fail('Another account must not inherit a choice');}catch(FacebookException $error){self::assertSame('facebook_resource_not_discovered',$error->reason);}
            $one->select('page','30003');self::assertSame('40004',$one->selected('page')['delegate_page_id']);
            self::assertNull($one->selected('ad_account'));unset($one);$restored=new SelectionStore($path,'10001');self::assertSame('30003',$restored->selected('page')['actor_id']);
            $restored->remember('page',[['id'=>'50005','name'=>'New partial result']]);self::assertSame('30003',$restored->selected('page')['id']);
        }finally{if(isset($one))$one->close();if(isset($two))$two->close();if(isset($restored))$restored->close();unset($one,$two,$restored);gc_collect_cycles();foreach(glob($path.'*')?:[] as $file)unlink($file);rmdir($root);}
    }
    public function testMalformedListIsAtomicAndCannotSelectAnUnconfirmedResource():void
    {
        $root=sys_get_temp_dir().'/mvo-facebook-selection-'.bin2hex(random_bytes(8));mkdir($root,0700);$path=$root.'/selection.sqlite';
        try{
            $store=new SelectionStore($path,'10001');
            try{$store->remember('ad_account',[['id'=>'20002','name'=>'Valid synthetic account'],['id'=>'invalid','name'=>'Invalid']]);self::fail('Expected malformed resource');}catch(\InvalidArgumentException){}
            try{$store->select('ad_account','20002');self::fail('A rolled back resource cannot be selected');}catch(FacebookException $error){self::assertSame('facebook_resource_not_discovered',$error->reason);}
        }finally{if(isset($store))$store->close();unset($store);gc_collect_cycles();foreach(glob($path.'*')?:[] as $file)unlink($file);rmdir($root);}
    }
}
