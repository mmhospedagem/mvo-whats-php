<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\{CredentialStore,PairingFlow};
use MVO\Mobile\CompanionCrypto;
use MVO\Protocol\BinaryNode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PairingCodeRearmTest extends TestCase
{
    public static function staleResults():iterable
    {
        yield 'old success'=>[false];yield 'old failure'=>[true];
    }
    #[DataProvider('staleResults')]
    public function testSupersededRearmCannotOverwriteOrDropNewManualCode(bool $failOld):void
    {
        $credentials=CredentialStore::fresh();$helloCount=0;$finishes=0;$hellos=[];$events=[];$flow=null;
        $flow=new PairingFlow(static function()use(&$credentials):array{return $credentials;},
            static function(array $next)use(&$credentials):void{$credentials=$next;},static function():void{},
            static function(BinaryNode $node)use(&$flow,&$helloCount,&$finishes,&$hellos,$failOld):BinaryNode{
                if(($node->child('link_code_companion_reg')->attrs['stage']??'')==='companion_finish'){++$finishes;return new BinaryNode('iq',['type'=>'result']);}
                $index=++$helloCount;$hellos[$index]=$node;
                if($index===2){$flow->requestCode('5511999999999','EFGH1234');if($failOld)throw new \RuntimeException('Stale hello timed out');}
                return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('link_code_companion_reg',[],[new BinaryNode('link_code_pairing_ref',[],'ref-'.$index)])]);
            },static function(string $event,array $payload)use(&$events):void{$events[]=[$event,$payload];});
        $flow->requestCode('5511999999999','ABCD1234');$flow->handleNode(self::pairDevice());$flow->drainPending();
        self::assertSame('EFGH1234',$flow->pendingCodeForReconnect()['code']);
        self::assertSame([],array_values(array_filter($events,static fn(array $event):bool=>$event[0]==='auth_code_refresh')));
        $flow->handleNode(self::primaryHello('ref-2','ABCD1234',$hellos[2]));self::assertSame(0,$finishes);
        $flow->handleNode(self::primaryHello('ref-3','EFGH1234',$hellos[3]));self::assertSame(1,$finishes);
    }
    public function testAnExpiredOrFutureSnapshotNeverRegistersACode():void
    {
        $credentials=CredentialStore::fresh();$queries=0;
        foreach([819.0,1001.0] as$createdAt){
            $flow=new PairingFlow(static fn():array=>$credentials,static function():void{},static function():void{},
                static function()use(&$queries):BinaryNode{++$queries;throw new \RuntimeException('No request expected');},static function():void{},clock:static fn():float=>1000.0);
            $flow->restorePendingCode(['code'=>'ABCD1234','phoneJid'=>'5511999999999@s.whatsapp.net','createdAt'=>$createdAt]);
            $flow->handleNode(self::pairDevice());$flow->drainPending();self::assertNull($flow->pendingCodeForReconnect());
        }
        self::assertSame(0,$queries);
    }
    private static function pairDevice():BinaryNode
    {
        return new BinaryNode('iq',['id'=>'PD','type'=>'set','from'=>'s.whatsapp.net'],[new BinaryNode('pair-device',[],[new BinaryNode('ref',[],'qr-ref')])]);
    }
    private static function primaryHello(string $ref,string $code,BinaryNode $hello):BinaryNode
    {
        $primary=CredentialStore::fresh();$prepared=CompanionCrypto::preparePrimaryHello($code,$hello->child('link_code_companion_reg')->child('link_code_pairing_wrapped_companion_ephemeral_pub')->content);
        return new BinaryNode('notification',['id'=>'PH-'.$ref,'from'=>'s.whatsapp.net','type'=>'link_code_companion_reg'],[
            new BinaryNode('link_code_companion_reg',['stage'=>'primary_hello'],[
                new BinaryNode('link_code_pairing_ref',[],$ref),
                new BinaryNode('link_code_pairing_wrapped_primary_ephemeral_pub',[],$prepared['wrappedPrimaryEphemeralPub']),
                new BinaryNode('primary_identity_pub',[],$primary['identityKeyPair']['public']),
            ]),
        ]);
    }
}
