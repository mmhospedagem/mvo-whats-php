<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Protocol\BinaryNode;
use MVO\Service\{IdentityService,MexService,ProfileService,ServiceTransport};
use MVO\Signal\SignalStore;
use PHPUnit\Framework\TestCase;

final class IdentityResolutionTest extends TestCase
{
    private SignalStore $store;
    private array $queries=[];
    protected function setUp():void{$this->store=new SignalStore(':memory:');}
    protected function tearDown():void{$this->store->close();}
    private function profile(callable $lookup):ProfileService{
        $transport=new ServiceTransport(function(BinaryNode $node)use($lookup):BinaryNode{$this->queries[]=$node;return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('usync',[],[new BinaryNode('list',[],$lookup($node))])]);},static function():void{},static fn():array=>[]);
        $transport->configureIdentities(new IdentityService($this->store));
        return new ProfileService($transport,new MexService($transport));
    }
    private static function user(string $jid,?string $type,?string $lid=null,?string $echo=null,array $attrs=[]):BinaryNode{
        return new BinaryNode('user',['jid'=>$jid]+$attrs,[...($type===null?[]:[new BinaryNode('contact',['type'=>$type],$echo)]),...($lid===null?[]:[new BinaryNode('lid',['val'=>$lid])])]);
    }
    public function testBatchCorrelatesReorderedResultsByValueAndPreservesCanonicalAliases():void{
        $query='551188888888@s.whatsapp.net';$canonical='5511988888888@s.whatsapp.net';$other='5584999999999@s.whatsapp.net';
        $profile=$this->profile(static fn():array=>[self::user($other,'in','22@lid'),self::user($canonical,'in','11@lid','+551188888888')]);
        $result=$profile->getLidsByPhoneNumbers(['+55 (11) 8888-8888',$other]);
        self::assertCount(1,$this->queries);$usync=$this->queries[0]->child('usync');self::assertNotNull($usync->child('query')->child('contact'));self::assertNotNull($usync->child('query')->child('lid'));
        self::assertSame(['queriedJid'=>$query,'phoneJid'=>$canonical,'lidJid'=>'11@lid','exists'=>true,'invalid'=>false,'jid'=>'11@lid','pn'=>$canonical,'lid'=>'11@lid','status'=>'in'],$result[0]);
        self::assertSame($other,$result[1]['queriedJid']);self::assertSame('22@lid',$result[1]['lidJid']);
        $identities=new IdentityService($this->store);self::assertSame('11@lid',$identities->resolveLid($query));self::assertSame('11@lid',$identities->resolveLid($canonical));self::assertSame($canonical,$identities->resolvePhone('11@lid'));
    }
    public function testBrazilianCorrectionWithoutEchoUsesOnlyUniqueServerReturnedCanonicalNumber():void{
        $profile=$this->profile(static fn():array=>[self::user('5511988888888@s.whatsapp.net','in','11@lid')]);
        $result=$profile->getLidsByPhoneNumbers(['551188888888']);
        self::assertSame('551188888888@s.whatsapp.net',$result[0]['queriedJid']);self::assertSame('5511988888888@s.whatsapp.net',$result[0]['phoneJid']);self::assertTrue($result[0]['exists']);
        self::assertSame('+551188888888',$this->queries[0]->child('usync')->child('list')->child('user')->child('contact')->content);
    }
    public function testInvalidItemsDoNotAbortOrEnterNetworkBatchAndDuplicatesKeepInputOrder():void{
        $profile=$this->profile(static fn():array=>[self::user('5584999999999@s.whatsapp.net','in','22@lid')]);
        $result=$profile->getLidsByPhoneNumbers(['bad-phone','5584999999999','22@lid','5584999999999',null,'0123456789','5584999999999:2@s.whatsapp.net']);
        self::assertCount(7,$result);self::assertCount(1,$this->queries);self::assertCount(1,$this->queries[0]->child('usync')->child('list')->content);
        foreach([0,2,4,5,6]as$i){self::assertTrue($result[$i]['invalid']);self::assertFalse($result[$i]['exists']);self::assertNull($result[$i]['lidJid']);}
        self::assertSame($result[1],$result[3]);self::assertSame('22@lid',$result[1]['lidJid']);
    }
    public function testServerOutInvalidAndUnknownAreDistinctAndDoNotFabricateMapping():void{
        $profile=$this->profile(static fn():array=>[
            self::user('5511000000001@s.whatsapp.net','out','11@lid'),self::user('5511000000002@s.whatsapp.net','invalid','12@lid'),
            self::user('5511000000003@s.whatsapp.net',null,'13@lid'),new BinaryNode('user',['jid'=>'5511000000004@s.whatsapp.net'],[new BinaryNode('error',['code'=>'500'])]),
        ]);
        $rows=$profile->getLidsByPhoneNumbers(['5511000000001','5511000000002','5511000000003','5511000000004','5511000000005']);
        self::assertFalse($rows[0]['exists']);self::assertFalse($rows[0]['invalid']);self::assertNull($rows[0]['lidJid']);
        self::assertFalse($rows[1]['exists']);self::assertTrue($rows[1]['invalid']);self::assertNull($rows[1]['lidJid']);
        self::assertNull($rows[2]['exists']);self::assertSame('13@lid',$rows[2]['lidJid']);
        foreach([3,4]as$i){self::assertNull($rows[$i]['exists']);self::assertFalse($rows[$i]['invalid']);self::assertNull($rows[$i]['lidJid']);}
        self::assertNull((new IdentityService($this->store))->resolveLid('5511000000001@s.whatsapp.net'));
    }
    public function testUnrelatedAndConflictingDuplicateRowsCannotPoisonMappings():void{
        $profile=$this->profile(static fn():array=>[self::user('5511000000001@s.whatsapp.net','in','11@lid'),self::user('5511000000001@s.whatsapp.net','in','12@lid'),self::user('5511000000009@s.whatsapp.net','in','99@lid')]);
        $row=$profile->getLidsByPhoneNumbers(['5511000000001'])[0];
        self::assertNull($row['exists']);self::assertNull($row['lidJid']);self::assertNull((new IdentityService($this->store))->resolveLid($row['queriedJid']));
    }
    public function testForeignEchoCannotOverrideMatchingJidAndLidRoutingCanCarryExplicitCanonicalPn():void{
        $pn='5511000000001@s.whatsapp.net';
        $profile=$this->profile(static fn():array=>[self::user($pn,'in','11@lid','+5511000000009')]);$row=$profile->getLidsByPhoneNumbers([$pn])[0];self::assertNull($row['exists']);self::assertNull($row['lidJid']);
        $profile=$this->profile(static fn():array=>[self::user('11@lid','in','11@lid',attrs:['pn_jid'=>$pn])]);$row=$profile->getLidsByPhoneNumbers([$pn])[0];self::assertTrue($row['exists']);self::assertSame($pn,$row['phoneJid']);self::assertSame('11@lid',$row['lidJid']);
    }
    public function testEmptyOrEntirelyMalformedBatchDoesNotOpenTransport():void{
        $profile=$this->profile(static function():never{throw new \LogicException('No network expected');});
        self::assertSame([],$profile->getLidsByPhoneNumbers([]));self::assertTrue($profile->getLidsByPhoneNumbers(['bad'])[0]['invalid']);self::assertSame([],$this->queries);
    }
    public function testLargeBatchIsBoundedAndResultsStayCorrelatedAcrossChunks():void{
        $profile=$this->profile(static function(BinaryNode $node):array{$rows=[];foreach(array_reverse($node->child('usync')->child('list')->content)as$user)$rows[]=self::user($user->attrs['jid'],'in');return $rows;});
        $numbers=[];for($i=0;$i<405;$i++)$numbers[]=(string)(5511000000000+$i);
        $rows=$profile->getLidsByPhoneNumbers($numbers);self::assertCount(3,$this->queries);self::assertCount(405,$rows);
        foreach($rows as$i=>$row){self::assertSame($numbers[$i].'@s.whatsapp.net',$row['queriedJid']);self::assertTrue($row['exists']);}
    }
}
