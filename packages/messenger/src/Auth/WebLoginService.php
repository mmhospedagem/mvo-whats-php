<?php
declare(strict_types=1);

namespace MVO\Messenger\Auth;

use MVO\Messenger\MessengerException;
use MVO\Messenger\Transport\{PublicBundleLoader,WebLoginCurlTransport,WebLoginResponse,WebLoginTransport};

/** Facebook-account login through the currently observed Messenger Web HTML form. */
final class WebLoginService implements LoginService
{
    private readonly WebLoginTransport $transport;
    private ?WebLoginResponse $challengeResponse = null;
    private ?string $challengeUrl = null;
    private int $challengeExpiresAt = 0;
    private int $continuationExpiresAt = 0;
    private string $challengeType = 'two_factor';
    /** @var array<string,mixed>|null */ private ?array $challengeSpec = null;
    private bool $confirmationConsumed = false;
    private readonly \Closure $bundleLoader;
    private readonly \Closure $methodBundleLoader;
    /** @var array<string,mixed>|null */ private ?array $methodSpec = null;
    private bool $methodOutcomeUncertain = false;
    private bool $codeContinuationUncertain = false;
    private int $methodRefreshAt = 0;
    private int $methodRequestAt = 0;
    private int $approvalCheckAt = 0;
    /** @var list<string> */ private array $challengeScripts = [];

    /** @param callable(list<string>):list<string>|null $bundleLoader */
    public function __construct(?WebLoginTransport $transport = null,?callable $bundleLoader = null)
    {
        $this->transport = $transport ?? new WebLoginCurlTransport();
        $this->bundleLoader=$bundleLoader===null?(new PublicBundleLoader())->loadTwoFactorBundles(...):\Closure::fromCallable($bundleLoader);
        $this->methodBundleLoader=$bundleLoader===null?(new PublicBundleLoader())->loadLoginMethodBundles(...):\Closure::fromCallable($bundleLoader);
    }

    public function login(#[\SensitiveParameter] string $email, #[\SensitiveParameter] string $password): CookieJar
    {
        if (trim($email) === '' || strlen($email) > 320 || preg_match('/[\x00-\x1f\x7f]/', $email) || $password === '' || strlen($password) > 4096) {
            throw new MessengerException('login_invalid_credentials', 'Informe um identificador e uma senha válidos para o login.');
        }
        $this->cancel();
        $fields = [];
        try {
            [$page, $url] = $this->follow('GET', 'https://www.messenger.com/');
            $parser = new WebLoginPageParser();
            $form = $parser->parse($page->body, $url);
            $fields = $form['fields'];
            $timestamp = (string) time();
            if (array_key_exists('lgnjs', $fields)) $fields['lgnjs'] = $timestamp;
            $fields['email'] = trim($email);
            $fields[$form['password_field']] = PasswordEncryption::encrypt($form['key_id'], $form['public_key'], $password, $timestamp);
            [$response] = $this->follow('POST', $form['url'], $fields);
            self::checkChallenge($response, $form['url']);
            return $this->verifyIdentity();
        } finally {
            if (function_exists('sodium_memzero')) sodium_memzero($password);
            $fields = [];
            if ($this->challengeResponse === null) $this->transport->clear();
        }
    }

    /** @internal Adopts only the original security URL already supplied by the server.
     * No password, form submission or new login is performed by this continuation.
     * Its response and cookies stay in this service's existing memory transport.
     */
    public function resumeCheckpoint(#[\SensitiveParameter] string $url,int $expiresAt):CookieJar
    {
        WebLoginCurlTransport::validateUrl($url);
        if(preg_match('~^/(?:checkpoint(?:\.php)?|challenge(?:\.php)?|two_step[^/]*|two_factor[^/]*|(?:re)?captcha(?:\.php)?|(?:privacy/)?consent(?:\.php)?|login(?:\.php)?)(?:/|$)~i',parse_url($url,PHP_URL_PATH)??'/')!==1)
            throw new MessengerException('login_challenge_unavailable','O servidor não forneceu uma URL de verificação suportada.');
        $now=time();
        if($expiresAt<=$now||$expiresAt>$now+600)throw new MessengerException('login_challenge_expired','O prazo da verificação não é válido ou já expirou.');
        if($this->challenge()!==null)throw new MessengerException('login_verification_pending','Conclua ou encerre a verificação atual antes de adotar outra continuação.');
        $this->continuationExpiresAt=$expiresAt;
        try{
            $this->follow('GET',$url,[],true,$expiresAt);
            $jar=$this->verifyIdentity(true,$expiresAt);
            if(time()>=$expiresAt)throw new MessengerException('login_challenge_expired','A verificação expirou durante a confirmação da identidade.');
            return $jar;
        }finally{
            // captureChallenge initializes its ordinary Web deadline. A native
            // handoff must never extend the original owner's shorter deadline.
            if($this->challengeResponse!==null)$this->challengeExpiresAt=min($this->challengeExpiresAt,$expiresAt);
            else{$this->challengeExpiresAt=0;$this->transport->clear();}
        }
    }

    /** Only this projection may leave the private, memory-only login context.
     * @return array<string,mixed>|null
     */
    public function challenge(): ?array
    {
        if ($this->challengeResponse === null || $this->challengeUrl === null) return null;
        if ($this->challengeExpiresAt <= time()) { $this->cancel(); return null; }
        $details=$this->methodSpec===null?[]:(new TwoFactorMethodService($this->transport))->selectedInfo($this->methodSpec);
        return ['type'=>$this->challengeType,'expires_at'=>$this->challengeExpiresAt,
            'ready'=>$this->challengeType==='two_factor'&&$this->challengeSpec!==null&&!$this->confirmationConsumed&&!$this->methodOutcomeUncertain,
            'methods'=>$this->methodSpec===null?[]:(new TwoFactorMethodService($this->transport))->methods($this->methodSpec),
            'selected_method'=>$details['id']??null,'requires_code'=>$details['code_supported']??($this->challengeSpec!==null),
            'can_refresh'=>$this->challengeType==='two_factor'&&!$this->confirmationConsumed&&!$this->codeContinuationUncertain&&time()>=$this->methodRefreshAt,
            'can_check'=>$this->challengeType==='two_factor'&&!$this->confirmationConsumed&&!$this->codeContinuationUncertain&&($details['approval_supported']??false),
            'request_retry_after'=>max(0,$this->methodRequestAt-time()),'check_retry_after'=>max(0,$this->approvalCheckAt-time()),
            'outcome_uncertain'=>$this->methodOutcomeUncertain||$this->codeContinuationUncertain];
    }

    /** Explicitly loads the current native picker; discovery never sends SMS/email.
     * @return array<string,mixed>
     */
    public function refreshMethods():array
    {
        $this->requireTwoFactor();
        if(time()<$this->methodRefreshAt)throw new MessengerException('login_method_cooldown','Aguarde antes de consultar os métodos novamente.');
        $this->methodRefreshAt=time()+5;
        $handler=new TwoFactorMethodService($this->transport);
        $urls=$handler->bundleUrls($this->challengeResponse->body);
        $scripts=[...$this->challengeScripts,...($urls===[]?[]:($this->methodBundleLoader)($urls))];
        $prepared=$handler->prepare($this->challengeResponse,$this->challengeUrl,$scripts);
        if($prepared===null)throw new MessengerException('login_method_unavailable','O Facebook não forneceu os contratos necessários para listar os métodos nesta tentativa.');
        $this->requireTwoFactor();
        // Discovery may rotate the offered list, but must not forget delivery
        // cooldowns or change an existing opaque ID within the same attempt.
        if($this->methodSpec!==null){
            $prepared=array_replace($this->methodSpec,[
                'operations'=>array_replace($this->methodSpec['operations'],$prepared['operations']),
                'approval_next'=>$prepared['approval_next'],
            ]);
        }
        $this->methodSpec=$handler->canRefresh($prepared)?$handler->discover($prepared):$prepared;
        if(!$this->methodOutcomeUncertain)$this->challengeSpec=$handler->codeSpec($this->methodSpec);
        return $this->challenge()??throw new MessengerException('login_challenge_expired','A verificação expirou.');
    }

    /** One deliberate selection/delivery action, in the existing cookie jar.
     * @return array<string,mixed>
     */
    public function requestMethod(string $methodId):array
    {
        $this->requireTwoFactor();
        if($this->methodOutcomeUncertain)throw new MessengerException('login_method_outcome_uncertain','A solicitação anterior tem resultado incerto; ela não será reenviada.');
        if($this->methodSpec===null)throw new MessengerException('login_method_unavailable','Consulte os métodos oferecidos nesta tentativa antes de selecionar.');
        $handler=new TwoFactorMethodService($this->transport);
        $chosen=null;
        foreach($handler->methods($this->methodSpec) as $method)if(($method['id']??null)===$methodId)$chosen=$method;
        if($chosen===null)throw new MessengerException('login_method_unavailable','Este método não foi oferecido nesta tentativa.');
        $sendsCode=in_array($chosen['type'],['sms','email'],true);
        if($sendsCode&&time()<$this->methodRequestAt)throw new MessengerException('login_method_cooldown','Aguarde antes de solicitar outro código.');
        try{$this->methodSpec=$handler->request($this->methodSpec,$methodId);}
        catch(MessengerException $error){
            if(in_array($error->reason,['login_transport_failed','login_response_too_large','login_two_factor_protocol_unavailable','login_method_outcome_uncertain'],true)){
                $this->methodOutcomeUncertain=true;$this->challengeSpec=null;
                throw new MessengerException('login_method_outcome_uncertain','Não foi possível confirmar o resultado da solicitação; nenhum código será reenviado automaticamente.');
            }
            throw $error;
        }
        $this->challengeSpec=$handler->codeSpec($this->methodSpec);
        if($sendsCode)$this->methodRequestAt=time()+60;
        return $this->challenge()??throw new MessengerException('login_challenge_expired','A verificação expirou.');
    }

    /** Explicit bounded check of the real app approval; null means still pending. */
    public function checkApproval():?CookieJar
    {
        $this->requireTwoFactor();
        if($this->methodSpec===null)throw new MessengerException('login_method_unavailable','Selecione primeiro a aprovação em outro aplicativo, se oferecida.');
        if(time()<$this->approvalCheckAt)throw new MessengerException('login_approval_cooldown','Aguarde antes de conferir a aprovação novamente.');
        $this->approvalCheckAt=time()+5;
        $handler=new TwoFactorMethodService($this->transport);
        try{$result=$handler->approval($this->methodSpec);}
        catch(MessengerException $error){
            if(in_array($error->reason,['login_two_factor_approval_denied','login_challenge_expired'],true))$this->cancel();
            throw $error;
        }
        $this->methodSpec=$result['state'];
        $this->approvalCheckAt=time()+max(5,$result['interval_seconds']);
        if(($result['approved']??false)!==true)return null;
        $this->confirmationConsumed=true;$this->challengeSpec=null;
        if($result['next']!==null)$this->follow('GET',$result['next'],[],false);
        $jar=$this->verifyIdentity(false);
        if($result['user_id']!==null&&$result['user_id']!==$jar->accountId())throw new MessengerException('login_identity_mismatch','A identidade final não corresponde à aprovação.');
        $this->cancel();return $jar;
    }

    private function requireTwoFactor():void
    {
        if($this->challenge()===null)throw new MessengerException('login_challenge_expired','Inicie outra tentativa de login.');
        if($this->challengeType!=='two_factor')throw new MessengerException(self::humanChallengeCode($this->challengeType),'Esta etapa exige verificação humana no Facebook.');
        if($this->confirmationConsumed)throw new MessengerException('login_continuation_required','A confirmação já foi enviada; ela não será repetida.');
        if($this->codeContinuationUncertain)throw new MessengerException('login_continuation_required','O resultado da confirmação é incerto; não é seguro reiniciar a seleção nesta tentativa.');
    }

    public function confirm(#[\SensitiveParameter] string $code): CookieJar
    {
        try {
            if ($this->challenge() === null) throw new MessengerException('login_challenge_expired','A verificação expirou; inicie o login novamente no formulário local.');
            if($this->challengeType!=='two_factor')throw new MessengerException(self::humanChallengeCode($this->challengeType),'O desafio atual exige a confirmação humana no Facebook; não é uma solicitação de código do autenticador.');
            if($this->confirmationConsumed)throw new MessengerException('login_continuation_required','Esta confirmação já foi enviada; o Facebook ainda não confirmou uma sessão utilizável.');
            if($this->codeContinuationUncertain)throw new MessengerException('login_challenge_unavailable','A confirmação anterior tem resultado incerto; ela não será reenviada.');
            if($this->methodOutcomeUncertain)throw new MessengerException('login_method_outcome_uncertain','A solicitação de código tem resultado incerto; não envie outra confirmação nesta tentativa.');
            if (preg_match('/^[0-9]{6,8}$/D',$code) !== 1) throw new MessengerException('login_code_invalid','Informe o código numérico do método escolhido.');
            if($this->challengeSpec===null||$this->challengeUrl===null)throw new MessengerException('login_challenge_unavailable','O formato desta etapa de verificação ainda precisa ser reconhecido pelo conector.');
            $handler=new TwoFactorService($this->transport);$spec=$this->challengeSpec;$url=$this->challengeUrl;
            try{$response=$handler->submit($spec,$code);}
            catch(MessengerException $error){
                if(in_array($error->reason,['login_transport_failed','login_response_too_large'],true)){$this->challengeSpec=null;throw new MessengerException('login_two_factor_outcome_uncertain','O resultado da confirmação é incerto; o código não será reenviado automaticamente.');}
                throw $error;
            }
            $expectedId=null;
            if(($spec['kind']??null)==='graphql'){
                try{$result=$handler->result($response,$url);}
                catch(MessengerException $error){
                    if($error->reason==='login_two_factor_protocol_unavailable'){$this->challengeSpec=null;throw new MessengerException('login_two_factor_outcome_uncertain','A resposta da confirmação não foi reconhecida; o código não será reenviado automaticamente.');}
                    throw $error;
                }
                $this->confirmationConsumed=true;$this->challengeSpec=null;$expectedId=$result['user_id'];
                if($result['next']!==null)$this->follow('GET',$result['next'],[],false);
            }else{
                $this->challengeSpec=null;
                $continued=$response;$continuedUrl=is_string($spec['form']['url']??null)?$spec['form']['url']:$url;
                if(in_array($response->status,[301,302,303],true)){
                    $location=$response->header('location');
                    if($location===null)throw new MessengerException('login_http_rejected','A confirmação retornou um redirecionamento incompleto.');
                    [$continued,$continuedUrl]=$this->follow('GET',WebLoginPageParser::resolveUrl($continuedUrl,$location),[],false);
                }elseif($response->status!==200)throw new MessengerException('login_two_factor_outcome_uncertain','A resposta da confirmação não foi reconhecida; o código não será reenviado automaticamente.');
                $retry=$handler->prepare($continued,$continuedUrl);
                if($retry!==null){$this->challengeResponse=$continued;$this->challengeUrl=$continuedUrl;$this->challengeSpec=$retry;throw new MessengerException('login_two_factor_invalid_code','O Facebook manteve o formulário de verificação; confira o código atual.');}
                $this->confirmationConsumed=true;
            }
            try{$jar=$this->verifyIdentity(false);}
            catch(MessengerException $error){
                if($this->confirmationConsumed&&in_array($error->reason,['login_rejected','login_unconfirmed'],true))throw new MessengerException('login_continuation_required','A confirmação foi enviada, mas o Facebook ainda não confirmou uma sessão utilizável.');
                throw $error;
            }
            if($expectedId!==null&&$expectedId!==$jar->accountId())throw new MessengerException('login_identity_mismatch','A identidade final não corresponde à confirmação do código.');
            $this->cancel();return $jar;
        }catch(MessengerException $error){
            if($error->reason==='login_two_factor_outcome_uncertain'){$this->codeContinuationUncertain=true;$this->challengeSpec=null;}
            if($error->reason==='login_captcha_required'&&$this->challengeResponse!==null){$this->challengeType='captcha';$this->challengeSpec=null;}
            throw $error;
        } finally { if ($code !== '') sodium_memzero($code); }
    }

    public function cancel(): void
    {
        $this->challengeResponse = null; $this->challengeUrl = null; $this->challengeExpiresAt = 0;
        $this->continuationExpiresAt=0;
        $this->challengeSpec=null;$this->challengeType='two_factor';$this->confirmationConsumed=false;
        $this->methodSpec=null;$this->methodOutcomeUncertain=false;$this->codeContinuationUncertain=false;
        $this->methodRefreshAt=0;$this->methodRequestAt=0;$this->approvalCheckAt=0;
        $this->challengeScripts=[];
        $this->transport->clear();
    }

    /** Private application boundary for an optional human-operated browser.
     * Never return this object through HTTP, tools, events, logs or diagnostics.
     * @return array{url:string,cookies:list<array<string,mixed>>,expires_at:int}
     */
    public function exportPrivateChallenge():array
    {
        if($this->challenge()===null||$this->challengeUrl===null)throw new MessengerException('login_challenge_expired','A verificação expirou; inicie outra tentativa pelo formulário local.');
        return ['url'=>$this->challengeUrl,'cookies'=>$this->transport->cookies(),'expires_at'=>$this->challengeExpiresAt];
    }

    private function verifyIdentity(bool $captureVerification=true,?int $checkpointDeadline=null): CookieJar
    {
        $checkpointDeadline=$this->continuationDeadline($checkpointDeadline);
        try { $jar = new CookieJar($this->transport->cookies()); }
        catch (\InvalidArgumentException) { throw new MessengerException('login_rejected', 'O Facebook não confirmou o login. Confira as credenciais ou conclua a verificação no aplicativo/site.'); }
        $verification = null;
        foreach (['https://www.messenger.com/', 'https://www.facebook.com/messages/'] as $candidate) {
            $header = $jar->headerFor($candidate);
            if (preg_match('/(?:^|; )c_user=/', $header) && preg_match('/(?:^|; )xs=/', $header)) { $verification = $candidate; break; }
        }
        if ($verification === null) throw new MessengerException('login_unconfirmed', 'Os cookies recebidos não autorizam um destino de verificação suportado.');
        [$confirmed] = $this->follow('GET', $verification,[],$captureVerification,$checkpointDeadline);
        try { $jar = new CookieJar($this->transport->cookies()); }
        catch (\InvalidArgumentException) { throw new MessengerException('login_unconfirmed', 'O servidor não manteve uma sessão válida depois da verificação.'); }
        if($checkpointDeadline!==null)self::assertIdentityPage($confirmed->body);
        $id = (new WebLoginPageParser())->userId($confirmed->body);
        if ($id === null) throw new MessengerException('login_unconfirmed', 'O servidor não confirmou uma identidade autenticada depois do login.');
        if ($id !== $jar->accountId()) throw new MessengerException('login_identity_mismatch', 'A identidade confirmada não corresponde à sessão recebida.');
        return $jar;
    }

    /**
     * @param array<string,string> $form
     * @return array{WebLoginResponse,string}
     */
    private function follow(string $method, #[\SensitiveParameter] string $url, #[\SensitiveParameter] array $form = [],bool $captureVerification=true,?int $checkpointDeadline=null): array
    {
        $checkpointDeadline=$this->continuationDeadline($checkpointDeadline);
        for ($redirect = 0; $redirect <= 5; ++$redirect) {
            WebLoginCurlTransport::validateUrl($url);
            if($checkpointDeadline!==null&&time()>=$checkpointDeadline)throw new MessengerException('login_challenge_expired','A verificação expirou antes da próxima consulta.');
            $response = $this->transport->request($method, $url, $form);
            if($checkpointDeadline!==null&&time()>=$checkpointDeadline)throw new MessengerException('login_challenge_expired','A verificação expirou durante a consulta.');
            $twoFactor = self::isTwoFactorUrl($url);
            if($checkpointDeadline!==null&&$response->status===200&&!$twoFactor){
                $handler=new TwoFactorService($this->transport);
                if($handler->method($response,$url)!==null||$handler->prepare($response,$url)!==null)$this->captureChallenge($response,$url);
            }
            if($twoFactor&&$response->status===200&&$captureVerification)$this->captureChallenge($response,$url);
            if($twoFactor&&$response->status===200&&!$captureVerification){
                $method=(new TwoFactorService($this->transport))->method($response,$url);
                if($method!==null&&str_contains($method,'CAPTCHA'))$this->captureChallenge($response,$url);
            }
            if($checkpointDeadline===null||$response->status===200)$this->checkOrCaptureChallenge($response,$url,$twoFactor,$captureVerification);
            elseif($response->status===429)throw new MessengerException('login_rate_limited','O Facebook limitou esta consulta de verificação.');
            if (!in_array($response->status, [301, 302, 303, 307, 308], true)) {
                if ($response->status !== 200) throw new MessengerException('login_http_rejected', 'O servidor não aceitou a requisição de login.');
                return [$response, $url];
            }
            if ($redirect === 5) throw new MessengerException('login_redirect_limit', 'O fluxo de login excedeu o limite de redirecionamentos.');
            $location = $response->header('location');
            if ($location === null) throw new MessengerException('login_http_rejected', 'O servidor retornou um redirecionamento de login incompleto.');
            $url = WebLoginPageParser::resolveUrl($url, $location);
            if($checkpointDeadline===null)$this->checkOrCaptureChallenge(new WebLoginResponse(200,''),$url,self::isTwoFactorUrl($url),$captureVerification);
            if ($method === 'POST' && in_array($response->status, [307, 308], true)) throw new MessengerException('login_redirect_unsupported', 'O login pediu reenvio de credenciais; a tentativa não será repetida automaticamente.');
            $method = 'GET'; $form = [];
        }
        throw new \LogicException('Unreachable login redirect state');
    }

    private function captureChallenge(#[\SensitiveParameter] WebLoginResponse $response,#[\SensitiveParameter] string $url,?string $humanType=null):never
    {
        if(self::documentChallenge($response->body)==='login_captcha_required')$humanType='captcha';
        $handler=new TwoFactorService($this->transport);$method=$humanType===null?$handler->method($response,$url):null;
        $deadline=$this->continuationDeadline();
        $this->challengeResponse=$response;$this->challengeUrl=$url;$this->challengeExpiresAt=$deadline??time()+600;
        $this->confirmationConsumed=false;$this->challengeSpec=null;
        $this->methodSpec=null;$this->challengeScripts=[];$this->methodOutcomeUncertain=false;$this->codeContinuationUncertain=false;
        $this->challengeType=$humanType??($method!==null&&str_contains($method,'CAPTCHA')?'captcha':'two_factor');
        if($this->challengeType!=='two_factor')throw new MessengerException(self::humanChallengeCode($this->challengeType),'O Facebook exige uma verificação humana nesta tentativa; ela não aceita um código do autenticador.');
        $this->challengeSpec=$handler->prepare($response,$url);
        if($this->challengeSpec===null&&$method==='TOTP'){
            $urls=$handler->bundleUrls($response->body);
            if($urls!==[]){$this->challengeScripts=($this->bundleLoader)($urls);$this->challengeSpec=$handler->prepare($response,$url,$this->challengeScripts);}
        }
        $methods=new TwoFactorMethodService($this->transport);
        $this->methodSpec=$methods->prepare($response,$url,$this->challengeScripts);
        if($this->methodSpec!==null)$this->challengeSpec??=$methods->codeSpec($this->methodSpec);
        throw new MessengerException('login_two_factor_required','Escolha uma forma de verificação oferecida pelo Facebook ou confirme o código do método atual.');
    }

    /** A native checkpoint's original deadline also bounds later code/approval redirects. */
    private function continuationDeadline(?int $deadline=null):?int
    {
        if($this->continuationExpiresAt>0){
            $deadline=min($deadline??$this->continuationExpiresAt,$this->continuationExpiresAt);
            if($this->challengeExpiresAt>0)$deadline=min($deadline,$this->challengeExpiresAt);
        }
        if($deadline!==null&&time()>=$deadline)throw new MessengerException('login_challenge_expired','A verificação expirou antes da próxima consulta.');
        return $deadline;
    }

    private static function isTwoFactorUrl(#[\SensitiveParameter] string $url): bool
    {
        $path = strtolower(parse_url($url,PHP_URL_PATH)??'');
        return str_contains($path,'two_factor') || str_contains($path,'two_step');
    }

    private static function humanChallengeCode(string $type):string
    {
        return match($type){'captcha'=>'login_captcha_required','consent'=>'login_consent_required',default=>'login_challenge_required'};
    }

    /** Preserve the exact pending cookie jar even when the challenge is not on a two-factor route. */
    private function checkOrCaptureChallenge(#[\SensitiveParameter]WebLoginResponse $response,#[\SensitiveParameter]string $url,bool $allowTwoFactor,bool $capture):void
    {
        try{self::checkChallenge($response,$url,$allowTwoFactor);}
        catch(MessengerException $error){
            $type=match($error->reason){'login_captcha_required'=>'captcha','login_challenge_required'=>'checkpoint','login_consent_required'=>'consent',default=>null};
            if($capture&&$type!==null)$this->captureChallenge($response,$url,$type);
            throw $error;
        }
    }

    /** @internal A matching account ID inside a visible login/security form is still unconfirmed. */
    public static function assertIdentityPage(#[\SensitiveParameter]string $html):void
    {
        if(self::documentChallenge($html,true)!==null)throw new MessengerException('auth_challenge_required','Facebook still requires a login, security or consent confirmation before this browser session can be saved');
    }

    /** Visible form controls only: script strings, templates and links do not identify a challenge. */
    private static function documentChallenge(#[\SensitiveParameter]string $html,bool $includeLogin=false):?string
    {
        if(strlen($html)>16777216)throw new MessengerException('login_response_too_large','A página de verificação excedeu o limite local.');
        $document=preg_replace('~<!--.*?-->|<(script|style|textarea|template|noscript)\b[^>]*>.*?</\1\s*>~is','',$html);
        if($document===null)throw new MessengerException('login_response_too_large','A página de verificação não pôde ser analisada dentro dos limites locais.');
        preg_match_all('~<(form|input|iframe)\b([^>]*)>~is',$document,$tags,PREG_SET_ORDER);
        if(count($tags)>2000)throw new MessengerException('login_response_too_large','A página contém controles acima do limite local.');
        $reasons=[];
        foreach($tags as$tag){
            $attributes=[];preg_match_all('~([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~',$tag[2],$matches,PREG_SET_ORDER);
            foreach($matches as$attribute){
                $value=($attribute[2]??'')!==''?$attribute[2]:((($attribute[3]??'')!=='')?$attribute[3]:($attribute[4]??''));
                $attributes[strtolower($attribute[1])]=html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8');
            }
            $label=strtolower(($attributes['id']??'').' '.($attributes['name']??''));
            if(str_contains($label,'captcha')||strtolower($tag[1])==='iframe'&&str_contains(strtolower($attributes['src']??''),'captcha'))return 'login_captcha_required';
            if(str_contains($label,'checkpoint'))$reasons['login_challenge_required']=true;
            if(str_contains($label,'approvals_code')||str_contains($label,'two_factor'))$reasons['login_two_factor_required']=true;
            if($includeLogin&&(strtolower($attributes['type']??'')==='password'||preg_match('/(?:^| )(?:login_form|loginform)(?: |$)/',$label)))$reasons['login_unconfirmed']=true;
            if(strtolower($tag[1])!=='form')continue;
            $path=strtolower(parse_url($attributes['action']??'',PHP_URL_PATH)??'');
            if(preg_match('~/(checkpoint|challenge)(?:\.php)?(?:/|$)~',$path))$reasons['login_challenge_required']=true;
            elseif(preg_match('~/(?:two_step[^/]*|two_factor[^/]*)(?:/|$)~',$path))$reasons['login_two_factor_required']=true;
            elseif(preg_match('~/(?:re)?captcha(?:\.php)?(?:/|$)~',$path))return 'login_captcha_required';
            elseif(preg_match('~/(?:privacy/)?consent(?:\.php)?(?:/|$)~',$path))$reasons['login_consent_required']=true;
            elseif($includeLogin&&preg_match('~/login(?:\.php)?(?:/|$)~',$path))$reasons['login_unconfirmed']=true;
        }
        return $reasons===[]?null:array_key_first($reasons);
    }

    private static function checkChallenge(#[\SensitiveParameter] WebLoginResponse $response, #[\SensitiveParameter] string $url, bool $allowTwoFactor = false): void
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        if ($response->status === 429 || str_contains($path, '/blocked')) throw new MessengerException('login_rate_limited', 'O Facebook limitou novas tentativas de login; aguarde antes de tentar novamente.');
        if (!$allowTwoFactor && self::isTwoFactorUrl($url)) throw new MessengerException('login_two_factor_required', 'Confirme a autenticação em duas etapas no Facebook; a biblioteca não contorna essa verificação.');
        $document=self::documentChallenge($response->body);
        if(str_contains($path,'captcha')||$document==='login_captcha_required')throw new MessengerException('login_captcha_required','O Facebook exige um CAPTCHA; conclua a verificação pelo aplicativo ou navegador.');
        if(str_contains($path,'checkpoint')||str_contains($path,'challenge')||$document==='login_challenge_required'||(!$allowTwoFactor&&$document==='login_two_factor_required'))throw new MessengerException('login_challenge_required','O Facebook exige confirmação de segurança no aplicativo ou navegador.');
        if (str_contains($path, '/consent')) throw new MessengerException('login_consent_required', 'O Facebook exige uma decisão de consentimento no aplicativo ou navegador.');
        if($document==='login_consent_required')throw new MessengerException('login_consent_required','O Facebook exige uma decisão de consentimento no aplicativo ou navegador.');
    }

    public function __debugInfo(): array { return ['transport' => '[PRIVATE LOGIN CONTEXT]','challenge'=>$this->challenge()]; }
    public function __destruct() { if ($this->challengeResponse !== null) $this->cancel(); }
}
