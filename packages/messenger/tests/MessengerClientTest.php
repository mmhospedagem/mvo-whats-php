<?php
declare(strict_types=1);
namespace MVO\Messenger\Tests;

use MVO\Messenger\Auth\{CookieJar,SessionStore};
use MVO\Messenger\MessengerClient;
use MVO\Messenger\MessengerException;
use MVO\Messenger\Protocol\DgwCodec;
use MVO\Messenger\Transport\{HttpClient,HttpResponse,Socket};
use PHPUnit\Framework\TestCase;

final class MessengerClientTest extends TestCase
{
    private array $directories=[];
    private array $clients=[];
    protected function tearDown():void
    {
        foreach($this->clients as $client)$client->stop();unset($client);$this->clients=[];gc_collect_cycles();
        foreach($this->directories as $root){$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $entry){if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($root);}
    }
    private function root():string{$root=sys_get_temp_dir().'/mvo-messenger-client-'.bin2hex(random_bytes(8));mkdir($root,0700);$this->directories[]=$root;return $root;}
    private function client(string $root,?MessengerPeer $peer=null,?HttpClient $http=null):MessengerClient
    {
        $peer??=new MessengerPeer();$http??=new MessengerBootstrapHttp();
        $client=new MessengerClient('demo',$root,$http,static function(string $url,string $origin,array $headers)use($peer):Socket{
            self::assertStringStartsWith('wss://gateway.facebook.com/ws/lightspeed?',$url);parse_str(parse_url($url,PHP_URL_QUERY),$query);
            self::assertSame('5',$query['x-dgw-version']);self::assertSame('12345678901234567',$query['x-dgw-uuid']);self::assertSame('1:0',$query['x-dgw-authtype']);
            self::assertSame('https://www.facebook.com',$origin);self::assertStringContainsString('xs=SYNTHETIC',$headers['Cookie']);return $peer;
        },static fn(array $urls):array=>[],.05);
        $this->clients[]=$client;return $client;
    }
    private function import(MessengerClient $client):void{$client->importCookies(CookieJar::fromJson('{"c_user":"12345678901234567","xs":"SYNTHETIC","datr":"SYNTHETIC"}'));}
    public function testNativeProtocolBootstrapSyncSendAndReceivePreserveIdsAndAckScope():void
    {
        $client=$this->client($this->root());$this->import($client);$events=[];$client->on('message',static function(array $event)use(&$events):void{$events[]=$event;});
        $client->connect();self::assertTrue($client->isConnected());self::assertSame('metadata_connected',$client->getState());self::assertFalse($client->accountInfo()['e2ee_ready']);
        self::assertCount(2,$client->threads());$result=$client->sendText('10001','Olá teste 🚀');
        self::assertSame('server_ack',$result['status']);self::assertSame('mid.synthetic.42',$result['id']);self::assertFalse($result['delivery_confirmed']);
        self::assertSame('Olá teste 🚀',$events[0]['text']);self::assertSame('12345678901234567',$events[0]['sender_id']);self::assertSame('10001',$events[0]['thread_id']);
        self::assertSame('server_ack',$client->outgoing($result['offline_id'])['state']);self::assertNotEmpty($client->events());
    }
    public function testEncryptedAndUnknownConversationsNeverFallbackToPlaintext():void
    {
        $peer=new MessengerPeer();$client=$this->client($this->root(),$peer);$this->import($client);$client->connect();$before=$peer->tasks;
        foreach(['10015'=>'messenger_e2ee_not_ready','999'=>'unknown_thread'] as $id=>$reason){try{$client->sendText((string)$id,'Must not be sent');self::fail('Expected explicit unsupported send');}catch(MessengerException $error){self::assertSame($reason,$error->reason);}}
        self::assertSame($before,$peer->tasks);
    }
    public function testSharedWebSessionKeepsTheOriginalOwnerAndCannotOutliveTheConnection():void
    {
        $client=$this->client($this->root());$this->import($client);$client->connect();$context=$client->webSession();
        self::assertSame('12345678901234567',$context->accountId());
        foreach(['https://unrelated.example/api/graphql/','https://www.facebook.com/logout/','https://business.facebook.com/api/graphql/?redirect=other'] as $url){
            try{$context->request('POST',$url,[]);self::fail('Expected endpoint rejection');}catch(\InvalidArgumentException){}
        }
        try{json_encode($context,JSON_THROW_ON_ERROR);self::fail('A private context cannot be serialized');}catch(\LogicException){}
        $client->stop();
        try{$context->privateRequestFields();self::fail('Stopped session cannot expose live request fields');}
        catch(MessengerException $error){self::assertSame('not_connected',$error->reason);}
    }
    public function testNativeVideoBoundaryChecksIdentityEndpointsAndOwnerBeforeUsingOptionalHttpTransport():void
    {
        $http=new class implements \MVO\Messenger\Transport\RawUploadHttpClient{
            public array $calls=[];
            public function request(string $method,string $url,CookieJar $cookies,array $form=[]):HttpResponse{return (new MessengerBootstrapHttp())->request($method,$url,$cookies,$form);}
            public function requestWithHeaders(string $method,string $url,CookieJar $cookies,array $form=[],array $headers=[]):HttpResponse{$this->calls[]=[$method,$url,$cookies->accountId(),$form,$headers];return new HttpResponse(200,'{}');}
            public function uploadRange(string $url,CookieJar $cookies,\MVO\Messenger\Transport\UploadFile $file,int $offset,int $length,array $headers):HttpResponse{$this->calls[]=['POST',$url,$cookies->accountId(),$offset,$length];return new HttpResponse(200,'{}');}
        };
        $client=$this->client($this->root(),http:$http);$this->import($client);$client->connect();$session=$client->webSession();
        $start='https://vupload-edge.facebook.com/ajax/video/upload/requests/start/?av=20002';
        $session->requestWithHeaders('POST',$start,['__user'=>'12345678901234567'],['X_FB_VIDEO_WATERFALL_ID'=>'SYNTHETIC']);
        self::assertSame('12345678901234567',$http->calls[0][2]);
        foreach([[$start,['__user'=>'20002']],[$start.'&extra=1',['__user'=>'12345678901234567']],['https://attacker.invalid/upload',['__user'=>'12345678901234567']]] as [$url,$form]){
            try{$session->requestWithHeaders('POST',$url,$form);self::fail('Invalid identity or route');}catch(\InvalidArgumentException){self::assertCount(1,$http->calls);}
        }
        $client->stop();
        try{$session->requestWithHeaders('POST',$start,['__user'=>'12345678901234567']);self::fail('Stopped upload owner');}
        catch(MessengerException $error){self::assertSame('not_connected',$error->reason);self::assertCount(1,$http->calls);}
    }
    public function testRestoredCutoverLinkBlocksLegacyThreadBeforeAnySendTask():void
    {
        $root=$this->root();$peer=new MessengerPeer();$client=$this->client($root,$peer);$this->import($client);
        $store=new SessionStore($root,'demo');$store->acquire();
        $calls=[['name'=>'LSCreateOpenToE2EEThreadLink','args'=>['10001','20002',true,'1792356000000']]];
        $store->transaction(fn()=>$store->saveBatch(hash('sha256',json_encode($calls,JSON_THROW_ON_ERROR)),$calls));$store->release();
        $client->connect();self::assertTrue($client->isConnected());$before=$peer->tasks;
        try{$client->sendText('10001','Never send on the stale plaintext route');self::fail('Expected E2EE refusal');}
        catch(MessengerException $error){self::assertSame('messenger_e2ee_not_ready',$error->reason);}
        self::assertSame($before,$peer->tasks);self::assertSame('20002',$client->threads()[0]['encrypted_thread_id']);unset($store);
    }
    public function testAckWithoutApplicationResultRemainsUncertainAndIsNotAutomaticallyRetried():void
    {
        $peer=new MessengerPeer();$peer->ackOnly=true;$client=$this->client($this->root(),$peer);$this->import($client);$client->connect();
        try{$client->sendText('10001','Only once');self::fail('Expected uncertain outcome');}catch(MessengerException $error){self::assertSame('send_outcome_uncertain',$error->reason);}
        self::assertCount(1,$peer->tasks);$event=array_values(array_filter($client->events(),static fn(array $e):bool=>$e['type']==='send_uncertain'))[0];
        self::assertSame('uncertain',$client->outgoing($event['payload']['offline_id'])['state']);$client->poll(0);self::assertCount(1,$peer->tasks);
    }
    public function testRestartRestoresSameSessionAndContinuesPersistedCursors():void
    {
        $root=$this->root();$one=$this->client($root);$this->import($one);$one->connect();$one->stop();$peer=new MessengerPeer();$two=$this->client($root,$peer);$two->connect();
        self::assertTrue($two->isConnected());self::assertSame('cursor-1',$peer->syncRequests[0]['last_applied_cursor']);self::assertSame(2,$peer->syncTypes[0]);
    }
    public function testSyncParamsUseTheirOwnDatabaseOnRestoreAndContinuation():void
    {
        $expected=[1=>'{"group":"mailbox"}',2=>'{"group":"contact"}',95=>'{"group":"e2ee"}',104=>'{"group":"community"}'];
        $root=$this->root();$peer=new MessengerPeer();$peer->syncOverrides=['send_params'=>true];$client=$this->client($root,$peer);$this->import($client);
        $client->connect();for($i=0;$i<32;$i++)$client->poll(0);
        $seen=[];foreach($peer->syncRequests as $request){self::assertSame($expected[$request['database']],$request['sync_params']);$seen[$request['database']]=true;}
        self::assertSame(array_keys($expected),array_keys($seen));$client->stop();
        $restoredPeer=new MessengerPeer();$restored=$this->client($root,$restoredPeer);$restored->connect();
        foreach(array_slice($restoredPeer->syncRequests,0,4) as $request){self::assertNotNull($request['last_applied_cursor']);self::assertSame($expected[$request['database']],$request['sync_params']);}
    }
    public function testExactCommittedDgwMailboxReplayStaysConnectedWithoutDuplicateEffects():void
    {
        $root=$this->root();$peer=new MessengerPeer();$client=$this->client($root,$peer);$this->import($client);
        $published=[];$client->on('thread',static function(array $event)use(&$published):void{$published[]=$event;});
        $client->connect();for($i=0;$i<32;$i++)$client->poll(0);
        $events=$client->events();$threads=$client->threads();$callbacks=$published;$requests=count($peer->syncRequests);
        $store=new SessionStore($root,'demo');$cursor=$store->get('cursor.1');
        $peer->replayFirstMailboxResponse();$peer->replayFirstMailboxResponse();
        for($i=0;$i<8;$i++)$client->poll(0);
        self::assertTrue($client->isConnected());self::assertSame('metadata_connected',$client->getState());
        self::assertSame($events,$client->events());self::assertSame($threads,$client->threads());self::assertSame($callbacks,$published);
        self::assertSame($cursor,$store->get('cursor.1'));self::assertSame($requests,count($peer->syncRequests));unset($store);
    }
    public function testListenersCannotStartCommandsInsideProtocolCallbacks():void
    {
        $client=$this->client($this->root());$this->import($client);$client->connect();$guarded=false;
        $client->on('message',static function(array $event)use($client,&$guarded):void{try{$client->refreshThreads();}catch(\LogicException){$guarded=true;}});
        $client->sendText('10001','Callback');self::assertTrue($guarded);
    }
    public function testListenerFailureDoesNotDowngradeCommittedServerAcknowledgment():void
    {
        $client=$this->client($this->root());$this->import($client);$client->connect();
        $client->on('message',static function(array $event):void{throw new \RuntimeException('SYNTHETIC_PRIVATE_CALLBACK_ERROR');});
        $sent=$client->sendText('10001','Callback failure');self::assertSame('server_ack',$sent['status']);
        self::assertSame('server_ack',$client->outgoing($sent['offline_id'])['state']);
        $events=$client->events();self::assertCount(1,array_filter($events,static fn(array $event):bool=>$event['type']==='listener_error'));
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE_CALLBACK_ERROR',json_encode($events,JSON_THROW_ON_ERROR));
    }
    public function testStoppingDuringConnectionCallbackPreventsEvenBootstrapNetworkIo():void
    {
        $http=new class implements HttpClient{public int $requests=0;public function request(string $method,string $url,CookieJar $cookies,array $form=[]):HttpResponse{$this->requests++;throw new \RuntimeException('Unexpected network request');}};
        $client=$this->client($this->root(),http:$http);$this->import($client);
        $client->on('connection',static function(array $event)use($client):void{if($event['state']==='authenticating')$client->stop();});
        $client->connect();self::assertSame(0,$http->requests);self::assertFalse($client->isConnected());self::assertSame('stopped',$client->getState());
    }
    public function testAnotherSessionOwnerRejectsPasswordLoginBeforeAnyAuthenticationRequest():void
    {
        $root=$this->root();$client=$this->client($root);$owner=new SessionStore($root,'demo');$owner->acquire();
        $transport=new class implements \MVO\Messenger\Transport\WebLoginTransport {
            public int $requests=0;
            public function request(string $method,string $url,array $form=[]):\MVO\Messenger\Transport\WebLoginResponse{$this->requests++;throw new \LogicException('Authentication must not begin');}
            public function cookies():array{return [];}
            public function clear():void{}
        };
        try{
            $client->login('person@example.invalid','SYNTHETIC_PASSWORD',new \MVO\Messenger\Auth\WebLoginService($transport));
            self::fail('Expected session ownership conflict');
        }catch(\RuntimeException $error){self::assertStringContainsString('active owner',$error->getMessage());}
        finally{$owner->release();unset($owner);}
        self::assertSame(0,$transport->requests);
    }
    public function testEmptyMailboxPayloadDoesNotProveInitialSyncCompleted():void
    {
        $peer=new MessengerPeer();$peer->emptySync=true;$client=$this->client($this->root(),$peer);$this->import($client);
        try{$client->connect();self::fail('Expected initial sync timeout');}catch(MessengerException $error){self::assertSame('initial_sync_timeout',$error->reason);}
        self::assertFalse($client->isConnected());
    }
    public function testServerSyncFailureIsExplicitAndNeverLeaksRemoteMessage():void
    {
        $root=$this->root();$peer=new MessengerPeer();$peer->syncOverrides=['failure'=>true];$client=$this->client($root,$peer);$this->import($client);
        try{$client->connect();self::fail('Expected explicit synchronization failure');}
        catch(MessengerException $error){self::assertSame('messenger_sync_failed',$error->reason);self::assertStringNotContainsString('SYNTHETIC_PRIVATE_SYNC_ERROR',$error->getMessage());}
        self::assertFalse($client->isConnected());self::assertTrue($client->accountInfo()['has_saved_session']);
        $store=new SessionStore($root,'demo');self::assertNull($store->get('cursor.1'));self::assertSame([],$store->threads());
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE_SYNC_ERROR',json_encode($store->events(),JSON_THROW_ON_ERROR));
        $store->acquire();self::assertTrue($store->ownsLock());$store->release();unset($store);
    }
    public function testExplicitNullCannotMatchTheSeededUndefinedInitialCursor():void
    {
        $root=$this->root();$peer=new MessengerPeer();$peer->syncOverrides=['cursor'=>null];$client=$this->client($root,$peer);$this->import($client);
        try{$client->connect();self::fail('A wire null must not match an uninitialized local cursor');}
        catch(MessengerException $error){self::assertSame('sync_guard_rejected',$error->reason);}
        $store=new SessionStore($root,'demo');self::assertSame('undefined',$store->get('sync_cursor_kind.1'));
        self::assertNull($store->get('cursor.1'));self::assertSame([],$store->threads());unset($store);
    }
    public function testSyncGuardRejectsEpochAndCursorBeforeAnyProjectionEscapes():void
    {
        foreach([['epoch'=>'999'],['cursor'=>'wrong-cursor'],['procedure'=>'LSExecuteFirstBlockForSyncTransaction','epoch'=>'999']] as $override){
            $root=$this->root();$peer=new MessengerPeer();$peer->syncOverrides=$override;
            $client=$this->client($root,$peer);$this->import($client);
            $store=new SessionStore($root,'demo');$store->acquire();$store->set('cursor.1','cursor-original');$store->release();
            $published=[];$client->on('thread',static function(array $event)use(&$published):void{$published[]=$event;});
            try{$client->connect();self::fail('A rejected sync must not become connected');}
            catch(MessengerException $error){self::assertSame(isset($override['procedure'])?'unsupported_lightspeed_result':'sync_guard_rejected',$error->reason);}
            self::assertFalse($client->isConnected());self::assertSame(isset($override['procedure'])?'unsupported_lightspeed_result':'sync_guard_rejected',$client->getState());
            self::assertSame('cursor-original',$store->get('cursor.1'));self::assertSame([],$store->threads());self::assertSame([],$published);
            self::assertSame([],array_values(array_filter($store->events(),static fn(array $event):bool=>in_array($event['type'],['thread','message','message_ack'],true))));
            $store->acquire();self::assertTrue($store->ownsLock());$store->release();unset($store);
        }
    }
    public function testCapturedEpochZeroPreloadCommitsItsCursorAndEvents():void
    {
        $root=$this->root();
        $http=new class implements HttpClient{
            public function request(string $method,string $url,CookieJar $cookies,array $form=[]):HttpResponse{
                $base=(new MessengerBootstrapHttp())->request($method,$url,$cookies,$form);
                $calls=[
                    [4,0,1,[5,'LSExecuteFirstBlockForSyncTransactionV4',1,[19,'0'],[9],'prefetch-cursor',0,1,false,0,false,1]],
                    [5,'LSDeleteThenInsertThread',1792356000000,0,'','Prefetched epoch zero',null,false,0,'10009',0,1],
                    [5,'LSExecuteFinallyBlockForSyncTransaction',true,1,[19,'0']],
                ];
                $preload=['expectedPreloaders'=>[['variables'=>['requestPayload'=>json_encode(['database'=>1,'epoch_id'=>'0','last_applied_cursor'=>null,'version'=>'987654321'],JSON_THROW_ON_ERROR)]]],
                    'result'=>['payload'=>json_encode(['step'=>[1,...$calls]],JSON_THROW_ON_ERROR),'dependencies'=>[]]];
                return new HttpResponse(200,$base->body.'<script type="application/json">'.json_encode($preload,JSON_THROW_ON_ERROR).'</script>');
            }
        };
        $client=$this->client($root,http:$http);$this->import($client);$client->connect();
        self::assertTrue($client->isConnected());self::assertSame('metadata_connected',$client->getState());
        self::assertSame('Prefetched epoch zero',$client->threads()[0]['name']);
        $store=new SessionStore($root,'demo');self::assertSame('prefetch-cursor',$store->get('cursor.1'));
        self::assertCount(1,array_filter($store->events(),static fn(array $event):bool=>$event['type']==='thread'));unset($store);
    }
    public function testRestoredCacheIgnoresEmptyCachePreloadsAndSendsItsOwnCursor():void
    {
        $http=new class implements HttpClient{
            public function request(string $method,string $url,CookieJar $cookies,array $form=[]):HttpResponse{
                $base=(new MessengerBootstrapHttp())->request($method,$url,$cookies,$form);
                $preload=['expectedPreloaders'=>[['variables'=>['requestPayload'=>json_encode(['database'=>1,'epoch_id'=>'0','last_applied_cursor'=>null,'version'=>'987654321'],JSON_THROW_ON_ERROR)]]],
                    'result'=>['payload'=>json_encode(['step'=>[105,'stale empty-cache instruction']],JSON_THROW_ON_ERROR),'dependencies'=>[]]];
                return new HttpResponse(200,$base->body.'<script type="application/json">'.json_encode($preload,JSON_THROW_ON_ERROR).'</script>');
            }
        };
        $freshPeer=new MessengerPeer();$fresh=$this->client($this->root(),$freshPeer,$http);$this->import($fresh);
        try{$fresh->connect();self::fail('A fresh cache must validate its preloaded instructions');}
        catch(MessengerException $error){self::assertSame('unsupported_lightspeed_opcode',$error->reason);}
        self::assertSame([],$freshPeer->syncRequests);
        $root=$this->root();$peer=new MessengerPeer();$restored=$this->client($root,$peer,$http);$this->import($restored);
        $store=new SessionStore($root,'demo');$store->acquire();$store->set('cursor.1','saved-incremental-cursor');$store->release();
        $restored->connect();self::assertTrue($restored->isConnected());self::assertSame(2,$peer->syncTypes[0]);
        self::assertSame('saved-incremental-cursor',$peer->syncRequests[0]['last_applied_cursor']);
        self::assertSame('cursor-1',$store->get('cursor.1'));unset($store);
    }
    public function testLoginRedirectCannotBecomeAConnectedSessionOrLeakCredentials():void
    {
        $http=new class implements HttpClient{public function request(string $method,string $url,CookieJar $cookies,array $form=[]):HttpResponse{return new HttpResponse(302,'PRIVATE',['location'=>['https://www.facebook.com/login/']]);}};
        $client=$this->client($this->root(),http:$http);$this->import($client);
        try{$client->connect();self::fail('Expected login requirement');}catch(MessengerException $error){self::assertSame('session_expired',$error->reason);self::assertStringNotContainsString('SYNTHETIC',$error->getMessage());}
        self::assertFalse($client->isConnected());self::assertFalse($client->accountInfo()['e2ee_ready']);
    }
    public function testMessengerCookiesStayOnTheirRealOriginAndSelectMessengerGateway():void
    {
        $root=$this->root();$peer=new MessengerPeer();
        $http=new class implements HttpClient{
            public array $urls=[];
            public function request(string $method,string $url,CookieJar $cookies,array $form=[]):HttpResponse{
                $this->urls[]=$url;
                TestCase::assertSame('https://www.messenger.com/',$url);TestCase::assertSame('',$cookies->headerFor('https://www.facebook.com/'));
                return (new MessengerBootstrapHttp())->request($method,$url,$cookies,$form);
            }
        };
        $client=new MessengerClient('demo',$root,$http,static function(string $url,string $origin,array $headers)use($peer):Socket{
            self::assertStringStartsWith('wss://gateway.messenger.com/ws/lightspeed?',$url);self::assertSame('https://www.messenger.com',$origin);self::assertStringContainsString('xs=SYNTHETIC',$headers['Cookie']);return $peer;
        },static fn(array $urls):array=>[],.05);$this->clients[]=$client;
        $client->importCookies(new CookieJar([['name'=>'c_user','value'=>'12345678901234567','domain'=>'.messenger.com'],['name'=>'xs','value'=>'SYNTHETIC','domain'=>'.messenger.com']]));
        $client->connect();self::assertTrue($client->isConnected());self::assertSame(['https://www.messenger.com/'],$http->urls);
    }
    public function testProjectionFailureRollsBackBothMessageAndCursor():void
    {
        $root=$this->root();$store=new SessionStore($root,'rollback');$store->acquire();
        $calls=[['name'=>'LSExecuteFirstBlockForSyncTransactionV4','args'=>[1,'42','','next',0,1,false,0,false,1]],
            ['name'=>'LSDeleteThenInsertThread','args'=>[1,0,'','Synthetic',null,false,0,'10001',0,1]],
            ['name'=>'LSExecuteFinallyBlockForSyncTransaction','args'=>[true,1,'999']]];
        try{(new \MVO\Messenger\Protocol\EventProjector($store,'123'))->apply($calls);self::fail('Expected transaction mismatch');}catch(MessengerException $error){self::assertSame('invalid_sync_transaction',$error->reason);}
        self::assertNull($store->get('cursor.1'));self::assertSame([],$store->threads());self::assertSame([],$store->events());$store->release();unset($store);
    }
}

final class MessengerBootstrapHttp implements HttpClient
{
    public function request(string $method,string $url,CookieJar $cookies,array $form=[]):HttpResponse
    {
        $modules=['CurrentUserInitialData'=>['USER_ID'=>'12345678901234567','APP_ID'=>'987123','NAME'=>'Synthetic test account'],
            'DGWWebConfig'=>['appId'=>'456789','appVersion'=>'0'],'MqttWebDeviceID'=>['clientID'=>'test-device'],
            'LSVersion'=>'987654321','LSPlatformMessengerSyncParams'=>['mailbox'=>'{"group":"mailbox"}','contact'=>'{"group":"contact"}','e2ee'=>'{"group":"e2ee"}','communityExclusive'=>'{"group":"community"}']];$definitions=[];
        foreach($modules as $name=>$value)$definitions[]=[$name,[],$value,0];
        return new HttpResponse(200,'<script type="application/json">'.json_encode(['require'=>[['ScheduledServerJS','handle',null,[['__bbox'=>['define'=>$definitions]]]]]],JSON_THROW_ON_ERROR).'</script>');
    }
}

final class MessengerPeer implements Socket
{
    private bool $active=false;
    private array $incoming=[];
    public array $tasks=[];
    public array $syncRequests=[];
    public array $syncTypes=[];
    public bool $ackOnly=false;
    public bool $emptySync=false;
    public array $syncOverrides=[];
    private ?string $firstMailboxResponse=null;
    public function replayFirstMailboxResponse():void
    {
        $this->incoming[]=$this->firstMailboxResponse??throw new \LogicException('No mailbox response was recorded');
    }
    public function connect():void{$this->active=true;}
    public function close():void{$this->active=false;}
    public function isConnected():bool{return $this->active;}
    public function receive(float $timeoutSeconds=.5):?string{return array_shift($this->incoming);}
    public function send(string $payload):void
    {
        foreach(DgwCodec::decode($payload) as $frame){
            $type=$frame['type'];$stream=$frame['stream_id']??0;
            if($type===DgwCodec::ESTABLISH)$this->incoming[]=DgwCodec::encode(['type'=>DgwCodec::ESTABLISH,'stream_id'=>$stream,'parameters'=>200]);
            elseif($type===DgwCodec::DATA){
                $this->incoming[]=DgwCodec::encode(['type'=>DgwCodec::ACK,'stream_id'=>$stream,'ack_id'=>$frame['ack_id']]);
                $outer=json_decode($frame['payload'],true,64,JSON_THROW_ON_ERROR);$request=json_decode($outer['payload'],true,64,JSON_THROW_ON_ERROR);$calls=[];
                if($outer['app_id']!=='987123')throw new \RuntimeException('Lightspeed and gateway app IDs were confused');
                if(in_array($outer['type'],[1,2],true)){
                    $this->syncRequests[]=$request;$this->syncTypes[]=$outer['type'];$db=$request['database'];$cursor=$request['last_applied_cursor'];$epoch=(string)$request['epoch_id'];
                    $responseEpoch=$this->syncOverrides['epoch']??$epoch;
                    $responseCursor=array_key_exists('cursor',$this->syncOverrides)?$this->syncOverrides['cursor']:($cursor===null?[9]:$cursor);
                    $calls[]=[5,$this->syncOverrides['procedure']??'LSExecuteFirstBlockForSyncTransactionV4',$db,$responseEpoch,$responseCursor,'cursor-'.$db,0,1,$this->syncOverrides['send_params']??false,0,false,1];
                    if($db===1)foreach(['10001'=>1,'10015'=>15] as $id=>$threadType)$calls[]=[5,'LSDeleteThenInsertThread',1792356000000,0,'','Synthetic thread',null,false,0,(string)$id,0,$threadType];
                    $calls[]=[5,'LSExecuteFinallyBlockForSyncTransaction',$this->syncOverrides['clear_epoch']??true,$db,$responseEpoch];
                    if($this->emptySync)$calls=[];
                    if(($this->syncOverrides['failure']??false)===true)$calls=[[5,'LSHandleSyncFailure',$db,'SYNTHETIC_PRIVATE_SYNC_ERROR']];
                }else{
                    foreach($request['tasks']??[] as $task){
                        $args=json_decode($task['payload'],true,64,JSON_THROW_ON_ERROR);
                        if($task['label']==='46'){
                            $this->tasks[]=$args;if($this->ackOnly)continue 2;
                            $calls[]=[5,'LSReplaceOptimsiticMessage',$args['otid'],'mid.synthetic.42'];
                            $row=array_fill(0,24,null);$row[0]=$args['text'];$row[3]=(string)$args['thread_id'];$row[5]=1792356000000;$row[8]='mid.synthetic.42';$row[9]=$args['otid'];$row[10]='12345678901234567';$row[12]=false;$row[17]=false;
                            $calls[]=[5,'LSUpsertMessage',...$row];
                        }
                    }
                }
                $response=['request_id'=>$outer['request_id'],'payload'=>json_encode(['step'=>[1,...$calls]],JSON_THROW_ON_ERROR),'sp'=>[]];
                $encoded=DgwCodec::encode(['type'=>DgwCodec::DATA,'stream_id'=>$stream,'ack_id'=>0,'requires_ack'=>true,'payload'=>json_encode($response,JSON_THROW_ON_ERROR)]);
                if(in_array($outer['type'],[1,2],true)&&($request['database']??null)===1)$this->firstMailboxResponse??=$encoded;
                $this->incoming[]=$encoded;
            }
        }
    }
}
