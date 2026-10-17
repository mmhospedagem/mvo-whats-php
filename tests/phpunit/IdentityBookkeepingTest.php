<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\AppState\{AppStateProjector,AppStateSchema};
use MVO\History\HistoryStore;
use MVO\Protocol\{BinaryData,BinaryNode};
use MVO\Service\{IdentityService,IncomingEvents};
use MVO\Signal\SignalStore;
use MVO\WaClient;
use PHPUnit\Framework\TestCase;

final class IdentityBookkeepingTest extends TestCase
{
    private SignalStore $store;
    private IdentityService $identities;
    private ?string $directory=null;
    protected function setUp():void{$this->store=new SignalStore(':memory:');$this->identities=new IdentityService($this->store);}
    protected function tearDown():void{$this->store->close();if($this->directory!==null){rmdir($this->directory.'/default');rmdir($this->directory);}}
    private static function node(array $change,array $errors=[],string $op='LidChangeNotification'):BinaryNode{
        return new BinaryNode('notification',['type'=>'mex','from'=>'s.whatsapp.net','id'=>'identity-event'],[new BinaryNode('update',['op_name'=>$op],json_encode(['data'=>['xwa2_notify_lid_change'=>$change],'errors'=>$errors],JSON_THROW_ON_ERROR))]);
    }
    public function testConfirmedPairKeepsCanonicalPhoneAndDelayedMessagesCannotReplaceIt():void{
        $this->identities->confirmPair('551188888888@s.whatsapp.net','5511988888888@s.whatsapp.net','11@lid');
        $this->identities->remember('551188888888@s.whatsapp.net','11@lid');self::assertSame('5511988888888@s.whatsapp.net',$this->identities->resolvePhone('11@lid'));
        $this->identities->remember('551188888888@s.whatsapp.net','22@lid');self::assertSame('11@lid',$this->identities->resolveLid('551188888888@s.whatsapp.net'));
    }
    public function testUnrelatedPhoneCannotTakeOverConfirmedLid():void{
        $this->identities->remember('5511000000001@s.whatsapp.net','11@lid',true);
        try{$this->identities->confirmPair('5511000000002@s.whatsapp.net','5511000000002@s.whatsapp.net','11@lid');self::fail('Unrelated phone accepted');}catch(\UnexpectedValueException){}
        self::assertNull($this->identities->resolveLid('5511000000002@s.whatsapp.net'));self::assertSame('5511000000001@s.whatsapp.net',$this->identities->resolvePhone('11@lid'));
    }
    public function testLidSuccessionsPersistWithoutReversalAndPreserveOutboxAndOldIdentities():void{
        $pn='5511000000001@s.whatsapp.net';$this->identities->remember($pn,'11@lid',true);
        $key="\x05".str_repeat('a',32);$this->store->trustIdentity('11:3@lid',$key);$this->store->set('message-outbox','pending','uncertain-original-ciphertext');
        $this->store->set('privacy-token-records','11@lid',BinaryData::encode(['token'=>'old-address-bound']));
        self::assertTrue($this->identities->changeLid('11@lid','22@lid'));self::assertSame('22@lid',$this->identities->resolveLid($pn));
        self::assertSame($key,$this->store->getIdentity('11:3@lid'));self::assertNull($this->store->getIdentity('22:3@lid'));self::assertNull($this->store->getSession('22:3@lid'));
        self::assertSame('uncertain-original-ciphertext',$this->store->get('message-outbox','pending'));self::assertNull($this->store->get('privacy-token-records','11@lid'));
        self::assertFalse($this->identities->changeLid('11@lid','22@lid'));self::assertFalse($this->identities->changeLid('22@lid','11@lid'));
        self::assertTrue($this->identities->changeLid('22@lid','33@lid'));self::assertFalse($this->identities->changeLid('11@lid','44@lid'));self::assertSame('33@lid',$this->identities->currentLid('11@lid'));
        $this->identities->remember($pn,'11@lid',true);self::assertSame('33@lid',$this->identities->resolveLid($pn));
    }
    public function testTrustedIdentityConflictRollsBackAllLinkageAndTokenChanges():void{
        $pn='5511000000001@s.whatsapp.net';$this->identities->remember($pn,'11@lid',true);
        $this->store->trustIdentity('11:3@lid',str_repeat('a',32));$this->store->trustIdentity('22:3@lid',str_repeat('b',32));$this->store->set('privacy-token-records','11@lid','preserve');
        try{$this->identities->changeLid('11@lid','22@lid');self::fail('Conflicting identity accepted');}catch(\UnexpectedValueException){}
        self::assertSame('11@lid',$this->identities->resolveLid($pn));self::assertSame('11@lid',$this->identities->currentLid('11@lid'));self::assertNull($this->identities->resolvePhone('22@lid'));self::assertSame('preserve',$this->store->get('privacy-token-records','11@lid'));
    }
    public function testStaleNotificationCannotReplaceNewerUsyncMapping():void{
        $pn='5511000000001@s.whatsapp.net';$this->identities->remember($pn,'11@lid',true);$this->identities->remember($pn,'33@lid',true);
        self::assertFalse($this->identities->changeLid('11@lid','22@lid'));self::assertSame('33@lid',$this->identities->resolveLid($pn));self::assertSame('11@lid',$this->identities->currentLid('11@lid'));
    }
    public function testAppStatePnLidSharingAndContactHaveDistinctAuthenticatedProjections():void{
        $history=new HistoryStore($this->store);$projector=new AppStateProjector($this->store,$history);$lid='11@lid';$pn='5511000000001@s.whatsapp.net';
        $projector->apply(AppStateSchema::mutation('PnForLidChat',['lid'=>$lid],['pnJid'=>$pn],1));self::assertSame($lid,$this->identities->resolveLid($pn));self::assertSame($pn,$history->getChat($lid)['phoneNumber']);
        $projector->apply(AppStateSchema::mutation('ShareOwnPn',['lid'=>$lid],[],2));self::assertTrue($history->getChat($lid)['shareOwnPhoneNumber']);self::assertSame(['lidJid'=>$lid,'shared'=>true],BinaryData::decode($this->store->get('identity-share-own-pn',$lid)));
        $projector->apply(AppStateSchema::mutation('LidContact',['id'=>$lid],['fullName'=>'Pessoa','username'=>'pessoa.mvo'],3));self::assertSame('Pessoa',$history->getContact($lid)['displayName']);self::assertSame('pessoa.mvo',$history->getContact($lid)['username']);
        $projector->apply(AppStateSchema::mutation('ShareOwnPn',['lid'=>$lid],null,4,true));self::assertTrue($history->getChat($lid)['shareOwnPhoneNumber']);self::assertSame($pn,$this->identities->resolvePhone($lid));
        $projector->apply(AppStateSchema::mutation('PnForLidChat',['lid'=>$lid],null,5,true));self::assertSame($lid,$this->identities->resolveLid($pn));self::assertSame($pn,$this->identities->resolvePhone($lid));
        $projector->replaceSnapshot('regular',[]);self::assertSame($pn,$this->identities->resolvePhone($lid));self::assertTrue($history->getChat($lid)['shareOwnPhoneNumber']);
    }
    public function testMexParserRecognizesOnlyProvenOperationAndValidExactShape():void{
        $event=IncomingEvents::parse(self::node(['old'=>'11','new'=>'22@hosted.lid']))[0];self::assertSame('mex_notification',$event['event']);self::assertSame('lid_change',$event['data']['kind']);self::assertSame('11@lid',$event['data']['oldLidJid']);self::assertSame('22@lid',$event['data']['newLidJid']);
        $unrelated=IncomingEvents::parse(self::node(['old'=>'11','new'=>'22'],op:'UnrelatedNotification'))[0];self::assertArrayNotHasKey('kind',$unrelated['data']);
        $error=IncomingEvents::parse(self::node(['old'=>'11','new'=>'22'],[['message'=>'denied']]))[0];self::assertArrayNotHasKey('kind',$error['data']);
        foreach([['old'=>'11'],['old'=>'11@lid','new'=>'5511000000001@s.whatsapp.net'],['old'=>[],'new'=>'22'],['old'=>'11','new'=>'name@lid']]as$change){self::assertSame('service_error',IncomingEvents::parse(self::node($change))[0]['event']);}
    }
    public function testWaClientCommitsBookkeepingBeforeEventAndIgnoresReplayedTransitions():void{
        $this->directory=sys_get_temp_dir().'/mvo-identity-events-'.bin2hex(random_bytes(8));
        $client=new WaClient(authDirectory:$this->directory,sessionFactory:static function():never{throw new \LogicException('No WhatsApp connection expected');});$reflection=new \ReflectionClass($client);
        foreach(['running'=>true,'credentials'=>['registered'=>true],'stateStore'=>$this->store]as$name=>$value)$reflection->getProperty($name)->setValue($client,$value);
        $pn='5511000000001@s.whatsapp.net';$this->identities->remember($pn,'11@lid',true);$events=[];
        $client->on('mex_notification',function(array $event)use(&$events,$pn):void{self::assertSame('22@lid',$this->identities->resolveLid($pn));$events[]=$event;});
        $dispatch=$reflection->getMethod('dispatchServiceEvents');$dispatch->invoke($client,self::node(['old'=>'11','new'=>'22']));$dispatch->invoke($client,self::node(['old'=>'11','new'=>'22']));
        self::assertCount(1,$events);self::assertSame('lid_change',$events[0]['kind']);
    }
}
