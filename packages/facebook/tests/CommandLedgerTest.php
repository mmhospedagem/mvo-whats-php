<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Storage\CommandLedger;
use MVO\Facebook\Protocol\{Document,DocumentRegistry,WebGraphQL};
use MVO\Messenger\Auth\WebSession;
use MVO\Messenger\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

final class CommandLedgerTest extends TestCase
{
    public function testConcurrentHandlesAndRestartNeverReplayAnUncertainMutation():void
    {
        $root=sys_get_temp_dir().'/mvo-facebook-ledger-'.bin2hex(random_bytes(8));mkdir($root,0700);$path=$root.'/commands.sqlite';
        try{
            $one=new CommandLedger($path);$two=new CommandLedger($path);
            $first=$one->reserve('10001','posts.create','20002',['message'=>'Synthetic post'],'same-key');self::assertTrue($first['execute']);
            $one->finish($first['id'],'unknown');$repeated=$two->reserve('10001','posts.create','20002',['message'=>'Synthetic post'],'same-key');
            self::assertFalse($repeated['execute']);self::assertSame($first['id'],$repeated['id']);self::assertSame('unknown',$repeated['state']);
            unset($one,$two);$restored=new CommandLedger($path);self::assertFalse($restored->reserve('10001','posts.create','20002',['message'=>'Synthetic post'],'same-key')['execute']);
            try{$restored->reserve('10001','posts.create','20002',['message'=>'Different text'],'same-key');self::fail('Expected conflicting content');}
            catch(FacebookException $error){self::assertSame('facebook_idempotency_conflict',$error->reason);}
            self::assertTrue($restored->reserve('10001','posts.create','30003',['message'=>'Synthetic post'],'same-key')['execute']);
            self::assertTrue($restored->reserve('40004','posts.create','20002',['message'=>'Synthetic post'],'same-key')['execute']);
            $restored->finish($first['id'],'confirmed',['id'=>'50005']);$restored->finish($first['id'],'unknown');
            self::assertSame('confirmed',$restored->get($first['id'])['state']);self::assertSame(['id'=>'50005'],$restored->get($first['id'])['result']);
        }finally{if(isset($one))$one->close();if(isset($two))$two->close();if(isset($restored))$restored->close();unset($one,$two,$restored);gc_collect_cycles();foreach(glob($path.'*')?:[] as $file)unlink($file);rmdir($root);}
    }
    public function testObjectKeyOrderDoesNotChangeTheCommandIdentity():void
    {
        $root=sys_get_temp_dir().'/mvo-facebook-ledger-'.bin2hex(random_bytes(8));mkdir($root,0700);$path=$root.'/commands.sqlite';
        try{
            $ledger=new CommandLedger($path);$first=$ledger->reserve('10001','ads.preview',null,['name'=>'Synthetic','budget'=>['currency'=>'BRL','amount'=>1000]],'key');
            $second=$ledger->reserve('10001','ads.preview',null,['budget'=>['amount'=>1000,'currency'=>'BRL'],'name'=>'Synthetic'],'key');
            self::assertSame($first['id'],$second['id']);self::assertFalse($second['execute']);
        }finally{if(isset($ledger))$ledger->close();unset($ledger);gc_collect_cycles();foreach(glob($path.'*')?:[] as $file)unlink($file);rmdir($root);}
    }
    public function testMutationRecordsUncertaintyBeforeIoAndNeverResendsTheSameCommand():void
    {
        $root=sys_get_temp_dir().'/mvo-facebook-ledger-'.bin2hex(random_bytes(8));mkdir($root,0700);$path=$root.'/commands.sqlite';
        try{
            $ledger=new CommandLedger($path);
            $session=new class implements WebSession{
                public int $calls=0;
                public function accountId():string{return '10001';}
                public function privateRequestFields():array{return ['__user'=>'10001','fb_dtsg'=>'SYNTHETIC'];}
                public function request(string $method,string $url,array $form=[]):HttpResponse{$this->calls++;throw new \RuntimeException('SYNTHETIC_PARTIAL_WRITE');}
            };
            $document=new Document('SyntheticCreatePostMutation','123456789','mutation',['input'],[],str_repeat('a',64),'https://static.xx.fbcdn.net/rsrc.php/v4/aa/r/synthetic.js');
            $graph=new WebGraphQL($session,new DocumentRegistry([$document]),$ledger);$id=null;
            try{$graph->execute($document->name,['input'=>['message'=>'Synthetic']],'20002',idempotencyKey:'key',projector:static fn(array $data):array=>[]);self::fail('Expected uncertain I/O');}
            catch(FacebookException $error){self::assertSame('unknown',$error->outcome);self::assertNotNull($error->commandId);$id=$error->commandId;}
            self::assertSame('unknown',$ledger->get($id)['state']);
            try{$graph->execute($document->name,['input'=>['message'=>'Synthetic']],'20002',idempotencyKey:'key',projector:static fn(array $data):array=>[]);self::fail('Uncertain command must not be repeated');}
            catch(FacebookException $error){self::assertSame('facebook_command_uncertain',$error->reason);self::assertSame($id,$error->commandId);}
            self::assertSame(1,$session->calls);
        }finally{if(isset($ledger))$ledger->close();unset($graph,$session,$ledger);gc_collect_cycles();foreach(glob($path.'*')?:[] as $file)unlink($file);rmdir($root);}
    }
}
