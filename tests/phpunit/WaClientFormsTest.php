<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Protocol\BinaryNode;
use MVO\Service\ServiceException;
use MVO\Transport\NodeSession;
use MVO\WaClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Public facade, connection ownership and native-IQ failures with an isolated node session. */
final class WaClientFormsTest extends TestCase
{
    private string $directory;
    private ?WaClient $client=null;
    private float $time=0;

    protected function setUp():void
    {
        $this->directory=sys_get_temp_dir().'/mvo-forms-client-'.bin2hex(random_bytes(8));
        mkdir($this->directory,0700);
        $credentials=CredentialStore::fresh();$credentials['registered']=true;
        $credentials['meJid']='5511000000000:12@s.whatsapp.net';$credentials['meLid']='123456789@lid';$credentials['serverHasPreKeys']=true;
        $store=new CredentialStore($this->directory);$store->save($credentials);$store->close();
    }

    protected function tearDown():void
    {
        $this->client?->stop();$this->client=null;gc_collect_cycles();
        $files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as$file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}
        rmdir($this->directory);
    }

    private function connected():FormsFacadeNodeSession
    {
        $peer=new FormsFacadeNodeSession(function(float $seconds):void{$this->time+=$seconds;});
        $this->client=new WaClient(authDirectory:$this->directory,sessionFactory:static fn():NodeSession=>$peer,clock:fn():float=>$this->time,automaticMaintenance:false);
        $this->client->connect();$peer->incoming[]=new BinaryNode('success');$this->client->poll(0);
        self::assertTrue($this->client->isConnected());return $peer;
    }

    public function testRequiresConnectedSessionWithoutOpeningTransport():void
    {
        $calls=0;$this->client=new WaClient(authDirectory:$this->directory,sessionFactory:static function()use(&$calls):never{$calls++;throw new \LogicException('Unexpected transport');});
        try{$this->client->forms();self::fail('Disconnected facade accepted');}catch(\LogicException $error){self::assertSame('WhatsApp is not connected',$error->getMessage());}
        self::assertSame(0,$calls);
    }

    public function testLocalCatalogSurvivesReconnectAndDoesNotRequestNativeAuthentication():void
    {
        $peer=$this->connected();$before=count($peer->sent);$forms=$this->client->forms();
        self::assertSame($forms,$this->client->forms());
        $saved=$forms->create(['form_title'=>'Dados do cliente','form_type'=>'CUSTOMER_INFO','message_text'=>'Preencha os dados.','selected_field_list'=>['NAME','EMAIL']]);
        self::assertSame($saved,$forms->get($saved['id']));self::assertSame($before,count($peer->sent));
        self::assertFalse($forms->authenticationStatus()['authenticated']);
        $this->client->stop();$this->connected();$restored=$this->client->forms();
        self::assertNotSame($forms,$restored);self::assertSame($saved,$restored->get($saved['id']));
        self::assertFalse($restored->authenticationStatus()['authenticated']);
    }

    public function testMetadataWithoutCatalogProviderFailsLocallyWithoutMexOrSmbAuthentication():void
    {
        $peer=$this->connected();$before=count($peer->sent);$forms=$this->client->forms();
        try{$forms->getFlowData('5511000000000@s.whatsapp.net','987654321');self::fail('Missing catalog provider accepted');}
        catch(\LogicException $error){self::assertSame('Business form catalog token provider is unavailable or not configured',$error->getMessage());}
        self::assertSame($before,count($peer->sent));self::assertFalse($forms->authenticationStatus()['authenticated']);
    }

    public function testCatalogProviderRotatesInExistingFacadeAndNeverPersistsOpaqueConfiguration():void
    {
        $peer=$this->connected();$before=count($peer->sent);$forms=$this->client->forms();$calls=0;
        $metadata=(new \ReflectionProperty($forms,'metadata'))->getValue($forms);
        $original=(new \ReflectionProperty($metadata,'mex'))->getValue($metadata);
        self::assertInstanceOf(\MVO\Service\BusinessFormCatalogHttpTransport::class,$original);
        // Reuse the actual facade's read-through provider and lifecycle guard with a bounded fake HTTP exchange.
        $provider=(new \ReflectionProperty($original,'tokenProvider'))->getValue($original);
        $guard=(new \ReflectionProperty($original,'authorizationGuard'))->getValue($original);$seen=[];
        $http=new \MVO\Service\BusinessFormCatalogHttpTransport($provider,exchange:static function(string $url,array $headers,string $body)use(&$seen):array{
            $seen[]=json_decode($body,true,128,JSON_THROW_ON_ERROR)['access_token'];return ['status'=>200,'body'=>'{"data":{}}'];
        },authorizationGuard:$guard);
        $this->client->setBusinessFormsCatalogTokenProvider(static function()use(&$calls):string{$calls++;return 'FIXTURE-CATALOG-FIRST';});
        self::assertSame($forms,$this->client->forms());self::assertSame(0,$calls);
        self::assertSame([],$http->getFlowData('123@lid','123'));
        $this->client->setBusinessFormsCatalogTokenProvider(static function()use(&$calls):string{$calls++;return 'FIXTURE-CATALOG-ROTATED';});
        self::assertSame([],$http->getFlowData('123@lid','123'));
        $this->client->setBusinessFormsCatalogTokenProvider(null);
        try{$http->getFlowData('123@lid','123');self::fail('Removed provider reused old configuration');}catch(\LogicException){}
        self::assertSame(['FIXTURE-CATALOG-FIRST','FIXTURE-CATALOG-ROTATED'],$seen);self::assertSame(2,$calls);
        self::assertSame($before,count($peer->sent));self::assertFalse($forms->authenticationStatus()['authenticated']);
        $this->client->stop();
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory,\FilesystemIterator::SKIP_DOTS))as$file){
            if($file->isFile())self::assertStringNotContainsString('FIXTURE-CATALOG',file_get_contents($file->getPathname()));
        }
    }

    public function testCatalogMetadataRetainsWithdrawalAndExpiredFacadeGuardsBeforeProvider():void
    {
        $this->connected();$forms=$this->client->forms();$active=false;$calls=0;
        $this->client->setBusinessFormsCatalogTokenProvider(static function()use(&$calls):string{$calls++;return 'FIXTURE';});
        $this->client->setBusinessFormsGuard(static function()use(&$active):void{if(!$active)throw new \LogicException('Access withdrawn');});
        try{$forms->getFlowData('123@lid','123');self::fail('Metadata read while suspended');}catch(\LogicException $error){self::assertSame('Access withdrawn',$error->getMessage());}
        self::assertSame(0,$calls);$active=true;$this->client->stop();$this->connected();
        try{$forms->getFlowData('123@lid','123');self::fail('Expired facade queried metadata');}catch(\LogicException $error){self::assertStringContainsString('expired',$error->getMessage());}
        self::assertSame(0,$calls);
    }

    public function testNativeAuthenticationRefusalKeepsSessionAndDoesNotCreateRemotePublication():void
    {
        $peer=$this->connected();$peer->smbError='403';$forms=$this->client->forms();
        $saved=$forms->create(['form_title'=>'Dados','form_type'=>'CUSTOMER_INFO','message_text'=>'Preencha','selected_field_list'=>['NAME']]);
        try{$forms->publish($saved['id'],'BR','DENIED');self::fail('Authentication refusal accepted');}
        catch(ServiceException $error){self::assertSame('403',$error->serverCode);}
        self::assertTrue($this->client->isConnected());self::assertSame('rejected',$forms->publicationStatus('DENIED')['state']);
        $native=array_values(array_filter($peer->sent,static fn(BinaryNode $node):bool=>($node->attrs['xmlns']??null)==='fb:thrift_iq'));
        self::assertCount(1,$native);self::assertSame('118',$native[0]->attrs['smax_id']);
        self::assertSame('5511000000000@s.whatsapp.net',$native[0]->attrs['from']);
        self::assertFalse($forms->authenticationStatus()['authenticated']);
    }

    public function testAuthenticationMaterialIsAbsentFromPublicNodeAndBusinessEvents():void
    {
        $peer=$this->connected();$events=[];$business=[];$filtered=[];
        $this->client->on('node',static function(array $event)use(&$events):void{$events[]=$event;});
        $this->client->on('business',static function(array $event)use(&$business):void{$business[]=$event;});
        $this->client->registerIncomingStanzaFilter(static function(BinaryNode $node)use(&$filtered):bool{$filtered[]=$node;return false;});
        $peer->incoming[]=new BinaryNode('notification',['type'=>'business','from'=>'s.whatsapp.net','id'=>'NONCE'],[
            new BinaryNode('wa_ad_account_nonce',[],'PRIVATE-NONCE'),new BinaryNode('profile',['version'=>'1']),
        ]);
        $peer->incoming[]=new BinaryNode('iq',['id'=>'UNSOLICITED','type'=>'result'],[
            new BinaryNode('access_token',[],'PRIVATE-TOKEN'),new BinaryNode('session_cookies',[],'PRIVATE-COOKIES'),
        ]);
        $this->client->poll(0);$this->client->poll(0);
        self::assertCount(2,$events);self::assertTrue($events[0]['redacted']);self::assertTrue($events[1]['redacted']);
        self::assertNull($events[0]['node']->child('wa_ad_account_nonce'));self::assertNotNull($events[0]['node']->child('profile'));
        self::assertNull($events[1]['node']->child('access_token'));self::assertNull($events[1]['node']->child('session_cookies'));
        self::assertCount(1,$business);self::assertSame('profile',$business[0]['action']);self::assertNull($filtered[0]->child('wa_ad_account_nonce'));
    }

    public function testReconnectRetainsAccountCooldownAndInvalidatesOldFacade():void
    {
        $peer=$this->connected();$peer->smbError='429';$old=$this->client->forms();
        try{$old->generate('Gerar um formulário de atendimento');self::fail('Rate limit accepted');}catch(ServiceException $error){self::assertSame('429',$error->serverCode);}
        self::assertSame('cooldown',$old->authenticationStatus()['state']);
        $client=$this->client;$client->stop();$peer->smbError=null;
        $client->connect();$peer->incoming[]=new BinaryNode('success');$client->poll(0);
        self::assertTrue($client->isConnected());$current=$client->forms();
        self::assertNotSame($old,$current);self::assertSame('cooldown',$current->authenticationStatus()['state']);
        self::assertSame(600,$current->authenticationStatus()['retryAfterSeconds']);
        $nativeBefore=count(array_filter($peer->sent,static fn(BinaryNode $node):bool=>($node->attrs['xmlns']??null)==='fb:thrift_iq'));
        try{$current->generate('Novo pedido durante o bloqueio');self::fail('Cooldown bypassed');}catch(ServiceException $error){self::assertSame('429',$error->serverCode);}
        try{$old->generate('Fachada anterior à reconexão');self::fail('Old facade authorized');}catch(\RuntimeException|\LogicException){}
        self::assertSame($nativeBefore,count(array_filter($peer->sent,static fn(BinaryNode $node):bool=>($node->attrs['xmlns']??null)==='fb:thrift_iq')));
    }

    public static function nonceTiming():iterable
    {yield 'nonce before IQ result'=>[true];yield 'nonce after IQ result'=>[false];}

    public function testWithdrawalOnNoncePreventsTokenExchangeAndDoesNotSeizeOtherServices():void
    {
        $peer=$this->connected();$peer->smbSuccess=true;$active=true;
        $this->client->setBusinessFormsGuard(static function()use(&$active):void{if(!$active)throw new \LogicException('Access withdrawn');});
        $forms=$this->client->forms();
        $this->client->on('node',static function(array $event)use(&$active):void{if(($event['node']->attrs['id']??null)==='NATIVE-NONCE')$active=false;});
        try{$forms->generate('Cadastro de teste');self::fail('Withdrawn operation continued');}catch(\LogicException $error){self::assertSame('Access withdrawn',$error->getMessage());}
        $native=array_values(array_filter($peer->sent,static fn(BinaryNode $node):bool=>($node->attrs['xmlns']??null)==='fb:thrift_iq'));
        self::assertCount(1,$native);self::assertSame('118',$native[0]->attrs['smax_id']);self::assertFalse($forms->authenticationStatus()['authenticated']);
        // The guard is limited to forms; a separate ordinary query remains usable.
        self::assertSame('result',$this->client->query(new BinaryNode('iq',['type'=>'get','xmlns'=>'fixture:unrelated']))->attrs['type']);
        $this->client->setBusinessFormsGuard(null);self::assertTrue($this->client->isConnected());
    }

    public function testWithdrawalAfterPublicationPersistsSnapshotAndBlocksMessageWithoutReplay():void
    {
        $peer=$this->connected();$active=true;$publications=0;
        $this->client->setBusinessFormsGuard(static function()use(&$active):void{if(!$active)throw new \LogicException('Access withdrawn');});
        $real=$this->client->forms();$sender=(new \ReflectionProperty($real,'sender'))->getValue($real);
        $store=new \MVO\Signal\SignalStore(':memory:');$catalog=new \MVO\Service\BusinessFormStore($store);
        $forms=new \MVO\Service\BusinessFormsService($catalog,$store,function()use(&$active,&$publications):array{$publications++;$active=false;return ['flowId'=>'987654321','wellVersion'=>'42'];},$sender);
        $form=$forms->create(['form_title'=>'Cadastro','form_type'=>'CUSTOMER_INFO','message_text'=>'Preencha','selected_field_list'=>['NAME']]);$before=count($peer->sent);
        try{$forms->send($form['id'],'5511999999999','BR',[],'withdrawn-publication');self::fail('Message sent after withdrawal');}catch(\LogicException $error){self::assertSame('Access withdrawn',$error->getMessage());}
        self::assertSame('published',$forms->publicationStatus('withdrawn-publication')['state']);self::assertSame('987654321',$forms->publicationStatus('withdrawn-publication')['publication']['flowId']);
        self::assertSame('unknown',$forms->sendStatus('withdrawn-publication')['state']);self::assertSame($before,count($peer->sent));
        $active=true;
        try{$forms->send($form['id'],'5511999999999','BR',[],'withdrawn-publication');self::fail('Interrupted operation replayed');}catch(\LogicException){}
        self::assertSame(1,$publications);self::assertSame($before,count($peer->sent));$store->close();
    }

    public function testExactMessageBoundaryRechecksAfterDiscoveryAndAlwaysReleasesItsScope():void
    {
        $peer=$this->connected();$active=true;$client=$this->client;
        $client->setBusinessFormsGuard(static function()use(&$active):void{if(!$active)throw new \LogicException('Access withdrawn');});
        $client->on('node',static function(array $event)use(&$active):void{if(($event['node']->attrs['id']??null)==='DISCOVERY-FIXTURE')$active=false;});
        $scope=new \ReflectionMethod($client,'guardBusinessFormsMessage');
        try{$scope->invoke($client,'GUARDED-MESSAGE',static function()use($client):array{
            $client->query(new BinaryNode('iq',['id'=>'DISCOVERY-FIXTURE','type'=>'get','xmlns'=>'fixture:discovery']));
            $client->sendNode(new BinaryNode('message',['id'=>'GUARDED-MESSAGE']));return [];
        });self::fail('Stanza escaped after withdrawal');}catch(\LogicException $error){self::assertSame('Access withdrawn',$error->getMessage());}
        self::assertSame([],array_values(array_filter($peer->sent,static fn(BinaryNode $node):bool=>$node->tag==='message')));
        self::assertSame([],(new \ReflectionProperty($client,'businessFormsMessageGuards'))->getValue($client));
        $client->sendNode(new BinaryNode('message',['id'=>'UNRELATED-MESSAGE']));self::assertSame('UNRELATED-MESSAGE',$peer->sent[array_key_last($peer->sent)]->attrs['id']);
    }

    public function testCsvDownloadChecksBeforeAndAfterHttpWithoutPublishingAFileOnWithdrawal():void
    {
        $this->connected();$active=true;$calls=0;
        $this->client->setBusinessFormsGuard(static function()use(&$active):void{if(!$active)throw new \LogicException('Access withdrawn');});
        $forms=$this->client->forms();$exports=(new \ReflectionProperty($forms,'exports'))->getValue($forms);$store=(new \ReflectionProperty($exports,'store'))->getValue($exports);
        $compressed=gzencode("name,answer\nMVO,yes\n");$encrypted=\MVO\Media\MediaCrypto::encrypt($compressed,'document',str_repeat('k',32));
        $transfer=new \MVO\Media\MediaTransfer(static fn():BinaryNode=>throw new \LogicException('Unexpected IQ'),static function()use(&$active,&$calls,$encrypted):array{$calls++;$active=false;return ['status'=>200,'body'=>$encrypted['body']];});
        (new \ReflectionProperty($this->client,'mediaTransfer'))->setValue($this->client,$transfer);
        $record=['requestId'=>'download-guard','flowId'=>'987654321','stanzaId'=>'EXPORT-FIXTURE','state'=>'ready','expiresAt'=>time()+86400,'bundle'=>$encrypted+['mediaType'=>'document','directPath'=>'/v/t62/form-response-fixture']];
        $store->set('galaxy-response-requests',hash('sha256','download-guard'),\MVO\Protocol\BinaryData::encode($record));$destination=$this->directory.'/responses.csv';$active=false;
        try{$forms->downloadResponses('download-guard',$destination);self::fail('Download started without authorization');}catch(\LogicException){}self::assertSame(0,$calls);
        $active=true;
        try{$forms->downloadResponses('download-guard',$destination);self::fail('Downloaded data escaped after withdrawal');}catch(\LogicException){}
        self::assertSame(1,$calls);self::assertFileDoesNotExist($destination);self::assertSame('download_failed',$forms->responseStatus('download-guard')['state']);
    }

    #[DataProvider('nonceTiming')]
    public function testNativeTokenExchangeUsesPrivateStanzasThroughRealClientRouting(bool $beforeResult):void
    {
        $peer=$this->connected();$peer->smbSuccess=true;$peer->nonceBeforeResult=$beforeResult;
        $forms=$this->client->forms();$nodes=[];
        $this->client->on('node',static function(array $event)use(&$nodes):void{$nodes[]=$event;});
        $auth=(new \ReflectionProperty($this->client,'businessFormsAuthentication'))->getValue($this->client);
        self::assertSame('FIXTURE-TOKEN',$auth->token());self::assertTrue($forms->authenticationStatus()['authenticated']);
        $requests=array_values(array_filter($peer->sent,static fn(BinaryNode $node):bool=>($node->attrs['xmlns']??null)==='fb:thrift_iq'));
        self::assertCount(2,$requests);self::assertSame('118',$requests[0]->attrs['smax_id']);self::assertSame('104',$requests[1]->attrs['smax_id']);
        self::assertSame('get',$requests[0]->attrs['type']);self::assertSame('get',$requests[1]->attrs['type']);
        self::assertSame('FIXTURE-NONCE',$requests[1]->child('parameters')->child('code')->content);
        foreach($nodes as$event){self::assertNull($event['node']->child('wa_ad_account_nonce'));self::assertNull($event['node']->child('access_token'));self::assertNull($event['node']->child('session_cookies'));}
        self::assertSame('FIXTURE-TOKEN',$auth->token());
        self::assertCount(2,array_filter($peer->sent,static fn(BinaryNode $node):bool=>($node->attrs['xmlns']??null)==='fb:thrift_iq'));
        $this->client->stop();self::assertFalse($auth->status()['authenticated']);
    }
}

final class FormsFacadeNodeSession implements NodeSession
{
    /** @var list<BinaryNode> */public array $incoming=[];
    /** @var list<BinaryNode> */public array $sent=[];
    public ?string $smbError=null;
    public bool $smbSuccess=false;
    public bool $nonceBeforeResult=false;
    private \Closure $advance;
    public function __construct(callable $advance){$this->advance=\Closure::fromCallable($advance);}
    public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string{return str_repeat('s',32);}
    public function sendNode(BinaryNode $node):void
    {
        $this->sent[]=$node;if($node->tag!=='iq')return;
        if(($node->attrs['xmlns']??null)==='fb:thrift_iq'&&$this->smbError!==null){$this->incoming[]=new BinaryNode('iq',['id'=>$node->attrs['id'],'type'=>'error'],[new BinaryNode('error',['code'=>$this->smbError])]);return;}
        if(($node->attrs['xmlns']??null)==='fb:thrift_iq'&&$this->smbSuccess){
            if(($node->attrs['smax_id']??null)==='118'){
                $nonce=new BinaryNode('notification',['type'=>'business','from'=>'s.whatsapp.net','id'=>'NATIVE-NONCE'],[new BinaryNode('wa_ad_account_nonce',[],'FIXTURE-NONCE')]);
                $result=new BinaryNode('iq',['id'=>$node->attrs['id'],'type'=>'result'],[new BinaryNode('result',['status'=>'Success'])]);
                array_push($this->incoming,...($this->nonceBeforeResult?[$nonce,$result]:[$result,$nonce]));return;
            }
            $this->incoming[]=new BinaryNode('iq',['id'=>$node->attrs['id'],'type'=>'result'],[
                new BinaryNode('business_person',['id'=>'123456789']),new BinaryNode('access_token',[],'FIXTURE-TOKEN'),
                new BinaryNode('session_cookies',[],'[{"name":"c_user","value":"123456789"},{"name":"xs","value":"FIXTURE-COOKIE"}]'),
            ]);return;
        }
        $children=($node->attrs['xmlns']??null)==='w:mex'?[new BinaryNode('result',['format'=>'json'],json_encode(['data'=>['xwa_extensions_get_flow_data'=>['extensions_flow_data'=>[]]]],JSON_THROW_ON_ERROR))]:null;
        $this->incoming[]=new BinaryNode('iq',['id'=>$node->attrs['id'],'type'=>'result'],$children);
    }
    public function receiveNode(float $timeoutSeconds=1.0):?BinaryNode
    {if($this->incoming!==[])return array_shift($this->incoming);($this->advance)($timeoutSeconds);return null;}
    public function close():void{}
}
