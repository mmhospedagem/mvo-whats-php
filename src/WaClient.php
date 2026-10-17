<?php
declare(strict_types=1);
namespace MVO;

use MVO\Auth\{CredentialStore,PairingFlow,ShortcakeFlow};
use MVO\Message\MessageService;
use MVO\Protocol\{BinaryNode,ClientPayload,Jid};
use MVO\Plugin\{PluginDefinition,PluginHost};
use MVO\Store\KeyValueStore;
use MVO\Service\{Services,OfflineResumeService,IncomingEvents,NewsletterInbox,AbPropsService,IdentityService};
use MVO\AppState\{AppStateService,AppStateProjector,AppStateSchema};
use MVO\History\HistoryStore;
use MVO\Media\{MediaTransfer,MediaProcessor};
use MVO\Protocol\BinaryData;
use MVO\Mobile\{PrimaryCredentials,MobileService,RegistrationService,TcpSocket};
use MVO\Signal\{SignalProtocol,SignalStore};
use MVO\Voip\VoipService;
use MVO\Transport\{ConnectionFailure,NodeSession,NoiseSession,WebSocket,SessionRestartRequired,WaVersion};

/** Blocking PHP client. Credentials and Signal state survive transport and process restarts. */
final class WaClient
{
    public const VERSION = '2.3000.1043028647';
    private CredentialStore $store;
    /** @var array<string,mixed> */
    private array $credentials = [];
    /** @var array<string,list<callable(array<string,mixed>):void>> */
    private array $listeners = [];
    /** @var array<string,list<array{original:callable(array<string,mixed>):void,wrapped:callable(array<string,mixed>):void}>> */
    private array $onceListeners=[];
    private ?NodeSession $session = null;
    private ?PairingFlow $pairing = null;
    private ?MessageService $messages = null;
    /** @var \Closure():NodeSession */
    private \Closure $sessionFactory;
    /** @var \Closure():float */
    private \Closure $clock;
    /** @var \Closure():float */
    private \Closure $random;
    private string $state = 'disconnected';
    private bool $running = false;
    private bool $pairingReady = false;
    private bool $pairingCodeActive = false;
    private bool $pairingRefsExpired = false;
    private bool $reconnectRequested = false;
    private bool $postLoginPending = false;
    private bool $loginReceived = false;
    private bool $queryInFlight = false;
    private float $lastPing = 0;
    private ?float $reconnectAt = null;
    private int $reconnectAttempt = 0;
    private int $successfulConnections = 0;
    private ?float $connectedAt = null;
    /** @var array<string,BinaryNode|null> */
    private array $pending = [];
    /** @var array<string,string> */
    private array $pendingTags = [];
    private PluginHost $plugins;
    /** @var array<int,callable(BinaryNode):bool> */
    private array $incomingHandlers=[];
    /** @var array<int,callable(BinaryNode):bool> */
    private array $incomingFilters=[];
    private int $handlerSequence=0;
    /** @var (\Closure(string):KeyValueStore)|null */
    private ?\Closure $backendFactory;
    /** @var \Closure():string */
    private \Closure $versionResolver;
    private string $activeVersion;
    private bool $versionRefreshed=false;
    private ?Services $services=null;
    private ?AppStateService $appState=null;
    private ?SignalStore $stateStore=null;
    private ?MediaTransfer $mediaTransfer=null;
    private float $nextAppStateSyncAt=0;
    private ?AppStateProjector $appStateData=null;
    private ?\MVO\Service\ModernStateService $modernState=null;
    private ?\MVO\Service\ChannelPublicationScheduler $channelScheduler=null;
    private ?\MVO\Service\MessagePublicationScheduler $messageScheduler=null;
    private ?\MVO\Service\DurablePublicationBatchService $publicationBatchService=null;
    private ?\MVO\Service\HistoryFeaturesService $historyFeaturesService=null;
    /** @var \Closure():void|null */
    private ?\Closure $channelScheduleGuard=null;
    private bool $scheduledPublishing=false;
    private ?string $scheduledMessageId=null;
    private bool $scheduledPublicationWriteStarted=false;
    /** @var (\Closure():void)|null */
    private ?\Closure $groupStatusGuard=null;
    private bool $groupStatusPublishing=false;
    /** @var array<string,true> */
    private array $groupStatusWrites=[];
    /** @var \Closure():float Unix seconds, independent of monotonic connection deadlines. */
    private \Closure $wallClock;
    private ?ShortcakeFlow $shortcake=null;
    private ?OfflineResumeService $offline=null;
    /** @var (\Closure(string):array{credentialId:string,webauthnAssertion:string})|null */
    private ?\Closure $passkeySigner;
    private ?MobileService $mobile=null;
    private bool $mobileInitialized=false;
    private ?NewsletterInbox $newsletterInbox=null;
    private ?\MVO\Telemetry\WamService $wam=null;
    private ?VoipService $voip=null;
    private ?AbPropsService $abprops=null;
    private bool $maintenancePending=false;
    /** @var (\Closure(callable(BinaryNode):BinaryNode):MediaTransfer)|null */
    private ?\Closure $mediaTransferFactory;

    /**
     *
     * @param callable():NodeSession|null $sessionFactory Must return an already-connected transport.
     *
     * @param callable():float|null $clock Monotonic test clock, in seconds.
     *
     * @param callable():float|null $random Jitter source between zero and one.
     * @param list<PluginDefinition> $plugins Native PHP extensions.
     * @param callable(string):KeyValueStore|null $backendFactory Session-scoped Signal/message backend; credentials remain in the protected session directory.
     * @param callable():string|null $versionResolver Used once when the server rejects an obsolete Web version.
     * @param callable(string):array{credentialId:string,webauthnAssertion:string}|null $signPasskeyAssertion External WebAuthn authenticator.
     * @param array<string,mixed>|null $mobileDeviceInfo Selects primary TCP login; persisted primary device information is restored on connect.
     * @param callable(callable(BinaryNode):BinaryNode):MediaTransfer|null $mediaTransferFactory Explicit media transport configuration, e.g. isolated test HTTPS trust.
     * @param bool $markOnlineOnConnect Announce available after each authenticated login; false preserves manual presence control.
     * @param callable():float|null $wallClock Unix seconds for durable schedules and timestamped state; independent of clock deadlines.
     */
    public function __construct(
        public readonly string $sessionId = 'default',
        ?string $authDirectory = null,
        private readonly string $version = self::VERSION,
        ?callable $sessionFactory = null,
        private readonly bool $autoReconnect = true,
        private readonly float $reconnectMinSeconds = 1.0,
        private readonly float $reconnectMaxSeconds = 60.0,
        ?callable $clock = null,
        ?callable $random = null,
        private readonly ?string $proxy = null,
        array $plugins = [],
        ?callable $backendFactory = null,
        ?callable $versionResolver = null,
        private readonly ?MediaProcessor $mediaProcessor = null,
        private readonly int $mediaMaxBytes = 33554432,
        ?callable $signPasskeyAssertion = null,
        private readonly bool $autoConfirmPasskey = true,
        private ?array $mobileDeviceInfo = null,
        private readonly string $mobileEndpoint = 'tcp://g.whatsapp.net:443',
        private readonly bool $automaticMaintenance = true,
        ?callable $mediaTransferFactory = null,
        private bool $markOnlineOnConnect = false,
        ?callable $wallClock = null,
    ) {
        WaVersion::validate($version);
        if (!is_finite($reconnectMinSeconds) || !is_finite($reconnectMaxSeconds) || $reconnectMinSeconds <= 0 || $reconnectMaxSeconds < $reconnectMinSeconds) throw new \InvalidArgumentException('Invalid reconnect limits');
        if ($mediaMaxBytes<1 || $mediaMaxBytes>PHP_INT_MAX-1024) throw new \InvalidArgumentException('Invalid media size limit');
        $this->store = new CredentialStore($authDirectory ?? dirname(__DIR__).'/.auth',$sessionId);
        $this->sessionFactory = $sessionFactory !== null ? \Closure::fromCallable($sessionFactory) : function (): NodeSession {
            $socket = $this->mobileDeviceInfo===null ? new WebSocket(proxy:$this->proxy) : new TcpSocket($this->mobileEndpoint,httpProxy:$this->proxy); $socket->connect();
            return $this->createNoiseSession($socket);
        };
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn():float=>hrtime(true)/1000000000;
        $this->wallClock=$wallClock===null?static fn():float=>microtime(true):\Closure::fromCallable($wallClock);
        $this->random = $random !== null ? \Closure::fromCallable($random) : static fn():float=>random_int(0,1000000)/1000000;
        $this->plugins=new PluginHost($plugins);
        $this->backendFactory=$backendFactory===null ? null : \Closure::fromCallable($backendFactory);
        $this->versionResolver=$versionResolver===null ? fn():string=>WaVersion::fetch($this->proxy) : \Closure::fromCallable($versionResolver);
        $this->activeVersion=$version;
        $this->passkeySigner=$signPasskeyAssertion===null ? null : \Closure::fromCallable($signPasskeyAssertion);
        $this->mediaTransferFactory=$mediaTransferFactory===null ? null : \Closure::fromCallable($mediaTransferFactory);
    }
    private function createNoiseSession(WebSocket $socket): NoiseSession {
        // A cached server key alone does not identify a linked companion. Fresh
        // registration must use XX again after a transport or process restart.
        return new NoiseSession($socket,serverStaticKey:$this->isRegistered() ? ($this->credentials['serverStaticKey'] ?? null) : null,routingInfo:$this->credentials['routingInfo'] ?? null);
    }
    /**
     * @param callable(array<string,mixed>):void $handler */
    public function on(string $event, callable $handler): self { $this->listeners[$event][] = $handler; return $this; }
    /** @param callable(array<string,mixed>):void $handler */
    public function off(string $event,callable $handler):self {
        $remove=[$handler];
        foreach ($this->onceListeners[$event] ?? [] as $entry) if ($entry['original']===$handler) $remove[]=$entry['wrapped'];
        $this->listeners[$event]=array_values(array_filter($this->listeners[$event] ?? [],static fn(callable $candidate):bool=>!in_array($candidate,$remove,true)));
        $this->onceListeners[$event]=array_values(array_filter($this->onceListeners[$event] ?? [],static fn(array $entry):bool=>!in_array($entry['wrapped'],$remove,true))); return $this;
    }
    /** @param callable(array<string,mixed>):void $handler */
    public function once(string $event,callable $handler):self {
        $wrapped=null;
        $wrapped=function(array $payload) use($event,$handler,&$wrapped):void { $this->off($event,$wrapped); $handler($payload); };
        $this->onceListeners[$event][]=['original'=>$handler,'wrapped'=>$wrapped];
        return $this->on($event,$wrapped);
    }
    /** @param array<string,mixed> $payload */
    public function emitEvent(string $event,array $payload=[]):void { $this->emit($event,$payload); }
    public function plugin(string $id):mixed { return $this->plugins->get($id); }
    /** Low-level raw node/query/handler API; same authenticated connection and locking rules. */
    public function lowlevel():self { return $this; }
    /** @param callable(BinaryNode):bool $handler true consumes stanza
     * @return \Closure():void */
    public function registerIncomingHandler(callable $handler):\Closure {
        $id=++$this->handlerSequence; $this->incomingHandlers[$id]=$handler;
        return function() use($id):void { unset($this->incomingHandlers[$id]); };
    }
    /** @param callable(BinaryNode):bool $filter true drops after server ACK
     * @return \Closure():void */
    public function registerIncomingStanzaFilter(callable $filter):\Closure {
        $id=++$this->handlerSequence; $this->incomingFilters[$id]=$filter;
        return function() use($id):void { unset($this->incomingFilters[$id]); };
    }
    /** @param array{id?:string,remoteJid?:string,participant?:string,fromMe?:bool}|callable(BinaryNode):bool $input
     * @return \Closure():void */
    public function ignoreKey(array|callable $input):\Closure {
        if (is_callable($input)) return $this->registerIncomingStanzaFilter($input);
        if ($input===[] || array_diff(array_keys($input),['id','remoteJid','participant','fromMe'])!==[]) throw new \InvalidArgumentException('Invalid ignore-key descriptor');
        foreach ($input as $key=>$value) if ($key==='fromMe' ? !is_bool($value) : (!is_string($value) || $value==='')) throw new \InvalidArgumentException('Invalid ignore-key value');
        return $this->registerIncomingStanzaFilter(function(BinaryNode $node) use($input):bool {
            $own=array_filter([$this->credentials['meJid'] ?? null,$this->credentials['meLid'] ?? null],'is_string');
            $sender=$node->attrs['participant'] ?? $node->attrs['from'] ?? '';
            $fromMe=false;
            foreach ($own as $jid) if (self::sameUser($sender,$jid)) $fromMe=true;
            foreach ($input as $key=>$value) {
                if ($key==='fromMe') { if ($value!==$fromMe) return false; continue; }
                if ($key==='id') { if (($node->attrs['id'] ?? '')!==$value) return false; continue; }
                $actual=$key==='participant' ? ($node->attrs['participant'] ?? '') : ($node->attrs['from'] ?? '');
                $alt=$key==='participant' ? ($node->attrs['participant_pn'] ?? '') : ($node->attrs['sender_pn'] ?? '');
                if (!self::sameUser($actual,$value) && !self::sameUser($alt,$value)) return false;
            }
            return true;
        });
    }
    private static function sameUser(string $a,string $b):bool {
        try { return Jid::parse($a)->canonical()->bare()===Jid::recipient($b)->canonical()->bare(); } catch (\InvalidArgumentException) { return false; }
    }
    /**
     * @param array<string,mixed> $payload */
    private function emit(string $event,array $payload): void { foreach ($this->listeners[$event] ?? [] as $handler) $handler($payload); }
    private function state(string $state): void {
        if ($this->state === $state) return;
        $this->state = $state; $this->emit('connection',['state'=>$state,'sessionId'=>$this->sessionId]);
    }
    /** @phpstan-impure The injected clock and asynchronous signal handlers may change client state. */
    private function now(): float { return ($this->clock)(); }
    public function getState(): string { return $this->state; }
    public function isConnected(): bool { return $this->state === 'connected'; }
    public function isRunning(): bool { return $this->running; }
    public function isRegistered(): bool { return ($this->credentials['registered'] ?? false) === true; }
    /** Changes future login policy only; never sends presence or starts a connection. */
    public function setMarkOnlineOnConnect(bool $enabled): void { $this->markOnlineOnConnect=$enabled; }
    public function isMarkOnlineOnConnect(): bool { return $this->markOnlineOnConnect; }
    public function accountJid(): ?string { return $this->credentials['meJid'] ?? null; }
    /** Account metadata already known locally; never connects, opens a backend or performs a query.
     * Unknown profile fields are null, rather than inferred from pairing input or ADV key enums.
     * @return array{jid:?string,deviceJid:?string,lid:?string,phoneNumber:?string,phoneE164:?string,deviceId:?int,displayName:?string,username:?string,platform:?string,webVersion:?string,mobileVersion:?string,transport:string,role:?string,registered:bool,connected:bool} */
    public function accountInfo(): array {
        $deviceJid=is_string($this->credentials['meJid'] ?? null) ? $this->credentials['meJid'] : null;
        $jid=self::accountAddress($deviceJid);
        $lid=self::accountAddress($this->credentials['meLid'] ?? null);
        if ($lid?->canonical()->server!=='lid') $lid=null;
        $phone=$jid!==null && in_array($jid->server,['s.whatsapp.net','hosted'],true) && preg_match('/^[1-9][0-9]{7,14}$/D',$jid->user)===1 ? $jid->user : null;
        $name=self::accountText($this->appStateData?->getSetting('SettingPushName')['name'] ?? null)
            ?? self::accountText($this->credentials['meDisplayName'] ?? null);
        $username=null;
        if ($this->stateStore!==null) {
            $history=new HistoryStore($this->stateStore);
            foreach (array_unique(array_filter([$jid?->canonical()->bare(),$lid?->canonical()->bare()])) as $ownJid) {
                $contact=$history->getContact($ownJid);
                $name ??= self::accountText($contact['pushName'] ?? null) ?? self::accountText($contact['displayName'] ?? null);
                $username ??= self::accountText($contact['username'] ?? null);
            }
        }
        return ['jid'=>$jid?->canonical()->bare(),'deviceJid'=>$jid===null ? null : $deviceJid,'lid'=>$lid?->canonical()->bare(),
            'phoneNumber'=>$phone,'phoneE164'=>$phone===null ? null : '+'.$phone,'deviceId'=>$jid?->device,
            'displayName'=>$name,'username'=>$username,'platform'=>self::accountText($this->credentials['platform'] ?? null),
            'webVersion'=>$this->isPrimary()?null:$this->activeVersion,
            'mobileVersion'=>$this->isPrimary()?self::accountText($this->mobileDeviceInfo['appVersion']??null):null,
            'transport'=>$this->mobileDeviceInfo===null?'websocket':'mobile_tcp',
            'role'=>$this->isPrimary()?'primary':($this->isRegistered()?'companion':null),
            'registered'=>$this->isRegistered(),'connected'=>$this->isConnected()];
    }
    private static function accountAddress(mixed $value): ?Jid {
        if (!is_string($value)) return null;
        try { return Jid::parse($value); } catch (\InvalidArgumentException) { return null; }
    }
    private static function accountText(mixed $value): ?string {
        return is_string($value) && trim($value)!=='' ? trim($value) : null;
    }
    public function sessionDirectory(): string { return $this->store->directory; }
    private function services(?float $queryTimeoutSeconds=null):Services {
        if ($queryTimeoutSeconds!==null && (!is_finite($queryTimeoutSeconds) || $queryTimeoutSeconds<=0 || $queryTimeoutSeconds>30)) throw new \InvalidArgumentException('Invalid service query timeout');
        if (!$this->isConnected()) throw new \LogicException('WhatsApp is not connected');
        if($queryTimeoutSeconds===null && $this->services!==null)return $this->services;
        $services = new Services(
            fn(BinaryNode $node,float $timeout=30):BinaryNode=>$this->query($node,$queryTimeoutSeconds===null ? $timeout : min($timeout,$queryTimeoutSeconds),$queryTimeoutSeconds!==null,$queryTimeoutSeconds===null),
            function(BinaryNode $node):void { $this->sendNode($node); },
            fn():array=>$this->credentials,
            fn(string $to,string|array $content):array=>$this->sendMessage($to,$content),
            fn():AppStateService=>$this->chat(),
            fn(string $to,string|array $content,array $recipients,array $options=[]):array=>$this->sendBroadcast($to,$content,$recipients,$options),
            function(string $name):void { $this->credentials['meDisplayName']=$name; $this->store->save($this->credentials); },
            fn(string $to,string|array $content,array $options=[]):array=>$this->message()->sendBotPrompt($to,$content,$options),
            fn(array $content):array=>$this->prepareNewsletterMedia($content),
            fn(string|array $source):string=>($this->mediaTransfer??throw new \LogicException('WhatsApp is not connected'))->uploadBusiness(\MVO\Media\MediaSource::read($source,$this->mediaTransfer->maxBytes()),'product-catalog-image')['url'],
            fn(mixed $source):array=>($this->mediaTransfer??throw new \LogicException('WhatsApp is not connected'))->uploadBusiness(\MVO\Media\MediaSource::read($source,$this->mediaTransfer->maxBytes()),'biz-cover-photo'),
            fn():\MVO\History\StatusStore=>$this->message()->statusStore(),
            function(array $keys,string $type):void {$this->sendReceipt($keys,$type);},
            fn(array $message):string=>$this->downloadMedia($message),
            fn(BinaryNode $node,string $jid):array=>($this->newsletterInbox??throw new \LogicException('Channel inbox is not initialized'))->ingestResponse($node,$jid),
            fn():\MVO\History\ChannelStore=>$this->channelStore(),
            fn(array $descriptor):string=>$this->downloadNewsletterMedia($descriptor),
            function(BinaryNode $post,BinaryNode $ack):void {($this->newsletterInbox??throw new \LogicException('Channel inbox is not initialized'))->recordPublication($post,$ack);},
        );
        $services->configurePrivacyTokens($this->message()->privacyTokens());
        $services->configureGroupStatusPublishing(
            fn(string|array $groups,string|array $content,array $options):array=>$this->withGroupStatusAuthorization(fn():array=>$this->message()->sendGroupStatuses($groups,$content,$options)),
            fn(string $group,string $id):array=>$this->withGroupStatusAuthorization(fn():array=>$this->message()->revokeGroupStatus($group,$id)),
        );
        if($this->stateStore!==null)$services->configureIdentities(new IdentityService($this->stateStore));
        if ($queryTimeoutSeconds===null) $this->services=$services;
        return $services;
    }
    public function presence():\MVO\Service\PresenceService { return $this->services()->presence(); }
    public function group():\MVO\Service\GroupService { return $this->services()->group(); }
    public function community():\MVO\Service\CommunityService { return $this->services()->community(); }
    /** Optional per-query timeout for a separate facade; reaching this deadline alone preserves the connection. */
    public function profile(?float $queryTimeoutSeconds=null):\MVO\Service\ProfileService { return $this->services($queryTimeoutSeconds)->profile(); }
    public function privacy():\MVO\Service\PrivacyService { return $this->services()->privacy(); }
    /** Optional per-query timeout for a separate facade; reaching this deadline alone preserves the connection. */
    public function business(?float $queryTimeoutSeconds=null):\MVO\Service\BusinessService { return $this->services($queryTimeoutSeconds)->business(); }
    public function catalog():\MVO\Service\CatalogService { return $this->services()->catalog(); }
    public function businessTools():\MVO\Service\BusinessToolsService { return new \MVO\Service\BusinessToolsService($this->chat(),$this->appStateData(),$this->history()); }
    public function accountTools():\MVO\Service\AccountToolsService { return new \MVO\Service\AccountToolsService($this->chat(),$this->appStateData(),$this->history()); }
    public function stateFeatures():\MVO\Service\ModernStateService
    {
        return $this->modernState??=new \MVO\Service\ModernStateService($this->stateStore??throw new \LogicException('Wait for authenticated login before querying state features'),$this->history(),$this->appStateData(),fn():array=>$this->credentials,fn(string $to,array $protocol,array $options):array=>$this->message()->publishProtocolMessage($to,$protocol,$options),fn(array $input):array=>$this->requestHistorySync($input),fn():int=>(int)floor(($this->wallClock)()*1000));
    }
    public function channelSchedules():\MVO\Service\ChannelPublicationScheduler
    {
        return $this->channelScheduler??=new \MVO\Service\ChannelPublicationScheduler($this->stateStore??throw new \LogicException('Wait for authenticated login before scheduling publications'),function(string $jid,array $content,array $options):array{
            $previous=[$this->scheduledPublishing,$this->scheduledMessageId,$this->scheduledPublicationWriteStarted];
            $this->scheduledPublishing=true;$this->scheduledMessageId=$options['id'];$this->scheduledPublicationWriteStarted=false;
            try{$this->assertScheduledAuthorization();return $this->newsletter()->send($jid,$content,$options['id']);}
            catch(\MVO\Service\ScheduledPublicationNotExecuted $error){
                // A nested callback can report its own pre-write denial after our
                // publication was already written. That is not proof about this ID.
                if($this->scheduledPublicationWriteStarted)throw new \RuntimeException('Scheduled publication outcome is uncertain after transport write',0,$error);
                throw $error;
            }
            finally{[$this->scheduledPublishing,$this->scheduledMessageId,$this->scheduledPublicationWriteStarted]=$previous;}
        },fn():int=>(int)floor(($this->wallClock)()),function():void{($this->channelScheduleGuard)?->__invoke();});
    }
    /** Fresh application ownership/access check, including during scheduled publication preparation.
     * @param callable():void|null $guard */
    public function setChannelScheduleGuard(?callable $guard):void{$this->channelScheduleGuard=$guard===null?null:\Closure::fromCallable($guard);}
    /** General local schedules share the same fresh access guard as channel publications. */
    public function messageSchedules():\MVO\Service\MessagePublicationScheduler
    {
        return $this->messageScheduler??=new \MVO\Service\MessagePublicationScheduler($this->stateStore??throw new \LogicException('Wait for authenticated login before scheduling messages'),function(string $jid,array $content,array $options):array{
            $previous=[$this->scheduledPublishing,$this->scheduledMessageId,$this->scheduledPublicationWriteStarted];
            $this->scheduledPublishing=true;$this->scheduledMessageId=$options['id'];$this->scheduledPublicationWriteStarted=false;
            try{$this->assertScheduledAuthorization();return $this->sendMessage($jid,[...$content,'messageId'=>$options['id']]);}
            catch(\MVO\Service\ScheduledPublicationNotExecuted $error){
                if($this->scheduledPublicationWriteStarted||$this->message()->getMessageStatus($options['id'])!==null)throw new \RuntimeException('Scheduled message may have a durable publication; inspect its ID',0,$error);
                throw $error;
            }catch(\Throwable $error){
                if(!$this->scheduledPublicationWriteStarted&&$this->message()->getMessageStatus($options['id'])===null)throw new \MVO\Service\ScheduledMessagePreparationFailed($error);
                throw $error;
            }finally{[$this->scheduledPublishing,$this->scheduledMessageId,$this->scheduledPublicationWriteStarted]=$previous;}
        },fn():int=>(int)floor(($this->wallClock)()),function():void{($this->channelScheduleGuard)?->__invoke();});
    }
    public function historyFeatures():\MVO\Service\HistoryFeaturesService
    {
        return $this->historyFeaturesService??=new \MVO\Service\HistoryFeaturesService($this->stateStore??throw new \LogicException('Wait for authenticated login before querying history features'),$this->history(),fn():array=>$this->credentials,fn(string $to,array $protocol,array $options):array=>$this->message()->publishProtocolMessage($to,$protocol,$options),fn():int=>(int)floor(($this->wallClock)()*1000));
    }
    public function accountExtensions():\MVO\Service\AccountExtensionsService
    {
        return new \MVO\Service\AccountExtensionsService($this->chat(),$this->appStateData(),$this->history());
    }
    /** Requests voluntary sharing from an individual recipient; does not reveal a hidden number.
     * @return array<string,mixed> */
    public function sendPhoneNumberRequest(string $to):array
    {
        $to=\MVO\Service\AccountToolsService::chat($this->resolveRecipient($to),true);
        return $this->sendMessage($to,\MVO\Message\PhoneNumberRequests::content());
    }
    /** @return list<array<string,mixed>> */
    public function getPhoneNumberRequests(string $chat,int $limit=100,string $after=''):array
    {
        return \MVO\Message\PhoneNumberRequests::list($this->history(),$chat,$limit,$after);
    }
    /** Optional bounded public metadata retrieval, then ordinary authenticated sending.
     * @return array<string,mixed> */
    public function sendTextWithLinkPreview(string $to,string $text,string $url,bool $includeThumbnail=true):array
    {
        $builder=new \MVO\Message\LinkPreviewBuilder(uploadThumbnail:function(string $jpeg):array{
            $stream=fopen('php://temp','w+b');if($stream===false)throw new \RuntimeException('Cannot allocate preview thumbnail');
            try{fwrite($stream,$jpeg);rewind($stream);return $this->uploadMediaStream($stream,'thumbnail-link');}finally{fclose($stream);}
        });
        return $this->sendMessage($to,$builder->build($text,$url,$includeThumbnail));
    }
    public function publicationBatches():\MVO\Service\DurablePublicationBatchService
    {
        return $this->publicationBatchService??=new \MVO\Service\DurablePublicationBatchService($this->stateStore??throw new \LogicException('Wait for authenticated login before creating batches'),function(string $mode,string $jid,array $content,array $options):array{
            $previous=[$this->scheduledPublishing,$this->scheduledMessageId,$this->scheduledPublicationWriteStarted];
            $this->scheduledPublishing=true;$this->scheduledMessageId=$options['id'];$this->scheduledPublicationWriteStarted=false;
            try{
                $this->assertScheduledAuthorization();
                return $mode==='group-status'?$this->withGroupStatusAuthorization(fn():array=>$this->message()->sendGroupStatusWithId($jid,$content,$options['id'])):$this->sendMessage($jid,[...$content,'messageId'=>$options['id']]);
            }catch(\MVO\Service\ScheduledPublicationNotExecuted $error){
                if($this->scheduledPublicationWriteStarted||$this->message()->getMessageStatus($options['id'])!==null)throw new \RuntimeException('Batch publication outcome is uncertain; inspect the reserved message ID',0,$error);
                throw $error;
            }catch(\MVO\Service\GroupStatusNotExecuted $error){throw $error;}
            catch(\Throwable $error){
                if(!$this->scheduledPublicationWriteStarted&&$this->message()->getMessageStatus($options['id'])===null)throw new \MVO\Service\ScheduledMessagePreparationFailed($error);
                throw $error;
            }finally{[$this->scheduledPublishing,$this->scheduledMessageId,$this->scheduledPublicationWriteStarted]=$previous;}
        },fn():int=>(int)floor(($this->wallClock)()),function():void{($this->channelScheduleGuard)?->__invoke();});
    }
    private function assertScheduledAuthorization():void
    {
        try{($this->channelScheduleGuard)?->__invoke();}
        catch(\Throwable $error){
            if(!$this->scheduledPublicationWriteStarted)throw new \MVO\Service\ScheduledPublicationNotExecuted($error);
            throw $error;
        }
    }
    public function newsletter():\MVO\Service\NewsletterService { return $this->services()->newsletter(); }
    public function channels():\MVO\Service\NewsletterService { return $this->services()->channels(); }
    /** Local channel metadata and posts from this session's durable store. */
    public function channelStore():\MVO\History\ChannelStore {return new \MVO\History\ChannelStore($this->stateStore??throw new \LogicException('Channel store is not initialized'),$this->history());}
    public function email():\MVO\Service\EmailService { return $this->services()->email(); }
    public function bot():\MVO\Service\BotService { return $this->services()->bot(); }
    public function mex():\MVO\Service\MexService { return $this->services()->mex(); }
    /** Optional telemetry is disabled until the application explicitly enables it. */
    public function wam():\MVO\Telemetry\WamService {
        return $this->wam ??= new \MVO\Telemetry\WamService(function(BinaryNode $node,float $timeout):BinaryNode {
            if (!$this->isConnected()) throw new \LogicException('WhatsApp is not connected'); return $this->query($node,$timeout);
        });
    }
    public function status():\MVO\Service\StatusService { return $this->services()->status(); }
    /** Re-evaluated before preparation, each group and every transport write during Group Status operations. */
    public function setGroupStatusGuard(?callable $guard):void{$this->groupStatusGuard=$guard===null?null:\Closure::fromCallable($guard);}
    /** @param \Closure():array<string,mixed> $action
     * @return array<string,mixed> */
    private function withGroupStatusAuthorization(\Closure $action):array
    {
        $previous=[$this->groupStatusPublishing,$this->groupStatusWrites];$this->groupStatusPublishing=true;$this->groupStatusWrites=[];
        try{($this->groupStatusGuard)?->__invoke();return $action();}
        finally{[$this->groupStatusPublishing,$this->groupStatusWrites]=$previous;}
    }
    public function broadcastList():\MVO\Service\BroadcastListService { return $this->services()->broadcastList(); }
    public function chat():AppStateService { return $this->appState ?? throw new \LogicException('Wait for authenticated login before synchronizing chats'); }
    public function appStateData():AppStateProjector { return $this->appStateData ?? throw new \LogicException('Wait for authenticated login before querying app-state'); }
    public function mobile():MobileService { return $this->mobile ?? throw new \LogicException('Mobile operations require an explicitly configured primary session'); }
    /** Registration is separate from the live socket. Codes and private keys stay in the registration service.
     * @param array<string,mixed>|null $deviceInfo
     * @param callable|null $transport Explicit registration transport for isolated tests or an owned provider.
     * @param callable|null $clock Registration wall clock.
     * @param callable|null $guard Application authorization/generation guard before and after network operations. */
    public function mobileRegistration(?array $deviceInfo=null,?callable $transport=null,?callable $clock=null,?callable $guard=null):RegistrationService {
        if($this->running||$this->session!==null)throw new \LogicException('Stop the live client before requesting mobile registration');
        return new RegistrationService($this->store,$deviceInfo??$this->mobileDeviceInfo,
            $transport===null?null:\Closure::fromCallable($transport),$clock===null?null:\Closure::fromCallable($clock),$guard===null?null:\Closure::fromCallable($guard));
    }
    public function isPrimary():bool { return $this->mobileDeviceInfo!==null && ($this->credentials['role'] ?? '')==='primary'; }
    /** Explicitly imports an already registered device-zero identity into an unused PHP session.
     * @param array<string,mixed> $credentials */
    public function importPrimaryCredentials(array $credentials):void {
        if ($this->running || $this->mobileDeviceInfo===null) throw new \LogicException('Configure mobileDeviceInfo and import primary credentials before connect()');
        PrimaryCredentials::validate($credentials,$this->mobileDeviceInfo);
        $this->store->acquire();
        try {
            if (($this->store->load()['registered'] ?? false)===true) throw new \LogicException('Primary import requires a new session; existing linked identities are preserved');
            $credentials['role']='primary'; $credentials['mobileDeviceInfo']=$this->mobileDeviceInfo; $this->store->save($credentials);
        } finally { $this->store->close(); }
    }
    public function history():HistoryStore { return $this->message()->history()->store; }
    /** Observed native consent in an individual conversation, without invented request correlation.
     * @return list<array<string,mixed>> */
    public function phoneNumberShares(string $chat,int $limit=100,string $after=''):array{return $this->history()->phoneNumberShares($chat,$limit,$after);}
    public function abprops():AbPropsService { return $this->abprops??throw new \LogicException('Wait for authenticated login before querying configuration'); }
    public function message():MessageService { return $this->messages ?? throw new \LogicException('Wait for authenticated login before using messages'); }
    public function voip():VoipService {
        $messages=$this->message();
        return $this->voip ??= new VoipService(
            fn():array=>$this->credentials,
            fn(BinaryNode $node,float $timeout):BinaryNode=>$this->query($node,$timeout),
            function(BinaryNode $node):void { $this->sendNode($node); },
            $messages->resolveCallDevices(...),
            $messages->encryptCallKeys(...),
            $messages->decryptCallKey(...),
            function(string $event,array $payload):void { $this->emit($event,$payload); },
            $this->clock,
        );
    }
    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function requestHistorySync(array $input):array { return $this->message()->requestHistorySync($input); }
    /** @param list<array<string,mixed>> $keys */
    public function sendReceipt(array $keys,string $type='read'):void { $this->message()->sendReceipt($keys,$type); }
    /** @param string|array<string,mixed> $content
     * @param list<string> $recipients
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function sendBroadcast(string $listJid,string|array $content,array $recipients,array $options=[]):array { return $this->message()->sendBroadcast($listJid,$content,$recipients,$options); }
    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function shareGroupHistory(string $group,array $input):array { return $this->message()->shareGroupHistory($group,$input); }
    /** @param resource|string $source
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function uploadMediaStream($source,string $type,array $options=[]):array { return ($this->mediaTransfer ?? throw new \LogicException('WhatsApp is not connected'))->uploadStream($source,$type,$options); }
    /** @param array<string,mixed> $message */
    public function downloadMediaStream(array $message,string $destination):string { return $this->message()->downloadMediaStream($message,$destination); }
    /** @param array<string,mixed> $source
     * @return array<string,mixed> */
    public function requestMediaReupload(array $source,float $timeoutSeconds=30):array { return $this->message()->requestMediaReupload($source,$timeoutSeconds); }
    /** @param array<string,mixed> $content
     * @return array<string,mixed> */
    public function prepareNewsletterMedia(array $content):array { return ($this->mediaTransfer ?? throw new \LogicException('WhatsApp is not connected'))->prepareNewsletter($content); }
    /** @param array<string,mixed> $message */
    public function downloadNewsletterMedia(array $message,?string $destination=null):string { return ($this->mediaTransfer ?? throw new \LogicException('WhatsApp is not connected'))->downloadNewsletter($message,$destination); }

    /** Resume the persisted linked identity, or start pairing when this is a new session. */
    public function connect(): void {
        if ($this->running || $this->session !== null) throw new \LogicException('Client already started');
        $this->store->acquire();
        $this->running = true; $this->reconnectAttempt = 0; $this->versionRefreshed=false;
        try {
            $this->credentials = $this->store->load() ?? CredentialStore::fresh();
            if (($this->credentials['registered']??false)!==true && isset($this->credentials['mobileRegistration'])) {
                throw new \LogicException('Confirm the pending mobile registration before connecting this session');
            }
            if(($this->credentials['role']??null)==='primary'&&$this->mobileDeviceInfo===null){
                $savedDeviceInfo=$this->credentials['mobileDeviceInfo']??null;
                if(!is_array($savedDeviceInfo))throw new \LogicException('Primary mobile session requires its persisted device information or explicit mobileDeviceInfo');
                $this->mobileDeviceInfo=$savedDeviceInfo;
            }
            if ($this->mobileDeviceInfo!==null) {
                PrimaryCredentials::validate($this->credentials,$this->mobileDeviceInfo);
                if (($this->credentials['role'] ?? '')!=='primary') throw new \LogicException('Register or explicitly import primary credentials; a linked Web session cannot become a primary');
            }
            if (($this->credentials['revoked'] ?? false) === true) throw new ConnectionFailure('Stored WhatsApp session was revoked; link a new session explicitly','401',false,true);
            $this->store->save($this->credentials);
            if ($this->version===self::VERSION && is_string($this->credentials['webVersion'] ?? null)) {
                try{WaVersion::validate($this->credentials['webVersion']);$this->activeVersion=$this->credentials['webVersion'];}
                catch(\InvalidArgumentException $error){$this->activeVersion=$this->version;$this->emit('version_error',['message'=>'Ignoring invalid saved WhatsApp Web version: '.$error->getMessage()]);}
                if(!$this->running)return;
            }
            $this->plugins->install($this);
            $this->open();
        } catch (\Throwable $e) {
            if (!$this->handleFailure($e)) throw $e;
        }
    }
    private function open(): void {
        $this->state('connecting');
        if (!$this->running) return;
        $this->pairingReady = false; $this->pairingCodeActive = false; $this->pairingRefsExpired = false; $this->reconnectRequested = false; $this->postLoginPending = false; $this->loginReceived = false;
        $this->reconnectAt = null; $this->connectedAt = null;
        $this->session = ($this->sessionFactory)();
        $this->pairing = new PairingFlow(
            fn():array=>$this->credentials,
            function (array $c):void { $this->save($c); },
            fn(BinaryNode $n)=>$this->sendNode($n),
            fn(BinaryNode $n,float $timeout=30)=>$this->query($n,$timeout),
            function (string $event,array $payload):void {
                if ($event === 'auth_qr') { $this->pairingReady = true; $this->pairingRefsExpired = false; }
                if ($event === 'auth_code') $this->pairingCodeActive = true;
                if ($event === 'auth_code_expired' || $event === 'auth_code_refresh') $this->pairingCodeActive = false;
                if ($event === 'auth_qr_expired') $this->pairingRefsExpired = true;
                if ($event === 'auth_paired') { $this->pairingCodeActive = false; $this->pairingRefsExpired = false; }
                if ($event === 'auth_paired' || $event === 'auth_qr_expired') { $this->pairingReady = false; $this->removeQr(); }
                $this->emit($event,$payload);
            }
        );
        $this->shortcake=new ShortcakeFlow(fn():array=>$this->credentials,
            function(array $credentials):void { $this->save($credentials); },
            function(BinaryNode $node):void { $this->sendNode($node); },
            fn(BinaryNode $node,float $timeout=30):BinaryNode=>$this->query($node,$timeout),
            function(string $event,array $payload):void {
                if (in_array($event,['auth_passkey_required','auth_passkey_prologue'],true)) { $this->pairing?->clear(); $this->pairingReady=false; $this->pairingCodeActive=false; $this->pairingRefsExpired=false; $this->removeQr(); }
                $this->emit($event,$payload);
            },
            $this->passkeySigner,autoConfirm:$this->autoConfirmPasskey);
        $this->offline=new OfflineResumeService(function(BinaryNode $node):void { $this->sendNode($node); },function(array $event):void { $this->emit('offline_resume',$event); },$this->clock);
        // The protocol's connectAttemptCount counts successful logins by this
        // client instance. Only lc/loginCounter persists across PHP processes.
        $loginCredentials = [...$this->credentials,'connectAttemptCount'=>$this->successfulConnections];
        $payload = $this->mobileDeviceInfo!==null ? \MVO\Mobile\ClientPayload::login($loginCredentials,$this->mobileDeviceInfo) : ($this->isRegistered() ? ClientPayload::login($loginCredentials,$this->activeVersion) : ClientPayload::registration($this->credentials,$this->activeVersion));
        $key = $this->credentials['noiseKeyPair'];
        $this->credentials['serverStaticKey'] = $this->session->start($key['private'],$key['public'],$payload);
        $this->store->save($this->credentials);
        if (!$this->running) return;
        $this->state($this->isRegistered() ? 'authenticating' : 'pairing');
        $this->lastPing = $this->now();
    }
    /**
     * @param array<string,mixed> $credentials */
    private function save(array $credentials): void { $this->store->save($credentials); $this->credentials = $credentials; }
    public function sendNode(BinaryNode $node): void {
        if ($this->session === null) throw new \LogicException('No active transport');
        if($this->scheduledPublishing){
            $this->assertScheduledAuthorization();
            // Mark before transport I/O: a failed write can still have reached the server.
            if($node->tag==='message'&&($node->attrs['id']??null)===$this->scheduledMessageId)$this->scheduledPublicationWriteStarted=true;
        }
        if($this->groupStatusPublishing){
            $id=$node->attrs['id']??'';
            try{($this->groupStatusGuard)?->__invoke();}
            catch(\Throwable $error){
                if($node->tag==='message'&&!isset($this->groupStatusWrites[$id]))throw new \MVO\Service\GroupStatusNotExecuted($id,$error);
                throw $error;
            }
            if($node->tag==='message')$this->groupStatusWrites[$id]=true;
        }
        $this->session->sendNode($node);
    }

    /**
     * Correlate IQ results and message/call ACKs while processing incoming stanzas.
     * Disable reconnectOnTimeout only for optional reads; transport failures still use normal recovery.
     * @phpstan-impure Incoming events may stop or reconnect this client.
     */
    public function query(BinaryNode $node,float $timeoutSeconds = 30,bool $allowErrorResponse=false,bool $reconnectOnTimeout=true): BinaryNode {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0) throw new \InvalidArgumentException('Invalid query timeout');
        $id = $node->attrs['id'] ?? bin2hex(random_bytes(8));
        if (array_key_exists($id,$this->pending)) throw new \LogicException('Duplicate pending IQ ID');
        $node = new BinaryNode($node->tag,[...$node->attrs,'id'=>$id],$node->content);
        $this->pending[$id] = null; $this->pendingTags[$id] = $node->tag;
        $previousQueryState = $this->queryInFlight; $this->queryInFlight = true; $timedOut=false;
        try {
            $this->sendNode($node); $deadline = $this->now()+$timeoutSeconds;
            while (($this->pending[$id] ?? null) === null && $this->now()<$deadline) {
                if (!$this->running || $this->session === null) throw new SessionRestartRequired('Connection stopped while waiting for response');
                $this->read(min(0.5,max(0.001,$deadline-$this->now())));
                if ($this->reconnectRequested) throw new SessionRestartRequired('Connection restarted while waiting for response');
                if ($this->running && $this->isConnected() && !$this->tickVoip()) throw new SessionRestartRequired('Connection stopped while polling call media');
            }
            $result = $this->pending[$id] ?? null;
            if ($result === null) { $timedOut=true; throw new \RuntimeException('WhatsApp IQ timeout: '.$id); }
            if (!$allowErrorResponse && (($result->attrs['type'] ?? '') === 'error' || isset($result->attrs['error']) || $result->child('error') !== null)) {
                $error = $result->child('error');
                $code = $error?->attrs['code'] ?? $result->attrs['error'] ?? '';
                $description = 'WhatsApp '.(in_array($node->tag,['message','call'],true) ? $node->tag : 'IQ').' error '.$code.' '.($error?->attrs['text'] ?? '');
                if ($node->tag==='iq' && self::retryCode($code)) throw new ConnectionFailure($description,$code,true);
                if ($node->tag==='iq') throw new \MVO\Service\ServiceException($node->attrs['xmlns'] ?? 'iq',$code===''?null:$code,$error?->attrs['text'] ?? 'WhatsApp IQ request rejected');
                if ($node->tag==='message') throw new \MVO\Service\ServiceException('message',preg_match('/^[0-9]{1,5}$/D',$code)===1?$code:null,'WhatsApp rejected outgoing message');
                throw new \RuntimeException($description);
            }
            return $result;
        } catch (\Throwable $e) {
            // Recovery never replays an IQ/message whose delivery may be uncertain.
            if ((!$timedOut || $reconnectOnTimeout) && $this->running && $this->session !== null && !($e instanceof SessionRestartRequired) && ($this->isTransient($e) || $e instanceof ConnectionFailure)) $this->handleFailure($e);
            throw $e;
        } finally { unset($this->pending[$id],$this->pendingTags[$id]); $this->queryInFlight = $previousQueryState; }
    }
    public function requestPairingCode(string $phoneNumber,?string $customCode = null): string {
        if ($this->session === null) throw new \LogicException('Call connect() before requesting a pairing code');
        if ($this->isRegistered()) throw new \LogicException('Session already paired');
        $deadline = $this->now()+30;
        while (!$this->pairingReady && $this->running && $this->now()<$deadline) $this->poll(0.5);
        if (!$this->pairingReady || $this->pairing === null) throw new \RuntimeException('Server did not provide pairing references');
        return $this->pairing->requestCode($phoneNumber,$customCode);
    }

    public function requestPasskeyPairing(?string $requestOptions=null,?string $handoffProof=null):void {
        ($this->shortcake ?? throw new \LogicException('Call connect() before requesting passkey pairing'))->requestPasskeyPairing($requestOptions,$handoffProof);
    }
    public function confirmPasskeyVerificationCode():void { ($this->shortcake ?? throw new \LogicException('No active passkey flow'))->confirmVerificationCode(); }
    /** @param callable(string):array{credentialId:string,webauthnAssertion:string} $signer */
    public function setPasskeySigner(callable $signer):void { $this->passkeySigner=\Closure::fromCallable($signer); $this->shortcake?->setSigner($this->passkeySigner); }

    /**
     * @return array<string,mixed> Server acknowledgement; delivery/read receipts arrive as events. */
    public function sendText(string $to,string $text): array {
        return $this->sendMessage($to,$text);
    }
    /** Explicit notice: text is the MVO message; native requires visible text and declares third-party handling.
     * @param array<string,mixed> $options
     * @return array<string,mixed> Server acknowledgement; delivery/read receipts arrive as events. */
    public function sendProviderNotice(string $to, array $options = []): array {
        return $this->sendMessage($to, [...$options, 'type'=>'provider-notice']);
    }
    /** Resolve a phone number, existing JID or username using this session's authenticated connection. */
    public function resolveRecipient(string $to):string {
        $to=\MVO\Service\RecipientResolver::normalize($to);
        if (!\MVO\Service\RecipientResolver::isUsername($to)) return $to;
        return (new \MVO\Service\RecipientResolver($this->profile()))->resolve($to);
    }
    /**
     * @param string|array<string,mixed> $content
     * @return array<string,mixed> */
    public function sendMessage(string $to,string|array $content): array {
        if (!$this->isConnected() || $this->messages === null) throw new \LogicException('WhatsApp is not connected');
        $to=$this->resolveRecipient($to);
        if (Jid::recipient($to)->server==='newsletter') return $this->newsletter()->send($to,$content);
        return $this->messages->sendMessage($to,$content);
    }
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function sendImage(string $to,string $path,array $options=[]):array { return $this->sendFile($to,'image',$path,$options); }
    /** @param list<array<string,mixed>> $cards
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function sendCarousel(string $to,array $cards,array $options=[]):array { return $this->message()->sendCarousel($this->resolveRecipient($to),$cards,$options); }
    /** @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function sendChannelInvite(string $to,string $channelJid,array $options=[]):array { return $this->sendMessage($to,$this->newsletter()->followerInviteContent($channelJid,$options)); }
    /** @param string|array<string,mixed> $content
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function sendSpoiler(string $to,string|array $content,array $options=[]):array { return $this->message()->sendSpoiler($this->resolveRecipient($to),$content,$options); }
    /** @param string|array<string,mixed> $content
     * @return array<string,mixed> */
    public function sendPartialQuote(string $to,string $parentId,string $selectedText,string|array $content,?string $author=null):array { return $this->message()->sendPartialQuote($this->resolveRecipient($to),$parentId,$selectedText,$content,$author); }
    /** @param string|array<string,mixed> $media
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function sendLottieSticker(string $to,string|array $media,array $options=[]):array { return $this->message()->sendLottieSticker($this->resolveRecipient($to),$media,$options); }
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function sendVideo(string $to,string $path,array $options=[]):array { return $this->sendFile($to,'video',$path,$options); }
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function sendGif(string $to,string $mp4Path,array $options=[]):array { return $this->sendFile($to,'gif',$mp4Path,$options); }
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function sendPtv(string $to,string $path,array $options=[]):array { return $this->sendFile($to,'ptv',$path,$options); }
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function sendAudio(string $to,string $path,array $options=[]):array { return $this->sendFile($to,'audio',$path,$options); }
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function sendVoice(string $to,string $path,array $options=[]):array { return $this->sendFile($to,'ptt',$path,$options); }
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function sendDocument(string $to,string $path,array $options=[]):array { return $this->sendFile($to,'document',$path,$options); }
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function sendSticker(string $to,string $path,array $options=[]):array { return $this->sendFile($to,'sticker',$path,$options); }
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    private function sendFile(string $to,string $type,string $path,array $options):array { return $this->sendMessage($to,[...$options,'type'=>$type,'media'=>['path'=>$path]]); }
    /**
     * @param array<string,mixed> $message Plaintext is returned only after MAC and hashes verify. */
    public function downloadMedia(array $message,?string $destination=null):string {
        if (!$this->isConnected() || $this->messages === null) throw new \LogicException('WhatsApp is not connected');
        return $this->messages->downloadMedia($message,$destination);
    }
    /** @param array<string,mixed> $content
     * @return array<string,mixed> Uploaded WAProto envelope. */
    public function prepareMedia(array $content):array {
        if (!$this->isConnected() || $this->messages === null) throw new \LogicException('WhatsApp is not connected');
        return $this->messages->prepareMedia($content);
    }
    /** @param list<array<string,mixed>> $items
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function sendAlbum(string $to, array $items, array $options = []): array {
        if (!$this->isConnected() || $this->messages === null) throw new \LogicException('WhatsApp is not connected');
        return $this->messages->sendAlbum($to, $items, $options);
    }
    /** @param array<string,mixed> $quiz
     * @return array<string,mixed> */
    public function sendQuiz(string $to, array $quiz): array { return $this->sendMessage($to, [...$quiz, 'type'=>'quiz']); }
    /** @param array<string,mixed> $music
     * @return array<string,mixed> */
    public function sendMusic(string $to, array $music): array { return $this->sendMessage($to, [...$music, 'type'=>'music']); }
    /** Upload the image and audio independently, then send the native sticker envelope.
     * @param string|array<string,mixed> $sticker
     * @param string|array<string,mixed> $audio
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function sendAudioSticker(string $to, string|array $sticker, string|array $audio, array $options = []): array {
        return $this->sendMessage($to, [...$options, 'type'=>'audio-sticker', 'media'=>$sticker, 'audio'=>$audio]);
    }
    /** Request the existing Android location action; format=text sends manual instructions, protocol is experimental.
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function requestLocation(string $to, array $options = []): array { return $this->sendMessage($to, [...$options, 'type'=>'request-location']); }
    /** Experimental cancellation of format=protocol; does not revoke an interactive button or text instruction.
     * @param array<string,mixed> $key
     * @return array<string,mixed> */
    public function cancelLocationRequest(string $to, array $key): array { return $this->sendMessage($to, ['type'=>'cancel-location-request', 'target'=>$key]); }
    /** @param array<string,mixed> $theme
     * @return array<string,mixed> */
    public function setChatTheme(string $to, array $theme): array { return $this->sendMessage($to, ['type'=>'chat-theme', 'theme'=>$theme]); }
    public function themes(): \MVO\Service\ChatThemeService { return new \MVO\Service\ChatThemeService(fn(string $to, array $content): array => $this->sendMessage($to, $content)); }
    public function interactions(): \MVO\History\InteractionStore {
        if ($this->messages===null) throw new \LogicException('Start the authenticated session before querying interactions');
        return $this->messages->interactions();
    }
    /** @return array<string,mixed> */
    public function resetChatTheme(string $to): array { return $this->sendMessage($to, ['type'=>'reset-chat-theme']); }
    /**
     * @return array<string,mixed> */
    public function retryMessage(string $id): array {
        if (!$this->isConnected() || $this->messages === null) throw new \LogicException('WhatsApp is not connected');
        return $this->messages->retryMessage($id);
    }
    /**
     * @return array<string,mixed>|null */
    public function getMessageStatus(string $id): ?array {
        if ($this->messages === null) throw new \LogicException('Call connect() and wait for authenticated login first');
        return $this->messages->getMessageStatus($id);
    }
    private function initializeMessages(): void {
        if ($this->messages === null) {
            $signalStore = new SignalStore($this->store->directory.'/state.sqlite',$this->sessionId,$this->backendFactory===null ? null : ($this->backendFactory)($this->sessionId));
            $this->stateStore=$signalStore;
            $this->abprops=new AbPropsService(fn(BinaryNode $node,float $timeout):BinaryNode=>$this->query($node,$timeout),$signalStore,$this->clock);
            $this->maintenancePending=$this->automaticMaintenance;
            $mediaQuery=fn(BinaryNode $node):BinaryNode=>$this->query($node);
            $this->mediaTransfer=$this->mediaTransferFactory===null ? new MediaTransfer($mediaQuery,maxBytes:$this->mediaMaxBytes,proxy:$this->proxy,processor:$this->mediaProcessor ?? new MediaProcessor()) : ($this->mediaTransferFactory)($mediaQuery);
            $signal = new SignalProtocol($signalStore,$this->credentials);
            $this->messages = new MessageService($signal,$signalStore,
                fn():array=>$this->credentials,
                function(array $credentials):void { $this->save($credentials); },
                fn(BinaryNode $node,float $timeout=30):BinaryNode=>$this->query($node,$timeout,($node->attrs['xmlns']??'')==='encrypt' && ($node->child('digest')!==null || $node->child('rotate')!==null)),
                function(BinaryNode $node):void { $this->sendNode($node); },
                function(string $event,array $payload):void { $this->messageEvent($event,$payload); },
                $this->mediaTransfer,
                function(float $timeout):void { $this->poll($timeout); },
                fn(string $to):string=>$this->resolveRecipient($to),
            );
            $this->messages->setGroupStatusGuard(function():void{($this->groupStatusGuard)?->__invoke();});
            $this->newsletterInbox=new NewsletterInbox($signalStore,$this->messages->history()->store,function(string $event,array $payload):void { $this->emit($event,$payload); });
            $this->appStateData=new AppStateProjector($signalStore,$this->messages->history()->store);
            $this->messages->privacyTokens()->setConfigOverrides(function():array {
                $out=[];foreach(['durationS'=>'tctoken_duration','numBuckets'=>'tctoken_num_buckets','senderDurationS'=>'tctoken_duration_sender','senderNumBuckets'=>'tctoken_num_buckets_sender'] as $key=>$name){$value=$this->abprops()->getConfigValue($name);if(!is_int($value))throw new \UnexpectedValueException('Invalid typed privacy token configuration');$out[$key]=$value;}return $out;
            });
            $salt=$this->appStateData->getSetting('NctSaltSync')['salt']??null;
            if(is_string($salt))$this->messages->privacyTokens()->handleNctSaltSync($salt);
            $this->appState=new AppStateService($signalStore,
                fn(BinaryNode $node,float $timeout=30):BinaryNode=>$this->query($node,$timeout),
                function(string $event,array $payload):void { $this->appStateEvent($event,$payload); },
                fn(array $descriptor):string=>$this->mediaTransfer->download($descriptor),
                function(string $sender):bool { return self::sameUser($sender,$this->credentials['meJid'] ?? '') || self::sameUser($sender,$this->credentials['meLid'] ?? ''); },
                fn(string $chat):array=>$this->appStateData->messageRange($chat),
            );
            $this->appState->setProjector($this->appStateData);
            $this->stateFeatures();
            if ($this->isPrimary()) {
                $this->appState->setPrimaryMode(true);
                $this->mobile=new MobileService($signalStore,fn():array=>$this->credentials,
                    fn(BinaryNode $node,float $timeout=30):BinaryNode=>$this->query($node,$timeout),
                    fn(string $device,array $message):array=>$this->message()->publishProtocolMessage($device,$message['protocolMessage'] ?? $message),
                    $this->appState,function(string $event,array $payload):void { $this->emit($event,$payload); },
                    fn():bool=>$this->isPrimary(),function(float $timeout):void { $this->poll($timeout); },
                    fn(string $bytes):array=>$this->mediaTransfer->upload($bytes,'md-msg-hist'));
            }
            $this->appState->requestSync(); $this->nextAppStateSyncAt=$this->now();
        }
        $this->messages->initialize();
    }
    /** @param array<string,mixed> $payload */
    private function messageEvent(string $event,array $payload):void {
        if(in_array($event,['message','message_protocol'],true)&&$this->modernState!==null){
            foreach($this->modernState->ingestAuthenticatedEvent($payload)as$projection)$this->emit($projection['name'],$projection['payload']);
        }
        if ($event==='message_protocol' && isset($payload['proto']) && ($payload['authenticatedOwnDevice'] ?? false)===true) {
            $sender=$payload['authenticatedSender'] ?? '';
            try { $this->appState?->handleProtocol($payload['protocolMessage'] ?? $payload['proto'],['sender'=>$sender,'authenticatedOwnDevice'=>true]); }
            catch (\Throwable $e) { $this->emit('appstate_error',['error'=>$e->getMessage()]); }
        }
        $this->emit($event,$payload);
    }
    /** @param array<string,mixed> $payload */
    private function appStateEvent(string $event,array $payload):void {
        if($event==='appstate_mutation' && $this->messages!==null){$action=AppStateSchema::parse($payload);if(($action['schema']??'')==='NctSaltSync')$this->messages->privacyTokens()->handleNctSaltSync(($action['operation']??'')==='remove'?null:($action['salt']??null));}
        if ($event==='appstate_keys') $this->nextAppStateSyncAt=min($this->nextAppStateSyncAt,$this->now()+0.5);
        if ($event==='appstate_missing_keys' && ($payload['keyIds'] ?? [])!==[]) {
            $ids=[]; foreach ($payload['keyIds'] as $id) if (is_string($id) && $id!=='') $ids[]=['keyId'=>$id];
            if ($ids!==[]) $this->queuePeerProtocol(Jid::parse($this->credentials['meJid'])->canonical()->bare(),['type'=>7,'appStateSyncKeyRequest'=>['keyIds'=>$ids]]);
        }
        if ($event==='appstate_key_share_requested' && is_string($payload['to'] ?? null) && is_array($payload['message']['protocolMessage'] ?? null)) $this->queuePeerProtocol($payload['to'],$payload['message']['protocolMessage']);
        $this->emit($event,$payload);
    }
    /** @param array<string,mixed> $protocol */
    private function queuePeerProtocol(string $to,array $protocol):void {
        $bytes=BinaryData::encode(['to'=>$to,'protocol'=>$protocol]);
        $this->stateStore?->set('appstate-peer-pending',hash('sha256',$bytes),$bytes);
    }
    private function drainAppState():void {
        if ($this->now()<$this->nextAppStateSyncAt || $this->stateStore===null || $this->appState===null || $this->messages===null) return;
        $this->nextAppStateSyncAt=$this->now()+30;
        foreach ($this->stateStore->entries('appstate-peer-pending',10) as $key=>$bytes) {
            $job=BinaryData::decode($bytes);
            try { $this->messages->publishProtocolMessage($job['to'],$job['protocol']); $this->stateStore->delete('appstate-peer-pending',(string)$key); }
            catch (\Throwable $e) { $this->emit('appstate_error',['error'=>$e->getMessage(),'operation'=>'key_exchange']); return; }
            if (!$this->running) return;
        }
        if ($this->appState->needsSync()) {
            try { $this->appState->sync(); }
            catch (\Throwable $e) { $this->emit('appstate_error',['error'=>$e->getMessage(),'operation'=>'sync']); }
        }
    }
    public function poll(float $timeoutSeconds = 0.5): void {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds < 0 || $timeoutSeconds > 30) throw new \InvalidArgumentException('Invalid poll timeout');
        if (!$this->running) throw new \LogicException('Call connect() first');
        if ($this->session === null) { $this->resume($timeoutSeconds); return; }
        try {
            $this->read($timeoutSeconds);
            if (!$this->running) return;
            if (!$this->queryInFlight) { $this->shortcake?->drainPending(); $this->offline?->tick(); }
            if (!$this->isRunning()) return;
            if (!$this->reconnectRequested && $this->postLoginPending && !$this->queryInFlight) {
                $loginSession=$this->session; $loginNumber=$this->successfulConnections; $loginJid=$this->accountJid();
                $this->postLoginPending = false;
                $this->initializeMessages();
                if (!$this->sameAuthenticatedLogin($loginSession,$loginNumber,$loginJid)) return;
                $this->query(new BinaryNode('iq',['type'=>'set','xmlns'=>'passive','to'=>'s.whatsapp.net'],[new BinaryNode('active')]),15);
                if (!$this->sameAuthenticatedLogin($loginSession,$loginNumber,$loginJid)) return;
                if ($this->markOnlineOnConnect) {
                    $attrs=['type'=>'available']; $name=$this->credentials['meDisplayName']??null;
                    if (is_string($name) && $name!=='') $attrs['name']=$name;
                    $this->sendNode(new BinaryNode('presence',$attrs));
                    if (!$this->sameAuthenticatedLogin($loginSession,$loginNumber,$loginJid)) return;
                }
                $connectedAt=$this->now();
                if (!$this->sameAuthenticatedLogin($loginSession,$loginNumber,$loginJid)) return;
                $this->connectedAt = $this->lastPing = $connectedAt;
                $this->state('connected');
            }
            if (!$this->reconnectRequested && $this->isConnected() && !$this->tickVoip()) return;
            if (!$this->reconnectRequested && $this->isConnected() && !$this->queryInFlight) {
                $this->drainMaintenance();
                if(!$this->isRunning())return;
                if ($this->mobile!==null) {
                    if (!$this->mobileInitialized) { $this->mobile->initializeAppState(); $this->mobileInitialized=true; }
                    $this->mobile->drainPending();
                }
                $this->messages?->drainPending();
                $this->newsletterInbox?->drainPending();
                if (!$this->running || $this->state!=='connected') return;
                $this->drainAppState();
                if (!$this->running || $this->state!=='connected') return;
                try{foreach($this->channelSchedules()->drain(1)as$result)$this->emit('channel_schedule',$result);}catch(\Throwable $error){$this->emit('service_error',['service'=>'channel_schedules','error'=>$error->getMessage()]);}
                if (!$this->running || $this->state!=='connected') return;
                try{foreach($this->messageSchedules()->drain(1)as$result)$this->emit('message_schedule',$result);}catch(\Throwable $error){$this->emit('service_error',['service'=>'message_schedules','error'=>$error->getMessage()]);}
                if (!$this->running || $this->state!=='connected') return;
                try{foreach($this->publicationBatches()->drain(1)as$result)$this->emit('publication_batch',$this->publicationBatches()->publicRecord($result));}catch(\Throwable $error){$this->emit('service_error',['service'=>'publication_batches','error'=>$error->getMessage()]);}
                if (!$this->running || $this->state!=='connected') return;
            }
            // Noise is already established while waiting for a QR/code or login
            // approval. Keep that socket alive without marking the account online.
            if (!$this->reconnectRequested && !$this->needsPairingRefresh() && !$this->queryInFlight && $this->session!==null && in_array($this->state,['pairing','authenticating','connected'],true) && $this->now()-$this->lastPing>=20) {
                $this->query(new BinaryNode('iq',['type'=>'get','xmlns'=>'w:p','to'=>'s.whatsapp.net'],[new BinaryNode('ping')]),15);
                if (!$this->running || $this->reconnectRequested || $this->session===null) return;
                $this->lastPing = $this->now();
                if ($this->connectedAt !== null && $this->now()-$this->connectedAt>=60) {$this->reconnectAttempt = 0;$this->versionRefreshed=false;}
            }
        } catch (SessionRestartRequired $restart) {
            if (!$this->running) return;
            if (!$this->reconnectRequested) {
                if ($this->reconnectAt !== null) return;
                if (!$this->handleFailure($restart)) throw $restart;
            }
        } catch (\Throwable $e) {
            if (!$this->running) {
                if ($this->state === 'stopped') return;
                throw $e;
            }
            if ($this->reconnectAt !== null) return;
            if (!$this->handleFailure($e)) throw $e;
        }
        if ($this->running && ($this->reconnectRequested || $this->needsPairingRefresh())) {
            // Exhausting otherwise healthy registration refs is normal renewal,
            // not another failed network attempt with an increasing backoff.
            if (!$this->reconnectRequested) $this->reconnectAttempt=0;
            $restart = new ConnectionFailure($this->reconnectRequested ? 'WhatsApp requested session restart' : 'Pairing references exhausted; requesting new references','515',true);
            if (!$this->handleFailure($restart)) throw $restart;
            $this->resume(0);
        }
    }
    private function needsPairingRefresh():bool {
        // A valid code can outlive the last QR. Refresh only after both have
        // expired, outside callbacks and pending hello/finish/ping queries.
        return $this->autoReconnect && !$this->queryInFlight && !$this->isRegistered() && $this->pairingRefsExpired && !$this->pairingCodeActive;
    }
    /** Post-login queries and injected transports can invoke callbacks that stop or replace this login. */
    private function sameAuthenticatedLogin(?NodeSession $session,int $loginNumber,?string $jid):bool {
        return $this->running && !$this->reconnectRequested && $session!==null && $this->session===$session
            && $this->loginReceived && $this->successfulConnections===$loginNumber && $this->isRegistered()
            && $jid!==null && $jid!=='' && $this->accountJid()===$jid;
    }
    private function drainMaintenance():void {
        if(!$this->automaticMaintenance || $this->messages===null)return;
        if($this->maintenancePending){
            $this->maintenancePending=false;
            try{$this->messages->passiveTasks()->run();}catch(\Throwable $e){$this->emit('service_error',['operation'=>'signal.maintenance','error'=>$e->getMessage()]);}
            if(!$this->running || $this->state!=='connected')return;
        }
        try{$this->abprops?->tick();}catch(\Throwable $e){$this->emit('service_error',['operation'=>'abprops.sync','error'=>$e->getMessage()]);}
    }
    /** Event callbacks invoked while polling media may stop or reconnect the client. */
    private function tickVoip():bool {
        $this->voip?->tick(!$this->queryInFlight);
        return $this->running && $this->state==='connected';
    }
    private function read(float $timeout): void {
        foreach($this->voip?->getCalls()??[] as $call)if(in_array($call['state'],['connecting','connected'],true)){$timeout=min($timeout,0.01);break;}
        // Pairing expiration uses wall-clock timestamps; lifecycle deadlines use monotonic time.
        $this->pairing?->tick(microtime(true));
        $this->shortcake?->tick();
        $node = $this->session?->receiveNode($timeout);
        if ($node === null) return;
        $this->emit('node',['node'=>$node]);
        if (!$this->running) return;
        $id = $node->attrs['id'] ?? '';
        if (array_key_exists($id,$this->pending) && (
            ($node->tag === 'iq' && $this->pendingTags[$id] === 'iq' && in_array($node->attrs['type'] ?? '',['result','error'],true)) ||
            ($node->tag === 'ack' && in_array($this->pendingTags[$id],['message','call'],true) && ($node->attrs['class'] ?? '') === $this->pendingTags[$id])
        )) { $this->pending[$id] = $node; return; }
        if ($this->isRegistered() && $node->tag === 'iq' && $node->child('pair-device') !== null) throw new ConnectionFailure('WhatsApp refused the saved linked identity','401',false,true);
        if ($this->shortcake?->handleNode($node)) return;
        if ($this->mobile?->handleNode($node)) return;
        if (!$this->isRegistered() && $this->pairing?->handleNode($node)) return;
        if ($node->tag === 'success') {
            if (!$this->isRegistered()) throw new \RuntimeException('Server login success without a verified pairing identity');
            if ($this->loginReceived) return;
            $this->loginReceived = true;
            $this->successfulConnections++;
            $this->credentials['loginCounter'] = ($this->credentials['loginCounter'] ?? 0)+1;
            $this->credentials['meLid'] = $node->attrs['lid'] ?? $this->credentials['meLid'];
            $this->store->save($this->credentials); $this->removeQr(); $this->postLoginPending = true; return;
        }
        if ($node->tag === 'stream:error') {
            $code = $node->attrs['code'] ?? '';
            if ($code === '515' && $this->isRegistered()) { $this->reconnectRequested = true; return; }
            $reason = $node->child('conflict')?->attrs['type'] ?? '';
            $revoked = $code === '401' || in_array($reason,['device_removed','device-removed','logged_out'],true);
            throw new ConnectionFailure('WhatsApp stream error: '.$code.' '.$reason,$code,!$revoked && self::retryCode($code),$revoked);
        }
        if ($node->tag === 'failure') {
            $code = $node->attrs['reason'] ?? 'unknown';
            throw new ConnectionFailure('WhatsApp authentication rejected: '.$code,$code,self::retryCode($code),$code==='401');
        }
        $routing=$node->child('edge_routing')?->child('routing_info');
        if ($routing!==null && is_string($routing->content) && strlen($routing->content)<=65535 && ($this->credentials['routingInfo'] ?? null)!==$routing->content) {
            $this->credentials['routingInfo']=$routing->content; $this->store->save($this->credentials);
        }
        if ($node->tag==='notification' && ($node->attrs['type'] ?? '')==='server_sync') { $this->appState?->requestSync(); $this->nextAppStateSyncAt=min($this->nextAppStateSyncAt,$this->now()); }
        $this->offline?->handleNode($node);
        if (!$this->running) return;
        // Own-device peer traffic contains protocol keys/history and cannot be ignored.
        if (in_array($node->tag,['message','receipt','notification','call'],true) && ($node->attrs['category'] ?? '')!=='peer') {
            foreach ($this->incomingFilters as $filter) if ($filter($node)) {
                $attrs=['class'=>$node->tag,'id'=>$node->attrs['id'] ?? '', 'to'=>$node->attrs['from'] ?? 's.whatsapp.net'];
                foreach (['type','participant'] as $key) if (isset($node->attrs[$key])) $attrs[$key]=$node->attrs[$key];
                $this->sendNode(new BinaryNode('ack',$attrs)); return;
            }
        }
        foreach ($this->incomingHandlers as $handler) if ($handler($node)) return;
        if (($node->tag==='call' || ($node->tag==='ack' && ($node->attrs['class']??'')==='call')) && $this->messages!==null && $this->voip()->handleNode($node)) return;
        if ($this->dispatchServiceEvents($node)) return;
        if ($this->messages?->handleNode($node)) return;
        if ($node->tag === 'iq' && ($node->attrs['type'] ?? '') === 'get' && $node->child('ping') !== null) {
            $this->sendNode(new BinaryNode('iq',['type'=>'result','id'=>$node->attrs['id'] ?? '', 'to'=>$node->attrs['from'] ?? 's.whatsapp.net']));
        }
    }
    /** Parse service events before the generic message handler consumes their stanzas. */
    private function dispatchServiceEvents(BinaryNode $node):bool {
        $newsletter=$node->tag==='message' && str_ends_with($node->attrs['from'] ?? '','@newsletter');
        $entries=IncomingEvents::parse($node); $channelEntries=[]; $otherEntries=[];
        foreach ($entries as $entry) {
            $chat=$entry['data']['chat'] ?? $entry['data']['chatJid'] ?? '';
            if (is_string($chat) && str_ends_with($chat,'@newsletter') && in_array($entry['event'],['message','newsletter_message','newsletter_update','channel_update','service_error'],true)) $channelEntries[]=$entry;
            else $otherEntries[]=$entry;
        }
        if ($channelEntries!==[] && $this->newsletterInbox!==null) $this->newsletterInbox->accept($node,$channelEntries);
        if (!$this->isRunning()) return true;
        foreach ($otherEntries as $entry) {
            $event=$entry['event']; $payload=$entry['data'];
            if($event==='mex_notification'&&($payload['kind']??null)==='lid_change'){
                if($this->stateStore===null||!$this->isRegistered())continue;
                try{
                    if(!(new IdentityService($this->stateStore))->changeLid($payload['oldLidJid'],$payload['newLidJid']))continue;
                }catch(\UnexpectedValueException){$this->emit('service_error',['operation'=>'identity.lid_change','reason'=>'identity_conflict','message'=>'Identity transition rejected']);continue;}
            }
            $this->emit($event,$payload);
            if (!$this->running) return true;
        }
        if ($newsletter) {
            $this->sendNode(new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id'] ?? '', 'to'=>$node->attrs['from']]));
            return true;
        }
        return false;
    }
    private static function retryCode(string $code): bool { return $code === '408' || $code === '515' || (ctype_digit($code) && (int)$code >= 500 && (int)$code <= 599); }
    private function isTransient(\Throwable $error): bool {
        if ($error instanceof ConnectionFailure) return $error->retryable;
        if ($error instanceof \UnexpectedValueException && preg_match('/^Server refused WebSocket upgrade: HTTP\/1\.[01] (408|5[0-9]{2})(?: |$)/',$error->getMessage())) return true;
        if (!$error instanceof \RuntimeException || $error instanceof \UnexpectedValueException) return false;
        foreach (['Mobile TCP connection failed:','Mobile TCP socket closed','Mobile TCP read failed or connection closed','Mobile TCP write timed out','Mobile TCP write failed','Mobile TCP select failed'] as $prefix) if (str_starts_with($error->getMessage(),$prefix)) return true;
        foreach (['WebSocket connection failed:','WebSocket connection ended','WebSocket closed by peer (1000)','WebSocket closed by peer (1001)','WebSocket closed by peer (1006)','WebSocket closed by peer (1011)','WebSocket closed by peer (1012)','WebSocket closed by peer (1013)','WebSocket write failed','WebSocket write timed out','WebSocket stream selection failed','WebSocket upgrade timed out','Noise server hello timed out','WhatsApp IQ timeout:'] as $prefix) {
            if (str_starts_with($error->getMessage(),$prefix)) return true;
        }
        return false;
    }
    /** Returns true when the failure is scheduled for automatic recovery. */
    private function handleFailure(\Throwable $error): bool {
        if ($error instanceof ConnectionFailure && $error->serverCode==='405' && !$error->revoked && $this->mobileDeviceInfo===null && !$this->versionRefreshed && $this->autoReconnect && $this->running) {
            $this->versionRefreshed=true;
            $this->closeTransport();$this->state('reconnecting');
            if(!$this->running)return true;
            try {
                $version=($this->versionResolver)();
                if(!$this->running)return true;
                WaVersion::validate($version);
                if ($version!==$this->activeVersion) {
                    $credentials=[...$this->credentials,'webVersion'=>$version];$this->store->save($credentials);
                    $this->activeVersion=$version;$this->credentials=$credentials;
                    $this->emit('version_updated',['version'=>$version]);
                    if(!$this->running)return true;
                    $error=new ConnectionFailure('WhatsApp version updated; reconnecting','515',true);
                }else{
                    $this->emit('version_error',['message'=>'The official WhatsApp Web version matches the rejected version']);
                    if(!$this->running)return true;
                }
            } catch (\Throwable $refreshError) { $this->emit('version_error',['message'=>$refreshError->getMessage()]);if(!$this->running)return true; }
        }
        $serverCode = $error instanceof ConnectionFailure || $error instanceof \MVO\Service\ServiceException ? $error->serverCode : null;
        if (!is_string($serverCode) || preg_match('/^[0-9]{1,5}$/D',$serverCode)!==1) $serverCode=null;
        $failureDetails=['type'=>get_class($error),'serverCode'=>$serverCode];
        $retry = $this->autoReconnect && $this->running && $this->isTransient($error);
        $immediate = $error instanceof ConnectionFailure && $error->serverCode === '515' && $this->reconnectAttempt === 0;
        $revoked = $error instanceof ConnectionFailure && $error->revoked;
        $this->closeTransport();
        if ($revoked && $this->credentials !== []) {
            $this->credentials['revoked'] = true;
            $this->store->save($this->credentials);
        }
        if ($retry) {
            $this->reconnectAttempt++;
            $base = min($this->reconnectMaxSeconds,$this->reconnectMinSeconds*(2 ** min(20,$this->reconnectAttempt-1)));
            $delay = $immediate ? 0.0 : min($this->reconnectMaxSeconds,$base*(0.75+0.5*max(0.0,min(1.0,($this->random)()))));
            $this->reconnectAt = $this->now()+$delay;
            $this->state('reconnecting');
            $this->emit('reconnect',['attempt'=>$this->reconnectAttempt,'delay'=>$delay,'reason'=>$error->getMessage(),...$failureDetails]);
            return true;
        }
        $this->running = false; $this->reconnectAt = null;
        $this->store->close(); $this->disposePlugins(); $this->state($revoked ? 'logged_out' : 'disconnected');
        if ($revoked) $this->emit('logged_out',['reason'=>$error->getMessage(),'code'=>$error->serverCode]);
        $this->emit('error',['message'=>$error->getMessage(),'retrying'=>false,...$failureDetails]);
        return false;
    }
    private function resume(float $timeout): void {
        if (!$this->running || $this->reconnectAt === null) return;
        $remaining = $this->reconnectAt-$this->now();
        if ($remaining>0) {
            if ($timeout>0) usleep((int)(min($timeout,$remaining,0.5)*1000000));
            if (!$this->running || $this->now()<$this->reconnectAt) return;
        }
        try { $this->open(); }
        catch (\Throwable $error) { if (!$this->handleFailure($error)) throw $error; }
    }
    /** Blocking process loop. durationSeconds=0 continues until stop or a fatal failure. */
    public function run(float $durationSeconds = 0): void {
        if (!is_finite($durationSeconds) || $durationSeconds < 0) throw new \InvalidArgumentException('Invalid run duration');
        $deadline = $durationSeconds>0 ? $this->now()+$durationSeconds : INF;
        while ($this->running && $this->now()<$deadline) $this->poll(min(0.5,max(0,$deadline-$this->now())));
    }
    private function removeQr(): void { $path = $this->store->directory.'/qr.svg'; if (is_file($path)) unlink($path); }
    /** Close only the socket; retries retain the exclusive directory lock. */
    private function closeTransport(): void {
        $session = $this->session; $this->session = null;
        try { $session?->close(); } catch (\Throwable) { }
        $voip=$this->voip; $this->voip=null;
        try { $voip?->close(); } catch (\Throwable) { }
        $this->pairing?->clear(); $this->pairing = null; $this->messages?->close(); $this->messages = null;
        $this->shortcake?->clear(); $this->shortcake=null; $this->offline?->reset(); $this->offline=null;
        $this->stateStore=null; $this->appState=null; $this->appStateData=null; $this->modernState=null; $this->channelScheduler=null; $this->messageScheduler=null; $this->historyFeaturesService=null; $this->publicationBatchService=null; $this->services=null; $this->mediaTransfer=null;
        $this->mobile=null; $this->mobileInitialized=false;
        $this->newsletterInbox=null;
        $this->abprops=null;$this->maintenancePending=false;
        $this->pending = []; $this->pendingTags = [];
        $this->postLoginPending = false; $this->pairingReady = false; $this->pairingCodeActive = false; $this->pairingRefsExpired = false; $this->reconnectRequested = false;
        $this->connectedAt = null;
        if ($this->store->ownsLock()) $this->removeQr();
    }
    /** Stop deliberately. Durable credentials and message state remain available for connect(). */
    public function disconnect(): void {
        $started = $this->running || $this->session !== null;
        $this->running = false; $this->reconnectAt = null;
        $this->closeTransport(); $this->store->close(); $this->disposePlugins();
        if ($started) $this->state('stopped');
    }
    public function stop(): void { $this->disconnect(); }
    /** Explicitly removes this linked device from the account; stored identity is retained as revoked. */
    public function logout(string $reason='user_initiated'):void {
        if (!$this->isConnected() || !is_string($this->credentials['meJid'] ?? null)) throw new \LogicException('Logout requires an authenticated session');
        if (!preg_match('/^[a-z_]{1,64}$/D',$reason)) throw new \InvalidArgumentException('Invalid logout reason');
        $this->query(new BinaryNode('iq',['type'=>'set','to'=>'s.whatsapp.net','xmlns'=>'md'],[new BinaryNode('remove-companion-device',['jid'=>$this->credentials['meJid'],'reason'=>$reason])]));
        $this->credentials['revoked']=true; $this->store->save($this->credentials);
        $this->disconnect(); $this->state('logged_out'); $this->emit('logged_out',['reason'=>$reason,'code'=>'401']);
    }
    private function disposePlugins():void { try { $this->plugins->dispose(); } catch (\Throwable $e) { $this->emit('plugin_error',['message'=>$e->getMessage()]); } }
    public function __destruct() {
        $this->running = false; $this->closeTransport(); $this->store->close(); $this->disposePlugins();
    }
}
