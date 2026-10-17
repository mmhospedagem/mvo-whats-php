<?php
declare(strict_types=1);
namespace MVO\Mobile;

use MVO\Auth\CredentialStore;

/** Durable primary registration, separate from the eight-character companion pairing code. */
final class RegistrationService
{
    /** @param array<string,mixed>|null $deviceInfo
     * @param \Closure(RegistrationRequest):RegistrationResponse|null $transport
     * @param \Closure():float|null $clock
     * @param \Closure():void|null $guard Re-check application ownership before network I/O and every credential commit. */
    public function __construct(private readonly CredentialStore $store,private readonly ?array $deviceInfo=null,private readonly ?\Closure $transport=null,private readonly ?\Closure $clock=null,private readonly ?\Closure $guard=null) {}

    /** @return array<string,mixed> */
    public function state():array
    {
        $this->checkGuard();return $this->publicState($this->store->load());
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function requestCode(array $input):array
    {
        [$cc,$number]=self::phone($input);$method=$input['method']??'sms';
        if(!in_array($method,['sms','voice'],true))throw new \InvalidArgumentException('Registration method must be sms or voice');
        if(isset($input['confirmResend']) && !is_bool($input['confirmResend']))throw new \InvalidArgumentException('confirmResend must be a boolean');
        return $this->locked(function()use($input,$cc,$number,$method):array{
            $credentials=$this->store->load()??CredentialStore::fresh();$this->requireUnused($credentials);$previous=$credentials['mobileRegistration']??[];
            if(!is_array($previous))throw new \UnexpectedValueException('Invalid stored mobile registration state');
            if($previous!==[] && (($previous['cc']??null)!==$cc || ($previous['number']??null)!==$number))throw new RegistrationException('registration_number_conflict','A different phone number already has a registration attempt in this session',0,$this->publicState($credentials));
            $remaining=max(0,max((int)($previous['nextRequestAt']??0),(int)($previous[$method==='sms'?'nextSmsAt':'nextVoiceAt']??0))-$this->now());
            if($remaining>0)throw new RegistrationException('registration_cooldown','Wait before requesting another registration code',$remaining,$this->publicState($credentials));
            if(in_array($previous['status']??null,['requesting','request_unknown'],true) && ($input['confirmResend']??false)!==true)throw new RegistrationException('registration_result_unknown','The previous code request may have reached WhatsApp; explicitly confirm a resend before requesting another code',0,$this->publicState($credentials));
            $info=$input['deviceInfo']??$previous['deviceInfo']??$this->deviceInfo??RegistrationProfile::defaultDeviceInfo();
            if(!is_array($info))throw new \InvalidArgumentException('Mobile registration requires deviceInfo');
            $candidate=[...$credentials,'registered'=>true,'meJid'=>$cc.$number.'@s.whatsapp.net'];PrimaryCredentials::validate($candidate,$info);
            $info=self::deviceInfo($info);$info['phoneId']??=self::uuid();
            $record=[...$previous,'version'=>1,'cc'=>$cc,'number'=>$number,'method'=>$method,'deviceInfo'=>$info,'status'=>'requesting','lastOperation'=>'code','updatedAt'=>$this->now(),'attemptId'=>bin2hex(random_bytes(16)),'reason'=>null,'challenge'=>null];
            $record['id']??=random_bytes(20);$record['backupToken']??=random_bytes(20);$record['experimentId']??=self::uuidBytes();$record['accessSessionId']??=self::uuidBytes();
            $parameters=$this->parameters($credentials,$record,$input);
            $request=new RegistrationRequest('code',$parameters,$info);$sender=$this->sender($request);
            $credentials['mobileRegistration']=$record;$this->commit($credentials);
            return $this->perform($credentials,$record,$request,$sender);
        });
    }

    /** The registration code and two-step PIN are separate values; neither is persisted.
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function verifyCode(array $input):array
    {
        $code=$input['code']??null;if(!isset($input['twoFactorPin']) && (!is_string($code) || !preg_match('/^[0-9]{6}$/D',$code)))throw new \InvalidArgumentException('The registration code must contain exactly six digits');
        if($code!==null && (!is_string($code) || !preg_match('/^[0-9]{6}$/D',$code)))throw new \InvalidArgumentException('The registration code must contain exactly six digits');
        if(isset($input['twoFactorPin']) && (!is_string($input['twoFactorPin']) || !preg_match('/^[0-9]{6}$/D',$input['twoFactorPin'])))throw new \InvalidArgumentException('The two-step PIN must contain exactly six digits');
        return $this->locked(function()use($input,$code):array{
            $credentials=$this->store->load();if($credentials===null)throw new RegistrationException('registration_not_started','Request a registration code first');$this->requireUnused($credentials);
            $record=$credentials['mobileRegistration']??null;if(!is_array($record) || !isset($record['cc'],$record['number'],$record['deviceInfo']))throw new RegistrationException('registration_not_started','Request a registration code first');
            if(!in_array($record['status']??null,['code_sent','requesting','request_unknown','challenge','failed','verifying','verification_unknown'],true))throw new RegistrationException('registration_code_not_requested','Request a registration code before verifying it',0,$this->publicState($credentials));
            $remaining=max(0,(int)($record['nextVerifyAt']??0)-$this->now());if($remaining>0)throw new RegistrationException('registration_cooldown','Wait before verifying another code',$remaining,$this->publicState($credentials));
            $operation=isset($input['twoFactorPin'])?'security':'register';
            if($operation==='security' && ($record['challenge']['type']??null)!=='two_factor')throw new RegistrationException('registration_two_factor_not_requested','WhatsApp has not requested a two-step verification PIN',0,$this->publicState($credentials));
            $record['status']='verifying';$record['lastOperation']=$operation;$record['updatedAt']=$this->now();$record['attemptId']=bin2hex(random_bytes(16));$record['reason']=null;
            $parameters=$this->parameters($credentials,$record,$input);$parameters['code']=$operation==='security'?$input['twoFactorPin']:$code;
            $request=new RegistrationRequest($operation,$parameters,$record['deviceInfo']);$sender=$this->sender($request);
            $credentials['mobileRegistration']=$record;$this->commit($credentials);
            return $this->perform($credentials,$record,$request,$sender);
        });
    }

    /** @param array<string,mixed> $credentials
     * @param array<string,mixed> $record
     * @param \Closure():RegistrationResponse $sender
     * @return array<string,mixed> */
    private function perform(array $credentials,array $record,RegistrationRequest $request,\Closure $sender):array
    {
        try{
            $this->checkGuard();$response=$sender();$this->checkGuard();
            if(!$response instanceof RegistrationResponse)throw new \UnexpectedValueException('Registration transport returned an invalid response');
        }catch(\Throwable $error){
            // A failed read or a process interruption never proves that a code was not sent.
            $record['status']=$request->operation==='code'?'request_unknown':'verification_unknown';$record['reason']='registration_result_unknown';$record['updatedAt']=$this->now();$credentials['mobileRegistration']=$record;$this->commit($credentials);
            throw new RegistrationException('registration_result_unknown','WhatsApp did not provide a confirmed registration result',0,$this->publicState($credentials),$error);
        }
        $body=$response->body;$status=$body['status']??null;$reason=self::reason($body['reason']??null);$commonRetry=max($response->retryAfter,self::seconds($body['retry_after']??0));$retry=max($commonRetry,self::seconds($body[$request->operation==='code'?($record['method']==='sms'?'sms_wait':'voice_wait'):'guess_wait']??0));
        if($request->operation==='code'){
            $record['nextRequestAt']=$this->now()+$commonRetry;
            $record['nextSmsAt']=$this->now()+max($commonRetry,self::seconds($body['sms_wait']??0));$record['nextVoiceAt']=$this->now()+max($commonRetry,self::seconds($body['voice_wait']??0));
        }
        $record['updatedAt']=$this->now();$record['reason']=$reason;$record['challenge']=self::challenge($body,$reason);
        if(in_array($request->operation,['register','security'],true) && $response->httpStatus>=200 && $response->httpStatus<300 && $status==='ok'){
            $login=$body['login']??null;if(is_int($login) && $login>=0)$login=(string)$login;
            if(!is_string($login) || !preg_match('/^[1-9][0-9]{6,14}$/D',$login) || $login!==$record['cc'].$record['number'])return $this->invalidResponse($credentials,$record,$request);
            $credentials['registered']=true;$credentials['role']='primary';$credentials['meJid']=$login.'@s.whatsapp.net';$credentials['mobileDeviceInfo']=$record['deviceInfo'];
            $lid=$body['lid']??null;if(is_string($lid) && preg_match('/^[1-9][0-9]*(?:@lid)?$/D',$lid))$credentials['meLid']=str_contains($lid,'@')?$lid:$lid.'@lid';
            $credentials['serverHasPreKeys']=false;$record['status']='registered';$record['reason']=null;$record['challenge']=null;$record['nextRequestAt']=0;$record['nextSmsAt']=0;$record['nextVoiceAt']=0;$record['nextVerifyAt']=0;$credentials['mobileRegistration']=$record;PrimaryCredentials::validate($credentials,$record['deviceInfo']);$this->commit($credentials);return $this->publicState($credentials);
        }
        if($request->operation==='code' && $response->httpStatus>=200 && $response->httpStatus<300 && $status==='sent'){
            $record['status']='code_sent';$record['reason']=null;$record['challenge']=null;$record['nextVerifyAt']=0;$credentials['mobileRegistration']=$record;$this->commit($credentials);return $this->publicState($credentials);
        }
        if(!in_array($status,['fail','ok','sent'],true))return $this->invalidResponse($credentials,$record,$request);
        if($request->operation==='security' && $record['challenge']===null)$record['challenge']=['type'=>'two_factor'];
        $record['status']=$record['challenge']===null?'failed':'challenge';if($request->operation!=='code')$record['nextVerifyAt']=$this->now()+$retry;$credentials['mobileRegistration']=$record;$this->commit($credentials);
        $public=$this->publicState($credentials);throw new RegistrationException($reason??'registration_rejected','WhatsApp refused the registration operation',$retry,$public);
    }

    /** @param array<string,mixed> $credentials
     * @param array<string,mixed> $record
     * @param array<string,mixed> $input
     * @return array<string,string> */
    private function parameters(array $credentials,array $record,array $input):array
    {
        return RegistrationWire::parameters($credentials,$record,$input);
    }
    /** Local validation/encryption happens before the durable in-flight marker.
     * @return \Closure():RegistrationResponse */
    private function sender(RegistrationRequest $request):\Closure
    {
        return $this->transport===null?(new RegistrationHttpTransport())->prepare($request):fn():RegistrationResponse=>($this->transport)($request);
    }

    /** @param array<string,mixed> $credentials
     * @param array<string,mixed> $record
     * @return never */
    private function invalidResponse(array $credentials,array $record,RegistrationRequest $request):never
    {
        $record['status']=$request->operation==='code'?'request_unknown':'verification_unknown';$record['reason']='registration_response_invalid';$credentials['mobileRegistration']=$record;$this->commit($credentials);throw new RegistrationException('registration_response_invalid','WhatsApp returned an incomplete registration response',0,$this->publicState($credentials));
    }
    /** @template T
     * @param \Closure():T $operation
     * @return T */
    private function locked(\Closure $operation):mixed
    {
        $this->checkGuard();if($this->store->ownsLock())throw new \LogicException('Disconnect the current client before registering a primary account');$this->store->acquire();
        try{$this->checkGuard();return $operation();}finally{$this->store->close();}
    }
    /** @param array<string,mixed> $credentials */
    private function commit(array $credentials):void {$this->checkGuard();$this->store->save($credentials);}
    private function checkGuard():void {if($this->guard!==null)($this->guard)();}
    /** @param array<string,mixed> $credentials */
    private function requireUnused(array $credentials):void {if(($credentials['registered']??false)===true)throw new RegistrationException('registration_session_in_use','Use a new session to register a primary account; the existing identity was preserved',0,$this->publicState($credentials));}
    /** @param array<string,mixed>|null $credentials
     * @return array<string,mixed> */
    private function publicState(?array $credentials):array
    {
        $record=is_array($credentials['mobileRegistration']??null)?$credentials['mobileRegistration']:[];$status=$record['status']??'idle';if($status==='requesting')$status='request_unknown';elseif($status==='verifying')$status='verification_unknown';
        $primary=($credentials['registered']??false)===true && ($credentials['role']??null)==='primary';$revoked=$primary && ($credentials['revoked']??false)===true;
        $registered=$primary && !$revoked;if($revoked)$status='revoked';elseif($registered)$status='registered';
        $retry=($record['lastOperation']??'code')==='code'?max((int)($record['nextRequestAt']??0),(int)($record[($record['method']??'sms')==='sms'?'nextSmsAt':'nextVoiceAt']??0)):(int)($record['nextVerifyAt']??0);$retry=max(0,$retry-$this->now());
        return ['status'=>$status,'registered'=>$registered,'method'=>$record['method']??null,'phoneNumber'=>isset($record['cc'],$record['number'])?'+'.$record['cc'].$record['number']:null,'retryAfter'=>$revoked?0:$retry,'challenge'=>$revoked?null:($record['challenge']??null),'reason'=>$revoked?'registration_session_revoked':($record['reason']??null),'accountJid'=>$registered?$credentials['meJid']:null];
    }
    /** @param array<string,mixed> $input
     * @return array{string,string} */
    private static function phone(array $input):array
    {
        $cc=$input['cc']??null;$number=$input['phoneNumber']??$input['phone']??null;if(!is_string($cc) || !preg_match('/^[1-9][0-9]{0,2}$/D',$cc) || !is_string($number))throw new \InvalidArgumentException('Provide cc and a national phoneNumber');
        if(str_starts_with($number,'+')){if(!str_starts_with($number,'+'.$cc))throw new \InvalidArgumentException('Phone country code does not match cc');$number=substr($number,strlen($cc)+1);}
        if(!preg_match('/^[0-9]{4,14}$/D',$number) || strlen($cc.$number)<7 || strlen($cc.$number)>15)throw new \InvalidArgumentException('Invalid national phoneNumber');
        if($cc==='55' && !preg_match('/^[0-9]{2}9[0-9]{8}$/D',$number))throw new \InvalidArgumentException('Para cadastro WhatsApp Android no Brasil, informe DDD + celular com 9 dígitos (9XXXX-XXXX), totalizando 11 dígitos nacionais. Números antigos com 10 dígitos e telefones fixos não são aceitos; o nono dígito não é inserido automaticamente.');
        return [$cc,$number];
    }
    /** @param array<string,mixed> $info
     * @return array<string,mixed> */
    private static function deviceInfo(array $info):array
    {
        $allowed=['os','manufacturer','device','osVersion','osBuildNumber','appVersion','phoneId','localeLanguageIso6391','localeCountryIso31661Alpha2','mcc','mnc','distributionChannel','deviceModelType','deviceBoard','business'];$out=[];
        foreach($allowed as$key)if(array_key_exists($key,$info)){
            $value=$info[$key];if($key==='business'){if(!is_bool($value))throw new \InvalidArgumentException('Invalid mobile business flag');}
            elseif($key==='deviceModelType'){if(!is_int($value) || $value<0)throw new \InvalidArgumentException('Invalid mobile model type');}
            elseif(!is_string($value) || strlen($value)>256 || preg_match('/[\x00-\x1f\x7f]/',$value))throw new \InvalidArgumentException('Invalid mobile device information');$out[$key]=$value;
        }
        if(isset($out['phoneId']) && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di',$out['phoneId']))throw new \InvalidArgumentException('phoneId must be a UUID');return $out;
    }
    private static function seconds(mixed $value):int {
        if(!(is_int($value) || is_float($value) || (is_string($value) && preg_match('/^[0-9]+(?:\.[0-9]+)?$/D',$value))))return 0;
        $seconds=(float)$value;return is_finite($seconds) && $seconds>=0?(int)min(31536000,ceil($seconds)):0;
    }
    private static function reason(mixed $value):?string {return is_string($value) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$value)?$value:null;}
    /** @param array<string,mixed> $body
     * @return array{type:string}|null */
    private static function challenge(array $body,?string $reason):?array
    {
        if($reason==='security_code' || ($body['security_code_set']??false)===true)return ['type'=>'two_factor'];
        if(in_array($reason,['device_confirm','device_confirm_or_second_code','second_code'],true))return ['type'=>'device_confirmation'];
        if(in_array($reason,['captcha','integrity','attestation','email','challenge'],true))return ['type'=>$reason==='challenge'?'unsupported':$reason];return null;
    }
    private static function uuid():string {$bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);}
    private static function uuidBytes():string {return hex2bin(str_replace('-','',self::uuid()));}
    private function now():int {return (int)floor($this->clock===null?microtime(true):($this->clock)());}
}
