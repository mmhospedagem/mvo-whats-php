<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\AppState\{AppStateProjector,AppStateSchema,AppStateService};
use MVO\Auth\CredentialStore;
use MVO\History\HistoryStore;
use MVO\Protocol\BinaryNode;
use MVO\Signal\SignalStore;
use MVO\Service\{ProfileService,MexService,ServiceTransport};
use MVO\Transport\NodeSession;
use MVO\WaClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WaClientAccountInfoTest extends TestCase
{
    private string $directory;
    /** @var list<WaClient> */ private array $clients=[];
    /** @var list<SignalStore> */ private array $stores=[];

    protected function setUp():void {
        $this->directory=sys_get_temp_dir().'/mvo-account-info-'.bin2hex(random_bytes(8));
        mkdir($this->directory,0700);
    }
    protected function tearDown():void {
        foreach($this->clients as $client)$client->stop();
        foreach($this->stores as $store)$store->close();
        $this->clients=[];$this->stores=[];gc_collect_cycles();
        $files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}
        rmdir($this->directory);
    }
    private function offlineClient():WaClient {
        $client=new WaClient(authDirectory:$this->directory,sessionFactory:static function():NodeSession{throw new \LogicException('Metadata opened a network session');},
            backendFactory:static function():never{throw new \LogicException('Metadata opened a backend');},
            versionResolver:static function():never{throw new \LogicException('Metadata requested a Web version');});
        $this->clients[]=$client;return $client;
    }
    /** @param array<string,mixed> $credentials */
    private function credentials(WaClient $client,array $credentials):void {(new \ReflectionProperty($client,'credentials'))->setValue($client,$credentials);}

    public function testNewClientReturnsExplicitUnknownFieldsWithoutNetworkOrBackend():void {
        $client=$this->offlineClient();
        self::assertSame(['jid'=>null,'deviceJid'=>null,'lid'=>null,'phoneNumber'=>null,'phoneE164'=>null,'deviceId'=>null,
            'displayName'=>null,'username'=>null,'platform'=>null,'webVersion'=>WaClient::VERSION,'mobileVersion'=>null,'transport'=>'websocket','role'=>null,'registered'=>false,'connected'=>false],$client->accountInfo());
        self::assertFileDoesNotExist($client->sessionDirectory().'/state.sqlite');
        self::assertSame('disconnected',$client->getState());
    }

    /** @return iterable<string,array{mixed,?string,?string,?int}> */
    public static function addresses():iterable {
        yield 'linked PN'=>['5511999999999:12@s.whatsapp.net','5511999999999@s.whatsapp.net','5511999999999',12];
        yield 'primary PN'=>['5511999999999@s.whatsapp.net','5511999999999@s.whatsapp.net','5511999999999',0];
        yield 'hosted PN'=>['5511999999999:99@hosted','5511999999999@s.whatsapp.net','5511999999999',99];
        yield 'LID is not a phone'=>['5511999999999:3@lid','5511999999999@lid',null,3];
        yield 'hosted LID is not a phone'=>['5511999999999:3@hosted.lid','5511999999999@lid',null,3];
        yield 'group is not a phone'=>['5511999999999@g.us','5511999999999@g.us',null,0];
        yield 'short user is not a phone'=>['12345@s.whatsapp.net','12345@s.whatsapp.net',null,0];
        yield 'overlong user is not a phone'=>['1234567890123456@s.whatsapp.net','1234567890123456@s.whatsapp.net',null,0];
        yield 'non numeric user is not a phone'=>['username@s.whatsapp.net','username@s.whatsapp.net',null,0];
        yield 'leading zero is not E164'=>['05511999999999@s.whatsapp.net','05511999999999@s.whatsapp.net',null,0];
        yield 'invalid device'=>['5511999999999:no@s.whatsapp.net',null,null,null];
        yield 'non string'=>[['private'=>'never expose'],null,null,null];
    }
    #[DataProvider('addresses')]
    public function testDeviceAddressesAreCanonicalizedWithoutMistakingLidForPhone(mixed $source,?string $jid,?string $phone,?int $device):void {
        $client=$this->offlineClient();$this->credentials($client,['meJid'=>$source,'meLid'=>'123456789012345:7@hosted.lid','registered'=>true,
            'pairingPhoneNumber'=>'5511888888888','phoneNumber'=>'5511888888888','accountType'=>1,'deviceType'=>1,'noiseKeyPair'=>['private'=>'SECRET']]);
        $info=$client->accountInfo();
        self::assertSame($jid,$info['jid']);self::assertSame($jid===null ? null : $source,$info['deviceJid']);
        self::assertSame($phone,$info['phoneNumber']);self::assertSame($phone===null ? null : '+'.$phone,$info['phoneE164']);
        self::assertSame($device,$info['deviceId']);self::assertSame('123456789012345@lid',$info['lid']);
        self::assertArrayNotHasKey('accountType',$info);self::assertArrayNotHasKey('isBusiness',$info);self::assertStringNotContainsString('SECRET',json_encode($info,JSON_THROW_ON_ERROR));
    }

    public function testOwnLocalAppStateAndPnLidContactsAreWhitelisted():void {
        $client=$this->offlineClient();$this->credentials($client,['meJid'=>'5511999999999:12@s.whatsapp.net','meLid'=>'111222333:12@lid',
            'meDisplayName'=>'Credential name','platform'=>'android','registered'=>true,'signedIdentity'=>'PRIVATE-IDENTITY']);
        $store=new SignalStore($this->directory.'/local.sqlite');$this->stores[]=$store;
        $history=new HistoryStore($store);$projector=new AppStateProjector($store,$history);
        $history->upsertContact(['jid'=>'5511888888888@s.whatsapp.net','pushName'=>'Other contact','username'=>'other.username']);
        $history->upsertContact(['jid'=>'5511999999999@s.whatsapp.net','pushName'=>'PN name','username'=>'']);
        $history->upsertContact(['jid'=>'111222333@lid','pushName'=>'LID name','username'=>'cliente.exemplo','secret'=>'CONTACT-SECRET']);
        (new \ReflectionProperty($client,'stateStore'))->setValue($client,$store);
        (new \ReflectionProperty($client,'appStateData'))->setValue($client,$projector);
        $mutation=AppStateSchema::mutation('SettingPushName',[],['name'=>' Synced own name '],123);
        $projector->apply($mutation);
        $info=$client->accountInfo();self::assertSame('Synced own name',$info['displayName']);self::assertSame('cliente.exemplo',$info['username']);self::assertSame('android',$info['platform']);
        $projector->apply(AppStateSchema::mutation('SettingPushName',[],null,124,true));
        self::assertSame('Credential name',$client->accountInfo()['displayName']);
        $this->credentials($client,['meJid'=>'5511999999999:12@s.whatsapp.net','meLid'=>'111222333@lid']);
        self::assertSame('PN name',$client->accountInfo()['displayName']);
        $this->credentials($client,['meJid'=>'5555999999999@s.whatsapp.net']);
        self::assertNull($client->accountInfo()['displayName']);self::assertNull($client->accountInfo()['username']);
        self::assertStringNotContainsString('CONTACT-SECRET',json_encode($info,JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('PRIVATE-IDENTITY',json_encode($info,JSON_THROW_ON_ERROR));
    }

    public function testOnlyLidContactCanSupplyOwnNameButNotPhone():void {
        $client=$this->offlineClient();$this->credentials($client,['meJid'=>'123456789:4@lid','meLid'=>'123456789@lid']);
        $store=new SignalStore($this->directory.'/local.sqlite');$this->stores[]=$store;
        (new HistoryStore($store))->upsertContact(['jid'=>'123456789@lid','displayName'=>'Own display','username'=>'own.username']);
        (new \ReflectionProperty($client,'stateStore'))->setValue($client,$store);
        $info=$client->accountInfo();self::assertSame('Own display',$info['displayName']);self::assertSame('own.username',$info['username']);
        self::assertNull($info['phoneNumber']);self::assertNull($info['phoneE164']);
    }

    public function testConfirmedPushNameCommandReplacesOlderLocalSettingBeforeAccountSnapshot():void {
        $client=$this->offlineClient();$credentials=['meJid'=>'5511999999999:12@s.whatsapp.net','registered'=>true,'meDisplayName'=>'Older credential name'];
        $this->credentials($client,$credentials);
        $store=new SignalStore($this->directory.'/local.sqlite');$this->stores[]=$store;
        $projector=new AppStateProjector($store,new HistoryStore($store));
        $projector->apply(AppStateSchema::mutation('SettingPushName',[],['name'=>'Older synced name'],123));
        (new \ReflectionProperty($client,'stateStore'))->setValue($client,$store);
        (new \ReflectionProperty($client,'appStateData'))->setValue($client,$projector);
        $queries=0;$presence=[];
        $query=static function(BinaryNode $request)use(&$queries):BinaryNode {
            $queries++;$collection=$request->child('sync')->child('collection');
            self::assertSame('critical_block',$collection->attrs['name']);
            $version=(int)$collection->attrs['version']+($collection->child('patch')===null ? 0 : 1);
            return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('sync',[],[new BinaryNode('collection',['name'=>'critical_block','version'=>(string)$version])])]);
        };
        $appState=new AppStateService($store,$query);$appState->setPrimaryMode(true);$appState->setProjector($projector);
        $appState->importKeys([['keyId'=>'KEY1','keyData'=>str_repeat('k',32),'timestamp'=>1]]);
        $transport=new ServiceTransport($query,static function(BinaryNode $node)use(&$presence):void {$presence[]=$node;},static fn():array=>$credentials);
        $profile=new ProfileService($transport,new MexService($transport),static fn():AppStateService=>$appState,
            function(string $name)use($client,&$credentials):void {$credentials['meDisplayName']=$name;$this->credentials($client,$credentials);});
        self::assertSame('Older synced name',$client->accountInfo()['displayName']);
        $profile->setPushName('New confirmed name');$count=$queries;
        self::assertSame('New confirmed name',$projector->getSetting('SettingPushName')['name']);
        self::assertSame('New confirmed name',$client->accountInfo()['displayName']);
        self::assertSame('New confirmed name',$presence[0]->attrs['name']);self::assertSame($count,$queries);
    }

    public function testPairingInputNeverInventsAccountAndConnectionFlagsReflectCurrentClient():void {
        $client=$this->offlineClient();$this->credentials($client,['registered'=>false,'meJid'=>null,'meLid'=>'5511999999999@s.whatsapp.net',
            'pairingPhoneNumber'=>'5511999999999','phoneNumber'=>'5511999999999','accountType'=>1,'deviceType'=>1]);
        $info=$client->accountInfo();
        self::assertNull($info['jid']);self::assertNull($info['lid']);self::assertNull($info['phoneNumber']);self::assertNull($info['phoneE164']);
        self::assertFalse($info['registered']);self::assertFalse($info['connected']);
        $this->credentials($client,['registered'=>true,'meJid'=>'5511999999999@s.whatsapp.net']);
        (new \ReflectionProperty($client,'state'))->setValue($client,'connected');
        self::assertTrue($client->accountInfo()['connected']);self::assertTrue($client->accountInfo()['registered']);
        (new \ReflectionProperty($client,'state'))->setValue($client,'disconnected');
        self::assertFalse($client->accountInfo()['connected']);self::assertTrue($client->accountInfo()['registered']);
    }

    /** @return iterable<string,array{?string,string,string}> */
    public static function versions():iterable {
        yield 'default without cache'=>[null,WaClient::VERSION,WaClient::VERSION];
        yield 'saved version without update event'=>['2.3000.1045000001',WaClient::VERSION,'2.3000.1045000001'];
        yield 'explicit version overrides saved'=>['2.3000.1045000001','2.3000.1045000002','2.3000.1045000002'];
        yield 'invalid saved version falls back'=>['not-a-version',WaClient::VERSION,WaClient::VERSION];
    }
    #[DataProvider('versions')]
    public function testActiveVersionIsReportedEvenWhenNoVersionUpdatedEventOccurs(?string $saved,string $configured,string $expected):void {
        $credentials=CredentialStore::fresh();$credentials['registered']=true;$credentials['meJid']='5511999999999:12@s.whatsapp.net';
        if($saved!==null)$credentials['webVersion']=$saved;
        $store=new CredentialStore($this->directory);$store->save($credentials);$store->close();
        $peer=new AccountInfoNodeSession();$client=new WaClient(authDirectory:$this->directory,version:$configured,sessionFactory:static fn():NodeSession=>$peer,automaticMaintenance:false);
        $this->clients[]=$client;$updates=[];$client->on('version_updated',static function(array $event)use(&$updates):void{$updates[]=$event;});
        $client->connect();$sent=count($peer->sent);$info=$client->accountInfo();
        self::assertSame($expected,$info['webVersion']);self::assertTrue($info['registered']);self::assertFalse($info['connected']);
        self::assertSame($sent,count($peer->sent));self::assertSame([],$updates);
    }

    public function test405RecoveryReportsNewActiveVersionBeforeLoginWithoutExtraQueries():void {
        $credentials=CredentialStore::fresh();$credentials['registered']=true;$credentials['meJid']='5511999999999:12@s.whatsapp.net';
        $store=new CredentialStore($this->directory);$store->save($credentials);$store->close();
        $peer=new AccountInfoNodeSession();$resolved=0;
        $client=new WaClient(authDirectory:$this->directory,sessionFactory:static fn():NodeSession=>$peer,
            versionResolver:static function()use(&$resolved):string{$resolved++;return '2.3000.1045000003';},automaticMaintenance:false);
        $this->clients[]=$client;$client->connect();$peer->incoming[]=new BinaryNode('failure',['reason'=>'405']);$client->poll(0);
        $sent=count($peer->sent);$info=$client->accountInfo();
        self::assertSame('2.3000.1045000003',$info['webVersion']);self::assertSame(1,$resolved);self::assertSame('reconnecting',$client->getState());
        self::assertTrue($info['registered']);self::assertFalse($info['connected']);self::assertSame($sent,count($peer->sent));
    }
}

final class AccountInfoNodeSession implements NodeSession
{
    /** @var list<BinaryNode> */ public array $incoming=[];
    /** @var list<BinaryNode> */ public array $sent=[];
    public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string{return str_repeat('s',32);}
    public function sendNode(BinaryNode $node):void {$this->sent[]=$node;}
    public function receiveNode(float $timeoutSeconds=1.0):?BinaryNode{return array_shift($this->incoming);}
    public function close():void {}
}
