<?php
declare(strict_types=1);
namespace MVO\Messenger;

use MVO\Messenger\Auth\CookieJar;
use MVO\Messenger\Auth\SessionStore;
use MVO\Messenger\Auth\WebLoginService;
use MVO\Messenger\Protocol\{BootstrapConfig,BootstrapParser,EventProjector,LightspeedDecoder,LightspeedSyncGuard,TaskBuilder};
use MVO\Messenger\Transport\{CurlHttpClient,DgwConnection,HttpClient,PublicBundleLoader,Socket,WebSocket};

/** Experimental Facebook Web client. Device E2EE is a separate, unimplemented capability. */
final class MessengerClient
{
    private SessionStore $store;
    private HttpClient $http;
    private ?BootstrapConfig $bootstrap=null;
    private ?DgwConnection $connection=null;
    private ?\Closure $socketFactory;
    private \Closure $bundleLoader;
    private string $state='stopped';
    private bool $inCallback=false;
    private bool $running=false;
    private ?WebLoginService $loginService=null;
    private bool $loginOwnsLock=false;
    private int $requestId=0;
    private int $taskId=0;
    /** @var array<string,list<callable(array<string,mixed>):void>> */ private array $listeners=[];
    /** @var array<int,int> */ private array $syncStreams=[];
    /** @var array<int,?string> */ private array $requestedCursors=[];
    /** @var array<int,bool> */ private array $synced=[];
    /** @var array<int,string> */ private array $pendingSync=[];

    /** Factories are explicit isolated-test seams; no target browser runtime is used.
     * @param callable(string,string,array<string,string>):Socket|null $socketFactory
     * @param callable(list<string>):list<string>|null $bundleLoader
     */
    public function __construct(
        public readonly string $sessionId,
        string $authDirectory,
        ?HttpClient $httpClient=null,
        ?callable $socketFactory=null,
        ?callable $bundleLoader=null,
        private readonly float $timeoutSeconds=20.0,
    ) {
        if(!is_finite($timeoutSeconds)||$timeoutSeconds<=0||$timeoutSeconds>120)throw new \InvalidArgumentException('Invalid Messenger timeout');
        $this->store=new SessionStore($authDirectory,$sessionId);
        $this->http=$httpClient??new CurlHttpClient($timeoutSeconds);
        $this->socketFactory=$socketFactory===null?null:\Closure::fromCallable($socketFactory);
        $this->bundleLoader=$bundleLoader===null?(new PublicBundleLoader())->loadVersionBundles(...):\Closure::fromCallable($bundleLoader);
    }
    public function channel():string{return 'messenger';}
    /** Optional Facebook modules share this owner instead of copying credentials.
     * Private context: never publish fields or HTTP response bodies indiscriminately.
     */
    public function webSession():\MVO\Messenger\Auth\WebSession
    {
        $this->requireConnected();$account=$this->bootstrap->userId;
        return new \MVO\Messenger\Auth\BoundWebSession($account,
            function():array{$this->requireConnected();return $this->bootstrap?->requestFields()??[];},
            function(string $method,string $url,array $form):\MVO\Messenger\Transport\HttpResponse{
                $this->requireConnected();
                $parts=parse_url($url);$host=$parts['host']??'';$path=$parts['path']??'/';
                if(($parts['scheme']??null)!=='https'||!in_array($host,['www.facebook.com','business.facebook.com','adsmanager.facebook.com'],true)||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||(isset($parts['port'])&&$parts['port']!==443))throw new \InvalidArgumentException('Unsupported Facebook Web origin');
                if($method==='POST'){
                    if($path!=='/api/graphql/'||isset($parts['query']))throw new \InvalidArgumentException('Unsupported Facebook Web operation endpoint');
                }elseif($method==='GET'){
                    if(!in_array($path,['/pages/','/professional_dashboard/','/latest/home','/latest/inbox/all','/adsmanager/manage/'],true)||$form!==[])throw new \InvalidArgumentException('Unsupported Facebook Web document route');
                }else throw new \InvalidArgumentException('Unsupported Facebook Web method');
                $cookies=$this->store->loadCookies()??throw new MessengerException('session_required','Restore the Facebook session before using its modules');
                $header=$cookies->headerFor($url);
                if(!preg_match('/(?:^|; )c_user=([^;]+)/',$header,$identity)||$identity[1]!==$cookies->accountId()||!preg_match('/(?:^|; )xs=[^;]+/',$header))throw new MessengerException('facebook_scope_unavailable','The saved cookies do not authenticate this Facebook Web origin');
                return $this->http->request($method,$url,$cookies,$form);
            });
    }
    public function provider():string{return 'mvo_protocol';}
    /** @return list<string> */
    public function capabilities():array{return ['session.email_password','session.cookie_import','connection.persistent','threads.metadata','message.text.unencrypted','events.unencrypted'];}
    public function on(string $event,callable $handler):static{$this->listeners[$event][]=$handler;return $this;}
    public function isConnected():bool{return $this->state==='metadata_connected'&&($this->connection?->isConnected()??false);}
    public function getState():string{return $this->state;}
    /** @return array<string,mixed> */
    public function accountInfo():array
    {
        $challenge=$this->loginChallenge();
        $identity=$this->bootstrap?->module('CurrentUserInitialData');
        return ['channel'=>$this->channel(),'provider'=>$this->provider(),'session_id'=>$this->sessionId,
            'account_id'=>$this->bootstrap!==null?$this->bootstrap->userId:$this->store->get('account_id'),'display_name'=>is_array($identity)&&is_string($identity['NAME']??null)?$identity['NAME']:null,
            'state'=>$this->state,'connected'=>$this->isConnected(),'transport'=>'dgw_websocket',
            'lightspeed_version'=>$this->bootstrap?->versionId,'e2ee_ready'=>false,'has_saved_session'=>$this->store->get('account_id')!==null,
            'capabilities'=>$this->capabilities(),'login_challenge'=>$challenge];
    }
    public function importCookies(#[\SensitiveParameter] CookieJar $cookies):void
    {
        $this->outsideCallback();
        if($this->isConnected())throw new \LogicException('Stop the Messenger client before importing a session');
        if($this->loginChallenge()!==null)throw new MessengerException('login_verification_pending','Complete or cancel the pending login before importing another session');
        $owner=$this->store->ownsLock();if(!$owner)$this->store->acquire();
        try{$this->store->saveCookies($cookies);$this->state='session_imported';}finally{if(!$owner)$this->store->release();}
    }
    /** Browser-password login runs in PHP. Only the resulting session is persisted. */
    public function login(#[\SensitiveParameter] string $email,#[\SensitiveParameter] string $password,?WebLoginService $service=null):void
    {
        $this->outsideCallback();
        if($this->isConnected())throw new \LogicException('Stop the Messenger client before signing in again');
        $owner=$this->store->ownsLock();$release=$this->loginOwnsLock||!$owner;$keep=false;
        try{
            // A conflicting owner must reject this operation before remote
            // authentication changes or creates any account session.
            if(!$owner)$this->store->acquire();
            $this->loginService?->cancel();$this->loginService=$service??new WebLoginService();
            $cookies=$this->loginService->login($email,$password);$this->importCookies($cookies);
        }catch(MessengerException $error){
            $challenge=$this->loginService?->challenge();
            if($challenge!==null){$keep=true;$this->loginOwnsLock=$release;$this->changeState($challenge['type']==='captcha'?'awaiting_captcha':'awaiting_two_factor');}
            throw $error;
        }finally{
            if($password!=='')sodium_memzero($password);
            if(!$keep){$this->loginService?->cancel();$this->loginService=null;$this->loginOwnsLock=false;if($release)$this->store->release();}
        }
    }
    /** @return array{type:string,expires_at:int,ready:bool}|null */
    public function loginChallenge():?array
    {
        $challenge=$this->loginService?->challenge();
        if($this->loginService!==null&&$challenge===null&&in_array($this->state,['awaiting_two_factor','awaiting_captcha'],true)){
            $this->state='login_challenge_expired';$this->loginService=null;
            if($this->loginOwnsLock){$this->store->release();$this->loginOwnsLock=false;}
        }
        return $challenge;
    }
    public function confirmLogin(#[\SensitiveParameter] string $code):void
    {
        $this->outsideCallback();
        try{
            if($this->isConnected())throw new \LogicException('Stop the Messenger connection before confirming another login');
            if($this->loginChallenge()===null||$this->loginService===null)throw new MessengerException('login_challenge_expired','Start a new login through the local form');
            // Import checks may clear the facade's completed challenge reference.
            // Keep the service that owns this confirmation until cleanup finishes.
            $service=$this->loginService;
            if(!$this->store->ownsLock()){$this->store->acquire();$this->loginOwnsLock=true;}
            try{$this->importCookies($service->confirm($code));}
            finally{
                $pending=$service->challenge();
                if($pending===null){$service->cancel();$this->loginService=null;if($this->loginOwnsLock)$this->store->release();$this->loginOwnsLock=false;if(in_array($this->state,['awaiting_two_factor','awaiting_captcha'],true))$this->state='login_challenge_expired';}
                else{$nextState=$pending['type']==='captcha'?'awaiting_captcha':'awaiting_two_factor';if($nextState!==$this->state)$this->changeState($nextState);}
            }
        }finally{if($code!=='')sodium_memzero($code);}
    }
    /** Explicit private application handoff; never serialize into a public response.
     * @return array{url:string,cookies:list<array<string,mixed>>,expires_at:int}
     */
    public function exportPrivateLoginChallenge():array
    {
        $this->outsideCallback();
        if($this->loginChallenge()===null||$this->loginService===null)throw new MessengerException('login_challenge_expired','Start a new login before opening its browser verification');
        if(!$this->store->ownsLock())throw new \LogicException('The pending login no longer owns its session');
        return $this->loginService->exportPrivateChallenge();
    }
    /** Accepts only a browser session independently verified over HTTPS by this client. */
    public function completeBrowserLogin(#[\SensitiveParameter] CookieJar $cookies):void
    {
        $this->outsideCallback();
        if($this->isConnected())throw new \LogicException('Stop the connection before completing another login');
        if($this->loginChallenge()===null||$this->loginService===null)throw new MessengerException('login_challenge_expired','The pending browser verification has expired');
        if(!$this->store->ownsLock())throw new \LogicException('The pending login no longer owns its session');
        $html=$this->loadBootstrap($cookies,$this->sessionOrigin($cookies));
        $identity=(new \MVO\Messenger\Auth\WebLoginPageParser())->userId($html);
        if($identity===null)throw new MessengerException('login_unconfirmed','Facebook has not confirmed this browser session yet');
        if($identity!==$cookies->accountId())throw new MessengerException('login_identity_mismatch','The browser session identity does not match the Facebook response');
        $this->store->saveCookies($cookies);
        $this->loginService->cancel();$this->loginService=null;
        $this->state='session_imported';
        if($this->loginOwnsLock)$this->store->release();$this->loginOwnsLock=false;
    }
    public function connect():void
    {
        $this->outsideCallback();if($this->isConnected())return;
        if($this->loginChallenge()!==null)throw new MessengerException('login_verification_pending','Complete or cancel the pending login before restoring a saved session');
        $this->store->acquire();$this->running=true;$this->syncStreams=[];$this->synced=[];$this->pendingSync=[];$this->requestedCursors=[];
        try{
            (new EventProjector($this->store,$this->store->get('account_id')??''))->restoreRouting();
            $this->changeState('authenticating');
            if(!$this->running)return;
            try{$cookies=$this->store->loadCookies();}catch(\InvalidArgumentException|\JsonException){throw new MessengerException('session_expired','Sign in again through the local Facebook account form');}
            if($cookies===null)throw new MessengerException('session_required','Sign in with the Facebook account before connecting');
            $origin=$this->sessionOrigin($cookies);$html=$this->loadBootstrap($cookies,$origin);
            $parser=new BootstrapParser();$this->bootstrap=$parser->parse($html,$cookies->accountId(),$origin);
            if($this->bootstrap->versionId===null){$scripts=($this->bundleLoader)($this->bootstrap->scriptUrls);$this->bootstrap=$parser->parse($html,$cookies->accountId(),$origin,$scripts);}
            if($this->bootstrap->versionId===null)throw new MessengerException('lightspeed_version_missing','The current Facebook Lightspeed version could not be recovered from the bootstrap');
            if($this->bootstrap->lightspeedAppId===null)throw new MessengerException('lightspeed_app_id_missing','Facebook did not provide the authenticated Lightspeed application ID');
            // Server-rendered preloaders describe an empty Web cache. A restored
            // PHP cache must resume its own cursors through explicit DGW requests.
            $restoring=false;
            foreach(array_unique([1,2,95,104,...array_keys($this->bootstrap->initialSyncRequests())]) as $database)if($this->store->get('cursor.'.$database)!==null){$restoring=true;break;}
            if(!$restoring){
                foreach($this->bootstrap->initialSyncRequests() as $database=>$request){
                    $this->store->set('sync_request.'.$database,json_encode($request,JSON_THROW_ON_ERROR));
                    $this->initializeSyncGroup($database);
                }
                foreach($this->bootstrap->initialLightspeedPayloads() as $payload){$this->process($payload['payload'],$payload['dependencies']??[]);if(!$this->running)return;}
            }
            $url=$this->gatewayUrl($this->bootstrap);
            $headers=['Cookie'=>$cookies->headerFor($url),'User-Agent'=>CurlHttpClient::USER_AGENT];
            $socket=$this->socketFactory===null?new WebSocket($url,$this->bootstrap->origin,$headers,timeoutSeconds:$this->timeoutSeconds):($this->socketFactory)($url,$this->bootstrap->origin,$headers);
            if(!$socket instanceof Socket)throw new \UnexpectedValueException('Messenger socket factory did not return a Socket');
            $this->connection=new DgwConnection($socket);$this->changeState('connecting_transport');if(!$this->running)return;$this->connection->connect();
            $params=$this->bootstrap->syncParams();
            foreach([1=>'mailbox',2=>'contact',95=>'e2ee',104=>'communityExclusive'] as $database=>$key){
                $cursor=$this->store->get('cursor.'.$database);$sync=is_string($params[$key]??null)?$params[$key]:null;
                if($cursor===null&&$sync===null){if($database===1)throw new MessengerException('sync_params_missing','Facebook did not provide an initial mailbox cursor or sync parameters');continue;}
                $this->requestedCursors[$database]=$cursor;
                $request=$this->syncRequest($database,$cursor,$sync);
                $this->syncStreams[$database]=$this->connection->subscribe($request,$this->timeoutSeconds);
            }
            $deadline=microtime(true)+$this->timeoutSeconds;
            while(!isset($this->synced[1])){
                $left=$deadline-microtime(true);if($left<=0)throw new MessengerException('initial_sync_timeout','Messenger did not confirm the initial mailbox sync',true);
                $this->receive(min(.25,$left));if(!$this->running)return;$this->drainSync();
            }
            $this->changeState('metadata_connected');
        }catch(\Throwable $error){
            $this->running=false;$this->connection?->close();$this->connection=null;$this->state=$error instanceof MessengerException?$error->reason:'connection_failed';
            try{$this->store->event('connection',['state'=>$this->state,'e2ee_ready'=>false]);}finally{$this->store->release();}
            throw $error;
        }
    }
    public function poll(float $timeoutSeconds=.5):void
    {
        $this->outsideCallback();if(!is_finite($timeoutSeconds)||$timeoutSeconds<0)throw new \InvalidArgumentException('Invalid poll timeout');
        $this->loginChallenge();
        if(!$this->isConnected())return;
        try{$this->receive($timeoutSeconds);$this->drainSync();}
        catch(\Throwable $error){$this->running=false;$this->connection?->close();$this->connection=null;$this->state='disconnected';try{if($this->store->ownsLock())$this->store->event('connection',['state'=>'disconnected','e2ee_ready'=>false]);}finally{$this->store->release();}throw $error;}
    }
    public function stop():void
    {
        $this->loginService?->cancel();$this->loginService=null;$this->loginOwnsLock=false;
        $this->running=false;$this->connection?->close();$this->connection=null;$this->state='stopped';
        if($this->store->ownsLock()){try{$this->store->event('connection',['state'=>'stopped','e2ee_ready'=>false]);}finally{$this->store->release();}}
    }
    /** @return list<array<string,mixed>> */
    public function threads():array{return $this->store->threads();}
    /** @return list<array<string,mixed>> */
    public function events(int $after=0,int $limit=100):array{return $this->store->events($after,$limit);}
    /** @return array<string,mixed>|null */
    public function outgoing(string $id):?array{return $this->store->outgoing($id);}

    /** Refreshes metadata only. This never registers an encrypted Messenger device.
     * @return list<array<string,mixed>>
     */
    public function refreshThreads():array
    {
        $this->requireConnected();
        $tasks=[TaskBuilder::threads(++$this->taskId,1,$this->store->get('cursor.1')),TaskBuilder::threads(++$this->taskId,95,$this->store->get('cursor.95'))];
        $response=$this->connection->request($this->taskRequest($tasks),$this->timeoutSeconds);$this->processResponse($response);
        return $this->threads();
    }
    /** @return array<string,mixed> */
    public function sendText(string $threadId,string $text):array
    {
        $this->requireConnected();TaskBuilder::decimalId($threadId);
        $thread=$this->store->thread($threadId);
        if($thread===null)throw new MessengerException('unknown_thread','Select an exact thread ID confirmed by Messenger sync');
        if(($thread['encrypted']??false)===true)throw new MessengerException('messenger_e2ee_not_ready','This conversation requires the Messenger encrypted device transport; plaintext fallback is disabled');
        if(($thread['plaintext_send_supported']??false)!==true)throw new MessengerException('unsupported_thread','The protocol for this Messenger thread type has not been established');
        $offlineId=TaskBuilder::epoch();$request=$this->taskRequest([TaskBuilder::text($threadId,$text,$offlineId,++$this->taskId)]);
        $this->store->enqueue($offlineId,$threadId,$text,$request['request_id']);
        // Mark uncertainty BEFORE I/O: a partial write may already reach the server.
        $this->store->markSent($offlineId,'uncertain');
        try{
            $response=$this->connection->request($request,$this->timeoutSeconds);$this->store->markSent($offlineId,'submitted');$this->processResponse($response);
        }catch(\Throwable $error){
            $known=$this->store->outgoing($offlineId);
            if(!in_array($known['state']??null,['server_ack','failed'],true)){
                if($this->store->ownsLock()){$this->store->markSent($offlineId,'uncertain');$this->store->event('send_uncertain',['offline_id'=>$offlineId,'thread_id'=>$threadId,'code'=>$error instanceof MessengerException?$error->reason:'transport_failed']);}
                throw new MessengerException('send_outcome_uncertain','Messenger send outcome is uncertain; inspect the outbox and events before retrying');
            }
        }
        $saved=$this->store->outgoing($offlineId);
        if(($saved['state']??null)==='failed')throw new MessengerException('message_rejected','Messenger rejected this send task; inspect the outbox and events');
        return ['id'=>$saved['server_id']??$offlineId,'offline_id'=>$offlineId,'thread_id'=>$threadId,'status'=>$saved['state']??'uncertain','delivery_confirmed'=>false,'read_confirmed'=>false];
    }
    /** @param list<array<string,mixed>> $tasks
     * @return array<string,mixed>
     */
    private function taskRequest(array $tasks):array
    {
        $bootstrap=$this->bootstrap??throw new \LogicException('Messenger bootstrap is unavailable');
        return json_decode(TaskBuilder::request($bootstrap->lightspeedAppId??throw new \LogicException('Lightspeed application is unavailable'),++$this->requestId,3,TaskBuilder::batch($bootstrap->versionId??throw new \LogicException('Lightspeed version is unavailable'),$tasks)),true,64,JSON_THROW_ON_ERROR);
    }
    /** @return array<string,mixed> */
    private function syncRequest(int $database,?string $cursor,?string $params):array
    {
        $bootstrap=$this->bootstrap??throw new \LogicException('Messenger bootstrap is unavailable');
        $this->initializeSyncGroup($database);
        $payload=TaskBuilder::sync($bootstrap->versionId??throw new \LogicException('Lightspeed version is unavailable'),$database,$cursor,$params);
        // Persist the actual request before I/O. A response cannot supply its
        // own expected epoch or cursor and thereby authorize its own changes.
        $this->store->set('sync_request.'.$database,json_encode(['epochId'=>(string)$payload['epoch_id'],'failureCount'=>0,'cursor'=>$cursor],JSON_THROW_ON_ERROR));
        return json_decode(TaskBuilder::request($bootstrap->lightspeedAppId??throw new \LogicException('Lightspeed application is unavailable'),++$this->requestId,$cursor===null?1:2,$payload),true,64,JSON_THROW_ON_ERROR);
    }
    private function initializeSyncGroup(int $database):void
    {
        // This is explicit local state created before processing the response.
        // A new group has no cursor value; it is not a group with a null cursor.
        if($this->store->get('cursor.'.$database)===null&&$this->store->get('sync_cursor_kind.'.$database)===null)$this->store->set('sync_cursor_kind.'.$database,'undefined');
    }
    private function receive(float $timeout):void
    {
        $event=$this->connection?->receive($timeout);if($event===null)return;
        if(($event['payload']['event']??null)==='stream_closed')throw new MessengerException('sync_stream_closed','Messenger closed a persistent sync stream; reconnect to resume its cursor',true);
        $this->processResponse($event['payload']);
    }
    /** @param array<string,mixed> $response */
    private function processResponse(array $response):void
    {
        if(!isset($response['payload'])||(!is_string($response['payload'])&&!is_array($response['payload'])))throw new MessengerException('invalid_lightspeed_payload','Messenger returned a response without a Lightspeed payload');
        $dependencies=$response['sp']??$response['dependencies']??[];
        if(!is_array($dependencies))throw new MessengerException('invalid_lightspeed_dependency','Messenger returned invalid stored procedure dependencies');
        $this->process($response['payload'],$dependencies);
    }
    /** @param array<mixed>|string $payload
     * @param array<mixed> $dependencies
     */
    private function process(array|string $payload,array $dependencies):void
    {
        $bootstrap=$this->bootstrap??throw new \LogicException('No authenticated Messenger identity');
        // Only an exact payload previously committed for this identity and
        // protocol revision may bypass the guard as a transport replay.
        $receipt=hash('sha256',json_encode(['native-ls-v2',$bootstrap->userId,$bootstrap->origin,$bootstrap->versionId,$payload,$dependencies],JSON_THROW_ON_ERROR));
        if($this->store->get('receipt.'.$receipt)==='committed')return;
        $guard=new LightspeedSyncGuard(function(int $database):?array{
            $raw=$this->store->get('sync_request.'.$database);
            if($raw===null)return null;
            $request=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
            if(!is_array($request)||!is_string($request['epochId']??null)||!is_int($request['failureCount']??null)||!array_key_exists('cursor',$request))return null;
            $cursor=$this->store->get('cursor.'.$database);
            $group=['cursor'=>$cursor];
            if($cursor===null){
                $kind=$this->store->get('sync_cursor_kind.'.$database);
                if($kind!=='undefined')return null;
                $group['cursorKind']='undefined';
            }
            return ['request'=>['epochId'=>$request['epochId'],'failureCount'=>$request['failureCount']],'group'=>$group];
        });
        $calls=(new LightspeedDecoder(procedureResultResolver:$guard))->decode($payload,$dependencies);
        $result=(new EventProjector($this->store,$bootstrap->userId))->apply($calls,$receipt);
        foreach($result['completed'] as $database)$this->synced[$database]=true;
        foreach($result['events'] as $event)$this->emit($event['type'],$event['payload']);
        foreach($result['cursors'] as $database=>$cursor)if(($this->requestedCursors[$database]??null)!==$cursor)$this->pendingSync[$database]=$cursor;
        if($result['unknown']!==[])$this->emit('protocol_notice',['code'=>'unprojected_procedures','names'=>array_slice($result['unknown'],0,50),'retained_privately'=>true]);
    }
    private function drainSync():void
    {
        $pending=$this->pendingSync;$this->pendingSync=[];
        foreach($pending as $database=>$cursor){
            $stream=$this->syncStreams[$database]??null;if($stream===null||($this->requestedCursors[$database]??null)===$cursor)continue;
            $params=$this->bootstrap?->syncParams()??[];
            $key=match($database){1=>'mailbox',2=>'contact',95=>'e2ee',104=>'communityExclusive',default=>null};
            $sync=$this->store->get('sync_send_params.'.$database)!=='0'&&$key!==null&&is_string($params[$key]??null)?$params[$key]:null;
            $this->requestedCursors[$database]=$cursor;$this->connection?->sendOnStream($stream,$this->syncRequest($database,$cursor,$sync),$this->timeoutSeconds);
        }
    }
    private function loadBootstrap(CookieJar $cookies,string $origin):string
    {
        $url=$origin.($origin==='https://www.facebook.com'?'/messages/':'/');
        for($attempt=0;$attempt<4;$attempt++){
            $response=$this->http->request('GET',$url,$cookies);
            if($response->status===429)throw new MessengerException('rate_limited','Facebook temporarily limited this session',true);
            if(in_array($response->status,[301,302,303,307,308],true)){
                $location=$response->header('location')??'';
                if(str_contains($location,'/checkpoint')||str_contains($location,'/two_step_verification')||str_contains($location,'/consent'))throw new MessengerException('auth_challenge_required','Complete the Facebook security or consent challenge in your browser');
                if(str_contains($location,'/login'))throw new MessengerException('session_expired','Facebook requires a new authenticated Web session');
                if(str_starts_with($location,'/')&&!str_starts_with($location,'//'))$location=$origin.$location;
                $parts=parse_url($location);
                $expectedHost=parse_url($origin,PHP_URL_HOST);
                if(($parts['scheme']??null)!=='https'||($parts['host']??null)!==$expectedHost||($origin==='https://www.facebook.com'&&!str_starts_with($parts['path']??'','/messages'))||isset($parts['user'])||isset($parts['pass'])||(isset($parts['port'])&&$parts['port']!==443))throw new MessengerException('unexpected_auth_redirect','Facebook redirected outside the selected messages page');
                $url=$location;continue;
            }
            if($response->status!==200)throw new MessengerException('bootstrap_http_failed','Facebook did not return an authenticated messages page',in_array($response->status,[500,502,503,504],true));
            return $response->body;
        }
        throw new MessengerException('bootstrap_redirect_limit','Facebook bootstrap exceeded the redirect limit');
    }
    private function gatewayUrl(BootstrapConfig $config):string
    {
        $host=$config->origin==='https://www.messenger.com'?'gateway.messenger.com':'gateway.facebook.com';
        return 'wss://'.$host.'/ws/lightspeed?'.http_build_query(['x-dgw-appid'=>$config->appId,'x-dgw-appversion'=>$config->appVersion,
            'x-dgw-authtype'=>'1:0','x-dgw-version'=>'5','x-dgw-uuid'=>$config->userId,'x-dgw-tier'=>'prod','x-dgw-deviceid'=>$config->deviceId],'','&',PHP_QUERY_RFC3986);
    }
    private function sessionOrigin(CookieJar $cookies):string
    {
        foreach(['https://www.messenger.com','https://www.facebook.com'] as $origin){
            $header=$cookies->headerFor($origin.'/');
            if(preg_match('/(?:^|; )c_user=([^;]+)/',$header,$user)&&$user[1]===$cookies->accountId()&&preg_match('/(?:^|; )xs=[^;]+/',$header))return $origin;
        }
        throw new MessengerException('session_domain_mismatch','The authenticated cookies do not cover a supported Facebook/Messenger Web origin');
    }
    private function requireConnected():void{$this->outsideCallback();if(!$this->isConnected())throw new MessengerException('not_connected','Connect and synchronize the Messenger session before executing this operation');}
    private function outsideCallback():void{if($this->inCallback)throw new \LogicException('Execute Messenger commands in the outer loop, after event callbacks return');}
    private function changeState(string $state):void{$this->state=$state;$payload=['state'=>$state,'e2ee_ready'=>false,'session_id'=>$this->sessionId];$this->store->event('connection',$payload);$this->emit('connection',$payload);}
    /** @param array<string,mixed> $payload */
    private function emit(string $event,array $payload):void
    {
        $previous=$this->inCallback;$this->inCallback=true;
        try{foreach($this->listeners[$event]??[] as $handler){
            try{$handler($payload);}catch(\Throwable){if($this->store->ownsLock())$this->store->event('listener_error',['event'=>$event,'code'=>'listener_failed']);}
        }}finally{$this->inCallback=$previous;}
    }
    public function __destruct(){$this->stop();}
    public function __debugInfo():array{return $this->accountInfo();}
}
