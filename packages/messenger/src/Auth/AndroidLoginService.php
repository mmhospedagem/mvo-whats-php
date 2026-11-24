<?php
declare(strict_types=1);

namespace MVO\Messenger\Auth;

use MVO\Messenger\MessengerException;
use MVO\Messenger\Transport\{AndroidLoginCurlTransport,AndroidLoginTransport,CurlHttpClient,HttpClient,HttpResponse,WebLoginCurlTransport};

/** Experimental native password/code/app-approval authentication into the same account owner.
 * The same cookies remain input to the existing Web/DGW metadata connector.
 * Default login and identity verification use only native JSON endpoints.
 * Native authentication is not E2EE provisioning or proof of Page inbox access.
 */
final class AndroidLoginService implements BrowserIdentityBoundLoginService
{
    private readonly AndroidDeviceIdentity $device;
    private readonly AndroidLoginTransport $transport;
    private readonly HttpClient $identityHttp;
    /** @var array{uid:string,first_factor:string,machine_id:?string}|null */
    private ?array $twoFactor=null;
    /** Native406 app-approval continuation, never persisted or exposed.
     * @var array{uid:string,machine_id:string,auth_token:string}|null
     */
    private ?array $approval=null;
    private int $approvalCheckAt=0;
    private ?string $email=null;
    private int $expiresAt=0;
    private bool $uncertain=false;
    private bool $checkpoint=false;
    private ?CookieJar $browserCookies=null;
    private ?string $browserUrl=null;
    /** Actual 405 context only, held in RAM; an empty context cannot open a browser.
     * @var array{url?:string,flow_id?:string,uid?:string}|null
     */
    private ?array $nativeCheckpoint=null;
    private ?string $expectedAccountId=null;
    private ?WebLoginService $checkpointWeb=null;
    private ?CookieJar $completedLogin=null;
    private bool $checkpointConsumed=false;
    private int $checkpointRefreshAt=0;
    private ?int $checkpointNativeCode=null;
    private string $smsRequestState='not_requested';

    public function __construct(
        private readonly SessionStore $store,
        #[\SensitiveParameter] private readonly AndroidLoginProfile $profile,
        ?AndroidLoginTransport $transport=null,
        ?HttpClient $identityHttp=null,
        private readonly ?WebLoginService $checkpointService=null,
        // Legacy HTTP405 continuation is an explicit compatibility opt-in.
        // loginAndroid() never enables it.
        private readonly bool $allowWebCheckpoint=false,
    ) {
        // No profile callbacks, persistence or remote I/O before the owner lock.
        $this->device=new AndroidDeviceIdentity($store);
        $this->transport=$transport??new AndroidLoginCurlTransport();
        $this->identityHttp=$identityHttp??new CurlHttpClient();
    }

    /** Credential-free application capability/key preflight under the session owner.
     * It never attempts auth/login or creates an authenticated account session.
     */
    public function prepare():AndroidPasswordKey
    {
        $this->requireOwner();
        if($this->challenge()!==null)throw new MessengerException('login_verification_pending','Do not replace the native profile during a pending verification');
        $this->device->ensure();
        $version=$this->profile->passwordVersion();$flow=$this->profile->keyFetchFlow();
        if(!in_array($version,[1,2],true)||preg_match('/^[A-Za-z0-9_]{1,128}$/D',$flow)!==1)throw self::profileInvalid();
        $response=$this->request('POST','/pwd_key_fetch',[
            'method'=>'GET','device_id'=>$this->device->getDeviceId(),'version'=>(string)$version,'flow'=>$flow,
        ],'pwdKeyFetch');
        if($response->status!==200)throw new MessengerException('login_native_profile_rejected','The server did not authorize the native application password-key request');
        return AndroidPasswordKey::fromJson($response->body);
    }

    public function login(#[\SensitiveParameter] string $email,#[\SensitiveParameter] string $password):CookieJar
    {
        try {
            $this->requireOwner();
            if($this->challenge()!==null)throw new MessengerException('login_verification_pending','Complete or cancel the current native authentication');
            if($email===''||strlen($email)>1024||preg_match('/[\x00-\x1f\x7f]/',$email)||preg_match('//u',$email)!==1
                ||$password===''||strlen($password)>4096||preg_match('//u',$password)!==1)throw new \InvalidArgumentException('Invalid native account credentials');
            $this->cancel();$key=$this->prepare();
            $version=$this->profile->passwordVersion();
            $encrypted=AndroidPasswordEncryption::encrypt($key,$password,$version);
            $this->email=$email;
            $response=$this->request('POST','/auth/login',array_replace($this->base(),[
                'email'=>$email,'password'=>$encrypted,'credentials_type'=>'password',
            ]),'authenticate');
            return $this->consume($response);
        } finally {
            if($password!=='')sodium_memzero($password);
            if($this->challenge()===null)$this->cancel();
        }
    }

    public function confirm(#[\SensitiveParameter] string $code):CookieJar
    {
        try {
            $this->requireOwner();
            if($this->challenge()===null)throw new MessengerException('login_challenge_expired','Start a new native authentication before submitting a code');
            if($this->nativeCheckpoint!==null){
                if(!$this->allowWebCheckpoint)throw self::nativeCheckpointUnsupported();
                if($this->checkpointWeb===null)throw new MessengerException('login_checkpoint_required','Consult the original checkpoint before submitting a code; native 406 confirmation cannot replace it');
                $web=$this->checkpointContinuation();
                return $this->finishCheckpoint($web->confirm($code));
            }
            if($this->twoFactor===null||$this->email===null)throw new MessengerException('login_challenge_expired','Start a new native authentication before submitting a code');
            if($this->uncertain)throw new MessengerException('login_code_outcome_uncertain','The previous native confirmation has an uncertain result; it will not be repeated');
            if($code===''||strlen($code)>64||preg_match('/[\x00-\x1f\x7f]/',$code)||preg_match('//u',$code)!==1)throw new \InvalidArgumentException('Invalid native verification code');
            $fields=array_replace($this->base(),['email'=>$this->email,'password'=>$code,'credentials_type'=>'two_factor',
                'twofactor_code'=>$code,'userid'=>$this->twoFactor['uid'],'first_factor'=>$this->twoFactor['first_factor']]);
            // Mark before I/O: a timeout can follow an accepted one-use code.
            $this->uncertain=true;
            $response=$this->request('POST','/auth/login',$fields,'authenticate');
            return $this->consume($response);
        } finally {
            if($code!=='')sodium_memzero($code);
        }
    }

    /** @return array<string,mixed>|null */
    public function challenge():?array
    {
        if($this->expiresAt===0)return null;
        if($this->expiresAt<=time()){$this->cancel();return null;}
        if($this->nativeCheckpoint!==null){
            if(!$this->allowWebCheckpoint)return ['type'=>'checkpoint','expires_at'=>$this->expiresAt,'ready'=>false,
                'methods'=>[],'selected_method'=>null,'requires_code'=>false,'can_check'=>false,'can_refresh'=>false,
                'native_code'=>$this->checkpointNativeCode,'continuation'=>'unsupported_native_checkpoint','native_only'=>true,
                'verification_supported'=>false,'browser_continuation_available'=>false,'outcome_uncertain'=>false];
            $web=$this->checkpointWeb?->challenge();
            if($web!==null)return array_replace($web,['expires_at'=>min($this->expiresAt,$web['expires_at']),
                'native_code'=>405,'continuation'=>'http_checkpoint','browser_continuation_available'=>$this->hasBrowserContinuation()]);
            return ['type'=>'checkpoint','expires_at'=>$this->expiresAt,'ready'=>false,
                'methods'=>[],'selected_method'=>null,'requires_code'=>false,'can_check'=>false,
                'can_refresh'=>$this->browserUrl!==null&&!$this->checkpointConsumed&&time()>=$this->checkpointRefreshAt,
                'retry_after'=>max(0,$this->checkpointRefreshAt-time()),'outcome_uncertain'=>false,
                'native_code'=>405,'continuation'=>'http_checkpoint','browser_continuation_available'=>$this->hasBrowserContinuation()];
        }
        return ['type'=>$this->checkpoint?'checkpoint':'two_factor','expires_at'=>$this->expiresAt,
            'ready'=>!$this->checkpoint&&$this->twoFactor!==null&&!$this->uncertain,
            'methods'=>[],'selected_method'=>null,'requires_code'=>!$this->checkpoint&&$this->twoFactor!==null,
            'can_refresh'=>false,'can_check'=>$this->approval!==null&&!$this->uncertain&&time()>=$this->approvalCheckAt,
            'retry_after'=>max(0,$this->approvalCheckAt-time()),'outcome_uncertain'=>$this->uncertain,
            'browser_continuation_available'=>$this->hasBrowserContinuation(),
            'native_only'=>!$this->allowWebCheckpoint,'native_code'=>$this->checkpoint?405:406,
            'continuation'=>$this->checkpoint?'unsupported_native_checkpoint':($this->approval===null?'native_code':'native_approval'),
            'sms_request_experimental'=>true,'sms_request_state'=>$this->smsRequestState,
            'can_request_sms'=>!$this->checkpoint&&$this->twoFactor!==null&&!$this->uncertain&&$this->smsRequestState==='not_requested'];
    }

    public function refreshMethods():array
    {
        $this->requireOwner();
        if($this->challenge()===null)throw new MessengerException('login_challenge_expired','The native verification has expired');
        if($this->nativeCheckpoint!==null&&!$this->allowWebCheckpoint)throw self::nativeCheckpointUnsupported();
        if($this->nativeCheckpoint===null||$this->browserUrl===null||$this->checkpointConsumed)throw self::methodsUnavailable();
        if(time()<$this->checkpointRefreshAt)throw new MessengerException('login_method_cooldown','Wait before consulting this checkpoint again');
        $this->checkpointRefreshAt=time()+5;
        $adopted=false;
        if($this->checkpointWeb===null){
            $web=$this->checkpointService??new WebLoginService();$this->checkpointWeb=$web;$cookies=null;
            try{
                $cookies=$web->resumeCheckpoint($this->browserUrl,$this->expiresAt);
            }catch(MessengerException $error){
                if($web->challenge()===null){
                    $web->cancel();$this->checkpointWeb=null;
                    throw $error;
                }
                // A fetched security document may offer a recognized 2FA
                // contract, or still require a human CAPTCHA/consent step.
                if(!in_array($error->reason,['login_two_factor_required','login_captcha_required','login_challenge_required','login_consent_required'],true))throw $error;
            }
            if($cookies!==null){$this->completedLogin=$this->finishCheckpoint($cookies);return ['outcome'=>'session_ready'];}
            $adopted=true;
        }
        $web=$this->checkpointWeb;$state=$web->challenge();
        // A complete HTML code form is already usable. Discovery of a separate
        // picker is optional and must not turn that form into an error.
        if(($state['type']??null)==='two_factor'&&(!$adopted||($state['methods']??[])!==[]||($state['ready']??false)!==true))$web->refreshMethods();
        return $this->challenge()??throw new MessengerException('login_challenge_expired','The native verification has expired');
    }

    public function requestMethod(string $methodId):array
    {
        $this->checkpointContinuation()->requestMethod($methodId);
        return $this->challenge()??throw new MessengerException('login_challenge_expired','The native verification has expired');
    }

    /** Explicit experimental native resend. This is not a method catalogue or
     * evidence that SMS is available/delivered for this account. Never auto-retry.
     * @return array{outcome:string,experimental:bool,delivery_confirmed:bool}
     */
    public function requestSms():array
    {
        $this->requireOwner();
        if($this->challenge()===null)throw new MessengerException('login_challenge_expired','The native verification has expired');
        if($this->nativeCheckpoint!==null)throw self::nativeCheckpointUnsupported();
        if($this->twoFactor===null||$this->uncertain)throw self::methodsUnavailable();
        if($this->smsRequestState!=='not_requested')throw new MessengerException('login_sms_already_requested','The native SMS request was already attempted; it will not be repeated');
        // The request may have reached Facebook before a transport failure.
        // This flag does not consume a code or invalidate app-approval polling.
        $this->smsRequestState='uncertain';
        try{
            $response=$this->request('POST','/'.$this->twoFactor['uid'].'/twofacsms',
                ['first_factor'=>$this->twoFactor['first_factor']],'login_approval_resend_code');
        }catch(\Throwable){throw new MessengerException('login_sms_outcome_uncertain','The SMS request result is unknown; no automatic or manual resend is permitted in this attempt');}
        if($this->challenge()===null)throw new MessengerException('login_challenge_expired','The native verification expired during the SMS request');
        if($response->status===429){$this->smsRequestState='rejected';throw new MessengerException('login_rate_limited','Facebook temporarily limited native authentication');}
        try{
            if(strlen($response->body)>2097152)throw new \UnexpectedValueException();
            $document=json_decode($response->body,false,32,JSON_THROW_ON_ERROR);
            if(!$document instanceof \stdClass)throw new \UnexpectedValueException();
            // The APK's RPC reader does not return a delivery result. An empty
            // JSON object is a completed response, not an authenticated session.
            $error=property_exists($document,'error')||property_exists($document,'error_code')
                ?AndroidLoginError::fromJson($response->body):null;
        }catch(\Throwable){throw new MessengerException('login_sms_outcome_uncertain','Facebook did not return a recognized SMS request result');}
        if($response->status!==200||$error!==null){$this->smsRequestState='rejected';throw new MessengerException('login_native_sms_rejected','Facebook did not accept the native SMS request');}
        $this->smsRequestState='submitted';
        return ['outcome'=>'request_submitted','experimental'=>true,'delivery_confirmed'=>false];
    }

    public function checkApproval():?CookieJar
    {
        $this->requireOwner();
        if($this->challenge()===null)throw new MessengerException('login_challenge_expired','The native verification has expired');
        if($this->nativeCheckpoint!==null){
            if(!$this->allowWebCheckpoint)throw self::nativeCheckpointUnsupported();
            $cookies=$this->checkpointContinuation()->checkApproval();
            return $cookies===null?null:$this->finishCheckpoint($cookies);
        }
        if($this->approval===null)throw self::methodsUnavailable();
        if($this->uncertain)throw new MessengerException('login_approval_outcome_uncertain','The previous native authentication has an uncertain result; it will not be repeated');
        if(time()<$this->approvalCheckAt)throw new MessengerException('login_method_cooldown','Wait before checking this native approval again');
        $this->approvalCheckAt=time()+5;
        $response=$this->request('GET','/check_approved_machine',[
            'u'=>$this->approval['uid'],'m'=>$this->approval['machine_id'],
        ],'checkApprovedMachine');
        if($this->challenge()===null)throw new MessengerException('login_challenge_expired','The native verification expired while checking approval');
        if($response->status===429)throw new MessengerException('login_rate_limited','Facebook temporarily limited native authentication');
        if($response->status!==200||AndroidLoginError::fromJson($response->body)!==null)throw new MessengerException('login_native_approval_failed','Facebook did not confirm the native approval status');
        if(!AndroidApprovalResult::fromJson($response->body))return null;
        // APK584: approved machine -> PasswordCredentials(type2, UID, auth_token).
        // The token is from this406, not the owner's password or first factor.
        $fields=array_replace($this->base(),[
            'email'=>$this->approval['uid'],'password'=>$this->approval['auth_token'],
            'credentials_type'=>'transient_token','machine_id'=>$this->approval['machine_id'],
        ]);
        $this->uncertain=true;
        return $this->consume($this->request('POST','/auth/login',$fields,'authenticate'));
    }

    /** @internal The owning MessengerClient consumes this result in the same
     * refresh call; cookies never travel in a public challenge/result array.
     */
    public function takeCompletedLogin():?CookieJar
    {
        $this->requireOwner();$cookies=$this->completedLogin;$this->completedLogin=null;return $cookies;
    }

    private function checkpointContinuation():WebLoginService
    {
        $this->requireOwner();
        if($this->challenge()===null)throw new MessengerException('login_challenge_expired','The native verification has expired');
        if($this->nativeCheckpoint!==null&&!$this->allowWebCheckpoint)throw self::nativeCheckpointUnsupported();
        if($this->nativeCheckpoint===null||$this->checkpointConsumed||$this->checkpointWeb===null)throw self::methodsUnavailable();
        return $this->checkpointWeb;
    }

    private function finishCheckpoint(#[\SensitiveParameter]CookieJar $cookies):CookieJar
    {
        $this->requireOwner();$deadline=$this->expiresAt;
        if($deadline<=time()){$this->cancel();throw new MessengerException('login_challenge_expired','The native verification has expired');}
        // A Web confirmation must obey the saved/native UID as well as its
        // own Web identity check. Consumed codes are never made reusable.
        $this->checkpointConsumed=true;$this->checkpointWeb?->cancel();$this->checkpointWeb=null;
        $this->matchAccount($cookies->accountId());
        $verified=$this->verifyIdentity($cookies,$deadline);
        if($deadline<=time()){$this->cancel();throw new MessengerException('login_challenge_expired','The native verification has expired');}
        $this->cancel();return $verified;
    }

    public function exportPrivateChallenge():array
    {
        $this->requireOwner();
        if(!$this->allowWebCheckpoint)throw new MessengerException('login_native_browser_unavailable','The native authentication policy does not allow browser continuation');
        if($this->challenge()===null||!$this->hasBrowserContinuation())throw new MessengerException('login_native_browser_unavailable','This native challenge has no usable original browser continuation');
        if($this->checkpointWeb?->challenge()!==null){
            $handoff=$this->checkpointWeb->exportPrivateChallenge();
            $handoff['expires_at']=min($this->expiresAt,$handoff['expires_at']);return $handoff;
        }
        // A server-provided 405 URL is a human handoff, not an authenticated
        // CookieJar. Do not invent cookies or append flow/callback parameters.
        return ['url'=>$this->browserUrl,'cookies'=>$this->browserCookies===null?[]:json_decode($this->browserCookies->exportPrivate(),true,16,JSON_THROW_ON_ERROR),'expires_at'=>$this->expiresAt];
    }

    public function assertBrowserIdentity(string $accountId):void
    {
        $this->requireOwner();
        if(!$this->allowWebCheckpoint)throw new MessengerException('login_native_browser_unavailable','The native authentication policy does not allow browser completion');
        if($this->challenge()===null||!$this->hasBrowserContinuation())throw new MessengerException('login_native_browser_unavailable','This native attempt has no usable Web continuation');
        $saved=$this->store->get('account_id');
        $expected=$this->expectedAccountId??$this->browserCookies?->accountId();
        if(preg_match('/^[1-9][0-9]{0,24}$/D',$accountId)!==1||($saved!==null&&$saved!==$accountId)||($expected!==null&&$expected!==$accountId))throw new MessengerException('login_identity_mismatch','The browser identity differs from the account bound to this attempt');
        // A first 405 may not contain a UID. In that case the owning client
        // must still verify real browser cookies against an independent HTTPS
        // identity response before it can persist the first account binding.
    }

    public function cancel():void
    {
        $this->checkpointWeb?->cancel();$this->checkpointWeb=null;$this->completedLogin=null;
        $this->checkpointConsumed=false;$this->checkpointRefreshAt=0;
        $this->checkpointNativeCode=null;
        $this->smsRequestState='not_requested';
        if($this->twoFactor!==null){$firstFactor=$this->twoFactor['first_factor'];sodium_memzero($firstFactor);}
        if($this->approval!==null){$approvalToken=$this->approval['auth_token'];sodium_memzero($approvalToken);}
        $this->approval=null;$this->approvalCheckAt=0;
        $this->twoFactor=null;$this->email=null;$this->expiresAt=0;$this->uncertain=false;
        $this->checkpoint=false;$this->browserCookies=null;$this->browserUrl=null;
        $this->nativeCheckpoint=null;$this->expectedAccountId=null;
    }
    public function __debugInfo():array{return ['challenge'=>$this->challenge(),'private_context'=>'[PRIVATE]'];}
    /** @return array<never,never> */
    public function __serialize():array{throw new \LogicException('Native login attempts must remain private and in memory');}
    public function __destruct(){$this->cancel();}

    /** @return array<string,string> */
    private function base():array
    {
        $fields=['adid'=>$this->device->getAdvertisingId(),'device_id'=>$this->device->getDeviceId(),'format'=>'json',
            'generate_analytics_claim'=>'1','community_id'=>'','cpl'=>'true','generate_session_cookies'=>'1'];
        $machine=$this->device->privateMachineId();
        if($machine===null)$fields['generate_machine_id']='1';else $fields['machine_id']=$machine;
        return $fields;
    }

    /** @param array<string,string> $form */
    private function request(string $method,string $path,#[\SensitiveParameter] array $form,string $friendly):HttpResponse
    {
        $this->requireOwner();
        $origin=$this->profile->origin();
        if(!in_array($origin,['https://graph.facebook.com','https://b-graph.facebook.com'],true))throw self::profileInvalid();
        $form['fb_api_req_friendly_name']=$friendly;
        try{$authorized=$this->profile->authorize($method,$path,$form);}
        catch(\Throwable){throw self::profileInvalid();}
        if(!isset($authorized['form'],$authorized['headers'])||!is_array($authorized['form'])||!is_array($authorized['headers']))throw self::profileInvalid();
        // App authorization may add fields, but cannot swap the recipient,
        // password envelope, first factor, code or device for this attempt.
        foreach($form as $name=>$value)if(($authorized['form'][$name]??null)!==$value)throw self::profileInvalid();
        foreach($authorized['form'] as $name=>$value)if(!is_string($name)||!is_string($value))throw self::profileInvalid();
        $headers=$authorized['headers'];$hasFriendly=false;
        foreach($headers as $name=>$value){
            if(strcasecmp($name,'X-FB-Friendly-Name')!==0)continue;
            if($value!==$friendly)throw self::profileInvalid();$hasFriendly=true;
        }
        if(!$hasFriendly)$headers['X-FB-Friendly-Name']=$friendly;
        return $this->transport->request($method,$origin.$path,$authorized['form'],$headers);
    }

    private function consume(#[\SensitiveParameter] HttpResponse $response):CookieJar
    {
        if($response->status===429){$this->uncertain=false;throw new MessengerException('login_rate_limited','Facebook temporarily limited native authentication');}
        $error=AndroidLoginError::fromJson($response->body);
        if($error!==null){
            $this->uncertain=false;
            if($error->code===406){
                $context=$error->twoFactorContextPrivate();
                $approval=$error->approvalContextPrivate();
                $machine=$approval['machine_id']??$this->device->privateMachineId();
                if($context===null&&($approval===null||$machine===null))throw new MessengerException('login_native_verification_unavailable','Facebook requested verification without a usable native continuation');
                $this->matchAccount($context['uid']??$approval['uid']);$this->twoFactor=$context;$this->checkpoint=false;
                $this->approval=$approval!==null&&$machine!==null
                    ?['uid'=>$approval['uid'],'machine_id'=>$machine,'auth_token'=>$approval['auth_token']]:null;
                $this->nativeCheckpoint=null;$this->browserUrl=null;$this->browserCookies=null;
                $this->checkpointNativeCode=null;
                $remember=$context['machine_id']??$approval['machine_id']??null;
                if($remember!==null)$this->device->rememberMachineIdIfEmpty($remember);
                if($this->expiresAt===0)$this->expiresAt=time()+600;
                throw new MessengerException('login_two_factor_required','Facebook requires the owner verification code for this native attempt');
            }
            if($error->code===405){
                $context=$error->checkpointContextPrivate();
                if(isset($context['uid']))$this->matchAccount($context['uid']);
                if($this->twoFactor!==null){$firstFactor=$this->twoFactor['first_factor'];sodium_memzero($firstFactor);}
                $this->twoFactor=null;$this->email=null;$this->checkpoint=true;$this->nativeCheckpoint=$context??[];
                $this->checkpointNativeCode=405;
                $this->approval=null;$this->approvalCheckAt=0;
                $this->browserCookies=null;$this->browserUrl=null;
                if($this->expiresAt===0)$this->expiresAt=time()+600;
                if($context!==null&&$this->allowWebCheckpoint){
                    try{WebLoginCurlTransport::validateUrl($context['url']);$this->browserUrl=$context['url'];}
                    catch(MessengerException){/* Keep the checkpoint pending without widening browser destinations. */}
                }
                if(!$this->allowWebCheckpoint)throw self::nativeCheckpointUnsupported();
                throw new MessengerException('login_checkpoint_required','Facebook requires a human checkpoint using its original verification page when available');
            }
            throw new MessengerException('login_native_rejected','Facebook rejected the native authentication request');
        }
        if($response->status!==200)throw new MessengerException('login_native_http_failed','Facebook did not confirm native authentication');
        $result=AndroidLoginResult::fromJson($response->body);$this->matchAccount($result->accountId);
        // The server has consumed the native first factor. A later Web check
        // must never make an already accepted verification code reusable.
        $expiresAt=$this->expiresAt;$this->cancel();
        $cookies=$this->allowWebCheckpoint?$this->verifyIdentity($result->cookies(),$expiresAt):$this->verifyNativeIdentity($result,$expiresAt);
        $this->cancel();return $cookies;
    }

    /** APK584 MCe.BRx: GET me/getMeUser. The user token authorizes this request,
     * never the application's anonymous authorization profile.
     */
    private function verifyNativeIdentity(AndroidLoginResult $result,int $expiresAt):CookieJar
    {
        $this->requireOwner();
        if($expiresAt>0&&time()>=$expiresAt)throw new MessengerException('login_challenge_expired','The native verification expired before the identity check');
        $origin=$this->profile->origin();
        if(!in_array($origin,['https://graph.facebook.com','https://b-graph.facebook.com'],true))throw self::profileInvalid();
        $token=$result->accessTokenPrivate();
        try{
            $response=$this->transport->request('GET',$origin.'/me',[],[
                'Authorization'=>'OAuth '.$token,'X-FB-Friendly-Name'=>'getMeUser',
            ]);
        }finally{if($token!=='')sodium_memzero($token);}
        if($expiresAt>0&&time()>=$expiresAt)throw new MessengerException('login_challenge_expired','The native verification expired during the identity check');
        if(strlen($response->body)>2097152)throw self::nativeIdentityUnconfirmed();
        try{$document=json_decode($response->body,false,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch(\JsonException){throw self::nativeIdentityUnconfirmed();}
        if(!$document instanceof \stdClass)throw self::nativeIdentityUnconfirmed();
        if(property_exists($document,'error')||property_exists($document,'error_code')){
            try{$error=AndroidLoginError::fromJson($response->body);}
            catch(MessengerException){throw self::nativeIdentityUnconfirmed();}
            if($error?->code===405){
                $this->nativeCheckpoint=[];$this->checkpointNativeCode=405;$this->checkpoint=true;
                $this->expectedAccountId=$result->accountId;$this->expiresAt=$expiresAt>0?$expiresAt:time()+600;
                throw self::nativeCheckpointUnsupported();
            }
            throw self::nativeIdentityUnconfirmed();
        }
        if($response->status!==200)throw self::nativeIdentityUnconfirmed();
        $uid=$document->id??null;if(is_int($uid))$uid=(string)$uid;
        if(!is_string($uid)||preg_match('/^[1-9][0-9]{0,24}$/D',$uid)!==1)throw self::nativeIdentityUnconfirmed();
        if($uid!==$result->accountId||$result->cookies()->accountId()!==$uid)throw new MessengerException('login_identity_mismatch','The native session token and authentication cookies belong to different identities');
        $this->matchAccount($uid);
        return $result->cookies();
    }

    private function matchAccount(string $accountId):void
    {
        $saved=$this->store->get('account_id');
        $firstFactorAccount=$this->expectedAccountId;
        if(($saved!==null&&$saved!==$accountId)||($firstFactorAccount!==null&&$firstFactorAccount!==$accountId)){
            $this->cancel();
            throw new MessengerException('login_identity_mismatch','The native account differs from the current session or first authentication factor');
        }
        $this->expectedAccountId=$accountId;
    }

    private function verifyIdentity(#[\SensitiveParameter] CookieJar $cookies,int $expiresAt):CookieJar
    {
        $origin=null;
        foreach(['https://www.facebook.com','https://www.messenger.com'] as $candidate){
            $header=$cookies->headerFor($candidate.'/');
            if(preg_match('/(?:^|; )c_user=([^;]+)/',$header,$user)&&$user[1]===$cookies->accountId()&&preg_match('/(?:^|; )xs=[^;]+/',$header)){$origin=$candidate;break;}
        }
        if($origin===null)throw new MessengerException('session_domain_mismatch','The native cookies do not authenticate the existing Web connector');
        $url=$origin.($origin==='https://www.facebook.com'?'/messages/':'/');
        for($redirects=0;$redirects<4;++$redirects){
        if($expiresAt>0&&time()>=$expiresAt){$this->cancel();throw new MessengerException('login_challenge_expired','The native verification expired before confirming the Web identity');}
        $response=$this->identityHttp->request('GET',$url,$cookies);
        if($expiresAt>0&&time()>=$expiresAt){$this->cancel();throw new MessengerException('login_challenge_expired','The native verification expired while confirming the Web identity');}
        if(in_array($response->status,[301,302,303,307,308],true)){
            $location=$response->header('location')??'';
            if(str_starts_with($location,'/')&&!str_starts_with($location,'//'))$location=$origin.$location;
            $parts=parse_url($location);
            $sameOrigin=$parts!==false&&($parts['scheme']??null)==='https'&&($parts['host']??null)===parse_url($origin,PHP_URL_HOST)
                &&!isset($parts['user'])&&!isset($parts['pass'])&&!isset($parts['fragment'])&&(!isset($parts['port'])||$parts['port']===443);
            if($sameOrigin&&preg_match('~/(?:checkpoint(?:\.php)?|challenge(?:\.php)?|(?:re)?captcha(?:\.php)?|two_step[^/]*|two_factor[^/]*|(?:privacy/)?consent(?:\.php)?|login(?:\.php)?)(?:/|$)~i',$parts['path']??'/')){
                $this->retainWebCheckpoint($location,$cookies,$expiresAt);
            }
            if($sameOrigin&&($origin==='https://www.messenger.com'||preg_match('~^/messages(?:/|$)~',$parts['path']??'/'))){$url=$location;continue;}
            throw new MessengerException('login_unconfirmed','Native authentication did not produce a confirmed Web session');
        }
        if($response->status!==200)throw new MessengerException('login_unconfirmed','Native authentication did not produce a confirmed Web session');
        try{WebLoginService::assertIdentityPage($response->body);}
        catch(MessengerException $error){if($error->reason==='auth_challenge_required')$this->retainWebCheckpoint($url,$cookies,$expiresAt);throw $error;}
        $identity=(new WebLoginPageParser())->userId($response->body);
        if($identity===null)throw new MessengerException('login_unconfirmed','Facebook did not independently confirm the native Web cookies');
        if($identity!==$cookies->accountId())throw new MessengerException('login_identity_mismatch','The native cookies differ from the confirmed Web identity');
        return $cookies;
        }
        throw new MessengerException('login_unconfirmed','The native Web identity verification exceeded the redirect limit');
    }

    private function retainWebCheckpoint(string $url,#[\SensitiveParameter] CookieJar $cookies,int $expiresAt):never
    {
        if(!$this->allowWebCheckpoint){
            $this->twoFactor=null;$this->approval=null;$this->checkpoint=true;$this->nativeCheckpoint=[];
            $this->checkpointNativeCode=null;
            $this->expectedAccountId=$cookies->accountId();$this->expiresAt=$expiresAt>0?$expiresAt:time()+600;
            $this->browserUrl=null;$this->browserCookies=null;
            throw self::nativeCheckpointUnsupported();
        }
        WebLoginCurlTransport::validateUrl($url);
        $this->twoFactor=null;$this->checkpoint=true;$this->browserUrl=$url;$this->browserCookies=$cookies;
        $this->expectedAccountId=$cookies->accountId();$this->expiresAt=$expiresAt>0?$expiresAt:time()+600;
        throw new MessengerException('auth_challenge_required','Native authentication still requires a human Facebook Web confirmation');
    }
    private function hasBrowserContinuation():bool{return $this->allowWebCheckpoint&&$this->browserUrl!==null&&($this->browserCookies!==null||$this->nativeCheckpoint!==null);}
    private function requireOwner():void{if(!$this->store->ownsLock())throw new \LogicException('Native authentication must use the current Messenger session owner');}
    private static function profileInvalid():MessengerException{return new MessengerException('login_native_profile_invalid','The native application authorization profile is invalid');}
    private static function methodsUnavailable():MessengerException{return new MessengerException('login_method_unavailable','Native SMS, email and device-approval method discovery has not been established for this flow');}
    private static function nativeCheckpointUnsupported():MessengerException{return new MessengerException('login_native_verification_unsupported','Facebook requires a checkpoint without an established native continuation; browser fallback is disabled');}
    private static function nativeIdentityUnconfirmed():MessengerException{return new MessengerException('login_native_identity_unconfirmed','Facebook did not independently confirm the native session identity');}
}
