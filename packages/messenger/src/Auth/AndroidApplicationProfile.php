<?php
declare(strict_types=1);

namespace MVO\Messenger\Auth;

/** Explicit application configuration for the experimentally reconstructed Android login.
 * No Meta client credentials, device proofs or application attestation are bundled.
 * This profile is private provider configuration, never a consumer IntegrationBearer.
 */
final class AndroidApplicationProfile implements AndroidLoginProfile
{
    public function __construct(
        private readonly string $applicationId,
        #[\SensitiveParameter] private readonly string $clientValue,
        private readonly string $locale='pt_BR',
        private readonly ?string $countryCode=null,
        private readonly string $endpoint='https://graph.facebook.com',
        private readonly int $version=2,
        private readonly string $flow='CONTROLLER_INITIALIZATION',
    ) {
        if(preg_match('/^[1-9][0-9]{0,31}$/D',$applicationId)!==1
            ||preg_match('/^[\x21-\x7e]{1,1024}$/D',$clientValue)!==1||str_contains($clientValue,'|')
            ||preg_match('/^[a-z]{2,3}_[A-Z]{2}$/D',$locale)!==1
            ||($countryCode!==null&&preg_match('/^[A-Z]{2}$/D',$countryCode)!==1)
            ||!in_array($endpoint,['https://graph.facebook.com','https://b-graph.facebook.com'],true)
            ||!in_array($version,[1,2],true)||preg_match('/^[A-Za-z0-9_]{1,128}$/D',$flow)!==1){
            throw new \InvalidArgumentException('Invalid native application configuration');
        }
    }

    public function origin():string{return $this->endpoint;}
    public function passwordVersion():int{return $this->version;}
    public function keyFetchFlow():string{return $this->flow;}

    public function authorize(string $method,string $path,#[\SensitiveParameter] array $form):array
    {
        $post=$method==='POST'&&in_array($path,['/pwd_key_fetch','/auth/login'],true);
        $approval=$method==='GET'&&$path==='/check_approved_machine';
        $sms=$method==='POST'&&preg_match('~\A/[1-9][0-9]{0,24}/twofacsms\z~',$path)===1;
        if(!$post&&!$approval&&!$sms)throw new \InvalidArgumentException('Unsupported native authentication request');
        $seen=[];
        foreach($form as $name=>$value){
            if(!is_string($name)||preg_match('/^[A-Za-z][A-Za-z0-9_]{0,127}$/D',$name)!==1||!is_string($value)||preg_match('//u',$value)!==1){
                throw new \InvalidArgumentException('Invalid native authentication fields');
            }
            $lower=strtolower($name);
            if(isset($seen[$lower])||in_array($lower,['sig','access_token','api_key','locale','client_country_code','fb_api_caller_class'],true)){
                throw new \InvalidArgumentException('Reserved or ambiguous native authentication field');
            }
            $seen[$lower]=true;
        }
        if($path==='/pwd_key_fetch'&&($form['method']??null)!=='GET')throw new \InvalidArgumentException('Native key fetch requires its logical GET override');
        if($path==='/auth/login'&&isset($form['method']))throw new \InvalidArgumentException('Native login does not use a method override');
        if($approval&&isset($seen['method']))throw new \InvalidArgumentException('Native approval uses an actual GET, not a method override');
        if($sms&&isset($seen['method']))throw new \InvalidArgumentException('Native SMS uses an actual POST, not a method override');
        // Caller labels describe this PHP implementation; they are not device proofs.
        $form['locale']=$this->locale;
        if($this->countryCode!==null)$form['client_country_code']=$this->countryCode;
        $form['fb_api_caller_class']=self::class;
        if($path==='/auth/login'){
            $form['api_key']=$this->applicationId;
            $names=array_keys($form);
            // All accepted names are ASCII. strcasecmp is the observed Java
            // compareToIgnoreCase ordering on this restricted key alphabet.
            usort($names,static fn(string $a,string $b):int=>strcasecmp($a,$b));
            $payload='';
            foreach($names as $name)$payload.=$name.'='.$form[$name];
            $payload.=$this->clientValue;
            $form['sig']=md5($payload);
            sodium_memzero($payload);
        }
        // Both new fields are excluded from the signature snapshot above.
        $form['access_token']=$this->applicationId.'|'.$this->clientValue;
        return ['form'=>$form,'headers'=>[]];
    }

    public function __debugInfo():array{return ['origin'=>$this->endpoint,'version'=>$this->version,'application_configuration'=>'[PRIVATE]'];}
    /** @return array<never,never> */
    public function __serialize():array{throw new \LogicException('Native application authorization must remain private');}
    /** @param array<mixed> $data */
    public function __unserialize(array $data):void{throw new \LogicException('Native application authorization must remain private');}
}
