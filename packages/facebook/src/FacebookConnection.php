<?php
declare(strict_types=1);

namespace MVO\Facebook;

use MVO\Facebook\Monitoring\SnapshotMonitor;
use MVO\Facebook\Service\{AdsService,CommentsService,InboxService,PagesService,PostsService,ReactionsService,MediaService,PageAdministrationService,InsightsService,AlbumService,ModerationService,ContentInsightsService};
use MVO\Messenger\Auth\{CookieJar,LoginService};
use MVO\Messenger\MessengerClient;
use MVO\Messenger\Transport\{HttpClient,Socket};

/** Owns one native PHP Facebook login, durable session and optional Web services.
 * Authentication and locks are delegated to the shared account runtime, never copied.
 */
final class FacebookConnection
{
    private readonly MessengerClient $owner;
    private ?FacebookClient $facebook=null;

    /** Optional transports/factories allow isolated tests without a real account.
     * @param callable(string,string,array<string,string>):Socket|null $socketFactory
     * @param callable(list<string>):list<string>|null $bundleLoader
     */
    public function __construct(
        public readonly string $sessionId,
        string $authDirectory,
        private readonly string $privateStorageDirectory,
        ?HttpClient $httpClient=null,
        ?callable $socketFactory=null,
        ?callable $bundleLoader=null,
        float $timeoutSeconds=20.0,
    ) {
        foreach([$authDirectory,$privateStorageDirectory] as $directory){
            if(trim($directory)===''||str_contains($directory,"\0")||preg_match('~^[a-z][a-z0-9+.-]*://~i',$directory))throw new \InvalidArgumentException('Use local private directories for the Facebook session and services');
        }
        $this->owner=new MessengerClient($sessionId,$authDirectory,$httpClient,$socketFactory,$bundleLoader,$timeoutSeconds);
    }

    /** Saves only the confirmed account session. Call connect() afterwards.
     * The optional service is an isolated authentication transport for tests.
     */
    public function login(#[\SensitiveParameter] string $email,#[\SensitiveParameter] string $password,?LoginService $service=null):void
    {
        try{$this->owner->login($email,$password,$service);}
        finally{
            if($password!=='')sodium_memzero($password);
            if(!$this->owner->isConnected())$this->closeServices();
        }
    }
    /** Experimental native authentication using the existing Messenger owner. */
    public function loginAndroid(
        #[\SensitiveParameter] string $email,
        #[\SensitiveParameter] string $password,
        #[\SensitiveParameter] \MVO\Messenger\Auth\AndroidLoginProfile $profile,
        ?\MVO\Messenger\Transport\AndroidLoginTransport $transport=null,
    ):void {
        try{$this->owner->loginAndroid($email,$password,$profile,$transport);}
        finally{if($password!=='')sodium_memzero($password);if(!$this->owner->isConnected())$this->closeServices();}
    }

    /** Safe metadata for the current attempt; no cookies or private protocol fields.
     * @return array<string,mixed>|null
     */
    public function loginChallenge():?array{return $this->owner->loginChallenge();}

    /** Explicitly discovers methods actually offered by Facebook for this attempt.
     * @return array<string,mixed>
     */
    public function refreshLoginMethods():array{return $this->owner->refreshLoginMethods();}

    /** Selects/requests one opaque ID returned by the current challenge.
     * @return array<string,mixed>
     */
    public function requestLoginMethod(string $methodId):array{return $this->owner->requestLoginMethod($methodId);}
    /** Experimental explicit native406 SMS request; no delivery guarantee.
     * @return array<string,mixed>
     */
    public function requestLoginSms():array{return $this->owner->requestLoginSms();}

    /** Checks the owner's approval in another app, then verifies and saves the session. */
    public function checkLoginApproval():bool
    {
        try{return $this->owner->checkLoginApproval();}
        finally{if(!$this->owner->isConnected())$this->closeServices();}
    }

    /** Continues the selected supported code challenge and saves its confirmed session. */
    public function confirmLogin(#[\SensitiveParameter] string $code):void
    {
        try{$this->owner->confirmLogin($code);}
        finally{if($code!=='')sodium_memzero($code);if(!$this->owner->isConnected())$this->closeServices();}
    }

    /** Secret handoff for the application's optional human verification bridge.
     * Never expose this result in public responses, logs, events or LLM tools.
     * @return array{url:string,cookies:list<array<string,mixed>>,expires_at:int}
     */
    public function exportPrivateLoginChallenge():array{return $this->owner->exportPrivateLoginChallenge();}

    /** Private handoff for an explicit browser login without a prior password submission.
     * @return array{url:string,cookies:list<array<string,mixed>>,expires_at:int}
     */
    public function beginBrowserLogin():array{return $this->owner->beginBrowserLogin();}
    public function cancelBrowserLogin():void{$this->owner->cancelBrowserLogin();}

    /** Verifies the identity via HTTPS before saving the human-confirmed session. */
    public function completeBrowserLogin(#[\SensitiveParameter] CookieJar $cookies):void
    {
        $this->owner->completeBrowserLogin($cookies);
        $this->closeServices();
    }

    /** Restores the saved session without repeating email/password authentication. */
    public function connect():void
    {
        if(!$this->owner->isConnected())$this->closeServices();
        $this->owner->connect();
    }

    public function poll(float $timeoutSeconds=0.5):void
    {
        try{$this->owner->poll($timeoutSeconds);}
        finally{if(!$this->owner->isConnected())$this->closeServices();}
    }

    /** Call application queries/commands in the external loop, outside callbacks. */
    public function on(string $event,callable $handler):static{$this->owner->on($event,$handler);return $this;}
    public function isConnected():bool{return $this->owner->isConnected();}
    public function getState():string{return $this->owner->getState();}
    /** has_saved_session describes local storage, not current server validity.
     * @return array<string,mixed>
     */
    public function accountInfo():array{return $this->owner->accountInfo();}
    /** @return list<array<string,mixed>> */
    public function events(int $after=0,int $limit=100):array{return $this->owner->events($after,$limit);}

    /** Stops locally and releases the single owner lock, preserving the saved session. */
    public function stop():void
    {
        try{$this->closeServices();}finally{$this->owner->stop();}
    }

    /** All services use this connection's authenticated identity and owner. */
    public function services():FacebookClient
    {
        // Always enforce the owner's connected/callback guard, including cached access.
        $session=$this->owner->webSession();
        return $this->facebook??=new FacebookClient($session,$this->privateStorageDirectory);
    }
    public function pages():PagesService{return $this->services()->pages();}
    public function posts():PostsService{return $this->services()->posts();}
    public function comments():CommentsService{return $this->services()->comments();}
    public function reactions():ReactionsService{return $this->services()->reactions();}
    public function inbox():InboxService{return $this->services()->inbox();}
    public function media():MediaService{return $this->services()->media();}
    public function albums():AlbumService{return $this->services()->albums();}
    public function moderation():ModerationService{return $this->services()->moderation();}
    public function contentInsights():ContentInsightsService{return $this->services()->contentInsights();}
    public function administration():PageAdministrationService{return $this->services()->administration();}
    public function insights():InsightsService{return $this->services()->insights();}
    /** Explicit device provisioning; does not enable encrypted messaging. */
    public function e2eeDevice(?\MVO\Messenger\E2ee\RegistrationTransport $transport=null):\MVO\Messenger\E2ee\DeviceManager{return $this->owner->e2eeDevice($transport);}
    /** Explicit experimental encrypted lifecycle; ordinary connect() never starts it. */
    public function e2eeRuntime():\MVO\Messenger\E2ee\Runtime{return $this->owner->e2eeRuntime();}
    public function ads():AdsService{return $this->services()->ads();}
    /** @return array<string,mixed> */
    public function listPages():array{return $this->services()->listPages();}
    /** @return array<string,mixed> */
    public function listAdAccounts():array{return $this->services()->listAdAccounts();}
    public function selectPage(string $id):void{$this->services()->selectPage($id);}
    public function selectAdAccount(string $id):void{$this->services()->selectAdAccount($id);}
    /** @return array{account_id:string,page:?array<string,mixed>,ad_account:?array<string,mixed>} */
    public function selectedContext():array{return $this->services()->selectedContext();}
    public function monitor():SnapshotMonitor{return $this->services()->monitor();}
    /** @return array<string,mixed>|null */
    public function command(string $id):?array{return $this->services()->command($id);}

    private function closeServices():void
    {
        $facebook=$this->facebook;$this->facebook=null;$facebook?->close();
    }
    /** @return array<string,mixed> */
    public function __debugInfo():array{return ['session_id'=>$this->sessionId,...$this->accountInfo()];}
    public function __serialize():never{throw new \LogicException('A private Facebook connection cannot be serialized');}
}
